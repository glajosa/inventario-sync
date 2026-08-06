<?php
/**
 * llamada_campo.php — tipo de campo propio "Registrar llamada" (galjosa_llamada).
 * ---------------------------------------------------------------------------
 * Mismo patrón que field.php (Inventario): Bitrix mete este HTML en un iframe
 * de 200px fijos dentro del formulario del deal. Cerrado se pide
 * BX24.resizeWindow a una línea chica; al hacer clic se abre y se pide un
 * alto fijo mayor — el calendario aparece AHÍ MISMO, en el propio formulario,
 * sin ningún panel/marco grande de Bitrix (eso es lo que da la placement
 * CRM_DEAL_DETAIL_ACTIVITY, y no se puede achicar desde adentro).
 *
 * No guarda ningún valor real: es un campo de ACCIÓN, no de dato. El VALUE
 * del campo se deja siempre vacío a propósito.
 *
 * La actividad que crea es la misma forma ya verificada (ver memoria
 * reference_galjosa_actividad_llamada_shape): SUBJECT="1234" si contestó
 * (así lo cuenta el dashboard), o el texto que Bitrix pondría solo si no.
 * Todo corre client-side con BX24 — el usuario logueado llama al API, no el
 * servidor.
 * ---------------------------------------------------------------------------
 */
declare(strict_types=1);

$opciones = [];
if (!empty($_REQUEST['PLACEMENT_OPTIONS'])) {
    $tmp = json_decode((string)$_REQUEST['PLACEMENT_OPTIONS'], true);
    if (is_array($tmp)) $opciones = $tmp;
}

$mode   = (string)($opciones['MODE'] ?? $_REQUEST['mode'] ?? 'edit');
$dealId = (int)($opciones['ENTITY_VALUE_ID'] ?? $_REQUEST['deal'] ?? 0);

// settings: el campo no tiene configuración.
if ($mode === 'settings') { echo ''; exit; }

