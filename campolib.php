<?php
/**
 * campolib.php — convierte el valor del campo "Inventario" en enlaces reales.
 * Compartido por guardar.php (lo llama el campo al elegir) y sync-campo.php
 * (red de seguridad / uso manual).
 * ---------------------------------------------------------------------------
 * El campo propio guarda solo texto ("581,623"). Esto es lo que lo vuelve un
 * enlace de verdad, igual que hacían los 4 campos anteriores:
 *
 *   1. escribe parentId2 = deal en cada unidad elegida  -> aparece la DEPENDENCIA
 *   2. suelta (parentId2 = 0) las unidades que se quitaron del campo
 *   3. copia responsable y cliente del deal a la unidad
 *   4. aplica el stage según la etapa del deal (RESERVADO / FIRMADO / VENDIDO)
 *   5. a las que se sueltan las deja en DISPONIBLE
 *
 * Lo llama el propio campo al elegir/quitar una unidad, y también reconcile.php
 * como red de seguridad.
 *
 * Solo actúa sobre deals de CLIENTES(44): Cobranzas(48) es de solo lectura.
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);
set_time_limit(120);

// SPA_ENTITY debe existir ANTES de requerir stagelib.php (lo usa por dentro).
// CLIENTES_CAT no se define aquí: ya la trae stagelib.php y chocaría.
const SPA_ENTITY  = 1072;
const CAMPO_NUEVO = 'UF_CRM_1785205972989';   // campo "Inventario" (tipo propio)

$DATA_DIR   = getenv('DATA_DIR') ?: '/data';
$WEBHOOK_IN = rtrim((string)getenv('BITRIX_WEBHOOK'), '/') . '/';

function logline(string $msg): void {
    global $DATA_DIR;
    // web.log: Apache no puede escribir en sync.log (lo crea el cron como root)
    @file_put_contents($DATA_DIR . '/web.log',
        gmdate('Y-m-d\TH:i:s\Z') . '  SYNCCAMPO ' . $msg . "\n", FILE_APPEND | LOCK_EX);
}

function bx(string $method, array $params = []): array {
    global $WEBHOOK_IN;
    usleep(200000);   // throttle: no vaciar el presupuesto de API de Bitrix
    for ($try = 0; $try < 4; $try++) {
        $ch = curl_init($WEBHOOK_IN . $method);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($params),
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $raw = curl_exec($ch); $errno = curl_errno($ch); curl_close($ch);
        if ($errno) { if ($try < 3) { sleep(1); continue; } return ['ok' => false, 'error' => "curl:$errno"]; }
        $j = json_decode((string)$raw, true);
        if (is_array($j) && isset($j['error'])) {
            if (in_array($j['error'], ['QUERY_LIMIT_EXCEEDED', 'OPERATION_TIME_LIMIT'], true) && $try < 3) {
                sleep(2 + $try); continue;
            }
            // Bitrix a veces manda error:"" con el motivo real en
            // error_description. Devolver solo $j['error'] dejaba errores
            // vacíos ("error":"") imposibles de diagnosticar.
            $e = trim((string)$j['error']);
            $d = trim((string)($j['error_description'] ?? ''));
            if ($e === '' && $d === '') $e = 'error-sin-detalle';
            return ['ok' => false, 'error' => $d !== '' ? ($e !== '' ? "$e: $d" : $d) : $e];
        }
        if (!is_array($j)) { if ($try < 3) { sleep(1); continue; } return ['ok' => false, 'error' => 'bad-json']; }
        return ['ok' => true, 'result' => $j['result'] ?? null, 'next' => $j['next'] ?? null];
    }
    return ['ok' => false, 'error' => 'retries-exhausted'];
}

require_once __DIR__ . '/stagelib.php';   // stage_id(), apply_unit_stage(), CLIENTES_TRIGGERS...

/**
 * Refresca en el caché del selector las unidades que acabamos de tocar.
 * Sin esto la lista seguía mostrando "DISPONIBLE" hasta el próximo refresco
 * (cada 15 min), aunque la unidad ya estuviera reservada.
 */
function refrescar_cache(array $unitIds): void {
    global $DATA_DIR;
    if (!$unitIds) return;
    $path = $DATA_DIR . '/selector_cache.json';
    $j = json_decode((string)@file_get_contents($path), true);
    if (!is_array($j) || empty($j['units'])) return;

    // nombre de stage por STATUS_ID, para guardar en el caché lo mismo que guarda rebuild
    $rev = [];
    foreach (stages_map() as $cat => $m) foreach ($m as $nombre => $sid) $rev[$sid] = $nombre;

    $nuevos = [];
    foreach ($unitIds as $uid) {
        $r = bx('crm.item.get', ['entityTypeId' => SPA_ENTITY, 'id' => (int)$uid]);
        if (!$r['ok']) continue;
        $it = $r['result']['item'] ?? $r['result'];
        $nuevos[(string)$uid] = [
            'stage'  => $rev[(string)($it['stageId'] ?? '')] ?? '',
            'dealId' => (int)($it['parentId2'] ?? 0),
        ];
    }
    if (!$nuevos) return;

    foreach ($j['units'] as &$u) {
        $k = (string)$u['id'];
        if (isset($nuevos[$k])) {
            $u['stage']  = $nuevos[$k]['stage'];
            $u['dealId'] = $nuevos[$k]['dealId'];
        }
    }
    unset($u);
    @file_put_contents($path, json_encode($j));
}

/** IDs del valor del campo ("581,623"). */
function ids_de(string $v): array {
    $out = [];
    foreach (preg_split('/[,;\s]+/', $v) as $x) {
        $x = trim($x);
        if ($x !== '' && ctype_digit($x) && (int)$x > 0) $out[] = (int)$x;
    }
    return array_values(array_unique($out));
}

