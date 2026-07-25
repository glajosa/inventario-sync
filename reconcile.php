<?php
/**
 * inventario-sync — reconcile.php  (RED DE SEGURIDAD, bidireccional)
 * ---------------------------------------------------------------------------
 * Cierra el gap de "evento perdido": si el servicio estaba caído cuando el
 * vendedor editó Inventario 2/3/4, el webhook de salida se pierde (Bitrix NO
 * reintenta de forma confiable — verificado 2026-07-25). Re-sincroniza TODO
 * periódicamente → consistencia eventual garantizada.
 *
 * Barato: solo toca deals P44 CON extras llenos y unidades CON parentId2 puesto
 * (los ~36 fusionados y sus unidades), no los 1350. Correr por cron cada 5 min.
 *
 * Reconcilia en AMBAS direcciones:
 *   - falta atar  (deal quiere unidad, unidad no la tiene)  -> set parentId2
 *   - sobra atada (unidad tiene deal, deal ya no la quiere)  -> clear parentId2
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

const CATEGORY_ID  = 44;
const SPA_ENTITY   = 1072;
const FIELDS_EXTRA = ['UF_CRM_DEAL_1784994996','UF_CRM_DEAL_1784995021','UF_CRM_DEAL_1784995044'];

$DATA_DIR   = getenv('DATA_DIR') ?: '/data';
$LOG_FILE   = $DATA_DIR . '/sync.log';
$WEBHOOK_IN = rtrim((string)getenv('BITRIX_WEBHOOK'), '/') . '/';

$isHttp = PHP_SAPI !== 'cli';
if ($isHttp) {
    header('Content-Type: text/plain; charset=utf-8');
    $expect = (string)getenv('OUTBOUND_TOKEN');
    $got    = (string)($_GET['token'] ?? '');
    if ($expect === '' || !hash_equals($expect, $got)) { http_response_code(403); echo 'forbidden'; exit; }
}

function logline(string $msg): void {
    global $LOG_FILE;
    @file_put_contents($LOG_FILE, gmdate('Y-m-d\TH:i:s\Z') . '  ' . $msg . "\n", FILE_APPEND | LOCK_EX);
}

function bx(string $method, array $params = []): array {
    global $WEBHOOK_IN;
    $ch = curl_init($WEBHOOK_IN . $method);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($params),
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $raw = curl_exec($ch); $errno = curl_errno($ch); curl_close($ch);
    if ($errno) return ['ok' => false, 'error' => "curl:$errno"];
    $j = json_decode((string)$raw, true);
    if (!is_array($j) || isset($j['error'])) return ['ok' => false, 'error' => $j['error'] ?? 'bad-json'];
    return ['ok' => true, 'result' => $j['result'] ?? null];
}

// ---- DESEADO: unidad => deal, según los campos Inv2/3/4 de deals P44 ----------
$desired = [];   // unitId => dealId
foreach (FIELDS_EXTRA as $f) {
    $r = bx('crm.deal.list', [
        'filter' => ['CATEGORY_ID' => CATEGORY_ID, '!' . $f => ''],
        'select' => ['ID', 'UF_CRM_DEAL_1784994996', 'UF_CRM_DEAL_1784995021', 'UF_CRM_DEAL_1784995044'],
    ]);
    if (!$r['ok']) { logline("RECONCILE ERR list($f): {$r['error']}"); if ($isHttp) echo "err\n"; exit(1); }
    foreach (($r['result'] ?? []) as $d) {
        $dealId = (string)$d['ID'];
        foreach (FIELDS_EXTRA as $ff) {
            $v = $d[$ff] ?? '';
            if ($v !== '' && $v !== null && (int)$v > 0) $desired[(int)$v] = $dealId;
        }
    }
}

// ---- ACTUAL: unidad => deal, según parentId2 de las unidades ------------------
// paginamos unidades con parentId2 != 0 (son pocas: las atadas por este sistema)
$actual = [];    // unitId => dealId
$start = 0;
do {
    $r = bx('crm.item.list', [
        'entityTypeId' => SPA_ENTITY,
        'filter'       => ['!parentId2' => 0],
        'select'       => ['id', 'parentId2'],
        'start'        => $start,
    ]);
    if (!$r['ok']) { logline("RECONCILE ERR item.list: {$r['error']}"); if ($isHttp) echo "err\n"; exit(1); }
    $items = $r['result']['items'] ?? [];
    foreach ($items as $it) $actual[(int)$it['id']] = (string)$it['parentId2'];
    $start = $r['result']['next'] ?? null;
} while ($start !== null && $start !== '');

// ---- DIFF y aplicar solo lo que difiere --------------------------------------
$cambios = 0;
// unidades que deben apuntar a un deal (o cambiar de deal)
foreach ($desired as $unit => $deal) {
    if (!isset($actual[$unit]) || $actual[$unit] !== $deal) {
        $u = bx('crm.item.update', ['entityTypeId' => SPA_ENTITY, 'id' => $unit, 'fields' => ['parentId2' => $deal]]);
        if ($u['ok']) { $cambios++; logline("RECONCILE set u=$unit -> deal=$deal"); }
    }
}
// unidades atadas que ya nadie desea -> soltar
foreach ($actual as $unit => $deal) {
    if (!isset($desired[$unit])) {
        $u = bx('crm.item.update', ['entityTypeId' => SPA_ENTITY, 'id' => $unit, 'fields' => ['parentId2' => 0]]);
        if ($u['ok']) { $cambios++; logline("RECONCILE clear u=$unit (era deal=$deal)"); }
    }
}

$msg = 'RECONCILE ok desired=' . count($desired) . ' actual=' . count($actual) . " cambios=$cambios";
logline($msg);
if ($isHttp) echo $msg;
