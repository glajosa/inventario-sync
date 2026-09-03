<?php
declare(strict_types=1);

// Boton "No contesto" de COBRANZAS (pipeline 48).
//
// Deliberadamente SEPARADO de llamada-resultado-service.php (prospectos): esa pieza
// funciona y tiene la cadencia [1,6,29] cableada, la etapa de reingreso del 28 y la
// rama del movil. Cobranzas no necesita nada de eso y meterle un parametro de pipeline
// arriesgaria el boton que ya usan los vendedores todos los dias.
//
// 🔴 UNA PULSACION = UNA ACTIVIDAD. Se cierra la planificada abierta y se crea UNA
// nueva. Esa nueva ES el registro del intento fallido Y la cita del proximo. Si
// alguien "mejora" esto agregando un sello aparte, cada pulsacion cuenta como dos
// no contestadas. Ya paso en prospectos (25-ago-2026, 4 deals inflados).

require_once __DIR__ . '/cobranza-protocolo.php';

class CobranzaLlamadaError extends RuntimeException {}

/**
 * 🔴 Toda llamada a Bitrix pasa por aca. El $bx del panel devuelve
 * ['ok'=>false,'error'=>...] cuando falla, y leer ['result'] ?? null convertia
 * ese fallo en un valor bueno: una crm.activity.list caida daba lista vacia ->
 * 0 intentos -> la guardia del tope pasaba -> creaba la llamada igual, salteando
 * el maximo de la etapa. Un fallo NO puede parecerse a un deal sin llamadas.
 */
function cobranza_bx(callable $bx, string $method, array $params) {
    $r = $bx($method, $params);
    if (!is_array($r) || ($r['ok'] ?? true) === false) {
        throw new CobranzaLlamadaError('bitrix_unavailable');
    }
    return $r['result'] ?? null;
}

