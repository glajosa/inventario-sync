<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/llamada-idempotencia.php';

[$dataDir, $idempotencyKey, $requestHash, $readyPath, $startPath] = array_slice($argv, 1);
$store = new LlamadaIdempotenciaStore($dataDir);
file_put_contents($readyPath, 'ready');

while (!is_file($startPath)) {
    usleep(1_000);
}

try {
    echo json_encode($store->begin($idempotencyKey, $requestHash, time()), JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    echo json_encode(['error' => $error::class], JSON_THROW_ON_ERROR);
}
