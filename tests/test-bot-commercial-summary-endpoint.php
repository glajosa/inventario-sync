<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/bot-commercial-summary.php';
require_once dirname(__DIR__) . '/api/private/bot/v1/commercial-summary.php';

$now = 1789399800;
$dir = sys_get_temp_dir() . '/bot-commercial-summary-' . bin2hex(random_bytes(6));
if (!mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('cannot create fixture dir');

try {
    $catalog = [
        'built'=>$now - 30,
        'units'=>[
            ['id'=>1,'codigo'=>'A-1-1','cat'=>'39','stage'=>'DISPONIBLE','dealId'=>0,
                'pvp'=>'135000|USD','m2'=>'75','tipo'=>1793],
            ['id'=>2,'codigo'=>'A-1-2','cat'=>'39','stage'=>'DISPONIBLE','dealId'=>0,
                'pvp'=>'134000|USD','m2'=>'75','tipo'=>1793],
            ['id'=>3,'codigo'=>'C-1-1','cat'=>'47','stage'=>'DISPONIBLE','dealId'=>0,
                'pvp'=>'92000|USD','m2'=>'48','tipo'=>1793],
            ['id'=>4,'codigo'=>'C-1-2','cat'=>'47','stage'=>'VENDIDO','dealId'=>0,
                'pvp'=>'80000|USD','m2'=>'48','tipo'=>1793],
        ],
    ];
    file_put_contents($dir . '/selector_cache.json', json_encode($catalog, JSON_THROW_ON_ERROR));
    $env = [
        'BOT_INVENTORY_SHARED_SECRET'=>'bot-inventory-test-secret-at-least-32-bytes',
        'BOT_INVENTORY_API_ENABLED'=>'1',
        'DATA_DIR'=>$dir,
    ];
    $body = json_encode(['request_id'=>'11111111-1111-4111-8111-111111111111'], JSON_THROW_ON_ERROR);
    $headers = [
        'content-type'=>'application/json; charset=utf-8',
        'x-galjosa-timestamp'=>(string)$now,
        'x-galjosa-signature'=>hash_hmac('sha256', $now . "\n" . $body, $env['BOT_INVENTORY_SHARED_SECRET']),
    ];

    $response = bot_commercial_summary_http('POST', $body, $headers, $env, $now);
    test_same(200, $response['status'], 'commercial summary succeeds');
    test_same('bot-commercial-summary-v1', $response['body']['schema_version'], 'summary schema is explicit');
    test_same(134000.0, $response['body']['projects']['Noral Apartments']['price_from'], 'summary uses lowest available PVP');
    test_same(240, $response['body']['projects']['Galero Torre C']['installment_months'], 'summary includes profile months');
    test_same(2, $response['body']['projects']['Noral Apartments']['available_units'], 'summary counts available units');
    test_same('no-store', $response['headers']['Cache-Control'] ?? null, 'summary is never cached');

    $stale = $catalog;
    $stale['built'] = $now - 3601;
    file_put_contents($dir . '/selector_cache.json', json_encode($stale, JSON_THROW_ON_ERROR));
    test_same(503, bot_commercial_summary_http('POST', $body, $headers, $env, $now)['status'], 'stale catalog never feeds weekly knowledge');
} finally {
    @unlink($dir . '/selector_cache.json');
    @rmdir($dir);
}