function cobranza_no_contesto(
    array $entrada,
    callable $bx,
    DateTimeImmutable $ahora
): array {
    $dealId = (int)($entrada['dealId'] ?? 0);
    $userId = (int)($entrada['bitrixUserId'] ?? 0);
    if ($dealId <= 0 || $userId <= 0) {
        throw new CobranzaLlamadaError('invalid_request');
    }
    $cfg = cobranza_config();
    $ahoraEc = $ahora->setTimezone(new DateTimeZone('America/Guayaquil'));

    // --- 1. el deal ---
    $deal = cobranza_bx($bx, 'crm.deal.get', ['id' => $dealId]);
    if (!is_array($deal) || $deal === []) {
        throw new CobranzaLlamadaError('deal_not_found');
    }
    $stageId = (string)($deal['STAGE_ID'] ?? '');

    // --- 2. las actividades del deal ---
    $acts = cobranza_bx($bx, 'crm.activity.list', [
        'filter' => ['OWNER_TYPE_ID' => 2, 'OWNER_ID' => $dealId],
        'select' => ['ID','SUBJECT','TYPE_ID','DIRECTION','COMPLETED','CREATED','ORIGIN_ID','END_TIME'],
        'order'  => ['CREATED' => 'ASC'],
    ]);
    // null es "no pude leer", no "no tiene ninguna". Son cosas distintas.
    if (!is_array($acts)) throw new CobranzaLlamadaError('bitrix_unavailable');

    // ¿hay una planificada a futuro? Es lo que convierte la pausa en pausa de verdad.
    $planificadaFutura = null;
    foreach ($acts as $a) {
        if ((string)($a['COMPLETED'] ?? '') === 'Y') continue;
        $fin = (string)($a['END_TIME'] ?? '');
        if ($fin !== '' && strtotime($fin) > $ahora->getTimestamp()) { $planificadaFutura = $a; break; }
    }
    $deal['_planificada_futura'] = $planificadaFutura !== null;

    // --- 3. el estado del ciclo ---
    $inicio = cobranza_inicio_ciclo($stageId, (string)($deal['_entrada_etapa'] ?? '') ?: null, $ahoraEc);
    $protocolo = cobranza_calcular_protocolo($acts, null, $inicio);

    // --- 4. las guardias ---
    // Doble pulsacion: no se duplica en silencio, se avisa.
    $ultimo = $protocolo['ultimoIntento'] ?? null;
    if (is_string($ultimo) && $ultimo !== '') {
        $edad = $ahora->getTimestamp() - strtotime($ultimo . ' -05:00');
        if ($edad >= 0 && $edad < $cfg['ventana_repeticion_seg']) {
            return ['status' => 'ya_registrado', 'motivo' => 'repeticion',
                    'haceMinutos' => intdiv($edad, 60),
                    'etapa' => $stageId, 'intentos' => (int)$protocolo['sinContestar']];
        }
    }

    $permiso = cobranza_puede_llamar($stageId, $protocolo, $deal);
    if (!$permiso['puede']) {
        return ['status' => 'rechazado', 'motivo' => $permiso['motivo'],
                'etapa' => $stageId, 'intentos' => (int)$protocolo['sinContestar']];
    }

    // --- 5. cerrar la planificada abierta (si la hay) ---
    $cerrada = null;
    foreach ($acts as $a) {
        if ((int)($a['TYPE_ID'] ?? 0) !== 2 || (int)($a['DIRECTION'] ?? 0) !== 2) continue;
        if ((string)($a['COMPLETED'] ?? '') === 'Y') continue;
        $cerrada = (int)$a['ID'];
        cobranza_bx($bx, 'crm.activity.update', ['id' => $cerrada, 'fields' => ['COMPLETED' => 'Y']]);
        break;
    }

    // --- 6. UNA actividad nueva: registro del fallo + cita del proximo ---
    $proximo  = cobranza_proximo_intento($ahoraEc);
    $contacto = trim((string)($entrada['contactName'] ?? '')) ?: 'cliente';
    $campos = [
        'OWNER_TYPE_ID' => 2,
        'OWNER_ID'      => $dealId,
        'TYPE_ID'       => 2,
        'DIRECTION'     => 2,
        'PROVIDER_ID'      => $cfg['provider_id'],
        'PROVIDER_TYPE_ID' => $cfg['provider_type_id'],
        'SUBJECT'       => 'Llamada saliente ' . $contacto,
        'COMPLETED'     => 'N',
        'RESPONSIBLE_ID'=> $userId,
        'START_TIME'    => $proximo->format(DateTimeInterface::ATOM),
        'END_TIME'      => $proximo->modify('+1 hour')->format(DateTimeInterface::ATOM),
        'DEADLINE'      => $proximo->format(DateTimeInterface::ATOM),
        'PRIORITY'      => 2,
        'NOTIFY_TYPE'   => 1,
        'NOTIFY_VALUE'  => 15,
        'DESCRIPTION_TYPE' => 1,
        'DESCRIPTION'   => 'No contestó. Reintento automático a +2 días hábiles (protocolo de cobranzas).',
    ];
    if ((int)($entrada['contactId'] ?? 0) > 0 && (string)($entrada['selectedPhone'] ?? '') !== '') {
        $campos['COMMUNICATIONS'] = [[
            'VALUE' => (string)$entrada['selectedPhone'],
            'ENTITY_ID' => (int)$entrada['contactId'],
            'ENTITY_TYPE_ID' => 3,
            'TYPE' => 'PHONE',
        ]];
    }
    $nueva = cobranza_bx($bx, 'crm.activity.add', ['fields' => $campos]);
    if (!is_int($nueva) && !ctype_digit((string)$nueva)) {
        throw new CobranzaLlamadaError('activity_not_created');
    }

    // --- 7. ESTADO DE GESTION. NO se tocan CICLOS_EXIG ni CICLOS_CUMPL:
    //        esos los lleva el proceso del ciclo, no un boton que aprieta una persona.
    $gestion = cobranza_estado_gestion($protocolo, $stageId);
    cobranza_bx($bx, 'crm.deal.update', ['id' => $dealId, 'fields' => [$cfg['campo_gestion'] => $gestion]]);

    return [
        'status'          => 'procesado',
        'etapa'           => $stageId,
        'intentos'        => (int)$protocolo['sinContestar'] + 1,
        'restantes'       => $permiso['restantes'] - 1,
        'proximoIntento'  => $proximo->format(DateTimeInterface::ATOM),
        'actividadNueva'  => (int)$nueva,
        'actividadCerrada'=> $cerrada,
        'estadoGestion'   => $gestion,
    ];
}