/**
 * De una lista de IDs, devuelve las que SÍ se pueden atar a este deal.
 * Portero del servidor: la lista del campo pinta en gris lo ocupado, pero eso es
 * solo la pantalla. Sin esto se podía guardar un ID inventado, o una unidad que
 * otro vendedor ya tenía (doble venta).
 *
 * Se piden DOS condiciones, y las dos hacen falta:
 *
 *   a) parentId2 libre (0/null) o ya de este mismo deal.
 *   b) stage DISPONIBLE (o ya es de este deal).
 *
 * La (b) no es de adorno. Hay dos formas de que una unidad esté tomada: por
 * parentId2 (lo que escribe este sistema) y por el campo NATIVO del deal
 * PARENT_ID_1072, que deja parentId2 en null. Hoy la mayoría de las 778 unidades
 * ocupadas lo están por la vía nativa, así que mirando solo parentId2 salían
 * TODAS como libres. El stage sí las delata: una unidad tomada está en
 * RESERVADO / FIRMADO / VENDIDO, nunca en DISPONIBLE.
 */
function unidades_asignables(array $ids, int $dealId): array {
    if (!$ids) return [];
    // sin `select`: con select explícito Bitrix devuelve id en null (bug verificado)
    $r = bx('crm.item.list', ['entityTypeId' => SPA_ENTITY, 'filter' => ['@id' => $ids]]);
    if (!$r['ok']) return [];

    // stageId -> nombre ("DT1072_33:NEW" -> "DISPONIBLE")
    $rev = [];
    foreach (stages_map() as $cat => $m) foreach ($m as $nombre => $sid) $rev[$sid] = $nombre;

    $ok = [];
    foreach (($r['result']['items'] ?? []) as $it) {
        $id = (int)($it['id'] ?? 0);
        if (!$id) continue;
        $dueno = (int)($it['parentId2'] ?? 0);
        if ($dueno === $dealId) { $ok[] = $id; continue; }   // ya es mía
        if ($dueno !== 0) continue;                          // de otro deal
        $stage = $rev[(string)($it['stageId'] ?? '')] ?? '';
        if ($stage === 'DISPONIBLE') $ok[] = $id;
    }
    return $ok;
}

/**
 * Sincroniza un deal: deja atadas exactamente las unidades del campo.
 * Devuelve un resumen para el log.
 */
function sincronizar_deal(int $dealId): array {
    $g = bx('crm.deal.get', ['id' => $dealId]);
    if (!$g['ok']) return ['ok' => false, 'error' => 'deal-no-existe'];
    $deal = $g['result'];

    // guarda dura: Cobranzas(48) es read-only por regla del negocio
    if ((int)($deal['CATEGORY_ID'] ?? -1) !== CLIENTES_CAT) {
        return ['ok' => false, 'error' => 'solo-clientes-44'];
    }

    $quiere = ids_de((string)($deal[CAMPO_NUEVO] ?? ''));

    // Lo que hoy apunta a este deal.
    // SIN `select`: con select explícito Bitrix devuelve id/title en null (bug
    // verificado). Por eso antes esta lista salía vacía, "agregadas" contaba de
    // más y —lo grave— quitar una unidad del campo NO la liberaba.
    $tiene = [];
    $r = bx('crm.item.list', ['entityTypeId' => SPA_ENTITY, 'filter' => ['parentId2' => $dealId]]);
    if ($r['ok']) foreach (($r['result']['items'] ?? []) as $it) {
        if (!empty($it['id'])) $tiene[] = (int)$it['id'];
    }

    $agregar = array_values(array_diff($quiere, $tiene));
    $soltar  = array_values(array_diff($tiene, $quiere));

    // stage que corresponde según la etapa del deal
    $etapa  = (string)($deal['STAGE_ID'] ?? '');
    $target = CLIENTES_TRIGGERS[$etapa] ?? null;

    foreach ($agregar as $uid) {
        bx('crm.item.update', ['entityTypeId' => SPA_ENTITY, 'id' => $uid,
                               'fields' => ['parentId2' => $dealId]]);
        sync_unit_owner($uid, $deal);                       // responsable + cliente
    }

    // El stage se aplica a TODAS las unidades del campo, no solo a las recién
    // agregadas: si no, al mover el deal de etapa (Promesa firmada, Cierre de
    // promesa, Firmados-caídos) las que ya estaban atadas se quedaban igual.
    $movidas = 0;
    if ($target) {
        foreach ($quiere as $uid) {
            if (apply_unit_stage((int)$uid, null, $target, false)) $movidas++;
        }
    }
    foreach ($soltar as $uid) {
        bx('crm.item.update', ['entityTypeId' => SPA_ENTITY, 'id' => $uid,
                               'fields' => ['parentId2' => 0]]);
        apply_unit_stage($uid, null, 'DISPONIBLE', false);  // se quitó del deal -> libre
    }

    // Ya NO se copia la primera unidad al campo nativo PARENT_ID_1072: los 4
    // campos anteriores salen de circulación y ese reflejo solo confundía (una
    // unidad elegida aquí aparecía además en el campo viejo de arriba). La
    // dependencia real que ve el usuario en la unidad la da parentId2, no esto.

    // que la lista del selector muestre el estado nuevo de inmediato
    refrescar_cache(array_values(array_unique(array_merge($quiere, $soltar))));

    return ['ok' => true, 'quiere' => count($quiere), 'agregadas' => count($agregar),
            'soltadas' => count($soltar), 'stage' => $target ?: '-', 'movidas' => $movidas];
}

