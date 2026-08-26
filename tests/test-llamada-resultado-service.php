<?php
declare(strict_types=1);

/**
 * Los campos de la planificada, sin importar de donde salieron.
 *
 * El panel la CREA (crm.activity.add). El movil REUSA la actividad que creo la
 * app (crm.activity.update con DEADLINE). Las pruebas que solo miran la fecha de
 * la escalera no tienen por que saber cual de los dos caminos se uso.
 */
function llamada_campos_planificada(FakeBitrix $fake): array {
    $adds = llamada_calls($fake, 'crm.activity.add');
    if ($adds !== []) return $adds[0][1]['fields'];
    foreach (llamada_calls($fake, 'crm.activity.update') as $call) {
        if (isset($call[1]['fields']['DEADLINE'])) return $call[1]['fields'];
    }
    return [];
}


require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../lib/llamada-idempotencia.php';
$servicePath = __DIR__ . '/../lib/llamada-resultado-service.php';
if (is_file($servicePath)) require_once $servicePath;

final class FakeBitrix {
    public array $calls = [];
    public array $deal = [
        'ID' => '77',
        'ASSIGNED_BY_ID' => '42',
        'CONTACT_ID' => '91',
        'STAGE_ID' => 'C28:INTERESADO',
    ];
    public array $activity = [
        'ID' => '731',
        'OWNER_ID' => '77',
        'OWNER_TYPE_ID' => '2',
        'TYPE_ID' => '2',
        'DIRECTION' => '2',
        'RESPONSIBLE_ID' => '42',
        'COMMUNICATIONS' => [[
            'VALUE' => '+593 99 123 4567',
            'ENTITY_ID' => '91',
            'ENTITY_TYPE_ID' => '3',
            'TYPE' => 'PHONE',
        ]],
    ];
    public array $contact = [
        'ID' => '91',
        'NAME' => 'Ana',
        'LAST_NAME' => 'Pérez',
        'PHONE' => [[
            'ID' => '501',
            'VALUE' => '+593 99 123 4567',
            'VALUE_TYPE' => 'MOBILE',
        ]],
    ];
    public array $historyPages = [];
    public array $pendingActivities = [[
        'ID' => '630',
        'SUBJECT' => 'Llamada pendiente',
        'DEADLINE' => '2026-08-20T10:00:00-05:00',
        'COMMUNICATIONS' => [[
            'VALUE' => '+593991234567',
            'TYPE' => 'PHONE',
        ]],
    ]];
    public array $reentryHistory = [];
    public array $errors = [];
    public array $responseQueues = [];
    public bool $throwOnComment = false;
    public mixed $onCall = null;

    public function __invoke(string $method, array $params): array {
        $this->calls[] = [$method, $params];
        if (is_callable($this->onCall)) ($this->onCall)($method, $params);
        if (!empty($this->responseQueues[$method])) {
            return array_shift($this->responseQueues[$method]);
        }
        if (isset($this->errors[$method])) return $this->errors[$method];

        return match ($method) {
            'crm.deal.get' => ['ok' => true, 'result' => $this->deal],
            'crm.activity.get' => ['ok' => true, 'result' => $this->activity],
            'crm.contact.get' => ['ok' => true, 'result' => $this->contact],
            'crm.activity.list' => ['ok' => true, 'result' => $this->historyPage($params)],
            'crm.stagehistory.list' => ['ok' => true, 'result' => $this->reentryHistory],
            'crm.activity.update' => ['ok' => true, 'result' => true],
            'crm.activity.add' => ['ok' => true, 'result' => 901],
            'crm.timeline.comment.add' => $this->commentResult(),
            'crm.deal.update' => ['ok' => true, 'result' => true],
            default => ['ok' => false, 'error' => 'unexpected-method', 'desc' => $method],
        };
    }

    private function historyPage(array $params): array {
        if (($params['filter']['COMPLETED'] ?? null) === 'N') {
            return $this->pendingActivities;
        }
        $afterId = (int)($params['filter']['>ID'] ?? 0);
        return $this->historyPages[$afterId] ?? [];
    }

    private function commentResult(): array {
        if ($this->throwOnComment) throw new RuntimeException('simulated connection loss');
        return ['ok' => true, 'result' => 801];
    }
}

function llamada_test_input(array $changes = []): array {
    return array_replace([
        'callRequestId' => '11111111-1111-4111-8111-111111111111',
        'memberId' => 'member-1',
        'dealId' => 77,
        'bitrixUserId' => 42,
        'bitrixActivityId' => 731,
        'outcome' => 'no_answer',
        'selectedPhone' => '+593991234567',
        'nextActivityAt' => null,
        'comment' => '',
    ], $changes);
}

function llamada_test_store(): array {
    $directory = sys_get_temp_dir() . '/inventario-sync-result-service-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700, true);
    $clock = (new DateTimeImmutable('2026-08-20T16:30:00-05:00'))->getTimestamp();
    return [new LlamadaIdempotenciaStore($directory, static fn(): int => $clock), $directory];
}

function llamada_test_store_with_clock(int &$clock): array {
    $directory = sys_get_temp_dir() . '/inventario-sync-result-service-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700, true);
    return [new LlamadaIdempotenciaStore($directory, function () use (&$clock): int {
        return $clock;
    }), $directory];
}

function llamada_test_cleanup(string $directory): void {
    $databasePath = $directory . '/llamada-resultados.sqlite';
    if (is_file($databasePath)) unlink($databasePath);
    rmdir($directory);
}

function llamada_calls(FakeBitrix $fake, string $method): array {
    return array_values(array_filter($fake->calls, fn(array $call): bool => $call[0] === $method));
}

function llamada_write_calls(FakeBitrix $fake): array {
    $writes = ['crm.activity.update', 'crm.activity.add', 'crm.timeline.comment.add', 'crm.deal.update'];
    return array_values(array_filter(
        $fake->calls,
        fn(array $call): bool => in_array($call[0], $writes, true)
    ));
}

$now = new DateTimeImmutable('2026-08-20T16:30:00-05:00');
$noInterestStage = 'C28:NO_INTERESADO';

$twoThousandEmojis = str_repeat('😀', 2_000);
$commentBoundary = llamada_validar_resultado(llamada_test_input([
    'comment' => "\u{00A0}\u{FEFF}" . $twoThousandEmojis . "\u{2028}",
]), $now, $noInterestStage);
test_same($twoThousandEmojis, $commentBoundary['comment'], 'comment trims bridge Unicode edge whitespace and accepts 2000 code points');

test_throws_message(
    fn() => llamada_validar_resultado(llamada_test_input([
        'comment' => "válido\xC3\x28",
    ]), $now, $noInterestStage),
    LlamadaValidationError::class,
    'valid UTF-8',
    'invalid UTF-8 comment is rejected explicitly'
);

[$store, $directory] = llamada_test_store();
try {
    $fake = new FakeBitrix();
    test_throws_message(
        fn() => llamada_procesar_resultado(llamada_test_input([
            'callRequestId' => '76767676-7676-4676-8676-767676767676',
            'comment' => str_repeat('😀', 2_001),
        ]), $fake, $store, $now, $noInterestStage),
        LlamadaValidationError::class,
        '2000 Unicode code points',
        'comment over 2000 emoji code points is rejected'
    );
    test_same([], $fake->calls, 'overlimit Unicode comment performs no Bitrix call');
} finally {
    llamada_test_cleanup($directory);
}

[$store, $directory] = llamada_test_store();
try {
    $fake = new FakeBitrix();
    $fake->pendingActivities = [[
        'ID' => '630',
        'SUBJECT' => 'Llamada pendiente',
        'DEADLINE' => '2026-08-20T10:00:00-05:00',
        'COMMUNICATIONS' => [['VALUE' => '+593991234567', 'TYPE' => 'PHONE']],
    ]];
    $mobile = llamada_procesar_resultado(llamada_test_input([
        'callRequestId' => '81818181-8181-4181-8181-818181818181',
    ]), $fake, $store, $now, $noInterestStage, 'mobile');
    $writesAfterMobile = llamada_write_calls($fake);
    $panel = llamada_procesar_resultado(llamada_test_input([
        'callRequestId' => '82828282-8282-4282-8282-828282828282',
        'memberId' => 'panel-42',
        'bitrixActivityId' => null,
    ]), $fake, $store, $now, $noInterestStage, 'panel');

    test_same('processed', $mobile['status'], 'mobile owns first shared result');
    test_same('already_processed', $panel['status'], 'panel sees the completed mobile result');
    test_same(null, $panel['bitrixActivityId'], 'panel duplicate does not borrow a mobile technical activity');
    test_same($writesAfterMobile, llamada_write_calls($fake), 'mobile then panel repeats no Bitrix write');
    // El movil ya no AGREGA: reusa la actividad que creo la app y la convierte
    // en la planificada. Lo que se afirma sigue siendo lo mismo — que entre los
    // dos quede UNA sola llamada futura, no dos.
    test_same(0, count(llamada_calls($fake, 'crm.activity.add')),
        'mobile reuses the app activity instead of creating a second one');
    $upd = llamada_calls($fake, 'crm.activity.update');
    $reuso = array_values(array_filter($upd,
        fn(array $c): bool => (int)$c[1]["id"] === 731 && isset($c[1]['fields']['DEADLINE'])));
    test_same(1, count($reuso), 'the app activity is turned into the planned call, exactly once');
    test_same('N', $reuso[0][1]['fields']['COMPLETED'], 'and it stays OPEN: it is the next call');
} finally {
    llamada_test_cleanup($directory);
}

