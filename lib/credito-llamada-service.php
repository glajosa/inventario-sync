<?php
declare(strict_types=1);

// Boton "No contesto" de CREDITO Y CONTADO (pipeline 79).
//
// Separado del de cobranzas por la misma razon por la que aquel se separo del de
// ventas: las reglas son OTRAS (sin techo, dos cadencias, otra lista de asuntos
// que cuentan como contestada) y meterle un parametro de pipeline al que ya usan
// las asesoras todos los dias seria arriesgar el que funciona.
//
// 🔴 UNA PULSACION = UNA ACTIVIDAD. Se cierra la planificada abierta y se crea UNA
// nueva, que es a la vez el registro del intento fallido y la cita del proximo. Un
// sello aparte haria que cada pulsacion contara como dos no contestadas -- ya paso
// en prospectos (25-ago-2026, 4 deals inflados).
//
// 🔴 Este boton NO escribe ningun campo del deal. En cobranzas escribe ESTADO DE
// GESTION porque ese campo existe y significa algo alli; en el 79 no hay un campo
// equivalente en el protocolo, y inventarle uno seria escribir un dato que nadie
// definio. Lo que deja es la ACTIVIDAD, que es de donde salen todos los contadores.

require_once __DIR__ . '/credito-protocolo.php';

class CreditoLlamadaError extends RuntimeException {}

/**
 * 🔴 Toda llamada a Bitrix pasa por aca. El $bx del panel devuelve
 * ['ok'=>false,'error'=>...] cuando falla, y leer ['result'] ?? null convertiria
 * ese fallo en un valor bueno: una crm.activity.list caida daria lista vacia, o
 * sea "no hay pacto", y el boton llamaria encima de un cliente que pacto fecha.
 */
function credito_bx(callable $bx, string $method, array $params) {
    $r = $bx($method, $params);
    if (!is_array($r) || ($r['ok'] ?? true) === false) {
        throw new CreditoLlamadaError('bitrix_unavailable');
    }
    return $r['result'] ?? null;
}

