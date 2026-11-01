<?php
/**
 * bin/parqueos-asignar.php — parqueo asignado de cada departamento de Noral Plaza.
 * ---------------------------------------------------------------------------
 * Jesua 1-nov-2026: "a esos deals que tienen la unidad pero no tienen el parqueo,
 * tienes que agregarle en el campo inventario".
 *
 *   php bin/parqueos-asignar.php mapa          escribe DATA_DIR/parqueos_asignados.json
 *   php bin/parqueos-asignar.php seco          lista qué deal recibiría qué parqueo (NO escribe)
 *   php bin/parqueos-asignar.php real [max]    lo escribe, de a un deal, con pausa
 *
 * De dónde sale el mapa: la tarjeta de cada parqueo asignado (SPA 1072, cat 63) dice en
 * Extras "Departamento: E-3-15". Se lee TODO de la libreta (0 llamadas a Bitrix).
 *
 * Qué escribe `real`, por deal: el campo Inventario con su parqueo agregado (1 update) y
 * después sincronizar_deal(), que ata el parqueo al deal y le pone la etapa del deal. La
 * ficha (monto, título, ACTIVO COMPRADO) NO se toca: agregar solo un parqueo no la
 * recalcula (campolib.php, $cambioUnidad).
 *
 * Antes de escribir cada deal se relee FRESCO de Bitrix: la libreta va unos segundos
 * atrasada y aquí se decide con el dato del momento.
 *
 * Barrido de más de 50 deals: ANUNCIARLO antes (ver ~/.claude/CLAUDE.md) para que los
 * handlers de cobranzas/prospectos/referidor encolen en vez de procesar cada evento.
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

require_once __DIR__ . '/../campolib.php';
require_once __DIR__ . '/../parqueolib.php';
require_once __DIR__ . '/../libreta1072.php';

const PQ_U_EXTRAS = 'ufCrm25_1782616175065';   // "Extras" de la tarjeta
const PQ_CAT_PLAZA = 33;                        // Noral Plaza (departamentos)
const PQ_PAUSA_S  = 3;                          // entre deal y deal (cada uno = ~4 llamadas)

$modo = $argv[1] ?? '';
$max  = (int)($argv[2] ?? 0);
if (!in_array($modo, ['mapa', 'seco', 'real'], true)) {
    fwrite(STDERR, "uso: php bin/parqueos-asignar.php mapa|seco|real [max]\n"); exit(2);
}

$motivo = '';
$items = l1072_todas($motivo);
if ($items === null) { fwrite(STDERR, "libreta no sirve: $motivo\n"); exit(1); }

// departamento (codigo) -> item, y todas las tarjetas de parqueos/bodegas
$deptoPorCod = []; $deptoPorId = []; $todos = []; $pqPorId = []; $mapa = []; $raros = [];
foreach ($items as $it) {
    $cat = (int)($it['categoryId'] ?? 0);
    if ($cat === PQ_CAT_PLAZA) {
        $c = codigo_activo((string)($it['title'] ?? ''));
        if ($c !== '') { $deptoPorCod[strtoupper($c)] = $it; $deptoPorId[(int)$it['id']] = $it; }
    } elseif ($cat === PARQUEO_CAT) {
        $todos[] = (int)$it['id'];
        $pqPorId[(int)$it['id']] = $it;
    }
}
foreach ($pqPorId as $pid => $it) {
    if (!preg_match('/Departamento:\s*([A-Z]-\d+-\d+)/i', (string)($it[PQ_U_EXTRAS] ?? ''), $m)) continue;
    $d = $deptoPorCod[strtoupper($m[1])] ?? null;
    if (!$d) { $raros[] = codigo_activo((string)$it['title']) . " -> {$m[1]} (no existe)"; continue; }
    if (isset($mapa[(int)$d['id']])) { $raros[] = "{$m[1]} tiene 2 parqueos"; continue; }
    $mapa[(int)$d['id']] = $pid;
}
echo "tarjetas cat " . PARQUEO_CAT . ": " . count($todos) . " · asignados: " . count($mapa)
   . " · raros: " . count($raros) . "\n";
foreach ($raros as $r) echo "  RARO $r\n";

if ($modo === 'mapa') {
    // Sin el mapa completo no se escribe: un archivo a medias haría que preciolib
    // repartiera precio con un parqueo adentro (pq_sin_accesorios no lo reconocería).
    if (count($todos) < 600 || count($mapa) < 190) { fwrite(STDERR, "mapa incompleto: NO se escribe\n"); exit(1); }
    $f = (getenv('DATA_DIR') ?: '/data') . '/parqueos_asignados.json';
    $ok = file_put_contents($f, json_encode(['ts' => gmdate('c'), 'mapa' => $mapa, 'todos' => $todos]), LOCK_EX);
    echo $ok ? "escrito $f\n" : "NO se pudo escribir $f\n";
    exit($ok ? 0 : 1);
}

// ── deals que hoy tienen un departamento con parqueo asignado ──────────────────
$porDeal = [];   // deal -> [idDepto...]
foreach ($mapa as $did => $pid) {
    $dueno = (int)($deptoPorId[$did]['parentId2'] ?? 0);
    if ($dueno > 0) $porDeal[$dueno][] = $did;
}
ksort($porDeal);
echo "deals con departamento atado: " . count($porDeal) . "\n";

$deals = [];
foreach (array_chunk(array_keys($porDeal), 50) as $lote) {
    $r = l1072_http('/deals?ids=' . implode(',', $lote));
    if ($r['status'] !== 200) { fwrite(STDERR, "libreta /deals http {$r['status']}\n"); exit(1); }
    foreach ((array)($r['json']['items'] ?? []) as $i)
        if (is_array($i['dato'] ?? null)) $deals[(int)$i['dato']['ID']] = $i['dato'];
}
if (count($deals) !== count($porDeal)) {
    echo "OJO: la libreta devolvió " . count($deals) . " de " . count($porDeal) . " deals\n";
}

/** Qué hay que agregar a este deal. [nuevoValor|null, motivo, parqueos] */
function pq_plan(array $deal, array $deptos, array $mapa, array $pqPorId): array {
    if ((int)($deal['CATEGORY_ID'] ?? -1) !== CLIENTES_CAT) return [null, 'no es Clientes(44)', []];
    if (etapa_libera((string)($deal['STAGE_ID'] ?? ''))) return [null, 'deal caido', []];
    $raw   = (string)($deal[CAMPO_NUEVO] ?? '');
    $campo = ids_de($raw);
    $sep   = unidades_separadas($raw);
    $add = []; $sinCampo = [];
    foreach ($deptos as $did) {
        if (!in_array($did, $campo, true)) { $sinCampo[] = $did; continue; }
        $pid = $mapa[$did];
        if (in_array($pid, $campo, true)) continue;
        $duenoPq = (int)($pqPorId[$pid]['parentId2'] ?? 0);
        if ($duenoPq !== 0 && $duenoPq !== (int)$deal['ID']) return [null, "parqueo $pid atado a otro deal $duenoPq", []];
        $add[] = $pid;
    }
    // Departamento atado por el campo VIEJO, sin estar en el nuevo: si se escribiera solo
    // el parqueo, sincronizar_deal soltaria el departamento. No se toca; se reporta.
    if ($sinCampo) return [null, 'departamento atado pero NO en el campo (' . implode(',', $sinCampo) . ')', []];
    if (!$add) return [null, 'ya tiene su parqueo', []];
    return [valor_campo(array_merge($campo, $add), $sep), 'agregar', $add];
}

