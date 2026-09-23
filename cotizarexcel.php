<?php
/**
 * cotizarexcel.php — el plan de pagos en Excel, con formulas vivas.
 * ---------------------------------------------------------------------------
 * DOS HOJAS, y el reparto importa:
 *
 *   "Plan de pagos"  VISIBLE. Es la cotizacion COMPLETA -- precio final, valores
 *                    legales, los cuadros, la tabla entera. Es la que se sube al
 *                    deal. No tiene un solo mando: todo sale de formulas.
 *   "Ajustes"        OCULTA. Ahi viven las celdas que se pueden cambiar y las
 *                    columnas de trabajo. Se muestra con clic derecho en las
 *                    pestañas -> Mostrar.
 *
 * Pedido del usuario (23-sep-2026), textual: "que la pestaña que lleva el plan de
 * pagos, la tabla de pagos que se va a subir al deal, solo sea esa, y que la otra
 * este oculta" y "tabla para subir... tiene que ser el formato completo, pues".
 * La version anterior tenia eso al reves: la hoja para subir era una tabla pelada
 * sin precio final ni credito directo, y los mandos estaban a la vista.
 *
 * 🔴 LAS CELDAS EDITABLES TIENEN LIMITE DE VERDAD. No un aviso de texto que se
 * ignora: `dataValidation` hace que Excel RECHACE el valor y explique por que.
 * Pedido: "que no permita o que salga error cuando coloque un numero que no cuadra
 * con el plan de pago".
 *
 * 🔴 CADA FORMULA LLEVA SU VALOR ESCRITO. Un programa que lee un .xlsx lee el valor
 * cacheado, NO la formula: sin el, el importador ve la hoja VACIA y no da ningun
 * error. Con `fullCalcOnLoad`, Excel lo recalcula apenas se abre.
 *
 * 🔴 NO ES EL MOTOR. Reproduce la ARITMETICA del plan, no las reglas del negocio:
 * no sabe cuantas cuotas caben antes de la entrega, ni el descuento de parqueo, ni
 * el piso de financiamiento. Por eso la hoja visible grita cuando deja de cuadrar y
 * dice que lo oficial es lo que emite el cotizador.
 * ---------------------------------------------------------------------------
 */
declare(strict_types=1);
require_once __DIR__ . '/xlsxlib.php';

const COT_XL_AJUSTES = 'Ajustes';

/** Lo que el documento necesita saber de cada bloque para armarse. */
function cot_excel_plan_de(array $b): array
{
    $plan  = $b['plan'];
    $filas = array_values((array)($plan['filas'] ?? []));
    $d = ['plan' => $plan, 'filas' => $filas, 'cods' => implode(', ', (array)($b['cods'] ?? [])),
          'soloFirma' => [], 'cuotas' => [], 'extras' => [], 'firmaEnCuota' => 0, 'sumFirmaEnCuota' => 0.0,
          'sumFirmaSola' => 0.0];
    foreach ($filas as $fila) {
        if (!empty($fila['soloFirma'])) { $d['soloFirma'][] = $fila; $d['sumFirmaSola'] += (float)$fila['monto']; continue; }
        $d['cuotas'][] = $fila;
        if ((float)($fila['firma'] ?? 0) > 0) { $d['firmaEnCuota']++; $d['sumFirmaEnCuota'] += (float)$fila['firma']; }
        if (!empty($fila['extra']))
            $d['extras'][] = round((float)$fila['monto'] - (float)($plan['mensual'] ?? 0) - (float)($fila['firma'] ?? 0), 2);
    }
    $d['nExtra'] = count($d['extras']);
    /* Solo se vuelven formula si son TODAS IGUALES. Repartidas a mano con montos
       distintos, escalarlas las aplanaria. */
    $d['extraParejas'] = $d['nExtra'] > 0 && count(array_unique($d['extras'])) === 1;
    $v = (float)($plan['valor'] ?? 0);
    /* 🔴 Los porcentajes salen del DINERO, no de `reservaPct`: ese campo vale 0,009009
       cuando la firma se paga sola, y confiar en el dejaba las filas de firma en cero. */
    $entrada = (float)($plan['separacion'] ?? 0) + (float)($plan['firma'] ?? 0)
             + $d['sumFirmaSola'] + $d['sumFirmaEnCuota'];
    $d['pctEntrada'] = $v > 0 ? round($entrada / $v, 10) : 0.1;
    $d['pctContra']  = $v > 0 ? round((float)($plan['contraentrega'] ?? 0) / $v, 10)
                              : round((float)($plan['contraPct'] ?? 0.6), 10);
    $d['pctExtra']   = $v > 0 ? round(array_sum($d['extras']) / $v, 10)
                              : round((float)($plan['extraPct'] ?? 0.1), 10);
    return $d;
}

