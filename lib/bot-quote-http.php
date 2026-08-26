<?php
declare(strict_types=1);

require_once __DIR__ . '/private-api-auth.php';
require_once __DIR__ . '/bot-quote-contract.php';
require_once __DIR__ . '/bot-quote-service.php';

function bot_quote_http_result(int $status, array $body, array $headers=[]): array {
    return ['status'=>$status,'headers'=>['Cache-Control'=>'no-store','X-Content-Type-Options'=>'nosniff']+$headers,'body'=>$body];
}

function bot_quote_http_error(int $status, string $error): array {
    return bot_quote_http_result($status, ['error'=>$error]);
}

function bot_quote_http_auth(string $method, string $body, array $headers,
                             array $env, int $now): ?array {
    if (strtoupper($method) !== 'POST') return bot_quote_http_error(405, 'method_not_allowed');
    if ($body === '' || strlen($body) > 65_536) return bot_quote_http_error(400, 'invalid_request');
    $headers = array_change_key_case($headers, CASE_LOWER);
    if (!preg_match('/^application\/json(?:\s*;.*)?$/iD', (string)($headers['content-type'] ?? ''))) {
        return bot_quote_http_error(400, 'invalid_request');
    }
    $enabled = (string)($env['BOT_QUOTE_API_ENABLED'] ?? '');
    if ($enabled === '') $enabled = (string)($env['BOT_INVENTORY_API_ENABLED'] ?? '0');
    if (!in_array(strtolower($enabled), ['1','true','yes','on'], true)) {
        return bot_quote_http_error(503, 'quotes_disabled');
    }
    $secret = (string)($env['BOT_QUOTE_SHARED_SECRET'] ?? '');
    if ($secret === '') $secret = (string)($env['BOT_INVENTORY_SHARED_SECRET'] ?? '');
    if (strlen($secret) < 32) return bot_quote_http_error(503, 'quotes_unavailable');
    $timestampText = (string)($headers['x-galjosa-timestamp'] ?? '');
    $timestamp = filter_var($timestampText, FILTER_VALIDATE_INT);
    $nonce = strtolower(trim((string)($headers['x-galjosa-nonce'] ?? '')));
    $received = strtolower(trim((string)($headers['x-galjosa-signature'] ?? '')));
    if (!preg_match('/^(?:0|[1-9][0-9]*)$/D', $timestampText)
        || $timestamp === false || abs($now - $timestamp) > 300
        || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $nonce)
        || !preg_match('/^[a-f0-9]{64}$/D', $received)
        || !hash_equals(hash_hmac('sha256', $timestampText."\n".$nonce."\n".$body, $secret), $received)) {
        return bot_quote_http_error(401, 'unauthorized');
    }
    // Protección contra repetición. `x` crea el archivo de manera atómica: dos
    // contenedores/procesos no pueden consumir el mismo nonce.
    $nonceDir = rtrim((string)($env['DATA_DIR'] ?? '/data'), '/\\').'/bot_quote_nonces';
    if (!is_dir($nonceDir) && !mkdir($nonceDir, 0700, true) && !is_dir($nonceDir)) {
        return bot_quote_http_error(503, 'quotes_unavailable');
    }
    foreach (glob($nonceDir.'/*') ?: [] as $old) {
        if (is_file($old) && filemtime($old) < $now - 600) @unlink($old);
    }
    $handle = @fopen($nonceDir.'/'.hash('sha256', $nonce), 'x');
    if ($handle === false) return bot_quote_http_error(401, 'replayed_request');
    fwrite($handle, (string)$now); fclose($handle);
    return null;
}

function bot_quote_catalog(array $env): array {
    $path = rtrim((string)($env['DATA_DIR'] ?? '/data'), '/\\') . '/selector_cache.json';
    $catalog = json_decode((string)@file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($catalog) || !isset($catalog['units'])) throw new RuntimeException('missing_catalog');
    return $catalog;
}

function bot_quote_state_path(array $env, string $quoteId): string {
    $dir = rtrim((string)($env['DATA_DIR'] ?? '/data'), '/\\') . '/bot_quote_previews';
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('quote_store_unavailable');
    return $dir . '/' . $quoteId . '.json';
}

