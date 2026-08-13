<?php
/**
 * inventario-sync — hook.php
 * ---------------------------------------------------------------------------
 * Sincroniza los campos "Inventario 2/3/4" de un deal (pipeline 44 CLIENTES)
 * hacia la relación nativa parentId2 de cada unidad del SPA Inventario (1072).
 *
 * Resultado: un deal fusionado (2-4 unidades) queda atado a TODAS sus unidades,
 * y cada unidad muestra la NEGOCIACIÓN en su pestaña Dependencias.
 *
 * El campo nativo "Inventario" (PARENT_ID_1072 = unidad 1) NO se toca aquí:
 * lo maneja el vendedor directo, es relación nativa y ya funciona sola.
 *
 * Disparado por un WEBHOOK DE SALIDA de Bitrix (push, casi instantáneo) en:
 *   ONCRMDEALUPDATE  ONCRMDEALADD  ONCRMDEALDELETE
 *
 * Eficiencia: el payload de Bitrix solo trae el ID del deal. Para NO llamar a
 * la API en cada edición de cada deal del portal, se usa una LISTA BLANCA local
 * (allowlist.json) de IDs que están en el pipeline 44. Si el ID no está en la
 * lista => se descarta sin ni una sola llamada a Bitrix.
 *   - ONCRMDEALADD: registra el deal nuevo en la lista al instante (1 get).
 *   - rebuild.php (cron conserje): reconstruye/limpia la lista periódicamente.
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');

// ---- Rutas y constantes -----------------------------------------------------
const CATEGORY_ID   = 44;                       // pipeline CLIENTES
// Los userfields viejos de Inventario ya se borraron: el campo nuevo es la unica
// fuente. La lista queda vacia a proposito (no se borra la constante para no
// tocar el resto del flujo). OJO: nunca volver a filtrar por un campo borrado,
// Bitrix ignora el filtro y devuelve TODO.
const FIELDS_EXTRA  = [];

$DATA_DIR      = getenv('DATA_DIR')      ?: '/data';        // volumen persistente
$ALLOWLIST     = $DATA_DIR . '/allowlist.json';            // IDs de deals P44
$LOG_FILE      = $DATA_DIR . '/sync.log';
$WEBHOOK_IN    = rtrim((string)getenv('BITRIX_WEBHOOK'), '/') . '/'; // webhook ENTRANTE
$EXPECT_TOKEN  = (string)getenv('OUTBOUND_TOKEN');         // token del webhook SALIENTE

// campolib.php trae bx(), logline(), las constantes y —lo importante—
// sincronizar_deal(), que es la ÚNICA lógica de atado. Antes este archivo tenía
// copias propias de bx/logline y no incluía campolib: al llamar a sincronizar_deal
// el hook moría con un fatal (función inexistente), devolvía 500 y no dejaba ni
// rastro en el log. De ahí que "no pasara nada" al copiarse un deal a Clientes.
// stagelib entra por dentro de campolib, así que no hace falta pedirlo aquí.
require_once __DIR__ . '/campolib.php';

// Evento en vivo: sin freno entre llamadas (el freno es para los barridos).
$BX_FRENO_US = 0;

/** Lee la lista blanca (IDs de deals P44) como set [id=>true]. */
function load_allowlist(): array {
    global $ALLOWLIST;
    if (!is_file($ALLOWLIST)) return [];
    $j = json_decode((string)@file_get_contents($ALLOWLIST), true);
    if (!is_array($j)) return [];
    $set = [];
    foreach ($j as $id) $set[(string)$id] = true;
    return $set;
}

/** Agrega un ID a la lista blanca (idempotente, con lock). */
function allowlist_add(string $dealId): void {
    global $ALLOWLIST;
    $fh = @fopen($ALLOWLIST, 'c+');
    if (!$fh) return;
    flock($fh, LOCK_EX);
    $cur = stream_get_contents($fh);
    $arr = json_decode($cur ?: '[]', true);
    if (!is_array($arr)) $arr = [];
    if (!in_array($dealId, array_map('strval', $arr), true)) {
        $arr[] = $dealId;
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode(array_values($arr)));
    }
    flock($fh, LOCK_UN);
    fclose($fh);
}

