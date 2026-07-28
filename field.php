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
    . "\n", FILE_APPEND | LOCK_EX);

$mode  = (string)($_REQUEST['mode'] ?? 'edit');
$value = (string)($_REQUEST['value'] ?? '');
$campo = $_REQUEST['field'] ?? [];
// nombre del input que Bitrix espera recibir de vuelta al guardar
$name  = (string)($campo['NAME'] ?? $_REQUEST['name'] ?? 'UF_UNIDAD');

/** IDs seleccionados a partir del valor guardado ("581,623"). */
function ids_de(string $v): array {
    $out = [];
    foreach (preg_split('/[,;\s]+/', $v) as $x) {
        $x = trim($x);
        if ($x !== '' && ctype_digit($x) && (int)$x > 0) $out[] = (int)$x;
    }
    return array_values(array_unique($out));
}

/** Unidades desde el caché de selector.php (no vuelve a pegarle al API). */
function catalogo_cache(): array {
    $j = json_decode((string)@file_get_contents((getenv('DATA_DIR') ?: '/data') . '/selector_cache.json'), true);
    return is_array($j) ? $j : ['units' => [], 'proyectos' => []];
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$cat   = catalogo_cache();
$units = $cat['units'] ?? [];
$proys = $cat['proyectos'] ?? [];

// índice por id para resolver rápido lo ya seleccionado
$porId = [];
foreach ($units as $u) $porId[(string)$u['id']] = $u;

$elegidos = ids_de($value);

// ---- settings: el campo no necesita configuración ---------------------------
if ($mode === 'settings') { echo ''; exit; }

// ---- view: mostrar las unidades guardadas -----------------------------------
if ($mode === 'view') {
    if (!$elegidos) { echo '<span style="color:#8b949e">—</span>'; exit; }
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
    echo implode('<span style="color:#d0d7de">&nbsp;·&nbsp;</span>', $partes);
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
  html,body{margin:0;padding:0;background:transparent;overflow:hidden}
  #<?= $uid ?>{font:13.5px/1.4 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:#1f2328}
  #<?= $uid ?> *{box-sizing:border-box}

  /* línea cerrada: chips de lo elegido + "agregar".
     El padding y el min-height igualan la altura/sangría de los campos nativos
     del deal, para que el texto quede centrado y no pegado al borde. */
  /* padding simétrico arriba/abajo: así el texto queda centrado y no "un poco arriba" */
  #<?= $uid ?> .gu-campo{display:flex;align-items:center;flex-wrap:wrap;gap:6px;min-height:28px;
      cursor:pointer;padding:5px 7px;border-bottom:1px solid transparent}
  #<?= $uid ?> .gu-campo:hover{border-bottom-color:#c9ccd0}
  #<?= $uid ?> .gu-ph{color:#a8adb4;display:inline-flex;align-items:center;height:20px}
  #<?= $uid ?> .gu-chip{display:inline-flex;align-items:center;gap:6px;background:#eef4ff;
      border:1px solid #c8dcff;border-radius:99px;padding:0 4px 0 9px;height:22px;
      font-size:12px;font-weight:600;line-height:1}
  #<?= $uid ?> .gu-chip small{font-weight:400;color:#57606a;line-height:1}
  /* la ✕ como cuadro centrado: antes bailaba respecto al texto del chip */
  #<?= $uid ?> .gu-chip b{display:inline-flex;align-items:center;justify-content:center;
      width:16px;height:16px;border-radius:50%;cursor:pointer;color:#8b949e;
      font-weight:700;font-size:12px;line-height:1}
  #<?= $uid ?> .gu-chip b:hover{color:#cf222e;background:#ffe3e3}
  #<?= $uid ?> .gu-mas{color:#0969da;font-size:12px;font-weight:600;display:inline-flex;
      align-items:center;height:20px}
  /* flecha: pegada a la derecha y a la misma altura que el texto */
  #<?= $uid ?> .gu-caret{margin-left:auto;display:inline-flex;align-items:center;justify-content:center;
      height:20px;color:#a8adb4;font-size:9px;padding-left:6px}

  #<?= $uid ?> .gu-panel{display:none;margin-top:5px;border:1px solid #d0d7de;border-radius:8px;
      background:#fff;box-shadow:0 6px 18px rgba(27,31,36,.12);position:relative}
  #<?= $uid ?>.abierto .gu-panel{display:block}

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
  #<?= $uid ?> .gu-fila.gu-yo{background:#eef4ff}
  #<?= $uid ?> .gu-tick{flex:0 0 auto;width:13px;color:#0969da;font-weight:700}
  #<?= $uid ?> .gu-cod{font-weight:600;min-width:58px;flex:0 0 auto}
  #<?= $uid ?> .gu-tag{flex:0 0 auto;font-size:9.5px;font-weight:700;padding:2px 6px;border-radius:99px}
  #<?= $uid ?> .gu-tag.DISPONIBLE{background:#dafbe1;color:#116329}
  #<?= $uid ?> .gu-tag.RESERVADO{background:#fff8c5;color:#7d4e00}
  #<?= $uid ?> .gu-tag.FIRMADO{background:#ddf4ff;color:#0a3069}
  #<?= $uid ?> .gu-tag.VENDIDO{background:#fbefff;color:#6639ba}
  #<?= $uid ?> .gu-tag.BLOQUEADO,#<?= $uid ?> .gu-tag.PERDIDO{background:#eaeef2;color:#57606a}
  #<?= $uid ?> .gu-meta{flex:0 1 auto;color:#8b949e;font-size:11px;white-space:nowrap;
      overflow:hidden;text-overflow:ellipsis}
  #<?= $uid ?> .gu-precio{margin-left:auto;flex:0 0 auto;font-variant-numeric:tabular-nums;
      font-size:12px;color:#57606a}
  #<?= $uid ?> .gu-vacio{padding:16px 10px;text-align:center;color:#8b949e}
  #<?= $uid ?> .gu-pie{display:flex;align-items:center;gap:8px;padding:6px 10px;
      border-top:1px solid #eaeef2;color:#8b949e;font-size:11px}
  #<?= $uid ?> .gu-listo{margin-left:auto;border:0;background:#0969da;color:#fff;font:inherit;
      font-size:11.5px;font-weight:600;padding:4px 12px;border-radius:5px;cursor:pointer}
