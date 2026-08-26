<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/bot-quote-contract.php';
require_once dirname(__DIR__) . '/lib/bot-quote-service.php';

$now = 1787640600;
$request = bot_quote_normalize_request([
    'request_id'=>'22222222-2222-4222-8222-222222222222',
    'project'=>'Noral Apartments','unit_code'=>'A-1-1','deal_id'=>10,
    'payment'=>['installments'=>44,'modality'=>'estandar','financing_percent'=>40,'start_month'=>'2026-09'],
]);
$catalog = ['built'=>$now-30,'units'=>[
    ['id'=>1,'codigo'=>'A-1-1','cat'=>'39','stage'=>'DISPONIBLE','dealId'=>0,
        'm2'=>'75','pvp'=>'135000|USD','tipo'=>1793,'torre'=>'A','piso'=>'1'],
]];
$profile = bot_commercial_profile('Noral Apartments');
$preview = bot_quote_preview($request, $catalog, $profile, $now);
test_same('preview', $preview['status'], 'available live unit produces quote preview');
test_same(135000.0, $preview['plan']['total'], 'quote uses exact catalog PVP');
$sum = $preview['plan']['separation'] + $preview['plan']['signing']
    + $preview['plan']['installment_total'] + $preview['plan']['delivery_balance'];
test_same(135000.0, round($sum, 2), 'payment identity closes exactly');

$final = bot_quote_finalize($preview, $catalog['units'][0], [
    'PUBLIC_BASE_URL'=>'https://inventario.example.test',
    'OUTBOUND_TOKEN'=>'quote-outbound-test-secret',
], $now);
test_same('finalized', $final['status'], 'unchanged quote finalizes');
test_same(true, str_starts_with($final['document']['url'], 'https://inventario.example.test/cotizar.php?'), 'document uses the existing cotizador');

$changed = $catalog['units'][0];
$changed['pvp'] = '136000|USD';
$conflict = bot_quote_finalize($preview, $changed, [
    'PUBLIC_BASE_URL'=>'https://inventario.example.test','OUTBOUND_TOKEN'=>'quote-outbound-test-secret',
], $now);
test_same('conflict', $conflict['status'], 'price change blocks finalization');

