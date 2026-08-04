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
 * Seguridad: el campo trae una firma (HMAC del id del deal con OUTBOUND_TOKEN)
 * que se genera al renderizar. Sin firma válida no se escribe nada.
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);
require_once __DIR__ . '/campolib.php';
require_once __DIR__ . '/reubicalib.php';

// Acción interactiva: sin freno entre llamadas. El freno es para los barridos.
$BX_FRENO_US = 0;

header('Content-Type: application/json; charset=utf-8');

$dealId = (int)($_POST['deal'] ?? $_GET['deal'] ?? 0);
$valor  = (string)($_POST['valor'] ?? $_GET['valor'] ?? '');
$firma  = (string)($_POST['firma'] ?? $_GET['firma'] ?? '');

if ($dealId <= 0) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'falta deal']); exit; }

$secreto = (string)getenv('OUTBOUND_TOKEN');
if ($secreto === '' || !hash_equals(hash_hmac('sha256', (string)$dealId, $secreto), $firma)) {
    http_response_code(403);
    logline("deal=$dealId FIRMA INVALIDA");
    echo json_encode(['ok' => false, 'error' => 'firma invalida']);
    exit;
}

// solo IDs numéricos: nunca se escribe en el deal lo que llegue tal cual
$ids = [];
foreach (preg_split('/[,;\s]+/', $valor) as $x) {
    $x = trim($x);
    if ($x !== '' && ctype_digit($x) && (int)$x > 0) $ids[] = (int)$x;
}
$ids    = array_values(array_unique($ids));
$limpio = implode(',', $ids);

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
$permisoOk = false;
$permiso   = (string)($_POST['permiso'] ?? '');
if ($permiso !== '') {
    $esperado  = hash_hmac('sha256', $dealId . '|kanban', (string)getenv('OUTBOUND_TOKEN'));
    $permisoOk = hash_equals($esperado, $permiso);
    if (!$permisoOk) logline("deal=$dealId permiso de etapa INVALIDO");
}
if ($cat === PROSPECTOS_CAT && $limpio !== '' && !$permisoOk && $stage !== etapa_28_reserva()) {
    logline("deal=$dealId RECHAZADO: etapa $stage no es RESERVA (28)");
    echo json_encode(['ok' => false,
        'error' => 'En Prospectos Ventas la unidad solo se elige en la etapa RESERVA']); exit;
}

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

// Las unidades deben existir y no estar tomadas por OTRA venta (anti doble-venta).
// Se pasa el contacto para que la copia en CLIENTES pueda tomar la unidad que su
// propio deal de Prospectos apartó.
if ($ids) {
    $libres = unidades_asignables($ids, $dealId, $contacto);
    $malas  = array_values(array_diff($ids, $libres));
    if ($malas) {
        logline("deal=$dealId RECHAZADO unidades no asignables: " . implode(',', $malas));
        echo json_encode(['ok' => false,
            'error' => 'Unidad ya apartada o no disponible: ' . implode(', ', $malas)]);
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
    $r = reubicar($dealId, $g['result'], $ids);
    logline("deal=$dealId REUBICACION guardado=[$limpio] r=" . json_encode($r));
    if (empty($r['ok'])) {
        echo json_encode(['ok' => false, 'error' => $r['error'] ?? 'no pude reubicar']); exit;
    }
    echo json_encode(['ok' => true, 'guardado' => $limpio, 'reubicacion' => $r]);
    exit;
}

$r = sincronizar_deal($dealId, $deal);
logline("deal=$dealId guardado=[$limpio] sync=" . json_encode($r));

echo json_encode(['ok' => true, 'guardado' => $limpio, 'sync' => $r]);
