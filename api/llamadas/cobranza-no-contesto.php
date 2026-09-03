<?php
/**
 * cobranza-no-contesto.php — endpoint del botón de COBRANZAS (pipeline 48).
 *
 * Actúa CON EL TOKEN DE LA COBRADORA (el del iframe del placement), no con el
 * de la app: la actividad queda a su nombre y con sus permisos, que es el dato
 * con el que se la califica. La app solo existe para ser dueña del botón.
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/lib/cobranza-llamada-service.php';
// Reusa el constructor de $bx y el transporte del endpoint de ventas. Requerirlo
// NO ejecuta su despacho: esta guardado por realpath(SCRIPT_FILENAME)===__FILE__.
require_once $root . '/api/llamadas/no-contesto.php';

const COBRANZA_MAX_BODY = 65_536;

function cobranza_panel_error(int $status, string $error): array {
    return ['status' => $status, 'body' => ['error' => $error]];
}

function cobranza_panel_http(
    string $method,
    string $body,
    array $env,
    callable $userCurrent,
    callable $bx,
    int $now
): array {
    if (strtoupper($method) !== 'POST' || $body === '' || strlen($body) > COBRANZA_MAX_BODY) {
        return cobranza_panel_error(400, 'invalid_request');
    }
    try {
        $d = json_decode($body, false, 32, JSON_THROW_ON_ERROR);
        if (!$d instanceof stdClass) return cobranza_panel_error(400, 'invalid_request');

        $auth = $d->auth ?? null;
        if (!is_string($auth) || trim($auth) === '' || strlen($auth) > 4_096) {
            return cobranza_panel_error(400, 'invalid_request');
        }
        try { $userId = (int)$userCurrent($auth); } catch (Throwable) { $userId = 0; }
        if ($userId <= 0) return cobranza_panel_error(401, 'unauthorized');

        $r = cobranza_no_contesto([
            'dealId'        => $d->dealId ?? null,
            'bitrixUserId'  => $userId,
            'contactName'   => $d->contactName ?? '',
            'contactId'     => $d->contactId ?? 0,
            'selectedPhone' => $d->selectedPhone ?? '',
        ], $bx, new DateTimeImmutable('@' . $now));

        return ['status' => 200, 'body' => $r];
    } catch (JsonException) {
        return cobranza_panel_error(400, 'invalid_request');
    } catch (CobranzaLlamadaError $e) {
        $m = $e->getMessage();
        if ($m === 'invalid_request')  return cobranza_panel_error(400, 'invalid_request');
        if ($m === 'deal_not_found')   return cobranza_panel_error(404, 'deal_not_found');
        return cobranza_panel_error(503, 'bitrix_unavailable');
    } catch (Throwable) {
        return cobranza_panel_error(503, 'bitrix_unavailable');
    }
}

function cobranza_panel_production_http(string $method, string $body, array $env, int $now, ?callable $transport = null): array {
    $decoded = json_decode($body, true);
    $auth = is_array($decoded) && is_string($decoded['auth'] ?? null) ? trim($decoded['auth']) : '';
    $domain = trim((string)($env['BITRIX_DOMAIN'] ?? 'galjosa.bitrix24.com'));
    $bx = llamada_no_contesto_panel_bx($auth, $domain, $transport);
    // 🔴 'user.current' EXIGE el scope 'user', y la app de cobranzas se creó con
    // CRM + placement nada más: el botón devolvía 401 unauthorized en un deal
    // perfectamente normal. 'profile' devuelve al usuario actual y NO pide scope,
    // así que se intenta primero. 'user.current' queda de respaldo para el día que
    // la app sí tenga el scope, y para que un cambio de permisos no rompa nada.
    $currentUser = static function (string $token) use ($domain, $transport): int {
        $c = llamada_no_contesto_panel_bx($token, $domain, $transport);
        foreach (['profile', 'user.current'] as $m) {
            $r = $c($m, []);
            if (($r['ok'] ?? false) !== true || !is_array($r['result'] ?? null)) continue;
            $id = (int)($r['result']['ID'] ?? 0);
            if ($id > 0) return $id;
        }
        return 0;
    };
    return cobranza_panel_http($method, $body, $env, $currentUser, $bx, $now);
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    $environment = getenv();
    $response = cobranza_panel_production_http(
        (string)($_SERVER['REQUEST_METHOD'] ?? 'GET'),
        (string)file_get_contents('php://input'),
        is_array($environment) ? ($_ENV + $environment) : $_ENV,
        time()
    );
    http_response_code((int)$response['status']);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($response['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
