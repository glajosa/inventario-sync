<?php
/**
 * lista_precio.php — la lista agrupada por TIPOLOGIA, como la especifico la direccion.
 * ---------------------------------------------------------------------------
 * Spec del 24-ago-2026 (`spec_uniones_y_escasez.json`), con fixture de aceptacion.
 *
 * La regla es una sola y es simple: se agrupa por **precio + m2**, sobre TODO el
 * proyecto. Nunca por edificio ni por piso. Se comprobo contra el inventario en vivo:
 * agrupando asi salen los 13 grupos y los 79 disponibles del fixture, uno a uno.
 *
 * O sea que EL PRECIO YA ES LA TIPOLOGIA. No hay que clasificar por zona, giro ni
 * esquinero para AGRUPAR — solo para ponerle nombre a cada grupo, y eso lo declara
 * el proyecto en su tabla `tipologias`.
 *
 * UNIONES. Una unidad con PVP vacio es la segunda mitad de una union. La spec es
 * tajante: esa mitad NO genera fila, NO cuenta como disponible y su m2 NO entra a
 * ninguna tipologia. Con solo exigir "PVP > 0" quedan fuera las cinco de Noral Plaza
 * (A-1-14, C-1-12, C-1-23, D-1-2, D-1-14), que son justo las que el fixture lista
 * como `no_deben_aparecer`. La mitad CON precio es una tipologia propia, con su m2 y
 * su precio, en fila aparte — antes quedaba escondida dentro de la fila de 30 m2.
 *
 * ESCASEZ. El universo es el proyecto entero:
 *   1 disponible  -> la fila se nombra con el CODIGO real (LOCAL A-1-12)
 *   2 disponibles -> el nombre de la tipologia + "2 ULTIMAS DISPONIBLES"
 *   3 o mas       -> sin etiqueta
 *
 * Se incluye desde lista.php, que ya dejo listos $cfg, $L, $unidades, $fam y las
 * funciones lh()/lp()/ln()/lst_plan().
 * ---------------------------------------------------------------------------
 */

$fin     = $L['financiamiento'] ?? [];
$TIPOS   = (array)($L['tipologias'] ?? []);
$BLOQUES = (array)($L['bloques'] ?? [['id' => '', 'etiqueta' => '', 'pisos' => null]]);

/** El bloque (1ER PISO / PLANTA BAJA) al que pertenece un piso. */
$bloqueDe = function (int $piso) use ($BLOQUES): ?string {
    foreach ($BLOQUES as $b) {
        $p = $b['pisos'] ?? null;
        if ($p === null || in_array($piso, (array)$p, true)) return (string)$b['id'];
    }
    return null;
};

// ── agrupar ─────────────────────────────────────────────────────────────────
/* Un rango de caras puede venir como un par plano [1,12] o como una LISTA de pares
   [[1,12],[25,25]]. Hizo falta porque el E-4-25 esta solo en el 4to piso, en el medio
   del edificio, y da al parque CENTRAL: no cabe en el corte 1-12 / 13-24. */
if (!function_exists('lp_en_rango')):
function lp_en_rango(int $pos, $r): bool {
    if (!is_array($r) || !$r) return false;
    if (is_array($r[0])) { foreach ($r as $sub) if (lp_en_rango($pos, $sub)) return true; return false; }
    return $pos >= (int)$r[0] && $pos <= (int)$r[1];
}
endif;

$grupos = [];
foreach ($unidades as $u => $d) {
    if (($d['etapa'] ?? '') !== 'DISPONIBLE') continue;
    if ((int)($d['tipo'] ?? 0) !== $fam) continue;
    $pvp = (float)($d['pvp'] ?? 0);
    if ($pvp <= 0) continue;                       // mitad de union o ficha incompleta
    [$ed, $piso, $pos] = array_pad(explode('-', $u), 3, '0');
    $blq = $bloqueDe((int)$piso);
    if ($blq === null) continue;
    $m2  = (float)str_replace(',', '.', (string)($d['m2'] ?? 0));
    /* Cuando la lista se pagina por edificio, el EDIFICIO entra a la llave: si no, dos
       hojas distintas comparten grupo y la escasez sale mal. "ESQUINEROS 2 · 75 m2 ·
       $158.875" existe en el B y en el D con dos disponibles cada uno, y su documento
       marca "2 ULTIMAS" en las dos hojas. Con la llave sin edificio quedaban cuatro y
       ninguna etiqueta. */
    $pref = ($L['paginar_por'] ?? '') === 'edificio' ? $ed . '|' : '';
    $key = $pref . $blq . '|' . round($pvp) . '|' . rtrim(rtrim(number_format($m2, 2, '.', ''), '0'), '.');
    if (!isset($grupos[$key])) {
        $t = $TIPOS[round($pvp) . '|' . rtrim(rtrim(number_format($m2, 2, '.', ''), '0'), '.')] ?? [];
        $grupos[$key] = ['bloque' => $blq, 'precio' => $pvp, 'm2' => $m2, 'ed' => $ed,
                         'catKey' => (string)($t['cat'] ?? ''),
                         'nombre' => (string)($t['nombre'] ?? ''), 'sing' => (string)($t['singular'] ?? ''),
                         'zona' => (string)($t['zona'] ?? ''), 'parq' => $t['parqueos'] ?? null,
                         'union' => !empty($t['union']), 'cods' => []];
    }
    $grupos[$key]['cods'][] = (string)($d['cod'] ?? $u);
}
foreach ($grupos as &$g) natsort($g['cods']);
unset($g);

/* M2 PATIO. Solo la planta baja tiene patio, y su medida depende del grupo de precio
   del edificio (16,25 en A/B/C/D/E/F · 18,75 en G y H · 16 en I). Sale de `metraje` y
   `grupos` de la matriz del director, no de una tabla aparte que se desincronice.
   Los demas pisos llevan un guion, que es lo que imprime su documento. */
