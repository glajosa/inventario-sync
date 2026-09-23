<?php
/**
 * cotizarexcel.php — el plan de pagos en Excel, con FORMULAS VIVAS.
 * ---------------------------------------------------------------------------
 * Pedido del usuario (23-sep-2026): "hay que poder descargar aparte de pdf, poderlo
 * hacer en excel, sin que se rompa el formato que tenemos... es que a veces el
 * vendedor quiere modificar cosas, lo quieren mas flexible... que tengan formula
 * logica implementada".
 *
 * ── LO QUE ES Y LO QUE NO ES ───────────────────────────────────────────────
 * ES: la misma tabla que sale en el PDF, pero donde las celdas AMARILLAS se pueden
 * cambiar y todo lo demas se recalcula solo. Cambiar el precio, las cuotas, la fecha
 * de la primera cuota o una extraordinaria y ver el resultado al instante, sin
 * volver al cotizador.
 *
 * 🔴 NO ES el motor. El cotizador decide cosas que una hoja de calculo no puede
 * saber: cuantas cuotas caben antes de la ENTREGA del proyecto, el descuento de
 * parqueo de las suites, el piso del 35% de financiamiento, en que meses caen las
 * extraordinarias. Si el vendedor cambia algo aca, el Excel le dira si la tabla
 * SIGUE CUADRANDO -- pero no si el trato es vendible. Por eso:
 *   · la fila CUADRE compara la suma contra el precio y dice CUADRA / NO CUADRA;
 *   · queda escrito en la hoja que lo oficial es lo que emite el cotizador.
 * Un Excel que se corrige solo y calla es peor que no tenerlo: el cliente firmaria
 * un numero que el sistema no reconoce.
 *
 * ── POR QUE LAS FORMULAS SON ASI ───────────────────────────────────────────
 * La cuota mensual sale de ROUND(financiado/cuotas, 2), igual que el motor. Y la
 * ULTIMA cuota es el RESIDUO -- lo que falta para llegar al total -- no otra vez el
 * valor redondeado. Es lo mismo que hace el cotizador al cuadrar los centavos: sin
 * eso, 28.926,00 / 56 deja 24 centavos sueltos y la columna no suma el precio.
 * ---------------------------------------------------------------------------
 */
declare(strict_types=1);
require_once __DIR__ . '/xlsxlib.php';

/**
 * @param array $bloques  los mismos que dibuja cotizar.php: cods, pvp, plan...
 * @param array $meta     cliente, proyecto, unidades, fecha
 */
function cot_excel_generar(array $bloques, array $meta): string
{
    $x = new Xlsx('Plan de pagos');
    $x->ancho(1, 7); $x->ancho(2, 16); $x->ancho(3, 30); $x->ancho(4, 16);
    $x->ancho(5, 16); $x->ancho(6, 16); $x->ancho(7, 14);

    $f = 1;
    $x->texto($f, 1, 'GALJOSA · PLAN DE PAGOS', Xlsx::TITULO); $f++;
    $linea = array_filter([
        $meta['cliente'] ?? '', $meta['unidades'] ?? '', $meta['proyecto'] ?? '',
    ]);
    $x->texto($f, 1, implode('  ·  ', $linea), Xlsx::PIE); $f++;
    $x->texto($f, 1, 'Generado el ' . (string)($meta['fecha'] ?? date('d/m/Y'))
        . '  ·  Las celdas AMARILLAS se pueden cambiar; el resto se recalcula solo.', Xlsx::PIE); $f++;
    $x->texto($f, 1, 'Lo OFICIAL es lo que emite el cotizador. Esta hoja sirve para simular: '
        . 'avisa si la tabla deja de cuadrar, pero no sabe si el trato es vendible.', Xlsx::PIE);
    $f += 2;

    foreach ($bloques as $i => $b) {
        $f = cot_excel_bloque($x, $f, $b, count($bloques) > 1 ? ($i + 1) : 0);
        $f += 2;
    }
    return $x->salida();
}

