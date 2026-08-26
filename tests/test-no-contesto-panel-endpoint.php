<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../lib/llamada-resultado-service.php';
$endpointPath = __DIR__ . '/../api/llamadas/no-contesto.php';
if (is_file($endpointPath)) require_once $endpointPath;

final class PanelEndpointFakeBitrix {
    public array $calls = [];
    public array $errors = [];
    public array $deal = [
        'ID' => '77',
        'ASSIGNED_BY_ID' => '42',
        'CONTACT_ID' => '91',
        'STAGE_ID' => 'C28:INTERESADO',
    ];
    public array $pending = [[
        'ID' => '630',
        'SUBJECT' => 'Llamada pendiente',
        'DEADLINE' => '2026-08-20T10:00:00-05:00',
        'RESPONSIBLE_ID' => '42',
        'COMPLETED' => 'N',
        'COMMUNICATIONS' => [[
            'VALUE' => '+593991234567',
            'TYPE' => 'PHONE',
        ]],
    ]];

    public function __invoke(string $method, array $params): array {
        $this->calls[] = [$method, $params];
        if (isset($this->errors[$method])) return $this->errors[$method];

        return match ($method) {
            'crm.deal.get' => ['ok' => true, 'result' => $this->deal],
            'crm.activity.get' => ['ok' => true, 'result' => [
                'ID' => '731',
                'OWNER_ID' => '77',
                'OWNER_TYPE_ID' => '2',
                'TYPE_ID' => '2',
                'DIRECTION' => '2',
                'RESPONSIBLE_ID' => '42',
                'COMMUNICATIONS' => [[
                    'VALUE' => '+593991234567',
                    'TYPE' => 'PHONE',
                ]],
            ]],
            'crm.contact.get' => ['ok' => true, 'result' => [
                'ID' => '91',
                'NAME' => 'Ana',
                'LAST_NAME' => 'Pérez',
                'PHONE' => [[
                    'ID' => '501',
                    'VALUE' => '+593991234567',
                    'VALUE_TYPE' => 'MOBILE',
                ]],
            ]],
            'crm.activity.list' => ['ok' => true, 'result' =>
                ($params['filter']['COMPLETED'] ?? null) === 'N' ? $this->pending : []],
            'crm.activity.update' => ['ok' => true, 'result' => true],
            'crm.activity.add' => ['ok' => true, 'result' => 901],
            'crm.timeline.comment.add' => ['ok' => true, 'result' => 801],
            'crm.deal.update' => ['ok' => true, 'result' => true],
            default => ['ok' => false, 'error' => 'unexpected-method', 'desc' => $method],
        };
    }
}

function panel_endpoint_dir(): string {
    $directory = sys_get_temp_dir() . '/inventario-sync-panel-endpoint-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700, true);
    return $directory;
}

function panel_endpoint_cleanup(string $directory): void {
    unset($GLOBALS['panel_endpoint_store']);
    foreach (['llamada-resultados.sqlite', 'llamada-resultados.sqlite-shm', 'llamada-resultados.sqlite-wal'] as $name) {
        $path = $directory . '/' . $name;
        if (is_file($path)) unlink($path);
    }
    if (is_dir($directory)) rmdir($directory);
}

function panel_endpoint_env(string $directory): array {
    return [
        'DATA_DIR' => $directory,
        'NO_INTEREST_STAGE_ID' => 'C28:NO_INTERESADO',
    ];
}

