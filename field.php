<?php
/**
 * field.php — handler del tipo de campo propio "Inventario" (galjosa_unidad).
 * ---------------------------------------------------------------------------
 * Reemplaza a los 4 campos anteriores (Inventario nativo + Inventario 2/3/4):
 * un solo campo que admite VARIAS unidades (fusión), con filtro por proyecto y
 * las unidades ocupadas bloqueadas.
 *
 * Cómo lo dibuja Bitrix: el editor nuevo de CRM mete este HTML en un iframe
 * (app-frame) de 200px fijos. Por eso el JS pide BX24.resizeWindow para dejarlo
 * en una línea cuando está cerrado y expandirlo al abrir.
 *
 * Valor guardado: los IDs de las unidades separados por coma ("581,623").
 * El campo es MULTIPLE=N a propósito: manejar la multiselección por dentro da
 * una interfaz mucho mejor que la que arma Bitrix repitiendo el handler.
 * Quien convierte ese valor en dependencia real (parentId2 en la unidad +
 * relación nativa) y aplica RESERVADO/FIRMADO/VENDIDO es hook.php.
 *
 * Modos que envía Bitrix en `mode`:
 *   view      -> cómo se ve el valor guardado
 *   edit      -> el selector
 *   settings  -> ajustes del campo (no se usa ninguno)
 *
 * El catálogo se lee del caché que arma selector.php (1.274 unidades, con
 * estado y quién las ocupa), sin pegarle al API en cada render.
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

$DATA_DIR = getenv('DATA_DIR') ?: '/data';

// Diagnóstico: qué nos manda Bitrix. Necesitamos el ID del deal para poder
// guardar por API, porque el <input> vive dentro del iframe y el formulario del
// deal NO lo envía al guardar (por eso la selección no se asentaba).
//
// OJO: se escribe en web.log, NO en sync.log. sync.log lo crea el cron como root
// y Apache (www-data) no puede añadirle líneas: por eso los logs del lado web
// se perdían en silencio.
@file_put_contents($DATA_DIR . '/web.log',
    gmdate('Y-m-d\TH:i:s\Z') . '  FIELD claves=[' . implode(',', array_keys($_REQUEST)) . ']'
    . ' mode=' . (string)($_REQUEST['mode'] ?? '-')
    . ' value=' . substr((string)($_REQUEST['value'] ?? ''), 0, 40)
    . ' field_keys=[' . (is_array($_REQUEST['field'] ?? null) ? implode(',', array_keys($_REQUEST['field'])) : '-') . ']'
    . ' PLACEMENT=' . (string)($_REQUEST['PLACEMENT'] ?? '-')
    // 2000 y no 400: se está buscando qué distingue el render del modal "Complete
    // los campos obligatorios" del de abrir el deal. Con 400 se cortaba justo
    // después del URI, así que si Bitrix manda algo más ahí, nunca se vio.
    . ' OPTIONS=' . substr((string)($_REQUEST['PLACEMENT_OPTIONS'] ?? '-'), 0, 2000)
    . "\n", FILE_APPEND | LOCK_EX);

// Bitrix manda todo en PLACEMENT_OPTIONS (verificado en el log):
//   MODE, ENTITY_ID, FIELD_NAME, ENTITY_VALUE_ID (= id del deal), VALUE, ...
// El `mode`/`value` sueltos solo existen cuando se llama a mano para pruebas.
$opciones = [];
if (!empty($_REQUEST['PLACEMENT_OPTIONS'])) {
    $tmp = json_decode((string)$_REQUEST['PLACEMENT_OPTIONS'], true);
    if (is_array($tmp)) $opciones = $tmp;
}

/** Base firmada de la cotización, para este deal y por 12 horas.
 *  La firma (HMAC con el secreto del servicio) evita poner el token en la URL,
 *  que quedaría a la vista de cualquiera con quien se comparta el enlace.
 *  Cubre el deal y el vencimiento, NO la lista de unidades: los activos
 *  fusionados se arman eligiendo en el desplegable, y esa selección cambia sin
 *  recargar la página, así que el servidor no puede firmar cada combinación.
 *  Lo que queda expuesto a cambio es el precio de otra unidad del catálogo, que
 *  el mismo asesor ya está viendo en la lista. */
function cot_base(int $dealId): array {
    $exp = time() + 12 * 3600;                    // dura la jornada del asesor
    $sig = hash_hmac('sha256', "d{$dealId}|e{$exp}", (string)getenv('OUTBOUND_TOKEN'));
    $host = (string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? '');
    return ['url' => 'https://' . $host . '/cotizar.php', 'd' => $dealId, 'exp' => $exp, 's' => $sig];
}

$mode   = (string)($opciones['MODE'] ?? $_REQUEST['mode'] ?? 'edit');
$dealId = (int)($opciones['ENTITY_VALUE_ID'] ?? 0);
$name   = (string)($opciones['FIELD_NAME'] ?? ($_REQUEST['field']['NAME'] ?? $_REQUEST['name'] ?? 'UF_UNIDAD'));

$value = (string)($opciones['VALUE'] ?? $_REQUEST['value'] ?? '');
if ($value === 'null') $value = '';

// El campo es OBLIGATORIO para entrar a RESERVA, así que Bitrix lo pide ANTES de
// mover el deal. Aquí hubo dos intentos de detectar ese momento para abrirle el
// candado: la URI del kanban (solo cubre arrastrar en el tablero) y el ancho del
// iframe (<560px, que resultó ser también el de la ficha normal, o sea el candado
// quedaba abierto siempre). Ninguno servía, porque desde el servidor ese render es
// idéntico al de abrir el deal: MANDATORY llega "N" y MODE "edit" también sale de
// /details/, comprobado en el log real.
//
// Ya no hace falta detectarlo. La regla la garantiza apartar_prospecto() en
// campolib.php: elegir la unidad se puede en cualquier etapa (así el obligatorio
// funciona desde el deal y desde el kanban) y apartarla solo pasa en RESERVA.

// Guardar NO puede depender del formulario del deal: el campo vive en un iframe
// y su <input> nunca viaja en el submit. Se guarda por API desde el navegador,
// con el token que Bitrix entrega en cada render (AUTH_ID).
$authId  = (string)($_REQUEST['AUTH_ID'] ?? '');
$dominio = (string)($_REQUEST['DOMAIN'] ?? '');

/** IDs seleccionados a partir del valor guardado ("581,623"). */
function ids_de(string $v): array {
    $out = [];
    foreach (preg_split('/[,;\s]+/', $v) as $x) {
        $x = trim($x);
        if ($x !== '' && ctype_digit($x) && (int)$x > 0) $out[] = (int)$x;
    }
    return array_values(array_unique($out));
}

/**
 * Unidades desde el caché de selector.php (no vuelve a pegarle al API).
 *
 * Si el caché está vencido se pide un refresco POR DETRÁS y se sigue dibujando
 * con lo que hay: reconstruirlo en primer plano tarda ~40s y el vendedor no va a
 * esperar. Hace falta porque el campo leía el archivo sin mirar su edad, y como
 * nadie abre ya la página selector.php el catálogo se quedaba horas viejo — una
 * unidad nueva del SPA no llegaba a aparecer nunca en la lista.
 */
function catalogo_cache(): array {
    $path = (getenv('DATA_DIR') ?: '/data') . '/selector_cache.json';
    $j = json_decode((string)@file_get_contents($path), true);

    $edad = is_file($path) ? (time() - (int)@filemtime($path)) : PHP_INT_MAX;
    if ($edad > 900) {                       // mismo TTL que usa selector.php
        $tok = (string)getenv('OUTBOUND_TOKEN');
        if ($tok !== '') {
            $ch = curl_init('http://127.0.0.1/selector.php?warm=1&token=' . urlencode($tok));
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT_MS     => 300,   // se corta enseguida; el rebuild sigue del otro lado
                CURLOPT_NOSIGNAL       => true,
            ]);
            curl_exec($ch);   // no se cierra: curl_close está deprecado desde PHP 8.5 y no hace nada
        }
    }
    return is_array($j) ? $j : ['units' => [], 'proyectos' => []];
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

