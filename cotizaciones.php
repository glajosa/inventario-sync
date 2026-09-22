<?php
/**
 * cotizaciones.php — HISTORIAL de las tablas de pago del cotizador.
 * ---------------------------------------------------------------------------
 * Pedido del usuario (22-sep-2026): "una historia de las tablas de pago que se hayan
 * generado en el cotizador... ya sea en filtro, en hoy, en ayer, en el mes, en la
 * semana" y "que guarde QUE tabla se generó, cuál es su plan de pagos".
 *
 * Solo LEE. Nada de esta pantalla escribe una cotización ni toca Bitrix: lo que se ve
 * es lo que el cotizador guardó cuando dibujó la tabla. Ver cotizacioneslib.php.
 *
 * Se abre con ?token=<OUTBOUND_TOKEN>, igual que preciomadre.php.
 * ---------------------------------------------------------------------------
 */
declare(strict_types=1);
require_once __DIR__ . '/cotizacioneslib.php';

$tok = (string)($_REQUEST['token'] ?? '');
$esperado = (string)getenv('OUTBOUND_TOKEN');
if ($esperado === '' || !hash_equals($esperado, $tok)) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    exit('<!doctype html><meta charset="utf-8"><p style="font:15px system-ui;padding:30px">Sin acceso.</p>');
}
header('Cache-Control: no-store');

