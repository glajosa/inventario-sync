<?php
declare(strict_types=1);

$root = dirname(__DIR__, 4);
require_once $root . '/lib/private-api-auth.php';
require_once $root . '/lib/bot-commercial-catalog.php';

const BOT_COMMERCIAL_CATALOG_MAX_BODY_BYTES = 1024;

function bot_commercial_catalog_result(int $status, array $body, array $headers=[]): array {
    return ['status'=>$status, 'headers'=>[
        'Cache-Control'=>'no-store',
        'X-Content-Type-Options'=>'nosniff',
    ] + $headers, 'body'=>$body];
}

function bot_commercial_catalog_error(int $status, string $error, array $headers=[]): array {
    return bot_commercial_catalog_result($status, ['error'=>$error], $headers);
}

function bot_commercial_catalog_http(
    string $method,
    string $body,
    array $headers,
    array $env,
    array $cache,
    string $matrixDirectory,
    int $now
): array {
    if (strtoupper($method) !== 'POST') return bot_commercial_catalog_error(405, 'method_not_allowed');
    if (strlen($body) > BOT_COMMERCIAL_CATALOG_MAX_BODY_BYTES) return bot_commercial_catalog_error(413, 'request_too_large');
    $headers = array_change_key_case($headers, CASE_LOWER);
    if (!preg_match('/^application\/json(?:\s*;\s*charset\s*=\s*"?utf-8"?)?\s*$/iD', trim((string)($headers['content-type'] ?? '')))) {
        return bot_commercial_catalog_error(400, 'invalid_request');
    }
    if (!in_array(strtolower(trim((string)($env['BOT_INVENTORY_API_ENABLED'] ?? '0'))), ['1','true','yes','on'], true)) {
        return bot_commercial_catalog_error(503, 'inventory_disabled');
    }
    $secret = (string)($env['BOT_INVENTORY_SHARED_SECRET'] ?? '');
    if (strlen($secret) < 32) return bot_commercial_catalog_error(503, 'inventory_unavailable');
    try {
        private_api_verify($body, $headers, $secret, $now);
        $input = json_decode($body, false, 8, JSON_THROW_ON_ERROR);
        if (!is_object($input) || get_object_vars($input) !== []) throw new InvalidArgumentException('invalid_request');
        $document = bot_commercial_catalog_document($cache, $matrixDirectory, $now);
    } catch (PrivateApiUnauthorized) {
        return bot_commercial_catalog_error(401, 'unauthorized');
    } catch (JsonException | InvalidArgumentException) {
        return bot_commercial_catalog_error(400, 'invalid_request');
    } catch (Throwable) {
        return bot_commercial_catalog_error(503, 'inventory_unavailable', ['Retry-After'=>'60']);
    }
    return bot_commercial_catalog_result(200, $document);
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    $requestHeaders = function_exists('getallheaders') ? getallheaders() : [];
    $environment = getenv();
    $environment = is_array($environment) ? ($_ENV + $environment) : $_ENV;
    $dataDir = rtrim((string)($environment['DATA_DIR'] ?? '/data'), '/\\');
    try {
        $cache = json_decode((string)@file_get_contents($dataDir . '/selector_cache.json'), true, 128, JSON_THROW_ON_ERROR);
        if (!is_array($cache)) throw new RuntimeException('missing_catalog');
    } catch (Throwable) {
        $cache = [];
    }
    $result = bot_commercial_catalog_http(
        (string)($_SERVER['REQUEST_METHOD'] ?? 'GET'),
        (string)file_get_contents('php://input'),
        is_array($requestHeaders) ? $requestHeaders : [],
        $environment,
        $cache,
        $root . '/matrices',
        time()
    );
    http_response_code($result['status']);
    header('Content-Type: application/json; charset=utf-8');
    foreach (($result['headers'] ?? []) as $name=>$value) header($name . ': ' . $value);
    echo json_encode($result['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}
