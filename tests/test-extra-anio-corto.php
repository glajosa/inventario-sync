<?php
/** La extraordinaria del año que NO llega al mes elegido (Jesua 26-oct-2026).
 *  Caso real: Carol Alcivar E-2-2, extraordinaria en diciembre; 2031 solo tiene
 *  enero-abril y la de ese año caia sola el 16/02 ("aleatoria"). */
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../cotizarlib.php';
$E = ['y' => 2031, 'm' => 4];
$extras = fn(array $p) => array_values(array_map(fn($f) => $f['fecha'], array_filter($p['filas'], fn($f) => !empty($f['extra']))));
$plan = fn(array $o) => cot_plan(88990, 53, 'estandar', '', $E, 0, $o + ['fechaSeparacion' => '2026-10-26', 'extraMes1' => 12, 'maxExtra' => 5]);

$auto = $plan([]);
test_same(['16/12/2027', '16/12/2028', '16/12/2029', '16/12/2030', '16/02/2031'], $extras($auto), 'sin elegir: 2031 a mitad (febrero), como antes');
test_same([2031 => [1, 2, 3, 4]], $auto['extraCortos'], 'avisa que 2031 no llega a diciembre y que tiene enero-abril');
test_same('16/03/2031', $extras($plan(['extraMesCorto' => 3]))[4], 'elegido marzo: la de 2031 cae en marzo');
test_same('16/04/2031', $extras($plan(['extraMesCorto' => 4]))[4], 'elegido abril: cae en abril');
test_same('16/01/2031', $extras($plan(['extraMesCorto' => 1]))[4], 'elegido enero: cae en enero');
test_same('16/02/2031', $extras($plan(['extraMesCorto' => 7]))[4], 'un mes que 2031 no tiene: a mitad, no se fuerza');
test_same(array_slice($extras($auto), 0, 4), array_slice($extras($plan(['extraMesCorto' => 3])), 0, 4), 'los años completos no se mueven');
$tot = fn(array $p) => round(array_sum(array_map(fn($f) => (float)$f['monto'], $p['filas'])), 2);
test_same($tot($auto), $tot($plan(['extraMesCorto' => 3])), 'el total no cambia: solo se mueve de mes');
// con el mes que SI existe en todos los años, no hay año corto ni selector
test_same([], cot_plan(88990, 53, 'estandar', '', $E, 0, ['fechaSeparacion' => '2026-10-26', 'extraMes1' => 3, 'maxExtra' => 5])['extraCortos'],
          'extraordinaria en marzo: todos los años la tienen, no aparece el selector');
// partida en 2 (abril + diciembre): el mes 1 sigue la misma regla
$dos = cot_plan(88990, 53, 'estandar', '', $E, 0, ['fechaSeparacion' => '2026-10-26', 'extraPartes' => 2, 'extraMes1' => 6, 'extraMes2' => 12, 'maxExtra' => 5, 'extraMesCorto' => 3]);
test_same(true, in_array('16/03/2031', $extras($dos), true), 'partida en 2: el año corto usa el mes elegido');
$src = (string)file_get_contents(__DIR__ . '/../cotizar.php');
test_same(true, str_contains($src, "\$opts['extraMesCorto'] = \$vExtraCorto;") && str_contains($src, 'name="extramescorto"'), 'la pantalla lee y muestra el campo');
test_same(true, str_contains((string)file_get_contents(__DIR__ . '/../cotizacioneslib.php'), "'extramescorto'"), 'el historial lo guarda');
echo 'extra año corto: ' . $GLOBALS['TEST_N'] . " comprobaciones\n";
