<?php
/**
 * Reubicación en modo estricto (5-oct-2026). El caso fijo es el deal 9610 tal como
 * estaba: un 48 que dice G-4-3 por los tres lados, el Inventario vacío, y un
 * contacto con varias compras cuyo 44 MÁS NUEVO (241608) tiene colgada OTRA unidad
 * (815, C-3-7 de Noral Apartments). El código viejo eligió la 815, pisó el ACTIVO
 * COMPRADO y la liberó. Esto tiene que dar G-4-3 con su 44 (5792), nunca la 815.
 */
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
putenv('DATA_DIR=' . sys_get_temp_dir());
require_once __DIR__ . '/../reubicalib.php';

// ── las fuentes del código ───────────────────────────────────────────────────
test_same('G-4-3', reub_codigo_cuotas(['G-4-3 - Cuota 2024-07 -($578.38)', 'G-4-3 - RESERVA -($2,000.00)', 'Cuota Extra 1 1/9/2026']),
    'el prefijo de las cuotas es el código; las Cuota Extra no votan');
test_same('H-13-14', reub_codigo_cuotas(['H-13-14 - Cuota 2024-03 -($624.44)']), 'un plan de dos lotes lleva su prefijo doble');
test_same(null, reub_codigo_cuotas(['Cuota Extra 1 1/9/2026', 'Reserva (Separacion) 26/07/2026']), 'sin prefijo no inventa código');
test_same(['proyecto' => 'Barranca Apartments (Nuevo Samborondón)', 'codigo' => 'G-4-3'],
    reub_titulo_partes('COBRANZAS--MarcelSalvador--Barranca Apartments (Nuevo Samborondón)--G-4-3'), 'título del 48 -> proyecto y código');
test_same(null, reub_titulo_partes('EMBAJADORMarcel Salvador'), 'un título sin forma no da nada');

test_same('G-4-3', reub_decidir_codigo('G-4-3', 'G-4-3', 'G-4-3')['codigo'], '9610: las tres fuentes dicen G-4-3');
test_same('G-4-3', reub_decidir_codigo('1234', 'G-4-3', 'G-4-3')['codigo'], 'ACTIVO COMPRADO sucio (la cédula): ganan título y cuotas');
test_same(true, isset(reub_decidir_codigo('G-4-3', 'C-3-7', null)['error']), '🔴 uno contra uno: NO adivina, frena');
test_same(true, isset(reub_decidir_codigo('', '', null)['error']), 'sin ninguna fuente: frena');

// ── el proyecto es parte de la llave ─────────────────────────────────────────
test_same(true,  reub_mismo_proyecto('Barranca Apartments (Nuevo Samborondón)', 'G-4-3 (Barranca Apartments)'), 'misma unidad, mismo proyecto');
test_same(false, reub_mismo_proyecto('Barranca Apartments (Nuevo Samborondón)', 'G-4-3 (Noral Apartments)'), '🔴 G-4-3 de otro proyecto NO es la misma');
test_same(true,  reub_mismo_proyecto('Noral Plaza (Locales Comerciales)', 'E-1-20 (Noral Plaza)'), 'Noral Plaza con su apellido');

// ── el contacto 173 tal como estaba el 5-oct antes de las 15:48Z ─────────────
$D = fn($id, $cat, $st, $t, $inv = '', $act = '') => ['ID' => (string)$id, 'CATEGORY_ID' => (string)$cat, 'STAGE_ID' => $st,
    'TITLE' => $t, CAMPO_NUEVO => $inv, D_ACTIVO => $act];
$deals = [
    $D(241608, 44, 'C44:UC_4R587H', 'MarcelSalvador--Noral Apartments (Nuevo Samborondón)--C-3-7', '815', 'C-3-7'),
    $D(241602, 44, 'C44:WON', 'MarcelSalvador--Noral Apartments (Nuevo Samborondón)--E-3-3', '935', 'E-3-3'),
    $D(241600, 44, 'C44:WON', 'MarcelSalvador--Noral Apartments (Nuevo Samborondón)--E-3-2', '933', 'E-3-2'),
    $D(5792, 44, 'C44:APOLOGY', 'MarcelSalvador--Barranca Apartments (Nuevo Samborondón)--G-4-3', '', 'G-4-3'),
    $D(5788, 44, 'C44:APOLOGY', 'MarcelSalvador--Barranca Apartments (Nuevo Samborondón)--G-4-2', '', 'G-4-2'),
    $D(282904, 48, 'C48:EXECUTING', 'COBRANZAS--MarcelSalvador--Noral Apartments (Nuevo Samborondón)--C-3-7', '', 'C-3-7'),
    $D(9610, 48, 'C48:FINAL_INVOICE', 'COBRANZAS--MarcelSalvador--Barranca Apartments (Nuevo Samborondón)--G-4-3', '', 'G-4-3'),
];
$deal48 = $D(9610, 48, 'C48:FINAL_INVOICE', 'COBRANZAS--MarcelSalvador--Barranca Apartments (Nuevo Samborondón)--G-4-3', '', 'G-4-3');
$cuotas = ['G-4-3 - Cuota 2024-07 -($578.38)', 'G-4-3 - Cuota 2024-06 -($578.38)', 'Cuota Extra 1 1/9/2026'];
$u815 = ['id' => 815, 'title' => 'C-3-7 (Noral Apartments)'];
$u449 = ['id' => 449, 'title' => 'G-4-3 (Barranca Apartments)'];
$u1079 = ['id' => 1079, 'title' => 'G-4-3 (Noral Apartments)'];

