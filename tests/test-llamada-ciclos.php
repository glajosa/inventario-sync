<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../lib/llamada-idempotencia.php';

$directory = sys_get_temp_dir() . '/inventario-sync-call-cycles-' . bin2hex(random_bytes(8));
mkdir($directory, 0700, true);

try {
    $store = new LlamadaIdempotenciaStore($directory, static fn(): int => 1_000);

    $first = $store->claimCycle('member:a', 77, 42, 630, 'mobile', 'no_answer', 1_000);
    test_same(true, $first['is_new'], 'first cycle claim wins');

    $samePending = $store->claimCycle('panel:b', 77, 42, 630, 'panel', 'no_answer', 1_001);
    test_same(false, $samePending['is_new'], 'same pending activity is shared across channels');
    test_same('member:a', $samePending['operation_key'], 'same pending duplicate points to first operation');

    $crossChannel = $store->claimCycle('panel:c', 77, 42, 631, 'panel', 'no_answer', 1_002);
    test_same(false, $crossChannel['is_new'], 'recent mobile then panel is one result');
    test_same('member:a', $crossChannel['operation_key'], 'cross-channel duplicate points to first operation');

    $otherMobileCall = $store->claimCycle('member:d', 77, 42, 631, 'mobile', 'no_answer', 1_003);
    test_same(true, $otherMobileCall['is_new'], 'different mobile calls remain independently registrable');

    $otherDeal = $store->claimCycle('panel:e', 78, 42, 700, 'panel', 'no_answer', 1_004);
    test_same(true, $otherDeal['is_new'], 'another deal is independent');

    $afterWindow = $store->claimCycle('panel:f', 77, 42, 632, 'panel', 'no_answer', 2_804);
    test_same(true, $afterWindow['is_new'], 'panel may register a later call after the duplicate window');

    test_throws(
        fn() => $store->claimCycle('bad-source', 77, 42, 633, 'browser', 'no_answer', 3_000),
        InvalidArgumentException::class,
        'unknown cycle source is rejected'
    );
    test_throws(
        fn() => $store->claimCycle('bad-outcome', 77, 42, 634, 'mobile', 'wrong', 3_000),
        InvalidArgumentException::class,
        'unknown cycle outcome is rejected'
    );
} finally {
    unset($store);
    $databasePath = $directory . '/llamada-resultados.sqlite';
    if (is_file($databasePath)) unlink($databasePath);
    if (is_dir($directory)) rmdir($directory);
}

echo "OK\n";