/** Donde va a quedar cada cosa en la hoja oculta. Se calcula ANTES de escribir nada,
 *  porque el documento apunta a esas celdas y necesita sus numeros de fila. */
function cot_excel_mapa(array $planes): array
{
    $mapa = []; $f = 1;
    foreach ($planes as $i => $d) {
        $m = ['titulo' => $f]; $f++;
        foreach (['precio', 'pctEntrada', 'sep', 'pctContra', 'contra'] as $k) { $m[$k] = $f; $f++; }
        if ($d['extraParejas']) { $m['pctExtra'] = $f; $f++; $m['totalExtra'] = $f; $f++; }
        foreach (['n', 'ini', 'financiado', 'mensual', 'cuadra', 'avisos'] as $k) { $m[$k] = $f; $f++; }
        $f++;
        $m['cabTabla'] = $f; $f++;
        $m['priCuota'] = $f;
        $f += count($d['cuotas']);
        $m['ultCuota'] = $f - 1;
        $f += 2;
        $mapa[$i] = $m;
    }
    return $mapa;
}

function cot_excel_generar(array $bloques, array $meta): string
{
    $planes = array_map('cot_excel_plan_de', $bloques);
    $mapa   = cot_excel_mapa($planes);

    $x = new Xlsx('Plan de pagos');
    $filasDoc = [];
    cot_excel_documento($x, $planes, $mapa, $meta, $filasDoc);
    cot_excel_ajustes($x, $planes, $mapa, $filasDoc);
    return $x->salida();
}

/** Referencia absoluta a una celda de la hoja oculta. */
function cot_xl_aj(int $fila, int $col = 2): string
{
    return COT_XL_AJUSTES . '!$' . Xlsx::col($col) . '$' . $fila;
}

