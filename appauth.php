<?php
/**
 * appauth.php — tokens OAuth de la aplicación local de Bitrix.
 * ---------------------------------------------------------------------------
 * La app local es necesaria para registrar un TIPO DE CAMPO propio
 * (userfieldtype.add): el webhook entrante no puede hacerlo.
 *
 * Bitrix entrega access_token (1h) + refresh_token al instalar. Se guardan en
 * /data y se refrescan solos cuando expiran.
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

function auth_path(): string {
    return (getenv('DATA_DIR') ?: '/data') . '/app_auth.json';
}

function auth_guardar(array $a): void {
    $prev = auth_cargar();
    $datos = [
        'access_token'  => (string)($a['access_token']  ?? $a['AUTH_ID']    ?? $prev['access_token']  ?? ''),
        'refresh_token' => (string)($a['refresh_token'] ?? $a['REFRESH_ID'] ?? $prev['refresh_token'] ?? ''),
        'client_id'     => (string)($a['client_id']     ?? $prev['client_id']     ?? ''),
        'client_secret' => (string)($a['client_secret'] ?? $prev['client_secret'] ?? ''),
        'domain'        => (string)($a['domain']        ?? $a['DOMAIN']     ?? $prev['domain'] ?? ''),
        'expires'       => time() + 3300,   // ~55 min, se refresca antes de la hora
    ];
    @file_put_contents(auth_path(), json_encode($datos));
    @chmod(auth_path(), 0600);
}

function auth_cargar(): array {
    $j = json_decode((string)@file_get_contents(auth_path()), true);
    return is_array($j) ? $j : [];
}

/** Renueva el access_token con el refresh_token. Devuelve true si quedó válido. */
function auth_refrescar(): bool {
    $a = auth_cargar();
    if (($a['refresh_token'] ?? '') === '' || ($a['client_id'] ?? '') === '') return false;
    $url = 'https://oauth.bitrix.info/oauth/token/?' . http_build_query([
        'grant_type'    => 'refresh_token',
        'client_id'     => $a['client_id'],
        'client_secret' => $a['client_secret'],
        'refresh_token' => $a['refresh_token'],
    ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
    $raw = curl_exec($ch); curl_close($ch);
    $j = json_decode((string)$raw, true);
    if (!is_array($j) || empty($j['access_token'])) return false;
    $j['client_id']     = $a['client_id'];
    $j['client_secret'] = $a['client_secret'];
    $j['domain']        = $a['domain'];
    auth_guardar($j);
    return true;
}

/** Llama al API REST como la aplicación (con OAuth), no como webhook. */
function app_bx(string $method, array $params = [], bool $reintento = true): array {
    $a = auth_cargar();
    if (($a['access_token'] ?? '') === '' || ($a['domain'] ?? '') === '') {
        return ['ok' => false, 'error' => 'app-sin-instalar'];
    }
    if (time() > (int)($a['expires'] ?? 0)) { auth_refrescar(); $a = auth_cargar(); }

    $url = 'https://' . $a['domain'] . '/rest/' . $method;
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($params + ['auth' => $a['access_token']]),
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
    ]);
    $raw = curl_exec($ch); curl_close($ch);
    $j = json_decode((string)$raw, true);

    if (is_array($j) && isset($j['error'])) {
        // token vencido -> refrescar una vez y repetir
        if ($reintento && in_array($j['error'], ['expired_token', 'invalid_token', 'NO_AUTH_FOUND'], true)) {
            if (auth_refrescar()) return app_bx($method, $params, false);
        }
        return ['ok' => false, 'error' => (string)$j['error'],
                'desc' => (string)($j['error_description'] ?? '')];
    }
    if (!is_array($j)) return ['ok' => false, 'error' => 'bad-json'];
    return ['ok' => true, 'result' => $j['result'] ?? null];
}
