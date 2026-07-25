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

require_once __DIR__ . '/stagelib.php';   // stages (Clientes re-afirmar + Cobranzas read-only)

function logline(string $msg): void {
    global $LOG_FILE;
    @file_put_contents($LOG_FILE, gmdate('Y-m-d\TH:i:s\Z') . '  ' . $msg . "\n", FILE_APPEND | LOCK_EX);
}

function bx(string $method, array $params = []): array {
    global $WEBHOOK_IN;
    // throttle base: ~3-4 req/s para no vaciar el pool de Bitrix en los barridos
    usleep(250000);
    for ($try = 0; $try < 5; $try++) {
        $ch = curl_init($WEBHOOK_IN . $method);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($params),
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $raw = curl_exec($ch); $errno = curl_errno($ch); curl_close($ch);
        if ($errno) { if ($try < 4) { sleep(1); continue; } return ['ok' => false, 'error' => "curl:$errno"]; }
        $j = json_decode((string)$raw, true);
        if (is_array($j) && isset($j['error'])) {
            // rate limit -> backoff y reintento
            if (in_array($j['error'], ['QUERY_LIMIT_EXCEEDED', 'OPERATION_TIME_LIMIT'], true) && $try < 4) {
                sleep(2 + $try);   // 2,3,4,5s
                continue;
            }
            return ['ok' => false, 'error' => $j['error']];
        }
        if (!is_array($j)) { if ($try < 4) { sleep(1); continue; } return ['ok' => false, 'error' => 'bad-json']; }
        // 'next' y 'total' vienen en el TOP-LEVEL de la respuesta (no dentro de result)
        return ['ok' => true, 'result' => $j['result'] ?? null, 'next' => $j['next'] ?? null];
    }
    return ['ok' => false, 'error' => 'retries-exhausted'];
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
    $start = $r['next'] ?? null;
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

// ==== STAGES (red de seguridad de etapas) =====================================
$stageCambios = 0;

// Lookup de unidades por (código normalizado | contacto) — clave BULLETPROOF.
// crm.item.list SIN select devuelve todos los campos (con select se rompe: id/title/contact = null).
// Verificado: por código hay varios cobranzas, pero solo el del MISMO contacto es la unidad correcta.
function norm_code(string $c): string { return strtoupper(str_replace(' ', '', trim($c))); }
$unitByKey = [];   // "CODE|CONTACT" => ['id'=>, 'cat'=>]
$codeSet   = [];   // CODE => true  (pre-filtro barato)
$start = 0;
do {
    $r = bx('crm.item.list', ['entityTypeId' => SPA_ENTITY, 'start' => $start]);   // SIN select
    if (!$r['ok']) { logline("RECONCILE ERR item.list units: {$r['error']}"); break; }
    $items = $r['result']['items'] ?? [];
    foreach ($items as $it) {
        $code = norm_code(explode('(', (string)($it['title'] ?? ''))[0]);
        if ($code === '') continue;
        $codeSet[$code] = true;
        $contact = (string)($it['contactId'] ?? '');
        if ($contact !== '' && $contact !== '0') {
            $unitByKey[$code . '|' . $contact] = ['id' => (int)$it['id'], 'cat' => (string)($it['categoryId'] ?? '')];
        }
    }
    $start = $r['next'] ?? null;
} while ($start !== null && $start !== '');

// --- A) CLIENTES (44) re-afirmar PRIMERO (por si se perdió un evento del hook) ----
foreach (CLIENTES_TRIGGERS as $stageId => $target) {
    $start = 0;
    do {
        $r = bx('crm.deal.list', [
            'filter' => ['CATEGORY_ID' => CLIENTES_CAT, 'STAGE_ID' => $stageId],
            'select' => ['ID', 'PARENT_ID_1072', 'STAGE_ID'],
            'start'  => $start,
        ]);
        if (!$r['ok']) { logline("RECONCILE ERR clientes($stageId): {$r['error']}"); break; }
        foreach (($r['result'] ?? []) as $d) {
            foreach (units_of_clientes_deal((string)$d['ID'], $d) as $uid) {
                if (apply_unit_stage((int)$uid, null, $target, false)) $stageCambios++;
            }
        }
        $start = $r['next'] ?? null;
    } while ($start !== null && $start !== '');
}

// --- B) COBRANZAS (48) READ-ONLY, DESPUÉS (más autoritativo para el estado final) -
// PAGADO TOTALMENTE -> VENDIDO ; DADO DE BAJA -> DISPONIBLE.
// NUNCA se escribe en el deal 48. Match SOLO por código+contacto (bulletproof):
//   1. crm.deal.list da el código (no el contacto) -> pre-filtro por codeSet (0 llamadas extra).
//   2. solo a los code-match se les hace crm.deal.get (para el CONTACT_ID).
//   3. la unidad se resuelve por "CODE|CONTACT". Si no matchea exacto -> NO se toca (seguro).
foreach (COBRANZAS_TRIGGERS as $stageId => $target) {
    $writeOff = ($stageId === 'C48:LOSE');
    $start = 0;
    do {
        $r = bx('crm.deal.list', [
            'filter' => ['CATEGORY_ID' => COBRANZAS_CAT, 'STAGE_ID' => $stageId],
            'select' => ['ID', COBRANZAS_CODE_FIELD],
            'start'  => $start,
        ]);
        if (!$r['ok']) { logline("RECONCILE ERR cobranzas($stageId): {$r['error']}"); break; }
        foreach (($r['result'] ?? []) as $d) {
            $code = norm_code((string)($d[COBRANZAS_CODE_FIELD] ?? ''));
            if ($code === '' || !isset($codeSet[$code])) continue;   // pre-filtro: sin get si no hay unidad con ese código
            $g = bx('crm.deal.get', ['id' => $d['ID']]);             // solo aquí gastamos 1 get
            if (!$g['ok']) continue;
            $contact = (string)($g['result']['CONTACT_ID'] ?? '');
            if ($contact === '' || $contact === '0') continue;
            $key = $code . '|' . $contact;
            if (!isset($unitByKey[$key])) continue;                  // no matchea código+contacto -> NO tocar
            if (apply_unit_stage($unitByKey[$key]['id'], null, $target, $writeOff)) $stageCambios++;
        }
        $start = $r['next'] ?? null;
    } while ($start !== null && $start !== '');
}

$msg = 'RECONCILE ok desired=' . count($desired) . ' actual=' . count($actual)
     . " link_cambios=$cambios stage_cambios=$stageCambios";
logline($msg);
if ($isHttp) echo $msg;
