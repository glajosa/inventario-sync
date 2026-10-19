<?php
/** Firma en abonos: el primer abono NUNCA antes de la separacion (Jesua 18-oct-2026). */
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../cotizarlib.php';
$abonos = fn(array $p) => array_values(array_map(fn($f) => $f['fecha'], array_filter($p['filas'], fn($f) => !empty($f['soloFirma']))));
// el caso real: separacion 19/10 (firma 29/10), firma en 3 abonos
$p = cot_plan(94500, 52, 'estandar', '', ['y' => 2031, 'm' => 4], 0, ['firmaAntes' => true, 'firmaMeses' => 3, 'fechaFirma' => '2026-10-29']);
test_same('19/10/2026', $p['fechaReserva'], 'separacion 19/10');
test_same(['29/10/2026', '16/11/2026', '16/12/2026'], $abonos($p), 'el primer abono es el DIA de la firma; los demas el 16 de cada mes');
test_same('2027-01', $p['inicio'], 'y la primera cuota, el 16 del mes siguiente al ultimo abono');
// reserva ANTES del 16: el primer abono sigue siendo ese mismo mes (como antes)
$q = cot_plan(94500, 52, 'estandar', '', ['y' => 2031, 'm' => 4], 0, ['firmaAntes' => true, 'firmaMeses' => 3, 'fechaFirma' => '2026-10-20']);
test_same('10/10/2026', $q['fechaReserva'], 'separacion 10/10');
test_same(['20/10/2026', '16/11/2026', '16/12/2026'], $abonos($q), 'firma el 20: primer abono el 20, luego un abono por mes el 16');
// la separacion el MISMO 16: ese dia vale
$r = cot_plan(94500, 52, 'estandar', '', ['y' => 2031, 'm' => 4], 0, ['firmaAntes' => true, 'firmaMeses' => 2, 'fechaFirma' => '2026-10-26']);
test_same(['26/10/2026', '16/11/2026'], $abonos($r), 'dos abonos: el dia de la firma y el 16 siguiente');
// el caso de Priscila Roman (F-1-15, 19-oct): firma 28/10, 3 abonos, 52 cuotas
$pr = cot_plan(94500, 52, 'estandar', '', ['y' => 2031, 'm' => 4], 0, ['firmaAntes' => true, 'firmaMeses' => 3, 'fechaFirma' => '2026-10-28']);
test_same(['28/10/2026', '16/11/2026', '16/12/2026'], $abonos($pr), 'Priscila: primer abono el 28/10');
test_same('2027-01', $pr['inicio'], 'Priscila: primera cuota en enero');
// un solo abono: la primera cuota igual cae un 16
$u1 = cot_plan(94500, 52, 'estandar', '', ['y' => 2031, 'm' => 4], 0, ['firmaAntes' => true, 'firmaMeses' => 1, 'fechaFirma' => '2026-10-28']);
test_same(['28/10/2026'], $abonos($u1), 'un abono: el dia de la firma');
test_same('2026-11', $u1['inicio'], 'un abono: primera cuota el 16 del mes siguiente');
test_same(true, str_starts_with($u1['filas'][1]['fecha'] ?? '', '16/'), 'la primera cuota siempre cae un 16');
// ningun abono antes de la separacion, en ningun caso
foreach (['2026-10-17', '2026-10-25', '2026-10-31', '2026-11-05'] as $ff) {
    $x = cot_plan(94500, 52, 'estandar', '', ['y' => 2031, 'm' => 4], 0, ['firmaAntes' => true, 'firmaMeses' => 3, 'fechaFirma' => $ff]);
    $sep = DateTimeImmutable::createFromFormat('d/m/Y', $x['fechaReserva'])->format('Ymd');
    $prim = DateTimeImmutable::createFromFormat('d/m/Y', $abonos($x)[0])->format('Ymd');
    test_same(true, $prim >= $sep, "firma $ff: el primer abono no cae antes de la separacion");
}
echo 'firma antes (fecha): ' . $GLOBALS['TEST_N'] . " comprobaciones\n";
