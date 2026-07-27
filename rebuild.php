<?php
/**
 * inventario-sync — rebuild.php  (CONSERJE, no cartero)
 * ---------------------------------------------------------------------------
 * Reconstruye la lista blanca de deals del pipeline 44 desde cero y la limpia
 * de IDs de deals borrados. NO es el mecanismo que atrapa deals nuevos (eso lo
 * hace hook.php al instante via ONCRMDEALADD) — esto es solo red de seguridad.
 *
 * Correr por cron (ej: cada 30 min). 1 sola llamada paginada a Bitrix.
 *
 * Uso:  php rebuild.php        (cron)
 *       /rebuild.php?token=... (opcional via HTTP, protegido por OUTBOUND_TOKEN)
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

const CATEGORY_ID = 44;
const SPA_ENTITY  = 1072;   // usado por stagelib (refresco de stages)

$DATA_DIR   = getenv('DATA_DIR') ?: '/data';
$ALLOWLIST  = $DATA_DIR . '/allowlist.json';
$LOG_FILE   = $DATA_DIR . '/sync.log';
$WEBHOOK_IN = rtrim((string)getenv('BITRIX_WEBHOOK'), '/') . '/';

// Si se invoca por HTTP, exigir token. Por CLI (cron) no.
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
    // throttle + backoff (igual que reconcile.php): sin esto este script pegaba sin
    // pausa, moría al primer QUERY_LIMIT_EXCEEDED y encima dejaba sin presupuesto de
    // API a los demás (se veía en el log en cada reinicio del contenedor).
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
            if (in_array($j['error'], ['QUERY_LIMIT_EXCEEDED', 'OPERATION_TIME_LIMIT'], true) && $try < 4) {
                sleep(2 + $try); continue;
            }
            return ['ok' => false, 'error' => (string)$j['error']];
        }
        if (!is_array($j)) { if ($try < 4) { sleep(1); continue; } return ['ok' => false, 'error' => 'bad-json']; }
        return ['ok' => true, 'result' => $j['result'] ?? null, 'next' => $j['next'] ?? null, 'total' => $j['total'] ?? null];
    }
    return ['ok' => false, 'error' => 'retries-exhausted'];
}

// Paginar crm.deal.list de CATEGORY_ID=44, solo IDs
$ids   = [];
$start = 0;
do {
    $r = bx('crm.deal.list', [
        'filter' => ['CATEGORY_ID' => CATEGORY_ID],
        'select' => ['ID'],
        'start'  => $start,
    ]);
    if (!$r['ok']) { logline("REBUILD ERR: {$r['error']}"); if ($isHttp) echo "err:{$r['error']}"; exit(1); }
    foreach (($r['result'] ?? []) as $d) $ids[] = (string)$d['ID'];
    $start = $r['next'] ?? null;
} while ($start !== null);

$ids = array_values(array_unique($ids));

// escritura atómica
$tmp = $ALLOWLIST . '.tmp';
file_put_contents($tmp, json_encode($ids));
rename($tmp, $ALLOWLIST);

// refrescar cache de stages del SPA (nombres->STATUS_ID por pipeline)
require_once __DIR__ . '/stagelib.php';
stages_map(true);

logline('REBUILD ok -> ' . count($ids) . ' deals en P44, stages refrescados');
if ($isHttp) echo 'ok rebuild=' . count($ids);
