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
$ids    = array_values(array_unique($ids));
$limpio = implode(',', $ids);

// El pipeline se valida ANTES de escribir. Antes se escribía primero y el
// sincronizador rechazaba después: el campo quedaba con un valor que nunca se
// convertía en enlace, y la respuesta decía ok:true (falso éxito).
$g = bx('crm.deal.get', ['id' => $dealId]);
if (!$g['ok']) {
    logline("deal=$dealId no existe: {$g['error']}");
    echo json_encode(['ok' => false, 'error' => 'el deal no existe']); exit;
}
if ((int)($g['result']['CATEGORY_ID'] ?? -1) !== CLIENTES_CAT) {
    logline("deal=$dealId RECHAZADO: no es CLIENTES(44)");
    echo json_encode(['ok' => false, 'error' => 'Las unidades solo se atan en el pipeline CLIENTES']); exit;
}

// Las unidades deben existir y no estar tomadas por OTRO deal (anti doble-venta).
if ($ids) {
    $libres = unidades_asignables($ids, $dealId);
    $malas  = array_values(array_diff($ids, $libres));
    if ($malas) {
        logline("deal=$dealId RECHAZADO unidades no asignables: " . implode(',', $malas));
        echo json_encode(['ok' => false,
            'error' => 'Unidad no disponible o inexistente: ' . implode(', ', $malas)]);
        exit;
    }
}

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
