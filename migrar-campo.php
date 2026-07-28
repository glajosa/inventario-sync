<?php
/**
 * migrar-campo.php — pasa los enlaces de los campos viejos al campo "Inventario".
 * ---------------------------------------------------------------------------
 * Los 4 campos anteriores (Inventario nativo + Inventario 2/3/4) tienen ~778
 * deals con unidades atadas. Esto copia esos IDs al campo nuevo, en el mismo
 * orden (nativo primero), y DESPUÉS vacía los viejos.
 *
 * Por qué se vacían y no se dejan de respaldo: si quedan llenos, el deal tiene la
 * unidad atada por dos vías a la vez (el campo nuevo y la nativa), la unidad
 * aparece duplicada, y sobre todo al quitar una unidad del campo nuevo el campo
 * viejo la seguiría reclamando y el barrido la volvería a atar. El respaldo va a
 * un archivo JSON en DATA_DIR antes de tocar nada.
 *
 * Es idempotente: si el campo nuevo ya coincide y los viejos están vacíos, no escribe.
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
$respaldo = [];                       // dealId => valores viejos, antes de tocar nada
$rutaResp = ($DATA_DIR ?: '/data') . '/migracion_campos_viejos.json';

$start = 0;
do {
    $r = bx('crm.deal.list', [
        'filter' => ['CATEGORY_ID' => CLIENTES_CAT],
        'select' => array_merge(['ID', 'PARENT_ID_1072', CAMPO_NUEVO], VIEJOS),
        // orden fijo por ID: la migración va escribiendo en los mismos deals que
        // pagina, y sin orden explícito la paginación puede saltarse deals.
        'order'  => ['ID' => 'ASC'],
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

        // respaldo del estado anterior ANTES de escribir, por si hay que volver
        $respaldo[(string)$d['ID']] = [
            'PARENT_ID_1072' => $d['PARENT_ID_1072'] ?? null,
            'UF_CRM_DEAL_1784994996' => $d['UF_CRM_DEAL_1784994996'] ?? null,
            'UF_CRM_DEAL_1784995021' => $d['UF_CRM_DEAL_1784995021'] ?? null,
            'UF_CRM_DEAL_1784995044' => $d['UF_CRM_DEAL_1784995044'] ?? null,
            'nuevo_antes' => $actual,
        ];

        if ($aplicar) {
            // Una sola escritura: pone el campo nuevo y vacía los 4 viejos.
            // Van juntos a propósito: si se pusiera el nuevo y el vaciado fallara
            // aparte, el deal quedaría con la unidad reclamada por dos vías.
            $u = bx('crm.deal.update', ['id' => $d['ID'], 'fields' => [
                CAMPO_NUEVO              => $destino,
                'PARENT_ID_1072'         => '',
                'UF_CRM_DEAL_1784994996' => '',
                'UF_CRM_DEAL_1784995021' => '',
                'UF_CRM_DEAL_1784995044' => '',
            ]]);
            if ($u['ok']) $escritos++; else { $errores++; echo "  ERR deal {$d['ID']}: {$u['error']}\n"; }
        }

        if ($limite && $porMigrar >= $limite) { $start = null; break; }
    }

    if ($start === null) break;
    $start = $r['next'] ?? null;
} while ($start !== null && $start !== '');

// El respaldo se guarda siempre, también en simulacro: así se puede revisar el
// archivo antes de aplicar.
if ($respaldo) {
    $previo = json_decode((string)@file_get_contents($rutaResp), true);
    if (is_array($previo)) $respaldo += $previo;      // no pisar respaldos de tandas anteriores
    @file_put_contents($rutaResp, json_encode($respaldo, JSON_PRETTY_PRINT));
    echo "respaldo             : $rutaResp (" . count($respaldo) . " deals)\n";
}

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