/** Quita un ID de la lista blanca (deal borrado). */
function allowlist_remove(string $dealId): void {
    global $ALLOWLIST;
    $fh = @fopen($ALLOWLIST, 'c+');
    if (!$fh) return;
    flock($fh, LOCK_EX);
    $cur = stream_get_contents($fh);
    $arr = json_decode($cur ?: '[]', true);
    if (!is_array($arr)) $arr = [];
    $arr = array_values(array_filter(array_map('strval', $arr), fn($x) => $x !== $dealId));
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($arr));
    flock($fh, LOCK_UN);
    fclose($fh);
}

// ---- 1) Autenticación del webhook de salida ---------------------------------
$token = $_REQUEST['auth']['application_token']
      ?? $_REQUEST['application_token']
      ?? '';
if ($EXPECT_TOKEN === '' || !hash_equals($EXPECT_TOKEN, (string)$token)) {
    http_response_code(403);
    logline('403 token invalido');
    echo 'forbidden';
    exit;
}

// Responder 200 cuanto antes: Bitrix reintenta si no ve 200.
// (procesamos igual dentro de este request; es corto)
$event  = strtoupper((string)($_REQUEST['event'] ?? ''));
$dealId = (string)($_REQUEST['data']['FIELDS']['ID'] ?? '');

// ---- 1.5) Eventos del SPA Inventario (unidades) -----------------------------
// El mismo webhook de salida trae los eventos de DEALS y los de UNIDADES. Se
// reutiliza a propósito: crear un webhook aparte traería su propio
// application_token y no coincidiría con OUTBOUND_TOKEN.
// El catálogo del campo se actualiza al instante en vez de esperar el cron.
if (strpos($event, 'DYNAMICITEM') !== false) {
    require_once __DIR__ . '/unidadlib.php';
    $r = unidad_evento(
        $event,
        (int)($_REQUEST['data']['FIELDS']['ID'] ?? 0),
        (int)($_REQUEST['data']['FIELDS']['ENTITY_TYPE_ID'] ?? 0)
    );
    echo $r;
    exit;
}

if ($dealId === '') { echo 'no-id'; exit; }

// ---- 1.6) COBRANZAS(48): el PRECIO FINAL de la unidad ------------------------
// Regla del negocio: Clientes mueve las unidades, pero el VALOR FINAL lo manda
// Cobranzas y tiene que llegar solo al SPA en cuanto lo escriban allá.
//
// Se cuelga de este MISMO evento a propósito. ONCRMDEALUPDATE ya está llegando
// aquí para todos los deals del portal y hoy se descarta; enganchar el 48 no
// agrega tráfico nuevo, solo deja de tirar los que importan. Un ID que no está en
// el mapa del 48 sale con CERO llamadas, igual que el resto del ruido.
//
// No se toca stage, ni parentId2, ni contacto: escribe UN campo y nada más. Por eso
// va ANTES del filtro de P44 — un deal del 48 nunca entra por ese camino.
// El ALTA no entra aquí: hook.php lee el deal más abajo para saber si es del 44, y
// esa misma lectura se reutiliza (ver el bloque ADD) en vez de pedirlo dos veces.
if ($event === 'ONCRMDEALUPDATE') {
    require_once __DIR__ . '/preciolib.php';
    $pf = precio_final_evento($dealId);
    if ($pf !== 'pf-skip-no48') { echo $pf; exit; }
    // 'pf-skip-no48' = no es del 48; sigue el camino normal de P44 / pendientes 28.
}

