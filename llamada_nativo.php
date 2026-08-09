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

  // Aire entre columnas. Medido: sin esto el paso es 21 px y el del selector
  // nativo de Bitrix es ~25 px -- por eso el suyo se lee mas suelto. Dos
  // U+2006 = 4,35 px, y como va en TODAS las celdas (numeros y encabezado)
  // no corre el aplome.
  var SEP = '\u2006\u2006';
  var AIRE = '\u2007';          // separación de los atajos: más suelto que SEP

  /**
   * Rótulos y sangrías, todo calculado con anchos MEDIDOS en el render real.
   *
   * Los espacios no valen lo mismo en cada fila porque cada una va en otro
   * tamaño: la celda del calendario en 14 px, los atajos en 13, la rueda en
   * 16. Por eso cada sangría se arma con SUS anchos:
   *     14 px → U+2007 8,668 · U+2006 2,174 · U+200A 0,834
   *     13 px → U+2007 8,112 · U+2006 2,082 · U+200A 0,838
   *     16 px → U+2007 9,984 · U+2006 2,344 · U+200A 0,781
   * "Hora" mide 29,148 y "Minuto" 41,596: se empareja el corto para que los
   * números de las dos filas arranquen en la misma vertical.
   * El eje es la fila de horas con su rótulo: 424 px.
   */
  // Los tres rótulos van en NEGRITA, como el nombre del mes, y se emparejan
  // al más ancho para que hagan columna. Ojo: la negrita mide distinto, así
  // que estos anchos son los de negrita 13 px -- Tiempo 48,128 · Minuto
  // 44,748 · Hora 31,129 -- y el relleno usa los espacios de esa misma
  // negrita: U+2007 8,652 · U+2006 2,082 · U+200A 0,762.


  var RAYA = '\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500';

  // Dentro de la caja de la hora no se centra fila por fila: las tres
  // arrancan pegadas a la izquierda y los rótulos hacen la columna. Lo único
  // que se centra es el calendario, sobre el ancho de la fila de horas, que
  // con el rótulo en negrita queda en 434,6 px.

  // El encabezado va a 12 px y los números a 14, así que sus espacios NO miden
  // lo mismo (U+2007 vale 7,559 contra 8,668). Con un relleno fijo el paso del
  // encabezado se iba de 19,1 a 26,4 px mientras el de los números es 25,3
  // clavado -- por eso las columnas se veían corridas. Acá cada rótulo se
  // rellena por separado hasta esos 25,3 px, con los espacios de SU tamaño.
  // Anchos medidos a 12 px: LU 15,369 · MA 18,574 · MI 13,699 · JU 15,305 ·
  // VI 11,297 · SA 15,732 · DO 17,977. Ojo: Bitrix mete un &nbsp; entre
  // bloques que suma 3,9 px, así que el relleno apunta a 21,40 y no a 25,30.
  // Peor error: 0,29 px.
  var SEPCAB = {
    LU: '\u2006\u2006\u2006',
    MA: '\u2006\u200A',
    MI: '\u2007',
    JU: '\u2006\u2006\u2006',
    VI: '\u2007\u2006\u200A',
    SA: '\u2006\u2006\u200A\u200A',
    DO: '\u2006\u200A\u200A'
  };

  // misma sangría que los números (127,37 px) pero con espacios de 12 px
  var SANG_CAB = '\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2006\u2006\u2006\u200A';

  var SANGRIA = {
    celda: '\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2006\u2006\u2006\u200A',
    raya:  '\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2006\u2006\u200A'
  };

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
  var importante = false;      // el fuego de Bitrix = PRIORITY 3 (high)
  var comentario = '';         // el mismo comentario de la pestaña Comentario
  var aviso  = '';
  // Sin estado colapsado. Antes había uno que pintaba un botón azul
  // "REGISTRAR LLAMADA", y sobraba: la pestaña "Seguimiento" de la barra ya es
  // el botón que abre esto, y mientras esté elegida otra pestaña Bitrix no
  // muestra nada de la app. El botón era un segundo clic para nada.
  //
  // CLAVE: los dos botones van SIEMPRE en el diseño. Cuando los omití, Bitrix
  // dejó los anteriores colgando y pintó uno vacío (el punto azul suelto).
  // ButtonDto solo acepta title y state: no se pueden ocultar.

  /**
   * Relleno para que TODOS los meses midan lo mismo y la flecha ▶ no se mueva.
   *
   * Medido en el render real (bold 14px system-ui): el mes más angosto es
   * julio con 28,9 px y el más ancho septiembre con 78,6 px. Sin compensar, la
   * flecha se corre casi 50 px de un mes a otro y hay que volver a apuntar el
   * mouse en cada salto -- justo lo que impide pasar varios meses seguidos.
   *
   * Cada entrada es [izquierda, derecha], con los mismos espacios de ancho
   * conocido que usan las celdas: U+2007 = 9,249 px · U+2006 = 2,174 ·
   * U+200A = 0,752. Peor desfase que queda: 0,73 px.
   */
  var RELLENO = {
    'enero':       ['\u2007\u2007\u200A', '\u2007\u2007\u200A'],
    'febrero':     ['\u2007\u2006\u2006', '\u2007\u2006\u2006'],
    'marzo':       ['\u2007\u2006\u2006\u2006\u200A\u200A\u200A', '\u2007\u2006\u2006\u2006\u200A\u200A\u200A'],
    'abril':       ['\u2007\u2007\u2006\u2006\u200A', '\u2007\u2007\u2006\u2006\u200A'],
    'mayo':        ['\u2007\u2007\u2006', '\u2007\u2007\u2006'],
    'junio':       ['\u2007\u2007\u2006\u200A\u200A', '\u2007\u2007\u2006\u200A\u200A'],
    'julio':       ['\u2007\u2007\u2006\u2006\u200A\u200A\u200A', '\u2007\u2007\u2006\u2006\u200A\u200A\u200A'],
    'agosto':      ['\u2007\u2006\u2006\u200A\u200A\u200A', '\u2007\u2006\u2006\u200A\u200A\u200A'],
    'septiembre':  ['', ''],
    'octubre':     ['\u2007\u2006\u200A', '\u2007\u2006\u200A'],
    'noviembre':   ['\u2006\u200A', '\u2006\u200A'],
    'diciembre':   ['\u2006\u2006\u200A', '\u2006\u2006\u200A'],
  };


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
  /** Hora actual redondeada al múltiplo de 5 más cercano. */
  function ahoraHHMM() {
    var d = new Date(), t = d.getHours()*60 + Math.round(d.getMinutes()/5)*5;
    t = ((t % 1440) + 1440) % 1440;
    return pad(Math.floor(t/60)) + ':' + pad(t % 60);
  }

  /** Corre la hora N minutos, con acarreo y dando la vuelta en medianoche. */
  function mover(min) {
    var t = parseInt(hhmm.slice(0,2),10)*60 + parseInt(hhmm.slice(3),10) + min;
    t = ((t % 1440) + 1440) % 1440;
    hhmm = pad(Math.floor(t/60)) + ':' + pad(t % 60);
    horaManual = true; aviso = '';
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
    var y = vista.getFullYear(), m = vista.getMonth();
    var blocks = {};
    if (aviso) blocks.ok = { type:'text', properties:{ value: aviso, bold:true } };

    // ── encabezado: ◀  agosto 2026  ▶
    var cal = {};
    cal.nav = { type:'lineOfBlocks', properties:{ blocks:{
      ant: { type:'link', properties:{ text: SANGRIA.celda + '◀', action:{ type:'layoutEvent', value:'mes:-1' } } },
      tit: { type:'text', properties:{
        value: EC + RELLENO[MESES[m]][0] + MESES[m] + ' ' + y + RELLENO[MESES[m]][1] + EC,
        bold:true } },
      sig: { type:'link', properties:{ text:'▶', action:{ type:'layoutEvent', value:'mes:1' } } }
    }}};

    // ── fila de días de la semana (2 caracteres, igual que los números)
    var cab = {};
    // Sáb y Dom en rojo, como el selector nativo. Acá SÍ se puede porque el
    // encabezado es `text` -- medido: color danger = rgb(255,87,82). En los
    // días no se puede: ahí son `link`, y `link` ignora color (probado en el
    // DOM: el sábado salía con el mismo azul que el viernes).
    for (var i = 0; i < 7; i++) cab['h'+i] = { type:'text', properties:{
      value: (i === 0 ? SANG_CAB : '') + DOW[i] + SEPCAB[DOW[i]],
      size:'xs', color: (i >= 5 ? 'danger' : 'base_50') } };
    cal.dow = { type:'lineOfBlocks', properties:{ blocks: cab } };
    // raya bajo los días, como el selector nativo
    cal.raya = { type:'text', properties:{ value: SANGRIA.raya + RAYA, color:'base_50' } };

    // ── celdas del mes, lunes primero.
    // La grilla se rellena con los días del mes anterior y del siguiente, en
    // gris, igual que el selector nativo de Bitrix: un bloque completo se lee
    // mucho mejor que uno con huecos. Además así toda fila tiene 7 números.
    var offset   = (new Date(y, m, 1).getDay() + 6) % 7;
    var total    = new Date(y, m + 1, 0).getDate();
    var totalAnt = new Date(y, m, 0).getDate();

    var celdas = [];
    for (var o = offset; o > 0; o--) celdas.push({ d: totalAnt - o + 1, otro:true });
    for (var d = 1; d <= total; d++)  celdas.push({ d: d, otro:false });
    var sig = 1;
    while (celdas.length < 42) celdas.push({ d: sig++, otro:true });

    for (var s = 0; s < 6; s++) {
      var fila = {};
      for (var c = 0; c < 7; c++) {
        var cel = celdas[s*7 + c], key = 'c' + c, dia = cel.d;
        var sang = (c === 0 ? SANGRIA.celda : '');
        if (cel.otro || esPasado(y, m, dia)) {
          // gris: ni de este mes, o ya pasó. No se puede planificar ahí.
          fila[key] = { type:'text', properties:{ value: sang + celda(dia) + SEP, color:'base_50' } };
        } else if (sel && sel.y===y && sel.m===m && sel.d===dia) {
          // el elegido va como TEXTO oscuro y en negrita: contra el azul de
          // los links se distingue de un vistazo, sin cambiar de ancho.
          fila[key] = { type:'text', properties:{ value: sang + celda(dia) + SEP, bold:true } };
        } else {
          // Sábado y domingo NO se pueden pintar de rojo: `color` solo existe
          // en el bloque `text`, y ahí el día deja de ser clickeable. Probado
          // en el DOM: mandando color:'danger' en el link, el sábado sale con
          // el mismo rgb(32,102,176) que el viernes, sin clase ni atributo.
          fila[key] = { type:'link', properties:{
            text: sang + celda(dia) + SEP,
            action:{ type:'layoutEvent', value:'dia:'+y+'-'+pad(m+1)+'-'+pad(dia) }
          }};
        }
      }
      cal['sem'+s] = { type:'lineOfBlocks', properties:{ blocks: fila } };
    }

    // ── hora: dos ruedas, hora y minuto, como el control nativo de Bitrix.
    // Reemplaza al campo de texto: se va el rectángulo de ancho completo y de
    // paso todos los enredos de teclear. El minuto va de 5 en 5 porque así lo
    // hacen: de 400 llamadas leídas, TODOS los minutos son múltiplo de 5.
    // ── comentario. Antes tenian que saltar a la pestana "Comentario" para
    // esto; ahora va en el mismo panel y se manda solo o junto con la llamada.
    blocks.coment = { type:'textarea', properties:{
      title:'Comentario', value: comentario, placeholder:'Qué pasó en la llamada' } };
    if (comentario.replace(/\s/g,'')) {
      blocks.enviar = { type:'link', properties:{ text:'Enviar comentario', size:'sm',
        action:{ type:'layoutEvent', value:'coment' } } };
    }

    // El calendario va en su propia caja: antes quedaba pegado al borde
    // izquierdo mientras la hora arrancaba 16 px mas adentro (el padding del
    // section). Dos cajas iguales = todo a plomo, y de paso cada cosa queda
    // agrupada en vez de ser una lista larga de numeros sueltos.
    blocks.cal = { type:'section', properties:{ type:'withBorder', blocks: cal } };

    var hh = hhmm.slice(0,2), mi = hhmm.slice(3);

    // ── hora, dentro de una caja (section withBorder: borde de 0,75 px y
    // esquinas de 10 px, medido en el DOM). Dos filas que se complementan:
    //   arriba  − 11 +  :  − 00 +   para afinar (el area clickeable ES el
    //                                texto, por eso los signos van con un
    //                                espacio de cifra a cada lado: de 9 px
    //                                de ancho pasan a ~27)
    //   abajo   08 … 20             para saltar directo a la hora
    // El rango 08-20 sale de los datos: de 400 llamadas leídas en Bitrix, no
    // hay ninguna fuera de esa franja salvo tres sueltas.
    var ruedas = { type:'lineOfBlocks', properties:{ blocks:{
      hmen: { type:'link', properties:{ text: EC+'\u2212'+EC, size:'xl', bold:true, action:{ type:'layoutEvent', value:'h-1' } } },
      hval: { type:'text', properties:{ value: EC + hh + EC, bold:true, size:'xl' } },
      hmas: { type:'link', properties:{ text: EC+'+'+EC, size:'xl', bold:true, action:{ type:'layoutEvent', value:'h+1' } } },
      dos:  { type:'text', properties:{ value: EC + ':' + EC, bold:true, size:'xl' } },
      mmen: { type:'link', properties:{ text: EC+'\u2212'+EC, size:'xl', bold:true, action:{ type:'layoutEvent', value:'m-5' } } },
      mval: { type:'text', properties:{ value: EC + mi + EC, bold:true, size:'xl' } },
      mmas: { type:'link', properties:{ text: EC+'+'+EC, size:'xl', bold:true, action:{ type:'layoutEvent', value:'m+5' } } }
    }}};

    // Fila de atajos: un click y queda. Va una para la hora y otra para el
    // minuto -- no se puede "aplastar y escribir" sobre el número, porque
    // dentro de un lineOfBlocks solo entran `text` y `link`: el `input` es de
    // ancho completo y el `dropdownMenu` se va a su propio renglón (medido).
    // El valor activo va en negrita y sin enlace, igual que el día elegido.
    // `pre` va en la clave de cada bloque: las dos filas comparten valores
    // (10, 15, 20) y con la misma clave quedaban ids duplicados en el mismo
    // diseño -- verificado en el DOM: q10, q15 y q20 aparecían dos veces.
    function filaAtajos(pre, desde, hasta, paso, actual, evento, sangria) {
      var f = {};
      for (var q = desde; q <= hasta; q += paso) {
        f[pre+q] = (pad(q) === actual)
          ? { type:'text', properties:{ value: celda(q) + AIRE, bold:true, size:'sm' } }
          : { type:'link', properties:{ text: celda(q) + AIRE, size:'sm',
                action:{ type:'layoutEvent', value: evento + pad(q) } } };
      }
      return { type:'lineOfBlocks', properties:{ blocks: f } };
    }

    // withTitle con inline: la columna del rótulo la arma Bitrix (titleWidth
    // 'sm' = 100 px medidos). Antes esos rótulos se emparejaban a mano con
    // espacios de ancho medido y había que rehacer las cuentas cada vez que
    // cambiaba el tamaño o la negrita.
    function conRotulo(titulo, bloque) {
      return { type:'withTitle', properties:{
        title: titulo, inline:true, titleWidth:'sm', block: bloque } };
    }

    blocks.caja = { type:'section', properties:{ type:'withBorder', blocks:{
      ruedas:  conRotulo('Tiempo', ruedas),
      horas:   conRotulo('Hora',   filaAtajos('h', 8, 20, 1, hh, 'hora:', '')),
      minutos: conRotulo('Minuto', filaAtajos('m', 0, 45, 15, mi, 'min:', ''))
    }}};

    // ── resumen: la confirmación en palabras, y de paso el a.m./p.m.
    // Confirmación en tarjeta celeste (#E5F9FF medido): es el bloque de aviso
    // que usa Bitrix en sus propias pantallas, y separa lo que se va a guardar
    // de los controles de arriba.
    blocks.resumen = { type:'section', properties:{ type:'primary', blocks:{
      t: { type:'text', properties:{ value: resumenTxt(), bold:true } }
    }}};
    // Importante y Cerrar comparten fila: el fuego de Bitrix es PRIORITY 3
    // ("high", confirmado con crm.enum.activitypriority) y algunos vendedores
    // lo usan -- de 400 llamadas leídas, 5 venían marcadas.
    blocks.pie = { type:'lineOfBlocks', properties:{ blocks:{
      imp: { type:'link', properties:{
        text: (importante ? '\u2611' : '\u2610') + ' Importante', size:'sm',
        action:{ type:'layoutEvent', value:'imp' } } },
    }}};

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

  /** Publica el comentario en la línea de tiempo, igual que la pestaña nativa. */
  function mandarComentario(luego) {
    var txt = comentario.replace(/^\s+|\s+$/g, '');
    if (!dealId || !txt) { if (luego) luego(); return; }
    BX24.placement.call('lock');
    BX24.callMethod('crm.timeline.comment.add', {
      fields: { ENTITY_ID: dealId, ENTITY_TYPE: 'deal', COMMENT: txt }
    }, function (r) {
      BX24.placement.call('unlock');
      if (r.error()) { aviso = 'No se pudo comentar: ' + r.error(); redibujar(); return; }
      if (luego) luego();
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
        PRIORITY: importante ? 3 : 2,      // 3 = high = el fuego
        NOTIFY_TYPE:1, NOTIFY_VALUE:15, DESCRIPTION_TYPE:1
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
        horaManual = false; diaManual = false; importante = false;
        // si escribió algo, se publica junto con la llamada: un solo paso
        mandarComentario(function(){
          comentario = '';
          aviso = 'Guardado \u2713';
          redibujar();
        });
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
      if (v.indexOf('hora:') === 0) {
        hhmm = v.slice(5) + ':' + hhmm.slice(3);
        horaManual = true; aviso = ''; redibujar();
      } else if (v.indexOf('min:') === 0) {
        hhmm = hhmm.slice(0,2) + ':' + v.slice(4);
        horaManual = true; aviso = ''; redibujar();
      } else if (v === 'h-1') { mover(-60); redibujar();
      } else if (v === 'h+1') { mover(60);  redibujar();
      } else if (v === 'm-5') { mover(-5);  redibujar();
      } else if (v === 'm+5') { mover(5);   redibujar();
      } else if (v === 'imp') {
        importante = !importante; redibujar();
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
      if (!ev || ev.id !== 'coment') return;
      var antes = !!comentario.replace(/\s/g,'');
      comentario = String(ev.value == null ? '' : ev.value);
      // solo se redibuja cuando aparece o desaparece el enlace de enviar: si
      // no, cada tecla redibujaria el textarea y le movería el cursor.
      if (antes !== !!comentario.replace(/\s/g,'')) redibujar();
    });

    BX24.placement.call('bindPrimaryButtonClickCallback',   null, function(){
      registrar(true);
    });
    BX24.placement.call('bindSecondaryButtonClickCallback', null, function(){
      registrar(false);
    });
  });
})();
</script>
</body>
</html>
