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
    /* Los anchos copian la proporcion del documento: Nº angosto, vencimiento medio,
       concepto ancho, valor. De la F en adelante viven los SUPUESTOS -- fuera de la
       cotizacion, para que lo que se imprime sea el documento y nada mas. */
    $x->ancho(1, 6); $x->ancho(2, 15); $x->ancho(3, 34); $x->ancho(4, 17);
    $x->ancho(5, 3);
    $x->ancho(6, 32); $x->ancho(7, 16);      // SUPUESTOS: rotulo y valor
    $x->ancho(8, 3);
    $x->ancho(9, 15); $x->ancho(10, 15); $x->ancho(11, 13);  // columnas de trabajo de la tabla

    $f = 1;
    $x->alto($f, 26);
    $x->texto($f, 1, 'GALJOSA · COTIZACIÓN', Xlsx::LOGO);
    $x->unir($f, 1, $f, 4); $f += 2;

    // ── datos del cliente, como en el documento ──────────────────────────────
    foreach ([['Cliente', (string)($meta['cliente'] ?? '')],
              ['Proyecto', (string)($meta['proyecto'] ?? '')],
              ['Unidad',   (string)($meta['unidades'] ?? '')]] as [$r, $v]) {
        if ($v === '') continue;
        $x->texto($f, 1, $r, Xlsx::DATO_R);
        $x->texto($f, 2, $v, Xlsx::DATO_V);
        $x->unir($f, 2, $f, 4);
        $f++;
    }
    $x->texto($f, 1, 'Generado el ' . (string)($meta['fecha'] ?? date('d/m/Y')), Xlsx::NOTA);
    $x->unir($f, 1, $f, 4);
    $f += 2;

    $GLOBALS['COT_EXCEL_MAPA'] = [];
    foreach ($bloques as $i => $b) {
        $f = cot_excel_bloque($x, $f, $b, count($bloques) > 1 ? ($i + 1) : 0);
        $f += 2;
    }
    cot_excel_hoja_limpia($x, $bloques, $meta);
    return $x->salida();
}

/**
 * Escribe UN bloque: el DOCUMENTO en las columnas A-D y los SUPUESTOS en F-I.
 *
 * 🔴 ESA SEPARACION ES EL PUNTO. La primera version puso los supuestos arriba de todo
 * y el usuario lo dijo sin rodeos: "ahi lo hiciste a tu forma y no se entiende nada".
 * Tenia razon -- una cotizacion que empieza con un bloque de parametros no se parece
 * a la cotizacion. Ahora A-D es el documento tal cual, con sus mismos colores, y lo
 * que se puede mover vive a la derecha, separado por una columna angosta.
 */