/**
 * Etapa RESERVA del 28, leída del caché que deja stagelib.php (cero llamadas).
 * Solo alimenta el candado VISUAL: el que decide de verdad es guardar.php.
 */
function reserva28_cache(): string {
    $p = (getenv('DATA_DIR') ?: '/data') . '/reserva28.txt';
    $c = trim((string)@file_get_contents($p));
    return $c !== '' ? $c : 'C28:WON';
}

$cat   = catalogo_cache();
$units = $cat['units'] ?? [];
$proys = $cat['proyectos'] ?? [];

// índice por id para resolver rápido lo ya seleccionado
$porId = [];
foreach ($units as $u) $porId[(string)$u['id']] = $u;

$elegidos = ids_de($value);

// ---- settings: el campo no necesita configuración ---------------------------
if ($mode === 'settings') { echo ''; exit; }

// ---- view -------------------------------------------------------------------
// A propósito NO se usa una vista aparte de solo lectura: obligaba a pulsar
// "editar" para cambiar la unidad, que es incómodo. Como el guardado va por API
// (no por el formulario), se puede mostrar siempre el selector y listo.
// Se conserva el render de solo lectura para cuando Bitrix lo pide fuera del
// formulario (por ejemplo en listados), donde no hay dónde editar.
if ($mode === 'view' && !empty($_REQUEST['solo_lectura'])) {
    $partes = [];
    foreach ($elegidos as $id) {
        $u = $porId[(string)$id] ?? null;
        if ($u) {
            $proy = $proys[(string)$u['cat']] ?? '';
            $partes[] = '<span style="white-space:nowrap">' . h($u['codigo'])
                      . ' <span style="color:#8b949e">(' . h($proy) . ')</span></span>';
        } else {
            $partes[] = '<span>#' . h((string)$id) . '</span>';
        }
    }
    $texto = $partes
        ? implode('<span style="color:#d0d7de">&nbsp;·&nbsp;</span>', $partes)
        : '<span style="color:#8b949e">—</span>';

    // El modo lectura TAMBIÉN va dentro del iframe de 200px. Si no se le pide a
    // Bitrix encogerlo, el campo deja un bloque enorme vacío en todos los deals.
    ?>
    <script src="//api.bitrix24.com/api/v1/"></script>
    <style>
      /* alto del documento chico a propósito: si Bitrix mide el contenido para
         dimensionar el iframe, así lo encoge; si no, al menos no queda hueco. */
      html,body{margin:0;padding:0;background:transparent;overflow:hidden;height:auto;max-height:26px}
      #guv{font:13.5px/1.4 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
           color:#1f2328;height:26px;display:flex;align-items:center;padding:0 7px;gap:4px;
           white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    </style>
    <div id="guv"><?= $texto ?></div>
    <script>
      (function(){
        function ajustar(){
          var el = document.getElementById('guv');
          var alto  = Math.max(24, el ? el.scrollHeight : 24);
          var ancho = Math.max(200, document.documentElement.scrollWidth || 0);
          try {
            if (typeof BX24 !== 'undefined') {
              if (BX24.resizeWindow) BX24.resizeWindow(ancho, alto);
              if (BX24.fitWindow)    BX24.fitWindow();   // respaldo: ajusta al contenido
            }
          } catch(e) {}
        }
        // El handshake de BX24 puede tardar; se reintenta unas cuantas veces.
        if (typeof BX24 !== 'undefined') { try { BX24.init(ajustar); } catch(e) { ajustar(); } }
        window.addEventListener('load', ajustar);
        [150, 400, 900, 1800].forEach(function(ms){ setTimeout(ajustar, ms); });
      })();
    </script>
    <?php
    exit;
}

// ---- edit: el selector ------------------------------------------------------
$porProyecto = [];
foreach ($units as $u) $porProyecto[(string)$u['cat']][] = $u;
foreach ($porProyecto as &$l) usort($l, fn($a, $b) => strnatcasecmp($a['codigo'], $b['codigo']));
unset($l);

