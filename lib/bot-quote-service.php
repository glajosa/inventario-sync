<?php
declare(strict_types=1);

require_once __DIR__ . '/bot-quote-contract.php';
require_once __DIR__ . '/bot-recommendation-service.php';
require_once __DIR__ . '/bot-live-inventory.php';
require_once dirname(__DIR__) . '/cotizarlib.php';

function bot_quote_rules_version(array $profile): string {
    $matrix = dirname(__DIR__) . '/matrices/proyecto_' . (int)$profile['category_id'] . '.json';
    return 'sha256:' . hash('sha256', (string)file_get_contents(dirname(__DIR__) . '/cotizarlib.php')
        . '|' . (is_file($matrix) ? (string)file_get_contents($matrix) : ''));
}

function bot_quote_catalog_unit(array $catalog, array $profile, string $code): ?array {
    foreach (($catalog['units'] ?? []) as $unit) {
        if (!is_array($unit)) continue;
        if (strtoupper(trim((string)($unit['codigo'] ?? ''))) !== $code) continue;
        if ((int)($unit['cat'] ?? 0) !== (int)$profile['category_id']) continue;
        if (bot_contract_plain((string)($unit['stage'] ?? '')) !== 'disponible') return null;
        if ((int)($unit['dealId'] ?? 0) !== 0) return null;
        if (!bot_unit_is_commercially_released($unit, $profile)) return null;
        if (bot_catalog_money($unit['pvp'] ?? null) === null || bot_catalog_money($unit['m2'] ?? null) === null) return null;
        return $unit;
    }
    return null;
}

function bot_quote_revalidate_unit(array $unit, array $live, array $profile,
                                   array $stageNames=[]): ?array {
    if ((int)($live['id'] ?? 0) !== (int)($unit['id'] ?? 0)) return null;
    if ((int)($live['categoryId'] ?? 0) !== (int)$profile['category_id']) return null;
    if ((int)($live['parentId2'] ?? 0) !== 0) return null;
    if (!bot_live_stage_available((string)($live['stageId'] ?? ''), $profile, $stageNames)) return null;
    $code = strtoupper(trim(explode('(', (string)($live['title'] ?? ''))[0]));
    if ($code !== strtoupper(trim((string)$unit['codigo']))) return null;
    $pvp = bot_catalog_money($live[BOT_UNIT_PVP_FIELD] ?? null);
    $m2 = bot_catalog_money($live[BOT_UNIT_M2_FIELD] ?? null);
    if ($pvp === null || $m2 === null) return null;
    $fresh = $unit;
    $fresh['codigo'] = $code;
    $fresh['pvp'] = $pvp . '|USD';
    $fresh['m2'] = (string)$m2;
    $fresh['stage'] = 'DISPONIBLE';
    $fresh['dealId'] = 0;
    return $fresh;
}

function bot_quote_plan(float $pvp, array $request, array $profile): array {
    $category = (int)$profile['category_id'];
    $model = cot_modelo($category);
    $payment = $request['payment'];
    $options = [
        'reservaPct'=>(float)$model['reservaPct'],
        'contraPct'=>(float)$model['contraPct'],
        'extra'=>(bool)$model['extra'],
        'maxExtra'=>(int)$model['maxExtra'],
    ];
    foreach (['cuotasPct','extraPct'] as $key) if (isset($model[$key])) $options[$key] = (float)$model[$key];
    if ($payment['financing_percent'] !== null && empty($model['banco'])) {
        $options['financiarPct'] = (float)$payment['financing_percent'];
    }
    $installments = (int)$payment['installments'];
    $maximum = (int)($model['maxCuotas'] ?? 0);
    if ($maximum > 0) $installments = min($installments, $maximum);
    if (!empty($model['inmediata'])) {
        $installments = 0;
        $options['inmediata'] = true;
    }
    $plan = cot_plan(
        $pvp,
        $installments,
        (string)$payment['modality'],
        (string)$payment['start_month'],
        cot_entrega($category),
        (float)($payment['monthly_comfort'] ?? 0),
        $options,
    );
    return [
        'separation'=>(float)$plan['separacion'],
        'signing'=>(float)$plan['firma'],
        'monthly'=>round((float)$plan['mensual'], 2),
        'installments'=>(int)$plan['cuotas'],
        'installment_total'=>(float)$plan['sumaCuotas'],
        'extraordinary'=>(float)$plan['valorExtra'],
        'extraordinary_count'=>(int)$plan['nExtra'],
        'delivery_balance'=>(float)$plan['contraentrega'],
        'total'=>$pvp,
        'currency'=>'USD',
    ];
}

