<?php
/**
 * unidad-hook.php — el catálogo del campo se actualiza al INSTANTE.
 * ---------------------------------------------------------------------------
 * Antes el catálogo solo se refrescaba por reloj (cron cada 30 min), así que una
 * unidad nueva del SPA podía tardar media hora en aparecer en el campo. Esto lo
 * vuelve por EVENTO: Bitrix avisa en el momento en que la unidad se crea, cambia
 * o se borra, y aquí se toca solo esa unidad en el caché.
 *
 * Lo dispara un webhook de SALIDA de Bitrix suscrito SOLO a este SPA:
 *   ONCRMDYNAMICITEMADD_1072  ONCRMDYNAMICITEMUPDATE_1072  ONCRMDYNAMICITEMDELETE_1072
 * Con el sufijo `_1072` no llegan eventos de los otros SPAs del portal.
 *
 * Coste: 1 sola llamada a Bitrix por evento (crm.item.get), porque el payload de
 * Bitrix trae únicamente el ID. En un borrado, cero llamadas.
 *
 * PROYECTO NUEVO: si la unidad viene de un pipeline que el caché no conoce, no se
 * intenta adivinar el nombre — se dispara la reconstrucción completa por detrás,
 * que es la que trae los proyectos y sus etapas. Así un pipeline nuevo también
 * entra solo.
 *
 * El cron de 30 min se queda como red de seguridad: Bitrix pierde webhooks de
 * salida y no reintenta de forma confiable (verificado en este mismo proyecto).
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');

const SPA_ENTITY = 1072;
// mismos campos que usa selector.php al construir el catálogo
const U_M2  = 'ufCrm25_1782615822688';
const U_PVP = 'ufCrm25_1784563253861';
const U_TOR = 'ufCrm25_1784314119';
const U_PIS = 'ufCrm25_1784313244';

$DATA_DIR   = getenv('DATA_DIR') ?: '/data';
$WEBHOOK_IN = rtrim((string)getenv('BITRIX_WEBHOOK'), '/') . '/';
$TOKEN      = (string)getenv('OUTBOUND_TOKEN');
$CACHE      = $DATA_DIR . '/selector_cache.json';

function ulog(string $msg): void {
    global $DATA_DIR;
    @file_put_contents($DATA_DIR . '/web.log',
        gmdate('Y-m-d\TH:i:s\Z') . '  UNIDADHOOK ' . $msg . "\n", FILE_APPEND | LOCK_EX);
}

function bx(string $method, array $params = []): array {
    global $WEBHOOK_IN;
    $ch = curl_init($WEBHOOK_IN . $method);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($params),
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $raw = curl_exec($ch); $errno = curl_errno($ch);
    if ($errno) return ['ok' => false, 'error' => "curl:$errno"];
    $j = json_decode((string)$raw, true);
    if (!is_array($j))      return ['ok' => false, 'error' => 'bad-json'];
    if (isset($j['error'])) return ['ok' => false, 'error' => $j['error'] . ':' . ($j['error_description'] ?? '')];
    return ['ok' => true, 'result' => $j['result'] ?? null];
}

/** Pide la reconstrucción completa del catálogo sin esperarla. */
function rebuild_en_segundo_plano(): void {
    global $TOKEN;
    $ch = curl_init('http://127.0.0.1/selector.php?warm=1&token=' . urlencode($TOKEN));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT_MS => 300, CURLOPT_NOSIGNAL => true,
    ]);
    curl_exec($ch);
}

// ---- 1) autenticación del webhook de salida --------------------------------
$got = $_REQUEST['auth']['application_token'] ?? $_REQUEST['application_token'] ?? '';
if ($TOKEN === '' || !hash_equals($TOKEN, (string)$got)) {
    http_response_code(403); ulog('403 token invalido'); echo 'forbidden'; exit;
}

