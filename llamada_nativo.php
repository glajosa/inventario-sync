<?php
/**
 * llamada_nativo.php — "Registrar llamada" con CALENDARIO en la interfaz
 * NATIVA de Bitrix (placement CRM_DEAL_DETAIL_ACTIVITY + useBuiltInInterface).
 * ---------------------------------------------------------------------------
 * Por qué así: cuando la app dibuja su propio HTML, Bitrix lo abre en un panel
 * grande que NO se puede encoger (probado con resizeWindow y fitWindow: la
 * tarjeta se achica, el panel blanco de atrás no). Con useBuiltInInterface la
 * interfaz la dibuja Bitrix a partir de un LayoutDto — sin iframe visible, sin
 * panel, sin espacio blanco.
 *
 * El calendario: la lista de bloques nativos no trae uno de calendario, PERO
 * `link` acepta `action:{type:'layoutEvent'}` (o sea es clickeable y avisa por
 * callback) y `lineOfBlocks` pone varios bloques EN UNA FILA. Entonces una
 * fila de 7 links = una semana, y 6 filas = el mes. Es un calendario de
 * verdad, hecho con piezas nativas.
 *
 * Limitación heredada de los bloques nativos: los días son enlaces de texto,
 * no celdas con fondo. El día elegido se marca en negrita y además se escribe
 * abajo ("Vuelvo a llamar el 15 de agosto") para que no quede duda.
 *
 * La actividad creada conserva la forma exacta ya verificada campo por campo
 * (ver memoria reference_galjosa_actividad_llamada_shape). Lo único que cambia
 * entre contestó / no contestó es el SUBJECT: "1234" cuando contestó, que es
 * lo que cuenta el dashboard.
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
  var MESES = ['enero','febrero','marzo','abril','mayo','junio',
               'julio','agosto','septiembre','octubre','noviembre','diciembre'];
  var DOW = ['L','M','M','J','V','S','D'];

  var dealId = 0;
  var vista  = new Date();     // mes que se muestra
  var sel    = null;           // {y,m,d} elegido
  var hora   = '';

  function pad(n) { return n < 10 ? '0' + n : '' + n; }
  function hoy() { var d = new Date(); return { y:d.getFullYear(), m:d.getMonth(), d:d.getDate() }; }
  function esHoy(y,m,d) { var h = hoy(); return y===h.y && m===h.m && d===h.d; }
  function esPasado(y,m,d) {
    var h = hoy();
    return (y<h.y) || (y===h.y && m<h.m) || (y===h.y && m===h.m && d<h.d);
  }

  /** Opciones de hora: 07:00 a 21:00 cada 30 min. */
  function opcionesHora() {
    var out = {};
    for (var h = 7; h <= 21; h++) {
      for (var m = 0; m < 60; m += 30) {
        if (h === 21 && m > 0) break;
        var h12 = h % 12 === 0 ? 12 : h % 12;
        out[pad(h)+':'+pad(m)] = h12 + ':' + pad(m) + ' ' + (h < 12 ? 'a.m.' : 'p.m.');
      }
    }
    return out;
  }
  function horaPorDefecto() {
    var d = new Date();
    d.setMinutes(d.getMinutes() + 30);
    var h = d.getHours(), m = d.getMinutes() < 30 ? 0 : 30;
    if (h < 7)  { h = 8;  m = 0; }
    if (h > 21) { h = 21; m = 0; }
    return pad(h)+':'+pad(m);
  }

  /** Arma el LayoutDto completo (calendario + hora + resumen). */
  function layout() {
    var y = vista.getFullYear(), m = vista.getMonth();
    var blocks = {};

    // fila de navegación: ‹  agosto 2026  ›
    blocks.nav = { type:'lineOfBlocks', properties:{ blocks:{
      ant: { type:'link', properties:{ text:'◀', action:{ type:'layoutEvent', value:'mes:-1' } } },
      tit: { type:'text', properties:{ value:'  ' + MESES[m] + ' ' + y + '  ', bold:true } },
      sig: { type:'link', properties:{ text:'▶', action:{ type:'layoutEvent', value:'mes:1' } } }
    }}};

    // encabezado L M M J V S D
    var cab = {};
    for (var i = 0; i < 7; i++) cab['h'+i] = { type:'text', properties:{ value: DOW[i] } };
    blocks.dow = { type:'lineOfBlocks', properties:{ blocks: cab } };

    // celdas del mes, lunes primero
    var offset = (new Date(y, m, 1).getDay() + 6) % 7;
    var total  = new Date(y, m + 1, 0).getDate();
    var celdas = [];
    for (var o = 0; o < offset; o++) celdas.push(null);
    for (var d = 1; d <= total; d++) celdas.push(d);
    while (celdas.length % 7 !== 0) celdas.push(null);

    for (var s = 0; s < celdas.length / 7; s++) {
      var fila = {};
      for (var c = 0; c < 7; c++) {
        var dia = celdas[s*7 + c];
        var key = 'c' + c;
        if (dia === null) {
          fila[key] = { type:'text', properties:{ value:'    ' } };
        } else if (esPasado(y, m, dia)) {
          // los días pasados no son clickeables: quedan como texto plano
          fila[key] = { type:'text', properties:{ value: pad(dia) } };
        } else {
          var elegido = sel && sel.y===y && sel.m===m && sel.d===dia;
          fila[key] = { type:'link', properties:{
            text: (elegido ? '▸'+pad(dia)+'◂' : (esHoy(y,m,dia) ? '['+pad(dia)+']' : pad(dia))),
            bold: !!elegido,
            action:{ type:'layoutEvent', value:'dia:'+y+'-'+pad(m+1)+'-'+pad(dia) }
          }};
        }
      }
      blocks['sem'+s] = { type:'lineOfBlocks', properties:{ blocks: fila } };
    }

    blocks.hora = { type:'select', properties:{
      title:'Hora', selectedValue: hora, values: opcionesHora()
    }};

    blocks.resumen = { type:'text', properties:{
      value: sel
        ? ('Vuelvo a llamar el ' + sel.d + ' de ' + MESES[sel.m] + (esHoy(sel.y,sel.m,sel.d) ? ' (hoy)' : ''))
        : 'Elegí un día arriba',
      bold: !!sel
    }};

    return {
      blocks: blocks,
      primaryButton:   { title:'Sí, contestó', state: sel ? 'normal' : 'disabled' },
      secondaryButton: { title:'No contestó',  state: sel ? 'normal' : 'disabled' }
    };
  }

  function redibujar() { BX24.placement.call('setLayout', layout(), function(){}); }

  /** "2026-08-15T10:00:00-05:00" (hora Ecuador). */
  function inicioIso() {
    return sel.y + '-' + pad(sel.m+1) + '-' + pad(sel.d) + 'T' + hora + ':00-05:00';
  }
  /** +1h a mano: Date() rompería el offset fijo -05:00. */
  function masUnaHora(iso) {
    var m = iso.match(/^(\d{4}-\d{2}-\d{2})T(\d{2}):(\d{2}):00(-05:00)$/);
    if (!m) return iso;
    return m[1] + 'T' + pad((parseInt(m[2],10)+1)%24) + ':' + m[3] + ':00' + m[4];
  }

  function registrar(contesto) {
    if (!dealId || !sel || !hora) return;
    BX24.placement.call('lock');
    var inicio = inicioIso();

    BX24.callMethod('crm.deal.get', { id: dealId }, function (rd) {
      if (rd.error()) { BX24.placement.call('unlock'); return; }
      var deal = rd.data(), contactId = deal.CONTACT_ID, resp = deal.ASSIGNED_BY_ID;

      function crear(nombre, tel) {
        var fields = {
          OWNER_TYPE_ID:2, OWNER_ID:dealId,
          TYPE_ID:2, DIRECTION:2,
          PROVIDER_ID:'VOXIMPLANT_CALL', PROVIDER_TYPE_ID:'CALL',
          SUBJECT: contesto ? '1234' : ('Llamada saliente ' + (nombre || 'cliente')),
          COMPLETED:'N', RESPONSIBLE_ID:resp,
          START_TIME:inicio, END_TIME:masUnaHora(inicio), DEADLINE:inicio,
          PRIORITY:2, NOTIFY_TYPE:1, NOTIFY_VALUE:15, DESCRIPTION_TYPE:1
        };
        if (contactId && tel) {
          fields.COMMUNICATIONS = [{ VALUE:tel, ENTITY_ID:contactId, ENTITY_TYPE_ID:3, TYPE:'PHONE' }];
        }
        BX24.callMethod('crm.activity.add', { fields: fields }, function (ra) {
          BX24.placement.call('unlock');
          if (!ra.error()) BX24.placement.call('finish');
        });
      }

      if (!contactId || parseInt(contactId,10) <= 0) { crear(null,null); return; }
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
    hora = horaPorDefecto();

    redibujar();

    // clicks en los días y en las flechas de mes
    BX24.placement.call('bindLayoutEventCallback', null, function (ev) {
      var v = (ev && ev.value) || '';
      if (v.indexOf('mes:') === 0) {
        vista.setMonth(vista.getMonth() + parseInt(v.slice(4), 10));
        redibujar();
      } else if (v.indexOf('dia:') === 0) {
        var p = v.slice(4).split('-');
        sel = { y:parseInt(p[0],10), m:parseInt(p[1],10)-1, d:parseInt(p[2],10) };
        redibujar();
      }
    });

    BX24.placement.call('bindValueChangeCallback', null, function (ev) {
      if (ev && ev.id === 'hora') hora = ev.value;
    });

    BX24.placement.call('bindPrimaryButtonClickCallback',   null, function(){ registrar(true);  });
    BX24.placement.call('bindSecondaryButtonClickCallback', null, function(){ registrar(false); });
  });
})();
</script>
</body>
</html>
