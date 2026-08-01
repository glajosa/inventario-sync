<?php
/**
 * cotizarlib.php — plan de pagos Galjosa. Solo cálculo, sin HTML ni Bitrix.
 * ---------------------------------------------------------------------------
 * Réplica del motor del cotizador de Noral, verificada contra dos cotizaciones
 * reales antes de escribirla:
 *   Ofi B3.6  $156.020 · 56 cuotas → cuota 557,21 · reserva 15.602 (1.000+14.602)
 *             · contraentrega 93.612 · extraordinaria 3.157,55 (557,21+2.600,34 ×6)
 *   Apt A-2-5 $146.500 · 44 cuotas → cuota 665,91 · reserva 14.650 (1.000+13.650)
 *             · contraentrega 87.900 · extraordinaria 3.595,91 (665,91+2.930 ×5)
 *
 * El modelo:
 *   10% reserva  = separación $1.000 + saldo a la firma
 *   60% contraentrega
 *   30% restante = 20% en cuotas mensuales + 10% en extraordinarias (ESTÁNDAR)
 *                  ó 30% repartido en las N cuotas, sin extraordinarias (IGUALES)
 *   Las cuotas caen el 16 de cada mes. Una extraordinaria por año calendario.
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

/** Valores legales (notaría) por rango de precio — tabla 2026. */
function cot_notaria(float $v): int {
    if ($v <= 60000)  return 466;
    if ($v <= 90000)  return 604;
    if ($v <= 150000) return 771;
    if ($v <= 300000) return 1048;
    return 1824;
}

/** Entrega por proyecto (pipeline del SPA). Manda el plazo: es fecha FIJA, así que
 *  mientras más tarde arranquen las cuotas, menos caben antes de entregar.
 *  Solo Noral está definido; para el resto se devuelve null y el plazo lo fija
 *  el asesor, avisando en pantalla que no hay fecha configurada. */
function cot_entrega(int $categoryId): ?array {
    switch ($categoryId) {
        case 39: return ['y' => 2030, 'm' => 4];   // Noral Apartments — abril 2030
        case 33: return ['y' => 2031, 'm' => 4];   // Noral Plaza      — abril 2031
    }
    return null;
}

const COT_PLAZO_REF = 60;     // plazo de referencia
const COT_SEPARACION = 1000;  // la separación siempre es $1.000 (o el 10% si es menor)

/**
 * Arma el plan completo.
 *
 * @param float  $valor      PVP de la unidad (ya con extras si los hubiera)
 * @param int    $nCuotas    cuotas mensuales pedidas (0 = usar el máximo razonable)
 * @param string $modalidad  'estandar' | 'iguales'
 * @param string $mesInicio  "AAAA-MM" de la primera cuota ('' = mes siguiente)
 * @param array|null $entrega ['y'=>2031,'m'=>4] o null si el proyecto no la tiene
 */
