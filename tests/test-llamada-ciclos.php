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

    $recentWithoutPending = $store->findCycle(77, 42, null, 'panel', 1_002);
    test_same('member:a', $recentWithoutPending['operation_key'], 'panel finds a recent mobile result without claiming a new cycle');

    $otherMobileCall = $store->claimCycle('member:d', 77, 42, 631, 'mobile', 'no_answer', 1_003);
    test_same(true, $otherMobileCall['is_new'], 'different mobile calls remain independently registrable');

    $otherDeal = $store->claimCycle('panel:e', 78, 42, 700, 'panel', 'no_answer', 1_004);
    test_same(true, $otherDeal['is_new'], 'another deal is independent');

    $afterWindow = $store->claimCycle('panel:f', 77, 42, 632, 'panel', 'no_answer', 2_804);
    test_same(true, $afterWindow['is_new'], 'panel may register a later call after the duplicate window');

    $nothingRecent = $store->findCycle(79, 42, null, 'panel', 2_804);
    test_same(null, $nothingRecent, 'cycle lookup does not create a record when none exists');

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

// ── LAS TRABADAS TIENEN QUE VERSE (27-ago-2026) ─────────────────────────────
// Una operacion en 'processing' bloquea el deal: toda pulsacion nueva devuelve
// 'processing' y el vendedor ve "No se pudo registrar" sin salida.
//
// Son DOS clases distintas y no se tratan igual:
//   nunca avanzo (updated_at == created_at)  -> no escribio nada, se da por
//                                               muerta sola y se puede reintentar
//   avanzo algo  (updated_at != created_at)  -> escribio a medias; desbloquearla
//                                               podria duplicar. Solo se MUESTRA.
$dirT = sys_get_temp_dir() . '/inventario-sync-trabadas-' . bin2hex(random_bytes(8));
mkdir($dirT, 0700, true);
try {
    $ahora = 1_000_000;
    $store = new LlamadaIdempotenciaStore($dirT, static fn(): int => $GLOBALS['ahoraT'] ?? 1_000);
    $pdo = (function () { return $this->pdo; })->call($store);
    $meter = function (string $clave, int $creada, int $actualizada, int $lease) use ($pdo) {
        $pdo->prepare("INSERT INTO result_operations
            (idempotency_key, request_hash, state, created_at, updated_at, lease_until)
            VALUES (?, 'h', 'processing', ?, ?, ?)")
            ->execute([$clave, $creada, $actualizada, $lease]);
    };
    $meter('viva',          $ahora - 10,  $ahora - 5,   $ahora + 60);   // lease vigente
    $meter('nunca-avanzo',  $ahora - 500, $ahora - 500, $ahora - 100);  // muerta, se cura sola
    $meter('trabada',       $ahora - 500, $ahora - 400, $ahora - 100);  // TRABADA de verdad

    $t = $store->trabadas($ahora);
    test_same(1, count($t), 'solo la que avanzo y murio cuenta como trabada');
    test_same('trabada', $t[0]['clave'], 'y es esa, no las otras dos');

    test_same(0, count($store->trabadas($ahora - 1000)),
        'con el lease todavia vigente ninguna esta trabada');
} finally {
    array_map('unlink', glob($dirT . '/*') ?: []);
    @rmdir($dirT);
}
