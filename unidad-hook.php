<?php
/**
 * unidad-hook.php — endpoint suelto para los eventos de unidad del SPA.
 * ---------------------------------------------------------------------------
 * En producción los eventos llegan por hook.php, que es el endpoint del webhook
 * de salida que ya existe. Esto se conserva aparte para poder probar la lógica
 * sin depender de que Bitrix dispare un evento real.
 * La lógica vive en unidadlib.php; aquí solo se valida el token.
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/unidadlib.php';

$esperado = (string)getenv('OUTBOUND_TOKEN');
$got = $_REQUEST['auth']['application_token'] ?? $_REQUEST['application_token'] ?? $_REQUEST['token'] ?? '';
if ($esperado === '' || !hash_equals($esperado, (string)$got)) {
    http_response_code(403); echo 'forbidden'; exit;
}

echo unidad_evento(
    (string)($_REQUEST['event'] ?? 'ONCRMDYNAMICITEMUPDATE_1072'),
    (int)($_REQUEST['data']['FIELDS']['ID'] ?? $_REQUEST['id'] ?? 0),
    (int)($_REQUEST['data']['FIELDS']['ENTITY_TYPE_ID'] ?? SPA_ENTITY)
);
