<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/bot-quote-contract.php';

$request = bot_quote_normalize_request([
    'request_id'=>'22222222-2222-4222-8222-222222222222',
    'project'=>'Noral Apartments',
    'unit_code'=>'A-1-1',
    'customer_name'=>'Ana',
    'deal_id'=>10,
    'payment'=>['installments'=>44,'modality'=>'estandar','financing_percent'=>40,'start_month'=>'2026-09'],
]);
test_same('A-1-1', $request['unit_code'], 'unit code is canonical');
test_same(44, $request['payment']['installments'], 'installments are normalized');

test_throws(fn()=>bot_quote_normalize_request([
    'request_id'=>'22222222-2222-4222-8222-222222222222','project'=>'Noral Apartments',
    'unit_code'=>'A-1-1','unknown'=>'x',
]), InvalidArgumentException::class, 'unknown critical request field is rejected');
test_throws(fn()=>bot_quote_normalize_request([
    'request_id'=>'22222222-2222-4222-8222-222222222222','project'=>'Noral Apartments',
    'unit_code'=>'A-1-1','payment'=>['monthly_comfort'=>-1],
]), InvalidArgumentException::class, 'negative money is rejected');

$fixture = json_decode((string)file_get_contents(__DIR__.'/fixtures/bot-quote-v1.json'), true, 32, JSON_THROW_ON_ERROR);
bot_quote_validate_response($fixture);
$bad = $fixture;
$bad['plan']['monthly'] = INF;
test_throws(fn()=>bot_quote_validate_response($bad), InvalidArgumentException::class, 'non finite response is rejected');
