<?php
// healthcheck simple — NO expone nada sensible
declare(strict_types=1);
$DATA_DIR = getenv('DATA_DIR') ?: '/data';

// visor de log (protegido por token) para debug: ?log=1&token=...
if (isset($_GET['log'])) {
    $expect = (string)getenv('OUTBOUND_TOKEN');
    if ($expect === '' || !hash_equals($expect, (string)($_GET['token'] ?? ''))) { http_response_code(403); exit('forbidden'); }
    header('Content-Type: text/plain; charset=utf-8');
    // dos archivos: sync.log lo escribe el cron (root) y web.log Apache (www-data),
    // porque Apache no puede añadir líneas al que creó root.
    foreach (['sync.log' => 'CRON', 'web.log' => 'WEB'] as $archivo => $etq) {
        $lines = @file($DATA_DIR . '/' . $archivo) ?: [];
        echo "===== $etq ($archivo) =====\n";
        echo $lines ? implode('', array_slice($lines, -60)) : "(vacío)\n";
        echo "\n";
    }
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
