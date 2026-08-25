<?php
/**
 * lista_solar.php — la lista de Sun Bay: una fila por SOLAR.
 *
 * Formato de la direccion (`Lista de Precios - Sun Bay Engabao`). Aca no hay
 * tipologias que agrupar: cada solar tiene precio propio segun su ubicacion, y la
 * lista lo dice solar por solar, agrupado por manzana.
 *
 * Se incluye desde lista.php, que ya valido el token y dejo listos $cfg, $L,
 * $unidades, $proyecto y las funciones lh()/lp()/ln().
 */
$fin = $L['financiamiento'] ?? [];
$filas = [];
foreach ($unidades as $u => $d) {
    if (($d['etapa'] ?? '') !== 'DISPONIBLE') continue;
    if ((int)($d['tipo'] ?? 0) !== $fam) continue;
    $pvp = (float)($d['pvp'] ?? 0);
    if ($pvp <= 0) continue;
    $cod = (string)($d['cod'] ?? $u);
    if (!preg_match('/^([A-Z])-(\d+)$/', $cod, $m)) continue;
    $m2 = (string)($d['m2'] ?? '');
    $filas[] = ['mz' => $m[1], 'n' => (int)$m[2],
                'm2' => $m2 !== '' ? (float)str_replace(',', '.', $m2) : null,
                'pvp' => $pvp, 'plan' => lst_plan($pvp, $fin)];
}
// Por manzana y numero: es el orden del plano, no el alfabetico.
usort($filas, fn($a, $b) => [$a['mz'], $a['n']] <=> [$b['mz'], $b['n']]);
$meses = $filas ? (int)$filas[0]['plan']['meses'] : (int)($fin['meses'] ?? 49);
?>
<?php
/* S-03: una HOJA POR GRUPO DE MANZANAS (B·D·F · H · J · L), no una tabla continua de 63
   filas. En 63 renglones seguidos el chip de color de la manzana es lo unico que deja
   ubicarse, y aun asi el vendedor pierde el hilo. Los grupos los declara el JSON. */
$GRUPOS_HOJA = (array)($L['hojas_manzanas'] ?? []);
if (!$GRUPOS_HOJA) {
    $mzs = [];
    foreach ($filas as $r) $mzs[$r['mz']] = true;
    $GRUPOS_HOJA = [array_keys($mzs)];
}
$TODAS = $filas;
foreach ($GRUPOS_HOJA as $GRUPO):
    $filas = array_values(array_filter($TODAS, fn($r) => in_array($r['mz'], (array)$GRUPO, true)));
    if (!$filas) continue;
?>
<section class="hoja"<?= !empty($L['tema']) ? ' data-tema="' . lh((string)$L['tema']) . '"' : '' ?>>
  <div class="cab">
    <?php /* El logo del PROYECTO, solo. En los documentos de la direccion la lista de
             precios lleva la marca del proyecto —Galero, Sun Bay, Noral— y NO la de
             Galjosa: es material de venta de ese proyecto, no corporativo. */
          $lg = lst_logo((int)$cat, $L); ?>
    <?php if ($lg): ?>
      <img class="logo" src="<?= lh($lg[0]) ?>"
           alt="<?= lh($lg[1] !== '' ? $lg[1] : $proyecto) ?>" onerror="this.style.display='none'">
    <?php else: ?>
      <img class="logo" src="assets/logo_galjosa_transparente.png"
           alt="Galjosa" onerror="this.style.display='none'">
    <?php endif; ?>
    <div class="tit">
      <table><tr><th class="titulo"><?= $L['titulo_html'] ?? lh((string)($L['titulo'] ?? 'TERRENOS DISPONIBLES')) ?></th></tr>
             <tr><th class="sub"><?= lh((string)($L['subtitulo'] ?? '')) ?></th></tr></table>
    </div>
  </div>
  <table>
    <thead>
      <tr class="g">
        <th colspan="4"><?= (int)($fin['entrada_pct'] ?? 70) ?>% de Entrada</th>
        <th colspan="4">FINANCIADO A <?= $meses ?> MESES PLAZO</th>
        <th><?= (int)($fin['contra_pct'] ?? 30) ?>%</th>
      </tr>
      <tr class="c">
        <th>MZ.</th><th># SOLAR</th><th>AREA M2<br>del Solar</th>
        <th>PRECIO DEL<br>SOLAR</th><th>SEPARE<br>CON</th>
        <th>A LA FIRMA<br>(<?= (int)($fin['reserva_pct'] ?? 20) ?>%)</th>
        <th>CUOTA<br>MENSUAL</th>
        <th>1 CUOTA<br>EXTRAORDINARIA<br>POR A&Ntilde;O DE:</th>
        <th>Saldo Contra Entrega<br>(Cr&eacute;dito)</th>
      </tr>
    </thead>
    <tbody>
    <?php $mzPrev = null; foreach ($filas as $r): $pl = $r['plan'];
          // Una linea en blanco al cambiar de manzana, como la lista original.
          if ($mzPrev !== null && $r['mz'] !== $mzPrev): ?>
            <tr><td class="esp" colspan="9"></td></tr>
          <?php endif; $mzPrev = $r['mz']; ?>
      <tr>
        <?php /* El chip de color de la manzana. En 63 filas es lo unico que deja
                 ubicarse de un vistazo, y el color es el del plano: B y D dorado,
                 F azul marino, H ciruela, J verde, L azul. */
          $clMz = ($L['colores_manzana'] ?? [])[$r['mz']] ?? null; ?>
        <td class="mz"<?= $clMz ? ' style="background:' . lh((string)$clMz[0]) . ';color:'
              . lh((string)($clMz[1] ?? '#000')) . '"' : '' ?>><?= lh($r['mz']) ?></td>
        <td class="c"><?= (int)$r['n'] ?></td>
        <td class="c"><?= $r['m2'] !== null ? lh(rtrim(rtrim(number_format($r['m2'], 2, ',', ''), '0'), ',')) : '—' ?></td>
        <td class="p"><?= lh(ln($r['pvp'])) ?></td>
        <td class="n"><?= lh(ln($pl['separa'])) ?></td>
        <td class="n"><?= lh(ln($pl['firma'])) ?></td>
        <td class="n"><?= lh(ln($pl['mensual'])) ?></td>
        <td class="n"><?= lh(ln($pl['extra'])) ?></td>
        <td class="sal"><?= lh(ln($pl['contra'])) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$filas): ?>
      <tr><td colspan="9" style="text-align:center;padding:26px">No hay solares disponibles.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
  <?php /* Su documento cierra con UNA nota amarilla que ya incluye la vigencia. */ ?>
  <div class="nota"><?= str_replace(['{horas}', '{meses}'],
        [(string)(int)($fin['vigencia_horas'] ?? 48), (string)$meses],
        (string)($L['nota'] ?? '')) ?></div>
  <div class="meta">Generada el <?= lh($hoy->format('d/m/Y H:i')) ?> desde el inventario en vivo ·
    <?= count($filas) ?> solares disponibles</div>
</section>
<?php endforeach; $filas = $TODAS; ?>