[$store, $directory] = llamada_test_store();
try {
    $fake = new FakeBitrix();
    $fake->pendingActivities = [[
        'ID' => '630',
        'SUBJECT' => 'Llamada pendiente',
        'DEADLINE' => '2026-08-20T10:00:00-05:00',
        'COMMUNICATIONS' => [['VALUE' => '+593991234567', 'TYPE' => 'PHONE']],
    ]];
    $panel = llamada_procesar_resultado(llamada_test_input([
        'callRequestId' => '83838383-8383-4383-8383-838383838383',
        'memberId' => 'panel-42',
        'bitrixActivityId' => null,
    ]), $fake, $store, $now, $noInterestStage, 'panel');
    $writesAfterPanel = llamada_write_calls($fake);
    $mobile = llamada_procesar_resultado(llamada_test_input([
        'callRequestId' => '84848484-8484-4484-8484-848484848484',
    ]), $fake, $store, $now, $noInterestStage, 'mobile');

    test_same('processed', $panel['status'], 'panel may own the shared result');
    test_same(null, $panel['bitrixActivityId'], 'panel creates no technical activity');
    test_same('already_processed', $mobile['status'], 'mobile observes the completed panel result');
    test_same(731, $mobile['bitrixActivityId'], 'mobile duplicate keeps its own technical activity correlation');
    test_same($writesAfterPanel, llamada_write_calls($fake), 'panel then mobile repeats no inventory Bitrix write');
    test_same(1, count(llamada_calls($fake, 'crm.activity.update')), 'panel completes only the pending activity');
    test_same(1, count(llamada_calls($fake, 'crm.activity.add')), 'panel then mobile creates one future activity');
} finally {
    llamada_test_cleanup($directory);
}

[$store, $directory] = llamada_test_store();
try {
    $fake = new FakeBitrix();
    $fake->pendingActivities = [[
        'ID' => '630',
        'SUBJECT' => 'Llamada pendiente',
        'DEADLINE' => '2026-08-20T10:00:00-05:00',
        'COMMUNICATIONS' => [['VALUE' => '+593991234567', 'TYPE' => 'PHONE']],
    ]];
    $answered = llamada_procesar_resultado(llamada_test_input([
        'callRequestId' => '85858585-8585-4585-8585-858585858585',
        'outcome' => 'answered',
    ]), $fake, $store, $now, $noInterestStage, 'mobile');
    test_same('processed', $answered['status'], 'answered owns its shared cycle');
    test_throws(
        fn() => llamada_procesar_resultado(llamada_test_input([
            'callRequestId' => '86868686-8686-4686-8686-868686868686',
            'memberId' => 'panel-42',
            'bitrixActivityId' => null,
        ]), $fake, $store, $now, $noInterestStage, 'panel'),
        LlamadaIdempotenciaConflict::class,
        'no answer cannot replace answered for the same call'
    );
    test_same([], llamada_calls($fake, 'crm.activity.add'), 'conflicting no answer creates no future activity');
} finally {
    llamada_test_cleanup($directory);
}

[$store, $directory] = llamada_test_store();
try {
    $fake = new FakeBitrix();
    // ⭐ SIN PENDIENTE TAMBIEN SE REGISTRA. Regla del 26-ago-2026.
    //
    // Antes el movil cortaba con 'pending_activity_not_found'. Medido en el deal
    // 401877: cero planificadas abiertas, el vendedor apreto desde el celular y
    // no se escribio NADA. La pendiente es algo que se CIERRA, no un requisito —
    // llamar a un deal sin llamada agendada pasa todos los dias.
    $fake->pendingActivities = [];
    $sinPendiente = llamada_procesar_resultado(llamada_test_input([
        'callRequestId' => '87878787-8787-4787-8787-878787878787',
    ]), $fake, $store, $now, $noInterestStage, 'mobile');
    test_same('processed', $sinPendiente['status'],
        'a press on a deal with no scheduled call is registered, not thrown away');
    $agendada = llamada_campos_planificada($fake);
    test_same(false, $agendada === [], 'and the next call gets scheduled');
    test_same('N', $agendada['COMPLETED'], 'left open: it IS the next call');
    test_same('2026-08-21T19:00:00-05:00', $agendada['DEADLINE'], 'with the ladder date');
} finally {
    llamada_test_cleanup($directory);
}

[$store, $directory] = llamada_test_store();
try {
    $fake = new FakeBitrix();
    $fake->pendingActivities = [
        ['ID' => '640', 'SUBJECT' => 'Pendiente A', 'DEADLINE' => '2026-08-19T10:00:00-05:00'],
        ['ID' => '641', 'SUBJECT' => 'Pendiente B', 'DEADLINE' => '2026-08-20T10:00:00-05:00'],
    ];
    $manual = llamada_procesar_resultado(llamada_test_input([
        'callRequestId' => '88888888-8888-4888-8888-888888888887',
    ]), $fake, $store, $now, $noInterestStage, 'mobile');
    // Pendiente AMBIGUA (dos abiertas y ninguna calza): se conserva la mitad
    // prudente —no se cierra ninguna al azar— pero la llamada SI se registra.
    // No registrar era la mitad cara: es trabajo real del vendedor.
    test_same('processed', $manual['status'], 'an ambiguous pending does not throw the call away');
    $cerradas = array_map(fn(array $c): int => (int)$c[1]['id'], llamada_calls($fake, 'crm.activity.update'));
    test_same(false, in_array(640, $cerradas, true), 'neither ambiguous pending is closed at random');
    test_same(false, in_array(641, $cerradas, true), 'neither ambiguous pending is closed at random');
    $agendada = llamada_campos_planificada($fake);
    test_same(false, $agendada === [], 'and the next call still gets scheduled');
} finally {
    llamada_test_cleanup($directory);
}

[$store, $directory] = llamada_test_store();
try {
    $fake = new FakeBitrix();
    $fake->historyPages = [0 => [fake_activity(731, 'Llamada saliente Ana Pérez', '2026-08-20T16:00:00-05:00')]];
    $result = llamada_procesar_resultado(llamada_test_input(), $fake, $store, $now, $noInterestStage);

    test_same([
        'status' => 'processed',
        'callRequestId' => '11111111-1111-4111-8111-111111111111',
        'outcome' => 'no_answer',
        'bitrixActivityId' => 731,
        'stageChanged' => false,
        'commentCreated' => false,
        'nextActivityAt' => '2026-08-21T19:00:00-05:00',
    ], $result, 'no answer returns exact result contract and calculated date');
    // ⭐ UNA SOLA ACTIVIDAD POR PULSACION, igual que el boton del panel.
    //
    // Antes quedaban DOS: el sello ('App movil · No contesto', cerrada) y una
    // planificada nueva. En este portal una misma actividad registra la llamada
    // que ocurrio Y agenda la proxima, y el sello era una tercera tarjeta en la
    // ficha sin ningun dato propio: el motor ya lo saltaba al contar.
    //
    // Ahora la actividad que creo la app SE CONVIERTE en la planificada.
    $updates = llamada_calls($fake, 'crm.activity.update');
    test_same(2, count($updates), 'no answer touches the app activity and the pending call');
    test_same(731, $updates[0][1]['id'], 'the app activity is the one reused');
    test_same([], llamada_calls($fake, 'crm.activity.add'),
        'nothing is created: there is no separate stamp and no separate planned call');

    $reuso = $updates[0][1]['fields'];
    test_same('Llamada saliente Ana Pérez', $reuso['SUBJECT'], 'it gets the planned call subject');
    test_same('N', $reuso['COMPLETED'], 'and stays OPEN: it IS the next call');
    test_same('2026-08-21T19:00:00-05:00', $reuso['DEADLINE'], 'with the ladder date');
    // la nota, sin el uuid: se ACTUALIZA, y actualizar dos veces no duplica nada.
    // El uuid solo hace falta cuando se CREA (el panel), para no duplicar la
    // planificada si el add prospero y el progreso no se alcanzo a guardar.
    test_same('Registrada desde la app de llamadas Galjosa', $reuso['DESCRIPTION'],
        'the note is one clean sentence: the uuid was noise the seller read every time');
    test_same(false, isset($reuso['OWNER_ID']),
        'an update does not resend the fields that identify the activity');

    test_same(630, $updates[1][1]['id'], 'no answer closes the matching pending activity');
    test_same(['COMPLETED' => 'Y'], $updates[1][1]['fields'], 'pending call is completed without rewriting its details');
    test_same([], llamada_calls($fake, 'crm.deal.update'), 'no answer preserves stage');
    test_same([], llamada_calls($fake, 'crm.timeline.comment.add'), 'empty comment creates no timeline entry');
    $stored = $store->get('member-1:11111111-1111-4111-8111-111111111111');
    test_same($now->getTimestamp(), (int)($stored['updated_at'] ?? 0), 'result service lease clock is deterministic');
} finally {
    llamada_test_cleanup($directory);
}

