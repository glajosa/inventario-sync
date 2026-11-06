<?php
/** 6-nov-2026 (Jesua): el proyecto de la unidad vieja va en "Proyecto 1/2/3" del trío, en el MISMO update, solo si
 *  la perilla REUBICA_PROYECTO_TEXTO=1 y el campo está vacío. Nunca toca "Proyectos 1" (D_PROYECTO). */
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
putenv('DATA_DIR=' . sys_get_temp_dir());
require_once __DIR__ . '/../reubicalib.php';

$t1 = REUBICA_TRIOS[0]; $t2 = REUBICA_TRIOS[1]; $t3 = REUBICA_TRIOS[2];
test_same(['UF_CRM_1793981988107', 'UF_CRM_1793981295411', 'UF_CRM_1793981339409'], [$t1['proyecto'], $t2['proyecto'], $t3['proyecto']], 'cada ocasión tiene su campo Proyecto 1/2/3');
test_same(false, in_array(D_PROYECTO, array_column(REUBICA_TRIOS, 'proyecto'), true), '🔴 nunca "Proyectos 1" (el proyecto ACTUAL del deal)');
test_same('Barranca Apartments', reub_proyecto_limpio('Barranca Apartments (Nuevo Samborondón)'), 'sin la ubicación entre paréntesis');
test_same('Noral Plaza (Suites)', reub_proyecto_limpio('Noral Plaza (Suites)'), 'Suites se queda: es otro activo');
test_same('Noral Plaza (Locales Comerciales)', reub_proyecto_limpio('Noral Plaza (Locales Comerciales)'), 'Locales se queda');
test_same('Noral Plaza', reub_proyecto_limpio('Noral Plaza (Nuevo Samborondon)'), 'ubicación sin tilde también se quita');
test_same([$t1['proyecto'] => 'Barranca Apartments'], reub_campo_proyecto($t1, [], 'Barranca Apartments (Nuevo Samborondón)', true), 'vacío + perilla prendida: escribe');
test_same([], reub_campo_proyecto($t1, [], 'Barranca Apartments', false), '🔴 perilla apagada: no escribe');
test_same([], reub_campo_proyecto($t1, [$t1['proyecto'] => 'Galero'], 'Barranca Apartments', true), '🔴 campo con algo: no lo pisa');
test_same([], reub_campo_proyecto($t2, [], '', true), 'sin proyecto conocido: no inventa');
test_same([$t3['proyecto'] => 'Noral Plaza (Suites)'], reub_campo_proyecto($t3, [$t1['proyecto'] => 'Barranca Apartments'], 'Noral Plaza (Suites)', true), '3ra ocasión: su propio campo');
putenv('REUBICA_PROYECTO_TEXTO'); test_same(false, reub_proyecto_texto_on(), 'apagado por defecto');
putenv('REUBICA_PROYECTO_TEXTO=1'); test_same(true, reub_proyecto_texto_on(), 'con =1 se prende'); putenv('REUBICA_PROYECTO_TEXTO');
// cableado: el campo va en el MISMO update del 48 y del 44 (0 llamadas extra), y antes de crm.deal.update
$src = (string)file_get_contents(__DIR__ . '/../reubicalib.php');
preg_match('/function reubicar\(int \$dealId.*?\n}\n/s', $src, $m); $f = $m[0] ?? '';
$a = strpos($f, '$c48 += reub_campo_proyecto('); $u48 = strpos($f, "bx('crm.deal.update', ['id' => \$dealId");
$b = strpos($f, '$c44 += reub_campo_proyecto(');
test_same(true, $a !== false && $u48 !== false && $a < $u48, 'el 48 lleva el proyecto en su update');
test_same(true, $b !== false, 'el 44 también');
echo "reubica-proyecto: ok\n";
