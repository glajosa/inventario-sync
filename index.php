<?php
// healthcheck simple — NO expone nada sensible
declare(strict_types=1);
header('Content-Type: application/json');
$DATA_DIR = getenv('DATA_DIR') ?: '/data';
$allow = @file_get_contents($DATA_DIR . '/allowlist.json');
$arr   = json_decode($allow ?: '[]', true);
echo json_encode([
    'service'        => 'inventario-sync',
    'ok'             => true,
    'allowlist_size' => is_array($arr) ? count($arr) : 0,
    'utc'            => gmdate('Y-m-d\TH:i:s\Z'),
]);