if (!empty($L['columna_patio'])) {
    $patioDe = [];
    foreach ((array)($cfg['grupos'] ?? []) as $gr => $info) {
        $pt = $cfg['metraje'][$gr]['patio_pb'] ?? null;
        if ($pt === null) continue;
        foreach ((array)($info['edificios'] ?? []) as $edg)
            $patioDe[(string)$edg] = rtrim(rtrim(number_format((float)$pt, 2, ',', ''), '0'), ',') . 'm2';
    }
    $blqPatio = (string)($L['bloque_patio'] ?? 'PB');
    foreach ($grupos as $k => $g)
        if ((string)$g['bloque'] === $blqPatio && isset($patioDe[(string)$g['ed']]))
            $grupos[$k]['patio'] = $patioDe[(string)$g['ed']];
}

/* EXCEPCIONES por unidad. Hay productos dentro de una familia que no siguen la regla
   general y no se pueden deducir de la posicion ni del metraje: los dos loft duplex de
   Galero Torre D (D-10-2 y D-10-4) llevan 2 parqueos, no 1, y parten su 5% de
   extraordinarias en DOS cuotas mientras el resto de la torre la paga en una. Estaba en
   el archivo del director (`parqueos: {_normal:1,_lofts:2}` y
   `cuotas_extraordinarias: {pct:5, n:1, n_lofts:2}`) y la lista lo ignoraba: la unica
   cuota fuerte del plan salia al doble. */
foreach ((array)($L['excepciones'] ?? []) as $codExc => $exc) {
    foreach ($grupos as $k => $g) {
        if (!in_array((string)$codExc, $g['cods'], true)) continue;
        if (isset($exc['parqueos'])) $grupos[$k]['parq']    = (int)$exc['parqueos'];
        if (isset($exc['extra_n']))  $grupos[$k]['extra_n'] = (int)$exc['extra_n'];
        if (!empty($exc['sufijo']))  $grupos[$k]['sufijo']  = (string)$exc['sufijo'];
    }
}

// ── nombre DERIVADO, cuando la familia no lista precio por precio ───────────
// Los monoambientes cambian de precio seguido y mantener una tabla de 9 llaves
// `precio|m2` a mano se desactualiza sola. Si la familia declara el mapa de
// posiciones, el nombre se arma de las unidades del grupo: su categoria y su cara.
$DER = $L['derivar_nombre'] ?? null;
if ($DER) {
    /* La CARA manda sobre el mapa de categorias (M-06 de la direccion, 24-ago-2026):
       en la cara central los dos esquineros valen distinto —la posicion 1 da a la garita
       y tiene las dos fachadas libres, la 12 colinda con el edificio vecino—, pero en la
       cara LINEAL los dos extremos colindan con un edificio, uno por cada punta: las
       posiciones 13 y 24 son UNA sola categoria y valen lo mismo.
       Aplicarle a la lineal el mapa de la central partia la categoria en dos y sacaba dos
       filas casi identicas, las dos marcadas "ULTIMA DISPONIBLE", a precios distintos
       (E-4-13 $84.863 contra F-4-24 $82.863). */
    /* La misma posicion se llama distinto segun el PISO. En Apartments la 8 es
       "ESQUINEROS 2" en planta baja y "4TO PISO ESQ 2" en el cuarto, y la 2 pasa de
       "MEDIANEROS" a "4TO PISO MED". Sale de `etiquetas_lista` del archivo de la
       direccion, que esta por nivel. */
    /* Modelo de DOS pasos, el del archivo de la direccion: la POSICION da la categoria
       y la categoria se rotula segun el NIVEL.
         posiciones[edificio][pos] -> 'M' | 'E1' | 'E2' | 'E3'
         etiquetas_lista[nivel][cat] -> 'MEDIANEROS' | '4TO PISO MED' | ...
       Hace falta porque la misma posicion no es la misma categoria en todos los
       edificios —la 1 es E3 en el A y E2 en el B, D, E y F— y porque la misma categoria
       se nombra distinto en el 4to piso. Un solo mapa plano no puede con las dos cosas.
       El valor "@algo" apunta al bloque `algo` de la raiz del proyecto para no duplicar
       la matriz del director. */
    $ref = function ($v) use ($cfg) {
        return (is_string($v) && $v !== '' && $v[0] === '@') ? (array)($cfg[substr($v, 1)] ?? []) : (array)$v;
    };
    $POS_ED = $ref($DER['posiciones_edificio'] ?? []);
    $ETI_BL = $ref($DER['etiquetas_por_bloque'] ?? []);
    $catDe = function (int $pos, string $cara = '', string $blq = '', string $ed = '') use ($DER, $POS_ED, $ETI_BL): string {
        if ($POS_ED && $ETI_BL) {
            $ck = (string)($POS_ED[$ed][(string)$pos] ?? '');
            if ($ck !== '' && isset($ETI_BL[$blq][$ck])) return (string)$ETI_BL[$blq][$ck];
            return (string)($DER['categoria_por_defecto'] ?? '');
        }
        {
            $mapas = (array)($DER['categorias_por_cara'] ?? []);
            $mapa  = ($cara !== '' && isset($mapas[$cara])) ? (array)$mapas[$cara]
                                                           : (array)($DER['categorias'] ?? []);
        }
        foreach ($mapa as $nom => $poss)
            if (in_array($pos, (array)$poss, true)) return (string)$nom;
        return (string)($DER['categoria_por_defecto_por_bloque'][$blq]
                     ?? $DER['categoria_por_defecto'] ?? '');
    };
    $caraDe = function (int $pos) use ($DER): string {
        foreach ((array)($DER['caras'] ?? []) as $nom => $r)
            if (lp_en_rango($pos, $r)) return (string)$nom;
        return '';
    };
    foreach ($grupos as $k => $g) {
        if ($g['nombre'] !== '') continue;
        $cats = []; $caras = [];
        foreach ($g['cods'] as $cod) {
            if (!preg_match('/^[A-Z]-\d+-(\d+)$/', $cod, $mm)) continue;
            $caraU = $caraDe((int)$mm[1]);
            $edU   = preg_match('/^([A-Z])/', $cod, $me) ? $me[1] : '';
            $cats[$catDe((int)$mm[1], $caraU, (string)$g['bloque'], $edU)] = true;
            $caras[$caraU] = true;
        }
        // Si el grupo mezcla categorias o caras se deja el nombre generico: inventar
        // uno seria prometerle al cliente una ubicacion que no todas tienen.
        // $nomCat y no $cat: $cat es la CATEGORIA del proyecto y viene de lista.php.
        // Pisarla aca rompio las tres familias que derivan el nombre —
        // lst_logo() recibia el texto de la tipologia en vez del numero.
        $nomCat = count($cats) === 1 ? (string)array_key_first($cats) : (string)($DER['mezcla'] ?? '');
        $cara = count($caras) === 1 ? (string)array_key_first($caras) : '';
        // El EDIFICIO entra al nombre cuando la familia va en una sola tabla y el
        // precio cambia por edificio: sin eso, en Apartments el vendedor lee
        // "MEDIANEROS 75 m2 $150.875" y no sabe de cual de los diez edificios es.
        $eds = [];
        if (!empty($DER['grupos_edificio'])) {
            foreach ($g['cods'] as $cod)
                if (preg_match('/^([A-Z])-/', $cod, $me))
                    $eds[(string)($DER['grupos_edificio'][$me[1]] ?? $me[1])] = true;
        }
        $ed = count($eds) === 1 ? (string)array_key_first($eds) : '';
        // {cara} en el nombre: el documento escribe "VISTA PARQUE CENTRAL ESQ. 1",
        // con la cara EN MEDIO, no pegada al final. Si el nombre trae el hueco se
        // reemplaza ahi; si no, la cara se agrega detras como antes.
        if (strpos($nomCat, '{cara}') !== false) {
            $nom = str_replace('{cara}', $cara, $nomCat);
        } else {
            $nom = trim($nomCat . ($cara !== '' ? ' ' . $cara : ''));
        }
        $grupos[$k]['nombre'] = trim($nom . ($ed !== '' ? ' · ' . $ed : ''));
        // Parqueos por edificio: G y H llevan 2 por ser de 3 dormitorios. Sale del
        // bloque `parqueos` del archivo del director.
        if ($grupos[$k]['parq'] === null && !empty($DER['parqueos_edificio'])) {
            $pq = [];
            foreach ($g['cods'] as $cod)
                if (preg_match('/^([A-Z])-/', $cod, $mp))
                    $pq[(int)($DER['parqueos_edificio'][$mp[1]] ?? $DER['parqueos_defecto'] ?? 1)] = true;
            // Si el grupo mezcla edificios con distinto parqueo se toma el MENOR: no
            // se le promete al cliente un parqueo que su unidad puede no traer.
            if ($pq) $grupos[$k]['parq'] = min(array_keys($pq));
        }
        $grupos[$k]['zona']   = $cara;
        if ($grupos[$k]['sing'] === '') $grupos[$k]['sing'] = (string)($DER['singular'] ?? '');
    }
}

