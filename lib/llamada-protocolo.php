<?php
declare(strict_types=1);

require_once __DIR__ . '/../feriados.php';

function llamada_config(): array {
    return [
        'plazo' => [1 => 1, 2 => 6, 3 => 29],
        'plazo_mantenimiento' => 99,
        'plazo_contesto' => 3,
        'hora_contesto' => '10:00',
        'provider_id' => 'VOXIMPLANT_CALL',
        'provider_type_id' => 'CALL',
        'reentry_stage_id' => 'C28:PREPARATION',
        'reentry_count_field' => 'UF_CRM_1781115254387',
    ];
}

function llamada_calcular_protocolo(
    array $actividades,
    ?int $excluirId,
    ?string $reingreso = null
): array {
    $estado = 'NUEVO';
    $sinContestar = 0;
    $viejas = 0;
    $reingreso = is_string($reingreso) ? substr($reingreso, 0, 19) : '';

    foreach ($actividades as $actividad) {
        if ($excluirId !== null && (int)$actividad['ID'] === $excluirId) continue;
        if ((int)$actividad['TYPE_ID'] !== 2 || (int)$actividad['DIRECTION'] !== 2) continue;
        $originId = (string)($actividad['ORIGIN_ID'] ?? '');
        $subject = (string)($actividad['SUBJECT'] ?? '');
        if (str_starts_with($originId, 'VI_externalCall') || str_starts_with($subject, 'App móvil ·')) continue;
        $creada = substr((string)($actividad['CREATED'] ?? ''), 0, 19);
        if ($reingreso !== '' && $creada !== '' && $creada < $reingreso) {
            $viejas++;
            continue;
        }

        if (stripos($subject, '1234') !== false) {
            $estado = 'CONTACTADO';
            $sinContestar = 0;
            continue;
        }

        $sinContestar++;
        $estado = $sinContestar === 1
            ? 'ESCALERA-1'
            : ($sinContestar === 2 ? 'ESCALERA-2' : 'MANTENIMIENTO');
    }

    return ['estado' => $estado, 'sinContestar' => $sinContestar, 'viejas' => $viejas];
}

function llamada_proxima_no_contesto(array $protocolo, DateTimeImmutable $ahora): array {
    $config = llamada_config();
    $intento = (int)($protocolo['sinContestar'] ?? 0) + 1;
    $dias = $config['plazo'][$intento] ?? $config['plazo_mantenimiento'];

    $hora = match (true) {
        (int)$ahora->format('G') < 11 => '12:30',
        (int)$ahora->format('G') < 14 => '16:00',
        (int)$ahora->format('G') < 18 => '19:00',
        default => '09:30',
    };

    $at = $ahora->modify('+' . $dias . ' days')->setTime(0, 0);
    for ($i = 0; $i < 15 && !fer_es_habil($at); $i++) {
        $at = $at->modify('+1 day');
    }

    [$hours, $minutes] = array_map('intval', explode(':', $hora));
    return ['at' => $at->setTime($hours, $minutes)];
}

function llamada_campos_actividad(array $contexto): array {
    $nextAt = $contexto['nextAt'];
    $fields = [
        'OWNER_TYPE_ID' => 2,
        'OWNER_ID' => (int)$contexto['dealId'],
        'TYPE_ID' => 2,
        'DIRECTION' => 2,
        'PROVIDER_ID' => llamada_config()['provider_id'],
        'PROVIDER_TYPE_ID' => llamada_config()['provider_type_id'],
        'SUBJECT' => (string)$contexto['subject'],
        'COMPLETED' => (string)$contexto['completed'],
        'RESPONSIBLE_ID' => (int)$contexto['responsibleId'],
        'START_TIME' => $nextAt->format(DateTimeInterface::ATOM),
        'END_TIME' => $nextAt->modify('+1 hour')->format(DateTimeInterface::ATOM),
        'DEADLINE' => $nextAt->format(DateTimeInterface::ATOM),
        'PRIORITY' => 2,
        'NOTIFY_TYPE' => 1,
        'NOTIFY_VALUE' => 15,
        'DESCRIPTION_TYPE' => 1,
    ];

    if ((int)$contexto['contactId'] > 0 && (string)$contexto['selectedPhone'] !== '') {
        $fields['COMMUNICATIONS'] = [[
            'VALUE' => (string)$contexto['selectedPhone'],
            'ENTITY_ID' => (int)$contexto['contactId'],
            'ENTITY_TYPE_ID' => 3,
            'TYPE' => 'PHONE',
        ]];
    }

    return $fields;
}
