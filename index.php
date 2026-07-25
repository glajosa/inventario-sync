<?php
// healthcheck simple — NO expone nada sensible
declare(strict_types=1);
$DATA_DIR = getenv('DATA_DIR') ?: '/data';

// visor de log (protegido por token) para debug: ?log=1&token=...
if (isset($_GET['log'])) {
    $expect = (string)getenv('OUTBOUND_TOKEN');
    if ($expect === '' || !hash_equals($expect, (string)($_GET['token'] ?? ''))) { http_response_code(403); exit('forbidden'); }
    header('Content-Type: text/plain; charset=utf-8');
    $lines = @file($DATA_DIR . '/sync.log') ?: [];
    echo implode('', array_slice($lines, -80));
    exit;
}

header('Content-Type: application/json');
$allow = @file_get_contents($DATA_DIR . '/allowlist.json');
$arr   = json_decode($allow ?: '[]', true);
echo json_encode([
    'service'        => 'inventario-sync',
    'ok'             => true,
    'allowlist_size' => is_array($arr) ? count($arr) : 0,
    'utc'            => gmdate('Y-m-d\TH:i:s\Z'),
]);
