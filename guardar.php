<?php
/**
 * guardar.php — guarda la selección del campo "Inventario".
 * ---------------------------------------------------------------------------
 * Por qué existe: el campo se dibuja en un iframe de NUESTRO dominio, y desde
 * ahí el navegador NO deja llamar al API de Bitrix (dominios distintos, CORS).
 * Así que el campo llama aquí —mismo dominio, sin bloqueo— y este servidor
 * escribe en Bitrix con el webhook (servidor a servidor).
 *
 * Además de guardar el valor, deja los enlaces y los stages al día llamando a
 * sincronizar_deal(), que es lo mismo que hacía el sincronizador de los campos
 * anteriores.
 *
 * Seguridad: el campo trae una firma (HMAC del id del deal con OUTBOUND_TOKEN)
 * que se genera al renderizar. Sin firma válida no se escribe nada.
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);
require_once __DIR__ . '/campolib.php';

header('Content-Type: application/json; charset=utf-8');

$dealId = (int)($_POST['deal'] ?? $_GET['deal'] ?? 0);
$valor  = (string)($_POST['valor'] ?? $_GET['valor'] ?? '');
$firma  = (string)($_POST['firma'] ?? $_GET['firma'] ?? '');

if ($dealId <= 0) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'falta deal']); exit; }

$secreto = (string)getenv('OUTBOUND_TOKEN');
if ($secreto === '' || !hash_equals(hash_hmac('sha256', (string)$dealId, $secreto), $firma)) {
    http_response_code(403);
    logline("deal=$dealId FIRMA INVALIDA");
    echo json_encode(['ok' => false, 'error' => 'firma invalida']);
    exit;
}

// solo IDs numéricos: nunca se escribe en el deal lo que llegue tal cual
$ids = [];
foreach (preg_split('/[,;\s]+/', $valor) as $x) {
    $x = trim($x);
    if ($x !== '' && ctype_digit($x) && (int)$x > 0) $ids[] = (int)$x;
}
$limpio = implode(',', array_values(array_unique($ids)));

$up = bx('crm.deal.update', ['id' => $dealId, 'fields' => [CAMPO_NUEVO => $limpio]]);
if (!$up['ok']) {
    logline("deal=$dealId ERROR al guardar: {$up['error']}");
    echo json_encode(['ok' => false, 'error' => $up['error']]);
    exit;
}

// dejar dependencia, responsable/cliente y stage al día
$r = sincronizar_deal($dealId);
logline("deal=$dealId guardado=[$limpio] sync=" . json_encode($r));

echo json_encode(['ok' => true, 'guardado' => $limpio, 'sync' => $r]);