$cuenta = []; $hechos = 0; $fallos = 0;
foreach ($porDeal as $dealId => $deptos) {
    $deal = $deals[$dealId] ?? null;
    if (!$deal) { $cuenta['no esta en la libreta'] = ($cuenta['no esta en la libreta'] ?? 0) + 1;
                  echo "deal=$dealId  SALTA: no esta en la libreta\n"; continue; }
    [$nuevo, $mot, $add] = pq_plan($deal, $deptos, $mapa, $pqPorId);
    $cods = implode('+', array_map(fn($d) => codigo_activo((string)$deptoPorId[$d]['title']), $deptos));
    $pqs  = implode('+', array_map(fn($p) => codigo_activo((string)$pqPorId[$p]['title']), $add));

    if ($nuevo === null || $modo === 'seco') {
        $k = $nuevo === null ? $mot : 'agregaria';
        $cuenta[$k] = ($cuenta[$k] ?? 0) + 1;
        echo "deal=$dealId  $cods  " . ($nuevo === null ? "SALTA: $mot" : "AGREGA $pqs  [{$deal[CAMPO_NUEVO]}] -> [$nuevo]") . "\n";
        continue;
    }
    if ($max > 0 && $hechos >= $max) break;

    // REAL: se decide con el deal FRESCO, no con la copia
    $g = bx('crm.deal.get', ['id' => $dealId]);
    if (!$g['ok']) { $fallos++; echo "deal=$dealId  ERR get: {$g['error']}\n"; continue; }
    $fresco = $g['result'];
    [$nuevo, $mot, $add] = pq_plan($fresco, $deptos, $mapa, $pqPorId);
    if ($nuevo === null) { echo "deal=$dealId  $cods  SALTA (fresco): $mot\n"; continue; }

    $u = bx('crm.deal.update', ['id' => $dealId, 'fields' => [CAMPO_NUEVO => $nuevo]]);
    if (!$u['ok']) { $fallos++; echo "deal=$dealId  ERR update: {$u['error']}\n"; continue; }
    $fresco[CAMPO_NUEVO] = $nuevo;
    $r = sincronizar_deal($dealId, $fresco);
    logline("PARQUEO-ASIGNAR deal=$dealId $cods + $pqs -> [$nuevo] sync=" . json_encode($r));
    echo "deal=$dealId  $cods  + $pqs  OK  sync=" . json_encode($r) . "\n";
    $hechos++;
    sleep(PQ_PAUSA_S);
}
foreach ($cuenta as $k => $n) echo "  $n  $k\n";
if ($modo === 'real') echo "HECHOS $hechos · FALLOS $fallos\n";