function h2($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function money($v): string { return '$' . number_format((float)$v, 2); }

$FILTROS = [
    'hoy' => 'Hoy', 'ayer' => 'Ayer', 'semana' => 'Esta semana',
    'semana_pasada' => 'Semana pasada', 'mes' => 'Este mes',
    'mes_pasado' => 'Mes pasado', 'rango' => 'Entre dos fechas', '' => 'Todo',
];
$filtro = (string)($_GET['filtro'] ?? 'hoy');
if (!array_key_exists($filtro, $FILTROS)) $filtro = 'hoy';
$q      = trim((string)($_GET['q'] ?? ''));
$desde  = (string)($_GET['desde'] ?? '');
$hasta  = (string)($_GET['hasta'] ?? '');
$ver    = (string)($_GET['ver'] ?? '');

[$rDesde, $rHasta] = cothist_rango($filtro, $desde, $hasta);
$res = cothist_buscar(['filtro' => $filtro, 'q' => $q, 'desde' => $desde, 'hasta' => $hasta]);
$detalle = $ver !== '' ? cothist_una($ver) : null;

/* El enlace para REABRIR la cotización exacta se firma aquí: la firma cubre deal y
   vencimiento (ver cotizar.php), así que se puede rehacer sin guardar nada secreto
   en la base. Vence a las 12 h, igual que cualquier otro enlace del cotizador. */
function cot_link(array $f): string {
    $par = json_decode((string)$f['params'], true);
    if (!is_array($par)) return '';
    $exp = time() + 43200;
    $deal = (int)($par['d'] ?? $f['deal_id'] ?? 0);
    $sig = hash_hmac('sha256', "d{$deal}|e{$exp}", (string)getenv('OUTBOUND_TOKEN'));
    $par['exp'] = (string)$exp; $par['s'] = $sig; $par['d'] = (string)$deal;
    return 'cotizar.php?' . http_build_query($par);
}

/* ── SALIDA JSON ──────────────────────────────────────────────────────────────
 * La pidio la sesion de COBRANZAS (22-sep-2026) y resuelve un problema real, no
 * teorico: cuando el asesor baja la tabla de pagos con Firefox, los montos del PDF
 * salen ilegibles ($???.??) para cualquier lector -- medido por ellos 15/15, falla
 * 3/3 con Firefox 155/cairo 1.18.4. cobranza2.php lee ese PDF para crear las
 * dependencias del deal; si no lo puede leer, el deal se queda en RESERVA. Habia 3
 * trabados asi.
 *
 * Aca las filas estan en limpio, calculadas, sin pasar por el PDF: se entregan tal
 * cual se guardaron y quien las consume redondea y formatea a su gusto.
 *
 * Mismo candado que la pantalla (OUTBOUND_TOKEN): no abre nada nuevo.
 *   ?json=1&deal=<id>     la mas reciente de ese deal
 *   ?json=1&huella=<h>    una exacta
 */
if (($_GET['json'] ?? '') === '1') {
    header('Content-Type: application/json; charset=utf-8');
    $hu = (string)($_GET['huella'] ?? '');
    $f = $hu !== '' ? cothist_una($hu) : cothist_por_deal((int)($_GET['deal'] ?? 0));
    if (!$f) { echo json_encode(['ok' => false, 'motivo' => 'sin_cotizacion']); exit; }
    $filas = json_decode((string)$f['plan'], true) ?: [];
    $hitos = json_decode((string)$f['hitos'], true) ?: [];
    echo json_encode([
        'ok'            => true,
        'huella'        => $f['huella'],
        'deal_id'       => (int)$f['deal_id'],
        'asesor_id'     => (int)$f['asesor_id'],
        'cliente'       => $f['cliente'],
        'proyecto'      => $f['proyecto'],
        'unidades'      => $f['unidades'],
        'valor'         => (float)$f['valor'],
        'separacion'    => (float)$f['separacion'],
        'firma'         => (float)$f['firma'],
        'contraentrega' => (float)$f['contraentrega'],
        'mensual'       => (float)$f['mensual'],
        'cuotas'        => (int)$f['cuotas'],
        'modalidad'     => $f['modalidad'],
        'creada'        => (int)$f['creada'],
        'ultima_vez'    => (int)$f['ultima_vez'],
        'veces'         => (int)$f['veces'],
        // Fechas de la separacion y de la firma: son filas de la tabla impresa que
        // NO viven en `filas` -- quien arme la tabla del otro lado las necesita.
        'hitos'         => $hitos,
        'filas'         => $filas,
        // Enlace firmado para reabrirla en el cotizador. Vence en 12 h, como todos.
        'link'          => cot_link($f),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
?><!doctype html><html lang="es"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Historial de cotizaciones · Galjosa</title>
<style>
:root{
  --paper:oklch(98.2% .004 259); --paper-2:oklch(95.8% .007 259);
  --ink:oklch(22% .018 259); --ink-2:oklch(40% .015 259); --muted:oklch(53% .016 259);
  --border:oklch(89% .012 259); --azul:#0c6c9c; --tinta:#0c2c44;
}
*{box-sizing:border-box}
body{margin:0;background:var(--paper);color:var(--ink);
  font:14px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
.barra{background:var(--tinta);color:#fff;padding:14px 20px;display:flex;align-items:center;gap:14px}
.barra h1{font-size:16px;margin:0;font-weight:600;letter-spacing:-.01em}
.barra .sub{font-size:12px;opacity:.72}
.envoltura{max-width:1240px;margin:0 auto;padding:20px}
.filtros{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:14px}
.chip{display:inline-block;padding:6px 13px;border:1px solid var(--border);border-radius:999px;
  background:#fff;color:var(--ink-2);text-decoration:none;font-size:13px}
.chip:hover{background:var(--paper-2)}
.chip.on{background:var(--tinta);border-color:var(--tinta);color:#fff;font-weight:600}
.busca{margin-left:auto;display:flex;gap:6px}
input[type=text],input[type=date]{padding:6px 10px;border:1px solid var(--border);
  border-radius:8px;font:inherit;background:#fff;color:var(--ink)}
button{padding:6px 14px;border:1px solid var(--tinta);background:var(--tinta);color:#fff;
  border-radius:8px;font:inherit;cursor:pointer}
.resumen{color:var(--muted);font-size:13px;margin:0 0 12px}
.resumen b{color:var(--ink)}
table{width:100%;border-collapse:collapse;background:#fff;border:1px solid var(--border);
  border-radius:10px;overflow:hidden}
th,td{padding:9px 11px;text-align:left;border-bottom:1px solid var(--border);vertical-align:top}
th{background:var(--paper-2);font-size:12px;text-transform:uppercase;letter-spacing:.04em;
  color:var(--muted);font-weight:600;white-space:nowrap}
tr:last-child td{border-bottom:none}
td.num{text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}
.uni{font-weight:600}
.vacio{padding:34px;text-align:center;color:var(--muted);background:#fff;
  border:1px solid var(--border);border-radius:10px}
.veces{display:inline-block;padding:1px 7px;border-radius:999px;background:var(--paper-2);
  color:var(--muted);font-size:11px;margin-left:6px}
a.ver{color:var(--azul);text-decoration:none;font-weight:600}
a.ver:hover{text-decoration:underline}
.panel{background:#fff;border:1px solid var(--border);border-radius:10px;padding:18px;margin-bottom:18px}
.panel h2{margin:0 0 4px;font-size:17px}
.panel .meta{color:var(--muted);font-size:13px;margin-bottom:14px}
.fichas{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px}
.ficha{border:1px solid var(--border);border-radius:9px;padding:9px 14px;min-width:135px}
.ficha .r{font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:var(--muted)}
.ficha .v{font-size:17px;font-weight:600;font-variant-numeric:tabular-nums}
.plan{width:100%;max-width:560px}
.plan td.e{background:oklch(97% .04 95)}
.etq{display:inline-block;padding:1px 6px;border-radius:4px;background:var(--tinta);
  color:#fff;font-size:10px;letter-spacing:.04em;margin-left:6px}
@media(max-width:700px){ th.opt,td.opt{display:none} }
</style></head><body>

<div class="barra">
  <h1>Historial de cotizaciones</h1>
  <span class="sub">tablas de pago generadas en el cotizador</span>
</div>

<div class="envoltura">

<?php if ($detalle): ?>
  <?php
    $filas = json_decode((string)$detalle['plan'], true) ?: [];
    $link = cot_link($detalle);
    $volver = 'cotizaciones.php?token=' . urlencode($tok) . '&filtro=' . urlencode($filtro)
            . ($q !== '' ? '&q=' . urlencode($q) : '');
  ?>
  <div class="panel">
    <h2><?= h2($detalle['unidades'] ?: 'Sin unidad') ?>
        <?= $detalle['cliente'] !== '' ? ' · ' . h2($detalle['cliente']) : '' ?></h2>
    <div class="meta">
      <?= h2($detalle['proyecto']) ?> ·
      generada el <?= h2($detalle['dia']) ?>
      a las <?= h2((new DateTimeImmutable('@'.$detalle['creada']))->setTimezone(new DateTimeZone(COTHIST_TZ))->format('H:i')) ?>
      <?= (int)$detalle['veces'] > 1 ? ' · se volvió a mostrar ' . (int)$detalle['veces'] . ' veces' : '' ?>
      <?= (int)$detalle['deal_id'] > 0 ? ' · deal ' . (int)$detalle['deal_id'] : '' ?>
      <?php if ((int)$detalle['asesor_id'] > 0):
        $nom = cothist_asesores([(int)$detalle['asesor_id']]); ?>
        · asesor <?= h2($nom[(int)$detalle['asesor_id']] ?? '') ?>
      <?php endif; ?>
    </div>
    <div class="fichas">
      <div class="ficha"><div class="r">Valor</div><div class="v"><?= money($detalle['valor']) ?></div></div>
      <div class="ficha"><div class="r">Separación</div><div class="v"><?= money($detalle['separacion']) ?></div></div>
      <div class="ficha"><div class="r">Firma</div><div class="v"><?= money($detalle['firma']) ?></div></div>
      <div class="ficha"><div class="r">Contraentrega</div><div class="v"><?= money($detalle['contraentrega']) ?></div></div>
      <div class="ficha"><div class="r">Cuota mensual</div>
        <div class="v"><?= money($detalle['mensual']) ?></div>
        <div class="r"><?= (int)$detalle['cuotas'] ?> cuotas</div></div>
    </div>
    <p><a class="ver" href="<?= h2($volver) ?>">&larr; volver al listado</a>
       <?php if ($link !== ''): ?>
       &nbsp;·&nbsp; <a class="ver" href="<?= h2($link) ?>" target="_blank">abrir esta cotización en el cotizador &rarr;</a>
       <?php endif; ?></p>

    <?php /* La tabla se dibuja COMPLETA, como la vio el cliente: la SEPARACION y el
             A LA FIRMA no son filas del motor -- la pantalla del cotizador las pone
             aparte, con su fecha. Sin ellas el historial mostraba la tabla con la
             primera linea de menos. */
       $hit = json_decode((string)($detalle['hitos'] ?? ''), true) ?: []; ?>
    <table class="plan">
      <tr><th>N°</th><th>Vencimiento</th><th style="text-align:right">Valor cuota</th></tr>
      <tr class="hito"><td></td>
        <td>SEPARACIÓN <span style="color:var(--muted)"><?= h2($hit['fechaReserva'] ?? '') ?></span></td>
        <td class="num"><?= money($detalle['separacion']) ?></td></tr>
      <?php if ((float)$detalle['firma'] > 0): ?>
      <tr class="hito"><td></td>
        <td>A LA FIRMA <span style="color:var(--muted)"><?= h2($hit['fechaFirma'] ?? '') ?></span></td>
        <td class="num"><?= money($detalle['firma']) ?></td></tr>
      <?php endif; ?>
      <?php foreach ($filas as $f): ?>
      <tr>
        <td><?= empty($f['soloFirma']) && (int)($f['n'] ?? 0) > 0 ? (int)$f['n'] : '' ?></td>
        <td><?= h2($f['fecha'] ?? '') ?><?= !empty($f['extra']) ? '<span class="etq">EXTRA</span>' : '' ?></td>
        <td class="num<?= !empty($f['extra']) ? ' e' : '' ?>"><?= money($f['monto'] ?? 0) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (!empty($hit['totalInicial'])): ?>
      <tr class="hito"><td></td><td><b>TOTAL CUOTA INICIAL</b></td>
        <td class="num"><b><?= money($hit['totalInicial']) ?></b></td></tr>
      <?php endif; ?>
      <tr class="hito"><td></td><td>CONTRA ENTREGA</td>
        <td class="num"><?= money($detalle['contraentrega']) ?></td></tr>
      <?php if (!$filas): ?><tr><td colspan="3">Esta cotización se guardó sin filas.</td></tr><?php endif; ?>
    </table>
    <p class="meta" style="margin-top:10px">
      Suma de todo lo de arriba:
      <b><?= money((float)$detalle['separacion'] + (float)$detalle['firma']
                   + array_sum(array_map(fn($x) => (float)($x['monto'] ?? 0), $filas))
                   + (float)$detalle['contraentrega']) ?></b>
      · valor de la unidad: <b><?= money($detalle['valor']) ?></b>
    </p>
  </div>

<?php else: ?>

  <form class="filtros" method="get">
    <input type="hidden" name="token" value="<?= h2($tok) ?>">
    <?php foreach ($FILTROS as $k => $etq):
      $u = 'cotizaciones.php?token=' . urlencode($tok) . '&filtro=' . urlencode($k)
         . ($q !== '' ? '&q=' . urlencode($q) : ''); ?>
      <a class="chip<?= $filtro === $k ? ' on' : '' ?>" href="<?= h2($u) ?>"><?= h2($etq) ?></a>
    <?php endforeach; ?>
    <span class="busca">
      <?php if ($filtro === 'rango'): ?>
        <input type="hidden" name="filtro" value="rango">
        <input type="date" name="desde" value="<?= h2($rDesde) ?>">
        <input type="date" name="hasta" value="<?= h2($rHasta) ?>">
      <?php else: ?>
        <input type="hidden" name="filtro" value="<?= h2($filtro) ?>">
      <?php endif; ?>
      <input type="text" name="q" value="<?= h2($q) ?>" placeholder="cliente, unidad o deal">
      <button type="submit">Buscar</button>
    </span>
  </form>

  <p class="resumen">
    <b><?= (int)$res['total'] ?></b> <?= (int)$res['total'] === 1 ? 'tabla de pagos' : 'tablas de pago' ?>
    <?= $rDesde !== '' ? 'entre el ' . h2($rDesde) . ' y el ' . h2($rHasta) : 'en todo el historial' ?>
    <?= $q !== '' ? ' que coinciden con "' . h2($q) . '"' : '' ?>
    · suman <b><?= money($res['suma']) ?></b> en valor cotizado
    <?= (int)$res['total'] > count($res['filas']) ? ' · se muestran las ' . count($res['filas']) . ' más recientes' : '' ?>
  </p>

  <?php if (!$res['filas']): ?>
    <div class="vacio">No hay ninguna tabla de pagos generada en ese período.</div>
  <?php else: ?>
  <table>
    <tr>
      <th>Cuándo</th><th>Unidad</th><th class="opt">Proyecto</th><th>Cliente</th>
      <th class="opt">Asesor</th><th class="opt">Deal</th>
      <th style="text-align:right">Valor</th>
      <th style="text-align:right">Cuota</th>
      <th style="text-align:right">Cuotas</th>
      <th></th>
    </tr>
    <?php /* Los nombres se resuelven UNA vez para toda la tabla, no uno por fila:
             asi una pagina de 200 cotizaciones no dispara 200 llamadas. */
      $ases = cothist_asesores(array_column($res['filas'], 'asesor_id'));
      foreach ($res['filas'] as $f):
      $cuando = (new DateTimeImmutable('@' . (int)$f['ultima_vez']))
                  ->setTimezone(new DateTimeZone(COTHIST_TZ));
      $verU = 'cotizaciones.php?token=' . urlencode($tok) . '&filtro=' . urlencode($filtro)
            . ($q !== '' ? '&q=' . urlencode($q) : '') . '&ver=' . urlencode((string)$f['huella']); ?>
    <tr>
      <td><?= h2($cuando->format('d/m/Y')) ?> <span style="color:var(--muted)"><?= h2($cuando->format('H:i')) ?></span>
          <?= (int)$f['veces'] > 1 ? '<span class="veces">×' . (int)$f['veces'] . '</span>' : '' ?></td>
      <td class="uni"><?= h2($f['unidades']) ?></td>
      <td class="opt"><?= h2($f['proyecto']) ?></td>
      <td><?= h2($f['cliente']) ?></td>
      <td class="opt"><?= h2($ases[(int)$f['asesor_id']] ?? '') ?></td>
      <td class="opt"><?= (int)$f['deal_id'] > 0 ? (int)$f['deal_id'] : '' ?></td>
      <td class="num"><?= money($f['valor']) ?></td>
      <td class="num"><?= money($f['mensual']) ?></td>
      <td class="num"><?= (int)$f['cuotas'] ?></td>
      <td><a class="ver" href="<?= h2($verU) ?>">ver tabla</a></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>

<?php endif; ?>
</div>
</body></html>
