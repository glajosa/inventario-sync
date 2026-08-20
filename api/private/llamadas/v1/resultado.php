<?php
declare(strict_types=1);

$root = dirname(__DIR__, 4);
require_once $root . '/appauth.php';
require_once $root . '/lib/private-api-auth.php';
require_once $root . '/lib/llamada-resultado-service.php';

const LLAMADA_RESULTADO_MAX_BODY_BYTES = 65_536;

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

function llamada_resultado_bx_seguro(callable $bx): callable {
    return static function (string $method, array $params) use ($bx): array {
        $response = $bx($method, $params);
        if (is_array($response) && ($response['ok'] ?? false) !== true) {
            $code = strtoupper(trim((string)($response['error'] ?? '')));
            $description = trim((string)($response['desc'] ?? ''));
            if ($code === 'ACCESS_DENIED' || preg_match('/\baccess denied\b/i', $description) === 1) {
                throw new LlamadaForbidden('Bitrix access denied');
            }
        }
        return $response;
    };
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

        $store = new LlamadaIdempotenciaStore($dataDir);
        $result = llamada_procesar_resultado(
            (array)$decoded,
            llamada_resultado_bx_seguro($bx),
            $store,
            new DateTimeImmutable('@' . $now),
            $noInterestStage
        );

        return match ((string)($result['status'] ?? '')) {
            'processed', 'already_processed' => ['status' => 200, 'body' => $result],
            'manual_review' => ['status' => 422, 'body' => $result],
            'processing' => llamada_resultado_error(409, 'conflict'),
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

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    $requestHeaders = function_exists('getallheaders') ? getallheaders() : [];
    $environment = getenv();
    $result = llamada_resultado_http(
        (string)($_SERVER['REQUEST_METHOD'] ?? 'GET'),
        (string)file_get_contents('php://input'),
        is_array($requestHeaders) ? $requestHeaders : [],
        is_array($environment) ? ($_ENV + $environment) : $_ENV,
        'app_bx',
        time()
    );

    http_response_code($result['status']);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($result['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}
