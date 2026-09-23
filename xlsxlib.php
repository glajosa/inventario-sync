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

    public function __construct(string $nombreHoja = 'Hoja1') {
        // Excel prohibe : \ / ? * [ ] y mas de 31 caracteres en el nombre de la hoja.
        $this->hoja = mb_substr(str_replace([':', '\\', '/', '?', '*', '[', ']'], '-', $nombreHoja), 0, 31);
    }

    /** Estilos. El indice es el que se escribe en cada celda (atributo s). */
    public const NORMAL = 0, TITULO = 1, CABECERA = 2, DINERO = 3, DINERO_B = 4,
                 ETIQUETA = 5, EDITABLE = 6, FECHA = 7, PIE = 8, AVISO_OK = 9,
                 AVISO_MAL = 10, SUBTITULO = 11, CENTRO = 12, PORCENTAJE = 13;

    public function texto(int $f, int $c, string $v, int $s = self::NORMAL): void { $this->set($f, $c, 'inlineStr', $v, $s); }
    public function numero(int $f, int $c, float $v, int $s = self::NORMAL): void { $this->set($f, $c, 'n', $v, $s); }
    /** La formula va SIN el '=' inicial. */
    public function formula(int $f, int $c, string $v, int $s = self::NORMAL): void { $this->set($f, $c, 'f', $v, $s); }
    public function ancho(int $c, float $w): void { $this->anchos[$c] = $w; }
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
        $partes = [
            '[Content_Types].xml'      => $this->contentTypes(),
            '_rels/.rels'              => $this->relsRaiz(),
            'xl/workbook.xml'          => $this->workbook(),
            'xl/_rels/workbook.xml.rels' => $this->relsWorkbook(),
            'xl/styles.xml'            => $this->styles(),
            'xl/worksheets/sheet1.xml' => $this->sheet(),
        ];
        return $this->zip($partes);
    }

    private function contentTypes(): string {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
          . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
          . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
          . '<Default Extension="xml" ContentType="application/xml"/>'
          . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
          . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
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
          . '<sheets><sheet name="' . self::esc($this->hoja) . '" sheetId="1" r:id="rId1"/></sheets>'
          /* fullCalcOnLoad: obliga a Excel a calcular TODAS las formulas al abrir. Sin
             esto, como no escribimos valores cacheados, algunas versiones muestran 0
             hasta que el usuario toca una celda. */
          . '<calcPr calcId="0" fullCalcOnLoad="1"/>'
          . '</workbook>';
    }
    private function relsWorkbook(): string {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
          . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
          . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
          . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
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
        $fonts = '<fonts count="6">'
          . '<font><sz val="11"/><name val="Calibri"/></font>'                                        // 0 normal
          . '<font><b/><sz val="15"/><color rgb="FF0C2C44"/><name val="Calibri"/></font>'             // 1 titulo
          . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'             // 2 cabecera
          . '<font><b/><sz val="11"/><color rgb="FF0C2C44"/><name val="Calibri"/></font>'             // 3 negrita
          . '<font><sz val="9"/><color rgb="FF5A6B7A"/><name val="Calibri"/></font>'                  // 4 pie
          . '<font><b/><sz val="12"/><color rgb="FF0C6C9C"/><name val="Calibri"/></font>'             // 5 subtitulo
          . '</fonts>';
        $fills = '<fills count="7">'
          . '<fill><patternFill patternType="none"/></fill>'
          . '<fill><patternFill patternType="gray125"/></fill>'
          . '<fill><patternFill patternType="solid"><fgColor rgb="FF0C2C44"/><bgColor indexed="64"/></patternFill></fill>'  // 2 cabecera
          . '<fill><patternFill patternType="solid"><fgColor rgb="FFFFF6D6"/><bgColor indexed="64"/></patternFill></fill>'  // 3 editable
          . '<fill><patternFill patternType="solid"><fgColor rgb="FFEAF3F8"/><bgColor indexed="64"/></patternFill></fill>'  // 4 suave
          . '<fill><patternFill patternType="solid"><fgColor rgb="FFDFF3E4"/><bgColor indexed="64"/></patternFill></fill>'  // 5 ok
          . '<fill><patternFill patternType="solid"><fgColor rgb="FFFBE3E3"/><bgColor indexed="64"/></patternFill></fill>'  // 6 mal
          . '</fills>';
        $borders = '<borders count="2">'
          . '<border><left/><right/><top/><bottom/><diagonal/></border>'
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
        ];
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
          . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
          . $numFmts . $fonts . $fills . $borders
          . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
          . '<cellXfs count="' . count($x) . '">' . implode('', $x) . '</cellXfs>'
          . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
          . '</styleSheet>';
    }

    private function sheet(): string
    {
        $cols = '';
        if ($this->anchos) {
            $cols = '<cols>';
            foreach ($this->anchos as $c => $w)
                $cols .= '<col min="' . $c . '" max="' . $c . '" width="' . $w . '" customWidth="1"/>';
            $cols .= '</cols>';
        }
        $panes = '';
        if ($this->congelar > 0) {
            $panes = '<pane ySplit="' . $this->congelar . '" topLeftCell="A' . ($this->congelar + 1)
                   . '" activePane="bottomLeft" state="frozen"/>'
                   . '<selection pane="bottomLeft" activeCell="A' . ($this->congelar + 1) . '" sqref="A' . ($this->congelar + 1) . '"/>';
        }
        $filas = '';
        for ($f = 1; $f <= $this->maxFila; $f++) {
            if (empty($this->celdas[$f])) continue;
            $cs = '';
            ksort($this->celdas[$f]);
            foreach ($this->celdas[$f] as $c => $cel) {
                $ref = self::ref($f, $c);
                $s = ' s="' . $cel['s'] . '"';
                if ($cel['t'] === 'inlineStr') {
                    $cs .= '<c r="' . $ref . '"' . $s . ' t="inlineStr"><is><t xml:space="preserve">'
                         . self::esc((string)$cel['v']) . '</t></is></c>';
                } elseif ($cel['t'] === 'f') {
                    // sin <v>: lo calcula Excel al abrir (ver fullCalcOnLoad)
                    $cs .= '<c r="' . $ref . '"' . $s . '><f>' . self::esc((string)$cel['v']) . '</f></c>';
                } else {
                    /* 🔴 12 DECIMALES, NO 6. Con 6 un porcentaje de 0,1000000901 -- el que
                       sale de una entrada de 11.100,01 sobre 111.000 -- se guardaba como
                       0,1 y la tabla salia UN CENTAVO por debajo de la del cotizador en
                       tres filas. Un centavo en un documento que firma un cliente es un
                       documento que no cuadra. */
                    $cs .= '<c r="' . $ref . '"' . $s . '><v>' . rtrim(rtrim(number_format((float)$cel['v'], 12, '.', ''), '0'), '.') . '</v></c>';
                }
            }
            $filas .= '<row r="' . $f . '">' . $cs . '</row>';
        }
        $dim = 'A1:' . self::ref(max(1, $this->maxFila), max(1, $this->maxCol));
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
          . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
          . '<dimension ref="' . $dim . '"/>'
          . '<sheetViews><sheetView workbookViewId="0" showGridLines="0">' . $panes . '</sheetView></sheetViews>'
          . '<sheetFormatPr defaultRowHeight="15"/>'
          . $cols
          . '<sheetData>' . $filas . '</sheetData>'
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
