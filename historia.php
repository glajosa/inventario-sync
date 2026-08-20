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

/**
 * Barrido: que deals de RESERVA se quedaron sin historia.
 *
 *   ?pendientes=1              solo LISTA (no genera nada)
 *   ?pendientes=1&generar=1    genera las que faltan
 *   ?pendientes=1&dias=3       en vez de hoy, los ultimos 3 dias
 *
 * Existe porque el disparador vivio un tiempo solo en el evento del deal, y todo
 * lo que se ato desde el campo Inventario quedo sin imagen. Un barrido reparador
 * es mas barato que ir deal por deal a mano, y sirve igual si el generador se cae
 * un rato.
 *
 * 🔴 El corte del dia se hace en hora de ECUADOR y del lado de PHP, no con un
 * filtro de fecha de Bitrix: el portal corre en otra zona y su "hoy" esta 8 h
 * adelantado, asi que despues de las 4 PM el filtro deja fuera lo de hoy mismo.
 * Se compara MOVED_TIME, que es cuando el deal ENTRO a la etapa -- DATE_MODIFY
 * cambia por cualquier edicion y traeria deals que entraron hace semanas.
 */
if (!empty($_GET['pendientes'])) {
    $TZ    = new DateTimeZone('America/Guayaquil');
    $dias  = max(0, min(30, (int)($_GET['dias'] ?? 0)));
    $desde = (new DateTime('now', $TZ))->setTime(0, 0, 0)->modify("-{$dias} day");

    $deals = [];
    $start = 0;
    do {
        $r = bx('crm.deal.list', [
            'filter' => ['CATEGORY_ID' => HIST_CAT_CLIENTES, 'STAGE_ID' => HIST_ETAPA_RESERVA],
            'select' => ['ID', 'TITLE', 'STAGE_ID', 'MOVED_TIME', 'DATE_MODIFY'],
            'order'  => ['ID' => 'DESC'],
            'start'  => $start,
        ]);
        if (!($r['ok'] ?? false)) exit(json_encode(['ok' => false, 'error' => 'no se pudo listar']));
        foreach (($r['result'] ?? []) as $d) $deals[] = $d;
        $start = (int)($r['next'] ?? 0);
    } while ($start > 0 && count($deals) < 500);

    $lib = hist_libreta();
    $delDia = []; $faltan = []; $hechas = [];
    foreach ($deals as $d) {
        $mv = (string)($d['MOVED_TIME'] ?? $d['DATE_MODIFY'] ?? '');
        if ($mv === '') continue;
        try { $cuando = (new DateTime($mv))->setTimezone($TZ); } catch (Throwable $e) { continue; }
        if ($cuando < $desde) continue;
        $fila = ['deal' => (string)$d['ID'], 'titulo' => (string)($d['TITLE'] ?? ''),
                 'entro' => $cuando->format('d/m H:i')];
        $delDia[] = $fila;
        // 🔴 "tiene entrada en la libreta" NO es lo mismo que "su historia esta al
        // dia": el deal 404139 entro con F-1-3, se le genero, y despues le cambiaron
        // la unidad a E-4-20 — libreta llena y ninguna imagen de la unidad actual.
        // La huella de la libreta se compara contra las unidades de AHORA, y eso lo
        // hace hist_al_reservar(), asi que aca solo se marca lo evidente.
        if (isset($lib[(string)$d['ID']])) $hechas[] = $fila; else $faltan[] = $fila;
    }

    // Al generar se repasan TODOS los del rango, no solo los sin entrada:
    // hist_al_reservar compara la huella y no hace nada si de verdad esta al dia.
    // Asi se atrapan los que cambiaron de unidad despues de generarse.
    $generadas = [];
    if (!empty($_GET['generar'])) {
        foreach ($delDia as $f) {
            $h = hist_al_reservar($f['deal']);
            $m = (string)($h['motivo'] ?? '?');
            if ($m === 'ya generada para estas unidades') continue;   // nada que hacer
            $generadas[] = $f + ['resultado' => $m, 'unidades' => $h['unidades'] ?? null];
            logline("BARRIDO deal={$f['deal']} historia=" . json_encode($h, JSON_UNESCAPED_SLASHES));
        }
    }

    exit(json_encode([
        'ok' => true,
        'desde' => $desde->format('d/m/Y H:i') . ' (hora Ecuador)',
        'en_reserva_en_ese_rango' => count($delDia),
        'cuales' => $delDia,
        'ya_tenian_historia' => count($hechas),
        'sin_historia' => $faltan,
        'generadas' => empty($_GET['generar']) ? '(no se generó: agregá &generar=1)' : $generadas,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
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
