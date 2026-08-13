<?php
/**
 * inventario-sync — mapa48.php  (CONSERJE del precio final)
 * ---------------------------------------------------------------------------
 * Construye en FRÍO el mapa  deal de COBRANZAS(48)  ->  unidades del SPA.
 *
 * preciolib.php lo consulta en disco durante el evento, así que resolver la unidad
 * le cuesta CERO llamadas. Aquí sí se paga el emparejamiento completo, una vez cada
 * varias horas, en vez de en cada webhook.
 *
 * El mapa incluye TAMBIÉN los deals del 48 que no resuelven unidad, con lista
 * vacía: el mapa hace de lista blanca, y un ID que no está en él se descarta en el
 * evento sin gastar nada.
 *
 * ── POR QUÉ EL EMPAREJAMIENTO NO ES "MISMO CÓDIGO Y YA" ─────────────────────
 * Medido sobre los 1.084 deals reales del 48 (2026-08-13):
 *   · el proyecto se cuela dentro del código      "SUN BAY J-12" / "L-12 Sunbay "
 *   · "|| Pelícano 3" es el MODELO de casa        "C-5 || Pelícano 4"  ->  "C-5"
 *   · Cobranzas agrupa varias unidades            "J-13" + "J-30"  ->  "J-13-30"
 *   · el titular de la cobranza puede ser otro    Lester Ojeda / Noelia Ojeda
 *   · y el mismo cliente puede tener DOS deals    de los cuales manda el que trae
 *                                                 el mismo VALOR DEL ACTIVO
 * Con la cascada completa se emparejan 849 de 969; con el código pelado, 794.
 *
 * Cada regla se aplica SOLO si deja un único candidato: ante la duda no se adivina,
 * porque el error no es "queda sin precio" sino "se le escribe el precio de otro".
 *
 * Uso:  php mapa48.php          (cron)
 *       /mapa48.php?token=...   (HTTP, protegido por OUTBOUND_TOKEN)
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

require_once __DIR__ . '/campolib.php';    // bx(), logline(), ids_de(), constantes
require_once __DIR__ . '/preciolib.php';   // pf_cod(), pf_agrupa(), pf_proy(), pf_base()

$isHttp = PHP_SAPI !== 'cli';
if ($isHttp) {
    header('Content-Type: text/plain; charset=utf-8');
    $expect = (string)getenv('OUTBOUND_TOKEN');
    if ($expect === '' || !hash_equals($expect, (string)($_GET['token'] ?? ''))) {
        http_response_code(403); echo 'forbidden'; exit;
    }
}

// Barrido, no evento: freno entre llamadas para no comerse el presupuesto del API.
$BX_FRENO_US = 250000;

/** Trae un pipeline completo, paginado. */
function m48_deals(int $cat, array $select, array $filtroExtra = []): array {
    $out = []; $start = 0;
    do {
        $r = bx('crm.deal.list', [
            'filter' => ['CATEGORY_ID' => $cat] + $filtroExtra,
            'select' => $select,
            'start'  => $start,
        ]);
        if (!$r['ok']) { logline("MAPA48 ERR list cat=$cat: {$r['error']}"); return $out; }
        foreach (($r['result'] ?? []) as $d) $out[] = $d;
        $start = $r['next'] ?? null;
    } while ($start !== null);
    return $out;
}

$c48 = m48_deals(COBRANZAS_CAT, ['ID', 'TITLE', 'CONTACT_ID', D_ACTIVO, D_VALOR]);
// Del 44 solo interesan los que YA tienen unidad elegida: la unidad sale de ahí.
// OJO con el filtro '!CAMPO' => '': si el campo no existiera, Bitrix ignora el
// filtro y devuelve TODO el pipeline. El campo existe y está verificado.
$c44 = m48_deals(CLIENTES_CAT, ['ID', 'TITLE', 'CONTACT_ID', D_ACTIVO, D_VALOR, CAMPO_NUEVO],
                 ['!' . CAMPO_NUEVO => '']);
logline('MAPA48 leídos: ' . count($c48) . ' deals del 48, ' . count($c44) . ' del 44 con unidad');

if (!$c48 || !$c44) {
    // Fallar ABIERTO sería peor que no hacer nada: dejaría el mapa vacío y el
    // evento pensaría que ningún deal es del 48. Se conserva el mapa anterior.
    logline('MAPA48 ABORTA: alguna lista vino vacía, se conserva el mapa anterior');
    if ($isHttp) echo 'abort-lista-vacia';
    exit(1);
}

