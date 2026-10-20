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
test_same('RESERVADO', etapa_al_atar('C44:PREPARATION', 'DISPONIBLE'), 'canje no firmado: atada = RESERVADO (A-1-10, C-1-20, B-3-2; Jesua 20-oct)');
test_same('RESERVADO', etapa_al_atar('C44:UC_W4OOQY', 'DISPONIBLE'), 'cesiones: igual, "si lo ataron a un deal es porque es el"');
test_same('RESERVADO', etapa_al_atar('C44:UC_CUALQUIERA', 'DISPONIBLE'), 'una etapa nueva de Clientes que nadie listo: igual');
test_same(null, etapa_al_atar('C44:APOLOGY', 'DISPONIBLE'), 'firmados caidos: nunca reserva (suelta)');
test_same(null, etapa_al_atar('C48:UC_1WR2BM', 'DISPONIBLE'), 'cobranzas: no (lo manda su propio flujo)');
test_same(null, etapa_al_atar('', 'DISPONIBLE'), 'deal sin etapa: no se toca');
test_same(null, etapa_al_atar('C44:LOSE', 'DISPONIBLE'), 'reserva caida: nunca reserva');
test_same(null, etapa_al_atar('C28:NEW', 'DISPONIBLE'), 'otro embudo: nada');
test_same(null, etapa_al_atar('C44:UC_Z3GY5H', ''), 'sin etapa conocida de la unidad: no se toca');
test_same(null, etapa_al_atar('C44:UC_Z3GY5H', 'DISPONIBLE', false), 'perilla reservar_al_atar_avanzado en 0: no cambia nada');
// el codigo de guardar usa esto SOLO para las recien agregadas
$src = (string)file_get_contents(__DIR__ . '/../campolib.php');
$i = strpos($src, '            } else {
                /* La etapa del deal no dice nada de la unidad');
$j = strpos($src, 'etapa_al_atar($etapa, (string)unit_stage_name($it),');
test_same(true, $i !== false && $j !== false && $j > $i && $j - $i < 900,
          'sincronizar_deal lo aplica a TODAS las unidades atadas del campo (no solo las recien agregadas)');
test_same(false, str_contains($src, "} elseif (isset(\$agregarSet[\$uid])) {"), 'ya no se limita a las recien agregadas');
test_same(true, str_contains($src, "if (isset(\$campos['stageId']) && empty(\$sinMarca)) @touch("),
          'la reserva al atar NO deja marca self_u_ (no se come una edicion humana)');
echo 'etapa al atar: ' . $GLOBALS['TEST_N'] . " comprobaciones\n";
