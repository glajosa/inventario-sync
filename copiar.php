<?php
/**
 * copiar.php — copia ESTE negocio dejandole otra unidad.
 * ---------------------------------------------------------------------------
 * El caso: el cliente reservo una unidad, el deal ya esta en CLIENTES, y despues
 * escoge otra. Antes habia que copiar el deal a mano con el boton de Bitrix, que
 * arrastra la unidad del original, y despues arreglar los dos campos — que es
 * justo donde se rompia.
 *
 * Desde el selector del campo se elige la unidad y esto crea la copia con ella. El
 * deal original NO se toca: su unidad sigue siendo suya.
 *
 * Solo unidades LIBRES: si la unidad ya es de otro negocio no se roba, se avisa.
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

require_once __DIR__ . '/copiarlib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$dealId = (int)($_POST['deal'] ?? 0);
$unitId = (int)($_POST['unidad'] ?? 0);
$firma  = (string)($_POST['firma'] ?? '');

function salir(array $j, int $code = 200): void { http_response_code($code); echo json_encode($j); exit; }

if ($dealId <= 0 || $unitId <= 0) salir(['ok' => false, 'error' => 'faltan datos'], 400);

$secreto = (string)getenv('OUTBOUND_TOKEN');
if ($secreto === '' || !hash_equals(hash_hmac('sha256', (string)$dealId, $secreto), $firma)) {
    logline("COPIAR deal=$dealId FIRMA INVALIDA");
    salir(['ok' => false, 'error' => 'firma invalida'], 403);
}

$g = bx('crm.deal.get', ['id' => $dealId]);
if (!$g['ok'] || empty($g['result'])) salir(['ok' => false, 'error' => 'el negocio no existe'], 404);
$deal = $g['result'];

// Pipelines donde el campo tiene sentido. La copia hereda la categoria y la etapa del
// original, asi que nace donde estaba el original y no hay que decidir nada.
$cat = (int)($deal['CATEGORY_ID'] ?? 0);
if (!in_array($cat, [PROSPECTOS_CAT, CLIENTES_CAT], true))
    salir(['ok' => false, 'error' => 'este negocio no lleva unidades']);

// La unidad tiene que estar LIBRE. Copiar el deal no es motivo para quitarle una
// unidad a otra venta: si ya tiene dueño se dice de quien y no se toca nada.
$q = bx('crm.item.get', ['entityTypeId' => SPA_ENTITY, 'id' => $unitId]);
if (!$q['ok']) salir(['ok' => false, 'error' => 'la unidad no existe'], 404);
$it    = $q['result']['item'] ?? $q['result'];
$dueno = (int)($it['parentId2'] ?? 0);
if ($dueno > 0 && $dueno !== $dealId) {
    $cod = codigo_activo((string)($it['title'] ?? ''));
    salir(['ok' => false, 'error' => "$cod ya es del negocio #$dueno"]);
}
if ($dueno === $dealId)
    salir(['ok' => false, 'error' => 'esa unidad ya es de este negocio']);

$r = copiar_deal_con_unidad($dealId, $deal, $unitId);
if (empty($r['id'])) salir(['ok' => false, 'error' => (string)($r['error'] ?? 'no se pudo copiar')]);

$cod = codigo_activo((string)($it['title'] ?? ''));
logline("COPIAR deal=$dealId -> {$r['id']} con u=$unitId ($cod) desde el selector");
salir(['ok' => true, 'deal' => (int)$r['id'], 'codigo' => $cod,
       'url' => '/crm/deal/details/' . (int)$r['id'] . '/',
       'aviso' => $r['error'] ?? null]);
