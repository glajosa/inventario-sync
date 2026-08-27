<?php
declare(strict_types=1);

function bot_contract_plain(string $value): string {
    $value = trim($value);
    if ($value === '') return '';
    $ascii = strtolower((string)iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value));
    return trim(preg_replace('/[^a-z0-9]+/', ' ', $ascii) ?? '');
}

function bot_commercial_profiles(): array {
    static $profiles = null;
    if ($profiles !== null) return $profiles;
    $path = dirname(__DIR__) . '/commercial-projects.json';
    $decoded = json_decode((string)file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($decoded) || !$decoded) {
        throw new RuntimeException('commercial_profiles_unavailable');
    }
    foreach ($decoded as $name => $profile) {
        if (!is_string($name) || !is_array($profile) || !isset($profile['category_id'])) {
            throw new RuntimeException('invalid_commercial_profiles');
        }
    }
    return $profiles = $decoded;
}

function bot_canonical_project(string $value): string {
    $needle = bot_contract_plain($value);
    if ($needle === '') return '';
    foreach (bot_commercial_profiles() as $canonical => $profile) {
        $labels = array_merge([$canonical], $profile['aliases'] ?? []);
        foreach ($labels as $label) {
            if ($needle === bot_contract_plain((string)$label)) return $canonical;
        }
    }
    return '';
}

function bot_commercial_profile(string $project): array {
    $canonical = bot_canonical_project($project);
    if ($canonical === '') throw new InvalidArgumentException('unsupported_project');
    $profile = bot_commercial_profiles()[$canonical];
    $profile['project'] = $canonical;
    return $profile;
}

function bot_nullable_enum(mixed $value, array $allowed): ?string {
    if ($value === null || trim((string)$value) === '') return null;
    $normalized = bot_contract_plain((string)$value);
    if (!in_array($normalized, $allowed, true)) {
        throw new InvalidArgumentException('invalid_enum');
    }
    return $normalized;
}

function bot_nullable_string(mixed $value, int $maxLength = 120): ?string {
    if ($value === null) return null;
    $text = trim((string)$value);
    if ($text === '') return null;
    $length = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
    if ($length > $maxLength || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $text)) {
        throw new InvalidArgumentException('invalid_string');
    }
    return $text;
}

function bot_nullable_money(mixed $value): ?float {
    if ($value === null || $value === '') return null;
    if (!is_int($value) && !is_float($value) && !is_string($value)) {
        throw new InvalidArgumentException('invalid_money');
    }
    if (!is_numeric($value)) throw new InvalidArgumentException('invalid_money');
    $money = (float)$value;
    if (!is_finite($money) || $money < 0 || $money > 100000000 || round($money, 2) !== $money) {
        throw new InvalidArgumentException('invalid_money');
    }
    return $money;
}

function bot_string_list(mixed $value, int $limit): array {
    if (!is_array($value) || count($value) > $limit) {
        throw new InvalidArgumentException('invalid_list');
    }
    $result = [];
    foreach ($value as $item) {
        $text = bot_nullable_string($item, 80);
        if ($text === null) throw new InvalidArgumentException('invalid_list_item');
        if (!in_array($text, $result, true)) $result[] = $text;
    }
    return $result;
}

