<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../lib/private-api-auth.php';
require_once __DIR__ . '/../lib/llamada-idempotencia.php';

$body = '{"callRequestId":"11111111-1111-4111-8111-111111111111"}';
$timestamp = 1787238000;
$secret = 'test-secret-with-at-least-32-bytes';
$signature = hash_hmac('sha256', $timestamp . "\n" . $body, $secret);

private_api_verify($body, [
    'x-galjosa-timestamp' => (string)$timestamp,
    'x-galjosa-signature' => $signature,
], $secret, $timestamp + 30);

test_throws(fn() => private_api_verify($body, [
    'x-galjosa-timestamp' => (string)$timestamp,
    'x-galjosa-signature' => str_repeat('0', 64),
], $secret, $timestamp), PrivateApiUnauthorized::class, 'bad signature');

test_throws(fn() => private_api_verify($body, [
    'x-galjosa-timestamp' => (string)$timestamp,
    'x-galjosa-signature' => $signature,
], $secret, $timestamp + 301), PrivateApiUnauthorized::class, 'expired signature');

$dataDir = sys_get_temp_dir() . '/inventario-sync-idempotencia-' . bin2hex(random_bytes(8));
mkdir($dataDir, 0700, true);

try {
    $store = new LlamadaIdempotenciaStore($dataDir);
    $created = $store->begin('result-111', hash('sha256', $body), $timestamp);
    test_same('processing', $created['state'], 'new key starts processing');
    test_same(true, $created['is_new'], 'new key owns the operation');

    $retried = $store->begin('result-111', hash('sha256', $body), $timestamp + 1);
    test_same('processing', $retried['state'], 'same key and request resumes operation');
    test_same(false, $retried['is_new'], 'retry does not own the operation');

    $store->complete('result-111', '{"ok":true}', 'created', 321, $timestamp + 2);
    $completed = $store->get('result-111');
    test_same('completed', $completed['state'], 'completed operation is retained');
    test_same('{"ok":true}', $completed['response_json'], 'completed response is retained');
    test_same('created', $completed['comment_state'], 'comment state is retained');
    test_same(321, $completed['comment_id'], 'comment id is retained');

    test_throws(
        fn() => $store->begin('result-111', hash('sha256', '{"callRequestId":"other"}'), $timestamp + 3),
        LlamadaIdempotenciaConflict::class,
        'same key with another request conflicts'
    );

    $store = new LlamadaIdempotenciaStore($dataDir);
    test_same('completed', $store->get('result-111')['state'], 'operation survives store restart');
} finally {
    $databasePath = $dataDir . '/llamada-resultados.sqlite';
    if (is_file($databasePath)) unlink($databasePath);
    rmdir($dataDir);
}
