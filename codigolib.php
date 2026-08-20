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
