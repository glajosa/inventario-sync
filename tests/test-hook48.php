<?php
// hook48 (6-oct-2026): DADO DE BAJA / PAGADO del 48 -> la unidad al instante. Funciones PURAS reales de hook48lib.php.
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../hook48lib.php';
$T = ['C48:WON' => 'VENDIDO', 'C48:LOSE' => 'DISPONIBLE'];
// el codigo: la unidad de Galero se llama "C1-1" y el 48 dice "C-1-1" (por eso el reconcile nunca la encontraba)
test_same(h48_cod('C-1-1'), h48_cod('C1-1 (Galero Torre C)'), '🔴 Galero: C1-1 de la unidad == C-1-1 del 48');
test_same(h48_cod('D-10-3'), h48_cod('D10-3 (Galero Torre D)'), 'Galero Torre D');
test_same(h48_cod('E-1-21'), h48_cod('E-1-21 (Noral Plaza)'), 'Noral: igual que antes');
test_same(false, h48_cod('C-1-1') === h48_cod('C-1-11'), 'C-1-1 no es C-1-11');
// elegir la unidad
$u = [['id' => 10, 'title' => 'C1-1 (Galero Torre C)'], ['id' => 11, 'title' => 'C1-2 (Galero Torre C)']];
test_same(10, h48_elegir($u, 'C-1-1')['id'] ?? null, '🔴 del 44 se elige la unidad con el codigo del 48');
test_same('sin_unidad', h48_elegir($u, 'C-9-9')['error'] ?? null, 'ninguna con ese codigo -> no escribe');
test_same('varias_unidades', h48_elegir([['id'=>1,'title'=>'C1-1 (Galero Torre C)'],['id'=>2,'title'=>'C-1-1 (otra)']], 'C-1-1')['error'] ?? null, '🔴 dos con el mismo codigo -> no adivina');
test_same('el_48_no_tiene_activo_comprado', h48_elegir($u, '')['error'] ?? null, 'sin ACTIVO COMPRADO -> no escribe');
// el PROYECTO (orquestador): mismo contacto, mismo codigo, otro proyecto -> no toca
$m = [['id' => 815, 'title' => 'C-3-7 (Noral Apartments)', '_proy' => 50], ['id' => 900, 'title' => 'C-3-7 (Barranca Apartments)', '_proy' => 142]];
test_same('la_unidad_es_de_otro_proyecto', h48_elegir([$m[0]], 'C-3-7', 142)['error'] ?? null, '🔴 mismo contacto y codigo, OTRO proyecto -> no la toca (la C-3-7 de Marcel)');
test_same(900, h48_elegir($m, 'C-3-7', 142)['id'] ?? null, '🔴 el mismo codigo en dos proyectos: gana el del proyecto del 48');
test_same('varias_unidades', h48_elegir($m, 'C-3-7', 0)['error'] ?? null, 'el 48 sin proyecto y el codigo en dos proyectos -> no adivina');
test_same(815, h48_elegir([$m[0]], 'C-3-7', 0)['id'] ?? null, 'el 48 sin proyecto: alcanza el codigo (como en FAMILIA)');
test_same(10, h48_elegir([['id' => 10, 'title' => 'C1-1 (Galero Torre C)', '_proy' => 0]], 'C-1-1', 73)['id'] ?? null, 'unidad con proyecto desconocido: no se descarta por eso');
// la accion
test_same('aplicar', h48_accion('C48:LOSE', null, $T), 'DADO DE BAJA -> aplicar');
test_same('aplicar', h48_accion('C48:WON', null, $T), 'PAGADO TOTALMENTE -> aplicar');
test_same('nada', h48_accion('C48:NEW', null, $T), 'otra etapa sin baja previa -> nada');
$est = ['por' => 'C48:LOSE', 'unidad' => 10, 'antes' => 'FIRMADO'];
test_same('vuelta', h48_accion('C48:NEW', $est, $T), '🔴 sale de la baja a una etapa viva -> la unidad vuelve');
test_same('nada', h48_accion('C48:UC_1WR2BM', $est, $T), '🔴 de la baja a REUBICACION NO se devuelve la unidad vieja');
test_same('aplicar', h48_accion('C48:LOSE', $est, $T), 'sigue en baja -> aplicar (idempotente)');
// la guarda 2: no tocar una unidad de otro deal
test_same(true, h48_es_suya(['parentId2' => 0], 44), 'sin dueno -> se puede');
test_same(true, h48_es_suya(['parentId2' => 44], 44), 'atada al 44 del par -> se puede');
test_same(false, h48_es_suya(['parentId2' => 99], 44), '🔴 atada a OTRO deal -> no se toca');
test_same(false, h48_es_suya(['parentId2' => 9348], 0), 'sin par: la del 44 hermano no pasa por es_suya (la resuelve h48_dueno_es_hermano)');
test_same(1, substr_count((string)file_get_contents(__DIR__ . '/../hook48lib.php'), 'h48_dueno_es_hermano((int)$it[\'parentId2\'], (int)($deal[\'CONTACT_ID\'] ?? 0))'), '🔴 sin par, el 44 del MISMO cliente no es "otro deal" (caso 9350 -> unidad 759 -> 44 9348)');
// el receptor: contesta antes de trabajar y respeta la perilla
$src = (string)file_get_contents(__DIR__ . '/../hook48.php');
test_same(true, strpos($src, "getenv('HOOK48_ON') !== '1'") < strpos($src, 'h48_procesar($dealId)'), 'la perilla se mira antes de trabajar');
test_same(true, strpos($src, "echo 'ok'") < strpos($src, 'h48_procesar($dealId)'), '🔴 contesta 200 ANTES de trabajar (8 s de la libreta)');
$lib = (string)file_get_contents(__DIR__ . '/../hook48lib.php');
test_same(0, preg_match("/crm\\.deal\\.(update|add|delete)/", $lib), '🔴 NUNCA escribe en el deal 48');
