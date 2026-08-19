<?php
/**
 * historia.php — dispara a mano la historia de un deal, y sirve para probar.
 *
 *   ?token=...&deal=304914            genera y notifica
 *   ?token=...&deal=304914&seco=1     dice QUE haria, sin generar ni notificar
 *   ?token=...&deal=304914&forzar=1   regenera aunque la libreta diga que ya se hizo
 *   ?token=...&libreta=1              lo ya generado
 */
declare(strict_types=1);
require_once __DIR__ . '/campolib.php';     // bx(), logline(), NORAL_PROY
require_once __DIR__ . '/stagelib.php';     // units_of_clientes_deal()
require_once __DIR__ . '/historialib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$esperado = (string)getenv('OUTBOUND_TOKEN');
if ($esperado === '' || !hash_equals($esperado, (string)($_GET['token'] ?? ''))) {
    http_response_code(403); exit(json_encode(['ok' => false, 'error' => 'forbidden']));
}

if (isset($_GET['libreta'])) {
    exit(json_encode(['ok' => true, 'libreta' => hist_libreta()],
                     JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

$dealId = trim((string)($_GET['deal'] ?? ''));
if ($dealId === '') exit(json_encode(['ok' => false, 'error' => 'falta &deal=']));

// Corrida en seco: NO genera, NO notifica y NO toca la libreta. Solo cuenta qué
// encontró. Una corrida en seco que muta estado no es una corrida en seco.
if (!empty($_GET['seco'])) {
    $g = bx('crm.deal.get', ['id' => $dealId]);
    if (!($g['ok'] ?? false)) exit(json_encode(['ok' => false, 'error' => 'no se pudo leer el deal']));
    $d = $g['result'];
    $ids = units_of_clientes_deal($dealId, $d);
    $unis = [];
    foreach ($ids as $uid) {
        $it = bx('crm.item.get', ['entityTypeId' => 1072, 'id' => $uid]);
        if (!($it['ok'] ?? false)) continue;
        $u = $it['result']['item'] ?? [];
        $proy = NORAL_PROY[(int)($u['categoryId'] ?? 0)] ?? null;
        $unis[] = ['id' => $uid, 'cod' => trim(explode('(', (string)($u['title'] ?? ''))[0]),
                   'proyecto' => $proy ?? '(sin plano en el generador)'];
    }
    $lib = hist_libreta();
    exit(json_encode([
        'ok' => true, 'seco' => true,
        'deal' => ['id' => $dealId, 'titulo' => $d['TITLE'] ?? '',
                   'categoria' => (int)($d['CATEGORY_ID'] ?? 0),
                   'etapa' => $d['STAGE_ID'] ?? '',
                   'es_reserva' => (string)($d['STAGE_ID'] ?? '') === HIST_ETAPA_RESERVA],
        'unidades' => $unis,
        'ya_en_libreta' => $lib[$dealId] ?? null,
        'avisaria_a' => (int)(getenv('HISTORIA_AVISAR_A') ?: 0),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

echo json_encode(hist_al_reservar($dealId, null, !empty($_GET['forzar'])),
                 JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