// ---- 2) DELETE: soltar unidades atadas a ese deal ---------------------------
if ($event === 'ONCRMDEALDELETE') {
    allowlist_remove($dealId);

    // Las unidades del deal borrado quedan COMO NUEVAS: sin dependencia, sin
    // cliente y en DISPONIBLE. Antes solo se quitaba parentId2, así que la unidad
    // seguía en FIRMADO y con el nombre del cliente en la tarjeta del kanban: nadie
    // podía venderla y parecía ocupada por un deal que ya no existe.
    //
    // De dónde salen las unidades del deal borrado: NO de un filtro por parentId2.
    // Bitrix borra las relaciones EN CASCADA antes de disparar el evento, así que
    // cuando llega aquí ya no queda ninguna unidad apuntando al deal y el filtro
    // devuelve cero. Verificado: el handler anterior no liberaba nada nunca y solo
    // lo parecía, porque Bitrix ya había limpiado el parentId2 por su cuenta — el
    // stage y el cliente se quedaban pegados para siempre.
    //
    // El rastro sí está en el caché del selector, que guarda para cada unidad el
    // deal que la tenía. Se filtra luego contra Bitrix: solo se libera la que de
    // verdad quedó sin dueño.
    // 1) la libreta propia (atados.json), que se escribe en el mismo momento del
    //    atado y por eso sobrevive al borrado en cascada
    $cand = atados_de((int)$dealId);
    // 2) el caché del selector, como segunda fuente
    $cache = json_decode((string)@file_get_contents($DATA_DIR . '/selector_cache.json'), true);
    foreach (($cache['units'] ?? []) as $u) {
        if ((int)($u['dealId'] ?? 0) === (int)$dealId) $cand[] = (int)$u['id'];
    }
    // 3) y el filtro directo, por si el borrado no hubiera arrastrado la relación
    $r = bx('crm.item.list', ['entityTypeId' => SPA_ENTITY, 'filter' => ['parentId2' => $dealId]]);
    if ($r['ok']) foreach (($r['result']['items'] ?? []) as $it) $cand[] = (int)($it['id'] ?? 0);
    $cand = array_values(array_unique(array_filter($cand)));
    logline("DELETE deal=$dealId candidatas=[" . implode(',', $cand) . ']');

    $n = 0;
    foreach ($cand as $uid) {
        $g = bx('crm.item.get', ['entityTypeId' => SPA_ENTITY, 'id' => $uid]);
        if (!$g['ok']) continue;
        $it = $g['result']['item'] ?? $g['result'];
        // si otro deal ya la tomó, no se toca: sería quitarle la unidad a una
        // venta viva por haber borrado un deal antiguo
        if ((int)($it['parentId2'] ?? 0) > 0) continue;
        $campos = ['parentId2' => 0, 'contactId' => 0];
        $sid    = stage_id((string)($it['categoryId'] ?? ''), 'DISPONIBLE');
        if ($sid !== null) $campos['stageId'] = $sid;
        // marca de escritura propia para que el guardián no lo lea como arrastre
        @touch($DATA_DIR . '/self_u_' . $uid);
        $u = bx('crm.item.update', ['entityTypeId' => SPA_ENTITY, 'id' => $uid, 'fields' => $campos]);
        if ($u['ok']) { $n++; cache_unidad($uid, 'DISPONIBLE', 0); atados_anotar($uid, 0); }
        else logline("DELETE u=$uid ERR: {$u['error']}");
    }
    logline("DELETE deal=$dealId -> $n unidad(es) a DISPONIBLE, sin dueño ni cliente");
    echo "ok-delete liberadas=$n";
    exit;
}

// ---- 3) ADD: registrar en lista blanca si es P44 ----------------------------
$allow = load_allowlist();

