<?php
/**
 * feriados.php — días NO hábiles de Ecuador, para no agendar llamadas en ellos.
 * ---------------------------------------------------------------------------
 * ⚠ POR QUÉ IMPORTA Y NO ES COSMÉTICO.
 * El panel de "No contestó" fija la fecha de la próxima llamada según la
 * escalera. Si esa fecha cae sábado, domingo o feriado, el vendedor no va a
 * llamar — y el sistema mide el atraso en días corridos, así que lo castiga por
 * un día que la empresa no trabaja.
 *
 * Medido en la regla ② (2º intento, plazo 2 días, peso 10):
 *     llamó al día 2 (el plazo)          → 10 de 10
 *     empujado al día 3 por el domingo   →  6 de 10   ← castigo que no le toca
 *
 * De ahí la regla de oro de este archivo: **se mueve HACIA ATRÁS, al día hábil
 * anterior**. Llamar antes nunca resta, porque el atraso es max(0, gap - plazo)
 * y no baja de cero. Mover hacia adelante sí resta. Solo si el día hábil
 * anterior ya pasó se mueve hacia adelante, y eso pasa nada más en los tramos
 * cortos, donde igual queda holgura.
 *
 * Los feriados se CALCULAN, no se escriben a mano año por año: los movibles
 * dependen de la Pascua, y una lista fija se vence sola y nadie se acuerda de
 * actualizarla.
 * ---------------------------------------------------------------------------
 */
declare(strict_types=1);

/**
 * Domingo de Pascua por el algoritmo gregoriano anónimo (Meeus/Jones/Butcher).
 * No se usa easter_date() de PHP porque vive en la extensión calendar, que no
 * está garantizada en la imagen.
 */
function fer_pascua(int $anio): DateTimeImmutable {
    $a = $anio % 19;
    $b = intdiv($anio, 100);
    $c = $anio % 100;
    $d = intdiv($b, 4);
    $e = $b % 4;
    $f = intdiv($b + 8, 25);
    $g = intdiv($b - $f + 1, 3);
    $h = (19 * $a + $b - $d - $g + 15) % 30;
    $i = intdiv($c, 4);
    $k = $c % 4;
    $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
    $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $mes = intdiv($h + $l - 7 * $m + 114, 31);
    $dia = (($h + $l - 7 * $m + 114) % 31) + 1;
    return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $anio, $mes, $dia),
        new DateTimeZone('America/Guayaquil'));
}

/**
 * Feriados de Ecuador para un año, en 'Y-m-d'.
 *
 * ⚠ Son las fechas NOMINALES de la ley. Ecuador además mueve varios feriados
 * para armar puentes (si cae martes se pasa al lunes anterior, si cae miércoles
 * o jueves al viernes siguiente), y esos traslados los publica el Ministerio de
 * Trabajo cada año. Acá NO se adivinan: se agregan a mano en FER_EXTRA cuando
 * salen publicados. Por eso el 25-jul de Guayaquil va incluido pero un puente
 * inventado no.
 */
function fer_feriados(int $anio): array {
    $p = fer_pascua($anio);
    $f = [
        sprintf('%04d-01-01', $anio),   // Año Nuevo
        sprintf('%04d-05-01', $anio),   // Día del Trabajo
        sprintf('%04d-05-24', $anio),   // Batalla de Pichincha
        sprintf('%04d-07-25', $anio),   // Fundación de Guayaquil (local, la oficina es de Guayaquil)
        sprintf('%04d-08-10', $anio),   // Primer Grito de Independencia
        sprintf('%04d-10-09', $anio),   // Independencia de Guayaquil
        sprintf('%04d-11-02', $anio),   // Día de los Difuntos
        sprintf('%04d-11-03', $anio),   // Independencia de Cuenca
        sprintf('%04d-12-25', $anio),   // Navidad
        // Movibles, atados a la Pascua:
        $p->modify('-48 days')->format('Y-m-d'),   // Carnaval lunes
        $p->modify('-47 days')->format('Y-m-d'),   // Carnaval martes
        $p->modify('-2 days')->format('Y-m-d'),    // Viernes Santo
    ];
    foreach (FER_EXTRA as $d) if (substr($d, 0, 4) === (string)$anio) $f[] = $d;
    sort($f);
    return array_values(array_unique($f));
}

/**
 * Días no laborables adicionales: puentes decretados, feriados de la empresa,
 * cierres por inventario. Se agregan a mano acá y quedan versionados.
 */
const FER_EXTRA = [
    // '2026-12-26',  // ejemplo: puente decretado
];

/** Todos los feriados de un rango de años, listos para mandar al navegador. */
function fer_lista(int $desde, int $hasta): array {
    $out = [];
    for ($a = $desde; $a <= $hasta; $a++) $out = array_merge($out, fer_feriados($a));
    sort($out);
    return $out;
}

function fer_es_feriado(DateTimeImmutable $d): bool {
    static $cache = [];
    $anio = (int)$d->format('Y');
    if (!isset($cache[$anio])) $cache[$anio] = array_flip(fer_feriados($anio));
    return isset($cache[$anio][$d->format('Y-m-d')]);
}

function fer_es_habil(DateTimeImmutable $d): bool {
    return (int)$d->format('N') <= 5 && !fer_es_feriado($d);
}

/**
 * Corre una fecha al día hábil más cercano, PREFIRIENDO HACIA ATRÁS.
 *
 * Hacia atrás porque adelantar la llamada no cuesta puntos y retrasarla sí
 * (ver la cabecera). Si el día hábil anterior ya pasó —o sea que quedaría en el
 * pasado— se va hacia adelante, que es lo único que queda.
 *
 * @param DateTimeImmutable $piso  no se devuelve nada anterior a esta fecha
 */
function fer_habil_cercano(DateTimeImmutable $d, DateTimeImmutable $piso): DateTimeImmutable {
    if (fer_es_habil($d) && $d >= $piso) return $d;

    $atras = $d;
    for ($i = 0; $i < 10; $i++) {
        if (fer_es_habil($atras) && $atras >= $piso) return $atras;
        $atras = $atras->modify('-1 day');
        if ($atras < $piso) break;
    }
    $adelante = $d;
    for ($i = 0; $i < 15; $i++) {
        $adelante = $adelante->modify('+1 day');
        if (fer_es_habil($adelante)) return $adelante;
    }
    return $d;   // no debería pasar
}
