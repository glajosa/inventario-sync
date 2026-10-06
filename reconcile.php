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
const CAMPO_NUEVO  = 'UF_CRM_1785205972989';   // campo "Inventario" (tipo propio, multi)
// Vacia a proposito: los userfields viejos ya se borraron y el campo nuevo es la
// unica fuente. Si se filtrara por un campo inexistente, Bitrix ignora el filtro
// y devuelve TODOS los deals (falsos positivos, no cero).
const FIELDS_EXTRA = [];

$DATA_DIR   = getenv('DATA_DIR') ?: '/data';
$LOG_FILE   = $DATA_DIR . '/sync.log';
$WEBHOOK_IN = rtrim((string)getenv('BITRIX_WEBHOOK'), '/') . '/';

set_time_limit(0);
$isHttp = PHP_SAPI !== 'cli';
if ($isHttp) {
    header('Content-Type: text/plain; charset=utf-8');
    $expect = (string)getenv('OUTBOUND_TOKEN');
    $got    = (string)($_GET['token'] ?? '');
    if ($expect === '' || !hash_equals($expect, $got)) { http_response_code(403); echo 'forbidden'; exit; }
}

require_once __DIR__ . '/stagelib.php';   // stages (Clientes re-afirmar + Cobranzas read-only)
require_once __DIR__ . '/libreta1072.php';

/* EN SECO (RECONCILE_SECO=1 o ?seco=1): corre todo, LEE de verdad, y no escribe nada —
   ni en Bitrix ni en disco. Cuenta cuantas escrituras HARIA. Sirve para saber cuantas
   unidades corregiria la logica nueva antes de dejarla escribir. */
$SECO = getenv('RECONCILE_SECO') === '1' || ($isHttp && ($_GET['seco'] ?? '') === '1');
$GLOBALS['RECONCILE_SECO'] = $SECO;
/* LOGICA NUEVA de etapas (decidir con la copia de la libreta y releer en Bitrix solo
   donde la copia dice que algo difiere). Perilla "reconcile_copia" en config.json;
   ?copia=1 / ?copia=0 la fuerza en una corrida a mano. Apagada por defecto hasta medir. */
$cfgRec = json_decode((string)@file_get_contents($DATA_DIR . '/config.json'), true) ?: [];
$COPIA = (int)($cfgRec['reconcile_copia'] ?? 0) === 1;
if ($isHttp && isset($_GET['copia'])) $COPIA = $_GET['copia'] === '1';

/* CANDADO: una sola corrida a la vez. Una corrida medida tardo 20 min y el cron es cada
   15: sin esto se pisan. flock lo suelta el sistema si el proceso muere, asi que no
   queda trabado; si la que corre lleva mas de 1 h se avisa en el log (no se mata). */
/* El cron corre como root y la web como www-data: el archivo puede quedar de uno y el
   otro no poder escribirlo. flock funciona igual sobre un archivo abierto solo para
   leer, asi que si no se puede 'c+' se abre 'r' y el candado vale para los dos. Y al
   crearlo se deja escribible por ambos. */
$lockPath = $DATA_DIR . '/reconcile.lock';
$lockNuevo = !is_file($lockPath);
$LOCK = @fopen($lockPath, 'c+') ?: @fopen($lockPath, 'r');
if ($LOCK && $lockNuevo) @chmod($lockPath, 0666);
if (!$LOCK) logline('RECONCILE 🔴 sin candado: no se pudo abrir ' . $lockPath);
if ($LOCK && !flock($LOCK, LOCK_EX | LOCK_NB)) {
    $info = json_decode((string)stream_get_contents($LOCK), true) ?: [];
    $edad = time() - (int)($info['desde'] ?? time());
    logline('RECONCILE omitido: otra corrida en curso (pid ' . ($info['pid'] ?? '?') . ", {$edad}s)"
        . ($edad > 3600 ? ' 🔴 lleva mas de 1 h' : ''));
    if ($isHttp) echo "ocupado\n";
    exit(0);
}
if ($LOCK) { @ftruncate($LOCK, 0); @fwrite($LOCK, json_encode(['pid' => getmypid(), 'desde' => time()])); @fflush($LOCK); }

