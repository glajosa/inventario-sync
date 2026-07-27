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
<div class="gu-wrap" id="<?= $uid ?>">
<style>
  #<?= $uid ?> .gu-box{position:relative;font:13.5px/1.45 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}
  #<?= $uid ?> .gu-input{display:flex;align-items:center;gap:8px;padding:6px 9px;border:1px solid #c9ccd0;
      border-radius:6px;background:#fff;cursor:pointer;min-height:32px}
  #<?= $uid ?> .gu-input:hover{border-color:#2fc7f7}
  #<?= $uid ?> .gu-sel{font-weight:600;color:#1f2328}
  #<?= $uid ?> .gu-ph{color:#a8adb4}
  #<?= $uid ?> .gu-x{margin-left:auto;color:#a8adb4;padding:0 3px}
  #<?= $uid ?> .gu-x:hover{color:#cf222e}
  #<?= $uid ?> .gu-pop{display:none;position:absolute;z-index:9999;left:0;right:0;top:calc(100% + 4px);
      background:#fff;border:1px solid #d0d7de;border-radius:8px;box-shadow:0 8px 26px rgba(0,0,0,.16);
      padding:9px;min-width:430px}
  #<?= $uid ?> .gu-pop.on{display:block}
  #<?= $uid ?> .gu-q{width:100%;padding:6px 9px;border:1px solid #d0d7de;border-radius:6px;font:inherit;margin-bottom:7px}
  #<?= $uid ?> .gu-chips{display:flex;gap:4px;flex-wrap:wrap;margin-bottom:8px}
  #<?= $uid ?> .gu-chip{padding:3px 9px;border-radius:99px;border:1px solid #d0d7de;background:#f6f8fa;
      color:#57606a;font-size:11.5px;cursor:pointer;white-space:nowrap}
  #<?= $uid ?> .gu-chip[aria-pressed=true]{background:#0969da;border-color:#0969da;color:#fff}
  #<?= $uid ?> .gu-list{max-height:270px;overflow-y:auto}
  #<?= $uid ?> .gu-grp{font-size:11px;font-weight:700;color:#57606a;text-transform:uppercase;
      letter-spacing:.04em;padding:7px 3px 4px;position:sticky;top:0;background:#fff}
  #<?= $uid ?> .gu-u{display:flex;align-items:center;gap:8px;padding:5px 7px;border-radius:6px;cursor:pointer}
  #<?= $uid ?> .gu-u:hover{background:#f0f6ff}
  #<?= $uid ?> .gu-u.no{opacity:.45;cursor:not-allowed;background:transparent}
  #<?= $uid ?> .gu-cod{font-weight:600;min-width:64px}
  #<?= $uid ?> .gu-meta{color:#8b949e;font-size:11.5px}
  #<?= $uid ?> .gu-pvp{margin-left:auto;font-variant-numeric:tabular-nums;font-size:12px;color:#57606a}
  #<?= $uid ?> .gu-b{font-size:9.5px;font-weight:700;padding:1px 5px;border-radius:99px;text-transform:uppercase}
  #<?= $uid ?> .gu-b.DISPONIBLE{background:#dafbe1;color:#1a7f37}
  #<?= $uid ?> .gu-b.RESERVADO{background:#fff8c5;color:#9a6700}
  #<?= $uid ?> .gu-b.FIRMADO{background:#ddf4ff;color:#0969da}
  #<?= $uid ?> .gu-b.VENDIDO{background:#fbefff;color:#8250df}
  #<?= $uid ?> .gu-b.BLOQUEADO,#<?= $uid ?> .gu-b.PERDIDO{background:#eaeef2;color:#6e7781}
  #<?= $uid ?> .gu-nada{color:#8b949e;padding:12px 4px;text-align:center}
</style>

