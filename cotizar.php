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
$suites = 0;   // suites de Noral Plaza en la compra: deciden si aplica el descuento de parqueo
// Logo del proyecto (como el cotizador original): 33 = Noral Plaza, 39 = Noral
// Apartments. El de Galjosa va siempre; el de Noral es el que corresponda a la
// categoría de la unidad — si es otro proyecto (Galero, Barranca...) no hay logo
// propio todavía y solo sale el de Galjosa.
$esPlaza = false; $esApartments = false; $esGalero = false;
$catPrincipal = 0;          // categoría que manda el modelo de pago (la primera con precio)
foreach ($unidades as $u) {
    $p = (float)str_replace(['|USD', ','], '', (string)$u['pvp']);
    $pvp += $p;
    $m2  += (float)str_replace(',', '.', (string)$u['m2']);
    $codigos[] = (string)$u['codigo'];
    $nomProy = (string)(($cat['proyectos'] ?? [])[(string)$u['cat']] ?? '');
    if ($nomProy !== '') $proyectosSet[$nomProy] = true;
    if ($p <= 0) $sinPrecio[] = (string)$u['codigo'];
    if (cot_es_suite((int)$u['cat'], (int)($u['tipo'] ?? 0))) $suites++;
    if ((int)$u['cat'] === 33) $esPlaza = true;
    if ((int)$u['cat'] === 39) $esApartments = true;
    if (in_array((int)$u['cat'], [47, 51, 53, 55], true)) $esGalero = true;
    if ($catPrincipal === 0) $catPrincipal = (int)$u['cat'];
    if (($u['stage'] ?? '') !== 'DISPONIBLE') $ocupadas[] = $u['codigo'] . ' (' . ($u['stage'] ?: 'sin etapa') . ')';
    $e = cot_entrega((int)$u['cat']);
    if ($e && (!$entrega || ($e['y'] * 12 + $e['m']) < ($entrega['y'] * 12 + $entrega['m']))) $entrega = $e;
}
$unidad   = $unidades[0];
$proyecto = implode(' · ', array_keys($proyectosSet));
$fusion   = count($unidades) > 1;

// PRECIO EDITABLE: el PVP del SPA es el punto de partida, no una condena — se
// negocia. Si el asesor escribe un precio, MANDA sobre el del inventario. En una
// fusión el monto escrito es el TOTAL y se reparte entre las unidades en la misma
// proporción que traían, para que la vista "separado" siga sumando lo mismo.
$pvpInventario = $pvp;
$vPrecio = str_replace([',', '$', ' '], '', (string)($_GET['precio'] ?? ''));
$precioEditado = false;
if (preg_match('/^\d+(\.\d+)?$/', $vPrecio) && (float)$vPrecio > 0 && $pvpInventario > 0) {
    $nuevo  = (float)$vPrecio;
    if (abs($nuevo - $pvpInventario) > 0.005) {
        $factor = $nuevo / $pvpInventario;
        foreach ($unidades as $k => $u) {
            $p = (float)str_replace(['|USD', ','], '', (string)$u['pvp']);
            $unidades[$k]['pvp'] = (string)($p * $factor);
        }
        $pvp = $nuevo;
        $precioEditado = true;
    }
}

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
// Variantes reales que pidió el negocio. Nada vacío = plan clásico, no se toca nada.
$num = function (string $k): string {
    $s = str_replace([',', '$', ' '], '', (string)($_GET[$k] ?? ''));
    return preg_match('/^\d+(\.\d+)?$/', $s) ? $s : '';
};
// 1) Firma diferida: "el 10% se suma a las cuotas" en vez de pagarse toda al firmar,
//    tope de 12 meses. O bien N meses parejos, o un monto mensual editable y el
//    resto se cae a las extraordinarias.
$vFirmaMeses = (int)($_GET['firmames'] ?? 0);
$vFirmaCuota = $num('firmacuota');
// 2) Monto exacto a la firma (independiente de la firma diferida: se puede fijar
//    cuánto paga al firmar sin tocar el mecanismo de deferencia).
$vFirma = $num('firma');
// 3) Extraordinaria partida en 2 (abril + diciembre por defecto), y en qué mes
//    exacto cae cada una — el asesor la mueve si el cliente cobra distinto.
$extraPartes = (($_GET['extrapartes'] ?? '') === '2') ? 2 : 1;
$vExtraMes1  = (int)($_GET['extrames1'] ?? 0);
$vExtraMes2  = (int)($_GET['extrames2'] ?? 0);
// 4) % a financiar antes de la entrega: 40 es lo común, el vendedor lo puede bajar
//    según lo que pida el cliente, pero el PISO ES 35 — se topa dentro del motor
//    (cot_plan), no aquí, así que ninguna URL armada a mano lo salta.
$vFinanciar = $num('financiar');
// MODELO POR PROYECTO. Galero no se vende como Noral: 30% de entrada y 70% con el
// banco. El modelo sale de cot_modelo() para que la pantalla no repita reglas que ya
// viven en el motor.
$modelo = cot_modelo($catPrincipal);
$opts = ['extraPartes' => $extraPartes,
         'reservaPct'  => $modelo['reservaPct'],
         'contraPct'   => $modelo['contraPct'],
         'extra'       => $modelo['extra'],
         'maxExtra'    => $modelo['maxExtra']];

// MONTOS PERSONALIZADOS de las extraordinarias. Se escriben las primeras; la última
// la calcula el motor con el residuo, así que acá NO se lee ni se manda.
$extraPers   = (($_GET['extrapers'] ?? '') === '1');
$extraMontos = [];
if ($extraPers) {
    for ($k = 1; $k <= 6; $k++) {
        $vv = str_replace([',', '$', ' '], '', (string)($_GET['extramonto' . $k] ?? ''));
        $extraMontos[] = is_numeric($vv) ? (float)$vv : 0.0;
    }
    $opts['extraMontos'] = $extraMontos;
}