// ── fila DESDE del bloque, para los proyectos que la declaran en `combos` ───
// Oficinas la lleva en cada piso: 100 m2, 2 parqueos, y el precio de la tabla de
// combos. Es la union de dos oficinas contiguas y cierra el bloque.
if (!empty($L['desde'])) {
    foreach (array_keys($porBloque ?? []) as $_ignora) {}
    foreach ($BLOQUES as $b) {
        $bid = (string)$b['id'];
        $cb  = lst_combo($cfg, (string)($L['desde']['grupo'] ?? ''), $bid);
        if (!$cb) continue;
        // Solo si el bloque tiene alguna fila: no se ofrece una union en un piso
        // donde no queda nada que unir.
        $hay = false;
        foreach ($grupos as $g) if ($g['bloque'] === $bid) { $hay = true; break; }
        if (!$hay) continue;
        $grupos['DESDE|' . $bid] = [
            'bloque' => $bid, 'precio' => $cb['precio'],
            'm2' => $cb['m2'] !== null ? (float)$cb['m2'] : 0.0,
            'nombre' => (string)($L['desde']['rotulo'] ?? 'DESDE'), 'sing' => '',
            'zona' => '', 'catKey' => '', 'parq' => (int)($L['desde']['parqueos'] ?? 2),
            'union' => true, 'calculada' => true, 'cods' => ['', ''],
        ];
    }
}