function panel_endpoint_body(array $changes = []): string {
    return json_encode(array_replace([
        'requestId' => '33333333-3333-4333-8333-333333333333',
        'auth' => 'seller-token',
        'dealId' => 77,
        'selectedPhone' => '+593991234567',
        'comment' => '',
    ], $changes), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function panel_endpoint_writes(PanelEndpointFakeBitrix $fake): array {
    return array_values(array_filter(
        $fake->calls,
        fn(array $call): bool => in_array($call[0], [
            'crm.activity.update',
            'crm.activity.add',
            'crm.timeline.comment.add',
            'crm.deal.update',
        ], true)
    ));
}

$now = (new DateTimeImmutable('2026-08-20T16:30:00-05:00'))->getTimestamp();
$currentUser = fn(string $token): int => $token === 'seller-token' ? 42 : 0;

$capturedTransport = [];
$caller = llamada_no_contesto_panel_bx(
    'seller-token',
    'galjosa.bitrix24.com',
    static function (string $url, array $params) use (&$capturedTransport): array {
        $capturedTransport = ['url' => $url, 'params' => $params];
        return [
            'status' => 200,
            'body' => json_encode(['result' => ['ID' => '42']], JSON_THROW_ON_ERROR),
            'error' => 0,
        ];
    }
);
test_same(['ok' => true, 'result' => ['ID' => '42']], $caller('user.current', []), 'seller-token caller decodes Bitrix response');
test_same('https://galjosa.bitrix24.com/rest/user.current.json', $capturedTransport['url'], 'seller-token caller uses the configured Bitrix domain');
test_same(['auth' => 'seller-token'], $capturedTransport['params'], 'seller-token caller sends the token only to Bitrix');

$directory = panel_endpoint_dir();
try {
    $fake = new PanelEndpointFakeBitrix();
    $response = llamada_no_contesto_panel_http(
        'POST',
        panel_endpoint_body(),
        panel_endpoint_env($directory),
        $currentUser,
        $fake,
        $now
    );
    test_same(200, $response['status'], 'valid seller request is accepted');
    test_same([
        'status' => 'processed',
        'requestId' => '33333333-3333-4333-8333-333333333333',
        'outcome' => 'no_answer',
        'nextActivityAt' => '2026-08-21T19:00:00-05:00',
    ], $response['body'], 'panel returns the shared no-answer result');
    test_same([630], array_map(
        fn(array $call): int => (int)$call[1]['id'],
        array_values(array_filter($fake->calls, fn(array $call): bool => $call[0] === 'crm.activity.update'))
    ), 'panel completes only the pending activity');
    test_same(1, count(array_filter($fake->calls, fn(array $call): bool => $call[0] === 'crm.activity.add')), 'panel creates one future activity');

    $writes = panel_endpoint_writes($fake);
    $duplicate = llamada_no_contesto_panel_http(
        'POST',
        panel_endpoint_body(['requestId' => '34343434-3434-4434-8434-343434343434']),
        panel_endpoint_env($directory),
        $currentUser,
        $fake,
        $now + 1
    );
    test_same(200, $duplicate['status'], 'duplicate panel request remains successful');
    test_same('already_processed', $duplicate['body']['status'], 'duplicate panel request reports prior result');
    test_same($writes, panel_endpoint_writes($fake), 'duplicate panel request repeats no write');
} finally {
    panel_endpoint_cleanup($directory);
}

$invalidCases = [
    ['GET', panel_endpoint_body(), $currentUser, 400],
    ['POST', '{', $currentUser, 400],
    ['POST', panel_endpoint_body(), fn(string $token): int => 0, 401],
];
foreach ($invalidCases as [$method, $body, $userResolver, $expectedStatus]) {
    $directory = panel_endpoint_dir();
    try {
        $fake = new PanelEndpointFakeBitrix();
        $response = llamada_no_contesto_panel_http(
            $method,
            $body,
            panel_endpoint_env($directory),
            $userResolver,
            $fake,
            $now
        );
        test_same($expectedStatus, $response['status'], 'invalid panel request status');
        test_same([], panel_endpoint_writes($fake), 'invalid panel request performs no write');
    } finally {
        panel_endpoint_cleanup($directory);
    }
}

$directory = panel_endpoint_dir();
try {
    $fake = new PanelEndpointFakeBitrix();
    // Regla NUEVA (negocio, 25-ago-2026): "cada uno sabe que a su deal le tiene
    // que dar gestion, asi que no importa quien presione el boton de no contesto".
    // Antes esto devolvia 403 y se perdia el registro de una llamada real.
    $fake->deal['ASSIGNED_BY_ID'] = '99';
    $response = llamada_no_contesto_panel_http(
        'POST', panel_endpoint_body(), panel_endpoint_env($directory), $currentUser, $fake, $now
    );
    test_same(200, $response['status'], 'quien no es dueno del deal tambien registra');
    test_same(true, panel_endpoint_writes($fake) !== [], 'registrar un deal ajeno SI escribe');
} finally {
    panel_endpoint_cleanup($directory);
}

$directory = panel_endpoint_dir();
try {
    $fake = new PanelEndpointFakeBitrix();
    $store = new LlamadaIdempotenciaStore($directory, static fn(): int => $now);
    $GLOBALS['panel_endpoint_store'] = $store;
    $answered = llamada_procesar_resultado([
        'callRequestId' => '35353535-3535-4535-8535-353535353535',
        'memberId' => 'member-1',
        'dealId' => 77,
        'bitrixUserId' => 42,
        'bitrixActivityId' => 731,
        'outcome' => 'answered',
        'selectedPhone' => '+593991234567',
        'nextActivityAt' => null,
        'comment' => '',
    ], $fake, $store, new DateTimeImmutable('@' . $now), 'C28:NO_INTERESADO', 'mobile');
    test_same('processed', $answered['status'], 'answered result is seeded');

    $response = llamada_no_contesto_panel_http(
        'POST', panel_endpoint_body(), panel_endpoint_env($directory), $currentUser, $fake, $now + 1
    );
    test_same(409, $response['status'], 'no answer cannot replace an answered result');
    test_same(0, count(array_filter($fake->calls, fn(array $call): bool => $call[0] === 'crm.activity.add')), 'outcome conflict creates no future activity');
} finally {
    unset($store);
    panel_endpoint_cleanup($directory);
}

$directory = panel_endpoint_dir();
try {
    $fake = new PanelEndpointFakeBitrix();
    $fake->pending = [
        ['ID' => '640', 'SUBJECT' => 'Pendiente A', 'DEADLINE' => '2026-08-19T10:00:00-05:00'],
        ['ID' => '641', 'SUBJECT' => 'Pendiente B', 'DEADLINE' => '2026-08-20T10:00:00-05:00'],
    ];
    $response = llamada_no_contesto_panel_http(
        'POST', panel_endpoint_body(), panel_endpoint_env($directory), $currentUser, $fake, $now
    );
    test_same(422, $response['status'], 'ambiguous pending activity requires manual review');
    test_same('pending_activity_not_found', $response['body']['reason'], 'manual review reports its reason');
    test_same([], panel_endpoint_writes($fake), 'ambiguous pending activity performs no write');
} finally {
    panel_endpoint_cleanup($directory);
}

$directory = panel_endpoint_dir();
try {
    $fake = new PanelEndpointFakeBitrix();
    $fake->errors['crm.activity.update'] = ['ok' => false, 'error' => 'NETWORK', 'desc' => 'temporary'];
    $response = llamada_no_contesto_panel_http(
        'POST', panel_endpoint_body(), panel_endpoint_env($directory), $currentUser, $fake, $now
    );
    test_same(503, $response['status'], 'Bitrix write failure stays retryable');
    test_same(0, count(array_filter($fake->calls, fn(array $call): bool => $call[0] === 'crm.activity.add')), 'failed pending update creates no future activity');
} finally {
    panel_endpoint_cleanup($directory);
}

echo "OK\n";
