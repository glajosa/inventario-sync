<?php
/** El pie dice "X% en cuotas mensuales + Y% en extraordinarias": X es SOLO lo mensual.
 *  Caso real 22-oct-2026, Joao de la Cuadra E-2-2 ($88.990): imprimia 37.8% + 17.8%,
 *  mas que el 40% de toda la cuota inicial. */
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../cotizarlib.php';
$E = ['y' => 2031, 'm' => 4];
$pc = fn(float $x) => round($x * 100, 1);
// Joao: entrada 2.000 (separacion + 1.000 a la firma), cuota 335,80, el resto a las extraordinarias
$j = cot_plan(88990, 53, 'estandar', '', $E, 335.80, ['fechaSeparacion' => '2026-10-22', 'firma' => 1000.0, 'extraMes1' => 6]);
$cuotas = array_values(array_filter($j['filas'], fn($f) => empty($f['soloFirma'])));
test_same(53, count($cuotas), 'Joao: 53 cuotas');
test_same(['335.80'], array_values(array_unique(array_map(fn($f) => number_format(empty($f['extra']) ? $f['monto'] : $j['mensual'], 2, '.', ''), $cuotas))), 'Joao: todas las cuotas de 335,80');
test_same(1000.0, (float)$j['firma'], 'Joao: 1.000 a la firma');
test_same(20.0, $pc($j['mensualPct']), 'Joao: 20,0% en cuotas mensuales (decia 37,8%)');
$entrada = ($j['separacion'] + $j['firma']) / $j['valor'];
test_same(40.0, $pc($j['mensualPct'] + $j['extraPct'] + $entrada), 'Joao: mensual + extra + entrada = el 40%');
// una cotizacion normal: 10% entrada + 20% mensual + 10% extra
$n = cot_plan(94500, 52, 'estandar', '', $E, 0, ['fechaSeparacion' => '2026-10-22']);
test_same(20.0, $pc($n['mensualPct']), 'normal: 20% mensual');
test_same(10.0, $pc($n['extraPct']), 'normal: 10% extra');
// firma en abonos: los abonos no cuentan como mensual
$a = cot_plan(94500, 52, 'estandar', '', $E, 0, ['fechaSeparacion' => '2026-10-22', 'fechaFirma' => '2026-10-28', 'firmaAntes' => true, 'firmaMeses' => 3]);
$abonos = array_sum(array_map(fn($f) => (float)$f['monto'], array_filter($a['filas'], fn($f) => !empty($f['soloFirma']))));
test_same(20.0, $pc($a['mensualPct']), 'abonos: los abonos de firma no cuentan como mensual');
test_same(40.0, $pc($a['mensualPct'] + $a['extraPct'] + ($a['separacion'] + $a['firma'] + $abonos) / $a['valor']), 'abonos: mensual + extra + entrada = 40%');
$src = (string)file_get_contents(__DIR__ . '/../cotizar.php');
test_same(true, str_contains($src, "\$pc(\$plan['mensualPct'])) ?> en cuotas mensuales"), 'el pie usa mensualPct');
test_same(true, str_contains($src, '\' a la firma\' . (count($abonosPie) > 1'), 'el pie suma los abonos de la firma (no dice "nada a la firma")');
echo 'pie porcentajes: ' . $GLOBALS['TEST_N'] . " comprobaciones\n";
