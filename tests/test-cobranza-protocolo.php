<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../lib/cobranza-protocolo.php';

// ---- topes por etapa (salen del protocolo del 2-sep-2026) ----
test_same(0, cobranza_tope_etapa('C48:UC_X35FSA'), 'MES CORRIENTE no se llama');
test_same(1, cobranza_tope_etapa('C48:UC_1WHC5Q'), '1 MES VENCIDO: 1 llamada');
test_same(3, cobranza_tope_etapa('C48:UC_LLUGGI'), '2 MESES VENCIDOS: 3 llamadas');
test_same(6, cobranza_tope_etapa('C48:UC_VXD8VQ'), '3 MESES VENCIDOS: 6 llamadas');
test_same(6, cobranza_tope_etapa('C48:FINAL_INVOICE'), 'ABOGADO: 6 llamadas');
test_same(0, cobranza_tope_etapa('C48:INVENTADA'), 'etapa desconocida no habilita el boton');

// ---- conteo del ciclo ----
$h = [
    fake_activity(1, 'Llamada saliente Denisse', '2026-08-18T09:00:00-05:00'),
    fake_activity(2, 'Llamada saliente Denisse', '2026-08-20T09:00:00-05:00'),
];
test_same(2, cobranza_calcular_protocolo($h)['sinContestar'], 'dos intentos fallidos seguidos');

// el 1234 cierra la tanda y REINICIA la cuenta
$h2 = array_merge($h, [fake_activity(3, '1234 hablo con la clienta', '2026-08-22T09:00:00-05:00')]);
$p2 = cobranza_calcular_protocolo($h2);
test_same(0, $p2['sinContestar'], 'el 1234 reinicia la cuenta');
test_same(1, $p2['contactos'], 'el 1234 cuenta como contacto efectivo');

// el sello tecnico del movil NO cuenta... salvo que lleve 1234
$h3 = [
    fake_activity(4, 'App móvil · No contestó', '2026-08-20T14:00:00-05:00') + ['ORIGIN_ID' => 'VI_externalCall.x'],
    fake_activity(5, 'Llamada saliente Michelle', '2026-08-20T14:01:00-05:00'),
];
test_same(1, cobranza_calcular_protocolo($h3)['sinContestar'], 'el sello del movil no duplica el intento');

// lo anterior al inicio del ciclo queda fuera
$p4 = cobranza_calcular_protocolo($h, null, '2026-08-19 00:00:00');
test_same(1, $p4['sinContestar'], 'solo cuenta lo del ciclo vigente');
test_same(1, $p4['fueraDelCiclo'], 'lo viejo se reporta, no se esconde');

// ---- la guardia del tope ----
$deal = [];
test_same(false, cobranza_puede_llamar('C48:UC_X35FSA', ['sinContestar'=>0], $deal)['puede'], 'MES CORRIENTE: boton apagado');
test_same('etapa_sin_llamadas', cobranza_puede_llamar('C48:UC_X35FSA', ['sinContestar'=>0], $deal)['motivo'], 'motivo explicito');
test_same(true,  cobranza_puede_llamar('C48:UC_1WHC5Q', ['sinContestar'=>0], $deal)['puede'], '1 MES: primera si');
test_same(false, cobranza_puede_llamar('C48:UC_1WHC5Q', ['sinContestar'=>1], $deal)['puede'], '1 MES: la segunda ya no');
test_same('tope_de_etapa', cobranza_puede_llamar('C48:UC_1WHC5Q', ['sinContestar'=>1], $deal)['motivo'], 'motivo del tope');
test_same(2, cobranza_puede_llamar('C48:UC_LLUGGI', ['sinContestar'=>1], $deal)['restantes'], 'quedan 2 de 3');

