<?php
/**
 * Historia por UNIDAD (12-oct-2026): reubicaciones y ventas que no pasan por
 * "deal entra a RESERVA". El caso que lo origino es de verdad: Marcel Salvador,
 * deals 5792 y 5788, E-1-20 y E-1-21 de Noral Plaza, con el deal en ELABORACION
 * PROMESA. Sin red: el generador y la cola se reemplazan por funciones que anotan.
 */
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
if (!defined('NORAL_PROY')) define('NORAL_PROY', [33 => 'NORAL PLAZA', 39 => 'NORAL APARTMENTS']);
require_once __DIR__ . '/../vendidaslib.php';
require_once __DIR__ . '/../historialib.php';

$tmp = sys_get_temp_dir() . '/hu-' . bin2hex(random_bytes(4));
@mkdir($tmp);
$P = "$tmp/pend.json"; $M = "$tmp/marcas.json";
$ahora = 1_800_000_000;

$cat = ['units' => [
    ['codigo' => 'E-1-20', 'cat' => 33, 'stage' => 'RESERVADO', 'dealId' => 5792],
    ['codigo' => 'E-1-21', 'cat' => 33, 'stage' => 'RESERVADO', 'dealId' => 5788],
    ['codigo' => 'C-3-7',  'cat' => 39, 'stage' => 'DISPONIBLE', 'dealId' => 0],
    ['codigo' => 'C1-1',   'cat' => 47, 'stage' => 'RESERVADO', 'dealId' => 9],     // Galero: sin plano
    ['codigo' => 'D-2-12', 'cat' => 33, 'stage' => 'FIRMADO',   'dealId' => 700],
    ['codigo' => 'E-1-22', 'cat' => 33, 'stage' => 'RESERVADO', 'dealId' => 777],   // vendida, pero a OTRO deal
    ['codigo' => 'E-1-30', 'cat' => 33, 'stage' => 'DISPONIBLE', 'dealId' => 55],   // libre aunque quedo un deal pegado
]];
$gen = []; $col = [];
$generar = function (string $proy, string $cod) use (&$gen) { $gen[] = "$proy|$cod"; return ['video' => "video_$cod.mp4?t=1", 'etiqueta' => $cod]; };
$encolar = function (string $v, string $u) use (&$col) { $col[] = "$v|$u"; };
$anotar  = fn(string $u, int $d, int $c, int $ah) => vend_anotar_historia($u, $d, $c, 'prueba', $P) && (function () use ($P, $u, $d, $ah) {
    $l = json_decode((string)file_get_contents($P), true); $l[strtoupper($u) . '|' . $d]['cuando'] = $ah;
    file_put_contents($P, json_encode($l)); return true; })();

// ── Marcel: la reubicacion SI genera historia ────────────────────────────────
$anotar('E-1-20', 5792, 33, $ahora);
$anotar('E-1-21', 5788, 33, $ahora);
$log = hist_procesar_pendientes($cat, $generar, $encolar, 5, $ahora, $P, $M);
test_same(['NORAL PLAZA|E-1-20', 'NORAL PLAZA|E-1-21'], $gen, 'reubicacion de Marcel: las dos unidades nuevas generan historia');
test_same(['video_E-1-20.mp4|E-1-20', 'video_E-1-21.mp4|E-1-21'], $col, 'se encolan sin la coletilla ?t= (la cola espera 5 min)');
test_same([], json_decode((string)file_get_contents($P), true), 'la lista de pendientes queda vacia');
test_same(true, hist_marca_existe('E-1-20', 5792, $M), 'queda la marca unidad|deal');

// ── la MISMA venta otra vez (otro aviso, el barrido) no repite ────────────────
$gen = []; $col = [];
$anotar('E-1-20', 5792, 33, $ahora);
hist_procesar_pendientes($cat, $generar, $encolar, 5, $ahora, $P, $M);
test_same([], $gen, 'ya tenia historia: no se genera otra');

// ── el disparador de siempre ve la marca (reserva normal, llegan los dos) ─────
test_same(false, hist_marca_reclamar('E-1-20', 5792, $M), 'quien llega segundo no se queda con la marca');
test_same(true,  hist_marca_reclamar('E-1-20', 9999, $M), 'otro deal con la misma unidad es OTRA venta (reventa)');

// ── la unidad ya no esta vendida a ese deal: se descarta ───────────────────────
$gen = [];
$anotar('C-3-7', 241608, 39, $ahora);
$l = hist_procesar_pendientes($cat, $generar, $encolar, 5, $ahora, $P, $M);
test_same([], $gen, 'la unidad vieja de la reubicacion (volvio a DISPONIBLE) no publica nada');
test_same(true, (bool)preg_grep('/ya no esta vendida/', $l), 'y queda dicho en el log');