function cot_excel_bloque(Xlsx $x, int $f, array $b, int $n): int
{
    $plan  = $b['plan'];
    $filas = array_values((array)($plan['filas'] ?? []));
    $cods  = implode(', ', (array)($b['cods'] ?? []));
    $col   = Xlsx::col(7);          // los supuestos editables viven en la G

    if ($n > 0) { $x->texto($f, 1, "Unidad $n · $cods", Xlsx::SUBTITULO); $x->unir($f, 1, $f, 4); $f++; }

    /* ── los SUPUESTOS, a la derecha ──────────────────────────────────────── */
    $fs = $f;
    $x->texto($fs, 6, 'SUPUESTOS — lo amarillo se puede cambiar', Xlsx::SUBTITULO);
    $x->unir($fs, 6, $fs, 7); $fs++;

    $sup = [];
    $x->texto($fs, 6, 'Precio de venta', Xlsx::ETIQUETA);
    $x->numero($fs, 7, round((float)($plan['valor'] ?? 0), 2), Xlsx::EDITABLE);
    $sup['precio'] = '$' . $col . '$' . $fs; $fs++;

    $sumFirmaSola = 0.0; $sumFirmaEnCuota = 0.0; $nFirmaEnCuota = 0; $nFirmaSola = 0;
    foreach ($filas as $fila) {
        if (!empty($fila['soloFirma'])) { $sumFirmaSola += (float)$fila['monto']; $nFirmaSola++; continue; }
        if ((float)($fila['firma'] ?? 0) > 0) { $sumFirmaEnCuota += (float)$fila['firma']; $nFirmaEnCuota++; }
    }
    $valor = (float)($plan['valor'] ?? 0);
    /* 🔴 El porcentaje se saca del DINERO, no de `reservaPct`: ese campo vale 0,009009
       cuando la firma se paga sola, porque ahi lo unico que se cobra "a la reserva" son
       los $1.000. Confiar en el dejaba las filas de firma en cero. */
    $entradaMonto = (float)($plan['separacion'] ?? 0) + (float)($plan['firma'] ?? 0)
                  + $sumFirmaSola + $sumFirmaEnCuota;
    $x->texto($fs, 6, '% de entrada (incluye separación)', Xlsx::ETIQUETA);
    $x->numero($fs, 7, $valor > 0 ? round($entradaMonto / $valor, 10) : 0.1, Xlsx::PORCENTAJE);
    $pctEntrada = '$' . $col . '$' . $fs; $fs++;

    $x->texto($fs, 6, 'Separación', Xlsx::ETIQUETA);
    $x->numero($fs, 7, round((float)($plan['separacion'] ?? 0), 2), Xlsx::EDITABLE);
    $sup['sep'] = '$' . $col . '$' . $fs; $fs++;

    $x->texto($fs, 6, '% contra entrega', Xlsx::ETIQUETA);
    $x->numero($fs, 7, $valor > 0 ? round((float)($plan['contraentrega'] ?? 0) / $valor, 10)
                                  : round((float)($plan['contraPct'] ?? 0.6), 10), Xlsx::PORCENTAJE);
    $pctContra = '$' . $col . '$' . $fs; $fs++;

    $entrada = 'ROUND(' . $sup['precio'] . '*' . $pctEntrada . ',2)';
    $x->texto($fs, 6, 'Contra entrega', Xlsx::ETIQUETA);
    $x->formula($fs, 7, "ROUND({$sup['precio']}*$pctContra,2)", Xlsx::DINERO_B);
    $sup['contra'] = '$' . $col . '$' . $fs; $fs++;

    $extras = [];
    foreach ($filas as $fila) {
        if (!empty($fila['soloFirma']) || empty($fila['extra'])) continue;
        $extras[] = round((float)$fila['monto'] - (float)($plan['mensual'] ?? 0) - (float)($fila['firma'] ?? 0), 2);
    }
    $nExtra = count($extras);
    /* Solo se vuelven formula si son TODAS IGUALES. Repartidas a mano con montos
       distintos, escalarlas las aplanaria: ahi quedan como numeros. */
    $extraParejas = $nExtra > 0 && count(array_unique($extras)) === 1;
    $totalExtra = '';
    if ($extraParejas) {
        $x->texto($fs, 6, '% extraordinarias', Xlsx::ETIQUETA);
        $x->numero($fs, 7, $valor > 0 ? round(array_sum($extras) / $valor, 10)
                                      : round((float)($plan['extraPct'] ?? 0.1), 10), Xlsx::PORCENTAJE);
        $pctExtra = '$' . $col . '$' . $fs; $fs++;
        $x->texto($fs, 6, 'Total extraordinarias', Xlsx::ETIQUETA);
        $x->formula($fs, 7, "ROUND({$sup['precio']}*$pctExtra,2)", Xlsx::DINERO_B);
        $totalExtra = '$' . $col . '$' . $fs; $fs++;
    }

    $filaN = $fs;
    $x->texto($fs, 6, 'Número de cuotas', Xlsx::ETIQUETA);
    $sup['n'] = '$' . $col . '$' . $fs; $fs++;

    $x->texto($fs, 6, 'Primera cuota', Xlsx::ETIQUETA);
    $prim = null;
    foreach ($filas as $fila) if (empty($fila['soloFirma'])) { $prim = $fila['fecha']; break; }
    $x->numero($fs, 7, $prim ? Xlsx::fecha(DateTimeImmutable::createFromFormat('!d/m/Y', $prim)) : 0, Xlsx::FECHA);
    $sup['ini'] = '$' . $col . '$' . $fs; $fs++;

    $x->texto($fs, 6, 'A financiar en cuotas', Xlsx::ETIQUETA);
    $x->formula($fs, 7, "{$sup['precio']}-$entrada-{$sup['contra']}", Xlsx::DINERO_B);
    $financiado = '$' . $col . '$' . $fs; $fs++;

    $filaCuotaMensual = $fs;
    $x->texto($fs, 6, 'Cuota mensual', Xlsx::ETIQUETA);
    $mensual = '$' . $col . '$' . $fs; $fs++;

    /* ── el DOCUMENTO, columnas A-D ───────────────────────────────────────── */
    $x->alto($f, 22);
    $x->texto($f, 1, 'Precio final', Xlsx::BANDA_AMA_T);
    $x->texto($f, 2, '', Xlsx::BANDA_AMA_T); $x->texto($f, 3, '', Xlsx::BANDA_AMA_T);
    $x->unir($f, 1, $f, 3);
    $x->formula($f, 4, $sup['precio'], Xlsx::BANDA_AMA_N); $f++;

    if ((float)($plan['legal'] ?? 0) > 0) {
        $x->alto($f, 20);
        $x->texto($f, 1, 'Valores legales promesa C/V', Xlsx::BANDA_VER_T);
        $x->texto($f, 2, '', Xlsx::BANDA_VER_T); $x->texto($f, 3, '', Xlsx::BANDA_VER_T);
        $x->unir($f, 1, $f, 3);
        $x->numero($f, 4, round((float)$plan['legal'], 2), Xlsx::BANDA_VER_N); $f++;
        $x->texto($f, 1, 'Pago directo para el notario. Se da al momento de la firma del contrato.', Xlsx::NOTA);
        $x->unir($f, 1, $f, 4); $f++;
    }
    $f++;

    // los cuadros de arriba del documento
    $x->texto($f, 1, 'RESERVA', Xlsx::FICHA_R); $x->texto($f, 2, '', Xlsx::FICHA_R); $x->unir($f, 1, $f, 3);
    $x->formula($f, 4, $sup['sep'], Xlsx::FICHA_V); $f++;
    $hayFirmaHito = $nFirmaSola === 0 && (float)($plan['firma'] ?? 0) > 0;
    $x->texto($f, 1, $nFirmaSola > 0 ? 'FIRMA (en ' . $nFirmaSola . ' pagos)' : 'A LA FIRMA', Xlsx::FICHA_R);
    $x->texto($f, 2, '', Xlsx::FICHA_R); $x->unir($f, 1, $f, 3);
    $x->formula($f, 4, "$entrada-{$sup['sep']}", Xlsx::FICHA_V); $f++;
    $x->texto($f, 1, 'CRÉDITO DIRECTO', Xlsx::FICHA_DR); $x->texto($f, 2, '', Xlsx::FICHA_DR); $x->unir($f, 1, $f, 3);
    $x->formula($f, 4, "$entrada+$financiado", Xlsx::FICHA_DV); $f++;
    $x->texto($f, 1, 'CONTRA ENTREGA', Xlsx::FICHA_R); $x->texto($f, 2, '', Xlsx::FICHA_R); $x->unir($f, 1, $f, 3);
    $x->formula($f, 4, $sup['contra'], Xlsx::FICHA_V); $f++;
    $x->texto($f, 1, 'CUOTA MENSUAL', Xlsx::FICHA_R); $x->texto($f, 2, '', Xlsx::FICHA_R); $x->unir($f, 1, $f, 3);
    $x->formula($f, 4, $mensual, Xlsx::FICHA_V); $f += 2;

    // la tabla, con la cabecera azul marino del documento
    $x->alto($f, 20);
    $x->texto($f, 1, 'N°', Xlsx::CABECERA);
    $x->texto($f, 2, 'VENCIMIENTO', Xlsx::CABECERA);
    $x->texto($f, 3, 'CONCEPTO', Xlsx::CABECERA);
    $x->texto($f, 4, 'VALOR CUOTA', Xlsx::CABECERA);
    /* 🔴 EN LA I, NO EN LA F. Primero las puse en F-H y se pisaron con los SUPUESTOS,
       que ocupan F-G: la cabecera de la tabla cayo justo encima de "A financiar en
       cuotas" y ese rotulo desaparecio. Lo vi al abrir la hoja, no al escribirla. */
    $x->texto($f, 9, 'EXTRAORDINARIA', Xlsx::CABECERA);
    if ($nFirmaEnCuota > 0) $x->texto($f, 10, 'FIRMA DIFERIDA', Xlsx::CABECERA);
    $x->texto($f, 11, 'CUOTA BASE', Xlsx::CABECERA);
    $x->congelarHasta($f);
    $f++;

    $primeraSuma = $f;
    if (!empty($plan['fechaReserva'])) {
        $x->numero($f, 2, Xlsx::fecha(DateTimeImmutable::createFromFormat('!d/m/Y', $plan['fechaReserva'])), Xlsx::FECHA);
        $x->texto($f, 1, '', Xlsx::HITO_T);
        $x->texto($f, 3, 'SEPARACIÓN', Xlsx::HITO_T);
        $x->formula($f, 4, $sup['sep'], Xlsx::HITO_N);
        $f++;
    }
    if ($hayFirmaHito && !empty($plan['fechaFirma'])) {
        $x->numero($f, 2, Xlsx::fecha(DateTimeImmutable::createFromFormat('!d/m/Y', $plan['fechaFirma'])), Xlsx::FECHA);
        $x->texto($f, 1, '', Xlsx::HITO_T);
        $x->texto($f, 3, 'A LA FIRMA', Xlsx::HITO_T);
        $x->formula($f, 4, "$entrada-{$sup['sep']}", Xlsx::HITO_N);
        $f++;
    }
    $kf = 0;
    foreach ($filas as $fila) {
        if (empty($fila['soloFirma'])) continue;
        $kf++;
        $x->texto($f, 1, '', Xlsx::HITO_T);
        $x->numero($f, 2, Xlsx::fecha(DateTimeImmutable::createFromFormat('!d/m/Y', $fila['fecha'])), Xlsx::FECHA);
        $x->texto($f, 3, 'FIRMA', Xlsx::HITO_T);
        $x->formula($f, 4, $kf < $nFirmaSola
            ? "ROUND(($entrada-{$sup['sep']})/$nFirmaSola,2)"
            : "$entrada-{$sup['sep']}-ROUND(($entrada-{$sup['sep']})/$nFirmaSola,2)*" . ($nFirmaSola - 1),
            Xlsx::HITO_N);
        $f++;
    }

    $priCuota = $f; $k = 0; $kx = 0; $kfc = 0;
    $colD = Xlsx::col(4); $colE = Xlsx::col(9); $colF = Xlsx::col(10); $colG = Xlsx::col(11); $colA = Xlsx::col(1);
    foreach ($filas as $fila) {
        if (!empty($fila['soloFirma'])) continue;
        $k++;
        $esX = !empty($fila['extra']);
        $x->numero($f, 1, $k, $esX ? Xlsx::EXTRA_T : Xlsx::CUOTA_T);
        $x->formula($f, 2, "EDATE({$sup['ini']}," . ($k - 1) . ')', Xlsx::FECHA);
        $x->texto($f, 3, $esX ? 'EXTRAORDINARIA' : '', $esX ? Xlsx::EXTRA_T : Xlsx::CUOTA_T);
        $f++;
    }
    $ultCuota = $f - 1;
    $rangoE = "\${$colE}\${$priCuota}:\${$colE}\${$ultCuota}";

    $x->formula($filaN, 7, "COUNT(\$$colA\$$priCuota:\$$colA\$$ultCuota)", Xlsx::CENTRO);
    $x->formula($filaCuotaMensual, 7, "ROUND(($financiado-SUM($rangoE))/{$sup['n']},2)", Xlsx::DINERO_B);

    $ff = $priCuota;
    foreach ($filas as $fila) {
        if (!empty($fila['soloFirma'])) continue;
        $esX = !empty($fila['extra']);
        /* La FIRMA DIFERIDA va en su columna y NO sumada dentro de la cuota: si se suma,
           el residuo de la ultima se come la de las anteriores y sale en negativo. */
        if (empty($fila['soloFirma']) && (float)($fila['firma'] ?? 0) > 0 && $nFirmaEnCuota > 0) {
            $peso = $sumFirmaEnCuota > 0 ? round((float)$fila['firma'] / $sumFirmaEnCuota, 10) : 0;
            $x->formula($ff, 10, "ROUND(($entrada-{$sup['sep']})*$peso,2)", Xlsx::NORMAL);
        }
        if ($esX) {
            if ($extraParejas) {
                $kx++;
                $x->formula($ff, 9, $kx < $nExtra
                    ? "ROUND($totalExtra/$nExtra,2)"
                    : "$totalExtra-ROUND($totalExtra/$nExtra,2)*" . ($nExtra - 1), Xlsx::NORMAL);
            } else {
                $e = round((float)$fila['monto'] - (float)($plan['mensual'] ?? 0) - (float)($fila['firma'] ?? 0), 2);
                $x->numero($ff, 9, max(0.0, $e), Xlsx::EDITABLE);
            }
        }
        if ($ff === $ultCuota && $ultCuota > $priCuota) {
            $ajusteFirma = $nFirmaEnCuota > 0
                ? "-(SUM(\$$colF\$$priCuota:\$$colF\$$ultCuota)-($entrada-{$sup['sep']}))" : '';
            $x->formula($ff, 11, "$financiado-SUM($rangoE)-SUM(\$$colG\$$priCuota:\$$colG\$" . ($ultCuota - 1) . ')' . $ajusteFirma, Xlsx::NORMAL);
        } else {
            $x->formula($ff, 11, $mensual, Xlsx::NORMAL);
        }
        $x->formula($ff, 4, "\$$colG\$$ff+\$$colE\$$ff" . ($nFirmaEnCuota > 0 ? "+\$$colF\$$ff" : ''),
                    $esX ? Xlsx::EXTRA_N : Xlsx::CUOTA_N);
        $ff++;
    }

    // los cierres del documento
    $x->texto($f, 1, '', Xlsx::TOTINI_T); $x->texto($f, 2, '', Xlsx::TOTINI_T);
    $x->texto($f, 3, 'TOTAL CUOTA INICIAL', Xlsx::TOTINI_T);
    $x->formula($f, 4, "$entrada+$financiado", Xlsx::TOTINI_N); $f++;
    $x->texto($f, 1, '', Xlsx::HITO_T); $x->texto($f, 2, '', Xlsx::HITO_T);
    $x->texto($f, 3, 'CONTRA ENTREGA', Xlsx::HITO_T);
    $x->formula($f, 4, $sup['contra'], Xlsx::HITO_N);
    $filaContra = $f; $f++;
    $x->alto($f, 22);
    $x->texto($f, 1, '', Xlsx::GRAN_T); $x->texto($f, 2, '', Xlsx::GRAN_T);
    $x->texto($f, 3, 'TOTAL', Xlsx::GRAN_T);
    /* 🔴 LA SUMA SALTA "TOTAL CUOTA INICIAL". Esa fila es un SUBTOTAL del documento
       -- separacion + firma + cuotas -- no un pago mas. Sumarla daba 155.400 sobre un
       precio de 111.000: 44.400 de mas, justo el subtotal contado dos veces. Por eso
       el rango llega hasta la ULTIMA CUOTA y despues se agrega la contraentrega
       sola, saltandose el subtotal que queda en el medio. */
    $x->formula($f, 4, "SUM(\$$colD\$$primeraSuma:\$$colD\$$ultCuota)+\$$colD\$$filaContra", Xlsx::GRAN_N);
    $suma = '$' . $colD . '$' . $f; $f++;

    /* ── las guardas, a la derecha con los supuestos ──────────────────────── */
    $x->texto($fs, 6, '¿CUADRA?', Xlsx::ETIQUETA);
    $x->formula($fs, 7, "IF(ABS($suma-{$sup['precio']})<0.005,\"CUADRA\",\"NO CUADRA — revisar\")", Xlsx::AVISO_OK);
    $fs++;
    /* 🔴 El cuadre SOLO no alcanza: la ultima cuota es el residuo, asi que la suma da el
       precio siempre y ese aviso nunca podria decir que no. Sirve para lo unico que si
       lo rompe -- que alguien pise una formula con un numero, que es lo que hace un
       vendedor. Estos tres cubren lo que el cuadre no ve. */
    $ultCel = '$' . $colG . '$' . $ultCuota;
    $x->texto($fs, 6, 'AVISOS', Xlsx::ETIQUETA);
    $x->formula($fs, 7,
        "IF($financiado<0,\"La entrada y la contraentrega superan el precio\","
      . "IF({$mensual}<=0,\"Las extraordinarias no dejan cuota mensual\","
      . "IF(ABS($ultCel-{$mensual})>1,\"La última cuota quedó en \"&TEXT($ultCel,\"$#,##0.00\")&\", muy distinta\","
      . "\"sin avisos\")))", Xlsx::AVISO_OK);
    $fs++;
    $x->texto($fs, 6, 'Lo OFICIAL es lo que emite el cotizador. Esta hoja sirve para simular: '
        . 'avisa si la tabla deja de cuadrar, pero no sabe si el trato es vendible.', Xlsx::NOTA);
    $x->unir($fs, 6, $fs, 7);

    /* Lo que la hoja LIMPIA necesita saber de este bloque: donde vive cada fila de la
       tabla, para apuntarle con una formula en vez de copiar numeros. */
    $mapa = ['cods' => $cods, 'filas' => []];
    for ($r = $primeraSuma; $r <= $filaContra; $r++) $mapa['filas'][] = $r;
    $GLOBALS['COT_EXCEL_MAPA'][] = $mapa;

    return max($f, $fs) + 2;
}

