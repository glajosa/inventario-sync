<?php
/**
 * prueba-etapa.php — TEMPORAL. Mueve de etapa SOLO el deal de prueba, para
 * comprobar el disparador de RESERVAS CAIDAS. Se borra al terminar.
 * Rechaza cualquier deal que no sea el de prueba, aunque traiga el token.
 */
declare(strict_types=1);
require_once __DIR__ . '/campolib.php';
header('Content-Type: text/plain; charset=utf-8');

$esperado = (string)getenv('OUTBOUND_TOKEN');
if ($esperado === '' || !hash_equals($esperado, (string)($_GET['token'] ?? ''))) {
    http_response_code(403); exit('forbidden');
}

const DEAL_PRUEBA = 400821;   // "PRUEBA INVENTARIO - no usar"

$deal  = (int)($_GET['deal'] ?? 0);
$etapa = (string)($_GET['etapa'] ?? '');
if ($deal !== DEAL_PRUEBA) exit("solo el deal de prueba " . DEAL_PRUEBA . "\n");
if (!preg_match('/^C44:[A-Z0-9_]+$/', $etapa)) exit("etapa invalida\n");

$r = bx('crm.deal.update', ['id' => $deal, 'fields' => ['STAGE_ID' => $etapa]]);
logline("PRUEBA etapa deal=$deal -> $etapa " . json_encode($r));
echo json_encode($r), "\n";
