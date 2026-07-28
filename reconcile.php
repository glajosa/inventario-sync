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

function logline(string $msg): void {
    global $LOG_FILE, $DATA_DIR;
    $line = gmdate('Y-m-d\TH:i:s\Z') . '  ' . $msg . "\n";
    // Por cron corre como root y escribe en sync.log; por HTTP corre como Apache,
    // que NO puede escribir ese archivo y perdía toda la traza en silencio.
    if (@file_put_contents($LOG_FILE, $line, FILE_APPEND | LOCK_EX) === false) {
        @file_put_contents($DATA_DIR . '/web.log', $line, FILE_APPEND | LOCK_EX);
    }
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
    if ($quedan !== $puestos) apartados_puestos_guardar($quedan);
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
// Solo PROMESA FIRMADA y FIRMADOS-CAIDOS (pocos deals). RESERVA se OMITE: es no-op
// casi siempre (migración ya puso RESERVADO, el hook lo mantiene) y barrer todos los
// deals en RESERVA con un get por unidad es carísimo. El hook cubre RESERVA en vivo.
foreach (CLIENTES_TRIGGERS as $stageId => $target) {
    if ($stageId === 'C44:NEW') continue;   // RESERVA: omitir en el barrido
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
