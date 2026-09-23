<?php
/**
 * xlsxlib.php — escribe un .xlsx de verdad, SIN librerias ni extension zip.
 * ---------------------------------------------------------------------------
 * POR QUE A MANO. El contenedor no tiene la extension `zip` ni ZipArchive ni
 * composer (comprobado el 23-sep-2026 dentro del contenedor: zip NO, ZipArchive NO,
 * sin composer.json). Y las alternativas sin zip son peores:
 *   · CSV     -> pierde formato Y formulas. El pedido era justamente no perderlos.
 *   · .xls XML (SpreadsheetML 2003) -> Excel lo abre quejandose de que la extension
 *     no coincide con el contenido. Un vendedor que le manda eso a un cliente pone
 *     una alerta roja en el medio de una cotizacion.
 * Un .xlsx es un ZIP de archivos XML. El ZIP se escribe aca con metodo 0
 * (guardado, sin comprimir): no hace falta zlib y el archivo es igual de valido.
 * Un plan de pagos son ~60 filas; el peso no es problema.
 *
 * 🔴 LAS FORMULAS SE ESCRIBEN SIN EL VALOR CALCULADO (<f> sin <v>). Excel las
 * calcula al abrir. Si se pusiera un <v> mentiroso, la hoja mostraria un numero y
 * la barra otra cosa hasta que alguien tocara una celda.
 * ---------------------------------------------------------------------------
 */
declare(strict_types=1);

final class Xlsx
{
    /** @var array<int,array<int,array{t:string,v:mixed,s:int}>> fila => col => celda */
    private array $celdas = [];
    private array $anchos = [];
    private int $maxFila = 0, $maxCol = 0;
    private string $hoja;
    private int $congelar = 0;
    /** @var string[] rangos combinados, "A1:C1" */
    private array $unir = [];
    private array $altos = [];
    private bool $oculta = false;
    /** @var array<int,array{ref:string,tipo:string,op:string,f1:string,f2:string,titulo:string,msg:string}> */
    private array $validaciones = [];

    /** @var array<int,array> hojas ya cerradas: {nombre, celdas, anchos, altos, unir, congelar, maxF, maxC} */
    private array $hojas = [];

    /** Cierra la hoja actual y empieza otra. El contenido escrito hasta aca queda
     *  guardado; lo que se escriba despues va a la hoja nueva. */
    public function nuevaHoja(string $nombre): void {
        $this->guardarHoja();
        $this->hoja = mb_substr(str_replace([':', '\\', '/', '?', '*', '[', ']'], '-', $nombre), 0, 31);
        $this->celdas = []; $this->anchos = []; $this->altos = []; $this->unir = [];
        $this->maxFila = 0; $this->maxCol = 0; $this->congelar = 0;
        $this->oculta = false; $this->validaciones = [];
    }
    private function guardarHoja(): void {
        $this->hojas[] = ['nombre' => $this->hoja, 'celdas' => $this->celdas, 'anchos' => $this->anchos,
            'altos' => $this->altos, 'unir' => $this->unir, 'congelar' => $this->congelar,
            'maxF' => $this->maxFila, 'maxC' => $this->maxCol,
            'oculta' => $this->oculta, 'val' => $this->validaciones];
    }

    public function __construct(string $nombreHoja = 'Hoja1') {
        // Excel prohibe : \ / ? * [ ] y mas de 31 caracteres en el nombre de la hoja.
        $this->hoja = mb_substr(str_replace([':', '\\', '/', '?', '*', '[', ']'], '-', $nombreHoja), 0, 31);
    }

