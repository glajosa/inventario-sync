<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/lib/llamada-resultado-service.php';

const LLAMADA_PANEL_MAX_BODY_BYTES = 65_536;

function llamada_no_contesto_panel_error(int $status, string $error): array {
    return ['status' => $status, 'body' => ['error' => $error]];
}

function llamada_no_contesto_panel_http(
    string $method,
    string $body,
    array $env,
    callable $userCurrent,
    callable $bx,
    int $now
): array {
    if (strtoupper($method) !== 'POST'
        || $body === ''
        || strlen($body) > LLAMADA_PANEL_MAX_BODY_BYTES) {
        return llamada_no_contesto_panel_error(400, 'invalid_request');
    }

    $dataDir = trim((string)($env['DATA_DIR'] ?? ''));
    $noInterestStage = trim((string)($env['NO_INTEREST_STAGE_ID'] ?? ''));
    if ($dataDir === '' || $noInterestStage === '') {
        return llamada_no_contesto_panel_error(503, 'bitrix_unavailable');
    }

    try {
        $decoded = json_decode($body, false, 32, JSON_THROW_ON_ERROR);
        if (!$decoded instanceof stdClass) {
            return llamada_no_contesto_panel_error(400, 'invalid_request');
        }
        $auth = $decoded->auth ?? null;
        if (!is_string($auth) || trim($auth) === '' || strlen($auth) > 4_096) {
            return llamada_no_contesto_panel_error(400, 'invalid_request');
        }

        try {
            $bitrixUserId = (int)$userCurrent($auth);
        } catch (Throwable) {
            $bitrixUserId = 0;
        }
        if ($bitrixUserId <= 0) {
            return llamada_no_contesto_panel_error(401, 'unauthorized');
        }

        $store = new LlamadaIdempotenciaStore($dataDir);
        $result = llamada_procesar_resultado([
            'callRequestId' => $decoded->requestId ?? null,
            'memberId' => 'panel-' . $bitrixUserId,
            'dealId' => $decoded->dealId ?? null,
            'bitrixUserId' => $bitrixUserId,
            'bitrixActivityId' => null,
            'outcome' => 'no_answer',
            'selectedPhone' => $decoded->selectedPhone ?? null,
            'nextActivityAt' => null,
            'comment' => $decoded->comment ?? '',
        ], $bx, $store, new DateTimeImmutable('@' . $now), $noInterestStage, 'panel');

        $status = (string)($result['status'] ?? '');
        if ($status === 'processed' || $status === 'already_processed') {
            return ['status' => 200, 'body' => [
                'status' => $status,
                'requestId' => (string)$result['callRequestId'],
                'outcome' => (string)$result['outcome'],
                'nextActivityAt' => $result['nextActivityAt'] ?? null,
            ]];
        }
        if ($status === 'manual_review') {
            return ['status' => 422, 'body' => [
                'status' => 'manual_review',
                'requestId' => (string)$result['callRequestId'],
                'reason' => (string)($result['reason'] ?? 'manual_review'),
            ]];
        }
        if ($status === 'processing') {
            return [
                'status' => 503,
                'headers' => ['Retry-After' => '1'],
                'body' => [
                    'status' => 'processing',
                    'requestId' => (string)$result['callRequestId'],
                    'reason' => 'processing',
                ],
            ];
        }
        return llamada_no_contesto_panel_error(503, 'bitrix_unavailable');
    } catch (JsonException | LlamadaValidationError) {
        return llamada_no_contesto_panel_error(400, 'invalid_request');
    } catch (LlamadaForbidden) {
        return llamada_no_contesto_panel_error(403, 'forbidden');
    } catch (LlamadaIdempotenciaConflict) {
        return llamada_no_contesto_panel_error(409, 'conflict');
    } catch (LlamadaBitrixError) {
        return llamada_no_contesto_panel_error(503, 'bitrix_unavailable');
    } catch (Throwable) {
        return llamada_no_contesto_panel_error(503, 'bitrix_unavailable');
    }
}

function llamada_no_contesto_panel_transport(string $url, array $params): array {
    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($params),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
    ]);
    $raw = curl_exec($handle);
    $error = curl_errno($handle);
    $status = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);
    return [
        'status' => $status,
        'body' => is_string($raw) ? $raw : '',
        'error' => $error,
    ];
}

function llamada_no_contesto_panel_bx(
    string $auth,
    string $domain,
    ?callable $transport = null
): callable {
    $transport ??= 'llamada_no_contesto_panel_transport';
    $domain = strtolower(trim($domain));

    return static function (string $method, array $params) use ($auth, $domain, $transport): array {
        if ($auth === ''
            || preg_match('/^[a-z0-9.-]+$/D', $domain) !== 1
            || preg_match('/^[a-z0-9_.]+$/iD', $method) !== 1) {
            return ['ok' => false, 'error' => 'not-configured'];
        }
        try {
            $response = $transport(
                'https://' . $domain . '/rest/' . $method . '.json',
                $params + ['auth' => $auth]
            );
        } catch (Throwable) {
            return ['ok' => false, 'error' => 'network-error'];
        }
        if (!is_array($response) || (int)($response['error'] ?? 0) !== 0) {
            return ['ok' => false, 'error' => 'network-error'];
        }
        $decoded = json_decode((string)($response['body'] ?? ''), true);
        if (!is_array($decoded)) return ['ok' => false, 'error' => 'bad-json'];
        if (array_key_exists('error', $decoded)) {
            return [
                'ok' => false,
                'error' => (string)$decoded['error'],
                'desc' => (string)($decoded['error_description'] ?? ''),
            ];
        }
        return ['ok' => true, 'result' => $decoded['result'] ?? null];
    };
}

function llamada_no_contesto_panel_production_http(
    string $method,
    string $body,
    array $env,
    int $now,
    ?callable $transport = null
): array {
    $decoded = json_decode($body, true);
    $auth = is_array($decoded) && is_string($decoded['auth'] ?? null)
        ? trim($decoded['auth'])
        : '';
    $domain = trim((string)($env['BITRIX_DOMAIN'] ?? 'galjosa.bitrix24.com'));
    $bx = llamada_no_contesto_panel_bx($auth, $domain, $transport);
    $currentUser = static function (string $token) use ($domain, $transport): int {
        $caller = llamada_no_contesto_panel_bx($token, $domain, $transport);
        $response = $caller('user.current', []);
        return ($response['ok'] ?? false) === true && is_array($response['result'] ?? null)
            ? (int)($response['result']['ID'] ?? 0)
            : 0;
    };
    return llamada_no_contesto_panel_http($method, $body, $env, $currentUser, $bx, $now);
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    $environment = getenv();
    $response = llamada_no_contesto_panel_production_http(
        (string)($_SERVER['REQUEST_METHOD'] ?? 'GET'),
        (string)file_get_contents('php://input'),
        is_array($environment) ? ($_ENV + $environment) : $_ENV,
        time()
    );
    http_response_code((int)$response['status']);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    foreach (($response['headers'] ?? []) as $name => $value) {
        header($name . ': ' . $value);
    }
    echo json_encode($response['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