[$store, $directory] = llamada_test_store();
try {
    $fake = new FakeBitrix();
    $fake->pendingActivities = [[
        'ID' => '630',
        'SUBJECT' => 'Única llamada pendiente',
        'DEADLINE' => '2026-08-19T10:00:00-05:00',
    ]];

    llamada_procesar_resultado(llamada_test_input([
        'callRequestId' => '30303030-3030-4030-8030-303030303030',
        'outcome' => 'answered',
    ]), $fake, $store, $now, $noInterestStage);

    test_same([731, 630], array_map(
        fn(array $call): int => (int)$call[1]['id'],
        llamada_calls($fake, 'crm.activity.update')
    ), 'the only pending call is completed when Bitrix omitted its communications');
} finally {
    llamada_test_cleanup($directory);
}

[$store, $directory] = llamada_test_store();
try {
    $fake = new FakeBitrix();
    $fake->pendingActivities = [
        [
            'ID' => '610',
            'SUBJECT' => 'Llamada pendiente anterior',
            'DEADLINE' => '2026-08-19T10:00:00-05:00',
            'COMMUNICATIONS' => [[
                'VALUE' => '099 123 4567',
                'TYPE' => 'PHONE',
            ]],
        ],
        [
            'ID' => '611',
            'SUBJECT' => 'Otra llamada del mismo número',
            'DEADLINE' => '2026-08-20T10:00:00-05:00',
            'COMMUNICATIONS' => [[
                'VALUE' => '+593991234567',
                'TYPE' => 'PHONE',
            ]],
        ],
        [
            'ID' => '612',
            'SUBJECT' => 'Llamada de otro número',
            'DEADLINE' => '2026-08-18T10:00:00-05:00',
            'COMMUNICATIONS' => [[
                'VALUE' => '+593 98 765 4321',
                'TYPE' => 'PHONE',
            ]],
        ],
        [
            'ID' => '731',
            'SUBJECT' => 'Outbound call',
            'DEADLINE' => '2026-08-17T10:00:00-05:00',
            'COMMUNICATIONS' => [[
                'VALUE' => '+593 99 123 4567',
                'TYPE' => 'PHONE',
            ]],
        ],
    ];

    llamada_procesar_resultado(llamada_test_input([
        'callRequestId' => '10101010-1010-4010-8010-101010101010',
    ]), $fake, $store, $now, $noInterestStage);

    $updates = llamada_calls($fake, 'crm.activity.update');
    // Un deal, una proxima llamada POR PERSONA. Se cierran las pendientes del
    // numero que se acaba de llamar (610 y 611) y se conserva 612, que es de
    // otro numero: esa llamada todavia hay que hacerla.
    //
    // Antes se cerraba solo la elegida (610). Con dos o mas pendientes del mismo
    // numero, cada pulsacion cerraba una y creaba otra: el sobrante nunca se iba
    // — medido en el deal 401173 el 26-ago-2026, dos planificadas abiertas al
    // mismo tiempo y el vendedor sin saber cual fecha valia.
    $cerradas = array_map(fn(array $call): int => (int)$call[1]['id'], $updates);
    test_same([731, 610], $cerradas,
        'result closes the technical call and the oldest pending call for the selected number');
    test_same(false, in_array(612, $cerradas, true),
        'a pending call for a DIFFERENT number is left open: that call still has to be made');
    test_same(['COMPLETED' => 'Y'], $updates[1][1]['fields'], 'previous pending call is completed without rewriting its details');
    $writes = array_values(array_filter(
        $fake->calls,
        fn(array $call): bool => in_array($call[0], ['crm.activity.update', 'crm.activity.add'], true)
    ));
    // Ya no hay 'add': la actividad de la app se convierte en la planificada.
    // Lo que se sigue afirmando es el ORDEN — primero se resuelve lo viejo.
    test_same(['crm.activity.update', 'crm.activity.update'], array_map(
        fn(array $call): string => $call[0],
        $writes
    ), 'the app activity becomes the planned call and the previous pending is closed');
} finally {
    llamada_test_cleanup($directory);
}

[$store, $directory] = llamada_test_store();
try {
    $fake = new FakeBitrix();
    $fake->pendingActivities = [
        ['ID' => '620', 'SUBJECT' => 'Pendiente sin teléfono 1', 'DEADLINE' => '2026-08-19T10:00:00-05:00'],
        ['ID' => '621', 'SUBJECT' => 'Pendiente sin teléfono 2', 'DEADLINE' => '2026-08-20T10:00:00-05:00'],
    ];

    llamada_procesar_resultado(llamada_test_input([
        'callRequestId' => '20202020-2020-4020-8020-202020202020',
        'outcome' => 'answered',
    ]), $fake, $store, $now, $noInterestStage);

    // 'answered' con pendiente ambigua: se sella la llamada que la app creo
    // —ese es el registro del contacto efectivo, no se puede perder— y NO se
    // cierra ninguna de las ambiguas.
    $tocadas = array_map(fn(array $c): int => (int)$c[1]['id'], llamada_calls($fake, 'crm.activity.update'));
    test_same([731], $tocadas, 'only the app activity is stamped; no ambiguous pending is closed');
} finally {
    llamada_test_cleanup($directory);
}

[$store, $directory] = llamada_test_store();
try {
    $fake = new FakeBitrix();
    $callRequestId = '19191919-1919-4191-8191-191919191919';
    $fake->responseQueues['crm.activity.add'] = [[
        'ok' => false,
        'error' => 'bad-json',
    ]];
    // ⚠ EN PANEL, no en movil. El movil ya no AGREGA: reusa la actividad que
    // creo la app. El unico camino que todavia crea una planificada —y que por
    // eso necesita el uuid para reconocerla si el add prospero sin que se
    // guardara el progreso— es el del panel.
    $input = llamada_test_input([
        'callRequestId' => $callRequestId,
        'memberId' => 'panel-42',
        'bitrixActivityId' => null,
    ]);

    test_throws(
        fn() => llamada_procesar_resultado($input, $fake, $store, $now, $noInterestStage, 'panel'),
        LlamadaBitrixError::class,
        'uncertain planned activity response is surfaced'
    );
    $created = fake_activity(901, 'Llamada saliente Ana Pérez', '2026-08-20T16:31:00-05:00');
    $created['DESCRIPTION'] = llamada_marca_actividad($callRequestId);
    $fake->historyPages = [0 => [$created]];

    $retried = llamada_procesar_resultado($input, $fake, $store, $now, $noInterestStage, 'panel');
    test_same('processed', $retried['status'], 'uncertain planned activity is recovered from its marker');
    test_same(1, count(llamada_calls($fake, 'crm.activity.add')), 'recovery never adds the marked activity twice');
    // En panel no hay actividad tecnica que cerrar: la pulsacion ES la accion.
    test_same(0, count(array_filter(
        llamada_calls($fake, 'crm.activity.update'),
        fn(array $call): bool => (int)$call[1]['id'] === 731
    )), 'the panel path touches no technical activity: there is none');
} finally {
    llamada_test_cleanup($directory);
}