// ---- la pausa: el campo SOLO no alcanza ----
$pausado = ['UF_CRM_ESTADO_PAUSA' => '2119', '_planificada_futura' => true];
test_same(false, cobranza_puede_llamar('C48:UC_LLUGGI', ['sinContestar'=>0], $pausado)['puede'], 'pausa + planificada futura frena');
test_same('en_pausa', cobranza_puede_llamar('C48:UC_LLUGGI', ['sinContestar'=>0], $pausado)['motivo'], 'motivo de la pausa');
$colgado = ['UF_CRM_ESTADO_PAUSA' => '2119'];   // sin planificada: quedo colgado
test_same(true, cobranza_puede_llamar('C48:UC_LLUGGI', ['sinContestar'=>0], $colgado)['puede'],
    'una pausa colgada sin planificada NO deja al deal mudo para siempre');

// ---- +2 dias habiles, saltando fin de semana ----
// jueves 3-sep-2026 -> +2 habiles = lunes 7 (sabado y domingo no cuentan)
$jue = new DateTimeImmutable('2026-09-03T09:00:00-05:00');
test_same('2026-09-07', cobranza_proximo_intento($jue)->format('Y-m-d'), '+2 habiles salta el fin de semana');
test_same('12:30', cobranza_proximo_intento($jue)->format('H:i'), 'antes de las 11 -> 12:30');
$mar = new DateTimeImmutable('2026-09-01T09:00:00-05:00');
test_same('2026-09-03', cobranza_proximo_intento($mar)->format('Y-m-d'), 'martes -> jueves');

// ---- 3 intentos sin respuesta = CUMPLIDO (hizo su parte) ----
test_same(2107, cobranza_estado_gestion(['sinContestar'=>0], 'C48:UC_LLUGGI'), 'primer fallo: NO CONTESTA');
test_same(2107, cobranza_estado_gestion(['sinContestar'=>1], 'C48:UC_LLUGGI'), 'segundo fallo: NO CONTESTA');
test_same(2105, cobranza_estado_gestion(['sinContestar'=>2], 'C48:UC_LLUGGI'), 'tercer fallo: CUMPLIDO');

// ---- ciclo mensual de ABOGADO ----
$ahora = new DateTimeImmutable('2026-09-15T10:00:00-05:00');
// ABOGADO: el corte es el MAS RECIENTE de mes-vs-etapa.
// entro en marzo, hoy 15-sep -> gana el mes (la secuencia se repite mensual)
test_same('2026-09-01T00:00:00-05:00',
    cobranza_inicio_ciclo('C48:FINAL_INVOICE', '2026-03-02T08:00:00+03:00', $ahora),
    'ABOGADO viejo: cuenta desde el inicio de mes');
// 🔴 entro a ABOGADO HOY -> gana la etapa, si no se traga los intentos de la
// etapa anterior. Es el caso que se vio en vivo en el deal 406519.
test_same('2026-09-10T09:00:00+03:00',
    cobranza_inicio_ciclo('C48:FINAL_INVOICE', '2026-09-10T09:00:00+03:00', $ahora),
    'ABOGADO recien: cuenta desde que entro, no desde el 1 del mes');
test_same('2026-09-01T00:00:00-05:00',
    cobranza_inicio_ciclo('C48:FINAL_INVOICE', '', $ahora),
    'ABOGADO sin MOVED_TIME: cae en el mes');
// y el intento de la etapa anterior YA NO cuenta
$prev = [
    fake_activity(1,'Llamada saliente Ana','2026-09-14T10:00:00-05:00'),  // en 3 MESES
    fake_activity(2,'Llamada saliente Ana','2026-09-15T11:00:00-05:00'),  // ya en ABOGADO
];
$ini = cobranza_inicio_ciclo('C48:FINAL_INVOICE', '2026-09-15T18:30:00+03:00', $ahora); // 10:30 Ecuador
$pp = cobranza_calcular_protocolo($prev, null, $ini);
test_same(1, $pp['sinContestar'], 'al entrar a ABOGADO no se arrastra el intento de la etapa anterior');
test_same(1, $pp['fueraDelCiclo'], 'el de la etapa anterior queda reportado como fuera');
// el resto devuelve MOVED_TIME TAL CUAL, con su huso: quien compara usa strtotime
test_same('2026-03-02T08:00:00+03:00',
    cobranza_inicio_ciclo('C48:UC_LLUGGI', '2026-03-02T08:00:00+03:00', $ahora),
    'el resto cuenta desde que entro a la etapa (MOVED_TIME sin tocar)');
