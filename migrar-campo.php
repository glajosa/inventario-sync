<?php
/**
 * migrar-campo.php — pasa los enlaces de los campos viejos al campo "Inventario".
 * ---------------------------------------------------------------------------
 * Los 4 campos anteriores (Inventario nativo + Inventario 2/3/4) tienen ~778
 * deals con unidades atadas. Esto copia esos IDs al campo nuevo, en el mismo
 * orden (nativo primero), SIN borrar los viejos: quedan intactos como respaldo.
 *
 * Es idempotente: si el campo nuevo ya coincide, no escribe.
 *
 *   ?token=...            -> SIMULACRO, no escribe nada (por defecto)
 *   ?token=...&aplicar=1  -> escribe de verdad
 *   &limite=50            -> tope de deals a procesar (para probar de a poco)
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);
set_time_limit(0);
require_once __DIR__ . '/campolib.php';

header('Content-Type: text/plain; charset=utf-8');

$esperado = (string)getenv('OUTBOUND_TOKEN');
if ($esperado === '' || !hash_equals($esperado, (string)($_GET['token'] ?? ''))) {
    http_response_code(403); exit('forbidden');
}

$aplicar = !empty($_GET['aplicar']);
$limite  = (int)($_GET['limite'] ?? 0);

const VIEJOS = ['UF_CRM_DEAL_1784994996', 'UF_CRM_DEAL_1784995021', 'UF_CRM_DEAL_1784995044'];

echo $aplicar ? "MODO: APLICAR (escribe)\n\n" : "MODO: SIMULACRO (no escribe nada)\n\n";

$revisados = 0; $porMigrar = 0; $yaIguales = 0; $escritos = 0; $errores = 0;
$ejemplos = [];

$start = 0;
do {
    $r = bx('crm.deal.list', [
        'filter' => ['CATEGORY_ID' => CLIENTES_CAT],
        'select' => array_merge(['ID', 'PARENT_ID_1072', CAMPO_NUEVO], VIEJOS),
        'start'  => $start,
    ]);
    if (!$r['ok']) { echo "ERROR listando: {$r['error']}\n"; break; }

    foreach (($r['result'] ?? []) as $d) {
        $revisados++;

        // orden: primero la relación nativa (unidad 1), luego Inventario 2/3/4
        $ids = [];
        $nat = (int)($d['PARENT_ID_1072'] ?? 0);
        if ($nat > 0) $ids[$nat] = true;
        foreach (VIEJOS as $f) {
            $v = (int)($d[$f] ?? 0);
            if ($v > 0) $ids[$v] = true;
        }
        if (!$ids) continue;                       // este deal no tiene nada atado

        $destino = implode(',', array_keys($ids));
        $actual  = trim((string)($d[CAMPO_NUEVO] ?? ''));
        if ($actual === $destino) { $yaIguales++; continue; }

        $porMigrar++;
        if (count($ejemplos) < 5) $ejemplos[] = "deal {$d['ID']}: '$actual' -> '$destino'";

        if ($aplicar) {
            $u = bx('crm.deal.update', ['id' => $d['ID'], 'fields' => [CAMPO_NUEVO => $destino]]);
            if ($u['ok']) $escritos++; else { $errores++; echo "  ERR deal {$d['ID']}: {$u['error']}\n"; }
        }

        if ($limite && $porMigrar >= $limite) { $start = null; break; }
    }

    if ($start === null) break;
    $start = $r['next'] ?? null;
} while ($start !== null && $start !== '');

echo "deals revisados      : $revisados\n";
echo "ya coincidian        : $yaIguales\n";
echo "a migrar             : $porMigrar\n";
if ($aplicar) {
    echo "escritos             : $escritos\n";
    echo "errores              : $errores\n";
}
echo "\nejemplos:\n";
foreach ($ejemplos as $e) echo "  $e\n";
if (!$aplicar) echo "\n(simulacro: no se escribió nada. Agregar &aplicar=1 para ejecutar)\n";

logline('MIGRAR ' . ($aplicar ? 'aplicado' : 'simulacro')
      . " revisados=$revisados aMigrar=$porMigrar escritos=$escritos errores=$errores");
