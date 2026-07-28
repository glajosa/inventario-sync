<?php
/**
 * diag.php — TEMPORAL, solo para la auditoría previa a la migración.
 * Lector de solo lectura, protegido por token y con lista blanca de métodos.
 * NO escribe nada en Bitrix. SE BORRA cuando termina la auditoría.
 */
declare(strict_types=1);
require_once __DIR__ . '/campolib.php';
header('Content-Type: text/plain; charset=utf-8');

$esperado = (string)getenv('OUTBOUND_TOKEN');
if ($esperado === '' || !hash_equals($esperado, (string)($_GET['token'] ?? ''))) {
    http_response_code(403); exit('forbidden');
}

// Solo lectura: cualquier otro método se rechaza aquí, no en Bitrix.
const PERMITIDOS = ['crm.deal.list', 'crm.deal.get', 'crm.item.list', 'crm.item.get', 'crm.deal.fields'];

$m = (string)($_GET['m'] ?? '');
if ($m !== '') {
    if (!in_array($m, PERMITIDOS, true)) { http_response_code(400); exit("metodo no permitido: $m\n"); }
    $p = json_decode((string)($_GET['p'] ?? '{}'), true);
    if (!is_array($p)) exit("p no es JSON valido\n");
    $r = bx($m, $p);
    echo json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
    exit;
}

// Modo "select": compara crm.item.list con y sin `select` para un deal.
$deal = (int)($_GET['deal'] ?? 0);
if ($deal <= 0) exit("uso: ?token=...&deal=ID   |   ?token=...&m=<metodo>&p=<json>\n");

$a = bx('crm.item.list', ['entityTypeId' => SPA_ENTITY, 'filter' => ['parentId2' => $deal], 'select' => ['id']]);
$b = bx('crm.item.list', ['entityTypeId' => SPA_ENTITY, 'filter' => ['parentId2' => $deal]]);

echo "CON select=['id']  (" . count($a['result']['items'] ?? []) . " items):\n";
foreach (($a['result']['items'] ?? []) as $it) echo '  id=' . var_export($it['id'] ?? null, true) . "\n";

echo "\nSIN select  (" . count($b['result']['items'] ?? []) . " items):\n";
$ids = [];
foreach (($b['result']['items'] ?? []) as $it) {
    echo '  id=' . var_export($it['id'] ?? null, true)
       . ' parentId2=' . var_export($it['parentId2'] ?? null, true)
       . ' stageId=' . var_export($it['stageId'] ?? null, true) . "\n";
    if (!empty($it['id'])) $ids[] = (int)$it['id'];
}

echo "\nunidades_asignables(" . implode(',', $ids) . ", deal=$deal): ";
echo implode(',', unidades_asignables($ids, $deal)), "\n";
