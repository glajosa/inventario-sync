<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$entrypoint = (string)file_get_contents(dirname(__DIR__) . '/entrypoint.sh');
test_same(true, str_contains($entrypoint, 'LAB_READ_ONLY'), 'entrypoint supports isolated read-only labs');
test_same(true, str_contains($entrypoint, 'warm-catalogo.php'), 'read-only lab can refresh the source catalog');
test_same(true, str_contains($entrypoint, 'reconcile.php'), 'production startup keeps reconciliation');

