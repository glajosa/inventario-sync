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
require_once __DIR__ . '/feriados.php';

// Los feriados se calculan en PHP (la Pascua mueve Carnaval y Viernes Santo) y
// viajan al navegador como una lista plana. Una sola fuente de verdad: el mismo
// archivo lo puede usar después el motor de puntaje.
$FERIADOS_JS = json_encode(fer_lista((int)date('Y'), (int)date('Y') + 2));
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
  // Tres letras como el nativo: "Lun Mar Mié" se lee mucho mejor que "LU MA MI".
  var DOW   = ['Lun','Mar','Mié','Jue','Vie','Sáb','Dom'];
  var ANCHO_DOW = { 'Lun':20.818, 'Mar':21.680, 'Mié':20.309, 'Jue':20.314,
                    'Vie':17.906, 'Sáb':21.639, 'Dom':26.250 };

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


  // ── Centrado ────────────────────────────────────────────────────────────
  // Todo se centra sobre UN eje: el de la fila más ancha, que es la de horas
  // (478,3 px medidos). Antes cada fila arrancaba pegada a la izquierda y el
  // calendario --mucho más angosto-- se veía corrido. Cada sangría va armada
  // con los espacios del tamaño de SU fila: calendario 14 px, encabezado 12,
  // rueda 16, minutos 13. Peor error: 0,35 px.
  // ── Centrado sobre el CENTRO DE LA TARJETA ──────────────────────────────
  // Medido: las tres tarjetas tienen su contenido de 808,8 a 1669,5, o sea
  // centro 1239,2. Antes yo centraba todo sobre el eje de la fila de horas
  // (1157), que no es el centro de la caja -- por eso se veía corrido a la
  // izquierda aunque las filas coincidieran entre sí.
  //
  // Cada sangría se arma con los espacios del tamaño de SU fila: calendario
  // 14 px, encabezado 12, rueda 16, horas y minutos 13, pie 13.
  // Peor error: 0,39 px.
  var SANG_FILA     = '\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2006\u200A\u200A';
  var SANG_FILA_CAB = '\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2006\u2006\u2006\u200A';
  var SANG_RAYA     = '\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2006\u2006';
  var SANG_NAV      = '\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007';
  var SANG_RUE      = '\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2006\u200A\u200A';
  var SANG_MANANA = '\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2006\u2006';
  var SANG_TARDE  = '\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u200A\u200A';
  var SANG_MIN = '\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2006\u2006';
  var SANG_PIE      = '\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u2007\u200A';
  var RAYA = '\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500';


  // ── Grilla del calendario ────────────────────────────────────────────────
  // El selector nativo se lee mejor por dos razones concretas, y las dos se
  // pueden copiar:
  //   1. NO lleva cero adelante: dice 3 4 5, no 03 04 05. La mitad de los
  //      dígitos desaparece de la pantalla.
  //   2. Las columnas son más anchas. El nombre más largo, "Dom", mide 26,25
  //      px a 12 px de letra, así que la celda pasa de 21,4 a 26,3.
  //
  // Para que igual queden a plomo se calcula el relleno de CADA celda con el
  // ancho real de sus dígitos (medidos en el render, no supuestos) en vez de
  // parchear a mano los dígitos angostos. Peor error: 0,41 px.
  var DIG = {'0':8.668,'1':6.344,'2':8.299,'3':8.627,'4':8.859,
             '5':8.504,'6':8.764,'7':7.820,'8':8.791,'9':8.764};
  // Los mismos dígitos en NEGRITA: el día elegido va en peso 700 y ahí todo
  // mide más. Sin esto, su celda queda ~1,4 px más ancha y corre las de al lado.
  var DIG_B = {'0':9.434,'1':7.014,'2':8.853,'3':9.215,'4':9.468,
               '5':9.140,'6':9.420,'7':8.313,'8':9.563,'9':9.420};
  var E14 = [8.668, 2.174, 0.834];      // U+2007 · U+2006 · U+200A a 14 px
  var E14B = [9.434, 2.365, 0.907];     // los mismos, en negrita
  var E12 = [7.559, 1.992, 0.844];      // los mismos a 12 px
  var CELDA = 26.30;

  // ── Fila de horas con a.m./p.m. ─────────────────────────────────────────
  // "8 … 20" obliga a traducir mentalmente; con el sufijo se lee directo.
  // La más ancha es 12pm: 32,817 px normal y 35,236 en negrita (la activa va
  // en negrita), así que la celda va a 36,50 y la fila queda en 525,2 px.
  // Los atajos van en DOS filas agrupadas, con el número solo.
  //
  // Con "8Am 9Am 10Am ... 8Pm" en una sola línea eran 13 etiquetas de cuatro
  // caracteres: cincuenta caracteres seguidos, y se leía como un bloque de
  // texto. El sufijo estaba para saber si el 3 era de la mañana o de la tarde
  // -- y eso lo dice mejor el rótulo de la fila. Así el número queda solo, con
  // la misma celda de 26,30 px que el calendario.
  var MINUTOS = [[0,'0'],[15,'15'],[30,'30'],[45,'45']];
  var MANANA  = [[8,'8'],[9,'9'],[10,'10'],[11,'11']];
  var TARDE   = [[12,'12'],[13,'1'],[14,'2'],[15,'3'],[16,'4'],
                 [17,'5'],[18,'6'],[19,'7'],[20,'8']];
  var E13B = [8.830, 2.265, 0.912];     // espacios a 13 px en negrita


  /** Arma un relleno de `faltan` px con los espacios de ancho conocido. */
  function relleno(faltan, esp) {
    if (faltan <= 0) return '';
    var n7 = Math.floor(faltan / esp[0]); var r = faltan - n7 * esp[0];
    var n6 = Math.floor(r / esp[1]);      r -= n6 * esp[1];
    var nA = Math.round(r / esp[2]);
    return Array(n7+1).join('\u2007') + Array(n6+1).join('\u2006') + Array(nA+1).join('\u200A');
  }

  /**
   * Un día tal cual se ve: sin cero adelante y CENTRADO en su celda.
   *
   * El relleno va MITAD Y MITAD, no todo al final. Poniéndolo solo al final
   * cada número quedaba pegado a la izquierda de su celda: con dos cifras casi
   * no se nota, pero la fila "3 4 5 6 7 8 9" se veía corrida contra la
   * "10 11 12 13...". Eso era el desorden que se veía y que las mediciones no
   * mostraban, porque las CELDAS sí estaban alineadas -- lo que estaba mal era
   * dónde caía el número adentro.
   */
  function centrar(txt, ancho, esp, celda) {
    var falta = (celda || CELDA) - ancho;
    if (falta <= 0) return txt;
    return relleno(falta / 2, esp) + txt + relleno(falta - falta / 2, esp);
  }

  function celda(n, negrita) {
    var t = '' + n, tabla = negrita ? DIG_B : DIG, ancho = 0;
    for (var i = 0; i < t.length; i++) ancho += tabla[t.charAt(i)];
    return centrar(t, ancho, negrita ? E14B : E14);
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

  /**
   * Los botones se arman en un solo lugar: nunca falta ninguno.
   *
   * NO se pueden pintar. ButtonDto solo acepta `title` y `state`, y `state`
   * solo entiende normal/disabled: probé state:'danger' y Bitrix descartó el
   * diseño entero -- los dos botones salieron VACÍOS en el DOM. Lo único que
   * sí se ve es un símbolo en el título, así que "No contestó" lleva una cruz
   * roja para que se lea como lo negativo de un vistazo.
   */
  /**
   * La franja de botones de Bitrix mide 56 px SIEMPRE y no se puede ocultar
   * (probado: `visible:false` sale como atributo HTML y no hace nada). Como
   * está ahí a la fuerza, se usa para lo único que hace falta después de
   * registrar: DESHACER. El secundario va vacío — es `ui-btn-link`, sin fondo,
   * así que vacío no se ve.
   */
  function botones() {
    return {
      primaryButton:   { title: registrado ? 'Deshacer' : '', state: registrado ? 'normal' : 'disabled' },
      secondaryButton: { title: '' }
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

  /** "el vie 14 de agosto", pero "hoy"/"mañana" van sin artículo. */
  function conEl(t) { return (t === 'hoy' || t === 'ma\u00f1ana') ? t : 'el ' + t; }

  /** "vie 14 de agosto" — o "hoy"/"mañana" cuando corresponde. */
  function textoFecha(f) {
    var h = hoy(), d = new Date(f.y, f.m, f.d);
    var difd = Math.round((d - new Date(h.y, h.m, h.d)) / 86400000);
    if (difd === 0) return 'hoy';
    if (difd === 1) return 'ma\u00f1ana';
    return DIAN[d.getDay()] + ' ' + f.d + ' de ' + MESES[f.m];
  }

  /** La frase que se lee en los dos estados: siempre la misma, sin repetir rótulos. */
  function resumenTxt() {
    var f = new Date(sel.y, sel.m, sel.d);
    return 'Vuelvo a llamar el ' + DIAN[f.getDay()] + ' ' + sel.d + ' de ' + MESES[sel.m]
         + (esHoy(sel.y, sel.m, sel.d) ? ' (hoy)' : '') + ', ' + horaTxt();
  }


  /**
   * UNA sola caja. El vendedor no elige nada: apretar la pestaña YA registró
   * la llamada sin contestar, y esto solo le dice qué pasó y para cuándo quedó.
   *
   * Lo que se sacó a propósito, y por qué:
   *   · el botón "Sí contestó"        → ese flujo sigue siendo manual, aparte
   *   · "el cliente pidió otra fecha" → pertenece al flujo del que sí contestó
   *   · el calendario y la hora       → la fecha la pone la escalera, no él
   *   · el comentario                 → la actividad ya se creó antes de escribir
   */
  function layout() {
    var TOCA = {
      'NUEVO':        'Primer contacto',
      'ESCALERA-1':   '2\u00ba intento',
      'ESCALERA-2':   '3\u00ba intento',
      'MANTENIMIENTO':'Mantenimiento',
      'CONTACTADO':   'Devolver la llamada'
    };
    var blocks = {};

    if (aviso && aviso.indexOf('No se pudo') === 0) {
      blocks.err = { type:'section', properties:{ type:'danger', blocks:{
        a: { type:'text', properties:{ value: aviso, bold:true } } }}};
      return { blocks: blocks, primaryButton:{title:''}, secondaryButton:{title:''} };
    }

    if (!protocolo) {
      blocks.esp = { type:'text', properties:{ value:'Registrando\u2026', color:'base_50' } };
      var b = botones(); b.blocks = blocks; return b;
    }

    // Renglón de arriba: en qué escalón quedó. Es lo que pediste ver.
    blocks.estado = { type:'text', properties:{ size:'sm', color:'base_70',
      value: (TOCA[protocolo.estado] || protocolo.estado) + '  \u00b7  ' + protocolo.estado } };

    if (deshecho) {
      blocks.caja = { type:'section', properties:{ type:'warning', blocks:{
        a: { type:'text', properties:{ bold:true, value:'Deshecho. No qued\u00f3 registrada.' } } }}};
      var b2 = botones(); b2.blocks = blocks; return b2;
    }

    // Lo que se muestra es lo que se guard\u00f3, no un rec\u00e1lculo: ver calcularProxima().
    // Si todav\u00eda no se registr\u00f3 (no deber\u00eda pasar ac\u00e1), se calcula al vuelo.
    var pr = proxAgendada || calcularProxima(false);
    blocks.caja = { type:'section', properties:{ type:'primary', blocks:{
      a: { type:'text', properties:{ bold:true,
           value:'No contest\u00f3  \u2192  vuelvo a llamar ' + conEl(textoFecha(pr.f))
                 + ' a las ' + pr.hm } }
    }}};

    var b3 = botones(); b3.blocks = blocks; return b3;
  }

  function redibujar() { BX24.placement.call('setLayout', layout(), function(){}); }





  /**
   * La fecha Y LA HORA que se guardan.
   *
   * Sin pacto las pone la escalera; con pacto, lo que eligió el vendedor
   * porque el cliente lo dijo.
   *
   * La fecha se corre al día hábil más cercano PREFIRIENDO HACIA ATRÁS. Ver
   * feriados.php: adelantar la llamada no cuesta puntos (el atraso es
   * max(0, gap - plazo) y no baja de cero), retrasarla sí. Medido en la regla
   * ②: empujar del sábado al lunes baja de 10 a 6 puntos por un día que la
   * empresa no trabaja — un castigo que el vendedor no se ganó.
   */
  /**
   * ⭐ UNA SOLA VERDAD para la próxima llamada.
   *
   * Antes había dos cálculos y no coincidían:
   *   · el que se GUARDABA, hecho antes de registrar
   *   · el que se MOSTRABA en la caja azul, hecho después de registrar — y por
   *     eso con `protocolo.sinContestar` ya incrementado, o sea corrido un
   *     escalón entero.
   *
   * Verificado en el deal 401877 (sin llamadas previas): guardaba el deadline a
   * +1 día y la caja decía "vuelvo a llamar el jue 20", que es +6. El vendedor
   * leía una fecha y en Bitrix quedaba otra.
   *
   * Ahora se calcula UNA vez, antes de guardar, y queda en `proxAgendada`. La
   * caja muestra exactamente eso.
   */
  var proxAgendada = null;    // { f:{y,m,d}, hm:'HH:MM' }

  function calcularProxima(contesto) {
    if (modoPacto && sel) return { f: sel, hm: hhmm };
    return { f: habilCercano(fechaMas(diasProxima(contesto))), hm: horaProxima(contesto) };
  }

  function inicioIso(contesto) {
    proxAgendada = calcularProxima(contesto);
    var f = proxAgendada.f;
    return f.y + '-' + pad(f.m+1) + '-' + pad(f.d) + 'T' + proxAgendada.hm + ':00-05:00';
  }

  /**
   * ⭐ LA FRANJA ROTA — no insistir a la hora que ya falló.
   *
   * Si el cliente no contestó a las 4 de la tarde, volver a marcarle a las 4 de
   * la tarde del día siguiente es repetir el experimento que salió mal: a esa
   * hora está ocupado. Cada intento se corre a una franja distinta, y los
   * últimos salen del horario de oficina, que es donde queda gente que trabaja
   * todo el día.
   *
   *   2º intento  → la franja OPUESTA a la de ahora (tarde ↔ mañana)
   *   3º intento  → hora de almuerzo, 12:30
   *   4º y sigs.  → 19:00, fuera de jornada
   *
   * Con pacto no aplica: ahí manda la hora que dijo el cliente.
   */
  function horaProxima(contesto) {
    if (contesto) return HORA_AGENDA;
    // La franja rota SEGÚN LA HORA DE ESTA LLAMADA, no según el número de
    // intento: lo que importa es no repetir la hora en que el cliente no pudo
    // atender. El ciclo recorre el día y vuelve a empezar:
    //
    //     mañana 09:30 → almuerzo 12:30 → tarde 16:00 → noche 19:00 → mañana…
    //
    // Las 19:00 son el TECHO: nunca se agenda más tarde. Después de esa hora el
    // ciclo vuelve a la mañana del día siguiente.
    var h = new Date().getHours();
    if (h < 11) return '12:30';        // llamó temprano  → almuerzo
    if (h < 14) return '16:00';        // llamó al mediodía → tarde
    if (h < 18) return '19:00';        // llamó en la tarde → fuera de jornada
    return '09:30';                    // llamó de noche  → mañana siguiente
  }

  /**
   * Corre la fecha al día hábil más cercano, hacia atrás y si no hacia
   * adelante. Los feriados los calcula PHP (la Pascua mueve Carnaval y Viernes
   * Santo) y llegan acá como lista.
   */
  function habilCercano(f) {
    function iso(x){ return x.y + '-' + pad(x.m+1) + '-' + pad(x.d); }
    function esHabil(x){
      var d = new Date(x.y, x.m, x.d), n = d.getDay();     // 0 dom · 6 sab
      return n !== 0 && n !== 6 && FERIADOS.indexOf(iso(x)) === -1;
    }
    function corrida(x, dias){
      var d = new Date(x.y, x.m, x.d); d.setDate(d.getDate() + dias);
      return { y:d.getFullYear(), m:d.getMonth(), d:d.getDate() };
    }
    if (esHabil(f)) return f;
    // ⭐ HACIA ADELANTE, nunca hacia atrás.
    //
    // Adelantar la llamada parecía gratis en puntos, pero ACUMULA: si todo lo
    // que cae sábado y domingo se empuja al viernes, el viernes le queda una
    // montaña al vendedor. Se corre al siguiente día hábil, y el colchón de un
    // día que deja PLAZO absorbe el corrimiento sin costar puntos.
    for (var j = 1; j <= 15; j++) {
      var b = corrida(f, j);
      if (esHabil(b)) return b;
    }
    return f;
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
  // ── El protocolo ────────────────────────────────────────────────────────
  // La fecha de la próxima llamada NO la elige el vendedor: la dicta la
  // escalera del documento "El Modelo de Proyección". El deadline que él
  // ponía a mano se cumplía el 45% de las veces (21% antes, 34% después), o
  // sea que el dato no servía y encima se lo calificaba por él.
  //
  //   ① entró el deal        → hoy
  //   ② 1ª sin contestar     → +2 días
  //   ③ 2ª sin contestar     → +7 días
  //   ④ 3ª sin contestar     → +30 días, y de ahí cada 100
  //   ⑤ contestó             → +3 días, salvo que el cliente pacte fecha
  //
  // El estado se calcula recorriendo las salientes en orden de CREATED, no
  // sumando contadores: dos deals con los mismos números pueden estar en
  // estados opuestos según el ORDEN. Contestada = SUBJECT contiene "1234".
  // Días hasta la PRÓXIMA llamada, contados desde ésta.
  //
  // Los plazos son los del DOCUMENTO, contados DESDE LA LLAMADA ANTERIOR:
  //
  //     "ESCALERA-1: 1 intento sin contestar -> 2o en 2 dias"
  //     "ESCALERA-2: 2 intentos sin contestar -> 3er en 7 dias"
  //     momento 4: mantenimiento 1o, plazo 30 · 4b: los siguientes, cada 100
  //
  // (spec-original/reglas_calificacion.json — el mismo criterio que CAL_REGLAS
  // del motor de puntaje, que mide el gap entre una llamada y la siguiente.)
  //
  // ⭐ EL PLAZO DEL DOCUMENTO ES UN TECHO, NO UNA CITA. Ahí está la clave:
  //
  //     regla ②  plazo 2 días   escala {0-2: 10, 3-5: 6, 6-10: 3, 11+: 0}
  //     regla ③  plazo 7 días   escala {0-7: 10, 8-15: 6, ...}
  //
  // Con 0, 1 o 2 días la ② paga los 10 completos. O sea que el documento dice
  // "tienes hasta 2 días", no "llama al segundo día". Entonces se agenda UN DÍA
  // ANTES del techo, y así le queda un día de colchón al vendedor:
  //
  //     agendado a +1 → si se le corre un día, sigue en 10/10
  //     agendado a +2 → si se le corre un día, cae a 6/10
  //
  // Los dos cumplen el documento; el primero no castiga por un desliz mínimo.
  // (El 17-ago lo puse en {1:2, 2:7} leyendo ese número como objetivo. Era el
  // techo. Revertido.)
  //     techo del documento:  2 · 7 · 30 · 100
  //     se agenda a:          1 · 6 · 29 ·  99   ← siempre un día antes
  //
  // El colchón importa porque la fecha se puede correr hacia adelante para
  // esquivar un feriado, y sin colchón ese corrimiento cuesta puntos: en la
  // regla ④ el techo son 30 y la escala cae a 8 en el día 31, o sea 7 puntos
  // por un día. Con 29 el corrimiento sale gratis.
  var PLAZO = { 1:1, 2:6, 3:29 };        // sin contestar consecutivas -> días
  var PLAZO_MANT = 99;
  var PLAZO_CONTESTO = 3;
  var HORA_AGENDA = '10:00';             // 10 h es de las más usadas (11,7%)

  // Días no laborables (fines de semana aparte): los calcula feriados.php en
  // PHP, incluidos los movibles que dependen de la Pascua, y viajan como lista.
  var FERIADOS = <?= $FERIADOS_JS ?>;

  var F_PROTOCOLO = 'UF_CRM_1786279719022';   // ESTADO DE PROTOCOLO
  var registrado = 0;      // id de la actividad recién creada (0 = todavía nada)
  var yaIntento  = false;  // el auto-registro corre UNA sola vez por apertura
  var deshecho   = false;
  var protocolo = null;    // { estado, sinContestar }
  var modoPacto = false;   // el cliente dijo una fecha -> ahí sí calendario

  function calcularProtocolo(llamadas) {
    // llamadas: [{fecha, contesto}] ya ordenadas por CREATED
    var estado = 'NUEVO', nc = 0;
    for (var i = 0; i < llamadas.length; i++) {
      if (llamadas[i].contesto) { estado = 'CONTACTADO'; nc = 0; }
      else { nc++; estado = nc === 1 ? 'ESCALERA-1' : (nc === 2 ? 'ESCALERA-2' : 'MANTENIMIENTO'); }
    }
    return { estado: estado, sinContestar: nc };
  }

  /** Días hasta la próxima llamada según lo que acaba de pasar. */
  function diasProxima(contesto) {
    if (contesto) return PLAZO_CONTESTO;
    var k = (protocolo ? protocolo.sinContestar : 0) + 1;
    return PLAZO[k] || PLAZO_MANT;
  }

  /** El estado en que queda el deal después de esta llamada. */
  function estadoProximo(contesto) {
    if (contesto) return 'CONTACTADO';
    var k = (protocolo ? protocolo.sinContestar : 0) + 1;
    return k === 1 ? 'ESCALERA-1' : (k === 2 ? 'ESCALERA-2' : 'MANTENIMIENTO');
  }

  function fechaMas(dias) {
    var d = new Date(); d.setDate(d.getDate() + dias);
    return { y:d.getFullYear(), m:d.getMonth(), d:d.getDate() };
  }

  var ctx = null;          // { resp, contactId, nombre, tel }
  var ctxCargando = false;
  var ctxCola = [];        // lo que quedó esperando la precarga

  /**
   * TODO lo que hace falta, en UN SOLO viaje.
   *
   * Antes eran cuatro seguidos —historial, deal, contacto y recién ahí el
   * alta— y cada uno esperaba al anterior: el vendedor veía "Registrando…"
   * varios segundos. Con `batch` los tres de lectura van juntos, y el contacto
   * se resuelve dentro del mismo lote con $result[deal][CONTACT_ID].
   *
   * Quedan dos viajes en vez de cuatro, y el segundo es el que de verdad
   * importa (crear la actividad).
   */
  function precargar() {
    if (ctx || ctxCargando || !dealId) return;
    ctxCargando = true;

    function listo(v) {
      ctxCargando = false; ctx = v;
      var cola = ctxCola; ctxCola = [];
      for (var i = 0; i < cola.length; i++) cola[i]();
    }

    BX24.callBatch({
      deal: ['crm.deal.get', { id: dealId }],
      cont: ['crm.contact.get', { id: '$result[deal][CONTACT_ID]' }],
      // ⚠ Sin paginar se leerían solo 50 y en un deal trabajado las llamadas
      // recientes quedan fuera: el escalón saldría mal. 200 cubre de sobra —
      // el deal más cargado de la base tiene 93.
      hist: ['crm.activity.list', {
        filter: { OWNER_TYPE_ID:2, OWNER_ID:dealId, TYPE_ID:2, DIRECTION:2 },
        select: ['ID','CREATED','SUBJECT'], order: { ID:'ASC' }, start: -1
      }]
    }, function (r) {
      // ── historial → escalón
      var l = [];
      try {
        var d = (r.hist && !r.hist.error()) ? (r.hist.data() || []) : [];
        for (var i = 0; i < d.length; i++)
          l.push({ contesto: String(d[i].SUBJECT || '').indexOf('1234') >= 0 });
      } catch (e) {}
      protocolo = calcularProtocolo(l);

      // ── deal + contacto → responsable y teléfono
      var base = null;
      try {
        if (r.deal && !r.deal.error()) {
          var deal = r.deal.data();
          var cid  = parseInt(deal.CONTACT_ID || 0, 10);
          base = { resp: deal.ASSIGNED_BY_ID, contactId: cid > 0 ? cid : 0, nombre:null, tel:null };
          if (r.cont && !r.cont.error()) {
            var c = r.cont.data() || {};
            base.nombre = [c.NAME, c.LAST_NAME].filter(Boolean).join(' ').trim() || null;
            base.tel = (c.PHONE && c.PHONE[0] && c.PHONE[0].VALUE) || null;
          }
        }
      } catch (e) {}

      listo(base);        // libera lo que estuviera esperando
      redibujar();
      autoRegistrar();    // apretar la pestaña ES la acción
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
    if (!dealId) return;
    BX24.placement.call('lock');
    // Una sola consulta del motivo por registro. Vive ACÁ y no dentro de
    // guardar() para que no se reinicie si se vuelve a entrar.
    var yaPreguntoMotivo = false;
    // Si nunca tocó la hora, se sella la de ESTE momento, no la del render.
    if (!horaManual) hhmm = ahoraHHMM();

    function guardar() {
      var inicio = inicioIso(contesto);
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
      // ⭐ EL ERROR TIENE QUE DECIR EL MOTIVO, y NADA se arregla solo.
      //
      // Hubo una version que alineaba el responsable del contacto y reintentaba
      // sin avisar. Se quito por decision del usuario (18-ago): reasignar el
      // dueno de un cliente es tocar la cartera de otro asesor, y eso lo decide
      // una persona, no el panel. Ahora se explica y el vendedor actua.
      function pedirMotivo(cb) {
        var a = '';
        try { a = (BX24.getAuth() || {}).access_token || ''; } catch (e) {}
        if (!a) { cb(null); return; }
        var x = new XMLHttpRequest();
        x.open('POST', 'motivo.php', true);
        x.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        x.timeout = 12000;
        x.onload = function () {
          var j = null;
          try { j = JSON.parse(x.responseText); } catch (e) {}
          cb(j);
        };
        x.onerror = function () { cb(null); };
        x.ontimeout = function () { cb(null); };
        x.send('deal=' + encodeURIComponent(dealId) + '&auth=' + encodeURIComponent(a));
      }

      BX24.callMethod('crm.activity.add', { fields: fields }, function (ra) {
        BX24.placement.call('unlock');
        if (ra.error() && !yaPreguntoMotivo) {
          yaPreguntoMotivo = true;
          aviso = 'No se pudo guardar. Averiguando por qu\u00e9\u2026'; redibujar();
          pedirMotivo(function (j) {
            if (j && j.motivo === 'contacto_ajeno') {
              aviso = 'NO SE GUARD\u00d3  \u00b7  el cliente est\u00e1 a nombre de '
                    + j.dueno + ', y el CRM no deja registrar actividades en un '
                    + 'contacto de otro asesor. Pide que te lo transfieran y volv\u00e9 a intentar.';
            } else {
              // No era el contacto: se muestra el detalle crudo para no adivinar.
              var cod = '', des = '';
              try { cod = ra.error() ? String(ra.error()) : ''; } catch (e) {}
              try { des = ra.error_description ? String(ra.error_description()) : ''; } catch (e) {}
              aviso = 'NO SE GUARD\u00d3  \u00b7  cod: ' + (cod || '(vac\u00edo)')
                    + (des && des !== cod ? '  \u00b7  ' + des : '')
                    + '  \u00b7  resp:' + (fields.RESPONSIBLE_ID || '?')
                    + ' contacto:' + (ctx.contactId || 'sin')
                    + ' prov:' + fields.PROVIDER_ID;
            }
            redibujar();
          });
          return;
        }
        if (ra.error()) {
          // ⚠ EL MENSAJE TIENE QUE DECIR QUÉ FALLÓ.
          //
          // El 18-ago a Andrea le salió "No se pudo guardar: : Access denied.
          // (400)" — con el código VACÍO, así que no se sabía qué campo ni qué
          // permiso. Se perdieron dos diagnósticos equivocados por eso.
          //
          // Ahora se muestra el código, la descripción, y los campos que Bitrix
          // podría estar rechazando por permisos: el responsable que se le
          // asigna, el contacto al que se liga, y el proveedor de telefonía
          // (VOXIMPLANT_CALL exige permisos de telefonía aparte).
          var cod = '';
          var des = '';
          try { cod = ra.error() ? String(ra.error()) : ''; } catch (e) {}
          try { des = ra.error_description ? String(ra.error_description()) : ''; } catch (e) {}
          aviso = 'No se pudo guardar'
                + (cod ? '  ·  cod: ' + cod : '  ·  cod: (vacío)')
                + (des && des !== cod ? '  ·  ' + des : '')
                + '  ·  resp:' + (fields.RESPONSIBLE_ID || '?')
                + ' contacto:' + (ctx.contactId || 'sin')
                + ' prov:' + fields.PROVIDER_ID;
          redibujar(); return;
        }
        registrado = parseInt(ra.data(), 10) || 0;   // para poder deshacerlo
        // Sin texto de "Guardado": la actividad recien creada YA sale ahi
        // abajo en la linea de tiempo con su fecha limite.
        aviso = 'Guardado \u2713  ' + (contesto ? 'contest\u00f3' : 'no contest\u00f3')
              + ' \u00b7 vuelvo a llamar el ' + textoFecha(
                  modoPacto && sel ? sel : fechaMas(diasProxima(contesto)));
        if (protocolo) {
          protocolo = { estado: estadoProximo(contesto),
                        sinContestar: contesto ? 0 : protocolo.sinContestar + 1 };
        }
        modoPacto = false; horaManual = false; diaManual = false; importante = false;
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

  /**
   * Apretar la pestaña ES la acción: no hay un segundo botón que buscar.
   *
   * Corre UNA vez por apertura (yaIntento). El seguro de verdad es el
   * "Deshacer" de la franja de botones: si alguien entró sin querer, un clic
   * borra la actividad y deja el deal como estaba.
   */
  function autoRegistrar() {
    if (yaIntento || !protocolo || !dealId) return;
    yaIntento = true;
    registrar(false);
  }

  /** Borra la actividad recién creada y devuelve el protocolo a como estaba. */
  function deshacer() {
    if (!registrado) return;
    BX24.placement.call('lock');
    var id = registrado;
    BX24.callMethod('crm.activity.delete', { id: id }, function (r) {
      BX24.placement.call('unlock');
      if (r.error()) { aviso = 'No se pudo deshacer: ' + r.error(); redibujar(); return; }
      registrado = 0; deshecho = true;
      if (protocolo && protocolo.sinContestar > 0) {
        protocolo = { estado: protocolo.sinContestar === 1 ? 'CONTACTADO'
                        : (protocolo.sinContestar === 2 ? 'ESCALERA-1' : 'ESCALERA-2'),
                      sinContestar: protocolo.sinContestar - 1 };
      }
      redibujar();

      // Borrar la actividad NO alcanza: el puntaje ya se había recalculado con
      // ella. Y el evento de borrado no sirve para arreglarlo, porque cuando
      // llega la actividad ya no existe y no hay forma de saber de qué deal era.
      //
      // Acá sí se sabe. Escribir el estado corregido dispara ONCRMDEALUPDATE,
      // y ese evento sí recalcula todo lo demás — puntaje, contadores y días.
      if (protocolo) {
        var campos = {}; campos[F_PROTOCOLO] = protocolo.estado;
        BX24.callMethod('crm.deal.update', { id: dealId, fields: campos }, function(){});
      }
    });
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

    BX24.placement.call('bindPrimaryButtonClickCallback',   null, deshacer);
    BX24.placement.call('bindSecondaryButtonClickCallback', null, function(){});
  });
})();
</script>
</body>
</html>
