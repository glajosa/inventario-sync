<?php
declare(strict_types=1);

require_once __DIR__ . '/bot-recommendation-contract.php';

function bot_catalog_money(mixed $value): ?float {
    if (is_int($value) || is_float($value)) {
        $amount = (float)$value;
    } else {
        $text = trim((string)$value);
        if ($text === '') return null;
        if (str_contains($text, '|')) {
            [$number, $currency] = array_pad(explode('|', $text, 2), 2, '');
            if (strtoupper(trim($currency)) !== 'USD') return null;
            $text = trim($number);
        }
        if (!is_numeric($text)) return null;
        $amount = (float)$text;
    }
    return is_finite($amount) && $amount > 0 ? round($amount, 2) : null;
}

function bot_unit_parts(string $code): ?array {
    $code = strtoupper(trim($code));
    if (preg_match('/^([A-Z]+)-(\d+)-(\d+)$/D', $code, $m)) {
        return ['tower'=>$m[1], 'floor'=>(int)$m[2], 'position'=>(int)$m[3], 'matrix_code'=>$code];
    }
    if (preg_match('/^([A-Z]+)(\d+)-(\d+)$/D', $code, $m)) {
        return ['tower'=>$m[1], 'floor'=>(int)$m[2], 'position'=>(int)$m[3],
            'matrix_code'=>$m[1].'-'.$m[2].'-'.$m[3]];
    }
    if (preg_match('/^([A-Z]+)-(\d+)$/D', $code, $m)) {
        return ['tower'=>$m[1], 'floor'=>1, 'position'=>(int)$m[2], 'matrix_code'=>$code];
    }
    return null;
}

