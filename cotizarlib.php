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

require_once __DIR__ . '/codigolib.php';   // codigos_comprimidos()

/** Valores legales (notaría) por rango de precio — tabla 2026. */
function cot_notaria(float $v): int {
    if ($v <= 60000)  return 466;
    if ($v <= 90000)  return 604;
    if ($v <= 150000) return 771;
    if ($v <= 300000) return 1048;
    return 1824;
}

/**
 * Entrega por proyecto. Manda el plazo: es fecha FIJA, así que mientras más tarde
 * arranquen las cuotas, menos caben antes de entregar.
 *
 * 🔴 LA FECHA SALE DE LA MATRIZ DEL PROYECTO, no de una lista escrita aquí. Estuvo
 * escrita a mano en los dos sitios y se separaron: `matrices/proyecto_33.json` decía
 * que Noral Plaza entrega en 2031-02 y esta función decía 2031-04. Dos meses. La
 * lista de precios (que lee la matriz) daba 54 meses y el cotizador 56 — al mismo
 * cliente, el mismo día.
 *
 * La dirección confirmó el 31-ago-2026 que la buena es **2031-04**: el cotizador
 * tenía razón y la matriz estaba vieja. Se corrigió la matriz, no el cotizador.
 *
 * La matriz es del proyecto; esta función solo la lee. Para mover una entrega se
 * edita `fecha_entrega` en la matriz y se mueven los dos motores a la vez.
 *
 * Si la matriz declara varias (una por lista), manda la MÁS TEMPRANA — la misma
 * regla que ya usa cotizar.php cuando la cotización junta unidades de dos torres.
 */
function cot_entrega(int $categoryId): ?array {
    static $cache = [];
    if (array_key_exists($categoryId, $cache)) return $cache[$categoryId];

    $f = __DIR__ . '/matrices/proyecto_' . $categoryId . '.json';
    $j = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;

    $mejor = null;
    if (is_array($j)) {
        foreach (cot_fechas_entrega($j) as $fe) {
            if (!preg_match('/^(\d{4})-(\d{2})$/', $fe, $m)) continue;
            $cand = ['y' => (int)$m[1], 'm' => (int)$m[2]];
            if ($mejor === null || ($cand['y'] * 12 + $cand['m']) < ($mejor['y'] * 12 + $mejor['m']))
                $mejor = $cand;
        }
    }

    // Galero Torre D no tiene matriz con financiamiento: su fecha vive en el contrato
    // y sigue aquí hasta que alguien la ponga en la matriz.
    if ($mejor === null && $categoryId === 51) $mejor = ['y' => 2027, 'm' => 5];

    return $cache[$categoryId] = $mejor;   // null = sin fecha fija (ver cot_modelo)
}

/** Todas las `fecha_entrega` que declare una matriz, esté donde esté: hay proyectos
 *  que la ponen arriba y otros dentro de cada lista. */
function cot_fechas_entrega($nodo): array {
    if (!is_array($nodo)) return [];
    $out = [];
    foreach ($nodo as $k => $v) {
        if ($k === 'fecha_entrega' && is_string($v)) $out[] = $v;
        elseif (is_array($v)) $out = array_merge($out, cot_fechas_entrega($v));
    }
    return $out;
}

/** MODELO DE PAGO por proyecto. Galjosa no vende igual en todos: Noral está en
 *  planos y financia a años (10/60/30 con extraordinarias); Galero Torre C ya está
 *  casi entregada, así que no hay plazo que financiar y va 30/70 sin extraordinarias.
 *
 *  reservaPct  qué parte del precio es la ENTRADA (incluye la separación de $1.000)
 *  contraPct   qué parte se paga CONTRA ENTREGA
 *  extra       si el proyecto usa cuotas extraordinarias
 *  maxCuotas   tope de cuotas mensuales que el asesor puede pedir
 *  maxExtra    tope de extraordinarias (solo si extra = true)
 *  entregaMin/entregaMax  rango en MESES que el asesor puede elegir como entrega,
 *              para los proyectos sin fecha fija (Galero Casas: 6 a 36 meses).
 *
 *  Un proyecto nuevo = un case más. El resto del motor no cambia. */
