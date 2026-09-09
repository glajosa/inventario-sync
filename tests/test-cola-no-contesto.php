<?php
/**
 * LA COLA DEL BOTÓN: que la pulsación NO se pierda y que la actividad salga con
 * la hora en que el vendedor apretó.
 *
 * Pedido del usuario (9-sep-2026): *"aplastaste y se guarda como un historial
 * para que, en el momento que se desature, se cree esa actividad automáticamente
 * con la hora correcta, el día correcto"*.
 *
 * 🔴 Lo que estas pruebas impiden que vuelva: el 9-sep-2026 había 13 pulsaciones
 * en `processing` que NADIE retomó nunca —4 de ese mismo día— porque el endpoint
 * devolvía 503 y no guardaba nada.
 */
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/test-no-contesto-panel-endpoint.php';   // trae PanelEndpointFakeBitrix y el endpoint
require_once __DIR__ . '/../lib/cola-no-contesto.php';
require_once __DIR__ . '/../lib/llamada-idempotencia.php';

function cola_nc_test_dir(): string {
    $d = sys_get_temp_dir() . '/cola-nc-' . bin2hex(random_bytes(6));
    mkdir($d, 0775, true);
    return $d;
}
function cola_nc_test_limpiar(string $d): void {
    foreach (glob($d . '/*') ?: [] as $f) @unlink($f);
    @rmdir($d);
}

function test_cola_no_contesto(): void {
    // ── 1 · guardar y no duplicar ────────────────────────────────────────────
    $dir = cola_nc_test_dir();
    $db = cola_nc_db($dir);
    $pulso = (new DateTimeImmutable('2026-09-09 13:55:00', new DateTimeZone('America/Guayaquil')))->getTimestamp();
    $pedido = ['callRequestId' => 'req-A', 'dealId' => 77, 'bitrixUserId' => 114900, 'outcome' => 'no_answer'];

    test_same(true, cola_nc_encolar($db, 'req-A', $pedido, $pulso, 'panel', 'C28:X', 'bitrix 503'),
        'cola: la pulsacion se guarda');
    cola_nc_encolar($db, 'req-A', $pedido, $pulso, 'panel', 'C28:X', 'otra vez');
    test_same(1, cola_nc_conteo($db)['encolada'],
        'cola: aplastar dos veces deja UNA sola entrada');

    // ⭐ lo que el usuario pidio: la HORA de la pulsacion viaja con ella
    $p = cola_nc_pendientes($db, 5);
    test_same($pulso, (int)$p[0]['now_ts'], 'cola: guarda la hora en que se apreto');
    test_same('req-A', (string)$p[0]['request_id'], 'cola: devuelve la mas vieja primero');

    // ── 2 · el orden es por antiguedad, no por llegada ───────────────────────
    cola_nc_encolar($db, 'req-B', $pedido, $pulso - 3600, 'panel', 'C28:X', '503');
    $p = cola_nc_pendientes($db, 5);
    test_same(2, count($p), 'cola: dos pendientes');

    // ── 3 · se rinde al tope, PERO no borra ──────────────────────────────────
    cola_nc_fallo($db, 'req-A', 'sigue saturado', 2);
    test_same(2, cola_nc_conteo($db)['encolada'], 'cola: un fallo la deja encolada');
    cola_nc_fallo($db, 'req-A', 'y otra vez', 2);
    $c = cola_nc_conteo($db);
    test_same(1, $c['fallida'], 'cola: al llegar al tope queda fallida');
    test_same(1, $c['encolada'], 'cola: la otra sigue esperando');
    test_same(0, $c['hecha'], 'cola: ninguna hecha todavia');

    // ── 4 · marcar hecha ─────────────────────────────────────────────────────
    cola_nc_hecha($db, 'req-B');
    test_same(1, cola_nc_conteo($db)['hecha'], 'cola: se marca hecha');
    test_same(0, cola_nc_conteo($db)['encolada'], 'cola: ya no queda ninguna esperando');

    // ── 5 · un request_id vacio NO se guarda ─────────────────────────────────
    test_same(false, cola_nc_encolar($db, '', $pedido, $pulso, 'panel', 'C28:X', 'x'),
        'cola: sin request_id no se guarda (no habria como reproducirla)');

    unset($db);
    cola_nc_test_limpiar($dir);

    // ── 6 · el endpoint responde "encolada", no 503 ───────────────────────────
    $dir2 = cola_nc_test_dir();
    $fake = new PanelEndpointFakeBitrix();
    // Bitrix rechaza TODO, como cuando el portal esta lleno
    $fake->errors['crm.deal.get'] = ['ok' => false, 'error' => 'QUERY_LIMIT_EXCEEDED', 'desc' => 'too many requests'];
    $ahora = (new DateTimeImmutable('2026-09-09 13:55:00', new DateTimeZone('America/Guayaquil')))->getTimestamp();
    $resp = llamada_no_contesto_panel_http(
        'POST', panel_endpoint_body(), panel_endpoint_env($dir2),
        static fn(string $t): int => 42, $fake, $ahora
    );
    test_same(200, (int)$resp['status'], 'endpoint: saturado responde 200, no 503');
    test_same('encolada', (string)($resp['body']['status'] ?? ''), 'endpoint: el estado es "encolada"');

    $db2 = cola_nc_db($dir2);
    test_same(1, cola_nc_conteo($db2)['encolada'], 'endpoint: la pulsacion quedo guardada');
    $g = cola_nc_pendientes($db2, 1);
    test_same($ahora, (int)$g[0]['now_ts'], 'endpoint: guarda la hora de la pulsacion');
    $guardado = json_decode((string)$g[0]['input_json'], true);
    test_same(77, (int)($guardado['dealId'] ?? 0), 'endpoint: guarda el deal para poder reproducirla');
    test_same(42, (int)($guardado['bitrixUserId'] ?? 0), 'endpoint: guarda de QUIEN es la llamada');
    test_same('no_answer', (string)($guardado['outcome'] ?? ''), 'endpoint: guarda el resultado');

    // ── 7 · un pedido invalido NO se encola ──────────────────────────────────
    $resp2 = llamada_no_contesto_panel_http(
        'POST', '{"nada":1}', panel_endpoint_env($dir2),
        static fn(string $t): int => 42, $fake, $ahora
    );
    test_same(400, (int)$resp2['status'], 'endpoint: un pedido invalido sigue siendo 400');
    test_same(1, cola_nc_conteo($db2)['encolada'], 'endpoint: y NO se encola (reproducirlo fallaria igual)');

    unset($db2);
    cola_nc_test_limpiar($dir2);
}

