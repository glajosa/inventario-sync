<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/bot-recommendation-contract.php';
require_once dirname(__DIR__) . '/lib/bot-recommendation-service.php';

$now = 1787640300;
$catalog = ['built' => $now - 300, 'units' => [
    ['id'=>1,'codigo'=>'A-1-1','cat'=>'39','stage'=>'DISPONIBLE','dealId'=>0,'m2'=>'75','pvp'=>'135000|USD','tipo'=>1793,'torre'=>'A','piso'=>'1'],
    ['id'=>2,'codigo'=>'A-1-2','cat'=>'39','stage'=>'RESERVADO','dealId'=>900,'m2'=>'75','pvp'=>'134000|USD','tipo'=>1793,'torre'=>'A','piso'=>'1'],
    ['id'=>3,'codigo'=>'J-1-1','cat'=>'39','stage'=>'DISPONIBLE','dealId'=>0,'m2'=>'85','pvp'=>'140000|USD','tipo'=>1793,'torre'=>'J','piso'=>'1'],
]];
$request = bot_recommendation_normalize_request([
    'request_id'=>'11111111-1111-4111-8111-111111111111',
    'project'=>'Noral Apartments',
    'project_enum'=>'516',
    'asset_type'=>'Departamento',
    'max_options'=>3,
]);
$profile = bot_commercial_profile('Noral Apartments');
$result = bot_recommendation_rank($request, $catalog, $profile, $now);
test_same(['A-1-1'], array_column($result['candidates'], 'code'), 'reserved and unreleased are excluded');
test_same('normal', $result['freshness'], 'five minute cache is normal');
test_same('candidates', $result['status'], 'eligible inventory produces candidates');

$limited = $request;
$limited['explicit_total_limit'] = 134999.99;
$none = bot_recommendation_rank($limited, $catalog, $profile, $now);
test_same('no_match', $none['status'], 'explicit total limit is a hard filter');

$wrongType = $request;
$wrongType['asset_type'] = 'local';
test_same([], bot_recommendation_rank($wrongType, $catalog, $profile, $now)['candidates'], 'asset type is a hard filter');

$badMoney = $catalog;
$badMoney['units'][0]['pvp'] = '';
test_same([], bot_recommendation_rank($request, $badMoney, $profile, $now)['candidates'], 'missing PVP is never recommended');

$degraded = $catalog;
$degraded['built'] = $now - 901;
test_same('degraded', bot_recommendation_rank($request, $degraded, $profile, $now)['freshness'], 'older than fifteen minutes is degraded');

$stale = $catalog;
$stale['built'] = $now - 3601;
test_same('stale', bot_recommendation_rank($request, $stale, $profile, $now)['status'], 'older than one hour is rejected');
$partial = $catalog;
$partial['parcial'] = true;
test_same('stale', bot_recommendation_rank($request, $partial, $profile, $now)['status'], 'partial cache is rejected');

$many = ['built'=>$now - 60, 'units'=>[]];
foreach ([
    [11,'A-1-1',135000,75], [12,'A-1-2',136000,75], [13,'B-1-1',137000,82],
    [14,'C-1-1',138000,85], [15,'D-1-1',139000,88],
] as [$id,$code,$price,$m2]) {
    $many['units'][] = ['id'=>$id,'codigo'=>$code,'cat'=>'39','stage'=>'DISPONIBLE','dealId'=>0,
        'm2'=>(string)$m2,'pvp'=>$price.'|USD','tipo'=>1793,'torre'=>substr($code,0,1),'piso'=>'1'];
}
$top = bot_recommendation_rank($request, $many, $profile, $now);
test_same(3, count($top['candidates']), 'at most three candidates are returned');
test_same(3, count(array_unique(array_column($top['candidates'], 'tower'))), 'useful tower diversity is preserved');
test_same(true, isset($top['candidates'][0]['score']['components']), 'score is auditable');
test_same(true, is_array($top['candidates'][0]['score']['reason_codes']), 'reasons are structured codes');

$noralAttributes = bot_unit_commercial_attributes($top['candidates'][0], $profile);
test_same('A', $noralAttributes['tower'] ?? null, 'commercial attributes expose the real tower');
test_same('1', $noralAttributes['floor'] ?? null, 'commercial attributes expose the real floor');
test_same('Esquinero 3', $noralAttributes['position'] ?? null, 'commercial attributes resolve the official position label');

$galeroD = bot_commercial_profile('Galero Torre D');
$galeroCandidate = [
    'code'=>'D-2-2', 'tower'=>'D', 'floor'=>'2', 'position'=>2,
];
$galeroAttributes = bot_unit_commercial_attributes($galeroCandidate, $galeroD);
test_same('Pos. 2 · Medianero', $galeroAttributes['position'] ?? null, 'position label is read from the project matrix');
test_same(3, $galeroAttributes['bedrooms'] ?? null, 'explicit bedroom count is read from the official matrix');

