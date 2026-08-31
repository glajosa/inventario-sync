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
test_same('2031-04', $profiles['Noral Plaza']['delivery']['date'], 'Plaza delivery comes from the current matrix');
test_same(39, bot_commercial_profile('Noral Apartments')['category_id'], 'Apartment profile resolves');

/* CANDADO: la fecha de entrega vive en la MATRIZ del proyecto. commercial-projects.json
   la repite para el bot, y el 31-ago-2026 esas dos copias llevaban dos meses separadas
   (2031-02 aqui, 2031-04 en el cotizador): la lista de precios decia 54 meses y el
   cotizador 56, al mismo cliente el mismo dia. Esta prueba convierte esa divergencia
   silenciosa en una prueba roja. */
require_once dirname(__DIR__) . '/cotizarlib.php';
foreach (bot_commercial_profiles() as $nombre => $perfil) {
    $cat = (int)($perfil['category_id'] ?? 0);
    $delBot = ($perfil['delivery']['date'] ?? null);
    $e = cot_entrega($cat);
    $delMatriz = $e ? sprintf('%04d-%02d', $e['y'], $e['m']) : null;
    // Torre D no tiene matriz: su fecha vive en el codigo y el bot no la declara.
    if ($cat === 51) continue;
    test_same($delMatriz, $delBot,
        "la entrega de $nombre debe salir de la matriz, no de una copia vieja");
}

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

$badAttributes = $fixture;
$badAttributes['options'][0]['attributes'] = ['bedrooms'=>'tres'];
test_throws(
    fn() => bot_recommendation_validate_response($badAttributes),
    InvalidArgumentException::class,
    'commercial attributes are strictly validated'
);

