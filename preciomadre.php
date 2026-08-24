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
require_once __DIR__ . '/listalib.php';   // lst_familias()

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

// ── descarga de la matriz (la fuente de verdad del proyecto) ────────────────
if (($_REQUEST['accion'] ?? '') === 'matriz') {
    $c = (int)($_REQUEST['cat'] ?? 0);
    $j = $proyectos[$c] ?? null;
    if (!$j) { http_response_code(404); exit('sin matriz'); }
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="proyecto_' . $c . '.json"');
    exit(json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

// ── portada: sin proyecto elegido, se muestran todos ────────────────────────
// Se descubren solos de matrices/proyecto_<cat>.json: agregar un JSON basta para
// que el proyecto aparezca aca, sin tocar esta pantalla.
if (($_REQUEST['accion'] ?? 'ver') === 'ver' && ($_REQUEST['cat'] ?? '') === '') {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    $COLOR = ['#2f6df6', '#5aa02c', '#12a594', '#b0871f', '#9b59b6'];
    $tarjetas = ''; $i = 0; $totProy = 0;
    foreach ($proyectos as $c => $j) {
        $nom = strtoupper((string)($j['proyecto'] ?? "Proyecto $c"));
        $col = $COLOR[$i++ % count($COLOR)];
        $tot = $dis = 0; $err = ''; $fams = [];
        try {
            $uu = mz_unidades_cache($j);
            foreach ($uu as $d) { $tot++; if ($d['etapa'] === 'DISPONIBLE') $dis++; }
            // Las familias que este proyecto vende HOY. Salen del tipo de bien de las
            // fichas disponibles, asi que una familia agotada deja de ofrecer lista sola.
            $fams = lst_familias($uu, $c);
        } catch (Throwable $e) { $err = 'el catálogo todavía se está armando'; }
        $eds  = mz_edificios($j);
        $ne   = count($eds);
        $meta = $err ?: "$tot unidades · $dis disponibles · " . ($ne === 1 ? '1 edificio' : "$ne edificios");
        $nivs = implode(', ', array_values(array_map(fn($n) => $n['etiqueta'] ?? '', $j['niveles'] ?? [])));
        $cats = count($j['categorias'] ?? []);
        $totProy++;
        $tarjetas .= '<section class="pc">
          <header class="ph">
            <span class="bdg" style="background:' . $col . '">' . htmlspecialchars($nom, ENT_QUOTES) . '</span>
            <span class="meta">' . htmlspecialchars($meta, ENT_QUOTES) . '</span>
          </header>
          <div class="cols">
            <div class="col">
              <div class="ct">Explorador de precios <span class="tag">INTERNO</span></div>
              <p>Los edificios con sus pisos y unidades. Categorías, precio de hoy contra
                 el de la matriz, y el simulador para subir por bloque.</p>
              <div class="acts"><a class="b pri" href="?token=' . urlencode($tok) . '&cat=' . $c . '">Abrir</a></div>
            </div>
            <div class="col">
              <div class="ct">Lista de precios <span class="tag ok">PARA EL CLIENTE</span></div>
              <p>' . ($fams
                  ? 'Una lista por familia, con separación, firma, cuota y saldo contra
                     entrega. Se arma del inventario en vivo: lo vendido no aparece.'
                  : 'Todavía no hay unidades disponibles con precio para armar una lista.') . '</p>
              <div class="acts">' . implode('', array_map(
                  fn($f) => '<a class="b" target="_blank" href="lista.php?token=' . urlencode($tok)
                          . '&cat=' . $c . '&fam=' . (int)$f['tipo'] . '">'
                          . htmlspecialchars($f['nombre'], ENT_QUOTES)
                          . ' <span class="n">' . (int)$f['n'] . '</span></a>',
                  $fams)) . '</div>
            </div>
            <div class="col">
              <div class="ct">Configuración <span class="tag">FUENTE DE VERDAD</span></div>
              <p>' . $cats . ' categorías · ' . htmlspecialchars($nivs, ENT_QUOTES) . '.
                 Matriz, mapa de posiciones y overrides. De acá sale cada precio.</p>
              <div class="acts"><a class="b" href="?token=' . urlencode($tok) . '&cat=' . $c . '&accion=matriz">↓ JSON</a></div>
            </div>
          </div>
        </section>';
    }
    echo '<!doctype html><meta charset="utf-8"><title>GALJOSA — Sistemas de precios</title>