</style>

<input type="hidden" name="<?= h($name) ?>" id="<?= $uid ?>_val" value="<?= h(implode(',', $elegidos)) ?>">

<div class="gu-campo" id="<?= $uid ?>_campo"></div>

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

  <div class="gu-lista" id="<?= $uid ?>_lista">
    <?php foreach ($proys as $cid => $nom):
          $lista = $porProyecto[(string)$cid] ?? [];
          if (!$lista) continue; ?>
      <div class="gu-grupo" data-cat="<?= h((string)$cid) ?>"><?= h($nom) ?></div>
      <?php foreach ($lista as $u):
            $libre = ($u['stage'] === 'DISPONIBLE' && empty($u['dealId']));
            $yo    = in_array((int)$u['id'], $elegidos, true);
            $meta  = trim(($u['torre'] !== '' ? 'T' . $u['torre'] : '')
                        . ($u['piso']  !== '' ? ' · P' . $u['piso'] : ''));
            $pvp   = $u['pvp'] !== '' ? '$' . number_format((float)str_replace(['|USD', ','], '', $u['pvp']), 0) : '';
            $est   = $u['stage'] ?: 'BLOQUEADO';
      ?>
        <div class="gu-fila <?= (!$libre && !$yo) ? 'gu-no' : '' ?> <?= $yo ? 'gu-yo' : '' ?>"
             data-cat="<?= h((string)$cid) ?>" data-cod="<?= h(strtoupper($u['codigo'])) ?>"
             data-libre="<?= ($libre || $yo) ? 1 : 0 ?>" data-id="<?= (int)$u['id'] ?>"
             data-cod-txt="<?= h($u['codigo']) ?>" data-proy="<?= h($nom) ?>">
          <span class="gu-tick"><?= $yo ? '&#10003;' : '' ?></span>
          <span class="gu-cod"><?= h($u['codigo']) ?></span>
          <span class="gu-tag <?= h($est) ?>"><?= h($est) ?></span>
          <?php if ($meta !== ''): ?><span class="gu-meta"><?= h($meta) ?></span><?php endif; ?>
          <?php if ($pvp !== ''): ?><span class="gu-precio"><?= h($pvp) ?></span><?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php endforeach; ?>
    <div class="gu-vacio" id="<?= $uid ?>_vacio" style="display:none">Sin resultados</div>
  </div>

  <div class="gu-pie">
    <span id="<?= $uid ?>_pie"></span>
    <button type="button" class="gu-listo" id="<?= $uid ?>_listo">Listo</button>
  </div>
</div>

