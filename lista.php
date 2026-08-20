<?php
/**
 * lista.php — la LISTA DE PRECIOS de una familia, en el formato de la direccion.
 * ---------------------------------------------------------------------------
 * El formato NO es nuestro: se reproduce el que ya usa la direccion y que vive en
 * `~/Downloads/GALJOSA - Todos los sistemas de precios`. Una fila por TIPOLOGIA,
 * banda vertical con el piso, colores por lado del parque, fila DESDE para los
 * combos, y el aviso de escasez cuando quedan una o dos.
 *
 * Lo unico que agrega esta version: se arma del inventario EN VIVO. La lista de
 * la direccion es un PDF que alguien regenera cada tanto, y el dia que una unidad
 * se vende la lista la sigue ofreciendo.
 *
 *   ?token=...&cat=33&fam=1951     oficinas y consultorios de Noral Plaza
 *
 * Cada familia declara su forma y su plazo en el bloque `listas` de su JSON.
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

require_once __DIR__ . '/matrizlib.php';
require_once __DIR__ . '/listalib.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$esperado = (string)getenv('OUTBOUND_TOKEN');
if ($esperado === '' || !hash_equals($esperado, (string)($_GET['token'] ?? ''))) {
    http_response_code(403); exit('forbidden');
}

function lh(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
/** Precio grande: la direccion lo escribe sin centavos ($ 144,420). */
function lp(float $v): string { return '$ ' . number_format($v, 0); }
/** Cifras del plan: con centavos ($1,000.00). */
function ln(float $v): string { return '$' . number_format($v, 2); }

$cat = (int)($_GET['cat'] ?? 0);
$fam = (int)($_GET['fam'] ?? 0);
$tok = (string)($_GET['token'] ?? '');

$cfgFile = __DIR__ . "/matrices/proyecto_$cat.json";
$cfg = is_file($cfgFile) ? json_decode((string)file_get_contents($cfgFile), true) : null;
if (!$cfg) { http_response_code(404); exit('proyecto sin matriz'); }
$proyecto = (string)($cfg['proyecto'] ?? "Proyecto $cat");

try {
    $unidades = mz_unidades_cache($cfg);
} catch (Throwable $e) {
    http_response_code(503);
    exit('<!doctype html><meta charset="utf-8"><p style="font:15px system-ui;padding:40px">'
       . lh($e->getMessage()) . '</p>');
}

$familias = lst_familias($unidades, $cat);
$LISTAS   = $cfg['listas'] ?? [];
// Sin familia pedida: la primera que TENGA lista declarada, no la mas grande —
// abrir en una familia que todavia no tiene formato es mostrar una pagina vacia.
if ($fam === 0) {
    foreach ($familias as $t => $_) if (isset($LISTAS[(string)$t])) { $fam = $t; break; }
    if ($fam === 0 && $familias) $fam = (int)array_key_first($familias);
}
$L = $LISTAS[(string)$fam] ?? null;
$nombreFam = lst_nombre_familia($cat, $fam);

