<?php
declare(strict_types=1);

// Boton "No contesto" de CREDITO Y CONTADO (pipeline 79).
//
// Hermano del de COBRANZAS (48), pero con otras reglas, y la diferencia no es de
// grado: alla el ciclo es LA CUOTA y hay un techo de intentos; aca no hay cuota
// mensual ni techo. Del protocolo, textual:
//
//   "EL MES NO SIRVE COMO CICLO EN CREDITO. En cobranzas el ciclo es la CUOTA:
//    una por mes, la etapa avanza sola cada mes -- el mes ES el ciclo. En credito
//    el ritmo es mensaje cada 2 dias habiles y llamada cada 4."
//
//   "no contesta -> INTERCALADO cada 2 dias: mensaje, llamada, mensaje, llamada.
//    SIN TECHO."
//
// Por eso este archivo NO tiene mapa de topes. Lo unico que calla al boton es un
// PACTO vigente: "contesta y pacta fecha -> SILENCIO hasta esa fecha (ni llamada
// ni mensaje). El pacto SIEMPRE se respeta."
//
// Y hay dos velocidades, no una:
//   GESTION (puerta, indeciso, no contesta, rechazado) -> la llamada cae cada 4
//     dias habiles, con el mensaje intercalado en el medio.
//   PROCESO (analisis, de contado, banco aprobado) -> "SI LLEGA LA FECHA Y NO
//     CONTESTA: llamada AL DIA SIGUIENTE, y de ahi CADA 5 DIAS HABILES desde que
//     dejo de contestar, hasta ubicarlo y fijar nueva fecha."

require_once __DIR__ . '/../feriados.php';
require_once __DIR__ . '/cobranza-protocolo.php';   // se reusa el contador de intentos

// Se sirve por HTTP en credito_app.php y como comentario en credito_nativo.php,
// para poder comprobar por fuera QUE version esta desplegada.
const CREDITO_VER = 'credito-boton-v1-regimenes-79';

/**
 * Las etapas REALES del 79, leidas de Bitrix el 5-sep-2026 (no inventadas), con
 * el regimen que les asigna el protocolo y cuantos deals vivos tenian ese dia.
 */
function credito_config(): array {
    return [
        'regimenes' => [
            'C79:NEW'               => 'puerta',   // X GESTIONAR              1 deal
            'C79:PREPAYMENT_INVOIC' => 'gestion',  // INDECISO                14
            'C79:UC_M21PX3'         => 'gestion',  // NO CONTESTA              3
            'C79:EXECUTING'         => 'gestion',  // RECHAZADO                6
            'C79:PREPARATION'       => 'proceso',  // ANALISIS Y PEND DOCUMENT 53
            'C79:UC_NGYPXQ'         => 'proceso',  // DE CONTADO              46
            'C79:FINAL_INVOICE'     => 'proceso',  // BANCO APROBADO          16
            'C79:UC_XJ71GA'         => 'salida',   // NO PUEDE PAGARLO         3
            'C79:UC_TA9OB1'         => 'fuera',    // CEDIO DERECHOS           0
            // 🔴 CREDITO DIRECTO (C79:UC_GUJ97G, 1 deal) NO figura en el protocolo.
            // Cae en el default de abajo: 'gestion'. Se prefiere que el boton FUNCIONE
            // sobre una etapa que no conocemos antes que dejar un deal mudo -- eso ya
            // paso en cobranzas y el deal se quedo sin gestion sin que nadie lo viera.
        ],
        'regimen_por_defecto' => 'gestion',
        'dias_gestion'         => 4,   // habiles: la llamada cae cada 4, el mensaje va en el medio
        'dias_proceso_primero' => 1,   // "llamada AL DIA SIGUIENTE" de la fecha que no cumplio
        'dias_proceso_despues' => 5,   // habiles, "de ahi CADA 5 DIAS HABILES"
        // Abrir la pestaña ES la accion: dos pulsaciones seguidas registrarian dos
        // intentos. Misma ventana que cobranzas, por la misma razon (doble clic).
        'ventana_repeticion_seg' => 600,
        // El credito pacta mas lejos que la cobranza (un tramite de banco son meses),
        // asi que el tope de seguridad contra una fecha absurda es mas holgado.
        'tope_pacto_dias' => 120,
        'provider_id'      => 'VOXIMPLANT_CALL',
        'provider_type_id' => 'CALL',
    ];
}

/** El regimen de una etapa. '' si el deal no es del 79. */
function credito_regimen(string $stageId): string {
    if (!str_starts_with($stageId, 'C79:')) return '';
    if (str_ends_with($stageId, ':WON') || str_ends_with($stageId, ':LOSE')
        || str_ends_with($stageId, ':APOLOGY')) return 'fuera';
    $cfg = credito_config();
    return (string)($cfg['regimenes'][$stageId] ?? $cfg['regimen_por_defecto']);
}

/**
 * ¿Este asunto es una CONTESTADA en credito?
 *
 * Los mismos tres de cobranzas mas FECHA DE PAGO, que aca es EL objetivo de la
 * llamada: "la asesora registra una LLAMADA SALIENTE CON ASUNTO 'FECHA DE PAGO'
 * y el deadline en esa fecha. Con eso la automatizacion LLENA SOLA el campo".
 *
 * Ser generoso con lo que cuenta como contestada nunca castiga a la asesora; ser
 * estricto si -- en cobranzas una PROMESA DE PAGO contaba como intento fallido.
 */
function credito_es_contestada(string $subject): bool {
    $s = mb_strtoupper(trim($subject), 'UTF-8');
    return str_contains($s, '1234')
        || str_contains($s, 'FECHA DE PAGO')
        || str_contains($s, 'PROMESA DE PAGO')
        || str_contains($s, 'REFINANCIAMIENTO');
}

