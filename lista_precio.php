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
    $key = $blq . '|' . round($pvp) . '|' . rtrim(rtrim(number_format($m2, 2, '.', ''), '0'), '.');
    if (!isset($grupos[$key])) {
        $t = $TIPOS[round($pvp) . '|' . rtrim(rtrim(number_format($m2, 2, '.', ''), '0'), '.')] ?? [];
        $grupos[$key] = ['bloque' => $blq, 'precio' => $pvp, 'm2' => $m2,
                         'nombre' => (string)($t['nombre'] ?? ''), 'sing' => (string)($t['singular'] ?? ''),
                         'zona' => (string)($t['zona'] ?? ''), 'parq' => $t['parqueos'] ?? null,
                         'union' => !empty($t['union']), 'cods' => []];
    }
    $grupos[$key]['cods'][] = (string)($d['cod'] ?? $u);
}
foreach ($grupos as &$g) natsort($g['cods']);
unset($g);

// ── nombre DERIVADO, cuando la familia no lista precio por precio ───────────
// Los monoambientes cambian de precio seguido y mantener una tabla de 9 llaves
// `precio|m2` a mano se desactualiza sola. Si la familia declara el mapa de
// posiciones, el nombre se arma de las unidades del grupo: su categoria y su cara.
$DER = $L['derivar_nombre'] ?? null;
if ($DER) {
    $catDe = function (int $pos) use ($DER): string {
        foreach ((array)($DER['categorias'] ?? []) as $nom => $poss)
            if (in_array($pos, (array)$poss, true)) return (string)$nom;
        return (string)($DER['categoria_por_defecto'] ?? '');
    };
    $caraDe = function (int $pos) use ($DER): string {
        foreach ((array)($DER['caras'] ?? []) as $nom => $r)
            if ($pos >= (int)$r[0] && $pos <= (int)$r[1]) return (string)$nom;
        return '';
    };
    foreach ($grupos as $k => $g) {
        if ($g['nombre'] !== '') continue;
        $cats = []; $caras = [];
        foreach ($g['cods'] as $cod) {
            if (!preg_match('/^[A-Z]-\d+-(\d+)$/', $cod, $mm)) continue;
            $cats[$catDe((int)$mm[1])] = true;
            $caras[$caraDe((int)$mm[1])] = true;
        }
        // Si el grupo mezcla categorias o caras se deja el nombre generico: inventar
        // uno seria prometerle al cliente una ubicacion que no todas tienen.
        $cat  = count($cats) === 1 ? (string)array_key_first($cats) : (string)($DER['mezcla'] ?? '');
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
        $grupos[$k]['nombre'] = trim($cat . ($cara !== '' ? ' ' . $cara : '')
                                          . ($ed !== '' ? ' · ' . $ed : ''));
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
            if ($pos >= (int)$r[0] && $pos <= (int)$r[1]) return (string)$nom;
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
            'parq' => $mj['parq'], 'union' => true,
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
uasort($grupos, function ($a, $b) use ($ordenBloque) {
    $ba = $ordenBloque[$a['bloque']] ?? 99;
    $bb = $ordenBloque[$b['bloque']] ?? 99;
    if ($ba !== $bb) return $ba <=> $bb;
    // Las uniones van al final del bloque: son la opcion grande, no el piso de precio.
    $ua = !empty($a['union']) ? 1 : 0;
    $ub = !empty($b['union']) ? 1 : 0;
    if ($ua !== $ub) return $ua <=> $ub;
    $za = $a['zona'] === 'LINEAL' ? 0 : 1;
    $zb = $b['zona'] === 'LINEAL' ? 0 : 1;
    if ($za !== $zb) return $za <=> $zb;
    return $a['precio'] <=> $b['precio'];
});

// por bloque, para la banda vertical
$porBloque = [];
foreach ($grupos as $g) $porBloque[$g['bloque']][] = $g;

$etBloque = [];
foreach ($BLOQUES as $b) $etBloque[(string)$b['id']] = (string)($b['etiqueta'] ?? $b['id']);
$conParq = !empty($L['columna_parqueos']);
$nCols   = 3 + ($conParq ? 1 : 0);
// Las uniones NO suman al conteo: no son unidades, son combinaciones de las que ya
// estan contadas. Sumarlas hacia decir 26 disponibles donde hay 24.
$totDisp = 0;
foreach ($grupos as $g) if (empty($g['union'])) $totDisp += count($g['cods']);
$nUnion = 0;
foreach ($grupos as $g) if (!empty($g['union']) && $g['cods'] === ['', '']) $nUnion++;
?>
<section class="hoja">
  <div class="cab">
    <?php /* Dos logos, como las listas de la direccion: el de Galjosa y el del
             PROYECTO. Sin el propio el documento parece de otra empresa. */
          $lg = lst_logo($cat, $L); ?>
    <img class="logo" src="assets/logo_galjosa_transparente.png"
         alt="Galjosa" onerror="this.style.display='none'">
    <?php if ($lg): ?>
      <img class="logo logo-proy" src="<?= lh($lg[0]) ?>"
           alt="<?= lh($lg[1] !== '' ? $lg[1] : $proyecto) ?>" onerror="this.style.display='none'">
    <?php endif; ?>
    <div class="tit">
      <table><tr><th class="titulo"><?= lh((string)($L['titulo'] ?? strtoupper($proyecto))) ?></th></tr>
             <tr><th class="sub"><?= lh((string)($L['subtitulo'] ?? '')) ?></th></tr></table>
    </div>
  </div>
  <div class="wrap">
    <table>
      <thead>
        <tr class="g"><th colspan="<?= $nCols ?>">CARACTER&Iacute;STICAS</th>
          <th colspan="2" class="it"><?= (int)($fin['reserva_pct'] ?? 10) ?>% DE RESERVA</th>
          <th><?= (int)($fin['cuotas_pct'] ?? 20) ?>%</th>
          <th><?= (int)($fin['extra_pct'] ?? 10) ?>%</th></tr>
        <tr class="c"><th></th><th><?= $L['encabezado_cat'] ?? 'CARACTER&Iacute;STICAS' ?></th>
          <th>METROS (m2)</th><?php if ($conParq): ?><th>PARQUEOS</th><?php endif; ?><th>PRECIO</th>
          <th>SEPARA CON</th><th>A LA FIRMA</th>
          <th>CUOTAS<br>MENSUALES</th>
          <th>CUOTAS EXTRAORDINARIAS<br>(1 VEZ AL A&Ntilde;O)</th></tr>
      </thead>
      <tbody>
      <?php $iB = 0; foreach ($porBloque as $blq => $filas): $iB++;
            $clsNiv = 'niv' . (($iB - 1) % 5 + 2); $primera = true; ?>
        <?php foreach ($filas as $g): $pl = lst_plan($g['precio'], $fin);
              $n = count($g['cods']);
              // Con UNA disponible la fila se nombra con el codigo real, no con la
              // tipologia: "LOCAL A-1-12", "RESTAURANTE C-7". Lo pide la spec y es lo
              // que hace la presion de escasez concreta.
              if (!empty($g['union'])) $n = 99;   // no es una unidad: no aplica escasez
              $texto = $n === 1 && $g['sing'] !== ''
                     ? trim($g['sing'] . ' ' . $g['cods'][array_key_first($g['cods'])])
                     : ($n === 1 ? $g['cods'][array_key_first($g['cods'])] : $g['nombre']);
              $ult = !empty($g['union']) ? ''
                   : ($n === 1 ? (string)($L['badge_uno'] ?? 'ÚLTIMA UNIDAD')
                   : ($n === 2 ? (string)($L['badge_dos'] ?? '2 ÚLTIMAS DISPONIBLES') : '')); ?>
          <tr>
            <?php if ($primera): $primera = false; ?>
              <td class="niv <?= $clsNiv ?>" rowspan="<?= count($filas) ?>"><span><?= lh(strtoupper($etBloque[$blq] ?? $blq)) ?></span></td>
            <?php endif; ?>
            <td class="cat <?= $g['zona'] === 'LINEAL' ? 'lineal' : ($g['zona'] !== '' ? 'central' : '') ?>"><?php
                echo lh($texto);
                if ($ult !== '') echo '<span class="ult">' . lh($ult) . '</span>'; ?></td>
            <td class="c"><?= lh(rtrim(rtrim(number_format($g['m2'], 2, ',', ''), '0'), ',')) ?></td>
            <?php if ($conParq): ?><td class="c"><?= (int)($g['parq'] ?? 1) ?></td><?php endif; ?>
            <td class="p"><?= lh(lp($g['precio'])) ?></td>
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
    <?php if (!empty($L['lat'])): ?><div class="lat"><?= $L['lat'] ?></div><?php endif; ?>
  </div>
  <?php if (!empty($L['pie'])): ?>
    <div class="pie"><?= lh((string)($L['pie']['rotulo'] ?? '')) ?><?php
        if (!empty($L['pie']['medidas'])) echo '<span>' . lh((string)$L['pie']['medidas']) . '</span>'; ?></div>
  <?php endif; ?>
  <div class="vig">ESTA COTIZACI&Oacute;N TIENE UNA VIGENCIA DE
    <?= (int)($fin['vigencia_horas'] ?? 48) ?> HRS NATURALES</div>
  <div class="meta">Generada el <?= lh($hoy->format('d/m/Y H:i')) ?> desde el inventario en vivo ·
    <?= count($grupos) - $nUnion ?> tipologías · <?= $totDisp ?> disponibles<?php
      if ($nUnion) echo ' · ' . $nUnion . ($nUnion > 1 ? ' opciones' : ' opción') . ' de unidades unidas'; ?></div>
</section>