/** El DEADLINE de la actividad planificada que se creo, o '' si no se creo ninguna. */
function cola_nc_test_deadline(PanelEndpointFakeBitrix $fake): string {
    foreach ($fake->calls as [$metodo, $params]) {
        if ($metodo !== 'crm.activity.add') continue;
        return (string)($params['fields']['DEADLINE'] ?? '');
    }
    return '';
}

/**
 * ⭐⭐ LO QUE EL USUARIO PIDIO, MEDIDO: *"se crea sola la actividad, con la hora
 * correcta, el dia correcto"*.
 *
 * No alcanza con ver que la actividad se cree. Lo que se prueba es que el
 * DEADLINE que queda es el que le habria tocado A LA HORA EN QUE APRETO, no el
 * de la hora en que la cola se drena. Si algun dia alguien cambia el drenador
 * para pasar `new DateTimeImmutable('now')`, esta prueba se cae.
 */
function test_cola_no_contesto_se_hace_sola(): void {
    $tzEc = new DateTimeZone('America/Guayaquil');
    // miercoles laborable, 13:55 de Ecuador: la hora real de las 4 pulsaciones
    // que se perdieron el 9-sep-2026
    $pulsada = (new DateTimeImmutable('2026-09-09 13:55:00', $tzEc))->getTimestamp();

    // ── A · la pulsacion se encola porque Bitrix rechaza la escritura ─────────
    $dirCola = cola_nc_test_dir();
    $roto = new PanelEndpointFakeBitrix();
    /* ⚠ EL FALLO TIENE QUE SER TEMPRANO, y esto costo un intento fallido.
     *
     * Primero rompi `crm.activity.update` (que falla al CERRAR la pendiente) y
     * la prueba pasaba incluso rompiendo el drenador a proposito. La razon: el
     * servicio ya habia guardado `nextActivityAt` en el progreso ANTES de esa
     * escritura, y al reproducir reusaba esa fecha en vez de mirar el reloj. La
     * prueba no medía nada.
     *
     * Rompiendo `crm.deal.get` el fallo ocurre antes de que exista esa fecha, asi
     * que el drenado SI tiene que calcularla — y con la hora de la pulsacion. */
    $roto->errors['crm.deal.get'] = ['ok' => false, 'error' => 'QUERY_LIMIT_EXCEEDED', 'desc' => 'too many requests'];
    $resp = llamada_no_contesto_panel_http(
        'POST', panel_endpoint_body(), panel_endpoint_env($dirCola),
        static fn(string $t): int => 42, $roto, $pulsada
    );
    test_same('encolada', (string)($resp['body']['status'] ?? ''), 'sola: la pulsacion se encolo');
    test_same('', cola_nc_test_deadline($roto), 'sola: y NO se creo ninguna actividad al fallar');

    /* ── B1 · drenar EN EL MISMO MINUTO no la duplica ─────────────────────────
     *
     * El intento que fallo dejo un arriendo ("lease") de 60 s sobre la operacion.
     * Mientras vive, el servicio responde `processing` y el drenado NO escribe:
     * eso es lo que impide crear dos llamadas para una sola pulsacion. La fila
     * se queda esperando el siguiente turno del cron (que pasa cada 2 min, o
     * sea SIEMPRE despues de que el arriendo vencio). */
    $mismoMinuto = new PanelEndpointFakeBitrix();
    $db = cola_nc_db($dirCola);
    $r0 = cola_nc_drenar($db, $mismoMinuto, new LlamadaIdempotenciaStore($dirCola), 'C28:NO_INTERESADO', 10);
    test_same(0, (int)$r0['hechas'], 'arriendo vivo: el drenado NO escribe todavia');
    test_same('', cola_nc_test_deadline($mismoMinuto), 'arriendo vivo: no crea una segunda actividad');
    test_same(1, cola_nc_conteo($db)['encolada'], 'arriendo vivo: la fila sigue esperando');

    // ── B2 · dos minutos despues (turno normal del cron) SI se crea ──────────
    // ⚠ el reloj del arriendo es el del sistema, NO el `now_ts` de la pulsacion:
    // por eso hace falta inyectarle un reloj adelantado para simular el turno.
    $sano = new PanelEndpointFakeBitrix();
    $reloj = static fn(): int => time() + 300;
    $r = cola_nc_drenar($db, $sano, new LlamadaIdempotenciaStore($dirCola, $reloj), 'C28:NO_INTERESADO', 10);
    test_same(1, (int)$r['hechas'], 'sola: el drenado la creo');
    test_same(0, (int)$r['fallidas'], 'sola: sin fallos');
    test_same(false, (bool)$r['cortado'], 'sola: no corto el lote');
    test_same(0, cola_nc_conteo($db)['encolada'], 'sola: ya no queda esperando');
    test_same(1, cola_nc_conteo($db)['hecha'], 'sola: quedo marcada hecha');
    $deadlineDrenado = cola_nc_test_deadline($sano);
    test_same(true, $deadlineDrenado !== '', 'sola: se creo la actividad planificada');
    unset($db);
    cola_nc_test_limpiar($dirCola);

    // ── C · control: la MISMA pulsacion atendida al instante ─────────────────
    $dirCtrl = cola_nc_test_dir();
    $ctrl = new PanelEndpointFakeBitrix();
    llamada_no_contesto_panel_http(
        'POST', panel_endpoint_body(['requestId' => '44444444-4444-4444-8444-444444444444']),
        panel_endpoint_env($dirCtrl), static fn(string $t): int => 42, $ctrl, $pulsada
    );
    $deadlineDirecto = cola_nc_test_deadline($ctrl);
    test_same(true, $deadlineDirecto !== '', 'control: la via rapida creo la actividad');
    // ⭐ EL CORAZON DE LA PRUEBA
    test_same($deadlineDirecto, $deadlineDrenado,
        'sola: la actividad drenada queda con la hora de la PULSACION, no del drenado');
    cola_nc_test_limpiar($dirCtrl);

    // ── D · anti-control: con otra hora el DEADLINE cambia ───────────────────
    // sin esto la prueba de arriba podria pasar por casualidad (por ejemplo si
    // el DEADLINE fuese una constante que no mira el reloj)
    $dirOtro = cola_nc_test_dir();
    $otro = new PanelEndpointFakeBitrix();
    llamada_no_contesto_panel_http(
        'POST', panel_endpoint_body(['requestId' => '55555555-5555-4555-8555-555555555555']),
        panel_endpoint_env($dirOtro), static fn(string $t): int => 42, $otro,
        $pulsada + 26 * 3600   // al dia siguiente
    );
    $deadlineOtroDia = cola_nc_test_deadline($otro);
    test_same(true, $deadlineOtroDia !== '' && $deadlineOtroDia !== $deadlineDrenado,
        'anti-control: apretar otro dia da OTRO deadline (la prueba de arriba mide algo)');
    cola_nc_test_limpiar($dirOtro);

    // ── E · el log del drenado dice la hora de ECUADOR, no la del contenedor ─
    test_same('2026-09-09 13:55', cola_nc_ec($pulsada),
        'el log muestra la hora de Ecuador (el contenedor corre en UTC)');
}