// ── filas de UNIDADES UNIDAS ────────────────────────────────────────────────
// Dos o tres monoambientes contiguos se venden como un departamento de 2 o 3
// dormitorios. Precio = suma de los PVP menos $20.000, UNA sola vez: al unirse
// viene un parqueo menos, y con tres unidades vienen dos parqueos, asi que
// tambien se resta una vez. Regla del director.
//
// Se muestra la combinacion MAS BARATA de cada tamaño y cara, que es lo que hace
// la lista: es un "desde", y prometer una union que hoy no se puede armar seria
// vender algo que no existe.
$UN = $L['uniones'] ?? null;
if ($UN) {
    $libres = [];   // [edificio][piso][pos] = unidad
    foreach ($unidades as $u => $d) {
        if (($d['etapa'] ?? '') !== 'DISPONIBLE') continue;
        if ((int)($d['tipo'] ?? 0) !== $fam) continue;
        $pvp = (float)($d['pvp'] ?? 0);
        if ($pvp <= 0) continue;
        [$ed, $piso, $pos] = array_pad(explode('-', $u), 3, '0');
        $libres[$ed][(int)$piso][(int)$pos] = [
            'pvp' => $pvp,
            'm2'  => (float)str_replace(',', '.', (string)($d['m2'] ?? 0)),
        ];
    }
    $caraDeU = function (int $pos) use ($UN): ?string {
        foreach ((array)($UN['caras'] ?? []) as $nom => $r)
            if (lp_en_rango($pos, $r)) return (string)$nom;
        return null;
    };
    $prohibido = function (int $a, int $b) use ($UN): bool {
        foreach ((array)($UN['prohibidos'] ?? []) as $par)
            if ((int)$par[0] === $a && (int)$par[1] === $b) return true;
        return false;
    };
    $excl = array_map('intval', (array)($UN['excluir_posiciones'] ?? []));
    $mejores = [];
    foreach ((array)($UN['tamanos'] ?? []) as $t) {
        $n = (int)($t['n'] ?? 0);
        if ($n < 2) continue;
        foreach ($libres as $ed => $porPiso) {
            foreach ($porPiso as $piso => $m) {
                foreach (array_keys($m) as $pos) {
                    $ok = true; $suma = 0.0; $m2 = 0.0; $cara = $caraDeU($pos);
                    if ($cara === null || in_array($pos, $excl, true)) continue;
                    for ($i = 0; $i < $n; $i++) {
                        $q = $pos + $i;
                        if (!isset($m[$q]) || in_array($q, $excl, true)
                            || $caraDeU($q) !== $cara
                            || ($i > 0 && $prohibido($q - 1, $q))) { $ok = false; break; }
                        $suma += $m[$q]['pvp']; $m2 += $m[$q]['m2'];
                    }
                    if (!$ok) continue;
                    $precio = $suma - (float)($UN['descuento'] ?? 20000);
                    $k = $n . '|' . $cara;
                    if (!isset($mejores[$k]) || $precio < $mejores[$k]['precio'])
                        $mejores[$k] = ['precio' => $precio, 'm2' => $m2, 'n' => $n,
                                        'cara' => $cara, 'parq' => (int)($t['parqueos'] ?? 1),
                                        'nombre' => str_replace('{cara}', $cara, (string)($t['nombre'] ?? ''))];
                }
            }
        }
    }
    // Van al final del bloque que declare la familia, como en la lista original.
    $blqU = (string)($UN['bloque'] ?? array_key_first($porBloque ?: ['' => null]));
    foreach ($mejores as $mj) {
        $grupos['UNION|' . $mj['n'] . '|' . $mj['cara']] = [
            'bloque' => $blqU, 'precio' => $mj['precio'], 'm2' => $mj['m2'],
            'nombre' => $mj['nombre'], 'sing' => '', 'zona' => $mj['cara'],
            'parq' => $mj['parq'], 'union' => true, 'calculada' => true,
            // Sin codigos: una union no es una unidad, es una combinacion. Asi
            // tampoco recibe etiqueta de escasez, que no tendria sentido.
            'cods' => ['', ''],
        ];
    }
}

// ── ordenar: bloque, después el LINEAL antes que el resto, después precio ────
// Es el orden del fixture: en el 1er piso van 94.500 · 124.300 · 229.181 (lineal) y
// recién ahí 101.900 · 111.000 · 136.500 · 237.767 (central). No es precio ascendente
// a secas: la zona manda primero.
$ordenBloque = array_flip(array_map(fn($b) => (string)$b['id'], $BLOQUES));
$ORDEN = (string)($L['orden_filas'] ?? 'precio');
$PRIO  = array_flip(array_map('strval', (array)($L['orden_categorias'] ?? [])));
// $ORDEN y $PRIO tienen que entrar al `use`: sin ellos el closure los ve indefinidos,
// imprime un Warning y el orden por categoria no se aplica nunca.
uasort($grupos, function ($a, $b) use ($ordenBloque, $ORDEN, $PRIO) {
    $ba = $ordenBloque[$a['bloque']] ?? 99;
    $bb = $ordenBloque[$b['bloque']] ?? 99;
    if ($ba !== $bb) return $ba <=> $bb;
    // Las uniones NO van al final: en el documento de la direccion el esquinero de
    // 77 m2 va tercero, entre el de 39 y el primer central, o sea en su lugar por
    // precio como cualquier otra fila. Solo las filas CALCULADAS (2 y 3 dormitorios
    // de los monoambientes) van al final, porque no son unidades del inventario.
    $ua = !empty($a['calculada']) ? 1 : 0;
    $ub = !empty($b['calculada']) ? 1 : 0;
    if ($ua !== $ub) return $ua <=> $ub;
    /* Departamentos NO ordena por vista: su 4to piso pone primero las cuatro filas
       de ULTIMA DISPONIBLE (80.315 · 82.863 · 84.863 · 93.760) y despues las cuatro
       tipologias (85.445 · 85.445 · 89.338 · 92.338), cada grupo ascendente. Es el
       mismo criterio con que pinta el color: primero lo que se acaba. */
    if ($ORDEN === 'escasez') {
        $ea = (!empty($a['union']) || count($a['cods']) !== 1) ? 1 : 0;
        $eb = (!empty($b['union']) || count($b['cods']) !== 1) ? 1 : 0;
        if ($ea !== $eb) return $ea <=> $eb;
        return $a['precio'] <=> $b['precio'];
    }
    $za = strpos($a['zona'], 'LINEAL') !== false ? 0 : 1;
    $zb = strpos($b['zona'], 'LINEAL') !== false ? 0 : 1;
    if ($za !== $zb) return $za <=> $zb;
    // Dentro de la zona, DOS ordenes posibles y cada documento usa el suyo:
    //   'precio'    ascendente (Locales, Departamentos)
    //   'categoria' el ESQUINERO antes que el medianero, aunque sea mas caro. Es el
    //               de Oficinas: 2do piso va 144.420 · 153.700 · 132.500, que no es
    //               ascendente ni por casualidad — manda la categoria.
    if ($ORDEN === 'categoria') {
        $pa = $PRIO[$a['catKey']] ?? 99;
        $pb = $PRIO[$b['catKey']] ?? 99;
        if ($pa !== $pb) return $pa <=> $pb;
    }
    return $a['precio'] <=> $b['precio'];
});

