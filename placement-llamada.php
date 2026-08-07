<?php
/**
 * placement-llamada.php — pone (o quita) el botón "Registrar llamada" en la
 * barra de actividades del deal (Llamada/Comentario/Mensaje/Reunión/...).
 * ---------------------------------------------------------------------------
 * Mismo patrón que placement.php (Cotizador): placement.bind EXIGE contexto
 * de APLICACIÓN — con un webhook entrante responde WRONG_AUTH_TYPE. Por eso
 * vive aquí, en la app local que ya está instalada y tiene tokens OAuth.
 *
 * A diferencia del Cotizador (pestaña, PLACEMENT_OPTIONS por POST + redirect),
 * llamada.php recibe el ID del deal client-side con BX24.placement.info() —
 * no necesita nada del servidor más que servir el HTML.
 *
 *   ?token=...&accion=ver      → qué hay enlazado hoy (por defecto)
 *   ?token=...&accion=poner    → enlaza el botón
 *   ?token=...&accion=quitar   → lo desenlaza (deshacer)
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

const PLACEMENT = 'CRM_DEAL_DETAIL_ACTIVITY';
const HANDLER   = 'https://galjosa-inventario-sync.pwluu1.easypanel.host/llamada_nativo.php';
const TITULO    = 'Registrar llamada';

// Handler viejo (HTML propio dentro del panel deslizante de Bitrix). Se
// desenlaza junto con el nuevo para que no queden los dos botones.
const HANDLER_VIEJO = 'https://galjosa-inventario-sync.pwluu1.easypanel.host/llamada.php';

$accion = (string)($_GET['accion'] ?? 'ver');

/** Lista compacta de lo ENLAZADO (placement.get, no placement.list — ver placement.php). */
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
    // Desenlazar primero: re-correr esto actualiza el handler en vez de duplicar
    // el botón (bind sobre el mismo placement da ERROR_PLACEMENT_EXISTS).
    // Se quita también el handler viejo, si quedó de la versión anterior.
    app_bx('placement.unbind', ['PLACEMENT' => PLACEMENT, 'HANDLER' => HANDLER]);
    app_bx('placement.unbind', ['PLACEMENT' => PLACEMENT, 'HANDLER' => HANDLER_VIEJO]);

    // ⭐ useBuiltInInterface = Y: la interfaz la dibuja BITRIX a partir del
    // LayoutDto que manda el handler (setLayout), dentro de la barra de
    // actividades. Sin esto, Bitrix abre el HTML de la app en su panel
    // deslizante grande, que NO se puede achicar desde adentro.
    $r = app_bx('placement.bind', [
        'PLACEMENT'   => PLACEMENT,
        'HANDLER'     => HANDLER,
        'TITLE'       => TITULO,
        'DESCRIPTION' => 'Registra la llamada (contesto / no contesto) y planifica la siguiente',
        'OPTIONS'     => ['useBuiltInInterface' => 'Y'],
    ]);
    echo "\nbind: " . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
} elseif ($accion === 'quitar') {
    $r  = app_bx('placement.unbind', ['PLACEMENT' => PLACEMENT, 'HANDLER' => HANDLER]);
    $r2 = app_bx('placement.unbind', ['PLACEMENT' => PLACEMENT, 'HANDLER' => HANDLER_VIEJO]);
    echo "\nunbind: " . json_encode($r, JSON_UNESCAPED_UNICODE)
       . " | viejo: " . json_encode($r2, JSON_UNESCAPED_UNICODE) . "\n";
}

if ($accion !== 'ver') { echo "\nDespués:\n"; mostrar(); }
