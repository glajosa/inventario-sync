<?php
/**
 * sync-campo.php — vuelve a sincronizar un deal a mano o como red de seguridad.
 * La lógica vive en campolib.php (compartida con guardar.php).
 *
 *   sync-campo.php?deal=<ID>
 */

declare(strict_types=1);
require_once __DIR__ . '/campolib.php';

// Este endpoint ESCRIBE en Bitrix (ata/suelta unidades y mueve stages) y estaba
// abierto: cualquiera con la URL podía forzar la sincronización de cualquier
// deal. Ahora pide el token, igual que el resto.
$esperado = (string)getenv('OUTBOUND_TOKEN');
if ($esperado === '' || !hash_equals($esperado, (string)($_REQUEST['token'] ?? ''))) {
    http_response_code(403); exit('forbidden');
}

$dealId = (int)($_REQUEST['deal'] ?? 0);
if ($dealId <= 0) { http_response_code(400); exit('falta deal'); }

$r = sincronizar_deal($dealId);
logline("deal=$dealId " . json_encode($r));

// Mismo motivo que en guardar.php: este camino tampoco mueve el deal.
require_once __DIR__ . '/historialib.php';
hist_intentar((string)$dealId, null, 'SYNCCAMPO');

header('Content-Type: application/json; charset=utf-8');
echo json_encode($r);