// ---- índices sobre COBRANZAS(48) -------------------------------------------
// Se recorre desde CLIENTES(44), no al revés: la unidad la eligió el vendedor allá,
// así que cada deal del 44 busca SU copia en Cobranzas. Es lo que permite que un
// mismo deal del 48 acumule las unidades de varios deals del 44 (la compra de dos
// departamentos con un solo plan de pagos) sin inventarse pertenencias.
$porContactoCod = []; $porContacto = []; $porContactoValor = [];
$porTitulo = [];      $porProyCod = [];
foreach ($c48 as $d) {
    $con = (string)($d['CONTACT_ID'] ?? '');
    $cod = pf_cod((string)($d[D_ACTIVO] ?? ''));
    $val = pf_money($d[D_VALOR] ?? null);
    $pro = pf_proy((string)($d['TITLE'] ?? ''));
    if ($con !== '' && $con !== '0') {
        $porContacto[$con][] = $d;
        if ($cod !== '') $porContactoCod[$con . '|' . $cod][] = $d;
        if ($val !== null) $porContactoValor[$con . '|' . $val][] = $d;
    }
    $porTitulo[pf_base((string)($d['TITLE'] ?? ''))][] = $d;
    if ($cod !== '' && $pro !== '') $porProyCod[$pro . '|' . $cod][] = $d;
}

/** De varios candidatos manda el que trae el MISMO VALOR DEL ACTIVO. */
function m48_elegir(array $cands, ?float $valor): ?array {
    if (count($cands) === 1) return $cands[0];
    if ($valor !== null) {
        $ig = [];
        foreach ($cands as $c) if (pf_money($c[D_VALOR] ?? null) === $valor) $ig[] = $c;
        if (count($ig) === 1) return $ig[0];
    }
    return null;   // sigue ambiguo: no se adivina
}

// ---- cascada, un deal de CLIENTES a la vez ---------------------------------
// Cada regla exige que quede UN solo candidato. Ante la duda no se empareja: el
// error caro no es "queda sin precio", es "se le escribe el precio de otra unidad".
$mapa = []; $via = []; $sin = 0;
foreach ($c48 as $d) $mapa[(string)$d['ID']] = [];   // el mapa hace de lista blanca

foreach ($c44 as $d) {
    $con = (string)($d['CONTACT_ID'] ?? '');
    $cod = pf_cod((string)($d[D_ACTIVO] ?? ''));
    $val = pf_money($d[D_VALOR] ?? null);
    $pro = pf_proy((string)($d['TITLE'] ?? ''));
    $unis = array_map('intval', ids_de((string)($d[CAMPO_NUEVO] ?? '')));
    if (!$unis) continue;

    $hit = null; $como = '';
    if ($con !== '' && $cod !== '' && isset($porContactoCod[$con . '|' . $cod])) {
        $hit = m48_elegir($porContactoCod[$con . '|' . $cod], $val); $como = 'codigo+contacto';
    }
    if (!$hit && $con !== '' && isset($porContacto[$con])) {
        // Cobranzas agrupa varias unidades en un solo deal ("J-13" y "J-30" viven
        // en "J-13-30"). Solo vale si UN único deal del 48 la contiene.
        $g = [];
        foreach ($porContacto[$con] as $e) {
            if (pf_agrupa((string)($d[D_ACTIVO] ?? ''), (string)($e[D_ACTIVO] ?? ''))) $g[] = $e;
        }
        if (count($g) === 1) { $hit = $g[0]; $como = 'agrupado'; }
    }
    if (!$hit && $con !== '' && $val !== null && isset($porContactoValor[$con . '|' . $val])) {
        $c = $porContactoValor[$con . '|' . $val];
        if (count($c) === 1 && pf_cod((string)($c[0][D_ACTIVO] ?? '')) === $cod) {
            $hit = $c[0]; $como = 'contacto+valor';
        }
    }
    if (!$hit && isset($porTitulo[pf_base((string)($d['TITLE'] ?? ''))])) {
        $hit = m48_elegir($porTitulo[pf_base((string)($d['TITLE'] ?? ''))], $val); $como = 'titulo';
    }
    if (!$hit && $cod !== '' && $pro !== '' && isset($porProyCod[$pro . '|' . $cod])) {
        // Último recurso, SIN contacto: el titular de la cobranza puede ser el
        // cónyuge o un familiar (Lester Ojeda en Clientes, Noelia Ojeda en Cobranzas).
        $c = $porProyCod[$pro . '|' . $cod];
        if (count($c) === 1) { $hit = $c[0]; $como = 'proyecto+codigo'; }
    }

    if (!$hit) { $sin++; continue; }
    $id = (string)$hit['ID'];
    $u  = array_flip($mapa[$id] ?? []);
    foreach ($unis as $x) $u[$x] = true;
    $mapa[$id] = array_values(array_map('intval', array_keys($u)));
    $via[$como] = ($via[$como] ?? 0) + 1;
}

pf_escribir('mapa48.json', $mapa);
$con  = count(array_filter($mapa));
$msg  = 'MAPA48 ok -> ' . count($mapa) . ' deals del 48, ' . $con . ' con unidad, ' . $sin . ' sin resolver'
      . ' · via ' . json_encode($via);
logline($msg);
if ($isHttp) echo $msg;
