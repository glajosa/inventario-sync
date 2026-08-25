<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$endpointPath = __DIR__ . '/../api/private/llamadas/v1/resultado.php';
if (is_file($endpointPath)) require_once $endpointPath;

final class EndpointFakeBitrix {
    public array $calls = [];
    public array $errors = [];

    public function __invoke(string $method, array $params): array {
        $this->calls[] = [$method, $params];
        if (isset($this->errors[$method])) return $this->errors[$method];

        return match ($method) {
            'crm.deal.get' => ['ok' => true, 'result' => [
                'ID' => '77',
                'ASSIGNED_BY_ID' => '42',
                'CONTACT_ID' => '91',
                'STAGE_ID' => 'C28:INTERESADO',
            ]],
            'crm.activity.get' => ['ok' => true, 'result' => [
                'ID' => '731',
                'OWNER_ID' => '77',
                'OWNER_TYPE_ID' => '2',
                'TYPE_ID' => '2',
                'DIRECTION' => '2',
                'RESPONSIBLE_ID' => '42',
                'COMMUNICATIONS' => [[
                    'VALUE' => '+593 99 123 4567',
                    'ENTITY_ID' => '91',
                    'ENTITY_TYPE_ID' => '3',
                    'TYPE' => 'PHONE',
                ]],
            ]],
            'crm.contact.get' => ['ok' => true, 'result' => [
                'ID' => '91',
                'NAME' => 'Ana',
                'LAST_NAME' => 'Pérez',
                'PHONE' => [[
                    'ID' => '501',
                    'VALUE' => '+593 99 123 4567',
                    'VALUE_TYPE' => 'MOBILE',
                ]],
            ]],
            'crm.activity.list' => ['ok' => true, 'result' =>
                ($params['filter']['COMPLETED'] ?? null) === 'N'
                    ? [[
                        'ID' => '630',
                        'SUBJECT' => 'Llamada pendiente',
                        'DEADLINE' => '2026-08-20T10:00:00-05:00',
                        'RESPONSIBLE_ID' => '42',
                        'COMPLETED' => 'N',
                        'COMMUNICATIONS' => [[
                            'VALUE' => '+593991234567',
                            'TYPE' => 'PHONE',
                        ]],
                    ]]
                    : []],
            'crm.activity.update' => ['ok' => true, 'result' => true],
            'crm.activity.add' => ['ok' => true, 'result' => 901],
            'crm.timeline.comment.add' => ['ok' => true, 'result' => 801],
            'crm.deal.update' => ['ok' => true, 'result' => true],
            default => ['ok' => false, 'error' => 'unexpected-method'],
        };
    }
}

function endpoint_test_input(array $changes = []): array {
    return array_replace([
        'callRequestId' => '11111111-1111-4111-8111-111111111111',
        'memberId' => 'member-1',
        'dealId' => 77,
        'bitrixUserId' => 42,
        'bitrixActivityId' => 731,
        'outcome' => 'no_answer',
        'selectedPhone' => '+593991234567',
        'nextActivityAt' => null,
        'comment' => '',
    ], $changes);
}

