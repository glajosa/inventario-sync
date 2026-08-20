<?php
/**
 * lista.php — la LISTA DE PRECIOS de una familia de un proyecto, lista para imprimir.
 * ---------------------------------------------------------------------------
 * Se arma del inventario EN VIVO: lo que se vende sale de la lista en la siguiente
 * apertura, sin que nadie regenere nada. Antes esto era un PDF que alguien producia
 * a mano cada tanto, y el dia que una unidad se vendia la lista seguia ofreciendola.
 *
 * Las cifras salen de cot_plan(), el MISMO motor que la cotizacion del deal. Es a
 * proposito: si la lista tuviera su propia aritmetica, tarde o temprano la lista y
 * la cotizacion de la misma unidad dirian numeros distintos, y el que queda mal
 * delante del cliente es el asesor.
 *
 *   ?token=...&cat=33&fam=1791     locales comerciales de Noral Plaza
 *   ?token=...&cat=33&fam=1951     oficinas y consultorios
 *   ?token=...&cat=33&fam=1793     monoambientes
 *
 * Solo DISPONIBLES y solo con precio: una unidad sin PVP no se ofrece.
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

require_once __DIR__ . '/matrizlib.php';
require_once __DIR__ . '/listalib.php';
require_once __DIR__ . '/cotizarlib.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$esperado = (string)getenv('OUTBOUND_TOKEN');
if ($esperado === '' || !hash_equals($esperado, (string)($_GET['token'] ?? ''))) {
    http_response_code(403); exit('forbidden');
}

function lh(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function lm(float $v): string { return '$ ' . number_format($v, 2); }

$cat = (int)($_GET['cat'] ?? 0);
$fam = (int)($_GET['fam'] ?? 0);

$cfgFile = __DIR__ . "/matrices/proyecto_$cat.json";
$cfg = is_file($cfgFile) ? json_decode((string)file_get_contents($cfgFile), true) : null;
$proyecto = $cfg['proyecto'] ?? "Proyecto $cat";

try {
    $unidades = mz_unidades_cache($cfg ?: ['bitrix' => ['categoryId' => $cat]]);
} catch (Throwable $e) {
    http_response_code(503);
    exit('<!doctype html><meta charset="utf-8"><p style="font:15px system-ui;padding:40px">'
       . lh($e->getMessage()) . '</p>');
}

$familias = lst_familias($unidades, $cat);
if ($fam === 0 && $familias) $fam = (int)array_key_first($familias);
$nombreFam = lst_nombre_familia($cat, $fam);

// ── las filas ───────────────────────────────────────────────────────────────
// El plan se calcula UNA vez por unidad con el modelo del proyecto. mesIni vacio
// = la primera cuota la pone el motor (el 16 del mes que viene).
$entrega = cot_entrega($cat);
$modelo  = cot_modelo($cat);
$nCuotas = (int)($modelo['maxCuotas'] ?: COT_PLAZO_REF);

$filas = []; $meses = 0; $nExtra = 0;
foreach ($unidades as $u => $d) {
    if (($d['etapa'] ?? '') !== 'DISPONIBLE') continue;
    if ((int)($d['tipo'] ?? 0) !== $fam) continue;
    $pvp = (float)($d['pvp'] ?? 0);
    if ($pvp <= 0) continue;

    $plan = cot_plan($pvp, $nCuotas, 'extraordinarias', '', $entrega);
    // La extraordinaria: se lee de las filas del propio plan en vez de recalcular
    // un 2% a mano, que es justo la clase de duplicado que despues se desalinea.
    $ex = 0.0; $nEx = 0;
    foreach (($plan['filas'] ?? []) as $f) {
        if (!empty($f['extra'])) { $ex = (float)($f['monto'] ?? $f['valor'] ?? 0); $nEx++; }
    }
    $meses  = max($meses, (int)$plan['cuotas']);
    $nExtra = max($nExtra, $nEx);
    $filas[] = [
        'cod'   => (string)($d['cod'] ?? $u),
        'm2'    => $d['m2'] !== null && $d['m2'] !== '' ? (float)str_replace(',', '.', (string)$d['m2']) : null,
        'pvp'   => $pvp,
        'sep'   => (float)$plan['separacion'],
        'firma' => (float)$plan['firma'],
        'mens'  => (float)$plan['mensual'],
        'cuotas'=> (int)$plan['cuotas'],
        'extra' => $ex,
        'contra'=> (float)$plan['contraentrega'],
    ];
}
// Orden natural del inventario: edificio, piso, numero. strnatcmp para que el
// 10 vaya despues del 9 y no entre el 1 y el 2.
usort($filas, fn($a, $b) => strnatcmp($a['cod'], $b['cod']));

$hoy = new DateTimeImmutable('now');
$MES = ['', 'enero','febrero','marzo','abril','mayo','junio','julio','agosto',
        'septiembre','octubre','noviembre','diciembre'];
$entregaTxt = $entrega ? $MES[(int)$entrega['m']] . ' de ' . (int)$entrega['y'] : null;
?>
<!doctype html>
<html lang="es"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Lista de Precios · <?= lh($proyecto) ?> · <?= lh($nombreFam) ?></title>
<style>
  :root{ --tinta:#0f2f5c; --borde:#dfe4ea; --gris:#6b7480; --suave:#f6f8fa; }
  *{box-sizing:border-box}
  body{margin:0;background:#eef2f5;color:#1a2530;
       font:14px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",system-ui,sans-serif}
  .hoja{max-width:1180px;margin:20px auto;background:#fff;padding:26px 28px;
        border-radius:8px;box-shadow:0 1px 3px rgba(16,24,40,.09)}
  .cab{display:flex;align-items:center;gap:16px;flex-wrap:wrap;margin-bottom:4px}
  .cab img{height:44px;width:auto}
  h1{font-size:19px;margin:0;letter-spacing:.01em}
  .sub{color:var(--gris);font-size:13px;margin:2px 0 18px}
  .barra{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 18px}
  .barra a{display:inline-block;padding:7px 13px;border-radius:6px;text-decoration:none;
           font-size:13px;font-weight:600;border:1px solid var(--borde);color:#334;background:#fff}
  .barra a.on{background:var(--tinta);border-color:var(--tinta);color:#fff}
  .imp{margin-left:auto;background:var(--tinta) !important;border-color:var(--tinta) !important;
       color:#fff !important;cursor:pointer;font-family:inherit}
  table{width:100%;border-collapse:collapse;font-size:13px}
  th,td{padding:7px 9px;text-align:right;white-space:nowrap}
  th:first-child,td:first-child{text-align:left}
  thead th{background:var(--tinta);color:#fff;font-weight:600;font-size:11.5px;
           text-transform:uppercase;letter-spacing:.04em;
           -webkit-print-color-adjust:exact;print-color-adjust:exact}
  tbody tr:nth-child(even){background:var(--suave)}
  tbody td{border-bottom:1px solid var(--borde)}
  .cod{font-weight:700}
  .tabla-marco{overflow-x:auto}
  .pie{margin-top:16px;font-size:11.5px;color:var(--gris);line-height:1.7}
  .vacio{padding:36px 0;text-align:center;color:var(--gris)}
  /* Margen de hoja en CERO: si no, el navegador estampa su encabezado y su pie con
     la URL completa —token incluido— en un documento que se le manda al cliente. */
  @page{ margin:0 }
  @media print{
    body{background:#fff;padding:12mm 10mm}
    .hoja{max-width:none;margin:0;padding:0;box-shadow:none;border-radius:0}
    .barra{display:none !important}
    .tabla-marco{overflow:visible}
    thead{display:table-header-group}   /* la cabecera se repite en cada hoja */
    tr{break-inside:avoid}
  }
</style>
</head><body>
<div class="hoja">

  <div class="barra">
    <?php foreach ($familias as $t => $f): ?>
      <a class="<?= $t === $fam ? 'on' : '' ?>"
         href="?token=<?= lh((string)($_GET['token'] ?? '')) ?>&cat=<?= $cat ?>&fam=<?= $t ?>"
      ><?= lh($f['nombre']) ?> <span style="opacity:.7;font-weight:400">(<?= (int)$f['n'] ?>)</span></a>
    <?php endforeach; ?>
    <button class="barra-imp imp" onclick="window.print()">Descargar PDF</button>
  </div>

  <div class="cab">
    <img src="assets/logo_galjosa_transparente.png" alt="Galjosa" onerror="this.style.display='none'">
    <div>
      <h1><?= lh(strtoupper($proyecto)) ?> — <?= lh(strtoupper($nombreFam)) ?></h1>
      <div class="sub">Lista de precios · <?= count($filas) ?> disponibles ·
        <?= lh($hoy->format('d/m/Y')) ?><?php if ($meses): ?> ·
        financiamiento a <?= $meses ?> meses<?php endif; ?></div>
    </div>
  </div>

  <?php if (!$filas): ?>
    <div class="vacio">No hay unidades disponibles con precio en esta familia.</div>
  <?php else: ?>
  <div class="tabla-marco">
  <table>
    <thead><tr>
      <th>Unidad</th><th>m²</th><th>Precio</th><th>Separe con</th><th>A la firma</th>
      <th>Cuota mensual<?= $meses ? " ($meses)" : '' ?></th>
      <?php if ($nExtra): ?><th>Extraordinaria (×<?= $nExtra ?>)</th><?php endif; ?>
      <th>Saldo contra entrega</th>
    </tr></thead>
    <tbody>
    <?php foreach ($filas as $r): ?>
      <tr>
        <td class="cod"><?= lh($r['cod']) ?></td>
        <td><?= $r['m2'] !== null && $r['m2'] > 0 ? number_format($r['m2'], 2) : '—' ?></td>
        <td><?= lm($r['pvp']) ?></td>
        <td><?= lm($r['sep']) ?></td>
        <td><?= lm($r['firma']) ?></td>
        <td><?= lm($r['mens']) ?></td>
        <?php if ($nExtra): ?><td><?= $r['extra'] > 0 ? lm($r['extra']) : '—' ?></td><?php endif; ?>
        <td><?= lm($r['contra']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>

  <div class="pie">
    Precios sujetos a cambio sin previo aviso. Valores en dólares de los Estados Unidos de América.<br>
    <?php if ($entregaTxt): ?>Entrega prevista: <b><?= lh($entregaTxt) ?></b>. El plazo de
    financiamiento se ajusta a esa fecha, así que se acorta con cada mes que pasa.<br><?php endif; ?>
    <?php if ($nExtra): ?>Las cuotas extraordinarias son <b><?= $nExtra ?></b>, una por año.<br><?php endif; ?>
    El saldo contra entrega puede cubrirse con recursos propios o con crédito hipotecario.<br>
    Generada el <?= lh($hoy->format('d/m/Y H:i')) ?> desde el inventario en vivo: las unidades
    colocadas dejan de aparecer solas.
  </div>
  <?php endif; ?>

</div>
</body></html>
