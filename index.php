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

/* Huella del codigo que ESTE contenedor esta sirviendo: ?huella=1&token=...
   Existe porque el disparo del despliegue devuelve HTTP 000 y no dice si funciono.
   La pregunta buena no es "el disparo tuvo exito" sino "el codigo nuevo ya sirve",
   y eso se le pregunta a la app. Ver el comentario largo en huella.php. */
if (isset($_GET['huella'])) {
    $expect = (string)getenv('OUTBOUND_TOKEN');
    if ($expect === '' || !hash_equals($expect, (string)($_GET['token'] ?? ''))) { http_response_code(403); exit('forbidden'); }
    require_once __DIR__ . '/huella.php';
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    $h = huella(__DIR__);
    // ?huella=1 da solo el total (barato); ?huella=detalle lista archivo por archivo
    if ($_GET['huella'] !== 'detalle') unset($h['archivos']);
    echo json_encode($h + ['utc' => gmdate('Y-m-d\TH:i:s\Z')], JSON_UNESCAPED_SLASHES);
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