$sinUnidad = fn(int $hid, array $inv) => $hid === 241608 ? [$u815] : [];
$r = reub_resolver($deal48, $cuotas, $deals, $sinUnidad);
test_same(true, $r['ok'], '9610: resuelve');
test_same('G-4-3', $r['codigo'], '🔴 9610: sale de G-4-3, no de C-3-7');
test_same('5792', (string)$r['hermano']['ID'], '🔴 9610: su 44 es el G-4-3 (5792), no el más nuevo (241608)');
test_same('título', $r['hermano_por'], 'lo encontró por el título');
test_same(null, $r['vieja'], '🔴 9610: la 815 NUNCA es la vieja (otro código, otro proyecto)');
test_same(false, $r['liberar'], 'sin unidad vieja identificada no se libera nada');

// la G-4-3 colgada del 5792: esa es la vieja y se puede soltar
$conUnidad = fn(int $hid, array $inv) => $hid === 5792 ? [$u1079, $u449] : ($hid === 241608 ? [$u815] : []);
$r = reub_resolver($deal48, $cuotas, $deals, $conUnidad);
test_same(449, (int)$r['vieja']['id'], 'entre dos G-4-3 elige la de Barranca (la llave lleva proyecto)');
test_same(true, $r['liberar'], 'nadie más vivo nombra la G-4-3 de Barranca: se libera');

// nunca liberar lo que otro deal vivo todavía nombra
test_same(282904, reub_otro_dueno($deals, [9610, 241608], 'C-3-7', 'Noral Apartments (Nuevo Samborondón)'),
    '🔴 la C-3-7 la sigue nombrando el 282904 vivo: no se libera');
test_same(null, reub_otro_dueno($deals, [9610, 5792], 'G-4-3', 'Barranca Apartments (Nuevo Samborondón)'), 'la G-4-3 no tiene otro dueño vivo');
$soloTitulo = [$D(500, 48, 'C48:EXECUTING', 'COBRANZAS--X--Noral Apartments (Nuevo Samborondón)--C-3-7', '', '')];
test_same(500, reub_otro_dueno($soloTitulo, [], 'C-3-7', 'Noral Apartments (Nuevo Samborondón)'), 'lo nombra solo por el título: igual cuenta');
$soloActivo = [$D(501, 44, 'C44:WON', 'EMBAJADOR sin forma', '', 'C-3-7')];
test_same(501, reub_otro_dueno($soloActivo, [], 'C-3-7', ''), 'lo nombra solo por ACTIVO COMPRADO: igual cuenta');
test_same(null, reub_otro_dueno([$D(502, 48, 'C48:LOSE', 'COBRANZAS--X--Noral Apartments (Nuevo Samborondón)--C-3-7', '', 'C-3-7')], [], 'C-3-7', ''), 'un deal perdido no es dueño');

// la libreta de reubicaciones no guarda links con llaves
test_same(['a' => '[link omitido]', 'b' => ['c' => 'G-4-3', 'd' => '[link omitido]']],
    reub_sin_links(['a' => 'https://galjosa.bitrix24.com/rest/1/xyz/x', 'b' => ['c' => 'G-4-3', 'd' => '/x?auth=abc']]), 'los links se tapan');

// si el contacto tiene varios 44 y ninguno es esta unidad: frena, no se lo pasa a otra compra
$sinG43 = array_values(array_filter($deals, fn($d) => $d['ID'] !== '5792'));
$r = reub_resolver($deal48, $cuotas, $sinG43, $sinUnidad);
test_same(false, $r['ok'], '🔴 sin un 44 que sea G-4-3 NO cae en el más nuevo');
test_same(true, str_contains($r['error'], 'no lo cambio a otra compra'), 'y dice por qué');

// con UN solo 44 se usa ese (comportamiento de siempre)
$uno = [$D(77, 44, 'C44:WON', 'Ana--Sun Bay (Vía a Engabao)--B-2', '', 'B-2')];
test_same('77', (string)reub_elegir_hermano($uno, 'B-3', 'Sun Bay (Vía a Engabao)', 0)['deal']['ID'], 'un solo 44: ese');