$uid = 'gu' . bin2hex(random_bytes(4));   // ids únicos: puede haber varios campos en el form
?>
<script src="//api.bitrix24.com/api/v1/"></script>
<div class="gu" id="<?= $uid ?>">
<style>
  /* Bitrix dimensiona este iframe según el ALTO DEL DOCUMENTO que devolvemos.
     Por eso el alto debe ser natural: con height:100% + overflow:hidden el
     documento nunca crecía y el panel abierto quedaba recortado. */
  html,body{margin:0;padding:0;background:transparent;height:auto;overflow:visible}
  #<?= $uid ?>{font:13.5px/1.4 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
      color:#1f2328}
  #<?= $uid ?> *{box-sizing:border-box}

  /* línea cerrada: chips de lo elegido + "agregar".
     El padding y el min-height igualan la altura/sangría de los campos nativos
     del deal, para que el texto quede centrado y no pegado al borde. */
  /* padding simétrico arriba/abajo: así el texto queda centrado y no "un poco arriba" */
  /* sin border-bottom: la caja redondeada ya la dibuja Bitrix y esa línea
     cruzaba por encima de las esquinas curvas */
  #<?= $uid ?> .gu-campo{display:flex;align-items:center;flex-wrap:wrap;gap:6px;min-height:26px;
      cursor:pointer;padding:2px 7px}
  #<?= $uid ?> .gu-ph{color:#a8adb4;display:inline-flex;align-items:center;height:20px}
  /* Candado de etapa. Antes cerraba el campo entero; ahora deja CONSULTAR y solo
     impide elegir: el vendedor necesita ver qué hay libre y a qué precio en
     cualquier etapa de Prospectos, aunque todavía no pueda apartar. */
  #<?= $uid ?>.gu-bloq .gu-fila{cursor:default}
  #<?= $uid ?>.gu-bloq .gu-fila:hover{background:transparent}
  #<?= $uid ?>.gu-bloq .gu-quita{display:none}
  /* El candado vuelve a ser UNA sola línea de texto, como estaba al principio.
     La nota queda en el DOM sin usarse: el estado se dice en el propio placeholder
     y meter un segundo texto al lado ensuciaba el campo. */
  #<?= $uid ?> .gu-nota{display:none}
  /* cerrado: texto plano, igual que los campos nativos del deal (sin chips ni ✕) */
  #<?= $uid ?> .gu-txt{display:inline-flex;align-items:center;height:20px;overflow:hidden;
      white-space:nowrap;text-overflow:ellipsis}
  #<?= $uid ?> .gu-sep{color:#d0d7de;padding:0 5px}
  #<?= $uid ?> .gu-proyname{color:#8b949e}
  /* el código abre la ficha de la unidad, como hacían los campos nativos */
  #<?= $uid ?> .gu-ir{color:#0969da;cursor:pointer}
  #<?= $uid ?> .gu-ir:hover{text-decoration:underline}
  /* el nombre completo de la unidad es el enlace: código Y proyecto en azul */
  #<?= $uid ?> .gu-ir .gu-proyname{color:inherit}

  /* dentro del desplegable: lo elegido, en azul y con la ✕ */
  #<?= $uid ?> .gu-elegidas{border-bottom:1px solid #eaeef2;background:#f6faff}
  #<?= $uid ?> .gu-eltit{padding:6px 10px 2px;font-size:10.5px;font-weight:700;color:#0969da;
      letter-spacing:.05em;text-transform:uppercase}
  #<?= $uid ?> .gu-el{display:flex;align-items:center;gap:8px;padding:6px 10px;color:#0a3069}
  #<?= $uid ?> .gu-el .gu-elcod{font-weight:600;min-width:58px}
  #<?= $uid ?> .gu-el .gu-elproy{color:#57606a;font-size:11.5px;flex:1 1 auto;overflow:hidden;
      white-space:nowrap;text-overflow:ellipsis}
  #<?= $uid ?> .gu-el b{display:inline-flex;align-items:center;justify-content:center;
      width:18px;height:18px;border-radius:50%;cursor:pointer;color:#57606a;
      font-weight:700;font-size:13px;line-height:1;flex:0 0 auto}
  #<?= $uid ?> .gu-el b:hover{color:#cf222e;background:#ffe3e3}
  /* flecha: pegada a la derecha y a la misma altura que el texto */
  #<?= $uid ?> .gu-caret{margin-left:auto;display:inline-flex;align-items:center;justify-content:center;
      height:20px;color:#a8adb4;font-size:9px;padding-left:6px}

  #<?= $uid ?> .gu-panel{display:none;margin-top:5px;border:1px solid #d0d7de;border-radius:8px;
      background:#fff;box-shadow:0 6px 18px rgba(27,31,36,.12);position:relative}
  #<?= $uid ?>.abierto .gu-panel{display:block}

  /* ---------------------------------------------------------------------------
     Con el panel ABIERTO, lo único que se desplaza es la lista de unidades.
     Antes el panel medía más que el iframe (Bitrix no da más de ~340px) y el que
     scrolleaba era el documento entero: la barra de Proyecto/Código y el pie con
     "Listo" se salían de la vista y había que arrastrar toda la pestaña.
     Ahora el documento se fija al alto del iframe y el panel es una columna
     flex: barra y pie quedan clavados, la lista se queda con el resto.
     El `min-height:0` NO es de adorno: sin él un hijo flex no encoge y la lista
     volvería a empujar el panel hacia abajo.
     Solo aplica con `.gu-open`; cerrado el documento conserva su alto natural
     (si no, el campo cerrado deja un cuadro vacío enorme).
     --------------------------------------------------------------------------- */
  html.gu-open, body.gu-open{height:100%;overflow:hidden}
  #<?= $uid ?>.abierto{display:flex;flex-direction:column;height:100%;min-height:0}
  #<?= $uid ?>.abierto .gu-campo{flex:0 0 auto}
  #<?= $uid ?>.abierto .gu-panel{display:flex;flex-direction:column;flex:1 1 auto;min-height:0;
      overflow:hidden}
  #<?= $uid ?>.abierto .gu-top,
  #<?= $uid ?>.abierto .gu-elegidas,
  #<?= $uid ?>.abierto .gu-pie{flex:0 0 auto}
  #<?= $uid ?>.abierto .gu-lista{flex:1 1 auto;min-height:64px;max-height:none}

  /* orden: 1) proyecto  2) buscar unidad  3) disponibles/todos */
  #<?= $uid ?> .gu-top{display:flex;gap:6px;align-items:center;padding:7px;border-bottom:1px solid #eaeef2}
  #<?= $uid ?> .gu-drop{position:relative;flex:0 0 auto}
  #<?= $uid ?> .gu-dropbtn{display:flex;align-items:center;gap:6px;max-width:150px;
      border:1px solid #d0d7de;border-radius:6px;background:#fff;color:#1f2328;font:inherit;font-size:12px;
      padding:5px 8px;cursor:pointer}
  #<?= $uid ?> .gu-dropbtn:hover{border-color:#0969da}
  #<?= $uid ?> .gu-dropbtn span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  #<?= $uid ?> .gu-dropbtn i{font-style:normal;color:#8b949e;font-size:8px;margin-left:auto}
  #<?= $uid ?> .gu-menu{display:none;position:absolute;z-index:5;top:calc(100% + 4px);left:0;min-width:186px;
      background:#fff;border:1px solid #d0d7de;border-radius:8px;box-shadow:0 8px 22px rgba(27,31,36,.18);
      padding:4px;max-height:190px;overflow-y:auto}
  #<?= $uid ?> .gu-menu.on{display:block}
  #<?= $uid ?> .gu-menu button{display:flex;align-items:center;gap:7px;width:100%;border:0;background:transparent;
      font:inherit;font-size:12.5px;color:#1f2328;text-align:left;padding:6px 8px;border-radius:5px;cursor:pointer}
  #<?= $uid ?> .gu-menu button:hover{background:#f0f6ff}
  #<?= $uid ?> .gu-menu button[aria-pressed=true]{background:#ddf4ff;font-weight:600}
  #<?= $uid ?> .gu-menu .n{margin-left:auto;color:#8b949e;font-size:11px;font-weight:400}
  #<?= $uid ?> .gu-buscar{flex:1 1 auto;min-width:80px;padding:5px 8px;border:1px solid #d0d7de;
      border-radius:6px;font:inherit;font-size:12.5px}
  #<?= $uid ?> .gu-buscar:focus{outline:2px solid #0969da;outline-offset:-1px}
  #<?= $uid ?> .gu-seg{display:flex;flex:0 0 auto;border:1px solid #d0d7de;border-radius:6px;overflow:hidden}
  #<?= $uid ?> .gu-seg button{border:0;background:#fff;color:#57606a;font:inherit;font-size:12px;
      padding:5px 9px;cursor:pointer;white-space:nowrap}
  #<?= $uid ?> .gu-seg button + button{border-left:1px solid #d0d7de}
  #<?= $uid ?> .gu-seg button[aria-pressed=true]{background:#0969da;color:#fff;font-weight:600}

  #<?= $uid ?> .gu-lista{max-height:200px;overflow-y:auto;overscroll-behavior:contain}
  #<?= $uid ?> .gu-grupo{padding:7px 10px 3px;font-size:10.5px;font-weight:700;color:#8b949e;
      letter-spacing:.05em;text-transform:uppercase;background:#f6f8fa;border-top:1px solid #eaeef2}
  #<?= $uid ?> .gu-grupo:first-child{border-top:0}
  #<?= $uid ?> .gu-fila{display:flex;align-items:center;gap:8px;padding:7px 10px;cursor:pointer;
      border-top:1px solid #f2f4f7}
  #<?= $uid ?> .gu-fila:hover{background:#f0f6ff}
  #<?= $uid ?> .gu-fila.gu-no{cursor:not-allowed;background:#fcfcfd}
  #<?= $uid ?> .gu-fila.gu-no .gu-cod,#<?= $uid ?> .gu-fila.gu-no .gu-precio{color:#a8adb4}
  #<?= $uid ?> .gu-cod{font-weight:600;min-width:58px;flex:0 0 auto}
  #<?= $uid ?> .gu-tag{flex:0 0 auto;font-size:9.5px;font-weight:700;padding:2px 6px;border-radius:99px}
  #<?= $uid ?> .gu-tag.DISPONIBLE{background:#dafbe1;color:#116329}
  #<?= $uid ?> .gu-tag.RESERVADO{background:#fff8c5;color:#7d4e00}
  #<?= $uid ?> .gu-tag.FIRMADO{background:#ddf4ff;color:#0a3069}
  #<?= $uid ?> .gu-tag.VENDIDO{background:#fbefff;color:#6639ba}
  #<?= $uid ?> .gu-tag.BLOQUEADO,#<?= $uid ?> .gu-tag.PERDIDO{background:#eaeef2;color:#57606a}
  #<?= $uid ?> .gu-meta{flex:0 1 auto;color:#8b949e;font-size:11px;white-space:nowrap;
      overflow:hidden;text-overflow:ellipsis}
  /* Botón por unidad: abre NUESTRA cotización en otra pestaña. Va discreto y solo
     se enciende al pasar por la fila, para no competir con el precio ni invitar a
     pulsarlo por error cuando lo que se quiere es elegir la unidad. */
  /* marcada para cotizar: azul suave y una ✓, distinto de "elegida" (que sí aparta) */
  #<?= $uid ?> .gu-fila.gu-cotsel{background:#eaf4ff;box-shadow:inset 3px 0 0 #0969da}
  /* Con unidades marcadas, la única acción válida es cotizarlas juntas: se oculta
     el botón por fila para que no haya dos caminos y se elija el equivocado. */
  #<?= $uid ?>.gu-marcando .gu-cot{display:none}
  #<?= $uid ?> .gu-fila.gu-cotsel .gu-cod::before{content:'\2713\00a0';color:#0969da;font-weight:700}
  #<?= $uid ?> .gu-cottodas{float:right;font-size:10.5px;font-weight:700;letter-spacing:.3px;
     color:#fff;background:#0969da;border-radius:6px;padding:2px 9px;text-decoration:none;
     margin-top:-2px}
  #<?= $uid ?> .gu-cottodas:hover{background:#0757b3}
  #<?= $uid ?> .gu-cot{flex:0 0 auto;margin-left:8px;font-size:11px;font-weight:600;
     letter-spacing:.3px;color:#0c6c9c;border:1px solid #cfe2f0;border-radius:6px;
     padding:2px 8px;text-decoration:none;background:#f2f8fc;opacity:.55;transition:opacity .12s;
     cursor:pointer;user-select:none}
  #<?= $uid ?> .gu-fila:hover .gu-cot{opacity:1}
  #<?= $uid ?> .gu-cot:hover{background:#0c6c9c;color:#fff;border-color:#0c6c9c;opacity:1}
  #<?= $uid ?> .gu-precio{margin-left:auto;flex:0 0 auto;font-variant-numeric:tabular-nums;
      font-size:12px;color:#57606a}
  #<?= $uid ?> .gu-vacio{padding:16px 10px;text-align:center;color:#8b949e}
  #<?= $uid ?> .gu-pie{display:flex;align-items:center;gap:8px;padding:6px 10px;
      border-top:1px solid #eaeef2;color:#8b949e;font-size:11px}
  #<?= $uid ?> .gu-listo{margin-left:auto;border:0;background:#0969da;color:#fff;font:inherit;
      font-size:11.5px;font-weight:600;padding:4px 12px;border-radius:5px;cursor:pointer}
