<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/matrizlib.php';
require_once dirname(__DIR__) . '/listalib.php';

/** @return array{building:?string,floor:?int,position:?int} */
function bot_catalog_code_parts(string $code): array {
    $code = strtoupper(trim($code));
    if (preg_match('/^([A-Z])-?(\d+)-(\d+)$/', $code, $m)) {
        return ['building' => $m[1], 'floor' => (int)$m[2], 'position' => (int)$m[3]];
    }
    if (preg_match('/^([A-Z])-(\d+)$/', $code, $m)) {
        return ['building' => $m[1], 'floor' => null, 'position' => (int)$m[2]];
    }
    return ['building' => null, 'floor' => null, 'position' => null];
}

function bot_catalog_bedrooms(array $row, array $list): ?int {
    $rules = (array)($list['bot_catalog'] ?? []);
    if (isset($row['bedrooms'])) return (int)$row['bedrooms'];
    if (!empty($row['union']) && isset($rules['composite_bedrooms'])) {
        return (int)$rules['composite_bedrooms'];
    }

    $codes = array_values(array_filter((array)($row['cods'] ?? []), 'strlen'));
    $parts = bot_catalog_code_parts((string)($codes[0] ?? ''));
    if ($parts['building'] !== null && isset($rules['bedrooms_by_building'][$parts['building']])) {
        return (int)$rules['bedrooms_by_building'][$parts['building']];
    }
    if ($parts['position'] !== null && isset($rules['bedrooms_by_position'][(string)$parts['position']])) {
        return (int)$rules['bedrooms_by_position'][(string)$parts['position']];
    }
    return isset($rules['default_bedrooms']) ? (int)$rules['default_bedrooms'] : null;
}

function bot_catalog_position(array $row, array $list): ?string {
    $rules = (array)($list['bot_catalog'] ?? []);
    $codes = array_values(array_filter((array)($row['cods'] ?? []), 'strlen'));
    $parts = bot_catalog_code_parts((string)($codes[0] ?? ''));
    if ($parts['position'] !== null && isset($rules['position_by_position'][(string)$parts['position']])) {
        return (string)$rules['position_by_position'][(string)$parts['position']];
    }
    $name = trim(strip_tags((string)($row['nombre'] ?? '')));
    return $name !== '' ? $name : null;
}

/**
 * Construye las filas comerciales de una familia cuya lista usa forma=precio.
 * La inclusión en modo datos garantiza que el bot y el documento visual parten de
 * la misma clasificación, disponibilidad, uniones y precio "desde".
 */
function bot_commercial_price_family(array $cfg, int $family, array $units): array {
    $list = (array)($cfg['listas'][(string)$family] ?? []);
    if (($list['forma'] ?? '') !== 'precio') return [];

    $L = $list;
    $unidades = $units;
    $fam = $family;
    $cat = (int)($cfg['bitrix']['categoryId'] ?? 0);
    $proyecto = (string)($cfg['proyecto'] ?? "Proyecto $cat");
    $LISTA_PRECIO_DATA_ONLY = true;
    $LISTA_PRECIO_RESULT = null;
    include dirname(__DIR__) . '/lista_precio.php';
    if (!is_array($LISTA_PRECIO_RESULT)) {
        throw new RuntimeException('No se pudo construir la lista comercial.');
    }

    $blockLabels = [];
    foreach ((array)$LISTA_PRECIO_RESULT['blocks'] as $block) {
        $blockLabels[(string)$block['id']] = (string)($block['etiqueta'] ?? $block['id']);
    }

    $rows = [];
    foreach ((array)$LISTA_PRECIO_RESULT['groups'] as $row) {
        $codes = array_values(array_filter(array_map('strval', (array)($row['cods'] ?? [])), 'strlen'));
        $components = array_values(array_filter(array_map('strval', (array)($row['component_codes'] ?? [])), 'strlen'));
        $m2 = round((float)($row['m2'] ?? 0), 2);
        $price = round((float)($row['precio'] ?? 0), 2);
        if ($m2 <= 0 || $price <= 0) continue;
        $plan = lst_plan($price, (array)($LISTA_PRECIO_RESULT['financing'] ?? []),
            isset($row['extra_n']) ? (int)$row['extra_n'] : null);
        $level = (string)($row['bloque'] ?? '');
        $rows[] = [
            'project' => $proyecto,
            'family_id' => $family,
            'family' => lst_nombre_familia($cat, $family),
            'level' => $level,
            'level_label' => $blockLabels[$level] ?? $level,
            'position' => bot_catalog_position($row, $list),
            'bedrooms' => bot_catalog_bedrooms($row, $list),
            'm2_min' => $m2,
            'm2_max' => $m2,
            'parkings' => isset($row['parq']) ? (int)$row['parq'] : null,
            'price_from' => $price,
            'price_to' => $price,
            'available_count' => !empty($row['union']) ? 1 : count($codes),
            'codes' => $codes,
            'component_codes' => $components,
            'is_composite' => !empty($row['union']),
            'payment' => $plan,
        ];
    }
    return $rows;
}