/**
 * LA HOJA PARA SUBIR: solo la tabla de pagos, sin un solo mando.
 *
 * Pedido del usuario (23-sep-2026): "al momento de descargarlo y subirlo, que no
 * aparezca eso, sino solamente la tabla de pagos, para que el PHP que lo lea la pueda
 * leer correctamente".
 *
 * Tres columnas y nada mas: N°, VENCIMIENTO, VALOR CUOTA. Sin supuestos, sin columnas
 * de trabajo, sin avisos. Quien la lea encuentra una tabla y ya.
 *
 * 🔴 CADA CELDA ES UNA FORMULA QUE APUNTA A LA HOJA 1, PERO LLEVA SU VALOR ESCRITO.
 * Las dos cosas, y por motivos distintos:
 *   - la formula, para que si el vendedor cambia el precio esta hoja cambie con el y
 *     no le suba al sistema una tabla vieja;
 *   - el valor, porque un programa que lee un .xlsx lee el valor cacheado, NO la
 *     formula. Sin el, el importador veria la hoja vacia y no daria ningun error.
 */
function cot_excel_hoja_limpia(Xlsx $x, array $bloques, array $meta): void
{
    $x->nuevaHoja('TABLA PARA SUBIR');
    $x->ancho(1, 8); $x->ancho(2, 16); $x->ancho(3, 18); $x->ancho(4, 30);

    $f = 1;
    $linea = array_filter([(string)($meta['cliente'] ?? ''), (string)($meta['unidades'] ?? ''),
                           (string)($meta['proyecto'] ?? '')]);
    $x->texto($f, 1, 'PLAN DE PAGOS', Xlsx::TITULO); $x->unir($f, 1, $f, 4); $f++;
    $x->texto($f, 1, implode('  ·  ', $linea), Xlsx::NOTA); $x->unir($f, 1, $f, 4); $f++;
    $x->texto($f, 1, 'Esta hoja es la que se sube al sistema. Se actualiza sola con lo que '
        . 'se cambie en la hoja anterior — no se edita aquí.', Xlsx::NOTA);
    $x->unir($f, 1, $f, 4); $f += 2;

    $x->alto($f, 20);
    $x->texto($f, 1, 'N°', Xlsx::CABECERA);
    $x->texto($f, 2, 'VENCIMIENTO', Xlsx::CABECERA);
    $x->texto($f, 3, 'VALOR CUOTA', Xlsx::CABECERA);
    $x->texto($f, 4, 'CONCEPTO', Xlsx::CABECERA);
    $x->congelarHasta($f); $f++;

    $hoja1 = "'Plan de pagos'!";
    foreach (($GLOBALS['COT_EXCEL_MAPA'] ?? []) as $i => $m) {
        if (count($bloques) > 1) {
            $x->texto($f, 1, 'Unidad ' . ($i + 1) . ' · ' . $m['cods'], Xlsx::SUBTITULO);
            $x->unir($f, 1, $f, 4); $f++;
        }
        $b = $bloques[$i] ?? null;
        $vals = $b ? cot_excel_valores($b['plan']) : [];
        foreach ($m['filas'] as $k => $r) {
            $v = $vals[$k] ?? null;
            $x->formula($f, 1, "IF({$hoja1}A$r=\"\",\"\",{$hoja1}A$r)", Xlsx::CENTRO, $v['n'] ?? '');
            $x->formula($f, 2, "{$hoja1}B$r", Xlsx::FECHA,   $v['fecha'] ?? null);
            $x->formula($f, 3, "{$hoja1}D$r", Xlsx::CUOTA_N, $v['monto'] ?? null);
            $x->formula($f, 4, "{$hoja1}C$r", Xlsx::CUOTA_T, $v['concepto'] ?? '');
            $f++;
        }
        $f++;
    }
}

