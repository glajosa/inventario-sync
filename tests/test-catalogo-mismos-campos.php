<?php
/**
 * El catalogo de unidades (selector_cache.json) se escribe por DOS caminos:
 *   - el rebuild completo   selector.php   ($units[] = [ ... ])
 *   - el rearmado por evento unidadlib.php unidad_evento() ($nueva = [ ... ])
 *
 * Si el segundo pierde un campo que el primero tiene, la unidad queda "a medias" en
 * cuanto alguien la toca en Bitrix, y nadie lo nota hasta que algo falla. Paso el
 * 3-oct-2026: unidad_evento() no guardaba `tipo`; subir el precio de D-2/3/4-12 las
 * dejo con tipo 0 y la lista de precios las escondio ("Sin lista de precios: Tipo 0").
 * El tipo tambien decide el descuento de parqueo de las suites.
 *
 * Prueba de CONTRATO: compara las claves que escribe cada camino, leyendo el codigo.
 */
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

function cmc_claves(string $archivo, string $abre): array {
    $src = (string)file_get_contents(__DIR__ . '/../' . $archivo);
    $i = strpos($src, $abre);
    if ($i === false) throw new RuntimeException("NO PUDE COMPROBAR: no encontre '$abre' en $archivo");
    $j = strpos($src, '];', $i);
    preg_match_all("/^\s*'([a-zA-Z]+)'\s*=>/m", substr($src, $i, $j - $i), $m);
    return array_values(array_unique($m[1]));
}

$completo = cmc_claves('selector.php', '$units[] = [');
$evento   = cmc_claves('unidadlib.php', '$nueva = [');

test_same(true, count($completo) >= 8, 'el rebuild completo trae sus campos (si da pocos, la lectura del codigo fallo)');
test_same(true, in_array('tipo', $completo, true), 'el rebuild completo guarda el tipo');
test_same([], array_values(array_diff($completo, $evento)),
          'el rearmado por evento guarda TODOS los campos del rebuild completo');

echo 'catalogo-mismos-campos: ' . count($completo) . " campos comparados\n";
