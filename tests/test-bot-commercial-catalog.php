<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../matrizlib.php';
require_once __DIR__ . '/../listalib.php';
require_once __DIR__ . '/../lib/bot-commercial-catalog.php';

function bot_catalog_matrix(int $categoryId): array {
    return json_decode(
        (string)file_get_contents(__DIR__ . "/../matrices/proyecto_$categoryId.json"),
        true,
        flags: JSON_THROW_ON_ERROR
    );
}

function bot_catalog_unit(string $code, float $m2, float $pvp, int $type=1793): array {
    return [
        'id' => abs(crc32($code)),
        'etapa' => 'DISPONIBLE',
        'pvp' => $pvp,
        'm2' => $m2,
        'tipo' => $type,
        'cod' => $code,
    ];
}

function bot_catalog_find(array $rows, callable $predicate, string $name): array {
    foreach ($rows as $row) if ($predicate($row)) return $row;
    throw new RuntimeException("No se encontró la fila esperada: $name");
}

$apartments = bot_catalog_matrix(39);
$apartmentRows = bot_commercial_price_family($apartments, 1793, [
    'G-2-2' => bot_catalog_unit('G-2-2', 106.0, 198330.0),
    'B-2-2' => bot_catalog_unit('B-2-2', 75.0, 140375.0),
]);

$threeBedroomApartment = bot_catalog_find(
    $apartmentRows,
    fn(array $row): bool => in_array('G-2-2', $row['codes'], true),
    'Noral Apartments G de tres dormitorios'
);
test_same(3, $threeBedroomApartment['bedrooms'], 'Apartments G/H are classified as 3 bedrooms');
test_same(2, $threeBedroomApartment['parkings'], 'Apartments G/H include 2 parkings');
test_same('PA', $threeBedroomApartment['level'], 'Apartments identifies planta alta');
test_same(198330.0, $threeBedroomApartment['price_from'], 'Apartments uses current available PVP');

$twoBedroomApartment = bot_catalog_find(
    $apartmentRows,
    fn(array $row): bool => in_array('B-2-2', $row['codes'], true),
    'Noral Apartments B de dos dormitorios'
);
test_same(2, $twoBedroomApartment['bedrooms'], 'Apartments non G/H are classified as 2 bedrooms');

$plaza = bot_catalog_matrix(33);
$plazaRows = bot_commercial_price_family($plaza, 1793, [
    'E-2-2' => bot_catalog_unit('E-2-2', 31.0, 70000.0),
    'E-2-3' => bot_catalog_unit('E-2-3', 31.0, 71000.0),
    'E-2-4' => bot_catalog_unit('E-2-4', 31.0, 72000.0),
]);

$plazaThree = bot_catalog_find(
    $plazaRows,
    fn(array $row): bool => $row['bedrooms'] === 3 && $row['is_composite'] === true,
    'Noral Plaza de tres dormitorios por unión'
);
test_same(193000.0, $plazaThree['price_from'], 'Plaza 3-bedroom union applies discount once');
test_same(93.0, $plazaThree['m2_min'], 'Plaza 3-bedroom union sums the three areas');
test_same(2, $plazaThree['parkings'], 'Plaza 3-bedroom union includes 2 parkings');
test_same(['E-2-2', 'E-2-3', 'E-2-4'], $plazaThree['component_codes'], 'Plaza union keeps backing unit codes');

$torreD = bot_catalog_matrix(51);
$torreDRows = bot_commercial_price_family($torreD, 1793, [
    'D-2-2' => bot_catalog_unit('D2-2', 81.10, 145980.0),
    'D-2-3' => bot_catalog_unit('D2-3', 70.0, 126000.0),
]);
$torreDThree = bot_catalog_find($torreDRows, fn(array $row): bool => in_array('D2-2', $row['codes'], true), 'Torre D posición 2');
$torreDTwo = bot_catalog_find($torreDRows, fn(array $row): bool => in_array('D2-3', $row['codes'], true), 'Torre D posición 3');
test_same(3, $torreDThree['bedrooms'], 'Torre D positions 2 and 4 are 3 bedrooms');
test_same(2, $torreDTwo['bedrooms'], 'Torre D positions 1 and 3 are 2 bedrooms');

$suites = bot_catalog_matrix(53);
$suiteRows = bot_commercial_family($suites, 1797, [
    'S-1-5' => bot_catalog_unit('S1-5', 30.0, 66500.0, 1797),
    'S-3-8' => bot_catalog_unit('S3-8', 30.0, 65050.0, 1797),
]);
$suiteCorner = bot_catalog_find($suiteRows, fn(array $row): bool => in_array('S3-8', $row['codes'], true), 'Suite esquinera');
test_same(0, $suiteCorner['bedrooms'], 'Suites have no separated bedrooms');
test_same('Posiciones 4 y 8', $suiteCorner['position'], 'Suite position comes from the project matrix');
test_same('INMEDIATA', $suiteCorner['delivery'], 'Suites preserve immediate delivery');

$sunBay = bot_catalog_matrix(49);
$solarRows = bot_commercial_family($sunBay, 1795, [
    'B-1-15' => bot_catalog_unit('B-15', 144.0, 41960.0, 1795),
]);
test_same(1, count($solarRows), 'Sun Bay exposes available priced solares');
test_same(null, $solarRows[0]['bedrooms'], 'Sun Bay is never classified by bedrooms');
test_same('T1', $solarRows[0]['position'], 'Sun Bay exposes its live location tier');
test_same(41960.0, $solarRows[0]['price_from'], 'Sun Bay uses current available PVP');

$casas = bot_catalog_matrix(55);
$houseRows = bot_commercial_family($casas, 1799, [
    'A-1-2' => bot_catalog_unit('A-2', 150.0, 150000.0, 1799),
]);
test_same(4, count($houseRows), 'Galero Casas exposes the four valid house models');
$pelicano3 = bot_catalog_find($houseRows, fn(array $row): bool => $row['position'] === 'PELÍCANO 3', 'Pelícano 3');
test_same(3, $pelicano3['bedrooms'], 'Every Galero house has 3 bedrooms');
test_same(100.0, $pelicano3['m2_min'], 'House catalog exposes construction area');
test_same(1, $pelicano3['available_count'], 'House model counts compatible available solares');

$cacheDir = sys_get_temp_dir() . '/commercial-catalog-list-' . bin2hex(random_bytes(5));
mkdir($cacheDir, 0700, true);
$previousDataDir = getenv('DATA_DIR');
try {
    file_put_contents($cacheDir . '/selector_cache.json', json_encode(['units'=>[
        ['id'=>10,'codigo'=>'G-2-2','cat'=>'39','stage'=>'DISPONIBLE','dealId'=>0,'m2'=>'106','pvp'=>'198330|USD','tipo'=>1793],
        ['id'=>11,'codigo'=>'H-2-2','cat'=>'39','stage'=>'DISPONIBLE','dealId'=>777,'m2'=>'106','pvp'=>'198330|USD','tipo'=>1793],
    ]], JSON_THROW_ON_ERROR));
    putenv('DATA_DIR=' . $cacheDir);
    $visualUnits = mz_unidades_cache($apartments);
    test_same(['G-2-2'], array_keys($visualUnits), 'visual list also excludes units tied to a deal');
} finally {
    if ($previousDataDir === false) putenv('DATA_DIR'); else putenv('DATA_DIR=' . $previousDataDir);
    @unlink($cacheDir . '/selector_cache.json');
    @rmdir($cacheDir);
}