$event  = strtoupper((string)($_REQUEST['event'] ?? ''));
$unitId = (int)($_REQUEST['data']['FIELDS']['ID'] ?? 0);
$etid   = (int)($_REQUEST['data']['FIELDS']['ENTITY_TYPE_ID'] ?? 0);

if ($unitId <= 0) { echo 'no-id'; exit; }
// aunque nos suscribimos a las variantes _1072, se comprueba igual: si alguien
// añade el evento genérico llegarían unidades de otros SPAs
if ($etid && $etid !== SPA_ENTITY) { echo 'otro-spa'; exit; }

// ---- 2) el caché tiene que existir; si no, que lo construya el rebuild -----
$cache = json_decode((string)@file_get_contents($CACHE), true);
if (!is_array($cache) || empty($cache['units'])) {
    rebuild_en_segundo_plano();
    ulog("u=$unitId sin cache -> rebuild completo");
    echo 'rebuild'; exit;
}

// ---- 3) DELETE: se quita del caché, sin llamar a Bitrix --------------------
if (strpos($event, 'DELETE') !== false) {
    $antes = count($cache['units']);
    $cache['units'] = array_values(array_filter($cache['units'],
        fn($u) => (int)($u['id'] ?? 0) !== $unitId));
    if (count($cache['units']) !== $antes) {
        @file_put_contents($CACHE, json_encode($cache), LOCK_EX);
        ulog("u=$unitId BORRADA del catalogo");
    }
    echo 'ok-delete'; exit;
}

// ---- 4) ADD / UPDATE: se lee esa unidad y se mete en el caché --------------
$r = bx('crm.item.get', ['entityTypeId' => SPA_ENTITY, 'id' => $unitId]);
if (!$r['ok']) { ulog("ERR get u=$unitId: {$r['error']}"); echo 'err-get'; exit; }
$it = $r['result']['item'] ?? $r['result'];

$cid = (string)($it['categoryId'] ?? '');
// Proyecto que el caché no conoce = pipeline nuevo. No se adivina el nombre ni
// sus etapas: se reconstruye todo, que es lo único que los trae.
if (!isset($cache['proyectos'][$cid])) {
    rebuild_en_segundo_plano();
    ulog("u=$unitId proyecto NUEVO cat=$cid -> rebuild completo");
    echo 'rebuild-proyecto'; exit;
}

// nombre del stage: los STATUS_ID difieren por pipeline, se resuelve por nombre
$st = bx('crm.status.list', ['filter' => ['ENTITY_ID' => 'DYNAMIC_' . SPA_ENTITY . '_STAGE_' . $cid]]);
$nombreStage = '';
foreach (($st['result'] ?? []) as $s) {
    if ((string)$s['STATUS_ID'] === (string)($it['stageId'] ?? '')) {
        $nombreStage = strtoupper((string)$s['NAME']); break;
    }
}

$enum  = $cache['enum'] ?? [];
$title = (string)($it['title'] ?? '');
$nueva = [
    'id'     => (int)$it['id'],
    'codigo' => trim(explode('(', $title)[0]),
    'cat'    => $cid,
    'stage'  => $nombreStage,
    'm2'     => (string)($it[U_M2]  ?? ''),
    'pvp'    => (string)($it[U_PVP] ?? ''),
    'torre'  => $enum[U_TOR][(string)($it[U_TOR] ?? '')] ?? '',
    'piso'   => $enum[U_PIS][(string)($it[U_PIS] ?? '')] ?? '',
    'dealId' => (int)($it['parentId2'] ?? 0),
];

$reemplazada = false;
foreach ($cache['units'] as $i => $u) {
    if ((int)($u['id'] ?? 0) === $unitId) { $cache['units'][$i] = $nueva; $reemplazada = true; break; }
}
if (!$reemplazada) $cache['units'][] = $nueva;

@file_put_contents($CACHE, json_encode($cache), LOCK_EX);
ulog(($reemplazada ? 'ACTUALIZADA' : 'AGREGADA') . " u=$unitId {$nueva['codigo']} cat=$cid stage={$nombreStage}");
echo 'ok';
