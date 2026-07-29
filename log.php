<?php
/**
 * log.php — últimas líneas de web.log. Diagnóstico, solo lectura.
 *
 * Existe porque el contenedor no es accesible por SSH (EasyPanel solo expone 443)
 * y web.log era la única fuente para ver qué le manda Bitrix al campo — sin esto
 * había que adivinar. Protegido con OUTBOUND_TOKEN, el mismo que ya usa el resto.
 *
 * Uso: log.php?token=<OUTBOUND_TOKEN>[&n=80][&q=FIELD]
 */
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

$tok = (string)getenv('OUTBOUND_TOKEN');
if ($tok === '' || !hash_equals($tok, (string)($_GET['token'] ?? ''))) {
    http_response_code(403); echo "403\n"; exit;
}

$path = (getenv('DATA_DIR') ?: '/data') . '/web.log';
if (!is_file($path)) { echo "sin web.log\n"; exit; }

$n = max(1, min(500, (int)($_GET['n'] ?? 80)));
$q = (string)($_GET['q'] ?? '');

// Se lee solo la cola del archivo: puede pesar MB y no hay por qué cargarlo todo.
$fh = fopen($path, 'rb');
$tam = (int)filesize($path);
$leer = min($tam, 400000);
fseek($fh, $tam - $leer);
$buf = (string)fread($fh, $leer);
fclose($fh);

$lineas = preg_split('/\R/', trim($buf)) ?: [];
if ($q !== '') $lineas = array_values(array_filter($lineas, fn($l) => stripos($l, $q) !== false));

echo implode("\n", array_slice($lineas, -$n)), "\n";
