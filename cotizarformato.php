<?php
/**
 * cotizarformato.php — el Excel del cotizador en el FORMATO DE GALJOSA.
 * ---------------------------------------------------------------------------
 * Pedido de Jesua (26-oct-2026), con el archivo que usan los vendedores de toda la
 * vida (`Cotizador - Noral Plaza (Departamentos).xlsx`, guardado en
 * memory/documentos/cotizador-excel-noral-plaza-26oct/): "que sea igual... ellos van
 * editando la cuota, y hasta los clientes mismos se lo pasan y arreglan como desearian
 * pagar". El Excel de septiembre (cotizarexcel.php) tenia formato propio y el vendedor
 * se lo mando asi a Antonio Galarza (B-8 y F-1-16).
 *
 * DOS HOJAS, como el de ellos:
 *   CUOTAS NORMALES  con SUS formulas: firma = precio x 10% - separacion; cuota =
 *                    precio x 20% / total de cuotas; extraordinaria = precio x 10% /
 *                    cuantas, sumada a la cuota de su mes. Se cambia el precio (B14) o
 *                    el total de cuotas (B20) y se recalcula solo.
 *   MENSUALIZADO     la cotizacion como la armo el asesor: los montos son NUMEROS que
 *                    se pueden cambiar; la contraentrega es "precio - lo pagado" y la
 *                    celda de la diferencia (columna E, oculta como en el de ellos)
 *                    dice cuanto falta para el 40%.
 *
 * 🔴 VA PRIMERO EL MENSUALIZADO, SIEMPRE. Cobranzas lee la PRIMERA hoja visible que
 * parezca tabla (xlsx_to_text: gana la de mas filas de cuota, y en empate la primera).
 * El mensualizado es la cotizacion exacta al centavo; la normal son formulas sin redondear
 * (356,6037...) y leida a 2 decimales pierde centavos: medido 26-oct, F-1-16 leyo 37.799,80
 * de 37.800 (el archivo original de ellos pierde igual: 49.719,98 de 49.720). En un plan
 * normal las dos hojas dicen lo mismo; la normal queda al lado como referencia.
 *
 * 🔴 FIRMA EN ABONOS: las filas "ABONO FIRMA N" (sin numero de cuota) cobranzas todavia
 * no las lee del Excel — solo del PDF ("28/10/2026 FIRMA $x"). Con abonos, subir el PDF.
 *
 * 🔴 CADA FORMULA LLEVA SU VALOR. Cobranzas lee el valor guardado, no la formula
 * (ver cotizarexcel.php); con `fullCalcOnLoad` Excel lo recalcula al abrir.
 *
 * DEL ARCHIVO DE ELLOS SE COPIA (plantillas/cotizador-xlsx/): los estilos (letra,
 * bordes, formato de dinero, la celda amarilla del precio), el tema, los logos y la
 * hoja NOTARIA. NO se copia: las listas de precios viejas ("a 60 meses"), los 3
 * vinculos a archivos de otra computadora ni los ~200 nombres rotos (#REF!) — con eso
 * Excel pregunta por "vinculos externos" al abrir.
 * ---------------------------------------------------------------------------
 */
declare(strict_types=1);
require_once __DIR__ . '/xlsxlib.php';

const COT_FMT_DIR = __DIR__ . '/plantillas/cotizador-xlsx';

/* Estilos: los indices de styles.xml del archivo de ellos, por papel. */
const COT_FMT_S = [
    'rotulo' => 24, 'valor' => 26, 'valorAlto' => 105, 'm2' => 28, 'vacio' => 25, 'num' => 27,
    'rotuloTerraza' => 46, 'terraza' => 83, 'rotuloParqueo' => 29, 'parqueo' => 30,
    'rotuloPrecio' => 32, 'precio' => 49, 'f15a' => 33, 'f15b' => 2, 'f15d' => 34, 'f15e' => 35,
    'rotuloLegal' => 106, 'legalB' => 107, 'legal' => 36, 'nota' => 37,
    'rotuloTotal' => 33, 'total' => 38, 'h22' => 39, 'fecha' => 40, 'entrada' => 41, 'h25' => 42,
    'pct' => 77, 'meta' => 78, 'rotuloContra' => 46, 'contra' => 47, 'rotuloTot' => 48,
    'tot' => 49, 'd80' => 50, 'diferencia' => 101, 'notaA' => 108, 'notaB3' => 84,
];