/** Escribe UN bloque (una unidad o la fusion) y devuelve la primera fila libre. */
function cot_excel_bloque(Xlsx $x, int $f, array $b, int $n): int
{
    $plan  = $b['plan'];
    $filas = array_values((array)($plan['filas'] ?? []));
    $cods  = implode(', ', (array)($b['cods'] ?? []));

    if ($n > 0) { $x->texto($f, 1, "Unidad $n · $cods", Xlsx::SUBTITULO); $f++; }

    // ── supuestos: lo que el vendedor puede tocar ──────────────────────────
    $x->texto($f, 1, 'SUPUESTOS', Xlsx::SUBTITULO);
    $x->texto($f, 3, 'se pueden cambiar', Xlsx::PIE); $f++;
    /* 🔴 LA ENTRADA Y LA CONTRAENTREGA VAN POR PORCENTAJE, NO POR MONTO FIJO. Primero
       las puse como montos y estaba mal: al bajar el precio de 111.000 a 90.000 la
       firma y la contraentrega se quedaban clavadas y la cuota mensual se desplomaba
       a $22,22. En el negocio son porcentajes del precio (10% de entrada, 60% contra
       entrega), asi que moviendo el precio se mueve todo con el, que es lo que el
       vendedor espera. Si quiere otra reparticion, cambia el PORCENTAJE. */
    $sup = [];
    $col = Xlsx::col(4);
    $x->texto($f, 1, 'Precio de venta', Xlsx::ETIQUETA);
    $x->numero($f, 4, round((float)($plan['valor'] ?? 0), 2), Xlsx::EDITABLE);
    $sup['precio'] = '$' . $col . '$' . $f; $f++;

    /* 🔴 EL PORCENTAJE SE SACA DEL DINERO, NO DEL CAMPO `reservaPct`. Ese campo
       cambia de significado segun el modo: con la firma pagada SOLA antes de las
       cuotas vale 0,009009 -- porque ahi lo unico que se cobra "a la reserva" son los
       $1.000 de separacion y el resto de la entrada vive en las filas de firma.
       Confiar en el daba un Excel con la entrada en $1.000 y las filas de firma en
       CERO. Se calcula de lo que de verdad se cobra antes de las cuotas. */
    $sumFirmaSola = 0.0; $sumFirmaEnCuota = 0.0; $nFirmaEnCuota = 0;
    foreach ($filas as $fila) {
        if (!empty($fila['soloFirma'])) { $sumFirmaSola += (float)$fila['monto']; continue; }
        if ((float)($fila['firma'] ?? 0) > 0) { $sumFirmaEnCuota += (float)$fila['firma']; $nFirmaEnCuota++; }
    }
    $valor = (float)($plan['valor'] ?? 0);
    $entradaMonto = (float)($plan['separacion'] ?? 0) + (float)($plan['firma'] ?? 0)
                  + $sumFirmaSola + $sumFirmaEnCuota;
    $x->texto($f, 1, '% de entrada (incluye separación)', Xlsx::ETIQUETA);
    /* 10 decimales y no 6: con 6, una entrada de 11.100,01 sobre 111.000 se
       redondeaba a 0,100000 y la ultima fila de firma salia UN CENTAVO por debajo
       de la del cotizador. Medido. */
    $x->numero($f, 4, $valor > 0 ? round($entradaMonto / $valor, 10) : 0.1, Xlsx::PORCENTAJE);
    $pctEntrada = '$' . $col . '$' . $f; $f++;

    $x->texto($f, 1, 'Separación', Xlsx::ETIQUETA);
    $x->numero($f, 4, round((float)($plan['separacion'] ?? 0), 2), Xlsx::EDITABLE);
    $sup['sep'] = '$' . $col . '$' . $f; $f++;

    /* 🔴 CUANDO LA FIRMA SE PAGA SOLA ANTES DE LAS CUOTAS NO HAY FILA "A LA FIRMA".
       El motor pone `firma = 0` en ese modo porque esa plata pasa a filas propias, y
       la pantalla por eso no la dibuja. Mi formula la inventaba igual y la firma se
       contaba DOS VECES: medido, la hoja sumaba 121.100,01 sobre un precio de
       111.000 -- exactamente los 10.100 de la entrada repetidos. Lo destapo comparar
       el cuadre calculado contra el precio, no leer el codigo. */
    $nFirmaSola = 0;
    foreach ($filas as $fila) if (!empty($fila['soloFirma'])) $nFirmaSola++;
    $entrada = 'ROUND(' . $sup['precio'] . '*' . $pctEntrada . ',2)';
    $sup['firma'] = null;
    if ($nFirmaSola === 0) {
        $x->texto($f, 1, 'A la firma', Xlsx::ETIQUETA);
        $x->formula($f, 4, "$entrada-{$sup['sep']}", Xlsx::DINERO_B);
        $sup['firma'] = '$' . $col . '$' . $f; $f++;
    }

    $x->texto($f, 1, '% contra entrega', Xlsx::ETIQUETA);
    $x->numero($f, 4, $valor > 0 ? round((float)($plan['contraentrega'] ?? 0) / $valor, 10)
                                 : round((float)($plan['contraPct'] ?? 0.6), 6), Xlsx::PORCENTAJE);
    $pctContra = '$' . $col . '$' . $f; $f++;

    $x->texto($f, 1, 'Contra entrega', Xlsx::ETIQUETA);
    $x->formula($f, 4, "ROUND({$sup['precio']}*$pctContra,2)", Xlsx::DINERO_B);
    $sup['contra'] = '$' . $col . '$' . $f; $f++;

    /* 🔴 LAS EXTRAORDINARIAS TAMBIEN ESCALAN. Medido: con las extraordinarias fijas,
       bajar el precio de 111.000 a 90.000 daba una cuota de $294,44 en el Excel y el
       cotizador decia $333,33 -- porque el motor las recalcula al 10% del precio
       nuevo (9.000) y la hoja las dejaba en 11.100. Un Excel que contradice al
       cotizador por $39 en cada cuota es peor que no tenerlo. */
    $extras = [];
    foreach ($filas as $fila) {
        if (!empty($fila['soloFirma']) || empty($fila['extra'])) continue;
        $extras[] = round((float)$fila['monto'] - (float)($plan['mensual'] ?? 0) - (float)($fila['firma'] ?? 0), 2);
    }
    $nExtra = count($extras);
    /* Solo se convierten en formula si son TODAS IGUALES, que es el caso normal. Si el
       asesor las repartio a mano con montos distintos, escalarlas las aplanaria: ahi
       se dejan como numeros y la hoja lo dice. */
    $extraParejas = $nExtra > 0 && count(array_unique($extras)) === 1;
    $pctExtra = ''; $totalExtra = '';
    if ($extraParejas) {
        $x->texto($f, 1, '% extraordinarias', Xlsx::ETIQUETA);
        $x->numero($f, 4, $valor > 0 ? round(array_sum($extras) / $valor, 10)
                                     : round((float)($plan['extraPct'] ?? 0.1), 6), Xlsx::PORCENTAJE);
        $pctExtra = '$' . $col . '$' . $f; $f++;
        $x->texto($f, 1, 'Total extraordinarias', Xlsx::ETIQUETA);
        $x->formula($f, 4, "ROUND({$sup['precio']}*$pctExtra,2)", Xlsx::DINERO_B);
        $totalExtra = '$' . $col . '$' . $f; $f++;
    } elseif ($nExtra > 0) {
        $x->texto($f, 1, 'Extraordinarias', Xlsx::ETIQUETA);
        $x->texto($f, 3, 'repartidas a mano: no escalan si cambiás el precio', Xlsx::PIE);
        $f++;
    }
    /* Las CUOTAS y la FECHA no son dinero: van sin el formato de moneda. Un "$56"
       en el numero de cuotas hace dudar de toda la hoja.

       🔴 EL NUMERO DE CUOTAS NO ES EDITABLE, Y ES A PROPOSITO. Primero lo puse
       editable y estaba mal: las filas de la tabla son fijas, asi que bajarlo a 40
       no quitaba 14 filas -- repartia sobre 40 y la ultima fila se comia la
       diferencia, quedando una cuota monstruosa sin que nada avisara. Ahora se
       CUENTA de la tabla, asi que no puede desincronizarse. Cambiar el plazo es
       trabajo del cotizador: ahi se sabe cuantas cuotas caben antes de la entrega. */
    $x->texto($f, 1, 'Número de cuotas', Xlsx::ETIQUETA);
    $nCuotas = 0;
    foreach ($filas as $fila) if (empty($fila['soloFirma'])) $nCuotas++;
    $filaN = $f;
    $sup['n'] = '$' . Xlsx::col(4) . '$' . $f; $f++;

    $x->texto($f, 1, 'Primera cuota', Xlsx::ETIQUETA);
    $prim = null;
    foreach ($filas as $fila) if (empty($fila['soloFirma'])) { $prim = $fila['fecha']; break; }
    $x->numero($f, 4, $prim ? Xlsx::fecha(DateTimeImmutable::createFromFormat('!d/m/Y', $prim)) : 0, Xlsx::FECHA);
    $sup['ini'] = '$' . Xlsx::col(4) . '$' . $f; $f++;

    // ── calculado ──────────────────────────────────────────────────────────
    /* La entrada es SIEMPRE el porcentaje del precio, se pague a la firma o repartida
       en abonos: por eso estas dos salen de `$entrada` y no de la celda "A la firma",
       que en el modo de firma sola ni existe. */
    $x->texto($f, 1, 'Total cuota inicial', Xlsx::ETIQUETA);
    $x->formula($f, 4, $entrada, Xlsx::DINERO_B);
    $f++;
    $x->texto($f, 1, 'A financiar en cuotas', Xlsx::ETIQUETA);
    /* `- $entrada` ya descuenta TODA la entrada, se pague a la firma, en abonos
       propios o montada sobre las primeras cuotas: lo que queda es cuota pura. */
    $x->formula($f, 4, "{$sup['precio']}-$entrada-{$sup['contra']}", Xlsx::DINERO_B);
    $financiado = '$' . Xlsx::col(4) . '$' . $f; $f++;

    // las extraordinarias se suman aparte: se escriben en la columna E de la tabla
    $filaCuotaMensual = $f;
    $x->texto($f, 1, 'Cuota mensual', Xlsx::ETIQUETA);
    $f++;   // la formula se escribe abajo, cuando se sabe el rango de la columna E

    $f++;
    // ── la tabla ───────────────────────────────────────────────────────────
    $x->texto($f, 1, 'Nº', Xlsx::CABECERA);
    $x->texto($f, 2, 'VENCIMIENTO', Xlsx::CABECERA);
    $x->texto($f, 3, 'CONCEPTO', Xlsx::CABECERA);
    $x->texto($f, 4, 'VALOR CUOTA', Xlsx::CABECERA);
    $x->texto($f, 5, 'EXTRAORDINARIA', Xlsx::CABECERA);
    if ($nFirmaEnCuota > 0) $x->texto($f, 6, 'FIRMA DIFERIDA', Xlsx::CABECERA);
    $x->texto($f, 7, 'CUOTA BASE', Xlsx::CABECERA);
    $x->congelarHasta($f);
    $f++;

    // separacion y firma: son hitos, no cuotas
    $primeraSuma = $f;
    if (!empty($plan['fechaReserva'])) {
        $x->numero($f, 2, Xlsx::fecha(DateTimeImmutable::createFromFormat('!d/m/Y', $plan['fechaReserva'])), Xlsx::FECHA);
        $x->texto($f, 3, 'SEPARACIÓN', Xlsx::NORMAL);
        $x->formula($f, 4, $sup['sep'], Xlsx::DINERO);
        $f++;
    }
    if ($sup['firma'] !== null && (float)($plan['firma'] ?? 0) > 0 && !empty($plan['fechaFirma'])) {
        $x->numero($f, 2, Xlsx::fecha(DateTimeImmutable::createFromFormat('!d/m/Y', $plan['fechaFirma'])), Xlsx::FECHA);
        $x->texto($f, 3, 'A LA FIRMA', Xlsx::NORMAL);
        $x->formula($f, 4, $sup['firma'], Xlsx::DINERO);
        $f++;
    }
    /* Los abonos de la firma sola tambien son FORMULA y reparten (entrada - separacion):
       asi escalan con el precio como todo lo demas. El ultimo es el residuo, para que
       los N sumen la entrada al centavo. */
    $kf = 0;
    foreach ($filas as $fila) {
        if (empty($fila['soloFirma'])) continue;
        $kf++;
        $x->numero($f, 2, Xlsx::fecha(DateTimeImmutable::createFromFormat('!d/m/Y', $fila['fecha'])), Xlsx::FECHA);
        $x->texto($f, 3, 'FIRMA', Xlsx::NORMAL);
        $x->formula($f, 4, $kf < $nFirmaSola
            ? "ROUND(($entrada-{$sup['sep']})/$nFirmaSola,2)"
            : "$entrada-{$sup['sep']}-ROUND(($entrada-{$sup['sep']})/$nFirmaSola,2)*" . ($nFirmaSola - 1),
            Xlsx::DINERO);
        $f++;
    }

    $priCuota = $f;
    $k = 0;
    foreach ($filas as $fila) {
        if (!empty($fila['soloFirma'])) continue;
        $k++;
        $x->numero($f, 1, $k, Xlsx::CENTRO);
        /* La fecha es EDATE desde la primera cuota: si el vendedor corre la primera,
           se corren TODAS solas y respetan el mismo dia del mes. */
        $x->formula($f, 2, "EDATE({$sup['ini']}," . ($k - 1) . ')', Xlsx::FECHA);
        $x->texto($f, 3, !empty($fila['extra']) ? 'Cuota + extraordinaria' : 'Cuota', Xlsx::NORMAL);
        $f++;
    }
    $ultCuota = $f - 1;
    /* Las cuatro columnas de la derecha, y por que son cuatro y no una:
         D VALOR CUOTA   lo que el cliente paga ese mes = base + extra + firma
         E EXTRAORDINARIA  entrada del vendedor
         F FIRMA DIFERIDA  la parte de la entrada montada sobre esa cuota
         G CUOTA BASE      la cuota pura, que es la que tiene que sumar el financiado
       Tenerlas separadas es lo que permite que el RESIDUO de la ultima cuota cuadre:
       si la firma va sumada dentro, el residuo la resta y sale en negativo. */
    $colD = Xlsx::col(4); $colE = Xlsx::col(5); $colF = Xlsx::col(6); $colG = Xlsx::col(7);
    $rangoE = "\${$colE}\${$priCuota}:\${$colE}\${$ultCuota}";

    // ahora si: las dos formulas que necesitaban conocer el rango de la tabla
    $colA = Xlsx::col(1);
    $x->formula($filaN, 4, "COUNT(\$$colA\$$priCuota:\$$colA\$$ultCuota)", Xlsx::CENTRO);
    $x->formula($filaCuotaMensual, 4,
        "ROUND(($financiado-SUM($rangoE))/{$sup['n']},2)", Xlsx::DINERO_B);
    $mensual = '$' . $colD . '$' . $filaCuotaMensual;

    // los montos de cada cuota, ya con la referencia a la mensual
    $k = 0; $kx = 0; $kfc = 0; $ff = $priCuota;
    foreach ($filas as $fila) {
        if (!empty($fila['soloFirma'])) continue;
        $k++;
        /* FIRMA DIFERIDA: cuando NO va sola, el motor la monta encima de las primeras
           cuotas. La pantalla muestra 3.777,78 donde la cuota vale 411,11 -- el resto
           es firma.
           🔴 VA EN SU PROPIA COLUMNA (F) Y NO SUMADA DENTRO DE LA CUOTA. Primero la
           sume dentro y la ultima cuota -- que es el residuo -- se comia la firma de
           las anteriores y salia en -9.688,83. Separada, la columna de cuota pura
           sigue sumando el financiado y el residuo cuadra solo.
           Y el reparto respeta los PESOS originales: con un plan a la medida
           (3.000 y 2.000) dividir en partes iguales daba 3.005,56 donde el cotizador
           dice 3.505,56. */
        $conFirma = empty($fila['soloFirma']) && (float)($fila['firma'] ?? 0) > 0;
        if ($conFirma && $nFirmaEnCuota > 0) {
            $kfc++;
            /* 🔴 CADA PARTE SE REDONDEA SOLA, SIN RESIDUO -- igual que el motor. Puse
               la ultima como residuo y quedaba en 3.366,66 donde el PDF dice 3.366,67:
               el motor redondea las tres a 3.366,67 (suman 10.100,01) y compensa ese
               centavo en la cuota de AJUSTE, no en la firma. Un centavo de diferencia
               entre el Excel y el PDF del mismo cliente es un documento que no cuadra,
               aunque el total coincida. El centavo se descuenta abajo, en la ultima
               cuota, que es donde el cotizador lo pone. */
            $peso = $sumFirmaEnCuota > 0 ? round((float)$fila['firma'] / $sumFirmaEnCuota, 10) : 0;
            $x->formula($ff, 6, "ROUND(($entrada-{$sup['sep']})*$peso,2)", Xlsx::NORMAL);
        }
        if ($ff === $ultCuota && $ultCuota > $priCuota) {
            /* LA ULTIMA ES EL RESIDUO, no otra vez el valor redondeado. Es lo que hace
               el motor para que la columna sume el precio exacto: sin esto quedan
               centavos sueltos y el CUADRE de abajo diria NO CUADRA por 24 centavos. */
            /* El `-(SUM(F)-(entrada-sep))` es el centavo del reparto de la firma: si las
               partes redondeadas suman un centavo de mas, sale de aca. Es exactamente
               lo que hace el motor con su cuota de ajuste. */
            $ajusteFirma = $nFirmaEnCuota > 0
                ? "-(SUM(\$$colF\$$priCuota:\$$colF\$$ultCuota)-($entrada-{$sup['sep']}))" : '';
            $x->formula($ff, 7, "$financiado-SUM($rangoE)-SUM(\$$colG\$$priCuota:\$$colG\$" . ($ultCuota - 1) . ')' . $ajusteFirma, Xlsx::NORMAL);
        } else {
            $x->formula($ff, 7, $mensual, Xlsx::NORMAL);
        }
        // lo que el cliente paga ese mes: base + extraordinaria + firma diferida
        $x->formula($ff, 4, "\$$colG\$$ff+\$$colE\$$ff" . ($nFirmaEnCuota > 0 ? "+\$$colF\$$ff" : ''), Xlsx::DINERO);
        // la extraordinaria de esa fila
        if (!empty($fila['extra'])) {
            if ($extraParejas) {
                $kx++;
                /* La ULTIMA extraordinaria es el residuo, por lo mismo que la ultima
                   cuota: asi las N suman exactamente el total y no quedan centavos. */
                $x->formula($ff, 5, $kx < $nExtra
                    ? "ROUND($totalExtra/$nExtra,2)"
                    : "$totalExtra-ROUND($totalExtra/$nExtra,2)*" . ($nExtra - 1), Xlsx::DINERO);
            } else {
                $e = round((float)$fila['monto'] - (float)($plan['mensual'] ?? 0) - (float)($fila['firma'] ?? 0), 2);
                $x->numero($ff, 5, max(0.0, $e), Xlsx::EDITABLE);
            }
        }
        $ff++;
    }

    $x->numero($f, 2, 0, Xlsx::NORMAL);   // celda vacia para cerrar el borde
    $x->texto($f, 3, 'CONTRA ENTREGA', Xlsx::ETIQUETA);
    $x->formula($f, 4, $sup['contra'], Xlsx::DINERO_B);
    $ultima = $f; $f++;

    // ── el cuadre: la guarda que hace util a esta hoja ─────────────────────
    $x->texto($f, 3, 'SUMA DE TODO', Xlsx::ETIQUETA);
    /* SIN sumar E ni F aparte: ya estan DENTRO de la columna D de cada cuota. Sumarlos
       otra vez contaria las extraordinarias dos veces. */
    $x->formula($f, 4, "SUM(\$$colD\$$primeraSuma:\$$colD\$$ultima)", Xlsx::DINERO_B);
    $suma = '$' . $colD . '$' . $f; $f++;
    $x->texto($f, 3, 'PRECIO DE VENTA', Xlsx::ETIQUETA);
    $x->formula($f, 4, $sup['precio'], Xlsx::DINERO_B); $f++;
    $x->texto($f, 3, '¿CUADRA?', Xlsx::ETIQUETA);
    /* El 0,005 no es capricho: con centavos, comparar por igualdad exacta marca
       NO CUADRA por un error de coma flotante que nadie puede ver ni corregir. */
    $x->formula($f, 4, "IF(ABS($suma-{$sup['precio']})<0.005,\"CUADRA\",\"NO CUADRA — revisar\")", Xlsx::AVISO_OK);
    $f++;

    /* 🔴 EL CUADRE SOLO NO ALCANZA, Y CASI LO DEJO ASI. Por como esta armada la hoja
       la ultima cuota es el RESIDUO, asi que la suma da el precio SIEMPRE: el
       "¿CUADRA?" nunca podria decir que no. Serviria de adorno.
       Solo se rompe si alguien PISA una formula escribiendo un numero encima -- que
       es justo lo que va a hacer un vendedor -- y para eso si sirve.
       Estos otros tres avisos cubren lo que el cuadre no puede ver: */
    /* Mira la CUOTA BASE y no el total: la ultima fila podria llevar una
       extraordinaria legitima y el aviso saltaria sin motivo. */
    $ultCel = '$' . $colG . '$' . $ultCuota;
    $x->texto($f, 3, 'AVISOS', Xlsx::ETIQUETA);
    $x->formula($f, 4,
        // a financiar negativo: se pidio mas de entrada y contraentrega que el precio
        "IF($financiado<0,\"La entrada y la contraentrega superan el precio\","
        // cuota negativa o cero: las extraordinarias se comieron el financiamiento
      . "IF({$mensual}<=0,\"Las extraordinarias no dejan cuota mensual\","
        // la ultima cuota deberia diferir en CENTAVOS, no en dolares
      . "IF(ABS($ultCel-{$mensual})>1,\"La última cuota quedó en \"&TEXT($ultCel,\"$#,##0.00\")&\", muy distinta de las demás\","
      . "\"sin avisos\")))", Xlsx::AVISO_OK);
    $f++;
    $x->texto($f, 1, 'Si dice NO CUADRA o aparece un aviso, la tabla dejó de ser válida: '
        . 'no se la mandes al cliente hasta rehacerla en el cotizador.', Xlsx::PIE);
    return $f + 1;
}
