<?php
/**
 * estado.php — disponibilidad al día de las unidades, en JSON compacto.
 * ---------------------------------------------------------------------------
 * El campo trae el catálogo impreso en el HTML al cargar la página. Si algo
 * cambia después (otro vendedor ata una unidad, o el propio usuario quita una),
 * la lista quedaba vieja hasta recargar la página.
 *
 * El campo pide esto al abrir el desplegable y corrige las filas al momento.
 * Sale del caché en disco, así que no le pega al API de Bitrix.
 *
 * Formato: {"1287":[0,"RESERVADO"], "1289":[1,"DISPONIBLE"], ...}
 *          [libre(0|1), nombre del stage]
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

$DATA_DIR = getenv('DATA_DIR') ?: '/data';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$j = json_decode((string)@file_get_contents($DATA_DIR . '/selector_cache.json'), true);
if (!is_array($j) || empty($j['units'])) { echo '{}'; exit; }

$out = [];
foreach ($j['units'] as $u) {
    $libre = ((string)($u['stage'] ?? '') === 'DISPONIBLE' && empty($u['dealId'])) ? 1 : 0;
    $out[(string)$u['id']] = [$libre, (string)($u['stage'] ?? '')];
}
echo json_encode($out);