if ($event === 'ONCRMDEALADD') {
    // deal nuevo del portal: 1 get para ver si es P44
    $r = bx('crm.deal.get', ['id' => $dealId]);
    if ($r['ok'] && (int)($r['result']['CATEGORY_ID'] ?? -1) === CATEGORY_ID) {
        allowlist_add($dealId);
        $allow[$dealId] = true;              // seguir procesando abajo por si ya trae unidades
        logline("ADD deal=$dealId registrado en P44");
    } elseif ($r['ok'] && (int)($r['result']['CATEGORY_ID'] ?? -1) === 48) {
        // Copia recién creada en COBRANZAS. Se registra en el mapa AHORA, con el
        // deal que ya se leyó arriba: si esperara al barrido de mapa48.php, sus
        // ONCRMDEALUPDATE se descartarían por "no está en el mapa" hasta 6 h, y el
        // precio final que escriban en ese rato no llegaría al inventario.
        require_once __DIR__ . '/preciolib.php';
        echo precio_final_evento($dealId, $r['result']);
        exit;
    } else {
        echo 'ok-add-skip';                  // no es P44 ni P48, fuera. 1 get gastado, aceptable.
        exit;
    }
}

// ---- 4) UPDATE (y ADD ya validado): filtro por lista blanca -----------------
// Además de P44, pasan los PENDIENTES DEL 28: prospectos que ya eligieron unidad
// pero todavía no están en RESERVA, así que la unidad NO está apartada. Este hook
// es justamente lo que los aparta en cuanto el deal entra a RESERVA — llega por
// push (ONCRMDEALUPDATE), en segundos, sin que nadie tenga que abrir el deal.
//
// Sigue siendo baratísimo: la lista de pendientes tiene los pocos deals que están
// negociando con una unidad elegida, no los 89.302 del pipeline. Lo demás se sigue
// descartando con CERO llamadas.
$pend28 = pendientes_28();
if (!isset($allow[$dealId]) && !isset($pend28[$dealId])) {
    // no es de P44 ni pendiente del 28 => CERO llamadas. Aquí se descarta el 99%
    // del ruido del portal.
    echo 'skip-not-p44';
    exit;
}

// ---- 5) Es P44: sincronizar ------------------------------------------------
// Este bloque tenía ANTES su propia copia de la lógica de atado: leía el deal,
// calculaba diffs, escribía parentId2 y aplicaba stages por su cuenta. Dos
// implementaciones de lo mismo, y divergieron: a esta le faltaba el autollenado de
// la ficha (ACTIVO COMPRADO / VALOR DEL ACTIVO / Proyectos 1 / Monto) y la
// limpieza del campo en Prospectos. Resultado real: un deal que llegaba a RESERVA
// por la copia automática del 28 se quedaba con la ficha sin llenar y con la
// unidad todavía puesta en Prospectos, porque por este camino nadie las hacía.
// Ahora llama a la MISMA función que guardar.php. Una sola lógica, un solo sitio.
$r = bx('crm.deal.get', ['id' => $dealId]);           // 1 llamada
if (!$r['ok']) { logline("ERR get deal=$dealId: {$r['error']}"); echo 'err-get'; exit; }
$deal = $r['result'];

// Compatibilidad con los deals sin migrar: si el campo nuevo está vacío pero los
// viejos tienen algo, se le pasa a sincronizar_deal por el campo nuevo.
if (ids_de((string)($deal[CAMPO_NUEVO] ?? '')) === []) {
    $viejas = [];
    foreach (FIELDS_EXTRA as $f) {
        $v = $deal[$f] ?? '';
        if ($v !== '' && $v !== null && (int)$v > 0) $viejas[(int)$v] = true;
    }
    if ($viejas) $deal[CAMPO_NUEVO] = implode(',', array_keys($viejas));
}

// (int) obligatorio: $dealId viaja como string desde el payload del webhook y
// campolib.php declara strict_types, así que pasarlo tal cual era un TypeError.
$res = sincronizar_deal((int)$dealId, $deal);
logline("HOOK deal=$dealId sync=" . json_encode($res));
echo 'ok-sync ' . json_encode($res);