function bot_quote_preview_http(string $method, string $body, array $headers,
                                array $env, callable $bx, int $now): array {
    if ($error = bot_quote_http_auth($method, $body, $headers, $env, $now)) return $error;
    try {
        $input = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($input) || array_is_list($input)) throw new InvalidArgumentException();
        $request = bot_quote_normalize_request($input);
        $profile = bot_commercial_profile($request['project']);
        $catalog = bot_quote_catalog($env);
        $unit = bot_quote_catalog_unit($catalog, $profile, $request['unit_code']);
        if ($unit === null) return bot_quote_http_error(409, 'unit_unavailable');
        $live = bot_bx_item($bx('crm.item.get', ['entityTypeId'=>1072,'id'=>(int)$unit['id']]));
        $fresh = bot_quote_revalidate_unit($unit, $live, $profile, $catalog['stages'] ?? []);
        if ($fresh === null) return bot_quote_http_error(409, 'unit_changed');
        foreach ($catalog['units'] as $index=>$candidate) {
            if ((int)($candidate['id'] ?? 0) === (int)$fresh['id']) $catalog['units'][$index] = $fresh;
        }
        $preview = bot_quote_preview($request, $catalog, $profile, $now);
        file_put_contents(bot_quote_state_path($env, $preview['quote_id']), json_encode($preview, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR), LOCK_EX);
        $public = $preview;
        unset($public['_private']);
        return bot_quote_http_result(200, $public);
    } catch (InvalidArgumentException | JsonException) {
        return bot_quote_http_error(400, 'invalid_request');
    } catch (Throwable) {
        return bot_quote_http_error(503, 'quotes_unavailable');
    }
}

function bot_quote_finalize_http(string $method, string $body, array $headers,
                                 array $env, callable $bx, int $now): array {
    if ($error = bot_quote_http_auth($method, $body, $headers, $env, $now)) return $error;
    try {
        $input = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($input) || array_keys($input) !== ['quote_id']) throw new InvalidArgumentException();
        $quoteId = strtolower(trim((string)$input['quote_id']));
        if (!preg_match('/^[0-9a-f-]{36}$/D', $quoteId)) throw new InvalidArgumentException();
        $preview = json_decode((string)file_get_contents(bot_quote_state_path($env, $quoteId)), true, 64, JSON_THROW_ON_ERROR);
        if (($preview['status'] ?? '') === 'finalized') {
            return bot_quote_http_result(200, $preview);
        }
        if (strtotime((string)$preview['expires_at']) < $now) return bot_quote_http_error(409, 'preview_expired');
        $profile = bot_commercial_profile((string)$preview['project']['name']);
        if (!hash_equals((string)$preview['rules_version'], bot_quote_rules_version($profile))) {
            return bot_quote_http_error(409, 'rules_changed');
        }
        $live = bot_bx_item($bx('crm.item.get', ['entityTypeId'=>1072,'id'=>(int)$preview['unit']['id']]));
        $unit = [
            'id'=>(int)$live['id'], 'codigo'=>strtoupper(trim(explode('(', (string)$live['title'])[0])),
            'cat'=>(string)$live['categoryId'], 'stage'=>'DISPONIBLE', 'dealId'=>(int)($live['parentId2'] ?? 0),
            'm2'=>$live[BOT_UNIT_M2_FIELD] ?? null, 'pvp'=>$live[BOT_UNIT_PVP_FIELD] ?? null,
        ];
        if (!bot_live_stage_available((string)($live['stageId'] ?? ''), $profile)) $unit['stage'] = 'NO_DISPONIBLE';
        $final = bot_quote_finalize($preview, $unit, $env, $now);
        if ($final['status'] === 'conflict') return bot_quote_http_result(409, $final);
        file_put_contents(bot_quote_state_path($env, $quoteId), json_encode($final, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR), LOCK_EX);
        return bot_quote_http_result(200, $final);
    } catch (InvalidArgumentException | JsonException) {
        return bot_quote_http_error(400, 'invalid_request');
    } catch (Throwable) {
        return bot_quote_http_error(503, 'quotes_unavailable');
    }
}

function bot_quote_status_http(string $method, string $body, array $headers,
                               array $env, int $now): array {
    if ($error = bot_quote_http_auth($method, $body, $headers, $env, $now)) return $error;
    try {
        $input = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($input) || array_keys($input) !== ['quote_id']) throw new InvalidArgumentException();
        $quoteId = strtolower(trim((string)$input['quote_id']));
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $quoteId)) {
            throw new InvalidArgumentException();
        }
        $path = bot_quote_state_path($env, $quoteId);
        if (!is_file($path)) return bot_quote_http_error(404, 'quote_not_found');
        $state = json_decode((string)file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        unset($state['_private']);
        bot_quote_validate_response($state);
        return bot_quote_http_result(200, $state);
    } catch (InvalidArgumentException | JsonException) {
        return bot_quote_http_error(400, 'invalid_request');
    } catch (Throwable) {
        return bot_quote_http_error(503, 'quotes_unavailable');
    }
}