<script>
(function(){
  var R = document.getElementById('<?= $uid ?>');
  if (!R || R.dataset.listo) return;
  R.dataset.listo = '1';

  var val     = document.getElementById('<?= $uid ?>_val');
  var campo   = document.getElementById('<?= $uid ?>_campo');
  var q       = document.getElementById('<?= $uid ?>_q');
  var seg     = document.getElementById('<?= $uid ?>_seg');
  var dropbtn = document.getElementById('<?= $uid ?>_dropbtn');
  var dropTxt = document.getElementById('<?= $uid ?>_dropTxt');
  var menu    = document.getElementById('<?= $uid ?>_menu');
  var lista   = document.getElementById('<?= $uid ?>_lista');
  var vacio   = document.getElementById('<?= $uid ?>_vacio');
  var pie     = document.getElementById('<?= $uid ?>_pie');
  var listoBt = document.getElementById('<?= $uid ?>_listo');

  var filas  = Array.prototype.slice.call(R.querySelectorAll('.gu-fila'));
  var grupos = Array.prototype.slice.call(R.querySelectorAll('.gu-grupo'));

  var soloLibres = true;
  var cat = '';
  var ALTO_CERRADO = 28;   // = min-height de .gu-campo, para que no sobre ni falte
  // selección viva (varias unidades por deal = fusión)
  var sel = val.value.split(',').filter(function(x){ return x; });

  function datos(id){
    var f = filas.filter(function(x){ return x.dataset.id === id; })[0];
    return f ? {cod: f.dataset.codTxt, proy: f.dataset.proy} : {cod: '#' + id, proy: ''};
  }

  function pintarChips(){
    campo.innerHTML = '';
    if (!sel.length) {
      var ph = document.createElement('span');
      ph.className = 'gu-ph';
      ph.textContent = 'Elegir unidad…';
      campo.appendChild(ph);
    } else {
      sel.forEach(function(id){
        var d = datos(id);
        var c = document.createElement('span');
        c.className = 'gu-chip';
        c.innerHTML = '<span></span><small></small><b title="Quitar">&times;</b>';
        c.children[0].textContent = d.cod;
        c.children[1].textContent = d.proy;
        c.children[2].addEventListener('click', function(e){
          e.stopPropagation();
          sel = sel.filter(function(x){ return x !== id; });
          val.value = sel.join(',');
          pintarChips(); marcar(); ajustarIframe();
        });
        campo.appendChild(c);
      });
    }
    if (sel.length) {
      var mas = document.createElement('span');
      mas.className = 'gu-mas';
      mas.textContent = '+ agregar';
      campo.appendChild(mas);
    }
    var caret = document.createElement('span');
    caret.className = 'gu-caret';
    caret.innerHTML = '&#9660;';
    campo.appendChild(caret);
  }

  function marcar(){
    filas.forEach(function(f){
      var yo = sel.indexOf(f.dataset.id) !== -1;
      f.classList.toggle('gu-yo', yo);
      f.querySelector('.gu-tick').innerHTML = yo ? '&#10003;' : '';
      // una unidad ya elegida por ESTE deal siempre se puede des-seleccionar
      if (yo) f.classList.remove('gu-no');
    });
  }

  function ajustarIframe(){
    // cerrado: exactamente el alto del contenido. Si el iframe queda más alto que
    // el contenido, Bitrix lo alinea arriba y el texto se ve "un poco arriba".
    var alto = R.classList.contains('abierto')
      ? Math.min(document.documentElement.scrollHeight + 4, 340)
      : Math.max(ALTO_CERRADO, campo.offsetHeight);
    try {
      if (typeof BX24 !== 'undefined' && BX24.resizeWindow) {
        BX24.resizeWindow(document.documentElement.scrollWidth, alto);
      }
    } catch(e) {}
  }

  function filtrar(){
    var t = q.value.trim().toUpperCase();
    var n = 0;
    filas.forEach(function(f){
      var yo = sel.indexOf(f.dataset.id) !== -1;
      var ok = (!cat || f.dataset.cat === cat)
            && (!t || f.dataset.cod.indexOf(t) !== -1)
            && (!soloLibres || f.dataset.libre === '1' || yo);
      f.style.display = ok ? '' : 'none';
      if (ok) n++;
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
    pie.textContent = n + (n === 1 ? ' unidad' : ' unidades')
                    + (sel.length ? ' · ' + sel.length + ' elegida' + (sel.length > 1 ? 's' : '') : '');
    lista.scrollTop = 0;
  }

  function abrirMenu(si){ menu.classList.toggle('on', !!si); }

  function abrir(si){
    R.classList.toggle('abierto', si);
    if (!si) abrirMenu(false);
    if (si) filtrar();
    ajustarIframe();
    if (si) { try { q.focus(); } catch(e){} }
  }

  campo.addEventListener('click', function(){ abrir(!R.classList.contains('abierto')); });
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
    var f = e.target.closest('.gu-fila'); if (!f) return;
    var id = f.dataset.id;
    var yo = sel.indexOf(id) !== -1;
    if (!yo && f.dataset.libre !== '1') return;   // ocupada por otro deal: no seleccionable
    if (yo) sel = sel.filter(function(x){ return x !== id; });
    else    sel.push(id);
    val.value = sel.join(',');
    pintarChips(); marcar(); filtrar(); ajustarIframe();
  });

  document.addEventListener('click', function(){ abrirMenu(false); });

  pintarChips(); marcar();
  function iniciar(){ ajustarIframe(); }
  if (typeof BX24 !== 'undefined') { try { BX24.init(iniciar); } catch(e) { iniciar(); } }
  else { window.addEventListener('load', iniciar); setTimeout(iniciar, 600); }
})();
</script>
</div>
