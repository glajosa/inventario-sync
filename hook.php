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
const SPA_ENTITY    = 1072;                      // SPA Inventario
const CAMPO_NUEVO   = 'UF_CRM_1785205972989';    // campo "Inventario" (tipo propio, multi)
const FIELDS_EXTRA  = [                          // "Inventario 2/3/4" en el deal
    'UF_CRM_DEAL_1784994996',
    'UF_CRM_DEAL_1784995021',
    'UF_CRM_DEAL_1784995044',
];

$DATA_DIR      = getenv('DATA_DIR')      ?: '/data';        // volumen persistente
$ALLOWLIST     = $DATA_DIR . '/allowlist.json';            // IDs de deals P44
$LOG_FILE      = $DATA_DIR . '/sync.log';
$WEBHOOK_IN    = rtrim((string)getenv('BITRIX_WEBHOOK'), '/') . '/'; // webhook ENTRANTE
$EXPECT_TOKEN  = (string)getenv('OUTBOUND_TOKEN');         // token del webhook SALIENTE

require_once __DIR__ . '/stagelib.php';   // lógica de STAGE (Clientes tiempo real)

// ---- Utilidades -------------------------------------------------------------
function logline(string $msg): void {
    global $LOG_FILE;
    // timestamp sin depender de tz del server: se guarda epoch + ISO UTC
    $line = gmdate('Y-m-d\TH:i:s\Z') . '  ' . $msg . "\n";
    @file_put_contents($LOG_FILE, $line, FILE_APPEND | LOCK_EX);
}

/** Llama al webhook ENTRANTE de Bitrix. Devuelve ['ok'=>bool,'result'=>mixed,'error'=>str]. */
function bx(string $method, array $params = []): array {
    global $WEBHOOK_IN;
    $url = $WEBHOOK_IN . $method;
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($params),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $raw  = curl_exec($ch);
    $errno = curl_errno($ch);
    curl_close($ch);
    if ($errno) return ['ok' => false, 'error' => "curl:$errno"];
    $j = json_decode((string)$raw, true);
    if (!is_array($j))          return ['ok' => false, 'error' => 'bad-json'];
    if (isset($j['error']))     return ['ok' => false, 'error' => $j['error'] . ':' . ($j['error_description'] ?? '')];
    return ['ok' => true, 'result' => $j['result'] ?? null];
}

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

if ($dealId === '') { echo 'no-id'; exit; }

// ---- 2) DELETE: soltar unidades atadas a ese deal ---------------------------
if ($event === 'ONCRMDEALDELETE') {
    allowlist_remove($dealId);
    // soltar cualquier unidad que apuntara a este deal
    $r = bx('crm.item.list', [
        'entityTypeId' => SPA_ENTITY,
        'filter'       => ['parentId2' => $dealId],
        'select'       => ['id'],
    ]);
    if ($r['ok']) {
        foreach (($r['result']['items'] ?? []) as $it) {
            bx('crm.item.update', ['entityTypeId' => SPA_ENTITY, 'id' => $it['id'],
                                   'fields' => ['parentId2' => 0]]);
        }
    }
    logline("DELETE deal=$dealId -> unidades liberadas");
    echo 'ok-delete';
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
    } else {
        echo 'ok-add-skip';                  // no es P44, fuera. 1 get gastado, aceptable.
        exit;
    }
}

// ---- 4) UPDATE (y ADD ya validado): filtro por lista blanca -----------------
if (!isset($allow[$dealId])) {
    // no es de P44 => CERO llamadas. Aquí se descarta el 99% del ruido del portal.
    echo 'skip-not-p44';
    exit;
}

// ---- 5) Es P44: leer los 3 campos extra y sincronizar -----------------------
$r = bx('crm.deal.get', ['id' => $dealId]);           // 1 llamada
if (!$r['ok']) { logline("ERR get deal=$dealId: {$r['error']}"); echo 'err-get'; exit; }
$deal = $r['result'];

// Unidades que el deal DICE tener.
// UNIÓN de las dos fuentes: el campo nuevo "Inventario" (varias unidades en uno)
// y los viejos Inventario 2/3/4. Mientras existan las dos, hay que respetar ambas:
// si solo se mirara la nueva, los 778 deals que aún viven en los campos viejos
// verían sus unidades DESATADAS; y si solo se miraran las viejas, este hook
// borraba lo que acababa de guardar el campo nuevo (era el caso real).
$quieren = [];
foreach (FIELDS_EXTRA as $f) {
    $v = $deal[$f] ?? '';
    if ($v !== '' && $v !== null && (int)$v > 0) $quieren[(int)$v] = true;
}
foreach (preg_split('/[,;\s]+/', (string)($deal[CAMPO_NUEVO] ?? '')) as $x) {
    $x = trim($x);
    if ($x !== '' && ctype_digit($x) && (int)$x > 0) $quieren[(int)$x] = true;
}
$quieren = array_keys($quieren);   // ids de unidades objetivo

// unidades que HOY apuntan a este deal via parentId2
$r = bx('crm.item.list', [                            // 1 llamada
    'entityTypeId' => SPA_ENTITY,
    'filter'       => ['parentId2' => $dealId],
    'select'       => ['id'],
]);
$tienen = [];
if ($r['ok']) foreach (($r['result']['items'] ?? []) as $it) $tienen[(int)$it['id']] = true;
$tienen = array_keys($tienen);

// diffs
$agregar = array_values(array_diff($quieren, $tienen)); // atar
$soltar  = array_values(array_diff($tienen, $quieren)); // desatar (ya no está en los campos)

$cambios = 0;
foreach ($agregar as $uid) {
    $u = bx('crm.item.update', ['entityTypeId' => SPA_ENTITY, 'id' => $uid,
                                'fields' => ['parentId2' => $dealId]]);
    if ($u['ok']) $cambios++; else logline("ERR set u=$uid deal=$dealId: {$u['error']}");
}
foreach ($soltar as $uid) {
    $u = bx('crm.item.update', ['entityTypeId' => SPA_ENTITY, 'id' => $uid,
                                'fields' => ['parentId2' => 0]]);
    if ($u['ok']) $cambios++; else logline("ERR unset u=$uid deal=$dealId: {$u['error']}");
}

if ($cambios > 0) {
    logline(sprintf('SYNC deal=%s +[%s] -[%s]', $dealId,
        implode(',', $agregar), implode(',', $soltar)));
}

// ---- 6) STAGE + RESPONSABLE/CLIENTE de las unidades del deal de Clientes -----
// Para cada unidad atada a este deal: copia el asesor (responsable) y el contacto
// (cliente) del deal a la unidad, y mueve el stage según la etapa del deal.
$deal_units  = units_of_clientes_deal($dealId, $deal);
$stage       = (string)($deal['STAGE_ID'] ?? '');
$stageTarget = CLIENTES_TRIGGERS[$stage] ?? null;
$stageCambios = 0;
foreach ($deal_units as $uid) {
    sync_unit_owner((int)$uid, $deal);                                    // asesor + contacto
    if ($stageTarget && apply_unit_stage((int)$uid, null, $stageTarget, false)) $stageCambios++;
}
// unidades que se acaban de SOLTAR de este deal -> DISPONIBLE (perdieron su deal)
foreach ($soltar as $uid) {
    apply_unit_stage((int)$uid, null, 'DISPONIBLE', false);
}

echo "ok-sync changes=$cambios stage=$stageCambios";
