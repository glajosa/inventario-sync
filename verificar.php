<?php
/**
 * verificar.php — foto del estado del inventario, solo lectura.
 * ---------------------------------------------------------------------------
 * Responde las preguntas que uno se hace después de migrar o cuando algo se ve
 * raro, sin tener que abrir Bitrix a mano:
 *
 *   - ¿cuántos deals de CLIENTES tienen el campo "Inventario" con algo?
 *   - ¿queda algún deal con los campos viejos llenos?
 *   - ¿cuántas unidades tienen la dependencia (parentId2) puesta?
 *   - ¿alguna unidad la reclaman dos deals?
 *   - ¿alguna unidad quedó ocupada (RESERVADO/FIRMADO) sin dueño, o libre con dueño?
 *
 * No escribe NADA. Protegido por token.
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);
set_time_limit(0);
require_once __DIR__ . '/campolib.php';

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

$esperado = (string)getenv('OUTBOUND_TOKEN');
if ($esperado === '' || !hash_equals($esperado, (string)($_GET['token'] ?? ''))) {
    http_response_code(403); exit('forbidden');
}

// Los 4 userfields de Inventario (UF_CRM_1782666709 y UF_CRM_DEAL_17849950*)
// se BORRARON al terminar la migración, así que salen de esta lista.
// Por qué importa: si se filtra por un campo que no existe, Bitrix IGNORA el
// filtro y devuelve TODOS los deals. Dejarlos aquí no daba "0 resultados": daba
// falsos positivos masivos, que es peor que no chequear.
const VIEJOS_V = ['PARENT_ID_1072'];

// ?viejos=1 -> ¿queda algún deal, de CUALQUIER pipeline, con los campos viejos
// llenos? Es el chequeo obligatorio antes de borrarlos: borrar un campo en Bitrix
// borra sus datos y no se deshace.
if (!empty($_GET['viejos'])) {
    foreach (VIEJOS_V as $f) {
        $total = 0; $ejem = [];
        $start = 0;
        do {
            $r = bx('crm.deal.list', ['filter' => ['!' . $f => ''],
                                      'select' => ['ID', 'CATEGORY_ID', $f], 'start' => $start]);
            if (!$r['ok']) { echo "$f: ERROR {$r['error']}\n"; break; }
            foreach (($r['result'] ?? []) as $d) {
                $v = $d[$f] ?? '';
                if ($v === '' || $v === null || (int)$v <= 0) continue;
                $total++;
                if (count($ejem) < 8) $ejem[] = "deal {$d['ID']}(cat {$d['CATEGORY_ID']})=$v";
            }
            $start = $r['next'] ?? null;
        } while ($start !== null && $start !== '');
        echo str_pad($f, 26) . ": $total" . ($ejem ? '  -> ' . implode('  ', $ejem) : '') . "\n";
    }
    exit;
}


// ?huerfanas=1 -> unidades que dicen NO estar disponibles pero nadie las reclama.
// Es el error que mas cuesta: esconden inventario vendible. Tres condiciones, y
// las tres a la vez: stage ocupado, sin parentId2, y NINGUN deal las nombra en su
// campo Inventario. Con &aplicar=1 se devuelven a DISPONIBLE.
if (!empty($_GET['huerfanas'])) {
    $aplicar = !empty($_GET['aplicar']);
    echo $aplicar ? "MODO: APLICAR\n\n" : "MODO: SIMULACRO (no escribe)\n\n";

    // 1) todo lo que algun deal de 28/44 nombra en su campo (0 riesgo de falso positivo)
    $reclamadas = [];
    $start = 0;
    do {
        $r = bx('crm.deal.list', [
            'filter' => ['@CATEGORY_ID' => [PROSPECTOS_CAT, CLIENTES_CAT], '!' . CAMPO_NUEVO => ''],
            'select' => ['ID', CAMPO_NUEVO], 'order' => ['ID' => 'ASC'], 'start' => $start,
        ]);
        if (!$r['ok']) { echo "ERROR listando deals: {$r['error']}\n"; exit; }
        foreach (($r['result'] ?? []) as $d) {
            foreach (ids_de((string)($d[CAMPO_NUEVO] ?? '')) as $u) $reclamadas[$u] = (string)$d['ID'];
        }
        $start = $r['next'] ?? null;
    } while ($start !== null && $start !== '');

    $apart = apartados_28();
    $rev = [];
    foreach (stages_map() as $c => $m) foreach ($m as $n => $sid) $rev[$sid] = $n;

    // 2) recorrer unidades y quedarse con las huerfanas ocupadas
    $malas = [];
    $start = 0;
    do {
        $r = bx('crm.item.list', ['entityTypeId' => SPA_ENTITY, 'order' => ['id' => 'ASC'], 'start' => $start]);
        if (!$r['ok']) break;
        foreach (($r['result']['items'] ?? []) as $it) {
            $id    = (int)($it['id'] ?? 0);
            $stage = $rev[(string)($it['stageId'] ?? '')] ?? '?';
            // VENDIDO lo manda Cobranzas (empareja por codigo+contacto, no por
            // parentId2): aqui saldria huerfana sin serlo. BLOQUEADO/PERDIDO son
            // gerenciales. Ninguno se toca.
            if (!in_array($stage, ['RESERVADO', 'FIRMADO'], true)) continue;
            if ((int)($it['parentId2'] ?? 0) > 0) continue;
            if (isset($apart[$id]))      continue;
            if (isset($reclamadas[$id])) continue;
            $malas[$id] = ['codigo' => trim(explode('(', (string)($it['title'] ?? ''))[0]),
                           'cat' => (string)($it['categoryId'] ?? ''), 'stage' => $stage,
                           'contacto' => (int)($it['contactId'] ?? 0)];
        }
        $start = $r['next'] ?? null;
    } while ($start !== null && $start !== '');

    echo 'unidades reclamadas por algun deal : ' . count($reclamadas) . "\n";
    echo 'HUERFANAS ocupadas sin reclamo     : ' . count($malas) . "\n\n";
    $proys = json_decode((string)@file_get_contents(($DATA_DIR ?: '/data') . '/selector_cache.json'), true)['proyectos'] ?? [];
    foreach ($malas as $id => $m) {
        echo "  u=$id  {$m['codigo']}  " . ($proys[$m['cat']] ?? ('cat ' . $m['cat']))
           . "  {$m['stage']}" . ($m['contacto'] ? "  contacto={$m['contacto']}" : '') . "\n";
    }
    if ($aplicar && $malas) {
        echo "\n-- devolviendo a DISPONIBLE --\n";
        foreach (array_keys($malas) as $id) {
            $ok = apply_unit_stage((int)$id, null, 'DISPONIBLE', false);
            echo "  u=$id " . ($ok ? 'DISPONIBLE' : 'sin cambio') . "\n";
        }
    }
    if (!$aplicar && $malas) echo "\n(simulacro: agregar &aplicar=1 para devolverlas a DISPONIBLE)\n";
    exit;
}

// ?poretapa=1 -> deals de CLIENTES con unidad en el campo, agrupados por etapa,
// y en qué stage está cada unidad. Sirve para medir el alcance de una regla antes
// de que el barrido la aplique (ej: cuántas reservas caídas van a liberar unidad).
if (!empty($_GET['poretapa'])) {
    $porEtapa = [];      // stage del deal => [deals, unidades, unidades por stage]
    $start = 0;
    do {
        $r = bx('crm.deal.list', [
            'filter' => ['CATEGORY_ID' => CLIENTES_CAT, '!' . CAMPO_NUEVO => ''],
            'select' => ['ID', 'STAGE_ID', CAMPO_NUEVO],
            'order'  => ['ID' => 'ASC'],
            'start'  => $start,
        ]);
        if (!$r['ok']) { echo "ERROR: {$r['error']}\n"; break; }
        foreach (($r['result'] ?? []) as $d) {
            $ids = ids_de((string)($d[CAMPO_NUEVO] ?? ''));
            if (!$ids) continue;
            $e = (string)($d['STAGE_ID'] ?? '?');
            $porEtapa[$e]['deals'] = ($porEtapa[$e]['deals'] ?? 0) + 1;
            foreach ($ids as $u) $porEtapa[$e]['units'][] = $u;
        }
        $start = $r['next'] ?? null;
    } while ($start !== null && $start !== '');

    // stage real de cada unidad, para saber cuántas siguen ocupadas
    $rev = [];
    foreach (stages_map() as $c => $m) foreach ($m as $n => $sid) $rev[$sid] = $n;
    $stageDe = [];
    $start = 0;
    do {
        $r = bx('crm.item.list', ['entityTypeId' => SPA_ENTITY, 'order' => ['id' => 'ASC'], 'start' => $start]);
        if (!$r['ok']) break;
        foreach (($r['result']['items'] ?? []) as $it) {
            $stageDe[(int)($it['id'] ?? 0)] = $rev[(string)($it['stageId'] ?? '')] ?? '?';
        }
        $start = $r['next'] ?? null;
    } while ($start !== null && $start !== '');

    $nombres = array_flip(CLIENTES_TRIGGERS);   // solo para anotar el efecto
    foreach ($porEtapa as $e => $x) {
        $us = $x['units'] ?? [];
        $cnt = [];
        foreach ($us as $u) { $s = $stageDe[$u] ?? '?'; $cnt[$s] = ($cnt[$s] ?? 0) + 1; }
        ksort($cnt);
        $efecto = CLIENTES_TRIGGERS[$e] ?? '(sin regla)';
        echo str_pad($e, 16) . ' deals=' . str_pad((string)$x['deals'], 4)
           . ' unidades=' . str_pad((string)count($us), 4)
           . ' -> regla: ' . str_pad($efecto, 11) . '  ';
        foreach ($cnt as $s => $n) echo "$s:$n ";
        echo "\n";
    }
    exit;
}

// ?unidad=1287[,1289] -> rastrea una unidad: su estado y qué deals la nombran,
// en CUALQUIER pipeline. Para saber si una unidad ocupada tiene dueño real.
if (!empty($_GET['unidad'])) {
    $rev0 = [];
    foreach (stages_map() as $c => $m) foreach ($m as $n => $sid) $rev0[$sid] = $n;
    foreach (explode(',', (string)$_GET['unidad']) as $uu) {
        $uu = (int)trim($uu); if ($uu <= 0) continue;
        $g = bx('crm.item.get', ['entityTypeId' => SPA_ENTITY, 'id' => $uu]);
        $it = $g['result']['item'] ?? $g['result'] ?? [];
        echo "unidad $uu  " . ($it['title'] ?? '?')
           . "\n  stage    : " . ($rev0[(string)($it['stageId'] ?? '')] ?? ('?' . ($it['stageId'] ?? '')))
           . "\n  parentId2: " . var_export($it['parentId2'] ?? null, true)
           . "\n  contactId: " . var_export($it['contactId'] ?? null, true) . "\n";
        foreach (array_merge([CAMPO_NUEVO], VIEJOS_V) as $f) {
            $op = ($f === CAMPO_NUEVO) ? '%' . $f : $f;   // el nuevo guarda "581,623"
            $r = bx('crm.deal.list', ['filter' => [$op => $uu],
                                      'select' => ['ID', 'TITLE', 'CATEGORY_ID', 'STAGE_ID']]);
            foreach (($r['result'] ?? []) as $d) {
                echo "  la nombra: deal {$d['ID']} (cat {$d['CATEGORY_ID']}, {$d['STAGE_ID']}) por $f — {$d['TITLE']}\n";
            }
        }
        echo "\n";
    }
    exit;
}

// ---- lado DEALS ------------------------------------------------------------
$conNuevo = 0; $conViejo = []; $reclamos = [];
$start = 0;
do {
    $r = bx('crm.deal.list', [
        'filter' => ['CATEGORY_ID' => CLIENTES_CAT],
        'select' => array_merge(['ID', 'STAGE_ID', CAMPO_NUEVO], VIEJOS_V),
        'order'  => ['ID' => 'ASC'],
        'start'  => $start,
    ]);
    if (!$r['ok']) { echo "ERROR listando deals: {$r['error']}\n"; break; }
    foreach (($r['result'] ?? []) as $d) {
        $ids = ids_de((string)($d[CAMPO_NUEVO] ?? ''));
        if ($ids) {
            $conNuevo++;
            foreach ($ids as $u) $reclamos[$u][] = (string)$d['ID'];
        }
        foreach (VIEJOS_V as $f) {
            $v = $d[$f] ?? '';
            if ($v !== '' && $v !== null && (int)$v > 0) $conViejo[$f][] = (string)$d['ID'];
        }
    }
    $start = $r['next'] ?? null;
} while ($start !== null && $start !== '');

// ---- lado UNIDADES ---------------------------------------------------------
$rev = [];
foreach (stages_map() as $cat => $m) foreach ($m as $nombre => $sid) $rev[$sid] = $nombre;

// Las apartadas desde Prospectos(28) están RESERVADO a propósito y SIN parentId2
// (la dependencia nace solo en CLIENTES). Sin separarlas, saldrían todas como
// "ocupadas sin dueño" y el chequeo daría falsa alarma cada vez.
$apartadas = apartados_28();

$porStage = []; $conDueno = 0; $ocupadaSinDueno = []; $libreConDueno = []; $apart = [];
$start = 0;
do {
    $r = bx('crm.item.list', ['entityTypeId' => SPA_ENTITY, 'order' => ['id' => 'ASC'], 'start' => $start]);
    if (!$r['ok']) { echo "ERROR listando unidades: {$r['error']}\n"; break; }
    foreach (($r['result']['items'] ?? []) as $it) {
        $id    = (int)($it['id'] ?? 0);
        $stage = $rev[(string)($it['stageId'] ?? '')] ?? '?';
        $dueno = (int)($it['parentId2'] ?? 0);
        $porStage[$stage] = ($porStage[$stage] ?? 0) + 1;
        if ($dueno) $conDueno++;

        $ocupado = in_array($stage, ['RESERVADO', 'FIRMADO', 'VENDIDO'], true);
        if ($ocupado && !$dueno && isset($apartadas[$id])) {
            $apart[] = "$id(deal28 {$apartadas[$id]['deal']})";
        } elseif ($ocupado && !$dueno)      $ocupadaSinDueno[] = "$id($stage)";
        if (!$ocupado && $dueno && $stage === 'DISPONIBLE') $libreConDueno[] = "$id(deal $dueno)";
    }
    $start = $r['next'] ?? null;
} while ($start !== null && $start !== '');

// ---- informe ---------------------------------------------------------------
echo "===== DEALS (CLIENTES 44) =====\n";
echo "con campo Inventario nuevo : $conNuevo\n";
foreach (VIEJOS_V as $f) {
    $n = count($conViejo[$f] ?? []);
    echo str_pad("con $f", 40) . ": $n";
    if ($n) echo '  -> ' . implode(',', array_slice($conViejo[$f], 0, 10)) . ($n > 10 ? ' ...' : '');
    echo "\n";
}

$peleadas = array_filter($reclamos, fn($ds) => count(array_unique($ds)) > 1);
echo "\nunidades reclamadas por 2+ deals: " . count($peleadas) . "\n";
foreach (array_slice($peleadas, 0, 20, true) as $u => $ds) {
    echo "  unidad $u <- deals " . implode(', ', array_unique($ds)) . "\n";
}

echo "\n===== UNIDADES (SPA 1072) =====\n";
ksort($porStage);
foreach ($porStage as $s => $n) echo str_pad($s, 16) . ": $n\n";
echo "con dependencia (parentId2): $conDueno\n";

echo "\napartadas desde Prospectos 28 (" . count($apart) . "): "
   . implode(' ', array_slice($apart, 0, 40)) . (count($apart) > 40 ? ' ...' : '') . "\n";
echo "ocupadas SIN dueño ni apartado (" . count($ocupadaSinDueno) . "): "
   . implode(' ', array_slice($ocupadaSinDueno, 0, 40)) . (count($ocupadaSinDueno) > 40 ? ' ...' : '') . "\n";
echo "DISPONIBLE con dueño (" . count($libreConDueno) . "): "
   . implode(' ', array_slice($libreConDueno, 0, 40)) . (count($libreConDueno) > 40 ? ' ...' : '') . "\n";
