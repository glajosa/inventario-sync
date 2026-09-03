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

// Se sirve por HTTP en cobranza_app.php y como comentario en cobranza_nativo.php.
// Sin esto no habia forma de comprobar QUE version esta desplegada: el endpoint
// respondia 400 al GET igual de nuevo que de viejo, y los archivos de lib/ no se
// sirven. Tres despliegues seguidos sin poder verificar por fuera.
const COBRANZA_VER = 'cobranzas-boton-v7-tres-asuntos-y-pacto';

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
        // Tope de seguridad del pacto, en dias. Existe solo contra una fecha
        // absurda cargada a mano (un 2030 muteaba el deal para siempre). 0 = sin
        // tope. OJO: el protocolo tiene su propio tope de 15 dias corridos para
        // REFINANCIAMIENTO, y eso le toca al proceso que administra las pausas,
        // que todavia no existe. Este numero NO es esa regla.
        'tope_pacto_dias' => 90,
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

/**
 * ¿Este asunto es una CONTESTADA? Son TRES, no uno.
 *
 * 🔴 Del protocolo, textual: "CONTESTADA = el SUBJECT es 1234, PROMESA DE PAGO o
 * REFINANCIAMIENTO. Cualquier otra cosa = NO contesto. Antes esta especificacion
 * decia solo 1234: con los tres asuntos hay que aceptar los tres."
 * Yo habia programado solo 1234, asi que una PROMESA DE PAGO contaba como intento
 * fallido: castigaba a la asesora por haber logrado justo lo que se le pedia.
 */
function cobranza_es_contestada(string $subject): bool {
    $s = mb_strtoupper(trim($subject), 'UTF-8');
    return str_contains($s, '1234')
        || str_contains($s, 'PROMESA DE PAGO')
        || str_contains($s, 'REFINANCIAMIENTO');
}

/**
 * ¿Hay un PACTO vigente? Devuelve ['fecha'=>ISO,'asunto'=>..] o null.
 *
 * Una contestada que deja un DEADLINE a futuro es un acuerdo con el cliente:
 *   1234 + deadline      -> quedaron en volver a hablar (CONVERSACION AGENDADA)
 *   PROMESA DE PAGO      -> la fecha la puso EL cliente: "no se le molesta hasta ese dia"
 *   REFINANCIAMIENTO     -> la asesora pidio no insistir mientras el directorio revisa
 *
 * 🔴 Mira TODAS las actividades, NO solo las del ciclo: el protocolo dice que "LA
 * PAUSA SOBREVIVE AL CAMBIO DE CICLO". Un pacto a 3 semanas cae en el ciclo
 * siguiente y sigue valiendo.
 *
 * 🔴 Y NO depende del campo ESTADO EN PAUSA: hoy ese campo lo llena nadie (el
 * proceso que lo escribe todavia no existe), asi que exigirlo dejaba la guardia
 * muerta y el boton llamaba encima de un pacto vivo.
 */