<div class="gu-box">
  <input type="hidden" name="<?= h($name) ?>" id="<?= $uid ?>_val" value="<?= h($value) ?>">
  <div class="gu-input" id="<?= $uid ?>_btn">
    <span id="<?= $uid ?>_lbl" class="<?= $sel ? 'gu-sel' : 'gu-ph' ?>">
      <?= $sel ? h($sel['codigo'] . ' (' . ($proys[(string)$sel['cat']] ?? '') . ')') : 'Elegir unidad…' ?>
    </span>
    <span class="gu-x" id="<?= $uid ?>_clr" style="<?= $sel ? '' : 'display:none' ?>">✕</span>
  </div>

  <div class="gu-pop" id="<?= $uid ?>_pop">
    <input type="text" class="gu-q" id="<?= $uid ?>_q" placeholder="Buscar código… (I-4-5, J-3, A-1)" autocomplete="off">
    <div class="gu-chips" id="<?= $uid ?>_chips">
      <button type="button" class="gu-chip" data-cat="" aria-pressed="true">Todos</button>
      <?php foreach ($proys as $cid => $nom): ?>
        <button type="button" class="gu-chip" data-cat="<?= h((string)$cid) ?>" aria-pressed="false"><?= h($nom) ?></button>
      <?php endforeach; ?>
    </div>
    <div class="gu-list" id="<?= $uid ?>_list">
      <?php foreach ($proys as $cid => $nom):
            $lista = $porProyecto[(string)$cid] ?? [];
            if (!$lista) continue; ?>
        <div class="gu-grp" data-cat="<?= h((string)$cid) ?>"><?= h($nom) ?></div>
        <?php foreach ($lista as $u):
              $libre = ($u['stage'] === 'DISPONIBLE' && empty($u['dealId']));
              $meta  = trim(($u['torre'] !== '' ? 'T' . $u['torre'] : '') . ($u['piso'] !== '' ? ' P' . $u['piso'] : ''));
              $pvp   = $u['pvp'] !== '' ? '$' . number_format((float)str_replace(['|USD', ','], '', $u['pvp']), 0) : '';
        ?>
          <div class="gu-u <?= $libre ? '' : 'no' ?>" data-cat="<?= h((string)$cid) ?>"
               data-cod="<?= h(strtoupper($u['codigo'])) ?>" data-libre="<?= $libre ? 1 : 0 ?>"
               data-id="<?= (int)$u['id'] ?>" data-lbl="<?= h($u['codigo'] . ' (' . $nom . ')') ?>">
            <span class="gu-cod"><?= h($u['codigo']) ?></span>
            <span class="gu-b <?= h($u['stage'] ?: 'BLOQUEADO') ?>"><?= h($u['stage'] ?: '—') ?></span>
            <?php if ($meta !== ''): ?><span class="gu-meta"><?= h($meta) ?></span><?php endif; ?>
            <?php if ($pvp !== ''): ?><span class="gu-pvp"><?= h($pvp) ?></span><?php endif; ?>
          </div>
        <?php endforeach; ?>
      <?php endforeach; ?>
      <div class="gu-nada" id="<?= $uid ?>_nada" style="display:none">Sin resultados</div>
    </div>
  </div>
</div>

<script>
(function(){
  var R = document.getElementById('<?= $uid ?>');
  if (!R || R.dataset.listo) return;
  R.dataset.listo = '1';

  var val   = document.getElementById('<?= $uid ?>_val');
  var btn   = document.getElementById('<?= $uid ?>_btn');
  var lbl   = document.getElementById('<?= $uid ?>_lbl');
  var clr   = document.getElementById('<?= $uid ?>_clr');
  var pop   = document.getElementById('<?= $uid ?>_pop');
  var q     = document.getElementById('<?= $uid ?>_q');
  var chips = document.getElementById('<?= $uid ?>_chips');
  var nada  = document.getElementById('<?= $uid ?>_nada');
  var cat   = '';

  function filtrar(){
    var t = q.value.trim().toUpperCase(), vistos = 0;
    R.querySelectorAll('.gu-u').forEach(function(u){
      var ok = (!cat || u.dataset.cat === cat) && (!t || u.dataset.cod.indexOf(t) !== -1);
      u.style.display = ok ? '' : 'none';
      if (ok) vistos++;
    });
    // los títulos de proyecto se ocultan si su grupo quedó vacío
    R.querySelectorAll('.gu-grp').forEach(function(g){
      var hay = false, n = g.nextElementSibling;
      while (n && n.classList.contains('gu-u')) { if (n.style.display !== 'none') { hay = true; break; } n = n.nextElementSibling; }
      g.style.display = hay ? '' : 'none';
    });
    nada.style.display = vistos ? 'none' : '';
  }

  function abrir(si){
    pop.classList.toggle('on', si);
    if (si) { q.value = ''; filtrar(); q.focus(); }
  }

  btn.addEventListener('click', function(e){ e.stopPropagation(); abrir(!pop.classList.contains('on')); });
  q.addEventListener('input', filtrar);
  pop.addEventListener('click', function(e){ e.stopPropagation(); });

  chips.addEventListener('click', function(e){
    var c = e.target.closest('.gu-chip'); if (!c) return;
    cat = c.dataset.cat;
    chips.querySelectorAll('.gu-chip').forEach(function(x){ x.setAttribute('aria-pressed', String(x === c)); });
    filtrar();
  });

  R.querySelector('.gu-list').addEventListener('click', function(e){
    var u = e.target.closest('.gu-u'); if (!u) return;
    if (u.dataset.libre !== '1') return;            // ocupada: no se puede elegir
    val.value = u.dataset.id;
    lbl.textContent = u.dataset.lbl;
    lbl.className = 'gu-sel';
    clr.style.display = '';
    abrir(false);
  });

  clr.addEventListener('click', function(e){
    e.stopPropagation();
    val.value = '';
    lbl.textContent = 'Elegir unidad…';
    lbl.className = 'gu-ph';
    clr.style.display = 'none';
  });

  document.addEventListener('click', function(){ abrir(false); });
})();
</script>
</div>