</style>

<!-- se deja el input por compatibilidad, pero el guardado real lo hace el JS por API -->
<input type="hidden" name="<?= h($name) ?>" id="<?= $uid ?>_val" value="<?= h(implode(',', $elegidos)) ?>">
<script>
  window.GU_CFG_<?= $uid ?> = {
    deal:  <?= (int)$dealId ?>,
    campo: <?= json_encode($name) ?>,
    // firma del id del deal: guardar.php no escribe nada sin ella
    firma: <?= json_encode($dealId > 0
        ? hash_hmac('sha256', (string)$dealId, (string)getenv('OUTBOUND_TOKEN'))
        : '') ?>,
    prospectos: 28,
    reserva28: <?= json_encode(reserva28_cache()) ?>,
    // MODO: la señal que distingue el modal de campos obligatorios de la ficha
    // normal, y la manda Bitrix. Verificado con un caso real el 2026-08-04 (deal
    // 402071): todos los renders de abrir y navegar el deal llegan con MODE=view,
    // y el ÚNICO con MODE=edit es el del modal "Complete todos los campos
    // requeridos para cambiar la etapa". No confundir con la vista de solo lectura
    // de este mismo archivo: esa solo se usa si Bitrix manda `solo_lectura`, que
    // no manda nunca, así que el desplegable se dibuja igual en los dos modos.
    modo: <?= json_encode($mode) ?>
  };
</script>

<?php
// El texto se imprime YA desde el servidor: si el JS tarda o falla, el campo
// igual muestra su valor en vez de quedar como un cuadro vacío.
$piezas = [];
foreach ($elegidos as $id) {
    $u = $porId[(string)$id] ?? null;
    $piezas[] = $u
        ? '<span class="gu-ir" data-ir="' . (int)$id . '">' . h($u['codigo'])
          . ' <span class="gu-proyname">(' . h($proys[(string)$u['cat']] ?? '') . ')</span></span>'
        : '<span class="gu-ir" data-ir="' . (int)$id . '">#' . h((string)$id) . '</span>';
}
?>
<div class="gu-campo" id="<?= $uid ?>_campo">
  <span class="gu-txt" id="<?= $uid ?>_txt"><?= $piezas
      ? implode('<span class="gu-sep">&middot;</span>', $piezas)
      : '<span class="gu-ph">Ver inventario&hellip;</span>' ?></span>
  <span class="gu-nota" id="<?= $uid ?>_nota"></span>
  <span class="gu-caret">&#9660;</span>
</div>

