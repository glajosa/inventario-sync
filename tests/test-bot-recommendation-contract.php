<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/bot-recommendation-contract.php';

$valid = bot_recommendation_normalize_request([
    'request_id' => '11111111-1111-4111-8111-111111111111',
    'project' => 'Noral Plaza Suites',
    'project_enum' => '1625',
    'segment' => 'residencial',
    'asset_type' => 'suite',
    'bedrooms' => 1,
    'use' => 'inversion',
    'preferences' => ['balcon'],
    'max_options' => 3,
]);

test_same('Noral Plaza', $valid['project'], 'Plaza aliases stay in one project');
test_same(3, $valid['max_options'], 'maximum is three');
test_same(['balcon'], $valid['preferences'], 'preferences are normalized');

test_throws(
    fn() => bot_recommendation_normalize_request(['request_id' => 'bad']),
    InvalidArgumentException::class,
    'invalid request is rejected'
);
test_throws(
    fn() => bot_recommendation_normalize_request([
        'request_id' => '11111111-1111-4111-8111-111111111111',
        'project' => 'Noral Apartments',
        'max_options' => 4,
    ]),
    InvalidArgumentException::class,
    'more than three options are rejected'
);
test_throws(
    fn() => bot_recommendation_normalize_request([
        'request_id' => '11111111-1111-4111-8111-111111111111',
        'project' => 'Noral Apartments',
        'segment' => 'industrial',
    ]),
    InvalidArgumentException::class,
    'unknown segment is rejected'
);

$profiles = bot_commercial_profiles();
test_same(33, $profiles['Noral Plaza']['category_id'], 'Noral Plaza category is canonical');
test_same('2031-02', $profiles['Noral Plaza']['delivery']['date'], 'Plaza delivery comes from the current matrix');
test_same(39, bot_commercial_profile('Noral Apartments')['category_id'], 'Apartment profile resolves');

$fixture = json_decode(
    (string)file_get_contents(__DIR__ . '/fixtures/bot-recommendation-v1.json'),
    true,
    32,
    JSON_THROW_ON_ERROR
);
bot_recommendation_validate_response($fixture);
test_same('verified', $fixture['status'], 'frozen response is a verified example');
test_same(2, count($fixture['options']), 'frozen response keeps two exact options');

$tooMany = $fixture;
$tooMany['options'][] = $fixture['options'][0];
$tooMany['options'][] = $fixture['options'][0];
test_throws(
    fn() => bot_recommendation_validate_response($tooMany),
    InvalidArgumentException::class,
    'response cannot expose more than three options'
);

