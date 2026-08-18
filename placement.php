<?php
/**
 * placement.php — pone (o quita) la pestaña "Cotizador" dentro de la ficha del deal.
 * ---------------------------------------------------------------------------
 * placement.bind EXIGE contexto de APLICACIÓN: con un webhook entrante responde
 * WRONG_AUTH_TYPE. Por eso vive aquí, en la app local que ya está instalada y
 * tiene tokens OAuth, y no en el cotizador (que solo habla por webhook).
 *
 * Qué hace la pestaña: Bitrix abre el HANDLER dentro de un iframe en el deal y le
 * manda PLACEMENT_OPTIONS = {"ID": <id del deal>} por POST. El cotizador recibe
 * ese POST en /bitrix/deal y redirige a /?deal=<id>, que carga cliente y unidades.
 *
 *   ?token=...&accion=ver      → qué hay enlazado hoy (por defecto)
 *   ?token=...&accion=poner    → enlaza la pestaña
 *   ?token=...&accion=quitar   → la desenlaza (deshacer)
 *
 * Protegido por OUTBOUND_TOKEN, igual que el resto de endpoints del servicio.
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);
require_once __DIR__ . '/appauth.php';

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

$esperado = (string)getenv('OUTBOUND_TOKEN');
if ($esperado === '' || !hash_equals($esperado, (string)($_GET['token'] ?? ''))) {
    http_response_code(403); exit('forbidden');
}

const PLACEMENT = 'CRM_DEAL_DETAIL_TAB';
const HANDLER   = 'https://galjosa-cotizador.pwluu1.easypanel.host/bitrix/deal';
const TITULO    = 'Cotizador';

/** El cotizador madre. Va en el menú del propio Inventario (el SPA 1072), que es
 *  donde viven las unidades, y además en el menú izquierdo para llegar siempre.
 *  El token viaja en el HANDLER porque Bitrix abre el placement por POST y no
 *  arrastra la query que uno pondría a mano. */
const PM_BASE   = 'https://galjosa-inventario-sync.pwluu1.easypanel.host/preciomadre.php';
const PM_TITULO = 'Precios del proyecto';
const PM_DESC   = 'Matriz de precios: simula una subida por bloque y aplícala';
const PM_SITIOS = ['CRM_DYNAMIC_1072_LIST_MENU', 'LEFT_MENU'];

function pm_handler(): string {
    // SIN &cat: el boton abre la portada y la persona elige el proyecto. Con el
    // proyecto clavado en la URL siempre caia en Noral Apartments y no habia forma
    // de llegar a los demas desde Bitrix.
    return PM_BASE . '?token=' . rawurlencode((string)getenv('OUTBOUND_TOKEN'));
}

$accion = (string)($_GET['accion'] ?? 'ver');

/** Lista compacta de lo ENLAZADO, para ver antes y después sin adivinar.
 *  OJO: placement.list devuelve los códigos DISPONIBLES del portal (cientos);
 *  lo que esta app tiene enlazado se pide con placement.get. */
function mostrar(): void {
    $r = app_bx('placement.get');
    if (!($r['ok'] ?? true) || isset($r['error'])) {
        echo "  no se pudo listar: {$r['error']} {$r['desc']}\n"; return;
    }
    $items = $r['result'] ?? [];
    if (!$items) { echo "  (no hay placements enlazados)\n"; return; }
    foreach ($items as $p) {
        $nombre = is_array($p) ? ($p['placement'] ?? '?') : (string)$p;
        $hand   = is_array($p) ? ($p['handler'] ?? '') : '';
        echo "  - {$nombre}  {$hand}\n";
    }
}

/** Las últimas líneas del log que dejan las pantallas del placement. Sin esto la
 *  única forma de saber si Bitrix manda AUTH_ID era pedirle al usuario que probara
 *  y contara qué vio. */
if (($_GET['accion'] ?? '') === 'log') {
    // web.log lo escribe Apache (logline de campolib); sync.log lo escribe el cron.
    $cual = ($_GET['f'] ?? 'web') === 'sync' ? 'sync.log' : 'web.log';
    $f = (getenv('DATA_DIR') ?: '/data') . '/' . $cual;
    $n = max(1, min(200, (int)($_GET['n'] ?? 40)));
    $q = (string)($_GET['q'] ?? 'MATRIZ');
    $todas = @file($f, FILE_IGNORE_NEW_LINES) ?: [];
    $hit = $q === '' ? $todas : array_values(array_filter($todas, fn($l) => str_contains($l, $q)));
    echo implode("\n", array_slice($hit, -$n)), "\n";
    exit;
}

