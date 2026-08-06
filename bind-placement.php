<?php
/**
 * bind-placement.php — registra el botón "Registrar llamada" en la barra de
 * actividades del deal (placement CRM_DEAL_ACTIVITY_TIMELINE_MENU).
 *
 * SE CORRE UNA SOLA VEZ a mano (?token=OUTBOUND_TOKEN), no es un endpoint que
 * Bitrix llame ni un cron. Usa la app local (OAuth) porque placement.bind NO
 * funciona con webhook entrante (WRONG_AUTH_TYPE, verificado).
 */
declare(strict_types=1);
require_once __DIR__ . '/appauth.php';

header('Content-Type: application/json; charset=utf-8');

$got = (string)($_GET['token'] ?? '');
$expect = (string)getenv('OUTBOUND_TOKEN');
if ($expect === '' || !hash_equals($expect, $got)) { http_response_code(403); exit('forbidden'); }

$handler = 'https://galjosa-inventario-sync.pwluu1.easypanel.host/llamada.php';

// placement.get (no placement.list) es el que trae los handlers YA enlazados
// por esta app para un placement dado — placement.list solo lista los tipos
// de placement que EXISTEN en Bitrix, no lo que esta app tiene registrado.
$actual = app_bx('placement.get', ['PLACEMENT' => 'CRM_DEAL_ACTIVITY_TIMELINE_MENU']);
$yaEsta = false;
if ($actual['ok'] && is_array($actual['result'])) {
    foreach ($actual['result'] as $p) {
        if (($p['handler'] ?? '') === $handler) { $yaEsta = true; break; }
    }
}

if ($yaEsta && ($_GET['forzar'] ?? '') !== '1') {
    echo json_encode(['ok' => true, 'accion' => 'ya-estaba', 'nota' => 'usa ?forzar=1 para re-enlazar']);
    exit;
}

$r = app_bx('placement.bind', [
    'PLACEMENT' => 'CRM_DEAL_ACTIVITY_TIMELINE_MENU',
    'HANDLER'   => $handler,
    'TITLE'     => 'Registrar llamada',
]);

echo json_encode(['ok' => $r['ok'], 'resultado' => $r]);