function cot_plan(float $valor, int $nCuotas, string $modalidad, string $mesInicio, ?array $entrega): array {
    $iguales = ($modalidad === 'iguales');
    $porc    = $iguales ? 0.30 : 0.20;          // lo que va en cuotas mensuales
    $v       = max(0.0, $valor);

    // --- primera cuota: el 16 del mes elegido; nunca un mes ya pasado ---
    $hoy = new DateTimeImmutable('now');
    if (preg_match('/^(\d{4})-(\d{2})$/', $mesInicio, $m)) {
        $primera = new DateTimeImmutable(sprintf('%04d-%02d-16', (int)$m[1], (int)$m[2]));
    } else {
        $primera = new DateTimeImmutable($hoy->format('Y-m-16'))->modify('+1 month');
    }
    $piso = new DateTimeImmutable($hoy->format('Y-m-16'));
    if ($primera < $piso) $primera = $piso;

    // --- plazo máximo: cuántas cuotas caben antes de la entrega ---
    $plazoMax = null;
    if ($entrega) {
        $plazoMax = max(1, ((int)$entrega['y'] - (int)$primera->format('Y')) * 12
                         + ((int)$entrega['m'] - (int)$primera->format('n')) + 1);
    }

    $n = $nCuotas > 0 ? $nCuotas : ($plazoMax !== null ? min(COT_PLAZO_REF, $plazoMax) : COT_PLAZO_REF);
    $recortado = false;
    if ($plazoMax !== null && $n > $plazoMax) { $n = $plazoMax; $recortado = true; }
    $n = max(1, $n);

    // --- reparto del precio ---
    $reserva10     = 0.10 * $v;
    $separacion    = min((float)COT_SEPARACION, $reserva10);
    $firma         = $reserva10 - $separacion;
    $contraentrega = 0.60 * $v;
    $cargaCuotas   = $porc * $v;
    $mensual       = $cargaCuotas / $n;
    $extraTotal    = $iguales ? 0.0 : 0.10 * $v;

    // --- fechas de las cuotas (el 16 de cada mes) ---
    $fechas = [];
    for ($i = 0; $i < $n; $i++) $fechas[] = $primera->modify("+{$i} month");

    // --- extraordinarias: UNA por año calendario que toca el plan ---
    // Se ponen en un mes CONSISTENTE: el que usa el año con más cuotas (un año
    // completo). Si ese mes no existe en un año parcial, va al medio de su bloque.
    $posExtra = [];
    if (!$iguales && $extraTotal > 0) {
        $porAnio = [];
        foreach ($fechas as $i => $f) $porAnio[(int)$f->format('Y')][] = $i;
        $mejor = null;
        foreach ($porAnio as $y => $idxs) if ($mejor === null || count($idxs) > count($porAnio[$mejor])) $mejor = $y;
        $ref     = $porAnio[$mejor];
        $mesAncla = (int)$fechas[$ref[intdiv(count($ref) - 1, 2)]]->format('n');
        foreach ($porAnio as $y => $idxs) {
            $enMes = null;
            foreach ($idxs as $i) if ((int)$fechas[$i]->format('n') === $mesAncla) { $enMes = $i; break; }
            $posExtra[] = $enMes !== null ? $enMes : $idxs[intdiv(count($idxs) - 1, 2)];
        }
        sort($posExtra);
    }
    $nExtra    = count($posExtra);
    $valorExtra = $nExtra > 0 ? $extraTotal / $nExtra : 0.0;

    // --- tabla final ---
    $filas = [];
    foreach ($fechas as $i => $f) {
        $esExtra = in_array($i, $posExtra, true);
        $filas[] = [
            'n'     => $i + 1,
            'fecha' => $f->format('d/m/Y'),
            'monto' => $mensual + ($esExtra ? $valorExtra : 0.0),
            'extra' => $esExtra,
        ];
    }

    return [
        'valor'         => $v,
        'legal'         => cot_notaria($v),
        'separacion'    => $separacion,
        'firma'         => $firma,
        'reserva'       => $reserva10,
        'contraentrega' => $contraentrega,
        'cuotas'        => $n,
        'mensual'       => $mensual,
        'modalidad'     => $iguales ? 'iguales' : 'estandar',
        'extraTotal'    => $extraTotal,
        'nExtra'        => $nExtra,
        'valorExtra'    => $valorExtra,
        'inicio'        => $primera->format('Y-m'),
        'inicioTxt'     => cot_mes_es((int)$primera->format('n')) . ' ' . $primera->format('Y'),
        'plazoMax'      => $plazoMax,
        'recortado'     => $recortado,
        'filas'         => $filas,
        // Cuadre: separación + firma + todas las cuotas + contraentrega debe dar el valor.
        'suma'          => $separacion + $firma + array_sum(array_column($filas, 'monto')) + $contraentrega,
    ];
}

function cot_mes_es(int $m): string {
    $n = ['', 'Enero','Febrero','Marzo','Abril','Mayo','Junio',
          'Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
    return $n[$m] ?? '';
}

function cot_money(float $v): string { return '$' . number_format($v, 2); }
