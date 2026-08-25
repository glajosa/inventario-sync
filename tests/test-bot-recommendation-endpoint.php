<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/bot-recommendation-contract.php';
require_once dirname(__DIR__) . '/lib/bot-recommendation-service.php';
require_once dirname(__DIR__) . '/lib/bot-live-inventory.php';
require_once dirname(__DIR__) . '/api/private/bot/v1/recommendations.php';

$now = 1787640600;
$request = bot_recommendation_normalize_request([
    'request_id'=>'11111111-1111-4111-8111-111111111111',
    'project'=>'Noral Apartments',
    'project_enum'=>'516',
    'asset_type'=>'departamento',
    'max_options'=>1,
]);
$profile = bot_commercial_profile('Noral Apartments');
$candidates = [
    ['id'=>1,'code'=>'A-1-1','project'=>'Noral Apartments','category_id'=>39,'asset_type'=>'departamento',
        'square_meters'=>75.0,'pvp'=>135000.0,'currency'=>'USD','tower'=>'A','floor'=>'1','position'=>1,
        'type_id'=>1793,'score'=>['total'=>85,'components'=>[],'reason_codes'=>['origin_project']]],
    ['id'=>2,'code'=>'A-1-2','project'=>'Noral Apartments','category_id'=>39,'asset_type'=>'departamento',
        'square_meters'=>75.0,'pvp'=>134000.0,'currency'=>'USD','tower'=>'A','floor'=>'1','position'=>2,
        'type_id'=>1793,'score'=>['total'=>84,'components'=>[],'reason_codes'=>['origin_project']]],
];
$calls = [];
$bx = function(string $method, array $params) use (&$calls): array {
    $calls[] = [$method, $params];
    if ((int)$params['id'] === 1) return ['ok'=>true,'result'=>['item'=>[
        'id'=>1,'title'=>'A-1-1','categoryId'=>39,'stageId'=>'DT1072_39:PREPARATION','parentId2'=>0,
        'ufCrm25_1782615822688'=>'75','ufCrm25_1784563253861'=>'135000|USD',
        'ufCrm25_1782616418179'=>1793,
    ]]];
    return ['ok'=>true,'result'=>['item'=>[
        'id'=>2,'title'=>'A-1-2','categoryId'=>39,'stageId'=>'DT1072_39:SUCCESS','parentId2'=>900,
        'ufCrm25_1782615822688'=>'75','ufCrm25_1784563253861'=>'134000|USD',
        'ufCrm25_1782616418179'=>1793,
    ]]];
};
$validated = bot_validate_finalists($candidates, $request, $profile, $bx, $now);
test_same(['A-1-1'], array_column($validated['options'], 'code'), 'changed unit is removed');
test_same(1, count($calls), 'validation stops after filling the requested maximum');
test_same(true, array_reduce($calls, fn(bool $ok, array $call): bool => $ok && $call[0] === 'crm.item.get', true), 'live validation is read only');
test_same('2030-04', $validated['options'][0]['delivery']['date'], 'delivery is sourced from profile');
test_same(44, $validated['options'][0]['standard_payment']['installment_months'], 'payment months derive from delivery date');
test_same('A', $validated['options'][0]['attributes']['tower'] ?? null, 'final option includes the verified tower');
test_same('1', $validated['options'][0]['attributes']['floor'] ?? null, 'final option includes the verified floor');
test_same('Esquinero 3', $validated['options'][0]['attributes']['position'] ?? null, 'final option includes the official position label');

$requestTwo = $request;
$requestTwo['max_options'] = 2;
$validatedTwo = bot_validate_finalists($candidates, $requestTwo, $profile, $bx, $now);
test_same(['A-1-1'], array_column($validatedTwo['options'], 'code'), 'unavailable finalist stays out');
test_same(3, count($calls), 'each needed finalist is reread live');