/** Los códigos DISPONIBLES del portal, filtrados. Sirve para no adivinar dónde
 *  puede vivir un botón: placement.list los devuelve todos (cientos). */
if (($_GET['accion'] ?? '') === 'disponibles') {
    $r = app_bx('placement.list');
    $q = strtoupper((string)($_GET['q'] ?? ''));
    $todos = $r['result'] ?? [];
    if (!is_array($todos)) { echo "no se pudo listar\n"; exit; }
    $vistos = [];
    foreach ($todos as $x) {
        $c = is_array($x) ? ($x['placement'] ?? ($x[0] ?? '')) : (string)$x;
        if ($c === '' || isset($vistos[$c])) continue;
        $vistos[$c] = true;
    }
    $lista = array_keys($vistos);
    sort($lista);
    $hit = $q === '' ? $lista : array_values(array_filter($lista, fn($c) => str_contains($c, $q)));
    echo count($lista) . " placements en el portal · " . count($hit) . " coinciden con '{$q}'\n\n";
    foreach ($hit as $c) echo "  {$c}\n";
    exit;
}

/** Permisos que la app tiene concedidos. user.current necesita el scope 'user'. */
if (($_GET['accion'] ?? '') === 'scope') {
    $r = app_bx('scope');
    echo "scope de la app:\n  " . implode(', ', (array)($r['result'] ?? [])) . "\n";
    // Se prueban los metodos que podrian identificar al usuario con los permisos
    // que la app YA tiene, antes de pedir permisos nuevos (que obligan a
    // reinstalarla y a que alguien vuelva a autorizarla).
    foreach (['profile', 'user.current', 'crm.settings.mode.get'] as $m) {
        $u = app_bx($m);
        $ok = isset($u['result']);
        $res = $ok ? (is_array($u['result'])
                        ? array_intersect_key($u['result'], array_flip(['ID','NAME','LAST_NAME','EMAIL','ADMIN']))
                        : $u['result'])
                   : ($u['error'] ?? '?');
        echo "\n{$m}: " . json_encode($res, JSON_UNESCAPED_UNICODE);
    }
    echo "\n";
    exit;
}

echo "Antes:\n"; mostrar();

if ($accion === 'poner') {
    // Se desenlaza primero para que re-ejecutar esto actualice el handler en vez
    // de duplicar la pestaña (bind sobre el mismo placement da ERROR_PLACEMENT_EXISTS).
    app_bx('placement.unbind', ['PLACEMENT' => PLACEMENT, 'HANDLER' => HANDLER]);

    $r = app_bx('placement.bind', [
        'PLACEMENT' => PLACEMENT,
        'HANDLER'   => HANDLER,
        'TITLE'     => TITULO,
        'DESCRIPTION' => 'Genera la cotizacion del cliente con las unidades de este negocio',
    ]);
    echo "\nbind: " . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
} elseif ($accion === 'poner-precios') {
    foreach (PM_SITIOS as $sitio) {
        app_bx('placement.unbind', ['PLACEMENT' => $sitio, 'HANDLER' => pm_handler()]);
        $r = app_bx('placement.bind', [
            'PLACEMENT'   => $sitio,
            'HANDLER'     => pm_handler(),
            'TITLE'       => PM_TITULO,
            'DESCRIPTION' => PM_DESC,
        ]);
        echo "\nbind {$sitio}: " . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
    }
} elseif ($accion === 'quitar-precios') {
    foreach (PM_SITIOS as $sitio) {
        $r = app_bx('placement.unbind', ['PLACEMENT' => $sitio, 'HANDLER' => pm_handler()]);
        echo "\nunbind {$sitio}: " . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
    }
} elseif ($accion === 'quitar') {
    $r = app_bx('placement.unbind', ['PLACEMENT' => PLACEMENT, 'HANDLER' => HANDLER]);
    echo "\nunbind: " . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
}

if ($accion !== 'ver') { echo "\nDespués:\n"; mostrar(); }