/** LA HOJA QUE SE SUBE: la cotizacion completa, sin un solo mando. */
function cot_excel_documento(Xlsx $x, array $planes, array $mapa, array $meta, array &$filasDoc = []): void
{
    $x->ancho(1, 7); $x->ancho(2, 16); $x->ancho(3, 34); $x->ancho(4, 18);

    $f = 1;
    $x->alto($f, 26);
    $x->texto($f, 1, 'GALJOSA · COTIZACIÓN', Xlsx::LOGO); $x->unir($f, 1, $f, 4); $f += 2;
    foreach ([['Cliente', (string)($meta['cliente'] ?? '')],
              ['Proyecto', (string)($meta['proyecto'] ?? '')],
              ['Unidad',   (string)($meta['unidades'] ?? '')]] as [$r, $v]) {
        if ($v === '') continue;
        $x->texto($f, 1, $r, Xlsx::DATO_R);
        $x->texto($f, 2, $v, Xlsx::DATO_V); $x->unir($f, 2, $f, 4); $f++;
    }
    $x->texto($f, 1, 'Generado el ' . (string)($meta['fecha'] ?? date('d/m/Y')), Xlsx::NOTA);
    $x->unir($f, 1, $f, 4); $f += 2;

    foreach ($planes as $i => $d) {
        $m = $mapa[$i]; $plan = $d['plan'];
        $P   = cot_xl_aj($m['precio']);
        $PE  = cot_xl_aj($m['pctEntrada']);
        $SEP = cot_xl_aj($m['sep']);
        $CON = cot_xl_aj($m['contra']);
        $FIN = cot_xl_aj($m['financiado']);
        $MEN = cot_xl_aj($m['mensual']);
        $ENT = "ROUND($P*$PE,2)";
        $vp  = (float)($plan['valor'] ?? 0);
        $ent = round($vp * $d['pctEntrada'], 2);

        if (count($planes) > 1) {
            $x->texto($f, 1, 'Unidad ' . ($i + 1) . ' · ' . $d['cods'], Xlsx::SUBTITULO);
            $x->unir($f, 1, $f, 4); $f++;
        }

        $x->alto($f, 22);
        foreach ([1, 2, 3] as $c) $x->texto($f, $c, $c === 1 ? 'Precio final' : '', Xlsx::BANDA_AMA_T);
        $x->unir($f, 1, $f, 3);
        $x->formula($f, 4, $P, Xlsx::BANDA_AMA_N, round($vp, 2)); $f++;

        if ((float)($plan['legal'] ?? 0) > 0) {
            $x->alto($f, 20);
            foreach ([1, 2, 3] as $c) $x->texto($f, $c, $c === 1 ? 'Valores legales promesa C/V' : '', Xlsx::BANDA_VER_T);
            $x->unir($f, 1, $f, 3);
            $x->numero($f, 4, round((float)$plan['legal'], 2), Xlsx::BANDA_VER_N); $f++;
            $x->texto($f, 1, 'Pago directo para el notario. Se da al momento de la firma del contrato.', Xlsx::NOTA);
            $x->unir($f, 1, $f, 4); $f++;
        }
        $f++;

        $nSola = count($d['soloFirma']);
        $cuadros = [
            ['RESERVA',        $SEP,          round((float)($plan['separacion'] ?? 0), 2), false],
            [$nSola > 0 ? 'FIRMA (en ' . $nSola . ' pagos)' : 'A LA FIRMA',
                               "$ENT-$SEP",   round($ent - (float)($plan['separacion'] ?? 0), 2), false],
            ['CRÉDITO DIRECTO', "$ENT+$FIN",  round((float)($plan['totalInicial'] ?? 0), 2), true],
            ['CONTRA ENTREGA',  $CON,         round((float)($plan['contraentrega'] ?? 0), 2), false],
            ['CUOTA MENSUAL',   $MEN,         round((float)($plan['mensual'] ?? 0), 2), false],
        ];
        foreach ($cuadros as [$rot, $form, $val, $dest]) {
            foreach ([1, 2, 3] as $c) $x->texto($f, $c, $c === 1 ? $rot : '', $dest ? Xlsx::FICHA_DR : Xlsx::FICHA_R);
            $x->unir($f, 1, $f, 3);
            $x->formula($f, 4, $form, $dest ? Xlsx::FICHA_DV : Xlsx::FICHA_V, $val); $f++;
        }
        $f++;

        $x->alto($f, 20);
        $x->texto($f, 1, 'N°', Xlsx::CABECERA);
        $x->texto($f, 2, 'VENCIMIENTO', Xlsx::CABECERA);
        $x->texto($f, 3, 'CONCEPTO', Xlsx::CABECERA);
        $x->texto($f, 4, 'VALOR CUOTA', Xlsx::CABECERA);
        $x->congelarHasta($f); $f++;

        $pri = $f;
        if (!empty($plan['fechaReserva'])) {
            $x->texto($f, 1, '', Xlsx::HITO_T);
            $x->numero($f, 2, Xlsx::fecha(DateTimeImmutable::createFromFormat('!d/m/Y', $plan['fechaReserva'])), Xlsx::FECHA);
            $x->texto($f, 3, 'SEPARACIÓN', Xlsx::HITO_T);
            $x->formula($f, 4, $SEP, Xlsx::HITO_N, round((float)($plan['separacion'] ?? 0), 2)); $f++;
        }
        if ($nSola === 0 && (float)($plan['firma'] ?? 0) > 0 && !empty($plan['fechaFirma'])) {
            $x->texto($f, 1, '', Xlsx::HITO_T);
            $x->numero($f, 2, Xlsx::fecha(DateTimeImmutable::createFromFormat('!d/m/Y', $plan['fechaFirma'])), Xlsx::FECHA);
            $x->texto($f, 3, 'A LA FIRMA', Xlsx::HITO_T);
            $x->formula($f, 4, "$ENT-$SEP", Xlsx::HITO_N, round((float)$plan['firma'], 2)); $f++;
        }
        $kf = 0;
        foreach ($d['soloFirma'] as $fila) {
            $kf++;
            $x->texto($f, 1, '', Xlsx::HITO_T);
            $x->numero($f, 2, Xlsx::fecha(DateTimeImmutable::createFromFormat('!d/m/Y', $fila['fecha'])), Xlsx::FECHA);
            $x->texto($f, 3, 'FIRMA', Xlsx::HITO_T);
            $x->formula($f, 4, $kf < $nSola ? "ROUND(($ENT-$SEP)/$nSola,2)"
                        : "$ENT-$SEP-ROUND(($ENT-$SEP)/$nSola,2)*" . ($nSola - 1),
                        Xlsx::HITO_N, round((float)$fila['monto'], 2)); $f++;
        }

        $rAj = $m['priCuota'];
        $priDoc = $f;                      // primera fila de cuota EN EL DOCUMENTO
        foreach ($d['cuotas'] as $k => $fila) {
            $esX = !empty($fila['extra']);
            $x->numero($f, 1, $k + 1, $esX ? Xlsx::EXTRA_T : Xlsx::CUOTA_T);
            $x->formula($f, 2, 'EDATE(' . cot_xl_aj($m['ini']) . ',' . $k . ')', Xlsx::FECHA,
                        Xlsx::fecha(DateTimeImmutable::createFromFormat('!d/m/Y', $fila['fecha'])));
            $x->texto($f, 3, $esX ? 'EXTRAORDINARIA' : '', $esX ? Xlsx::EXTRA_T : Xlsx::CUOTA_T);
            /* base + extraordinaria + firma diferida, las tres de la hoja oculta. */
            $x->formula($f, 4,
                COT_XL_AJUSTES . '!$F$' . $rAj . '+' . COT_XL_AJUSTES . '!$D$' . $rAj
                . ($d['firmaEnCuota'] > 0 ? '+' . COT_XL_AJUSTES . '!$E$' . $rAj : ''),
                $esX ? Xlsx::EXTRA_N : Xlsx::CUOTA_N, round((float)$fila['monto'], 2));
            $f++; $rAj++;
        }
        $ultFila = $f - 1;
        /* Se anota para que la hoja oculta pueda CONTAR las cuotas mirando la columna
           N° del documento, que son numeros escritos. Contar su propia columna de
           formulas creaba una referencia CIRCULAR -- ver el comentario en 'n'. */
        $filasDoc[$i] = ['pri' => $priDoc, 'ult' => $ultFila];

        foreach ([1, 2] as $c) $x->texto($f, $c, '', Xlsx::TOTINI_T);
        $x->texto($f, 3, 'TOTAL CUOTA INICIAL', Xlsx::TOTINI_T);
        $x->formula($f, 4, "$ENT+$FIN", Xlsx::TOTINI_N, round((float)($plan['totalInicial'] ?? 0), 2)); $f++;
        foreach ([1, 2] as $c) $x->texto($f, $c, '', Xlsx::HITO_T);
        $x->texto($f, 3, 'CONTRA ENTREGA', Xlsx::HITO_T);
        $x->formula($f, 4, $CON, Xlsx::HITO_N, round((float)($plan['contraentrega'] ?? 0), 2));
        $fContra = $f; $f++;

        $x->alto($f, 22);
        foreach ([1, 2] as $c) $x->texto($f, $c, '', Xlsx::GRAN_T);
        $x->texto($f, 3, 'TOTAL', Xlsx::GRAN_T);
        /* 🔴 La suma SALTA "TOTAL CUOTA INICIAL": esa fila es un SUBTOTAL del documento,
           no un pago mas. Sumarla daba 155.400 sobre un precio de 111.000. */
        $x->formula($f, 4, 'SUM($D$' . $pri . ':$D$' . $ultFila . ')+$D$' . $fContra,
                    Xlsx::GRAN_N, round($vp, 2));
        $fTotal = $f; $f++;

        /* El grito. Vive en la hoja que se sube, no escondido entre los mandos: si la
           tabla deja de cuadrar tiene que verlo quien la manda, no quien la edita.
           Vacio cuando todo esta bien -- que aparezca texto ES la alarma. */
        $x->formula($f, 1, 'IF(ABS($D$' . $fTotal . '-' . $P . ')<0.005,"",'
            . '"⚠ ESTA TABLA NO CUADRA — no la subas: rehacela en el cotizador")',
            Xlsx::AVISO_MAL, '');
        $x->unir($f, 1, $f, 4); $f++;
        $x->texto($f, 1, 'Lo oficial es lo que emite el cotizador. Para cambiar algo: clic derecho '
            . 'en las pestañas → Mostrar → «Ajustes».', Xlsx::NOTA);
        $x->unir($f, 1, $f, 4); $f += 2;
    }
}

