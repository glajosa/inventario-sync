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
<section class="hoja">
  <div class="cab">
    <img class="logo" src="assets/logo_galjosa_transparente.png"
         alt="<?= lh($proyecto) ?>" onerror="this.style.display='none'">
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
        <td class="cat"><?php
            $et = (string)($cfg['niveles'][mz_nivel_de_piso($cfg, $r['piso']) ?? '']['etiqueta'] ?? '');
            $fmt = (string)($L['codigo_formato'] ?? '');
            if ($fmt === 'suite' && preg_match('/^[A-Z](\d+)-(\d+)$/', $r['cod'], $mm)) {
                echo lh("SUITE {$mm[1]}-{$mm[2]}") . ($et !== '' ? ' ' . lh("($et)") : '');
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
  </div>
  <div class="vig">ESTA COTIZACI&Oacute;N TIENE UNA VIGENCIA DE
    <?= (int)($fin['vigencia_horas'] ?? 48) ?> HRS NATURALES. La cuota del préstamo es
    <b>tentativa</b>: la aprueba y la fija el banco.</div>
  <div class="meta">Generada el <?= lh($hoy->format('d/m/Y H:i')) ?> desde el inventario en vivo ·
    <?= count($filas) ?> disponibles</div>
</section>