function bot_catalog_base_row(array $cfg, int $family, array $list): array {
    $cat = (int)($cfg['bitrix']['categoryId'] ?? 0);
    return [
        'project' => (string)($cfg['proyecto'] ?? "Proyecto $cat"),
        'family_id' => $family,
        'family' => lst_nombre_familia($cat, $family),
        'level' => null,
        'level_label' => null,
        'position' => null,
        'bedrooms' => isset($list['bot_catalog']['default_bedrooms'])
            ? (int)$list['bot_catalog']['default_bedrooms'] : null,
        'm2_min' => null,
        'm2_max' => null,
        'parkings' => null,
        'price_from' => null,
        'price_to' => null,
        'available_count' => 0,
        'codes' => [],
        'component_codes' => [],
        'is_composite' => false,
        'delivery' => $list['bot_catalog']['delivery'] ?? null,
        'payment' => null,
    ];
}

function bot_catalog_matrix_position(array $cfg, string $building, int $position): ?string {
    $key = $cfg['posiciones'][$building][(string)$position] ?? null;
    if ($key === null) return null;
    return (string)($cfg['categorias'][(string)$key]['etiqueta'] ?? $key);
}

function bot_commercial_mortgage_family(array $cfg, int $family, array $units, array $list): array {
    $rows = [];
    foreach ($units as $key => $unit) {
        if (($unit['etapa'] ?? '') !== 'DISPONIBLE' || (int)($unit['tipo'] ?? 0) !== $family) continue;
        $price = (float)($unit['pvp'] ?? 0);
        $m2 = (float)str_replace(',', '.', (string)($unit['m2'] ?? 0));
        if ($price <= 0 || $m2 <= 0) continue;
        [$building, $floor, $position] = array_pad(explode('-', (string)$key), 3, '0');
        $row = bot_catalog_base_row($cfg, $family, $list);
        $level = (string)(mz_nivel_de_piso($cfg, (int)$floor) ?? '');
        $row['level'] = $level !== '' ? $level : null;
        $row['level_label'] = $level !== '' ? (string)($cfg['niveles'][$level]['etiqueta'] ?? $level) : null;
        $row['position'] = bot_catalog_matrix_position($cfg, (string)$building, (int)$position);
        $row['m2_min'] = $row['m2_max'] = round($m2, 2);
        $row['parkings'] = (int)($list['parqueos_por_unidad'] ?? 1);
        $row['price_from'] = $row['price_to'] = round($price, 2);
        $row['available_count'] = 1;
        $row['codes'] = [(string)($unit['cod'] ?? $key)];
        $row['payment'] = lst_plan_hipo($price, (array)($list['financiamiento'] ?? []));
        $rows[] = $row;
    }
    usort($rows, fn(array $a, array $b): int => strnatcmp($a['codes'][0], $b['codes'][0]));
    return $rows;
}

