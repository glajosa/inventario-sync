<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/lib/llamada-resultado-service.php';
require_once $root . '/lib/cola-no-contesto.php';

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
        /* ⭐ EL PEDIDO SE GUARDA EN UNA VARIABLE, no se arma dentro de la llamada.
         * Hace falta tenerlo a mano para poder ENCOLARLO si Bitrix falla: la cola
         * reproduce la pulsación entera, no un resumen. */
        $pedido = [
            'callRequestId' => $decoded->requestId ?? null,
            'memberId' => 'panel-' . $bitrixUserId,
            'dealId' => $decoded->dealId ?? null,
            'bitrixUserId' => $bitrixUserId,
            'bitrixActivityId' => null,
            'outcome' => 'no_answer',
            'selectedPhone' => $decoded->selectedPhone ?? null,
            'nextActivityAt' => null,
            'comment' => $decoded->comment ?? '',
        ];
        /* ⭐⭐ SE GUARDA ANTES DE INTENTAR, NO DESPUES DE FALLAR.
         *
         * 🔴 Por que: encolar en el `catch` solo cubre los fallos que se pueden
         * atrapar. Si el proceso MUERE a mitad —Bitrix cuelga y se agota el tiempo
         * de ejecucion, o se reinicia el contenedor con la peticion en vuelo— no
         * corre ningun catch, y la pulsacion se pierde igual.
         *
         * Medido el 9-sep-2026: con la cola ya desplegada, una pulsacion de las
         * 14:32 (uid 111820) quedo en `processing` sin tocar y NO estaba en la
         * cola. El unico modo de que eso pase es que el proceso no llegara a
         * ningun `catch`. Guardar primero lo cierra: la fila ya existe antes de
         * que Bitrix pueda colgarse.
         *
         * Cuesta una escritura local por pulsacion (~2.000/dia, SQLite en disco)
         * y se limpia sola: las `hecha` se borran a los 7 dias. */
        llamada_no_contesto_panel_guardar_primero($dataDir, $pedido, $now, $noInterestStage);

        $result = llamada_procesar_resultado(
            $pedido, $bx, $store, new DateTimeImmutable('@' . $now), $noInterestStage, 'panel'
        );

        $status = (string)($result['status'] ?? '');
        if ($status === 'processed' || $status === 'already_processed') {
            // salio por el camino rapido: la fila guardada ya no hace falta
            llamada_no_contesto_panel_cerrar($dataDir, $pedido, 'hecha');
            return ['status' => 200, 'body' => [
                'status' => $status,
                'requestId' => (string)$result['callRequestId'],
                'outcome' => (string)$result['outcome'],
                'nextActivityAt' => $result['nextActivityAt'] ?? null,
            ]];
        }
        if ($status === 'manual_review') {
            // lo tiene que mirar una persona: reintentarlo 60 veces no lo arregla
            llamada_no_contesto_panel_cerrar($dataDir, $pedido, 'revision manual');
            return ['status' => 422, 'body' => [
                'status' => 'manual_review',
                'requestId' => (string)$result['callRequestId'],
                'reason' => (string)($result['reason'] ?? 'manual_review'),
            ]];
        }
        /* ⭐⭐ SATURADO NO ES UN ERROR PARA EL VENDEDOR: SE ENCOLA.
         *
         * Antes esto devolvía 503 y el vendedor tenía que volver a aplastar. Y si
         * no volvía, la pulsación moría: medido el 9-sep-2026, había 13
         * operaciones en `processing` que NADIE retomó nunca —4 de ese mismo día—.
         *
         * Ahora la pulsación queda guardada con su hora y
         * bin/drenar-no-contesto.php la crea cuando el portal respira. El
         * vendedor recibe 200 y sigue trabajando. */
        if ($status === 'processing') {
            return llamada_no_contesto_panel_encolar(
                $dataDir, $pedido, $now, $noInterestStage, 'processing'
            );
        }
        return llamada_no_contesto_panel_encolar(
            $dataDir, $pedido, $now, $noInterestStage, 'estado ' . ($status !== '' ? $status : 'desconocido')
        );
    } catch (JsonException | LlamadaValidationError) {
        // un pedido mal formado NO se encola: reproducirlo fallaría igual
        return llamada_no_contesto_panel_error(400, 'invalid_request');
    } catch (LlamadaForbidden) {
        // sin permiso: la cola no consigue permisos que no existen
        if (isset($pedido)) llamada_no_contesto_panel_cerrar($dataDir, $pedido, 'sin permiso');
        return llamada_no_contesto_panel_error(403, 'forbidden');
    } catch (LlamadaIdempotenciaConflict) {
        // otra pulsacion del mismo ciclo la tiene agarrada: esa es la que vale
        if (isset($pedido)) llamada_no_contesto_panel_cerrar($dataDir, $pedido, 'hecha');
        return llamada_no_contesto_panel_error(409, 'conflict');
    } catch (LlamadaBitrixError $error) {
        return llamada_no_contesto_panel_encolar(
            $dataDir, $pedido ?? [], $now, $noInterestStage, 'bitrix: ' . $error->getMessage()
        );
    } catch (Throwable $error) {
        return llamada_no_contesto_panel_encolar(
            $dataDir, $pedido ?? [], $now, $noInterestStage, get_class($error) . ': ' . $error->getMessage()
        );
    }
}