// ENTREGA ELEGIBLE (Galero Casas: 6 a 36 meses). Los proyectos con fecha fija la
// traen de cot_entrega(); en Casas la pone el asesor y de ahí sale el plazo máximo.
$vEntregaMeses = (int)($_GET['entregameses'] ?? 0);
if ($modelo['entregaMax'] > 0) {
    if ($vEntregaMeses < $modelo['entregaMin'] || $vEntregaMeses > $modelo['entregaMax']) {
        $vEntregaMeses = $modelo['entregaMax'];          // por defecto, el plazo más largo
    }
    $t = strtotime('+' . $vEntregaMeses . ' months');
    $entrega = ['y' => (int)date('Y', $t), 'm' => (int)date('n', $t)];
}
if ($vFirmaMeses > 0) $opts['firmaMeses'] = min(12, $vFirmaMeses);
if ($vFirmaCuota !== '') $opts['firmaCuota'] = (float)$vFirmaCuota;
if ($vFirma !== '') $opts['firma'] = (float)$vFirma;
if ($vExtraMes1 >= 1 && $vExtraMes1 <= 12) $opts['extraMes1'] = $vExtraMes1;
if ($vExtraMes2 >= 1 && $vExtraMes2 <= 12) $opts['extraMes2'] = $vExtraMes2;
if ($vFinanciar !== '') $opts['financiarPct'] = (float)$vFinanciar;
// Parqueo: en una compra de 2+ suites se puede perdonar UNO solo. Apagado por
// defecto — lo decide el asesor, no se descuenta a espaldas de nadie.
$sinParqueo = (($_GET['sinparq'] ?? '') === '1');
$dctoParq   = cot_descuento_parqueo($suites, $sinParqueo);
$pvpFinal   = max(0.0, $pvp - $dctoParq);

// UNIFICAR: por defecto las unidades van en UN solo plan (es como Galjosa vende una
// fusión y como lo trata cobranzas). Apagarlo saca un plan POR UNIDAD, útil para que
// el cliente compare "si compro una" contra "si compro las dos".
$unificar = $fusion ? (($_GET['unif'] ?? '1') !== '0') : true;

$bloques = [];
if ($unificar) {
    $bloques[] = ['cods'=>$codigos, 'pvp'=>$pvpFinal, 'm2'=>$m2, 'dcto'=>$dctoParq, 'bruto'=>$pvp,
                  'plan'=>cot_plan($pvpFinal, $cuotas, $modalidad, $mesIni, $entrega, $presu, $opts)];
} else {
    // Separado: el descuento de parqueo NO se reparte — pertenece a una unidad concreta,
    // así que se muestra solo en la primera y el resto va a precio pleno.
    $primero = true;
    foreach ($unidades as $u) {
        $p = (float)str_replace(['|USD', ','], '', (string)$u['pvp']);
        $d = ($primero && $dctoParq > 0) ? $dctoParq : 0.0;
        $e = cot_entrega((int)$u['cat']);
        $bloques[] = ['cods'=>[(string)$u['codigo']], 'pvp'=>max(0.0,$p-$d),
                      'm2'=>(float)str_replace(',', '.', (string)$u['m2']), 'dcto'=>$d, 'bruto'=>$p,
                      'plan'=>cot_plan(max(0.0,$p-$d), $cuotas, $modalidad, $mesIni, $e, $presu, $opts)];
        $primero = false;
    }
}
$plan = $bloques[0]['plan'];          // el primero manda para los avisos de plazo
// Los porcentajes se CALCULAN, no se escriben a mano: el reparto ya no es fijo, así que
// un "10%" rotulado mentiría en cuanto el asesor mueva una variante.
$pc = fn(float $x) => rtrim(rtrim(number_format($x * 100, 1), '0'), '.') . '%';
$hoy  = new DateTimeImmutable('now');
?>
<!doctype html>
<html lang="es"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Cotización<?= $cliente !== '' ? ' · ' . h($cliente) : '' ?> · <?= h(implode(' + ', $codigos)) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Geist:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
/* Hallmark · genre: modern-minimal · theme: Quiet (custom, anclado en el AZUL MARINO
   real de Galjosa — el mismo #1A52A8 que usa el Sales War Room dashboard, oklch(45.4%
   0.15 259) — no el teal/celeste del primer intento). Alcance: chrome de página +
   panel de opciones. La tabla de pagos y los bloques de datos de abajo NO se tocan —
   siguen usando las variables --azul/--tinta/--linea/--gris tal cual, con sus valores
   originales sin cambiar, para que ese formato quede exactamente igual. */
