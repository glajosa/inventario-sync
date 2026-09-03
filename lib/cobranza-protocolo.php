<?php
declare(strict_types=1);

// Escalera de llamadas de COBRANZAS (pipeline 48). Hermana de llamada-protocolo.php,
// que es la de PROSPECTOS (28) y tiene otra cadencia: [1, 6, 29] dias corridos.
//
// Aqui la cadencia es fija -- un intento cada 2 DIAS HABILES -- y lo que cambia es
// el TOPE, que depende de la etapa. Sale del protocolo del 2-sep-2026, medido sobre
// 7.383 llamadas (mar-ago 2026): 3 intentos capturan el 94% de los contactos que se
// van a lograr, y esperar mas NO mejora la tasa (60% mismo dia, 62% a 2 dias, 57% a
// 8-15 dias). Por eso 2 dias habiles y no 8.

require_once __DIR__ . '/../feriados.php';

function cobranza_config(): array {
    return [
        // Tope de llamadas POR CICLO, por etapa. 0 = el boton no se ofrece.
        // Los 6 de 3 MESES y ABOGADO son DOS TANDAS de 3 ("dias 6, 8, 10 · y 16, 18, 20"),
        // una por cada contacto efectivo exigido, no seis intentos sueltos.
        'topes' => [
            'C48:UC_X35FSA'    => 0,   // MES CORRIENTE  - 100% automatica, nadie llama
            'C48:NEW'          => 0,   // AL DIA
            'C48:UC_TPE9QV'    => 0,   // ADELANTADO
            'C48:UC_JW3G4N'    => 0,   // CANJE
            'C48:UC_1WHC5Q'    => 1,   // 1 MES VENCIDO
            'C48:UC_LLUGGI'    => 3,   // 2 MESES VENCIDOS
            'C48:UC_VXD8VQ'    => 6,   // 3 MESES VENCIDOS
            'C48:FINAL_INVOICE'=> 6,   // ABOGADO - se repite TODOS LOS MESES, no se agota
        ],
        'dias_entre_intentos' => 2,          // habiles
        'intentos_por_tanda'  => 3,
        // Abrir la pestaña ES la accion, asi que abrirla dos veces registraria
        // dos intentos. Dentro de esta ventana la segunda pulsacion no escribe:
        // avisa que ya estaba hecho. 10 min cubre el doble clic y el "no cargo,
        // le doy de nuevo" sin tapar un reintento legitimo (el proximo es a +2 dias).
        'ventana_repeticion_seg' => 600,
        // ABOGADO no es un ciclo que se agota: el tope se cuenta por MES calendario.
        'etapas_ciclo_mensual' => ['C48:FINAL_INVOICE'],
        'provider_id'      => 'VOXIMPLANT_CALL',
        'provider_type_id' => 'CALL',
        'campo_pausa'    => 'UF_CRM_ESTADO_PAUSA',
        'campo_gestion'  => 'UF_CRM_ESTADO_GESTION',
        'gestion_no_contesta' => 2107,
        'gestion_cumplido'    => 2105,
    ];
}

function cobranza_tope_etapa(string $stageId): int {
    return cobranza_config()['topes'][$stageId] ?? 0;
}

/**
 * Cuenta los intentos NO CONTESTADOS del ciclo vigente.
 *
 * El "1234" en el asunto es contacto efectivo: cierra la tanda y reinicia la cuenta,
 * igual que en prospectos. Los sellos tecnicos del movil se ignoran salvo que lleven
 * 1234 -- ahi son el unico registro de la contestada (ver rc_digerir).
 *
 * $desde acota el ciclo: entrada a la etapa, o inicio de mes en ABOGADO.
 */
function cobranza_calcular_protocolo(
    array $actividades,
    ?int $excluirId = null,
    ?string $desde = null
): array {
    $sinContestar = 0; $contactos = 0; $fuera = 0; $ultima = null;
    $desde = is_string($desde) ? substr($desde, 0, 19) : '';

    foreach ($actividades as $a) {
        if ($excluirId !== null && (int)$a['ID'] === $excluirId) continue;
        if ((int)$a['TYPE_ID'] !== 2 || (int)$a['DIRECTION'] !== 2) continue;

        $originId = (string)($a['ORIGIN_ID'] ?? '');
        $subject  = (string)($a['SUBJECT'] ?? '');
        $selloMovil = str_starts_with($originId, 'VI_externalCall')
            || str_starts_with($subject, 'App móvil ·');
        if ($selloMovil && stripos($subject, '1234') === false) continue;

        $creada = substr((string)($a['CREATED'] ?? ''), 0, 19);
        if ($desde !== '' && $creada !== '' && $creada < $desde) { $fuera++; continue; }

        if (stripos($subject, '1234') !== false) {
            $contactos++; $sinContestar = 0; continue;    // contesto: la tanda se cierra
        }
        $sinContestar++;
        if ($creada !== '' && ($ultima === null || $creada > $ultima)) $ultima = $creada;
    }

    return [
        'sinContestar'  => $sinContestar,
        'contactos'     => $contactos,
        'fueraDelCiclo' => $fuera,
        'ultimoIntento' => $ultima,
    ];
}

