<?php
/**
 * llamada_nativo.php — "Registrar llamada" con la INTERFAZ NATIVA de Bitrix.
 * ---------------------------------------------------------------------------
 * Placement: CRM_DEAL_DETAIL_ACTIVITY con OPTIONS.useBuiltInInterface = 'Y'.
 *
 * Por qué así y no dibujando HTML propio (llamada.php / llamada_campo.php):
 * cuando la app dibuja su propio HTML, Bitrix lo mete SIEMPRE en su panel
 * deslizante grande — ese contenedor no se puede achicar desde adentro, sin
 * importar el tamaño del contenido (probado). Con useBuiltInInterface la
 * interfaz la dibuja BITRIX a partir de un LayoutDto, dentro de la propia
 * barra de actividades: sin iframe visible, sin panel, sin fondo blanco.
 *
 * El costo (documentado, no hay vuelta): los bloques nativos disponibles son
 * text/link/withTitle/lineOfBlocks/dropdownMenu/input/textarea/select/list/
 * section. NO hay bloque de calendario. Por eso el día es un <select> con
 * fechas reales ("Hoy · jue 6 ago") en vez de una grilla de calendario.
 *
 * Flujo: 3 clics — día, hora, y el botón (primario = contestó / secundario =
 * no contestó). Los dos botones crean UNA actividad con la forma exacta de
 * la nativa (ver memoria reference_galjosa_actividad_llamada_shape); lo único
 * que cambia entre una y otra es el SUBJECT: "1234" si contestó (es lo que
 * cuenta el dashboard de contestadas), o el texto que Bitrix pondría solo.
 *
 * Este iframe queda OCULTO (Bitrix no lo muestra con useBuiltInInterface), así
 * que acá no va ningún estilo ni marcado visible: solo la lógica.
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
</head>
<body>
<script>
(function () {
  var DIAS  = ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'];
  var MESES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

  var dealId = 0;
  // valores vivos de los <select>: la API nativa no deja LEER un bloque, solo
  // avisa por callback cuando cambia — así que hay que ir guardándolos acá.
  var valores = { dia: '0', hora: '' };

  function pad(n) { return n < 10 ? '0' + n : '' + n; }

  /** Fecha (Y-M-D en hora local) a N días de hoy. */
  function fechaEn(dias) {
    var d = new Date();
    d.setDate(d.getDate() + dias);
    return d;
  }

  /** Opciones de día: hoy + 30 días, con fecha real escrita. */
  function opcionesDia() {
    var out = {};
    for (var i = 0; i <= 30; i++) {
      var d = fechaEn(i);
      var etq = DIAS[d.getDay()] + ' ' + d.getDate() + ' ' + MESES[d.getMonth()];
      if (i === 0) etq = 'Hoy · ' + etq;
      else if (i === 1) etq = 'Mañana · ' + etq;
      out[String(i)] = etq;
    }
    return out;
  }

  /** Opciones de hora: 07:00 a 21:00 cada 30 min. */
  function opcionesHora() {
    var out = {};
    for (var h = 7; h <= 21; h++) {
      for (var m = 0; m < 60; m += 30) {
        if (h === 21 && m > 0) break;
        var k = pad(h) + ':' + pad(m);
        var h12 = h % 12 === 0 ? 12 : h % 12;
        out[k] = h12 + ':' + pad(m) + ' ' + (h < 12 ? 'a.m.' : 'p.m.');
      }
    }
    return out;
  }

  /** Próxima media hora redonda, para arrancar en algo sensato. */
  function horaPorDefecto() {
    var d = new Date();
    d.setMinutes(d.getMinutes() + 30);
    var h = d.getHours();
    var m = d.getMinutes() < 30 ? 0 : 30;
    if (h < 7) { h = 8; m = 0; }
    if (h > 21) { h = 21; m = 0; }
    return pad(h) + ':' + pad(m);
  }

  function layout() {
    return {
      blocks: {
        dia: {
          type: 'select',
          properties: {
            title: '¿Cuándo lo vuelvo a llamar?',
            selectedValue: valores.dia,
            values: opcionesDia()
          }
        },
        hora: {
          type: 'select',
          properties: {
            title: 'Hora',
            selectedValue: valores.hora,
            values: opcionesHora()
          }
        }
      },
      primaryButton:   { title: 'Sí, contestó', state: 'normal' },
      secondaryButton: { title: 'No contestó',  state: 'normal' }
    };
  }

  /** "2026-08-07T10:00:00-05:00" a partir de los selects (hora Ecuador). */
  function inicioIso() {
    var d = fechaEn(parseInt(valores.dia, 10) || 0);
    var f = d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
    return f + 'T' + valores.hora + ':00-05:00';
  }

  /** +1h a mano: usar Date() rompería el offset fijo -05:00. */
  function masUnaHora(iso) {
    var m = iso.match(/^(\d{4}-\d{2}-\d{2})T(\d{2}):(\d{2}):00(-05:00)$/);
    if (!m) return iso;
    return m[1] + 'T' + pad((parseInt(m[2], 10) + 1) % 24) + ':' + m[3] + ':00' + m[4];
  }

  function registrar(contesto) {
    if (!dealId || !valores.hora) { BX24.placement.call('finish'); return; }
    BX24.placement.call('lock');

    var inicio = inicioIso();

    BX24.callMethod('crm.deal.get', { id: dealId }, function (rd) {
      if (rd.error()) { BX24.placement.call('unlock'); return; }
      var deal = rd.data();
      var contactId = deal.CONTACT_ID;
      var responsable = deal.ASSIGNED_BY_ID;

      function crear(nombre, telefono) {
        var fields = {
          OWNER_TYPE_ID: 2, OWNER_ID: dealId,
          TYPE_ID: 2, DIRECTION: 2,
          PROVIDER_ID: 'VOXIMPLANT_CALL', PROVIDER_TYPE_ID: 'CALL',
          SUBJECT: contesto ? '1234' : ('Llamada saliente ' + (nombre || 'cliente')),
          COMPLETED: 'N', RESPONSIBLE_ID: responsable,
          START_TIME: inicio, END_TIME: masUnaHora(inicio), DEADLINE: inicio,
          PRIORITY: 2, NOTIFY_TYPE: 1, NOTIFY_VALUE: 15,
          DESCRIPTION_TYPE: 1
        };
        if (contactId && telefono) {
          fields.COMMUNICATIONS = [{ VALUE: telefono, ENTITY_ID: contactId, ENTITY_TYPE_ID: 3, TYPE: 'PHONE' }];
        }
        BX24.callMethod('crm.activity.add', { fields: fields }, function (ra) {
          BX24.placement.call('unlock');
          if (!ra.error()) BX24.placement.call('finish');
        });
      }

      if (!contactId || parseInt(contactId, 10) <= 0) { crear(null, null); return; }
      BX24.callMethod('crm.contact.get', { id: contactId }, function (rc) {
        var nombre = null, tel = null;
        if (!rc.error()) {
          var c = rc.data();
          nombre = [c.NAME, c.LAST_NAME].filter(Boolean).join(' ').trim() || null;
          tel = (c.PHONE && c.PHONE[0] && c.PHONE[0].VALUE) || null;
        }
        crear(nombre, tel);
      });
    });
  }

  BX24.init(function () {
    var opt = {};
    try { opt = BX24.placement.info().options || {}; } catch (e) {}
    dealId = parseInt(opt.ENTITY_ID || opt.entityId || opt.ID || 0, 10);

    valores.hora = horaPorDefecto();

    BX24.placement.call('setLayout', layout(), function () {});

    // Los selects avisan por acá cuando el vendedor los cambia.
    BX24.placement.call('bindValueChangeCallback', null, function (ev) {
      if (ev && ev.id) valores[ev.id] = ev.value;
    });

    BX24.placement.call('bindPrimaryButtonClickCallback', null, function () {
      registrar(true);
    });
    BX24.placement.call('bindSecondaryButtonClickCallback', null, function () {
      registrar(false);
    });
  });
})();
</script>
</body>
</html>
