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
 * El LOGO del proyecto, por categoria del SPA.
 *
 * Las listas del director llevan siempre dos: el de Galjosa y el del PROYECTO —el de
 * Galero en Torre C, D, Suites y Casas; el de Noral Plaza en sus tres familias; el de
 * Sun Bay en los solares—. Sin el logo propio el documento parece de otra empresa, y
 * es lo primero que ve el cliente.
 *
 * Una familia puede pisarlo con `logo` en su bloque de lista; si no, manda este mapa.
 * Un proyecto sin logo propio devuelve null y solo sale el de Galjosa: es preferible
 * a poner uno que no le corresponde.
 */
const LST_LOGOS = [
    33 => ['assets/logo_noral_plaza.png',      'Noral Plaza'],
    39 => ['assets/logo_noral_apartments.png', 'Noral Apartments'],
    47 => ['assets/logo_galero.png',           'Galero'],
    49 => ['assets/logo_sunbay.png',           'Sun Bay Engabao'],
    51 => ['assets/logo_galero.png',           'Galero'],
    53 => ['assets/logo_galero.png',           'Galero'],
    55 => ['assets/logo_galero.png',           'Galero'],
];

/** @return array{0:string,1:string}|null  [ruta, alt] del logo del proyecto */
function lst_logo(int $cat, array $L): ?array {
    if (!empty($L['logo'])) return [(string)$L['logo'], (string)($L['logo_alt'] ?? '')];
    return LST_LOGOS[$cat] ?? null;
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
/* $nExtra fuerza el numero de cuotas extraordinarias de ESTA fila. Hace falta por los
   dos lofts duplex de Galero Torre D: parten el mismo 5% en DOS cuotas y el resto de la
   torre lo paga en una. Cobrarles la del resto es pedirles mas del doble en la unica
   cuota fuerte del plan — D-10-2 salia a $12.817,70 cuando son $6.408,86. */
function lst_plan(float $p, array $fin, ?int $nExtra = null): array {
    $sep   = (float)($fin['separa'] ?? 1000);
    $pz    = lst_plazo($fin);
    $meses = $pz['meses'];
    $nEx   = $nExtra !== null && $nExtra > 0 ? $nExtra : $pz['extra_n'];
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

/**
 * El par contiguo DISPONIBLE mas barato de un nivel: la fila "DESDE".
 *
 * "DESDE" significa desde cuanto sale hoy una unidad unida, asi que se calcula del
 * inventario y no de una tabla. El valor declarado en `combos` es una referencia que
 * envejece: en Apartments A/PB decia $306.750 —dos medianeros— y hoy no hay dos
 * medianeros contiguos libres, o sea que ese par NO EXISTE. El par real es
 * A-1-7 + A-1-8 = $312.250, que es exactamente lo que publica la lista del director.
 *
 * Y al revés: en Noral Plaza P2 la tabla ofrece $249.000 cuando hoy no queda ningun
 * par valido. Sin este calculo la lista promete una union que no se puede vender.
 *
 * Dos formas de saber que unidades se pueden unir, y cada proyecto usa la suya:
 *   `combos.pares_validos`  posiciones que se unen (Plaza: [[2,3],[4,5],[8,9],[10,11]])
 *   `nucleo_entre`          contiguas, salvo las que el nucleo de ascensores separa
 *                           (Apartments: con 8 por piso, la 4 y la 5 no se unen)
 * Los dos pares tienen que estar en el MISMO piso: dos unidades de pisos distintos
 * no se unen.
 *
 * @return array{precio: float, a: string, b: string}|null
 */
function lst_par_mas_barato(array $cfg, array $unidades, array $edificios, string $niv): ?array {
    // Disponibles por piso y posicion, solo de los edificios de esta hoja.
    $porPiso = [];
    foreach ($unidades as $k => $d) {
        if (($d['etapa'] ?? '') !== 'DISPONIBLE') continue;
        $pvp = (float)($d['pvp'] ?? 0);
        if ($pvp <= 0) continue;
        [$ed, $piso, $pos] = array_pad(explode('-', $k), 3, '0');
        if (!in_array($ed, $edificios, true)) continue;
        if (mz_nivel_de_piso($cfg, (int)$piso) !== $niv) continue;
        $porPiso["$ed-$piso"][(int)$pos] = ['pvp' => $pvp, 'cod' => (string)($d['cod'] ?? $k)];
    }
    $validos = $cfg['combos']['pares_validos'] ?? null;
    $mejor = null;
    foreach ($porPiso as $clave => $m) {
        $ed = explode('-', $clave)[0];
        if (is_array($validos)) {
            $pares = array_map(fn($x) => [(int)$x[0], (int)$x[1]], $validos);
        } else {
            $uxp = (int)(($cfg['unidades_por_piso'][$ed] ?? null)
                      ?? ($cfg['unidades_por_piso'][mz_grupo_de($cfg, $ed)] ?? 8));
            $corte = (array)(($cfg['nucleo_entre'][(string)$uxp] ?? []) ?: []);
            $pares = [];
            for ($i = 1; $i < $uxp; $i++) {
                if (count($corte) === 2 && $i === (int)$corte[0] && $i + 1 === (int)$corte[1]) continue;
                $pares[] = [$i, $i + 1];
            }
        }
        foreach ($pares as [$a, $b]) {
            if (!isset($m[$a], $m[$b])) continue;
            $sum = $m[$a]['pvp'] + $m[$b]['pvp'];
            if ($mejor === null || $sum < $mejor['precio'])
                $mejor = ['precio' => $sum, 'a' => $m[$a]['cod'], 'b' => $m[$b]['cod']];
        }
    }
    return $mejor;
}

/**
 * La fila de UNIDADES UNIDAS de un nivel: precio y metraje.
 *
 * Dos estructuras reales y hay que aceptar las dos, porque cada archivo del director
 * la escribe a su manera:
 *   Plaza:      combos = {metraje: 100, precio: {P2: 249000, ...}}
 *   Apartments: combos = {A: {m2: 150, PB: 306750, PA: ..., 4P: ...}, GH: {...}}
 * Unificar los archivos seria tocar su fuente de verdad; se lee lo que hay.
 *
 * @return array{precio: float, m2: ?float}|null
 */
function lst_combo(array $cfg, string $grupo, string $niv): ?array {
    $cb = $cfg['combos'] ?? null;
    if (!is_array($cb)) return null;
    if (isset($cb[$grupo]) && is_array($cb[$grupo])) {
        $p = $cb[$grupo][$niv] ?? null;
        return $p === null ? null : ['precio' => (float)$p, 'm2' => $cb[$grupo]['m2'] ?? null];
    }
    $p = $cb['precio'][$niv] ?? null;
    return $p === null ? null : ['precio' => (float)$p, 'm2' => $cb['metraje'] ?? null];
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
                    string $origen = 'unidades', string $niv = '', string $nivPatio = ''): string {
    $fmt = fn(float $v) => rtrim(rtrim(number_format($v, 2, ',', ''), '0'), ',');
    $me  = $cfg['metraje'][$grupo] ?? [];
    // "En PB todas las unidades son del metraje base + patio. El metraje mayor solo
    // existe desde planta alta." Es la nota del propio archivo del director: sin esto
    // A-1-1 y A-1-8 salian con 85,15 m2 cuando en planta baja son 75.
    $esPatio = $nivPatio !== '' && $niv === $nivPatio;
    $cfgM = (!$esPatio && in_array($cat, (array)($me['cats_mayor'] ?? []), true))
                ? ($me['mayor'] ?? null) : ($me['base'] ?? null);
    // `config`: manda la tabla de la direccion. Apartments publica 85,15 m2 y Bitrix
    // guarda 84,90 en algunas fichas; el numero que el cliente ya vio es el suyo.
    // `unidades`: manda lo que dice cada ficha. Es lo correcto donde la tabla no
    // cubre la familia — a los locales de Plaza les imprimia 50 m2 siendo de 30.
    if ($origen === 'config' && $cfgM !== null) return $fmt((float)$cfgM);
    if ($m2) return implode(' - ', array_map($fmt, $m2));
    return $cfgM !== null ? $fmt((float)$cfgM) : '—';
}

/**
 * Fichas INCOMPLETAS: disponibles sin precio que tampoco son la mitad de una union.
 *
 * La direccion lo pidio textual: "una DISPONIBLE sin precio y sin pareja valida es una
 * ficha incompleta, no una union: tampoco se publica, pero se REPORTA al equipo
 * comercial en vez de esconderse".
 *
 * Las tres condiciones de la pareja son suyas, y las tres hacen falta. Con solo "le
 * falta el PVP" su detector dio 45 uniones en Noral Plaza donde hay 21: una unidad
 * BLOQUEADA o de un dueno tambien puede no tener precio. Y sin el 1.6x se equivoca de
 * pareja — C-1-23 tiene dos vecinos disponibles, C-1-22 (30 m2, medianero suelto) y
 * C-1-24 (77 m2, la union real).
 *
 * Ojo: una unidad sin codigo utilizable (como la ficha #3091 de Sun Bay) no llega
 * hasta aca — el catalogo la descarta antes. Esa se ve en Bitrix, no en la lista.
 */
function lst_incompletas(array $unidades, int $tipo, ?array $L = null): array {
    $base = null;
    foreach ($unidades as $d)
        if ((int)($d['tipo'] ?? 0) === $tipo && (float)($d['pvp'] ?? 0) > 0) {
            $m = (float)str_replace(',', '.', (string)($d['m2'] ?? 0));
            if ($m > 0) $base = $base === null ? $m : min($base, $m);
        }
    $fuera = [];
    foreach ($unidades as $k => $d) {
        if ((int)($d['tipo'] ?? 0) !== $tipo) continue;
        if (($d['etapa'] ?? '') !== 'DISPONIBLE') continue;
        if ((float)($d['pvp'] ?? 0) > 0) continue;
        if (!preg_match('/^([A-Z])-(\d+)-(\d+)$/', $k, $m)) { $fuera[] = (string)($d['cod'] ?? $k); continue; }
        /* Las tres condiciones bastan y hay que respetarlas las tres. La de "misma
           etapa" es la que salva a H-32 de Galero Casas: esta DISPONIBLE y sus dos
           vecinos de solar estan FIRMADOS, asi que no hay pareja posible y queda
           reportado. Un corte extra por proyecto sobra — y de hecho mandaba al aviso a
           las cinco mitades legitimas de Locales. */
        $tienePareja = false;
        foreach ([-1, 1] as $paso) {
            $v = $unidades["{$m[1]}-{$m[2]}-" . ((int)$m[3] + $paso)] ?? null;
            if (!$v) continue;
            if (($v['etapa'] ?? '') !== ($d['etapa'] ?? '')) continue;      // misma etapa
            if ((float)($v['pvp'] ?? 0) <= 0) continue;                    // la pareja tiene precio
            $mv = (float)str_replace(',', '.', (string)($v['m2'] ?? 0));
            if ($base !== null && $mv < $base * 1.6) continue;             // mide lo que dos
            $tienePareja = true; break;
        }
        if (!$tienePareja) $fuera[] = (string)($d['cod'] ?? $k);
    }
    sort($fuera);
    return $fuera;
}
