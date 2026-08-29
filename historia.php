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
/* ?tareas=1 — ¿corren de verdad las tareas programadas?
   ---------------------------------------------------------------------------
   🔴 PRIMERA VERSION EQUIVOCADA, Y CASI DA UN "TODO BIEN" FALSO. Miraba la FECHA
   del archivo que deja cada tarea. Pero `web.log`, `sync.log`, `allowlist.json` y
   `selector_cache.json` los escribe TAMBIEN el camino web (campolib, field, hook,
   app), asi que "reconcile corrio hace 0 minutos" era mi propia consulta tocando
   el archivo. La huella era compartida: no probaba nada.

   Ahora se lee el CONTENIDO del log del cron y se busca la FIRMA de cada tarea con
   su hora. Eso si distingue quien escribio.

   Ojo con lo que significa: una corrida tambien ocurre al ARRANCAR el contenedor
   (el entrypoint dispara rebuild, reconcile y mapa48). Por eso se reporta el
   intervalo entre corridas: si coincide con los despliegues y no con el horario
   programado, el cron no esta corriendo aunque haya lineas. */
/* ?cuantos=1 — cuantos deals hay en cada etapa del 44. Solo lectura, y hace falta
   para saber cuanto costaria ampliar el filtro: pedir TODO el pipeline serian ~29
   paginas cada 5 minutos, y eso no se hace sin medirlo antes. */
if (isset($_GET['cuantos'])) {
    $r = bx('crm.status.list', ['filter' => ['ENTITY_ID' => 'DEAL_STAGE_' . HIST_CAT_CLIENTES],
                                'order' => ['SORT' => 'ASC']]);
    $out = []; $tot = 0;
    foreach (($r['result'] ?? []) as $st) {
        $id = (string)($st['STATUS_ID'] ?? '');
        // 🔴 `start => -1` NO devuelve el total: lo suprime. Se vio porque RESERVA
        // daba 0 sabiendo que tiene ~44 — el control positivo delato la consulta.
        $c = bx('crm.deal.list', ['filter' => ['CATEGORY_ID' => HIST_CAT_CLIENTES, 'STAGE_ID' => $id],
                                  'select' => ['ID']]);
        $n = (int)($c['total'] ?? count($c['result'] ?? [])); $tot += $n;
        $out[] = ['id' => $id, 'nombre' => $st['NAME'] ?? '', 'deals' => $n];
    }
    exit(json_encode(['ok' => true, 'total' => $tot, 'etapas' => $out],
                     JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

/* ?etapas=1 — las etapas del pipeline CLIENTES(44), en orden.
   Solo lectura. Hace falta para decidir cuales significan "la unidad SIGUE vendida":
   hoy solo se mira RESERVA, y un deal que AVANZA a promesa deja de contar aunque la
   unidad siga vendida — con eso se retiraba su historia. */
if (isset($_GET['etapas'])) {
    $r = bx('crm.status.list', ['filter' => ['ENTITY_ID' => 'DEAL_STAGE_' . HIST_CAT_CLIENTES],
                                'order'  => ['SORT' => 'ASC']]);
    if (!($r['ok'] ?? false))
        exit(json_encode(['ok' => false, 'error' => 'no se pudo leer las etapas'], JSON_PRETTY_PRINT));
    $out = [];
    foreach (($r['result'] ?? []) as $st)
        $out[] = ['id' => $st['STATUS_ID'] ?? '', 'nombre' => $st['NAME'] ?? '',
                  'orden' => (int)($st['SORT'] ?? 0),
                  'es_la_que_miro_hoy' => ($st['STATUS_ID'] ?? '') === HIST_ETAPA_RESERVA];
    exit(json_encode(['ok' => true, 'pipeline' => HIST_CAT_CLIENTES,
                      'etapa_actual' => HIST_ETAPA_RESERVA, 'etapas' => $out],
                     JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

if (isset($_GET['tareas'])) {
    $D   = rtrim((string)(getenv('DATA_DIR') ?: '/data'), '/');
    $log = @file($D . '/sync.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    if (!$log) exit(json_encode(['ok' => false,
        'error' => 'no se pudo leer sync.log', 'accion' => 'no se concluye nada'],
        JSON_PRETTY_PRINT));

    $FIRMAS = ['reconcile (cada 15 min)' => ['RECONCILE ok', 15],
               'rebuild     (cada 6 h)'  => ['REBUILD ok',  360]];
    $out = [];
    foreach ($FIRMAS as $nombre => [$firma, $min]) {
        $horas = [];
        foreach ($log as $l)
            if (strpos($l, $firma) !== false && preg_match('/^(\S+Z)/', $l, $m))
                $horas[] = strtotime($m[1]);
        if (!$horas) { $out[$nombre] = ['estado' => 'NUNCA aparece en el log']; continue; }
        sort($horas);
        $ult = end($horas);
        // el hueco MAS GRANDE entre corridas: es lo que delata al cron dormido
        $hueco = 0;
        for ($i = 1, $n = count($horas); $i < $n; $i++)
            $hueco = max($hueco, $horas[$i] - $horas[$i - 1]);
        $out[$nombre] = [
            'corridas_en_el_log' => count($horas),
            'ultima'             => gmdate('c', $ult),
            'hace_min'           => (int)round((time() - $ult) / 60),
            'hueco_mayor_min'    => (int)round($hueco / 60),
            'deberia_ser_cada'   => $min . ' min',
            'veredicto'          => $hueco > $min * 60 * 2.5
                ? 'EL CRON NO CORRE: hubo un hueco de ' . (int)round($hueco / 3600)
                  . ' h, muy por encima de su intervalo'
                : 'los intervalos cuadran con lo programado',
        ];
    }
    exit(json_encode(['ok' => true, 'tareas' => $out,
        'nota' => 'las corridas al arrancar el contenedor tambien aparecen aca; por '
                . 'eso manda el HUECO MAYOR y no la ultima hora.'],
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
