<?php
/**
 * llamada.php — registrar llamada rápida desde la barra de actividades del deal.
 * ---------------------------------------------------------------------------
 * Placement: CRM_DEAL_DETAIL_ACTIVITY (la fila Llamada/Comentario/Mensaje/
 * Reunión/Actividad/Reserva/Tarea/E-mail/Espacios disponibles/Más → "Registrar
 * llamada" dentro de "Más").
 *
 * Bitrix abre este placement en su propio panel deslizante (mismo contenedor
 * que usa su botón nativo "Llamada") — el tamaño del contenedor no se puede
 * angostar desde acá, así que en vez de pelear con eso, el contenido se dibuja
 * como una tarjeta chica y centrada (calendario real, no un <input type=date>)
 * para que SE SIENTA como un desplegable aunque el marco de Bitrix sea grande.
 *
 * Flujo: clic en un día del calendario → se revela Contestó/No contestó ahí
 * mismo. La hora se ajusta con chips rápidos o a mano, en cualquier momento.
 *
 * La actividad que crea es IDÉNTICA en forma a la que crea el panel nativo —
 * verificado campo por campo contra actividades reales (ver memoria
 * reference_galjosa_actividad_llamada_shape). Lo único que cambia es el
 * SUBJECT: "1234" si contestó (así lo cuenta el dashboard de ventas), o el
 * texto que Bitrix pondría solo si no contestó.
 *
 * Todo se hace client-side con el SDK BX24 (el usuario logueado en Bitrix es
 * quien llama al API, no esta app) — no hace falta OAuth de servidor aquí,
 * solo para el placement.bind (placement-llamada.php, se corre una vez).
 * ---------------------------------------------------------------------------
 */