/* Fila de UNIDADES UNIDAS por edificio y por piso: "DESDE" en planta baja y "3 DORM"
   arriba. El precio y el metraje NO salen de la tabla `combos`, que envejece: son el par
   contiguo DISPONIBLE mas barato de ese piso. Se comprueba contra su documento —
   EDIFICIO A, planta baja, $312.250: la tabla dice 306.750 (dos medianeros), pero esos
   dos no estan contiguos hoy; el par real es el medianero de la 7 con el esquinero de la
   8 (153.375 + 158.875). El par que cruza el nucleo de ascensores no se puede unir y
   queda fuera por `nucleo_entre`. */
if (!empty($L['combo_por_edificio'])) {
    $etCombo = (array)($cfg['etiquetas_lista']['combo'] ?? []);
    $parqCb  = (int)($cfg['parqueos']['_combo'] ?? 2);
    $eds = [];
    foreach ($grupos as $g) if ((string)($g['ed'] ?? '') !== '') $eds[(string)$g['ed']] = true;
    foreach (array_keys($eds) as $edc) {
        foreach ($BLOQUES as $b) {
            $bid = (string)$b['id'];
            $hay = false;
            foreach ($grupos as $g) if ($g['ed'] === $edc && $g['bloque'] === $bid) { $hay = true; break; }
            if (!$hay) continue;
            $par = lst_par_mas_barato($cfg, $unidades, [$edc], $bid);
            if (!$par) continue;
            $fila = [
                'bloque' => $bid, 'ed' => $edc, 'precio' => (float)$par['precio'],
                'm2' => (float)($par['m2'] ?? 0),
                'nombre' => (string)($etCombo[$bid] ?? 'DESDE'), 'sing' => '',
                'zona' => '', 'catKey' => '', 'parq' => $parqCb,
                'union' => true, 'calculada' => true, 'cods' => ['', ''],
            ];
            /* El patio de la fila unida es el de las DOS unidades: 32,5 donde cada una
               tiene 16,25. */
            if (!empty($patioDe) && $bid === (string)($L['bloque_patio'] ?? 'PB')
                && isset($cfg['metraje'][mz_grupo_de($cfg, $edc)]['patio_pb'])) {
                $pt = 2 * (float)$cfg['metraje'][mz_grupo_de($cfg, $edc)]['patio_pb'];
                $fila['patio'] = rtrim(rtrim(number_format($pt, 2, ',', ''), '0'), ',') . 'm2';
            }
            $grupos['COMBO|' . $edc . '|' . $bid] = $fila;
        }
    }
}

/* HOJAS. Noral Apartments no es una lista sino NUEVE: el PDF aprobado de la direccion
   trae una hoja por edificio, con su titulo ("EDIFICIO A"), su pie y sus filas DESDE y
   3 DORM. Tiene sentido porque ahi el precio cambia de un edificio a otro: cada hoja es
   la lista de ese edificio. En los demas proyectos hay una sola hoja y este bucle da
   una vuelta.
   Ojo con la escasez: dentro de una hoja se cuenta lo de ESE edificio, que es lo que
   hace su documento — "ESQUINEROS 2 · 75 m2 · $158.875" sale con "2 ULTIMAS" en el
   edificio B y otra vez en el D, porque son dos por edificio. La regla general de
   contar en todo el proyecto es para las familias que van en una sola hoja. */
$PAGINAS = [];
if (($L['paginar_por'] ?? '') === 'edificio') {
    $porEd = [];
    foreach ($grupos as $k => $g) {
        $ed = (string)($g['ed'] ?? '');
        if ($ed === '') foreach ($g['cods'] as $c)
            if (preg_match('/^([A-Z])/', (string)$c, $me)) { $ed = $me[1]; break; }
        $porEd[$ed][$k] = $g;
    }
    ksort($porEd);
    foreach ($porEd as $ed => $gs) $PAGINAS[] = ['ed' => (string)$ed, 'grupos' => $gs];
} else {
    $PAGINAS[] = ['ed' => '', 'grupos' => $grupos];
}

$etBloque = [];
foreach ($BLOQUES as $b) $etBloque[(string)$b['id']] = (string)($b['etiqueta'] ?? $b['id']);
$conParq  = !empty($L['columna_parqueos']);
$conPatio = !empty($L['columna_patio']);
$GRUPOS_TODOS = $grupos;
// CUATRO columnas caen bajo CARACTERISTICAS: la banda del bloque, el nombre, los
// metros y el precio. Estaba en 3, y esa columna de menos corria toda la fila de
// grupo un lugar a la izquierda: el "20%" quedaba sobre A LA FIRMA y el "10%" sobre
// CUOTAS MENSUALES, cuando el 10% es el de las EXTRAORDINARIAS. Ademas la banda del
// titulo se quedaba una columna corta.
$nCols   = 4 + ($conParq ? 1 : 0) + ($conPatio ? 1 : 0);
// Las uniones NO suman al conteo: no son unidades, son combinaciones de las que ya
// estan contadas. Sumarlas hacia decir 26 disponibles donde hay 24.
?>
<?php foreach ($PAGINAS as $PAG):
    $grupos = $PAG['grupos'];
    $porBloque = [];
    foreach ($grupos as $g) $porBloque[$g['bloque']][] = $g;
    $totDisp = 0;
    foreach ($grupos as $g) if (empty($g['union'])) $totDisp += count($g['cods']);
    $nUnion = 0;
    foreach ($grupos as $g) if (!empty($g['union']) && $g['cods'] === ['', '']) $nUnion++;