/**
 * Deja la pulsación guardada ANTES de tocar Bitrix.
 *
 * Silencioso a propósito: si la cola no se puede abrir, el camino rápido tiene que
 * seguir funcionando igual. Lo que no puede pasar es lo contrario —responderle
 * "encolada" sin haberla guardado—, y de eso se encarga
 * llamada_no_contesto_panel_encolar(), que sí verifica.
 */
function llamada_no_contesto_panel_guardar_primero(
    string $dataDir, array $pedido, int $now, string $stage
): void {
    $requestId = (string)($pedido['callRequestId'] ?? '');
    if ($requestId === '') return;
    try {
        cola_nc_encolar(cola_nc_db($dataDir), $requestId, $pedido, $now, 'panel', $stage,
            'guardada antes de intentar');
    } catch (Throwable) {
        // se sigue: el catch de abajo la vuelve a intentar guardar si Bitrix falla
    }
}

/**
 * Cierra la fila guardada: `hecha` si ya se resolvió, o `fallida` si no tiene
 * sentido reintentarla (sin permiso, revisión manual).
 */
function llamada_no_contesto_panel_cerrar(string $dataDir, array $pedido, string $motivo): void {
    $requestId = (string)($pedido['callRequestId'] ?? '');
    if ($requestId === '') return;
    try {
        $db = cola_nc_db($dataDir);
        if ($motivo === 'hecha') cola_nc_hecha($db, $requestId);
        else cola_nc_fallo($db, $requestId, $motivo, 1);
    } catch (Throwable) {
        // no se pierde nada: el drenador la vera y el servicio es idempotente
    }
}

/**
 * Guarda la pulsación y le responde al vendedor que quedó registrada.
 *
 * ⚠ SI LA COLA MISMA FALLA, se vuelve al 503 de antes. Decirle "encolada" sin
 * haberla guardado seria la peor de las mentiras: el vendedor se va tranquilo y
 * la llamada no existe en ninguna parte.
 */
function llamada_no_contesto_panel_encolar(
    string $dataDir, array $pedido, int $now, string $stage, string $motivo
): array {
    $requestId = (string)($pedido['callRequestId'] ?? '');
    if ($requestId === '') return llamada_no_contesto_panel_error(503, 'bitrix_unavailable');
    try {
        $db = cola_nc_db($dataDir);
        if (!cola_nc_encolar($db, $requestId, $pedido, $now, 'panel', $stage, $motivo)) {
            return llamada_no_contesto_panel_error(503, 'bitrix_unavailable');
        }
    } catch (Throwable) {
        return llamada_no_contesto_panel_error(503, 'bitrix_unavailable');
    }
    return ['status' => 200, 'body' => [
        'status' => 'encolada',
        'requestId' => $requestId,
        'reason' => 'portal_saturado',
        'mensaje' => 'Queda registrada. Se crea sola en cuanto Bitrix responda; no hace falta volver a aplastar.',
    ]];
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
