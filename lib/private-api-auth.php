<?php
declare(strict_types=1);

final class PrivateApiUnauthorized extends RuntimeException {}

function private_api_verify(string $body, array $headers, string $secret, int $now): void {
    $timestamp = filter_var($headers['x-galjosa-timestamp'] ?? null, FILTER_VALIDATE_INT);
    $received = strtolower(trim((string)($headers['x-galjosa-signature'] ?? '')));

    if ($timestamp === false || abs($now - $timestamp) > 300 || !preg_match('/^[a-f0-9]{64}$/', $received)) {
        throw new PrivateApiUnauthorized('invalid signature metadata');
    }

    $expected = hash_hmac('sha256', $timestamp . "\n" . $body, $secret);
    if (!hash_equals($expected, $received)) {
        throw new PrivateApiUnauthorized('invalid signature');
    }
}