function bot_quote_preview(array $request, array $catalog, array $profile, int $now): array {
    $built = (int)($catalog['built'] ?? 0);
    if ($built <= 0 || $now - $built > 3600 || !empty($catalog['parcial'])) {
        throw new RuntimeException('stale_inventory');
    }
    $unit = bot_quote_catalog_unit($catalog, $profile, $request['unit_code']);
    if ($unit === null) throw new RuntimeException('unit_unavailable');
    $pvp = (float)bot_catalog_money($unit['pvp']);
    $m2 = (float)bot_catalog_money($unit['m2']);
    $expires = $now + 600;
    $response = [
        'schema_version'=>'bot-quote-v1',
        'request_id'=>$request['request_id'],
        'quote_id'=>$request['request_id'],
        'status'=>'preview',
        'project'=>['name'=>$profile['project'],'category_id'=>(int)$profile['category_id']],
        'unit'=>['id'=>(int)$unit['id'],'code'=>$request['unit_code'],'square_meters'=>$m2,
            'pvp'=>$pvp,'currency'=>'USD'],
        'rules_version'=>bot_quote_rules_version($profile),
        'editable_fields'=>BOT_QUOTE_EDITABLE_FIELDS,
        'payment_input'=>$request['payment'],
        'plan'=>bot_quote_plan($pvp, $request, $profile),
        'source'=>['catalog_pvp'=>$pvp,'verified_at'=>gmdate(DateTimeInterface::ATOM, $now)],
        'expires_at'=>gmdate(DateTimeInterface::ATOM, $expires),
        'document'=>null,
        '_private'=>['deal_id'=>(int)$request['deal_id'],'customer_name'=>$request['customer_name'] ?? ''],
    ];
    return $response;
}

function bot_quote_finalize(array $preview, array $liveUnit, array $env, int $now): array {
    $expected = $preview['unit'];
    $livePvp = bot_catalog_money($liveUnit['pvp'] ?? null);
    $unchanged = strtoupper(trim((string)($liveUnit['codigo'] ?? ''))) === $expected['code']
        && bot_contract_plain((string)($liveUnit['stage'] ?? '')) === 'disponible'
        && (int)($liveUnit['dealId'] ?? 0) === 0
        && $livePvp !== null && abs((float)$livePvp - (float)$expected['pvp']) < 0.005;
    if (!$unchanged) {
        $conflict = $preview;
        $conflict['status'] = 'conflict';
        $conflict['document'] = null;
        return $conflict;
    }
    $base = rtrim((string)($env['PUBLIC_BASE_URL'] ?? ''), '/');
    $secret = (string)($env['OUTBOUND_TOKEN'] ?? '');
    if (!preg_match('#^https://#', $base) || strlen($secret) < 16) throw new RuntimeException('quote_document_unavailable');
    $expires = $now + 43_200;
    $deal = (int)($preview['_private']['deal_id'] ?? 0);
    $signature = hash_hmac('sha256', "d{$deal}|e{$expires}", $secret);
    $payment = $preview['payment_input'];
    $query = [
        'u'=>(int)$expected['id'], 'd'=>$deal, 'exp'=>$expires, 's'=>$signature,
        'precio'=>(string)$expected['pvp'], 'cliente'=>(string)($preview['_private']['customer_name'] ?? ''),
        'n'=>(int)$payment['installments'], 'mod'=>(string)$payment['modality'],
        'mes'=>(string)$payment['start_month'],
    ];
    if ($payment['monthly_comfort'] !== null) $query['presu'] = (string)$payment['monthly_comfort'];
    if ($payment['financing_percent'] !== null) $query['financiar'] = (string)$payment['financing_percent'];
    $final = $preview;
    $final['status'] = 'finalized';
    $final['expires_at'] = gmdate(DateTimeInterface::ATOM, $expires);
    $url = $base . '/cotizar.php?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    $final['document'] = [
        'url'=>$url,
        'mime_type'=>'text/html',
        'fingerprint'=>'sha256:' . hash('sha256', json_encode([
            $expected, $preview['rules_version'], $preview['payment_input'], $preview['plan'], $expires,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
        'expires_at'=>$final['expires_at'],
    ];
    unset($final['_private']);
    return $final;
}