/** Los MISMOS valores que la hoja 1 muestra hoy, en el orden en que los escribio.
 *  Sirven de valor cacheado para quien lee el archivo sin abrir Excel. */
function cot_excel_valores(array $plan): array
{
    $out = [];
    $filas = array_values((array)($plan['filas'] ?? []));
    if (!empty($plan['fechaReserva']))
        $out[] = ['n' => '', 'fecha' => Xlsx::fecha(DateTimeImmutable::createFromFormat('!d/m/Y', $plan['fechaReserva'])),
                  'monto' => round((float)$plan['separacion'], 2), 'concepto' => 'SEPARACIÓN'];
    $soloFirma = 0;
    foreach ($filas as $fila) if (!empty($fila['soloFirma'])) $soloFirma++;
    if ($soloFirma === 0 && (float)($plan['firma'] ?? 0) > 0 && !empty($plan['fechaFirma']))
        $out[] = ['n' => '', 'fecha' => Xlsx::fecha(DateTimeImmutable::createFromFormat('!d/m/Y', $plan['fechaFirma'])),
                  'monto' => round((float)$plan['firma'], 2), 'concepto' => 'A LA FIRMA'];
    foreach ($filas as $fila) {
        if (empty($fila['soloFirma'])) continue;
        $out[] = ['n' => '', 'fecha' => Xlsx::fecha(DateTimeImmutable::createFromFormat('!d/m/Y', $fila['fecha'])),
                  'monto' => round((float)$fila['monto'], 2), 'concepto' => 'FIRMA'];
    }
    $k = 0;
    foreach ($filas as $fila) {
        if (!empty($fila['soloFirma'])) continue;
        $k++;
        $out[] = ['n' => $k, 'fecha' => Xlsx::fecha(DateTimeImmutable::createFromFormat('!d/m/Y', $fila['fecha'])),
                  'monto' => round((float)$fila['monto'], 2),
                  'concepto' => !empty($fila['extra']) ? 'EXTRAORDINARIA' : ''];
    }
    $out[] = ['n' => '', 'fecha' => null, 'monto' => round((float)($plan['totalInicial'] ?? 0), 2),
              'concepto' => 'TOTAL CUOTA INICIAL'];
    $out[] = ['n' => '', 'fecha' => null, 'monto' => round((float)($plan['contraentrega'] ?? 0), 2),
              'concepto' => 'CONTRA ENTREGA'];
    return $out;
}
