<?php
declare(strict_types=1);

require_once __DIR__ . '/bot-recommendation-contract.php';
require_once __DIR__ . '/bot-recommendation-service.php';
require_once __DIR__ . '/../lib/private-api-auth.php';

const BOT_COMMERCIAL_SUMMARY_MAX_BODY_BYTES = 65_536;

function bot_commercial_summary_headers(): array {
    return [
        'Cache-Control'=>'no-store',
        'X-Content-Type-Options'=>'nosniff',
    ];
}

function bot_commercial_summary_result(int $status, array $body, array $headers=[]): array {
    return [
        'status'=>$status,
        'headers'=>bot_commercial_summary_headers() + $headers,
        'body'=>$body,
    ];
}

function bot_commercial_summary_error(int $status, string $error, array $headers=[]): array {
    return bot_commercial_summary_result($status, ['error'=>$error], $headers);
}

function bot_commercial_summary_content_type(mixed $value): bool {
    return is_string($value) && preg_match(
        '/^application\/json(?:\s*;\s*charset\s*=\s*"?utf-8"?)?\s*$/iD',
        trim($value)
    ) === 1;
}

function bot_commercial_summary_enabled(mixed $value): bool {
    return in_array(strtolower(trim((string)$value)), ['1','true','yes','on'], true);
}

function bot_commercial_summary_validate_request(array $input): string {
    if (array_diff(array_keys($input), ['request_id'])) {
        throw new InvalidArgumentException('invalid_request');
    }
    $requestId = strtolower(trim((string)($input['request_id'] ?? '')));
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $requestId)) {
        throw new InvalidArgumentException('invalid_request');
    }
    return $requestId;
}

function bot_commercial_summary_build(array $catalog, int $now): array {
    if (!isset($catalog['built']) || !is_numeric($catalog['built'])) {
        throw new RuntimeException('inventory_unavailable');
    }
    $built = (int)$catalog['built'];
    $age = max(0, $now - $built);
    if (!empty($catalog['parcial']) || $age > 3600) {
        throw new RuntimeException('inventory_unavailable');
    }
    if (!isset($catalog['units']) || !is_array($catalog['units'])) {
        throw new RuntimeException('inventory_unavailable');
    }

    $projects = [];
    foreach (bot_commercial_profiles() as $project => $profile) {
        $min = null;
        $count = 0;
        foreach ($catalog['units'] as $unit) {
            if (!is_array($unit)) continue;
            if ((int)($unit['cat'] ?? 0) !== (int)$profile['category_id']) continue;
            if (bot_contract_plain((string)($unit['stage'] ?? '')) !== 'disponible') continue;
            if ((int)($unit['dealId'] ?? 0) !== 0) continue;
            $pvp = bot_catalog_money($unit['pvp'] ?? null);
            if ($pvp === null) continue;
            $count++;
            $min = $min === null ? $pvp : min($min, $pvp);
        }
        $payment = $profile['standard_payment'] ?? [];
        $months = isset($payment['installment_months']) && $payment['installment_months'] !== null
            ? (int)$payment['installment_months']
            : bot_months_until($profile['delivery']['date'] ?? null, $now);
        $projects[$project] = [
            'price_from'=>$min,
            'currency'=>'USD',
            'installment_months'=>($months !== null && $months > 0) ? $months : null,
            'available_units'=>$count,
            'verified_at'=>gmdate(DateTimeInterface::ATOM, $now),
        ];
    }
    return [
        'schema_version'=>'bot-commercial-summary-v1',
        'generated_at'=>gmdate(DateTimeInterface::ATOM, $now),
        'catalog_age_seconds'=>$age,
        'projects'=>$projects,
    ];
}

function bot_commercial_summary_http(
    string $method,
    string $body,
    array $headers,
    array $env,
    int $now
): array {
    if (strtoupper($method) !== 'POST') return bot_commercial_summary_error(405, 'method_not_allowed');
    if (strlen($body) > BOT_COMMERCIAL_SUMMARY_MAX_BODY_BYTES) return bot_commercial_summary_error(413, 'request_too_large');
    $headers = array_change_key_case($headers, CASE_LOWER);
    if (!bot_commercial_summary_content_type($headers['content-type'] ?? null) || $body === '') {
        return bot_commercial_summary_error(400, 'invalid_request');
    }
    if (!bot_commercial_summary_enabled($env['BOT_INVENTORY_API_ENABLED'] ?? '0')) {
        return bot_commercial_summary_error(503, 'inventory_disabled');
    }
    $secret = (string)($env['BOT_INVENTORY_SHARED_SECRET'] ?? '');
    if (strlen($secret) < 32) return bot_commercial_summary_error(503, 'inventory_unavailable');
    try {
        private_api_verify($body, $headers, $secret, $now);
        $input = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($input) || array_is_list($input)) throw new InvalidArgumentException('invalid_request');
        $requestId = bot_commercial_summary_validate_request($input);
        $dataDir = rtrim((string)($env['DATA_DIR'] ?? '/data'), '/\\');
        $catalog = json_decode((string)@file_get_contents($dataDir . '/selector_cache.json'), true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($catalog)) throw new RuntimeException('inventory_unavailable');
        $summary = bot_commercial_summary_build($catalog, $now);
        $summary['request_id'] = $requestId;
        return bot_commercial_summary_result(200, $summary);
    } catch (PrivateApiUnauthorized) {
        return bot_commercial_summary_error(401, 'unauthorized');
    } catch (JsonException | InvalidArgumentException) {
        return bot_commercial_summary_error(400, 'invalid_request');
    } catch (Throwable) {
        return bot_commercial_summary_error(503, 'inventory_unavailable', ['Retry-After'=>'60']);
    }
}

function bot_commercial_summary_emit_http(): void {
    $requestHeaders = function_exists('getallheaders') ? getallheaders() : [];
    $environment = getenv();
    $result = bot_commercial_summary_http(
        (string)($_SERVER['REQUEST_METHOD'] ?? 'GET'),
        (string)file_get_contents('php://input'),
        is_array($requestHeaders) ? $requestHeaders : [],
        is_array($environment) ? ($_ENV + $environment) : $_ENV,
        time()
    );
    http_response_code($result['status']);
    header('Content-Type: application/json; charset=utf-8');
    foreach (($result['headers'] ?? []) as $name=>$value) header($name . ': ' . $value);
    echo json_encode($result['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    bot_commercial_summary_emit_http();
}