?>
<?php
// Subtitulo con el PLAZO: "40% de Entrada a 54 Meses". Los meses NO se escriben —
// salen de la fecha de entrega, asi que el rotulo baja solo con el calendario.
$pz  = lst_plazo($fin);
$sub = (string)($L['subtitulo'] ?? '');
if ($sub !== '' && strpos($sub, '{meses}') !== false)
    $sub = str_replace('{meses}', (string)$pz['meses'], $sub);
$lg = lst_logo((int)$cat, $L);
?>
<?php
// DOS layouts de cabecera, porque los documentos de la direccion usan dos:
//   'arriba'  (Locales)   el logo arriba a la izquierda y el titulo en banda a TODO
//                         el ancho de la tabla, debajo del logo.
//   'al_lado' (Oficinas, Departamentos)  el logo a la izquierda y la banda del titulo
//                         a su derecha, a la misma altura, FUERA de la tabla.
// Es exactamente como esta en su HTML: `.cab` en flex con el logo y una tablita
// `.tit` al lado. Forzar un solo layout no se parece a ninguno de los dos.
$layout = (string)($L['layout'] ?? 'arriba');
$tituloBase = (string)($L['titulo'] ?? strtoupper($proyecto));
?>
<?php /* El titulo y el pie llevan la letra del edificio cuando la lista va paginada:
         "EDIFICIO A" arriba y "EDIFICIO - A -" abajo, como en su PDF. */
  $titulo = $PAG['ed'] !== '' && !empty($L['titulo_hoja'])
          ? str_replace('{ed}', $PAG['ed'], (string)$L['titulo_hoja']) : $tituloBase;
  $pieRotulo = $PAG['ed'] !== '' && !empty($L['pie2_hoja'])
          ? str_replace('{ed}', $PAG['ed'], (string)$L['pie2_hoja']) : (string)($L['pie2'] ?? '');