    /** Estilos. El indice es el que se escribe en cada celda (atributo s). */
    public const NORMAL = 0, TITULO = 1, CABECERA = 2, DINERO = 3, DINERO_B = 4,
                 ETIQUETA = 5, EDITABLE = 6, FECHA = 7, PIE = 8, AVISO_OK = 9,
                 AVISO_MAL = 10, SUBTITULO = 11, CENTRO = 12, PORCENTAJE = 13,
                 /* Los de la COTIZACION: copian el documento que ve el cliente.
                    Los colores salen de la pantalla real, no de mi gusto -- medidos
                    con getComputedStyle sobre cotizar.php el 23-sep-2026. */
                 BANDA_AMA_T = 14, BANDA_AMA_N = 15,   // "Precio final", amarillo #FFF200
                 BANDA_VER_T = 16, BANDA_VER_N = 17,   // "Valores legales", verde #6F8F3F
                 HITO_T = 18, HITO_N = 19,             // SEPARACION / A LA FIRMA, #F5F8FA
                 EXTRA_T = 20, EXTRA_N = 21,           // cuota con extraordinaria, #FFF8E6
                 TOTINI_T = 22, TOTINI_N = 23,         // TOTAL CUOTA INICIAL, #EEF4FB
                 FICHA_R = 24, FICHA_V = 25,           // los cuadros de arriba
                 FICHA_DR = 26, FICHA_DV = 27,         // el cuadro destacado
                 DATO_R = 28, DATO_V = 29,             // Cliente / Proyecto / Unidad
                 CUOTA_T = 30, CUOTA_N = 31,           // fila de cuota normal
                 NOTA = 32, LOGO = 33, GRAN_T = 34, GRAN_N = 35;

    public function texto(int $f, int $c, string $v, int $s = self::NORMAL): void { $this->set($f, $c, 'inlineStr', $v, $s); }
    public function numero(int $f, int $c, float $v, int $s = self::NORMAL): void { $this->set($f, $c, 'n', $v, $s); }
    /**
     * La formula va SIN el '=' inicial.
     *
     * `$cache` es el valor que la formula da HOY, calculado en el servidor. Se escribe
     * junto a la formula porque quien lee el archivo con un programa -- no con Excel --
     * lee el valor cacheado, no la formula: sin el, un importador ve la hoja VACIA.
     * No es un numero inventado: es el que emitio el cotizador en este momento. Y
     * `fullCalcOnLoad` hace que Excel lo recalcule apenas se abre, asi que en cuanto
     * alguien toque algo manda la formula.
     */
    public function formula(int $f, int $c, string $v, int $s = self::NORMAL, $cache = null): void {
        $this->celdas[$f][$c] = ['t' => 'f', 'v' => $v, 's' => $s, 'c' => $cache];
        if ($f > $this->maxFila) $this->maxFila = $f;
        if ($c > $this->maxCol)  $this->maxCol  = $c;
    }
    public function ancho(int $c, float $w): void { $this->anchos[$c] = $w; }
    /** Combina celdas. OJO: el contenido y el estilo van en la de arriba a la izquierda;
     *  las demas tienen que existir con el MISMO estilo o el relleno se corta a la mitad. */
    public function unir(int $f1, int $c1, int $f2, int $c2): void {
        $this->unir[] = self::ref($f1, $c1) . ':' . self::ref($f2, $c2);
    }
    public function alto(int $f, float $h): void { $this->altos[$f] = $h; }

    /** Marca la hoja ACTUAL como oculta. Se puede volver a mostrar desde Excel con
     *  clic derecho en las pestañas -> Mostrar. No es un candado, es quitarla de enmedio. */
    public function ocultar(): void { $this->oculta = true; }

    /**
     * Limite para lo que se puede escribir en una celda. Excel RECHAZA el valor y
     * muestra el mensaje: no es un aviso que se pueda ignorar como una formula de texto.
     *
     * @param string $tipo  'decimal' | 'whole' | 'date' | 'list'
     * @param string $op    'between' | 'greaterThan' | 'greaterThanOrEqual' | ...
     */
    public function limite(int $f, int $c, string $tipo, string $op, string $f1, string $f2,
                           string $titulo, string $msg): void {
        $this->validaciones[] = ['ref' => self::ref($f, $c), 'tipo' => $tipo, 'op' => $op,
            'f1' => $f1, 'f2' => $f2, 'titulo' => $titulo, 'msg' => $msg];
    }
    public function congelarHasta(int $fila): void { $this->congelar = $fila; }

    private function set(int $f, int $c, string $t, $v, int $s): void {
        $this->celdas[$f][$c] = ['t' => $t, 'v' => $v, 's' => $s];
        if ($f > $this->maxFila) $this->maxFila = $f;
        if ($c > $this->maxCol)  $this->maxCol  = $c;
    }

