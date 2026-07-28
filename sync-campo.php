<?php
/**
 * sync-campo.php — vuelve a sincronizar un deal a mano o como red de seguridad.
 * La lógica vive en campolib.php (compartida con guardar.php).
 *
 *   sync-campo.php?deal=<ID>
 */

declare(strict_types=1);
require_once __DIR__ . '/campolib.php';

$dealId = (int)($_REQUEST['deal'] ?? 0);
if ($dealId <= 0) { http_response_code(400); exit('falta deal'); }

$r = sincronizar_deal($dealId);
logline("deal=$dealId " . json_encode($r));

header('Content-Type: application/json; charset=utf-8');
echo json_encode($r);
