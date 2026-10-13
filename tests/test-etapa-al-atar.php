<?php
/** Unidad atada a un deal mas alla de la reserva (13-oct-2026, deal 6220 Gema Zevallos). */
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../stagelib.php';

test_same('RESERVADO', etapa_al_atar('C44:UC_Z3GY5H', 'DISPONIBLE'), 'elaboracion de promesa: la unidad atada queda RESERVADO');
test_same('RESERVADO', etapa_al_atar('C44:UC_4R587H', 'DISPONIBLE'), 'listo para firma: igual');
test_same('RESERVADO', etapa_al_atar('C44:UC_N637MD', 'disponible'), 'reventas-reserva: igual (sin importar mayusculas)');
test_same(null, etapa_al_atar('C44:UC_Z3GY5H', 'FIRMADO'), 'no baja una FIRMADA');
test_same(null, etapa_al_atar('C44:UC_Z3GY5H', 'VENDIDO'), 'no toca una VENDIDA');
test_same(null, etapa_al_atar('C44:UC_Z3GY5H', 'BLOQUEADO'), 'no toca una BLOQUEADA');
test_same(null, etapa_al_atar('C44:UC_Z3GY5H', 'RESERVADO'), 'si ya esta reservada, nada que hacer');
test_same(null, etapa_al_atar('C44:PREPARATION', 'DISPONIBLE'), 'canje: fuera (no se sabe que etapa le toca)');
test_same(null, etapa_al_atar('C44:UC_W4OOQY', 'DISPONIBLE'), 'cesiones: fuera');
test_same(null, etapa_al_atar('C44:LOSE', 'DISPONIBLE'), 'reserva caida: nunca reserva');
test_same(null, etapa_al_atar('C28:NEW', 'DISPONIBLE'), 'otro embudo: nada');
test_same(null, etapa_al_atar('C44:UC_Z3GY5H', ''), 'sin etapa conocida de la unidad: no se toca');
test_same(null, etapa_al_atar('C44:UC_Z3GY5H', 'DISPONIBLE', false), 'perilla reservar_al_atar_avanzado en 0: no cambia nada');
// el codigo de guardar usa esto SOLO para las recien agregadas
$src = (string)file_get_contents(__DIR__ . '/../campolib.php');
test_same(true, str_contains($src, "} elseif (isset(\$agregarSet[\$uid])) {") && str_contains($src, 'etapa_al_atar($etapa, (string)unit_stage_name($it),'),
          'sincronizar_deal lo aplica solo a las unidades recien atadas');
test_same(true, str_contains($src, "if (isset(\$campos['stageId']) && empty(\$sinMarca)) @touch("),
          'la reserva al atar NO deja marca self_u_ (no se come una edicion humana)');
echo 'etapa al atar: ' . $GLOBALS['TEST_N'] . " comprobaciones\n";
