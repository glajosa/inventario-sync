<?php
declare(strict_types=1);

require_once __DIR__ . '/llamada-idempotencia.php';
require_once __DIR__ . '/llamada-protocolo.php';

final class LlamadaValidationError extends InvalidArgumentException {}
final class LlamadaForbidden extends RuntimeException {}
final class LlamadaBitrixError extends RuntimeException {}

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
    $operation = $store->begin($idempotencyKey, $requestHash, $now->getTimestamp());

    if (!$operation['is_new']) {
        return llamada_resultado_repetido($operation, $request['callRequestId']);
    }

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

    $contactId = (int)($deal['CONTACT_ID'] ?? 0);
    $contact = [];
    if ($contactId > 0) {
        $contact = llamada_bx_array($bx, 'crm.contact.get', ['id' => $contactId]);
        if ((int)($contact['ID'] ?? 0) !== $contactId) {
            throw new LlamadaForbidden('contact id mismatch');
        }
    }

    $history = llamada_historial_actividades($bx, $dealId);
    $protocol = llamada_calcular_protocolo($history, $activityId);
    $contactName = trim(implode(' ', array_filter([
        trim((string)($contact['NAME'] ?? '')),
        trim((string)($contact['LAST_NAME'] ?? '')),
    ], fn(string $part): bool => $part !== '')));
    if ($contactName === '') $contactName = 'cliente';

    if ($request['outcome'] === 'not_interested') {
        $fields = llamada_campos_actividad_completada([
            'dealId' => $dealId,
            'responsibleId' => $bitrixUserId,
            'contactId' => $contactId,
            'selectedPhone' => $request['selectedPhone'],
            'subject' => '1234 · No le interesa',
        ]);
    } else {
        $nextAt = $request['outcome'] === 'answered'
            ? new DateTimeImmutable($request['nextActivityAt'])
            : llamada_proxima_no_contesto($protocol, $now)['at'];
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

    llamada_bx_result($bx, 'crm.activity.update', [
        'id' => $activityId,
        'fields' => $fields,
    ]);

    $stageUpdated = false;
    if ($request['outcome'] === 'not_interested' && (string)($deal['STAGE_ID'] ?? '') !== $noInterestStage) {
        llamada_bx_result($bx, 'crm.deal.update', [
            'id' => $dealId,
            'fields' => ['STAGE_ID' => $noInterestStage],
        ]);
        $stageUpdated = true;
    }

    $response = [
        'status' => 'processed',
        'callRequestId' => $request['callRequestId'],
        'outcome' => $request['outcome'],
        'bitrixActivityId' => $activityId,
        'stageUpdated' => $stageUpdated,
        'commentId' => null,
    ];
    $commentState = 'skipped';
    $commentId = null;

    if ($request['comment'] !== '') {
        $manualReview = array_merge($response, [
            'status' => 'manual_review',
            'reason' => 'comment_in_progress',
        ]);
        $store->complete(
            $idempotencyKey,
            json_encode($manualReview, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'in_progress',
            null,
            $now->getTimestamp()
        );

        $commentResult = llamada_bx_result($bx, 'crm.timeline.comment.add', [
            'fields' => [
                'ENTITY_ID' => $dealId,
                'ENTITY_TYPE' => 'deal',
                'COMMENT' => $request['comment'],
            ],
        ]);
        $commentId = (int)$commentResult;
        if ($commentId <= 0) {
            throw new LlamadaBitrixError('Bitrix crm.timeline.comment.add did not return a comment id');
        }
        $commentState = 'created';
        $response['commentId'] = $commentId;
    }

    $store->complete(
        $idempotencyKey,
        json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $commentState,
        $commentId,
        $now->getTimestamp()
    );

    return $response;
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
    $comment = trim($commentValue);

    $nextActivityAt = null;
    if ($outcome === 'answered') {
        $nextValue = $input['nextActivityAt'] ?? null;
        if (!is_string($nextValue) || trim($nextValue) === '') {
            throw new LlamadaValidationError('nextActivityAt is required for answered');
        }
        try {
            $nextActivityAt = new DateTimeImmutable($nextValue);
        } catch (Throwable) {
            throw new LlamadaValidationError('invalid nextActivityAt');
        }
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
    if (!is_string($digits) || strlen($digits) < 7) {
        throw new LlamadaValidationError('invalid selectedPhone');
    }
    return $prefix . $digits;
}

function llamada_resultado_repetido(array $operation, string $callRequestId): array {
    if ((string)($operation['state'] ?? '') !== 'completed') {
        return [
            'status' => 'manual_review',
            'callRequestId' => $callRequestId,
            'reason' => 'operation_in_progress',
        ];
    }

    if ((string)($operation['comment_state'] ?? '') === 'in_progress'
        && $operation['comment_id'] === null) {
        return [
            'status' => 'manual_review',
            'callRequestId' => $callRequestId,
            'reason' => 'comment_in_progress',
        ];
    }

    $previous = json_decode((string)($operation['response_json'] ?? ''), true);
    if (!is_array($previous)) {
        return [
            'status' => 'manual_review',
            'callRequestId' => $callRequestId,
            'reason' => 'stored_response_invalid',
        ];
    }
    $previous['status'] = 'already_processed';
    return $previous;
}

function llamada_bx_result(callable $bx, string $method, array $params): mixed {
    $response = $bx($method, $params);
    if (!is_array($response) || ($response['ok'] ?? false) !== true) {
        $error = trim((string)($response['error'] ?? 'unknown error'));
        $description = trim((string)($response['desc'] ?? ''));
        $detail = $description !== '' && $description !== $error
            ? $error . ': ' . $description
            : $error;
        throw new LlamadaBitrixError('Bitrix ' . $method . ' failed: ' . $detail);
    }
    if (!array_key_exists('result', $response)) {
        throw new LlamadaBitrixError('Bitrix ' . $method . ' returned no result');
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
