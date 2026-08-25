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
$separadas = false;   // sin deal no hay marca: se cotiza como fusion, que es el defecto
if ($dealId > 0) {
    $d = cot_bx('crm.deal.get', ['id' => $dealId]);
    $deal = $d['result'] ?? [];
    // Fusion o separadas: lo declara el vendedor en el campo Inventario y decide si
    // se perdona un parqueo. No es una preferencia de esta pantalla.
    $separadas = unidades_separadas((string)($deal[COT_CAMPO_UNIDADES] ?? ''));
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

// CRÉDITO HIPOTECARIO. En Galero el 70% de contra entrega lo cubre el cliente con el
// banco, así que la cotización tiene que poder responder "¿y mi cuota mensual?" sin
// mandarlo a otra página. Se calca la simulación de Banco Pichincha (aliado), que el
// cliente ya conoce. REFERENCIAL: el número final lo confirma el banco.
require_once __DIR__ . '/pichinchalib.php';
$pichAnios = (int)($_GET['pichanios'] ?? 0);
$pp = pich_params();
if ($pichAnios < (int)$pp['anios_min'] || $pichAnios > (int)$pp['anios_max']) $pichAnios = 20;
// Francés (cuota fija) o Alemán (arranca más alto y baja). No es un detalle técnico:
// el banco califica al cliente por la PRIMERA cuota, así que el sistema decide si
// entra o no en el crédito.
$pichSis = (($_GET['pichsis'] ?? '') === 'aleman') ? 'aleman' : 'frances';
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
// TOPE DE CUOTAS DEL PROYECTO. Torre C es entrega inmediata: maxCuotas = 0, o sea el
// 30% se paga de una y no hay tabla de cuotas que armar. Sin esto la pantalla usaba el
// plazo por defecto (60) y repartía la entrada en 5 años, que es justo lo contrario.
$topeCuotas = (int)$modelo['maxCuotas'];
if ($topeCuotas > 0 && $cuotas > $topeCuotas) $cuotas = $topeCuotas;
if ($topeCuotas === 0 && !empty($modelo['inmediata'])) { $cuotas = 0; $opts['inmediata'] = true; }

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
// "Financia %" es una palanca de NORAL: mueve cuánto se paga antes de la entrega y
// tiene piso de 35% dentro del motor. En Galero el reparto es fijo por contrato
// (30/70 en Torre C y Casas), así que si se dejaba pasar, ese piso de 35 pisaba el
// 30 y la cotización salía 35/65 con un préstamo menor al que de verdad necesita.
$repartoFijo = !empty($modelo['banco']);
if ($vFinanciar !== '' && !$repartoFijo) $opts['financiarPct'] = (float)$vFinanciar;
// El reparto que declara el PROYECTO. Va despues de financiarPct para que un proyecto
// con reparto propio no quede a merced de lo que el asesor escriba en el formulario.
foreach (['cuotasPct', 'extraPct'] as $k)
    if (isset($modelo[$k])) $opts[$k] = (float)$modelo[$k];
// Parqueo: en una compra de 2+ suites de Noral Plaza se perdona UNO solo.
//
// Lo decide la MARCA del campo Inventario, no esta pantalla:
//   FUSION     -> una sola compra, se perdona un parqueo (-20.000)
//   SEPARADAS  -> cada unidad su compra, su contrato y su parqueo: NO se resta nada
// Es la palanca que el equipo comercial usa a proposito, y por eso vive en el deal y
// no en un parametro de la URL: la cotizacion tiene que decir lo mismo que el deal.
//
// `sinparq=0` sigue existiendo para apagarlo a mano en un caso raro, pero ya no hace
// falta prenderlo: con fusion viene puesto.
$sinParqueo = !$separadas && (($_GET['sinparq'] ?? '1') !== '0');
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
<title>Cotización<?= $cliente !== '' ? ' · ' . h($cliente) : '' ?> · <?= h(codigos_comprimidos($codigos)) ?></title>
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
  .resumen b small{font-size:11.5px;font-weight:600;color:var(--gris);margin-left:5px;
                   white-space:nowrap}
  .resumen div.destacado{background:#eef4fb;border-color:#bcd3ea}
  .resumen div.destacado span{color:#3f6a99}
  .resumen div.destacado b{color:#1c4e80}
  table{width:100%;border-collapse:collapse;font-size:13.5px}
  thead th{background:var(--tinta);color:#fff;padding:9px 12px;text-align:left;font-size:11.5px;
           letter-spacing:1px;text-transform:uppercase}
  thead th:last-child{text-align:right}
  td{padding:8px 12px;border-bottom:1px solid #eef2f6}
  td:last-child{text-align:right;font-variant-numeric:tabular-nums;font-weight:600}
  tr.hito td{background:#f5f8fa;font-weight:700}
  /* El total a la empresa se destaca: es la cifra que el cliente compara contra lo
     que le pide el banco, y en la tabla de pagos del equipo va resaltada. */
  tr.total-ini td{background:#eef4fb;color:#1c4e80}
  tr.gran-total td{background:#e8efe9;color:#1f4d2e;border-top:2px solid #1f4d2e}
  .pct{font-weight:600;font-size:10.5px;color:#5a6472;margin-left:5px}
  .etq3{display:inline-block;background:#e8eaed;color:#4a5158;font-size:9.5px;font-weight:700;
        letter-spacing:.04em;padding:1px 5px;border-radius:3px;margin-left:6px;vertical-align:1px}
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
  /* Los dos interruptores, en una fila propia a lo ancho del grupo. */
  .fila-chks{display:flex;gap:var(--space-lg);flex-wrap:wrap;align-items:center;
             margin-bottom:var(--space-sm)}
  /* Caja de montos personalizados: se separa visualmente porque es un modo aparte. */
  .pers-caja{grid-column:1 / -1;background:var(--paper);border:1px solid var(--border-2);
             border-radius:var(--radius-sm);padding:var(--space-sm) var(--space-sm) var(--space-xs);
             margin-bottom:var(--space-sm)}
  /* Rejilla propia: se acomoda sola a 4, 7 u 8 pagos sin romperse, y NO hereda las
     2 columnas rígidas de .grupo-campos, que era lo que descuadraba las etiquetas. */
  /* Un bloque por AÑO: los pagos de 2027 van juntos, los de 2028 juntos, etc.
     Antes la rejilla los ponía de a 3 y la primera fila mezclaba dos años. */
  .anio-bloque{margin-bottom:var(--space-xs)}
  .anio-tit{font-size:10px;font-weight:700;letter-spacing:.08em;color:var(--ink-2);
            margin-bottom:2px;font-variant-numeric:tabular-nums}
  .extras-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(84px,1fr));
               gap:var(--space-xs) var(--space-sm)}
  .extra-campo{display:flex;flex-direction:column;gap:2px;min-width:0}
  .extra-campo label{font-size:10px;letter-spacing:.04em;white-space:nowrap;
             overflow:hidden;text-overflow:ellipsis}
  .extra-campo input{width:100%;min-width:0;font-size:13px;padding:6px 8px;
             font-variant-numeric:tabular-nums;text-align:right}
  /* El último es de lectura: se ve que lo calcula el sistema, no que está deshabilitado. */
  .extra-campo.es-ultimo input{background:var(--paper-2);color:var(--ink-2);font-weight:600;
             border-style:dashed;cursor:default}
  .extra-fecha{font-size:9.5px;color:var(--gris);text-align:right;
             font-variant-numeric:tabular-nums}
  .aviso-rojo{background:#fdecea;border:1px solid #f5b5ae;color:#8f2418;border-radius:8px;
         padding:10px 13px;font-size:13px;margin:8px 0 4px}
  /* Texto de apoyo debajo de un campo, para explicar la regla sin abrir un tooltip. */
  .ayuda-campo{font-size:11.5px;color:var(--gris);margin:4px 0 8px;line-height:1.45}

  /* ── SIMULACIÓN DE CRÉDITO (calcada de Banco Pichincha) ────────────────────
     Se copia su estructura a propósito: el cliente ya vio esa pantalla y la
     entiende, así no hay que explicar de nuevo qué es SOLCA o el desgravamen.
     Los colores son los suyos: amarillo el capital, azul marino el desgravamen,
     azul grisáceo el incendio. */
  .pich{margin-top:var(--space-lg);border:1px solid var(--border-2);border-radius:var(--radius-md);
        background:var(--paper);overflow:hidden;break-inside:avoid}
  .pich-tit{display:flex;align-items:center;justify-content:space-between;gap:var(--space-sm);
        padding:var(--space-sm) var(--space-md);background:var(--paper-2);
        border-bottom:1px solid var(--border-2);font-size:13px;font-weight:600;color:var(--ink)}
  .pich-ref{font-size:9.5px;letter-spacing:.1em;font-weight:700;color:#8a6412;
        background:#fdf3d8;border:1px solid #eedca0;border-radius:4px;padding:2px 7px}
  .pich-cuerpo{display:grid;grid-template-columns:minmax(0,250px) minmax(0,1fr);gap:var(--space-md);
        padding:var(--space-md);align-items:start}
  .pich-dona-col{display:flex;flex-direction:column;gap:var(--space-sm);align-items:center}
  /* Dona con conic-gradient: sin librerías (la CSP no deja cargar nada externo) y se
     imprime bien. --a y --b son los cortes acumulados en %. */
  .pich-dona{width:172px;height:172px;border-radius:50%;flex:none;
        background:conic-gradient(#F5D000 0 var(--a), #12305B var(--a) var(--b), #7C93B5 var(--b) 100%);
        display:flex;align-items:center;justify-content:center;
        -webkit-print-color-adjust:exact;print-color-adjust:exact}
  .pich-dona-centro{width:112px;height:112px;border-radius:50%;background:var(--paper);
        display:flex;flex-direction:column;align-items:center;justify-content:center;gap:2px;text-align:center}
  .pich-dona-centro span{font-size:10px;color:var(--ink-2);line-height:1.25}
  .pich-dona-centro b{font-size:19px;color:var(--ink);font-variant-numeric:tabular-nums}
  .pich-leyenda{list-style:none;margin:0;padding:0;width:100%;display:flex;flex-direction:column;gap:5px}
  .pich-leyenda li{display:flex;align-items:baseline;gap:7px;font-size:11.5px;color:var(--ink-2);
        flex-wrap:wrap;min-width:0}
  .pich-leyenda li b{margin-left:auto;color:var(--ink);font-variant-numeric:tabular-nums}
  .pich-leyenda i{width:9px;height:9px;border-radius:50%;flex:none;
        -webkit-print-color-adjust:exact;print-color-adjust:exact}
  .pich-leyenda .c1{background:#F5D000} .pich-leyenda .c2{background:#12305B} .pich-leyenda .c3{background:#7C93B5}
  .pich-cuota-final{display:flex;align-items:center;justify-content:space-between;gap:8px;width:100%;
        border-top:1px solid var(--border-2);padding-top:7px;font-size:12px;color:var(--ink-2)}
  .pich-cuota-final b{font-size:14px;color:var(--ink);font-variant-numeric:tabular-nums}
  .pich-datos{display:flex;flex-direction:column;gap:var(--space-sm);min-width:0}
  .pich-caja{background:var(--paper-2);border:1px solid var(--border-2);border-radius:var(--radius-sm);
        padding:var(--space-sm)}
  .pich-caja-tit{font-size:12px;font-weight:600;color:var(--ink);margin-bottom:5px}
  .pich-fila{display:flex;align-items:baseline;justify-content:space-between;gap:4px 10px;
        font-size:11.5px;color:var(--ink-2);padding:3px 0;flex-wrap:wrap;min-width:0}
  .pich-fila > span{min-width:0}
  .pich-fila b{color:var(--ink);font-variant-numeric:tabular-nums;white-space:nowrap}
  /* Las dos filas que llevan un <select> no caben en el patrón etiqueta↔valor: el
     select queda espachurrado contra la etiqueta y recorta el texto ("Francés · cuota
     fij…"). Se apilan: etiqueta arriba, control a lo ancho de la caja. */
  .pich-fila--sel{flex-direction:column;align-items:stretch;gap:3px}
  .pich-fila--sel > b{width:100%}
  .pich-fila select{font-size:11.5px;padding:4px 8px;width:100%;max-width:none;
        font-weight:600;color:var(--ink);background:var(--paper);
        border:1px solid var(--border-2);border-radius:var(--radius-sm)}
  .pich-suma{border-top:1px solid var(--border-2);margin-top:4px;padding-top:6px;font-weight:600}
  .pich-comp{width:100%;font-size:10.5px;line-height:1.5;color:var(--ink-2);
        background:var(--paper-2);border:1px solid var(--border-2);border-radius:var(--radius-sm);
        padding:7px 9px}
  .pich-nota{margin:0;padding:0 var(--space-md) var(--space-sm);font-size:10.5px;
        color:var(--gris);line-height:1.5}
  @media (max-width:960px){ .pich-cuerpo{grid-template-columns:minmax(0,1fr)} .pich-dona-col{max-width:320px;margin:0 auto} }
  .pie{font-size:11.5px;color:var(--gris);margin-top:16px;line-height:1.5}
  /* Margen de hoja en CERO para que el navegador NO dibuje su encabezado ni su pie.
     Ese pie es el que estampaba la URL completa del cotizador —con el token de firma
     dentro— en un documento que se le manda al cliente. El margen visual se devuelve
     por padding, que es nuestro y no arrastra chrome del navegador. */
  @page{ margin:0 }
  @media print{
    body{background:#fff;padding:14mm 12mm}
    /* .aviso son advertencias para el ASESOR — "Ojo, en el inventario esta E-4-23
       (RESERVADO)", "este proyecto no tiene fecha de entrega configurada". Utiles en
       pantalla, pero este documento se le entrega al cliente y ahi no van. */
    .barra,.ajustes,.tarjeta-opciones,.noimp,.aviso{display:none !important}
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

  <form class="ajustes" id="frm-ajustes" method="get">
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
      <input type="number" name="n" min="<?= !empty($modelo['inmediata']) ? 0 : 1 ?>" max="<?= (int)($plan['plazoMax'] ?? 120) ?>" value="<?= (int)$plan['cuotas'] ?>"
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
             <?= $repartoFijo ? 'disabled title="En este proyecto el reparto es fijo por contrato y no se negocia acá."' : '' ?>
             value="<?= $repartoFijo ? round($plan['financiarPct']) : ($vFinanciar !== '' ? h($vFinanciar) : round($plan['financiarPct'])) ?>"
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
      <!-- Los dos interruptores van en su PROPIA fila a lo ancho. NO recargan la página:
           mostrar/ocultar es cosa del navegador, y el residuo se recalcula en vivo. Antes
           hacían submit y la página saltaba arriba en cada clic. -->
      <div class="fila-chks">
        <label class="chk-linea" title="Parte la extraordinaria de cada año en dos pagos en vez de uno solo.">
          <input type="checkbox" name="extrapartes" value="2" id="chk-partes" <?= $extraPartes === 2 ? 'checked' : '' ?>
                 onchange="this.form.requestSubmit ? this.form.requestSubmit() : this.form.submit()">
          Partir en 2 pagos
        </label>
        <?php if ($modelo['maxExtra'] > 1): ?>
        <label class="chk-linea" title="Escribir cuánto va en cada pago. El último se calcula solo con lo que falte.">
          <input type="checkbox" name="extrapers" value="1" id="chk-pers" <?= $extraPers ? 'checked' : '' ?>
                 onchange="var c=document.getElementById('pers-caja'); if(c) c.style.display=this.checked?'':'none';">
          Personalizar montos
        </label>
        <?php endif; ?>
      </div>

      <?php if ($modelo['maxExtra'] > 1 && (int)$plan['nExtra'] > 1):
              // AGRUPADO POR AÑO: los pagos de un mismo año van juntos. Antes la rejilla
              // los ponía de a 3 y la primera fila mezclaba 2027, 2027 y 2028.
              $porAnioUI = [];
              foreach (($plan['posExtra'] ?? []) as $idx => $posFila) {
                  $fx = $plan['filas'][$posFila]['fecha'] ?? '';
                  $anio = $fx !== '' ? substr($fx, 6, 4) : '—';
                  $porAnioUI[$anio][] = ['k' => $idx + 1, 'fecha' => $fx];
              }
              $ultimoK = (int)$plan['nExtra'];
      ?>
      <div class="pers-caja" id="pers-caja" style="<?= $extraPers ? '' : 'display:none' ?>">
        <div class="ayuda-campo">Escribí los que quieras. El <b>último sale solo</b> con lo que falte para cuadrar los
          <b id="extra-total-txt"><?= '$' . number_format($plan['extraTotal'], 2) ?></b> de extraordinarias.</div>
        <?php foreach ($porAnioUI as $anio => $pagos): ?>
        <div class="anio-bloque">
          <div class="anio-tit"><?= $anio ?></div>
          <div class="extras-grid">
            <?php foreach ($pagos as $pg): $k = $pg['k']; $ultimo = ($k === $ultimoK);
                    $val = isset($plan['extraMontos'][$k-1]) ? number_format($plan['extraMontos'][$k-1], 2, '.', '') : ''; ?>
            <div class="extra-campo<?= $ultimo ? ' es-ultimo' : '' ?>">
              <?php if ($ultimo): ?>
              <label>Último · sale solo</label>
              <input type="text" id="extra-ultimo" value="<?= $val !== '' ? $val : number_format($plan['valorExtra'], 2, '.', '') ?>" readonly tabindex="-1"
                     title="Lo calcula el sistema con lo que falte. No se escribe: es lo que garantiza que el plan cuadre con el precio.">
              <?php else: ?>
              <label>Pago <?= $k ?></label>
              <input type="text" class="extra-monto" name="extramonto<?= $k ?>" inputmode="decimal"
                     placeholder="<?= number_format($plan['valorExtra'], 2, '.', '') ?>"
                     value="<?= ($extraPers && $val !== '' && (float)$val > 0) ? $val : '' ?>">
              <?php endif; ?>
              <span class="extra-fecha"><?= $pg['fecha'] ?></span>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endforeach; ?>
        <div class="aviso-rojo" id="extra-excedido" style="<?= !empty($plan['extraExcedido']) ? '' : 'display:none' ?>">
          Lo que escribiste se pasa por <b id="extra-excedido-monto"><?= '$' . number_format($plan['extraExcedido'] ?? 0, 2) ?></b>
          de los <?= '$' . number_format($plan['extraTotal'], 2) ?> disponibles. Bajá los montos o subí el <b>Financia %</b>.
        </div>
      </div>
      <script>
      // RESIDUO EN VIVO: el último pago se recalcula mientras el asesor escribe, sin
      // recargar. El servidor vuelve a hacer la misma cuenta al recalcular, así que esto
      // es solo para que vea el efecto al instante — la verdad la sigue teniendo el PHP.
      (function(){
        var TOTAL = <?= json_encode(round((float)$plan['extraTotal'], 2)) ?>;
        var campos = document.querySelectorAll('.extra-monto');
        var ultimo = document.getElementById('extra-ultimo');
        var aviso  = document.getElementById('extra-excedido');
        var montoEx= document.getElementById('extra-excedido-monto');
        function fmt(n){ return n.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}); }
        function recalc(){
          var suma = 0;
          campos.forEach(function(c){ var v = parseFloat(String(c.value).replace(/[^0-9.]/g,'')); if(!isNaN(v)) suma += v; });
          var resto = TOTAL - suma;
          if (ultimo) ultimo.value = fmt(Math.max(0, resto));
          if (aviso){
            if (resto < -0.005){ aviso.style.display=''; if(montoEx) montoEx.textContent = '$' + fmt(-resto); }
            else aviso.style.display='none';
          }
        }
        campos.forEach(function(c){ c.addEventListener('input', recalc); });
        recalc();
      })();
      </script>
      <?php endif; ?>

      <div class="grupo-campos">
        <div><label id="lbl-mes1"><?= $extraPartes === 2 ? 'Mes 1 de 2' : 'Mes de pago' ?></label>
          <select name="extrames1" onchange="this.form.requestSubmit ? this.form.requestSubmit() : this.form.submit()">
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
    <?php if ($suites >= 2 && !$separadas): ?>
    <!-- Solo aparece con 2+ suites de Noral Plaza: es el único caso donde la regla existe. -->
    <div class="col2" style="align-self:center">
      <label class="chk-linea">
        <!-- Va un hidden en 0 delante: un checkbox desmarcado NO se envia, asi que sin
             esto el formulario no tendria como decir "apagalo" y el descuento seria
             imposible de quitar. -->
        <input type="hidden" name="sinparq" value="0">
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
  <?php // El aviso solo tiene sentido si HAY cuotas mensuales que puedan pasarse de la
        // entrega. En entrega inmediata (Torre C: 30% + 70% con el banco) no hay ninguna,
        // así que salía un "verifica que las 0 cuotas terminen antes de la entrega" que
        // no dice nada y solo confunde al asesor.
        if (!$entrega && (int)$plan['cuotas'] > 0): ?>
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
      Opción <?= $bi + 1 ?> · <?= h(codigos_comprimidos($B['cods'])) ?></h2>
  <?php endif; ?>

  <dl class="datos">
    <dt>Proyecto</dt><dd><?= h($proyecto) ?></dd>
    <dt><?= count($B['cods']) > 1 ? 'Unidades' : 'Unidad' ?></dt>
    <?php /* Codigo comprimido, la misma regla que usa el titulo del deal: dos unidades
             fusionadas son "E-4-23-24" y no "E-4-23 + E-4-24" — es UNA compra y el
             cliente la tiene que leer como una. Se quito tambien el rotulo de cuantos
             activos son: es vocabulario interno del CRM.
             Va como comentario PHP y no HTML: este documento se le abre al cliente y
             los comentarios HTML viajan a su navegador. */ ?>
    <dd><?= h(codigos_comprimidos($B['cods'])) ?></dd>
    <?php if ($B['m2'] > 0): ?><dt>Metros<?= count($B['cods']) > 1 ? ' (suma)' : '' ?></dt><dd><?= number_format($B['m2'], 2) ?> m²</dd><?php endif; ?>
  </dl>

  <?php /* El desglose "Suma de las N unidades / Una unidad sin parqueo" ya no se
           imprime. El descuento SIGUE aplicandose —esta dentro de $plan['valor']— pero
           el documento del cliente muestra el precio final y nada mas: el detalle de
           como se llego a el es informacion interna. Si hace falta auditarlo, esta en
           $B['bruto'] y $B['dcto'], y el log del handler lo registra. */ ?>
  <?php if ($separadas && $suites >= 2): ?>
    <?php /* Se dice explicito: si no, el asesor ve dos suites sin descuento y cree
             que la pantalla se equivoco. La clase `aviso` no se imprime. */ ?>
    <div class="aviso">Las unidades están marcadas como <b>separadas</b>, así que
      cada una lleva su parqueo y <b>no se descuentan los
      <?= h(cot_money((float)COT_PARQUEO)) ?></b>. Para que aplique, hay que marcarlas
      como fusión en el campo Inventario del negocio.</div>
  <?php endif; ?>
  <div class="precio"><span>Precio final</span><span><?= h(cot_money($plan['valor'])) ?></span></div>
  <div class="legal"><span>Valores legales promesa C/V</span><span><?= h(cot_money((float)$plan['legal'])) ?></span></div>
  <p>Pago directo para el notario. Se da al momento de la firma del contrato.</p>

  <div class="resumen">
    <div><span>Reserva <?= h($pc($plan['reservaPct'])) ?></span><b><?= h(cot_money($plan['reserva'])) ?></b></div>
    <?php /* El credito directo va ANTES de la contraentrega porque ese es el orden en
             que se paga, y es la cifra que el cliente compara contra lo que le pide el
             banco. Se resalta: de los cinco cuadros, es el que define si la compra le
             cierra o no. El mismo numero que la fila TOTAL CUOTA INICIAL de la tabla. */ ?>
    <div class="destacado"><span>Crédito directo <?= h($pc($plan['financiarPct'] / 100)) ?></span><b><?= h(cot_money($plan['totalInicial'])) ?></b></div>
    <div><span>Contraentrega <?= h($pc($plan['contraPct'])) ?></span><b><?= h(cot_money($plan['contraentrega'])) ?></b></div>
    <?php /* Las cuotas van DENTRO del cuadro de la mensual, no en uno propio: con
             cinco cuadros el quinto caia solo en una segunda fila y se veia mal. Y
             ademas se lee mejor junto — "396,43 al mes, 56 veces" es una sola idea. */ ?>
    <div><span>Cuota mensual</span><b><?= h(cot_money($plan['mensual'])) ?><small>/ <?= (int)$plan['cuotas'] ?> cuotas</small></b></div>
  </div>

  <table>
    <thead><tr><th style="width:64px">N°</th><th>Vencimiento</th><th>Valor cuota</th></tr></thead>
    <tbody>
      <tr class="hito"><td></td><td>SEPARACIÓN</td><td><?= h(cot_money($plan['separacion'])) ?></td></tr>
      <?php if ($plan['firma'] > 0): ?>
      <tr class="hito"><td></td><td>A LA FIRMA</td><td><?= h(cot_money($plan['firma'])) ?></td></tr>
      <?php endif; ?>
      <?php /* La etiqueta FIRMA va SOLO en la primera cuota diferida. Repetirla en
               todas está mal legalmente: da a entender que hay varias firmas del
               contrato, y firma hay una. En las siguientes se entiende por el monto. */
            $primerDiferido = true; ?>
      <?php foreach ($plan['filas'] as $f): ?>
      <tr class="<?= $f['extra'] ? 'extra' : ($f['diferido'] ? 'diferido' : '') ?>">
        <td><?= (int)$f['n'] ?></td>
        <td><?= h($f['fecha']) ?><?= $f['extra'] ? '<span class="etq">EXTRA</span>' : '' ?><?php
            if (!empty($f['diferido']) && $primerDiferido) { echo '<span class="etq2">FIRMA</span>'; $primerDiferido = false; }
            /* La cuota que absorbe el redondeo va marcada: sin esto, el cliente ve una
               cuota distinta a las demas y parece un error. Con el rotulo se entiende
               que es el ajuste que hace cuadrar la suma al centavo. */
            if (!empty($f['ajuste'])) echo '<span class="etq3">AJUSTE</span>'; ?></td>
        <td><?= h(cot_money($f['monto'])) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php /* El total que el cliente le paga a la EMPRESA — el crédito directo.
               Va arriba de la contraentrega, igual que en la tabla de pagos que usa
               el equipo, para que se lea "esto a la empresa, esto al banco". */ ?>
      <tr class="hito total-ini"><td></td><td>TOTAL CUOTA INICIAL<?php
        if ($plan['valor'] > 0): ?> <span class="pct"><?= number_format($plan['totalInicial'] / $plan['valor'] * 100, 1) ?>%</span><?php endif; ?></td>
        <td><?= h(cot_money($plan['totalInicial'])) ?></td></tr>
      <tr class="hito"><td></td><td>CONTRAENTREGA</td><td><?= h(cot_money($plan['contraentrega'])) ?></td></tr>
      <tr class="hito gran-total"><td></td><td>TOTAL</td><td><?= h(cot_money($plan['totalInicial'] + $plan['contraentrega'])) ?></td></tr>
    </tbody>
  </table>

  <?php if (!empty($modelo['banco']) && $plan['contraentrega'] > 0):
          $sim = pich_simular($pvpFinal, $plan['contraentrega'], $pichAnios, null, $pichSis);
          $simOtro = pich_simular($pvpFinal, $plan['contraentrega'], $pichAnios, null, $pichSis === 'aleman' ? 'frances' : 'aleman'); ?>
  <!-- SIMULACIÓN DE CRÉDITO — se calca la pantalla de Banco Pichincha a propósito:
       el cliente ya la vio y la entiende, y copiar su estructura evita explicar de
       nuevo qué es cada rubro. La dona es un conic-gradient (sin librerías: la CSP no
       deja cargar nada externo y así también sale bien impresa). -->
  <div class="pich">
    <div class="pich-tit">
      <span>Crédito hipotecario para la contraentrega</span>
      <span class="pich-ref">REFERENCIAL</span>
    </div>

    <div class="pich-cuerpo">
      <div class="pich-dona-col">
        <div class="pich-dona" style="--a:<?= round($sim['pctCapInt'],2) ?>%; --b:<?= round($sim['pctCapInt']+$sim['pctDesgrav'],2) ?>%">
          <div class="pich-dona-centro">
            <span><?= $pichSis === 'aleman' ? 'Primera cuota<br>aproximada' : 'Cuota mensual<br>aproximada' ?></span>
            <b><?= h(cot_money($sim['cuota'])) ?></b>
          </div>
        </div>
        <ul class="pich-leyenda">
          <li><i class="c1"></i>Capital + interés<b><?= h(cot_money($sim['capInt'])) ?></b></li>
          <li><i class="c2"></i>Seguro de Desgravamen<b><?= h(cot_money($sim['desgrav'])) ?></b></li>
          <li><i class="c3"></i>Seguro contra incendio y terremoto<b><?= h(cot_money($sim['incendio'])) ?></b></li>
        </ul>
        <?php if ($pichSis === 'aleman'): ?>
        <div class="pich-cuota-final"><span>Última cuota (va bajando)</span><b><?= h(cot_money($sim['cuotaUltima'])) ?></b></div>
        <?php else: ?>
        <div class="pich-cuota-final"><span>Cuota mensual aproximada</span><b><?= h(cot_money($sim['cuota'])) ?></b></div>
        <?php endif; ?>
        <!-- Comparativa: la decisión real no es "cuál es mejor" sino si el cliente
             CALIFICA con la primera cuota, que es por donde lo mide el banco. -->
        <div class="pich-comp">
          <?php $dif = $simOtro['cuotaPrimera'] - $sim['cuotaPrimera']; $difInt = $simOtro['totalInteres'] - $sim['totalInteres']; ?>
          Con <b><?= $pichSis === 'aleman' ? 'Francés' : 'Alemán' ?></b> la primera cuota sería
          <b><?= h(cot_money($simOtro['cuotaPrimera'])) ?></b>
          (<?= $dif >= 0 ? '+' : '−' ?><?= h(cot_money(abs($dif))) ?>)
          y los intereses totales <b><?= h(cot_money($simOtro['totalInteres'])) ?></b>
          (<?= $difInt >= 0 ? '+' : '−' ?><?= h(cot_money(abs($difInt))) ?>).
        </div>
      </div>

      <div class="pich-datos">
        <div class="pich-caja">
          <div class="pich-caja-tit">Lo que calculaste</div>
          <div class="pich-fila"><span>Precio de la vivienda</span><b><?= h(cot_money($sim['vivienda'])) ?></b></div>
          <div class="pich-fila"><span>Monto solicitado</span><b><?= h(cot_money($sim['prestamo'])) ?></b></div>
          <div class="pich-fila pich-fila--sel"><span>Plazo de pago</span><b>
            <select name="pichanios" form="frm-ajustes" onchange="this.form.requestSubmit ? this.form.requestSubmit() : this.form.submit()">
              <?php for ($y = (int)$pp['anios_min']; $y <= (int)$pp['anios_max']; $y++): ?>
              <option value="<?= $y ?>" <?= $pichAnios === $y ? 'selected' : '' ?>><?= $y ?> años</option>
              <?php endfor; ?>
            </select></b></div>
          <div class="pich-fila pich-fila--sel"><span>Amortización</span><b>
            <select name="pichsis" form="frm-ajustes" onchange="this.form.requestSubmit ? this.form.requestSubmit() : this.form.submit()">
              <option value="frances" <?= $pichSis === 'frances' ? 'selected' : '' ?>>Francés — cuota fija</option>
              <option value="aleman"  <?= $pichSis === 'aleman'  ? 'selected' : '' ?>>Alemán — cuota decreciente</option>
            </select></b></div>
          <div class="pich-fila"><span>Producto elegido</span><b>Vivienda nueva o usada</b></div>
          <div class="pich-fila"><span>Tasa de interés</span><b><?= number_format($sim['tasa'], 2) ?>%</b></div>
        </div>

        <div class="pich-caja">
          <div class="pich-caja-tit">Detalles de tu crédito</div>
          <div class="pich-fila"><span>Monto para tu vivienda</span><b><?= h(cot_money($sim['prestamo'])) ?></b></div>
          <div class="pich-fila"><span>Gastos de avalúo</span><b>+ <?= h(cot_money($sim['avaluo'])) ?></b></div>
          <div class="pich-fila"><span>Gastos legales</span><b>+ <?= h(cot_money($sim['legales'])) ?></b></div>
          <div class="pich-fila"><span>Contribución SOLCA</span><b>+ <?= h(cot_money($sim['solca'])) ?></b></div>
          <div class="pich-fila pich-suma"><span>Monto total a financiar</span><b>= <?= h(cot_money($sim['total'])) ?></b></div>
        </div>

        <div class="pich-caja">
          <div class="pich-caja-tit">Valores totales referenciales</div>
          <div class="pich-fila"><span>Monto total a financiar</span><b><?= h(cot_money($sim['total'])) ?></b></div>
          <div class="pich-fila"><span>Total intereses</span><b><?= h(cot_money($sim['totalInteres'])) ?></b></div>
          <div class="pich-fila"><span>Total seguros</span><b><?= h(cot_money($sim['totalSeguros'])) ?></b></div>
        </div>
      </div>
    </div>

    <p class="pich-nota">
      Estimación referencial calcada de la calculadora de Banco Pichincha. No es una
      preaprobación ni un otorgamiento del crédito. Los gastos de avalúo y legales
      salen de una tabla del banco y pueden variar; confirmalos con tu asesor de
      crédito. Los seguros son obligatorios por normativa.
      El cliente puede financiar con otro banco: acá se usa Pichincha por ser el aliado.
    </p>
  </div>
  <?php endif; ?>

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
    <?php /* Si una cuota absorbe el redondeo, se dice. Antes el papel imprimia todas
             iguales y al sumarlo a mano daba centavos de mas — nos devolvieron una
             tabla marcada por 24 centavos. */
          $filaAjuste = null;
          foreach ($plan['filas'] as $fa) if (!empty($fa['ajuste'])) { $filaAjuste = $fa; break; }
          if ($filaAjuste): ?>
      La cuota <?= (int)$filaAjuste['n'] ?> es de <?= h(cot_money($filaAjuste['monto'])) ?>
      para que la suma cierre exacta con el precio.
    <?php endif; ?>
    El total que se paga a la empresa antes de la entrega es
    <b><?= h(cot_money($plan['totalInicial'])) ?></b>.
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

<script>
// MANTENER EL LUGAR AL RECALCULAR. "Partir en 2 pagos" cambia CUÁNTOS pagos hay, así
// que sí o sí lo recalcula el servidor; lo que no tiene por qué pasar es que la página
// se vaya arriba y el asesor pierda de vista lo que estaba tocando.
// Se guardan los DOS scrolls: el de la ventana y el del panel de ajustes, que scrollea
// por dentro (.ajustes es sticky con overflow-y:auto).
(function(){
  var LLAVE = 'cot_scroll';
  var panel = document.querySelector('.ajustes');
  function guardar(){
    try{ sessionStorage.setItem(LLAVE, JSON.stringify({
      w: window.scrollY || 0,
      p: panel ? panel.scrollTop : 0
    })); }catch(e){}
  }
  // Cualquier envío del formulario (checkbox, select o el botón Recalcular).
  document.addEventListener('submit', guardar, true);
  var f = document.querySelector('form.ajustes');
  if (f) f.addEventListener('submit', guardar);

  try{
    var s = JSON.parse(sessionStorage.getItem(LLAVE) || 'null');
    if (s){
      sessionStorage.removeItem(LLAVE);
      // En dos tiempos: el layout todavía se está armando en el primer frame.
      var poner = function(){
        if (s.w) window.scrollTo(0, s.w);
        if (panel && s.p) panel.scrollTop = s.p;
      };
      poner();
      requestAnimationFrame(poner);
      setTimeout(poner, 60);
    }
  }catch(e){}
})();
</script>
</body></html>
