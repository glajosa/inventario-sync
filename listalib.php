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
 * Meses de plazo y numero de extraordinarias, DERIVADOS de la fecha de entrega.
 *
 * Lo dice el propio archivo de la direccion: "EL PLAZO NO SE GUARDA: se calcula como
 * los meses que faltan hasta fecha_entrega. Cada primero de mes queda un mes menos y
 * las cuotas suben solas. Por eso aqui no hay campo 'meses'."
 *
 *   meses      = meses entre hoy y fecha_entrega
 *   extraord_n = ceil(meses / 12)   un pago extraordinario por anio restante
 *
 * Escribir los meses a mano es el error que esta funcion evita: hoy Apartments da 44
 * meses y 4 extraordinarias, pero un numero fijo se queda viejo el mes siguiente y
 * nadie se entera hasta que un cliente compara dos cotizaciones.
 *
 * `meses` en el bloque solo se respeta cuando NO hay fecha de entrega (Galero Casas
 * cuenta 36 meses desde la firma, no hasta una fecha del calendario).
 */
function lst_plazo(array $fin): array {
    $f = (string)($fin['fecha_entrega'] ?? '');
    if (preg_match('/^(\d{4})-(\d{2})$/', $f, $m)) {
        $hoy = new DateTimeImmutable('now');
        $meses = ((int)$m[1] - (int)$hoy->format('Y')) * 12 + ((int)$m[2] - (int)$hoy->format('n'));
        $meses = max(1, $meses);
        return ['meses' => $meses, 'extra_n' => (int)ceil($meses / 12), 'derivado' => true];
    }
    return ['meses' => max(1, (int)($fin['meses'] ?? 54)),
            'extra_n' => max(1, (int)($fin['extra_n'] ?? 5)), 'derivado' => false];
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
    $pz    = lst_plazo($fin);
    $meses = $pz['meses'];
    $nEx   = $pz['extra_n'];
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
    $cods = []; $m2 = [];
    foreach ($unidades as $u => $d) {
        if (($d['etapa'] ?? '') !== 'DISPONIBLE') continue;
        [$ed, $piso, $pos] = array_pad(explode('-', $u), 3, '0');
        if (!in_array($ed, $edificios, true)) continue;
        if (mz_nivel_de_piso($cfg, (int)$piso) !== $niv) continue;
        $ov = mz_por_unidad($cfg, $cfg['overrides_unidad'] ?? [], $ed, (int)$piso, (int)$pos) ?? [];
        if (mz_categoria_de($cfg, $ed, (int)$pos, $u, $niv, $ov) !== $cat) continue;
        $cods[] = (string)($d['cod'] ?? $u);
        $v = (string)($d['m2'] ?? '');
        if ($v !== '') $m2[] = round((float)str_replace(',', '.', $v), 2);
    }
    natsort($cods);
    $m2 = array_values(array_unique($m2));
    sort($m2);
    return ['cods' => array_values($cods), 'm2' => $m2];
}

/**
 * Los metros que se imprimen en la fila.
 *
 * Salen de las PROPIAS unidades disponibles, no de la tabla `metraje` del proyecto:
 * esa guarda base/mayor por grupo y sirve para oficinas y departamentos, pero los
 * locales tienen sus propios 30, 39 y 77 m2 y quedaban impresos como 50 o 31 — un
 * local de 30 m2 anunciado con 50. Si en la celda conviven varios metrajes se dicen
 * los dos ("30 - 39") en vez de elegir uno y mentir en el otro.
 */
function lst_metros(array $m2, array $cfg, string $grupo, string $cat,
                    string $origen = 'unidades'): string {
    $fmt = fn(float $v) => rtrim(rtrim(number_format($v, 2, ',', ''), '0'), ',');
    $me  = $cfg['metraje'][$grupo] ?? [];
    $cfgM = in_array($cat, (array)($me['cats_mayor'] ?? []), true)
                ? ($me['mayor'] ?? null) : ($me['base'] ?? null);
    // `config`: manda la tabla de la direccion. Apartments publica 85,15 m2 y Bitrix
    // guarda 84,90 en algunas fichas; el numero que el cliente ya vio es el suyo.
    // `unidades`: manda lo que dice cada ficha. Es lo correcto donde la tabla no
    // cubre la familia — a los locales de Plaza les imprimia 50 m2 siendo de 30.
    if ($origen === 'config' && $cfgM !== null) return $fmt((float)$cfgM);
    if ($m2) return implode(' - ', array_map($fmt, $m2));
    return $cfgM !== null ? $fmt((float)$cfgM) : '—';
}
