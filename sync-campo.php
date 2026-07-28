<?php
/**
 * sync-campo.php — convierte el valor del campo "Inventario" en enlaces reales.
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
            return ['ok' => false, 'error' => (string)$j['error']];
        }
        if (!is_array($j)) { if ($try < 3) { sleep(1); continue; } return ['ok' => false, 'error' => 'bad-json']; }
        return ['ok' => true, 'result' => $j['result'] ?? null, 'next' => $j['next'] ?? null];
    }
    return ['ok' => false, 'error' => 'retries-exhausted'];
}

require_once __DIR__ . '/stagelib.php';   // stage_id(), apply_unit_stage(), CLIENTES_TRIGGERS...

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

    // lo que hoy apunta a este deal
    $tiene = [];
    $r = bx('crm.item.list', ['entityTypeId' => SPA_ENTITY, 'filter' => ['parentId2' => $dealId], 'select' => ['id']]);
    if ($r['ok']) foreach (($r['result']['items'] ?? []) as $it) $tiene[] = (int)$it['id'];

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

    // la relación nativa se mantiene con la PRIMERA unidad, para no perder la
    // dependencia que Bitrix ya mostraba con los campos anteriores
    $primera = $quiere[0] ?? 0;
    if ((int)($deal['PARENT_ID_1072'] ?? 0) !== (int)$primera) {
        bx('crm.deal.update', ['id' => $dealId, 'fields' => ['PARENT_ID_1072' => $primera ?: '']]);
    }

    return ['ok' => true, 'quiere' => count($quiere), 'agregadas' => count($agregar),
            'soltadas' => count($soltar), 'stage' => $target ?: '-', 'movidas' => $movidas];
}

// ---- entrada ----------------------------------------------------------------
$dealId = (int)($_REQUEST['deal'] ?? 0);
if ($dealId <= 0) { http_response_code(400); exit('falta deal'); }

$r = sincronizar_deal($dealId);
logline("deal=$dealId " . json_encode($r));

header('Content-Type: application/json; charset=utf-8');
echo json_encode($r);