function bot_project_matrix(array $profile): array {
    static $cache = [];
    $category = (int)($profile['category_id'] ?? 0);
    if (isset($cache[$category])) return $cache[$category];
    $path = dirname(__DIR__) . '/matrices/proyecto_' . $category . '.json';
    if (!is_file($path)) return $cache[$category] = [];
    $matrix = json_decode((string)file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
    return $cache[$category] = (is_array($matrix) ? $matrix : []);
}

function bot_matrix_level_key(array $matrix, int $floor): ?string {
    foreach (($matrix['niveles'] ?? []) as $key => $level) {
        if (!is_array($level)) continue;
        foreach (($level['pisos'] ?? []) as $candidateFloor) {
            if ((int)$candidateFloor === $floor) return (string)$key;
        }
    }
    return null;
}

function bot_matrix_position_category(array $matrix, array $parts): ?string {
    $code = (string)($parts['matrix_code'] ?? '');
    $override = $matrix['overrides_unidad'][$code]['categoria'] ?? null;
    if (is_string($override) && trim($override) !== '') return trim($override);

    $tower = (string)($parts['tower'] ?? '');
    $position = (string)((int)($parts['position'] ?? 0));
    $positions = $matrix['posiciones'][$tower] ?? null;
    if (!is_array($positions)) return null;

    $direct = $positions[$position] ?? null;
    if (is_string($direct) && trim($direct) !== '') return trim($direct);

    $levelKey = bot_matrix_level_key($matrix, (int)($parts['floor'] ?? 0));
    if ($levelKey === null || !is_array($positions[$levelKey] ?? null)) return null;
    $nested = $positions[$levelKey][$position] ?? null;
    return is_string($nested) && trim($nested) !== '' ? trim($nested) : null;
}

function bot_unit_commercial_attributes(array $candidate, array $profile): array {
    $parts = bot_unit_parts((string)($candidate['code'] ?? ''));
    if ($parts === null) return [];
    $matrix = bot_project_matrix($profile);
    $categoryCode = bot_matrix_position_category($matrix, $parts);
    $category = $categoryCode !== null ? ($matrix['categorias'][$categoryCode] ?? null) : null;

    $attributes = [
        'tower'=>(string)($candidate['tower'] ?? $parts['tower']),
        'floor'=>(string)($candidate['floor'] ?? $parts['floor']),
    ];
    if (is_array($category)) {
        $label = trim(strip_tags((string)($category['etiqueta'] ?? '')));
        if ($label !== '') $attributes['position'] = $label;
        $note = bot_contract_plain(strip_tags((string)($category['nota'] ?? '')));
        if (preg_match('/(?:^| )(\d{1,2}) dormitorios?(?: |$)/D', $note, $m)) {
            $attributes['bedrooms'] = (int)$m[1];
        }
    }
    return array_filter($attributes, static fn(mixed $value): bool => $value !== '');
}

function bot_unit_is_commercially_released(array $unit, array $profile): bool {
    $parts = bot_unit_parts((string)($unit['codigo'] ?? ''));
    if ($parts === null) return false;
    $matrix = bot_project_matrix($profile);
    foreach (($matrix['grupos'] ?? []) as $group) {
        if (in_array($parts['tower'], $group['edificios'] ?? [], true)) {
            if (($group['lanzado'] ?? true) === false) return false;
            break;
        }
    }
    $exempt = $matrix['exentas'] ?? [];
    return !array_key_exists($parts['matrix_code'], $exempt);
}

function bot_asset_type_name(mixed $value): string {
    $ids = [
        1791=>'local', 1793=>'departamento', 1797=>'suite', 1801=>'parqueo',
        1951=>'oficina', 1799=>'casa', 1947=>'casa', 1945=>'casa',
        1943=>'terreno', 1949=>'terreno',
    ];
    if (is_int($value) || ctype_digit(trim((string)$value))) {
        return $ids[(int)$value] ?? '';
    }
    $plain = bot_contract_plain((string)$value);
    foreach ([
        'consultorio'=>'oficina', 'oficinas'=>'oficina', 'locales'=>'local',
        'departamentos'=>'departamento', 'apartamento'=>'departamento',
        'suites'=>'suite', 'parqueos'=>'parqueo', 'estacionamiento'=>'parqueo',
        'casas'=>'casa', 'solar'=>'terreno', 'solares'=>'terreno',
    ] as $alias => $canonical) {
        if ($plain === $alias) return $canonical;
    }
    return $plain;
}

function bot_asset_type_matches(mixed $unitType, ?string $requested): bool {
    if ($requested === null) return true;
    $actual = bot_asset_type_name($unitType);
    $wanted = bot_asset_type_name($requested);
    if ($actual === '' || $wanted === '') return false;
    if ($wanted === 'residencial') return in_array($actual, ['departamento','suite','casa'], true);
    return $actual === $wanted;
}

function bot_unit_passes_hard_filters(array $unit, array $request, array $profile): bool {
    if ((int)($unit['cat'] ?? 0) !== (int)($profile['category_id'] ?? 0)) return false;
    if (bot_contract_plain((string)($unit['stage'] ?? '')) !== 'disponible') return false;
    if ((int)($unit['dealId'] ?? 0) !== 0) return false;
    if ((int)($unit['id'] ?? 0) <= 0) return false;
    if (!bot_unit_is_commercially_released($unit, $profile)) return false;
    if (!bot_asset_type_matches($unit['tipo'] ?? null, $request['asset_type'] ?? null)) return false;
    $m2 = bot_catalog_money($unit['m2'] ?? null);
    $pvp = bot_catalog_money($unit['pvp'] ?? null);
    if ($m2 === null || $pvp === null) return false;
    $limit = $request['explicit_total_limit'] ?? null;
    return $limit === null || $pvp <= (float)$limit;
}

function bot_months_until(?string $date, int $now): ?int {
    if ($date === null || !preg_match('/^(\d{4})-(\d{2})$/D', $date, $m)) return null;
    $month = (int)$m[2];
    if ($month < 1 || $month > 12) return null;
    $currentYear = (int)gmdate('Y', $now);
    $currentMonth = (int)gmdate('n', $now);
    return max(1, ((int)$m[1] - $currentYear) * 12 + $month - $currentMonth);
}

function bot_standard_payment_preview(float $pvp, array $profile, int $now): array {
    $payment = $profile['standard_payment'] ?? [];
    $months = isset($payment['installment_months']) && $payment['installment_months'] !== null
        ? (int)$payment['installment_months']
        : bot_months_until($profile['delivery']['date'] ?? null, $now);
    $installmentPercent = $payment['installment_percent'] ?? null;
    $signingPercent = $payment['signing_percent'] ?? null;
    $separation = $payment['separation'] ?? null;
    return [
        'entry' => ($signingPercent !== null && $separation !== null)
            ? round((float)$separation + ($pvp * (float)$signingPercent / 100), 2) : null,
        'monthly' => ($installmentPercent !== null && $months)
            ? round($pvp * (float)$installmentPercent / 100 / $months, 2) : null,
        'installment_months' => $months,
    ];
}

function bot_unit_score(array $unit, array $request, array $profile, int $now): array {
    $pvp = (float)bot_catalog_money($unit['pvp']);
    $components = ['verified_available'=>50, 'origin_project'=>20];
    $reasons = ['verified_available', 'origin_project'];
    if (($request['asset_type'] ?? null) !== null) {
        $components['asset_type'] = 15;
        $reasons[] = 'asset_type';
    }
    if (($request['explicit_total_limit'] ?? null) !== null) {
        $limit = max(1.0, (float)$request['explicit_total_limit']);
        $components['total_limit'] = round(max(0, 10 - (($limit - $pvp) / $limit) * 5), 3);
        $reasons[] = 'within_total_limit';
    }
    $preview = bot_standard_payment_preview($pvp, $profile, $now);
    foreach (['entry_comfort'=>'entry', 'monthly_comfort'=>'monthly'] as $requestKey => $previewKey) {
        $comfort = $request[$requestKey] ?? null;
        $value = $preview[$previewKey];
        if ($comfort !== null && $value !== null) {
            $fits = $value <= (float)$comfort;
            $components[$previewKey . '_comfort'] = $fits ? 8 : max(0, 4 - (($value / max(1.0, (float)$comfort)) - 1) * 4);
            if ($fits) $reasons[] = $previewKey . '_flow';
        }
    }
    return [
        'total'=>round(array_sum($components), 3),
        'components'=>$components,
        'reason_codes'=>array_values(array_unique($reasons)),
        'payment_preview'=>$preview,
    ];
}

function bot_candidate_from_unit(array $unit, array $request, array $profile, int $now): array {
    $parts = bot_unit_parts((string)$unit['codigo']);
    return [
        'id'=>(int)$unit['id'],
        'code'=>strtoupper(trim((string)$unit['codigo'])),
        'project'=>(string)$profile['project'],
        'category_id'=>(int)$profile['category_id'],
        'asset_type'=>bot_asset_type_name($unit['tipo'] ?? null),
        'square_meters'=>(float)bot_catalog_money($unit['m2']),
        'pvp'=>(float)bot_catalog_money($unit['pvp']),
        'currency'=>'USD',
        'tower'=>(string)($unit['torre'] ?? $parts['tower']),
        'floor'=>(string)($unit['piso'] ?? $parts['floor']),
        'position'=>(int)$parts['position'],
        'type_id'=>is_numeric($unit['tipo'] ?? null) ? (int)$unit['tipo'] : null,
        'score'=>bot_unit_score($unit, $request, $profile, $now),
    ];
}

function bot_diverse_take(array $candidates, int $limit): array {
    $selected = [];
    $deferred = [];
    $seenTowers = [];
    foreach ($candidates as $candidate) {
        $tower = (string)($candidate['tower'] ?? '');
        if ($tower !== '' && !isset($seenTowers[$tower]) && count($selected) < $limit) {
            $selected[] = $candidate;
            $seenTowers[$tower] = true;
        } else {
            $deferred[] = $candidate;
        }
    }
    foreach ($deferred as $candidate) {
        if (count($selected) >= $limit) break;
        $selected[] = $candidate;
    }
    return $selected;
}

function bot_recommendation_rank(array $request, array $catalog, array $profile, int $now): array {
    $built = (int)($catalog['built'] ?? 0);
    $age = $built > 0 ? max(0, $now - $built) : PHP_INT_MAX;
    $freshness = !empty($catalog['parcial']) || $age > 3600
        ? 'invalid' : ($age > 900 ? 'degraded' : 'normal');
    if ($freshness === 'invalid') {
        return ['status'=>'stale','freshness'=>'invalid','candidates'=>[],'catalog_age_seconds'=>$age];
    }
    $eligible = [];
    foreach (($catalog['units'] ?? []) as $unit) {
        if (!is_array($unit) || !bot_unit_passes_hard_filters($unit, $request, $profile)) continue;
        $eligible[] = bot_candidate_from_unit($unit, $request, $profile, $now);
    }
    usort($eligible, static function(array $a, array $b): int {
        $score = $b['score']['total'] <=> $a['score']['total'];
        return $score !== 0 ? $score : strnatcasecmp($a['code'], $b['code']);
    });
    $candidates = bot_diverse_take($eligible, (int)$request['max_options']);
    return [
        'status'=>$candidates ? 'candidates' : 'no_match',
        'freshness'=>$freshness,
        'candidates'=>$candidates,
        'eligible_count'=>count($eligible),
        'catalog_age_seconds'=>$age,
        'catalog_built'=>$built,
    ];
}