$uid = 'gl' . substr(md5((string)$dealId . microtime()), 0, 8);
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<script src="//api.bitrix24.com/api/v1/"></script>
<style>
  * { box-sizing: border-box; }
  html, body {
    margin: 0; padding: 0; background: transparent; overflow: hidden;
    font: 13px/1.4 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    color: #1f2328;
  }
  #<?= $uid ?>_cerrado {
    height: 30px; display: flex; align-items: center; gap: 6px;
    padding: 0 8px; cursor: pointer; color: #444; white-space: nowrap;
  }
  #<?= $uid ?>_cerrado:hover { color: #2f6fed; }
  #<?= $uid ?>_cerrado svg { flex-shrink: 0; }

  #<?= $uid ?>_abierto { display: none; padding: 10px 12px 12px; }
  #<?= $uid ?>_abierto.on { display: block; }

  h3 { margin: 0 0 10px; font-size: 13px; font-weight: 600; text-align: center; }

  .mes-nav { display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px; }
  .mes-nav span { font-size: 12px; font-weight: 600; text-transform: capitalize; }
  .mes-nav button {
    border: none; background: #f0f2f5; width: 24px; height: 24px; border-radius: 6px;
    cursor: pointer; font-size: 13px; color: #444; line-height: 1;
  }
  .mes-nav button:hover { background: #e1e4e8; }

  .dow { display: grid; grid-template-columns: repeat(7, 1fr); text-align: center; margin-bottom: 3px; }
  .dow span { font-size: 9px; color: #8a919c; font-weight: 600; }

  .grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 2px; margin-bottom: 10px; }
  .dia {
    aspect-ratio: 1; display: flex; align-items: center; justify-content: center;
    border-radius: 6px; cursor: pointer; font-size: 11px; user-select: none;
  }
  .dia:hover { background: #eef1f4; }
  .dia.fuera { visibility: hidden; cursor: default; }
  .dia.hoy { border: 1.5px solid #2f6fed; color: #2f6fed; font-weight: 600; }
  .dia.pasado { color: #c4c9d0; cursor: default; }
  .dia.pasado:hover { background: none; }
  .dia.sel { background: #2f6fed; color: #fff; font-weight: 600; }
  .dia.sel.hoy { border-color: #2f6fed; }

  .hora-fila { display: flex; align-items: center; gap: 6px; margin-bottom: 8px; }
  .hora-fila label { font-size: 10px; color: #57606a; white-space: nowrap; }
  .hora-fila input[type="time"] {
    flex: 1; padding: 5px 6px; border: 1px solid #d0d7de; border-radius: 6px; font-size: 12px;
  }
  .chips-hora { display: flex; gap: 5px; margin-bottom: 10px; }
  .chip-hora {
    flex: 1; text-align: center; padding: 4px 3px; border: 1px solid #d0d7de; border-radius: 6px;
    background: #f6f8fa; cursor: pointer; font-size: 10px; color: #444;
  }
  .chip-hora:hover { background: #eef1f4; }

  #<?= $uid ?>_accion { display: none; }
  #<?= $uid ?>_accion.on { display: block; }
  .fecha-elegida {
    text-align: center; font-size: 10px; color: #57606a; margin-bottom: 8px;
    padding-top: 8px; border-top: 1px solid #eef1f4;
  }
  .fecha-elegida b { color: #1f2328; }
  .botones { display: flex; gap: 6px; }
  .btn {
    flex: 1; padding: 9px; border: none; border-radius: 6px; font-size: 12px;
    font-weight: 600; cursor: pointer; color: #fff;
  }
  .btn.no { background: #cf222e; }
  .btn.no:hover { background: #a40e26; }
  .btn.si { background: #1a7f37; }
  .btn.si:hover { background: #116329; }
  .btn:disabled { opacity: .5; cursor: default; }

  .pista { text-align: center; font-size: 10px; color: #8a919c; padding: 2px 0 0; }
  #<?= $uid ?>_cerrar {
    text-align: center; font-size: 10px; color: #8a919c; cursor: pointer; padding-top: 8px;
  }
  #<?= $uid ?>_cerrar:hover { color: #57606a; }
  #<?= $uid ?>_estado { margin-top: 8px; font-size: 11px; text-align: center; min-height: 14px; }
  #<?= $uid ?>_estado.ok { color: #1a7f37; font-weight: 600; }
  #<?= $uid ?>_estado.err { color: #cf222e; font-weight: 600; }
</style>
</head>
<body>

<div id="<?= $uid ?>_cerrado">
  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#2f6fed" stroke-width="2">
    <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/>
  </svg>
  <span>Registrar llamada</span>
</div>

<div id="<?= $uid ?>_abierto">

  <h3>¿Cuándo lo vuelvo a llamar?</h3>

  <div class="mes-nav">
    <button id="<?= $uid ?>_mesAnt" type="button">‹</button>
    <span id="<?= $uid ?>_mesLabel"></span>
    <button id="<?= $uid ?>_mesSig" type="button">›</button>
  </div>
  <div class="dow">
    <span>L</span><span>M</span><span>M</span><span>J</span><span>V</span><span>S</span><span>D</span>
  </div>
  <div class="grid" id="<?= $uid ?>_grid"></div>

  <div class="hora-fila">
    <label>Hora</label>
    <input type="time" id="<?= $uid ?>_hora">
  </div>
  <div class="chips-hora">
    <div class="chip-hora" data-min="0">Ahora</div>
    <div class="chip-hora" data-min="15">+15m</div>
    <div class="chip-hora" data-min="30">+30m</div>
    <div class="chip-hora" data-min="60">+1h</div>
  </div>

  <div id="<?= $uid ?>_accion">
    <div class="fecha-elegida">Vuelvo a llamar el <b id="<?= $uid ?>_fechaTxt">—</b></div>
    <div class="botones">
      <button class="btn no" id="<?= $uid ?>_btnNo" disabled>No contestó</button>
      <button class="btn si" id="<?= $uid ?>_btnSi" disabled>Sí, contestó</button>
    </div>
  </div>
  <div class="pista" id="<?= $uid ?>_pista">Elegí un día en el calendario ↑</div>

  <div id="<?= $uid ?>_estado"></div>
  <div id="<?= $uid ?>_cerrar">Cerrar</div>

</div>

<script>
(function () {
  var uid = '<?= $uid ?>';
  var dealId = <?= $dealId ?: 0 ?>;
  var vista = new Date();
  var seleccionada = null;
  var MESES = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];

  function el(id) { return document.getElementById(uid + id); }
  function pad(n) { return n < 10 ? '0' + n : '' + n; }

  function ajustarIframe(abierto) {
    var ancho = 300;
    var alto = abierto ? 480 : 30;
    try {
      if (typeof BX24 !== 'undefined' && BX24.resizeWindow) BX24.resizeWindow(ancho, alto);
    } catch (e) {}
  }

  function abrir() {
    el('_cerrado').style.display = 'none';
    el('_abierto').classList.add('on');
    ajustarIframe(true);
  }
  function cerrar() {
    el('_abierto').classList.remove('on');
    el('_cerrado').style.display = 'flex';
    ajustarIframe(false);
  }
  el('_cerrado').addEventListener('click', abrir);
  el('_cerrar').addEventListener('click', cerrar);

  function hoy() { var d = new Date(); return { y: d.getFullYear(), m: d.getMonth(), d: d.getDate() }; }
  function esHoy(y, m, d) { var h = hoy(); return y === h.y && m === h.m && d === h.d; }
  function esPasado(y, m, d) {
    var h = hoy();
    return (y < h.y) || (y === h.y && m < h.m) || (y === h.y && m === h.m && d < h.d);
  }

  function pintarMes() {
    var y = vista.getFullYear(), m = vista.getMonth();
    el('_mesLabel').textContent = MESES[m] + ' ' + y;

    var primerDia = new Date(y, m, 1).getDay();
    var offset = (primerDia + 6) % 7;
    var totalDias = new Date(y, m + 1, 0).getDate();

    var grid = el('_grid');
    grid.innerHTML = '';
    for (var i = 0; i < offset; i++) {
      var vacio = document.createElement('div');
      vacio.className = 'dia fuera';
      grid.appendChild(vacio);
    }
    for (var d = 1; d <= totalDias; d++) {
      var celda = document.createElement('div');
      celda.className = 'dia';
      celda.textContent = d;
      var pasado = esPasado(y, m, d);
      if (esHoy(y, m, d)) celda.classList.add('hoy');
      if (pasado) celda.classList.add('pasado');
      if (seleccionada && seleccionada.y === y && seleccionada.m === m && seleccionada.d === d) celda.classList.add('sel');
      if (!pasado) {
        celda.addEventListener('click', (function (dd) {
          return function () { elegirDia(y, m, dd); };
        })(d));
      }
      grid.appendChild(celda);
    }
  }

  function elegirDia(y, m, d) {
    seleccionada = { y: y, m: m, d: d };
    pintarMes();
    el('_fechaTxt').textContent = d + ' de ' + MESES[m] + (esHoy(y, m, d) ? ' (hoy)' : '');
    el('_accion').classList.add('on');
    el('_pista').style.display = 'none';
    el('_btnSi').disabled = false;
    el('_btnNo').disabled = false;
  }

  el('_mesAnt').addEventListener('click', function () { vista.setMonth(vista.getMonth() - 1); pintarMes(); });
  el('_mesSig').addEventListener('click', function () { vista.setMonth(vista.getMonth() + 1); pintarMes(); });

  function horaActual() {
    var d = new Date();
    el('_hora').value = pad(d.getHours()) + ':' + pad(d.getMinutes());
  }
  Array.prototype.forEach.call(document.querySelectorAll('#' + uid + '_abierto .chip-hora'), function (c) {
    c.addEventListener('click', function () {
      var min = parseInt(c.getAttribute('data-min'), 10);
      var d = new Date();
      d.setMinutes(d.getMinutes() + min);
      el('_hora').value = pad(d.getHours()) + ':' + pad(d.getMinutes());
    });
  });

  function estado(msg, cls) {
    var e = el('_estado');
    e.textContent = msg;
    e.className = cls || '';
  }
  function botones(activos) {
    el('_btnSi').disabled = !activos;
    el('_btnNo').disabled = !activos;
  }

  function isoConOffsetEcuador() {
    if (!seleccionada) return null;
    var h = el('_hora').value;
    if (!h) return null;
    var f = seleccionada.y + '-' + pad(seleccionada.m + 1) + '-' + pad(seleccionada.d);
    return f + 'T' + h + ':00-05:00';
  }
  function sumarHora(iso) {
    var m = iso.match(/^(\d{4}-\d{2}-\d{2})T(\d{2}):(\d{2}):00(-05:00)$/);
    if (!m) return iso;
    var hh = (parseInt(m[2], 10) + 1) % 24;
    return m[1] + 'T' + pad(hh) + ':' + m[3] + ':00' + m[4];
  }

  function registrar(contesto) {
    var inicio = isoConOffsetEcuador();
    if (!inicio) { estado('Elegí día y hora.', 'err'); return; }
    botones(false);
    estado('Guardando…');

    BX24.callMethod('crm.deal.get', { id: dealId }, function (rd) {
      if (rd.error()) { estado('Error leyendo el deal: ' + rd.error(), 'err'); botones(true); return; }
      var deal = rd.data();
      var contactId = deal.CONTACT_ID;
      var responsable = deal.ASSIGNED_BY_ID;

      function crearActividad(nombre, telefono) {
        var subject = contesto ? '1234' : ('Llamada saliente ' + (nombre || 'cliente'));
        var fin = sumarHora(inicio);
        var fields = {
          OWNER_TYPE_ID: 2, OWNER_ID: dealId,
          TYPE_ID: 2, DIRECTION: 2,
          PROVIDER_ID: 'VOXIMPLANT_CALL', PROVIDER_TYPE_ID: 'CALL',
          SUBJECT: subject,
          COMPLETED: 'N', RESPONSIBLE_ID: responsable,
          START_TIME: inicio, END_TIME: fin, DEADLINE: inicio,
          PRIORITY: 2, NOTIFY_TYPE: 1, NOTIFY_VALUE: 15,
          DESCRIPTION_TYPE: 1
        };
        if (contactId && telefono) {
          fields.COMMUNICATIONS = [{ VALUE: telefono, ENTITY_ID: contactId, ENTITY_TYPE_ID: 3, TYPE: 'PHONE' }];
        }
        BX24.callMethod('crm.activity.add', { fields: fields }, function (ra) {
          if (ra.error()) { estado('Error creando la actividad: ' + ra.error(), 'err'); botones(true); return; }
          estado('Guardado ✓', 'ok');
          setTimeout(cerrar, 900);
        });
      }

      if (!contactId || parseInt(contactId, 10) <= 0) { crearActividad(null, null); return; }
      BX24.callMethod('crm.contact.get', { id: contactId }, function (rc) {
        var nombre = null, tel = null;
        if (!rc.error()) {
          var c = rc.data();
          nombre = [c.NAME, c.LAST_NAME].filter(Boolean).join(' ').trim() || null;
          tel = (c.PHONE && c.PHONE[0] && c.PHONE[0].VALUE) || null;
        }
        crearActividad(nombre, tel);
      });
    });
  }

  el('_btnNo').addEventListener('click', function () { registrar(false); });
  el('_btnSi').addEventListener('click', function () { registrar(true); });

  pintarMes();
  horaActual();
  ajustarIframe(false);

  try {
    BX24.init(function () {
      var opt = BX24.placement.info().options || {};
      if (!dealId) dealId = parseInt(opt.ENTITY_VALUE_ID || opt.ID || 0, 10);
    });
  } catch (e) {}
})();
</script>

</body>
</html>
