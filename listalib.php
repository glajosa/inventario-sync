<?php
/**
 * listalib.php — las FAMILIAS de un proyecto y el armado de su lista de precios.
 * ---------------------------------------------------------------------------
 * La lista de precios NO es un volcado de unidades: es una lista por TIPOLOGIA.
 * El cliente lee "esquinero con vista al parque lineal, 58 m2, $144.420", no 78
 * codigos de unidad. El formato es el que ya usa la direccion y esta en
 * `GALJOSA - Todos los sistemas de precios`; aca se reproduce, no se reinventa.
 *
 * La familia se decide por el TIPO DE BIEN de la ficha, que es dato vivo del SPA:
 * el 33 vende locales, oficinas y monoambientes a la vez, y cada uno lleva su
 * propia lista. No se decide por el codigo, porque los edificios E y F tienen
 * locales Y monoambientes con la misma letra.
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

/** Tipo de bien -> nombre con el que la familia sale en la lista del cliente. */
const LST_TIPOS = [
    1791 => 'Locales comerciales',
    1951 => 'Oficinas y consultorios',
    1793 => 'Monoambientes',
    1797 => 'Suites',
    1799 => 'Casas',
    1795 => 'Solares',
    1943 => 'Terrenos',
    1947 => 'Casa modelo',
    1801 => 'Parqueos',
];

/** Nombre por proyecto cuando el generico no sirve: en Apartments y en las torres
 *  de Galero el tipo "Departamento" son departamentos, no monoambientes de 30 m2. */
const LST_NOMBRE_POR_CAT = [
    39 => [1793 => 'Departamentos'],
    47 => [1793 => 'Departamentos'],
    51 => [1793 => 'Departamentos'],
    49 => [1795 => 'Solares'],
    55 => [1799 => 'Casas', 1947 => 'Casa modelo'],
];

function lst_nombre_familia(int $cat, int $tipo): string {
    return LST_NOMBRE_POR_CAT[$cat][$tipo] ?? (LST_TIPOS[$tipo] ?? "Tipo $tipo");
}

/**
 * Las familias que este proyecto tiene DISPONIBLES hoy.
 * Solo disponibles y solo con precio: una familia agotada no lleva lista.
 */
function lst_familias(array $unidades, int $cat): array {
    $f = [];
    foreach ($unidades as $d) {
        if (($d['etapa'] ?? '') !== 'DISPONIBLE') continue;
        $pvp = (float)($d['pvp'] ?? 0);
        if ($pvp <= 0) continue;
        $t = (int)($d['tipo'] ?? 0);
        if (!isset($f[$t])) {
            $f[$t] = ['tipo' => $t, 'nombre' => lst_nombre_familia($cat, $t),
                      'n' => 0, 'min' => $pvp, 'max' => $pvp];
        }
        $f[$t]['n']++;
        $f[$t]['min'] = min($f[$t]['min'], $pvp);
        $f[$t]['max'] = max($f[$t]['max'], $pvp);
    }
    uasort($f, fn($a, $b) => $b['n'] <=> $a['n']);
    return $f;
}

/**
 * El plan de pago de un precio, con los porcentajes que declara la familia.
 *
 * Verificado contra las cuatro filas de la lista de OFICINAS de la direccion:
 * $144.420 a 54 meses da firma $13.442,00 · mensual $534,89 · extraordinaria
 * $2.888,40, y las cuatro coinciden al centavo.
 *
 * OJO: los meses son de la FAMILIA, no del proyecto. En Noral Plaza los locales,
 * las oficinas y los monoambientes no comparten plazo, y el cotizador —que resuelve
 * por categoria de pipeline— no puede distinguirlos.
 */
function lst_plan(float $p, array $fin): array {
    $sep   = (float)($fin['separa'] ?? 1000);
    $meses = max(1, (int)($fin['meses'] ?? 54));
    $nEx   = max(1, (int)($fin['extra_n'] ?? 5));
    $reserva = $p * ((float)($fin['reserva_pct'] ?? 10) / 100);
    $cuotas  = $p * ((float)($fin['cuotas_pct']  ?? 20) / 100);
    $extras  = $p * ((float)($fin['extra_pct']   ?? 10) / 100);
    return [
        'separa'  => $sep,
        'firma'   => max(0.0, $reserva - $sep),
        'mensual' => $cuotas / $meses,
        'extra'   => $extras / $nEx,
        'contra'  => $p * ((float)($fin['contra_pct'] ?? 60) / 100),
        'meses'   => $meses,
        'nExtra'  => $nEx,
    ];
}

/** Plan de los proyectos que se venden con CREDITO HIPOTECARIO (Torre C, Suites):
 *  entrada + prestamo, y la cuota sale de una amortizacion francesa. */
function lst_plan_hipo(float $p, array $fin): array {
    $sep = (float)($fin['separa'] ?? 1000);
    $ent = $p * ((float)($fin['entrada_pct'] ?? 30) / 100);
    $pre = $p - $ent;
    $i   = ((float)($fin['tasa'] ?? 7.35) / 100) / 12;
    $n   = max(1, (int)($fin['anios'] ?? 20) * 12);
    $cuota = $i > 0 ? $pre * $i / (1 - pow(1 + $i, -$n)) : $pre / $n;
    return ['separa' => $sep, 'entrada' => $ent - $sep, 'prestamo' => $pre,
            'cuota' => $cuota, 'anios' => (int)($fin['anios'] ?? 20),
            'tasa' => (float)($fin['tasa'] ?? 7.35)];
}

/** Cuántas unidades DISPONIBLES hay en una celda (grupo, nivel, categoría), y
 *  cuáles son. El código hace falta porque cuando queda UNA la lista la nombra. */
function lst_celda(array $cfg, array $unidades, array $edificios, string $niv, string $cat): array {
    $cods = [];
    foreach ($unidades as $u => $d) {
        if (($d['etapa'] ?? '') !== 'DISPONIBLE') continue;
        [$ed, $piso, $pos] = array_pad(explode('-', $u), 3, '0');
        if (!in_array($ed, $edificios, true)) continue;
        if (mz_nivel_de_piso($cfg, (int)$piso) !== $niv) continue;
        $ov = mz_por_unidad($cfg, $cfg['overrides_unidad'] ?? [], $ed, (int)$piso, (int)$pos) ?? [];
        if (mz_categoria_de($cfg, $ed, (int)$pos, $u, $niv, $ov) !== $cat) continue;
        $cods[] = (string)($d['cod'] ?? $u);
    }
    natsort($cods);
    return array_values($cods);
}