// caído no frena: la reubicación lo revive a ELABORACIÓN PROMESA
$h = reub_elegir_hermano($deals, 'G-4-3', 'Barranca Apartments (Nuevo Samborondón)', 0);
test_same(true, $h['caido'], 'el 5792 está caído y se marca');
test_same('C44:UC_Z3GY5H', reub_etapa_44(), 'toda reubicación deja el 44 en ELABORACIÓN PROMESA DE COMPRAVENTA');
putenv('REUBICA_ETAPA_44=0');
test_same(null, reub_etapa_44(), 'perilla REUBICA_ETAPA_44=0 la apaga');
// v-reubica-48 (6-oct): el 48 pasa SOLO a REUBICACION al cambiar la unidad, con perilla (apagada por defecto)
putenv('REUBICA_ETAPA_48');   test_same(null, reub_etapa_48(), 'sin perilla el 48 NO cambia de etapa');
putenv('REUBICA_ETAPA_48=0'); test_same(null, reub_etapa_48(), 'REUBICA_ETAPA_48=0 lo apaga');
putenv('REUBICA_ETAPA_48=1'); test_same('C48:UC_1WR2BM', reub_etapa_48(), '🔴 REUBICA_ETAPA_48=1 -> REUBICACION');
putenv('REUBICA_ETAPA_48');
// 6-oct: el cambio de unidad en COBRANZAS solo con el deal YA en REUBICACION
test_same(true,  guardar_reubica_bloqueada(48, 'C48:NEW'), '🔴 cobranzas AL DIA: no se puede cambiar la unidad');
test_same(true,  guardar_reubica_bloqueada(48, 'C48:LOSE'), 'cobranzas DADO DE BAJA: tampoco');
test_same(false, guardar_reubica_bloqueada(48, 'C48:UC_1WR2BM'), '🔴 cobranzas en REUBICACION: si');
test_same(false, guardar_reubica_bloqueada(44, 'C44:NEW'), 'clientes: el freno no aplica');
putenv('REUBICA_SOLO_EN_ETAPA=0'); test_same(false, guardar_reubica_bloqueada(48, 'C48:NEW'), 'perilla en 0: sin freno'); putenv('REUBICA_SOLO_EN_ETAPA');
$srcRL = (string)file_get_contents(__DIR__ . '/../reubicalib.php');
preg_match('/function reubicar\(int \$dealId.*?\n}\n/s', $srcRL, $mr);
test_same(true, isset($mr[0]) && strpos($mr[0], 'guardar_reubica_bloqueada(') !== false && strpos($mr[0], 'guardar_reubica_bloqueada(') < strpos($mr[0], "crm.deal.update"), '🔴 reubicar() mismo frena un 48 fuera de REUBICACION, antes de escribir (todas las puertas)');
$srcG = (string)file_get_contents(__DIR__ . '/../guardar.php');
test_same(true, (($__a = strpos($srcG, 'guardar_reubica_bloqueada($cat, $stage)')) !== false) && (($__b = strpos($srcG, "\$up = bx('crm.deal.update'")) !== false) && $__a < $__b, '🔴 el freno va ANTES de guardar el campo (no queda el campo cambiado sin reubicar)');
$srcR = (string)file_get_contents(__DIR__ . '/../reubicalib.php');
test_same(1, preg_match("/\\\$etapa48 = reub_etapa_48\(\);\s*if \(\\\$etapa48 !== null\) \\\$c48\['STAGE_ID'\] = \\\$etapa48;\s*\\\$u = bx\('crm.deal.update', \['id' => \\\$dealId, 'fields' => \\\$c48\]\);/", $srcR), '🔴 la etapa va en el MISMO update del 48 (no en otra llamada)');
putenv('REUBICA_ETAPA_44');

// la perilla del modo estricto
test_same(true, reub_estricto(), 'estricto por defecto');
putenv('REUBICA_ESTRICTO=0');
test_same(false, reub_estricto(), 'REUBICA_ESTRICTO=0 vuelve al camino viejo');
putenv('REUBICA_ESTRICTO');

// FAMILIA: solo la ficha de ESA unidad (código y proyecto)
test_same(true,  reub_familia_es([D_ACTIVO => 'G-4-2', D_PROYECTO => '142'], 'G-4-2', '142'), 'FAMILIA de la G-4-2 de Barranca: se actualiza');
test_same(false, reub_familia_es([D_ACTIVO => 'G-4-2', D_PROYECTO => '516'], 'G-4-2', '142'), '🔴 mismo código, OTRO proyecto: no se toca');
test_same(false, reub_familia_es([D_ACTIVO => 'E-1-21', D_PROYECTO => '162'], 'G-4-3', '142'), 'la ficha de otra compra no se toca (lo que pisó el 9610)');
test_same(true,  reub_familia_es([D_ACTIVO => 'G-4-2', D_PROYECTO => ''], 'G-4-2', '142'), 'ficha sin proyecto: alcanza el código');