function bot_endpoint_temp_dir(): string {
    $dir = sys_get_temp_dir() . '/bot-inventory-' . bin2hex(random_bytes(6));
    if (!mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('cannot create fixture dir');
    return $dir;
}

function bot_endpoint_cleanup(string $dir): void {
    foreach (glob($dir . '/*') ?: [] as $file) @unlink($file);
    @rmdir($dir);
}

function bot_endpoint_headers(string $body, int $now, string $secret='bot-inventory-test-secret-at-least-32-bytes'): array {
    return [
        'content-type'=>'application/json; charset=utf-8',
        'x-galjosa-timestamp'=>(string)$now,
        'x-galjosa-signature'=>hash_hmac('sha256', $now . "\n" . $body, $secret),
    ];
}

$dir = bot_endpoint_temp_dir();
try {
    $catalog = ['built'=>$now - 30,'units'=>[
        ['id'=>1,'codigo'=>'A-1-1','cat'=>'39','stage'=>'DISPONIBLE','dealId'=>0,
            'm2'=>'75','pvp'=>'135000|USD','tipo'=>1793,'torre'=>'A','piso'=>'1'],
    ]];
    file_put_contents($dir . '/selector_cache.json', json_encode($catalog, JSON_THROW_ON_ERROR));
    $env = [
        'BOT_INVENTORY_SHARED_SECRET'=>'bot-inventory-test-secret-at-least-32-bytes',
        'BOT_INVENTORY_API_ENABLED'=>'1',
        'DATA_DIR'=>$dir,
    ];
    $input = [
        'request_id'=>'11111111-1111-4111-8111-111111111111',
        'project'=>'Noral Apartments','project_enum'=>'516','asset_type'=>'departamento','max_options'=>1,
    ];
    $body = json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $fakeCalls = [];
    $fake = function(string $method, array $params) use (&$fakeCalls): array {
        $fakeCalls[] = [$method,$params];
        return ['ok'=>true,'result'=>['item'=>[
            'id'=>1,'title'=>'A-1-1','categoryId'=>39,'stageId'=>'DT1072_39:PREPARATION','parentId2'=>0,
            'ufCrm25_1782615822688'=>'75','ufCrm25_1784563253861'=>'135000|USD',
            'ufCrm25_1782616418179'=>1793,
        ]]];
    };

    $unsigned = bot_recommendations_http('POST',$body,['content-type'=>'application/json'],$env,$fake,$now);
    test_same(401, $unsigned['status'], 'unsigned request is rejected');
    test_same([], $fakeCalls, 'unsigned request performs no Bitrix call');

    $badJson = '{"request_id":';
    test_same(400, bot_recommendations_http('POST',$badJson,bot_endpoint_headers($badJson,$now),$env,$fake,$now)['status'], 'malformed JSON is rejected');
    test_same(413, bot_recommendations_http('POST',str_repeat('x',65537),bot_endpoint_headers(str_repeat('x',65537),$now),$env,$fake,$now)['status'], 'oversized body is rejected');

    $response = bot_recommendations_http('POST',$body,bot_endpoint_headers($body,$now),$env,$fake,$now);
    test_same(200, $response['status'], 'verified request succeeds');
    test_same('verified', $response['body']['status'], 'verified status is explicit');
    test_same('A-1-1', $response['body']['options'][0]['code'], 'exact live code is returned');
    test_same('no-store', $response['headers']['Cache-Control'] ?? null, 'responses are never cached');
    test_same(false, isset($response['headers']['Access-Control-Allow-Origin']), 'private endpoint has no CORS');
    test_same(true, array_reduce($fakeCalls, fn(bool $ok, array $call): bool => $ok && !str_contains($call[0], 'update'), true), 'endpoint never writes to Bitrix');

    $disabled = $env;
    $disabled['BOT_INVENTORY_API_ENABLED'] = '0';
    test_same(503, bot_recommendations_http('POST',$body,bot_endpoint_headers($body,$now),$disabled,$fake,$now)['status'], 'feature can be disabled instantly');
} finally {
    bot_endpoint_cleanup($dir);
}

