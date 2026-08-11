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
  function botones(activos) {
    return {
      primaryButton:   { title:'S\u00ed, contest\u00f3',    state: activos ? 'normal' : 'disabled' },
      secondaryButton: { title:'\u274c No contest\u00f3', state: activos ? 'normal' : 'disabled' }
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

    // ── encabezado: ◀  agosto 2026  ▶
    var cal = {};
    cal.nav = { type:'lineOfBlocks', properties:{ blocks:{
      ant: { type:'link', properties:{ text: SANG_NAV + '\u25c0', action:{ type:'layoutEvent', value:'mes:-1' } } },
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
    cab.sang = { type:'text', properties:{ value: SANG_FILA_CAB, size:'xs' } };
    for (var i = 0; i < 7; i++) cab['h'+i] = { type:'text', properties:{
      value: centrar(DOW[i], ANCHO_DOW[DOW[i]], E12),
      size:'xs', color: (i >= 5 ? 'danger' : 'base_50') } };
    cal.dow = { type:'lineOfBlocks', properties:{ blocks: cab } };
    // raya bajo los días, como el selector nativo
    cal.raya = { type:'text', properties:{ value: SANG_RAYA + RAYA, color:'base_50' } };

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
      // La sangría de centrado va en su PROPIO bloque, no dentro de la primera
      // celda: si esa celda cae en negrita (el día elegido), sus espacios
      // también engordan y la fila entera se corre. Medido: +17 px.
      var fila = { sang: { type:'text', properties:{ value: SANG_FILA } } };
      for (var c = 0; c < 7; c++) {
        var cel = celdas[s*7 + c], key = 'c' + c, dia = cel.d;
        if (cel.otro || esPasado(y, m, dia)) {
          // gris: ni de este mes, o ya pasó. No se puede planificar ahí.
          fila[key] = { type:'text', properties:{ value: celda(dia), color:'base_50' } };
        } else if (sel && sel.y===y && sel.m===m && sel.d===dia) {
          // el elegido va como TEXTO oscuro y en negrita: contra el azul de
          // los links se distingue de un vistazo, sin cambiar de ancho.
          fila[key] = { type:'text', properties:{ value: celda(dia, true), bold:true } };
        } else {
          // Sábado y domingo NO se pueden pintar de rojo: `color` solo existe
          // en el bloque `text`, y ahí el día deja de ser clickeable. Probado
          // en el DOM: mandando color:'danger' en el link, el sábado sale con
          // el mismo rgb(32,102,176) que el viernes, sin clase ni atributo.
          fila[key] = { type:'link', properties:{
            text: celda(dia),
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

    // ── Lo que toca, según la escalera ──────────────────────────────────
    // Sin calendario: el vendedor no elige fecha. Solo aparece si el cliente
    // pactó una, que es el único caso en que la fecha significa algo.
    var TOCA = {
      'NUEVO':        'Primer contacto',
      'ESCALERA-1':   '2\u00ba intento',
      'ESCALERA-2':   '3\u00ba intento',
      'MANTENIMIENTO':'Mantenimiento',
      'CONTACTADO':   'Devolver la llamada'
    };

    if (!modoPacto) {
      var linea = protocolo
        ? (TOCA[protocolo.estado] || protocolo.estado) + '  \u00b7  ' + protocolo.estado
        : 'Leyendo el historial\u2026';
      blocks.estado = { type:'text', properties:{ value: linea, color:'base_70', size:'sm' } };

      if (protocolo) {
        var pNo = fechaMas(diasProxima(false)), pSi = fechaMas(diasProxima(true));
        blocks.plan = { type:'section', properties:{ type:'primary', blocks:{
          a: { type:'text', properties:{ bold:true,
               value: 'No contest\u00f3  \u2192  vuelvo a llamar el ' + textoFecha(pNo) } },
          b: { type:'text', properties:{ size:'sm', color:'base_70',
               value: 'S\u00ed contest\u00f3  \u2192  ' + textoFecha(pSi) + ', salvo que pacten otra' } }
        }}};
        blocks.pacto = { type:'link', properties:{ size:'sm',
          text:'El cliente pidi\u00f3 otra fecha \u203a',
          action:{ type:'layoutEvent', value:'pacto' } } };
      }

      var b0 = botones(!!protocolo);
      b0.blocks = blocks;
      return b0;
    }

    // ── Modo pacto: acá sí el calendario, porque la fecha la puso el cliente
    blocks.volver = { type:'link', properties:{ size:'sm',
      text:'\u2039 Volver', action:{ type:'layoutEvent', value:'sinpacto' } } };

    blocks.cal  = { type:'section', properties:{ type:'withBorder', blocks: cal } };

    var hh = hhmm.slice(0,2), mi = hhmm.slice(3);

    var ruedas = { type:'lineOfBlocks', properties:{ blocks:{
      hmen: { type:'link', properties:{ text: SANG_RUE+EC+'\u2212'+EC, size:'xl', bold:true,
              action:{ type:'layoutEvent', value:'h-1' } } },
      hval: { type:'text', properties:{ value: EC + hh + EC, bold:true, size:'xl' } },
      hmas: { type:'link', properties:{ text: EC+'+'+EC, size:'xl', bold:true,
              action:{ type:'layoutEvent', value:'h+1' } } },
      dos:  { type:'text', properties:{ value: EC + ':' + EC, bold:true, size:'xl' } },
      mmen: { type:'link', properties:{ text: EC+'\u2212'+EC, size:'xl', bold:true,
              action:{ type:'layoutEvent', value:'m-5' } } },
      mval: { type:'text', properties:{ value: EC + mi + EC, bold:true, size:'xl' } },
      mmas: { type:'link', properties:{ text: EC+'+'+EC, size:'xl', bold:true,
              action:{ type:'layoutEvent', value:'m+5' } } }
    }}};

    // withTitle con inline: la columna del rótulo la arma Bitrix (100 px).
    function conRotulo(titulo, bloque) {
      return { type:'withTitle', properties:{
        title: titulo, inline:true, titleWidth:'sm', block: bloque } };
    }

    // La sangría de centrado va en su PROPIO bloque: dentro de una celda que
    // caiga en negrita, sus espacios engordan y la fila entera se corre.
    function filaAtajos(pre, pares, actual, evento, sangria) {
      var f = { sang: { type:'text', properties:{ value: sangria || '', size:'sm' } } };
      for (var i = 0; i < pares.length; i++) {
        var val = pares[i][0], txt = pares[i][1];
        var activo = (pad(val) === actual);
        var cel = celda(txt, activo);
        f[pre+val] = activo
          ? { type:'text', properties:{ value: cel, bold:true, size:'sm' } }
          : { type:'link', properties:{ text: cel, size:'sm',
                action:{ type:'layoutEvent', value: evento + pad(val) } } };
      }
      return { type:'lineOfBlocks', properties:{ blocks: f } };
    }

    blocks.caja = { type:'section', properties:{ type:'withBorder', blocks:{
      ruedas:  conRotulo('Tiempo', ruedas),
      manana:  conRotulo('Ma\u00f1ana', filaAtajos('h', MANANA, hh, 'hora:', SANG_MANANA)),
      tarde:   conRotulo('Tarde',   filaAtajos('t', TARDE,  hh, 'hora:', SANG_TARDE)),
      minutos: conRotulo('Minuto',  filaAtajos('m', MINUTOS, mi, 'min:', SANG_MIN))
    }}};

    // Confirmación en tarjeta celeste (#E5F9FF medido): es el bloque de aviso
    // que usa Bitrix en sus propias pantallas, y separa lo que se va a guardar
    // de los controles de arriba.
    // La tarjeta cambia de color según el estado: celeste mientras se decide,
    // verde al guardar, roja si algo falló. Colores medidos: primary #E5F9FF,
    // success #F1FBD0, danger #FFE8E8.
    var tipo = 'primary', linea = resumenTxt();
    if (aviso) {
      var malo = /no se pudo/i.test(aviso);
      tipo  = malo ? 'danger' : 'success';
      linea = aviso;
    }
    blocks.resumen = { type:'section', properties:{ type:tipo, blocks:{
      t: { type:'text', properties:{ value: linea, bold:true } }
    }}};
    // Importante y Cerrar comparten fila: el fuego de Bitrix es PRIORITY 3
    // ("high", confirmado con crm.enum.activitypriority) y algunos vendedores
    // lo usan -- de 400 llamadas leídas, 5 venían marcadas.
    blocks.pie = { type:'lineOfBlocks', properties:{ blocks:{
      imp: { type:'link', properties:{
        text: SANG_PIE + (importante ? '\u2611 Importante \ud83d\udd25' : '\u2610 Importante'), size:'sm',
        action:{ type:'layoutEvent', value:'imp' } } },
    }}};

    var out = botones(true);
    out.blocks = blocks;
    return out;
  }

  function redibujar() { BX24.placement.call('setLayout', layout(), function(){}); }





  /**
   * La fecha que se guarda.
   *
   * Sin pacto la pone la escalera; con pacto, lo que eligió el vendedor
   * porque el cliente lo dijo. La hora de agenda es fija (10 h, de las más
   * usadas): lo que califica el protocolo es el DÍA, no la hora.
   */
  function inicioIso(contesto) {
    var f, hm;
    if (modoPacto && sel) { f = sel; hm = hhmm; }
    else { f = fechaMas(diasProxima(contesto)); hm = HORA_AGENDA; }
    return f.y + '-' + pad(f.m+1) + '-' + pad(f.d) + 'T' + hm + ':00-05:00';
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
  var PLAZO = { 1:2, 2:7, 3:30 };        // sin contestar consecutivas -> días
  var PLAZO_MANT = 100;
  var PLAZO_CONTESTO = 3;
  var HORA_AGENDA = '10:00';             // 10 h es de las más usadas (11,7%)

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

  function precargar() {
    if (ctx || ctxCargando || !dealId) return;
    ctxCargando = true;

    function listo(v) {
      ctxCargando = false; ctx = v;
      var cola = ctxCola; ctxCola = [];
      for (var i = 0; i < cola.length; i++) cola[i]();
    }

    // el historial de salientes viaja junto con el deal: sirve para saber en
    // qué peldaño de la escalera va y, por lo tanto, qué día toca
    BX24.callMethod('crm.activity.list', {
      filter: { OWNER_TYPE_ID:2, OWNER_ID:dealId, TYPE_ID:2, DIRECTION:2 },
      select: ['ID','CREATED','SUBJECT'], order: { CREATED:'ASC' }
    }, function (rl) {
      var l = [];
      if (!rl.error()) {
        var d = rl.data() || [];
        for (var i = 0; i < d.length; i++)
          l.push({ contesto: String(d[i].SUBJECT || '').indexOf('1234') >= 0 });
      }
      protocolo = calcularProtocolo(l);
      redibujar();
    });

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
      BX24.callMethod('crm.activity.add', { fields: fields }, function (ra) {
        BX24.placement.call('unlock');
        if (ra.error()) { aviso = 'No se pudo guardar: ' + ra.error(); redibujar(); return; }
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
