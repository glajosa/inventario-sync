<?php
/**
 * guardar.php — guarda la selección del campo "Inventario".
 * ---------------------------------------------------------------------------
 * Por qué existe: el campo se dibuja en un iframe de NUESTRO dominio, y desde
 * ahí el navegador NO deja llamar al API de Bitrix (dominios distintos, CORS).
 * Así que el campo llama aquí —mismo dominio, sin bloqueo— y este servidor
 * escribe en Bitrix con el webhook (servidor a servidor).
 *
 * Además de guardar el valor, deja los enlaces y los stages al día llamando a
 * sincronizar_deal(), que es lo mismo que hacía el sincronizador de los campos
 * anteriores.
 *
 * Seguridad: el campo trae una firma v2 (lib/placement-auth.php) que field.php
 * solo emite después de que Bitrix confirmó, con el token del usuario, que ese
 * usuario puede leer el deal. Amarra deal + usuario + vencimiento (2 h). Sin
 * firma válida no se escribe nada.
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

require_once __DIR__ . '/codigolib.php';   // valor_campo(), unidades_separadas()
require_once __DIR__ . '/campolib.php';
require_once __DIR__ . '/reubicalib.php';
require_once __DIR__ . '/lib/placement-auth.php';   // pl_verificar_firma()

// Acción interactiva: sin freno entre llamadas. El freno es para los barridos.
$BX_FRENO_US = 0;

header('Content-Type: application/json; charset=utf-8');

header('Cache-Control: no-store');

// Solo POST: el campo siempre llama así, y una firma en la URL queda en logs e historial.
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
    http_response_code(405); header('Allow: POST');
    echo json_encode(['ok' => false, 'error' => 'metodo no permitido']); exit;
}

$dealId = (int)($_POST['deal'] ?? 0);
$valor  = (string)($_POST['valor'] ?? '');
$firma  = (string)($_POST['firma'] ?? '');

if ($dealId <= 0) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'falta deal']); exit; }

// Firma v2 de field.php: deal + usuario verificado por Bitrix + vencimiento (2 h).
// La firma vieja (HMAC del id del deal, sin vencimiento) ya NO vale: se podía sacar
// sin sesión de field.php y servía para siempre.
$fv = pl_verificar_firma('campo', $firma, $dealId, time(), PL_FIRMA_CAMPO_TTL);
if (!$fv['ok']) {
    http_response_code(403);
    logline("deal=$dealId FIRMA INVALIDA ({$fv['motivo']})");
    echo json_encode(['ok' => false, 'error' => $fv['motivo'] === 'vencida'
        ? 'la sesion del campo vencio: vuelve a abrir el negocio' : 'firma invalida']);
    exit;
}
$usuarioFirma = (int)$fv['usuario'];

// solo IDs numéricos: nunca se escribe en el deal lo que llegue tal cual
$ids = [];
foreach (preg_split('/[,;\s]+/', $valor) as $x) {
    $x = trim($x);
    if ($x !== '' && ctype_digit($x) && (int)$x > 0) $ids[] = (int)$x;
}
$ids    = array_values(array_unique($ids));
// La marca de separadas llega en su propio parametro y se vuelve a pegar aca: el
// valor que se escribe se arma SIEMPRE en el servidor, nunca se guarda lo que llego.
$separadas = ((string)($_POST['separadas'] ?? $_GET['separadas'] ?? '')) === '1';
$limpio = valor_campo($ids, $separadas);

// El pipeline se valida ANTES de escribir. Antes se escribía primero y el
// sincronizador rechazaba después: el campo quedaba con un valor que nunca se
// convertía en enlace, y la respuesta decía ok:true (falso éxito).
$g = bx('crm.deal.get', ['id' => $dealId]);
if (!$g['ok']) {
    logline("deal=$dealId no existe: {$g['error']}");
    echo json_encode(['ok' => false, 'error' => 'el deal no existe']); exit;
}
// Pipelines donde el campo tiene sentido:
//   PROSPECTOS(28) -> se aparta al llegar a RESERVA (ver candado de etapa abajo).
//   CLIENTES(44)   -> la reserva oficial (ATA de verdad).
//   COBRANZAS(48)  -> REUBICACIÓN: el cliente ya compró y cambia de unidad.
//                     No ata (la dependencia vive en el 44), pero sí reescribe la
//                     ficha de los tres deals y deja el rastro de la reubicación.
$cat      = (int)($g['result']['CATEGORY_ID'] ?? -1);
$contacto = (int)($g['result']['CONTACT_ID'] ?? 0);
$stage    = (string)($g['result']['STAGE_ID'] ?? '');
if ($cat !== CLIENTES_CAT && $cat !== PROSPECTOS_CAT && $cat !== COBRANZAS_CAT) {
    logline("deal=$dealId RECHAZADO: pipeline $cat no soportado");
    echo json_encode(['ok' => false,
        'error' => 'Las unidades solo se eligen en Prospectos Ventas, Clientes o Cobranzas']); exit;
}

// COBRANZAS: vaciar el campo no reubica nada, y dejarlo pasar sería una forma
// silenciosa de perder el rastro. Se rechaza con un mensaje claro.
if ($cat === COBRANZAS_CAT && $ids === []) {
    echo json_encode(['ok' => false,
        'error' => 'Para reubicar hay que escoger la unidad nueva, no vaciar el campo']); exit;
}

// CANDADO DE ETAPA (28) — jul-2026. Antes se podía apartar desde CUALQUIER etapa
// del 28: dos vendedores nombraban la misma unidad en negociaciones que todavía
// no eran nada, y la primera en llegar la dejaba trabada para el resto.
// Vaciar el campo SÍ se permite en cualquier etapa: es la válvula para liberar
// una unidad mal apartada sin tener que mover el deal de etapa.
// Este es el candado de verdad; el de field.php es solo la capa visual.
//
// EXCEPCIÓN: el kanban. Al arrastrar a RESERVA, Bitrix pide el campo obligatorio
// antes de mover, así que sin excepción el candado se muerde la cola. field.php
// firma el permiso SOLO en los renders cuya URI es la del kanban; el navegador no
// tiene OUTBOUND_TOKEN, así que no puede fabricarlo.
//
// Ago-2026: esta excepción estaba MUCHO más abierta de lo que dice este comentario.
// field.php firmaba el permiso en TODO render y el navegador lo reenviaba cuando se
// veía a sí mismo con menos de 560px de ancho — y la columna del campo en la ficha
// de un deal mide ~435px. O sea el bypass estaba activo en la vista normal, y por
// ahí se apartó la A-1-1 de Noral Apartments desde VOLVER A LLAMAR. Ahora la firma
// solo existe si el render viene del kanban.
// Ago-2026: aquí YA NO se rechaza nada por etapa, y el permiso del kanban se fue
// con él. La regla se mudó a apartar_prospecto() en campolib.php, que es por donde
// pasan los cuatro caminos (este, hook.php, sync-campo.php y reconcile.php).
//
// Motivo: rechazar la ESCRITURA DEL CAMPO era incompatible con que el campo sea
// OBLIGATORIO para entrar a RESERVA. Bitrix lo pide ANTES de mover, y ahí el deal
// todavía no está en RESERVA, así que el guardado se rechazaba y el asesor no podía
// pasar la etapa — ni desde el deal ni desde el kanban. Y no hay forma de detectar
// ese momento desde el servidor (MANDATORY llega "N" siempre, MODE "edit" también
// viene de /details/); el intento de adivinarlo por el ancho del iframe dejó el
// candado abierto en la vista normal del deal.
//
// Ahora: ELEGIR se guarda en cualquier etapa, APARTAR (mover la unidad a RESERVADO)
// solo ocurre en RESERVA. Lo que había que evitar era trabar inventario, y elegir
// ya no traba: la unidad sigue DISPONIBLE hasta que el deal llegue a RESERVA.
$enReserva28 = ($cat === PROSPECTOS_CAT) && ($stage === etapa_28_reserva());

// COBRANZAS: si el vendedor abre el campo y guarda SIN cambiar la unidad, esto se
// tiene que ver como "no pasó nada". Sin esta salida temprana caía en el candado
// anti doble-venta de abajo —la unidad está atada al deal de Clientes, no a este—
// y le respondía "unidad ya apartada", que asusta y no describe lo que pasó.
if ($cat === COBRANZAS_CAT && count($ids) === 1) {
    $q = bx('crm.item.get', ['entityTypeId' => SPA_ENTITY, 'id' => (int)$ids[0]]);
    if ($q['ok']) {
        $cod = codigo_activo((string)(($q['result']['item'] ?? $q['result'])['title'] ?? ''));
        $act = (string)($g['result'][D_ACTIVO] ?? '');
        $norm = fn($s) => strtoupper(str_replace(' ', '', trim($s)));
        if ($cod !== '' && $norm($cod) === $norm($act)) {
            echo json_encode(['ok' => true, 'guardado' => $limpio,
                'nota' => "El deal ya está en $cod: no hay reubicación que hacer"]); exit;
        }
    }
}

// Jesua 6-oct-2026: "no debe permitirle cambiar unidad, sino mencionarle ahi: la unidad se puede cambiar en la etapa
// de reubicacion". El cambio de unidad de un 48 dispara la reubicacion entera: fuera de REUBICACION se RECHAZA antes
// de guardar el campo (no queda nada cambiado). Perilla env REUBICA_SOLO_EN_ETAPA ('0' la apaga).
if (guardar_reubica_bloqueada($cat, $stage) && !reub_pendiente_activo()) {
    logline("deal=$dealId RECHAZADO cambio de unidad en COBRANZAS fuera de REUBICACION (etapa $stage)");
    echo json_encode(['ok' => false, 'error' => 'La unidad solo se puede cambiar con el deal en la etapa REUBICACIÓN']); exit;
}

// Las unidades deben existir y no estar tomadas por OTRA venta (anti doble-venta).
// Se pasa el contacto para que la copia en CLIENTES pueda tomar la unidad que su
// propio deal de Prospectos apartó.
if ($ids) {
    /* COBRANZAS en REUBICACION (o en la ventanita que lo lleva ahi) puede tomar la unidad
       que ya tiene su GEMELO de Clientes: misma venta, mismo contacto. gemelolib.php. */
    $gemelo = null; $motivoGemelo = '';
    if ($cat === COBRANZAS_CAT) {
        require_once __DIR__ . '/gemelolib.php';
        $pend = guardar_reubica_bloqueada($cat, $stage) && reub_pendiente_activo();
        $dealsC = null; $leidos = false;
        $gemelo = function (int $uid, int $dueno, string $etapaU) use (&$dealsC, &$leidos, &$motivoGemelo, $contacto, $stage, $pend, $dealId): bool {
            if (!$leidos) { $dealsC = $contacto > 0 ? reub_deals_contacto($contacto) : null; $leidos = true; }
            $r = gemelo_decide($dueno, $etapaU, $stage, $pend, $dealsC, gemelo_perilla());
            logline("deal=$dealId GEMELO u=$uid dueno=$dueno -> " . ($r['ok'] ? 'SI' : 'NO') . ": {$r['motivo']}");
            if (!$r['ok']) $motivoGemelo = $r['motivo'];
            return $r['ok'];
        };
    }
    $libres = unidades_asignables($ids, $dealId, $contacto, $gemelo);
    $malas  = array_values(array_diff($ids, $libres));
    if ($malas) {
        logline("deal=$dealId RECHAZADO unidades no asignables: " . implode(',', $malas)
                . ($motivoGemelo !== '' ? " ($motivoGemelo)" : ''));
        echo json_encode(['ok' => false,
            'error' => 'Unidad ya apartada o no disponible: ' . implode(', ', $malas)
                     . ($motivoGemelo !== '' ? " — $motivoGemelo" : '')]);
        exit;
    }
}