<div class="gu-panel" id="<?= $uid ?>_panel">
  <div class="gu-top">
    <div class="gu-drop" id="<?= $uid ?>_drop">
      <button type="button" class="gu-dropbtn" id="<?= $uid ?>_dropbtn">
        <span id="<?= $uid ?>_dropTxt">Proyecto</span><i>&#9660;</i>
      </button>
      <div class="gu-menu" id="<?= $uid ?>_menu">
        <button type="button" data-cat="" data-nom="Proyecto" aria-pressed="true">Todos los proyectos</button>
        <?php foreach ($proys as $cid => $nom):
              // el conteo se muestra solo aquí, en el desplegable; nunca en el botón
              $n = count(array_filter($porProyecto[(string)$cid] ?? [],
                        fn($u) => $u['stage'] === 'DISPONIBLE' && empty($u['dealId']))); ?>
          <button type="button" data-cat="<?= h((string)$cid) ?>" data-nom="<?= h($nom) ?>" aria-pressed="false">
            <?= h($nom) ?><span class="n"><?= $n ?></span>
          </button>
        <?php endforeach; ?>
      </div>
    </div>

    <input type="text" class="gu-buscar" id="<?= $uid ?>_q" placeholder="Código…" autocomplete="off">

    <div class="gu-seg" id="<?= $uid ?>_seg">
      <button type="button" data-libres="1" aria-pressed="true">Disponibles</button>
      <button type="button" data-libres="0" aria-pressed="false">Todos</button>
    </div>
  </div>

  <div class="gu-elegidas" id="<?= $uid ?>_elegidas" style="display:none"></div>

  <?php
  /*
   * La lista NO se imprime en HTML: viaja como JSON y la arma el navegador.
   *
   * Antes cada una de las 1.274 unidades era un <div> con 7 data-attributes, y el
   * render pesaba 745 KB — el 94% eran esas filas. Bitrix crea el iframe con 200px
   * y lo deja en blanco hasta que llega la respuesta, así que el asesor veía un
   * cuadro blanco grande 1-2 segundos en CADA apertura de deal (medido: 0,6-1,1s
   * de primer byte y hasta 7s de total en el peor caso). El gzip ya estaba puesto
   * (49 KB por la red), o sea lo que costaba era parsear el HTML, no bajarlo.
   *
   * En JSON las mismas unidades ocupan ~9 veces menos y el navegador construye las
   * filas de un solo golpe con innerHTML. El HTML resultante es IDÉNTICO al que se
   * imprimía aquí — mismas clases y mismos data-*, para que filtrar() y el resto
   * del JS sigan funcionando sin tocarse.
   */
  $datos = [];
  foreach ($proys as $cid => $nom) {
      $lst = $porProyecto[(string)$cid] ?? [];
      if (!$lst) continue;
      $us = [];
      foreach ($lst as $u) {
          $pvpNum = (float)str_replace(['|USD', ','], '', (string)$u['pvp']);
          $us[] = [
              (int)$u['id'],
              (string)$u['codigo'],
              (string)($u['stage'] ?: 'BLOQUEADO'),
              ($u['stage'] === 'DISPONIBLE' && empty($u['dealId'])) ? 1 : 0,
              trim(($u['torre'] !== '' ? 'T' . $u['torre'] : '')
                 . ($u['piso']  !== '' ? ' · P' . $u['piso'] : '')),
              $pvpNum,
          ];
      }
      $datos[] = [(string)$cid, (string)$nom, $us];
  }
  ?>
  <div class="gu-lista" id="<?= $uid ?>_lista">
    <div class="gu-vacio" id="<?= $uid ?>_vacio" style="display:none">Sin resultados</div>
  </div>
  <script>window['GU_DATOS_<?= $uid ?>'] = <?= json_encode($datos,
      JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>

  <div class="gu-pie">
    <span id="<?= $uid ?>_pie"></span>
    <!-- Marcar para COTIZAR no es elegir: no toca el campo ni aparta nada. Por eso
         existe también en las etapas donde la unidad todavía no se puede reservar. -->
    <a class="gu-cottodas" id="<?= $uid ?>_cotsel" style="display:none;float:none;margin-left:auto"
       target="_blank" rel="noopener"></a>
    <button type="button" class="gu-listo" id="<?= $uid ?>_listo">Listo</button>
  </div>
</div>

<script>
(function(){
  var R = document.getElementById('<?= $uid ?>');
  if (!R || R.dataset.listo) return;
  R.dataset.listo = '1';

  var CFG = window['GU_CFG_<?= $uid ?>'] || {};

  /*
   * Encoger el iframe LO PRIMERO de todo.
   *
   * Bitrix crea el app-frame con 200px fijos, y hasta que corría el ajuste se veía
   * un cuadro blanco enorme donde debería haber una línea: el ajuste vivía dentro
   * de BX24.init(), que espera a que cargue la librería, y ese salto era bien
   * visible. Ahora se pide al parsear el script; si BX24 todavía no está, se
   * reintenta en cuanto esté (iniciar() vuelve a llamar a ajustarIframe()).
   */
  try {
    if (typeof BX24 !== 'undefined') {
      if (BX24.resizeWindow) BX24.resizeWindow(document.documentElement.scrollWidth || 400, 28);
      if (BX24.fitWindow)    BX24.fitWindow();
    }
  } catch(e) {}

  var BLOQ = false;             // true = la etapa del deal no permite elegir unidad

  /*
   * Texto del campo vacío, en UNA sola variable.
   *
   * Lo escribían tres sitios a destiempo —el PHP, pintar() y el candado— y al abrir
   * un deal el asesor veía el campo cambiar tres veces en menos de un segundo:
   *   "Elegir unidad…"  ->  "Ver inventario…"  ->  "Ver inventario (se elige en RESERVA)"
   * Parecía un bug, y era uno.
   *
   * Arranca en el texto NEUTRO, que es cierto en todos los casos (mirar el
   * inventario siempre se puede) y es el mismo que ya imprime el PHP, así que el
   * primer pintado no cambia nada. De ahí solo se mueve UNA vez, cuando se resuelve
   * la etapa: a "(se elige en RESERVA)" si toca candado, o a "Elegir unidad…" si no.
   */
  var PH_TXT = 'Ver inventario…';
  /** Escribe el placeholder de una sola forma, desde PH_TXT. */
  function phTexto(t){
    PH_TXT = t;
    var ph = campo.querySelector('.gu-ph');
    if (ph) ph.textContent = t;
  }
  var val     = document.getElementById('<?= $uid ?>_val');
  var campo    = document.getElementById('<?= $uid ?>_campo');
  var notaEl   = document.getElementById('<?= $uid ?>_nota');
  var txt      = document.getElementById('<?= $uid ?>_txt');
  var elegidas = document.getElementById('<?= $uid ?>_elegidas');
  var COT = <?= json_encode(cot_base($dealId), JSON_UNESCAPED_SLASHES) ?>;
  // Unidades marcadas SOLO para cotizar. Es una lista aparte de `sel` a propósito:
  // no se guarda, no cambia la etapa de la unidad y no ocupa nada. Así el asesor
  // puede armar una fusión y cotizarla desde cualquier etapa, aunque todavía no
  // pueda apartar (en Prospectos la unidad se elige solo en RESERVA).
  var selCot = [];
  var btnCot = document.getElementById('<?= $uid ?>_cotsel');
  /** Cotización de TODAS las unidades elegidas: los activos fusionados se cotizan
   *  como uno solo (precio y metros sumados, un único plan de pago). */
  function pintarCotSel(){
    if (!btnCot) return;
    filas.forEach(function(f){ f.classList.toggle('gu-cotsel', selCot.indexOf(f.dataset.id) !== -1); });
    R.classList.toggle('gu-marcando', selCot.length > 0);
    if (!selCot.length) { btnCot.style.display = 'none'; return; }
    btnCot.style.display = '';
    btnCot.href = cotUrlDe(selCot);
    btnCot.textContent = selCot.length > 1
      ? ('Cotizar las ' + selCot.length + ' juntas')
      : 'Cotizar la marcada';
    btnCot.title = selCot.length > 1
      ? 'Un solo plan: precio y metros sumados, una reserva y una serie de cuotas. No aparta nada.'
      : 'Abre la cotización. No reserva ni aparta nada.';
    // El pie dice qué se lleva marcado; sin esto, con la lista larga, no se ve.
    if (pie) pie.textContent = selCot.length + (selCot.length > 1 ? ' marcadas para cotizar' : ' marcada para cotizar');
  }
  function cotUrlDe(ids){
    return COT.url + '?u=' + ids.join(',') + '&d=' + COT.d + '&exp=' + COT.exp + '&s=' + COT.s;
  }
  var q       = document.getElementById('<?= $uid ?>_q');
  var seg     = document.getElementById('<?= $uid ?>_seg');
  var dropbtn = document.getElementById('<?= $uid ?>_dropbtn');
  var dropTxt = document.getElementById('<?= $uid ?>_dropTxt');
  var menu    = document.getElementById('<?= $uid ?>_menu');
  var lista   = document.getElementById('<?= $uid ?>_lista');
  var vacio   = document.getElementById('<?= $uid ?>_vacio');
  var pie     = document.getElementById('<?= $uid ?>_pie');
  var listoBt = document.getElementById('<?= $uid ?>_listo');

  /*
   * Se arman las filas desde el JSON, de un solo innerHTML.
   *
   * El HTML que sale de aquí es EL MISMO que antes imprimía PHP: mismas clases y
   * mismos data-*, así que filtrar(), el clic de la fila y el marcado para cotizar
   * siguen igual. Corre ANTES de capturar `filas`/`grupos`, que es lo único que
   * pedía el orden anterior.
   */
  (function armarFilas(){
    var D = window['GU_DATOS_<?= $uid ?>'] || [];
    var esc = function(s){
      return String(s).replace(/[&<>"]/g, function(c){
        return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];
      });
    };
    // "$1.234.567" sin decimales, igual que el number_format del PHP que había
    var money = function(n){ return '$' + Math.round(n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ','); };
    var h = [];
    for (var i = 0; i < D.length; i++) {
      var cid = esc(D[i][0]), nom = esc(D[i][1]), us = D[i][2];
      if (!us || !us.length) continue;
      h.push('<div class="gu-grupo" data-cat="', cid, '">', nom, '</div>');
      for (var j = 0; j < us.length; j++) {
        var u = us[j];                      // [id, codigo, estado, libre, meta, pvp]
        var cod = esc(u[1]), est = esc(u[2]), libre = u[3] ? 1 : 0, meta = esc(u[4]), pvp = u[5] || 0;
        h.push('<div class="gu-fila', (libre ? '' : ' gu-no'),
               '" data-cat="', cid, '" data-cod="', esc(String(u[1]).toUpperCase()),
               '" data-libre="', libre, '" data-id="', u[0], '" data-pvp="', pvp,
               '" data-cod-txt="', cod, '" data-proy="', nom, '">',
               '<span class="gu-cod">', cod, '</span>',
               '<span class="gu-tag ', est, '">', est, '</span>');
        if (meta) h.push('<span class="gu-meta">', meta, '</span>');
        if (pvp)  h.push('<span class="gu-precio">', money(pvp), '</span>',
                         '<a class="gu-cot">Cotizar</a>');
        h.push('</div>');
      }
    }
    // antes del "Sin resultados", que es lo único que ya vive en la lista
    if (h.length && vacio) vacio.insertAdjacentHTML('beforebegin', h.join(''));
  })();

  var filas  = Array.prototype.slice.call(R.querySelectorAll('.gu-fila'));
  var grupos = Array.prototype.slice.call(R.querySelectorAll('.gu-grupo'));

  var soloLibres = true;
  var cat = '';
  var ALTO_CERRADO = 28;   // = min-height de .gu-campo, para que no sobre ni falte
  // selección viva (varias unidades por deal = fusión)
  var sel = val.value.split(',').filter(function(x){ return x; });

  /**
   * Guarda por API. Imprescindible: el <input> vive dentro del iframe del campo,
   * así que el submit del deal NUNCA lo incluye. Se escribe el campo con el token
   * que Bitrix entrega en cada render y después se avisa a nuestro servicio para
   * que arme la dependencia en la unidad y aplique el stage.
   */
  /**
   * Le dice a Bitrix el valor del campo.
   *
   * Hace falta porque este campo guarda por su cuenta contra nuestro servidor y
   * el FORMULARIO de Bitrix nunca se enteraba. Con el campo marcado obligatorio
   * para cambiar de etapa, su validación miraba el estado del formulario, lo veía
   * vacío y bloqueaba el cambio aunque la unidad ya estuviera elegida y guardada.
   *
   * El placement USERFIELD_TYPE expone los comandos setValue/getValue
   * (comprobado en vivo con BX24.placement.getInterface, no por documentación).
   */
  function avisarBitrix(v){
    try {
      if (typeof BX24 !== 'undefined' && BX24.placement && BX24.placement.call) {
        BX24.placement.call('setValue', v);
      }
    } catch(e) {}
  }

  var guardando = false;
  function guardar(){
    if (!CFG.deal || !CFG.firma) { avisar('este campo solo guarda dentro de un deal', true); return; }
    guardando = true;

    // Se llama a NUESTRO servidor (mismo dominio que este iframe). Llamar al API
    // de Bitrix directo desde aquí lo bloquea el navegador por ser otro dominio
    // (CORS): era la razón por la que la selección no se guardaba.
    var cuerpo = new URLSearchParams();
    cuerpo.set('deal',  CFG.deal);
    cuerpo.set('valor', val.value);
    cuerpo.set('firma', CFG.firma);

    fetch('guardar.php', {method:'POST', body:cuerpo})
      .then(function(r){ return r.json(); })
      .then(function(j){
        guardando = false;
        // solo se avisa si FALLA: en el camino bueno la interfaz queda limpia
        // solo se avisa si FALLA: en el camino bueno la interfaz queda limpia
        if (!j || !j.ok) { avisar('no se pudo guardar: ' + ((j && j.error) || '?'), true); return; }
        // El servidor ya validó y guardó: ahora sí se le reporta al formulario.
        avisarBitrix(typeof j.guardado === 'string' ? j.guardado : val.value);
        refrescado = 0; refrescarEstado();
      })
      .catch(function(e){ guardando = false; avisar('error de red', true); });
  }

  function avisar(txt, err){
    var p = document.getElementById('<?= $uid ?>_pie');
    if (!p) return;
    p.dataset.msg = txt;
    p.style.color = err ? '#cf222e' : '#57606a';
    p.textContent = txt;
    if (!err) setTimeout(function(){ if (p.dataset.msg === txt) filtrar(); }, 1400);
  }

  /** Marca la fila como libre/ocupada en el momento, sin recargar. */
  function marcarFila(id, libre){
    var f = filas.filter(function(x){ return x.dataset.id === id; })[0];
    if (!f) return;
    f.dataset.libre = libre ? '1' : '0';
    f.classList.toggle('gu-no', !libre);
    var tag = f.querySelector('.gu-tag');
    if (tag) {
      tag.className = 'gu-tag ' + (libre ? 'DISPONIBLE' : 'RESERVADO');
      tag.textContent = libre ? 'DISPONIBLE' : 'RESERVADO';
    }
  }

  function datos(id){
    var f = filas.filter(function(x){ return x.dataset.id === id; })[0];
    // pvp va incluido: el botón de cotizar el conjunto descarta las unidades sin
    // precio, que no suman nada al total.
    return f ? {cod: f.dataset.codTxt, proy: f.dataset.proy, pvp: f.dataset.pvp}
             : {cod: '#' + id, proy: '', pvp: 0};
  }

  /** Cerrado: texto plano, igual que los campos nativos del deal. */
  function pintar(){
    txt.innerHTML = '';
    if (!sel.length) {
      var ph = document.createElement('span');
      ph.className = 'gu-ph';
      // PH_TXT y no un texto fijo: pintar() corre al arrancar y cada vez que cambia
      // la selecci\u00f3n, as\u00ed que si escribiera "Elegir unidad\u2026" a mano le pisar\u00eda el
      // mensaje al candado y el campo parpadear\u00eda de vuelta al texto equivocado.
      ph.textContent = PH_TXT;
      txt.appendChild(ph);
      return;
    }
    sel.forEach(function(id, i){
      var d = datos(id);
      if (i) {
        var s = document.createElement('span');
        s.className = 'gu-sep'; s.textContent = '\u00b7';
        txt.appendChild(s);
      }
      // todo el nombre (código + proyecto) es un solo enlace
      var a = document.createElement('span');
      a.className = 'gu-ir'; a.dataset.ir = id;
      a.title = 'Abrir la ficha de la unidad';
      a.appendChild(document.createTextNode(d.cod + ' '));
      var p = document.createElement('span');
      p.className = 'gu-proyname'; p.textContent = '(' + d.proy + ')';
      a.appendChild(p);
      txt.appendChild(a);
    });
  }

  /** Dentro del desplegable: lo elegido arriba, en azul y con la X. */
  function pintarElegidas(){
    elegidas.innerHTML = '';
    if (!sel.length) { elegidas.style.display = 'none'; return; }
    elegidas.style.display = '';
    var t = document.createElement('div');
    t.className = 'gu-eltit';
    t.textContent = 'Elegidas';
    // Con más de una, lo que hay que cotizar es el CONJUNTO, no cada una suelta:
    // así se cotiza una fusión (dos locales, oficina + parqueo…) en un solo plan.
    var conPrecio = sel.filter(function(id){ return parseFloat((datos(id)||{}).pvp || 0) > 0; });
    if (conPrecio.length) {
      var a = document.createElement('a');
      a.className = 'gu-cottodas';
      a.target = '_blank'; a.rel = 'noopener';
      a.href = cotUrlDe(conPrecio);
      a.textContent = conPrecio.length > 1 ? ('Cotizar las ' + conPrecio.length + ' juntas') : 'Cotizar';
      a.title = conPrecio.length > 1
        ? 'Un solo plan de pago con el precio y los metros sumados'
        : 'Cotizar la unidad elegida';
      a.addEventListener('click', function(e){ e.stopPropagation(); });
      t.appendChild(a);
    }
    elegidas.appendChild(t);
    sel.forEach(function(id){
      var d = datos(id);
      var row = document.createElement('div');
      row.className = 'gu-el';
      row.innerHTML = '<span class="gu-elcod"></span><span class="gu-elproy"></span><b title="Quitar">&times;</b>';
      row.children[0].textContent = d.cod;
      row.children[1].textContent = d.proy;
      row.children[2].addEventListener('click', function(e){
        e.stopPropagation();
        sel = sel.filter(function(x){ return x !== id; });
        val.value = sel.join(',');
        marcarFila(id, true);        // se libera: vuelve a aparecer en Disponibles
        pintar(); pintarElegidas(); filtrar(); ajustarIframe(); guardar();
      });
      elegidas.appendChild(row);
    });
  }


  function ajustarIframe(){
    // cerrado: exactamente el alto del contenido. Si el iframe queda más alto que
    // el contenido, Bitrix lo alinea arriba y el texto se ve "un poco arriba".
    // Abierto se pide un alto FIJO: el panel se adapta a lo que Bitrix conceda,
    // así que ya no hace falta medir scrollHeight (que además ahora está clavado).
    var alto = R.classList.contains('abierto')
      ? 360
      : Math.max(ALTO_CERRADO, campo.offsetHeight);
    try {
      if (typeof BX24 !== 'undefined' && BX24.resizeWindow) {
        BX24.resizeWindow(document.documentElement.scrollWidth, alto);
      }
    } catch(e) {}
  }

  // "$1.234.567" — la cuantía se lee de un vistazo, sin decimales: son cifras de
  // seis y siete dígitos y los centavos solo estorban.
  function plata(n){
    if (!n) return '';
    return '$' + Math.round(n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  }

  function filtrar(){
    var t = q.value.trim().toUpperCase();
    var n = 0, monto = 0;
    filas.forEach(function(f){
      // las ya elegidas no se repiten en el listado: salen arriba, en "Elegidas".
      // Y con el filtro "Disponibles" tampoco aparecen las ocupadas.
      var yo = sel.indexOf(f.dataset.id) !== -1;
      var ok = !yo
            && (!cat || f.dataset.cat === cat)
            && (!t || f.dataset.cod.indexOf(t) !== -1)
            && (!soloLibres || f.dataset.libre === '1');
      f.style.display = ok ? '' : 'none';
      if (ok) { n++; monto += parseFloat(f.dataset.pvp || 0) || 0; }
    });
    grupos.forEach(function(g){
      var hay = false, node = g.nextElementSibling;
      while (node && node.classList.contains('gu-fila')) {
        if (node.style.display !== 'none') { hay = true; break; }
        node = node.nextElementSibling;
      }
      g.style.display = (hay && !cat) ? '' : 'none';
    });
    vacio.style.display = n ? 'none' : '';
    // Cuantía de lo que se está viendo: cambia con el proyecto elegido, con la
    // búsqueda y con Disponibles/Todos. Sirve para saber cuánto inventario queda
    // por vender sin salir a hacer la suma por fuera.
    pie.textContent = n + (n === 1 ? ' unidad' : ' unidades')
                    + (monto ? ' · ' + plata(monto) : '')
                    + (sel.length ? ' · ' + sel.length + ' elegida' + (sel.length > 1 ? 's' : '') : '');
    // DESPUÉS del pie: cuando hay unidades marcadas para cotizar, pintarCotSel lo
    // reemplaza por ese conteo. Si se llamara antes, esta línea lo borraría.
    pintarCotSel();
    lista.scrollTop = 0;
  }

  function abrirMenu(si){ menu.classList.toggle('on', !!si); }

  /**
   * Trae del servidor la disponibilidad al día y corrige las filas.
   * El catálogo viene impreso al cargar la página; sin esto la lista quedaba
   * vieja hasta recargar (una unidad liberada seguía sin aparecer).
   */
  var refrescado = 0;
  function refrescarEstado(){
    if (Date.now() - refrescado < 3000) return;   // no spamear al abrir/cerrar
    refrescado = Date.now();
    fetch('estado.php', {cache:'no-store'})
      .then(function(r){ return r.json(); })
      .then(function(mapa){
        filas.forEach(function(f){
          var e = mapa[f.dataset.id];
          if (!e) return;
          var libre = e[0] === 1 && sel.indexOf(f.dataset.id) === -1;
          f.dataset.libre = libre ? '1' : '0';
          f.classList.toggle('gu-no', !libre);
          var tag = f.querySelector('.gu-tag');
          if (tag && e[1]) { tag.className = 'gu-tag ' + e[1]; tag.textContent = e[1]; }
        });
        filtrar(); ajustarIframe();
      })
      .catch(function(){});
  }

  function abrir(si){
    // Estando bloqueado el panel SÍ abre: el vendedor necesita consultar qué hay
    // y a qué precio en cualquier etapa. Lo que no puede es elegir, y de eso se
    // encarga la clase gu-bloq (filas sin clic) más el candado real de guardar.php.
    R.classList.toggle('abierto', si);
    // fija el documento al alto del iframe mientras está abierto
    document.documentElement.classList.toggle('gu-open', !!si);
    document.body.classList.toggle('gu-open', !!si);
    if (!si) abrirMenu(false);
    if (si) { filtrar(); refrescarEstado(); }
    ajustarIframe();
    if (si) { try { q.focus(); } catch(e){} }
  }

  /**
   * Abre la ficha de la unidad en el panel lateral de Bitrix.
   *
   * Es lo que hacían los campos nativos: pulsar la unidad y ver su ficha. Se usa
   * BX24.openPath para que salga en el slider (sin sacar al usuario del deal); si
   * no está disponible, se cae a navegar la ventana de arriba, porque un enlace
   * normal dentro de este iframe abriría la ficha DENTRO del iframe del campo.
   */
  function abrirUnidad(id){
    // 1072 = SPA Inventario. field.php es autónomo y no carga las constantes.
    var ruta = '/crm/type/1072/details/' + encodeURIComponent(id) + '/';
    try {
      if (typeof BX24 !== 'undefined' && BX24.openPath) { BX24.openPath(ruta); return; }
    } catch(e) {}
    try { window.top.location.href = ruta; } catch(e) { window.open(ruta, '_blank'); }
  }

  campo.addEventListener('click', function(ev){
    // pulsar el código abre la unidad; pulsar el resto de la barra despliega
    var ir = ev.target.closest ? ev.target.closest('.gu-ir') : null;
    if (ir && ir.dataset.ir) {
      ev.stopPropagation(); ev.preventDefault();
      abrirUnidad(ir.dataset.ir);
      return;
    }
    abrir(!R.classList.contains('abierto'));
  });
  listoBt.addEventListener('click', function(){ abrir(false); });

  seg.addEventListener('click', function(e){
    var b = e.target.closest('button'); if (!b) return;
    soloLibres = (b.dataset.libres === '1');
    Array.prototype.forEach.call(seg.children, function(x){ x.setAttribute('aria-pressed', String(x === b)); });
    filtrar();
  });

  dropbtn.addEventListener('click', function(e){ e.stopPropagation(); abrirMenu(!menu.classList.contains('on')); });

  menu.addEventListener('click', function(e){
    var b = e.target.closest('button'); if (!b) return;
    cat = b.dataset.cat;
    Array.prototype.forEach.call(menu.children, function(x){ x.setAttribute('aria-pressed', String(x === b)); });
    // se usa data-nom: el textContent traía pegado el conteo ("Noral Plaza251")
    dropTxt.textContent = b.dataset.nom || 'Proyecto';
    abrirMenu(false);
    filtrar();
  });

  q.addEventListener('input', filtrar);
  q.addEventListener('keydown', function(e){
    if (e.key === 'Enter') { e.preventDefault(); e.stopPropagation(); }
    if (e.key === 'Escape') { menu.classList.contains('on') ? abrirMenu(false) : abrir(false); }
  });

  lista.addEventListener('click', function(e){
    // "Cotizar" va SIN href: la firma es la misma para todas las filas, así que
    // repetir la URL entera en cada una engordaba el fragmento ~400 KB (1.418
    // unidades × 286 bytes) y Bitrix llegó a cortar la conexión del iframe.
    // Se arma aquí, en el momento del clic. Y se corta la propagación: si no,
    // además de cotizar marcaría la unidad sin que nadie lo pida.
    var bc = e.target.closest('.gu-cot');
    if (bc) {
      e.stopPropagation(); e.preventDefault();
      var fc = bc.closest('.gu-fila');
      if (fc) window.open(cotUrlDe([fc.dataset.id]), '_blank', 'noopener');
      return;
    }
    var f = e.target.closest('.gu-fila'); if (!f) return;
    var id = f.dataset.id;
    if (BLOQ) {
      // No se puede APARTAR en esta etapa, pero sí cotizar: el clic marca y
      // desmarca para armar la fusión que se va a cotizar. Nada se guarda.
      if ((parseFloat(f.dataset.pvp || 0) || 0) <= 0) return;   // sin precio no suma
      var i = selCot.indexOf(id);
      if (i === -1) selCot.push(id); else selCot.splice(i, 1);
      // filtrar() y no pintarCotSel() a secas: filtrar reescribe el pie con el
      // conteo normal y luego llama a pintarCotSel, así que al desmarcar la
      // última el pie vuelve a su texto en vez de quedarse en "1 marcada".
      filtrar();
      return;
    }
    if (f.dataset.libre !== '1') return;          // ocupada: no seleccionable
    if (sel.indexOf(id) !== -1) return;           // ya elegida (se quita desde "Elegidas")
    sel.push(id);
    val.value = sel.join(',');
    marcarFila(id, false);          // queda tomada por este deal
    pintar(); pintarElegidas(); filtrar(); ajustarIframe(); guardar();
  });

  document.addEventListener('click', function(){ abrirMenu(false); });

  /**
   * Candado de etapa (capa VISUAL). En PROSPECTOS(28) la unidad se elige solo en
   * RESERVA; en el resto de etapas el campo queda de lectura.
   *
   * La etapa se pregunta con BX24.callMethod, es decir con el token del USUARIO,
   * a propósito: nuestro webhook entrante es compartido y el portal ya va al tope
   * (~120 llamadas/min entre todos los sistemas). Un crm.deal.get por apertura de
   * deal contra ese webhook le quitaba turno a cobranzas.
   *
   * Si esto falla (sin BX24, error del API) NO se bloquea nada: quien decide de
   * verdad es guardar.php, que rechaza con el mismo criterio y sin depender del
   * navegador. Aquí solo se evita que el vendedor elija para que se lo tumben.
   */
  /*
   * FAIL-CLOSED (ago-2026). Antes esto arrancaba ABIERTO y solo cerraba cuando
   * volvía el crm.deal.get. Ese viaje tarda, y en esa ventana el asesor alcanzaba
   * a abrir el panel y elegir una unidad estando en cualquier etapa del 28. No es
   * teórico: así quedó la A-1-1 de Noral Apartments en RESERVADO, apartada por un
   * prospecto en VOLVER A LLAMAR (deal 401401), sin dueño y sin poder venderse.
   * Ahora nace cerrado y solo se abre cuando se confirma que la etapa lo permite;
   * si la comprobación falla, se queda cerrado y lo dice.
   */
  /*
   * Ya NO bloquea: AVISA.
   *
   * El campo es obligatorio para entrar a RESERVA, así que bloquearlo fuera de
   * RESERVA hacía imposible cambiar de etapa — ni desde el deal ni desde el kanban.
   * La regla ahora la garantiza el servidor de otra forma (apartar_prospecto en
   * campolib.php): elegir se puede en cualquier etapa, pero la unidad solo se
   * aparta cuando el deal llega a RESERVA.
   *
   * Aquí lo único que hace falta es no dejar creer al asesor que ya la tiene.
   */
  function candadoEtapa(){
    /*
     * El modal "Complete todos los campos requeridos para cambiar la etapa" pide
     * ESTE campo para poder pasar a RESERVA. Si ahí se bloquea, el candado se
     * muerde la cola: no se puede elegir hasta estar en RESERVA y Bitrix no deja
     * entrar a RESERVA sin haber elegido. Y da igual si el asesor mueve la etapa
     * desde el deal o arrastrando en el kanban: el modal es el mismo.
     *
     * Se reconoce por MODE=edit, que lo manda Bitrix. Comprobado con un caso real
     * (deal 402071, 2026-08-04): abrir y navegar el deal produce MODE=view en
     * todos los renders, y el único MODE=edit es el del modal. Es una señal del
     * servidor, no una medida del navegador — el intento anterior de adivinarlo por
     * el ancho del iframe (<560px) daba positivo también en la ficha normal, y por
     * ahí se apartó la A-1-1 de Noral Apartments desde VOLVER A LLAMAR.
     *
     * Queda un caso que también entra por aquí: pulsar "editar" en la sección del
     * deal. Es un acto deliberado y poco común, y aun así no puede trabar nada — el
     * servidor solo aparta en RESERVA (apartar_prospecto en campolib.php).
     */
    if (CFG.modo === 'edit') return;
    if (!CFG.deal) return;  // deal nuevo: no hay etapa que comprobar todavía
    // FAIL-CLOSED: cerrado hasta que se confirme que la etapa deja elegir. Si
    // arrancara abierto, entre el render y la respuesta del crm.deal.get hay una
    // ventana en la que se puede elegir igual — así quedó apartada la A-1-1.
    bloquear('verificando');
    if (typeof BX24 === 'undefined' || !BX24.callMethod) { bloquear('sin-verificar'); return; }
    try {
      BX24.callMethod('crm.deal.get', {id: CFG.deal}, function(res){
        try {
          if (!res || (res.error && res.error())) { bloquear('sin-verificar'); return; }
          var d = res.data() || {};
          // fuera de Prospectos, o ya en RESERVA: el campo se usa normal
          if (String(d.CATEGORY_ID) !== String(CFG.prospectos)) { desbloquear(); return; }
          if (String(d.STAGE_ID) === String(CFG.reserva28))     { desbloquear(); return; }
          bloquear();
        } catch(e) { bloquear('sin-verificar'); }
      });
    } catch(e) { bloquear('sin-verificar'); }
  }

  /**
   * Deja el campo de lectura: el panel SIGUE abriendo (consultar y cotizar se
   * puede en cualquier etapa), lo que se apaga es elegir. `motivo` cambia el
   * mensaje: mentir con "se elige en RESERVA" cuando en realidad no pudimos
   * comprobar la etapa manda al asesor a buscar un problema que no existe.
   *
   * Se mantiene en UNA línea de texto, como estaba al principio: el asesor abre el
   * desplegable, mira disponibles, marca las que quiera y cotiza (esa selección es
   * aparte, `selCot`, y no se guarda ni ocupa nada). Lo único apagado es elegir la
   * unidad de verdad.
   */
  function bloquear(motivo){
    BLOQ = true;
    R.classList.add('gu-bloq');
    var txtPh, txtTitle;
    if (motivo === 'verificando') {
      txtPh = 'Ver inventario…';
      txtTitle = 'Comprobando la etapa del deal…';
    } else if (motivo === 'sin-verificar') {
      txtPh = 'Ver inventario (no pude comprobar la etapa · recarga el deal)';
      txtTitle = 'No pude comprobar la etapa del deal. Puedes consultar y cotizar; para apartar, recarga el deal.';
    } else {
      txtPh = 'Ver inventario (se elige en RESERVA)';
      txtTitle = 'Puedes consultar y cotizar el inventario; apartar la unidad se hace en la etapa RESERVA';
    }
    campo.title = txtTitle;
    phTexto(txtPh);
    if (notaEl) notaEl.textContent = '';
    ajustarIframe();
  }

  /** La etapa SÍ permite elegir: se devuelve el campo a su estado normal. */
  function desbloquear(){
    BLOQ = false;
    R.classList.remove('gu-bloq');
    campo.title = '';
    phTexto('Elegir unidad…');
    if (notaEl) notaEl.textContent = '';
    ajustarIframe();
  }

  pintar(); pintarElegidas();
  function iniciar(){ ajustarIframe(); candadoEtapa(); }
  if (typeof BX24 !== 'undefined') { try { BX24.init(iniciar); } catch(e) { iniciar(); } }


  else { window.addEventListener('load', iniciar); setTimeout(iniciar, 600); }

  // Al cargar se le reporta a Bitrix el valor que YA tiene el deal. Sin esto, si
  // la unidad se eligió en otro momento, el modal de "campos obligatorios para
  // cambiar de etapa" seguía viéndolo vacío y no dejaba avanzar.
  if (typeof BX24 !== 'undefined') { try { BX24.init(function(){ avisarBitrix(val.value); }); } catch(e) {} }


})();
</script>
</div>
