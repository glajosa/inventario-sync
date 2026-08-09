<?php
/**
 * placement-sonda.php — enlaza y desenlaza la SONDA probe_iframe.php.
 *
 * Va aparte de placement-llamada.php a propósito: la sonda es un SEGUNDO
 * handler sobre el mismo placement, así el botón que ya usan los vendedores
 * no se toca ni se re-enlaza (re-enlazar lo manda de vuelta al menú "Más").
 *
 *   ?token=...&accion=poner     enlaza la sonda
 *   ?token=...&accion=quitar    la saca
 *   ?token=...&accion=ver       lista lo enlazado
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
const HANDLER   = 'https://galjosa-inventario-sync.pwluu1.easypanel.host/probe_iframe.php';

function mostrar(): void {
    $r = app_bx('placement.get');
    foreach (($r['result'] ?? []) as $p) {
        $nombre = is_array($p) ? ($p['placement'] ?? '?') : (string)$p;
        $hand   = is_array($p) ? ($p['handler'] ?? '') : '';
        echo "  - {$nombre}  {$hand}\n";
    }
}

$accion = (string)($_GET['accion'] ?? 'ver');
echo "Antes:\n"; mostrar();

if ($accion === 'poner') {
    // SIN useBuiltInInterface: queremos justamente ver qué hace Bitrix cuando
    // la app dibuja su propio HTML.
    $r = app_bx('placement.bind', [
        'PLACEMENT'   => PLACEMENT,
        'HANDLER'     => HANDLER,
        'TITLE'       => 'Sonda (borrar)',
        'DESCRIPTION' => 'Prueba temporal de tamaño de iframe',
    ]);
    echo "\nbind: " . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
} elseif ($accion === 'quitar') {
    $r = app_bx('placement.unbind', ['PLACEMENT' => PLACEMENT, 'HANDLER' => HANDLER]);
    echo "\nunbind: " . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
}

if ($accion !== 'ver') { echo "\nDespués:\n"; mostrar(); }