/** "d/m/Y" -> numero de serie de Excel. */
function cot_fmt_serial(string $dmy): int
{
    $d = DateTimeImmutable::createFromFormat('!d/m/Y', $dmy);
    return $d ? Xlsx::fecha($d) : 0;
}

/**
 * Los TEXTOS van en la tabla compartida (sharedStrings), como en el archivo de ellos, y
 * NO como texto en la celda (inlineStr): el lector de cobranzas (xlsx_read_grid) solo
 * entiende los compartidos, y con inlineStr no veia "SEPARACIÓN" ni "A LA FIRMA" — la
 * tabla le llegaba sin reserva ni firma (medido 26-oct: 52 lineas contra 59 del original).
 * Arranca con los textos de la plantilla, en su orden, porque la hoja NOTARIA los usa por
 * numero. $reset vacia la tabla (un libro nuevo).
 */
function cot_fmt_ss(?string $texto, bool $reset = false): int
{
    static $lista = null, $idx = [];
    if ($reset || $lista === null) {
        $lista = []; $idx = [];
        $x = (string)@file_get_contents(COT_FMT_DIR . '/sharedStrings.xml');
        preg_match_all('#<si>(.*?)</si>#s', $x, $m);
        foreach ($m[1] as $si) { $lista[] = $si; }
    }
    if ($texto === null) return count($lista);
    $si = '<t xml:space="preserve">' . htmlspecialchars($texto, ENT_XML1) . '</t>';
    if (!isset($idx[$si])) { $idx[$si] = count($lista); $lista[] = $si; }
    return $idx[$si];
}

function cot_fmt_ss_xml(): string
{
    $n = cot_fmt_ss(null);
    $r = new ReflectionFunction('cot_fmt_ss');
    $lista = $r->getStaticVariables()['lista'] ?? [];
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
         . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . $n . '" uniqueCount="' . $n . '">'
         . implode('', array_map(fn($si) => '<si>' . $si . '</si>', $lista)) . '</sst>';
}

/** Una celda. $v: null (vacia), string (texto), int/float (numero). $f: formula. */
function cot_fmt_c(string $ref, ?int $s, $v = null, ?string $f = null): string
{
    $st = $s !== null ? ' s="' . $s . '"' : '';
    $num = fn($n) => rtrim(rtrim(sprintf('%.10F', (float)$n), '0'), '.');
    if ($f !== null) {
        $vv = $v === null ? '' : (is_string($v) ? '<v>' . htmlspecialchars($v, ENT_XML1) . '</v>' : '<v>' . $num($v) . '</v>');
        $t  = is_string($v) ? ' t="str"' : '';
        return '<c r="' . $ref . '"' . $st . $t . '><f>' . htmlspecialchars($f, ENT_XML1) . '</f>' . $vv . '</c>';
    }
    if ($v === null) return '<c r="' . $ref . '"' . $st . '/>';
    if (is_string($v)) return '<c r="' . $ref . '"' . $st . ' t="s"><v>' . cot_fmt_ss($v) . '</v></c>';
    return '<c r="' . $ref . '"' . $st . '><v>' . $num($v) . '</v></c>';
}

function cot_fmt_fila(int $r, array $celdas, string $attrs = ''): string
{
    return '<row r="' . $r . '"' . ($attrs !== '' ? ' ' . $attrs : '') . '>' . implode('', $celdas) . '</row>';
}

/**
 * La hoja entera (XML). $tipo 'normal' | 'mensual'. PURA salvo el reloj: todo sale de $b.
 * Devuelve ['xml' => ..., 'ultima' => fila de la ultima cuota].
 */