function endpoint_test_body(array $changes = []): string {
    return json_encode(endpoint_test_input($changes), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function endpoint_contract_body(): string {
    $raw = file_get_contents(__DIR__ . '/fixtures/call-result-v1.json');
    if ($raw === false || !str_ends_with($raw, "\n")) {
        throw new RuntimeException('canonical contract fixture must have one terminal line ending');
    }
    $body = substr($raw, 0, -1);
    if (str_ends_with($body, "\r")) $body = substr($body, 0, -1);
    if (str_contains($body, "\r") || str_contains($body, "\n")) {
        throw new RuntimeException('canonical contract fixture must contain one JSON line');
    }
    return $body;
}

function endpoint_test_dir(): string {
    $directory = sys_get_temp_dir() . '/inventario-sync-endpoint-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700, true);
    return $directory;
}

function endpoint_test_cleanup(string $directory): void {
    foreach (['llamada-resultados.sqlite', 'llamada-resultados.sqlite-shm', 'llamada-resultados.sqlite-wal'] as $name) {
        $path = $directory . '/' . $name;
        if (is_file($path)) unlink($path);
    }
    if (is_dir($directory)) rmdir($directory);
}

function endpoint_test_allow_apache(string $directory): void {
    chmod($directory, 0777);
    foreach (['llamada-resultados.sqlite', 'llamada-resultados.sqlite-shm', 'llamada-resultados.sqlite-wal'] as $name) {
        $path = $directory . '/' . $name;
        if (is_file($path)) chmod($path, 0666);
    }
}

function endpoint_test_env(string $directory): array {
    return [
        'INVENTARIO_SYNC_SHARED_SECRET' => 'test-secret-with-at-least-32-bytes',
        'NO_INTEREST_STAGE_ID' => 'C28:NO_INTERESADO',
        'DATA_DIR' => $directory,
    ];
}

function endpoint_test_headers(string $body, int $timestamp, ?string $secret = null): array {
    $secret ??= 'test-secret-with-at-least-32-bytes';
    try {
        $decoded = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        $idempotencyKey = is_array($decoded) && is_string($decoded['callRequestId'] ?? null)
            ? $decoded['callRequestId']
            : '11111111-1111-4111-8111-111111111111';
    } catch (JsonException) {
        $idempotencyKey = '11111111-1111-4111-8111-111111111111';
    }
    return [
        'content-type' => 'application/json; charset=utf-8',
        'idempotency-key' => $idempotencyKey,
        'x-galjosa-timestamp' => (string)$timestamp,
        'x-galjosa-signature' => hash_hmac('sha256', $timestamp . "\n" . $body, $secret),
    ];
}

function endpoint_seed_processing(string $directory, array $input, int $now): void {
    $stage = 'C28:NO_INTERESADO';
    $request = llamada_validar_resultado(
        $input,
        new DateTimeImmutable('@' . $now),
        $stage
    );
    $requestHash = hash('sha256', json_encode([
        'request' => $request,
        'noInterestStage' => $stage,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $store = new LlamadaIdempotenciaStore($directory);
    $store->begin($request['memberId'] . ':' . $request['callRequestId'], $requestHash, $store->now());
    unset($store);
}

function endpoint_test_response(int $expectedStatus, array $expectedBody, array $actual, string $name): void {
    test_same($expectedStatus, $actual['status'] ?? null, $name . ' status');
    test_same($expectedBody, $actual['body'] ?? null, $name . ' body');
}

$now = 1787238000;

test_same(
    true,
    function_exists('llamada_resultado_production_http'),
    'production result endpoint has a server-webhook execution path'
);

if (function_exists('llamada_resultado_production_http')) {
    $directory = endpoint_test_dir();
    try {
        $fake = new EndpointFakeBitrix();
        $requestedUrls = [];
        $transport = static function (string $url, array $params) use ($fake, &$requestedUrls): array {
            $requestedUrls[] = $url;
            $path = (string)parse_url($url, PHP_URL_PATH);
            $method = preg_replace('/\.json$/D', '', basename($path));
            if (!is_string($method) || $method === '') {
                throw new RuntimeException('invalid Bitrix method URL');
            }
            $response = $fake($method, $params);
            if (($response['ok'] ?? false) !== true) {
                return [
                    'status' => 400,
                    'body' => json_encode([
                        'error' => $response['error'] ?? 'unknown',
                        'error_description' => $response['desc'] ?? '',
                    ], JSON_THROW_ON_ERROR),
                ];
            }
            return [
                'status' => 200,
                'body' => json_encode(['result' => $response['result']], JSON_THROW_ON_ERROR),
            ];
        };
        $body = endpoint_test_body([
            'callRequestId' => '10101010-1010-4010-8010-101010101010',
        ]);
        $env = endpoint_test_env($directory) + [
            'BITRIX_WEBHOOK' => 'https://bitrix.example/rest/1/server-secret/',
        ];
        $result = llamada_resultado_production_http(
            'POST',
            $body,
            endpoint_test_headers($body, $now),
            $env,
            $now,
            $transport
        );
        test_same(200, $result['status'] ?? null, 'production webhook result status');
        test_same('processed', $result['body']['status'] ?? null, 'production webhook result processed');
        test_same(
            'https://bitrix.example/rest/1/server-secret/crm.deal.get.json',
            $requestedUrls[0] ?? null,
            'production result reads Bitrix through the configured server webhook'
        );
    } finally {
        endpoint_test_cleanup($directory);
    }
}

$directory = endpoint_test_dir();
try {
    $fake = new EndpointFakeBitrix();
    $body = endpoint_contract_body();
    $headers = endpoint_test_headers($body, $now);
    $headers['Idempotency-Key'] = $headers['idempotency-key'];
    unset($headers['idempotency-key']);
    $result = llamada_resultado_http(
        'POST', $body, $headers, endpoint_test_env($directory), $fake, $now
    );
    test_same(200, $result['status'] ?? null, 'canonical contract status');
    test_same('processed', $result['body']['status'] ?? null, 'canonical contract processed outcome');
    test_same('answered', $result['body']['outcome'] ?? null, 'canonical contract answered outcome');
    test_same(731, $result['body']['bitrixActivityId'] ?? null, 'canonical contract activity id');
} finally {
    endpoint_test_cleanup($directory);
}

$directory = endpoint_test_dir();
try {
    $fake = new EndpointFakeBitrix();
    endpoint_test_response(400, ['error' => 'invalid_request'], llamada_resultado_http(
        'GET', '', [], endpoint_test_env($directory), $fake, $now
    ), 'non-POST request');
    test_same([], $fake->calls, 'non-POST request performs no Bitrix call');

    $body = endpoint_test_body();
    $validHeaders = endpoint_test_headers($body, $now);
    $missingIdempotencyKey = $validHeaders;
    unset($missingIdempotencyKey['idempotency-key']);
    endpoint_test_response(400, ['error' => 'invalid_request'], llamada_resultado_http(
        'POST', $body, $missingIdempotencyKey, endpoint_test_env($directory), $fake, $now
    ), 'missing idempotency key');
    endpoint_test_response(400, ['error' => 'invalid_request'], llamada_resultado_http(
        'POST', $body, array_replace($validHeaders, ['idempotency-key' => 'not-a-uuid']),
        endpoint_test_env($directory), $fake, $now
    ), 'malformed idempotency key');
    endpoint_test_response(400, ['error' => 'invalid_request'], llamada_resultado_http(
        'POST', $body, array_replace($validHeaders, [
            'idempotency-key' => '22222222-2222-4222-8222-222222222222',
        ]), endpoint_test_env($directory), $fake, $now
    ), 'mismatched idempotency key');
    test_same([], $fake->calls, 'invalid idempotency keys perform no Bitrix call');
    test_same(false, is_file($directory . '/llamada-resultados.sqlite'), 'invalid idempotency keys create no store');

    endpoint_test_response(400, ['error' => 'invalid_request'], llamada_resultado_http(
        'POST', $body, array_replace(endpoint_test_headers($body, $now), ['content-type' => 'text/plain']),
        endpoint_test_env($directory), $fake, $now
    ), 'wrong content type');
    test_same([], $fake->calls, 'wrong content type performs no Bitrix call');

    $largeBody = str_repeat('x', 65_537);
    endpoint_test_response(400, ['error' => 'invalid_request'], llamada_resultado_http(
        'POST', $largeBody, endpoint_test_headers($largeBody, $now), endpoint_test_env($directory), $fake, $now
    ), 'oversized request');
    test_same([], $fake->calls, 'oversized request performs no Bitrix call');
} finally {
    endpoint_test_cleanup($directory);
}

$directory = endpoint_test_dir();
try {
    $fake = new EndpointFakeBitrix();
    $input = endpoint_test_input([
        'callRequestId' => '12121212-1212-4212-8212-121212121212',
    ]);
    $body = json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    endpoint_seed_processing($directory, $input, $now);
    $processing = llamada_resultado_http(
        'POST', $body, endpoint_test_headers($body, $now), endpoint_test_env($directory), $fake, $now
    );
    endpoint_test_response(503, [
        'status' => 'processing',
        'callRequestId' => '12121212-1212-4212-8212-121212121212',
        'reason' => 'processing',
    ], $processing, 'identical operation still processing');
    test_same(['Retry-After' => '1'], $processing['headers'] ?? null, 'processing retry header');
    test_same([], $fake->calls, 'processing retry performs no Bitrix call');

    $differentBody = json_encode(array_replace($input, ['comment' => 'different']), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    endpoint_test_response(409, ['error' => 'conflict'], llamada_resultado_http(
        'POST', $differentBody, endpoint_test_headers($differentBody, $now), endpoint_test_env($directory), $fake, $now
    ), 'active operation with different fingerprint conflicts');
} finally {
    endpoint_test_cleanup($directory);
}

$directory = endpoint_test_dir();
try {
    $fake = new EndpointFakeBitrix();
    $body = endpoint_test_body([
        'callRequestId' => '77777777-7777-4777-8777-777777777777',
        'comment' => str_repeat('😀', 2_001),
    ]);
    endpoint_test_response(400, ['error' => 'invalid_request'], llamada_resultado_http(
        'POST', $body, endpoint_test_headers($body, $now), endpoint_test_env($directory), $fake, $now
    ), 'comment over 2000 Unicode code points');
    test_same([], $fake->calls, 'overlimit endpoint comment performs no Bitrix call');
} finally {
    endpoint_test_cleanup($directory);
}

$directory = endpoint_test_dir();
try {
    $fake = new EndpointFakeBitrix();
    $body = endpoint_test_body([
        'callRequestId' => '75757575-7575-4575-8575-757575757575',
        'selectedPhone' => '+593999999999',
    ]);
    endpoint_test_response(403, ['error' => 'forbidden'], llamada_resultado_http(
        'POST', $body, endpoint_test_headers($body, $now), endpoint_test_env($directory), $fake, $now
    ), 'selected phone outside Bitrix context');
    test_same(0, count(array_filter($fake->calls, fn(array $call): bool => str_ends_with($call[0], '.update'))), 'selected phone mismatch performs no endpoint write');
} finally {
    endpoint_test_cleanup($directory);
}

$directory = endpoint_test_dir();
try {
    $fake = new EndpointFakeBitrix();
    $body = endpoint_test_body();
    $missingSecret = endpoint_test_env($directory);
    unset($missingSecret['INVENTARIO_SYNC_SHARED_SECRET']);
    endpoint_test_response(503, ['error' => 'bitrix_unavailable'], llamada_resultado_http(
        'POST', $body, endpoint_test_headers($body, $now), $missingSecret, $fake, $now
    ), 'missing configuration');
    test_same([], $fake->calls, 'missing configuration performs no Bitrix call');

    endpoint_test_response(401, ['error' => 'unauthorized'], llamada_resultado_http(
        'POST', $body, endpoint_test_headers($body, $now, 'another-secret-with-at-least-32-bytes'),
        endpoint_test_env($directory), $fake, $now
    ), 'invalid signature');
    endpoint_test_response(401, ['error' => 'unauthorized'], llamada_resultado_http(
        'POST', $body, endpoint_test_headers($body, $now - 301), endpoint_test_env($directory), $fake, $now
    ), 'expired signature');
    test_same([], $fake->calls, 'unauthorized requests perform no Bitrix call');

    $invalidJson = '{"callRequestId":';
    endpoint_test_response(400, ['error' => 'invalid_request'], llamada_resultado_http(
        'POST', $invalidJson, endpoint_test_headers($invalidJson, $now), endpoint_test_env($directory), $fake, $now
    ), 'signed malformed JSON');
    test_same([], $fake->calls, 'malformed JSON performs no Bitrix call');
} finally {
    endpoint_test_cleanup($directory);
}

$directory = endpoint_test_dir();
try {
    $fake = new EndpointFakeBitrix();
    $body = endpoint_test_body();
    $result = llamada_resultado_http(
        'POST', $body, endpoint_test_headers($body, $now), endpoint_test_env($directory), $fake, $now
    );
    test_same(200, $result['status'], 'valid result status');
    test_same('processed', $result['body']['status'] ?? null, 'valid result body status');
    test_same(731, $result['body']['bitrixActivityId'] ?? null, 'valid result body activity');

    $repeat = llamada_resultado_http(
        'POST', $body, endpoint_test_headers($body, $now), endpoint_test_env($directory), $fake, $now
    );
    test_same(200, $repeat['status'], 'repeat status');
    test_same('already_processed', $repeat['body']['status'] ?? null, 'repeat body status');

    $conflictingBody = endpoint_test_body(['comment' => 'different']);
    endpoint_test_response(409, ['error' => 'conflict'], llamada_resultado_http(
        'POST', $conflictingBody, endpoint_test_headers($conflictingBody, $now), endpoint_test_env($directory), $fake, $now
    ), 'idempotency conflict');
} finally {
    endpoint_test_cleanup($directory);
}

$directory = endpoint_test_dir();
try {
    $fake = new EndpointFakeBitrix();
    $fake->errors['crm.deal.get'] = [
        'ok' => false,
        'error' => 'network-error',
        'desc' => 'credential=must-never-leak',
    ];
    $body = endpoint_test_body(['callRequestId' => '22222222-2222-4222-8222-222222222222']);
    endpoint_test_response(503, ['error' => 'bitrix_unavailable'], llamada_resultado_http(
        'POST', $body, endpoint_test_headers($body, $now), endpoint_test_env($directory), $fake, $now
    ), 'Bitrix failure');
} finally {
    endpoint_test_cleanup($directory);
}

$directory = endpoint_test_dir();
try {
    $fake = new EndpointFakeBitrix();
    $fake->errors['crm.activity.update'] = [
        'ok' => false,
        'error' => 'ACCESS_DENIED',
        'desc' => 'Access denied',
    ];
    $body = endpoint_test_body(['callRequestId' => '33333333-3333-4333-8333-333333333333']);
    endpoint_test_response(403, ['error' => 'forbidden'], llamada_resultado_http(
        'POST', $body, endpoint_test_headers($body, $now), endpoint_test_env($directory), $fake, $now
    ), 'Bitrix access denied');
    $callsAfterForbidden = $fake->calls;
    endpoint_test_response(403, ['error' => 'forbidden'], llamada_resultado_http(
        'POST', $body, endpoint_test_headers($body, $now), endpoint_test_env($directory), $fake, $now
    ), 'identical Bitrix access denied retry');
    test_same($callsAfterForbidden, $fake->calls, 'access denied endpoint retry performs no Bitrix call');

    $differentBody = endpoint_test_body([
        'callRequestId' => '33333333-3333-4333-8333-333333333333',
        'comment' => 'different',
    ]);
    endpoint_test_response(409, ['error' => 'conflict'], llamada_resultado_http(
        'POST', $differentBody, endpoint_test_headers($differentBody, $now), endpoint_test_env($directory), $fake, $now
    ), 'different payload after access denied');
    test_same(1, count(array_filter($fake->calls, fn(array $call): bool => $call[0] === 'crm.activity.update')), 'access denied is not retried or reassigned');
    test_same(0, count(array_filter($fake->calls, fn(array $call): bool => $call[0] === 'crm.deal.update')), 'access denied does not reassign the deal');
} finally {
    endpoint_test_cleanup($directory);
}

$directory = endpoint_test_dir();
try {
    $fake = new EndpointFakeBitrix();
    $fake->errors['crm.timeline.comment.add'] = [
        'ok' => false,
        'error' => 'ACCESS_DENIED',
        'desc' => 'Access denied',
    ];
    $body = endpoint_test_body([
        'callRequestId' => '34343434-3434-4343-8343-343434343434',
        'outcome' => 'answered',
        'nextActivityAt' => '2026-08-25T10:15:00-05:00',
        'comment' => 'Necesita seguimiento',
    ]);
    endpoint_test_response(403, ['error' => 'forbidden'], llamada_resultado_http(
        'POST', $body, endpoint_test_headers($body, $now), endpoint_test_env($directory), $fake, $now
    ), 'comment access denied');
    $callsAfterForbidden = $fake->calls;
    endpoint_test_response(403, ['error' => 'forbidden'], llamada_resultado_http(
        'POST', $body, endpoint_test_headers($body, $now), endpoint_test_env($directory), $fake, $now
    ), 'identical comment access denied retry');
    test_same($callsAfterForbidden, $fake->calls, 'comment access denied retry duplicates no external effect');
    $store = new LlamadaIdempotenciaStore($directory);
    $record = $store->get('member-1:34343434-3434-4343-8343-343434343434');
    test_same('forbidden', $record['state'] ?? null, 'endpoint persists comment forbidden state');
    test_same('pending', $record['comment_state'] ?? null, 'endpoint clears uncertain comment marker for known denial');
    unset($store);
} finally {
    endpoint_test_cleanup($directory);
}

$directory = endpoint_test_dir();
try {
    $fake = new EndpointFakeBitrix();
    $fake->errors['crm.timeline.comment.add'] = ['ok' => false, 'error' => 'network-error'];
    $body = endpoint_test_body([
        'callRequestId' => '44444444-4444-4444-8444-444444444444',
        'outcome' => 'answered',
        'nextActivityAt' => '2026-08-25T10:15:00-05:00',
        'comment' => 'Necesita seguimiento',
    ]);
    endpoint_test_response(503, ['error' => 'bitrix_unavailable'], llamada_resultado_http(
        'POST', $body, endpoint_test_headers($body, $now), endpoint_test_env($directory), $fake, $now
    ), 'uncertain comment delivery');
    $callsAfterFailure = $fake->calls;
    $review = llamada_resultado_http(
        'POST', $body, endpoint_test_headers($body, $now), endpoint_test_env($directory), $fake, $now
    );
    test_same(422, $review['status'], 'manual review status');
    test_same('manual_review', $review['body']['status'] ?? null, 'manual review body');
    test_same($callsAfterFailure, $fake->calls, 'manual review retry performs no Bitrix call');
} finally {
    endpoint_test_cleanup($directory);
}

function endpoint_http_request(string $url, string $method, string $body = '', array $headers = []): array {
    $handle = curl_init($url);
    $responseHeaders = [];
    curl_setopt_array($handle, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_HEADERFUNCTION => function ($curl, string $line) use (&$responseHeaders): int {
            $length = strlen($line);
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return $length;
        },
    ]);
    $raw = curl_exec($handle);
    $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($handle);
    curl_close($handle);
    if ($raw === false) throw new RuntimeException('HTTP request failed: ' . $curlError);
    return ['status' => $status, 'raw' => $raw, 'headers' => $responseHeaders];
}

function endpoint_http_json(string $url, string $method, string $body = '', array $headers = []): array {
    $response = endpoint_http_request($url, $method, $body, $headers);
    return [
        'status' => $response['status'],
        'body' => json_decode($response['raw'], true, 32, JSON_THROW_ON_ERROR),
        'headers' => $response['headers'],
    ];
}

$apache = '/usr/local/bin/apache2-foreground';
test_same(true, is_file($apache), 'real HTTP tests run inside the Docker image');
$httpDirectory = endpoint_test_dir();
endpoint_test_allow_apache($httpDirectory);
$httpEnv = getenv();
$httpEnv['INVENTARIO_SYNC_SHARED_SECRET'] = 'test-secret-with-at-least-32-bytes';
$httpEnv['NO_INTEREST_STAGE_ID'] = 'C28:NO_INTERESADO';
$httpEnv['DATA_DIR'] = $httpDirectory;
$pipes = [];
$apacheProcess = proc_open([$apache], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__), $httpEnv);
if (!is_resource($apacheProcess)) throw new RuntimeException('could not start Apache for real HTTP tests');

try {
    $endpointUrl = 'http://127.0.0.1/api/private/llamadas/v1/resultado';
    $ready = false;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        try {
            if (endpoint_http_request($endpointUrl, 'GET')['status'] > 0) {
                $ready = true;
                break;
            }
        } catch (Throwable) {
            usleep(100_000);
        }
    }
    test_same(true, $ready, 'Apache accepts real HTTP requests');

    endpoint_test_response(400, ['error' => 'invalid_request'], endpoint_http_json($endpointUrl, 'GET'), 'real HTTP wrong method');
    test_same(403, endpoint_http_request('http://127.0.0.1/tests/run.php', 'GET')['status'], 'Apache keeps tests blocked');

    $invalidJson = '{"callRequestId":';
    $timestamp = time();
    endpoint_test_response(400, ['error' => 'invalid_request'], endpoint_http_json(
        $endpointUrl,
        'POST',
        $invalidJson,
        [
            'Content-Type: application/json',
            'Idempotency-Key: 11111111-1111-4111-8111-111111111111',
            'X-Galjosa-Timestamp: ' . $timestamp,
            'X-Galjosa-Signature: ' . hash_hmac('sha256', $timestamp . "\n" . $invalidJson, $httpEnv['INVENTARIO_SYNC_SHARED_SECRET']),
        ]
    ), 'real HTTP exact HMAC over malformed JSON');

    $body = endpoint_test_body(['callRequestId' => '55555555-5555-4555-8555-555555555555']);
    $expired = time() - 301;
    endpoint_test_response(401, ['error' => 'unauthorized'], endpoint_http_json(
        $endpointUrl,
        'POST',
        $body,
        [
            'Content-Type: application/json',
            'Idempotency-Key: 55555555-5555-4555-8555-555555555555',
            'X-Galjosa-Timestamp: ' . $expired,
            'X-Galjosa-Signature: ' . hash_hmac('sha256', $expired . "\n" . $body, $httpEnv['INVENTARIO_SYNC_SHARED_SECRET']),
        ]
    ), 'real HTTP expired HMAC');

    $store = new LlamadaIdempotenciaStore($httpDirectory);
    $store->begin('member-1:66666666-6666-4666-8666-666666666666', str_repeat('a', 64), time());
    unset($store);
    endpoint_test_allow_apache($httpDirectory);
    $conflictBody = endpoint_test_body(['callRequestId' => '66666666-6666-4666-8666-666666666666']);
    $timestamp = time();
    endpoint_test_response(409, ['error' => 'conflict'], endpoint_http_json(
        $endpointUrl,
        'POST',
        $conflictBody,
        [
            'Content-Type: application/json',
            'Idempotency-Key: 66666666-6666-4666-8666-666666666666',
            'X-Galjosa-Timestamp: ' . $timestamp,
            'X-Galjosa-Signature: ' . hash_hmac('sha256', $timestamp . "\n" . $conflictBody, $httpEnv['INVENTARIO_SYNC_SHARED_SECRET']),
        ]
    ), 'real HTTP conflict');

    $processingInput = endpoint_test_input([
        'callRequestId' => '68686868-6868-4868-8868-686868686868',
    ]);
    $processingBody = json_encode($processingInput, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    endpoint_seed_processing($httpDirectory, $processingInput, time());
    endpoint_test_allow_apache($httpDirectory);
    $timestamp = time();
    $processingResponse = endpoint_http_json(
        $endpointUrl,
        'POST',
        $processingBody,
        [
            'Content-Type: application/json',
            'Idempotency-Key: 68686868-6868-4868-8868-686868686868',
            'X-Galjosa-Timestamp: ' . $timestamp,
            'X-Galjosa-Signature: ' . hash_hmac('sha256', $timestamp . "\n" . $processingBody, $httpEnv['INVENTARIO_SYNC_SHARED_SECRET']),
        ]
    );
    endpoint_test_response(503, [
        'status' => 'processing',
        'callRequestId' => '68686868-6868-4868-8868-686868686868',
        'reason' => 'processing',
    ], $processingResponse, 'real HTTP identical operation processing');
    test_same('1', $processingResponse['headers']['retry-after'] ?? null, 'real HTTP processing retry header');

    $manualBody = endpoint_test_body([
        'callRequestId' => '77777777-7777-4777-8777-777777777777',
        'outcome' => 'answered',
        'nextActivityAt' => (new DateTimeImmutable('tomorrow 10:15', new DateTimeZone('America/Guayaquil')))->format(DateTimeInterface::ATOM),
        'comment' => 'Necesita seguimiento',
    ]);
    $manualInput = json_decode($manualBody, true, 32, JSON_THROW_ON_ERROR);
    $manualFake = new EndpointFakeBitrix();
    $manualFake->errors['crm.timeline.comment.add'] = ['ok' => false, 'error' => 'network-error'];
    $manualStore = new LlamadaIdempotenciaStore($httpDirectory);
    try {
        llamada_procesar_resultado(
            $manualInput,
            $manualFake,
            $manualStore,
            new DateTimeImmutable('@' . time()),
            $httpEnv['NO_INTEREST_STAGE_ID']
        );
        throw new RuntimeException('manual review fixture did not fail uncertainly');
    } catch (LlamadaBitrixError) {
        // The persisted in-progress comment is the real uncertain-delivery state.
    }
    unset($manualStore);
    endpoint_test_allow_apache($httpDirectory);
    $timestamp = time();
    $manualResponse = endpoint_http_json(
        $endpointUrl,
        'POST',
        $manualBody,
        [
            'Content-Type: application/json',
            'Idempotency-Key: 77777777-7777-4777-8777-777777777777',
            'X-Galjosa-Timestamp: ' . $timestamp,
            'X-Galjosa-Signature: ' . hash_hmac('sha256', $timestamp . "\n" . $manualBody, $httpEnv['INVENTARIO_SYNC_SHARED_SECRET']),
        ]
    );
    test_same(422, $manualResponse['status'], 'real HTTP manual review status');
    test_same('manual_review', $manualResponse['body']['status'] ?? null, 'real HTTP manual review body');
} finally {
    proc_terminate($apacheProcess);
    foreach ($pipes as $pipe) fclose($pipe);
    proc_close($apacheProcess);
    endpoint_test_cleanup($httpDirectory);
}
