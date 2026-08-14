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

/**
 * Quién está aplicando la subida.
 * Bitrix manda AUTH_ID al abrir el placement; con eso se le pregunta su nombre. Si
 * la pantalla se abrió por URL suelta ese dato no existe, y entonces vale el nombre
 * que la persona escriba. Una subida de precios sin responsable no sirve de nada:
 * dentro de un mes nadie va a saber quién la decidió.
 */
function pm_quien(string $auth, string $escrito): string {
    $escrito = trim($escrito);
    if ($auth !== '') {
        $dom = (string)(getenv('BITRIX_DOMINIO') ?: 'galjosa.bitrix24.com');
        $ch = curl_init("https://{$dom}/rest/user.current?auth=" . rawurlencode($auth));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8]);
        $j = json_decode((string)curl_exec($ch), true);
        $u = $j['result'] ?? null;
        if (is_array($u)) {
            $nom = trim(($u['NAME'] ?? '') . ' ' . ($u['LAST_NAME'] ?? ''));
            if ($nom !== '') return $nom;
        }
    }
    return $escrito !== '' ? $escrito : 'sin identificar';
}

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
        'quien'     => pm_quien((string)($_POST['auth'] ?? ''), (string)($_POST['quien'] ?? '')),
        'fecha'     => gmdate('Y-m-d H:i') . ' UTC',
    ];
    $val  = (float)($_POST['val'] ?? 0);
    $tipo = ($_POST['tipo'] ?? 'monto') === 'pct' ? 'pct' : 'monto';
    if (abs($val) < 0.005) exit(json_encode(['ok' => false, 'error' => 'El monto es cero: no hay nada que aplicar.']));
    $aj[$tipo] = $val;

    // El ajuste se guarda ANTES de escribir: si Bitrix falla a la mitad, la matriz
    // ya dice cuál es el precio bueno y volver a aplicar termina el trabajo. Al
    // revés se perdería la subida y nadie sabría cuáles quedaron a medias.
    //
    // Pero se guarda como PENDIENTE. Si la petición muere antes de escribir -- pasó:
    // la matriz decía 148.001 y Bitrix seguía en 148.000 -- el histórico lo dice y
    // ofrece reintentar, en vez de figurar como aplicado y sin respaldo que deshacer.
    $aj['estado'] = 'pendiente';
    $lista = mz_ajustes($cat);
    $lista[] = $aj;
    mz_ajustes_guardar($cat, $lista);

    $cfg2 = mz_cfg($cat);
    // Se usa el caché compartido, no una lectura nueva: releer las 304 unidades son
    // 7 páginas con freno y era justo lo que hacía que la petición se cayera antes
    // de escribir. El precio de hoy que hace falta para el plan ya está ahí.
    $filas = mz_plan($cfg2, mz_unidades_cache($cfg2));
    [$ok, $err, $respaldo] = mz_aplicar($cfg2, $filas);
    mz_cache_actualizar($cfg2, $filas);
    $n = count(array_filter($filas, fn($r) => $r['cambia']));

    // El respaldo queda apuntado en el propio ajuste: así "deshacer" sabe qué
    // archivo restaurar sin que nadie tenga que elegirlo de una lista.
    $lista = mz_ajustes($cat);
    if ($lista) {
        $i = count($lista) - 1;
        $lista[$i]['respaldo'] = $respaldo;
        $lista[$i]['escritas'] = $ok;
        $lista[$i]['estado']   = $err ? 'parcial' : 'aplicado';
        mz_ajustes_guardar($cat, $lista);
    }

    logline("MATRIZ cat=$cat ajuste=" . json_encode($aj, JSON_UNESCAPED_UNICODE)
          . " · {$ok}/{$n} unidades escritas · respaldo={$respaldo}"
          . ($err ? ' · errores: ' . implode('; ', $err) : ''));

    exit(json_encode(['ok' => true, 'escritas' => $ok, 'previstas' => $n,
                      'errores' => $err, 'ajuste' => $aj, 'respaldo' => $respaldo],
                     JSON_UNESCAPED_UNICODE));
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
    $fuera['deshecho_por'] = pm_quien((string)($_POST['auth'] ?? ''), (string)($_POST['quien'] ?? ''));
    mz_ajustes_guardar($cat, $lista);

    // Se quita el ajuste de la matriz Y se devuelven los precios que tenían las
    // unidades antes. Estas son unidades reales que el equipo está cotizando: dejar
    // la matriz corregida pero Bitrix con el precio nuevo sería lo peor de los dos
    // mundos, porque nadie sabría cuál de los dos manda.
    $rest = [0, []];
    if (!empty($fuera['respaldo'])) {
        $rest = mz_restaurar($cfg, (string)$fuera['respaldo'], $cfg);
    }
    logline("MATRIZ cat=$cat DESHECHO " . json_encode($fuera, JSON_UNESCAPED_UNICODE)
          . " · {$rest[0]} precios restaurados");
    exit(json_encode(['ok' => true, 'fuera' => $fuera, 'restauradas' => $rest[0],
                      'errores' => $rest[1]], JSON_UNESCAPED_UNICODE));
}

// ── ver ─────────────────────────────────────────────────────────────────────
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$px = mz_precios_vigentes($cfg);
$cacheInfo = null;
try {
    // La pantalla lee el archivo que deja warm-precios.php; solo intenta refrescar
    // si está vencido, y si Bitrix falla sirve la copia anterior fechada.
    $unid = mz_unidades_cache($cfg, 0, $cacheInfo);
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
// El caché compartido guarda el NOMBRE de la etapa: los STATUS_ID cambian por
// pipeline y el nombre no.
$LETRA = ['DISPONIBLE' => 'D', 'RESERVADO' => 'R', 'FIRMADO' => 'F', 'VENDIDO' => 'F', 'BLOQUEADO' => 'N'];

$estado = []; $pvp = []; $m2 = [];
foreach ($unid as $u => $d) {
    $estado[$u] = $LETRA[$d['etapa']] ?? '?';
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
    'niv'        => array_map(fn($n) => $n['etiqueta'] ?? '', $cfg['niveles']),
    'cat_nom'    => array_map(fn($c) => $c['etiqueta'] ?? '', $cfg['categorias']),
    'proyectos'  => array_map(fn($c) => $c['proyecto'] ?? '', $proyectos),
    'token'      => $tok,
    // Bitrix lo manda al abrir el placement. Sirve para saber quién aplica sin
    // pedirle que se identifique a mano.
    'auth'       => (string)($_REQUEST['AUTH_ID'] ?? ''),
    'edad'       => (int)($cacheInfo['edad'] ?? 0),
    'fresco'     => (bool)($cacheInfo['fresco'] ?? true),
];

$tpl = (string)file_get_contents(__DIR__ . '/matriz.tpl.html');
echo str_replace('/*__DATOS__*/',
    'const DATA = ' . json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';',
    $tpl);
