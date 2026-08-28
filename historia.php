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

/* ?latido=1 — ¿el cron esta vivo? Lo escribe conciliar-cron.php antes de trabajar.
   Sin esta señal, "el cron no corre" y "el cron corre y falla" se ven identicos
   desde afuera: en los dos casos no pasa nada. */
/* ?tareas=1 — ¿corren las OTRAS cuatro tareas programadas?
   No tienen señal propia, asi que su silencio se ve igual que "no habia nada que
   hacer". Lo unico que hablan es el archivo que dejan: si la fecha del archivo es
   mas vieja que su intervalo, la tarea no corrio. Es la misma pregunta que el
   latido, contestada con la huella en vez de con un sello. */
if (isset($_GET['tareas'])) {
    $D = rtrim((string)(getenv('DATA_DIR') ?: '/data'), '/');
    $TAREAS = [
        'reconcile   (cada 15 min)' => [$D . '/web.log',           15 * 60],
        'warm-catalogo (cada 30 m)' => [$D . '/selector_cache.json', 30 * 60],
        'rebuild       (cada 6 h)'  => [$D . '/allowlist.json',     6 * 3600],
        'mapa48        (cada 6 h)'  => [$D . '/stages.json',        6 * 3600],
        'conciliar    (cada 5 min)' => [$D . '/conciliar-latido.json',  5 * 60],
    ];
    $out = []; $mudas = 0;
    foreach ($TAREAS as $nombre => [$f, $cada]) {
        $t = @filemtime($f);
        if ($t === false) { $out[$nombre] = ['archivo' => basename($f), 'estado' => 'NUNCA: el archivo no existe']; $mudas++; continue; }
        $seg = time() - $t;
        // 2.5x el intervalo antes de acusar: un pico de carga puede atrasar una vuelta
        $viva = $seg < $cada * 2.5;
        if (!$viva) $mudas++;
        $out[$nombre] = ['archivo' => basename($f), 'hace_min' => (int)round($seg / 60),
                         'estado' => $viva ? 'corre' : 'MUDA hace ' . (int)round($seg / 3600) . ' h'];
    }
    exit(json_encode(['ok' => $mudas === 0, 'mudas' => $mudas, 'tareas' => $out,
        'nota' => 'la fecha del archivo es la unica huella: no prueba que la tarea '
                . 'hiciera algo, solo que ESCRIBIO. Una tarea que corre y no tiene '
                . 'nada que escribir puede verse muda.'],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

if (isset($_GET['latido'])) {
    $f = rtrim((string)(getenv('DATA_DIR') ?: '/data'), '/') . '/conciliar-latido.json';
    $j = json_decode((string)@file_get_contents($f), true);
    $arr0 = json_decode((string)@file_get_contents(
        rtrim((string)(getenv('DATA_DIR') ?: '/data'), '/') . '/arranque.json'), true);
    if (!is_array($j)) exit(json_encode(['ok' => false,
        'latido' => null,
        'dice' => 'el cron NUNCA corrio desde que existe esta señal',
        'arranque' => $arr0], JSON_PRETTY_PRINT));
    $seg = time() - (int)strtotime((string)($j['ultima'] ?? ''));
    $arr = json_decode((string)@file_get_contents(
        rtrim((string)(getenv('DATA_DIR') ?: '/data'), '/') . '/arranque.json'), true);
    exit(json_encode(['ok' => true, 'latido' => $j, 'hace_seg' => $seg,
        'dice' => $seg < 420 ? 'el cron corre' : 'el cron NO corre desde hace '
                  . round($seg / 60) . ' min (deberia ser cada 5)',
        'termino_bien' => isset($j['fin']),
        'arranque' => $arr], JSON_PRETTY_PRINT));
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
/* Conciliar el buzon con la realidad: retira las historias de unidades que ya no
   tienen reserva viva. Una llamada a Bitrix, y el cruce se hace en el generador.
   `seco=1` dice que haria sin mover nada — asi se mira antes de confiar. */
if (!empty($_GET['conciliar'])) {
    $cods = hist_codigos_en_reserva();
    // null = no se pudo saber. Con la lista vacia el generador retiraria TODO, asi
    // que se frena aca y se dice por que.
    if ($cods === null)
        exit(json_encode(['ok' => false, 'error' => 'no se pudo leer el estado en Bitrix',
                          'accion' => 'no se toco nada'], JSON_PRETTY_PRINT));

    $base = rtrim((string)getenv('NORAL_URL'), '/');
    $tok  = (string)getenv('NORAL_SYNC_TOKEN');
    if ($base === '' || $tok === '')
        exit(json_encode(['ok' => false, 'error' => 'falta NORAL_URL o NORAL_SYNC_TOKEN']));

    $url = $base . '/conciliar.php';
    $post = http_build_query(['token' => $tok, 'vivos' => implode(',', $cods)]
                             + (!empty($_GET['seco']) ? ['seco' => 1] : []));
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $post,
                            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 180,
                            CURLOPT_CONNECTTIMEOUT => 8]);   // sube hasta 5 videos
    $raw = (string)curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $j = json_decode($raw, true);
    exit(json_encode(['ok' => $http === 200 && !empty($j['ok']),
                      'codigos_en_reserva' => count($cods),
                      'generador' => $j ?: substr($raw, 0, 300)],
                     JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

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
