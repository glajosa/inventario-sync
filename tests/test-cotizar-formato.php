<?php
/** El Excel del cotizador en el FORMATO DE GALJOSA (26-oct-2026). Se prueba el XML que
 *  sale, no una copia de la logica: los numeros se comparan contra el plan del motor. */
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../cotizarlib.php';
require_once __DIR__ . '/../cotizarformato.php';
$E = ['y' => 2031, 'm' => 4];
$plan = fn(float $v, float $presu, array $o) => cot_plan($v, 53, 'estandar', '', $E, $presu, $o + ['fechaSeparacion' => '2026-10-26', 'maxExtra' => 5]);
$celda = function (string $xml, string $ref): array {   // [valor, formula]
    if (!preg_match('#<c r="' . $ref . '"[^>]*?(?:/>|>(.*?)</c>)#s', $xml, $m)) return [null, null];
    $in = $m[1] ?? '';
    preg_match('#<f>(.*?)</f>#', $in, $f); preg_match('#<v>(.*?)</v>#', $in, $v);
    return [isset($v[1]) ? $v[1] : null, isset($f[1]) ? html_entity_decode($f[1], ENT_XML1) : null];
};
$b = fn(array $p) => ['cods' => ['F-1-16'], 'm2' => 31.0, 'plan' => $p];
$meta = ['cliente' => 'ANTONIO GALARZA', 'proyecto' => 'Noral Plaza'];

// ── Antonio Galarza F-1-16: plan normal ──────────────────────────────────────
$p = $plan(94500, 0, []);
$cuotas = array_values(array_filter($p['filas'], fn($f) => empty($f['soloFirma'])));
cot_fmt_ss(null, true);
$m = cot_fmt_hoja('mensual', $b($p), $meta, true, 'CUOTAS NORMALES')['xml'];
$n = cot_fmt_hoja('normal', $b($p), $meta, false, 'CUOTAS NORMALES')['xml'];
test_same('94500', $celda($m, 'B14')[0], 'precio final en B14');
test_same('53', $celda($m, 'B20')[0], 'total de cuotas en B20');
test_same('1000', $celda($m, 'C22')[0], 'separacion');
test_same('8450', $celda($m, 'C23')[0], 'mensualizado: la firma como numero');
test_same((string)round($cuotas[52]['monto'], 2), $celda($m, 'C76')[0], 'mensualizado: la ultima cuota es la del plan (con el cuadre)');
test_same('+SUM(C22:C76)', $celda($m, 'C77')[1], 'total cuota inicial suma hasta la ultima cuota');
test_same('37800', $celda($m, 'C77')[0], 'mensualizado: total inicial = el del plan, exacto');
test_same('56700', $celda($m, 'C78')[0], 'contraentrega = precio - lo pagado');
test_same('B14-C77', $celda($m, 'C78')[1], 'la contraentrega es FORMULA: el cliente cambia una cuota y se recalcula');
test_same('B14*0.1-C22', $celda($n, 'C23')[1], 'normal: la firma con SU formula');
test_same('$B$14*0.2/$B$20', $celda($n, 'C24')[1], 'normal: la cuota con SU formula');
test_same('E23/5', $celda($n, 'E24')[1], 'normal: extraordinaria = 10% / 5');
test_same(5, substr_count($n, '<f>$E$24+$B$14*0.2/$B$20</f>'), 'normal: 5 filas con la extraordinaria sumada');
test_same(0, substr_count($m . $n, 'inlineStr'), 'ningun texto en la celda: todos en la tabla compartida (cobranzas no lee inlineStr)');
test_same(true, str_contains($n, '<row r="82" hidden="1">'), 'normal: la nota del parqueo de Plaza va oculta, como en el de ellos');
test_same(true, str_contains($m, "<f>'CUOTAS NORMALES'!C16</f>"), 'mensualizado: legales apunta a la normal');

// ── el libro: MENSUALIZADO primero, NOTARIA oculta, textos compartidos ───────
$zip = cot_formato_generar([$b($p)], $meta);
test_same(true, str_starts_with($zip, "PK\x03\x04"), 'sale un zip');
preg_match('#<sheets>(.*?)</sheets>#', $zip, $sh);
test_same(['MENSUALIZADO', 'CUOTAS NORMALES', 'NOTARÍA'], (preg_match_all('#name="([^"]+)"#', $sh[1] ?? '', $nm) ? array_map(fn($s) => html_entity_decode($s), $nm[1]) : []), 'orden: mensualizado, normales, notaria');
test_same(true, str_contains($sh[1] ?? '', 'name="NOTARÍA" sheetId="3" state="hidden"'), 'NOTARIA oculta');
test_same(true, str_contains($zip, '<t xml:space="preserve">SEPARACIÓN</t>'), 'SEPARACIÓN en la tabla compartida');
test_same(0, substr_count($zip, 'externalLink') + substr_count($zip, '#REF!'), 'sin vinculos externos ni nombres rotos');
test_same(true, str_contains($zip, 'fullCalcOnLoad="1"'), 'Excel recalcula al abrir');

// ── un plan armado (Joao: firma 1.000, cuota 335,80) ─────────────────────────
$j = $plan(88990, 335.80, ['firma' => 1000.0, 'extraMes1' => 6]);
$mj = cot_fmt_hoja('mensual', $b($j), $meta, true, 'CUOTAS NORMALES')['xml'];
test_same('1000', $celda($mj, 'C23')[0], 'Joao: firma 1.000');
test_same('35596', $celda($mj, 'C77')[0], 'Joao: total inicial = 40%');
test_same('53394', $celda($mj, 'C78')[0], 'Joao: contraentrega');

// ── firma en abonos: filas propias en el mensualizado ────────────────────────
$a = $plan(94500, 0, ['firmaAntes' => true, 'firmaMeses' => 3, 'fechaFirma' => '2026-10-28']);
$ma = cot_fmt_hoja('mensual', $b($a), $meta, true, 'CUOTAS NORMALES')['xml'];
test_same('2816.67', $celda($ma, 'C23')[0], 'abonos: el primero en A LA FIRMA');
test_same(2, substr_count($ma, '>' . cot_fmt_ss('ABONO FIRMA 2') . '<') + substr_count($ma, '>' . cot_fmt_ss('ABONO FIRMA 3') . '<'), 'abonos: el 2 y el 3 en filas propias');
test_same('37800', $celda($ma, 'C' . (23 + 2 + 52 + 1))[0], 'abonos: el total inicial cuadra');

// ── logo de otro proyecto: no se estira ──────────────────────────────────────
$d = cot_fmt_dibujo(__DIR__ . '/../assets/logo_noral_apartments.png');
preg_match_all('#<xdr:ext cx="(\d+)" cy="(\d+)"/>#', $d, $ex);
test_same(true, abs($ex[1][1] / $ex[2][1] - 711 / 160) < 0.02, 'logo de Apartments con su proporcion (711x160)');
test_same(true, str_contains(cot_fmt_dibujo(COT_FMT_DIR . '/logo-noral-plaza.png'), 'a:srcRect'), 'Plaza usa el logo del archivo de ellos, con su recorte');
echo 'cotizar formato: ' . $GLOBALS['TEST_N'] . " comprobaciones\n";