function bot_recommendation_normalize_request(array $input): array {
    $allowed = [
        'request_id', 'project', 'project_enum', 'segment', 'asset_type', 'bedrooms',
        'use', 'delivery_preference', 'payment_mode', 'entry_comfort', 'monthly_comfort',
        'explicit_total_limit', 'preferences', 'revalidate_codes', 'max_options',
    ];
    $unknown = array_diff(array_keys($input), $allowed);
    if ($unknown) throw new InvalidArgumentException('unknown_field');

    $requestId = strtolower(trim((string)($input['request_id'] ?? '')));
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $requestId)) {
        throw new InvalidArgumentException('invalid_request_id');
    }
    $project = bot_canonical_project((string)($input['project'] ?? ''));
    if ($project === '') throw new InvalidArgumentException('invalid_project');

    $max = filter_var($input['max_options'] ?? 3, FILTER_VALIDATE_INT);
    if ($max === false || $max < 1 || $max > 3) {
        throw new InvalidArgumentException('invalid_max_options');
    }
    $bedrooms = null;
    if (array_key_exists('bedrooms', $input) && $input['bedrooms'] !== null) {
        $bedrooms = filter_var($input['bedrooms'], FILTER_VALIDATE_INT);
        if ($bedrooms === false || $bedrooms < 0 || $bedrooms > 20) {
            throw new InvalidArgumentException('invalid_bedrooms');
        }
    }

    return [
        'request_id' => $requestId,
        'project' => $project,
        'project_enum' => trim((string)($input['project_enum'] ?? '')),
        'segment' => bot_nullable_enum($input['segment'] ?? null, ['residencial', 'comercial']),
        'asset_type' => bot_nullable_string($input['asset_type'] ?? null),
        'bedrooms' => $bedrooms,
        'use' => bot_nullable_enum($input['use'] ?? null, ['vivienda', 'inversion', 'trabajo']),
        'delivery_preference' => bot_nullable_string($input['delivery_preference'] ?? null),
        'payment_mode' => bot_nullable_enum($input['payment_mode'] ?? null, ['contado', 'financiamiento']),
        'entry_comfort' => bot_nullable_money($input['entry_comfort'] ?? null),
        'monthly_comfort' => bot_nullable_money($input['monthly_comfort'] ?? null),
        'explicit_total_limit' => bot_nullable_money($input['explicit_total_limit'] ?? null),
        'preferences' => bot_string_list($input['preferences'] ?? [], 8),
        'revalidate_codes' => bot_string_list($input['revalidate_codes'] ?? [], 3),
        'max_options' => $max,
    ];
}

function bot_recommendation_validate_response(array $response): void {
    if (($response['schema_version'] ?? '') !== 'bot-recommendation-v1') {
        throw new InvalidArgumentException('invalid_schema_version');
    }
    $requestId = (string)($response['request_id'] ?? '');
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $requestId)) {
        throw new InvalidArgumentException('invalid_response_request_id');
    }
    if (!in_array($response['status'] ?? null, ['verified', 'no_match', 'stale', 'unavailable'], true)) {
        throw new InvalidArgumentException('invalid_response_status');
    }
    $options = $response['options'] ?? null;
    if (!is_array($options) || count($options) > 3) {
        throw new InvalidArgumentException('invalid_response_options');
    }
    $seen = [];
    foreach ($options as $index => $option) {
        if (!is_array($option) || ($option['number'] ?? null) !== $index + 1) {
            throw new InvalidArgumentException('invalid_option_number');
        }
        $code = trim((string)($option['code'] ?? ''));
        $m2 = $option['square_meters'] ?? null;
        $pvp = $option['pvp'] ?? null;
        if ($code === '' || isset($seen[$code]) || !is_numeric($m2) || (float)$m2 <= 0
            || !is_numeric($pvp) || (float)$pvp <= 0 || ($option['currency'] ?? '') !== 'USD') {
            throw new InvalidArgumentException('invalid_option');
        }
        $attributes = $option['attributes'] ?? [];
        if (!is_array($attributes) || array_diff(array_keys($attributes), ['position','tower','floor','view','bedrooms'])) {
            throw new InvalidArgumentException('invalid_option_attributes');
        }
        foreach (['position','tower','floor','view'] as $attribute) {
            if (!array_key_exists($attribute, $attributes)) continue;
            if (!is_string($attributes[$attribute]) || bot_nullable_string($attributes[$attribute], 80) === null) {
                throw new InvalidArgumentException('invalid_option_attributes');
            }
        }
        if (array_key_exists('bedrooms', $attributes)) {
            $bedrooms = $attributes['bedrooms'];
            if (!is_int($bedrooms) || $bedrooms < 0 || $bedrooms > 20) {
                throw new InvalidArgumentException('invalid_option_attributes');
            }
        }
        $seen[$code] = true;
    }
}
