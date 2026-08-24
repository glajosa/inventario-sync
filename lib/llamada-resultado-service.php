<?php
declare(strict_types=1);

require_once __DIR__ . '/llamada-idempotencia.php';
require_once __DIR__ . '/llamada-protocolo.php';

final class LlamadaValidationError extends InvalidArgumentException {}
final class LlamadaForbidden extends RuntimeException {}
final class LlamadaBitrixError extends RuntimeException {
    public function __construct(string $message, private bool $deliveryUncertain = false) {
        parent::__construct($message);
    }

    public function isDeliveryUncertain(): bool {
        return $this->deliveryUncertain;
    }
}

function llamada_procesar_resultado(
    array $input,
    callable $bx,
    LlamadaIdempotenciaStore $store,
    DateTimeImmutable $now,
    string $noInterestStage
): array {
    $request = llamada_validar_resultado($input, $now, $noInterestStage);
    $idempotencyKey = ($request['memberId'] !== '' ? $request['memberId'] . ':' : '') . $request['callRequestId'];
    $requestHash = hash('sha256', json_encode([
        'request' => $request,
        'noInterestStage' => $noInterestStage,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $operation = $store->begin($idempotencyKey, $requestHash, $store->now());

    if (!$operation['is_new'] && (string)$operation['state'] === 'forbidden') {
        throw new LlamadaForbidden('request was previously forbidden');
    }
    if (!$operation['is_new'] && (string)$operation['state'] === 'completed') {
        return llamada_resultado_repetido($operation, $request['callRequestId']);
    }
    if (!$operation['is_new']
        && (string)($operation['comment_state'] ?? '') === 'in_progress'
        && $operation['comment_id'] === null) {
        return [
            'status' => 'manual_review',
            'callRequestId' => $request['callRequestId'],
            'reason' => 'comment_delivery_uncertain',
        ];
    }
    if (!$operation['is_new']) {
        return [
            'status' => 'processing',
            'callRequestId' => $request['callRequestId'],
        ];
    }
    $leaseToken = (string)($operation['lease_token'] ?? '');
    if ($leaseToken === '') throw new LlamadaLeaseLost('idempotency reservation has no lease token');

    try {
    $progress = llamada_cargar_progreso($operation['response_json'] ?? null);
    $commentState = isset($operation['comment_state']) ? (string)$operation['comment_state'] : null;
    $commentId = $operation['comment_id'] === null ? null : (int)$operation['comment_id'];

    $dealId = $request['dealId'];
    $bitrixUserId = $request['bitrixUserId'];
    $activityId = $request['bitrixActivityId'];

    $deal = llamada_bx_array($bx, 'crm.deal.get', ['id' => $dealId]);
    if ((int)($deal['ID'] ?? 0) !== $dealId) {
        throw new LlamadaForbidden('deal id mismatch');
    }
    if ((int)($deal['ASSIGNED_BY_ID'] ?? 0) !== $bitrixUserId) {
        throw new LlamadaForbidden('deal owner mismatch');
    }

    $activity = llamada_bx_array($bx, 'crm.activity.get', ['id' => $activityId]);
    if ((int)($activity['ID'] ?? 0) !== $activityId
        || (int)($activity['OWNER_TYPE_ID'] ?? 0) !== 2
        || (int)($activity['OWNER_ID'] ?? 0) !== $dealId) {
        throw new LlamadaForbidden('activity owner mismatch');
    }
    if ((int)($activity['TYPE_ID'] ?? 0) !== 2) {
        throw new LlamadaForbidden('activity type mismatch');
    }
    if ((int)($activity['DIRECTION'] ?? 0) !== 2) {
        throw new LlamadaForbidden('activity direction mismatch');
    }
    if ((int)($activity['RESPONSIBLE_ID'] ?? 0) !== $bitrixUserId) {
        throw new LlamadaForbidden('activity responsible mismatch');
    }

    $contactId = (int)($deal['CONTACT_ID'] ?? 0);
    $contact = [];
    if ($contactId > 0) {
        $contact = llamada_bx_array($bx, 'crm.contact.get', ['id' => $contactId]);
        if ((int)($contact['ID'] ?? 0) !== $contactId) {
            throw new LlamadaForbidden('contact id mismatch');
        }
    }
    if (!llamada_telefono_pertenece_contexto($request['selectedPhone'], $activity, $contact)) {
        throw new LlamadaForbidden('selected phone mismatch');
    }

    $reentry = $request['outcome'] === 'no_answer'
        ? llamada_ultimo_reingreso($bx, $deal, $dealId)
        : null;
    $history = llamada_historial_actividades($bx, $dealId);
    $protocol = llamada_calcular_protocolo($history, $activityId, $reentry);
    $contactName = trim(implode(' ', array_filter([
        trim((string)($contact['NAME'] ?? '')),
        trim((string)($contact['LAST_NAME'] ?? '')),
    ], fn(string $part): bool => $part !== '')));
    if ($contactName === '') $contactName = 'cliente';

    $nextAt = null;
    if ($request['outcome'] === 'not_interested') {
        $fields = llamada_campos_actividad_completada([
            'dealId' => $dealId,
            'responsibleId' => $bitrixUserId,
            'contactId' => $contactId,
            'selectedPhone' => $request['selectedPhone'],
            'subject' => '1234 · No le interesa',
        ]);
    } else {
        if (is_string($progress['nextActivityAt']) && $progress['nextActivityAt'] !== '') {
            $nextAt = new DateTimeImmutable($progress['nextActivityAt']);
        } else {
            $nextAt = $request['outcome'] === 'answered'
                ? new DateTimeImmutable($request['nextActivityAt'])
                : llamada_proxima_no_contesto(
                    $protocol,
                    $now->setTimezone(new DateTimeZone('America/Guayaquil'))
                )['at'];
        }
        $nextAt = $nextAt->setTimezone(new DateTimeZone('America/Guayaquil'));
        $fields = llamada_campos_actividad([
            'nextAt' => $nextAt,
            'dealId' => $dealId,
            'subject' => $request['outcome'] === 'answered' ? '1234' : 'Llamada saliente ' . $contactName,
            'completed' => 'N',
            'responsibleId' => $bitrixUserId,
            'contactId' => $contactId,
            'selectedPhone' => $request['selectedPhone'],
        ]);
    }

    $progress['nextActivityAt'] = $nextAt?->format(DateTimeInterface::ATOM);
    llamada_guardar_progreso($store, $idempotencyKey, $progress, $commentState, $commentId, $leaseToken);

    if (!$progress['activityUpdated']) {
        try {
            llamada_bx_true($bx, 'crm.activity.update', [
                'id' => $activityId,
                'fields' => $fields,
            ]);
        } catch (LlamadaForbidden $error) {
            throw $error;
        } catch (Throwable $error) {
            llamada_guardar_progreso($store, $idempotencyKey, $progress, $commentState, $commentId, $leaseToken, 'retryable');
            throw $error;
        }
        $progress['activityUpdated'] = true;
        llamada_guardar_progreso($store, $idempotencyKey, $progress, $commentState, $commentId, $leaseToken);
    }

    if (!$progress['commentProcessed']) {
        if ($request['comment'] === '') {
            $commentState = 'skipped';
            $progress['commentProcessed'] = true;
            llamada_guardar_progreso($store, $idempotencyKey, $progress, $commentState, null, $leaseToken);
        } else {
            // comment.add is the only non-idempotent effect: after dispatch, a
            // transport loss cannot prove whether Bitrix created the comment.
            $commentState = 'in_progress';
            llamada_guardar_progreso($store, $idempotencyKey, $progress, $commentState, null, $leaseToken);
            try {
                $commentResult = llamada_bx_result($bx, 'crm.timeline.comment.add', [
                    'fields' => [
                        'ENTITY_ID' => $dealId,
                        'ENTITY_TYPE' => 'deal',
                        'COMMENT' => $request['comment'],
                    ],
                ]);
            } catch (LlamadaBitrixError $error) {
                if ($error->isDeliveryUncertain()) throw $error;
                $commentState = 'pending';
                llamada_guardar_progreso($store, $idempotencyKey, $progress, $commentState, null, $leaseToken, 'retryable');
                throw $error;
            }
            $commentId = (int)$commentResult;
            if ($commentId <= 0) {
                throw new LlamadaBitrixError('Bitrix crm.timeline.comment.add did not return a comment id', true);
            }
            $commentState = 'created';
            $progress['commentProcessed'] = true;
            llamada_guardar_progreso($store, $idempotencyKey, $progress, $commentState, $commentId, $leaseToken);
        }
    }

    if (!$progress['stageProcessed']) {
        if ($request['outcome'] !== 'not_interested') {
            $progress['stageProcessed'] = true;
        } elseif ((string)($deal['STAGE_ID'] ?? '') === $noInterestStage) {
            $progress['stageChanged'] = $progress['stageAttempted'];
            $progress['stageProcessed'] = true;
        } else {
            $progress['stageAttempted'] = true;
            llamada_guardar_progreso($store, $idempotencyKey, $progress, $commentState, $commentId, $leaseToken);
            try {
                llamada_bx_true($bx, 'crm.deal.update', [
                    'id' => $dealId,
                    'fields' => ['STAGE_ID' => $noInterestStage],
                ]);
            } catch (LlamadaForbidden $error) {
                throw $error;
            } catch (Throwable $error) {
                llamada_guardar_progreso($store, $idempotencyKey, $progress, $commentState, $commentId, $leaseToken, 'retryable');
                throw $error;
            }
            $progress['stageChanged'] = true;
            $progress['stageProcessed'] = true;
        }
        llamada_guardar_progreso($store, $idempotencyKey, $progress, $commentState, $commentId, $leaseToken);
    }

    $response = [
        'status' => 'processed',
        'callRequestId' => $request['callRequestId'],
        'outcome' => $request['outcome'],
        'bitrixActivityId' => $activityId,
        'stageChanged' => (bool)$progress['stageChanged'],
        'commentCreated' => $commentState === 'created' && $commentId !== null,
        'nextActivityAt' => $progress['nextActivityAt'],
    ];

    $store->complete(
        $idempotencyKey,
        json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $commentState,
        $commentId,
        $store->now(),
        $leaseToken
    );

    return $response;
    } catch (LlamadaForbidden $error) {
        $store->forbid($idempotencyKey, $store->now(), $leaseToken);
        throw $error;
    }
}

function llamada_cargar_progreso(mixed $responseJson): array {
    $stored = is_string($responseJson) && $responseJson !== ''
        ? json_decode($responseJson, true)
        : null;
    if (!is_array($stored)) $stored = [];

    return [
        'nextActivityAt' => isset($stored['nextActivityAt']) && is_string($stored['nextActivityAt'])
            ? $stored['nextActivityAt']
            : null,
        'activityUpdated' => (bool)($stored['activityUpdated'] ?? false),
        'commentProcessed' => (bool)($stored['commentProcessed'] ?? false),
        'stageAttempted' => (bool)($stored['stageAttempted'] ?? false),
        'stageProcessed' => (bool)($stored['stageProcessed'] ?? false),
        'stageChanged' => (bool)($stored['stageChanged'] ?? false),
    ];
}

function llamada_guardar_progreso(
    LlamadaIdempotenciaStore $store,
    string $idempotencyKey,
    array $progress,
    ?string $commentState,
    ?int $commentId,
    string $leaseToken,
    string $state = 'processing'
): void {
    $store->checkpoint(
        $idempotencyKey,
        json_encode($progress, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $commentState,
        $commentId,
        $store->now(),
        $state,
        $leaseToken
    );
}

function llamada_validar_resultado(array $input, DateTimeImmutable $now, string $noInterestStage): array {
    $callRequestId = trim((string)($input['callRequestId'] ?? ''));
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $callRequestId)) {
        throw new LlamadaValidationError('invalid callRequestId');
    }

    $dealId = llamada_entero_positivo($input['dealId'] ?? null, 'dealId');
    $bitrixUserId = llamada_entero_positivo($input['bitrixUserId'] ?? null, 'bitrixUserId');
    $activityId = llamada_entero_positivo($input['bitrixActivityId'] ?? null, 'bitrixActivityId');
    $outcome = trim((string)($input['outcome'] ?? ''));
    if (!in_array($outcome, ['no_answer', 'answered', 'not_interested'], true)) {
        throw new LlamadaValidationError('invalid outcome');
    }

    if (trim($noInterestStage) === '') {
        throw new LlamadaValidationError('noInterestStage is required');
    }

    $selectedPhone = llamada_normalizar_telefono($input['selectedPhone'] ?? null);
    $commentValue = $input['comment'] ?? '';
    if (!is_string($commentValue)) throw new LlamadaValidationError('comment must be a string');
    $comment = llamada_validar_comentario($commentValue);

    $nextActivityAt = null;
    if ($outcome === 'answered') {
        $nextValue = $input['nextActivityAt'] ?? null;
        if (!is_string($nextValue) || trim($nextValue) === '') {
            throw new LlamadaValidationError('nextActivityAt is required for answered');
        }
        if (!preg_match(
            '/^(?<date>\d{4}-\d{2}-\d{2})T(?<time>\d{2}:\d{2}:\d{2})(?:\.\d+)?(?<zone>Z|[+-]\d{2}:\d{2})$/D',
            $nextValue,
            $dateParts
        )) {
            throw new LlamadaValidationError('nextActivityAt must be RFC3339 with an explicit offset');
        }
        try {
            $parsedNextActivityAt = new DateTimeImmutable($nextValue);
        } catch (Throwable) {
            throw new LlamadaValidationError('invalid nextActivityAt');
        }
        $parseErrors = DateTimeImmutable::getLastErrors();
        $expectedOffset = $dateParts['zone'] === 'Z' ? '+00:00' : $dateParts['zone'];
        if (($parseErrors !== false
                && ((int)$parseErrors['warning_count'] > 0 || (int)$parseErrors['error_count'] > 0))
            || $parsedNextActivityAt->format('Y-m-d') !== $dateParts['date']
            || $parsedNextActivityAt->format('H:i:s') !== $dateParts['time']
            || $parsedNextActivityAt->format('P') !== $expectedOffset) {
            throw new LlamadaValidationError('invalid nextActivityAt');
        }
        $nextActivityAt = $parsedNextActivityAt->setTimezone(new DateTimeZone('America/Guayaquil'));
        if ($nextActivityAt <= $now) {
            throw new LlamadaValidationError('nextActivityAt must be in the future');
        }
    }

    $memberValue = $input['memberId'] ?? '';
    if (!is_string($memberValue)) throw new LlamadaValidationError('memberId must be a string');

    return [
        'callRequestId' => strtolower($callRequestId),
        'memberId' => trim($memberValue),
        'dealId' => $dealId,
        'bitrixUserId' => $bitrixUserId,
        'bitrixActivityId' => $activityId,
        'outcome' => $outcome,
        'selectedPhone' => $selectedPhone,
        'nextActivityAt' => $nextActivityAt?->format(DateTimeInterface::ATOM),
        'comment' => $comment,
    ];
}

function llamada_entero_positivo(mixed $value, string $field): int {
    if (is_int($value)) {
        $number = $value;
    } elseif (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value)) {
        $number = (int)$value;
    } else {
        throw new LlamadaValidationError($field . ' must be a positive integer');
    }
    if ($number <= 0) throw new LlamadaValidationError($field . ' must be a positive integer');
    return $number;
}

function llamada_normalizar_telefono(mixed $value): string {
    if (!is_string($value)) throw new LlamadaValidationError('selectedPhone must be a string');
    $trimmed = trim($value);
    $prefix = str_starts_with($trimmed, '+') ? '+' : '';
    $digits = preg_replace('/\D+/', '', $trimmed);
    if (!is_string($digits) || strlen($digits) < 7 || strlen($digits) > 15) {
        throw new LlamadaValidationError('invalid selectedPhone');
    }
    return $prefix . $digits;
}

function llamada_validar_comentario(string $value): string {
    if (preg_match('//u', $value) !== 1) {
        throw new LlamadaValidationError('comment must be valid UTF-8');
    }
    $trimmed = preg_replace(
        '/\A[\p{Z}\x{0009}-\x{000D}\x{FEFF}]+|[\p{Z}\x{0009}-\x{000D}\x{FEFF}]+\z/u',
        '',
        $value
    );
    if (!is_string($trimmed)) {
        throw new LlamadaValidationError('comment must be valid UTF-8');
    }
    $codePoints = preg_match_all('/./us', $trimmed);
    if (!is_int($codePoints) || $codePoints > 2_000) {
        throw new LlamadaValidationError('comment must not exceed 2000 Unicode code points');
    }
    return $trimmed;
}

function llamada_telefono_pertenece_contexto(
    string $selectedPhone,
    array $activity,
    array $contact
): bool {
    $sources = [];
    foreach (($activity['COMMUNICATIONS'] ?? []) as $communication) {
        if (!is_array($communication)
            || strtoupper((string)($communication['TYPE'] ?? '')) !== 'PHONE') {
            continue;
        }
        $sources[] = $communication['VALUE'] ?? null;
    }
    foreach (($contact['PHONE'] ?? []) as $phone) {
        if (is_array($phone)) $sources[] = $phone['VALUE'] ?? null;
    }

    foreach ($sources as $candidate) {
        try {
            if (llamada_normalizar_telefono($candidate) === $selectedPhone) return true;
        } catch (LlamadaValidationError) {
            continue;
        }
    }
    return false;
}

function llamada_resultado_repetido(array $operation, string $callRequestId): array {
    if ((string)($operation['state'] ?? '') !== 'completed') {
        throw new RuntimeException('idempotency operation is not completed');
    }

    if ((string)($operation['comment_state'] ?? '') === 'in_progress'
        && $operation['comment_id'] === null) {
        return [
            'status' => 'manual_review',
            'callRequestId' => $callRequestId,
            'reason' => 'comment_delivery_uncertain',
        ];
    }

    $previous = json_decode((string)($operation['response_json'] ?? ''), true);
    if (!is_array($previous)) {
        throw new RuntimeException('stored idempotency response is invalid');
    }
    $previous['status'] = 'already_processed';
    return $previous;
}

function llamada_bx_result(callable $bx, string $method, array $params): mixed {
    $response = $bx($method, $params);
    if (!is_array($response)) {
        throw new LlamadaBitrixError('Bitrix ' . $method . ' returned an invalid response', true);
    }
    if (($response['ok'] ?? false) !== true) {
        $error = trim((string)($response['error'] ?? 'unknown error'));
        $description = trim((string)($response['desc'] ?? ''));
        if (strtoupper($error) === 'ACCESS_DENIED' || preg_match('/\baccess denied\b/i', $description) === 1) {
            throw new LlamadaForbidden('Bitrix access denied');
        }
        $detail = $description !== '' && $description !== $error
            ? $error . ': ' . $description
            : $error;
        $deliveryUncertain = in_array(strtolower($error), [
            'bad-json',
            'network-error',
            'timeout',
            'transport-error',
            'unknown error',
        ], true);
        throw new LlamadaBitrixError('Bitrix ' . $method . ' failed: ' . $detail, $deliveryUncertain);
    }
    if (!array_key_exists('result', $response)) {
        throw new LlamadaBitrixError('Bitrix ' . $method . ' returned no result', true);
    }
    return $response['result'];
}

function llamada_bx_array(callable $bx, string $method, array $params): array {
    $result = llamada_bx_result($bx, $method, $params);
    if (!is_array($result)) {
        throw new LlamadaBitrixError('Bitrix ' . $method . ' returned an invalid result');
    }
    return $result;
}

function llamada_bx_true(callable $bx, string $method, array $params): void {
    $result = llamada_bx_result($bx, $method, $params);
    if ($result !== true) {
        throw new LlamadaBitrixError('Bitrix ' . $method . ' did not return true');
    }
}

function llamada_historial_actividades(callable $bx, int $dealId): array {
    $history = [];
    $afterId = 0;

    do {
        $filter = [
            'OWNER_TYPE_ID' => 2,
            'OWNER_ID' => $dealId,
            'TYPE_ID' => 2,
            'DIRECTION' => 2,
        ];
        if ($afterId > 0) $filter['>ID'] = $afterId;

        $page = llamada_bx_array($bx, 'crm.activity.list', [
            'filter' => $filter,
            'select' => ['ID', 'TYPE_ID', 'DIRECTION', 'SUBJECT', 'CREATED'],
            'order' => ['ID' => 'ASC'],
            'start' => -1,
        ]);
        if (isset($page['items']) && is_array($page['items'])) $page = $page['items'];

        $lastId = $afterId;
        foreach ($page as $item) {
            if (!is_array($item)) continue;
            $itemId = (int)($item['ID'] ?? 0);
            if ($itemId <= $afterId) continue;
            $history[] = $item;
            $lastId = max($lastId, $itemId);
        }

        $pageCount = count($page);
        if ($pageCount < 50 || $lastId <= $afterId) break;
        $afterId = $lastId;
    } while (true);

    usort($history, fn(array $left, array $right): int => (int)$left['ID'] <=> (int)$right['ID']);
    return $history;
}

function llamada_ultimo_reingreso(callable $bx, array $deal, int $dealId): ?string {
    $config = llamada_config();
    $counterField = (string)$config['reentry_count_field'];
    $counter = $deal[$counterField] ?? null;
    $hasRealReentry = !($counter === null
        || $counter === false
        || $counter === 0
        || $counter === 0.0
        || $counter === '');
    if (!$hasRealReentry) return null;

    $history = llamada_bx_array($bx, 'crm.stagehistory.list', [
        'entityTypeId' => 2,
        'filter' => [
            'OWNER_ID' => $dealId,
            'STAGE_ID' => (string)$config['reentry_stage_id'],
        ],
        'select' => ['ID', 'CREATED_TIME'],
        'order' => ['ID' => 'DESC'],
    ]);
    $items = isset($history['items']) && is_array($history['items'])
        ? $history['items']
        : $history;
    $created = isset($items[0]) && is_array($items[0])
        ? substr((string)($items[0]['CREATED_TIME'] ?? ''), 0, 19)
        : '';
    return $created !== '' ? $created : null;
}

function llamada_campos_actividad_completada(array $context): array {
    $fields = [
        'OWNER_TYPE_ID' => 2,
        'OWNER_ID' => (int)$context['dealId'],
        'TYPE_ID' => 2,
        'DIRECTION' => 2,
        'PROVIDER_ID' => llamada_config()['provider_id'],
        'PROVIDER_TYPE_ID' => llamada_config()['provider_type_id'],
        'SUBJECT' => (string)$context['subject'],
        'COMPLETED' => 'Y',
        'RESPONSIBLE_ID' => (int)$context['responsibleId'],
        'PRIORITY' => 2,
        'NOTIFY_TYPE' => 1,
        'NOTIFY_VALUE' => 15,
        'DESCRIPTION_TYPE' => 1,
    ];

    if ((int)$context['contactId'] > 0 && (string)$context['selectedPhone'] !== '') {
        $fields['COMMUNICATIONS'] = [[
            'VALUE' => (string)$context['selectedPhone'],
            'ENTITY_ID' => (int)$context['contactId'],
            'ENTITY_TYPE_ID' => 3,
            'TYPE' => 'PHONE',
        ]];
    }
    return $fields;
}