function credito_no_contesto(
    array $entrada,
    callable $bx,
    DateTimeImmutable $ahora
): array {
    $dealId = (int)($entrada['dealId'] ?? 0);
    $userId = (int)($entrada['bitrixUserId'] ?? 0);
    if ($dealId <= 0 || $userId <= 0) {
        throw new CreditoLlamadaError('invalid_request');
    }
    $cfg = credito_config();
    $ahoraEc = $ahora->setTimezone(new DateTimeZone('America/Guayaquil'));

    // --- 1. el deal ---
    $deal = credito_bx($bx, 'crm.deal.get', ['id' => $dealId]);
    if (!is_array($deal) || $deal === []) {
        throw new CreditoLlamadaError('deal_not_found');
    }
    $stageId = (string)($deal['STAGE_ID'] ?? '');

    // --- 2. las actividades ---
    $acts = credito_bx($bx, 'crm.activity.list', [
        'filter' => ['OWNER_TYPE_ID' => 2, 'OWNER_ID' => $dealId],
        'select' => ['ID','SUBJECT','TYPE_ID','DIRECTION','COMPLETED','CREATED','ORIGIN_ID','END_TIME','DEADLINE'],
        'order'  => ['CREATED' => 'ASC'],
    ]);
    if (!is_array($acts)) throw new CreditoLlamadaError('bitrix_unavailable');

    // El pacto se busca sobre TODAS las actividades: en credito el pacto ES el
    // ciclo, y puede caer a semanas o meses vista (un tramite de banco).
    $deal['_pacto'] = credito_pacto_vigente($acts, $ahora->getTimestamp());

    // --- 3. cuantos intentos van desde la ultima contestada ---
    // Sin ventana de ciclo: en credito no hay cuota mensual que corte.
    $protocolo = credito_calcular_protocolo($acts, null);

    // --- 4. las guardias ---
    // Doble pulsacion. NO frena si la asesora ya cerro esa planificada: completarla
    // es decir "esta la hice", asi que la siguiente es un intento nuevo.
    $ultimo = $protocolo['ultimoIntento'] ?? null;
    if (is_string($ultimo) && $ultimo !== '' && empty($protocolo['ultimoCerrado'])) {
        $ultimoTs = strtotime($ultimo);
        $edad = $ultimoTs !== false ? ($ahora->getTimestamp() - $ultimoTs) : -1;
        if ($edad >= 0 && $edad < $cfg['ventana_repeticion_seg']) {
            return ['status' => 'ya_registrado', 'motivo' => 'repeticion',
                    'haceMinutos' => intdiv($edad, 60),
                    'etapa' => $stageId, 'intentos' => (int)$protocolo['sinContestar']];
        }
    }

    $permiso = credito_puede_llamar($stageId, $protocolo, $deal);
    if (!$permiso['puede']) {
        $out = ['status' => 'rechazado', 'motivo' => $permiso['motivo'],
                'etapa' => $stageId, 'regimen' => $permiso['regimen'],
                'intentos' => (int)$protocolo['sinContestar']];
        if (!empty($permiso['pacto'])) {
            $out['pactoFecha']  = $permiso['pacto']['fecha'];
            $out['pactoAsunto'] = $permiso['pacto']['asunto'];
        }
        return $out;
    }
    $regimen = (string)$permiso['regimen'];

    // --- 5. cerrar la planificada abierta ---
    $cerrada = null;
    foreach ($acts as $a) {
        if ((int)($a['TYPE_ID'] ?? 0) !== 2 || (int)($a['DIRECTION'] ?? 0) !== 2) continue;
        if ((string)($a['COMPLETED'] ?? '') === 'Y') continue;
        $cerrada = (int)$a['ID'];
        credito_bx($bx, 'crm.activity.update', ['id' => $cerrada, 'fields' => ['COMPLETED' => 'Y']]);
        break;
    }

    // --- 6. UNA actividad nueva: el fallo y la cita del proximo ---
    // 🔴 En regimen de PROCESO el protocolo lo exige: "EN ANALISIS, DE CONTADO Y
    // BANCO APROBADO TODOS LOS DEALS TIENEN QUE TENER UNA LLAMADA AGENDADA CON
    // DEADLINE, SIEMPRE. Un deal en regimen de proceso sin llamada agendada es un
    // deal soltado, sin excusa posible." El boton lo deja agendado solo.
    $proximo = credito_proximo_intento($regimen, $protocolo, $ahoraEc);

    // El SUBJECT es lo que cuentan los tableros, asi que el nombre no puede depender
    // de que el navegador lo mande: si llega vacio se resuelve con el CONTACT_ID del
    // propio deal. Cuesta 1 llamada y solo cuando hace falta.
    $contacto   = trim((string)($entrada['contactName'] ?? ''));
    $contactoId = (int)($entrada['contactId'] ?? 0);
    $tel        = (string)($entrada['selectedPhone'] ?? '');
    if ($contacto === '') {
        $cid = $contactoId ?: (int)($deal['CONTACT_ID'] ?? 0);
        if ($cid > 0) {
            $c = credito_bx($bx, 'crm.contact.get', ['id' => $cid]);
            if (is_array($c)) {
                $contacto = trim(implode(' ', array_filter([
                    trim((string)($c['NAME'] ?? '')),
                    trim((string)($c['LAST_NAME'] ?? '')),
                ], fn(string $x): bool => $x !== '')));
                $contactoId = $cid;
                if ($tel === '') {
                    foreach ((array)($c['PHONE'] ?? []) as $ph) {
                        $tel = (string)($ph['VALUE'] ?? '');
                        if ($tel !== '') break;
                    }
                }
            }
        }
    }
    if ($contacto === '') $contacto = 'cliente';

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
        'DESCRIPTION'   => credito_nota($regimen, $proximo),
    ];
    if ($contactoId > 0 && $tel !== '') {
        $campos['COMMUNICATIONS'] = [[
            'VALUE' => $tel,
            'ENTITY_ID' => $contactoId,
            'ENTITY_TYPE_ID' => 3,
            'TYPE' => 'PHONE',
        ]];
    }
    $nueva = credito_bx($bx, 'crm.activity.add', ['fields' => $campos]);
    if (!is_int($nueva) && !ctype_digit((string)$nueva)) {
        throw new CreditoLlamadaError('activity_not_created');
    }

    return [
        'status'          => 'procesado',
        'etapa'           => $stageId,
        'regimen'         => $regimen,
        'intentos'        => (int)$protocolo['sinContestar'] + 1,
        'restantes'       => -1,          // sin techo, a proposito
        'proximoIntento'  => $proximo->format(DateTimeInterface::ATOM),
        'actividadNueva'  => (int)$nueva,
        'actividadCerrada'=> $cerrada,
    ];
}