function cot_modelo(int $categoryId): array {
    $base = ['reservaPct'=>0.10, 'contraPct'=>0.60, 'extra'=>true,
             'maxCuotas'=>COT_PLAZO_REF, 'maxExtra'=>0, 'entregaMin'=>0, 'entregaMax'=>0,
             'banco'=>false, 'inmediata'=>false];
    switch ($categoryId) {
        // Noral Apartments: hasta 46 cuotas y solo 4 extraordinarias.
        case 39: return array_merge($base, ['maxCuotas'=>46, 'maxExtra'=>4]);

        // Noral Plaza: 5 extraordinarias, no 4. El tope NO es una regla del cotizador,
        // es cuántas cabe cobrar en cada proyecto: Plaza entrega en 2031 (la fecha
        // exacta la dice su matriz, no este comentario) y su plan cruza cinco años,
        // Apartments entrega antes y cruza cuatro. Declararlo
        // aquí (en vez de dejarlo en 0 = sin tope) es lo que habilita partir la
        // extraordinaria en 2 y personalizar los montos. El PLAZO no se toca: manda la
        // entrega de Plaza, no los 46 meses de Apartments.
        case 33: return array_merge($base, ['maxExtra'=>5]);

        // Galero Torre C — ENTREGA INMEDIATA. No se financia nada: el 30% de entrada
        // se paga DE UNA (los $1.000 de reserva son su primer abono) y el 70% restante
        // lo cubre el cliente CON EL BANCO contra entrega. Por eso maxCuotas = 0: no
        // hay cuotas mensuales que repartir, y la cotización muestra el bloque de
        // crédito hipotecario en vez de una tabla de pagos.
        case 47: return array_merge($base, ['reservaPct'=>0.30, 'contraPct'=>0.70,
                                            'extra'=>false, 'maxCuotas'=>0,
                                            'banco'=>true, 'inmediata'=>true]);

        // Galero Torre D — 30% de entrada A 9 MESES y el 70% contra entrega.
        // El reparto lo fija el contrato: 10% a la firma, 15% en las 9 mensuales y 5%
        // en UNA extraordinaria. Por eso `cuotasPct` es 0,15 y no el 20% de Noral.
        //
        // Lleva `banco` aunque SI tenga cuotas: son pocas y chicas, y el 70% de
        // contraentrega igual lo cubre el cliente con un credito. La simulacion corre
        // sobre `contraentrega`, o sea sobre lo que falta — no sobre el precio entero.
        case 51: return array_merge($base, ['reservaPct'=>0.10, 'contraPct'=>0.70,
                                            'cuotasPct'=>0.15, 'extraPct'=>0.05,
                                            'maxCuotas'=>9, 'maxExtra'=>1,
                                            'banco'=>true]);

        // Galero Casas — mismo 30/70 con banco, pero NO es entrega inmediata: la
        // entrega la elige el asesor entre 6 y 36 meses, y el 30% se reparte en las
        // cuotas que quepan hasta esa fecha.
        case 55: return array_merge($base, ['reservaPct'=>0.30, 'contraPct'=>0.70,
                                            'extra'=>false, 'maxCuotas'=>36,
                                            'banco'=>true,
                                            'entregaMin'=>6, 'entregaMax'=>36]);
    }
    return $base;   // Noral Plaza, Torre D, Suites y el resto: modelo histórico
}

const COT_PLAZO_REF = 60;     // plazo de referencia
const COT_SEPARACION = 1000;  // la separación siempre es $1.000 (o el 10% si es menor)
/* Plazo para pagar lo de la firma, en dias CORRIDOS desde la cotizacion. Se suman
   dias y no meses a proposito: '+1 month' sobre el 31 de agosto da 1 de octubre. */
const COT_DIAS_FIRMA = 10;
const COT_PARQUEO = 20000;    // valor de un parqueo de Noral Plaza Suites
/** El campo "Inventario" del deal. Se lee para saber si las unidades van en fusion o
 *  separadas, que es lo que decide el descuento del parqueo. Mismo codigo que
 *  CAMPO_NUEVO en campolib; se repite la constante porque el cotizador no carga
 *  campolib (arrastra los hooks y el API entero para leer un campo). */
const COT_CAMPO_UNIDADES = 'UF_CRM_1785205972989';

/** ¿La unidad es una SUITE de Noral Plaza? Es el único producto con la regla del
 *  parqueo. Se decide por el TIPO DE BIEN, no por el código: los edificios E y F
 *  tienen locales comerciales Y suites, y un local de $237.000 no puede llevarse
 *  el descuento de $20.000 solo por empezar con «E-». */