$invalidUpdateResults = [
    ['51515151-5151-4151-8151-515151515151', ['ok' => true, 'result' => false], 'false'],
    ['52525252-5252-4252-8252-525252525252', ['ok' => true], 'missing result'],
    ['53535353-5353-4353-8353-535353535353', ['ok' => true, 'result' => ['updated' => true]], 'unexpected result'],
];
foreach ($invalidUpdateResults as [$callRequestId, $invalidResponse, $label]) {
    [$store, $directory] = llamada_test_store();
    try {
        $fake = new FakeBitrix();
        $fake->responseQueues['crm.activity.update'] = [
            $invalidResponse,
            ['ok' => true, 'result' => true],
        ];
        $input = llamada_test_input([
            'callRequestId' => $callRequestId,
            'outcome' => 'not_interested',
            'comment' => 'No desea nuevas llamadas',
        ]);

        test_throws(
            fn() => llamada_procesar_resultado($input, $fake, $store, $now, $noInterestStage),
            LlamadaBitrixError::class,
            'activity update ' . $label . ' is not success'
        );
        test_same([], llamada_calls($fake, 'crm.timeline.comment.add'), 'activity update ' . $label . ' creates no comment');
        test_same([], llamada_calls($fake, 'crm.deal.update'), 'activity update ' . $label . ' changes no stage');
        $record = $store->get('member-1:' . $callRequestId);
        test_same('retryable', $record['state'] ?? null, 'activity update ' . $label . ' keeps retryable checkpoint');
        $progress = json_decode((string)($record['response_json'] ?? ''), true, 64, JSON_THROW_ON_ERROR);
        test_same(false, $progress['activityUpdated'] ?? null, 'activity update ' . $label . ' does not advance checkpoint');

        $store = new LlamadaIdempotenciaStore($directory);
        $retried = llamada_procesar_resultado($input, $fake, $store, $now, $noInterestStage);
        test_same('processed', $retried['status'], 'activity update ' . $label . ' retries successfully');
        $updates = llamada_calls($fake, 'crm.activity.update');
        test_same(2, count(array_filter(
            $updates,
            fn(array $call): bool => (int)$call[1]['id'] === 731
        )), 'activity update ' . $label . ' retries only the failed technical write');
        test_same(1, count(array_filter(
            $updates,
            fn(array $call): bool => (int)$call[1]['id'] === 630
        )), 'activity update ' . $label . ' completes the pending call once');
        test_same(1, count(llamada_calls($fake, 'crm.timeline.comment.add')), 'activity update ' . $label . ' creates one comment after retry');
        test_same(1, count(llamada_calls($fake, 'crm.deal.update')), 'activity update ' . $label . ' changes stage once after retry');
    } finally {
        llamada_test_cleanup($directory);
    }
}

$invalidStageResults = [
    ['61616161-6161-4161-8161-616161616161', ['ok' => true, 'result' => false], 'false'],
    ['62626262-6262-4262-8262-626262626262', ['ok' => true], 'missing result'],
    ['63636363-6363-4363-8363-636363636363', ['ok' => true, 'result' => (object)['updated' => true]], 'unexpected result'],
];
foreach ($invalidStageResults as [$callRequestId, $invalidResponse, $label]) {
    [$store, $directory] = llamada_test_store();
    try {
        $fake = new FakeBitrix();
        $fake->responseQueues['crm.deal.update'] = [
            $invalidResponse,
            ['ok' => true, 'result' => true],
        ];
        $input = llamada_test_input([
            'callRequestId' => $callRequestId,
            'outcome' => 'not_interested',
            'comment' => 'No desea nuevas llamadas',
        ]);

        test_throws(
            fn() => llamada_procesar_resultado($input, $fake, $store, $now, $noInterestStage),
            LlamadaBitrixError::class,
            'deal update ' . $label . ' is not success'
        );
        test_same(2, count(llamada_calls($fake, 'crm.activity.update')), 'deal update ' . $label . ' checkpoints technical and pending activities once');
        test_same(1, count(llamada_calls($fake, 'crm.timeline.comment.add')), 'deal update ' . $label . ' checkpoints comment once');
        $record = $store->get('member-1:' . $callRequestId);
        test_same('retryable', $record['state'] ?? null, 'deal update ' . $label . ' keeps retryable checkpoint');
        test_same('created', $record['comment_state'] ?? null, 'deal update ' . $label . ' preserves created comment checkpoint');

        $store = new LlamadaIdempotenciaStore($directory);
        $retried = llamada_procesar_resultado($input, $fake, $store, $now, $noInterestStage);
        test_same('processed', $retried['status'], 'deal update ' . $label . ' retries successfully');
        test_same(2, count(llamada_calls($fake, 'crm.activity.update')), 'deal update ' . $label . ' never duplicates activity');
        test_same(1, count(llamada_calls($fake, 'crm.timeline.comment.add')), 'deal update ' . $label . ' never duplicates comment');
        test_same(2, count(llamada_calls($fake, 'crm.deal.update')), 'deal update ' . $label . ' retries only stage');
    } finally {
        llamada_test_cleanup($directory);
    }
}

[$store, $directory] = llamada_test_store();
try {
    $fake = new FakeBitrix();
    $fake->deal['UF_CRM_1781115254387'] = '4';
    $fake->historyPages = [0 => [
        fake_activity(701, 'Llamada saliente Ana Pérez', '2026-08-10T09:00:00-05:00'),
        fake_activity(702, 'Llamada saliente Ana Pérez', '2026-08-11T09:00:00-05:00'),
        fake_activity(703, 'Llamada saliente Ana Pérez', '2026-08-12T09:00:00-05:00'),
        fake_activity(731, 'Llamada saliente Ana Pérez', '2026-08-20T16:00:00-05:00'),
    ]];
    $fake->reentryHistory = ['items' => [[
        'ID' => '900',
        'CREATED_TIME' => '2026-08-20T15:00:00-05:00',
    ]]];

    llamada_procesar_resultado(llamada_test_input([
        'callRequestId' => '13131313-1313-4131-8131-131313131313',
    ]), $fake, $store, $now, $noInterestStage);

    test_same(
        '2026-08-21T19:00:00-05:00',
        llamada_campos_planificada($fake)['START_TIME'],
        'three old attempts plus real reentry restart no-answer at one day'
    );
} finally {
    llamada_test_cleanup($directory);
}

[$store, $directory] = llamada_test_store();
try {
    $fake = new FakeBitrix();
    $fake->historyPages = [0 => [
        fake_activity(701, 'Llamada saliente Ana Pérez', '2026-08-10T09:00:00-05:00'),
        fake_activity(702, 'Llamada saliente Ana Pérez', '2026-08-11T09:00:00-05:00'),
        fake_activity(703, 'Llamada saliente Ana Pérez', '2026-08-12T09:00:00-05:00'),
        fake_activity(731, 'Llamada saliente Ana Pérez', '2026-08-20T16:00:00-05:00'),
    ]];

    llamada_procesar_resultado(llamada_test_input([
        'callRequestId' => '14141414-1414-4141-8141-141414141414',
    ]), $fake, $store, $now, $noInterestStage);

    test_same(
        '2026-11-27T19:00:00-05:00',
        llamada_campos_planificada($fake)['START_TIME'],
        'same three attempts without real reentry keep maintenance schedule'
    );
    test_same([], llamada_calls($fake, 'crm.stagehistory.list'), 'no reentry counter avoids stage-history lookup');
} finally {
    llamada_test_cleanup($directory);
}

$leaseClock = 1_000;
[$store, $directory] = llamada_test_store_with_clock($leaseClock);
try {
    $firstLease = $store->begin('lease-cas', 'same-hash', $leaseClock);
    test_same(true, is_string($firstLease['lease_token'] ?? null) && $firstLease['lease_token'] !== '', 'first reservation receives lease token');

    $leaseClock = 1_061;
    $secondLease = $store->begin('lease-cas', 'same-hash', $leaseClock);
    test_same(true, $secondLease['is_new'], 'expired reservation can be recovered after crash');
    test_same(true, $secondLease['lease_token'] !== $firstLease['lease_token'], 'recovered reservation receives a new token');

    test_throws(
        fn() => $store->checkpoint('lease-cas', '{"step":"old"}', null, null, $leaseClock, 'processing', $firstLease['lease_token']),
        LlamadaLeaseLost::class,
        'expired owner cannot checkpoint after reclaim'
    );
    $store->checkpoint('lease-cas', '{"step":"new"}', null, null, $leaseClock, 'processing', $secondLease['lease_token']);
    test_throws(
        fn() => $store->complete('lease-cas', '{"status":"old"}', 'skipped', null, $leaseClock, $firstLease['lease_token']),
        LlamadaLeaseLost::class,
        'expired owner cannot complete after reclaim'
    );
    test_same('{"step":"new"}', $store->get('lease-cas')['response_json'], 'new owner checkpoint survives stale owner attempts');
} finally {
    llamada_test_cleanup($directory);
}

