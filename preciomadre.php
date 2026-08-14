<?php
/**
 * inventario-sync — preciomadre.php   ·   EL COTIZADOR MADRE
 * ---------------------------------------------------------------------------
 * Pantalla de precios por proyecto: se ve el edificio entero con sus unidades, se
 * simula una subida ("+$3.000 al edificio F") y, si convence, se aplica.
 *
 * Se abre dentro de Bitrix como slider (placement). También abre suelta en el
 * navegador con ?token=<OUTBOUND_TOKEN>.
 *
 * ── POR QUÉ REUSA LA PLANTILLA DEL EXPLORADOR ──────────────────────────────
 * `matriz.tpl.html` ya dibuja las fichas, los filtros y la tabla de referencia, y
 * TODO lo pinta desde un único objeto DATA. Aquí solo se arma ese DATA con el
 * estado en vivo de Bitrix y se inyecta. Rehacer la vista habría significado dos
 * interfaces que dicen lo mismo y se separan a la primera semana.
 *
 * ── LO QUE ESCRIBE Y LO QUE NO ─────────────────────────────────────────────
 * Ver la pantalla no escribe nada. Aplicar exige clave, y aun así solo toca
 * unidades DISPONIBLES de edificios LANZADOS — lo reservado y lo vendido conserva
 * el precio que el cliente firmó. Los dos candados viven en matrizlib, no aquí.
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

require_once __DIR__ . '/campolib.php';     // bx(), logline()
require_once __DIR__ . '/matrizlib.php';

$BX_FRENO_US = 120000;   // pantalla, no barrido: freno suave

// ── acceso ──────────────────────────────────────────────────────────────────
// Bitrix abre el placement por POST y no arrastra la query, así que el token
// también se acepta por POST.
$tok = (string)($_REQUEST['token'] ?? '');
$esperado = (string)getenv('OUTBOUND_TOKEN');
if ($esperado === '' || !hash_equals($esperado, $tok)) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    exit('<!doctype html><meta charset="utf-8"><p style="font:15px system-ui;padding:30px">Sin acceso.</p>');
}

$proyectos = mz_proyectos();
$cat = (int)($_REQUEST['cat'] ?? array_key_first($proyectos) ?? 0);
$cfg = mz_cfg($cat);
if (!$cfg) {
    http_response_code(404);
    exit('<!doctype html><meta charset="utf-8"><p style="font:15px system-ui;padding:30px">Ese proyecto no tiene matriz de precios todavía.</p>');
}

$accion = (string)($_REQUEST['accion'] ?? 'ver');

/** Nombre legible del proyecto, por si el JSON no lo trae. */
function pm_nombre(array $cfg, int $cat): string {
    return (string)($cfg['proyecto'] ?? "Proyecto $cat");
}

// ── aplicar ─────────────────────────────────────────────────────────────────
// Se responde JSON: lo llama el botón de la pantalla, no un humano tecleando URLs.
if ($accion === 'aplicar') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    $clave = (string)getenv('PRECIOS_CLAVE');
    if ($clave === '' || !hash_equals($clave, (string)($_POST['clave'] ?? ''))) {
        http_response_code(403);
        exit(json_encode(['ok' => false, 'error' => 'Clave incorrecta. No se escribió nada.']));
    }

    $aj = [
        'aplica_a'  => (string)($_POST['dest'] ?? '*'),
        'nivel'     => (string)($_POST['niv'] ?? '*'),
        'categoria' => (string)($_POST['cat_f'] ?? '*'),
        'nota'      => trim((string)($_POST['nota'] ?? '')) ?: 'subida desde el cotizador madre',
        'fecha'     => gmdate('Y-m-d H:i') . ' UTC',
    ];
    $val  = (float)($_POST['val'] ?? 0);
    $tipo = ($_POST['tipo'] ?? 'monto') === 'pct' ? 'pct' : 'monto';
    if (abs($val) < 0.005) exit(json_encode(['ok' => false, 'error' => 'El monto es cero: no hay nada que aplicar.']));
    $aj[$tipo] = $val;

    // El ajuste se guarda ANTES de escribir: si Bitrix falla a la mitad, la matriz
    // ya dice cuál es el precio bueno y volver a aplicar termina el trabajo. Al
    // revés se perdería la subida y nadie sabría cuáles quedaron a medias.
    $lista = mz_ajustes($cat);
    $lista[] = $aj;
    mz_ajustes_guardar($cat, $lista);

    $cfg2 = mz_cfg($cat);
    mz_cache_borrar($cfg2);                 // tras escribir, la foto vieja miente
    $filas = mz_plan($cfg2, mz_unidades($cfg2));
    [$ok, $err] = mz_aplicar($cfg2, $filas);
    mz_cache_borrar($cfg2);
    $n = count(array_filter($filas, fn($r) => $r['cambia']));

    logline("MATRIZ cat=$cat ajuste=" . json_encode($aj, JSON_UNESCAPED_UNICODE)
          . " · {$ok}/{$n} unidades escritas" . ($err ? ' · errores: ' . implode('; ', $err) : ''));

    exit(json_encode(['ok' => true, 'escritas' => $ok, 'previstas' => $n,
                      'errores' => $err, 'ajuste' => $aj], JSON_UNESCAPED_UNICODE));
}