function cot_fmt_hoja(string $tipo, array $b, array $meta, bool $primera, string $hojaNormal): array
{
    $S = COT_FMT_S; $plan = $b['plan'];
    $P   = round((float)$plan['valor'], 2);
    $sep = round((float)$plan['separacion'], 2);
    $filas  = array_values((array)$plan['filas']);
    $abonos = array_values(array_filter($filas, fn($f) => !empty($f['soloFirma'])));
    $cuotas = array_values(array_filter($filas, fn($f) => empty($f['soloFirma'])));
    $N  = count($cuotas);
    $nX = count(array_filter($cuotas, fn($f) => !empty($f['extra'])));
    $mensual = $tipo === 'mensual';
    $plaza = stripos((string)($meta['proyecto'] ?? ''), 'plaza') !== false;

    $rows = [];
    $rows[] = cot_fmt_fila(4, [], 'ht="16.5" customHeight="1"');
    $rows[] = cot_fmt_fila(7, [cot_fmt_c('A7', $S['rotulo'], 'CLIENTE'),
                               cot_fmt_c('B7', $mensual ? $S['valorAlto'] : $S['valor'], (string)($meta['cliente'] ?? '')),
                               cot_fmt_c('E7', $S['vacio'])]);
    $rows[] = cot_fmt_fila(8, [cot_fmt_c('A8', $S['rotulo'], 'UBICACIÓN'),
                               cot_fmt_c('B8', $S['valor'], implode(', ', (array)$b['cods'])),
                               cot_fmt_c('D8', $S['num']), cot_fmt_c('E8', $S['vacio'])]);
    $rows[] = cot_fmt_fila(9, [cot_fmt_c('A9', $S['rotulo'], 'MTS Const.'),
                               cot_fmt_c('B9', $S['m2'], (float)$b['m2'] > 0 ? round((float)$b['m2'], 2) : null),
                               cot_fmt_c('C9', null, 'm2'), cot_fmt_c('D9', $S['num']), cot_fmt_c('E9', $S['vacio'])]);
    // terraza, parqueo, precio y parqueo adicional: el cotizador no los sabe -> ocultas, como en el de ellos
    $rows[] = cot_fmt_fila(10, [cot_fmt_c('A10', $S['rotuloTerraza'], 'TERRAZA'), cot_fmt_c('B10', $S['terraza'], '-'),
                                cot_fmt_c('C10', null, 'm2')], 'ht="16.2" hidden="1" thickBot="1"');
    $rows[] = cot_fmt_fila(11, [cot_fmt_c('A11', $S['rotuloParqueo'], 'PARQUEO'), cot_fmt_c('B11', $S['parqueo'])],
                           'ht="16.2" hidden="1" thickBot="1"');
    $rows[] = cot_fmt_fila(12, [cot_fmt_c('A12', $S['rotulo'], 'PRECIO')], 'ht="15.6" hidden="1" customHeight="1" thickTop="1"');
    $rows[] = cot_fmt_fila(13, [cot_fmt_c('A13', $S['rotuloParqueo'], 'PARQUEO ADICIONAL')], 'ht="15.6" hidden="1" customHeight="1" thickBot="1"');
    $rows[] = cot_fmt_fila(14, [cot_fmt_c('A14', $S['rotuloPrecio'], 'Precio Final:'), cot_fmt_c('B14', $S['precio'], $P),
                                cot_fmt_c('C14', $S['vacio'])], 'ht="16.2" thickTop="1"');
    $rows[] = cot_fmt_fila(15, [cot_fmt_c('A15', $S['f15a']), cot_fmt_c('B15', $S['f15b']), cot_fmt_c('D15', $S['f15d']),
                                cot_fmt_c('E15', $S['f15e'])], 's="1" customFormat="1" ht="16.2" thickBot="1"');
    $legal = (float)($plan['legal'] ?? 0);
    $rows[] = cot_fmt_fila(16, [cot_fmt_c('A16', $S['rotuloLegal'], 'VALORES LEGALES PROMESA C/V'), cot_fmt_c('B16', $S['legalB']),
                                $mensual ? cot_fmt_c('C16', $S['legal'], $legal, "'" . $hojaNormal . "'!C16")
                                         : cot_fmt_c('C16', $S['legal'], $legal)], 'ht="16.2" thickBot="1"');
    $rows[] = cot_fmt_fila(17, [cot_fmt_c('A17', $S['nota'], 'Pago directo para el notario. Se da al momento de la ')]);
    $rows[] = cot_fmt_fila(18, [cot_fmt_c('A18', $S['nota'], 'firma del contrato')]);
    $rows[] = cot_fmt_fila(20, [cot_fmt_c('A20', $S['rotuloTotal'], 'TOTAL CUOTAS'), cot_fmt_c('B20', $S['total'], $N),
                                cot_fmt_c('D20', $S['f15d']), cot_fmt_c('E20', $S['f15e'])], 's="1" customFormat="1"');
    $rows[] = cot_fmt_fila(22, [cot_fmt_c('B22', $S['rotulo'], 'SEPARACIÓN'), cot_fmt_c('C22', $S['vacio'], $sep),
                                cot_fmt_c('D22', null, 'ENTRADA'), cot_fmt_c('E22', $S['vacio'], 'C. EXTRAORDINARIAS'),
                                cot_fmt_c('H22', $S['h22'])]);

    $diez = $P * 0.1;
    if ($mensual) {
        $firma1 = $abonos ? round((float)$abonos[0]['monto'], 2) : round((float)$plan['firma'], 2);
        $rows[] = cot_fmt_fila(23, [cot_fmt_c('B23', $S['fecha'], 'A LA FIRMA'), cot_fmt_c('C23', $S['num'], $firma1),
                                    cot_fmt_c('D23', $S['entrada'], 0.0, '(B14*0.1)-C22-H23'),
                                    cot_fmt_c('E23', $S['vacio'], $diez, '+B14*0.1'),
                                    cot_fmt_c('H23', $S['vacio'], $diez - $sep, 'B14*0.1-C22')], 'ht="15.75" customHeight="1"');
    } else {
        $rows[] = cot_fmt_fila(23, [cot_fmt_c('B23', $S['fecha'], 'A LA FIRMA'), cot_fmt_c('C23', $S['vacio'], $diez - $sep, 'B14*0.1-C22'),
                                    cot_fmt_c('D23', $S['entrada'], 0.0, '(B14*0.1)-C22-C23'),
                                    cot_fmt_c('E23', $S['vacio'], $diez, '+B14*0.1')], 'ht="15.75" customHeight="1"');
    }

    $r = 24;
    $porAnio = $nX > 0 ? $diez / $nX : 0.0;
    // abonos de la firma despues del primero (solo en el mensualizado: la normal es la firma de una vez)
    if ($mensual) {
        foreach (array_slice($abonos, 1) as $k => $a) {
            $rows[] = cot_fmt_fila($r, [cot_fmt_c('A' . $r, null, 'ABONO FIRMA ' . ($k + 2)),
                                        cot_fmt_c('B' . $r, $S['fecha'], cot_fmt_serial((string)$a['fecha'])),
                                        cot_fmt_c('C' . $r, $S['vacio'], round((float)$a['monto'], 2))]);
            $r++;
        }
    }
    $base = $P * 0.2 / max(1, $N);
    foreach ($cuotas as $i => $f) {
        $cel = [cot_fmt_c('A' . $r, null, $i + 1), cot_fmt_c('B' . $r, $S['fecha'], cot_fmt_serial((string)$f['fecha']))];
        if ($mensual) {
            $cel[] = cot_fmt_c('C' . $r, $S['vacio'], round((float)$f['monto'], 2));
        } else {
            $ex = !empty($f['extra']);
            $cel[] = cot_fmt_c('C' . $r, $S['vacio'], $base + ($ex ? $porAnio : 0.0),
                               ($ex ? '$E$24+' : '') . '$B$14*0.2/$B$20');
        }
        if ($i === 0) {   // la columna de trabajo de ellos, al lado de la primera cuota
            $cel[] = cot_fmt_c('D' . $r, $S['vacio'], 0.0, 'D23/4');
            if ($nX > 0) { $cel[] = cot_fmt_c('E' . $r, $S['vacio'], $porAnio, 'E23/' . $nX); $cel[] = cot_fmt_c('F' . $r, $S['vacio'], 'por año'); }
            if ($mensual) $cel[] = cot_fmt_c('H' . $r, $S['vacio'], ($diez - $sep) / max(1, $N), 'H23/B20');
        }
        if ($i === 1) {
            $cel[] = cot_fmt_c('E' . $r, $S['vacio'], $diez / max(1, $N), 'E23/B20');
            $cel[] = cot_fmt_c('F' . $r, $S['vacio'], 'semestral');
        }
        $rows[] = cot_fmt_fila($r, $cel, $i < 2 ? 'ht="15.75" customHeight="1"' : '');
        $r++;
    }
    $ult = $r - 1; $t = $r; $c2 = $r + 1; $t3 = $r + 2;

    // totales: el valor guardado sale de las mismas celdas que suma la formula
    $suma = $sep + ($mensual ? array_sum(array_map(fn($a) => round((float)$a['monto'], 2), $abonos))
                                 + ($abonos ? 0.0 : round((float)$plan['firma'], 2))
                                 + array_sum(array_map(fn($f) => round((float)$f['monto'], 2), $cuotas))
                             : ($diez - $sep) + $base * $N + $porAnio * $nX);
    $rows[] = cot_fmt_fila($t, [cot_fmt_c('B' . $t, $S['rotulo'], 'Total Cuota Inicial:'),
                                cot_fmt_c('C' . $t, $S['vacio'], $suma, "+SUM(C22:C$ult)"),
                                cot_fmt_c('D' . $t, $S['pct'], $P > 0 ? $suma / $P : 0.0, "C$t/C$t3"),
                                cot_fmt_c('E' . $t, $S['meta'], $P * 0.4, "+C$t3*0.4")]);
    $rows[] = cot_fmt_fila($c2, [cot_fmt_c('B' . $c2, $S['rotuloContra'], 'Contraentrega'),
                                 cot_fmt_c('C' . $c2, $S['contra'], $P - $suma, "B14-C$t"),
                                 cot_fmt_c('E' . $c2, $mensual ? $S['diferencia'] : $S['vacio'], $P * 0.4 - $suma, "+E$t-C$t")],
                           'ht="16.2" thickBot="1"');
    $rows[] = cot_fmt_fila($t3, [cot_fmt_c('B' . $t3, $S['rotuloTot'], 'TOTAL ='),
                                 cot_fmt_c('C' . $t3, $S['tot'], $P, "+C$t+C$c2"), cot_fmt_c('D' . $t3, $S['d80'])]);
    $merges = ['A16:B16'];
    $fin = $t3;
    if ($plaza) {   // la nota del parqueo es de Noral Plaza; en la normal va oculta, como en el de ellos
        $n1 = $t3 + 3; $oc = $mensual ? '' : 'hidden="1"';
        $rows[] = cot_fmt_fila($n1,     [cot_fmt_c('A' . $n1, $S['notaA'], 'Nota: '), cot_fmt_c('B' . $n1, null, 'Están a la venta parqueos privados dentro del edificio')], $oc);
        $rows[] = cot_fmt_fila($n1 + 1, [cot_fmt_c('A' . ($n1 + 1), $S['notaA']), cot_fmt_c('B' . ($n1 + 1), null, 'de parqueos. ')], $oc);
        $rows[] = cot_fmt_fila($n1 + 2, [cot_fmt_c('A' . ($n1 + 2), $S['notaA']), cot_fmt_c('B' . ($n1 + 2), $S['notaB3'], 'PVP: $20,000 por parqueo')], $oc);
        $merges[] = 'A' . $n1 . ':A' . ($n1 + 2);
        $fin = $n1 + 2;
    }

    $cols = $mensual
        ? '<col min="1" max="1" width="16.5" customWidth="1"/><col min="2" max="2" width="19.69921875" customWidth="1"/><col min="3" max="3" width="12.8984375" customWidth="1"/><col min="4" max="4" width="15.69921875" hidden="1" customWidth="1"/><col min="5" max="8" width="12.8984375" hidden="1" customWidth="1"/><col min="9" max="9" width="13.8984375" hidden="1" customWidth="1"/><col min="10" max="10" width="11.19921875" hidden="1" customWidth="1"/><col min="11" max="12" width="0" hidden="1" customWidth="1"/>'
        : '<col min="1" max="1" width="18.69921875" customWidth="1"/><col min="2" max="2" width="19.69921875" customWidth="1"/><col min="3" max="3" width="12.8984375" customWidth="1"/><col min="4" max="4" width="15.69921875" customWidth="1"/><col min="5" max="8" width="12.8984375" customWidth="1"/><col min="9" max="9" width="13.8984375" customWidth="1"/><col min="10" max="10" width="11.19921875" customWidth="1"/>';
    $tab = $mensual ? '<tabColor theme="7" tint="0.39997558519241921"/>' : '<tabColor theme="9" tint="0.39997558519241921"/>';
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
         . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
         . '<sheetPr>' . $tab . '</sheetPr><dimension ref="A4:I' . $fin . '"/>'
         . '<sheetViews><sheetView' . ($primera ? ' tabSelected="1"' : '') . ' zoomScale="' . ($mensual ? 85 : 90) . '" zoomScaleNormal="' . ($mensual ? 85 : 90) . '" workbookViewId="0"/></sheetViews>'
         . '<sheetFormatPr baseColWidth="10" defaultColWidth="10.8984375" defaultRowHeight="15.6"/>'
         . '<cols>' . $cols . '</cols><sheetData>' . implode('', $rows) . '</sheetData>'
         . '<mergeCells count="' . count($merges) . '">' . implode('', array_map(fn($m) => '<mergeCell ref="' . $m . '"/>', $merges)) . '</mergeCells>'
         . '<pageMargins left="0.7" right="0.7" top="1.3149999999999999" bottom="0.75" header="0.3" footer="0.3"/>'
         . '<pageSetup paperSize="9" scale="95" orientation="portrait"/><drawing r:id="rId1"/></worksheet>';
    return ['xml' => $xml, 'ultima' => $ult];
}

