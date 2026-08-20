<?php
/**
 * codigolib.php — el código comprimido de un grupo de unidades.
 *
 * Vivía dentro de campolib.php, que carga los hooks y habla con Bitrix. La
 * cotización necesita exactamente lo mismo —el cliente tiene que leer
 * "E-4-23-24" y no "E-4-23 + E-4-24"— y no puede cargar todo campolib para
 * usar veinte líneas de texto. Se saca acá para que las dos lean la MISMA
 * regla: si divergen, el título del deal y la cotización nombran distinto la
 * misma compra.
 */

declare(strict_types=1);

/**
 * La marca de UNIDADES SEPARADAS dentro del valor del campo Inventario.
 *
 * El campo guarda los IDs como texto ("581,623"). Cuando el vendedor declara que las
 * unidades van SEPARADAS —cada una su compra, su contrato y su deal— se le pega la
 * palabra al final: "581,623 separadas".
 *
 * Va en el mismo campo a proposito, y no en un campo nuevo del SPA, por dos razones:
 * los campos del SPA solo se crean por interfaz de administrador, y sobre todo porque
 * asi la marca VIAJA SOLA en el "Copiar" nativo de Bitrix, que es justo la operacion
 * donde importa. Todos los lectores que ya existen sacan los mismos IDs: ids_de()
 * descarta lo que no es numero, asi que agregar la palabra no rompe nada.
 *
 * Sin marca = FUSION, que es como se comporto siempre.
 */
const MARCA_SEPARADAS = 'separadas';

function unidades_separadas(?string $valor): bool {
    return $valor !== null && stripos($valor, MARCA_SEPARADAS) !== false;
}

/** El valor a escribir en el campo: los IDs y, si van separadas, la marca. */
function valor_campo(array $ids, bool $separadas): string {
    $v = implode(',', array_map('intval', $ids));
    return $separadas && $v !== '' ? $v . ' ' . MARCA_SEPARADAS : $v;
}

/**
 * Junta varios códigos de unidad en el formato comprimido que usan los títulos de
 * los deals: F-4-14 + F-4-15 -> "F-4-14-15".
 *
 * Se busca el prefijo que comparten (hasta el último guion común) y se pegan solo
 * los tramos que cambian. Si no comparten prefijo —dos unidades de torres
 * distintas— se listan separados por coma, porque comprimirlos daría un código
 * que no existe y sería peor que un título largo.
 */
function codigos_comprimidos(array $cods): string {
    $cods = array_values(array_filter(array_map('trim', $cods), fn($c) => $c !== ''));
    if (!$cods)            return '';
    if (count($cods) === 1) return $cods[0];

    $partes = array_map(fn($c) => explode('-', $c), $cods);
    $comun  = [];
    for ($i = 0; ; $i++) {
        $v = $partes[0][$i] ?? null;
        if ($v === null) break;
        foreach ($partes as $p) if (($p[$i] ?? null) !== $v) { $v = null; break; }
        if ($v === null) break;
        $comun[] = $v;
    }
    // hace falta prefijo común Y que a cada código le quede algo propio detrás
    $n = count($comun);
    if ($n === 0) return implode(', ', $cods);
    foreach ($partes as $p) if (count($p) <= $n) return implode(', ', $cods);

    $colas = [];
    foreach ($partes as $p) $colas[] = implode('-', array_slice($p, $n));
    return implode('-', $comun) . '-' . implode('-', $colas);
}
