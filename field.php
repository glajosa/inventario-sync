<?php
/**
 * field.php — handler del tipo de campo propio "Unidad de Inventario".
 * ---------------------------------------------------------------------------
 * Bitrix llama aquí DESDE SU SERVIDOR (no es iframe) y pega el HTML devuelto
 * dentro del formulario del deal. Por eso el valor puede viajar en un <input>
 * normal con el nombre que Bitrix indica, sin problemas de dominios cruzados.
 *
 * Modos que envía Bitrix en `mode`:
 *   view      -> cómo se ve el valor guardado
 *   edit      -> el selector (lo interesante)
 *   settings  -> ajustes del campo al crearlo (no usamos ninguno)
 *
 * El catálogo se lee del caché que ya arma selector.php (1.274 unidades,
 * agrupadas por proyecto, con estado y quién las ocupa).
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

$DATA_DIR = getenv('DATA_DIR') ?: '/data';

$mode  = (string)($_REQUEST['mode'] ?? 'edit');
$value = (string)($_REQUEST['value'] ?? '');
$campo = $_REQUEST['field'] ?? [];
// nombre del input que Bitrix espera recibir de vuelta al guardar
$name  = (string)($campo['NAME'] ?? $_REQUEST['name'] ?? 'UF_UNIDAD');

/** Unidades desde el caché de selector.php (no vuelve a pegarle al API). */
function catalogo_cache(): array {
    $j = json_decode((string)@file_get_contents((getenv('DATA_DIR') ?: '/data') . '/selector_cache.json'), true);
    return is_array($j) ? $j : ['units' => [], 'proyectos' => []];
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$cat   = catalogo_cache();
$units = $cat['units'] ?? [];
$proys = $cat['proyectos'] ?? [];

// ---- settings: el campo no necesita configuración ---------------------------
if ($mode === 'settings') { echo ''; exit; }

// ---- view: mostrar la unidad guardada ---------------------------------------
if ($mode === 'view') {
    if ($value === '' || $value === '0') { echo '<span style="color:#8b949e">—</span>'; exit; }
    foreach ($units as $u) {
        if ((string)$u['id'] === $value) {
            $proy = $proys[(string)$u['cat']] ?? '';
            echo '<span>' . h($u['codigo']) . ' <span style="color:#8b949e">(' . h($proy) . ')</span></span>';
            exit;
        }
    }
    echo '<span>#' . h($value) . '</span>';
    exit;
}

// ---- edit: el selector ------------------------------------------------------
$porProyecto = [];
foreach ($units as $u) $porProyecto[(string)$u['cat']][] = $u;
foreach ($porProyecto as &$l) usort($l, fn($a, $b) => strnatcasecmp($a['codigo'], $b['codigo']));
unset($l);

$sel = null;
foreach ($units as $u) if ((string)$u['id'] === $value) { $sel = $u; break; }
$uid = 'gu' . bin2hex(random_bytes(4));   // ids únicos: puede haber varios campos en el form
?>
<div class="gu" id="<?= $uid ?>">
<style>
  /* El campo debe verse como cualquier otro del deal: UNA sola línea.
     El desplegable va con position:fixed (no absolute) porque el formulario
     recorta lo que se desborda — con fixed se dibuja encima, como los
     selectores nativos de Bitrix, y no reserva espacio en el formulario. */
  #<?= $uid ?>{font:13.5px/1.4 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:#1f2328}
  #<?= $uid ?> *{box-sizing:border-box}
  #<?= $uid ?> .gu-campo{display:flex;align-items:center;gap:8px;padding:0 8px 0 0;min-height:26px;
      cursor:pointer;border-bottom:1px solid transparent}
  #<?= $uid ?> .gu-campo:hover{border-bottom-color:#c9ccd0}
  #<?= $uid ?> .gu-val{font-weight:600}
  #<?= $uid ?> .gu-ph{color:#a8adb4}
  #<?= $uid ?> .gu-caret{margin-left:auto;color:#a8adb4;font-size:9px}
  #<?= $uid ?> .gu-quitar{color:#a8adb4;font-size:15px;line-height:1;padding:0 2px}
  #<?= $uid ?> .gu-quitar:hover{color:#cf222e}

  /* panel flotante */
  .gu-flotante{position:fixed;z-index:10000;background:#fff;border:1px solid #d0d7de;
      border-radius:8px;box-shadow:0 12px 32px rgba(27,31,36,.22);overflow:hidden;
      font:13.5px/1.4 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:#1f2328}
  .gu-flotante *{box-sizing:border-box}
  .gu-flotante .gu-top{display:flex;gap:6px;padding:8px;border-bottom:1px solid #eaeef2;background:#fff}
  .gu-flotante .gu-buscar{flex:1 1 auto;min-width:0;padding:6px 9px;border:1px solid #d0d7de;
      border-radius:6px;font:inherit}
  .gu-flotante .gu-buscar:focus{outline:2px solid #0969da;outline-offset:-1px}
  .gu-flotante .gu-proy{flex:0 0 auto;max-width:46%;padding:6px 7px;border:1px solid #d0d7de;
      border-radius:6px;font:inherit;background:#fff;cursor:pointer}
  .gu-flotante .gu-lista{max-height:260px;overflow-y:auto;overscroll-behavior:contain}
  /* encabezado NO sticky: el sticky se montaba encima de las filas */
  .gu-flotante .gu-grupo{padding:7px 10px 3px;font-size:10.5px;font-weight:700;color:#8b949e;
      letter-spacing:.05em;text-transform:uppercase;background:#f6f8fa;border-top:1px solid #eaeef2}
  .gu-flotante .gu-grupo:first-child{border-top:0}
  .gu-flotante .gu-fila{display:flex;align-items:center;gap:8px;padding:7px 10px;cursor:pointer;
      border-top:1px solid #f2f4f7}
  .gu-flotante .gu-fila:hover{background:#f0f6ff}
  .gu-flotante .gu-fila.gu-no{cursor:not-allowed;background:#fcfcfd}
  .gu-flotante .gu-fila.gu-no .gu-cod,.gu-flotante .gu-fila.gu-no .gu-precio{color:#a8adb4}
  .gu-flotante .gu-cod{font-weight:600;min-width:58px;flex:0 0 auto}
  .gu-flotante .gu-tag{flex:0 0 auto;font-size:9.5px;font-weight:700;padding:2px 6px;border-radius:99px}
  .gu-flotante .gu-tag.DISPONIBLE{background:#dafbe1;color:#116329}
  .gu-flotante .gu-tag.RESERVADO{background:#fff8c5;color:#7d4e00}
  .gu-flotante .gu-tag.FIRMADO{background:#ddf4ff;color:#0a3069}
  .gu-flotante .gu-tag.VENDIDO{background:#fbefff;color:#6639ba}
  .gu-flotante .gu-tag.BLOQUEADO,.gu-flotante .gu-tag.PERDIDO{background:#eaeef2;color:#57606a}
  .gu-flotante .gu-meta{flex:0 1 auto;color:#8b949e;font-size:11px;white-space:nowrap;
      overflow:hidden;text-overflow:ellipsis}
  .gu-flotante .gu-precio{margin-left:auto;flex:0 0 auto;font-variant-numeric:tabular-nums;
      font-size:12px;color:#57606a}
  .gu-flotante .gu-vacio{padding:16px 10px;text-align:center;color:#8b949e}
  .gu-flotante .gu-pie{padding:6px 10px;border-top:1px solid #eaeef2;color:#8b949e;font-size:11px;background:#fff}
</style>

<input type="hidden" name="<?= h($name) ?>" id="<?= $uid ?>_val" value="<?= h($value) ?>">

<div class="gu-campo" id="<?= $uid ?>_btn">
  <span id="<?= $uid ?>_lbl" class="<?= $sel ? 'gu-val' : 'gu-ph' ?>">
    <?= $sel ? h($sel['codigo'] . ' — ' . ($proys[(string)$sel['cat']] ?? '')) : 'Elegir unidad…' ?>
  </span>
  <span class="gu-quitar" id="<?= $uid ?>_clr" style="<?= $sel ? '' : 'display:none' ?>" title="Quitar">&times;</span>
  <span class="gu-caret">&#9660;</span>
</div>

<template id="<?= $uid ?>_tpl">
  <div class="gu-top">
    <input type="text" class="gu-buscar" placeholder="Buscar código…" autocomplete="off">
    <select class="gu-proy">
      <option value="LIBRES">Disponibles</option>
      <option value="">Todos</option>
      <?php foreach ($proys as $cid => $nom): ?>
        <option value="<?= h((string)$cid) ?>"><?= h($nom) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="gu-lista">
    <?php foreach ($proys as $cid => $nom):
          $lista = $porProyecto[(string)$cid] ?? [];
          if (!$lista) continue; ?>
      <div class="gu-grupo" data-cat="<?= h((string)$cid) ?>"><?= h($nom) ?></div>
      <?php foreach ($lista as $u):
            $libre = ($u['stage'] === 'DISPONIBLE' && empty($u['dealId']));
            $meta  = trim(($u['torre'] !== '' ? 'T' . $u['torre'] : '')
                        . ($u['piso']  !== '' ? ' · P' . $u['piso'] : ''));
            $pvp   = $u['pvp'] !== '' ? '$' . number_format((float)str_replace(['|USD', ','], '', $u['pvp']), 0) : '';
            $est   = $u['stage'] ?: 'BLOQUEADO';
      ?>
        <div class="gu-fila <?= $libre ? '' : 'gu-no' ?>" data-cat="<?= h((string)$cid) ?>"
             data-cod="<?= h(strtoupper($u['codigo'])) ?>" data-libre="<?= $libre ? 1 : 0 ?>"
             data-id="<?= (int)$u['id'] ?>" data-lbl="<?= h($u['codigo'] . ' — ' . $nom) ?>">
          <span class="gu-cod"><?= h($u['codigo']) ?></span>
          <span class="gu-tag <?= h($est) ?>"><?= h($est) ?></span>
          <?php if ($meta !== ''): ?><span class="gu-meta"><?= h($meta) ?></span><?php endif; ?>
          <?php if ($pvp !== ''): ?><span class="gu-precio"><?= h($pvp) ?></span><?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php endforeach; ?>
    <div class="gu-vacio" style="display:none">Sin resultados</div>
  </div>
  <div class="gu-pie"></div>
</template>

<script>
(function(){
  var R = document.getElementById('<?= $uid ?>');
  if (!R || R.dataset.listo) return;
  R.dataset.listo = '1';

  var val = document.getElementById('<?= $uid ?>_val');
  var btn = document.getElementById('<?= $uid ?>_btn');
  var lbl = document.getElementById('<?= $uid ?>_lbl');
  var clr = document.getElementById('<?= $uid ?>_clr');
  var tpl = document.getElementById('<?= $uid ?>_tpl');
  var pop = null;

  // Bitrix reserva un alto grande al contenedor de los campos propios; se
  // devuelve a alto natural para que ocupe una línea como los demás campos.
  (function compactar(){
    var n = R.parentElement, i = 0;
    while (n && i++ < 3) {
      if (n.offsetHeight > 60) { n.style.height = 'auto'; n.style.minHeight = '0'; }
      n = n.parentElement;
    }
  })();

  function ubicar(){
    if (!pop) return;
    var r = btn.getBoundingClientRect();
    var ancho = Math.max(r.width, 380);
    ancho = Math.min(ancho, window.innerWidth - 16);
    pop.style.width = ancho + 'px';
    var alto = pop.offsetHeight || 320;
    var izq = Math.min(r.left, window.innerWidth - ancho - 8);
    pop.style.left = Math.max(8, izq) + 'px';
    // si no cabe abajo, se abre hacia arriba
    var abajo = window.innerHeight - r.bottom;
    pop.style.top = (abajo < alto + 12 && r.top > alto + 12)
      ? (r.top - alto - 4) + 'px'
      : (r.bottom + 4) + 'px';
  }

  function filtrar(){
    var q    = pop.querySelector('.gu-buscar');
    var proy = pop.querySelector('.gu-proy');
    var t = q.value.trim().toUpperCase();
    var p = proy.value;
    var soloLibres = (p === 'LIBRES');
    var cat = (p === 'LIBRES' || p === '') ? '' : p;
    var n = 0;

    pop.querySelectorAll('.gu-fila').forEach(function(f){
      var ok = (!cat || f.dataset.cat === cat)
            && (!t || f.dataset.cod.indexOf(t) !== -1)
            && (!soloLibres || f.dataset.libre === '1');
      f.style.display = ok ? '' : 'none';
      if (ok) n++;
    });

    // el nombre del proyecto sobra cuando ya se filtró a uno solo
    pop.querySelectorAll('.gu-grupo').forEach(function(g){
      var hay = false, node = g.nextElementSibling;
      while (node && node.classList.contains('gu-fila')) {
        if (node.style.display !== 'none') { hay = true; break; }
        node = node.nextElementSibling;
      }
      g.style.display = (hay && !cat) ? '' : 'none';
    });

    pop.querySelector('.gu-vacio').style.display = n ? 'none' : '';
    pop.querySelector('.gu-pie').textContent = n + (n === 1 ? ' unidad' : ' unidades');
    pop.querySelector('.gu-lista').scrollTop = 0;
    ubicar();
  }

  function cerrar(){
    if (!pop) return;
    pop.remove(); pop = null;
    document.removeEventListener('mousedown', fueraClick, true);
    window.removeEventListener('scroll', ubicar, true);
    window.removeEventListener('resize', ubicar);
  }

  function fueraClick(e){
    if (pop && !pop.contains(e.target) && !btn.contains(e.target)) cerrar();
  }

  function abrir(){
    if (pop) { cerrar(); return; }
    pop = document.createElement('div');
    pop.className = 'gu-flotante';
    pop.appendChild(tpl.content.cloneNode(true));
    document.body.appendChild(pop);   // al body: así ningún contenedor lo recorta

    pop.querySelector('.gu-buscar').addEventListener('input', filtrar);
    pop.querySelector('.gu-proy').addEventListener('change', filtrar);
    pop.querySelector('.gu-buscar').addEventListener('keydown', function(e){
      if (e.key === 'Enter') { e.preventDefault(); e.stopPropagation(); }
      if (e.key === 'Escape') { cerrar(); }
    });
    pop.querySelector('.gu-lista').addEventListener('click', function(e){
      var f = e.target.closest('.gu-fila');
      if (!f || f.dataset.libre !== '1') return;   // ocupada: no seleccionable
      val.value = f.dataset.id;
      lbl.textContent = f.dataset.lbl;
      lbl.className = 'gu-val';
      clr.style.display = '';
      cerrar();
    });

    filtrar();
    pop.querySelector('.gu-buscar').focus();
    document.addEventListener('mousedown', fueraClick, true);
    window.addEventListener('scroll', ubicar, true);
    window.addEventListener('resize', ubicar);
  }

  btn.addEventListener('click', function(e){
    if (e.target === clr) return;
    abrir();
  });

  clr.addEventListener('click', function(e){
    e.stopPropagation();
    val.value = '';
    lbl.textContent = 'Elegir unidad…';
    lbl.className = 'gu-ph';
    clr.style.display = 'none';
    cerrar();
  });
})();
</script>
</div>