/** LA HOJA OCULTA: los mandos y las columnas de trabajo. */
function cot_excel_ajustes(Xlsx $x, array $planes, array $mapa, array $filasDoc): void
{
    $x->nuevaHoja(COT_XL_AJUSTES);
    $x->ocultar();
    $x->ancho(1, 34); $x->ancho(2, 16); $x->ancho(3, 3);
    $x->ancho(4, 16); $x->ancho(5, 16); $x->ancho(6, 14);

    foreach ($planes as $i => $d) {
        $m = $mapa[$i]; $plan = $d['plan'];
        $vp = (float)($plan['valor'] ?? 0);
        $P   = cot_xl_aj($m['precio']);
        $PE  = cot_xl_aj($m['pctEntrada']);
        $SEP = cot_xl_aj($m['sep']);
        $PC  = cot_xl_aj($m['pctContra']);
        $CON = cot_xl_aj($m['contra']);
        $N   = cot_xl_aj($m['n']);
        $INI = cot_xl_aj($m['ini']);
        $FIN = cot_xl_aj($m['financiado']);
        $MEN = cot_xl_aj($m['mensual']);
        $ENT = "ROUND($P*$PE,2)";
        $ent = round($vp * $d['pctEntrada'], 2);

        $x->texto($m['titulo'], 1, 'AJUSTES' . (count($planes) > 1 ? ' · unidad ' . ($i + 1) . ' ' . $d['cods'] : '')
            . ' — lo amarillo se puede cambiar', Xlsx::SUBTITULO);
        $x->unir($m['titulo'], 1, $m['titulo'], 6);

        $x->texto($m['precio'], 1, 'Precio de venta', Xlsx::ETIQUETA);
        $x->numero($m['precio'], 2, round($vp, 2), Xlsx::EDITABLE);
        $x->limite($m['precio'], 2, 'decimal', 'greaterThan', '0', '',
            'Precio inválido', 'El precio de venta tiene que ser mayor que cero.');

        $x->texto($m['pctEntrada'], 1, '% de entrada (incluye separación)', Xlsx::ETIQUETA);
        $x->numero($m['pctEntrada'], 2, $d['pctEntrada'], Xlsx::PORCENTAJE);
        /* 🔴 Los limites NO son decorativos: Excel RECHAZA el valor. El piso del 5% y el
           techo del 60% no son del motor -- son el rango donde el plan sigue teniendo
           sentido. Fuera de ahi la cuota se vuelve absurda y nadie lo nota a tiempo. */
        $x->limite($m['pctEntrada'], 2, 'decimal', 'between', '0.05', '0.6',
            'Entrada fuera de rango', 'La entrada va entre 5% y 60% del precio. '
            . 'Si el trato necesita otra cosa, hay que rehacerlo en el cotizador.');

        $x->texto($m['sep'], 1, 'Separación', Xlsx::ETIQUETA);
        $x->numero($m['sep'], 2, round((float)($plan['separacion'] ?? 0), 2), Xlsx::EDITABLE);
        $x->limite($m['sep'], 2, 'decimal', 'between', '0', '=' . $ENT,
            'Separación fuera de rango', 'La separación no puede ser negativa ni mayor '
            . 'que la entrada completa.');

        $x->texto($m['pctContra'], 1, '% contra entrega', Xlsx::ETIQUETA);
        $x->numero($m['pctContra'], 2, $d['pctContra'], Xlsx::PORCENTAJE);
        $x->limite($m['pctContra'], 2, 'decimal', 'between', '0', '=1-' . $PE,
            'Contra entrega fuera de rango', 'La contra entrega más la entrada no pueden '
            . 'pasar del 100% del precio: no quedaría nada que financiar en cuotas.');

        $x->texto($m['contra'], 1, 'Contra entrega', Xlsx::ETIQUETA);
        $x->formula($m['contra'], 2, "ROUND($P*$PC,2)", Xlsx::DINERO_B,
                    round((float)($plan['contraentrega'] ?? 0), 2));

        $TX = '';
        if ($d['extraParejas']) {
            $x->texto($m['pctExtra'], 1, '% extraordinarias', Xlsx::ETIQUETA);
            $x->numero($m['pctExtra'], 2, $d['pctExtra'], Xlsx::PORCENTAJE);
            $x->limite($m['pctExtra'], 2, 'decimal', 'between', '0', '=1-' . $PE . '-' . $PC,
                'Extraordinarias fuera de rango', 'Las extraordinarias no pueden superar lo que '
                . 'queda por financiar: dejarían la cuota mensual en cero o negativa.');
            $PX = cot_xl_aj($m['pctExtra']);
            $x->texto($m['totalExtra'], 1, 'Total extraordinarias', Xlsx::ETIQUETA);
            $x->formula($m['totalExtra'], 2, "ROUND($P*$PX,2)", Xlsx::DINERO_B,
                        round(array_sum($d['extras']), 2));
            $TX = cot_xl_aj($m['totalExtra']);
        }

        $colD = 'D'; $colE = 'E'; $colF = 'F';
        $pri = $m['priCuota']; $ult = $m['ultCuota'];
        $rangoX = "\$$colD\$$pri:\$$colD\$$ult";

        /* 🔴 CUENTA LA COLUMNA N° DEL DOCUMENTO, que son numeros escritos. Contar la
           columna F de aca -- la cuota base -- creaba una REFERENCIA CIRCULAR: F sale de
           la cuota mensual, la mensual divide por este numero, y este numero contaba F.
           Excel habria abierto el archivo con una advertencia de referencia circular y
           las cuotas en cero. Lo destapo el motor de formulas devolviendo None. */
        $dd = $filasDoc[$i] ?? ['pri' => 1, 'ult' => 1];
        $x->texto($m['n'], 1, 'Número de cuotas', Xlsx::ETIQUETA);
        $x->formula($m['n'], 2, "COUNT('Plan de pagos'!\$A\${$dd['pri']}:\$A\${$dd['ult']})",
                    Xlsx::CENTRO, count($d['cuotas']));

        $x->texto($m['ini'], 1, 'Primera cuota', Xlsx::ETIQUETA);
        $pf = $d['cuotas'] ? DateTimeImmutable::createFromFormat('!d/m/Y', $d['cuotas'][0]['fecha']) : null;
        $x->numero($m['ini'], 2, $pf ? Xlsx::fecha($pf) : 0, Xlsx::FECHA);
        if ($pf) $x->limite($m['ini'], 2, 'date', 'between',
            $pf->modify('-2 year')->format('Y-m-d'), $pf->modify('+3 year')->format('Y-m-d'),
            'Fecha fuera de rango', 'La primera cuota quedó a más de dos años de la original. '
            . 'Mover el arranque cambia cuántas cuotas caben antes de la entrega, y eso solo '
            . 'lo sabe el cotizador.');

        $x->texto($m['financiado'], 1, 'A financiar en cuotas', Xlsx::ETIQUETA);
        $x->formula($m['financiado'], 2, "$P-$ENT-$CON", Xlsx::DINERO_B,
                    round($vp - $ent - (float)($plan['contraentrega'] ?? 0), 2));

        $x->texto($m['mensual'], 1, 'Cuota mensual', Xlsx::ETIQUETA);
        $x->formula($m['mensual'], 2, "ROUND(($FIN-SUM($rangoX))/$N,2)", Xlsx::DINERO_B,
                    round((float)($plan['mensual'] ?? 0), 2));

        $x->texto($m['cuadra'], 1, '¿CUADRA?', Xlsx::ETIQUETA);
        $x->formula($m['cuadra'], 2, "IF(ABS($SEP+($ENT-$SEP)+$FIN+$CON-$P)<0.005,\"CUADRA\",\"NO CUADRA\")",
                    Xlsx::AVISO_OK, 'CUADRA');
        $x->texto($m['avisos'], 1, 'AVISOS', Xlsx::ETIQUETA);
        $ultBase = "\$$colF\$$ult";
        $x->formula($m['avisos'], 2,
            "IF($FIN<0,\"La entrada y la contraentrega superan el precio\","
          . "IF($MEN<=0,\"Las extraordinarias no dejan cuota mensual\","
          . "IF(ABS($ultBase-$MEN)>1,\"La última cuota quedó muy distinta de las demás\","
          . "\"sin avisos\")))", Xlsx::AVISO_OK, 'sin avisos');

        $x->texto($m['cabTabla'], 4, 'EXTRAORDINARIA', Xlsx::CABECERA);
        if ($d['firmaEnCuota'] > 0) $x->texto($m['cabTabla'], 5, 'FIRMA DIFERIDA', Xlsx::CABECERA);
        $x->texto($m['cabTabla'], 6, 'CUOTA BASE', Xlsx::CABECERA);

        $kx = 0; $kfc = 0; $r = $pri;
        foreach ($d['cuotas'] as $fila) {
            $base = round((float)($plan['mensual'] ?? 0), 2);
            if ((float)($fila['firma'] ?? 0) > 0 && $d['firmaEnCuota'] > 0) {
                $kfc++;
                $peso = $d['sumFirmaEnCuota'] > 0 ? round((float)$fila['firma'] / $d['sumFirmaEnCuota'], 10) : 0;
                $x->formula($r, 5, "ROUND(($ENT-$SEP)*$peso,2)", Xlsx::NORMAL,
                            round(($ent - (float)($plan['separacion'] ?? 0)) * $peso, 2));
            }
            if (!empty($fila['extra'])) {
                $e = round((float)$fila['monto'] - (float)($plan['mensual'] ?? 0) - (float)($fila['firma'] ?? 0), 2);
                if ($d['extraParejas']) {
                    $kx++;
                    $x->formula($r, 4, $kx < $d['nExtra'] ? "ROUND($TX/{$d['nExtra']},2)"
                        : "$TX-ROUND($TX/{$d['nExtra']},2)*" . ($d['nExtra'] - 1), Xlsx::NORMAL, $e);
                } else {
                    $x->numero($r, 4, max(0.0, $e), Xlsx::EDITABLE);
                    $x->limite($r, 4, 'decimal', 'greaterThanOrEqual', '0',
                        '', 'Extraordinaria inválida', 'Una extraordinaria no puede ser negativa.');
                }
            }
            if ($r === $ult && $ult > $pri) {
                /* La ULTIMA es el residuo, no otra vez el valor redondeado: es como cuadra
                   los centavos el motor. Y descuenta el centavo del reparto de la firma. */
                $aj = $d['firmaEnCuota'] > 0
                    ? "-(SUM(\$$colE\$$pri:\$$colE\$$ult)-($ENT-$SEP))" : '';
                $x->formula($r, 6, "$FIN-SUM($rangoX)-SUM(\$$colF\$$pri:\$$colF\$" . ($ult - 1) . ')' . $aj,
                            Xlsx::NORMAL, $base);
            } else {
                $x->formula($r, 6, $MEN, Xlsx::NORMAL, $base);
            }
            $r++;
        }
    }
}
