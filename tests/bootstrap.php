<?php
declare(strict_types=1);

function test_same(mixed $expected, mixed $actual, string $name): void {
    if ($expected !== $actual) {
        throw new RuntimeException($name . "\nexpected=" . var_export($expected, true) . "\nactual=" . var_export($actual, true));
    }
}

function test_throws(callable $callback, string $className, string $name): void {
    try {
        $callback();
    } catch (Throwable $error) {
        if ($error instanceof $className) return;
        throw new RuntimeException($name . ': clase inesperada ' . $error::class);
    }
    throw new RuntimeException($name . ': no lanzó excepción');
}

function test_throws_message(callable $callback, string $className, string $messagePart, string $name): void {
    try {
        $callback();
    } catch (Throwable $error) {
        if (!$error instanceof $className) {
            throw new RuntimeException($name . ': clase inesperada ' . $error::class);
        }
        if (!str_contains($error->getMessage(), $messagePart)) {
            throw new RuntimeException($name . ': mensaje inesperado ' . $error->getMessage());
        }
        return;
    }
    throw new RuntimeException($name . ': no lanzó excepción');
}

function fake_activity(int $id, string $subject, string $created): array {
    return ['ID' => $id, 'TYPE_ID' => 2, 'DIRECTION' => 2, 'SUBJECT' => $subject, 'CREATED' => $created];
}