/* ⚠ Las pruebas de este repo se ejecutan AL CARGAR el archivo (run.php solo hace
 * require_once). Sin estas dos lineas el archivo se cargaba, no corria nada, y
 * run.php decia OK igual. */
/**
 * ⚠ QUE EL TRABAJADOR ESTE ENCHUFADO.
 *
 * Toda esta cola no sirve de nada si nadie la drena. Y ya me paso dos veces que
 * el codigo estaba bien y la tarea no corria: una vez porque `A && B > archivo`
 * redirigia solo B (13 de 14 tareas al vacio) y otra porque cron en este mismo
 * contenedor esta vivo y no ejecuta nada.
 *
 * Se leen las lineas EJECUTABLES del entrypoint (sin comentarios): si el
 * comentario que explica el bucle contara como prueba, la prueba se aprobaria a
 * si misma.
 */
function test_cola_no_contesto_enchufada(): void {
    $ep = (string)file_get_contents(__DIR__ . '/../entrypoint.sh');
    $vivas = implode("\n", array_filter(
        explode("\n", $ep),
        static fn(string $l): bool => !str_starts_with(ltrim($l), '#')
    ));

    test_same(1, substr_count($vivas, 'drenar-no-contesto.php'),
        'entrypoint: el drenador esta enchufado UNA vez (no cero, no dos)');
    test_same(true, str_contains($vivas, 'export NO_INTEREST_STAGE_ID='),
        'entrypoint: la etapa viaja a /data/env.sh (cron no hereda el entorno)');
    test_same(true, str_contains($vivas, 'export BITRIX_WEBHOOK='),
        'entrypoint: la credencial del servicio tambien');

    // el bucle espera al menos los 60 s del arriendo antes de reintentar
    $i = strpos($vivas, 'drenar-no-contesto.php');
    $cola = substr($vivas, (int)$i);
    test_same(1, preg_match('/sleep\s+(\d+)/', $cola, $m),
        'entrypoint: el bucle del drenador tiene su espera');
    test_same(true, (int)($m[1] ?? 0) >= 120,
        'entrypoint: espera >= 120 s (el arriendo dura 60; insistir mas rapido empuja al portal)');

    // el archivo que el bucle invoca tiene que existir de verdad
    test_same(true, is_file(__DIR__ . '/../bin/drenar-no-contesto.php'),
        'el drenador existe en la ruta que invoca el entrypoint');
}

test_cola_no_contesto();
test_cola_no_contesto_se_hace_sola();
test_cola_no_contesto_enchufada();
echo "test-cola-no-contesto OK\n";