// Hoy las 219 suites de Plaza estan tipificadas como "Departamento" y ninguna como
// "Suite", pero las dos opciones existen en la lista y basta que alguien elija la
// otra para que esta pantalla dejara de contarla. El campo del deal la contaria
// igual —el mira el PROYECTO, no el tipo— y las dos cifras se separarian sin que
// nadie se entere. Se aceptan los dos tipos.
const COT_TIPO_DEPARTAMENTO = 1793;
const COT_TIPO_SUITE        = 1797;
function cot_es_suite(int $categoryId, int $tipo): bool {
    return $categoryId === 33 && in_array($tipo, [COT_TIPO_DEPARTAMENTO, COT_TIPO_SUITE], true);
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
 *        'extraPct'     porcentaje del precio que va en las EXTRAORDINARIAS (0.10 por
 *                       defecto, el reparto de Noral). Torre D pone 5%.
 *        'cuotasPct'    porcentaje del precio que va en las cuotas MENSUALES. Sin
 *                       esto se usa 20% (o 30% en modalidad "iguales"), que es como
 *                       reparte Noral. Torre D reparte 15%.
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
/** Monto de la extraordinaria que cae en la cuota $i. Con montos personalizados usa
 *  el de su posición; si no, el valor parejo. Separado en función para que la fila de
 *  la tabla siga siendo una línea legible. */
function cot_monto_extra(array $posExtra, array $extraMontos, float $parejo, int $i): float {
    if (!$extraMontos) return $parejo;
    $k = array_search($i, $posExtra, true);
    return $k === false ? $parejo : (float)($extraMontos[$k] ?? $parejo);
}

function cot_plan(float $valor, int $nCuotas, string $modalidad, string $mesInicio, ?array $entrega, float $presupuesto = 0.0, array $opts = []): array {
    $iguales = ($modalidad === 'iguales');
    // Lo que va en cuotas mensuales. Estaba clavado en 20% (30% sin extraordinarias)
    // porque los dos proyectos de Noral reparten asi, pero no es universal: Galero
    // Torre D pone 15% en las mensuales y 5% en una extraordinaria. Si el proyecto lo
    // declara, manda el suyo.
    $porc    = isset($opts['cuotasPct'])
                 ? max(0.0, min(1.0, (float)$opts['cuotasPct']))
                 : ($iguales ? 0.30 : 0.20);
    $v       = max(0.0, $valor);

    // --- primera cuota: el 16 del mes elegido; nunca un mes ya pasado ---
    $hoy = new DateTimeImmutable('now');
    /* Las dos fechas que faltaban en el cronograma. Se suman DIAS, no meses: sumar
       un mes al 31 de agosto da 1 de octubre porque septiembre no tiene 31, y eso
       habria puesto una fecha equivocada en un documento de cliente. */
    /* Si el asesor escribe cuando se paga la firma, esa manda y la separacion se
       deduce hacia atras. Si no, se asume que se cotiza y se reserva hoy.
       Se acepta fecha pasada a proposito: la reserva de un cliente que ya firmo
       OCURRIO, y sin poder escribirla no se puede reproducir su tabla de pagos. */
    $reservaFecha = $hoy;
    $firmaFecha   = $hoy->modify('+' . COT_DIAS_FIRMA . ' days');
    $ff = trim((string)($opts['fechaFirma'] ?? ''));
    if ($ff !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $ff)) {
        $cand = DateTimeImmutable::createFromFormat('!Y-m-d', $ff);
        // createFromFormat NO falla con un 31 de febrero: lo corre al 3 de marzo. Se
        // compara con lo escrito para descartar una fecha que no existe.
        if ($cand instanceof DateTimeImmutable && $cand->format('Y-m-d') === $ff) {
            $firmaFecha   = $cand;
            $reservaFecha = $cand->modify('-' . COT_DIAS_FIRMA . ' days');
        }
    }
    if (preg_match('/^(\d{4})-(\d{2})$/', $mesInicio, $m)) {
        $primera = new DateTimeImmutable(sprintf('%04d-%02d-16', (int)$m[1], (int)$m[2]));
    } else {
        // Paréntesis obligatorios: encadenar sobre `new` sin ellos es sintaxis de
        // PHP 8.4, y el contenedor corre 8.2 — revienta con parse error.
        /* La primera cuota cae el 16 del mes SIGUIENTE al de la firma. Antes caia
           el mes siguiente al de HOY, y eso la ponia en el mismo mes en que aun se
           estaba firmando -- o incluso antes de la firma. Ahora el orden es
           reserva -> firma -> primera cuota, que es como se cobra de verdad.

           🔴 Cotizar pasado el ~21 corre la firma al mes siguiente y con ella la
           primera cuota. Como el plazo lo fija la ENTREGA, eso deja una cuota
           menos y sube el valor de cada una. Medido en E-3-18: 56 cuotas de
           $516.54 pasan a 55 de $525.93. No es un error: si el cliente firma en
           septiembre, su primera cuota es en octubre. */
        $primera = (new DateTimeImmutable($firmaFecha->format('Y-m-16')))->modify('+1 month');
    }
    /* 🔴 EL PISO SOLO APLICA A LA COTIZACION AUTOMATICA. Existe para que una
       cotizacion nueva no arranque en un mes ya pasado. Pero cuando el asesor ESCRIBE
       la fecha de la firma o el mes de la primera cuota, esta describiendo un trato
       real -- muchas veces uno que ya ocurrio, para reproducir la tabla de pagos que
       el cliente ya acepto. Ahi el piso estaba pisando el dato: con la firma en enero
       de 2025 la primera cuota saltaba a agosto de 2026 y la tabla salia mal.
       Si lo escribieron, manda lo escrito. */
    $loEscribieron = ($mesInicio !== '') || ($ff !== '' && $firmaFecha != $hoy->modify('+' . COT_DIAS_FIRMA . ' days'));
    if (!$loEscribieron) {
        $piso = new DateTimeImmutable($hoy->format('Y-m-16'));
        if ($primera < $piso) $primera = $piso;
    }

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
    // La ENTRADA ya no está clavada en 10%: Galero Torre C y Casas van con 30%.
    // Se sigue llamando reserva10 para no tocar las 6 referencias de abajo.
    $reservaPct    = isset($opts['reservaPct']) ? max(0.0, min(1.0, (float)$opts['reservaPct'])) : 0.10;
    $reserva10     = $reservaPct * $v;
    $separacion    = min((float)COT_SEPARACION, $reserva10);
    $contraentrega = $contraPct * $v;
    $cargaCuotas   = $porc * $v;

    // --- número de cuotas: por HASTA, por PRESUPUESTO o por plazo ---
    // PLAZO FIJO: si el proyecto tiene fecha de entrega es un proyecto en planos, y
    // el plazo NO se acorta — el cliente paga hasta que se entregue, punto. Por eso
    // "puedo pagar $X al mes" ya no recorta meses: sube la cuota y lo que sobra se
    // descuenta de las EXTRAORDINARIAS (y si no alcanzan, de la contraentrega, que
    // ya se avisa aparte). Sin fecha de entrega no hay horizonte que respetar, así
    // que ahí se conserva el comportamiento viejo de calcular los meses.
    $plazoFijo = ($entrega !== null);
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
    } elseif ($presupuesto > 0 && $v > 0 && !$plazoFijo) {
        // Con presupuesto se invierte la pregunta: el cliente dice cuánto puede pagar
        // al mes y salen las cuotas necesarias. Es la forma en que se vende de verdad.
        $n = max(1, (int)ceil($cargaCuotas / $presupuesto));
        if ($plazoMax !== null && $n > $plazoMax) {
            // No alcanza: ni pagando hasta la entrega llega. Se topa al plazo real y
            // se avisa cuál es la cuota mínima posible, que es el dato accionable.
            $n = $plazoMax; $insuficiente = true; $cuotaMinima = $cargaCuotas / $plazoMax;
        }
    } else {
        // ENTREGA INMEDIATA (Galero Torre C): no hay nada que financiar, la entrada se
        // paga de una a la firma. Sin este caso, pedir 0 cuotas caía al plazo de
        // referencia (60) y repartía el 30% en cinco años, justo lo contrario.
        if (!empty($opts['inmediata'])) $n = 0;
        else $n = $nCuotas > 0 ? $nCuotas : ($plazoMax !== null ? min(COT_PLAZO_REF, $plazoMax) : COT_PLAZO_REF);
        if ($plazoMax !== null && $n > $plazoMax) { $n = $plazoMax; $recortado = true; }
    }
    // El piso de 1 cuota vale para todo el resto: un plan sin cuotas no existiría. La
    // excepción es la ENTREGA INMEDIATA (Galero Torre C), donde 0 es lo correcto: no
    // hay tabla de cuotas, la entrada va entera a la firma y el 70% al banco.
    if (empty($opts['inmediata'])) $n = max(1, $n);

    // Cuota mensual objetivo cuando el plazo es fijo: manda sobre el reparto y las
    // extraordinarias absorben la diferencia (arriba y abajo: pagar MENOS al mes las
    // engorda, pagar MÁS las adelgaza).
    $mensualObjetivo = ($plazoFijo && $presupuesto > 0 && $v > 0) ? $presupuesto : 0.0;

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
    // Sin cuotas (entrega inmediata) no hay dónde poner una extraordinaria: el bloque
    // de abajo asume al menos una fecha y explotaba con la tabla vacía.
    if (!$iguales && $fechas) {
        $porAnio = [];
        foreach ($fechas as $i => $f) $porAnio[(int)$f->format('Y')][] = $i;
        // TOPE: se cuenta en AÑOS, no en pagos. Noral Apartments tiene 4 extraordinarias;
        // si además se parten en 2, son 4 años × 2 = 8 pagos, y el tope sigue siendo 4.
        // Aplicarlo sobre los pagos (como estaba) dejaba 4 pagos = 2 años al partir.
        // Se sueltan los años MÁS TEMPRANOS: el primero casi siempre es parcial y cobrar
        // una extraordinaria a las pocas semanas de firmar es lo más duro para el cliente.
        $maxExtra = isset($opts['maxExtra']) ? (int)$opts['maxExtra'] : 0;
        if ($maxExtra > 0 && count($porAnio) > $maxExtra) {
            $porAnio = array_slice($porAnio, count($porAnio) - $maxExtra, null, true);
        }
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
    // Proyecto sin extraordinarias (Galero): no basta con poner el monto en 0 — hay que
    // sacar las posiciones, si no la tabla marca una fila como EXTRA sin serlo.
    if (array_key_exists('extra', $opts) && !$opts['extra']) $posExtra = [];
    $nExtra = count($posExtra);

    // ---------- REPARTO DEL DINERO ----------
    // Cuánto va en extraordinarias: monto por extraordinaria, total explícito, o el
    // 10% de siempre. Poner más acá alivia la cuota mensual, que es una de las
    // variantes que se venden.
    $firmaFijada   = isset($opts['firma'])   && $opts['firma'] !== '';
    $extraAbsorbio = false;
    // Proyecto SIN extraordinarias (Galero Torre C / Casas): el 30% de entrada se
    // reparte entre separación, firma y cuotas, y no hay dónde poner un extra.
    $sinExtra = array_key_exists('extra', $opts) && !$opts['extra'];
    if ($sinExtra) {
        $extraTotal = 0.0;
    } elseif ($iguales) {
        $extraTotal = 0.0;
    } elseif (isset($opts['extraCada']) && (float)$opts['extraCada'] > 0) {
        $extraTotal = (float)$opts['extraCada'] * $nExtra;
    } elseif (isset($opts['extraTotal']) && (float)$opts['extraTotal'] > 0) {
        $extraTotal = (float)$opts['extraTotal'];
    } elseif ($mensualObjetivo > 0 && $nExtra > 0) {
        // Plazo fijo + cuota pedida: las extraordinarias son el amortiguador. Se
        // quedan con lo que NO cubren la separación, la firma y las N cuotas.
        $firmaPrev  = $firmaFijada ? max(0.0, (float)$opts['firma'])
                                   : max(0.0, $reserva10 - $separacion);
        $extraTotal = max(0.0, $v - $separacion - $contraentrega - $firmaPrev - $mensualObjetivo * $n);
        $extraAbsorbio = true;
    } else {
        // 10% es el reparto de Noral. Torre D pone 5% en una sola extraordinaria, asi
        // que el proyecto puede declarar el suyo. Si no lo declara, sigue el de siempre.
        $extraTotal = (isset($opts['extraPct'])
                          ? max(0.0, min(1.0, (float)$opts['extraPct']))
                          : 0.10) * $v;
    }
    if ($nExtra === 0) $extraTotal = 0.0;      // sin extraordinarias no hay dónde ponerlo
    $extraTotal = max(0.0, min($extraTotal, max(0.0, $v - $separacion - $contraentrega)));

    // La CONTRAENTREGA es el cierre de la identidad, no un dato independiente: se
    // calcula al FINAL, sumando la tabla real de cuotas (no mensual × n — con la
    // firma diferida cada mes puede traer un monto distinto, así que hace falta la
    // tabla ya construida para que el cierre sea exacto).
    $mensualFijada = isset($opts['mensual']) && (float)$opts['mensual'] > 0;
    $porRepartir   = $v - $separacion - $contraentrega - $extraTotal;
    $bajaContraentrega = false;
    $sobrepago = false;

    if ($mensualObjetivo > 0) {
        // Plazo fijo: manda la cuota pedida y la firma se queda en su valor normal
        // (el ajuste ya se hizo en las extraordinarias, arriba).
        $mensual   = $mensualObjetivo;
        $firmaBase = $firmaFijada ? max(0.0, (float)$opts['firma'])
                                  : max(0.0, min($reserva10 - $separacion, $porRepartir));
    } elseif ($mensualFijada) {
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
    } elseif ($sinExtra) {
        // GALERO (30/70). Acá la ENTRADA no es un pago único: es lo que el cliente
        // abona ANTES de la entrega. Si pide cuotas, se reparte entre ellas y a la
        // firma va solo la separación; si pide 0 cuotas, cae toda a la firma —
        // que es el caso de Torre C, ya casi entregada.
        // El reparto clásico no sirve aquí: topaba la firma en reserva-separación,
        // que con entrada del 30% es TODO, y dejaba las cuotas en cero.
        $firmaBase = 0.0;
        $mensual   = ($n > 0) ? max(0.0, $porRepartir / $n) : 0.0;
        if ($n === 0) $firmaBase = $porRepartir;
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
    // MONTOS POR EXTRAORDINARIA. Por defecto se reparte el total en partes iguales.
    // Si el asesor los personaliza, escribe los primeros y la ÚLTIMA sale del RESIDUO:
    // así el plan cuadra con el precio por construcción y no hay descuadre posible a
    // mano. Un monto escrito de más se topa contra lo que queda (nunca negativo) y
    // 'extraExcedido' avisa cuánto sobró para que la pantalla lo diga en rojo.
    $valorExtra   = $nExtra > 0 ? $extraTotal / $nExtra : 0.0;
    $extraMontos  = [];
    $extraExcedido = 0.0;
    if ($nExtra > 0 && !empty($opts['extraMontos']) && is_array($opts['extraMontos'])) {
        $pedidos = array_values(array_map(fn($x) => max(0.0, (float)$x), $opts['extraMontos']));
        $usado = 0.0;
        for ($k = 0; $k < $nExtra - 1; $k++) {
            $m = $pedidos[$k] ?? 0.0;
            $m = min($m, max(0.0, $extraTotal - $usado));     // no puede pasarse del total
            $extraMontos[$k] = $m; $usado += $m;
        }
        $extraMontos[$nExtra - 1] = max(0.0, $extraTotal - $usado);   // la última: residuo
        $sumaPedida = 0.0;
        for ($k = 0; $k < $nExtra - 1; $k++) $sumaPedida += ($pedidos[$k] ?? 0.0);
        if ($sumaPedida > $extraTotal) $extraExcedido = $sumaPedida - $extraTotal;
    }

    // --- tabla final ---
    $filas = [];
    foreach ($fechas as $i => $f) {
        $esExtra = in_array($i, $posExtra, true);
        $esDiferido = $i < $diferidoMeses;
        $filas[] = [
            'n'        => $i + 1,
            'fecha'    => $f->format('d/m/Y'),
            'monto'    => $mensual + ($esExtra ? cot_monto_extra($posExtra, $extraMontos, $valorExtra, $i) : 0.0) + ($esDiferido ? $diferidoCuota : 0.0),
            'extra'    => $esExtra,
            'diferido' => $esDiferido,
        ];
    }

    /* ── CUADRE AL CENTAVO ──────────────────────────────────────────────────
     * Por dentro el plan siempre cerraba, porque la contraentrega se calculaba con
     * la cuota SIN redondear. El problema era el papel: 28.926,00 / 56 da
     * 516,535714…, cada fila se imprimía 516,54 y sumando la columna a mano salía
     * 28.926,24. El documento cuadraba solo si nadie lo sumaba — y el cliente lo
     * suma siempre. Pasó de verdad con E-3-18: nos devolvieron la tabla marcada.
     *
     * Se redondean las filas a centavos y la DIFERENCIA se carga a una sola cuota,
     * así la columna impresa suma exacto. Se elige la última cuota común (no una
     * extraordinaria: mezclar el ajuste con la extra hace ilegible de dónde sale el
     * número).
     */
    // El objetivo es la suma REAL sin redondear, no una formula re-derivada: cada
    // rama de arriba (mensual fija, firma fija, sin extraordinarias, diferido) llega
    // al monto por un camino distinto, y re-deducirlo se equivocaba de miles.
    $objetivo = round(array_sum(array_column($filas, 'monto')), 2);
    foreach ($filas as &$fl) $fl['monto'] = round($fl['monto'], 2);
    unset($fl);
    $sumaRed  = round(array_sum(array_column($filas, 'monto')), 2);
    $ajuste   = round($objetivo - $sumaRed, 2);
    if (abs($ajuste) >= 0.01) {
        $idx = null;
        for ($k = count($filas) - 1; $k >= 0; $k--) {
            if (empty($filas[$k]['extra'])) { $idx = $k; break; }
        }
        if ($idx === null) $idx = count($filas) - 1;        // todas extra: la última
        if ($idx >= 0) {
            $filas[$idx]['monto'] = round($filas[$idx]['monto'] + $ajuste, 2);
            $filas[$idx]['ajuste'] = true;                  // la pantalla lo marca
        }
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
    // Los hitos también a centavos: si la contraentrega queda con más decimales, el
    // total del documento no cierra aunque las cuotas sí.
    $separacion    = round($separacion, 2);
    $firma         = round($firma, 2);
    $contraentrega = round($v - $separacion - $firma - round($sumaCuotas, 2), 2);
    $sumaCuotas    = round($sumaCuotas, 2);

    if ($contraentrega < $contraPct * $v - 1.0) $bajaContraentrega = true;

    return [
        'valor'         => $v,
        'legal'         => cot_notaria($v),
        'separacion'    => $separacion,
        // Las fechas del cronograma completo: hasta ahora la reserva y la firma
        // salian sin fecha y no se sabia cuando vencia cada cosa.
        'fechaReserva'  => $reservaFecha->format('d/m/Y'),
        'fechaFirma'    => $firmaFecha->format('d/m/Y'),
        'diasFirma'     => COT_DIAS_FIRMA,
        'firma'         => $firma,
        // 'reserva' es lo que el cliente pone ANTES de las cuotas: separación + firma.
        // Ya no es siempre el 10% — con la firma en 0 puede ser solo los $1.000.
        'reserva'       => $separacion + $firma,
        'reservaPct'    => $v > 0 ? ($separacion + $firma) / $v : 0.0,
        'contraentrega' => $contraentrega,
        // Lo que el cliente le paga a la EMPRESA antes de la entrega: separación +
        // firma + todas las cuotas (mensuales y extraordinarias). Es el "Total Cuota
        // Inicial" de la tabla de pagos, y el crédito directo de la empresa.
        'totalInicial'  => round($separacion + $firma + $sumaCuotas, 2),
        'sumaCuotas'    => $sumaCuotas,
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
        // Plazo fijo por fecha de entrega: el asesor no puede acortar el plan, y si
        // pidió una cuota mensual fueron las extraordinarias las que se movieron.
        'plazoFijo'     => $plazoFijo,
        'extraAbsorbio' => $extraAbsorbio,
        'hasta'         => $n > 0 ? $fechas[$n - 1]->format('Y-m') : '',
        'hastaTxt'      => $n > 0 ? cot_mes_es((int)$fechas[$n - 1]->format('n')) . ' ' . $fechas[$n - 1]->format('Y') : '',
        'cuotas'        => $n,
        'mensual'       => $mensual,
        'modalidad'     => $iguales ? 'iguales' : 'estandar',
        'extraTotal'    => $extraTotal,
        'nExtra'        => $nExtra,
        'extraPartes'   => $extraPartes,
        'valorExtra'    => $valorExtra,
        'extraMontos'   => $extraMontos,
        'posExtra'      => $posExtra,
        'extraExcedido' => $extraExcedido,
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