/** Inicio del ciclo vigente: mes calendario en ABOGADO, entrada a la etapa en el resto. */
function cobranza_inicio_ciclo(string $stageId, ?string $entradaEtapa, DateTimeImmutable $ahora): ?string {
    if (in_array($stageId, cobranza_config()['etapas_ciclo_mensual'], true)) {
        return $ahora->modify('first day of this month')->setTime(0, 0)->format('Y-m-d H:i:s');
    }
    return $entradaEtapa !== null ? substr($entradaEtapa, 0, 19) : null;
}

/**
 * ¿Se puede pulsar el boton? Devuelve ['puede'=>bool,'motivo'=>string,'restantes'=>int].
 * Las razones son las del protocolo, no genericas: el que las lee tiene que entender
 * por que no se ofrece el boton sin abrir el codigo.
 */
function cobranza_puede_llamar(string $stageId, array $protocolo, array $deal): array {
    $cfg  = cobranza_config();

    // Un placement se engancha a TODOS los deals, no al embudo. En un deal de
    // ventas (C28:...) la etapa no está en el mapa de topes y saldría el mensaje
    // "en esta etapa no se llama", que a un vendedor no le dice nada. Se dice lo
    // que pasa de verdad: este botón no es el suyo.
    if (!preg_match('/^C(48|79):/', $stageId)) {
        return ['puede' => false, 'motivo' => 'otro_embudo', 'restantes' => 0];
    }

    $tope = cobranza_tope_etapa($stageId);
    if ($tope === 0) {
        return ['puede' => false, 'motivo' => 'etapa_sin_llamadas', 'restantes' => 0];
    }

    // Pausa: SOLO frena si ademas hay una actividad planificada a futuro. El campo
    // solo no alcanza -- un ESTADO EN PAUSA que quedo colgado sin planificada dejaria
    // al deal mudo para siempre.
    $pausa = (string)($deal[$cfg['campo_pausa']] ?? '');
    if ($pausa !== '' && !empty($deal['_planificada_futura'])) {
        return ['puede' => false, 'motivo' => 'en_pausa', 'restantes' => 0];
    }

    $hechas = (int)($protocolo['sinContestar'] ?? 0);
    if ($hechas >= $tope) {
        return ['puede' => false, 'motivo' => 'tope_de_etapa', 'restantes' => 0];
    }
    return ['puede' => true, 'motivo' => '', 'restantes' => $tope - $hechas];
}

/** Proximo intento: +2 dias HABILES desde ahora, saltando feriados de Ecuador. */
function cobranza_proximo_intento(DateTimeImmutable $ahora): DateTimeImmutable {
    $dias = cobranza_config()['dias_entre_intentos'];
    $at = $ahora->setTime(0, 0);
    $sumados = 0;
    for ($i = 0; $i < 30 && $sumados < $dias; $i++) {
        $at = $at->modify('+1 day');
        if (fer_es_habil($at)) $sumados++;
    }
    $hora = match (true) {
        (int)$ahora->format('G') < 11 => '12:30',
        (int)$ahora->format('G') < 14 => '16:00',
        (int)$ahora->format('G') < 18 => '19:00',
        default                       => '09:30',
    };
    [$h, $m] = array_map('intval', explode(':', $hora));
    return $at->setTime($h, $m);
}

/**
 * El estado de gestion que le toca al deal tras este intento fallido.
 * 3 intentos sin respuesta = CUMPLIDO: la asesora hizo su parte aunque no hablara.
 */
function cobranza_estado_gestion(array $protocolo, string $stageId): int {
    $cfg = cobranza_config();
    $hechas = (int)($protocolo['sinContestar'] ?? 0) + 1;
    return ($hechas % $cfg['intentos_por_tanda'] === 0)
        ? $cfg['gestion_cumplido']
        : $cfg['gestion_no_contesta'];
}