    /** A, B, ... Z, AA, AB... — 1 = A. */
    public static function col(int $n): string {
        $s = '';
        while ($n > 0) { $r = ($n - 1) % 26; $s = chr(65 + $r) . $s; $n = intdiv($n - 1 - $r, 26); }
        return $s;
    }
    public static function ref(int $f, int $c): string { return self::col($c) . $f; }

    private static function esc(string $s): string {
        // Excel revienta con los caracteres de control; se quitan antes de escapar.
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $s) ?? '';
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    public function salida(): string
    {
        $this->guardarHoja();                       // cierra la que este abierta
        $partes = [
            '[Content_Types].xml'        => $this->contentTypes(),
            '_rels/.rels'                => $this->relsRaiz(),
            'xl/workbook.xml'            => $this->workbook(),
            'xl/_rels/workbook.xml.rels' => $this->relsWorkbook(),
            'xl/styles.xml'              => $this->styles(),
        ];
        foreach ($this->hojas as $i => $h)
            $partes['xl/worksheets/sheet' . ($i + 1) . '.xml'] = $this->sheet($h);
        return $this->zip($partes);
    }

    private function contentTypes(): string {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
          . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
          . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
          . '<Default Extension="xml" ContentType="application/xml"/>'
          . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
          . implode('', array_map(fn($i) => '<Override PartName="/xl/worksheets/sheet' . ($i + 1)
                . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>',
                array_keys($this->hojas)))
          . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
          . '</Types>';
    }
    private function relsRaiz(): string {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
          . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
          . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
          . '</Relationships>';
    }
    private function workbook(): string {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
          . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
          . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
          . '<sheets>'
          . implode('', array_map(fn($i) => '<sheet name="' . self::esc($this->hojas[$i]['nombre'])
                . '" sheetId="' . ($i + 1) . '"'
                . (!empty($this->hojas[$i]['oculta']) ? ' state="hidden"' : '')
                . ' r:id="rId' . ($i + 1) . '"/>', array_keys($this->hojas)))
          . '</sheets>'
          /* fullCalcOnLoad: obliga a Excel a calcular TODAS las formulas al abrir. Sin
             esto, como no escribimos valores cacheados, algunas versiones muestran 0
             hasta que el usuario toca una celda. */
          . '<calcPr calcId="0" fullCalcOnLoad="1"/>'
          . '</workbook>';
    }
    private function relsWorkbook(): string {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
          . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
          . implode('', array_map(fn($i) => '<Relationship Id="rId' . ($i + 1)
                . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"'
                . ' Target="worksheets/sheet' . ($i + 1) . '.xml"/>', array_keys($this->hojas)))
          . '<Relationship Id="rId' . (count($this->hojas) + 1)
          . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
          . '</Relationships>';
    }