/** Los dos logos (oneCellAnchor: el ancho sale de la proporcion de la imagen, no se estira). */
function cot_fmt_dibujo(string $logoProyecto): string
{
    $emu = 9525;   // EMU por pixel
    $alto = 882912;
    $crop = '';
    if (basename($logoProyecto) === 'logo-noral-plaza.png') {   // la del archivo de ellos, con su recorte
        $ancho = 1241193; $crop = '<a:srcRect l="12340" t="9844" r="11902" b="12081"/>';
    } else {
        $sz = @getimagesize($logoProyecto) ?: [3, 2];
        $ancho = (int)min(2400000, round($alto * $sz[0] / max(1, $sz[1])));
        $alto  = (int)round($ancho * $sz[1] / max(1, $sz[0]));
    }
    $pic = fn(int $id, string $rid, int $col, int $colOff, int $rowOff, int $cx, int $cy, string $recorte) =>
        '<xdr:oneCellAnchor><xdr:from><xdr:col>' . $col . '</xdr:col><xdr:colOff>' . $colOff . '</xdr:colOff><xdr:row>0</xdr:row><xdr:rowOff>' . $rowOff . '</xdr:rowOff></xdr:from>'
        . '<xdr:ext cx="' . $cx . '" cy="' . $cy . '"/><xdr:pic><xdr:nvPicPr><xdr:cNvPr id="' . $id . '" name="Logo ' . $id . '"/><xdr:cNvPicPr><a:picLocks noChangeAspect="1"/></xdr:cNvPicPr></xdr:nvPicPr>'
        . '<xdr:blipFill><a:blip xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" r:embed="' . $rid . '"/>' . $recorte . '<a:stretch><a:fillRect/></a:stretch></xdr:blipFill>'
        . '<xdr:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></xdr:spPr></xdr:pic><xdr:clientData/></xdr:oneCellAnchor>';
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
         . '<xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">'
         . $pic(2, 'rId2', 0, 212108, 86414, 703770, 853768, '')
         . $pic(3, 'rId1', 1, 204251, 78557, $ancho, $alto, $crop)
         . '</xdr:wsDr>';
}

