<?php
declare(strict_types=1);

require_once __DIR__ . '/bot-recommendation-contract.php';
require_once __DIR__ . '/bot-recommendation-service.php';

const BOT_UNIT_M2_FIELD = 'ufCrm25_1782615822688';
const BOT_UNIT_PVP_FIELD = 'ufCrm25_1784563253861';
const BOT_UNIT_TYPE_FIELD = 'ufCrm25_1782616418179';

function bot_bx_item(array $response): array {
    if (array_key_exists('ok', $response)) {
        if (($response['ok'] ?? false) !== true) throw new RuntimeException('bitrix_read_failed');
        $response = $response['result'] ?? [];
    }
    if (isset($response['item']) && is_array($response['item'])) $response = $response['item'];
    if (!is_array($response) || !$response) throw new RuntimeException('bitrix_read_failed');
    return $response;
}

function bot_live_stage_available(string $stageId, array $profile, array $stageNames = []): bool {
    $matrix = bot_project_matrix($profile);
    $expected = trim((string)($matrix['bitrix']['etapa_disponible'] ?? ''));
    if ($expected !== '') return hash_equals($expected, $stageId);
    return bot_contract_plain((string)($stageNames[$stageId] ?? '')) === 'disponible';
}

function bot_standard_payment_exact(float $pvp, array $profile, int $now): array {
    $payment = $profile['standard_payment'] ?? [];
    $months = isset($payment['installment_months']) && $payment['installment_months'] !== null
        ? (int)$payment['installment_months']
        : bot_months_until($profile['delivery']['date'] ?? null, $now);
    $separation = $payment['separation'] ?? null;
    $signingPercent = $payment['signing_percent'] ?? null;
    $installmentPercent = $payment['installment_percent'] ?? null;
    $extraPercent = $payment['extraordinary_percent'] ?? null;
    $extraCount = $payment['extraordinary_count'] ?? null;
    $deliveryPercent = $payment['delivery_percent'] ?? null;
    if ($extraPercent !== null && $extraCount === null && $months) {
        $extraCount = (int)ceil($months / 12);
    }
    $available = $separation !== null && $signingPercent !== null
        && $installmentPercent !== null && $months && $deliveryPercent !== null;
    return [
        'available'=>$available,
        'separation'=>$available ? round((float)$separation, 2) : null,
        'signing'=>$available ? round(max(0, $pvp * (float)$signingPercent / 100 - (float)$separation), 2) : null,
        'monthly'=>$available ? round($pvp * (float)$installmentPercent / 100 / $months, 2) : null,
        'installment_months'=>$available ? $months : null,
        'extraordinary'=>($available && $extraPercent !== null && $extraCount)
            ? round($pvp * (float)$extraPercent / 100 / (int)$extraCount, 2) : null,
        'extraordinary_count'=>($available && $extraPercent !== null) ? ($extraCount ? (int)$extraCount : null) : null,
        'delivery_balance'=>$available ? round($pvp * (float)$deliveryPercent / 100, 2) : null,
        'currency'=>'USD',
        'disclaimer'=>'Plan referencial sujeto a validación comercial',
    ];
}

function bot_live_candidate(
    array $candidate,
    array $live,
    array $request,
    array $profile,
    int $now,
    array $stageNames = []
): ?array {
    if ((int)($live['id'] ?? 0) !== (int)$candidate['id']) return null;
    if ((int)($live['categoryId'] ?? 0) !== (int)$profile['category_id']) return null;
    if ((int)($live['parentId2'] ?? 0) !== 0) return null;
    if (!bot_live_stage_available((string)($live['stageId'] ?? ''), $profile, $stageNames)) return null;

    $liveCode = strtoupper(trim(explode('(', (string)($live['title'] ?? ''))[0]));
    if ($liveCode === '' || !hash_equals(strtoupper((string)$candidate['code']), $liveCode)) return null;
    $unit = [
        'id'=>(int)$live['id'], 'codigo'=>$liveCode, 'cat'=>(string)$live['categoryId'],
        'stage'=>'DISPONIBLE', 'dealId'=>(int)($live['parentId2'] ?? 0),
        'm2'=>$live[BOT_UNIT_M2_FIELD] ?? null, 'pvp'=>$live[BOT_UNIT_PVP_FIELD] ?? null,
        'tipo'=>$live[BOT_UNIT_TYPE_FIELD] ?? null,
        'torre'=>$candidate['tower'] ?? '', 'piso'=>$candidate['floor'] ?? '',
    ];
    if (!bot_unit_passes_hard_filters($unit, $request, $profile)) return null;
    $fresh = bot_candidate_from_unit($unit, $request, $profile, $now);
    $delivery = $profile['delivery'] ?? ['mode'=>null,'date'=>null,'months'=>null];
    return [
        'number'=>0,
        'code'=>$fresh['code'],
        'project'=>$fresh['project'],
        'category_id'=>$fresh['category_id'],
        'segment'=>$request['segment'] ?? null,
        'asset_type'=>$fresh['asset_type'],
        'square_meters'=>$fresh['square_meters'],
        'pvp'=>$fresh['pvp'],
        'currency'=>'USD',
        'delivery'=>[
            'mode'=>$delivery['mode'] ?? null,
            'date'=>$delivery['date'] ?? null,
            'months'=>$delivery['months'] ?? null,
        ],
        'standard_payment'=>bot_standard_payment_exact($fresh['pvp'], $profile, $now),
        'fit'=>[
            'score'=>$fresh['score']['total'],
            'reason_codes'=>$fresh['score']['reason_codes'],
        ],
        'verified_at'=>gmdate(DateTimeInterface::ATOM, $now),
        'visual'=>['page'=>null,'coordinate_key'=>$fresh['code']],
    ];
}

function bot_validate_finalists(
    array $candidates,
    array $request,
    array $profile,
    callable $bx,
    int $now,
    array $stageNames = []
): array {
    $options = [];
    $errors = 0;
    foreach ($candidates as $candidate) {
        if (count($options) >= (int)$request['max_options']) break;
        try {
            $response = $bx('crm.item.get', [
                'entityTypeId'=>1072,
                'id'=>(int)$candidate['id'],
            ]);
            $live = bot_bx_item(is_array($response) ? $response : []);
            $option = bot_live_candidate($candidate, $live, $request, $profile, $now, $stageNames);
            if ($option === null) continue;
            $option['number'] = count($options) + 1;
            $options[] = $option;
        } catch (Throwable) {
            $errors++;
        }
    }
    return [
        'options'=>$options,
        'validated_at'=>gmdate(DateTimeInterface::ATOM, $now),
        'read_errors'=>$errors,
    ];
}