// ── deshacer el último ajuste ───────────────────────────────────────────────
if ($accion === 'deshacer') {
    header('Content-Type: application/json; charset=utf-8');
    $clave = (string)getenv('PRECIOS_CLAVE');
    if ($clave === '' || !hash_equals($clave, (string)($_POST['clave'] ?? ''))) {
        http_response_code(403);
        exit(json_encode(['ok' => false, 'error' => 'Clave incorrecta.']));
    }
    $lista = mz_ajustes($cat);
    if (!$lista) exit(json_encode(['ok' => false, 'error' => 'No hay ajustes que deshacer.']));
    $fuera = array_pop($lista);
    mz_ajustes_guardar($cat, $lista);
    // Deshacer NO reescribe Bitrix solo: bajar precios ya publicados es una
    // decisión comercial, no un rollback técnico. Queda la matriz corregida y el
    // plan mostrará la diferencia para que alguien la aplique a conciencia.
    logline("MATRIZ cat=$cat DESHECHO " . json_encode($fuera, JSON_UNESCAPED_UNICODE));
    exit(json_encode(['ok' => true, 'fuera' => $fuera], JSON_UNESCAPED_UNICODE));
}

// ── ver ─────────────────────────────────────────────────────────────────────
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$px = mz_precios_vigentes($cfg);
try {
    // Caché corta: la pantalla se abre en un slider y leer 304 unidades son 7
    // páginas con freno. Sin esto tarda ~4 s cada vez que alguien la abre.
    $unid = mz_unidades_cache($cfg, (int)($_REQUEST['recargar'] ?? 0) ? 0 : 180);
} catch (Throwable $e) {
    // Mejor no mostrar nada que mostrar la mitad: con datos parciales los totales
    // mienten y una subida se calcularía sobre un inventario incompleto.
    http_response_code(503);
    $msg = htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    exit('<!doctype html><meta charset="utf-8"><div style="font:15px/1.6 -apple-system,system-ui;'
       . 'padding:40px;max-width:52ch;color:#1a1a18"><h2 style="font-size:17px;margin:0 0 10px">'
       . 'No se pudo leer el inventario completo</h2><p style="color:#52514e">' . $msg . '</p>'
       . '<p style="color:#52514e">Suele ser el límite de llamadas del portal. Espera un minuto y '
       . 'vuelve a abrir.</p></div>');
}
$LETRA = ['PREPARATION' => 'D', 'CLIENT' => 'R', 'UC_FIRMAD' => 'F', 'NEW' => 'N'];

$estado = []; $pvp = []; $m2 = [];
foreach ($unid as $u => $d) {
    $suf = substr((string)strrchr($d['etapa'], ':'), 1);
    $estado[$u] = $LETRA[$suf] ?? '?';
    if ($d['pvp'] !== null) $pvp[$u] = (int)round($d['pvp']);
    if ($d['m2'] !== null && $d['m2'] !== '') $m2[$u] = str_replace(',', '.', (string)$d['m2']);
}

$datos = [
    'version'    => $cfg['version'] ?? '',
    'proyecto'   => pm_nombre($cfg, $cat),
    'cat'        => $cat,
    'edificios'  => mz_edificios($cfg),
    'lanzados'   => mz_edificios($cfg, true),
    'grupos'     => array_combine(mz_edificios($cfg), array_map(fn($e) => mz_grupo_de($cfg, $e), mz_edificios($cfg))),
    'grupoNota'  => array_map(fn($d) => $d['nota'] ?? '', $cfg['grupos']),
    'metraje'    => array_diff_key($cfg['metraje'], ['_nota' => 1]),
    'precios'    => $px,
    'posiciones' => $cfg['posiciones'],
    'ovUnidad'   => $cfg['overrides_unidad'] ?? new stdClass(),
    'uxp'        => $cfg['unidades_por_piso'] ?? new stdClass(),
    'm2paMed'    => $cfg['metraje_pa_medianero'] ?? new stdClass(),
    'combos'     => $cfg['combos'] ?? new stdClass(),
    'estado'     => $estado,
    'pvp'        => $pvp,
    'm2'         => $m2,
    // lo que necesita el panel de aplicar
    'ajustes'    => mz_ajustes($cat),
    'proyectos'  => array_map(fn($c) => $c['proyecto'] ?? '', $proyectos),
    'token'      => $tok,
];

$tpl = (string)file_get_contents(__DIR__ . '/matriz.tpl.html');
echo str_replace('/*__DATOS__*/',
    'const DATA = ' . json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';',
    $tpl);
