<?php
declare(strict_types=1);

$root = dirname(__DIR__, 4);
require_once $root . '/lib/private-api-auth.php';
require_once $root . '/lib/llamada-resultado-service.php';

const LLAMADA_RESULTADO_MAX_BODY_BYTES = 65_536;

function llamada_resultado_bitrix_transport(string $url, array $params): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($params),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $body = curl_exec($ch);
    $error = curl_errno($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [
        'status' => $status,
        'body' => is_string($body) ? $body : '',
        'error' => $error,
    ];
}

function llamada_resultado_webhook_bx(array $env, ?callable $transport = null): callable {
    $base = rtrim(trim((string)($env['BITRIX_WEBHOOK'] ?? '')), '/');
    $transport ??= 'llamada_resultado_bitrix_transport';

    return static function (string $method, array $params) use ($base, $transport): array {
        if ($base === '' || preg_match('/^[a-z0-9_.]+$/iD', $method) !== 1) {
            return ['ok' => false, 'error' => 'not-configured'];
        }
        try {
            $response = $transport($base . '/' . $method . '.json', $params);
        } catch (Throwable) {
            return ['ok' => false, 'error' => 'network-error'];
        }
        if (!is_array($response) || (int)($response['error'] ?? 0) !== 0) {
            return ['ok' => false, 'error' => 'network-error'];
        }
        $decoded = json_decode((string)($response['body'] ?? ''), true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'error' => 'bad-json'];
        }
        if (array_key_exists('error', $decoded)) {
            return [
                'ok' => false,
                'error' => (string)$decoded['error'],
                'desc' => (string)($decoded['error_description'] ?? ''),
            ];
        }
        return ['ok' => true, 'result' => $decoded['result'] ?? null];
    };
}

function llamada_resultado_error(int $status, string $error): array {
    return ['status' => $status, 'body' => ['error' => $error]];
}

function llamada_resultado_content_type_valido(mixed $value): bool {
    if (!is_string($value)) return false;
    return preg_match(
        '/^application\/json(?:\s*;\s*charset\s*=\s*"?utf-8"?)?\s*$/iD',
        trim($value)
    ) === 1;
}

function llamada_resultado_idempotency_key_valida(array $headers, stdClass $decoded): bool {
    $idempotencyKey = $headers['idempotency-key'] ?? null;
    $callRequestId = $decoded->callRequestId ?? null;
    return is_string($idempotencyKey)
        && is_string($callRequestId)
        && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $idempotencyKey) === 1
        && hash_equals($callRequestId, $idempotencyKey);
}

function llamada_resultado_http(
    string $method,
    string $body,
    array $headers,
    array $env,
    callable $bx,
    int $now
): array {
    if (strtoupper($method) !== 'POST') {
        return llamada_resultado_error(400, 'invalid_request');
    }

    $headers = array_change_key_case($headers, CASE_LOWER);
    if (!llamada_resultado_content_type_valido($headers['content-type'] ?? null)
        || $body === ''
        || strlen($body) > LLAMADA_RESULTADO_MAX_BODY_BYTES) {
        return llamada_resultado_error(400, 'invalid_request');
    }

    $secret = (string)($env['INVENTARIO_SYNC_SHARED_SECRET'] ?? '');
    $noInterestStage = trim((string)($env['NO_INTEREST_STAGE_ID'] ?? ''));
    $dataDir = trim((string)($env['DATA_DIR'] ?? ''));
    if (strlen($secret) < 32 || $noInterestStage === '' || $dataDir === '') {
        return llamada_resultado_error(503, 'bitrix_unavailable');
    }

    try {
        private_api_verify($body, $headers, $secret, $now);
    } catch (PrivateApiUnauthorized) {
        return llamada_resultado_error(401, 'unauthorized');
    }

    try {
        $decoded = json_decode($body, false, 64, JSON_THROW_ON_ERROR);
        if (!$decoded instanceof stdClass) {
            return llamada_resultado_error(400, 'invalid_request');
        }
        if (!llamada_resultado_idempotency_key_valida($headers, $decoded)) {
            return llamada_resultado_error(400, 'invalid_request');
        }

        $store = new LlamadaIdempotenciaStore($dataDir);
        $result = llamada_procesar_resultado(
            (array)$decoded,
            $bx,
            $store,
            new DateTimeImmutable('@' . $now),
            $noInterestStage
        );

        return match ((string)($result['status'] ?? '')) {
            'processed', 'already_processed' => ['status' => 200, 'body' => $result],
            'manual_review' => ['status' => 422, 'body' => $result],
            'processing' => [
                'status' => 503,
                'headers' => ['Retry-After' => '1'],
                'body' => $result + ['reason' => 'processing'],
            ],
            default => llamada_resultado_error(503, 'bitrix_unavailable'),
        };
    } catch (JsonException | LlamadaValidationError) {
        return llamada_resultado_error(400, 'invalid_request');
    } catch (LlamadaForbidden) {
        return llamada_resultado_error(403, 'forbidden');
    } catch (LlamadaIdempotenciaConflict) {
        return llamada_resultado_error(409, 'conflict');
    } catch (LlamadaBitrixError) {
        return llamada_resultado_error(503, 'bitrix_unavailable');
    } catch (Throwable) {
        return llamada_resultado_error(503, 'bitrix_unavailable');
    }
}

function llamada_resultado_production_http(
    string $method,
    string $body,
    array $headers,
    array $env,
    int $now,
    ?callable $transport = null
): array {
    return llamada_resultado_http(
        $method,
        $body,
        $headers,
        $env,
        llamada_resultado_webhook_bx($env, $transport),
        $now
    );
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    $requestHeaders = function_exists('getallheaders') ? getallheaders() : [];
    $environment = getenv();
    $result = llamada_resultado_production_http(
        (string)($_SERVER['REQUEST_METHOD'] ?? 'GET'),
        (string)file_get_contents('php://input'),
        is_array($requestHeaders) ? $requestHeaders : [],
        is_array($environment) ? ($_ENV + $environment) : $_ENV,
        time()
    );

    http_response_code($result['status']);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    foreach (($result['headers'] ?? []) as $name => $value) {
        header($name . ': ' . $value);
    }
    echo json_encode($result['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}