// ── sigue vendida, pero a OTRO deal (se reubico de nuevo en la espera): se descarta
$anotar('E-1-22', 5555, 33, $ahora);
hist_procesar_pendientes($cat, $generar, $encolar, 5, $ahora, $P, $M);
test_same([], $gen, 'la unidad ahora es de otro deal: la historia de este deal no sale');

// ── proyecto sin plano: en silencio ────────────────────────────────────────────
$anotar('C1-1', 9, 47, $ahora);
hist_procesar_pendientes($cat, $generar, $encolar, 5, $ahora, $P, $M);
test_same([], $gen, 'Galero no tiene plano: no se genera');
test_same([], json_decode((string)file_get_contents($P), true), 'y no se queda esperando');

// ── viejo: no se publica una venta de hace dias ────────────────────────────────
$anotar('D-2-12', 700, 33, $ahora - 3 * 86400);
hist_procesar_pendientes($cat, $generar, $encolar, 5, $ahora, $P, $M);
test_same([], $gen, 'anotada hace 3 dias: se descarta');

// ── tope por corrida ───────────────────────────────────────────────────────────
$muchas = ['units' => []];
for ($i = 1; $i <= 7; $i++) {
    $muchas['units'][] = ['codigo' => "F-2-$i", 'cat' => 33, 'stage' => 'RESERVADO', 'dealId' => 100 + $i];
    $anotar("F-2-$i", 100 + $i, 33, $ahora);
}
$gen = [];
hist_procesar_pendientes($muchas, $generar, $encolar, 5, $ahora, $P, $M);
test_same(5, count($gen), 'tope: 5 por vuelta');
test_same(2, count(json_decode((string)file_get_contents($P), true)), 'las otras 2 esperan la siguiente');
$gen = [];
hist_procesar_pendientes($muchas, $generar, $encolar, 5, $ahora, $P, $M);
test_same(2, count($gen), 'y salen en la siguiente vuelta');

// ── el generador falla: se suelta la marca y se reintenta ──────────────────────
$malo = fn(string $p, string $c) => null;
$anotar('E-1-21', 5788, 33, $ahora);   // ya tenia marca de la primera corrida: se borra para probar
hist_marca_soltar('E-1-21', 5788, $M);
hist_procesar_pendientes($cat, $malo, $encolar, 5, $ahora, $P, $M);
test_same(false, hist_marca_existe('E-1-21', 5788, $M), 'sin video no queda marca: el proximo intento puede');
test_same(1, (int)(json_decode((string)file_get_contents($P), true)['E-1-21|5788']['intentos'] ?? 0), 'cuenta el intento');
hist_procesar_pendientes($cat, $malo, $encolar, 5, $ahora, $P, $M);
hist_procesar_pendientes($cat, $malo, $encolar, 5, $ahora, $P, $M);
test_same([], json_decode((string)file_get_contents($P), true), 'a los 3 fallos se abandona (no se reintenta para siempre)');

// ── olvidar el deal (se solto la unidad) borra sus marcas ──────────────────────
hist_marcas_olvidar_deal(5792, $M);
test_same(false, hist_marca_existe('E-1-20', 5792, $M), 'soltar la unidad borra la marca de ese deal');
test_same(true, hist_marca_existe('E-1-20', 9999, $M), 'y no la de otro deal');

// ── archivo de marcas que no se puede abrir: se genera igual ────────────────────
test_same(true, hist_marca_reclamar('E-9-9', 1, "$tmp/no-existe-la-carpeta/marcas.json"),
          'sin poder leer la marca, se genera (una historia de mas, nunca una perdida)');

// ── perilla apagada (por defecto): no se anota nada ─────────────────────────────
test_same(false, vend_anotar_historia('E-9-9', 1, 33, 'prueba'), 'perilla historia_por_unidad apagada: no anota');

// ── vivos para la cola: lo que el catalogo da por vendido ──────────────────────
test_same(['33:E-1-20', '33:E-1-21', '33:D-2-12', '33:E-1-22'], hist_codigos_vendidos_catalogo($cat),
          'reubicacion cuenta como viva; DISPONIBLE no; Galero no');

// el MISMO codigo en Plaza y en Apartments viaja distinto (13-oct, D-2-4 / E-1-6)
$dos = ['units' => [['codigo' => 'D-2-4', 'cat' => 33, 'stage' => 'RESERVADO', 'dealId' => 1],
                    ['codigo' => 'D-2-4', 'cat' => 39, 'stage' => 'RESERVADO', 'dealId' => 6364]]];
test_same(['33:D-2-4', '39:D-2-4'], hist_codigos_vendidos_catalogo($dos), 'D-2-4 de Plaza y de Apartments no se confunden');

array_map('unlink', glob("$tmp/*") ?: []);
@rmdir($tmp);
echo 'historia por unidad: ' . $GLOBALS['TEST_N'] . " comprobaciones\n";