$raceClock = 2_000;
[$store, $directory] = llamada_test_store_with_clock($raceClock);
try {
    $fake = new FakeBitrix();
    $input = llamada_test_input([
        'callRequestId' => '18181818-1818-4181-8181-181818181818',
        'outcome' => 'not_interested',
        'comment' => 'No desea nuevas llamadas',
    ]);
    $nestedResult = null;
    $raceTriggered = false;
    $fake->onCall = function (string $method) use (
        &$raceTriggered,
        &$raceClock,
        &$nestedResult,
        $input,
        $fake,
        $store,
        $now,
        $noInterestStage
    ): void {
        if ($method !== 'crm.timeline.comment.add' || $raceTriggered) return;
        $raceTriggered = true;
        $raceClock += 61;
        $nestedResult = llamada_procesar_resultado($input, $fake, $store, $now, $noInterestStage);
    };

    test_throws(
        fn() => llamada_procesar_resultado($input, $fake, $store, $now, $noInterestStage),
        LlamadaLeaseLost::class,
        'owner expiring during comment cannot checkpoint its response'
    );
    test_same('manual_review', $nestedResult['status'] ?? null, 'overlapping retry cannot reclaim uncertain comment');
    test_same(2, count(llamada_calls($fake, 'crm.activity.update')), 'lease race emits one technical and one pending activity update');
    test_same(1, count(llamada_calls($fake, 'crm.timeline.comment.add')), 'lease race emits one non-repeatable comment');
    test_same(0, count(llamada_calls($fake, 'crm.deal.update')), 'lease race does not change stage after uncertain comment');

    $callsAfterRace = $fake->calls;
    $review = llamada_procesar_resultado($input, $fake, $store, $now, $noInterestStage);
    test_same('manual_review', $review['status'], 'uncertain raced comment remains manual review');
    test_same($callsAfterRace, $fake->calls, 'manual review after lease race emits no duplicate effects');
} finally {
    llamada_test_cleanup($directory);
}

[$store, $directory] = llamada_test_store();
try {
    $fake = new FakeBitrix();
    $fake->responseQueues['crm.timeline.comment.add'] = [
        ['ok' => false, 'error' => 'bad-json'],
    ];
    $input = llamada_test_input([
        'callRequestId' => '16161616-1616-4161-8161-161616161616',
        'outcome' => 'not_interested',
        'comment' => 'No desea nuevas llamadas',
    ]);

    test_throws(
        fn() => llamada_procesar_resultado($input, $fake, $store, $now, $noInterestStage),
        LlamadaBitrixError::class,
        'uncertain comment response is surfaced'
    );
    $callsAfterUncertainResponse = $fake->calls;
    $retry = llamada_procesar_resultado($input, $fake, $store, $now, $noInterestStage);
    test_same('manual_review', $retry['status'], 'uncertain comment response requires manual review');
    test_same($callsAfterUncertainResponse, $fake->calls, 'uncertain comment response is never retried automatically');
    test_same([], llamada_calls($fake, 'crm.deal.update'), 'uncertain comment response leaves stage unchanged');
} finally {
    llamada_test_cleanup($directory);
}

[$store, $directory] = llamada_test_store();
try {
    $fake = new FakeBitrix();
    $fake->responseQueues['crm.deal.update'] = [
        ['ok' => false, 'error' => 'TEMPORARY_ERROR', 'desc' => 'temporary stage failure'],
        ['ok' => true, 'result' => true],
    ];
    $input = llamada_test_input([
        'callRequestId' => '12121212-1212-4121-8121-121212121212',
        'outcome' => 'not_interested',
    ]);

    test_throws(
        fn() => llamada_procesar_resultado($input, $fake, $store, $now, $noInterestStage),
        LlamadaBitrixError::class,
        'partial stage failure is surfaced'
    );
    test_same(2, count(llamada_calls($fake, 'crm.activity.update')), 'partial failure checkpoints technical and pending activity updates');
    test_same(1, count(llamada_calls($fake, 'crm.deal.update')), 'partial failure attempts stage once');
    test_same('retryable', $store->get('member-1:12121212-1212-4121-8121-121212121212')['state'], 'partial failure releases operation for retry');

    $store = new LlamadaIdempotenciaStore($directory);
    $retried = llamada_procesar_resultado($input, $fake, $store, $now, $noInterestStage);
    test_same('processed', $retried['status'], 'partial stage failure resumes successfully');
    test_same(true, $retried['stageChanged'], 'resumed stage update is reported');
    test_same(2, count(llamada_calls($fake, 'crm.activity.update')), 'resumed stage update does not duplicate activity update');
    test_same(2, count(llamada_calls($fake, 'crm.deal.update')), 'resumed operation retries only missing stage update');
} finally {
    llamada_test_cleanup($directory);
}

