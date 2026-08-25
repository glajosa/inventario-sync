<?php
/**
 * lista_hipotecario.php — Torre C y Suites: entrega inmediata, credito hipotecario.
 *
 * Formato de la direccion (`Lista de Precios - Galero Torre C`, `SUITES GALERO`).
 * Aca no hay cuotas del constructor: el cliente pone la entrada y el saldo lo cubre
 * un banco, asi que la columna que le importa es la CUOTA DEL PRESTAMO a 20 anios,
 * calculada con amortizacion francesa a la tasa vigente.
 *
 * Una fila por unidad, no por tipologia: son proyectos chicos —6 y 3 disponibles— y
 * la direccion las nombra una por una.
 */
$fin = $L['financiamiento'] ?? [];
$filas = [];
foreach ($unidades as $u => $d) {
    if (($d['etapa'] ?? '') !== 'DISPONIBLE') continue;
    if ((int)($d['tipo'] ?? 0) !== $fam) continue;
    $pvp = (float)($d['pvp'] ?? 0);
    if ($pvp <= 0) continue;
    [$ed, $piso, $pos] = array_pad(explode('-', $u), 3, '0');
    $m2 = (string)($d['m2'] ?? '');
    $filas[] = [
        'cod'   => (string)($d['cod'] ?? $u),
        'piso'  => (int)$piso,
        'm2'    => $m2 !== '' ? (float)str_replace(',', '.', $m2) : null,
        // La direccion marca el patio con "1" y la falta con "NO", no con SI/NO.
        'patio' => ((int)$piso === (int)($L['piso_con_patio'] ?? -1))
                     ? (string)($L['patio_si'] ?? '1') : 'NO',
        'pvp'   => $pvp,
        'plan'  => lst_plan_hipo($pvp, $fin),
    ];
}
usort($filas, fn($a, $b) => strnatcmp($a['cod'], $b['cod']));
$conPatio = !empty($L['columna_patio']);
$pl0 = $filas ? $filas[0]['plan'] : lst_plan_hipo(0.0, $fin);
?>
<section class="hoja"<?= !empty($L['tema']) ? ' data-tema="' . lh((string)$L['tema']) . '"' : '' ?>>
  <?php /* La LEYENDA de color arriba a la derecha. En Torre C el color es el metraje
             (azul 70 m2, durazno 75) y en Suites el nivel (durazno planta baja con patio,
             azul plantas altas). En los dos casos el color explica el precio: sin leyenda
             el cliente ve filas de colores y no sabe que le dicen. */ ?>
  <?php if (!empty($L['leyenda'])): ?>
    <div class="leyenda leyenda-arriba">
    <?php foreach ((array)$L['leyenda'] as $cl => $tx): ?>
        <span><i class="<?= lh((string)$cl) ?>"></i><?= lh((string)$tx) ?></span>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
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
      <table><tr><th class="titulo"><?= lh((string)($L['titulo'] ?? strtoupper($proyecto))) ?></th></tr>
             <tr><th class="sub"><?= lh((string)($L['subtitulo'] ?? '')) ?></th></tr></table>
    </div>
  </div>
  <table>
    <thead>
      <tr class="g">
        <th colspan="<?= $conPatio ? 5 : 4 ?>"><?= (int)($fin['entrada_pct'] ?? 30) ?>% DE ENTRADA</th>
        <th colspan="4"><?= 100 - (int)($fin['entrada_pct'] ?? 30) ?>% - Cr&eacute;dito Hipotecario</th>
      </tr>
      <tr class="c">
        <th>DEPARTAMENTO TIPO</th><th>M2</th><th>PARQUEOS</th>
        <?php if ($conPatio): ?><th>PATIO</th><?php endif; ?>
        <th>PRECIO</th><th>SEPARA CON</th><th>ENTRADA</th><th>PR&Eacute;STAMO</th>
        <th>Cuotas del Pr&eacute;stamo a <?= (int)$pl0['anios'] ?><br>a&ntilde;os - Tasa
          <?= lh(number_format($pl0['tasa'], 2, ',', '')) ?>%<br>(tentativo)</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($filas as $r): $pl = $r['plan']; ?>
      <tr>
        <?php /* Como la direccion escribe el codigo en la lista, que NO es como lo
                 guarda Bitrix: la torre pone "C-1-1" donde el SPA tiene "C1-1", y las
                 suites se nombran "SUITE 1-5 (Planta Baja)". Es el nombre que el
                 cliente ya vio en su cotizacion en papel. */ ?>
        <?php
          /* El COLOR de la fila: por metraje en Torre C, por nivel en Suites. */
          $nivR = (string)(mz_nivel_de_piso($cfg, $r['piso']) ?? '');
          $clsR = '';
          foreach ((array)($L['colores_metraje'] ?? []) as $cl => $vals)
              if (in_array((float)$r['m2'], array_map('floatval', (array)$vals), true)) { $clsR = (string)$cl; break; }
          if ($clsR === '') foreach ((array)($L['colores_nivel'] ?? []) as $cl => $vals)
              if (in_array($nivR, (array)$vals, true)) { $clsR = (string)$cl; break; }
        ?>
        <td class="cat <?= lh($clsR) ?>"><?php
            $et = (string)($cfg['niveles'][$nivR]['etiqueta'] ?? '');
            /* La etiqueta del nivel solo se imprime donde su documento la imprime: en
               Suites "SUITE 1-5 (Planta Baja)" lleva parentesis y "SUITE 2-2" no. */
            $soloEn = (array)($L['etiqueta_nivel_solo'] ?? []);
            if ($soloEn && !in_array($nivR, $soloEn, true)) $et = '';
            /* Sufijo por unidad: la 3-8 es esquinera y esa esquina justifica los $5.000
               que la separan de la 2-2. Decir "(Piso 3)" en su lugar borra el motivo. */
            $suf = (string)(($L['excepciones'][$r['cod']]['sufijo'] ?? '') ?: '');
            $fmt = (string)($L['codigo_formato'] ?? '');
            if ($fmt === 'suite' && preg_match('/^[A-Z](\d+)-(\d+)$/', $r['cod'], $mm)) {
                echo lh("SUITE {$mm[1]}-{$mm[2]}")
                   . ($et !== '' ? ' ' . lh("($et)") : '')
                   . ($suf !== '' ? ' ' . lh($suf) : '');
            } else {
                $cod = $fmt === 'guion'
                    ? preg_replace('/^([A-Z])(\d+)-/', '$1-$2-', $r['cod'])
                    : $r['cod'];
                echo lh($cod) . ($et !== '' ? ' &nbsp;·&nbsp; ' . lh(strtoupper($et)) : '');
            }
        ?></td>
        <td class="c"><?= $r['m2'] !== null ? lh(rtrim(rtrim(number_format($r['m2'], 2, ',', ''), '0'), ',')) : '—' ?></td>
        <td class="c"><?= (int)($L['parqueos_por_unidad'] ?? 1) ?></td>
        <?php if ($conPatio): ?><td class="c"><?= lh($r['patio']) ?></td><?php endif; ?>
        <td class="p"><?= lh(ln($r['pvp'])) ?></td>
        <td class="n s-sep"><?= lh(ln($pl['separa'])) ?></td>
        <td class="n s-ent"><?= lh(ln($pl['entrada'])) ?></td>
        <td class="n s-pre"><?= lh(ln($pl['prestamo'])) ?></td>
        <td class="n s-cuo"><?= lh(ln($pl['cuota'])) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$filas): ?>
      <tr><td colspan="<?= $conPatio ? 9 : 8 ?>" style="text-align:center;padding:26px">
        No hay unidades disponibles.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
  <div class="pies">
    <?php foreach ((array)($L['pies'] ?? []) as $q): ?>
      <div class="pieh"><b><?= lh((string)($q[0] ?? '')) ?></b><span><?= lh((string)($q[1] ?? '')) ?></span></div>
    <?php endforeach; ?>
    <?php /* El gancho comercial del producto. En Suites es "PREVENTA DE SUITES CON
             SALIDA DIRECTA AL MAR": es el argumento de venta, no un adorno. */ ?>
    <?php foreach ((array)($L['pies_destacados'] ?? []) as $q): ?>
      <div class="pieh destacado"><?= lh((string)$q) ?></div>
    <?php endforeach; ?>
  </div>
  <?php /* Su documento cierra con esta NOTA, no con la banda de vigencia. La frase de
           la cuota tentativa se queda: la direccion la marco como mejora sobre su PDF. */ ?>
  <div class="vig"><?= $L['nota_amarilla'] ?? 'ESTA COTIZACI&Oacute;N TIENE UNA VIGENCIA DE '
      . (int)($fin['vigencia_horas'] ?? 48) . ' HRS NATURALES' ?>
    La cuota del préstamo es <b>tentativa</b>: la aprueba y la fija el banco.</div>
  <?php /* F-05: la linea de generacion es informacion INTERNA. Sobra en la version que
           va al cliente, asi que viaja en un title y no impresa. */ ?>
  <div class="meta" title="Generada el <?= lh($hoy->format('d/m/Y H:i')) ?> desde el inventario en vivo · <?= count($filas) ?> disponibles"></div>
</section>
