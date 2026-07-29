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
// Pipelines donde el campo tiene sentido:
//   PROSPECTOS(28) -> se aparta al llegar a RESERVA (ver candado de etapa abajo).
//   CLIENTES(44)   -> la reserva oficial (ATA de verdad).
// Cobranzas(48) y el resto no: 48 es read-only por regla del negocio.
$cat      = (int)($g['result']['CATEGORY_ID'] ?? -1);
$contacto = (int)($g['result']['CONTACT_ID'] ?? 0);
$stage    = (string)($g['result']['STAGE_ID'] ?? '');
if ($cat !== CLIENTES_CAT && $cat !== PROSPECTOS_CAT) {
    logline("deal=$dealId RECHAZADO: pipeline $cat no soportado");
    echo json_encode(['ok' => false,
        'error' => 'Las unidades solo se eligen en Prospectos Ventas o Clientes']); exit;
}

// CANDADO DE ETAPA (28) — jul-2026. Antes se podía apartar desde CUALQUIER etapa
// del 28: dos vendedores nombraban la misma unidad en negociaciones que todavía
// no eran nada, y la primera en llegar la dejaba trabada para el resto.
// Vaciar el campo SÍ se permite en cualquier etapa: es la válvula para liberar
// una unidad mal apartada sin tener que mover el deal de etapa.
// Este es el candado de verdad; el de field.php es solo la capa visual.
if ($cat === PROSPECTOS_CAT && $limpio !== '' && $stage !== etapa_28_reserva()) {
    logline("deal=$dealId RECHAZADO: etapa $stage no es RESERVA (28)");
    echo json_encode(['ok' => false,
        'error' => 'En Prospectos Ventas la unidad solo se elige en la etapa RESERVA']); exit;
}

// Las unidades deben existir y no estar tomadas por OTRA venta (anti doble-venta).
// Se pasa el contacto para que la copia en CLIENTES pueda tomar la unidad que su
// propio deal de Prospectos apartó.
if ($ids) {
    $libres = unidades_asignables($ids, $dealId, $contacto);
    $malas  = array_values(array_diff($ids, $libres));
    if ($malas) {
        logline("deal=$dealId RECHAZADO unidades no asignables: " . implode(',', $malas));
        echo json_encode(['ok' => false,
            'error' => 'Unidad ya apartada o no disponible: ' . implode(', ', $malas)]);
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
