<?php
/**
 * placement-cobranza.php — enlaza (o quita) el botón «No contestó» de COBRANZAS
 * en la barra de actividades del deal.
 *
 * Mismo placement CRM_DEAL_DETAIL_ACTIVITY que el de ventas, pero con OTRO
 * handler y desde OTRA app: Bitrix permite varios enlaces sobre el mismo
 * placement mientras el handler sea distinto. Son dos botones independientes.
 *
 *   ?token=...&accion=ver | poner | quitar
 */
declare(strict_types=1);
require_once __DIR__ . '/lib/cobranza-appauth.php';

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

$esperado = (string)getenv('OUTBOUND_TOKEN');
if ($esperado === '' || !hash_equals($esperado, (string)($_GET['token'] ?? ''))) {
    http_response_code(403); exit('forbidden');
}

const PLACEMENT = 'CRM_DEAL_DETAIL_ACTIVITY';
const HANDLER   = 'https://galjosa-inventario-sync.pwluu1.easypanel.host/cobranza_nativo.php';
// Los dos botones viven en la MISMA barra (ventas y cobranzas), y con el mismo
// título no hay forma de saber cuál es cuál: el usuario apretó el de ventas sobre
// un deal de cobranzas sin darse cuenta.
const TITULO    = 'No contestó · Cobranzas';

function cob_mostrar(): void {
    $r = cob_app_bx('placement.get');
    if (!($r['ok'] ?? false)) { echo "  no se pudo listar: {$r['error']} " . ($r['desc'] ?? '') . "\n"; return; }
    $items = $r['result'] ?? [];
    if (!$items) { echo "  (esta app no tiene placements enlazados)\n"; return; }
    foreach ($items as $p) {
        echo '  - ' . (is_array($p) ? ($p['placement'] ?? '?') . '  ' . ($p['handler'] ?? '') : (string)$p) . "\n";
    }
}

$accion = (string)($_GET['accion'] ?? 'ver');
echo "Antes:\n"; cob_mostrar();

if ($accion === 'poner') {
    // unbind primero: re-correr esto ACTUALIZA el handler en vez de fallar con
    // ERROR_PLACEMENT_EXISTS. No toca el de ventas: distinto handler, distinta app.
    cob_app_bx('placement.unbind', ['PLACEMENT' => PLACEMENT, 'HANDLER' => HANDLER]);
    $r = cob_app_bx('placement.bind', [
        'PLACEMENT'   => PLACEMENT,
        'HANDLER'     => HANDLER,
        'TITLE'       => TITULO,
        'DESCRIPTION' => 'Cobranzas: registra el intento fallido y agenda el siguiente a +2 días hábiles',
        'OPTIONS'     => ['useBuiltInInterface' => 'Y'],
    ]);
    echo "\nbind: " . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
} elseif ($accion === 'quitar') {
    $r = cob_app_bx('placement.unbind', ['PLACEMENT' => PLACEMENT, 'HANDLER' => HANDLER]);
    echo "\nunbind: " . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
}
if ($accion !== 'ver') { echo "\nDespués:\n"; cob_mostrar(); }
