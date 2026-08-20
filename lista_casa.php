<?php
/**
 * lista_casa.php — Galero Casas: una tabla por modelo de casa.
 *
 * Formato de la direccion (`lista.py` de Galero Casas). El precio de un solar
 * depende del modelo que el cliente elija, asi que la lista saca UNA TABLA POR
 * MODELO Pelicano con los solares que lo admiten. Un solar que no puede recibir
 * esa casa no aparece en su tabla: F-7 y G-7 solo salen en la del Pelicano 3,
 * porque su terreno no llega a 18 m de profundidad.
 *
 * La lista es para el cliente: NO lleva el costo del terreno ni el precio por m2.
 * Eso es interno y vive en el motor.
 */
$fin = $L['financiamiento'] ?? [];
$px  = mz_precios_vigentes($cfg);
$modelos = (array)($L['orden'] ?? array_keys($cfg['categorias'] ?? []));

// Los solares disponibles con su area, una sola vez: las tablas de los cuatro
// modelos recorren el mismo inventario.
$solares = [];
foreach ($unidades as $u => $d) {
    if (($d['etapa'] ?? '') !== 'DISPONIBLE') continue;
    if ((int)($d['tipo'] ?? 0) !== $fam) continue;
    $cod = (string)($d['cod'] ?? $u);
    if (!preg_match('/^([A-Z])-(\d+)$/', $cod, $m)) continue;
    if (mz_por_unidad($cfg, $cfg['exentas'] ?? [], $m[1], 1, (int)$m[2]) !== null) continue;
    $t = $cfg['terreno'] ?? [];
    $area = $t['areas'][$cod] ?? null;
    if ($area === null) continue;              // sin area no hay precio: no se ofrece
    $solares[] = ['mz' => $m[1], 'n' => (int)$m[2], 'cod' => $cod, 'area' => (float)$area,
                  'terreno' => mz_terreno_de($cfg, $m[1], (int)$m[2], "{$m[1]}-1-{$m[2]}")];
}
usort($solares, fn($a, $b) => [$a['mz'], $a['n']] <=> [$b['mz'], $b['n']]);
$COL = (array)($L['colores_manzana'] ?? []);
?>
<section class="hoja">
  <div class="cab">
    <img class="logo" src="assets/logo_galjosa_transparente.png"
         alt="<?= lh($proyecto) ?>" onerror="this.style.display='none'">
    <div class="tit">
      <table><tr><th class="titulo"><?= lh((string)($L['titulo'] ?? strtoupper($proyecto))) ?></th></tr>
             <tr><th class="sub"><?= lh((string)($L['subtitulo'] ?? '')) ?></th></tr></table>
    </div>
  </div>

<?php foreach ($modelos as $k):
    $casa = $px[array_key_first($cfg['precios'])]['U'][$k] ?? null;
    if ($casa === null) continue;
    $m2c = $cfg['metraje'][array_key_first($cfg['metraje'])]['por_categoria'][$k] ?? null;
    $m2c = $m2c ?? ($cfg['metraje'][array_key_first($cfg['grupos'])]['por_categoria'][$k] ?? null);
    $nom = (string)($L['nombre'][$k] ?? ($cfg['categorias'][$k]['etiqueta'] ?? $k));
    // Solo los solares que ADMITEN este modelo. La restriccion la declara la matriz
    // con un override de categoria: si un solar solo puede recibir el Pelicano 3, su
    // override lo dice y no aparece en las otras tres tablas.
    $filas = [];
    foreach ($solares as $sl) {
        $ov = mz_por_unidad($cfg, $cfg['overrides_unidad'] ?? [], $sl['mz'], 1, $sl['n']) ?? [];
        $solo = (string)($ov['categoria'] ?? '');
        if ($solo !== '' && $solo !== $k) continue;
        $filas[] = $sl + ['total' => $sl['terreno'] + (float)$casa];
    }
    if (!$filas) continue;
?>
  <table style="margin-bottom:14px">
    <thead>
      <tr class="g"><th colspan="4">CASA <?= lh(strtoupper($nom)) ?><?php
          if ($m2c !== null) echo ' - ' . (int)$m2c . ' MTS DE CONSTRUCCI&Oacute;N'; ?></th>
        <th colspan="4">FINANCIADO HASTA <?= (int)($fin['meses'] ?? 36) ?> MESES</th></tr>
      <tr class="c"><th>MZ.</th><th># SOLAR</th><th>AREA M2<br>del Solar</th>
        <th><?= lh(strtoupper($nom)) ?><?php if ($m2c !== null) echo '<br>(' . (int)$m2c . 'mts)'; ?></th>
        <th>SEPARE<br>CON</th>
        <th>A LA FIRMA<br>(<?= (int)($fin['reserva_pct'] ?? 10) ?>%)</th>
        <th>CUOTA MENSUAL<br>(<?= (int)($fin['cuotas_pct'] ?? 20) ?>%)</th>
        <th>1 CUOTA EXTRAORDINARIA<br>POR A&Ntilde;O (<?= (int)($fin['extra_pct'] ?? 10) ?>%)</th></tr>
    </thead>
    <tbody>
    <?php foreach ($filas as $r): $pl = lst_plan($r['total'], $fin);
          $cl = $COL[$r['mz']] ?? null; ?>
      <tr>
        <td class="mz"<?= $cl ? ' style="background:' . lh((string)$cl[0]) . ';color:' . lh((string)($cl[1] ?? '#000')) . '"' : '' ?>><?= lh($r['mz']) ?></td>
        <td class="c"><?= (int)$r['n'] ?></td>
        <td class="n"><?= lh(number_format($r['area'], 2)) ?></td>
        <td class="p"><?= lh(ln($r['total'])) ?></td>
        <td class="n"><?= lh(ln($pl['separa'])) ?></td>
        <td class="n"><?= lh(ln($pl['firma'])) ?></td>
        <td class="n"><?= lh(ln($pl['mensual'])) ?></td>
        <td class="n"><?= lh(ln($pl['extra'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endforeach; ?>

  <?php if (!empty($L['nota'])): ?><div class="nota"><?= $L['nota'] ?></div><?php endif; ?>
  <div class="vig">ESTA COTIZACI&Oacute;N TIENE UNA VIGENCIA DE
    <?= (int)($fin['vigencia_horas'] ?? 48) ?> HRS NATURALES</div>
  <div class="meta">Generada el <?= lh($hoy->format('d/m/Y H:i')) ?> desde el inventario en vivo ·
    <?= count($solares) ?> solares disponibles</div>
</section>
