<?php
/**
 * cotizar.php — cotización de UNA unidad, para imprimir y mandarle al cliente.
 * ---------------------------------------------------------------------------
 * Se abre desde el botón "Cotizar" que sale junto a cada unidad en el campo
 * Inventario del deal. Nuestra propia pantalla, sencilla: el asesor ajusta plazo
 * y modalidad, y sale el plan listo para PDF.
 *
 * Acceso: NO lleva el token del servicio en la URL (se filtraría al vendedor y a
 * cualquiera que vea el enlace). Va con una firma HMAC de corta vida que arma
 * field.php, que Bitrix solo dibuja a un usuario ya autenticado en el portal.
 * La firma amarra unidad + deal + vencimiento: no sirve para pedir otra cosa.
 *
 * Solo lectura: lee el catálogo del caché y el contacto del deal por API.
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

require_once __DIR__ . '/cotizarlib.php';

// El catálogo se lee del MISMO archivo de caché que usa field.php. No se incluye
// selector.php a propósito: ese módulo puede lanzar una reconstrucción completa
// (~40s contra el API) y esta página tiene que abrir al instante.
function cot_catalogo(): array {
    $j = json_decode((string)@file_get_contents((getenv('DATA_DIR') ?: '/data') . '/selector_cache.json'), true);
    return is_array($j) ? $j : ['units' => [], 'proyectos' => []];
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

/** Lectura puntual contra Bitrix (solo el contacto del deal). */
function cot_bx(string $metodo, array $params): array {
    $base = rtrim((string)getenv('BITRIX_WEBHOOK'), '/') . '/';
    $ch = curl_init($base . $metodo);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($params),
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 8,
    ]);
    $raw = curl_exec($ch);
    $j = json_decode((string)$raw, true);
    return (is_array($j) && !isset($j['error'])) ? ['result' => $j['result'] ?? null] : ['result' => null];
}

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

/** Firma de la misma forma que field.php. Clave = el secreto del servicio.
 *  Cubre el DEAL y el vencimiento, NO la lista de unidades: el asesor arma la
 *  fusión eligiendo en el desplegable y esa selección cambia sin recargar la
 *  página, así que no hay forma de firmar cada combinación desde el servidor.
 *  Lo que se expone a cambio es el precio de otra unidad del catálogo, que el
 *  mismo asesor ya ve en la lista. El enlace sigue venciendo a las 12h. */
function cot_firma(int $deal, int $exp): string {
    $k = (string)getenv('OUTBOUND_TOKEN');
    return hash_hmac('sha256', "d{$deal}|e{$exp}", $k);
}

// Una o VARIAS unidades: "u=1263" o "u=1263,1265" (activos fusionados).
$ids = array_values(array_unique(array_filter(array_map(
    'intval', explode(',', (string)($_GET['u'] ?? ''))))));
$dealId = (int)($_GET['d'] ?? 0);
$exp    = (int)($_GET['exp'] ?? 0);
$sig    = (string)($_GET['s'] ?? '');

if (!$ids || !$exp || !hash_equals(cot_firma($dealId, $exp), $sig)) {
    http_response_code(403);
    exit('<!doctype html><meta charset="utf-8"><p style="font:15px system-ui;padding:30px">Enlace no válido.</p>');
}
if (time() > $exp) {
    http_response_code(403);
    exit('<!doctype html><meta charset="utf-8"><p style="font:15px system-ui;padding:30px">'
       . 'Este enlace ya venció. Vuelve al negocio en Bitrix y pulsa <b>Cotizar</b> otra vez.</p>');
}

// ---------- unidades ----------
$cat = cot_catalogo();
$porId = [];
foreach (($cat['units'] ?? []) as $u) $porId[(int)$u['id']] = $u;

$unidades = [];
foreach ($ids as $id) if (isset($porId[$id])) $unidades[] = $porId[$id];
if (!$unidades) {
    http_response_code(404);
    exit('<!doctype html><meta charset="utf-8"><p style="font:15px system-ui;padding:30px">No se encontró la unidad.</p>');
}

// Activos fusionados: se cotizan como UNO solo. El precio y los metros se suman,
// los códigos se listan y el plazo lo manda la entrega MÁS TEMPRANA de los
// proyectos involucrados — si una torre entrega antes, ahí se corta el plazo.
$pvp = 0.0; $m2 = 0.0; $codigos = []; $proyectosSet = []; $entrega = null; $sinPrecio = []; $ocupadas = [];
foreach ($unidades as $u) {
    $p = (float)str_replace(['|USD', ','], '', (string)$u['pvp']);
    $pvp += $p;
    $m2  += (float)str_replace(',', '.', (string)$u['m2']);
    $codigos[] = (string)$u['codigo'];
    $nomProy = (string)(($cat['proyectos'] ?? [])[(string)$u['cat']] ?? '');
    if ($nomProy !== '') $proyectosSet[$nomProy] = true;
    if ($p <= 0) $sinPrecio[] = (string)$u['codigo'];
    if (($u['stage'] ?? '') !== 'DISPONIBLE') $ocupadas[] = $u['codigo'] . ' (' . ($u['stage'] ?: 'sin etapa') . ')';
    $e = cot_entrega((int)$u['cat']);
    if ($e && (!$entrega || ($e['y'] * 12 + $e['m']) < ($entrega['y'] * 12 + $entrega['m']))) $entrega = $e;
}
$unidad   = $unidades[0];
$proyecto = implode(' · ', array_keys($proyectosSet));
$fusion   = count($unidades) > 1;

