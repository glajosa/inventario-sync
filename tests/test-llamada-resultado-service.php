<?php
declare(strict_types=1);

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
    ];
    public array $contact = [
        'ID' => '91',
        'NAME' => 'Ana',
        'LAST_NAME' => 'Pérez',
    ];
    public array $historyPages = [];
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
            'crm.activity.update' => ['ok' => true, 'result' => true],
            'crm.timeline.comment.add' => $this->commentResult(),
            'crm.deal.update' => ['ok' => true, 'result' => true],
            default => ['ok' => false, 'error' => 'unexpected-method', 'desc' => $method],
        };
    }

    private function historyPage(array $params): array {
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
    return [new LlamadaIdempotenciaStore($directory), $directory];
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

$now = new DateTimeImmutable('2026-08-20T16:30:00-05:00');
$noInterestStage = 'C28:NO_INTERESADO';

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
    $updates = llamada_calls($fake, 'crm.activity.update');
    test_same(1, count($updates), 'no answer updates one existing activity');
    test_same(731, $updates[0][1]['id'], 'no answer updates requested activity id');
    test_same([
        'OWNER_TYPE_ID' => 2,
        'OWNER_ID' => 77,
        'TYPE_ID' => 2,
        'DIRECTION' => 2,
        'PROVIDER_ID' => 'VOXIMPLANT_CALL',
        'PROVIDER_TYPE_ID' => 'CALL',
        'SUBJECT' => 'Llamada saliente Ana Pérez',
        'COMPLETED' => 'N',
        'RESPONSIBLE_ID' => 42,
        'START_TIME' => '2026-08-21T19:00:00-05:00',
        'END_TIME' => '2026-08-21T20:00:00-05:00',
        'DEADLINE' => '2026-08-21T19:00:00-05:00',
        'PRIORITY' => 2,
        'NOTIFY_TYPE' => 1,
        'NOTIFY_VALUE' => 15,
        'DESCRIPTION_TYPE' => 1,
        'COMMUNICATIONS' => [[
            'VALUE' => '+593991234567',
            'ENTITY_ID' => 91,
            'ENTITY_TYPE_ID' => 3,
            'TYPE' => 'PHONE',
        ]],
    ], $updates[0][1]['fields'], 'no answer writes complete pending activity fields');
    test_same([], llamada_calls($fake, 'crm.deal.update'), 'no answer preserves stage');
    test_same([], llamada_calls($fake, 'crm.timeline.comment.add'), 'empty comment creates no timeline entry');
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
    test_same(1, count(llamada_calls($fake, 'crm.activity.update')), 'lease race emits one activity update');
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
    test_same(1, count(llamada_calls($fake, 'crm.activity.update')), 'partial failure checkpoints successful activity update');
    test_same(1, count(llamada_calls($fake, 'crm.deal.update')), 'partial failure attempts stage once');
    test_same('retryable', $store->get('member-1:12121212-1212-4121-8121-121212121212')['state'], 'partial failure releases operation for retry');

    $store = new LlamadaIdempotenciaStore($directory);
    $retried = llamada_procesar_resultado($input, $fake, $store, $now, $noInterestStage);
    test_same('processed', $retried['status'], 'partial stage failure resumes successfully');
    test_same(true, $retried['stageChanged'], 'resumed stage update is reported');
    test_same(1, count(llamada_calls($fake, 'crm.activity.update')), 'resumed stage update does not duplicate activity update');
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
    ];
    $requestHash = hash('sha256', json_encode([
        'request' => $normalized,
        'noInterestStage' => $noInterestStage,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $store->begin('member-1:15151515-1515-4151-8151-151515151515', $requestHash, $now->getTimestamp());

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
    test_same(1, count(llamada_calls($fake, 'crm.activity.update')), 'known comment retry does not duplicate activity update');
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
    $input = llamada_test_input([
        'callRequestId' => '22222222-2222-4222-8222-222222222222',
        'outcome' => 'answered',
        'selectedPhone' => '+593 99-123-4567',
        'nextActivityAt' => '2026-08-25T15:15:00Z',
        'comment' => '  Pide información del proyecto  ',
    ]);
    $result = llamada_procesar_resultado($input, $fake, $store, $now, $noInterestStage);

    test_same('processed', $result['status'], 'answered processed');
    $updates = llamada_calls($fake, 'crm.activity.update');
    test_same('1234', $updates[0][1]['fields']['SUBJECT'], 'answered keeps dashboard marker');
    test_same('N', $updates[0][1]['fields']['COMPLETED'], 'answered future activity remains pending');
    test_same('2026-08-25T10:15:00-05:00', $updates[0][1]['fields']['START_TIME'], 'answered uses requested future date');
    test_same('2026-08-25T10:15:00-05:00', $result['nextActivityAt'], 'answered response normalizes date to Guayaquil');
    test_same(false, $result['stageChanged'], 'answered reports unchanged stage');
    test_same(true, $result['commentCreated'], 'answered reports created comment');
    test_same('+593991234567', $updates[0][1]['fields']['COMMUNICATIONS'][0]['VALUE'], 'answered normalizes selected phone');
    test_same([], llamada_calls($fake, 'crm.deal.update'), 'answered preserves stage');
    $comments = llamada_calls($fake, 'crm.timeline.comment.add');
    test_same([ 'fields' => [
        'ENTITY_ID' => 77,
        'ENTITY_TYPE' => 'deal',
        'COMMENT' => 'Pide información del proyecto',
    ]], $comments[0][1], 'answered adds trimmed optional comment after activity');
    test_same(['crm.activity.update', 'crm.timeline.comment.add'], array_values(array_map(
        fn(array $call): string => $call[0],
        array_filter($fake->calls, fn(array $call): bool => in_array($call[0], ['crm.activity.update', 'crm.timeline.comment.add'], true))
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
    test_same('1234 · No le interesa', $fields['SUBJECT'], 'not interested uses dashboard-compatible marker');
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
    $fake->deal['ASSIGNED_BY_ID'] = '99';
    test_throws_message(
        fn() => llamada_procesar_resultado(llamada_test_input([
            'callRequestId' => '55555555-5555-4555-8555-555555555555',
        ]), $fake, $store, $now, $noInterestStage),
        LlamadaForbidden::class,
        'deal owner mismatch',
        'different deal owner is rejected'
    );
    test_same([], llamada_calls($fake, 'crm.activity.update'), 'deal owner mismatch performs no activity write');
    test_same([], llamada_calls($fake, 'crm.deal.update'), 'deal owner mismatch performs no deal write');
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
    $fake->errors['crm.activity.update'] = ['ok' => false, 'error' => 'ACCESS_DENIED', 'desc' => 'Access denied'];
    test_throws_message(
        fn() => llamada_procesar_resultado(llamada_test_input([
            'callRequestId' => '88888888-8888-4888-8888-888888888888',
        ]), $fake, $store, $now, $noInterestStage),
        LlamadaBitrixError::class,
        'Access denied',
        'Bitrix access denied is understandable'
    );
    test_same(1, count(llamada_calls($fake, 'crm.activity.update')), 'access denied is not retried with another user');
    test_same([], llamada_calls($fake, 'crm.deal.update'), 'access denied never reassigns or updates deal');
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
    $historyCalls = llamada_calls($fake, 'crm.activity.list');
    test_same(2, count($historyCalls), 'activity history is paginated by id');
    test_same(50, $historyCalls[1][1]['filter']['>ID'], 'next history page starts after last id');
    test_same('2026-08-21T19:00:00-05:00', llamada_calls($fake, 'crm.activity.update')[0][1]['fields']['START_TIME'], 'current activity is excluded after paginated answered marker');
} finally {
    llamada_test_cleanup($directory);
}

[$store, $directory] = llamada_test_store();
try {
    $fake = new FakeBitrix();
    test_throws(
        fn() => llamada_procesar_resultado(llamada_test_input([
            'callRequestId' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'outcome' => 'answered',
            'nextActivityAt' => null,
        ]), $fake, $store, $now, $noInterestStage),
        LlamadaValidationError::class,
        'answered requires next activity date'
    );
    test_same([], $fake->calls, 'invalid answered request performs no external reads or writes');
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