[$store, $directory] = llamada_test_store();
try {
    $fake = new FakeBitrix();
    $input = llamada_test_input([
        'callRequestId' => '15151515-1515-4151-8151-151515151515',
    ]);
    $normalized = [
        'callRequestId' => '15151515-1515-4151-8151-151515151515',
        'memberId' => 'member-1',
        'dealId' => 77,
        'bitrixUserId' => 42,
        'bitrixActivityId' => 731,
        'outcome' => 'no_answer',
        'selectedPhone' => '+593991234567',
        'nextActivityAt' => null,
        'comment' => '',
        'source' => 'mobile',
    ];
    $requestHash = hash('sha256', json_encode([
        'request' => $normalized,
        'noInterestStage' => $noInterestStage,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $store->begin('member-1:15151515-1515-4151-8151-151515151515', $requestHash, $store->now());

    $concurrent = llamada_procesar_resultado($input, $fake, $store, $now, $noInterestStage);
    test_same([
        'status' => 'processing',
        'callRequestId' => '15151515-1515-4151-8151-151515151515',
    ], $concurrent, 'active concurrent attempt reports processing');
    test_same([], $fake->calls, 'active concurrent attempt performs no external calls');
} finally {
    llamada_test_cleanup($directory);
}

[$store, $directory] = llamada_test_store();
try {
    $fake = new FakeBitrix();
    $result = llamada_procesar_resultado(llamada_test_input([
        'callRequestId' => '13131313-1313-4131-8131-131313131313',
        'outcome' => 'not_interested',
        'comment' => 'No desea nuevas llamadas',
    ]), $fake, $store, $now, $noInterestStage);
    $writeOrder = array_values(array_map(
        fn(array $call): string => $call[0],
        array_filter($fake->calls, fn(array $call): bool => in_array($call[0], [
            'crm.activity.update',
            'crm.timeline.comment.add',
            'crm.deal.update',
        ], true))
    ));
    test_same([
        'crm.activity.update',
        'crm.activity.update',
        'crm.timeline.comment.add',
        'crm.deal.update',
    ], $writeOrder, 'not interested writes comment before stage');
    test_same(true, $result['commentCreated'], 'not interested reports comment creation');
    test_same(true, $result['stageChanged'], 'not interested reports stage change');
    test_same(null, $result['nextActivityAt'], 'not interested reports no future activity');
} finally {
    llamada_test_cleanup($directory);
}

[$store, $directory] = llamada_test_store();
try {
    $fake = new FakeBitrix();
    $fake->responseQueues['crm.timeline.comment.add'] = [
        ['ok' => false, 'error' => 'INVALID_COMMENT', 'desc' => 'comment rejected'],
        ['ok' => true, 'result' => 801],
    ];
    $input = llamada_test_input([
        'callRequestId' => '14141414-1414-4141-8141-141414141414',
        'outcome' => 'not_interested',
        'comment' => 'No desea nuevas llamadas',
    ]);

    test_throws(
        fn() => llamada_procesar_resultado($input, $fake, $store, $now, $noInterestStage),
        LlamadaBitrixError::class,
        'known comment failure is surfaced'
    );
    test_same([], llamada_calls($fake, 'crm.deal.update'), 'known comment failure leaves stage unchanged');

    $retried = llamada_procesar_resultado($input, $fake, $store, $now, $noInterestStage);
    test_same('processed', $retried['status'], 'known comment failure is safely retryable');
    test_same(2, count(llamada_calls($fake, 'crm.activity.update')), 'known comment retry does not duplicate technical or pending updates');
    test_same(2, count(llamada_calls($fake, 'crm.timeline.comment.add')), 'known comment retry repeats only failed comment');
    test_same(1, count(llamada_calls($fake, 'crm.deal.update')), 'stage changes after retried comment succeeds');
} finally {
    llamada_test_cleanup($directory);
}

$activityMismatches = [
    ['RESPONSIBLE_ID', '99', 'cccccccc-cccc-4ccc-8ccc-cccccccccccc', 'activity responsible mismatch'],
    ['TYPE_ID', '1', 'dddddddd-dddd-4ddd-8ddd-dddddddddddd', 'activity type mismatch'],
    ['DIRECTION', '1', 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee', 'activity direction mismatch'],
];
foreach ($activityMismatches as [$field, $value, $callRequestId, $expectedMessage]) {
    [$store, $directory] = llamada_test_store();
    try {
        $fake = new FakeBitrix();
        $fake->activity[$field] = $value;
        test_throws_message(
            fn() => llamada_procesar_resultado(llamada_test_input([
                'callRequestId' => $callRequestId,
            ]), $fake, $store, $now, $noInterestStage),
            LlamadaForbidden::class,
            $expectedMessage,
            $expectedMessage . ' is rejected'
        );
        test_same([], llamada_calls($fake, 'crm.activity.update'), $expectedMessage . ' performs no activity write');
        test_same([], llamada_calls($fake, 'crm.deal.update'), $expectedMessage . ' performs no deal write');
        test_same([], llamada_calls($fake, 'crm.timeline.comment.add'), $expectedMessage . ' performs no comment write');
    } finally {
        llamada_test_cleanup($directory);
    }
}

[$store, $directory] = llamada_test_store();
try {
    $fake = new FakeBitrix();
    $fake->activity['COMMUNICATIONS'] = [[
        'VALUE' => '+593 (99) 765-4321',
        'ENTITY_ID' => '777',
        'ENTITY_TYPE_ID' => '3',
        'TYPE' => 'PHONE',
    ]];
    $fake->contact['PHONE'] = [[
        'ID' => '501',
        'VALUE' => '+593 99 000 0000',
        'VALUE_TYPE' => 'MOBILE',
    ]];
    $fake->pendingActivities[0]['COMMUNICATIONS'][0]['VALUE'] = '+593 99 765 4321';
    $result = llamada_procesar_resultado(llamada_test_input([
        'callRequestId' => '71717171-7171-4171-8171-717171717171',
        'selectedPhone' => '+593 99-765-4321',
    ]), $fake, $store, $now, $noInterestStage);
    test_same('processed', $result['status'], 'formatted selected number matches current activity communication');
} finally {
    llamada_test_cleanup($directory);
}

[$store, $directory] = llamada_test_store();
try {
    $fake = new FakeBitrix();
    $fake->activity['COMMUNICATIONS'] = [];
    $fake->contact['PHONE'] = [[
        'ID' => '501',
        'VALUE' => '593991234567',
        'VALUE_TYPE' => 'MOBILE',
    ]];
    $result = llamada_procesar_resultado(llamada_test_input([
        'callRequestId' => '72727272-7272-4272-8272-727272727271',
        'selectedPhone' => '+593991234567',
    ]), $fake, $store, $now, $noInterestStage);
    test_same('processed', $result['status'], 'country-code phone matches with or without a leading plus');
} finally {
    llamada_test_cleanup($directory);
}

[$store, $directory] = llamada_test_store();
try {
    $fake = new FakeBitrix();
    $fake->activity['COMMUNICATIONS'] = [];
    $fake->contact['PHONE'] = [
        ['ID' => '501', 'VALUE' => '099 111 1111', 'VALUE_TYPE' => 'MOBILE'],
        ['ID' => '502', 'VALUE' => '099 765 4321', 'VALUE_TYPE' => 'WORK'],
    ];
    $fake->pendingActivities[0]['COMMUNICATIONS'][0]['VALUE'] = '099 765 4321';
    $result = llamada_procesar_resultado(llamada_test_input([
        'callRequestId' => '72727272-7272-4272-8272-727272727272',
        'selectedPhone' => '099-765-4321',
    ]), $fake, $store, $now, $noInterestStage);
    test_same('processed', $result['status'], 'selected number matches a non-first primary-contact phone');
} finally {
    llamada_test_cleanup($directory);
}

[$store, $directory] = llamada_test_store();
try {
    $fake = new FakeBitrix();
    test_throws_message(
        fn() => llamada_procesar_resultado(llamada_test_input([
            'callRequestId' => '73737373-7373-4373-8373-737373737373',
            'selectedPhone' => '+593 99 999 9999',
        ]), $fake, $store, $now, $noInterestStage),
        LlamadaForbidden::class,
        'selected phone mismatch',
        'selected phone outside activity and contact context is rejected'
    );
    test_same([], llamada_calls($fake, 'crm.activity.list'), 'selected phone mismatch is rejected before protocol reads');
    test_same([], llamada_calls($fake, 'crm.activity.update'), 'selected phone mismatch performs no activity write');
    test_same([], llamada_calls($fake, 'crm.timeline.comment.add'), 'selected phone mismatch performs no comment write');
    test_same([], llamada_calls($fake, 'crm.deal.update'), 'selected phone mismatch performs no stage write');
} finally {
    llamada_test_cleanup($directory);
}

[$store, $directory] = llamada_test_store();
try {
    $fake = new FakeBitrix();
    test_throws(
        fn() => llamada_procesar_resultado(llamada_test_input([
            'callRequestId' => '74747474-7474-4474-8474-747474747474',
            'selectedPhone' => '+1234567890123456',
        ]), $fake, $store, $now, $noInterestStage),
        LlamadaValidationError::class,
        'selected phone longer than bridge E.164 limit is rejected'
    );
    test_same([], $fake->calls, 'overlong selected phone performs no Bitrix call');
} finally {
    llamada_test_cleanup($directory);
}

[$store, $directory] = llamada_test_store();
try {
    $fake = new FakeBitrix();
    $input = llamada_test_input([
        'callRequestId' => '22222222-2222-4222-8222-222222222222',
        'outcome' => 'answered',
        'selectedPhone' => '+593 99-123-4567',
        'nextActivityAt' => null,
        'comment' => '  Pide información del proyecto  ',
    ]);
    $result = llamada_procesar_resultado($input, $fake, $store, $now, $noInterestStage);

    test_same('processed', $result['status'], 'answered processed');
    $updates = llamada_calls($fake, 'crm.activity.update');
    test_same('App móvil · 1234 · Sí contestó', $updates[0][1]['fields']['SUBJECT'], 'answered closes technical call with the contacted marker');
    test_same([], llamada_calls($fake, 'crm.activity.add'), 'answered creates no future activity');
    test_same(null, $result['nextActivityAt'], 'answered reports no future activity');
    test_same(false, $result['stageChanged'], 'answered reports unchanged stage');
    test_same(true, $result['commentCreated'], 'answered reports created comment');
    test_same([], llamada_calls($fake, 'crm.deal.update'), 'answered preserves stage');
    $comments = llamada_calls($fake, 'crm.timeline.comment.add');
    test_same([ 'fields' => [
        'ENTITY_ID' => 77,
        'ENTITY_TYPE' => 'deal',
        'COMMENT' => 'Pide información del proyecto',
    ]], $comments[0][1], 'answered adds trimmed optional comment after activity');
    test_same(['crm.activity.update', 'crm.activity.update', 'crm.timeline.comment.add'], array_values(array_map(
        fn(array $call): string => $call[0],
        array_filter($fake->calls, fn(array $call): bool => in_array($call[0], ['crm.activity.update', 'crm.activity.add', 'crm.timeline.comment.add'], true))
    )), 'comment is written after activity');
} finally {
    llamada_test_cleanup($directory);
}

[$store, $directory] = llamada_test_store();
try {
    $fake = new FakeBitrix();
    test_throws(
        fn() => llamada_procesar_resultado(llamada_test_input([
            'callRequestId' => 'ffffffff-ffff-4fff-8fff-ffffffffffff',
            'outcome' => 'answered',
            'nextActivityAt' => '2026-08-25T10:15:00',
        ]), $fake, $store, $now, $noInterestStage),
        LlamadaValidationError::class,
        'answered rejects date without explicit zone'
    );
    test_same([], $fake->calls, 'ambiguous answered date performs no external calls');
} finally {
    llamada_test_cleanup($directory);
}

[$store, $directory] = llamada_test_store();
try {
    $fake = new FakeBitrix();
    test_throws(
        fn() => llamada_procesar_resultado(llamada_test_input([
            'callRequestId' => '17171717-1717-4171-8171-171717171717',
            'outcome' => 'answered',
            'nextActivityAt' => '2027-02-30T10:15:00-05:00',
        ]), $fake, $store, $now, $noInterestStage),
        LlamadaValidationError::class,
        'answered rejects nonexistent civil date'
    );
    test_same([], $fake->calls, 'nonexistent civil date performs no external calls');
} finally {
    llamada_test_cleanup($directory);
}

[$store, $directory] = llamada_test_store();
try {
    $fake = new FakeBitrix();
    $result = llamada_procesar_resultado(llamada_test_input([
        'callRequestId' => '33333333-3333-4333-8333-333333333333',
        'outcome' => 'not_interested',
    ]), $fake, $store, $now, $noInterestStage);

    test_same('processed', $result['status'], 'not interested processed');
    $fields = llamada_calls($fake, 'crm.activity.update')[0][1]['fields'];
    test_same('App móvil · No le interesa', $fields['SUBJECT'], 'not interested closes technical call as history');
    test_same('Y', $fields['COMPLETED'], 'not interested completes current activity');
    test_same(false, array_key_exists('START_TIME', $fields), 'not interested schedules no future activity');
    test_same([['crm.deal.update', ['id' => 77, 'fields' => ['STAGE_ID' => $noInterestStage]]]], llamada_calls($fake, 'crm.deal.update'), 'not interested changes only requested stage');
} finally {
    llamada_test_cleanup($directory);
}

[$store, $directory] = llamada_test_store();
try {
    $fake = new FakeBitrix();
    $fake->deal['STAGE_ID'] = $noInterestStage;
    llamada_procesar_resultado(llamada_test_input([
        'callRequestId' => '44444444-4444-4444-8444-444444444444',
        'outcome' => 'not_interested',
    ]), $fake, $store, $now, $noInterestStage);
    test_same([], llamada_calls($fake, 'crm.deal.update'), 'not interested avoids redundant stage update');
} finally {
    llamada_test_cleanup($directory);
}

[$store, $directory] = llamada_test_store();
try {
    $fake = new FakeBitrix();
    // Regla NUEVA (negocio, 25-ago-2026): quien llamo puede registrar la llamada
    // aunque el deal este a nombre de otro. Antes esto se rechazaba y se perdia
    // el registro de una llamada real.
    $fake->deal['ASSIGNED_BY_ID'] = '99';
    $result = llamada_procesar_resultado(llamada_test_input([
        'callRequestId' => '55555555-5555-4555-8555-555555555555',
    ]), $fake, $store, $now, $noInterestStage);
    test_same('processed', $result['status'], 'quien no es dueno del deal tambien registra');
    $agendada = llamada_campos_planificada($fake);
    test_same(false, $agendada === [], 'registrar sin ser dueno agenda la proxima');
    test_same(42, (int)$agendada['RESPONSIBLE_ID'], 'la actividad queda a nombre de quien llamo');
} finally {
    llamada_test_cleanup($directory);
}

[$store, $directory] = llamada_test_store();
try {
    $fake = new FakeBitrix();
    $fake->activity['OWNER_ID'] = '78';
    test_throws_message(
        fn() => llamada_procesar_resultado(llamada_test_input([
            'callRequestId' => '66666666-6666-4666-8666-666666666666',
        ]), $fake, $store, $now, $noInterestStage),
        LlamadaForbidden::class,
        'activity owner mismatch',
        'activity from another deal is rejected'
    );
    test_same([], llamada_calls($fake, 'crm.activity.update'), 'activity owner mismatch performs no write');
} finally {
    llamada_test_cleanup($directory);
}

[$store, $directory] = llamada_test_store();
try {
    $fake = new FakeBitrix();
    $input = llamada_test_input(['callRequestId' => '77777777-7777-4777-8777-777777777777']);
    llamada_procesar_resultado($input, $fake, $store, $now, $noInterestStage);
    $callsAfterFirst = $fake->calls;
    $retried = llamada_procesar_resultado($input, $fake, $store, $now, $noInterestStage);
    test_same('already_processed', $retried['status'], 'same key and payload returns prior result');
    test_same($callsAfterFirst, $fake->calls, 'same key and payload repeats no external calls');

    test_throws(
        fn() => llamada_procesar_resultado(array_replace($input, ['comment' => 'different']), $fake, $store, $now, $noInterestStage),
        LlamadaIdempotenciaConflict::class,
        'same key with different payload conflicts'
    );
    test_same($callsAfterFirst, $fake->calls, 'idempotency conflict performs no external calls');
} finally {
    llamada_test_cleanup($directory);
}

[$store, $directory] = llamada_test_store();
try {
    $fake = new FakeBitrix();
    $fake->errors['crm.deal.get'] = ['ok' => false, 'error' => 'ACCESS_DENIED', 'desc' => 'Access denied'];
    $input = llamada_test_input([
        'callRequestId' => '88888888-8888-4888-8888-888888888888',
    ]);
    test_throws(
        fn() => llamada_procesar_resultado($input, $fake, $store, $now, $noInterestStage),
        LlamadaForbidden::class,
        'Bitrix read access denied is forbidden'
    );
    $callsAfterFirst = $fake->calls;
    test_same('forbidden', $store->get('member-1:' . $input['callRequestId'])['state'] ?? null, 'read access denied is stored as terminal forbidden');

    unset($store);
    $store = new LlamadaIdempotenciaStore($directory);
    test_throws(
        fn() => llamada_procesar_resultado($input, $fake, $store, $now, $noInterestStage),
        LlamadaForbidden::class,
        'identical forbidden read remains forbidden after restart'
    );
    test_same($callsAfterFirst, $fake->calls, 'forbidden read retry performs no Bitrix call');
    test_throws(
        fn() => llamada_procesar_resultado(array_replace($input, ['comment' => 'different']), $fake, $store, $now, $noInterestStage),
        LlamadaIdempotenciaConflict::class,
        'different payload after forbidden read still conflicts'
    );
    test_same([], llamada_calls($fake, 'crm.activity.update'), 'read access denied performs no write');
    test_same([], llamada_calls($fake, 'crm.deal.update'), 'read access denied never reassigns or updates deal');
} finally {
    llamada_test_cleanup($directory);
}

[$store, $directory] = llamada_test_store();
try {
    $fake = new FakeBitrix();
    $fake->errors['crm.timeline.comment.add'] = ['ok' => false, 'error' => 'ACCESS_DENIED', 'desc' => 'Access denied'];
    $input = llamada_test_input([
        'callRequestId' => '18181818-1818-4181-8181-181818181818',
        'outcome' => 'answered',
        'nextActivityAt' => '2026-08-25T10:15:00-05:00',
        'comment' => 'Necesita seguimiento',
    ]);
    test_throws(
        fn() => llamada_procesar_resultado($input, $fake, $store, $now, $noInterestStage),
        LlamadaForbidden::class,
        'known comment access denied is forbidden'
    );
    $callsAfterFirst = $fake->calls;
    $record = $store->get('member-1:' . $input['callRequestId']);
    test_same('forbidden', $record['state'] ?? null, 'comment access denied is stored as terminal forbidden');
    test_same('pending', $record['comment_state'] ?? null, 'known comment denial is not delivery uncertain');
    test_same(2, count(llamada_calls($fake, 'crm.activity.update')), 'technical and pending activities are updated once before comment denial');
    test_same(1, count(llamada_calls($fake, 'crm.timeline.comment.add')), 'denied comment is attempted once');

    unset($store);
    $store = new LlamadaIdempotenciaStore($directory);
    test_throws(
        fn() => llamada_procesar_resultado($input, $fake, $store, $now, $noInterestStage),
        LlamadaForbidden::class,
        'identical denied comment remains forbidden after restart'
    );
    test_same($callsAfterFirst, $fake->calls, 'forbidden comment retry duplicates no external effect');
    test_throws(
        fn() => llamada_procesar_resultado(array_replace($input, ['comment' => 'different']), $fake, $store, $now, $noInterestStage),
        LlamadaIdempotenciaConflict::class,
        'different payload after forbidden comment still conflicts'
    );
} finally {
    llamada_test_cleanup($directory);
}

[$store, $directory] = llamada_test_store();
try {
    $fake = new FakeBitrix();
    $firstPage = [];
    for ($id = 1; $id <= 50; $id++) $firstPage[] = fake_activity($id, 'Llamada saliente', '2026-08-01T09:00:00-05:00');
    $fake->historyPages = [
        0 => $firstPage,
        50 => [fake_activity(51, '1234', '2026-08-19T09:00:00-05:00'), fake_activity(731, 'Llamada saliente', '2026-08-20T16:00:00-05:00')],
    ];
    llamada_procesar_resultado(llamada_test_input([
        'callRequestId' => '99999999-9999-4999-8999-999999999999',
    ]), $fake, $store, $now, $noInterestStage);
    $historyCalls = array_values(array_filter(
        llamada_calls($fake, 'crm.activity.list'),
        fn(array $call): bool => !array_key_exists('COMPLETED', $call[1]['filter'])
    ));
    test_same(2, count($historyCalls), 'activity history is paginated by id');
    test_same(50, $historyCalls[1][1]['filter']['>ID'], 'next history page starts after last id');
    test_same('2026-08-21T19:00:00-05:00', llamada_campos_planificada($fake)['START_TIME'], 'current activity is excluded after paginated answered marker');
} finally {
    llamada_test_cleanup($directory);
}

[$store, $directory] = llamada_test_store();
try {
    $fake = new FakeBitrix();
    $legacy = llamada_procesar_resultado(llamada_test_input([
        'callRequestId' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
        'outcome' => 'answered',
        'nextActivityAt' => '2026-08-25T10:15:00-05:00',
    ]), $fake, $store, $now, $noInterestStage);
    test_same(null, $legacy['nextActivityAt'], 'legacy answered date is ignored during PWA rollout');
    test_same([], llamada_calls($fake, 'crm.activity.add'), 'legacy answered date creates no future activity');
} finally {
    llamada_test_cleanup($directory);
}

[$store, $directory] = llamada_test_store();
try {
    $fake = new FakeBitrix();
    $fake->throwOnComment = true;
    $input = llamada_test_input([
        'callRequestId' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
        'outcome' => 'answered',
        'nextActivityAt' => '2026-08-25T10:15:00-05:00',
        'comment' => 'Necesita seguimiento',
    ]);
    test_throws(
        fn() => llamada_procesar_resultado($input, $fake, $store, $now, $noInterestStage),
        RuntimeException::class,
        'comment connection loss is surfaced'
    );
    $callsAfterFailure = $fake->calls;
    $retry = llamada_procesar_resultado($input, $fake, $store, $now, $noInterestStage);
    test_same('manual_review', $retry['status'], 'uncertain comment retry requires manual review');
    test_same($callsAfterFailure, $fake->calls, 'manual review retry does not duplicate external calls');
} finally {
    llamada_test_cleanup($directory);
}

// ---------------------------------------------------------------------------
// EL PANEL DE BITRIX NO TIENE POR QUE TENER UNA LLAMADA PENDIENTE.
//
// El 25-ago-2026 a las 17:36 el panel quedo mudo: 7 intentos de 3 vendedores
// (Adriana x3, Nicolas x2, Jesua x2) devolvieron 'pending_activity_not_found'
// sin registrar nada. El servicio solo sabia RESOLVER una llamada que ya
// existiera —la que crea la app del movil— y el panel no crea ninguna.
//
// Abrir la pestana ES la accion. Que no haya pendiente es normal.
// El registro de la llamada es la actividad planificada que se crea aca: en
// este portal UNA actividad registra la llamada que ocurrio y agenda la
// proxima. Por eso se exige EXACTAMENTE UNA: dos serian dos no-contestadas
// contadas por un solo boton.
[$store, $directory] = llamada_test_store();
try {
    $fake = new FakeBitrix();
    $fake->pendingActivities = [];                       // el panel no crea nada antes
    $result = llamada_procesar_resultado(llamada_test_input([
        'callRequestId' => '77777777-7777-4777-8777-777777777777',
        'bitrixActivityId' => null,
    ]), $fake, $store, $now, $noInterestStage, 'panel');

    test_same('processed', $result['status'], 'panel sin pendiente SI registra');
    $agendada = llamada_calls($fake, 'crm.activity.add');
    test_same(1, count($agendada), 'panel sin pendiente crea UNA sola actividad');
    test_same('N', $agendada[0][1]['fields']['COMPLETED'], 'la actividad creada agenda la proxima');
    test_same(42, (int)$agendada[0][1]['fields']['RESPONSIBLE_ID'], 'queda a nombre de quien llamo');
    test_same(false, str_contains($agendada[0][1]['fields']['SUBJECT'], '1234'), 'sin 1234: el motor la cuenta como NO contestada');
    test_same([], llamada_calls($fake, 'crm.activity.update'), 'panel sin pendiente no toca ninguna actividad vieja');
} finally {
    llamada_test_cleanup($directory);
}

// Y con pendiente el panel se comporta igual que antes: la cierra y agenda la
// proxima. Una sola actividad nueva, sin sello aparte.
[$store, $directory] = llamada_test_store();
try {
    $fake = new FakeBitrix();
    $result = llamada_procesar_resultado(llamada_test_input([
        'callRequestId' => '88888888-8888-4888-8888-888888888888',
        'bitrixActivityId' => null,
    ]), $fake, $store, $now, $noInterestStage, 'panel');

    test_same('processed', $result['status'], 'panel con pendiente registra');
    test_same(1, count(llamada_calls($fake, 'crm.activity.add')), 'panel con pendiente crea UNA sola actividad');
    $cerrada = llamada_calls($fake, 'crm.activity.update');
    test_same(1, count($cerrada), 'panel con pendiente cierra la pendiente');
    test_same(630, (int)$cerrada[0][1]['id'], 'cierra exactamente la pendiente que encontro');
    test_same(['COMPLETED' => 'Y'], $cerrada[0][1]['fields'], 'la cierra sin sello: el sello duplicaria el conteo');
} finally {
    llamada_test_cleanup($directory);
}

// ── EL SOBRANTE QUE NUNCA SE IBA (deal 401173, 26-ago-2026) ─────────────────
// Se cerraba SOLO la pendiente elegida, asi que las planificadas de este mismo
// servicio se apilaban: el deal quedaba con dos abiertas a la vez y —peor— el
// buscador devuelve null con mas de una candidata sin telefono, asi que desde
// la segunda la pulsacion del celular terminaba en manual_review y se perdia.
//
// Calcado de las reales: las planificadas que crea este servicio NO traen
// COMMUNICATIONS, solo la marca en DESCRIPTION. Por eso el criterio es la marca
// y no el telefono — comparar telefonos no habria hecho nada.
[$store, $directory] = llamada_test_store();
try {
    $fake = new FakeBitrix();
    $marca = 'Registrada desde la app de llamadas Galjosa. Referencia: ';
    $fake->pendingActivities = [
        ['ID' => '2513745', 'SUBJECT' => 'Llamada saliente Martín Lead',
         'DEADLINE' => '2026-12-02T17:30:00-05:00',
         'DESCRIPTION' => $marca . 'b2cceba5-a640-468d-ada8-613fe979d7ee'],
        ['ID' => '2513883', 'SUBJECT' => 'Llamada saliente Martín Lead',
         'DEADLINE' => '2026-12-03T20:30:00-05:00',
         'DESCRIPTION' => $marca . '5ad29af3-5f4d-441f-b190-4d43dfc5387c'],
        ['ID' => '2513759', 'SUBJECT' => 'llamar a deal nuevo',
         'DEADLINE' => '2026-08-26T06:25:00-05:00'],
    ];

    $salida = llamada_procesar_resultado(llamada_test_input([
        'callRequestId' => '20202020-2020-4020-8020-202020202020',
    ]), $fake, $store, $now, $noInterestStage);

    $cerradas = array_map(
        fn(array $call): int => (int)$call[1]['id'],
        llamada_calls($fake, 'crm.activity.update')
    );
    test_same(true, in_array(2513745, $cerradas, true),
        'the OLDER leftover created by this service is closed');
    test_same(false, in_array(2513759, $cerradas, true),
        'a pending call the seller scheduled by hand is NOT touched');
    // ⚠ NO se afirma 'processed' aqui: con una pendiente MANUAL tambien sin
    // telefono el buscador sigue sin poder decidir, y para 'mobile' eso termina
    // en manual_review por diseño de este servicio. Lo que este cambio garantiza
    // es que las nuestras no se apilen — la ambiguedad con pendientes ajenas es
    // otra decision, y no se toca aqui.
    test_same(1, count(array_filter($cerradas, fn(int $id): bool => $id === 2513745)),
        'the leftover is closed exactly once');
} finally {
    llamada_test_cleanup($directory);
}