// ---------- cliente ----------
$cliente = '';
if ($dealId > 0) {
    $d = cot_bx('crm.deal.get', ['id' => $dealId]);
    $deal = $d['result'] ?? [];
    // El nombre sale del CONTACTO: el título del deal casi siempre es el texto
    // crudo del formulario ("Complete CRM form ...") y no se le muestra a nadie.
    if (!empty($deal['CONTACT_ID'])) {
        $c = cot_bx('crm.contact.get', ['id' => (int)$deal['CONTACT_ID']]);
        $ct = $c['result'] ?? [];
        $cliente = trim(implode(' ', array_filter([
            $ct['NAME'] ?? '', $ct['SECOND_NAME'] ?? '', $ct['LAST_NAME'] ?? ''
        ])));
    }
}
$cliente = mb_strtoupper((string)($_GET['cliente'] ?? $cliente), 'UTF-8');

// ---------- parámetros del plan ----------
$modalidad = (($_GET['mod'] ?? '') === 'iguales') ? 'iguales' : 'estandar';
$cuotas    = (int)($_GET['n'] ?? 0);
$mesIni    = (string)($_GET['mes'] ?? '');
$presu     = (float)str_replace([',', '$', ' '], '', (string)($_GET['presu'] ?? ''));

$plan = cot_plan($pvp, $cuotas, $modalidad, $mesIni, $entrega, $presu);
$hoy  = new DateTimeImmutable('now');
?>
<!doctype html>
<html lang="es"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Cotización<?= $cliente !== '' ? ' · ' . h($cliente) : '' ?> · <?= h(implode(' + ', $codigos)) ?></title>
<style>
  :root{ --azul:#0c6c9c; --tinta:#0c2c44; --linea:#dfe6ec; --gris:#5a6b7a; }
  *{box-sizing:border-box}
  body{margin:0;font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:var(--tinta);background:#eef2f6}
  .barra{background:var(--azul);color:#fff;padding:12px 18px;display:flex;align-items:center;gap:14px;flex-wrap:wrap}
  .barra h1{font-size:16px;margin:0;font-weight:700;letter-spacing:.4px}
  .barra .sp{margin-left:auto;display:flex;gap:8px}
  .barra button{border:0;border-radius:8px;padding:9px 16px;font-size:14px;font-weight:600;cursor:pointer}
  .imprimir{background:#fff;color:var(--azul)}
  .envoltura{max-width:820px;margin:18px auto;padding:0 14px}
  .tarjeta{background:#fff;border-radius:12px;padding:22px 24px;box-shadow:0 2px 14px rgba(12,44,68,.08)}
  .ajustes{display:flex;gap:14px;flex-wrap:wrap;align-items:flex-end;margin-bottom:18px;
           padding-bottom:16px;border-bottom:1px solid var(--linea)}
  .ajustes label{display:block;font-size:11px;text-transform:uppercase;letter-spacing:.8px;color:var(--gris);margin-bottom:5px}
  .ajustes input,.ajustes select{padding:9px 11px;border:1.5px solid var(--linea);border-radius:8px;font-size:14px;font-family:inherit}
  .ajustes .ir{background:var(--azul);color:#fff;border:0;border-radius:8px;padding:10px 18px;font-size:14px;font-weight:600;cursor:pointer}
  .datos{display:grid;grid-template-columns:auto 1fr;gap:6px 20px;font-size:14px;margin-bottom:16px}
  .datos dt{color:var(--gris)}
  .datos dd{margin:0;font-weight:600}
  .precio{background:#fff8c5;border-radius:8px;padding:11px 14px;display:flex;justify-content:space-between;
          font-size:17px;font-weight:700;margin-bottom:10px}
  .legal{background:#e8f2e2;border-radius:8px;padding:10px 14px;display:flex;justify-content:space-between;
         font-weight:700;font-size:14px}
  .legal + p{font-size:12px;color:var(--gris);font-style:italic;margin:6px 0 18px}
  .resumen{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin:16px 0 20px}
  .resumen div{border:1px solid var(--linea);border-radius:9px;padding:11px 13px}
  .resumen span{display:block;font-size:11px;text-transform:uppercase;letter-spacing:.7px;color:var(--gris);margin-bottom:3px}
  .resumen b{font-size:16px}
  table{width:100%;border-collapse:collapse;font-size:13.5px}
  thead th{background:var(--tinta);color:#fff;padding:9px 12px;text-align:left;font-size:11.5px;
           letter-spacing:1px;text-transform:uppercase}
  thead th:last-child{text-align:right}
  td{padding:8px 12px;border-bottom:1px solid #eef2f6}
  td:last-child{text-align:right;font-variant-numeric:tabular-nums;font-weight:600}
  tr.hito td{background:#f5f8fa;font-weight:700}
  tr.extra td{background:#fff8e6}
  .etq{display:inline-block;background:#f0b429;color:#4a3200;font-size:9.5px;font-weight:700;
       padding:2px 6px;border-radius:4px;margin-left:7px;letter-spacing:.6px}
  .aviso{background:#fff4e5;border:1px solid #ffd9a0;color:#7a5200;border-radius:8px;
         padding:10px 13px;font-size:13px;margin-bottom:16px}
  .pie{font-size:11.5px;color:var(--gris);margin-top:16px;line-height:1.5}
  @media print{
    body{background:#fff}
    .barra,.ajustes,.noimp{display:none !important}
    .envoltura{max-width:none;margin:0;padding:0}
    .tarjeta{box-shadow:none;border-radius:0;padding:0}
    thead th{background:var(--tinta) !important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
    tr.extra td,.precio,.legal{-webkit-print-color-adjust:exact;print-color-adjust:exact}
  }
</style>
</head><body>

<div class="barra">
  <h1>COTIZACIÓN · GALJOSA</h1>
  <div class="sp"><button class="imprimir" onclick="window.print()">Descargar PDF</button></div>
</div>

<div class="envoltura"><div class="tarjeta">

  <form class="ajustes" method="get">
    <input type="hidden" name="u" value="<?= h(implode(',', $ids)) ?>">
    <input type="hidden" name="d" value="<?= (int)$dealId ?>">
    <input type="hidden" name="exp" value="<?= (int)$exp ?>">
    <input type="hidden" name="s" value="<?= h($sig) ?>">
    <div><label>Cliente</label>
      <input type="text" name="cliente" value="<?= h($cliente) ?>" placeholder="Nombre del cliente" size="24"></div>
    <div><label>Cuotas</label>
      <input type="number" name="n" min="1" max="<?= (int)($plan['plazoMax'] ?? 120) ?>" value="<?= (int)$plan['cuotas'] ?>" style="width:90px"></div>
    <!-- La otra forma de preguntar, y la que más se usa vendiendo: el cliente dice
         cuánto puede pagar al mes y salen las cuotas. Si se llena, manda sobre "Cuotas". -->
    <div><label>o paga al mes</label>
      <input type="text" name="presu" inputmode="decimal" placeholder="$"
             value="<?= $presu > 0 ? h(number_format($presu, 0)) : '' ?>" style="width:100px"></div>
    <div><label>Modalidad</label>
      <select name="mod">
        <option value="estandar" <?= $modalidad === 'estandar' ? 'selected' : '' ?>>Estándar (con extraordinarias)</option>
        <option value="iguales"  <?= $modalidad === 'iguales'  ? 'selected' : '' ?>>Cuotas iguales</option>
      </select></div>
    <div><label>Primera cuota</label>
      <input type="month" name="mes" value="<?= h($plan['inicio']) ?>" min="<?= $hoy->format('Y-m') ?>"></div>
    <button class="ir" type="submit">Recalcular</button>
  </form>

  <?php if (!empty($plan['insuficiente'])): ?>
    <div class="aviso">Con <b><?= h(cot_money($plan['presupuesto'])) ?>/mes</b> no alcanza ni pagando hasta la entrega.
      La cuota mínima posible para esta unidad es <b><?= h(cot_money($plan['cuotaMinima'])) ?>/mes</b>
      (a <?= (int)$plan['cuotas'] ?> cuotas). Es lo que se muestra abajo.</div>
  <?php elseif ($plan['presupuesto'] > 0): ?>
    <div class="aviso" style="background:#eaf6ff;border-color:#b9ddf5;color:#0c4a6e">
      Con <b><?= h(cot_money($plan['presupuesto'])) ?>/mes</b> son
      <b><?= (int)$plan['cuotas'] ?> cuotas</b> de <b><?= h(cot_money($plan['mensual'])) ?></b>.</div>
  <?php endif; ?>
  <?php if ($plan['recortado']): ?>
    <div class="aviso">Se ajustó a <b><?= (int)$plan['cuotas'] ?> cuotas</b>: es el máximo que cabe antes de la
      entrega (<?= h(cot_mes_es((int)$entrega['m']) . ' ' . $entrega['y']) ?>) empezando en <?= h($plan['inicioTxt']) ?>.</div>
  <?php endif; ?>
  <?php if (!$entrega): ?>
    <div class="aviso">Este proyecto no tiene fecha de entrega configurada, así que el plazo no se limita solo.
      Verifica que las <?= (int)$plan['cuotas'] ?> cuotas terminen antes de la entrega real.</div>
  <?php endif; ?>
  <?php if ($sinPrecio): ?>
    <div class="aviso"><b><?= h(implode(', ', $sinPrecio)) ?></b>
      <?= count($sinPrecio) > 1 ? 'no tienen' : 'no tiene' ?> <b>PVP cargado en Bitrix</b>,
      así que <?= count($sinPrecio) > 1 ? 'no suman' : 'no suma' ?> nada al total.</div>
  <?php endif; ?>
  <?php if ($ocupadas): ?>
    <div class="aviso">Ojo, en el inventario está: <b><?= h(implode(' · ', $ocupadas)) ?></b>.</div>
  <?php endif; ?>

  <dl class="datos">
    <?php if ($cliente !== ''): ?><dt>Cliente</dt><dd><?= h($cliente) ?></dd><?php endif; ?>
    <dt>Proyecto</dt><dd><?= h($proyecto) ?></dd>
    <dt><?= $fusion ? 'Unidades' : 'Unidad' ?></dt>
    <dd><?= h(implode(' + ', $codigos)) ?><?= $fusion ? ' <span style="font-weight:400;color:var(--gris)">(' . count($codigos) . ' activos fusionados)</span>' : '' ?></dd>
    <?php if ($m2 > 0): ?><dt>Metros<?= $fusion ? ' (suma)' : '' ?></dt><dd><?= number_format($m2, 2) ?> m²</dd><?php endif; ?>
  </dl>

  <div class="precio"><span>Precio final</span><span><?= h(cot_money($plan['valor'])) ?></span></div>
  <div class="legal"><span>Valores legales promesa C/V</span><span><?= h(cot_money((float)$plan['legal'])) ?></span></div>
  <p>Pago directo para el notario. Se da al momento de la firma del contrato.</p>

  <div class="resumen">
    <div><span>Reserva 10%</span><b><?= h(cot_money($plan['reserva'])) ?></b></div>
    <div><span>Contraentrega 60%</span><b><?= h(cot_money($plan['contraentrega'])) ?></b></div>
    <div><span>Cuota mensual</span><b><?= h(cot_money($plan['mensual'])) ?></b></div>
    <div><span>Total cuotas</span><b><?= (int)$plan['cuotas'] ?></b></div>
  </div>

  <table>
    <thead><tr><th style="width:64px">N°</th><th>Vencimiento</th><th>Valor cuota</th></tr></thead>
    <tbody>
      <tr class="hito"><td></td><td>SEPARACIÓN</td><td><?= h(cot_money($plan['separacion'])) ?></td></tr>
      <?php if ($plan['firma'] > 0): ?>
      <tr class="hito"><td></td><td>A LA FIRMA</td><td><?= h(cot_money($plan['firma'])) ?></td></tr>
      <?php endif; ?>
      <?php foreach ($plan['filas'] as $f): ?>
      <tr class="<?= $f['extra'] ? 'extra' : '' ?>">
        <td><?= (int)$f['n'] ?></td>
        <td><?= h($f['fecha']) ?><?= $f['extra'] ? '<span class="etq">EXTRA</span>' : '' ?></td>
        <td><?= h(cot_money($f['monto'])) ?></td>
      </tr>
      <?php endforeach; ?>
      <tr class="hito"><td></td><td>CONTRAENTREGA</td><td><?= h(cot_money($plan['contraentrega'])) ?></td></tr>
    </tbody>
  </table>

  <p class="pie">
    Plan: 10% de reserva (separación <?= h(cot_money($plan['separacion'])) ?> + saldo a la firma),
    <?= $plan['modalidad'] === 'iguales' ? '30% en cuotas iguales' : '20% en cuotas mensuales + 10% en cuotas extraordinarias' ?>
    y 60% contraentrega. Las cuotas vencen el 16 de cada mes.
    <?php if ($plan['nExtra'] > 0): ?>
      Incluye <?= (int)$plan['nExtra'] ?> cuota<?= $plan['nExtra'] > 1 ? 's' : '' ?> extraordinaria<?= $plan['nExtra'] > 1 ? 's' : '' ?>
      de <?= h(cot_money($plan['valorExtra'])) ?> (una por año), que van sumadas a la cuota de ese mes.
    <?php endif; ?>
    <br>Cotización generada el <?= $hoy->format('d/m/Y') ?>. Precios sujetos a cambio sin previo aviso;
    la unidad se confirma únicamente con la reserva.
  </p>

</div></div>
</body></html>
