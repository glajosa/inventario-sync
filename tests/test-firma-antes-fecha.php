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
// ── SEPARACION escrita + firma libre + DIA DE PAGO (Jesua 19-oct-2026) ──────────
$E = ['y' => 2031, 'm' => 4];
$cuotasDe = fn(array $p) => array_values(array_map(fn($f) => $f['fecha'], array_filter($p['filas'], fn($f) => empty($f['soloFirma']))));
$s7 = cot_plan(94500, 52, 'estandar', '', $E, 0, ['fechaSeparacion' => '2026-10-19', 'fechaFirma' => '2026-10-26']);
test_same(['19/10/2026', '26/10/2026'], [$s7['fechaReserva'], $s7['fechaFirma']], 'firma a 7 dias: la separacion NO se mueve');
test_same('16/11/2026', $cuotasDe($s7)[0], 'y la primera cuota el 16 del mes siguiente a la firma');
$s0 = cot_plan(94500, 52, 'estandar', '', $E, 0, ['fechaSeparacion' => '2026-10-19']);
test_same('29/10/2026', $s0['fechaFirma'], 'separacion sin firma: la firma 10 dias despues');
$sx = cot_plan(94500, 52, 'estandar', '', $E, 0, ['fechaSeparacion' => '2026-10-19', 'fechaFirma' => '2026-10-10']);
test_same('19/10/2026', $sx['fechaFirma'], 'una firma ANTES de separar se corre al dia de la separacion');
$v = cot_plan(94500, 52, 'estandar', '', $E, 0, ['fechaFirma' => '2026-10-28']);
test_same('18/10/2026', $v['fechaReserva'], 'sin separacion escrita, como siempre: separacion = firma - 10 (tablas viejas)');
$d20 = cot_plan(94500, 52, 'estandar', '', $E, 0, ['fechaSeparacion' => '2026-10-19', 'fechaFirma' => '2026-10-28',
                 'diaPago' => 20, 'firmaAntes' => true, 'firmaMeses' => 3]);
test_same(['28/10/2026', '20/11/2026', '20/12/2026'], $abonos($d20), 'abonos: el primero el dia de la firma, los demas el dia de pago (20)');
test_same('20/01/2027', $cuotasDe($d20)[0], 'cuotas el 20');
test_same(true, count(array_filter($cuotasDe($d20), fn($f) => !str_starts_with($f, '20/'))) === 0, 'TODAS las cuotas el 20');
test_same(20, $d20['diaPago'], 'el plan dice su dia de pago');
foreach ([5, 12, 29, 31, 0] as $malo)
    test_same(16, cot_plan(94500, 52, 'estandar', '', $E, 0, ['diaPago' => $malo])['diaPago'], "dia de pago $malo fuera de 13..28: vuelve al 16");
$n20 = cot_plan(94500, 52, 'estandar', '', $E, 0, ['fechaSeparacion' => '2026-10-19', 'fechaFirma' => '2026-10-26', 'diaPago' => 20]);
test_same('20/11/2026', $cuotasDe($n20)[0], 'sin abonos, dia de pago 20: la primera cuota el 20 del mes siguiente a la firma');
$m20 = cot_plan(94500, 52, 'estandar', '2027-03', $E, 0, ['diaPago' => 20]);
test_same('20/03/2027', $cuotasDe($m20)[0], 'primera cuota escrita a mano: cae el dia de pago');
// ── la fecha de la firma ESCRITA saca la firma de las cuotas (19-oct, deal 413035) ──
test_same(true,  cot_firma_sola(true, '', '', 0, '', false), 'casilla marcada: sola');
test_same(true,  cot_firma_sola(false, '2026-10-19', '2026-10-27', 1, '', false), 'fecha escrita + 1 mes: sola');
test_same(true,  cot_firma_sola(false, '2026-10-19', '2026-10-27', 0, '500', false), 'fecha escrita + cuota mensual: sola');
test_same(true,  cot_firma_sola(false, '2026-10-19', '2026-10-27', 0, '', true), 'fecha escrita + a la medida: sola');
test_same(false, cot_firma_sola(false, '2026-10-19', '', 3, '', false), 'sin fecha de firma: sigue sumada a las cuotas');
test_same(false, cot_firma_sola(false, '', '2026-10-27', 3, '', false), 'enlace viejo (sin separacion): como antes');
test_same(false, cot_firma_sola(false, '2026-10-19', '2026-10-27', 0, '', false), 'firma sin partir: no hace falta (ya va en su fila)');
test_same(false, cot_firma_sola(false, '2026-10-19', '2026-10-27', 0, '0', false), 'cuota mensual en 0 no cuenta');
// el caso de Mario: firma el 27 en 1 pago, cuotas el 15
$sola = fn(array $o) => cot_plan(94500, 52, 'estandar', '', $E, 0, $o + ['firmaAntes' => cot_firma_sola(false,
    (string)($o['fechaSeparacion'] ?? ''), (string)($o['fechaFirma'] ?? ''), (int)($o['firmaMeses'] ?? 0), '', false)]);
$ma = $sola(['fechaSeparacion' => '2026-10-19', 'fechaFirma' => '2026-10-27', 'firmaMeses' => 1, 'diaPago' => 15]);
test_same(['27/10/2026'], $abonos($ma), 'Mario: la firma en su fila, el dia escrito');
test_same('15/11/2026', $cuotasDe($ma)[0], 'Mario: primera cuota el 15 del mes siguiente');
test_same(0.0, round(array_sum(array_map(fn($f) => (float)$f['firma'], array_filter($ma['filas'], fn($f) => empty($f['soloFirma'])))), 2), 'la firma ya no va encima de ninguna cuota');
$m4 = $sola(['fechaSeparacion' => '2026-10-19', 'fechaFirma' => '2026-10-25', 'firmaMeses' => 4, 'diaPago' => 15]);
test_same(['25/10/2026', '15/11/2026', '15/12/2026', '15/01/2027'], $abonos($m4), 'en 4: el primero el dia escrito, los demas el 15');
test_same('15/02/2027', $cuotasDe($m4)[0], 'en 4: la primera cuota despues del ultimo abono');
$tot = fn(array $p) => round(array_sum(array_map(fn($f) => (float)$f['monto'], $p['filas'])), 2);
$sinFecha = cot_plan(94500, 52, 'estandar', '', $E, 0, ['fechaSeparacion' => '2026-10-19', 'firmaMeses' => 1, 'diaPago' => 15]);
test_same($tot($sinFecha), $tot($ma), 'el total pagado no cambia por sacar la firma de las cuotas');
$src = (string)file_get_contents(__DIR__ . '/../cotizar.php');
test_same(true, str_contains($src, '$firmaAntes = cot_firma_sola('), 'la pagina decide con cot_firma_sola');
echo 'firma antes (fecha): ' . $GLOBALS['TEST_N'] . " comprobaciones\n";
