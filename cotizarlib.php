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
const COT_PARQUEO = 20000;    // valor de un parqueo de Noral Plaza Suites

/** ¿La unidad es una SUITE de Noral Plaza? Es el único producto con la regla del
 *  parqueo. Se decide por el TIPO DE BIEN, no por el código: los edificios E y F
 *  tienen locales comerciales Y suites, y un local de $237.000 no puede llevarse
 *  el descuento de $20.000 solo por empezar con «E-». */
const COT_TIPO_DEPARTAMENTO = 1793;
function cot_es_suite(int $categoryId, int $tipo): bool {
    return $categoryId === 33 && $tipo === COT_TIPO_DEPARTAMENTO;
}

/** Descuento por parqueo NO obligatorio.
 *  Regla del negocio: cada suite se vende con parqueo obligatorio, PERO en una
 *  compra de 2 o más se puede perdonar UNO — y solo uno, no importa cuántas
 *  sean. Con 2 suites: 1 paga completa y a la otra se le restan $20.000. Con 3:
 *  dos pagan completas con sus parqueos y a la tercera se le restan $20.000.
 *  Por eso el tope es 1 y no "n-1". */
function cot_descuento_parqueo(int $suites, bool $aplicar): float {
    return ($aplicar && $suites >= 2) ? (float)COT_PARQUEO : 0.0;
}

/**
 * Arma el plan completo.
 *
 * El 10/60/30 dejó de estar clavado. Lo único intocable es la IDENTIDAD:
 *
 *     separación + firma + Σ(cuotas) + Σ(extraordinarias) + contraentrega = precio
 *
 * Se venden variantes reales que el reparto fijo no podía representar:
 *   · "no quiero pagar nada a la firma, súbeme la cuota"  -> se fija la FIRMA y la
 *     cuota mensual absorbe la diferencia;
 *   · "puedo pagar $700 al mes"                            -> se fija la CUOTA y la
 *     firma absorbe lo que quede;
 *   · "prefiero cargar las extraordinarias y aliviar la mensual" -> se fija el monto
 *     de cada extraordinaria y el resto se reacomoda;
 *   · "quiero terminar de pagar en tal mes"                -> se fija HASTA cuándo.
 *
 * Quién absorbe: si se fija la firma, absorbe la cuota. Si se fija la cuota, absorbe
 * la firma. Si la firma llegaría a negativo (el cliente adelanta más del 40% que
 * normalmente va antes de la entrega), se topa en 0 y lo que sobra le baja a la
 * CONTRAENTREGA — es aritmética, no una concesión: si paga más antes, queda menos
 * para el final. Se avisa con la bandera 'bajaContraentrega' porque cambia el 60%
 * que suele estar amarrado al crédito, y eso lo tiene que ver un humano.
 *
 * @param float  $valor      PVP de la unidad (ya con extras si los hubiera)
 * @param int    $nCuotas    cuotas mensuales pedidas (0 = usar el máximo razonable)
 * @param string $modalidad  'estandar' | 'iguales'
 * @param string $mesInicio  "AAAA-MM" de la primera cuota ('' = mes siguiente)
 * @param array|null $entrega ['y'=>2031,'m'=>4] o null si el proyecto no la tiene
 * @param float  $presupuesto  si es > 0 MANDA sobre $nCuotas: el cliente dice cuánto
 *                             puede pagar al mes y salen las cuotas que hagan falta.
 * @param array  $opts  variantes, todas opcionales:
 *        'hasta'        "AAAA-MM": último mes de pago. Manda sobre $nCuotas.
 *        'firma'        monto fijo a la firma (0 = nada a la firma). La cuota absorbe.
 *        'mensual'      cuota mensual fija. La firma absorbe.
 *        'extraCada'    monto de CADA extraordinaria (en vez del 10% repartido).
 *        'extraTotal'   total en extraordinarias (alternativa a extraCada).
 *        'extraPartes'  1 o 2: parte la extraordinaria de cada año en dos pagos
 *                       (abril + diciembre, 14vo/18vo sueldo) en vez de uno solo.
 *        'contraPct'    porcentaje de contraentrega directo (0.60 por defecto). Lo
 *                       normal es usar 'financiarPct' en su lugar, que ya trae el
 *                       piso de negocio; este queda para uso interno/pruebas.
 *        'financiarPct' % del precio que se financia ANTES de la entrega (separación +
 *                       firma + cuotas + extraordinarias). Lo común es 40; el vendedor
 *                       puede bajarlo, pero el PISO ES 35 — se topa aquí adentro, no
 *                       en la pantalla, así que ninguna URL armada a mano lo salta.
 *                       contraPct = 1 - financiarPct/100.
 *        'firmaMeses'   diferir la firma sobre N meses (tope 12, regla del director:
 *                       "inadmisible que el 10% se reparta en toda la deuda") en vez
 *                       de pagarla toda al firmar. Se SUMA a la cuota de esos meses.
 *        'firmaCuota'   monto mensual editable para esa deferencia (en vez de dividir
 *                       parejo entre firmaMeses). Si no alcanza a cubrir la firma en
 *                       12 meses, el resto se cae a las extraordinarias — nunca se
 *                       pierde ni se estira más allá del tope.
 *        'extraMes1'    mes (1-12) de la primera/única extraordinaria de cada año, en
 *                       vez del automático. 'extraMes2' es el de la segunda cuando
 *                       extraPartes=2 (por defecto abril y diciembre).
 */