test_same(null, cobranza_inicio_ciclo('C48:UC_LLUGGI', '', $ahora),
    'sin MOVED_TIME no hay ventana (cuenta todo), y se ve');

// ── 🔴 la ventana del ciclo: el fallo que hacia decir "intento 2" con un intento ──
// MOVED_TIME es la entrada a la etapa. Lo anterior es de OTRO ciclo y no cuenta.
$hist = [
    fake_activity(1,'Llamada saliente Ana','2026-08-06T09:00:00-05:00'),   // ciclo viejo
    fake_activity(2,'Llamada saliente Ana','2026-08-18T09:00:00-05:00'),   // ciclo viejo
    fake_activity(3,'Llamada saliente Ana','2026-09-03T10:25:00-05:00'),   // antes de mover
    fake_activity(4,'Llamada saliente Ana','2026-09-03T11:00:00-05:00'),   // ya en la etapa
];
// entro a la etapa el 3-sep 10:40 Ecuador == 18:40 del servidor de Bitrix (+03:00)
$movedTime = '2026-09-03T18:40:05+03:00';
$p = cobranza_calcular_protocolo($hist, null, $movedTime);
test_same(1, $p['sinContestar'], 'solo la llamada POSTERIOR a entrar a la etapa cuenta');
test_same(3, $p['fueraDelCiclo'], 'las 3 anteriores se reportan como fuera, no se esconden');
// sin ventana (el bug): contaria las cuatro
test_same(4, cobranza_calcular_protocolo($hist, null, null)['sinContestar'],
    'sin ventana cuenta TODA la historia: era el bug');
// el huso importa: comparar como texto ponia 18:40 despues de las 11:00 locales
test_same(1, cobranza_calcular_protocolo($hist, null, '2026-09-03T18:40:05+03:00')['sinContestar'],
    'la comparacion es por instante, no por cadena');

// ── fuera de cobranzas: se dice claro, no se disfraza de "esta etapa no llama" ──
// Un placement se engancha a TODOS los deals. En uno de ventas la etapa no esta
// en el mapa de topes y el mensaje generico no le dice nada al vendedor.
test_same('otro_embudo', cobranza_puede_llamar('C28:PREPARATION', ['sinContestar'=>0], [])['motivo'],
    'un deal de VENTAS se rechaza por embudo, no por etapa');
test_same(false, cobranza_puede_llamar('C28:NEW', ['sinContestar'=>0], [])['puede'], 'y no se puede llamar');
test_same('otro_embudo', cobranza_puede_llamar('C44:WON', ['sinContestar'=>0], [])['motivo'], 'CLIENTES tambien fuera');
test_same('otro_embudo', cobranza_puede_llamar('', ['sinContestar'=>0], [])['motivo'], 'etapa vacia: fuera');
// los de cobranzas siguen pasando por la puerta correcta
test_same('etapa_sin_llamadas', cobranza_puede_llamar('C48:UC_X35FSA', ['sinContestar'=>0], [])['motivo'],
    'C48 sigue evaluandose por etapa');
test_same(true, cobranza_puede_llamar('C79:PREPARATION', ['sinContestar'=>0], [])['puede'] === false, 'C79 entra al mapa (tope 0)');

// ── ABOGADO: agotados los 6, se reabre SOLO el mes siguiente ──
// Es la pregunta del usuario: "cuando pasan esos 6 intentos y nunca contesto,
// se puede aplastar nuevamente, ya que el ciclo se repite ahi".
// El deal entro a ABOGADO el 3-sep y nunca contesto: 6 intentos en septiembre.
$entradaAbogado = '2026-09-03T18:40:00+03:00';
$seis = [];
foreach ([4,8,10,15,17,21] as $i => $dia) {
    $seis[] = fake_activity(600+$i, 'Llamada saliente Ana',
        sprintf('2026-09-%02dT10:00:00-05:00', $dia));
}

