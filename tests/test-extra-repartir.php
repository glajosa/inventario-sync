<?php
/** Extraordinarias REPARTIDAS en las cuotas (Jesua 29/30-oct-2026, Antonio Galarza F-1-16,
 *  "opcion 1"): las de 2027 y 2028 van en 24 cuotas (+157,50), desde la primera de 2027. */
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../cotizarlib.php';
require_once __DIR__ . '/../cotizarformato.php';
$E = ['y' => 2031, 'm' => 4];
$antonio = fn(array $o = []) => cot_plan(94500, 53, 'estandar', '', $E, 0, $o + ['fechaSeparacion' => '2026-10-29',
    'firmaAntes' => true, 'firmaMeses' => 8, 'extraMes1' => 6, 'maxExtra' => 5]);
$cuotas = fn(array $p) => array_values(array_filter($p['filas'], fn($f) => empty($f['soloFirma'])));
$total  = fn(array $p) => round($p['separacion'] + $p['firma'] + array_sum(array_map(fn($f) => (float)$f['monto'], $p['filas'])), 2);
$m = fn(array $p, int $n) => round((float)$cuotas($p)[$n - 1]['monto'], 2);
$esExtra = fn(array $p, int $n) => !empty($cuotas($p)[$n - 1]['extra']);

$sin = $antonio(); $con = $antonio(['extraRepartir' => [2027, 2028]]);
test_same(2300.87, $m($sin, 3), 'sin repartir: la 3 (sep-2027) es extraordinaria');
test_same([568.37, 568.37, 568.37, 568.37, 2458.37, 410.87, 2300.87, 410.85],
          [$m($con, 1), $m($con, 3), $m($con, 12), $m($con, 23), $m($con, 24), $m($con, 25), $m($con, 36), $m($con, 46)],
          'Antonio: 1-23 = 568,37; la 24 con la extra de 2029; desde la 25 = 410,87');
test_same([false, false, true, true, true], [$esExtra($con, 3), $esExtra($con, 12), $esExtra($con, 24), $esExtra($con, 36), $esExtra($con, 44)],
          'las de 2027 y 2028 dejan de ser extraordinarias; 2029, 2030 y 2031 siguen');
test_same($total($sin), $total($con), 'el total no cambia');
test_same(37800.0, $total($con), 'cuota inicial 37.800');
test_same(56700.0, (float)$con['contraentrega'], 'contraentrega 56.700');
test_same([2027, 2028], $con['extraRepartida']['anios'], 'informa los años');
test_same([1, 24], [$con['extraRepartida']['desde'], $con['extraRepartida']['hasta']], 'cuotas 1 a 24');
test_same(157.5, round($con['extraRepartida']['porCuota'], 2), '157,50 por cuota');
test_same([24.0, 6.0], [round($con['mensualPct'] * 100, 1), round($con['extraPct'] * 100, 1)], '24% mensual + 6% extraordinarias');

// un solo año: 12 cuotas
$uno = $antonio(['extraRepartir' => [2029]]);
test_same([19, 30], [$uno['extraRepartida']['desde'], $uno['extraRepartida']['hasta']], 'solo 2029: 12 cuotas desde la primera de 2029 (ene-2029 = cuota 19)');
test_same(568.37, $m($uno, 19), 'solo 2029: +157,50');
test_same(410.87, $m($uno, 18), 'solo 2029: la de dic-2028 no cambia');
test_same($total($sin), $total($uno), 'solo 2029: el total no cambia');
// un año sin extraordinaria: no hace nada
test_same(null, $antonio(['extraRepartir' => [2035]])['extraRepartida'], 'un año sin extraordinaria: nada');
test_same(null, $antonio(['extraRepartir' => ['x', 1990]])['extraRepartida'], 'basura: nada');

// pantalla y Excel
$src = (string)file_get_contents(__DIR__ . '/../cotizar.php');
test_same(true, str_contains($src, "\$opts['extraRepartir'] = \$vExtraRep;") && str_contains($src, 'name="extrarep"'), 'la pantalla lee y manda extrarep');
test_same(true, str_contains((string)file_get_contents(__DIR__ . '/../cotizacioneslib.php'), "'extrarep'"), 'el historial lo guarda');
cot_fmt_ss(null, true);
$n = cot_fmt_hoja('normal', ['cods' => ['F-1-16'], 'm2' => 30.0, 'plan' => $con], ['proyecto' => 'Noral Plaza'], false, 'CUOTAS NORMALES')['xml'];
test_same(23, substr_count($n, '<f>$E$24/12+$B$14*0.2/$B$20</f>'), 'Excel normal: 23 cuotas con E24/12');
test_same(1, substr_count($n, '<f>$E$24+$E$24/12+$B$14*0.2/$B$20</f>'), 'Excel normal: la 24 con extra + reparto');
test_same(true, str_contains($n, '<f>E23/5</f>'), 'Excel normal: cada extraordinaria sigue siendo el 10% / 5');
echo 'extra repartir: ' . $GLOBALS['TEST_N'] . " comprobaciones\n";