<style>
 :root{--bg:#0d1014;--sf:#151a20;--sf2:#1b222a;--bd:#252d37;--t1:#e9edf2;--t2:#98a2b0;--t3:#6b7683;--ac:#4ea1ff}
 /* Tema CLARO. El anterior era casi ilegible: tarjetas blancas sobre un fondo casi
    blanco y un borde de #e2e7ec que no se veia, asi que nada separaba a nada y la
    pantalla se leia como una sola mancha. Lo que cambia:
      - el FONDO baja a #e8ecf1, para que la tarjeta blanca se levante
      - el BORDE sube a #d0d7de, que se ve sin ser duro
      - la CABECERA de cada tarjeta lleva relleno propio, no queda flotando
      - una sombra suave: en claro es lo que da la sensacion de tarjeta */
 @media(prefers-color-scheme:light){
   :root{--bg:#e8ecf1;--sf:#fff;--sf2:#f2f5f8;--bd:#d0d7de;--t1:#0f1419;--t2:#57606a;--t3:#6e7781;--ac:#0969da;
         --sh:0 1px 2px rgba(16,24,40,.06),0 1px 3px rgba(16,24,40,.05)}
   .pc{box-shadow:var(--sh)}
   .ph{background:var(--sf2)}
   .b{box-shadow:0 1px 1px rgba(16,24,40,.04)}
   .b:hover{background:#fff}
 }
 html,body{overflow-x:clip}
 body{background:var(--bg);color:var(--t1);margin:0;font:16px/1.55 -apple-system,"Segoe UI",Roboto,sans-serif;-webkit-font-smoothing:antialiased}
 .w{max-width:1180px;margin:0 auto;padding:46px 24px 80px}
 h1{font-size:31px;margin:0 0 5px;letter-spacing:-.025em;overflow-wrap:anywhere;min-width:0}
 .sb{color:var(--t2);margin:0 0 34px;font-size:15px}
 .pc{background:var(--sf);border:1px solid var(--bd);border-radius:16px;overflow:hidden;margin-bottom:20px}
 .ph{display:flex;align-items:center;gap:14px;flex-wrap:wrap;padding:16px 22px;border-bottom:1px solid var(--bd)}
 .bdg{color:#fff;font-size:11.5px;font-weight:700;letter-spacing:.07em;padding:4px 11px;border-radius:6px}
 .meta{color:var(--t2);font-size:14px}
 .tag.ok{background:#14532d;color:#a7f3d0;border-color:#166534}
 @media(prefers-color-scheme:light){.tag.ok{background:#dcfce7;color:#14532d;border-color:#bbf7d0}}
 .n{display:inline-block;min-width:18px;padding:0 5px;margin-left:5px;border-radius:9px;
    background:var(--bd);color:var(--t1);font-size:11.5px;font-weight:700;text-align:center}
 .cols{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,300px),1fr))}
 .col{padding:20px 22px 22px;border-right:1px solid var(--bd)}
 .col:last-child{border-right:0}
 .ct{font-size:17px;font-weight:600;margin-bottom:7px}
 .tag{font-size:10px;letter-spacing:.08em;font-weight:600;color:var(--t3);
      border:1px solid var(--bd);border-radius:5px;padding:2px 6px;vertical-align:2px;margin-left:5px}
 .col p{color:var(--t2);font-size:14px;margin:0 0 15px;max-width:46ch}
 .acts{display:flex;gap:8px;flex-wrap:wrap}
 .b{display:inline-block;background:var(--sf2);border:1px solid var(--bd);border-radius:9px;
    padding:8px 17px;font-size:14px;font-weight:600;color:var(--t1);text-decoration:none;white-space:nowrap}
 .b:hover{border-color:var(--ac);color:var(--ac)}
 .b.pri{background:var(--t1);color:var(--bg);border-color:var(--t1)}
 .b.pri:hover{opacity:.88;color:var(--bg)}
 .pie{color:var(--t3);font-size:13px;border-top:1px solid var(--bd);padding-top:16px;margin-top:30px}
 @media(max-width:620px){h1{font-size:24px}.w{padding:28px 15px 60px}.col{border-right:0;border-bottom:1px solid var(--bd)}}
</style>
<div class="w"><h1>GALJOSA — Sistemas de precios</h1>
<p class="sb">Elegí el proyecto para ver su inventario y subir precios por bloque.</p>'
    . $tarjetas
    . '<p class="pie">' . $totProy . ' proyecto(s) con matriz. Los precios se leen del catálogo
       compartido, sin llamadas al API. Ver no escribe nada: aplicar exige clave y solo toca
       unidades disponibles.</p></div>';
    exit;
}

$cat = (int)($_REQUEST['cat'] ?? array_key_first($proyectos) ?? 0);
$cfg = mz_cfg($cat);
if (!$cfg) {
    http_response_code(404);
    exit('<!doctype html><meta charset="utf-8"><p style="font:15px system-ui;padding:30px">Ese proyecto no tiene matriz de precios todavía.</p>');
}

$accion = (string)($_REQUEST['accion'] ?? 'ver');

// Diagnóstico de una línea: qué campos manda Bitrix al abrir el placement. Solo los
// nombres — AUTH_ID es una credencial y no se escribe en ningún log.
if ($accion === 'ver' && !empty($_POST)) {
    logline('MATRIZ placement POST: ' . implode(',', array_keys($_POST))
          . ' · scope=' . (string)($_POST['APPLICATION_SCOPE'] ?? '-'));
}

/**
 * Quién está aplicando la subida.
 * Bitrix manda AUTH_ID al abrir el placement; con eso se le pregunta su nombre. Si
 * la pantalla se abrió por URL suelta ese dato no existe, y entonces vale el nombre
 * que la persona escriba. Una subida de precios sin responsable no sirve de nada:
 * dentro de un mes nadie va a saber quién la decidió.
 */
function pm_quien(string $auth, string $escrito): string {
    $escrito = trim($escrito);
    if ($auth === '') {
        // Distinguir "Bitrix no mando AUTH_ID" de "AUTH_ID llego pero profile fallo":
        // son dos averias distintas y antes las dos se veian igual desde afuera.
        logline('MATRIZ sin AUTH_ID (pantalla abierta fuera del placement)');
    }
    if ($auth !== '') {
        $dom = (string)(getenv('BITRIX_DOMINIO') ?: 'galjosa.bitrix24.com');
        // 'profile' devuelve al usuario cuya sesion abrio la pantalla y es de la
        // base: no depende del permiso 'user' (que la app ya tiene desde el
        // 2026-08-15, pero que un reinstall futuro podria dejar fuera).
        $ch = curl_init("https://{$dom}/rest/profile?auth=" . rawurlencode($auth));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8]);
        $raw = (string)curl_exec($ch);
        $errno = curl_errno($ch);
        $j = json_decode($raw, true);
        $u = $j['result'] ?? null;
        if (is_array($u)) {
            $nom = trim(($u['NAME'] ?? '') . ' ' . ($u['LAST_NAME'] ?? ''));
            if ($nom !== '') return $nom;
            // Hay usuario pero sin nombre cargado en su ficha: vale el correo antes
            // que pedirle que se identifique a mano.
            if (!empty($u['EMAIL'])) return (string)$u['EMAIL'];
        }
        // Se anota POR QUE fallo. Sin esto solo se veia "sin identificar" y no habia
        // forma de saber si era la sesion, el permiso de la app o la red.
        logline('MATRIZ profile -> ' . ($errno ? "curl:$errno"
              : ('error=' . (string)($j['error'] ?? '-') . ' desc=' . (string)($j['error_description'] ?? '-'))));
    }
    return $escrito;      // vacio = no se pudo saber; quien llama decide que hacer
}

