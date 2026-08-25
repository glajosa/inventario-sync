<?php
declare(strict_types=1);

$root = dirname(__DIR__, 4);
require_once $root . '/appauth.php';
require_once $root . '/lib/private-api-auth.php';
require_once $root . '/lib/bot-recommendation-contract.php';
require_once $root . '/lib/bot-recommendation-service.php';
require_once $root . '/lib/bot-live-inventory.php';

const BOT_RECOMMENDATIONS_MAX_BODY_BYTES = 65_536;

function bot_recommendations_headers(): array {
    return [
        'Cache-Control'=>'no-store',
        'X-Content-Type-Options'=>'nosniff',
    ];
}

function bot_recommendations_result(int $status, array $body, array $headers=[]): array {
    return ['status'=>$status,'headers'=>bot_recommendations_headers() + $headers,'body'=>$body];
}

function bot_recommendations_error(int $status, string $error, array $headers=[]): array {
    return bot_recommendations_result($status, ['error'=>$error], $headers);
}

function bot_recommendations_content_type(mixed $value): bool {
    return is_string($value) && preg_match(
        '/^application\/json(?:\s*;\s*charset\s*=\s*"?utf-8"?)?\s*$/iD',
        trim($value)
    ) === 1;
}

function bot_recommendations_enabled(mixed $value): bool {
    return in_array(strtolower(trim((string)$value)), ['1','true','yes','on'], true);
}

function bot_unavailable_codes(array $catalog, array $profile): array {
    $codes = [];
    foreach (($catalog['units'] ?? []) as $unit) {
        if (!is_array($unit) || (int)($unit['cat'] ?? 0) !== (int)$profile['category_id']) continue;
        if (bot_contract_plain((string)($unit['stage'] ?? '')) === 'disponible'
            && (int)($unit['dealId'] ?? 0) === 0) continue;
        $code = strtoupper(trim((string)($unit['codigo'] ?? '')));
        if ($code !== '' && strlen($code) <= 40) $codes[$code] = true;
    }
    $result = array_keys($codes);
    sort($result, SORT_NATURAL | SORT_FLAG_CASE);
    return $result;
}

function bot_recommendations_http(
    string $method,
    string $body,
    array $headers,
    array $env,
    callable $bx,
    int $now
): array {
    if (strtoupper($method) !== 'POST') return bot_recommendations_error(405, 'method_not_allowed');
    if (strlen($body) > BOT_RECOMMENDATIONS_MAX_BODY_BYTES) return bot_recommendations_error(413, 'request_too_large');
    $headers = array_change_key_case($headers, CASE_LOWER);
    if (!bot_recommendations_content_type($headers['content-type'] ?? null) || $body === '') {
        return bot_recommendations_error(400, 'invalid_request');
    }
    if (!bot_recommendations_enabled($env['BOT_INVENTORY_API_ENABLED'] ?? '0')) {
        return bot_recommendations_error(503, 'inventory_disabled');
    }
    $secret = (string)($env['BOT_INVENTORY_SHARED_SECRET'] ?? '');
    if (strlen($secret) < 32) return bot_recommendations_error(503, 'inventory_unavailable');
    try {
        private_api_verify($body, $headers, $secret, $now);
    } catch (PrivateApiUnauthorized) {
        return bot_recommendations_error(401, 'unauthorized');
    }
    try {
        $input = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($input) || array_is_list($input)) throw new InvalidArgumentException('invalid_request');
        $request = bot_recommendation_normalize_request($input);
        $profile = bot_commercial_profile($request['project']);
    } catch (JsonException | InvalidArgumentException) {
        return bot_recommendations_error(400, 'invalid_request');
    }

    $dataDir = rtrim((string)($env['DATA_DIR'] ?? '/data'), '/\\');
    $catalogPath = $dataDir . '/selector_cache.json';
    try {
        $catalog = json_decode((string)@file_get_contents($catalogPath), true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($catalog) || !isset($catalog['units'])) throw new RuntimeException('missing_catalog');
    } catch (Throwable) {
        return bot_recommendations_error(503, 'inventory_unavailable', ['Retry-After'=>'60']);
    }

    $poolRequest = $request;
    $poolRequest['max_options'] = 12;
    $ranked = bot_recommendation_rank($poolRequest, $catalog, $profile, $now);
    if ($ranked['status'] === 'stale') {
        return bot_recommendations_error(503, 'inventory_unavailable', ['Retry-After'=>'60']);
    }

    $expires = $now + 300;
    $base = [
        'schema_version'=>'bot-recommendation-v1',
        'request_id'=>$request['request_id'],
        'project'=>['name'=>$profile['project'],'category_id'=>(int)$profile['category_id']],
        'source'=>[
            'catalog_generated_at'=>gmdate(DateTimeInterface::ATOM, (int)$ranked['catalog_built']),
            'catalog_age_seconds'=>(int)$ranked['catalog_age_seconds'],
            'live_verified_at'=>gmdate(DateTimeInterface::ATOM, $now),
        ],
    ];
    if (!$ranked['candidates']) {
        return bot_recommendations_result(200, $base + [
            'status'=>'no_match','options'=>[],'render'=>null,
            'expires_at'=>gmdate(DateTimeInterface::ATOM, $expires),
        ]);
    }

    $validated = bot_validate_finalists(
        $ranked['candidates'], $request, $profile, $bx, $now, $catalog['stages'] ?? []
    );
    if (!$validated['options']) {
        $status = $validated['read_errors'] >= count($ranked['candidates']) ? 503 : 200;
        if ($status === 503) return bot_recommendations_error(503, 'inventory_unavailable', ['Retry-After'=>'15']);
        return bot_recommendations_result(200, $base + [
            'status'=>'unavailable','options'=>[],'render'=>null,
            'expires_at'=>gmdate(DateTimeInterface::ATOM, $expires),
        ]);
    }

    $render = null;
    if (in_array($profile['project'], ['Noral Plaza','Noral Apartments'], true)) {
        $render = [
            'project'=>$profile['project'],
            'snapshot_id'=>$request['request_id'],
            'expires_at'=>gmdate(DateTimeInterface::ATOM, $expires),
            'unavailable_codes'=>bot_unavailable_codes($catalog, $profile),
            'recommendations'=>array_map(
                fn(array $option): array => ['number'=>$option['number'],'code'=>$option['code']],
                $validated['options']
            ),
        ];
    }
    return bot_recommendations_result(200, $base + [
        'status'=>'verified',
        'options'=>$validated['options'],
        'render'=>$render,
        'expires_at'=>gmdate(DateTimeInterface::ATOM, $expires),
    ]);
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    $requestHeaders = function_exists('getallheaders') ? getallheaders() : [];
    $environment = getenv();
    $result = bot_recommendations_http(
        (string)($_SERVER['REQUEST_METHOD'] ?? 'GET'),
        (string)file_get_contents('php://input'),
        is_array($requestHeaders) ? $requestHeaders : [],
        is_array($environment) ? ($_ENV + $environment) : $_ENV,
        'app_bx',
        time()
    );
    http_response_code($result['status']);
    header('Content-Type: application/json; charset=utf-8');
    foreach (($result['headers'] ?? []) as $name=>$value) header($name . ': ' . $value);
    echo json_encode($result['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

