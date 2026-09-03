<?php
/**
 * cobranza-appauth.php — tokens OAuth de la app local de COBRANZAS.
 * ---------------------------------------------------------------------------
 * Copia deliberada de appauth.php, NO un parametro suyo. Son dos apps distintas
 * en Bitrix y cada una tiene su client_id, su refresh_token y su ciclo de vida:
 * reinstalar la de cobranzas no puede tocar los placements de ventas, que es
 * justo lo que se pidio. Compartir el archivo de tokens las ataria.
 *
 * Archivo:  /data/cobranza_auth.json   (0600)
 * Entorno:  COB_APP_CLIENT_ID · COB_APP_CLIENT_SECRET
 * ---------------------------------------------------------------------------
 */
declare(strict_types=1);

function cob_auth_path(): string {
    return (getenv('DATA_DIR') ?: '/data') . '/cobranza_auth.json';
}

function cob_auth_guardar(array $a): void {
    $prev = cob_auth_cargar();
    $datos = [
        'access_token'  => (string)($a['access_token']  ?? $a['AUTH_ID']    ?? $prev['access_token']  ?? ''),
        'refresh_token' => (string)($a['refresh_token'] ?? $a['REFRESH_ID'] ?? $prev['refresh_token'] ?? ''),
        'client_id'     => (string)($a['client_id']     ?? $prev['client_id']     ?? ''),
        'client_secret' => (string)($a['client_secret'] ?? $prev['client_secret'] ?? ''),
        'domain'        => (string)($a['domain']        ?? $a['DOMAIN']     ?? $prev['domain'] ?? ''),
        'expires'       => time() + 3300,
    ];
    @file_put_contents(cob_auth_path(), json_encode($datos));
    @chmod(cob_auth_path(), 0600);
}

function cob_auth_cargar(): array {
    $j = json_decode((string)@file_get_contents(cob_auth_path()), true);
    $j = is_array($j) ? $j : [];
    if (($j['client_id'] ?? '') === '')     $j['client_id']     = (string)getenv('COB_APP_CLIENT_ID');
    if (($j['client_secret'] ?? '') === '') $j['client_secret'] = (string)getenv('COB_APP_CLIENT_SECRET');
    return $j;
}

function cob_auth_refrescar(): bool {
    $a = cob_auth_cargar();
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
    cob_auth_guardar($j);
    return true;
}

/** Llama al REST como la APLICACION de cobranzas (OAuth), no como webhook. */
function cob_app_bx(string $method, array $params = [], bool $reintento = true): array {
    $a = cob_auth_cargar();
    if (($a['access_token'] ?? '') === '' || ($a['domain'] ?? '') === '') {
        return ['ok' => false, 'error' => 'not-installed',
                'desc' => 'la app de cobranzas no esta instalada todavia'];
    }
    $ch = curl_init('https://' . $a['domain'] . '/rest/' . $method . '.json');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($params + ['auth' => $a['access_token']]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    $raw = curl_exec($ch); curl_close($ch);
    $j = json_decode((string)$raw, true);
    if (!is_array($j)) return ['ok' => false, 'error' => 'bad-json'];
    if (isset($j['error'])) {
        // token vencido: se refresca UNA vez y se reintenta. Sin el reintento la
        // app se muere sola cada hora y hay que abrirla a mano.
        if ($reintento && in_array((string)$j['error'], ['expired_token', 'invalid_token'], true)
            && cob_auth_refrescar()) {
            return cob_app_bx($method, $params, false);
        }
        return ['ok' => false, 'error' => (string)$j['error'],
                'desc' => (string)($j['error_description'] ?? '')];
    }
    return ['ok' => true, 'result' => $j['result'] ?? null];
}