$hoy = new DateTimeImmutable('now');
?>
<!doctype html>
<html lang="es"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Lista de Precios · <?= lh($proyecto) ?> · <?= lh($nombreFam) ?></title>
<style>
  /* El CSS es el de la lista de la direccion. No se "moderniza": el equipo comercial
     reconoce este documento y el cliente ya lo vio asi. */
  @page { size: A4 landscape; margin: 12mm; }
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:Calibri,'Segoe UI',Arial,sans-serif;background:#e9e9e6;color:#000;padding:18px}
  .hoja{background:#fff;padding:20px 26px 26px;margin:0 auto;max-width:1120px;
        box-shadow:0 1px 5px rgba(0,0,0,.16)}
  .cab{display:flex;align-items:center;gap:20px;margin-bottom:6px}
  .cab .logo{height:60px;width:auto}
  .cab .tit{flex:1}
  .wrap{display:flex;gap:0;align-items:stretch}
  .wrap .lat{background:#3b5323;color:#fff;writing-mode:vertical-rl;transform:rotate(180deg);
       display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;
       padding:8px 5px;border:1px solid #000;border-left:0;letter-spacing:.03em}
  .lat em{font-style:italic}
  table{width:100%;border-collapse:collapse;font-size:11px}
  th,td{border:1px solid #000;padding:4px 6px}
  .titulo{background:#3b5323;color:#fff;font-size:15px;font-weight:700;text-align:center;padding:7px}
  .sub{text-align:center;font-size:11.5px;font-weight:700;padding:5px}
  .g th{font-weight:700;text-align:center;font-size:11px}
  .g .it{font-style:italic}
  .c th{font-weight:700;text-align:center;font-size:9px;line-height:1.2;padding:5px 4px}
  .niv{color:#fff;font-weight:700;font-size:9px;text-align:center;width:26px;padding:2px}
  .niv span{writing-mode:vertical-rl;transform:rotate(180deg);white-space:nowrap}
  .niv2{background:#3b5323}.niv3{background:#6b8e3d}.niv4{background:#8a6d1f}
  .niv5{background:#4a5d6b}.niv6{background:#7a4b00}
  .cat{font-weight:700;font-size:10px;background:#fdf6e3;line-height:1.25}
  /* Codigo de color de la vista, tomado de la hoja de Excel original:
     crema para el parque lineal, verde palido para el parque central. */
  .cat.lineal {background:#fff2cc}
  .cat.central{background:#e2efda}
  .cat.combo{background:#3b5323;color:#fff;text-align:center;letter-spacing:.04em}
  .cat .ult{display:block;font-weight:600;font-size:6.5px;letter-spacing:.09em;
       margin-top:2px;color:#9a6a63;opacity:.9}
  td.c{text-align:center}
  td.p{text-align:right;font-weight:700;font-variant-numeric:tabular-nums}
  td.n{text-align:right;font-variant-numeric:tabular-nums}
  .pie{display:inline-flex;background:#3b5323;color:#fff;padding:5px 12px;
       border:1px solid #000;border-top:0;font-size:10.5px;font-weight:700}
  .pie span{background:#8fae5d;color:#1a1a1a;margin-left:14px;padding:0 10px;
       font-size:9.5px;display:inline-flex;align-items:center}
  .vig{margin-top:12px;background:#ffff00;border:1px solid #000;text-align:center;
       font-size:11px;font-weight:700;padding:6px}
  .meta{margin-top:6px;font-size:9.5px;color:#555;text-align:center}
  /* Barra de familias: es navegacion nuestra y NO va en el papel. */
  .barra{max-width:1120px;margin:0 auto 14px;display:flex;gap:8px;flex-wrap:wrap;align-items:center}
  .barra a,.barra button{font:600 12.5px/1 Calibri,'Segoe UI',Arial,sans-serif;padding:8px 13px;
       border:1px solid #b9bdb4;background:#fff;color:#26301c;text-decoration:none;cursor:pointer;
       border-radius:3px}
  .barra a.on{background:#3b5323;border-color:#3b5323;color:#fff}
  .barra .imp{margin-left:auto;background:#3b5323;border-color:#3b5323;color:#fff}
  .aviso{max-width:1120px;margin:0 auto 14px;background:#fff3cd;border:1px solid #e0c874;
       padding:10px 14px;font-size:12px}
  @media print{body{background:#fff;padding:0}.hoja{box-shadow:none}.barra,.aviso{display:none}}
</style>
</head><body>

<div class="barra">
  <?php foreach ($familias as $t => $f): ?>
    <a class="<?= $t === $fam ? 'on' : '' ?>"
       href="?token=<?= lh($tok) ?>&cat=<?= $cat ?>&fam=<?= (int)$t ?>"
    ><?= lh($f['nombre']) ?> (<?= (int)$f['n'] ?>)</a>
  <?php endforeach; ?>
  <button class="imp" onclick="window.print()">Descargar PDF</button>
</div>

<?php if (!$L): ?>
  <div class="aviso">
    <b><?= lh($nombreFam) ?></b> todavía no tiene el formato de lista declarado en
    <code>matrices/proyecto_<?= $cat ?>.json</code> (bloque <code>listas</code>).
    Las familias con formato están en la barra de arriba.
  </div>
<?php else:
  $fin   = $L['financiamiento'] ?? [];
  $grupos = (array)($L['grupos'] ?? []);
  $niveles = (array)($L['niveles'] ?? []);
  $orden  = (array)($L['orden'] ?? []);
  $px     = mz_precios_vigentes($cfg);
  $combo  = $L['combo'] ?? null;
  $conParq = !empty($L['parqueos']);

  // Se arma primero y se dibuja despues: hace falta saber cuantas filas tiene cada
  // piso para el rowspan de la banda vertical, y cuales pisos quedaron sin nada.
  // Una HOJA por grupo cuando la familia lo pide. La direccion saca una tabla por
  // edificio ("EDIFICIO A", "EDIFICIO B"): juntar D, E y F en una sola mezclaba
  // precios de edificios distintos bajo el mismo rotulo de piso.
  $porGrupo = !empty($L['una_tabla_por_grupo']);
  $hojas = [];
  foreach ($porGrupo ? $grupos : [null] as $gSolo) {
      $gs = $gSolo === null ? $grupos : [$gSolo];
      $bloques = [];
      foreach ($niveles as $niv) {
          $filas = [];
          foreach ($gs as $g) {
              $eds = (array)($cfg['grupos'][$g]['edificios'] ?? [$g]);
              $precio = $px[$eds[0]][$niv] ?? null;
              if (!is_array($precio)) continue;
              foreach ($orden as $k) {
                  if (!isset($precio[$k])) continue;
                  $cel = lst_celda($cfg, $unidades, $eds, $niv, $k);
                  if (!$cel['cods']) continue;          // agotada: no se ofrece
                  $filas[] = ['cat' => $k, 'g' => $g, 'precio' => (float)$precio[$k],
                              'm2' => lst_metros($cel['m2'], $cfg, $g, $k),
                              'cods' => $cel['cods']];
              }
          }
          if ($combo && isset($cfg['combos']['precio'][$niv]) && $filas) {
              $cm = $cfg['combos']['metraje'] ?? null;
              $filas[] = ['combo' => true, 'precio' => (float)$cfg['combos']['precio'][$niv],
                          'm2' => $cm !== null ? rtrim(rtrim(number_format((float)$cm, 2, ',', ''), '0'), ',') : '—',
                          'cods' => []];
          }
          if ($filas) $bloques[$niv] = $filas;
      }
      if ($bloques) $hojas[] = ['grupo' => $gSolo, 'bloques' => $bloques];
  }
  $nCols = 4 + ($conParq ? 1 : 0);   // niv + cat + metros + [parqueos] + precio
  if (!$hojas) $hojas = [['grupo' => null, 'bloques' => []]];
?>
<?php foreach ($hojas as $hoja): $bloques = $hoja['bloques'];
      $tit = $hoja['grupo'] === null
          ? (string)($L['titulo'] ?? strtoupper($proyecto))
          : strtoupper((string)($cfg['grupos'][$hoja['grupo']]['etiqueta'] ?? ('EDIFICIO ' . $hoja['grupo']))); ?>
<section class="hoja">
  <div class="cab">
    <img class="logo" src="assets/logo_galjosa_transparente.png"
         alt="<?= lh($proyecto) ?>" onerror="this.style.display='none'">
    <div class="tit">
      <table><tr><th class="titulo"><?= lh($tit) ?></th></tr>
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
      <?php $iNiv = 0; foreach ($bloques as $niv => $filas): $iNiv++;
            $etNiv = strtoupper((string)($cfg['niveles'][$niv]['etiqueta'] ?? $niv));
            $clsNiv = 'niv' . (($iNiv - 1) % 5 + 2);
            $primera = true; ?>
        <?php foreach ($filas as $r): $pl = lst_plan($r['precio'], $fin); ?>
          <tr>
            <?php if ($primera): $primera = false; ?>
              <td class="niv <?= $clsNiv ?>" rowspan="<?= count($filas) ?>"><span><?= lh($etNiv) ?></span></td>
            <?php endif; ?>
            <?php if (!empty($r['combo'])): ?>
              <td class="cat combo"><?= lh((string)($combo['rotulo'] ?? 'DESDE')) ?></td>
            <?php else:
              $n = count($r['cods']);
              // Una sola: la lista la nombra por su codigo. Dos: la tipologia con el
              // aviso. Es como lo escribe la direccion y es presion de escasez real.
              $texto = $n === 1 ? $r['cods'][0]
                                : (string)($L['nombre'][$r['cat']] ?? $r['cat']);
              $ult = $n === 1 ? 'ÚLTIMA DISPONIBLE' : ($n === 2 ? '2 ÚLTIMAS DISPONIBLES' : '');
            ?>
              <td class="cat <?= lh((string)($L['lado'][$r['cat']] ?? '')) ?>"><?= lh($texto) ?><?php
                  if ($ult !== '') echo '<span class="ult">' . lh($ult) . '</span>'; ?></td>
            <?php endif; ?>
            <td class="c"><?= lh((string)$r['m2']) ?></td>
            <?php if ($conParq): ?>
              <td class="c"><?= (int)(!empty($r['combo'])
                    ? ($combo['parqueos'] ?? 2)
                    : ($L['parqueos'][$r['cat']] ?? 1)) ?></td>
            <?php endif; ?>
            <td class="p"><?= lh(lp($r['precio'])) ?></td>
            <td class="n"><?= lh(ln($pl['separa'])) ?></td>
            <td class="n"><?= lh(ln($pl['firma'])) ?></td>
            <td class="n"><?= lh(ln($pl['mensual'])) ?></td>
            <td class="n"><?= lh(ln($pl['extra'])) ?></td>
          </tr>
        <?php endforeach; ?>
      <?php endforeach; ?>
      <?php if (!$bloques): ?>
        <tr><td colspan="<?= $nCols + 4 ?>" style="text-align:center;padding:26px">
          No hay unidades disponibles de esta familia.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
    <?php if (!empty($L['lat'])): ?>
      <div class="lat"><?= $L['lat'] ?></div>
    <?php endif; ?>
  </div>
  <?php if (!empty($L['pie'])): ?>
    <div class="pie"><?= lh((string)($L['pie']['rotulo'] ?? '')) ?><?php
        if (!empty($L['pie']['medidas'])) echo '<span>' . lh((string)$L['pie']['medidas']) . '</span>'; ?></div>
  <?php endif; ?>
  <div class="vig">ESTA COTIZACI&Oacute;N TIENE UNA VIGENCIA DE
    <?= (int)($fin['vigencia_horas'] ?? 48) ?> HRS NATURALES</div>
  <div class="meta">Generada el <?= lh($hoy->format('d/m/Y H:i')) ?> desde el inventario en vivo ·
    <?= array_sum(array_map('count', $bloques)) ?> tipologías con disponibilidad</div>
</section>
<?php endforeach; ?>
<?php endif; ?>

</body></html>