/**
 * Los pisos del proyecto, del ultimo al primero, con el nivel al que pertenecen.
 * Sale de `niveles`: cada uno declara que pisos abarca. Asi la pantalla no necesita
 * saber de antemano si el proyecto tiene planta baja o cuantos pisos tiene.
 */
function pm_pisos(array $cfg): array {
    $out = [];
    foreach (($cfg['niveles'] ?? []) as $grp => $n) {
        foreach (($n['pisos'] ?? []) as $piso) {
            $out[] = [
                'num'  => (int)$piso,
                'grp'  => (string)$grp,
                'name' => (string)($n['etiqueta'] ?? ('Piso ' . $piso)),
                'sub'  => (string)($n['nota'] ?? ''),
            ];
        }
    }
    usort($out, fn($a, $b) => $b['num'] <=> $a['num']);
    return $out;
}

/**
 * Las letras de estado que la pantalla puede reprecear, sacadas de
 * `etapas_editables` del proyecto. Se resuelven contra los nombres reales de las
 * etapas del pipeline: el mismo stageId significa cosas distintas segun el
 * proyecto — ':NEW' es DISPONIBLE en Plaza y otra cosa en Apartments.
 */
function pm_editables(array $cfg): array {
    $L = ['DISPONIBLE'=>'D','RESERVADO'=>'R','FIRMADO'=>'F','VENDIDO'=>'F','BLOQUEADO'=>'N'];
    $nombres = mz_nombres_etapa($cfg);          // stageId => NOMBRE
    $out = [];
    $etapas = (array)($cfg['bitrix']['etapas_editables'] ?? [$cfg['bitrix']['etapa_disponible'] ?? '']);
    foreach ($etapas as $e) {
        $n = strtoupper((string)($nombres[$e] ?? ''));
        if (isset($L[$n])) $out[] = $L[$n];
    }
    return array_values(array_unique($out ?: ['D']));
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
        'quien'     => pm_quien((string)($_POST['auth'] ?? ''), (string)($_POST['quien'] ?? '')) ?: 'sin identificar',
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
    $fuera['deshecho_por'] = pm_quien((string)($_POST['auth'] ?? ''), (string)($_POST['quien'] ?? '')) ?: 'sin identificar';
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
// no-store solo no bastaba: dentro del iframe de Bitrix el navegador seguia
// sirviendo la version anterior del JS despues de desplegar, y las subidas salian
// con el comportamiento viejo sin que nadie entendiera por que.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
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
    // ?? [] y no a secas: Galero Casas no cotiza por metraje de categoria y sin esto
    // la pantalla entera moria con un TypeError en vez de abrir sin esa columna.
    'metraje'    => array_diff_key($cfg['metraje'] ?? [], ['_nota' => 1]),
    'terreno'    => $cfg['terreno'] ?? new stdClass(),
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
    // La plantilla dibujaba las categorias y los pisos de Noral Apartments escritos
    // a mano. Van aca para que cada proyecto traiga los suyos: Plaza tiene EC/MC/EL/ML
    // y tres pisos sueltos, Apartments M/E1/E2/E3 con PB, PA y 4P.
    'cats'       => array_map(fn($c) => [
        'etiqueta'   => $c['etiqueta'] ?? '',
        'nota'       => $c['nota'] ?? '',
        'posiciones' => $c['posiciones'] ?? [],
    ], $cfg['categorias']),
    'pisos'      => pm_pisos($cfg),
    // Que estados se pueden reprecear. En Apartments el bloqueado tambien entra
    // (retencion gerencial que igual se cotiza); en las oficinas de Plaza NO, y
    // por eso el resumen contaba 68 disponibles donde hay 50.
    'editables'  => pm_editables($cfg),
    'proyectos'  => array_map(fn($c) => $c['proyecto'] ?? '', $proyectos),
    'token'      => $tok,
    // Bitrix lo manda al abrir el placement. Sirve para saber quién aplica sin
    // pedirle que se identifique a mano.
    'auth'       => (string)($_REQUEST['AUTH_ID'] ?? ''),
    // Se resuelve aqui y no al aplicar: asi la pantalla puede decir "vas a firmar
    // como X" ANTES de escribir, en vez de descubrirlo despues en el historico.
    'yo'         => pm_quien((string)($_REQUEST['AUTH_ID'] ?? ''), ''),
    'build'      => substr((string)@shell_exec('git -C ' . escapeshellarg(__DIR__) . ' rev-parse --short HEAD 2>/dev/null'), 0, 7)
                    ?: gmdate('md-Hi'),
    'edad'       => (int)($cacheInfo['edad'] ?? 0),
    'fresco'     => (bool)($cacheInfo['fresco'] ?? true),
];