// (a) el 25 de septiembre: los 6 estan dentro del ciclo -> topado
$sep = new DateTimeImmutable('2026-09-25T10:00:00-05:00');
$iniSep = cobranza_inicio_ciclo('C48:FINAL_INVOICE', $entradaAbogado, $sep);
$pSep = cobranza_calcular_protocolo($seis, null, $iniSep);
test_same(6, $pSep['sinContestar'], 'en septiembre lleva los 6');
test_same(false, cobranza_puede_llamar('C48:FINAL_INVOICE', $pSep, [])['puede'],
    'con los 6 hechos NO se puede apretar mas en el mes');
test_same('tope_de_etapa', cobranza_puede_llamar('C48:FINAL_INVOICE', $pSep, [])['motivo'],
    'y el motivo es el tope, no otra cosa');

// (b) el 1 de OCTUBRE: el corte pasa a ser el 1-oct (la entrada es mas vieja)
//     -> los 6 de septiembre quedan fuera y la escalera arranca limpia
$oct = new DateTimeImmutable('2026-10-01T09:00:00-05:00');
$iniOct = cobranza_inicio_ciclo('C48:FINAL_INVOICE', $entradaAbogado, $oct);
test_same('2026-10-01T00:00:00-05:00', $iniOct, 'en octubre gana el inicio de mes');
$pOct = cobranza_calcular_protocolo($seis, null, $iniOct);
test_same(0, $pOct['sinContestar'], 'los 6 de septiembre ya no cuentan');
test_same(6, $pOct['fueraDelCiclo'], 'quedan reportados como del ciclo anterior, no borrados');
$permOct = cobranza_puede_llamar('C48:FINAL_INVOICE', $pOct, []);
test_same(true, $permOct['puede'], 'el 1 de octubre se puede apretar de nuevo');
test_same(6, $permOct['restantes'], 'y vuelve a tener los 6 completos');

// (c) el contraste: en 2 MESES VENCIDOS agotar el tope NO se reabre en el mes
//     siguiente, porque ahi el ciclo va con la ETAPA, no con el mes.
$tresEnEtapa = [
    fake_activity(700,'Llamada saliente Ana','2026-09-04T10:00:00-05:00'),
    fake_activity(701,'Llamada saliente Ana','2026-09-08T10:00:00-05:00'),
    fake_activity(702,'Llamada saliente Ana','2026-09-10T10:00:00-05:00'),
];
$iniEtapaOct = cobranza_inicio_ciclo('C48:UC_LLUGGI', $entradaAbogado, $oct);
$pEtapa = cobranza_calcular_protocolo($tresEnEtapa, null, $iniEtapaOct);
test_same(3, $pEtapa['sinContestar'], '2 MESES sigue contando desde la etapa, aunque cambie el mes');
test_same(false, cobranza_puede_llamar('C48:UC_LLUGGI', $pEtapa, [])['puede'],
    'y sigue topado: para reabrirlo el deal tiene que MOVERSE de etapa');

// ── el 1234 mata la ventana, no solo la cuenta ──
$par = [
    fake_activity(1,'Llamada saliente Ana','2026-09-03T08:55:00-05:00') + ['COMPLETED'=>'N'],
    fake_activity(2,'1234 hablé con la clienta','2026-09-03T08:58:00-05:00') + ['COMPLETED'=>'Y'],
];
$pc = cobranza_calcular_protocolo($par, null, null);
test_same(0, $pc['sinContestar'], 'el 1234 reinicia la cuenta');
test_same(null, $pc['ultimoIntento'], 'y TAMBIEN borra la ventana de repeticion');
test_same(1, $pc['contactos'], 'y suma el contacto efectivo');

// una fallida CERRADA se reporta como cerrada
$cerr = [fake_activity(3,'Llamada saliente Ana','2026-09-03T08:55:00-05:00') + ['COMPLETED'=>'Y']];
$pz = cobranza_calcular_protocolo($cerr, null, null);
test_same(true, $pz['ultimoCerrado'], 'una fallida completada se marca como cerrada');
$abr = [fake_activity(4,'Llamada saliente Ana','2026-09-03T08:55:00-05:00') + ['COMPLETED'=>'N']];
test_same(false, cobranza_calcular_protocolo($abr, null, null)['ultimoCerrado'], 'y una abierta no');
