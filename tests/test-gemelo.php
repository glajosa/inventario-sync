<?php
/** El 48 toma la unidad de su gemelo 44 (13-oct-2026, Gema Zevallos 6302/6220). */
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../gemelolib.php';
$R = GEMELO_ETAPA_REUBICACION;
$gema = [['ID' => 6220, 'CATEGORY_ID' => 44, 'STAGE_ID' => 'C44:UC_Z3GY5H'], ['ID' => 6302, 'CATEGORY_ID' => 48, 'STAGE_ID' => $R]];

test_same(true, gemelo_decide(6220, 'DISPONIBLE', $R, false, $gema)['ok'], 'Gema: el 6302 en REUBICACION toma la D-2-21 de su 6220');
test_same(true, gemelo_decide(6220, 'RESERVADO', $R, false, $gema)['ok'], 'tambien si la unidad del gemelo ya esta RESERVADO');
test_same(true, gemelo_decide(6220, 'DISPONIBLE', 'C48:NEW', true, $gema)['ok'], 'en la ventanita (pendiente) tambien');

$dos = array_merge($gema, [['ID' => 7000, 'CATEGORY_ID' => 44, 'STAGE_ID' => 'C44:NEW']]);
$r = gemelo_decide(6220, 'DISPONIBLE', $R, false, $dos);
test_same([false, 'el cliente tiene varias ventas, elegir a mano'], [$r['ok'], $r['motivo']], 'contacto con DOS ventas en Clientes: rechaza');
$conCaida = array_merge($gema, [['ID' => 7001, 'CATEGORY_ID' => 44, 'STAGE_ID' => 'C44:LOSE']]);
test_same(true, gemelo_decide(6220, 'DISPONIBLE', $R, false, $conCaida)['ok'], 'una venta CAIDA no cuenta como otra venta abierta');

test_same(false, gemelo_decide(6220, 'DISPONIBLE', 'C48:NEW', false, $gema)['ok'], 'el 48 fuera de REUBICACION y sin pendiente: rechaza');
test_same(false, gemelo_decide(9999, 'DISPONIBLE', $R, false, $gema)['ok'], 'la unidad es de un 44 de OTRO contacto (no esta en sus ventas): rechaza');
test_same(false, gemelo_decide(6220, 'DISPONIBLE', $R, false, $gema, false)['ok'], 'perilla reubica_gemelo en 0: rechaza (como antes)');
test_same(false, gemelo_decide(6220, 'DISPONIBLE', $R, false, null)['ok'], 'no se pudieron leer las ventas: rechaza');
$f = gemelo_decide(6220, 'FIRMADO', $R, false, $gema);
test_same(false, $f['ok'], 'FIRMADO en Clientes: rechaza');
test_same(true, str_contains($f['motivo'], 'revisar una persona'), 'y avisa que lo revise una persona');
test_same(false, gemelo_decide(6220, 'VENDIDO', $R, false, $gema)['ok'], 'VENDIDO: rechaza');
test_same(false, gemelo_decide(6220, 'BLOQUEADO', $R, false, $gema)['ok'], 'BLOQUEADO: rechaza');
test_same(false, gemelo_decide(6220, 'PERDIDO', $R, false, $gema)['ok'], 'PERDIDO: rechaza');
test_same(false, gemelo_decide(0, 'DISPONIBLE', $R, false, $gema)['ok'], 'sin dueno: no es caso de gemelo');
$soloCob = [['ID' => 6302, 'CATEGORY_ID' => 48, 'STAGE_ID' => $R]];
test_same(false, gemelo_decide(6220, 'DISPONIBLE', $R, false, $soloCob)['ok'], 'el cliente no tiene venta abierta en Clientes: rechaza');

// los dos lugares usan la MISMA regla
$campo = (string)file_get_contents(__DIR__ . '/../campolib.php');
$guard = (string)file_get_contents(__DIR__ . '/../guardar.php');
$field = (string)file_get_contents(__DIR__ . '/../field.php');
test_same(true, str_contains($campo, 'if ($gemelo !== null && $gemelo($id, $dueno, $stage)) $ok[] = $id;'), 'unidades_asignables consulta al gemelo solo para unidades con dueno');
test_same(true, str_contains($guard, 'gemelo_decide($dueno, $etapaU, $stage, $pend, $dealsC, gemelo_perilla())') && str_contains($guard, 'reub_deals_contacto($contacto)'), 'guardar.php decide con gemelo_decide y la libreta (con su caida a Bitrix anotada)');
test_same(true, str_contains($field, 'gemelo_decide($dn, (string)$u[\'stage\'], $etapa48, $pendG, $dc)') && str_contains($field, 'gemelo_deals_libreta('), 'la pantalla usa la misma regla y solo la libreta');
echo 'gemelo: ' . $GLOBALS['TEST_N'] . " comprobaciones\n";