/**
 * ¿Hay un PACTO vigente? Es lo UNICO que calla al boton en credito.
 * Una contestada con DEADLINE a futuro es un acuerdo: hasta esa fecha, silencio.
 */
function credito_pacto_vigente(array $actividades, int $ahoraTs): ?array {
    $tope = (int)credito_config()['tope_pacto_dias'];
    $mejor = null;
    foreach ($actividades as $a) {
        if ((int)($a['TYPE_ID'] ?? 0) !== 2 || (int)($a['DIRECTION'] ?? 0) !== 2) continue;
        $subject = (string)($a['SUBJECT'] ?? '');
        if (!credito_es_contestada($subject)) continue;
        $dl = (string)($a['DEADLINE'] ?? '');
        if ($dl === '') $dl = (string)($a['END_TIME'] ?? '');
        if ($dl === '') continue;
        $ts = strtotime($dl);
        if ($ts === false || $ts <= $ahoraTs) continue;                 // ya paso
        if ($tope > 0 && ($ts - $ahoraTs) > $tope * 86400) continue;    // fecha absurda
        if ($mejor === null || $ts > $mejor['ts']) {
            $mejor = ['ts' => $ts, 'fecha' => $dl, 'asunto' => trim($subject)];
        }
    }
    return $mejor;
}

/**
 * Cuenta los intentos desde la ultima contestada. Se reusa el contador de
 * cobranzas pasandole la regla de credito: la logica de orden, sellos del movil y
 * reinicio por contacto efectivo es delicada y no conviene tener dos copias.
 * Sin ventana de ciclo ($desde = null): en credito no hay ciclo que cortar.
 */
function credito_calcular_protocolo(array $actividades, ?int $excluirId = null): array {
    return cobranza_calcular_protocolo($actividades, $excluirId, null, 'credito_es_contestada');
}

/**
 * ¿Se puede pulsar? En credito NO hay techo de intentos: "SIN TECHO", textual.
 * Solo lo frena el pacto vigente, o que el deal no sea de este embudo.
 */
function credito_puede_llamar(string $stageId, array $protocolo, array $deal): array {
    $reg = credito_regimen($stageId);
    if ($reg === '') {
        return ['puede' => false, 'motivo' => 'otro_embudo', 'regimen' => '', 'restantes' => -1];
    }
    if ($reg === 'fuera') {
        return ['puede' => false, 'motivo' => 'deal_cerrado', 'regimen' => $reg, 'restantes' => 0];
    }
    if (!empty($deal['_pacto']['fecha'])) {
        return ['puede' => false, 'motivo' => 'pacto_vigente', 'regimen' => $reg,
                'restantes' => -1, 'pacto' => $deal['_pacto']];
    }
    // -1 = sin techo. NO es "cero restantes": el boton nunca se agota en credito.
    return ['puede' => true, 'motivo' => '', 'regimen' => $reg, 'restantes' => -1];
}

/** Suma dias HABILES saltando fines de semana y feriados de Ecuador. */
function credito_sumar_habiles(DateTimeImmutable $desde, int $dias): DateTimeImmutable {
    $at = $desde->setTime(0, 0);
    $sumados = 0;
    for ($i = 0; $i < 60 && $sumados < $dias; $i++) {
        $at = $at->modify('+1 day');
        if (fer_es_habil($at)) $sumados++;
    }
    return $at;
}

/**
 * Cuando cae el proximo intento.
 *
 * PROCESO: si es el PRIMER intento despues de que la fecha pactada paso sin que
 * el cliente contestara, va AL DIA SIGUIENTE habil. De ahi en adelante, cada 5.
 * El "primero" se reconoce porque no hay intentos acumulados desde la ultima
 * contestada ($sinContestar === 0): el cliente venia pactando, no ignorando.
 *
 * GESTION / PUERTA / SALIDA: cada 4 dias habiles. El mensaje del protocolo cae
 * intercalado en el medio, asi que el cliente recibe algo cada 2.
 */
function credito_proximo_intento(string $regimen, array $protocolo, DateTimeImmutable $ahora): DateTimeImmutable {
    $cfg = credito_config();
    $dias = match ($regimen) {
        'proceso' => ((int)($protocolo['sinContestar'] ?? 0) === 0)
                        ? (int)$cfg['dias_proceso_primero']
                        : (int)$cfg['dias_proceso_despues'],
        default   => (int)$cfg['dias_gestion'],
    };
    $at = credito_sumar_habiles($ahora, $dias);
    $hora = match (true) {
        (int)$ahora->format('G') < 11 => '12:30',
        (int)$ahora->format('G') < 14 => '16:00',
        (int)$ahora->format('G') < 18 => '19:00',
        default                       => '09:30',
    };
    [$h, $m] = array_map('intval', explode(':', $hora));
    return $at->setTime($h, $m);
}

/** El texto que va en la actividad, para que se entienda sin abrir el codigo. */
function credito_nota(string $regimen, DateTimeImmutable $proximo): string {
    $cfg = credito_config();
    return match ($regimen) {
        'proceso' => 'No contestó. El deal está en régimen de PROCESO: toda llamada queda agendada. '
                   . 'Reintento el ' . $proximo->format('d/m/Y') . '.',
        default   => 'No contestó. Ritmo intercalado: el mensaje va en el medio y la llamada vuelve '
                   . 'cada ' . $cfg['dias_gestion'] . ' días hábiles, el ' . $proximo->format('d/m/Y') . '.',
    };
}
