<?php
/**
 * diag.php — TEMPORAL. Comprueba si crm.item.list con `select` explícito
 * devuelve los ids en null (bug ya visto en sincronizar_deal). Se borra después.
 */
declare(strict_types=1);
require_once __DIR__ . '/campolib.php';
header('Content-Type: text/plain; charset=utf-8');

$esperado = (string)getenv('OUTBOUND_TOKEN');
if ($esperado === '' || !hash_equals($esperado, (string)($_GET['token'] ?? ''))) {
    http_response_code(403); exit('forbidden');
}

$deal = (int)($_GET['deal'] ?? 0);
if ($deal <= 0) exit("uso: ?token=...&deal=ID\n");

$a = bx('crm.item.list', ['entityTypeId' => SPA_ENTITY, 'filter' => ['parentId2' => $deal], 'select' => ['id']]);
$b = bx('crm.item.list', ['entityTypeId' => SPA_ENTITY, 'filter' => ['parentId2' => $deal]]);

echo "CON select=['id']:\n";
foreach (($a['result']['items'] ?? []) as $it) echo '  id=' . var_export($it['id'] ?? null, true) . "\n";
echo '  ok=' . var_export($a['ok'], true) . " err=" . ($a['error'] ?? '-') . "\n\n";

echo "SIN select:\n";
foreach (($b['result']['items'] ?? []) as $it) {
    echo '  id=' . var_export($it['id'] ?? null, true)
       . ' parentId2=' . var_export($it['parentId2'] ?? null, true)
       . ' stageId=' . var_export($it['stageId'] ?? null, true) . "\n";
}
echo '  ok=' . var_export($b['ok'], true) . " err=" . ($b['error'] ?? '-') . "\n";

echo "\nunidades_asignables() sobre esos ids, para este mismo deal:\n";
$ids = [];
foreach (($b['result']['items'] ?? []) as $it) if (!empty($it['id'])) $ids[] = (int)$it['id'];
var_export(unidades_asignables($ids, $deal));
echo "\n";