    private function styles(): string {
        /* Los colores son los del cotizador: tinta #0C2C44 y azul #0C6C9C. El amarillo
           marca lo que el vendedor PUEDE tocar -- que se vea de un vistazo cual es
           dato de entrada y cual sale de una formula. */
        $numFmts = '<numFmts count="3">'
          . '<numFmt numFmtId="164" formatCode="&quot;$&quot;#,##0.00"/>'
          . '<numFmt numFmtId="165" formatCode="dd/mm/yyyy"/>'
          . '<numFmt numFmtId="166" formatCode="0.0%"/>'
          . '</numFmts>';
        $fonts = '<fonts count="12">'
          . '<font><sz val="11"/><color rgb="FF0C2C44"/><name val="Calibri"/></font>'                 // 0 normal
          . '<font><b/><sz val="15"/><color rgb="FF0C2C44"/><name val="Calibri"/></font>'             // 1 titulo
          . '<font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'             // 2 cabecera tabla
          . '<font><b/><sz val="11"/><color rgb="FF0C2C44"/><name val="Calibri"/></font>'             // 3 negrita
          . '<font><i/><sz val="9"/><color rgb="FF5A6B7A"/><name val="Calibri"/></font>'              // 4 pie/nota
          . '<font><b/><sz val="12"/><color rgb="FF0C6C9C"/><name val="Calibri"/></font>'             // 5 subtitulo
          . '<font><b/><sz val="13"/><color rgb="FF0C2C44"/><name val="Calibri"/></font>'             // 6 banda amarilla
          . '<font><b/><sz val="12"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'             // 7 banda verde
          . '<font><sz val="8"/><color rgb="FF5A6B7A"/><name val="Calibri"/></font>'                  // 8 rotulo de ficha
          . '<font><b/><sz val="13"/><color rgb="FF1C4E80"/><name val="Calibri"/></font>'             // 9 destacado azul
          . '<font><sz val="10"/><color rgb="FF5A6B7A"/><name val="Calibri"/></font>'                 // 10 etiqueta de dato
          . '<font><b/><sz val="13"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'             // 11 total grande, sobre el navy
          . '</fonts>';
        /* Los colores son EXACTAMENTE los del documento, medidos con getComputedStyle
           sobre la cotizacion en pantalla. No aproximar: el cliente ve los dos. */
        $fills = '<fills count="11">'
          . '<fill><patternFill patternType="none"/></fill>'
          . '<fill><patternFill patternType="gray125"/></fill>'
          . '<fill><patternFill patternType="solid"><fgColor rgb="FF0C2C44"/><bgColor indexed="64"/></patternFill></fill>'  // 2 cabecera tabla
          . '<fill><patternFill patternType="solid"><fgColor rgb="FFFFF6D6"/><bgColor indexed="64"/></patternFill></fill>'  // 3 editable
          . '<fill><patternFill patternType="solid"><fgColor rgb="FFF5F8FA"/><bgColor indexed="64"/></patternFill></fill>'  // 4 hito
          . '<fill><patternFill patternType="solid"><fgColor rgb="FFDFF3E4"/><bgColor indexed="64"/></patternFill></fill>'  // 5 ok
          . '<fill><patternFill patternType="solid"><fgColor rgb="FFFBE3E3"/><bgColor indexed="64"/></patternFill></fill>'  // 6 mal
          . '<fill><patternFill patternType="solid"><fgColor rgb="FFFFF200"/><bgColor indexed="64"/></patternFill></fill>'  // 7 precio final
          . '<fill><patternFill patternType="solid"><fgColor rgb="FF6F8F3F"/><bgColor indexed="64"/></patternFill></fill>'  // 8 valores legales
          . '<fill><patternFill patternType="solid"><fgColor rgb="FFFFF8E6"/><bgColor indexed="64"/></patternFill></fill>'  // 9 extraordinaria
          . '<fill><patternFill patternType="solid"><fgColor rgb="FFEEF4FB"/><bgColor indexed="64"/></patternFill></fill>'  // 10 destacado / total inicial
          . '</fills>';
        $borders = '<borders count="4">'
          . '<border><left/><right/><top/><bottom/><diagonal/></border>'
          . '<border><left style="thin"><color rgb="FFDFE6EC"/></left><right style="thin"><color rgb="FFDFE6EC"/></right>'
          . '<top style="thin"><color rgb="FFDFE6EC"/></top><bottom style="thin"><color rgb="FFDFE6EC"/></bottom><diagonal/></border>'
          // 2: solo una linea abajo, como las filas de la tabla del documento
          . '<border><left/><right/><top/><bottom style="thin"><color rgb="FFEEF2F6"/></bottom><diagonal/></border>'
          // 3: recuadro completo, para los cuadros de arriba
          . '<border><left style="thin"><color rgb="FFDFE6EC"/></left><right style="thin"><color rgb="FFDFE6EC"/></right>'
          . '<top style="thin"><color rgb="FFDFE6EC"/></top><bottom style="thin"><color rgb="FFDFE6EC"/></bottom><diagonal/></border>'
          . '</borders>';
        // El orden TIENE que coincidir con las constantes de arriba.
        $x = [
            '<xf numFmtId="0"   fontId="0" fillId="0" borderId="1" applyBorder="1"/>',                                        // NORMAL
            '<xf numFmtId="0"   fontId="1" fillId="0" borderId="0"/>',                                                        // TITULO
            '<xf numFmtId="0"   fontId="2" fillId="2" borderId="1" applyFill="1" applyFont="1" applyBorder="1"><alignment horizontal="center" vertical="center"/></xf>', // CABECERA
            '<xf numFmtId="164" fontId="0" fillId="0" borderId="1" applyNumberFormat="1" applyBorder="1"/>',                  // DINERO
            '<xf numFmtId="164" fontId="3" fillId="4" borderId="1" applyNumberFormat="1" applyFill="1" applyFont="1" applyBorder="1"/>', // DINERO_B
            '<xf numFmtId="0"   fontId="3" fillId="0" borderId="1" applyFont="1" applyBorder="1"/>',                          // ETIQUETA
            '<xf numFmtId="164" fontId="3" fillId="3" borderId="1" applyNumberFormat="1" applyFill="1" applyFont="1" applyBorder="1"/>', // EDITABLE
            '<xf numFmtId="165" fontId="0" fillId="0" borderId="1" applyNumberFormat="1" applyBorder="1"><alignment horizontal="center"/></xf>', // FECHA
            '<xf numFmtId="0"   fontId="4" fillId="0" borderId="0" applyFont="1"/>',                                          // PIE
            '<xf numFmtId="0"   fontId="3" fillId="5" borderId="1" applyFill="1" applyFont="1" applyBorder="1"><alignment horizontal="center"/></xf>', // AVISO_OK
            '<xf numFmtId="0"   fontId="3" fillId="6" borderId="1" applyFill="1" applyFont="1" applyBorder="1"><alignment horizontal="center"/></xf>', // AVISO_MAL
            '<xf numFmtId="0"   fontId="5" fillId="0" borderId="0" applyFont="1"/>',                                          // SUBTITULO
            '<xf numFmtId="0"   fontId="0" fillId="0" borderId="1" applyBorder="1"><alignment horizontal="center"/></xf>',    // CENTRO
            '<xf numFmtId="166" fontId="3" fillId="3" borderId="1" applyNumberFormat="1" applyFill="1" applyFont="1" applyBorder="1"><alignment horizontal="center"/></xf>', // PORCENTAJE (editable)
            /* ── los del DOCUMENTO ──────────────────────────────────────────────── */
            '<xf numFmtId="0"   fontId="6"  fillId="7"  borderId="0" applyFill="1" applyFont="1"><alignment vertical="center" indent="1"/></xf>',                                   // 14 BANDA_AMA_T
            '<xf numFmtId="164" fontId="6"  fillId="7"  borderId="0" applyNumberFormat="1" applyFill="1" applyFont="1"><alignment horizontal="right" vertical="center" indent="1"/></xf>', // 15 BANDA_AMA_N
            '<xf numFmtId="0"   fontId="7"  fillId="8"  borderId="0" applyFill="1" applyFont="1"><alignment vertical="center" indent="1"/></xf>',                                   // 16 BANDA_VER_T
            '<xf numFmtId="164" fontId="7"  fillId="8"  borderId="0" applyNumberFormat="1" applyFill="1" applyFont="1"><alignment horizontal="right" vertical="center" indent="1"/></xf>', // 17 BANDA_VER_N
            '<xf numFmtId="0"   fontId="3"  fillId="4"  borderId="2" applyFill="1" applyFont="1" applyBorder="1"><alignment indent="1"/></xf>',                                     // 18 HITO_T
            '<xf numFmtId="164" fontId="3"  fillId="4"  borderId="2" applyNumberFormat="1" applyFill="1" applyFont="1" applyBorder="1"><alignment horizontal="right" indent="1"/></xf>',  // 19 HITO_N
            '<xf numFmtId="0"   fontId="0"  fillId="9"  borderId="2" applyFill="1" applyBorder="1"><alignment indent="1"/></xf>',                                                   // 20 EXTRA_T
            '<xf numFmtId="164" fontId="0"  fillId="9"  borderId="2" applyNumberFormat="1" applyFill="1" applyBorder="1"><alignment horizontal="right" indent="1"/></xf>',          // 21 EXTRA_N
            '<xf numFmtId="0"   fontId="9"  fillId="10" borderId="2" applyFill="1" applyFont="1" applyBorder="1"><alignment indent="1"/></xf>',                                     // 22 TOTINI_T
            '<xf numFmtId="164" fontId="9"  fillId="10" borderId="2" applyNumberFormat="1" applyFill="1" applyFont="1" applyBorder="1"><alignment horizontal="right" indent="1"/></xf>',  // 23 TOTINI_N
            '<xf numFmtId="0"   fontId="8"  fillId="0"  borderId="3" applyFont="1" applyBorder="1"><alignment indent="1"/></xf>',                                                   // 24 FICHA_R
            '<xf numFmtId="164" fontId="3"  fillId="0"  borderId="3" applyNumberFormat="1" applyFont="1" applyBorder="1"><alignment indent="1"/></xf>',                             // 25 FICHA_V
            '<xf numFmtId="0"   fontId="8"  fillId="10" borderId="3" applyFill="1" applyFont="1" applyBorder="1"><alignment indent="1"/></xf>',                                     // 26 FICHA_DR
            '<xf numFmtId="164" fontId="9"  fillId="10" borderId="3" applyNumberFormat="1" applyFill="1" applyFont="1" applyBorder="1"><alignment indent="1"/></xf>',               // 27 FICHA_DV
            '<xf numFmtId="0"   fontId="10" fillId="0"  borderId="0" applyFont="1"/>',                                                                                             // 28 DATO_R
            '<xf numFmtId="0"   fontId="3"  fillId="0"  borderId="0" applyFont="1"/>',                                                                                             // 29 DATO_V
            '<xf numFmtId="0"   fontId="0"  fillId="0"  borderId="2" applyBorder="1"><alignment indent="1"/></xf>',                                                                // 30 CUOTA_T
            '<xf numFmtId="164" fontId="0"  fillId="0"  borderId="2" applyNumberFormat="1" applyBorder="1"><alignment horizontal="right" indent="1"/></xf>',                        // 31 CUOTA_N
            '<xf numFmtId="0"   fontId="4"  fillId="0"  borderId="0" applyFont="1"/>',                                                                                             // 32 NOTA
            '<xf numFmtId="0"   fontId="1"  fillId="0"  borderId="0" applyFont="1"><alignment vertical="center"/></xf>',                                                           // 33 LOGO
            '<xf numFmtId="0"   fontId="11" fillId="2"  borderId="0" applyFill="1" applyFont="1"><alignment indent="1" vertical="center"/></xf>',                                   // 34 GRAN_T
            '<xf numFmtId="164" fontId="11" fillId="2"  borderId="0" applyNumberFormat="1" applyFill="1" applyFont="1"><alignment horizontal="right" indent="1" vertical="center"/></xf>', // 35 GRAN_N
        ];
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
          . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
          . $numFmts . $fonts . $fills . $borders
          . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
          . '<cellXfs count="' . count($x) . '">' . implode('', $x) . '</cellXfs>'
          . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
          . '</styleSheet>';
    }