function cot_plan(float $valor, int $nCuotas, string $modalidad, string $mesInicio, ?array $entrega, float $presupuesto = 0.0, array $opts = []): array {
    $iguales = ($modalidad === 'iguales');
    $porc    = $iguales ? 0.30 : 0.20;          // lo que va en cuotas mensuales
    $v       = max(0.0, $valor);

    // --- primera cuota: el 16 del mes elegido; nunca un mes ya pasado ---
    $hoy = new DateTimeImmutable('now');
    if (preg_match('/^(\d{4})-(\d{2})$/', $mesInicio, $m)) {
        $primera = new DateTimeImmutable(sprintf('%04d-%02d-16', (int)$m[1], (int)$m[2]));
    } else {
        // Paréntesis obligatorios: encadenar sobre `new` sin ellos es sintaxis de
        // PHP 8.4, y el contenedor corre 8.2 — revienta con parse error.
        $primera = (new DateTimeImmutable($hoy->format('Y-m-16')))->modify('+1 month');
    }
    $piso = new DateTimeImmutable($hoy->format('Y-m-16'));
    if ($primera < $piso) $primera = $piso;

    // --- plazo máximo: cuántas cuotas caben antes de la entrega ---
    $plazoMax = null;
    if ($entrega) {
        $plazoMax = max(1, ((int)$entrega['y'] - (int)$primera->format('Y')) * 12
                         + ((int)$entrega['m'] - (int)$primera->format('n')) + 1);
    }

    // --- piezas fijas ---
    // La separación es siempre $1.000 (o el 10% si la unidad valiera menos): es lo
    // que aparta la unidad y no se negocia.
    // La contraentrega sale de cuánto se FINANCIA antes de la entrega. Lo común es
    // financiar 40%; el vendedor puede bajarlo según lo que pida el cliente, PERO el
    // piso de negocio es 35% — se topa aquí, no en la pantalla, porque un piso que
    // solo vive en el HTML se salta con una URL armada a mano.
    if (isset($opts['financiarPct'])) {
        $financiarPct = max(35.0, min(100.0, (float)$opts['financiarPct']));
        $contraPct = 1.0 - $financiarPct / 100.0;
    } else {
        $contraPct = isset($opts['contraPct']) ? max(0.0, min(1.0, (float)$opts['contraPct'])) : 0.60;
    }
    $reserva10     = 0.10 * $v;
    $separacion    = min((float)COT_SEPARACION, $reserva10);
    $contraentrega = $contraPct * $v;
    $cargaCuotas   = $porc * $v;

    // --- número de cuotas: por HASTA, por PRESUPUESTO o por plazo ---
    $recortado = false;
    $insuficiente = false;
    $cuotaMinima = 0.0;
    $hasta = (string)($opts['hasta'] ?? '');
    if (preg_match('/^(\d{4})-(\d{2})$/', $hasta, $mh)) {
        // "Quiero terminar de pagar en tal mes": cuenta los meses desde la primera
        // cuota hasta ese mes, ambos incluidos. Mínimo 1.
        $n = ((int)$mh[1] - (int)$primera->format('Y')) * 12
           + ((int)$mh[2] - (int)$primera->format('n')) + 1;
        $n = max(1, $n);
        if ($plazoMax !== null && $n > $plazoMax) { $n = $plazoMax; $recortado = true; }
    } elseif ($presupuesto > 0 && $v > 0) {
        // Con presupuesto se invierte la pregunta: el cliente dice cuánto puede pagar
        // al mes y salen las cuotas necesarias. Es la forma en que se vende de verdad.
        $n = max(1, (int)ceil($cargaCuotas / $presupuesto));
        if ($plazoMax !== null && $n > $plazoMax) {
            // No alcanza: ni pagando hasta la entrega llega. Se topa al plazo real y
            // se avisa cuál es la cuota mínima posible, que es el dato accionable.
            $n = $plazoMax; $insuficiente = true; $cuotaMinima = $cargaCuotas / $plazoMax;
        }
    } else {
        $n = $nCuotas > 0 ? $nCuotas : ($plazoMax !== null ? min(COT_PLAZO_REF, $plazoMax) : COT_PLAZO_REF);
        if ($plazoMax !== null && $n > $plazoMax) { $n = $plazoMax; $recortado = true; }
    }
    $n = max(1, $n);

    // --- fechas de las cuotas (el 16 de cada mes) ---
    $fechas = [];
    for ($i = 0; $i < $n; $i++) $fechas[] = $primera->modify("+{$i} month");

    // --- extraordinarias: una o dos por año calendario que toca el plan ---
    // Por defecto UNA, en el mes que usa el año con más cuotas (un año completo); si
    // ese mes no existe en un año parcial, va al medio de su bloque — igual que antes.
    // Con extraPartes=2 se parte en DOS: abril y diciembre (14vo/18vo sueldo, que es
    // cuando de verdad les entra ese dinero a los clientes) — pero el asesor puede
    // elegir otro mes con extraMes1/extraMes2 si el cliente cobra distinto. Si un año
    // no llega al mes elegido (el plan termina antes), ese año se queda con la que sí
    // le cabe, no se fuerza.
    // Las posiciones se calculan ANTES del reparto del dinero: para saber cuánto
    // suman las extraordinarias hace falta saber antes cuántas son.
    $extraPartes = ($iguales) ? 1 : max(1, min(2, (int)($opts['extraPartes'] ?? 1)));
    $mesExtra1 = (int)($opts['extraMes1'] ?? 0);   // 0 = automático (el de siempre)
    $mesExtra2 = max(1, min(12, (int)($opts['extraMes2'] ?? 12)));
    $posExtra = [];
    if (!$iguales) {
        $porAnio = [];
        foreach ($fechas as $i => $f) $porAnio[(int)$f->format('Y')][] = $i;
        $mejor = null;
        foreach ($porAnio as $y => $idxs) if ($mejor === null || count($idxs) > count($porAnio[$mejor])) $mejor = $y;
        $ref     = $porAnio[$mejor];
        $mesAncla = (int)$fechas[$ref[intdiv(count($ref) - 1, 2)]]->format('n');
        $mes1 = $mesExtra1 > 0 ? $mesExtra1 : ($extraPartes === 2 ? 4 : $mesAncla);
        foreach ($porAnio as $y => $idxs) {
            $buscar = function (int $mes) use ($fechas, $idxs): ?int {
                foreach ($idxs as $i) if ((int)$fechas[$i]->format('n') === $mes) return $i;
                return null;
            };
            if ($extraPartes === 2) {
                $slotA = $buscar($mes1) ?? $idxs[intdiv(count($idxs) - 1, 2)];
                $slotB = $buscar($mesExtra2);
                $posExtra[] = $slotA;
                if ($slotB !== null && $slotB !== $slotA) $posExtra[] = $slotB;
            } else {
                $enMes = $buscar($mes1);
                $posExtra[] = $enMes !== null ? $enMes : $idxs[intdiv(count($idxs) - 1, 2)];
            }
        }
        sort($posExtra);
    }
    $nExtra = count($posExtra);

    // ---------- REPARTO DEL DINERO ----------
    // Cuánto va en extraordinarias: monto por extraordinaria, total explícito, o el
    // 10% de siempre. Poner más acá alivia la cuota mensual, que es una de las
    // variantes que se venden.
    if ($iguales) {
        $extraTotal = 0.0;
    } elseif (isset($opts['extraCada']) && (float)$opts['extraCada'] > 0) {
        $extraTotal = (float)$opts['extraCada'] * $nExtra;
    } elseif (isset($opts['extraTotal']) && (float)$opts['extraTotal'] > 0) {
        $extraTotal = (float)$opts['extraTotal'];
    } else {
        $extraTotal = 0.10 * $v;
    }
    if ($nExtra === 0) $extraTotal = 0.0;      // sin extraordinarias no hay dónde ponerlo
    $extraTotal = max(0.0, min($extraTotal, max(0.0, $v - $separacion - $contraentrega)));

    // La CONTRAENTREGA es el cierre de la identidad, no un dato independiente: se
    // calcula al FINAL, sumando la tabla real de cuotas (no mensual × n — con la
    // firma diferida cada mes puede traer un monto distinto, así que hace falta la
    // tabla ya construida para que el cierre sea exacto).
    $firmaFijada   = isset($opts['firma'])   && $opts['firma'] !== '';
    $mensualFijada = isset($opts['mensual']) && (float)$opts['mensual'] > 0;
    $porRepartir   = $v - $separacion - $contraentrega - $extraTotal;
    $bajaContraentrega = false;
    $sobrepago = false;

    if ($mensualFijada) {
        // "Puedo pagar $700 al mes". Manda la cuota. Si además pidió firma (típico:
        // "y nada a la firma"), se respetan LAS DOS y lo que sobra sale de la
        // contraentrega — que es exactamente lo que pide ese cliente.
        $mensual = (float)$opts['mensual'];
        $firmaBase = $firmaFijada ? max(0.0, (float)$opts['firma'])
                                  : max(0.0, $porRepartir - $mensual * $n);
    } elseif ($firmaFijada) {
        // "No quiero pagar nada a la firma": la CUOTA absorbe la diferencia.
        $firmaBase = max(0.0, min((float)$opts['firma'], $porRepartir));
        $mensual   = max(0.0, ($porRepartir - $firmaBase) / $n);
    } else {
        // Sin variantes: reparto clásico. La firma se topa contra lo disponible por si
        // las extraordinarias se cargaron y ya no cabe el 10% completo.
        $firmaBase = max(0.0, min($reserva10 - $separacion, $porRepartir));
        $mensual   = max(0.0, ($porRepartir - $firmaBase) / $n);
    }

    // --- firma diferida: en vez de pagarse toda al firmar, se reparte sobre hasta
    // 12 meses SUMADA a la cuota de esos meses. Tope duro de 12 — "que no aplique
    // a más, porque si no la gente no termina pagando la entrada nunca". ---
    $firma = $firmaBase;
    $diferidoMeses = 0;
    $diferidoCuota = 0.0;
    $diferidoSobra = 0.0;
    $mesesFirmaOpt = (int)($opts['firmaMeses'] ?? 0);
    $cuotaFirmaOpt = (float)($opts['firmaCuota'] ?? 0);
    if ($firmaBase > 0.01 && ($mesesFirmaOpt > 0 || $cuotaFirmaOpt > 0)) {
        if ($cuotaFirmaOpt > 0) {
            // El asesor edita el monto mensual del diferido; de ahí sale cuántos meses
            // hacen falta, topado a 12. Lo que no alcance a cobrarse en el tope se
            // suma a las extraordinarias — "que el saldo lo pague en la extraordinaria".
            $mesesNecesarios = (int)ceil($firmaBase / $cuotaFirmaOpt);
            $diferidoMeses = max(1, min(12, $mesesNecesarios, $n));
            $diferidoCuota = $cuotaFirmaOpt;
            $cobrado = $diferidoCuota * $diferidoMeses;
            $diferidoSobra = max(0.0, $firmaBase - $cobrado);
        } else {
            $diferidoMeses = max(1, min(12, $mesesFirmaOpt, $n));
            $diferidoCuota = $firmaBase / $diferidoMeses;
        }
        $firma = 0.0;   // ya no se paga nada al firmar: quedó repartida en las cuotas
        if ($diferidoSobra > 0.01 && $nExtra > 0) {
            $extraTotal += $diferidoSobra;
            $diferidoSobra = 0.0;
        }
    }
    $valorExtra = $nExtra > 0 ? $extraTotal / $nExtra : 0.0;

    // --- tabla final ---
    $filas = [];
    foreach ($fechas as $i => $f) {
        $esExtra = in_array($i, $posExtra, true);
        $esDiferido = $i < $diferidoMeses;
        $filas[] = [
            'n'        => $i + 1,
            'fecha'    => $f->format('d/m/Y'),
            'monto'    => $mensual + ($esExtra ? $valorExtra : 0.0) + ($esDiferido ? $diferidoCuota : 0.0),
            'extra'    => $esExtra,
            'diferido' => $esDiferido,
        ];
    }

    // Cierre: la contraentrega es lo que falta para llegar al precio, sumando la
    // tabla REAL (ya con extraordinarias y diferido de firma adentro).
    $sumaCuotas = array_sum(array_column($filas, 'monto'));
    $contraentrega = $v - $separacion - $firma - $sumaCuotas;
    if ($contraentrega < -0.01) {
        // Ni con la contraentrega en 0 cabe lo que se pidió pagar antes: la cuota es
        // imposible para este plazo. Se recorta al máximo que cabe y se avisa.
        $sobrepago = true;
        $contraentrega = 0.0;
        $factor = ($v - $separacion - $firma) / max(0.01, $sumaCuotas);
        foreach ($filas as &$fl) $fl['monto'] *= $factor;
        unset($fl);
        $sumaCuotas = array_sum(array_column($filas, 'monto'));
        $mensual *= $factor; $valorExtra *= $factor; $diferidoCuota *= $factor;
    }
    if ($contraentrega < $contraPct * $v - 1.0) $bajaContraentrega = true;

    return [
        'valor'         => $v,
        'legal'         => cot_notaria($v),
        'separacion'    => $separacion,
        'firma'         => $firma,
        // 'reserva' es lo que el cliente pone ANTES de las cuotas: separación + firma.
        // Ya no es siempre el 10% — con la firma en 0 puede ser solo los $1.000.
        'reserva'       => $separacion + $firma,
        'reservaPct'    => $v > 0 ? ($separacion + $firma) / $v : 0.0,
        'contraentrega' => $contraentrega,
        'contraPct'     => $v > 0 ? $contraentrega / $v : 0.0,
        // Objetivo elegido (financiarPct, ya con el piso 35 aplicado) ANTES de que
        // firma/cuota fija pudieran empujar la contraentrega más abajo todavía —
        // es lo que 'bajaContraentrega' compara, y lo que el aviso necesita nombrar
        // en vez de un "60%" fijo que ya no es siempre el punto de partida real.
        'contraPctObjetivo' => $contraPct,
        // % financiado real (piso 35 ya aplicado arriba, esto es lo que quedó).
        'financiarPct'  => $v > 0 ? round((1 - $contraentrega / $v) * 100, 2) : 0.0,
        'cuotasTotal'   => $sumaCuotas,
        'cuotasPct'     => $v > 0 ? $sumaCuotas / $v : 0.0,
        'extraPct'      => $v > 0 ? $extraTotal / $v : 0.0,
        // Banderas para que la pantalla avise en vez de mentir en silencio.
        'bajaContraentrega' => $bajaContraentrega,
        'sobrepago'     => $sobrepago,
        'hasta'         => $n > 0 ? $fechas[$n - 1]->format('Y-m') : '',
        'hastaTxt'      => $n > 0 ? cot_mes_es((int)$fechas[$n - 1]->format('n')) . ' ' . $fechas[$n - 1]->format('Y') : '',
        'cuotas'        => $n,
        'mensual'       => $mensual,
        'modalidad'     => $iguales ? 'iguales' : 'estandar',
        'extraTotal'    => $extraTotal,
        'nExtra'        => $nExtra,
        'extraPartes'   => $extraPartes,
        'valorExtra'    => $valorExtra,
        // Meses reales que quedaron para la(s) extraordinaria(s), para que la
        // pantalla los muestre y el asesor pueda editarlos.
        'extraMes1'     => $iguales ? 0 : (isset($mes1) ? $mes1 : 0),
        'extraMes2'     => $iguales ? 0 : $mesExtra2,
        // Firma diferida: 0 meses = se pagó normal al firmar (o no había firma).
        'firmaBase'     => $firmaBase,
        'diferidoMeses' => $diferidoMeses,
        'diferidoCuota' => $diferidoCuota,
        'inicio'        => $primera->format('Y-m'),
        'inicioTxt'     => cot_mes_es((int)$primera->format('n')) . ' ' . $primera->format('Y'),
        'plazoMax'      => $plazoMax,
        'recortado'     => $recortado,
        'presupuesto'   => $presupuesto,
        'insuficiente'  => $insuficiente,
        'cuotaMinima'   => $cuotaMinima,
        'filas'         => $filas,
        // Cuadre: separación + firma + todas las cuotas + contraentrega debe dar el valor.
        'suma'          => $separacion + $firma + $sumaCuotas + $contraentrega,
    ];
}

function cot_mes_es(int $m): string {
    $n = ['', 'Enero','Febrero','Marzo','Abril','Mayo','Junio',
          'Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
    return $n[$m] ?? '';
}

function cot_money(float $v): string { return '$' . number_format($v, 2); }
