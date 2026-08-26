<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__) . '/api/private/bot/v1/commercial-catalog.php';

function commercial_catalog_headers(string $body, int $now, string $secret): array {
    return [
        'content-type' => 'application/json; charset=utf-8',
        'x-galjosa-timestamp' => (string)$now,
        'x-galjosa-signature' => hash_hmac('sha256', $now . "\n" . $body, $secret),
    ];
}

function commercial_catalog_find_row(array $body, callable $predicate, string $name): array {
    foreach ($body['projects'] as $project) {
        foreach ($project['families'] as $family) {
            foreach ($family['offers'] as $row) if ($predicate($row)) return $row;
        }
    }
    throw new RuntimeException("No se encontró: $name");
}

$now = 1787752800;
$secret = 'commercial-catalog-test-secret-at-least-32-bytes';
$body = '{}';
$cache = [
    'built' => $now - 120,
    'units' => [
        ['id'=>1,'codigo'=>'G-2-2','cat'=>'39','stage'=>'DISPONIBLE','dealId'=>0,'m2'=>'106','pvp'=>'198330|USD','tipo'=>1793],
        ['id'=>2,'codigo'=>'B-2-2','cat'=>'39','stage'=>'DISPONIBLE','dealId'=>0,'m2'=>'75','pvp'=>'140375|USD','tipo'=>1793],
        ['id'=>3,'codigo'=>'H-2-2','cat'=>'39','stage'=>'DISPONIBLE','dealId'=>999,'m2'=>'106','pvp'=>'198330|USD','tipo'=>1793],
        ['id'=>4,'codigo'=>'E-2-2','cat'=>'33','stage'=>'DISPONIBLE','dealId'=>0,'m2'=>'31','pvp'=>'70000|USD','tipo'=>1793],
        ['id'=>5,'codigo'=>'E-2-3','cat'=>'33','stage'=>'DISPONIBLE','dealId'=>0,'m2'=>'31','pvp'=>'71000|USD','tipo'=>1793],
        ['id'=>6,'codigo'=>'E-2-4','cat'=>'33','stage'=>'DISPONIBLE','dealId'=>0,'m2'=>'31','pvp'=>'72000|USD','tipo'=>1793],
    ],
];
$env = [
    'BOT_INVENTORY_API_ENABLED' => '1',
    'BOT_INVENTORY_SHARED_SECRET' => $secret,
];

$unsigned = bot_commercial_catalog_http('POST', $body, ['content-type'=>'application/json'], $env, $cache, dirname(__DIR__) . '/matrices', $now);
test_same(401, $unsigned['status'], 'commercial catalog rejects unsigned requests');

$response = bot_commercial_catalog_http('POST', $body, commercial_catalog_headers($body, $now, $secret), $env, $cache, dirname(__DIR__) . '/matrices', $now);
test_same(200, $response['status'], 'commercial catalog accepts signed requests');
test_same('bot-commercial-catalog-v1', $response['body']['schema_version'], 'commercial catalog version is explicit');
test_same(120, $response['body']['source']['catalog_age_seconds'], 'commercial catalog reports source age');

$threeBedroom = commercial_catalog_find_row(
    $response['body'],
    fn(array $row): bool => $row['project'] === 'Noral Apartments' && $row['bedrooms'] === 3,
    'Apartments de tres dormitorios'
);
test_same(['G-2-2'], $threeBedroom['codes'], 'occupied inventory is excluded from catalog');

$plazaThree = commercial_catalog_find_row(
    $response['body'],
    fn(array $row): bool => $row['project'] === 'Noral Plaza' && $row['bedrooms'] === 3,
    'Plaza de tres dormitorios'
);
test_same(193000.0, $plazaThree['price_from'], 'catalog exposes current Plaza 3-bedroom price');