:root{
  /* Variables originales: se QUEDAN con su valor de siempre — de ellas depende la
     tabla de pagos y los bloques de abajo, que no se tocan en este rediseño. */
  --azul:#0c6c9c; --tinta:#0c2c44; --linea:#dfe6ec; --gris:#5a6b7a;

  /* Tokens nuevos: solo los usa el chrome de página y el panel de opciones. Hue 259 =
     el azul marino de marca (#1A52A8), no el hue 240 anterior (más celeste/teal). */
  --paper:      oklch(98.2% 0.004 259);
  --paper-2:    oklch(95.8% 0.007 259);
  --ink:        oklch(22%   0.018 259);
  --ink-2:      oklch(40%   0.015 259);
  --muted:      oklch(53%   0.016 259);
  --border:     oklch(89%   0.012 259);
  --border-2:   oklch(93.5% 0.009 259);
  --accent:     oklch(45.4% 0.150 259);   /* = #1A52A8, azul marino real de Galjosa */
  --accent-ink: oklch(20%   0.050 259);
  --focus:      oklch(56%   0.170 259);
  --chrome:     oklch(28%   0.120 259);   /* barra superior: azul marino de verdad (#012a68-ish), no casi-negro */
  --chrome-2:   oklch(23%   0.110 259);   /* hover del botón Recalcular: un tono más oscuro del mismo marino */

  --space-3xs:.125rem; --space-2xs:.25rem; --space-xs:.5rem; --space-sm:.75rem;
  --space-md:1rem; --space-lg:1.5rem; --space-xl:2.5rem;
  --radius-sm:8px; --radius-md:10px; --radius-lg:16px; --radius-pill:999px;
  --font: 'Geist', ui-sans-serif, system-ui, -apple-system, sans-serif;
  --ease-out: cubic-bezier(.16,1,.3,1);
}
  *{box-sizing:border-box}
  body{margin:0;font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:var(--tinta);background:#eef2f6}

  /* ---------- Chrome de página (barra superior) ---------- */
  /* Pegajoso: al hacer scroll se queda arriba, para no tener que subir de
     nuevo a buscar "Descargar PDF". En @media print se anula abajo (position
     sticky en una hoja impresa no significa nada, pero mejor ser explícito). */
  .barra{position:sticky;top:0;z-index:20;background:var(--chrome);color:#fff;
         padding:var(--space-md) var(--space-lg);
         display:flex;align-items:center;gap:var(--space-md);flex-wrap:wrap;
         border-bottom:1px solid oklch(45.4% 0.15 259 / .4);font-family:var(--font);
         overflow:hidden}
  /* Reflejo que recorre la franja, igual que la barra del generador de historias
     (mismo gradiente, misma duración de 6.5s). overflow:hidden en .barra para que
     no se asome por los lados. */
  .barra::after{content:"";position:absolute;top:0;left:-65%;width:45%;height:100%;z-index:0;
                pointer-events:none;
                background:linear-gradient(100deg,transparent,rgba(255,255,255,.20),transparent);
                animation:brillo 6.5s ease-in-out infinite}
  @keyframes brillo{0%{left:-65%}55%,100%{left:135%}}
  /* Quien de verdad no quiere animaciones no debería recibir una barra parpadeando. */
  @media (prefers-reduced-motion:reduce){ .barra::after{animation:none;opacity:0} }
  .barra .logo-barra{height:30px;width:auto;display:block;position:relative;z-index:1}
  .barra h1{font-size:13px;margin:0;font-weight:600;letter-spacing:.06em;text-transform:uppercase;
            color:oklch(78% 0.04 259);position:relative;z-index:1}
  .barra .sp{margin-left:auto;display:flex;gap:var(--space-xs);position:relative;z-index:1}
  .barra button{border:1.5px solid oklch(100% 0 0 / .28);border-radius:var(--radius-pill);
                padding:9px 20px;font-size:13.5px;font-weight:600;font-family:var(--font);
                cursor:pointer;transition:background var(--dur-fast,140ms) var(--ease-out),
                border-color var(--dur-fast,140ms) var(--ease-out);background:transparent;color:#fff}
  .barra button:hover{background:oklch(100% 0 0 / .08);border-color:oklch(100% 0 0 / .5)}
  .barra button:focus-visible{outline:2px solid oklch(70% 0.15 259);outline-offset:2px}
  .imprimir{background:#fff !important;color:var(--accent-ink) !important;border-color:#fff !important}
  .imprimir:hover{background:oklch(93% 0.015 259) !important}

  /* Dos columnas: opciones a la izquierda, plan de pagos a la derecha — así se ve
     todo a la vez sin subir y bajar. Mismo patrón (y mismo ancho de columna) que
     el cotizador original. align-items:start para que el panel no se estire al
     alto de la tabla. */
  .envoltura{max-width:1240px;margin:var(--space-lg) auto;padding:0 var(--space-md);
             display:grid;grid-template-columns:420px minmax(0,1fr);
             gap:var(--space-lg);align-items:start}
  /* Pantalla angosta: una sola columna, opciones arriba (como estaba antes). */
  @media (max-width:1080px){
    .envoltura{grid-template-columns:minmax(0,1fr)}
    .tarjeta-opciones{position:static !important}
  }

  /* ---------- Panel de opciones (tarjeta propia, separada de los resultados) ---------- */
  /* Pegajoso bajo la barra (que mide ~53px): al recorrer una tabla de 56 cuotas
     los controles siguen a la vista. max-height + overflow para que si el panel
     llegara a ser más alto que la pantalla no queden campos inalcanzables. */
  .tarjeta-opciones{background:var(--paper);border:1px solid var(--border-2);border-radius:var(--radius-lg);
                    padding:var(--space-lg);font-family:var(--font);
                    position:sticky;top:68px;max-height:calc(100vh - 88px);overflow-y:auto}
  /* Rejilla de 2 columnas dentro del panel: los campos quedan alineados en vez de
     ragged como los dejaba el flex-wrap. */
  .ajustes{display:grid;grid-template-columns:1fr 1fr;gap:var(--space-md) var(--space-sm);align-items:end}
  .ajustes .col2{grid-column:1 / -1}
  .ajustes .ir{grid-column:1 / -1;justify-self:start}
  .ajustes label{display:block;font-size:11px;text-transform:uppercase;letter-spacing:.07em;
                 color:var(--muted);margin-bottom:6px;font-weight:600}
  /* width:100% para que cada campo llene su celda de la rejilla. Gana por
     especificidad a las clases .w-* del marcado (clase+tipo vs clase sola), que
     quedan sin efecto a propósito: en dos columnas los anchos fijos en px se
     veían desalineados. */
  .ajustes input,.ajustes select{width:100%;padding:9px 12px;border:1.5px solid var(--border);border-radius:var(--radius-sm);
                 font-size:14px;font-family:var(--font);color:var(--ink);background:#fff;
                 transition:border-color 120ms var(--ease-out),box-shadow 120ms var(--ease-out)}
  .ajustes input:hover,.ajustes select:hover{border-color:oklch(65% 0.03 259)}
  .ajustes input:focus-visible,.ajustes select:focus-visible{outline:none;border-color:var(--accent);
                 box-shadow:0 0 0 3px oklch(45.4% 0.15 259 / .16)}
  .ajustes input:disabled{background:var(--paper-2);color:var(--muted);border-color:var(--border-2);cursor:not-allowed}
  .campo-fijo{font-size:11px;color:var(--muted);margin-top:4px;font-style:normal}
  /* Recalcular: azul marino de marca, no negro/tinta — el usuario lo pidió puntual
     junto con la franja de arriba. */
  .ajustes .ir{background:var(--chrome);color:#fff;border:0;border-radius:var(--radius-pill);
               padding:11px 22px;font-size:14px;font-weight:600;font-family:var(--font);cursor:pointer;
               transition:background 120ms var(--ease-out),transform 80ms var(--ease-out)}
  .ajustes .ir:hover{background:var(--chrome-2)}
  .ajustes .ir:active{transform:scale(.98)}
  .ajustes .ir:focus-visible{outline:2px solid var(--focus);outline-offset:2px}
  /* Grupos de campos relacionados (firma diferida, extraordinaria): un rótulo que
     explica la idea en una frase, con sus campos justo debajo — para que no se lean
     como una fila suelta de labels sin conexión entre sí. */
  .grupo{grid-column:1 / -1;background:var(--paper-2);border:1px solid var(--border-2);border-radius:var(--radius-md);
         padding:var(--space-md) var(--space-md);margin-top:var(--space-2xs)}
  .grupo-tit{font-size:12.5px;font-weight:600;color:var(--ink-2);margin-bottom:var(--space-sm);font-family:var(--font)}
  /* Rejilla de 2 columnas, no flex-wrap: con flex, "Mes 2 de 2" no cabía al lado y
     se iba a la línea siguiente pegado a la izquierda — debajo del checkbox en vez
     de debajo de "Mes 1 de 2". */
  .grupo-campos{display:grid;grid-template-columns:1fr 1fr;gap:var(--space-sm) var(--space-md);
                align-items:end}
  /* El mes 2 se fija en la 2ª columna: por colocación automática caería en la 1ª
     (fila 2), que es justo el desorden que había que arreglar. */
  #wrap-mes2{grid-column:2}
  /* .ajustes .chk-linea (dos clases) para ganarle en especificidad a ".ajustes
     label" — si no, el checkbox hereda el text-transform:uppercase pensado
     para las etiquetas de los campos, no para texto de un checkbox. */
  .ajustes .chk-linea{display:flex;align-items:center;gap:8px;font-size:13px;color:var(--ink-2);
             cursor:pointer;align-self:center;font-family:var(--font);text-transform:none;
             letter-spacing:normal;font-weight:400}
  .chk-linea input[type=checkbox]{width:16px;height:16px;margin:0;accent-color:var(--accent);cursor:pointer}

  /* Logos del documento (Galjosa + proyecto): igual que el cotizador original. */
  .logos{display:flex;align-items:center;gap:22px;margin-bottom:20px;flex-wrap:wrap}
  .logos img{height:48px;width:auto}

  .datos{display:grid;grid-template-columns:auto 1fr;gap:6px 20px;font-size:14px;margin-bottom:16px}
  .datos dt{color:var(--gris)}
  .datos dd{margin:0;font-weight:600}
  /* Amarillo #fff200 y verde #6f8f3f: los MISMOS del cotizador original
     (cotizador-galjosa), no una versión pálida. Se veían apagados porque
     antes usaba #fff8c5/#e8f2e2 — colores propios, no los de la marca. */
  .precio{background:#fff200;border-radius:8px;padding:11px 14px;display:flex;justify-content:space-between;
          font-size:17px;font-weight:700;margin-bottom:10px}
  .legal{background:#6f8f3f;color:#fff;border-radius:8px;padding:10px 14px;display:flex;justify-content:space-between;
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
  tr.diferido td{background:#eef5ff}
  .etq{display:inline-block;background:#f0b429;color:#4a3200;font-size:9.5px;font-weight:700;
       padding:2px 6px;border-radius:4px;margin-left:7px;letter-spacing:.6px}
  .etq2{display:inline-block;background:#3b82c4;color:#fff;font-size:9.5px;font-weight:700;
       padding:2px 6px;border-radius:4px;margin-left:7px;letter-spacing:.6px}
  .aviso{background:#fff4e5;border:1px solid #ffd9a0;color:#7a5200;border-radius:8px;
         padding:10px 13px;font-size:13px;margin-bottom:16px}
  /* Variante ROJA del aviso: se usa cuando lo que escribió el asesor NO cuadra y
     hay que decirle por qué. El .aviso ámbar informa; este corrige. */
  .aviso-rojo{background:#fdecea;border:1px solid #f5b5ae;color:#8f2418;border-radius:8px;
         padding:10px 13px;font-size:13px;margin:8px 0 4px}
  /* Texto de apoyo debajo de un campo, para explicar la regla sin abrir un tooltip. */
  .ayuda-campo{font-size:11.5px;color:var(--gris);margin:4px 0 8px;line-height:1.45}
  .pie{font-size:11.5px;color:var(--gris);margin-top:16px;line-height:1.5}
  @media print{
    body{background:#fff}
    .barra,.ajustes,.tarjeta-opciones,.noimp{display:none !important}
    /* display:block obligatorio: en pantalla .envoltura es una rejilla de 2
       columnas, y al imprimir el documento tiene que ocupar la hoja completa. */
    .envoltura{display:block;max-width:none;margin:0;padding:0}
    .tarjeta{box-shadow:none;border-radius:0;padding:0}
    thead th{background:var(--tinta) !important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
    tr.extra td,.precio,.legal{-webkit-print-color-adjust:exact;print-color-adjust:exact}
  }
</style>
</head><body>

<div class="barra">
  <img class="logo-barra" src="assets/logo_galjosa_transparente.png" alt="Galjosa"
       onerror="this.style.display='none'">
  <h1><span style="color:#fff;font-weight:700;letter-spacing:.02em">GALJOSA</span> · Cotización</h1>
  <div class="sp"><button class="imprimir" onclick="window.print()">Descargar PDF</button></div>
</div>

<div class="envoltura"><div class="tarjeta-opciones">

  <form class="ajustes" method="get">
    <input type="hidden" name="u" value="<?= h(implode(',', $ids)) ?>">
    <input type="hidden" name="d" value="<?= (int)$dealId ?>">
    <input type="hidden" name="exp" value="<?= (int)$exp ?>">
    <input type="hidden" name="s" value="<?= h($sig) ?>">
    <?php if ($fusion): ?><input type="hidden" name="unif" value="0"><?php endif; ?>
    <div class="col2"><label>Cliente</label>
      <input type="text" name="cliente" value="<?= h($cliente) ?>" placeholder="Nombre del cliente"></div>
    <!-- Precio negociable: arranca en el PVP del inventario y el asesor lo puede
         cambiar. Vacío = vuelve al del inventario. En una fusión es el TOTAL. -->
    <div class="col2"><label>Precio del activo<?= $precioEditado ? ' · editado' : '' ?></label>
      <input type="text" name="precio" inputmode="decimal"
             value="<?= $precioEditado ? h(number_format($pvp, 2, '.', '')) : '' ?>"
             placeholder="<?= h(number_format($pvpInventario, 2, '.', '')) ?> (del inventario)"
             title="Precio de venta de la unidad. Si lo dejas vacío se usa el del inventario (<?= h(cot_money($pvpInventario)) ?>). Todo el plan se recalcula sobre este número."></div>
    <div><label>Cuotas<?= !empty($plan['plazoFijo']) ? ' · fijas' : '' ?></label>
      <input type="number" name="n" min="1" max="<?= (int)($plan['plazoMax'] ?? 120) ?>" value="<?= (int)$plan['cuotas'] ?>"
             <?= !empty($plan['plazoFijo']) ? 'readonly' : '' ?>
             title="<?= !empty($plan['plazoFijo'])
               ? 'Proyecto en planos: el plazo lo fija la fecha de entrega y no se acorta. Si el cliente puede pagar más al mes, se reducen las extraordinarias.'
               : 'Se deshabilita si llenas \'o paga al mes\' — el motor siempre prioriza el presupuesto sobre este número.' ?>"></div>
    <!-- La otra forma de preguntar, y la que más se usa vendiendo: el cliente dice
         cuánto puede pagar al mes y salen las cuotas. Si se llena, MANDA sobre "Cuotas"
         (que por eso se deshabilita — ver script al final del formulario). -->
    <div><label>o paga al mes</label>
      <input type="text" name="presu" inputmode="decimal" placeholder="$"
             value="<?= $presu > 0 ? h(number_format($presu, 0)) : '' ?>"></div>
    <div><label>Modalidad</label>
      <!-- Etiquetas cortas: "Estándar (con extraordinarias)" no cabía en la columna
           de 420px y salía cortado a media palabra. El detalle completo va en el
           title, y el grupo de abajo ya dice "Cuota extraordinaria (una por año)". -->
      <select name="mod" title="Estándar = 20% en cuotas + 10% en extraordinarias · Iguales = 30% repartido en cuotas iguales, sin extraordinarias">
        <option value="estandar" <?= $modalidad === 'estandar' ? 'selected' : '' ?>>Con extraordinarias</option>
        <option value="iguales"  <?= $modalidad === 'iguales'  ? 'selected' : '' ?>>Cuotas iguales</option>
      </select></div>
    <div><label>Primera cuota</label>
      <input type="month" name="mes" value="<?= h($plan['inicio']) ?>" min="<?= $hoy->format('Y-m') ?>"></div>
    <!-- % a financiar antes de la entrega: 40 es lo común, piso de negocio 35
         (se topa DENTRO del motor, el min/max de aquí es solo guía visual). -->
    <div><label>Financia %</label>
      <input type="number" name="financiar" min="35" max="100" step="1"
             value="<?= $vFinanciar !== '' ? h($vFinanciar) : round($plan['financiarPct']) ?>"
             title="% del precio que se paga antes de la entrega (separación + firma + cuotas + extraordinarias). Lo común es 40. El piso de negocio es 35 — no baja de ahí aunque se escriba menos."></div>
    <div><label>A la firma</label>
      <input type="text" name="firma" inputmode="decimal" placeholder="auto" value="<?= h($vFirma) ?>"
             title="Monto exacto que paga al firmar. Vacío = el que sale del reparto normal."></div>

    <!-- Grupo "firma diferida": los dos campos van juntos, con un rótulo arriba que
         explica la idea completa en una frase, porque separados como dos labels
         sueltas ("Diferir firma (meses)" / "...cuota de eso") no se entendía qué
         hacían entre sí. -->
    <div class="grupo">
      <div class="grupo-tit" title="En vez de pagar toda la firma al firmar, se reparte sumada a la cuota de los primeros meses.">
        Diferir la firma en cuotas (opcional)
      </div>
      <div class="grupo-campos">
        <!-- "Meses" SIEMPRE muestra el número real que salió del cálculo
             (plan.diferidoMeses), no lo que se haya escrito a mano — si no,
             quedaba en 0 aunque el motor ya hubiera resuelto los meses a
             partir de "Cuota mensual de eso". Se deshabilita cuando esa cuota
             tiene valor, mismo patrón que Cuotas/o-paga-al-mes: dos formas de
             pedir lo mismo no pueden estar las dos activas a la vez. -->
        <div><label>Meses</label>
          <input type="number" name="firmames" min="0" max="12" placeholder="0"
                 value="<?= (int)$plan['diferidoMeses'] > 0 ? (int)$plan['diferidoMeses'] : ($vFirmaMeses > 0 ? (int)$vFirmaMeses : '') ?>"
                 title="Sobre cuántos meses repartir la firma. Tope 12. Se calcula solo si llenas 'Cuota mensual de eso'."></div>
        <div><label>Cuota mensual de eso</label>
          <input type="text" name="firmacuota" inputmode="decimal" placeholder="auto" value="<?= h($vFirmaCuota) ?>"
                 title="Monto mensual editable de esa firma diferida. Si en 12 meses no alcanza a cubrirla, el resto se suma a las extraordinarias."></div>
      </div>
    </div>

    <?php if ($modalidad !== 'iguales'):
      $mesesSel = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
    ?>
    <!-- Grupo "extraordinaria": el checkbox y los meses van juntos, y el segundo mes
         se muestra/oculta con JS al toque — antes solo aparecía después de apretar
         Recalcular, que confundía porque parecía que no había pasado nada. -->
    <div class="grupo">
      <?php if ($modelo['entregaMax'] > 0): ?>
      <div class="grupo-tit">Entrega estimada</div>
      <div class="grupo-campos">
        <div><label title="Galero Casas se entrega entre <?= $modelo['entregaMin'] ?> y <?= $modelo['entregaMax'] ?> meses. De acá sale el plazo máximo de cuotas.">Entrega en</label>
          <select name="entregameses" onchange="this.form.submit()">
            <?php for ($mm = $modelo['entregaMin']; $mm <= $modelo['entregaMax']; $mm += 6): ?>
            <option value="<?= $mm ?>" <?= $vEntregaMeses === $mm ? 'selected' : '' ?>><?= $mm ?> meses<?= $mm === $modelo['entregaMax'] ? ' (máximo)' : '' ?></option>
            <?php endfor; ?>
          </select></div>
        <div class="ayuda-campo">Manda el plazo: no caben cuotas después de la entrega.</div>
      </div>
      <?php endif; ?>
      <?php
      // MESES QUE DE VERDAD EXISTEN en la tabla. Ofrecer los 12 era una trampa: si el
      // asesor elegía un mes que el plan no toca, la extraordinaria caía en otro y la
      // pantalla mostraba una fecha que nadie eligió. Ahora solo se ofrecen los meses
      // con cuota, y si el plan es corto se ve de una que hay menos opciones.
      $mesesPlan = [];
      foreach (($plan['filas'] ?? []) as $f) {
          $mm = (int)substr((string)$f['fecha'], 3, 2);   // dd/mm/yyyy
          if ($mm >= 1 && $mm <= 12) $mesesPlan[$mm] = true;
      }
      $mesesPlan = array_keys($mesesPlan);
      sort($mesesPlan);
      if (!$mesesPlan) $mesesPlan = range(1, 12);
      ?>
      <div class="grupo-tit">Cuota extraordinaria (una por año)</div>
      <div class="grupo-campos">
        <label class="chk-linea" title="Parte la extraordinaria de cada año en dos pagos en vez de uno solo.">
          <input type="checkbox" name="extrapartes" value="2" id="chk-partes" <?= $extraPartes === 2 ? 'checked' : '' ?>
                 onchange="document.getElementById('wrap-mes2').style.display=this.checked?'':'none';
                           document.getElementById('lbl-mes1').textContent=this.checked?'Mes 1 de 2':'Mes de pago'">
          Partir en 2 pagos
        </label>
        <?php if ($modelo['maxExtra'] > 1): ?>
        <!-- PERSONALIZAR: en vez de repartir el total en partes iguales, el asesor
             escribe cuánto va en cada extraordinaria. La ÚLTIMA no se escribe: sale
             del residuo, así el plan siempre cuadra con el precio y no hay forma de
             dejar un descuadre a mano. Si lo escrito se pasa del total, el aviso
             explica en rojo por qué y cuánto sobra. -->
        <label class="chk-linea" title="Escribir cuánto va en cada extraordinaria. La última se calcula sola con lo que falte.">
          <input type="checkbox" name="extrapers" value="1" id="chk-pers" <?= $extraPers ? 'checked' : '' ?>
                 onchange="document.getElementById('wrap-pers').style.display=this.checked?'':'none'">
          Personalizar montos
        </label>
        <?php endif; ?>
        <?php if ($modelo['maxExtra'] > 1 && (int)$plan['nExtra'] > 1): ?>
        <div id="wrap-pers" style="<?= $extraPers ? '' : 'display:none' ?>">
          <div class="ayuda-campo">Escribí las primeras. La <b>última sale sola</b> con lo que falte para cuadrar el total de <?= '$' . number_format($plan['extraTotal'], 2) ?>.</div>
          <div class="grupo-campos">
            <?php for ($k = 1; $k <= (int)$plan['nExtra'] - 1; $k++): ?>
            <div><label>Extra <?= $k ?></label>
              <input type="text" name="extramonto<?= $k ?>" inputmode="decimal"
                     value="<?= $extraPers && isset($plan['extraMontos'][$k-1]) ? number_format($plan['extraMontos'][$k-1], 2, '.', '') : '' ?>"></div>
            <?php endfor; ?>
            <div><label>Extra <?= (int)$plan['nExtra'] ?> (sale sola)</label>
              <input type="text" value="<?= isset($plan['extraMontos'][(int)$plan['nExtra']-1]) ? number_format($plan['extraMontos'][(int)$plan['nExtra']-1], 2, '.', '') : number_format($plan['valorExtra'], 2, '.', '') ?>" readonly
                     title="La calcula el sistema con lo que falte. No se puede escribir: es lo que garantiza que el plan cuadre con el precio."></div>
          </div>
          <?php if (!empty($plan['extraExcedido'])): ?>
          <div class="aviso-rojo">Lo que escribiste se pasa por <b><?= '$' . number_format($plan['extraExcedido'], 2) ?></b> del total en extraordinarias (<?= '$' . number_format($plan['extraTotal'], 2) ?>). Las últimas quedaron en cero porque ya no había de dónde. Bajá los montos o subí el % a financiar.</div>
          <?php endif; ?>
        </div>
        <?php endif; ?>
        <div><label id="lbl-mes1"><?= $extraPartes === 2 ? 'Mes 1 de 2' : 'Mes de pago' ?></label>
          <select name="extrames1">
            <?php foreach ($mesesPlan as $m): ?>
            <option value="<?= $m ?>" <?= (int)$plan['extraMes1'] === $m ? 'selected' : '' ?>><?= $mesesSel[$m] ?></option>
            <?php endforeach; ?>
          </select></div>
        <div id="wrap-mes2" style="<?= $extraPartes === 2 ? '' : 'display:none' ?>">
          <label>Mes 2 de 2</label>
          <select name="extrames2">
            <?php foreach ($mesesPlan as $m): ?>
            <option value="<?= $m ?>" <?= (int)$plan['extraMes2'] === $m ? 'selected' : '' ?>><?= $mesesSel[$m] ?></option>
            <?php endforeach; ?>
          </select></div>
      </div>
    </div>
    <?php endif; ?>
    <?php if ($fusion): ?>
    <div class="col2" style="align-self:center">
      <label class="chk-linea" title="Unificado = un solo plan por el total. Separado = un plan por cada unidad.">
        <input type="checkbox" name="unif" value="1" <?= $unificar ? 'checked' : '' ?>>
        Unificar las <?= count($unidades) ?> en un solo plan
      </label>
    </div>
    <?php endif; ?>
    <?php if ($suites >= 2): ?>
    <!-- Solo aparece con 2+ suites de Noral Plaza: es el único caso donde la regla existe. -->
    <div class="col2" style="align-self:center">
      <label class="chk-linea">
        <input type="checkbox" name="sinparq" value="1" <?= $sinParqueo ? 'checked' : '' ?>>
        Una unidad sin parqueo <b>(−<?= h(cot_money((float)COT_PARQUEO)) ?>)</b>
      </label>
    </div>
    <?php endif; ?>
    <button class="ir" type="submit">Recalcular</button>
  </form>
</div>

<script>
(function(){
  var form      = document.querySelector('form.ajustes');
  var cuotasEl  = document.querySelector('input[name="n"]');
  var presuEl   = document.querySelector('input[name="presu"]');
  var mesesEl   = document.querySelector('input[name="firmames"]');
  var cuotaFEl  = document.querySelector('input[name="firmacuota"]');
  var financiarEl = document.querySelector('input[name="financiar"]');
  if (!form || !cuotasEl || !presuEl) return;

  // "Todo estricto": dos formas de pedir lo mismo no pueden estar activas a la
  // vez. Cuotas ↔ o-paga-al-mes, y Meses ↔ cuota-mensual-de-la-firma-diferida
  // (el motor siempre prioriza el segundo de cada par cuando tiene valor).
  function exclusivo(principal, secundario, explicacion){
    if (!principal || !secundario) return;
    function sync(){
      var activo = secundario.value.trim() !== '';
      principal.disabled = activo;
      principal.title = activo ? explicacion : '';
    }
    secundario.addEventListener('input', sync);
    sync();
  }
  // Con PLAZO FIJO (proyecto en planos) ya no compiten: el plazo lo manda la fecha
  // de entrega y "o paga al mes" solo mueve las extraordinarias. Deshabilitar
  // Cuotas ahí sería mentir — está fijo por otra razón, y readonly ya lo dice.
  if (!cuotasEl.hasAttribute('readonly')) {
    exclusivo(cuotasEl, presuEl, 'Se calcula solo a partir de "o paga al mes"');
  }
  exclusivo(mesesEl,  cuotaFEl, 'Se calcula solo a partir de "cuota mensual de eso"');

  // "Todo debe estar relacionado", sin refrescar la página: escribir cuánto
  // quiere pagar (o cambiar Cuotas / Financia % / la firma diferida) recalcula
  // TODO solo — Cuotas real, Meses real, resumen, tabla, avisos — 900ms después
  // de dejar de escribir. El cálculo sigue siendo 100% del servidor (PHP): se
  // pide la MISMA página por fetch con los valores actuales del formulario, y
  // solo se reemplaza el contenido — no hay navegación ni parpadeo de recarga.
  // Si el fetch fallara por algo (red, CORS raro), cae a un submit normal.
  function serializar(){
    var qs = new URLSearchParams();
    Array.prototype.forEach.call(form.elements, function(el){
      if (!el.name || el.disabled) return;
      if ((el.type === 'checkbox' || el.type === 'radio') && !el.checked) return;
      qs.append(el.name, el.value);
    });
    return qs.toString();
  }
  // ejecutarRecalculo() es la acción real (sin espera). autoRecalcular() es el
  // envoltorio con pausa, para no disparar un fetch en cada tecla mientras se
  // sigue escribiendo un número. Selects y checkboxes llaman a la acción real
  // DIRECTO — son una elección discreta, no hace falta esperar nada.
  function ejecutarRecalculo(){
    var url = window.location.pathname + '?' + serializar();
    fetch(url, { credentials: 'same-origin' })
      .then(function(r){ if (!r.ok) throw new Error('http ' + r.status); return r.text(); })
      .then(function(html){
        var nuevo = new DOMParser().parseFromString(html, 'text/html');
        // Campos que el servidor recalcula y hay que reflejar de vuelta —
        // "Cuotas" y "Meses" cuando el otro campo del par manda sobre ellos.
        ['n', 'firmames'].forEach(function(name){
          var actual = form.querySelector('[name="' + name + '"]');
          var fresco = nuevo.querySelector('[name="' + name + '"]');
          if (actual && fresco) actual.value = fresco.value;
        });
        // Resultado (precio, resumen, tabla, avisos): se reemplaza entero, es
        // la misma tarjeta con los números ya al día.
        var resActual = document.querySelector('.tarjeta');
        var resFresco = nuevo.querySelector('.tarjeta');
        if (resActual && resFresco) resActual.innerHTML = resFresco.innerHTML;
        history.replaceState(null, '', url);
      })
      .catch(function(){ form.submit(); });
  }
  var timer = null;
  function autoRecalcular(){
    clearTimeout(timer);
    timer = setTimeout(ejecutarRecalculo, 900);
  }
  Array.prototype.forEach.call(form.elements, function(el){
    if (!el.name || el.type === 'hidden' || el.type === 'submit') return;
    var inmediato = (el.tagName === 'SELECT' || el.type === 'checkbox');
    el.addEventListener(inmediato ? 'change' : 'input', inmediato ? ejecutarRecalculo : autoRecalcular);
  });
})();
</script>

<div class="tarjeta">
  <!-- Logos: como el cotizador original — Galjosa siempre, más el del proyecto
       (Noral Plaza o Noral Apartments) según la unidad. Van en pantalla Y en el
       PDF (no son un anexo de impresión, son parte del documento). onerror los
       oculta solos si algún día falta el archivo, en vez de romper el layout. -->
  <div class="logos">
    <img src="assets/logo_galjosa_transparente.png" alt="Galjosa" onerror="this.style.display='none'">
    <?php if ($esPlaza): ?>
    <img src="assets/logo_noral_plaza.png" alt="Noral Plaza" onerror="this.style.display='none'">
    <?php endif; ?>
    <?php if ($esApartments): ?>
    <img src="assets/logo_noral_apartments.png" alt="Noral Apartments" onerror="this.style.display='none'">
    <?php endif; ?>
    <?php if ($esGalero): ?>
    <img src="assets/logo_galero.png" alt="Galero Urbanización" onerror="this.style.display='none'">
    <?php endif; ?>
  </div>

  <?php if (!empty($plan['insuficiente'])): ?>
    <div class="aviso">Con <b><?= h(cot_money($plan['presupuesto'])) ?>/mes</b> no alcanza ni pagando hasta la entrega.
      La cuota mínima posible para esta unidad es <b><?= h(cot_money($plan['cuotaMinima'])) ?>/mes</b>
      (a <?= (int)$plan['cuotas'] ?> cuotas). Es lo que se muestra abajo.</div>
  <?php elseif ($plan['presupuesto'] > 0 && !empty($plan['extraAbsorbio'])): ?>
    <div class="aviso" style="background:#eaf6ff;border-color:#b9ddf5;color:#0c4a6e">
      El plazo no se toca: <b><?= (int)$plan['cuotas'] ?> cuotas</b> hasta la entrega
      (<?= h(cot_mes_es((int)$entrega['m']) . ' ' . $entrega['y']) ?>).
      Pagando <b><?= h(cot_money($plan['presupuesto'])) ?>/mes</b>
      <?php if ((float)$plan['extraTotal'] > 0.01): ?>
        la extraordinaria queda en <b><?= h(cot_money($plan['valorExtra'])) ?></b>
        (<?= (int)$plan['nExtra'] ?> en total, <?= h(cot_money($plan['extraTotal'])) ?>).
      <?php else: ?>
        <b>ya no quedan extraordinarias</b>: la cuota cubre todo lo que iba en ellas.
      <?php endif; ?>
    </div>
  <?php elseif ($plan['presupuesto'] > 0): ?>
    <div class="aviso" style="background:#eaf6ff;border-color:#b9ddf5;color:#0c4a6e">
      Con <b><?= h(cot_money($plan['presupuesto'])) ?>/mes</b> son
      <b><?= (int)$plan['cuotas'] ?> cuotas</b> de <b><?= h(cot_money($plan['mensual'])) ?></b>.</div>
  <?php endif; ?>
  <?php if ($precioEditado): ?>
    <div class="aviso" style="background:#fff7e6;border-color:#f0d090;color:#7a4b00">
      Precio <b>editado a mano</b>: <?= h(cot_money($pvp)) ?>.
      En el inventario esta unidad está en <b><?= h(cot_money($pvpInventario)) ?></b>.
      Todo el plan de abajo se calculó sobre el precio editado.</div>
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
  <?php if (!empty($plan['sobrepago'])): ?>
    <div class="aviso">Lo que se pidió pagar antes de la entrega <b>excede el precio</b>. Se recortó la
      cuota a <b><?= h(cot_money($plan['mensual'])) ?></b>, que es el máximo que cabe en
      <?= (int)$plan['cuotas'] ?> cuotas con esa firma y esas extraordinarias.</div>
  <?php elseif (!empty($plan['bajaContraentrega'])): ?>
    <!-- No es un error: adelantar más deja menos para el final. Pero el % objetivo
         suele estar amarrado al crédito del cliente, así que esto lo tiene que ver
         un humano. Se compara contra 'contraPctObjetivo' (lo elegido con Financia %),
         no un 60% fijo — el objetivo ya puede ser 65% si el vendedor bajó a 35%. -->
    <div class="aviso">Con este plan el cliente adelanta más de lo elegido, así que la
      <b>contraentrega baja a <?= h(cot_money($plan['contraentrega'])) ?></b>
      (<?= h($pc($plan['contraPct'])) ?> en vez de <?= h($pc($plan['contraPctObjetivo'])) ?>).
      Verifica que el crédito del cliente cuadre con eso.</div>
  <?php endif; ?>
  <?php if ($plan['mensual'] <= 0.01 && $plan['cuotas'] > 0): ?>
    <div class="aviso">La cuota mensual quedó en <b>$0</b>: entre la firma y las extraordinarias
      ya se cubre todo lo que va antes de la entrega. Baja alguna de las dos si quieres cuotas reales.</div>
  <?php endif; ?>
  <?php if ($plan['diferidoMeses'] > 0): ?>
    <div class="aviso" style="background:#eaf6ff;border-color:#b9ddf5;color:#0c4a6e">
      La firma no se paga al firmar: se reparte en <b><?= (int)$plan['diferidoMeses'] ?> cuota<?= $plan['diferidoMeses'] > 1 ? 's' : '' ?></b>
      de <b><?= h(cot_money($plan['diferidoCuota'])) ?></b> cada una, sumadas a la cuota normal (marcadas <b>FIRMA</b> en la tabla).
      <?= $plan['diferidoMeses'] >= 12 ? ' Es el tope: no se puede diferir más de 12 meses.' : '' ?></div>
  <?php endif; ?>

  <?php if ($cliente !== ''): ?>
  <dl class="datos"><dt>Cliente</dt><dd><?= h($cliente) ?></dd></dl>
  <?php endif; ?>

  <?php foreach ($bloques as $bi => $B): $plan = $B['plan']; ?>
  <?php if (count($bloques) > 1): ?>
    <h2 style="font-size:15px;margin:26px 0 10px;padding-top:16px;
               border-top:1px solid var(--linea);letter-spacing:.3px">
      Opción <?= $bi + 1 ?> · <?= h(implode(' + ', $B['cods'])) ?></h2>
  <?php endif; ?>

  <dl class="datos">
    <dt>Proyecto</dt><dd><?= h($proyecto) ?></dd>
    <dt><?= count($B['cods']) > 1 ? 'Unidades' : 'Unidad' ?></dt>
    <dd><?= h(implode(' + ', $B['cods'])) ?><?= count($B['cods']) > 1 ? ' <span style="font-weight:400;color:var(--gris)">(' . count($B['cods']) . ' activos fusionados)</span>' : '' ?></dd>
    <?php if ($B['m2'] > 0): ?><dt>Metros<?= count($B['cods']) > 1 ? ' (suma)' : '' ?></dt><dd><?= number_format($B['m2'], 2) ?> m²</dd><?php endif; ?>
  </dl>

  <?php if ($B['dcto'] > 0): ?>
    <div class="datos" style="grid-template-columns:1fr auto;margin-bottom:10px;font-size:14px">
      <div><?= count($B['cods']) > 1 ? 'Suma de las ' . count($B['cods']) . ' unidades' : 'Precio de lista' ?></div>
      <div><?= h(cot_money($B['bruto'])) ?></div>
      <div style="color:var(--gris)">Una unidad sin parqueo</div>
      <div style="color:var(--gris)">− <?= h(cot_money($dctoParq)) ?></div>
    </div>
  <?php endif; ?>
  <div class="precio"><span>Precio final</span><span><?= h(cot_money($plan['valor'])) ?></span></div>
  <div class="legal"><span>Valores legales promesa C/V</span><span><?= h(cot_money((float)$plan['legal'])) ?></span></div>
  <p>Pago directo para el notario. Se da al momento de la firma del contrato.</p>

  <div class="resumen">
    <div><span>Reserva <?= h($pc($plan['reservaPct'])) ?></span><b><?= h(cot_money($plan['reserva'])) ?></b></div>
    <div><span>Contraentrega <?= h($pc($plan['contraPct'])) ?></span><b><?= h(cot_money($plan['contraentrega'])) ?></b></div>
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
      <tr class="<?= $f['extra'] ? 'extra' : ($f['diferido'] ? 'diferido' : '') ?>">
        <td><?= (int)$f['n'] ?></td>
        <td><?= h($f['fecha']) ?><?= $f['extra'] ? '<span class="etq">EXTRA</span>' : '' ?><?= $f['diferido'] ? '<span class="etq2">FIRMA</span>' : '' ?></td>
        <td><?= h(cot_money($f['monto'])) ?></td>
      </tr>
      <?php endforeach; ?>
      <tr class="hito"><td></td><td>CONTRAENTREGA</td><td><?= h(cot_money($plan['contraentrega'])) ?></td></tr>
    </tbody>
  </table>

  <p class="pie">
    Plan: <?= h($pc($plan['reservaPct'])) ?> de reserva (separación <?= h(cot_money($plan['separacion'])) ?>
    <?= $plan['firma'] > 0 ? '+ ' . h(cot_money($plan['firma'])) . ' a la firma' : '· nada a la firma' ?>),
    <?= h($pc($plan['cuotasPct'])) ?> en cuotas mensuales<?= $plan['extraTotal'] > 0 ? ' + ' . h($pc($plan['extraPct'])) . ' en cuotas extraordinarias' : '' ?>
    y <?= h($pc($plan['contraPct'])) ?> contraentrega. Las cuotas vencen el 16 de cada mes
    y la última es en <?= h($plan['hastaTxt']) ?>.
    <?php if ($plan['nExtra'] > 0): ?>
      Incluye <?= (int)$plan['nExtra'] ?> cuota<?= $plan['nExtra'] > 1 ? 's' : '' ?> extraordinaria<?= $plan['nExtra'] > 1 ? 's' : '' ?>
      de <?= h(cot_money($plan['valorExtra'])) ?>
      (<?= $plan['extraPartes'] === 2 ? 'dos por año, en abril y diciembre' : 'una por año' ?>), que van sumadas a la cuota de ese mes.
    <?php endif; ?>
  </p>
  <?php endforeach; ?>

  <?php if (count($bloques) > 1): ?>
    <div class="datos" style="grid-template-columns:1fr auto;font-size:15px;font-weight:700;
         border-top:2px solid var(--tinta);margin-top:22px;padding-top:12px">
      <div>Si se lleva <?= count($bloques) ?> unidades</div>
      <div><?= h(cot_money(array_sum(array_column($bloques,'pvp')))) ?></div>
      <div style="font-weight:400;color:var(--gris);font-size:13px">Cuota mensual sumada</div>
      <div style="font-weight:400;color:var(--gris);font-size:13px">
        <?= h(cot_money(array_sum(array_map(fn($b)=>$b['plan']['mensual'], $bloques)))) ?></div>
    </div>
  <?php endif; ?>

  <p class="pie" style="margin-top:18px">
    Cotización generada el <?= $hoy->format('d/m/Y') ?>. Precios sujetos a cambio sin previo aviso;
    la unidad se confirma únicamente con la reserva.
  </p>

</div></div>
</body></html>