    private function sheet(array $h): string
    {
        $cols = '';
        if ($h['anchos']) {
            $cols = '<cols>';
            foreach ($h['anchos'] as $c => $w)
                $cols .= '<col min="' . $c . '" max="' . $c . '" width="' . $w . '" customWidth="1"/>';
            $cols .= '</cols>';
        }
        $panes = '';
        if ($h['congelar'] > 0) {
            $panes = '<pane ySplit="' . $h['congelar'] . '" topLeftCell="A' . ($h['congelar'] + 1)
                   . '" activePane="bottomLeft" state="frozen"/>'
                   . '<selection pane="bottomLeft" activeCell="A' . ($h['congelar'] + 1) . '" sqref="A' . ($h['congelar'] + 1) . '"/>';
        }
        $filas = '';
        for ($f = 1; $f <= $h['maxF']; $f++) {
            if (empty($h['celdas'][$f])) continue;
            $cs = '';
            ksort($h['celdas'][$f]);
            foreach ($h['celdas'][$f] as $c => $cel) {
                $ref = self::ref($f, $c);
                $s = ' s="' . $cel['s'] . '"';
                if ($cel['t'] === 'inlineStr') {
                    $cs .= '<c r="' . $ref . '"' . $s . ' t="inlineStr"><is><t xml:space="preserve">'
                         . self::esc((string)$cel['v']) . '</t></is></c>';
                } elseif ($cel['t'] === 'f') {
                    $vc = ($cel['c'] ?? null) === null ? ''
                        : '<v>' . (is_string($cel['c']) ? self::esc($cel['c'])
                                   : rtrim(rtrim(number_format((float)$cel['c'], 12, '.', ''), '0'), '.')) . '</v>';
                    $tt = is_string($cel['c'] ?? null) ? ' t="str"' : '';
                    $cs .= '<c r="' . $ref . '"' . $s . $tt . '><f>' . self::esc((string)$cel['v']) . '</f>' . $vc . '</c>';
                } else {
                    /* 🔴 12 DECIMALES, NO 6. Con 6 un porcentaje de 0,1000000901 -- el que
                       sale de una entrada de 11.100,01 sobre 111.000 -- se guardaba como
                       0,1 y la tabla salia UN CENTAVO por debajo de la del cotizador en
                       tres filas. Un centavo en un documento que firma un cliente es un
                       documento que no cuadra. */
                    $cs .= '<c r="' . $ref . '"' . $s . '><v>' . rtrim(rtrim(number_format((float)$cel['v'], 12, '.', ''), '0'), '.') . '</v></c>';
                }
            }
            $alto = isset($h['altos'][$f]) ? ' ht="' . $h['altos'][$f] . '" customHeight="1"' : '';
            $filas .= '<row r="' . $f . '"' . $alto . '>' . $cs . '</row>';
        }
        $dim = 'A1:' . self::ref(max(1, $h['maxF']), max(1, $h['maxC']));
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
          . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
          . '<dimension ref="' . $dim . '"/>'
          . '<sheetViews><sheetView workbookViewId="0" showGridLines="0">' . $panes . '</sheetView></sheetViews>'
          . '<sheetFormatPr defaultRowHeight="15"/>'
          . $cols
          . '<sheetData>' . $filas . '</sheetData>'
          . ($h['unir'] ? '<mergeCells count="' . count($h['unir']) . '">'
                . implode('', array_map(fn($r) => '<mergeCell ref="' . $r . '"/>', $h['unir']))
                . '</mergeCells>' : '')
          . (empty($h['val']) ? '' : '<dataValidations count="' . count($h['val']) . '">'
                . implode('', array_map(fn($v) =>
                    '<dataValidation type="' . $v['tipo'] . '" operator="' . $v['op'] . '"'
                    . ' allowBlank="0" showInputMessage="1" showErrorMessage="1" errorStyle="stop"'
                    . ' errorTitle="' . self::esc($v['titulo']) . '" error="' . self::esc($v['msg']) . '"'
                    . ' sqref="' . $v['ref'] . '">'
                    . '<formula1>' . self::esc($v['f1']) . '</formula1>'
                    . ($v['f2'] === '' ? '' : '<formula2>' . self::esc($v['f2']) . '</formula2>')
                    . '</dataValidation>', $h['val']))
                . '</dataValidations>')
          . '<pageMargins left="0.5" right="0.5" top="0.6" bottom="0.6" header="0.3" footer="0.3"/>'
          . '</worksheet>';
    }

