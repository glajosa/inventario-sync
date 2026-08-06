<?php
/**
 * llamada.php — registrar llamada rápida desde la barra de actividades del deal.
 * ---------------------------------------------------------------------------
 * Placement: CRM_DEAL_ACTIVITY_TIMELINE_MENU (la fila Llamada/Comentario/
 * Mensaje/Reunión/Actividad/Reserva/Tarea/E-mail/Espacios disponibles/Más).
 *
 * Reemplaza el flujo manual (abrir el panel grande, escribir asunto, elegir
 * fecha) por 2 clicks: elegir cuándo volver a llamar + Contestó/No contestó.
 * La actividad que crea es IDÉNTICA en forma a la que crea el panel nativo —
 * verificado campo por campo contra actividades reales (ver memoria
 * reference_galjosa_actividad_llamada_shape). Lo único que cambia es el
 * SUBJECT: "1234" si contestó (así lo cuenta el dashboard de ventas), o el
 * texto que Bitrix pondría solo si no contestó.
 *
 * Todo se hace client-side con el SDK BX24 (el usuario logueado en Bitrix es
 * quien llama al API, no esta app) — no hace falta OAuth de servidor aquí,
 * solo para el placement.bind (bind-placement.php, se corre una vez).
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
  body {
    font: 14px/1.4 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    margin: 0; padding: 18px; color: #1f2328; background: #fff;
  }
  h3 { margin: 0 0 14px; font-size: 15px; font-weight: 600; }
  .fila { display: flex; gap: 8px; margin-bottom: 14px; flex-wrap: wrap; }
  .chip {
    flex: 1 1 auto; padding: 8px 10px; border: 1px solid #d0d7de; border-radius: 6px;
    background: #f6f8fa; cursor: pointer; text-align: center; font-size: 13px; user-select: none;
  }
  .chip:hover { background: #eef1f4; }
  .chip.activo { background: #2f6fed; color: #fff; border-color: #2f6fed; }
  .campos { display: flex; gap: 8px; margin-bottom: 18px; }
  .campos input {
    flex: 1; padding: 8px; border: 1px solid #d0d7de; border-radius: 6px; font-size: 13px;
  }
  .botones { display: flex; gap: 10px; }
  .btn {
    flex: 1; padding: 12px; border: none; border-radius: 6px; font-size: 14px;
    font-weight: 600; cursor: pointer; color: #fff;
  }
  .btn.no { background: #cf222e; }
  .btn.no:hover { background: #a40e26; }
  .btn.si { background: #1a7f37; }
  .btn.si:hover { background: #116329; }
  .btn:disabled { opacity: .5; cursor: default; }
  #estado { margin-top: 14px; font-size: 13px; text-align: center; min-height: 18px; }
  #estado.ok { color: #1a7f37; font-weight: 600; }
  #estado.err { color: #cf222e; font-weight: 600; }
</style>
</head>
<body>

<h3>¿Cuándo lo vuelvo a llamar?</h3>
<div class="fila" id="chips">
  <div class="chip" data-dias="0">Hoy</div>
  <div class="chip" data-dias="1">Mañana</div>
  <div class="chip" data-dias="2">+2 días</div>
  <div class="chip" data-dias="3">+3 días</div>
</div>
<div class="campos">
  <input type="date" id="fecha">
  <input type="time" id="hora">
</div>

<div class="botones">
  <button class="btn no" id="btnNo">No contestó</button>
  <button class="btn si" id="btnSi">Sí, contestó</button>
</div>

<div id="estado"></div>

<script>
var dealId = null;

function pad(n) { return n < 10 ? '0' + n : '' + n; }

function fijarFecha(dias) {
  var d = new Date();
  d.setDate(d.getDate() + dias);
  document.getElementById('fecha').value = d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
  var ahora = new Date();
  document.getElementById('hora').value = pad(ahora.getHours()) + ':' + pad(ahora.getMinutes());
}

document.querySelectorAll('.chip').forEach(function (c) {
  c.addEventListener('click', function () {
    document.querySelectorAll('.chip').forEach(function (x) { x.classList.remove('activo'); });
    c.classList.add('activo');
    fijarFecha(parseInt(c.getAttribute('data-dias'), 10));
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
  var f = document.getElementById('fecha').value;
  var h = document.getElementById('hora').value;
  if (!f || !h) return null;
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
  if (!inicio) { estado('Elegí cuándo volver a llamar.', 'err'); return; }
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

BX24.init(function () {
  var opt = BX24.placement.info().options || {};
  dealId = opt.ID || opt.ENTITY_ID;
  if (!dealId) { estado('No se encontró el deal.', 'err'); botones(false); return; }
  document.querySelector('.chip[data-dias="0"]').click();
  BX24.fitWindow();
});
</script>

</body>
</html>