function cobranza_pacto_vigente(array $actividades, int $ahoraTs): ?array {
    $mejor = null;
    foreach ($actividades as $a) {
        if ((int)($a['TYPE_ID'] ?? 0) !== 2 || (int)($a['DIRECTION'] ?? 0) !== 2) continue;
        $subject = (string)($a['SUBJECT'] ?? '');
        if (!cobranza_es_contestada($subject)) continue;
        $dl = (string)($a['DEADLINE'] ?? '');
        if ($dl === '') $dl = (string)($a['END_TIME'] ?? '');
        if ($dl === '') continue;
        $ts = strtotime($dl);
        if ($ts === false || $ts <= $ahoraTs) continue;      // ya paso: no es pacto vigente
        $tope = (int)cobranza_config()['tope_pacto_dias'];
        if ($tope > 0 && ($ts - $ahoraTs) > $tope * 86400) continue;  // fecha absurda
        if ($mejor === null || $ts > $mejor['ts']) {
            $mejor = ['ts' => $ts, 'fecha' => $dl, 'asunto' => trim($subject)];
        }
    }
    return $mejor;
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
    // Se compara por INSTANTE, no por cadena. CREATED y MOVED_TIME llegan con su
    // propio huso (+03:00 del servidor de Bitrix) y el inicio de mes se calcula en
    // hora de Ecuador: recortar a 19 caracteres y comparar como texto mezclaba tres
    // relojes distintos y el desfase se comia hasta 8 horas de actividades.
    $desdeTs = (is_string($desde) && $desde !== '') ? strtotime($desde) : null;
    if ($desdeTs === false) $desdeTs = null;
    $ultimaCerrada = false;

    // El conteo DEPENDE del orden (un 1234 posterior cierra la tanda anterior).
    // El servicio ya pide CREATED ASC, pero dejarlo implicito es una trampa para
    // el proximo que llame a esta funcion desde otro sitio.
    usort($actividades, function ($a, $b) {
        $ta = strtotime((string)($a['CREATED'] ?? '')) ?: 0;
        $tb = strtotime((string)($b['CREATED'] ?? '')) ?: 0;
        return $ta <=> $tb ?: ((int)($a['ID'] ?? 0) <=> (int)($b['ID'] ?? 0));
    });

    foreach ($actividades as $a) {
        if ($excluirId !== null && (int)$a['ID'] === $excluirId) continue;
        if ((int)$a['TYPE_ID'] !== 2 || (int)$a['DIRECTION'] !== 2) continue;

        $originId = (string)($a['ORIGIN_ID'] ?? '');
        $subject  = (string)($a['SUBJECT'] ?? '');
        $selloMovil = str_starts_with($originId, 'VI_externalCall')
            || str_starts_with($subject, 'App móvil ·');
        if ($selloMovil && !cobranza_es_contestada($subject)) continue;

        $creada = (string)($a['CREATED'] ?? '');
        $creadaTs = $creada !== '' ? strtotime($creada) : false;
        if ($desdeTs !== null && $creadaTs !== false && $creadaTs < $desdeTs) { $fuera++; continue; }

        if (cobranza_es_contestada($subject)) {
            // Contesto: la tanda se cierra. 🔴 Tambien muere la ventana de
            // repeticion: reiniciaba la CUENTA pero seguia apuntando al intento
            // fallido anterior, asi que tras registrar una contestada el boton
            // quedaba bloqueado 10 minutos sin motivo.
            $contactos++; $sinContestar = 0; $ultima = null; $ultimaCerrada = false;
            continue;
        }
        $sinContestar++;
        // se guarda CRUDA, con su huso: el que la lea usa strtotime, no le pega un
        // offset a mano (eso desplazaba la ventana de repeticion 5 horas).
        if ($creadaTs !== false && ($ultima === null || $creadaTs > strtotime((string)$ultima))) {
            $ultima = $creada;
            // Si la asesora YA cerro esa llamada planificada, esta diciendo "esta
            // la hice". La proxima pulsacion es un intento nuevo, no un doble clic:
            // la ventana no debe frenarla.
            $ultimaCerrada = ((string)($a['COMPLETED'] ?? '') === 'Y');
        }
    }

    return [
        'sinContestar'  => $sinContestar,
        'contactos'     => $contactos,
        'fueraDelCiclo' => $fuera,
        'ultimoIntento' => $ultima,
        'ultimoCerrado' => $ultimaCerrada,
    ];
}

/** Inicio del ciclo vigente: mes calendario en ABOGADO, entrada a la etapa en el resto. */
function cobranza_inicio_ciclo(string $stageId, ?string $entradaEtapa, DateTimeImmutable $ahora): ?string {
    $entrada = ($entradaEtapa !== null && $entradaEtapa !== '') ? $entradaEtapa : null;

    if (in_array($stageId, cobranza_config()['etapas_ciclo_mensual'], true)) {
        // ABOGADO no es un ciclo que se agota: la secuencia se repite todos los
        // meses. Pero el mes NO alcanza como corte.
        //
        // 🔴 Con el inicio de mes a secas, un deal que llega a ABOGADO el 3-sep se
        // traga los intentos que hizo en 3 MESES VENCIDOS el 1 y el 2: el boton
        // decia "ya se registro recien" por una llamada de OTRA etapa. Visto en
        // vivo en el deal 406519.
        //
        // El corte es el MAS RECIENTE de los dos:
        //   entro a ABOGADO en marzo, hoy es 15-sep -> cuenta desde el 1-sep  (mensual)
        //   entro a ABOGADO hoy 3-sep             -> cuenta desde el 3-sep  (la etapa)
        $mes = $ahora->modify('first day of this month')->setTime(0, 0)->format(DateTimeInterface::ATOM);
        if ($entrada === null) return $mes;
        $tm = strtotime($mes); $te = strtotime($entrada);
        if ($te === false) return $mes;
        return ($te > $tm) ? $entrada : $mes;
    }

    // El resto cuenta desde que el deal ENTRO a su etapa actual (MOVED_TIME). Se
    // devuelve tal cual, con su huso: quien compara usa strtotime.
    return $entrada;
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

    // 🔴 EL PACTO VA PRIMERO. El protocolo: "Verificacion de pausa ANTES DE CADA
    // PASO (mensaje o llamada)" y "durante la ventana pactada NO sale ningun
    // mensaje ni llamada". Se revisa antes del tope: un deal que pacto fecha no se
    // llama aunque le queden intentos.
    if (!empty($deal['_pacto']['fecha'])) {
        return ['puede' => false, 'motivo' => 'pacto_vigente', 'restantes' => 0,
                'pacto' => $deal['_pacto']];
    }

    // El campo ESTADO EN PAUSA como senal secundaria: hoy no lo llena nadie, pero
    // el dia que el proceso de pausas exista, se respeta. Solo frena si ademas hay
    // una planificada a futuro: un campo colgado sin planificada dejaria al deal
    // mudo para siempre.
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
