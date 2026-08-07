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
 * fila de 7 links = una semana, y 6 filas = el mes.
 *
 * ⭐ ALINEACIÓN — el detalle que costó una vuelta:
 * los bloques se renderizan como HTML, y HTML COLAPSA los espacios seguidos.
 * Con espacios normales las celdas vacías desaparecían y las columnas salían
 * corridas. Se usa ESPACIO DE CIFRA (U+2007), que no colapsa y mide
 * exactamente lo que un dígito: así toda celda ocupa 2 caracteres de ancho y
 * las columnas quedan a plomo. Por lo mismo el día elegido se marca con
 * `bold` y NO con corchetes: cualquier caracter extra corre la fila entera.
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
  var DIAN  = ['dom','lun','mar','mié','jue','vie','sáb'];
  // MAYUSCULA a proposito: medido en el render real, el encabezado en
  // minuscula suma 99,8 px contra 120,6 px de la fila de numeros -- 21 px de
  // menos, que es lo que hacia que los dias "se pasaran" del domingo.
  // En mayuscula suma 123,9 px y el desfase baja a 3,3 px.
  var DOW   = ['LU','MA','MI','JU','VI','SA','DO'];

  // U+2007 = espacio de cifra = 8,672 px = exactamente el ancho de un "0".
  // Dos de estos = una celda de 2 digitos. NO lo colapsa el HTML: CSS solo
  // colapsa espacio/tab/salto ASCII, no los espacios Unicode tipograficos.
  var EC = '\u2007';
  var VACIA = EC + EC;

  /**
   * Rellena los digitos angostos para que toda celda mida lo mismo.
   *
   * Medido en el render: el "1" mide 6,344 px contra 8,672 del "0" (le faltan
   * 2,328) y el "7" mide 7,820 (le faltan 0,852). El resto va de 8,3 a 8,9.
   * U+2006 mide 2,180 y U+200A mide 0,836 -- casi clavados a lo que falta.
   * Con esto la variacion por celda pasa de 4,84 px a 0,83 px.
   */
  function celda(n) {
    var t = pad(n), out = '';
    for (var i = 0; i < t.length; i++) {
      out += t.charAt(i);
      if      (t.charAt(i) === '1') out += '\u2006';
      else if (t.charAt(i) === '7') out += '\u200A';
    }
    return out;
  }

  var dealId = 0;
  var vista  = new Date();
  var sel    = null;
  var hhmm = '09:00';          // 24 h, tal cual viaja a Bitrix
  var horaManual = false;      // ¿el vendedor tocó la hora?
  var diaManual  = false;      // ¿eligió un día distinto de hoy?
  var aviso  = '';
  // La pestaña NO se puede cerrar por API. Probado: finish/close/cancel/
  // closeApplication (sin respuesta), y dejar el boton secundario sin enlazar
  // tampoco cierra. Lo unico que si controlo es MI contenido, asi que "Cerrar"
  // lo colapsa a una linea. Para el vendedor el efecto es el mismo.
  //
  // CLAVE: los dos botones van SIEMPRE en el diseño, en todos los estados.
  // Cuando los omiti, Bitrix dejo los anteriores colgando y pinto uno vacio
  // (el punto azul suelto). ButtonDto solo acepta title y state: no se ocultan.
  var abierto = false;

  function pad(n) { return n < 10 ? '0' + n : '' + n; }
  function hoy() { var d = new Date(); return { y:d.getFullYear(), m:d.getMonth(), d:d.getDate() }; }
  function esHoy(y,m,d) { var h = hoy(); return y===h.y && m===h.m && d===h.d; }
  function esPasado(y,m,d) {
    var h = hoy();
    return (y<h.y) || (y===h.y && m<h.m) || (y===h.y && m===h.m && d<h.d);
  }

  /** Los botones se arman en un solo lugar: nunca falta ninguno. */
  function botones(activos) {
    return {
      primaryButton:   { title:'Sí, contestó', state: activos ? 'normal' : 'disabled' },
      secondaryButton: { title:'No contestó',  state: activos ? 'normal' : 'disabled' }
    };
  }

  /**
   * Hora en UN SOLO campo, 24 h — el mismo formato del reloj que Bitrix ya
   * usa en sus propias actividades. Antes fueron tres listas (hora/minuto/
   * am-pm) y sobraban campos.
   */
  function ahoraHHMM() { var d = new Date(); return pad(d.getHours()) + ':' + pad(d.getMinutes()); }

  /**
   * Lee lo que sea que escriban. Aguanta 16:25 · 1625 · 16.25 · 4:25pm · 4pm · 9.
   * Devuelve "HH:MM" o null si no se entiende (y entonces se deja la anterior).
   */
  function leerHora(txt) {
    var s = String(txt == null ? '' : txt).toLowerCase().replace(/\s+/g, '');
    var pm = /p\.?m\.?/.test(s), am = /a\.?m\.?/.test(s);
    s = s.replace(/[ap]\.?m\.?/g, '');
    // con separador el minuto puede ir de un dígito ("9:5"); sin separador
    // tiene que ir de dos, si no "165" sería ambiguo.
    var m = s.match(/^(\d{1,2})(?:[:.h](\d{1,2})|(\d{2}))?$/);
    if (!m) return null;
    var mm = m[2] || m[3];
    var h = parseInt(m[1], 10), mi = mm ? parseInt(mm, 10) : 0;
    if (pm && h < 12)  h += 12;
    if (am && h === 12) h = 0;
    if (h > 23 || mi > 59) return null;
    return pad(h) + ':' + pad(mi);
  }

  /** "16:25" -> "4:25 p.m.", solo para el texto de confirmación. */
  function horaTxt() {
    var h = parseInt(hhmm.slice(0, 2), 10);
    var h12 = h % 12; if (h12 === 0) h12 = 12;
    return h12 + ':' + hhmm.slice(3) + ' ' + (h < 12 ? 'a.m.' : 'p.m.');
  }

  /** La frase que se lee en los dos estados: siempre la misma, sin repetir rótulos. */
  function resumenTxt() {
    var f = new Date(sel.y, sel.m, sel.d);
    return 'Vuelvo a llamar el ' + DIAN[f.getDay()] + ' ' + sel.d + ' de ' + MESES[sel.m]
         + (esHoy(sel.y, sel.m, sel.d) ? ' (hoy)' : '') + ', ' + horaTxt();
  }

  function layout() {
    // Mientras el vendedor no toque nada, día y hora se mantienen al día solos:
    // si deja la pestaña abierta media hora, no registra con la hora vieja.
    if (!horaManual) hhmm = ahoraHHMM();
    if (!diaManual)  { var hy = hoy(); sel = { y:hy.y, m:hy.m, d:hy.d }; }

    // COLAPSADO = lo que se ve al entrar al deal. El botón de la barra ya se
    // llama "Registrar llamada", así que acá NO se repite ese rótulo: va
    // directo la frase de qué se va a guardar y los dos botones listos. El
    // caso normal (hoy, ahora mismo) se resuelve en UN click, sin abrir nada.
    if (!abierto) {
      var b = botones(true);
      b.blocks = {};
      if (aviso) b.blocks.ok = { type:'text', properties:{ value: aviso, bold:true } };
      b.blocks.info    = { type:'text', properties:{ value: resumenTxt() } };
      b.blocks.cambiar = { type:'link', properties:{
        text:'Cambiar día u hora', action:{ type:'layoutEvent', value:'abrir' } } };
      return b;
    }
    var y = vista.getFullYear(), m = vista.getMonth();
    var blocks = {};

    // ── encabezado: ◀  agosto 2026  ▶
    blocks.nav = { type:'lineOfBlocks', properties:{ blocks:{
      ant: { type:'link', properties:{ text:'◀', action:{ type:'layoutEvent', value:'mes:-1' } } },
      tit: { type:'text', properties:{ value: EC + MESES[m] + ' ' + y + EC, bold:true } },
      sig: { type:'link', properties:{ text:'▶', action:{ type:'layoutEvent', value:'mes:1' } } }
    }}};

    // ── fila de días de la semana (2 caracteres, igual que los números)
    var cab = {};
    for (var i = 0; i < 7; i++) cab['h'+i] = { type:'text', properties:{ value: DOW[i] } };
    blocks.dow = { type:'lineOfBlocks', properties:{ blocks: cab } };

    // ── celdas del mes, lunes primero
    var offset = (new Date(y, m, 1).getDay() + 6) % 7;
    var total  = new Date(y, m + 1, 0).getDate();
    var celdas = [];
    for (var o = 0; o < offset; o++) celdas.push(null);
    for (var d = 1; d <= total; d++) celdas.push(d);
    while (celdas.length % 7 !== 0) celdas.push(null);

    for (var s = 0; s < celdas.length / 7; s++) {
      var fila = {};
      for (var c = 0; c < 7; c++) {
        var dia = celdas[s*7 + c], key = 'c' + c;
        if (dia === null) {
          fila[key] = { type:'text', properties:{ value: VACIA } };
        } else if (esPasado(y, m, dia)) {
          fila[key] = { type:'text', properties:{ value: celda(dia) } };
        } else if (sel && sel.y===y && sel.m===m && sel.d===dia) {
          // el elegido va como TEXTO (oscuro) y en negrita: contra el azul de
          // los links se distingue de un vistazo, que era el reclamo.
          fila[key] = { type:'text', properties:{ value: celda(dia), bold:true } };
        } else {
          fila[key] = { type:'link', properties:{
            text: celda(dia),
            action:{ type:'layoutEvent', value:'dia:'+y+'-'+pad(m+1)+'-'+pad(dia) }
          }};
        }
      }
      blocks['sem'+s] = { type:'lineOfBlocks', properties:{ blocks: fila } };
    }

    // ── hora: un campo, se escribe. Aguanta 16:25, 1625, 4:25pm o solo 9.
    blocks.hora = { type:'input', properties:{
      title:'Hora', value:hhmm, placeholder:'16:25' } };

    // ── resumen: la confirmación en palabras, que es lo que de verdad se lee
    blocks.resumen = { type:'text', properties:{ value: resumenTxt(), bold:true } };
    blocks.cerrar  = { type:'link', properties:{
      text:'Listo', action:{ type:'layoutEvent', value:'cerrar' } } };

    var out = botones(true);
    out.blocks = blocks;
    return out;
  }

  function redibujar() { BX24.placement.call('setLayout', layout(), function(){}); }

  function inicioIso() {
    return sel.y + '-' + pad(sel.m+1) + '-' + pad(sel.d) + 'T' + hhmm + ':00-05:00';
  }
  /** +1h a mano: Date() rompería el offset fijo -05:00. */
  function masUnaHora(iso) {
    var m = iso.match(/^(\d{4}-\d{2}-\d{2})T(\d{2}):(\d{2}):00(-05:00)$/);
    if (!m) return iso;
    return m[1] + 'T' + pad((parseInt(m[2],10)+1)%24) + ':' + m[3] + ':00' + m[4];
  }

  function registrar(contesto) {
    if (!dealId || !sel) return;
    BX24.placement.call('lock');
    // Si nunca tocó la hora, se sella la de ESTE momento, no la del render.
    if (!horaManual) hhmm = ahoraHHMM();
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
          if (ra.error()) { aviso = 'No se pudo guardar: ' + ra.error(); redibujar(); return; }
          var f = new Date(sel.y, sel.m, sel.d);
          aviso = 'Guardado \u2713  ' + (contesto ? 'contest\u00f3' : 'no contest\u00f3')
                + ', vuelvo a llamar el ' + DIAN[f.getDay()] + ' ' + sel.d + ' de ' + MESES[sel.m]
                + ' a las ' + horaTxt();
          // vuelve al estado de arranque: hoy, ahora, listo para la siguiente
          abierto = false; horaManual = false; diaManual = false;
          redibujar();
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

    redibujar();                     // nace COLAPSADO: frase + los dos botones

    BX24.placement.call('bindLayoutEventCallback', null, function (ev) {
      var v = (ev && ev.value) || '';
      if (v === 'abrir') {
        abierto = true; aviso = ''; vista = new Date(); redibujar();
      } else if (v === 'cerrar') {
        abierto = false; aviso = ''; redibujar();
      } else if (v.indexOf('mes:') === 0) {
        vista.setMonth(vista.getMonth() + parseInt(v.slice(4), 10));
        redibujar();
      } else if (v.indexOf('dia:') === 0) {
        var p = v.slice(4).split('-');
        sel = { y:parseInt(p[0],10), m:parseInt(p[1],10)-1, d:parseInt(p[2],10) };
        diaManual = true;
        redibujar();
      }
    });

    // Hora escrita a mano. Si no se entiende, se ignora y el redibujo la deja
    // como estaba -- nunca queda un valor basura camino a Bitrix.
    BX24.placement.call('bindValueChangeCallback', null, function (ev) {
      if (!ev || ev.id !== 'hora') return;
      var v = leerHora(ev.value);
      if (v) { hhmm = v; horaManual = true; }
      redibujar();
    });

    BX24.placement.call('bindPrimaryButtonClickCallback',   null, function(){ registrar(true);  });
    BX24.placement.call('bindSecondaryButtonClickCallback', null, function(){ registrar(false); });
  });
})();
</script>
</body>
</html>