/**
 * El .xlsx completo. $bloques: los mismos de la pantalla (un bloque por plan).
 * $meta: cliente, proyecto, logo (ruta del logo del proyecto; vacio = el de Noral Plaza).
 */
function cot_formato_generar(array $bloques, array $meta): string
{
    $logo = (string)($meta['logo'] ?? '');
    if ($logo === '' || !is_file($logo)) $logo = COT_FMT_DIR . '/logo-noral-plaza.png';
    $partes = []; $hojas = []; $n = 0;
    cot_fmt_ss(null, true);   // tabla de textos nueva para este libro
    $varios = count($bloques) > 1;
    foreach ($bloques as $b) {
        $suf = $varios ? ' ' . substr(preg_replace('/[\\\\\/?*\[\]:]/', '-', implode(',', (array)$b['cods'])), 0, 12) : '';
        $nomN = 'CUOTAS NORMALES' . $suf; $nomM = 'MENSUALIZADO' . $suf;
        $orden = [['mensual', $nomM], ['normal', $nomN]];   // el mensualizado primero: ver arriba
        foreach ($orden as [$tipo, $nom]) {
            $n++;
            $h = cot_fmt_hoja($tipo, $b, $meta, $n === 1, $nomN);
            $partes["xl/worksheets/sheet$n.xml"] = $h['xml'];
            $partes["xl/worksheets/_rels/sheet$n.xml.rels"] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" Target="../drawings/drawing' . $n . '.xml"/></Relationships>';
            $partes["xl/drawings/drawing$n.xml"] = cot_fmt_dibujo($logo);
            $partes["xl/drawings/_rels/drawing$n.xml.rels"] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/logo-proyecto.png"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/logo-galjosa.png"/></Relationships>';
            $hojas[] = [$nom, false];
        }
    }
    $n++;
    $partes["xl/worksheets/sheet$n.xml"] = (string)file_get_contents(COT_FMT_DIR . '/notaria.xml');
    $hojas[] = ['NOTARÍA', true];

    $sheets = ''; $rels = ''; $ct = '';
    foreach ($hojas as $i => [$nom, $oculta]) {
        $k = $i + 1;
        $sheets .= '<sheet name="' . htmlspecialchars($nom, ENT_XML1) . '" sheetId="' . $k . '"' . ($oculta ? ' state="hidden"' : '') . ' r:id="rId' . $k . '"/>';
        $rels   .= '<Relationship Id="rId' . $k . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $k . '.xml"/>';
        $ct     .= '<Override PartName="/xl/worksheets/sheet' . $k . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        if (!$oculta) $ct .= '<Override PartName="/xl/drawings/drawing' . $k . '.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/>';
    }
    $m = count($hojas);
    $rels .= '<Relationship Id="rId' . ($m + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
           . '<Relationship Id="rId' . ($m + 2) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/theme" Target="theme/theme1.xml"/>'
           . '<Relationship Id="rId' . ($m + 3) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>';
    $partes['xl/workbook.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<bookViews><workbookView activeTab="0"/></bookViews><sheets>' . $sheets . '</sheets><calcPr calcId="191029" fullCalcOnLoad="1"/></workbook>';
    $partes['xl/_rels/workbook.xml.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . '</Relationships>';
    $partes['xl/styles.xml']        = (string)file_get_contents(COT_FMT_DIR . '/styles.xml');
    $partes['xl/theme/theme1.xml']  = (string)file_get_contents(COT_FMT_DIR . '/theme1.xml');
    $partes['xl/sharedStrings.xml'] = cot_fmt_ss_xml();   // los de la plantilla (NOTARIA) + los de las hojas
    $partes['xl/media/logo-proyecto.png'] = (string)file_get_contents($logo);
    $partes['xl/media/logo-galjosa.png']  = (string)file_get_contents(COT_FMT_DIR . '/logo-galjosa.png');
    $partes['[Content_Types].xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/><Default Extension="png" ContentType="image/png"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . $ct
        . '<Override PartName="/xl/theme/theme1.xml" ContentType="application/vnd.openxmlformats-officedocument.theme+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
        . '</Types>';
    $partes['_rels/.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';
    // [Content_Types].xml primero: algunos lectores lo esperan al principio del zip
    $orden = ['[Content_Types].xml' => $partes['[Content_Types].xml']] + $partes;
    return Xlsx::zip($orden);
}
