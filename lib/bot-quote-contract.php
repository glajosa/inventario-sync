<?php
declare(strict_types=1);

require_once __DIR__ . '/bot-recommendation-contract.php';

const BOT_QUOTE_EDITABLE_FIELDS = [
    'installments', 'modality', 'monthly_comfort', 'financing_percent', 'start_month',
];

function bot_quote_normalize_request(array $input): array {
    $allowed = ['request_id','project','unit_code','customer_name','deal_id','payment','preview_quote_id'];
    if (array_diff(array_keys($input), $allowed)) throw new InvalidArgumentException('unknown_field');
    $requestId = strtolower(trim((string)($input['request_id'] ?? '')));
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $requestId)) {
        throw new InvalidArgumentException('invalid_request_id');
    }
    $project = bot_canonical_project((string)($input['project'] ?? ''));
    $code = strtoupper(trim((string)($input['unit_code'] ?? '')));
    if ($project === '' || !preg_match('/^[A-Z0-9]+(?:[-.][A-Z0-9]+)+$/D', $code)) {
        throw new InvalidArgumentException('invalid_quote_target');
    }
    $payment = $input['payment'] ?? [];
    if (!is_array($payment) || array_is_list($payment) || array_diff(array_keys($payment), BOT_QUOTE_EDITABLE_FIELDS)) {
        throw new InvalidArgumentException('invalid_payment');
    }
    $installments = filter_var($payment['installments'] ?? 0, FILTER_VALIDATE_INT);
    if ($installments === false || $installments < 0 || $installments > 120) {
        throw new InvalidArgumentException('invalid_installments');
    }
    $modality = bot_contract_plain((string)($payment['modality'] ?? 'estandar'));
    if (!in_array($modality, ['estandar','iguales'], true)) throw new InvalidArgumentException('invalid_modality');
    $startMonth = trim((string)($payment['start_month'] ?? ''));
    if ($startMonth !== '' && !preg_match('/^20\d{2}-(?:0[1-9]|1[0-2])$/D', $startMonth)) {
        throw new InvalidArgumentException('invalid_start_month');
    }
    $financing = bot_nullable_money($payment['financing_percent'] ?? null);
    if ($financing !== null && ($financing < 0 || $financing > 100)) {
        throw new InvalidArgumentException('invalid_financing_percent');
    }
    $dealId = filter_var($input['deal_id'] ?? 0, FILTER_VALIDATE_INT);
    if ($dealId === false || $dealId < 0) throw new InvalidArgumentException('invalid_deal');
    return [
        'request_id'=>$requestId,
        'project'=>$project,
        'unit_code'=>$code,
        'customer_name'=>bot_nullable_string($input['customer_name'] ?? null, 160),
        'deal_id'=>$dealId,
        'preview_quote_id'=>bot_nullable_string($input['preview_quote_id'] ?? null, 80),
        'payment'=>[
            'installments'=>$installments,
            'modality'=>$modality,
            'monthly_comfort'=>bot_nullable_money($payment['monthly_comfort'] ?? null),
            'financing_percent'=>$financing,
            'start_month'=>$startMonth,
        ],
    ];
}

function bot_quote_validate_numbers(mixed $value): void {
    if (is_float($value) && (!is_finite($value) || $value < 0)) {
        throw new InvalidArgumentException('invalid_number');
    }
    if (is_int($value) && $value < 0) throw new InvalidArgumentException('invalid_number');
    if (is_array($value)) foreach ($value as $item) bot_quote_validate_numbers($item);
}

function bot_quote_validate_response(array $response): void {
    if (($response['schema_version'] ?? '') !== 'bot-quote-v1') throw new InvalidArgumentException('invalid_schema');
    if (!in_array($response['status'] ?? null, ['preview','finalized','conflict','unavailable'], true)) {
        throw new InvalidArgumentException('invalid_status');
    }
    foreach (['request_id','quote_id'] as $key) {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', (string)($response[$key] ?? ''))) {
            throw new InvalidArgumentException('invalid_id');
        }
    }
    $unit = $response['unit'] ?? null;
    $plan = $response['plan'] ?? null;
    if (!is_array($unit) || !is_array($plan) || ($unit['currency'] ?? '') !== 'USD'
        || ($plan['currency'] ?? '') !== 'USD' || (float)($unit['pvp'] ?? 0) <= 0
        || (float)($unit['square_meters'] ?? 0) <= 0) {
        throw new InvalidArgumentException('invalid_quote');
    }
    $editable = $response['editable_fields'] ?? null;
    if (!is_array($editable) || array_diff($editable, BOT_QUOTE_EDITABLE_FIELDS)) {
        throw new InvalidArgumentException('invalid_editable_fields');
    }
    bot_quote_validate_numbers($response);
}