/* CONTADOR de llamadas reales a Bitrix, por metodo (cada intento HTTP cuenta). Al
   terminar queda en el log y en /data/reconcile_llamadas.json (ultimas 30 corridas). */
$BX_N = []; $BX_W = []; $T0 = microtime(true);
register_shutdown_function(function () use ($T0) {
    global $BX_N, $BX_W, $SECO, $COPIA, $DATA_DIR;
    $tot = array_sum($BX_N);
    $fila = ['fin' => gmdate('c'), 'seg' => (int)round(microtime(true) - $T0), 'seco' => $SECO, 'copia' => $COPIA,
             'llamadas' => $tot, 'por_metodo' => $BX_N, 'escribiria' => $BX_W];
    logline('RECONCILE llamadas total=' . $tot . ' seg=' . $fila['seg'] . ' copia=' . ($COPIA ? 1 : 0)
        . ' ' . json_encode($BX_N) . ($SECO ? ' escribiria=' . json_encode($BX_W) : ''));
    $f = $DATA_DIR . '/reconcile_llamadas.json';
    $h = json_decode((string)@file_get_contents($f), true) ?: [];
    $h[] = $fila;
    @file_put_contents($f, json_encode(array_slice($h, -30), JSON_PRETTY_PRINT), LOCK_EX);
});

function logline(string $msg): void {
    global $LOG_FILE, $DATA_DIR;
    $line = gmdate('Y-m-d\TH:i:s\Z') . '  ' . (!empty($GLOBALS['RECONCILE_SECO']) ? '[SECO] ' : '') . $msg . "\n";
    // Por cron corre como root y escribe en sync.log; por HTTP corre como Apache,
    // que NO puede escribir ese archivo y perdía toda la traza en silencio.
    if (@file_put_contents($LOG_FILE, $line, FILE_APPEND | LOCK_EX) === false) {
        @file_put_contents($DATA_DIR . '/web.log', $line, FILE_APPEND | LOCK_EX);
    }
}