?>
<section class="hoja">
  <?php /* En los DOS documentos el logo va FUERA de la tabla, arriba a la izquierda,
           y la tabla empieza debajo alineada con el. La diferencia entre layouts es
           donde vive el TITULO: en 'al_lado' va en su propio recuadro a la derecha del
           logo (Oficinas, Departamentos); en 'arriba', como banda a todo el ancho de
           la tabla (Locales). */ ?>
  <?php if ($layout !== 'en_tabla'): ?>
  <div class="cab<?= $layout === 'al_lado' ? ' cab-lado' : '' ?>">
    <?php if ($lg): ?>
      <img class="logo" src="<?= lh($lg[0]) ?>"
           alt="<?= lh($lg[1] !== '' ? $lg[1] : $proyecto) ?>" onerror="this.style.display='none'">
    <?php else: ?><span></span><?php endif; ?>
    <?php if ($layout === 'al_lado'): ?>
      <div class="tit">
        <table><tr><th class="titulo"><?= lh($titulo) ?></th></tr>
               <tr><th class="sub"><?= lh($sub) ?></th></tr></table>
      </div>
    <?php elseif (!empty($L['leyenda_vista'])): ?>
      <div class="leyenda">
        <span><i class="lin"></i><?= lh((string)$L['leyenda_vista'][0]) ?></span>
        <span><i class="cen"></i><?= lh((string)$L['leyenda_vista'][1]) ?></span>
      </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>
  <div class="wrap">
    <table>
      <thead>
      <?php
        /* El LOGO entra a la tabla solo en 'en_tabla' (Departamentos): ocupa la banda
           y el nombre durante las tres filas de cabecera, y el titulo, el subtitulo y
           la fila de porcentajes van a su derecha. Ese es el unico documento donde la
           banda del titulo arranca en la columna de METROS y no en el borde de la
           tabla. Como el logo se come 2 columnas, todo lo que va a su derecha resta 2. */
        $enTabla = $layout === 'en_tabla';
        $resta   = $enTabla ? 2 : 0;
      ?>
      <?php if ($enTabla): ?>
        <tr>
          <?php if ($lg): ?>
            <td class="celda-logo" rowspan="3" colspan="2"><img src="<?= lh($lg[0]) ?>"
                 alt="<?= lh($lg[1] !== '' ? $lg[1] : $proyecto) ?>" onerror="this.style.display='none'"></td>
          <?php else: ?><td rowspan="3" colspan="2"></td><?php endif; ?>
          <th class="titulo" colspan="<?= $nCols + 4 - $resta ?>"><?= lh($titulo) ?></th></tr>
        <tr><th class="sub" colspan="<?= $nCols + 4 - $resta ?>"><?= lh($sub) ?></th></tr>
      <?php elseif ($layout !== 'al_lado'): ?>
        <tr><th class="titulo" colspan="<?= $nCols + 4 ?>"><?= lh($titulo) ?></th></tr>
        <tr><th class="sub" colspan="<?= $nCols + 4 ?>"><?= lh($sub) ?></th></tr>
      <?php endif; ?>
        <?php /* El logo vive FUERA de la tabla en los dos layouts, asi que esta fila
                 siempre cubre las mismas columnas: banda + nombre + metros +
                 [parqueos] + precio. Habia quedado una version con $colLogo, que ya
                 no existe: el Warning se imprimia DENTRO de la celda y el colspan
                 salia mal, con lo que los porcentajes volvian a quedar corridos. */ ?>
        <tr class="g"><th colspan="<?= $nCols - $resta ?>">CARACTER&Iacute;STICAS</th>
          <th colspan="2" class="it"><?= (int)($fin['reserva_pct'] ?? 10) ?>% DE RESERVA</th>
          <th><?= (int)($fin['cuotas_pct'] ?? 20) ?>%</th>
          <th><?= (int)($fin['extra_pct'] ?? 10) ?>%</th></tr>
        <tr class="c"><th></th><th><?= $L['encabezado_cat'] ?? 'CARACTER&Iacute;STICAS' ?></th>
          <th><?= $L['encabezado_metros'] ?? 'METROS (m2)' ?></th><?php if ($conParq): ?><th>PARQUEOS</th><?php endif; ?>
          <?php if ($conPatio): ?><th><?= $L['encabezado_patio'] ?? 'M2 PATIO' ?></th><?php endif; ?>
          <th><?= $L['encabezado_precio'] ?? 'PRECIO' ?></th>
          <th>SEPARA CON</th><th>A LA FIRMA</th>
          <th>CUOTAS<br>MENSUALES</th>
          <th>CUOTAS EXTRAORDINARIAS<br>(1 VEZ AL A&Ntilde;O)</th></tr>
      </thead>
      <tbody>
      <?php $iB = 0; foreach ($porBloque as $blq => $filas): $iB++;
            /* El color de la banda del piso lo fija el documento, no el numero de
               piso: en Locales los DOS bloques son ocres, en Oficinas van oscuro,
               medio y ocre. Si el JSON no lo dice, se cae al ciclo de siempre. */
            $bandas = (array)($L['colores_banda'] ?? []);
            $clsNiv = $bandas ? 'niv-' . preg_replace('/[^a-z]/', '', (string)($bandas[$iB - 1] ?? end($bandas)))
                              : 'niv' . (($iB - 1) % 5 + 2);
            $primera = true; ?>
        <?php foreach ($filas as $g): $pl = lst_plan($g['precio'], $fin, $g['extra_n'] ?? null);
              $n = count($g['cods']);
              // Con UNA disponible la fila se nombra con el codigo real, no con la
              // tipologia: "LOCAL A-1-12", "RESTAURANTE C-7". Lo pide la spec y es lo
              // que hace la presion de escasez concreta.
              if (!empty($g['union'])) $n = 99;   // no es una unidad: no aplica escasez
              // Con UNA disponible la fila se nombra con el codigo. El prefijo
              // ("LOCAL A-1-12", "RESTAURANTE C-7") solo va donde el documento lo pone:
              // en Departamentos la fila dice "F-4-18" a secas.
              $texto = $n !== 1 ? $g['nombre']
                     : (($g['sing'] !== '' && empty($L['codigo_sin_prefijo']))
                          ? trim($g['sing'] . ' ' . $g['cods'][array_key_first($g['cods'])])
                          : $g['cods'][array_key_first($g['cods'])]);
              /* Hay listas donde cada fila ES una unidad —Torre D tiene 13 filas y 13
                 unidades— y ahi la etiqueta de escasez se repite en todas y no dice
                 nada: quema la unica palanca de urgencia que tiene el asesor. */
              $ult = (!empty($L['sin_badges']) || !empty($g['union'])) ? ''
                   : ($n === 1 ? (string)($L['badge_uno'] ?? 'ÚLTIMA UNIDAD')
                   : ($n === 2 ? (string)($L['badge_dos'] ?? '2 ÚLTIMAS DISPONIBLES') : '')); ?>
          <tr>
            <?php if ($primera): $primera = false; ?>
              <td class="niv <?= $clsNiv ?>" rowspan="<?= count($filas) ?>"><span><?= lh(strtoupper($etBloque[$blq] ?? $blq)) ?></span></td>
            <?php endif; ?>

            <?php
              /* El COLOR no significa lo mismo en los dos documentos, y hay que
                 respetar cada uno:
                   'vista'   (Locales, Oficinas) crema = parque lineal · verde = central
                             Por eso esos documentos llevan LEYENDA que lo explica.
                   'escasez' (Departamentos) crema = ULTIMA DISPONIBLE · verde = tipologia
                             Ese documento NO lleva leyenda, justamente porque el color
                             no habla de la vista.
                 Se comprobo fila por fila contra su PDF de Departamentos: las cinco
                 filas crema son exactamente las cinco que dicen "ULTIMA DISPONIBLE",
                 incluida F-4-12, que es posicion 12 y da al parque CENTRAL. Si el color
                 fuera la vista, esa seria verde. */
              $porEscasez = ($L['color_por'] ?? 'vista') === 'escasez';
              $esLin  = strpos($g['zona'], 'LINEAL') !== false;
              /* Los tres documentos usan pares de color DISTINTOS:
                   Locales      palido (lineal)  + fuerte (central)
                   Oficinas     crema  (lineal)  + palido (central)
                   Departamentos crema (ultima)  + palido (tipologia)
                 Por eso el par se declara en el JSON y no se hornea en el CSS. */
              $colA = (string)($L['color_a'] ?? 'crema');
              $colB = (string)($L['color_b'] ?? 'palido');
              if ($porEscasez) {
                  $cls    = $n === 1 ? $colA : $colB;
                  $fuerte = !empty($g['union']);
              } else {
                  $cls    = $esLin ? $colA : ($g['zona'] !== '' ? $colB : '');
                  $fuerte = !empty($g['union'])
                              ? !empty($L['union_fuerte'])
                              : (!$esLin && !empty($L['central_fuerte']));
              }
            ?>
            <?php $alin = ($L['alineacion_nombre'] ?? 'centro') === 'izquierda' ? ' izq' : ''; ?>
            <td class="cat <?= $cls ?><?= $alin ?><?= $fuerte ? ' fuerte' : '' ?>"><?php
                echo lh($texto);
                if ($ult !== '') echo '<span class="ult">' . lh($ult) . '</span>'; ?></td>
            <?php /* Sus documentos escriben 32.5 y 94.5 con PUNTO. Bitrix guarda el
                     metraje como texto con coma; convertirlo aqui evita que la lista
                     mezcle las dos formas. */ ?>
            <?php $dm = (string)($L['decimal_metros'] ?? '.'); ?>
            <td class="c"><?= lh(rtrim(rtrim(number_format($g['m2'], 2, $dm, ''), '0'), $dm)) ?></td>
            <?php if ($conParq): ?><td class="c"><?= (int)($g['parq'] ?? 1) ?></td><?php endif; ?>
            <?php /* El patio solo existe en planta baja; en los demas pisos su documento
                     imprime un guion, no una celda vacia. */ ?>
            <?php if ($conPatio): ?><td class="c"><?= $g['patio'] ?? '-' ?></td><?php endif; ?>
            <?php /* Locales escribe "$94,500" pegado; Oficinas y Departamentos
                     "$ 144,420" con espacio. Es del documento, no del formateador. */ ?>
            <td class="p"><?= lh(empty($L['precio_pegado']) ? lp($g['precio'])
                                  : '$' . number_format($g['precio'], 0)) ?></td>
            <td class="n"><?= lh(ln($pl['separa'])) ?></td>
            <td class="n"><?= lh(ln($pl['firma'])) ?></td>
            <td class="n"><?= lh(ln($pl['mensual'])) ?></td>
            <td class="n"><?= lh(ln($pl['extra'])) ?></td>
          </tr>
        <?php endforeach; ?>
      <?php endforeach; ?>
      <?php if (!$grupos): ?>
        <tr><td colspan="<?= $nCols + 4 ?>" style="text-align:center;padding:26px">
          No hay unidades disponibles de esta familia.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
    <?php /* La nota lateral es HERMANA de la tabla dentro de `.wrap`, que es flex: asi
             cubre toda su altura sin ser una columna, que descuadraba los colspan de
             la cabecera. */ ?>
    <?php if (!empty($L['lat'])): ?><div class="lat"><?= $L['lat'] ?></div><?php endif; ?>

  </div>
  <?php if ($pieRotulo !== ''): ?>
    <?php /* Cierre de dos bloques: el rotulo y el rango de metros. El rango se calcula
             de las filas, no se escribe: si entra una tipologia nueva se ajusta solo. */
      $mm = [];
      foreach ($grupos as $g) if ($g['m2'] > 0) $mm[] = (float)$g['m2'];
      /* Apartments escribe "75m2 & 85,15m2 & 150m2": separador " & " y decimales con
         COMA. Departamentos escribe "31m2 hasta 94.5m2", con punto. Es del documento. */
      $dec = (string)($L['pie2_decimal'] ?? '.');
      $fmt = fn($v) => rtrim(rtrim(number_format($v, 2, $dec, ''), '0'), $dec);
    ?>
    <?php /* Las medidas del pie: cada documento las escribe distinto. Oficinas lista
             los metrajes ("50 - 58 - 100m2") y Departamentos da el rango ("31m2 hasta
             94.5m2"). Si la familia declara `pie2_medidas` manda lo suyo; si no, se
             calcula el rango de las filas, que se ajusta solo. */ ?>
    <div class="pie2<?= !empty($L['pie2_lista']) ? ' corto' : '' ?>"><b><?= lh($pieRotulo) ?></b><span><?php
        if (!empty($L['pie2_medidas'])) {
            echo lh((string)$L['pie2_medidas']);
        } elseif (!empty($L['pie2_lista'])) {
            $u = array_values(array_unique($mm)); sort($u);
            $sep = (string)($L['pie2_sep'] ?? ' - ');
            echo lh(implode('m2' . $sep, array_map($fmt, $u)) . 'm2');
        } elseif ($mm) {
            echo lh($fmt(min($mm)) . 'm2 hasta ' . $fmt(max($mm)) . 'm2');
        } ?></span></div>
  <?php endif; ?>
  <?php if (!empty($L['notas'])): ?>
    <div class="notas"><?php foreach ((array)$L['notas'] as $nt): ?><p><?= $nt ?></p><?php endforeach; ?></div>
  <?php endif; ?>
  <?php if (empty($L['sin_vigencia'])): ?>
  <div class="vig">ESTA COTIZACI&Oacute;N TIENE UNA VIGENCIA DE
    <?= (int)($pz['meses'] > 0 ? ($fin['vigencia_horas'] ?? 48) : 48) ?> HRS NATURALES</div>
  <?php endif; ?>
  <?php /* La nota del documento de la direccion. El detalle de cuando se genero y
           cuantas tipologias hay es interno: va en un title, no impreso. */ ?>
  <div class="meta" title="Generada el <?= lh($hoy->format('d/m/Y H:i')) ?> desde el inventario en vivo · <?= count($grupos) - $nUnion ?> tipologías · <?= $totDisp ?> disponibles<?php
      if ($nUnion) echo ' · ' . $nUnion . ($nUnion > 1 ? ' opciones' : ' opción') . ' de unidades unidas'; ?>"><?php
      /* Solo el documento de LOCALES lleva impresa esta linea. Los de Oficinas y
         Departamentos terminan en el pie verde. */
      if (empty($L['sin_meta'])) echo 'Precios sujetos a disponibilidad'; ?></div>
</section>
<?php endforeach; $grupos = $GRUPOS_TODOS; ?>