$up = bx('crm.deal.update', ['id' => $dealId, 'fields' => [CAMPO_NUEVO => $limpio]]);
if (!$up['ok']) {
    logline("deal=$dealId ERROR al guardar: {$up['error']}");
    echo json_encode(['ok' => false, 'error' => $up['error']]);
    exit;
}

// Se reusa el deal ya leído para no repetir el crm.deal.get, pero OJO: ese objeto
// es de ANTES del update, así que hay que ponerle el valor nuevo del campo a mano.
// Sin esto, sincronizar_deal leería el valor viejo y ataría lo que ya no toca.
$deal = $g['result'];
$deal[CAMPO_NUEVO] = $limpio;

// COBRANZAS va por otro camino: no ata unidades, reubica. reubicar() recibe el
// deal tal como estaba ANTES del update a propósito — necesita el ACTIVO COMPRADO
// y el VALOR DEL ACTIVO viejos para saber de qué unidad se está saliendo.
if ($cat === COBRANZAS_CAT) {
    /* ⭐ 13-oct-2026 (Jesua, deal 6302): la VENTANITA de campos obligatorios de REUBICACIÓN pide la unidad ANTES
     * de que el deal entre a la etapa, y el rechazo de a8e8dbf trababa el paso: "ahí me deja escoger, y como ya
     * escogí en esa ventanita, se mueve automáticamente a la reubicación". Vuelve el PENDIENTE de 3da1bf2: la
     * unidad espera, hook48 la aplica al ENTRAR y, si en 10 min no entra, el barrido devuelve el campo. Fuera de
     * la ventanita (un vendedor que la cambia "sin culpa") tampoco pasa nada: sin entrar a la etapa, se revierte.
     * Perilla env REUBICA_PENDIENTE ('0' = vuelve el rechazo del 6-oct). */
    if (guardar_reubica_bloqueada($cat, $stage)) {
        reub_pend_guardar($dealId, $ids, (string)($g['result'][CAMPO_NUEVO] ?? ''), $g['result']);
        logline("deal=$dealId REUBICACION PENDIENTE [$limpio]: se aplica al entrar a REUBICACION (etapa ahora $stage)");
        echo json_encode(['ok' => true, 'guardado' => $limpio, 'pendiente' => true,
            'nota' => 'La unidad se cambia cuando el deal entre a la etapa REUBICACIÓN. Si no entra en 10 min, vuelve la de antes.']);
        exit;
    }
    $r = reubicar($dealId, $g['result'], $ids);
    logline("deal=$dealId REUBICACION guardado=[$limpio] r=" . json_encode($r));
    if (empty($r['ok'])) {
        echo json_encode(['ok' => false, 'error' => $r['error'] ?? 'no pude reubicar']); exit;
    }
    echo json_encode(['ok' => true, 'guardado' => $limpio, 'reubicacion' => $r]);
    exit;
}

$r = sincronizar_deal($dealId, $deal);
logline("deal=$dealId user=$usuarioFirma guardado=[$limpio] sync=" . json_encode($r));

// Atar la unidad desde la app no mueve el deal, asi que el evento de Bitrix nunca
// llega y la historia no se generaba: el sello aparecia en el plano y el buzon
// quedaba vacio. Se intenta aca tambien; hist_intentar valida etapa y pipeline.
require_once __DIR__ . '/historialib.php';
hist_intentar((string)$dealId, $deal, 'GUARDAR');

// En Prospectos fuera de RESERVA el campo SÍ se guardó pero la unidad NO se apartó.
// Se dice explícito para que el asesor no crea que ya la tiene: hasta que el deal
// entre a RESERVA la unidad sigue disponible y otro puede llevársela.
$nota = null;
if ($cat === PROSPECTOS_CAT && !$enReserva28 && $limpio !== '') {
    $nota = 'Unidad elegida. Se aparta cuando el deal pase a RESERVA — hasta entonces sigue disponible.';
}
echo json_encode(array_filter([
    'ok' => true, 'guardado' => $limpio, 'sync' => $r, 'nota' => $nota,
], fn($v) => $v !== null));