function bot_commercial_solar_family(array $cfg, int $family, array $units, array $list): array {
    $rows = [];
    foreach ($units as $key => $unit) {
        if (($unit['etapa'] ?? '') !== 'DISPONIBLE' || (int)($unit['tipo'] ?? 0) !== $family) continue;
        $price = (float)($unit['pvp'] ?? 0);
        $m2 = (float)str_replace(',', '.', (string)($unit['m2'] ?? 0));
        $code = (string)($unit['cod'] ?? $key);
        if ($price <= 0 || $m2 <= 0 || !preg_match('/^([A-Z])-(\d+)$/', $code, $parts)) continue;
        $row = bot_catalog_base_row($cfg, $family, $list);
        $row['position'] = bot_catalog_matrix_position($cfg, $parts[1], (int)$parts[2]);
        $row['m2_min'] = $row['m2_max'] = round($m2, 2);
        $row['price_from'] = $row['price_to'] = round($price, 2);
        $row['available_count'] = 1;
        $row['codes'] = [$code];
        $row['payment'] = lst_plan($price, (array)($list['financiamiento'] ?? []));
        $rows[] = $row;
    }
    usort($rows, fn(array $a, array $b): int => strnatcmp($a['codes'][0], $b['codes'][0]));
    return $rows;
}

function bot_commercial_house_family(array $cfg, int $family, array $units, array $list): array {
    $solares = [];
    foreach ($units as $key => $unit) {
        if (($unit['etapa'] ?? '') !== 'DISPONIBLE' || (int)($unit['tipo'] ?? 0) !== $family) continue;
        $code = (string)($unit['cod'] ?? $key);
        if (!preg_match('/^([A-Z])-(\d+)$/', $code, $parts)) continue;
        $building = $parts[1]; $position = (int)$parts[2];
        if (mz_por_unidad($cfg, $cfg['exentas'] ?? [], $building, 1, $position) !== null) continue;
        $terrain = mz_terreno_de($cfg, $building, $position, "$building-1-$position");
        $area = $cfg['terreno']['areas'][$code] ?? null;
        if ($terrain === null || $area === null) continue;
        $override = mz_por_unidad($cfg, $cfg['overrides_unidad'] ?? [], $building, 1, $position) ?? [];
        $solares[] = ['code' => $code, 'terrain' => (float)$terrain, 'only' => (string)($override['categoria'] ?? '')];
    }

    $prices = mz_precios_vigentes($cfg);
    $firstGroup = (string)array_key_first((array)$cfg['grupos']);
    $firstPriceBuilding = (string)array_key_first((array)$prices);
    $rows = [];
    foreach ((array)($list['orden'] ?? []) as $model) {
        $housePrice = $prices[$firstPriceBuilding]['U'][$model] ?? null;
        $construction = $cfg['metraje'][$firstGroup]['por_categoria'][$model] ?? null;
        if ($housePrice === null || $construction === null) continue;
        $options = [];
        foreach ($solares as $solar) {
            if ($solar['only'] !== '' && $solar['only'] !== $model) continue;
            $options[] = ['code' => $solar['code'], 'price' => round($solar['terrain'] + (float)$housePrice, 2)];
        }
        if (!$options) continue;
        usort($options, fn(array $a, array $b): int => $a['price'] <=> $b['price']);
        $row = bot_catalog_base_row($cfg, $family, $list);
        $row['position'] = (string)($list['nombre'][$model] ?? $model);
        $row['m2_min'] = $row['m2_max'] = (float)$construction;
        $row['price_from'] = (float)$options[0]['price'];
        $row['price_to'] = (float)$options[count($options) - 1]['price'];
        $row['available_count'] = count($options);
        $row['codes'] = array_column($options, 'code');
        $row['payment'] = lst_plan($row['price_from'], (array)($list['financiamiento'] ?? []));
        $rows[] = $row;
    }
    return $rows;
}

function bot_commercial_family(array $cfg, int $family, array $units): array {
    $list = (array)($cfg['listas'][(string)$family] ?? []);
    return match ((string)($list['forma'] ?? '')) {
        'precio' => bot_commercial_price_family($cfg, $family, $units),
        'hipotecario' => bot_commercial_mortgage_family($cfg, $family, $units, $list),
        'solar' => bot_commercial_solar_family($cfg, $family, $units, $list),
        'casa' => bot_commercial_house_family($cfg, $family, $units, $list),
        default => [],
    };
}