function bx(string $method, array $params = []): array {
    global $WEBHOOK_IN, $BX_N, $BX_W;
    // en seco no se escribe: se cuenta y se contesta "ok" para que el flujo siga igual
    if (!empty($GLOBALS['RECONCILE_SECO']) && preg_match('/\.(update|add|delete|set)$/', $method)) {
        $BX_W[$method] = ($BX_W[$method] ?? 0) + 1;
        return ['ok' => true, 'result' => true, 'next' => null];
    }
    // throttle base: ~3-4 req/s para no vaciar el pool de Bitrix en los barridos
    usleep(250000);
    for ($try = 0; $try < 5; $try++) {
        $BX_N[$method] = ($BX_N[$method] ?? 0) + 1;
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

// ---- DESEADO: unidad => deal ---------------------------------------------------
// PRECEDENCIA, no unión: para cada deal, si el campo nuevo "Inventario" tiene
// algo, ese manda y los viejos Inv 2/3/4 se ignoran. Si está vacío, se usan los
// viejos (deals sin migrar).
//
// Antes se hacía la UNIÓN de las dos fuentes, y eso era un error de fondo: en un
// deal ya migrado los dos campos están llenos, así que al quitar una unidad del
// campo nuevo este barrido la veía todavía en el viejo y la volvía a atar cada
// 15 minutos. Quitar una unidad era imposible.
$desired  = [];   // unitId => dealId
$migrados = [];   // dealId => true  (tiene campo nuevo con valor: manda ese)

// 1) campo nuevo primero (varias unidades separadas por coma en un solo campo)
$start = 0;
do {
    $r = bx('crm.deal.list', [
        'filter' => ['CATEGORY_ID' => CATEGORY_ID, '!' . CAMPO_NUEVO => ''],
        'select' => ['ID', 'STAGE_ID', CAMPO_NUEVO],
        'start'  => $start,
    ]);
    if (!$r['ok']) { logline('RECONCILE ERR list(campo nuevo): ' . $r['error']); break; }
    foreach (($r['result'] ?? []) as $d) {
        $dealId = (string)$d['ID'];
        // Deal caído: no desea ninguna unidad. Sin esto el barrido volvía a atar
        // cada 15 min lo que la caída acababa de soltar.
        if (etapa_libera((string)($d['STAGE_ID'] ?? ''))) continue;
        foreach (preg_split('/[,;\s]+/', (string)($d[CAMPO_NUEVO] ?? '')) as $x) {
            $x = trim($x);
            if ($x !== '' && ctype_digit($x) && (int)$x > 0) {
                $desired[(int)$x]    = $dealId;
                $migrados[$dealId]   = true;
            }
        }
    }
    $start = $r['next'] ?? null;
} while ($start !== null && $start !== '');

// 2) campos viejos, SOLO para los deals que no tienen campo nuevo
foreach (FIELDS_EXTRA as $f) {
    $r = bx('crm.deal.list', [
        'filter' => ['CATEGORY_ID' => CATEGORY_ID, '!' . $f => ''],
        'select' => array_merge(['ID'], FIELDS_EXTRA),
    ]);
    if (!$r['ok']) { logline("RECONCILE ERR list($f): {$r['error']}"); if ($isHttp) echo "err\n"; exit(1); }
    foreach (($r['result'] ?? []) as $d) {
        $dealId = (string)$d['ID'];
        if (isset($migrados[$dealId])) continue;      // el campo nuevo ya decidió
        foreach (FIELDS_EXTRA as $ff) {
            $v = $d[$ff] ?? '';
            if ($v !== '' && $v !== null && (int)$v > 0) $desired[(int)$v] = $dealId;
        }
    }
}

// ---- ACTUAL: unidad => deal, según parentId2 de las unidades ------------------
// OJO: el filtro `!parentId2 => 0` Bitrix lo IGNORA (verificado), así que esta
// paginación recorre TODAS las unidades. Ya que se pagan las llamadas igual, se
// aprovecha para leer también el stage: es lo que necesita el barrido de huérfanas
// de más abajo, y así no cuesta ni una llamada extra.
// Sin `select` a propósito: con select explícito Bitrix devuelve id en null.
$actual  = [];   // unitId => dealId  (solo las que SÍ tienen parentId2)
$stageDe = [];   // unitId => nombre del stage
$revStage = [];
foreach (stages_map() as $c => $m) foreach ($m as $n => $sid) $revStage[$sid] = $n;

/* TODAS las unidades, UNA vez por corrida, y de la libreta. Antes eran dos barridos
   de ~31 crm.item.list cada uno (este y el de STAGES de abajo). Si la libreta no
   sirve, un solo barrido en Bitrix y el motivo queda anotado. */
function unidades_todas(): ?array {
    $motivo = '';
    $u = l1072_todas($motivo);
    l1072_contar('reconcile', $u !== null, $motivo);
    if ($u !== null) return $u;
    logline("RECONCILE libreta -> bitrix: $motivo");
    $todas = []; $start = 0;
    do {
        $r = bx('crm.item.list', ['entityTypeId' => SPA_ENTITY, 'order' => ['id' => 'ASC'], 'start' => $start]);
        if (!$r['ok']) { logline("RECONCILE ERR item.list: {$r['error']}"); return null; }
        foreach (($r['result']['items'] ?? []) as $it) $todas[] = $it;
        $start = $r['next'] ?? null;
    } while ($start !== null && $start !== '');
    return $todas;
}
$TODAS = unidades_todas();
if ($TODAS === null) { if ($isHttp) echo "err\n"; exit(1); }
foreach ($TODAS as $it) {
    $uid = (int)($it['id'] ?? 0);
    if (!$uid) continue;
    $p = (int)($it['parentId2'] ?? 0);
    if ($p > 0) $actual[$uid] = (string)$p;
    $stageDe[$uid] = $revStage[(string)($it['stageId'] ?? '')] ?? '';
}

// ---- DIFF y aplicar solo lo que difiere --------------------------------------
$cambios = 0;
/* La copia de la libreta va ~30 s atrasada: antes de CADA escritura se relee la unidad
   en Bitrix y se decide con ese dato (l1072_decidir_set / _clear). Asi no se reescribe
   un valor que ya esta bien ni se suelta una unidad que alguien acaba de mover. Solo
   cuesta un get por diferencia, y normalmente no hay ninguna. */
function parent_fresco(int $unit): ?int {
    $g = bx('crm.item.get', ['entityTypeId' => SPA_ENTITY, 'id' => $unit]);
    if (!$g['ok']) return null;
    $it = $g['result']['item'] ?? $g['result'];
    return is_array($it) ? (int)($it['parentId2'] ?? 0) : null;
}
// unidades que deben apuntar a un deal (o cambiar de deal)
foreach ($desired as $unit => $deal) {
    if (!isset($actual[$unit]) || $actual[$unit] !== $deal) {
        $dec = l1072_decidir_set(parent_fresco((int)$unit), (string)$deal);
        if ($dec !== 'escribir') { logline("RECONCILE set u=$unit -> deal=$deal OMITIDO ($dec en Bitrix)"); continue; }
        $u = bx('crm.item.update', ['entityTypeId' => SPA_ENTITY, 'id' => $unit, 'fields' => ['parentId2' => $deal]]);
        if ($u['ok']) { $cambios++; logline("RECONCILE set u=$unit -> deal=$deal"); }
    }
}
// unidades atadas que ya nadie desea -> soltar
foreach ($actual as $unit => $deal) {
    if (!isset($desired[$unit])) {
        $dec = l1072_decidir_clear(parent_fresco((int)$unit), (string)$deal);
        if ($dec !== 'escribir') { logline("RECONCILE clear u=$unit OMITIDO ($dec en Bitrix; la copia decia deal=$deal)"); continue; }
        $u = bx('crm.item.update', ['entityTypeId' => SPA_ENTITY, 'id' => $unit, 'fields' => ['parentId2' => 0]]);
        if ($u['ok']) { $cambios++; logline("RECONCILE clear u=$unit (era deal=$deal)"); }
    }
}

// ==== APARTADOS de Prospectos(28) =============================================
// El 28 NO va por el webhook en tiempo real a propósito: es el embudo de leads y
// tiene muchísimas ediciones (Wazzup, formularios); procesarlas todas satura el
// API, igual que pasaría con Cobranzas. El apartado se pone al instante desde el
// campo (guardar.php) y esto es la red de seguridad que lo suelta.
//
// Solo se liberan unidades que ESTE sistema apartó (registro en disco). Las que
// ya venían ocupadas sin dueño de antes NO se tocan: liberarlas sería decidir
// sobre datos reales del negocio por cuenta propia.
$apartCambios = 0;
$puestos  = apartados_puestos();
$vigentes = null;
if ($puestos) {
    $fiable   = true;
    $vigentes = apartados_28($fiable);
    // Si la lista no es fiable NO se libera nada: soltar por un error de consulta
    // dejaría libres unidades que un vendedor acaba de apartar.
    if (!$fiable) {
        logline('RECONCILE apartados: lista no fiable, no se libera nada');
        $vigentes = null;
    }
}
if ($puestos && $vigentes !== null) {
    $quedan = [];
    foreach ($puestos as $uid => $dealDe) {
        if (isset($vigentes[(int)$uid])) { $quedan[$uid] = $dealDe; continue; }
        if (isset($desired[(int)$uid]))  continue;   // CLIENTES ya la tomó de verdad
        if (liberar_apartado((int)$uid)) {
            $apartCambios++;
            logline("RECONCILE apartado liberado u=$uid (era deal28=$dealDe)");
        } else {
            $quedan[$uid] = $dealDe;                 // no se pudo, se reintenta luego
        }
    }
    if ($quedan !== $puestos && !$SECO) apartados_puestos_guardar($quedan);
}

// ==== HUÉRFANAS: ocupadas que NADIE reclama ===================================
// El error que más cuesta no es una unidad tomada que se ve libre, es una unidad
// LIBRE que se ve tomada: esconde inventario vendible y nadie se entera. Pasa
// cuando se borra un deal sin vaciar su campo, o cuando alguien la arrastra en el
// kanban del SPA.
//
// Tres condiciones a la vez, para no liberar nada por error:
//   1. stage RESERVADO o FIRMADO   (VENDIDO lo manda Cobranzas, que empareja por
//      código+contacto y no por parentId2: aquí saldría huérfana sin serlo.
//      BLOQUEADO y PERDIDO son gerenciales.)
//   2. sin parentId2
//   3. ningún deal la nombra: ni en $desired (CLIENTES) ni apartada desde el 28.
//
// Coste: 0 llamadas extra para detectarlas (reusa la paginación de arriba y el
// apartados_28() que ya se consultó). Solo se paga al corregir.
$huerfanas = 0;
// La lista de apartados TIENE que ser fiable: si viene a medias, unidades
// apartadas de verdad parecerían huérfanas y se liberarían. Antes que eso, no se
// barre. (Mismo criterio que el bloque de apartados de arriba.)
$apFiable  = true;
$reclamaAp = ($vigentes !== null) ? $vigentes : apartados_28($apFiable);
if (!$apFiable) {
    logline('RECONCILE huerfanas: lista de apartados no fiable, no se barre');
    $reclamaAp = null;
}
foreach (($reclamaAp === null ? [] : $stageDe) as $uid => $st) {
    if ($st !== 'RESERVADO' && $st !== 'FIRMADO') continue;
    if (isset($actual[$uid]))     continue;   // tiene parentId2
    if (isset($desired[$uid]))    continue;   // un deal de CLIENTES la nombra
    if (isset($reclamaAp[$uid]))  continue;   // apartada desde Prospectos
    if (apply_unit_stage((int)$uid, null, 'DISPONIBLE', false)) {
        $huerfanas++;
        logline("RECONCILE huerfana u=$uid estaba $st sin deal que la reclame -> DISPONIBLE");
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
foreach ([$TODAS] as $items) {   // las mismas de arriba: cero llamadas mas
    foreach ($items as $it) {
        $code = norm_code(explode('(', (string)($it['title'] ?? ''))[0]);
        if ($code === '') continue;
        $codeSet[$code] = true;
        $contact = (string)($it['contactId'] ?? '');
        if ($contact !== '' && $contact !== '0') {
            $unitByKey[$code . '|' . $contact] = ['id' => (int)$it['id'], 'cat' => (string)($it['categoryId'] ?? '')];
        }
    }
}

// --- A) CLIENTES (44) re-afirmar PRIMERO (por si se perdió un evento del hook) ----
// Solo PROMESA FIRMADA y FIRMADOS-CAIDOS (pocos deals). RESERVA se OMITE: es no-op
// casi siempre (migración ya puso RESERVADO, el hook lo mantiene) y barrer todos los
// deals en RESERVA con un get por unidad es carísimo. El hook cubre RESERVA en vivo.
/* Con la perilla reconcile_copia: los deals salen de la libreta y cada unidad se mira
   primero en la copia ($TODAS). Solo se va a Bitrix (get fresco + escribir) donde la copia
   dice que la etapa difiere. Si la libreta no da la lista de esa etapa, como antes. */
$porId = []; $porDeal = []; $saltadas = 0; $releidas = 0;
if ($COPIA) foreach ($TODAS as $it) {
    $iid = (int)($it['id'] ?? 0); $porId[$iid] = $it;
    $pp = (int)($it['parentId2'] ?? 0); if ($pp > 0) $porDeal[$pp][] = $iid;
}
foreach (CLIENTES_TRIGGERS as $stageId => $target) {
    if ($stageId === 'C44:NEW') continue;   // RESERVA: omitir en el barrido
    $dealsCopia = null;
    if ($COPIA) {
        $mot = '';
        $dealsCopia = l1072_deals(CLIENTES_CAT, $stageId, $mot);
        l1072_contar('reconcile_deals', $dealsCopia !== null, $mot);
        if ($dealsCopia === null) logline("RECONCILE clientes($stageId) libreta -> bitrix: $mot");
    }
    if ($dealsCopia !== null) {
        foreach ($dealsCopia as $d) {
            foreach (l1072_unidades_del_deal($d, $porDeal, CAMPO_NUEVO) as $uid) {
                $ic  = $porId[$uid] ?? null;
                $dec = l1072_decidir_etapa($ic, $ic ? stage_objetivo($uid, $ic, $target, false) : null, $target, (string)$d['ID']);
                if ($dec === 'saltar') { $saltadas++; continue; }
                $releidas++;
                if ($target === 'DISPONIBLE' && !puede_liberar((int)$uid, (string)$d['ID'])) continue;
                if (apply_unit_stage((int)$uid, null, $target, false)) $stageCambios++;
            }
        }
        continue;
    }
    $start = 0;
    do {
        $r = bx('crm.deal.list', [
            'filter' => ['CATEGORY_ID' => CLIENTES_CAT, 'STAGE_ID' => $stageId],
            // CAMPO_NUEVO va en el select: units_of_clientes_deal() lo lee de aquí.
            // Sin él, los deals que viven en el campo nuevo no re-afirmaban stage.
            'select' => ['ID', 'PARENT_ID_1072', CAMPO_NUEVO, 'STAGE_ID', 'ASSIGNED_BY_ID', 'CONTACT_ID'],
            'start'  => $start,
        ]);
        if (!$r['ok']) { logline("RECONCILE ERR clientes($stageId): {$r['error']}"); break; }
        foreach (($r['result'] ?? []) as $d) {
            foreach (units_of_clientes_deal((string)$d['ID'], $d) as $uid) {
                // owner-sync NO aquí (get por unidad = muy pesado en barrido); lo hace el hook en vivo.
                // Soltar solo si la unidad sigue siendo de este deal: un deal caído
                // que nombra una unidad ya revendida no debe liberarla.
                if ($target === 'DISPONIBLE' && !puede_liberar((int)$uid, (string)$d['ID'])) continue;
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
    $dealsCopia = null;
    if ($COPIA) {
        $mot = '';
        $dealsCopia = l1072_deals(COBRANZAS_CAT, $stageId, $mot);
        l1072_contar('reconcile_deals', $dealsCopia !== null, $mot);
        if ($dealsCopia === null) logline("RECONCILE cobranzas($stageId) libreta -> bitrix: $mot");
    }
    if ($dealsCopia !== null) {
        foreach ($dealsCopia as $d) {
            $code = norm_code((string)($d[COBRANZAS_CODE_FIELD] ?? ''));
            if ($code === '' || !isset($codeSet[$code])) continue;
            $contact = (string)($d['CONTACT_ID'] ?? '');          // de la copia: sin crm.deal.get
            if ($contact === '' || $contact === '0') continue;
            $key = $code . '|' . $contact;
            if (!isset($unitByKey[$key])) continue;                  // no matchea código+contacto -> NO tocar
            $uid = (int)$unitByKey[$key]['id'];
            $ic  = $porId[$uid] ?? null;
            $dec = l1072_decidir_etapa($ic, $ic ? stage_objetivo($uid, $ic, $target, $writeOff) : null, $target, '');
            if ($dec === 'saltar') { $saltadas++; continue; }
            $releidas++;
            if (apply_unit_stage($uid, null, $target, $writeOff)) $stageCambios++;
        }
        continue;
    }
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

$msg = 'RECONCILE ok huerfanas=' . $huerfanas . ' desired=' . count($desired) . ' actual=' . count($actual)
     . " link_cambios=$cambios stage_cambios=$stageCambios"
     . ($COPIA ? " copia: saltadas=$saltadas releidas=$releidas" : '');
logline($msg);
if ($isHttp) echo $msg;

// Red de las reubicaciones PENDIENTES (6-oct): unidad elegida en un 48 que no entró a REUBICACIÓN en 10 min ->
// se devuelve el campo (o se aplica si ya entró). Vive en hook48.php porque este archivo tiene sus propios
// bx()/logline() y no puede cargar reubicalib. Fail-open: si falla, el próximo reconcile lo reintenta.
$tokB = (string)getenv('OUTBOUND_TOKEN');
if ($tokB !== '' && !$SECO) {   // en seco no se dispara: hook48 escribe
    $chB = curl_init('http://127.0.0.1/hook48.php?barrer=1');   // la llave va en el CUERPO, no en la URL (no queda en el access log)
    curl_setopt_array($chB, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query(['auth' => ['application_token' => $tokB]]),
                             CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_CONNECTTIMEOUT => 3]);
    $rB = curl_exec($chB); $cB = (int)curl_getinfo($chB, CURLINFO_HTTP_CODE); unset($chB);
    logline('RECONCILE pendientes de reubicacion: http ' . $cB . ' ' . substr((string)$rB, 0, 60));
}
