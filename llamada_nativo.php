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
  // CERRAR: `finish` devuelve el foco a la pestaña por defecto de la línea de
  // tiempo -- o sea, cierra esto de verdad. (Antes lo di por imposible con una
  // prueba mal hecha; está documentado en la referencia del placement.)
  // Se llama al guardar y desde el enlace "Cerrar". `colapsado` es el respaldo
  // por si finish no cerrara: deja una sola línea en vez del calendario suelto.
  // Nace CERRADO: al entrar al deal Bitrix deja seleccionada esta pestana,
  // asi que si arrancara abierto el calendario aparece sin que nadie lo pida.
  var colapsado = true;
  //
  // CLAVE: los dos botones van SIEMPRE en el diseño. Cuando los omití, Bitrix
  // dejó los anteriores colgando y pintó uno vacío (el punto azul suelto).
  // ButtonDto solo acepta title y state: no se pueden ocultar.

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
   * Se teclean SOLO dígitos y el campo se acomoda solo: 1245 -> 12:45.
   * Los dos puntos los pone esto, no el vendedor, y de ahí sale el a.m./p.m.
   *
   * Devuelve tres cosas distintas a propósito:
   * Devuelve las tres cosas que hacen falta:
   *   texto → cómo debe quedar el campo ("12", "124", "12:40")
   *   hora  → "HH:MM" bueno, o null si todavía no lo es
   *   error → mensaje bajo el campo cuando los 4 dígitos no son una hora
   *
   * Se lee APENAS se puede, sin esperar los cuatro dígitos: tecleás "13" y ya
   * dice 1:00 p.m. Esperar a los cuatro obligaba a poner "1300" para ver el
   * p.m., y eso es tiempo en cada llamada.
   *
   * Se cortan a los CUATRO PRIMEROS dígitos: teclear de más no hace nada. El
   * input de Bitrix no tiene maxLength, así que el tope lo pone esto.
   */
  function normHora(txt) {
    var d = String(txt == null ? '' : txt).replace(/\D/g, '').slice(0, 4);
    if (!d) return { texto:'', hora:null, error:'' };

    var h, mi;
    if (d.length === 1) { h = +d; mi = 0; }
    else if (d.length === 2) {
      h = +d; mi = 0;
      if (h > 23) { h = +d.charAt(0); mi = +d.charAt(1) * 10; }   // "45" -> 4:50
    } else if (d.length === 3) {
      h = +d.slice(0,2); mi = +d.charAt(2) * 10;                   // "133" -> 13:30
      if (h > 23) { h = +d.charAt(0); mi = +d.slice(1); }          // "935" -> 9:35
    } else {
      h = +d.slice(0,2); mi = +d.slice(2);
    }
    if (h > 23 || mi > 59) return { texto:d, hora:null, error:'Esa hora no existe' };

    // El texto del campo SOLO se fuerza con los 4 dígitos (para meter los dos
    // puntos). Mientras va a medias se deja tal cual escribió: reescribirlo le
    // movería el cursor. La hora igual ya quedó leída y el resumen la muestra.
    return { texto: (d.length === 4 ? d.slice(0,2) + ':' + d.slice(2) : d),
             hora: pad(h) + ':' + pad(mi), error:'' };
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

    // UN SOLO ESTADO. Antes había un paso previo con un enlace para abrir, y
    // sobraba: la pestaña "Registrar llamada" de la barra YA es el botón que
    // abre esto. Sin enlaces propios de abrir/cerrar no se repite ningún
    // rótulo y no sobra ni una línea en blanco.
    // CERRADO: nada más que el botón que abre.
    //
    // Los 56 px del contenedor de botones (crm-entity-stream-restapp-btn-
    // container) NO se pueden quitar: medido en el DOM, omitirlos del diseño
    // deja los anteriores colgando, y `visible:false` Bitrix lo escupe tal
    // cual como atributo HTML sin hacer nada. Si esa franja va a estar sí o
    // sí, que sirva: el botón principal ES el que abre. Así desaparecen de un
    // saque el enlace repetido y los dos botones grises muertos.
    //
    // El secundario va con título vacío: es de estilo link (sin fondo), así
    // que vacío no se ve. El principal vacío sí se veía -- era el "punto azul".
    if (colapsado) {
      return {
        // Sin bloques cuando no hay error: el bloque vacio de antes pintaba
        // 12 px de aire justo encima del boton.
        blocks: aviso ? { ok: { type:'text', properties:{ value: aviso, bold:true } } } : {},
        primaryButton:   { title:'Registrar llamada', state:'normal' },
        secondaryButton: { title:'', state:'disabled' }
      };
    }

    var y = vista.getFullYear(), m = vista.getMonth();
    var blocks = {};
    if (aviso) blocks.ok = { type:'text', properties:{ value: aviso, bold:true } };

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
    // Siempre 42 celdas = 6 filas, aunque el mes entre en 5. Si la altura
    // cambia de mes a mes, las flechas ◀ ▶ se mueven y hay que volver a
    // apuntar el mouse en cada salto: eso es tiempo perdido en cada llamada.
    while (celdas.length < 42) celdas.push(null);

    for (var s = 0; s < celdas.length / 7; s++) {
      var fila = {};
      for (var c = 0; c < 7; c++) {
        var dia = celdas[s*7 + c], key = 'c' + c;
        if (dia === null) {
          // Celda vacía como LINK muerto, no como text: medido en el DOM, una
          // fila de puros text mide 3 px menos que una con links, y eso movía
          // los botones al saltar de mes. El link no se ve (solo espacios) y
          // su evento se ignora.
          fila[key] = { type:'link', properties:{
            text: VACIA, action:{ type:'layoutEvent', value:'nada' } } };
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

    // ── hora: se teclean 4 dígitos y solo se acomoda a HH:MM
    blocks.hora = { type:'input', properties:{ title:'Hora', value:hhmm } };

    // ── resumen: la confirmación en palabras, y de paso el a.m./p.m.
    blocks.resumen = { type:'text', properties:{ value: resumenTxt(), bold:true } };
    blocks.cerrar  = { type:'link', properties:{
      text:'Cerrar', size:'sm', action:{ type:'layoutEvent', value:'cerrar' } } };

    var out = botones(true);
    out.blocks = blocks;
    return out;
  }

  function redibujar() { BX24.placement.call('setLayout', layout(), function(){}); }

  /** Retoca UN bloque sin redibujar todo: así el campo no pierde el foco. */
  function retocar(id, props) {
    BX24.placement.call('setLayoutItemState', { id:id, properties:props }, function(){});
  }

  /**
   * Cierra.
   *
   * `finish` va SUELTO, no dentro del callback de setLayout: así lo tenía y
   * por eso no cerraba nunca -- si Bitrix no invoca ese callback, la orden no
   * llega a salir. Primero cerrar, después dejar el respaldo colapsado por si
   * finish no hiciera efecto (mejor una línea que el calendario colgado).
   */
  function cerrar() {
    BX24.placement.call('finish');
    colapsado = true;
    BX24.placement.call('setLayout', layout(), function(){});
  }



  function inicioIso() {
    return sel.y + '-' + pad(sel.m+1) + '-' + pad(sel.d) + 'T' + hhmm + ':00-05:00';
  }
  /** +1h a mano: Date() rompería el offset fijo -05:00. */
  function masUnaHora(iso) {
    var m = iso.match(/^(\d{4}-\d{2}-\d{2})T(\d{2}):(\d{2}):00(-05:00)$/);
    if (!m) return iso;
    return m[1] + 'T' + pad((parseInt(m[2],10)+1)%24) + ':' + m[3] + ':00' + m[4];
  }

  // ── Precarga ──────────────────────────────────────────────────────────
  // Antes cada click disparaba TRES viajes al servidor en fila:
  //   crm.deal.get  ->  crm.contact.get  ->  crm.activity.add
  // Los dos primeros no dependen de nada que el vendedor elija, así que se
  // piden apenas se abre el panel, mientras mira el calendario. Al momento de
  // apretar ya están y solo queda el tercero: de 3 viajes a 1.
  var ctx = null;          // { resp, contactId, nombre, tel }
  var ctxCargando = false;
  var ctxCola = [];        // lo que quedó esperando la precarga

  function precargar() {
    if (ctx || ctxCargando || !dealId) return;
    ctxCargando = true;

    function listo(v) {
      ctxCargando = false; ctx = v;
      var cola = ctxCola; ctxCola = [];
      for (var i = 0; i < cola.length; i++) cola[i]();
    }

    BX24.callMethod('crm.deal.get', { id: dealId }, function (rd) {
      if (rd.error()) { listo(null); return; }
      var deal = rd.data();
      var cid  = parseInt(deal.CONTACT_ID || 0, 10);
      var base = { resp: deal.ASSIGNED_BY_ID, contactId: cid > 0 ? cid : 0, nombre:null, tel:null };
      if (!base.contactId) { listo(base); return; }
      BX24.callMethod('crm.contact.get', { id: base.contactId }, function (rc) {
        if (!rc.error()) {
          var c = rc.data();
          base.nombre = [c.NAME, c.LAST_NAME].filter(Boolean).join(' ').trim() || null;
          base.tel = (c.PHONE && c.PHONE[0] && c.PHONE[0].VALUE) || null;
        }
        listo(base);
      });
    });
  }

  function registrar(contesto) {
    if (!dealId || !sel) return;
    BX24.placement.call('lock');
    // Si nunca tocó la hora, se sella la de ESTE momento, no la del render.
    if (!horaManual) hhmm = ahoraHHMM();
    var inicio = inicioIso();

    function guardar() {
      if (!ctx) {
        BX24.placement.call('unlock');
        aviso = 'No se pudo leer la negociación'; redibujar(); return;
      }
      var fields = {
        OWNER_TYPE_ID:2, OWNER_ID:dealId,
        TYPE_ID:2, DIRECTION:2,
        PROVIDER_ID:'VOXIMPLANT_CALL', PROVIDER_TYPE_ID:'CALL',
        SUBJECT: contesto ? '1234' : ('Llamada saliente ' + (ctx.nombre || 'cliente')),
        COMPLETED:'N', RESPONSIBLE_ID:ctx.resp,
        START_TIME:inicio, END_TIME:masUnaHora(inicio), DEADLINE:inicio,
        PRIORITY:2, NOTIFY_TYPE:1, NOTIFY_VALUE:15, DESCRIPTION_TYPE:1
      };
      if (ctx.contactId && ctx.tel) {
        fields.COMMUNICATIONS = [{ VALUE:ctx.tel, ENTITY_ID:ctx.contactId, ENTITY_TYPE_ID:3, TYPE:'PHONE' }];
      }
      BX24.callMethod('crm.activity.add', { fields: fields }, function (ra) {
        BX24.placement.call('unlock');
        if (ra.error()) { aviso = 'No se pudo guardar: ' + ra.error(); redibujar(); return; }
        // Sin texto de "Guardado": la actividad recien creada YA sale ahi
        // abajo en la linea de tiempo con su fecha limite.
        aviso = '';
        horaManual = false; diaManual = false;
        cerrar();
      });
    }

    // Caso normal: ya está precargado y sale directo. Si el vendedor fue más
    // rápido que la precarga, se encola y arranca sola en cuanto llegue.
    if (ctx) guardar();
    else { ctxCola.push(guardar); precargar(); }
  }

  BX24.init(function () {
    var opt = {};
    try { opt = BX24.placement.info().options || {}; } catch (e) {}
    dealId = parseInt(opt.ENTITY_ID || opt.entityId || opt.ID || 0, 10);

    precargar();                     // adelanta deal+contacto
    redibujar();                     // nace COLAPSADO: frase + los dos botones

    BX24.placement.call('bindLayoutEventCallback', null, function (ev) {
      var v = (ev && ev.value) || '';
      if (v === 'cerrar') {
        cerrar();
      } else if (v === 'abrir') {
        colapsado = false; aviso = ''; precargar(); redibujar();
      } else if (v.indexOf('mes:') === 0) {
        vista.setMonth(vista.getMonth() + parseInt(v.slice(4), 10));
        redibujar();
      } else if (v.indexOf('dia:') === 0) {
        var p = v.slice(4).split('-');
        sel = { y:parseInt(p[0],10), m:parseInt(p[1],10)-1, d:parseInt(p[2],10) };
        diaManual = true; aviso = '';
        redibujar();
      }
    });

    // Hora tecleada. Acá NO se redibuja todo -- se retoca solo el campo y solo
    // cuando lo que hay escrito no coincide con lo que debería quedar. Así el
    // cursor se queda quieto mientras teclea, y a partir del quinto dígito el
    // campo simplemente no cambia: quedan los 4 y nada más.
    BX24.placement.call('bindValueChangeCallback', null, function (ev) {
      if (!ev || ev.id !== 'hora') return;
      var r = normHora(ev.value);
      if (r.hora) { hhmm = r.hora; horaManual = true; aviso = ''; }
      if (r.texto !== String(ev.value == null ? '' : ev.value) || r.error) {
        retocar('hora', { title:'Hora', value:r.texto, errorText:r.error });
      }
      retocar('resumen', { value: resumenTxt(), bold:true });
    });

    // Cerrado, el principal abre; abierto, guarda. Es el mismo boton con dos
    // trabajos porque esa franja de 56 px esta siempre, se use o no.
    BX24.placement.call('bindPrimaryButtonClickCallback',   null, function(){
      if (colapsado) { colapsado = false; aviso = ''; precargar(); redibujar(); return; }
      registrar(true);
    });
    BX24.placement.call('bindSecondaryButtonClickCallback', null, function(){
      if (colapsado) return;              // vacio y deshabilitado: no hace nada
      registrar(false);
    });
  });
})();
</script>
</body>
</html>