$tpl = (string)file_get_contents(__DIR__ . '/matriz.tpl.html');

// Camino de vuelta a la portada. Sin esto se entra a un proyecto y no hay salida:
// dentro de Bitrix el slider no tiene barra de direcciones ni boton de atras.
// Solo aparece si hay mas de un proyecto — con uno solo la portada no aporta.
if (count($proyectos) > 1) {
    $volver = '<div style="max-width:1180px;margin:0 auto;padding:14px 22px 0">'
        . '<a href="?token=' . urlencode($tok) . '" style="display:inline-flex;align-items:center;'
        . 'gap:7px;font:600 14px/1 -apple-system,\'Segoe UI\',Roboto,sans-serif;text-decoration:none;'
        . 'color:#8b95a1;border:1px solid rgba(128,140,155,.35);border-radius:9px;padding:8px 14px">'
        . '&#8592; Todos los proyectos</a>'
        . '<span style="margin-left:12px;color:#8b95a1;font:14px -apple-system,sans-serif">'
        . htmlspecialchars(pm_nombre($cfg, $cat), ENT_QUOTES) . '</span></div>';
    $tpl = preg_replace('/<body>/', '<body>' . $volver, $tpl, 1);
}
echo str_replace('/*__DATOS__*/',
    'const DATA = ' . json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';',
    $tpl);
