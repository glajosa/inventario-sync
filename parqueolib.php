<?php
/**
 * parqueolib.php — parqueos de Noral Plaza dentro del campo "Inventario".
 * ---------------------------------------------------------------------------
 * Los parqueos y bodegas viven en el SPA 1072, categoría 63 ("Parqueos y bodegas ·
 * Noral Plaza"), cargados el 1-nov-2026 desde los 3 planos del ingeniero. Cada
 * departamento tiene SU parqueo asignado: la tarjeta lo dice en Extras
 * ("Departamento: E-3-15") y tiene precio 0, porque ya va incluido en el departamento.
 * Los que sobran son los libres: $20.000 cada uno, para el que quiera otro.
 *
 * Qué hace esto:
 *   - El mapa departamento -> su parqueo, en DATA_DIR/parqueos_asignados.json. Lo
 *     escribe bin/parqueos-asignar.php leyendo la libreta. 0 llamadas al leerlo.
 *   - Reglas para que un parqueo/bodega en el campo NO toque el monto, el título ni
 *     ACTIVO COMPRADO del deal: el precio negociado es del vendedor (ver
 *     autollenar_ficha en campolib.php, que suma PVPs de lista).
 *
 * Perilla: config.json "parqueo_auto" (1 = el campo pega el parqueo al elegir el
 * departamento; 0 = como antes). Las reglas de "no tocar el monto" NO tienen perilla:
 * apagarlas es dejar que un parqueo pise el precio de la venta.
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

const PARQUEO_CAT = 63;   // categoría del SPA 1072 "Parqueos y bodegas · Noral Plaza"
const PQ_TIPO_BODEGA = 2177;
const PQ_CAMPO_EXTRAS = 'ufCrm25_1782616175065';   // "Extras" de la tarjeta   // "Tipo de bien" = Bodega (la opción la agregó Jesua el 1-nov)

/** Lee una clave de config.json sin cargar nada más (field.php también lo usa). */
function pq_cfg(string $clave, $porDefecto) {
    static $cfg = null;
    if ($cfg === null) {
        $f = (getenv('DATA_DIR') ?: '/data') . '/config.json';
        $j = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
        $cfg = is_array($j) ? $j : [];
    }
    return array_key_exists($clave, $cfg) ? $cfg[$clave] : $porDefecto;
}

function pq_auto_on(): bool {
    return (int)pq_cfg('parqueo_auto', 1) === 1;
}

/** [idDepartamento => idParqueo]. Vacío si el archivo no existe o está roto. */
function pq_asignados(): array {
    static $m = null;
    if ($m !== null) return $m;
    $f = (getenv('DATA_DIR') ?: '/data') . '/parqueos_asignados.json';
    $j = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
    $m = [];
    foreach ((array)($j['mapa'] ?? []) as $u => $p) {
        if ((int)$u > 0 && (int)$p > 0) $m[(int)$u] = (int)$p;
    }
    return $m;
}

/**
 * Ids de TODAS las tarjetas de la categoría 63 (parqueos y bodegas), asignadas o no.
 * Para quien solo tiene el id y no quiere pagar un crm.item.get por saber qué es.
 */
function pq_ids_accesorios(): array {
    static $s = null;
    if ($s !== null) return $s;
    $f = (getenv('DATA_DIR') ?: '/data') . '/parqueos_asignados.json';
    $j = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
    $s = [];
    foreach ((array)($j['todos'] ?? []) as $id) if ((int)$id > 0) $s[(int)$id] = true;
    return $s;
}

/**
 * Las unidades del campo SIN parqueos ni bodegas. Para todo lo que reparte o compara
 * PRECIOS por unidad (el reparto del precio final en Cobranzas, preciolib.php): un
 * parqueo de $0 en la lista partía el precio del departamento por la mitad.
 */
function pq_sin_accesorios(array $ids): array {
    $acc = pq_ids_accesorios();
    return array_values(array_filter(array_map('intval', $ids), fn($i) => !isset($acc[$i])));
}

/**
 * Título del deal con los parqueos/bodegas ADICIONALES al final, entre paréntesis:
 *   "RubyPacheco--Noral Plaza (Locales Comerciales)--F-1-22 + Bodega (BD-1-12)"
 * Jesua 1-nov-2026: "bodega, paréntesis, y ahí la unidad que es".
 * Se borra lo que hubiera de antes y se pone lo de ahora: quitar la bodega la saca
 * del nombre. Solo títulos con la forma "A--B--C"; otro texto se deja como está.
 * $extras = [['t' => 'Bodega'|'Parqueo', 'cod' => 'BD-1-12'], ...]
 */
function pq_titulo(string $titulo, array $extras): string {
    if (count(explode('--', $titulo)) < 3) return $titulo;
    $base = preg_replace('/(\s*\+\s*(Bodega|Parqueo)\s*\([^)]*\))+\s*$/u', '', $titulo);
    foreach ($extras as $e) $base .= ' + ' . $e['t'] . ' (' . $e['cod'] . ')';
    return $base;
}

/**
 * Suite UNIDA cuyo parqueo se libera: 2 suites juntas llevan 1 parqueo, 3 llevan 2.
 * Se libera el de la suite de código mayor (mismo criterio que la carga del 1-nov y que
 * field.php). $suites = [idUnidad => código]. Devuelve el id de esa suite o null.
 */
function pq_mitad_de_fusion(array $suites, bool $separadas): ?int {
    if ($separadas || count($suites) < 2) return null;
    uasort($suites, fn($a, $b) => strnatcasecmp($a, $b));
    return (int)array_key_last($suites);
}

/** Saca del mapa el parqueo de una suite (ya no es suyo) y lo anota en 'liberados'. */
function pq_mapa_liberar(int $deptoId, int $pqId, array $nota): bool {
    $f = (getenv('DATA_DIR') ?: '/data') . '/parqueos_asignados.json';
    $j = json_decode((string)@file_get_contents($f), true);
    if (!is_array($j) || !isset($j['mapa'])) return false;
    unset($j['mapa'][(string)$deptoId]);
    $j['liberados'][(string)$pqId] = $nota + ['depto' => $deptoId, 'ts' => gmdate('c')];
    return file_put_contents($f, json_encode($j), LOCK_EX) !== false;
}

/** Parqueo o bodega (categoría 63): va en el campo pero no es "la unidad" del deal. */
function pq_es_accesorio($categoryId): bool {
    return (int)$categoryId === PARQUEO_CAT;
}

/**
 * ¿Este parqueo asignado puede entrar al campo junto a estas unidades?
 * Sí solo si su departamento también está en la lista: el asignado de E-3-15 no se
 * le vende suelto a otro cliente. Devuelve null si el id no es un parqueo asignado.
 */
function pq_asignado_acompanado(int $pqId, array $ids): ?bool {
    $dueno = array_search($pqId, pq_asignados(), true);
    if ($dueno === false) return null;
    return in_array((int)$dueno, array_map('intval', $ids), true);
}
