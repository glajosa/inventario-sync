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
} elseif ($accion === 'quitar') {
    $r = app_bx('placement.unbind', ['PLACEMENT' => PLACEMENT, 'HANDLER' => HANDLER]);
    echo "\nunbind: " . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
}

if ($accion !== 'ver') { echo "\nDespués:\n"; mostrar(); }
