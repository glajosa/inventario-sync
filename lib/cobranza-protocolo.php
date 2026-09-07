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
const COBRANZA_VER = 'cobranzas-boton-v10-responsable-de-cobranza';

function cobranza_config(): array {
    return [
        // Tope de llamadas POR CICLO, por etapa. 0 = el boton no se ofrece.
        // Los 6 de 3 MESES y ABOGADO son DOS TANDAS de 3 ("dias 6, 8, 10 · y 16, 18, 20"),
        // una por cada contacto efectivo exigido, no seis intentos sueltos.
        // Tope de INTENTOS por ciclo, por etapa. Cada contacto efectivo exigido lleva
        // su escalera de 3 intentos, asi que el tope = contactos x 3.
        // Fuente: protocolo_cobranza-7 (4-sep-2026). CAMBIO respecto de la version
        // anterior: 1 MES VENCIDO tenia 1 y son 3 -- "hasta 3 llamadas (D+13, D+15,
        // D+17)" -- y 2 MESES es "IGUAL que 1 MES en fechas", no el doble.
        'topes' => [
            'C48:UC_X35FSA'    => 0,   // MES CORRIENTE - "CERO llamadas", 100% automatica
            'C48:NEW'          => 0,   // AL DIA
            'C48:PREPARATION'  => 0,   // RESERVA
            'C48:UC_TPE9QV'    => 0,   // ADELANTADO
            'C48:UC_JW3G4N'    => 0,   // CANJE
            'C48:UC_RSP3F0'    => 0,   // ABOGADO DAR DE BAJA - solo el mail final
            'C48:UC_RIXTMH'    => 0,   // ERRORES O ANOMALIAS
            'C48:UC_1WHC5Q'    => 3,   // 1 MES VENCIDO   - 1 contacto x 3 (D+13,15,17)
            'C48:UC_LLUGGI'    => 3,   // 2 MESES VENCIDOS- igual que 1 MES
            'C48:UC_VXD8VQ'    => 6,   // 3 MESES VENCIDOS- 2 contactos x 3 (D+10,12,14 y D+20,22,24)
            // ABOGADO no va aca: su tope depende de si es el PRIMER MES en la etapa.
        ],
        // 🔴 ABOGADO cambio en protocolo-7: "PRIMER MES: 2 contactos -- 1 de cobranzas
        // + 1 del abogado (3 intentos cada uno). DESPUES: 1 contacto mensual del
        // abogado, siempre. La asesora de cobranzas ya no vuelve a entrar."
        'abogado_primer_mes' => 6,
        'abogado_despues'    => 3,
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
        // El ciclo se cuenta por MES CALENDARIO en todas las etapas de mora, no solo
        // en ABOGADO. Pedido del usuario el 7-sep-2026: "si ya pasa al otro mes, para
        // planificar deberia contar ya como el ciclo del siguiente mes para que haya
        // orden". El caso concreto: el cliente pacta el 28 y no cumple; el intento
        // del 3 del mes siguiente no puede arrastrar los intentos del mes cerrado.
        //
        // El corte es el MAS RECIENTE entre el inicio de mes y la entrada a la etapa,
        // asi que un deal que entro a mitad de mes cuenta desde que entro (ver
        // cobranza_inicio_ciclo).
        //
        // Hoy esto casi no cambia nada -- medido: 0 de 237 deals en 1/2/3 MESES
        // llevan mas de un mes en su etapa, porque el motor los va moviendo cuando
        // avanza la mora. Se pone igual, porque el dia que un deal se quede quieto
        // (pago parcial, refinanciamiento en revision) el tope no puede quedar
        // agotado para siempre.
        'etapas_ciclo_mensual' => ['C48:FINAL_INVOICE','C48:UC_1WHC5Q','C48:UC_LLUGGI','C48:UC_VXD8VQ'],
        'provider_id'      => 'VOXIMPLANT_CALL',
        'provider_type_id' => 'CALL',
        'campo_pausa'    => 'UF_CRM_ESTADO_PAUSA',
        'campo_gestion'  => 'UF_CRM_ESTADO_GESTION',
        // 🔴 La actividad va a nombre de la RESPONSABLE DE COBRANZA del deal, no del
        // asesor comercial ni de quien aprieta el boton. Pedido del usuario el
        // 7-sep-2026: "aqui no seria Ricardo, sino Martha Paola". El comercial cambia
        // seguido y la cobranza la lleva otra persona; si la actividad queda a nombre
        // del comercial, la asesora no la ve en su agenda y el deal se ve abandonado.
        // Lleno en 676 de 687 deals vivos (medido). Campo tipo employee.
        'campo_responsable_cob' => 'UF_CRM_1743119144',
        // los 5 ids de la lista ESTADO EN GESTION, leidos de Bitrix el 7-sep-2026
        'gestion_cumplido'       => 2105,
        'gestion_no_contesta'    => 2107,
        'gestion_en_proceso'     => 2109,
        'gestion_sin_gestionar'  => 2111,
        'gestion_pacto_incumpl'  => 2117,
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
        // 🔴 NO se mira COMPLETED, y es a proposito. El protocolo describe el pacto
        // como COMPLETED=N ("la conversacion pactada todavia no ocurrio"), pero el
        // uso REAL es otro: la asesora marca la llamada como HECHA -- porque la
        // hizo -- y le pone el deadline de lo que quedaron. Asi salio en la prueba
        // del 3-sep-2026 con el presidente: un 1234 completado con fecha al 9, y lo
        // que se esperaba era SILENCIO. Exigir COMPLETED=N rompe ese caso; se probo
        // el 5-sep y las pruebas lo atajaron.
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

function cobranza_tope_etapa(string $stageId, ?string $entradaEtapa = null,
                             ?DateTimeImmutable $ahora = null): int {
    $cfg = cobranza_config();

    if ($stageId === 'C48:FINAL_INVOICE') {          // ABOGADO
        // El primer mes entran dos: la asesora y el abogado. De ahi en adelante solo
        // el abogado. Sin dato de entrada se devuelve el tope MAYOR: frenar de mas es
        // peor que dejar un intento de sobra, porque el deal quedaria sin gestion.
        $ahora = $ahora ?? new DateTimeImmutable('now', new DateTimeZone('America/Guayaquil'));
        if ($entradaEtapa === null || $entradaEtapa === '') return (int)$cfg['abogado_primer_mes'];
        $te = strtotime($entradaEtapa);
        if ($te === false) return (int)$cfg['abogado_primer_mes'];
        // los dos en hora de Ecuador: MOVED_TIME llega con el huso del servidor de
        // Bitrix (+03:00) y comparar meses con 8 h de desfase falla el dia 1.
        $ent = (new DateTimeImmutable('@'.$te))->setTimezone(new DateTimeZone('America/Guayaquil'));
        return $ent->format('Y-m') === $ahora->format('Y-m')
            ? (int)$cfg['abogado_primer_mes']
            : (int)$cfg['abogado_despues'];
    }

    return (int)($cfg['topes'][$stageId] ?? 0);
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
    ?string $desde = null,
    ?callable $esContestada = null
): array {
    // 🔴 La regla de "contesto" entra por parametro para que CREDITO (pipeline 79)
    // reuse este contador sin copiarlo: alla los asuntos validos incluyen FECHA DE
    // PAGO. El orden, los sellos del movil y el reinicio por contacto efectivo son
    // logica delicada y dos copias se separan solas. Por defecto se comporta EXACTO
    // como antes: cobranza_es_contestada.
    $esContestada = $esContestada ?? 'cobranza_es_contestada';
    $sinContestar = 0; $contactos = 0; $fuera = 0; $ultima = null;
    // 🔴 'intentos' = TODAS las salientes del ciclo, planificadas incluidas. El
    // registro que crea el boton nace COMPLETED='N' porque ES la cita de la proxima
    // llamada: descartar las planificadas dejaria al boton sin ver sus propios
    // intentos, y se caerian el tope y la ventana de doble clic. Por eso aca se
    // cuenta distinto que en cic_contar() del proceso de ciclos, que mide dias con
    // llamada HECHA -- son dos preguntas distintas sobre el mismo deal.
    $intentos = 0; $pactoIncumplido = false;
    $hoyEc = (new DateTimeImmutable('now', new DateTimeZone('America/Guayaquil')))->format('Y-m-d');
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
        if ($selloMovil && !$esContestada($subject)) continue;

        $creada = (string)($a['CREATED'] ?? '');
        $creadaTs = $creada !== '' ? strtotime($creada) : false;
        if ($desdeTs !== null && $creadaTs !== false && $creadaTs < $desdeTs) { $fuera++; continue; }

        $intentos++;
        // ALARMA: quedo agendada una llamada con asunto de contestada, paso su fecha
        // y nadie la hizo. Es el PACTO INCUMPLIDO del protocolo.
        if ((string)($a['COMPLETED'] ?? '') === 'N') {
            $dlp = substr((string)($a['DEADLINE'] ?? ''), 0, 10);
            if ($dlp !== '' && $dlp < $hoyEc && $esContestada($subject)) $pactoIncumplido = true;
        }

        if ($esContestada($subject)) {
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
        'intentos'      => $intentos,
        'pactoIncumplido' => $pactoIncumplido,
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

    $tope = cobranza_tope_etapa($stageId, (string)($deal['MOVED_TIME'] ?? '') ?: null,
                                $deal['_ahora'] ?? null);
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
    [$h, $m] = cobranza_hora_alterna($ahora);
    return $at->setTime($h, $m);
}

/**
 * El estado de gestion que le toca al deal tras este intento fallido.
 * 3 intentos sin respuesta = CUMPLIDO: la asesora hizo su parte aunque no hablara.
 */
/**
 * ESTADO DE GESTION — los 5 valores de la lista, con el mismo SIGNIFICADO que les
 * da el proceso de ciclos (cic_estado_gestion de lib_ciclos.php).
 *
 * 🔴 Antes era `intentos % 3 == 0 ? CUMPLIDO : NO CONTESTA`. Eso daba CUMPLIDO en
 * el intento 3 y OTRA VEZ en el 6, decia NO CONTESTA en el primer intento, y nunca
 * usaba EN PROCESO ni PACTO INCUMPLIDO. De ahi la inconsistencia que reporto el
 * presidente: el campo no significaba lo que dice su nombre.
 *
 *   PACTO INCUMPLIDO  quedo una llamada agendada, paso su fecha y nadie la hizo
 *   CUMPLIDO          logro los contactos efectivos que la etapa exige
 *   NO CONTESTA       3 intentos o mas y CERO contactos
 *   SIN GESTIONAR     ni un intento en el ciclo
 *   EN PROCESO        algo hizo, todavia no alcanza
 *
 * Los CONTACTOS EXIGIDOS salen del tope de la etapa dividido 3, porque el tope se
 * construyo como "contactos exigidos x 3 intentos": 1 MES 3/3=1, 3 MESES 6/3=2,
 * ABOGADO primer mes 6/3=2 y despues 3/3=1. Una sola fuente, sin tabla duplicada.
 */
function cobranza_estado_gestion(array $protocolo, string $stageId,
                                 ?string $entradaEtapa = null,
                                 ?DateTimeImmutable $ahora = null): int {
    $cfg = cobranza_config();
    $intentos  = (int)($protocolo['intentos'] ?? 0) + 1;   // el que se registra ahora
    $contactos = (int)($protocolo['contactos'] ?? 0);
    $exigidos  = intdiv(cobranza_tope_etapa($stageId, $entradaEtapa, $ahora),
                        (int)$cfg['intentos_por_tanda']);

    if (!empty($protocolo['pactoIncumplido']))    return $cfg['gestion_pacto_incumpl'];
    if ($exigidos > 0 && $contactos >= $exigidos) return $cfg['gestion_cumplido'];
    if ($contactos === 0 && $intentos >= 3)       return $cfg['gestion_no_contesta'];
    if ($intentos === 0)                          return $cfg['gestion_sin_gestionar'];
    return $cfg['gestion_en_proceso'];
}

/**
 * La hora de la proxima llamada: SOLO mañana o tarde, y ALTERNANDO.
 *
 * Pedido del usuario el 7-sep-2026, y la razon es del oficio: las asesoras de
 * cobranzas NO llaman como los vendedores. No hay llamadas a las 17:00 ni a las
 * 19:00 -- eso era de la escalera de prospectos y aca no aplica.
 *
 *   llamo en la MAÑANA  -> la proxima cae en la TARDE   (13:00)
 *   llamo en la TARDE   -> la proxima cae en la MAÑANA  (09:30)
 *
 * Alternar sirve para algo concreto: si el cliente nunca contesta de mañana, la
 * vuelta siguiente lo busca de tarde. Llamar siempre a la misma hora es probar
 * dos veces lo mismo.
 */
function cobranza_hora_alterna(DateTimeImmutable $ahora): array {
    $esMañana = (int)$ahora->format('G') < 12;
    return $esMañana ? [13, 0] : [9, 30];
}

/**
 * A nombre de QUIEN queda la actividad.
 *
 * La RESPONSABLE DE COBRANZA del deal manda. Si el campo esta vacio (11 de 687
 * deals) se cae a quien apreto el boton, que es la persona que de verdad hizo la
 * llamada -- nunca al asesor comercial, que no gestiona la cobranza.
 * Acepta las dos formas del campo: UF_CRM_... (crm.deal.get) y ufCrm_...
 * (crm.item.get), porque los dos caminos existen en este codigo.
 */
function cobranza_responsable(array $deal, int $usuarioQueApreto): int {
    $c = cobranza_config()['campo_responsable_cob'];
    foreach ([$c, lcfirst(str_replace('UF_CRM_', 'ufCrm_', $c))] as $k) {
        $v = $deal[$k] ?? null;
        if (is_array($v)) $v = $v[0] ?? null;
        $id = (int)$v;
        if ($id > 0) return $id;
    }
    return $usuarioQueApreto;
}