/** Convierte el caché compartido al mismo formato que usa la lista visual. */
function bot_catalog_units_from_cache(array $cfg, array $cache): array {
    $category = (int)($cfg['bitrix']['categoryId'] ?? 0);
    $knownBuildings = mz_edificios($cfg);
    $out = [];
    foreach ((array)($cache['units'] ?? []) as $unit) {
        if (!is_array($unit) || (int)($unit['cat'] ?? 0) !== $category) continue;
        if (strtoupper(trim((string)($unit['stage'] ?? ''))) !== 'DISPONIBLE') continue;
        if ((int)($unit['dealId'] ?? 0) > 0) continue;
        $code = strtoupper(trim((string)($unit['codigo'] ?? '')));
        if (preg_match('/^([A-Z])-(\d+)$/', $code, $short)) {
            $building = $short[1]; $floor = (int)($cfg['piso_sin_numero'] ?? 1); $position = (int)$short[2];
        } elseif (preg_match('/^([A-Z])(\d+)-(\d+)$/', $code, $tower)) {
            $building = $tower[1]; $floor = (int)$tower[2]; $position = (int)$tower[3];
        } elseif (preg_match('/^([A-Z])-(\d+)-(\d+)$/', $code, $normal)) {
            $building = $normal[1]; $floor = (int)$normal[2]; $position = (int)$normal[3];
        } else continue;
        if (!in_array($building, $knownBuildings, true)) continue;
        $group = mz_grupo_de($cfg, $building);
        if (isset($cfg['grupos'][$group]['lanzado']) && !$cfg['grupos'][$group]['lanzado']) continue;
        if (mz_nivel_de_piso($cfg, $floor) === null) continue;
        $out["$building-$floor-$position"] = [
            'id' => (int)($unit['id'] ?? 0),
            'etapa' => 'DISPONIBLE',
            'pvp' => mz_money($unit['pvp'] ?? null),
            'm2' => $unit['m2'] ?? null,
            'tipo' => (int)($unit['tipo'] ?? 0),
            'cod' => $code,
        ];
    }
    return $out;
}

function bot_commercial_catalog_document(array $cache, string $matrixDirectory, int $now): array {
    $built = (int)($cache['built'] ?? 0);
    if ($built <= 0 || !isset($cache['units']) || !is_array($cache['units'])) {
        throw new RuntimeException('missing_catalog');
    }
    $projects = [];
    $matrixFiles = glob(rtrim($matrixDirectory, '/\\') . '/proyecto_*.json') ?: [];
    sort($matrixFiles, SORT_NATURAL);
    foreach ($matrixFiles as $matrixFile) {
        $cfg = json_decode((string)file_get_contents($matrixFile), true, 128, JSON_THROW_ON_ERROR);
        if (!is_array($cfg)) continue;
        $units = bot_catalog_units_from_cache($cfg, $cache);
        $families = [];
        foreach ((array)($cfg['listas'] ?? []) as $familyKey => $list) {
            if (!preg_match('/^\d+$/D', (string)$familyKey) || !is_array($list)) continue;
            $family = (int)$familyKey;
            $offers = bot_commercial_family($cfg, $family, $units);
            if (!$offers) continue;
            $underlying = 0;
            foreach ($units as $unit) {
                if ((int)($unit['tipo'] ?? 0) === $family && (float)($unit['pvp'] ?? 0) > 0) $underlying++;
            }
            $families[] = [
                'family_id' => $family,
                'name' => lst_nombre_familia((int)$cfg['bitrix']['categoryId'], $family),
                'available_units' => $underlying,
                'offers' => $offers,
            ];
        }
        if ($families) {
            $projects[] = [
                'name' => (string)($cfg['proyecto'] ?? ''),
                'category_id' => (int)($cfg['bitrix']['categoryId'] ?? 0),
                'families' => $families,
            ];
        }
    }
    return [
        'schema_version' => 'bot-commercial-catalog-v1',
        'generated_at' => gmdate(DateTimeInterface::ATOM, $now),
        'status' => ($now - $built) <= 3600 ? 'fresh' : 'stale',
        'source' => [
            'catalog_generated_at' => gmdate(DateTimeInterface::ATOM, $built),
            'catalog_age_seconds' => max(0, $now - $built),
        ],
        'projects' => $projects,
    ];
}