declare(strict_types=1);
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Registrar llamada</title>
<script src="//api.bitrix24.com/api/v1/"></script>
<style>
  * { box-sizing: border-box; }
  html, body {
    font: 13px/1.4 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    margin: 0; padding: 0; color: #1f2328; background: #f6f8fa;
  }
  /* nada de centrar ni sombra: el panel se encoge con BX24.resizeWindow al
     alto real de la tarjeta, así que la tarjeta ES el panel. */
  #wrap { padding: 8px; }
  .card {
    width: 300px; background: #fff; border: 1px solid #d0d7de;
    border-radius: 8px; padding: 12px;
  }
  h3 { margin: 0 0 12px; font-size: 14px; font-weight: 600; text-align: center; }

  .mes-nav { display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; }
  .mes-nav span { font-size: 13px; font-weight: 600; text-transform: capitalize; }
  .mes-nav button {
    border: none; background: #f0f2f5; width: 26px; height: 26px; border-radius: 6px;
    cursor: pointer; font-size: 14px; color: #444; line-height: 1;
  }
  .mes-nav button:hover { background: #e1e4e8; }

  .dow { display: grid; grid-template-columns: repeat(7, 1fr); text-align: center; margin-bottom: 4px; }
  .dow span { font-size: 10px; color: #8a919c; font-weight: 600; }

  .grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 2px; margin-bottom: 14px; }
  .dia {
    aspect-ratio: 1; display: flex; align-items: center; justify-content: center;
    border-radius: 7px; cursor: pointer; font-size: 12px; user-select: none;
  }
  .dia:hover { background: #eef1f4; }
  .dia.fuera { visibility: hidden; cursor: default; }
  .dia.hoy { border: 1.5px solid #2f6fed; color: #2f6fed; font-weight: 600; }
  .dia.pasado { color: #c4c9d0; cursor: default; }
  .dia.pasado:hover { background: none; }
  .dia.sel { background: #2f6fed; color: #fff; font-weight: 600; }
  .dia.sel.hoy { border-color: #2f6fed; }

  .hora-fila { display: flex; align-items: center; gap: 8px; margin-bottom: 12px; }
  .hora-fila label { font-size: 11px; color: #57606a; white-space: nowrap; }
  .hora-fila input[type="time"] {
    flex: 1; padding: 6px 8px; border: 1px solid #d0d7de; border-radius: 6px; font-size: 13px;
  }
  .chips-hora { display: flex; gap: 6px; margin-bottom: 14px; }
  .chip-hora {
    flex: 1; text-align: center; padding: 5px 4px; border: 1px solid #d0d7de; border-radius: 6px;
    background: #f6f8fa; cursor: pointer; font-size: 11px; color: #444;
  }
  .chip-hora:hover { background: #eef1f4; }

  #accion { display: none; }
  #accion.on { display: block; }
  .fecha-elegida {
    text-align: center; font-size: 11px; color: #57606a; margin-bottom: 10px;
    padding-top: 10px; border-top: 1px solid #eef1f4;
  }
  .fecha-elegida b { color: #1f2328; }
  .botones { display: flex; gap: 8px; }
  .btn {
    flex: 1; padding: 11px; border: none; border-radius: 7px; font-size: 13px;
    font-weight: 600; cursor: pointer; color: #fff;
  }
  .btn.no { background: #cf222e; }
  .btn.no:hover { background: #a40e26; }
  .btn.si { background: #1a7f37; }
  .btn.si:hover { background: #116329; }
  .btn:disabled { opacity: .5; cursor: default; }

  .pista { text-align: center; font-size: 11px; color: #8a919c; padding: 4px 0 0; }
  #estado { margin-top: 10px; font-size: 12px; text-align: center; min-height: 16px; }
  #estado.ok { color: #1a7f37; font-weight: 600; }
  #estado.err { color: #cf222e; font-weight: 600; }
</style>
</head>
<body>

<div id="wrap"><div class="card">

  <h3>¿Cuándo lo vuelvo a llamar?</h3>

  <div class="mes-nav">
    <button id="mesAnt" type="button">‹</button>
    <span id="mesLabel"></span>
    <button id="mesSig" type="button">›</button>
  </div>
  <div class="dow">
    <span>L</span><span>M</span><span>M</span><span>J</span><span>V</span><span>S</span><span>D</span>
  </div>
  <div class="grid" id="grid"></div>

  <div class="hora-fila">
    <label>Hora</label>
    <input type="time" id="hora">
  </div>
  <div class="chips-hora">
    <div class="chip-hora" data-min="0">Ahora</div>
    <div class="chip-hora" data-min="15">+15m</div>
    <div class="chip-hora" data-min="30">+30m</div>
    <div class="chip-hora" data-min="60">+1h</div>
  </div>

  <div id="accion">
    <div class="fecha-elegida">Vuelvo a llamar el <b id="fechaTxt">—</b></div>
    <div class="botones">
      <button class="btn no" id="btnNo" disabled>No contestó</button>
      <button class="btn si" id="btnSi" disabled>Sí, contestó</button>
    </div>
  </div>
  <div class="pista" id="pista">Elegí un día en el calendario ↑</div>

  <div id="estado"></div>

</div></div>

<script>
var dealId = null;
var vista = new Date();           // mes que se está mostrando
var seleccionada = null;          // {y,m,d} elegido
var MESES = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
var DIAS_ES = ['dom','lun','mar','mié','jue','vie','sáb'];

function pad(n) { return n < 10 ? '0' + n : '' + n; }

function hoy() { var d = new Date(); return { y: d.getFullYear(), m: d.getMonth(), d: d.getDate() }; }
function esHoy(y, m, d) { var h = hoy(); return y === h.y && m === h.m && d === h.d; }
function esPasado(y, m, d) {
  var h = hoy();
  return (y < h.y) || (y === h.y && m < h.m) || (y === h.y && m === h.m && d < h.d);
}

function pintarMes() {
  var y = vista.getFullYear(), m = vista.getMonth();
  document.getElementById('mesLabel').textContent = MESES[m] + ' ' + y;

  var primerDia = new Date(y, m, 1).getDay();      // 0=domingo
  var offset = (primerDia + 6) % 7;                // lunes-primero
  var totalDias = new Date(y, m + 1, 0).getDate();

  var grid = document.getElementById('grid');
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

/**
 * Encoge el panel de Bitrix al alto real de la tarjeta.
 *
 * Es lo único que faltaba probar: cuando la app dibuja su propio HTML, Bitrix
 * abre un área grande — pero resizeWindow sí la achica. fitWindow solo no
 * alcanzaba (dejaba el bloque blanco enorme alrededor del contenido).
 */
function ajustar() {
  try {
    if (typeof BX24 === 'undefined') return;
    var c = document.querySelector('.card');
    var alto = (c ? c.offsetHeight : 400) + 22;
    if (BX24.resizeWindow) BX24.resizeWindow(330, alto);
    if (BX24.fitWindow) BX24.fitWindow();
  } catch (e) {}
}

function elegirDia(y, m, d) {
  seleccionada = { y: y, m: m, d: d };
  pintarMes();
  document.getElementById('fechaTxt').textContent = d + ' de ' + MESES[m] + (esHoy(y, m, d) ? ' (hoy)' : '');
  document.getElementById('accion').classList.add('on');
  document.getElementById('pista').style.display = 'none';
  document.getElementById('btnSi').disabled = false;
  document.getElementById('btnNo').disabled = false;
  ajustar();   // al revelarse los botones la tarjeta crece
}

document.getElementById('mesAnt').addEventListener('click', function () {
  vista.setMonth(vista.getMonth() - 1); pintarMes();
});
document.getElementById('mesSig').addEventListener('click', function () {
  vista.setMonth(vista.getMonth() + 1); pintarMes();
});

function horaActual() {
  var d = new Date();
  document.getElementById('hora').value = pad(d.getHours()) + ':' + pad(d.getMinutes());
}
document.querySelectorAll('.chip-hora').forEach(function (c) {
  c.addEventListener('click', function () {
    var min = parseInt(c.getAttribute('data-min'), 10);
    var d = new Date();
    d.setMinutes(d.getMinutes() + min);
    document.getElementById('hora').value = pad(d.getHours()) + ':' + pad(d.getMinutes());
  });
});

function estado(msg, cls) {
  var e = document.getElementById('estado');
  e.textContent = msg;
  e.className = cls || '';
}

function botones(activos) {
  document.getElementById('btnSi').disabled = !activos;
  document.getElementById('btnNo').disabled = !activos;
}

function isoConOffsetEcuador() {
  if (!seleccionada) return null;
  var h = document.getElementById('hora').value;
  if (!h) return null;
  var f = seleccionada.y + '-' + pad(seleccionada.m + 1) + '-' + pad(seleccionada.d);
  return f + 'T' + h + ':00-05:00';
}

function sumarHora(iso) {
  // iso viene como YYYY-MM-DDTHH:MM:SS-05:00 ; le suma 1h a mano (sin Date(), que
  // rompería el offset fijo de Ecuador si el navegador tuviera otra zona).
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
        setTimeout(function () { BX24.closeApplication(); }, 700);
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

document.getElementById('btnNo').addEventListener('click', function () { registrar(false); });
document.getElementById('btnSi').addEventListener('click', function () { registrar(true); });

pintarMes();
horaActual();

BX24.init(function () {
  var opt = {};
  try { opt = BX24.placement.info().options || {}; } catch (e) {}
  // el nombre de la clave cambia según el placement: cubrir las tres formas
  dealId = parseInt(opt.ID || opt.ENTITY_ID || opt.entityId || 0, 10);
  if (!dealId) { estado('No se encontró el deal.', 'err'); return; }
  elegirDia(hoy().y, hoy().m, hoy().d);
  ajustar();
});
</script>

</body>
</html>
