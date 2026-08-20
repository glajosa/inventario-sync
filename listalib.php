<?php
/**
 * listalib.php — las FAMILIAS de un proyecto y sus nombres comerciales.
 * ---------------------------------------------------------------------------
 * Un pipeline del SPA no vende una sola cosa: el 33 (Noral Plaza) vende locales
 * comerciales, oficinas y monoambientes al mismo tiempo, y cada familia lleva su
 * propia lista de precios porque el cliente que busca un local no quiere ver
 * departamentos.
 *
 * La familia se decide por el TIPO DE BIEN de la ficha, que es dato vivo del SPA,
 * y NO por el nombre de la categoria de la matriz —que lo escribimos nosotros y
 * puede quedar desalineado— ni por el codigo, porque en Noral Plaza los edificios
 * E y F tienen locales Y monoambientes con la misma letra.
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

/** Tipo de bien -> nombre con el que la familia sale en la lista del cliente. */
const LST_TIPOS = [
    1791 => 'Locales comerciales',
    1951 => 'Oficinas y consultorios',
    1793 => 'Monoambientes',
    1797 => 'Suites',
    1799 => 'Casas',
    1795 => 'Solares',
    1943 => 'Terrenos',
    1947 => 'Casa modelo',
    1801 => 'Parqueos',
];

/** Nombre por proyecto cuando el generico no sirve: en Apartments el tipo
 *  "Departamento" son departamentos de verdad, no monoambientes. */
const LST_NOMBRE_POR_CAT = [
    39 => [1793 => 'Departamentos'],
    49 => [1795 => 'Solares'],
    55 => [1799 => 'Casas', 1947 => 'Casa modelo'],
];

function lst_nombre_familia(int $cat, int $tipo): string {
    return LST_NOMBRE_POR_CAT[$cat][$tipo] ?? (LST_TIPOS[$tipo] ?? "Tipo $tipo");
}

/**
 * Las familias que este proyecto tiene DISPONIBLES hoy, con cuántas y desde/hasta
 * qué precio. Solo disponibles: una familia agotada no lleva lista de precios.
 *
 * @return array<int, array{tipo:int,nombre:string,n:int,min:float,max:float}>
 */
function lst_familias(array $unidades, int $cat): array {
    $f = [];
    foreach ($unidades as $d) {
        if (($d['etapa'] ?? '') !== 'DISPONIBLE') continue;
        $pvp = (float)($d['pvp'] ?? 0);
        if ($pvp <= 0) continue;                      // sin precio no entra a una lista
        $t = (int)($d['tipo'] ?? 0);
        if (!isset($f[$t])) {
            $f[$t] = ['tipo' => $t, 'nombre' => lst_nombre_familia($cat, $t),
                      'n' => 0, 'min' => $pvp, 'max' => $pvp];
        }
        $f[$t]['n']++;
        $f[$t]['min'] = min($f[$t]['min'], $pvp);
        $f[$t]['max'] = max($f[$t]['max'], $pvp);
    }
    // De la familia más grande a la más chica: el que abre la portada ve primero
    // la lista que más se usa.
    uasort($f, fn($a, $b) => $b['n'] <=> $a['n']);
    return $f;
}