    /**
     * ZIP escrito a mano, metodo 0 (guardado). No necesita zlib ni ZipArchive.
     * Formato: por cada archivo una cabecera local + los datos; al final el
     * directorio central y su cierre. Es el ZIP mas simple que existe y es valido.
     */
    private function zip(array $partes): string
    {
        $local = ''; $central = ''; $n = 0;
        foreach ($partes as $nombre => $datos) {
            $crc = crc32($datos);
            $len = strlen($datos);
            $off = strlen($local);
            $cab = "\x14\x00\x00\x00\x00\x00"           // version 2.0, sin flags, metodo 0
                 . "\x00\x00\x00\x00"                    // hora y fecha (0 = 1980, no importa)
                 . pack('VVV', $crc, $len, $len)
                 . pack('vv', strlen($nombre), 0);
            $local   .= "PK\x03\x04" . $cab . $nombre . $datos;
            $central .= "PK\x01\x02" . "\x14\x00" . $cab . pack('vvvVV', 0, 0, 0, 0, $off) . $nombre;
            $n++;
        }
        return $local . $central
             . "PK\x05\x06" . pack('vvvvVVv', 0, 0, $n, $n, strlen($central), strlen($local), 0);
    }

    /** Fecha -> numero de serie de Excel (1900). El 60 que no existe ya esta contemplado. */
    public static function fecha(DateTimeInterface $d): int {
        $base = new DateTimeImmutable('1899-12-30 00:00:00', new DateTimeZone('UTC'));
        $x = new DateTimeImmutable($d->format('Y-m-d') . ' 00:00:00', new DateTimeZone('UTC'));
        return (int)$base->diff($x)->days;
    }
}
