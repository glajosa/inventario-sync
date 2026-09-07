<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../lib/cobranza-protocolo.php';

// ---- topes por etapa (salen del protocolo del 2-sep-2026) ----
test_same(0, cobranza_tope_etapa('C48:UC_X35FSA'), 'MES CORRIENTE no se llama');
// protocolo-7 (4-sep-2026): cada contacto exigido lleva 3 intentos -> tope = contactos x 3
test_same(3, cobranza_tope_etapa('C48:UC_1WHC5Q'), '1 MES VENCIDO: 3 intentos (D+13,15,17)');
test_same(3, cobranza_tope_etapa('C48:UC_LLUGGI'), '2 MESES VENCIDOS: 3 llamadas');
test_same(6, cobranza_tope_etapa('C48:UC_VXD8VQ'), '3 MESES VENCIDOS: 6 llamadas');
// ABOGADO depende de si es el PRIMER MES en la etapa
$hoyEc = new DateTimeImmutable('2026-09-15T10:00:00-05:00');
test_same(6, cobranza_tope_etapa('C48:FINAL_INVOICE','2026-09-04T16:00:00+03:00',$hoyEc),
    'ABOGADO primer mes: 6 (1 de cobranzas + 1 del abogado, 3 intentos cada uno)');
test_same(3, cobranza_tope_etapa('C48:FINAL_INVOICE','2026-03-04T16:00:00+03:00',$hoyEc),
    'ABOGADO despues: 3 (solo el abogado)');
test_same(6, cobranza_tope_etapa('C48:FINAL_INVOICE', null, $hoyEc),
    'sin MOVED_TIME devuelve el tope MAYOR: frenar de mas dejaria el deal sin gestion');
test_same(6, cobranza_tope_etapa('C48:FINAL_INVOICE','basura',$hoyEc),
    'una fecha ilegible tampoco frena de mas');
// el 1 del mes, con el desfase de husos: entro el 31-ago 20:00 Ecuador = 1-sep 04:00 del
// servidor de Bitrix. Comparando en hora de Ecuador es OTRO mes, y eso es lo correcto.
test_same(3, cobranza_tope_etapa('C48:FINAL_INVOICE','2026-09-01T04:00:00+03:00',
    new DateTimeImmutable('2026-09-15T10:00:00-05:00')),
    'el huso no puede mover el mes de entrada');
// ABOGADO DAR DE BAJA: solo el mail final
test_same(0, cobranza_tope_etapa('C48:UC_RSP3F0'), 'ABOGADO DAR DE BAJA: cero llamadas');
test_same(0, cobranza_tope_etapa('C48:UC_RIXTMH'), 'ERRORES O ANOMALIAS: cero');
test_same(0, cobranza_tope_etapa('C48:PREPARATION'), 'RESERVA: cero');
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
test_same(true,  cobranza_puede_llamar('C48:UC_1WHC5Q', ['sinContestar'=>2], $deal)['puede'], '1 MES: la tercera todavia si');
test_same(false, cobranza_puede_llamar('C48:UC_1WHC5Q', ['sinContestar'=>3], $deal)['puede'], '1 MES: la cuarta ya no');
test_same('tope_de_etapa', cobranza_puede_llamar('C48:UC_1WHC5Q', ['sinContestar'=>3], $deal)['motivo'], 'motivo del tope');
// ABOGADO por la puerta de puede_llamar, con MOVED_TIME en el deal
$abgNuevo = ['MOVED_TIME'=>'2026-09-04T16:00:00+03:00','_ahora'=>$hoyEc];
$abgViejo = ['MOVED_TIME'=>'2026-03-04T16:00:00+03:00','_ahora'=>$hoyEc];
test_same(2, cobranza_puede_llamar('C48:FINAL_INVOICE', ['sinContestar'=>4], $abgNuevo)['restantes'],
    'ABOGADO primer mes: con 4 hechos quedan 2');
test_same(false, cobranza_puede_llamar('C48:FINAL_INVOICE', ['sinContestar'=>4], $abgViejo)['puede'],
    'ABOGADO despues: con 4 hechos ya topo (su tope es 3)');
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
// 🔴 Solo mañana o tarde, alternando (decision del usuario 7-sep-2026): las
// asesoras de cobranzas NO llaman a las 17:00 ni a las 19:00 como los vendedores.
test_same('13:00', cobranza_proximo_intento($jue)->format('H:i'), 'llamo de mañana -> la proxima en la TARDE');
test_same('09:30', cobranza_proximo_intento(new DateTimeImmutable('2026-09-03T14:00:00-05:00'))->format('H:i'), 'llamo de tarde -> la proxima en la MAÑANA');
foreach (['07:00','09:15','11:59','12:00','15:45','18:30','21:00'] as $hh) {
    $hp = cobranza_proximo_intento(new DateTimeImmutable("2026-09-03T$hh:00-05:00"))->format('H:i');
    test_same(true, in_array($hp, ['09:30','13:00'], true), "desde las $hh cae 09:30 o 13:00, nunca 17:00 ni 19:00 (dio $hp)");
}
$mar = new DateTimeImmutable('2026-09-01T09:00:00-05:00');
test_same('2026-09-03', cobranza_proximo_intento($mar)->format('Y-m-d'), 'martes -> jueves');

// ---- ESTADO DE GESTION: los 5 valores, con el significado que dice su nombre ----
// 🔴 Antes era `intentos % 3`: decia NO CONTESTA en el primer intento y CUMPLIDO en
// el 3 y otra vez en el 6. Unificado el 7-sep-2026 con la regla del proceso de
// ciclos, porque el presidente veia el campo diciendo cosas que no correspondian.
$EP=2109; $NC=2107; $CU=2105; $SG=2111; $PI=2117;
$M1='C48:UC_1WHC5Q';   // 1 MES VENCIDO   -> 1 contacto exigido
$M3='C48:UC_VXD8VQ';   // 3 MESES VENCIDOS -> 2 contactos exigidos

test_same($EP, cobranza_estado_gestion(['intentos'=>0,'contactos'=>0], $M1),
    'primer intento: EN PROCESO -- esta trabajandolo, no es "no contesta"');
test_same($EP, cobranza_estado_gestion(['intentos'=>1,'contactos'=>0], $M1),
    'segundo intento: sigue EN PROCESO');
test_same($NC, cobranza_estado_gestion(['intentos'=>2,'contactos'=>0], $M1),
    'tercer intento y CERO contactos: NO CONTESTA');
test_same($NC, cobranza_estado_gestion(['intentos'=>7,'contactos'=>0], $M1),
    'y sigue siendo NO CONTESTA en el octavo: no vuelve a CUMPLIDO como antes');
test_same($CU, cobranza_estado_gestion(['intentos'=>2,'contactos'=>1], $M1),
    '1 MES con 1 contacto logrado: CUMPLIDO');
test_same($EP, cobranza_estado_gestion(['intentos'=>3,'contactos'=>1], $M3),
    '3 MESES exige 2 contactos: con 1 todavia EN PROCESO');
test_same($CU, cobranza_estado_gestion(['intentos'=>4,'contactos'=>2], $M3),
    'con los 2 contactos: CUMPLIDO');
test_same($PI, cobranza_estado_gestion(['intentos'=>1,'contactos'=>0,'pactoIncumplido'=>true], $M1),
    'el pacto incumplido gana sobre todo lo demas');
test_same($PI, cobranza_estado_gestion(['intentos'=>9,'contactos'=>5,'pactoIncumplido'=>true], $M3),
    'incluso con los contactos logrados: quedo una llamada agendada sin hacer');
// los contactos exigidos salen del tope /3, sin tabla duplicada
test_same(1, intdiv(cobranza_tope_etapa($M1), 3), '1 MES exige 1 contacto (tope 3 / 3)');
test_same(2, intdiv(cobranza_tope_etapa($M3), 3), '3 MESES exige 2 (tope 6 / 3)');
$hoyEc2 = new DateTimeImmutable('2026-09-15T10:00:00-05:00');
test_same(2, intdiv(cobranza_tope_etapa('C48:FINAL_INVOICE','2026-09-04T16:00:00+03:00',$hoyEc2), 3),
    'ABOGADO primer mes exige 2 (tope 6 / 3)');
test_same(1, intdiv(cobranza_tope_etapa('C48:FINAL_INVOICE','2026-03-04T16:00:00+03:00',$hoyEc2), 3),
    'ABOGADO despues exige 1 (tope 3 / 3)');


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
// 🔴 Desde el 7-sep-2026 las etapas de mora tambien cuentan por MES: si el deal
// entro hace meses, el ciclo arranca el 1 del mes corriente, no en la entrada.
// Antes esto devolvia MOVED_TIME tal cual y los intentos de meses viejos
// bloqueaban el boton para siempre.
test_same('2026-09-01',
    substr((string)cobranza_inicio_ciclo('C48:UC_LLUGGI', '2026-03-02T08:00:00+03:00', $ahora), 0, 10),
    'entro en marzo: el ciclo cuenta desde el 1 del mes corriente');
// y si entro DENTRO del mes, manda la entrada (es la mas reciente de las dos)
test_same('2026-09-02T08:00:00+03:00',
    cobranza_inicio_ciclo('C48:UC_LLUGGI', '2026-09-02T08:00:00+03:00', $ahora),
    'entro este mes: cuenta desde que entro, con su huso sin tocar');
// 🔴 Sin MOVED_TIME ya NO se cuenta toda la historia: en las etapas de mora se
// cae al inicio del mes corriente, que es mas correcto. Antes devolvia null (sin
// ventana) y los intentos de meses viejos bloqueaban el boton.
test_same('2026-09-01',
    substr((string)cobranza_inicio_ciclo('C48:UC_LLUGGI', null, $ahora), 0, 10),
    'sin MOVED_TIME el ciclo arranca el 1 del mes corriente');

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
// 🔴 Esta asercion afirmaba lo CONTRARIO hasta el 7-sep-2026: que 2 MESES contaba
// desde la etapa aunque cambiara el mes. Se invirtio a pedido del usuario -- "si ya
// pasa al otro mes deberia contar como el ciclo del siguiente mes para que haya
// orden" -- y ahora los intentos de septiembre NO arrastran al ciclo de octubre.
test_same(0, $pEtapa['sinContestar'], 'los intentos de septiembre no cuentan en el ciclo de octubre');
test_same(3, $pEtapa['fueraDelCiclo'], 'quedan reportados como fuera del ciclo');
// 🔴 Invertida el 7-sep-2026: al cambiar el mes el ciclo se reabre SOLO, sin que el
// deal tenga que moverse de etapa. Antes hacia falta el cambio de etapa y un deal
// que se quedaba quieto (pago parcial, refi en revision) quedaba mudo para siempre.
test_same(true, cobranza_puede_llamar('C48:UC_LLUGGI', $pEtapa, ['MOVED_TIME'=>$entradaAbogado,'_ahora'=>$oct])['puede'],
    'al cambiar el mes el ciclo se reabre solo: el boton vuelve a estar disponible');

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

// ════════════════════════════════════════════════════════════════════════════
// LOS TRES ASUNTOS DE CONTESTADA (protocolo, textual: "1234, PROMESA DE PAGO
// o REFINANCIAMIENTO. Cualquier otra cosa = NO contesto")
// ════════════════════════════════════════════════════════════════════════════
test_same(true,  cobranza_es_contestada('1234 hablé con la clienta'), '1234 es contestada');
test_same(true,  cobranza_es_contestada('PROMESA DE PAGO'),           'PROMESA DE PAGO es contestada');
test_same(true,  cobranza_es_contestada('promesa de pago 15/09'),     'sin importar mayusculas');
test_same(true,  cobranza_es_contestada('REFINANCIAMIENTO'),          'REFINANCIAMIENTO es contestada');
test_same(false, cobranza_es_contestada('Llamada saliente Ana'),      'una saliente normal NO');
test_same(false, cobranza_es_contestada('no contesta'),               '"no contesta" NO');
test_same(false, cobranza_es_contestada(''),                          'vacio NO');

// una PROMESA DE PAGO reinicia la escalera igual que un 1234
$conPromesa = [
    fake_activity(1,'Llamada saliente Ana','2026-09-03T08:00:00-05:00'),
    fake_activity(2,'PROMESA DE PAGO','2026-09-03T08:30:00-05:00'),
];
$pp2 = cobranza_calcular_protocolo($conPromesa, null, null);
test_same(0, $pp2['sinContestar'], 'la PROMESA DE PAGO cierra la tanda');
test_same(1, $pp2['contactos'],    'y cuenta como contacto efectivo');

// ════════════════════════════════════════════════════════════════════════════
// EL PACTO: una contestada con DEADLINE a futuro = silencio
// ════════════════════════════════════════════════════════════════════════════
$hoyTs = strtotime('2026-09-03T12:00:00-05:00');

// el caso real: 1234 con deadline el 9 -> pacto vivo
$pactada = [ fake_activity(10,'1234','2026-09-03T11:50:00-05:00')
             + ['DEADLINE'=>'2026-09-09T12:15:00-05:00'] ];
$pac = cobranza_pacto_vigente($pactada, $hoyTs);
test_same('2026-09-09T12:15:00-05:00', $pac['fecha'], 'un 1234 con deadline futuro es pacto');
test_same(false, cobranza_puede_llamar('C48:UC_LLUGGI', ['sinContestar'=>0], ['_pacto'=>$pac])['puede'],
    'con pacto vivo NO se llama');
test_same('pacto_vigente', cobranza_puede_llamar('C48:UC_LLUGGI', ['sinContestar'=>0], ['_pacto'=>$pac])['motivo'],
    'y el motivo lo dice');

// el pacto gana AL TOPE: aunque queden intentos, no se llama
test_same('pacto_vigente', cobranza_puede_llamar('C48:UC_LLUGGI', ['sinContestar'=>1], ['_pacto'=>$pac])['motivo'],
    'el pacto se revisa ANTES del tope');

// PROMESA DE PAGO y REFINANCIAMIENTO tambien pactan
foreach (['PROMESA DE PAGO','REFINANCIAMIENTO'] as $asunto) {
    $a = [ fake_activity(11,$asunto,'2026-09-03T11:00:00-05:00') + ['DEADLINE'=>'2026-09-20T10:00:00-05:00'] ];
    test_same('2026-09-20T10:00:00-05:00', cobranza_pacto_vigente($a,$hoyTs)['fecha'], "$asunto pacta fecha");
}

// deadline YA PASADO: no es pacto, se puede llamar (es justo el dia de llamar)
$vencida = [ fake_activity(12,'1234','2026-08-20T11:00:00-05:00')
             + ['DEADLINE'=>'2026-09-01T10:00:00-05:00'] ];
test_same(null, cobranza_pacto_vigente($vencida,$hoyTs), 'un pacto vencido ya no frena');
test_same(true, cobranza_puede_llamar('C48:UC_LLUGGI', ['sinContestar'=>0], ['_pacto'=>null])['puede'],
    'y sin pacto se puede llamar');

// una SALIENTE normal con deadline futuro (la que crea el propio boton) NO es pacto
$propia = [ fake_activity(13,'Llamada saliente Ana','2026-09-03T11:50:00-05:00')
            + ['DEADLINE'=>'2026-09-07T12:30:00-05:00'] ];
test_same(null, cobranza_pacto_vigente($propia,$hoyTs),
    'la planificada que crea el boton NO se confunde con un pacto');

// fecha absurda: el tope de seguridad la descarta
$absurda = [ fake_activity(14,'PROMESA DE PAGO','2026-09-03T11:00:00-05:00')
             + ['DEADLINE'=>'2030-01-01T10:00:00-05:00'] ];
test_same(null, cobranza_pacto_vigente($absurda,$hoyTs),
    'una fecha a 4 anos no mutea el deal para siempre');

// si hay dos pactos, gana el MAS LEJANO (el acuerdo mas reciente manda)
$dos = [
    fake_activity(15,'1234','2026-09-03T10:00:00-05:00') + ['DEADLINE'=>'2026-09-05T10:00:00-05:00'],
    fake_activity(16,'PROMESA DE PAGO','2026-09-03T11:00:00-05:00') + ['DEADLINE'=>'2026-09-12T10:00:00-05:00'],
];
test_same('2026-09-12T10:00:00-05:00', cobranza_pacto_vigente($dos,$hoyTs)['fecha'], 'gana el pacto mas lejano');

// ══════════════════════════════════════════════════════════════════════════
// 🔴 EL CASO REAL del deal 406519 (7-sep-2026). El usuario puso el pacto para
// HOY 11:20 y el boton NO lo dejo registrar: decia "pactado hasta 8 sep 03:59".
// Causa: el select del servicio no pedia DEADLINE, asi que se leia vacio y caia
// al respaldo END_TIME, que Bitrix pone al dia SIGUIENTE.
//   DEADLINE  2026-09-07T19:20:00+03:00 -> lun 7-sep 11:20 Ecuador  (lo que puso)
//   END_TIME  2026-09-08T11:59:00+03:00 -> mar 8-sep 03:59 Ecuador  (lo que usaba)
// ══════════════════════════════════════════════════════════════════════════
$ahora406519 = strtotime('2026-09-07T12:07:00-05:00');
$actReal = [['ID'=>2533165,'TYPE_ID'=>2,'DIRECTION'=>2,'SUBJECT'=>'1234','COMPLETED'=>'N',
             'DEADLINE'=>'2026-09-07T19:20:00+03:00','END_TIME'=>'2026-09-08T11:59:00+03:00']];
test_same(null, cobranza_pacto_vigente($actReal, $ahora406519),
    'el pacto vencio a las 11:20: a las 12:07 ya NO calla el boton');
// y con el mismo dato una hora ANTES si tiene que callar
test_same(true, cobranza_pacto_vigente($actReal, strtotime('2026-09-07T10:00:00-05:00')) !== null,
    'a las 10:00 el pacto todavia vale');
// sin DEADLINE se cae al END_TIME: por eso va en el select del servicio
$sinDl = $actReal; unset($sinDl[0]['DEADLINE']);
test_same(true, cobranza_pacto_vigente($sinDl, $ahora406519) !== null,
    'sin DEADLINE cae al END_TIME de mañana: por eso DEADLINE va en el select');

// ══════════════════════════════════════════════════════════════════════════
// EL CICLO SE REINICIA CON EL MES en todas las etapas de mora (7-sep-2026).
// El caso del usuario: el cliente pacta el 28, no cumple, y el intento del 3 del
// mes siguiente NO puede arrastrar los intentos del mes que ya cerro.
// ══════════════════════════════════════════════════════════════════════════
$oct3 = new DateTimeImmutable('2026-10-03T10:00:00-05:00');
// entro a 1 MES VENCIDO el 10 de septiembre; hoy es 3 de octubre
$ini = cobranza_inicio_ciclo('C48:UC_1WHC5Q', '2026-09-10T14:00:00+03:00', $oct3);
test_same('2026-10-01', substr((string)$ini, 0, 10),
    'entro en septiembre y ya es octubre: el ciclo cuenta desde el 1-oct');
// pero si entro a la etapa DENTRO de este mes, manda la entrada
$ini2 = cobranza_inicio_ciclo('C48:UC_LLUGGI', '2026-10-02T14:00:00+03:00', $oct3);
test_same('2026-10-02', substr((string)$ini2, 0, 10),
    'entro el 2-oct: cuenta desde que entro, no desde el 1');
// y los intentos del mes cerrado quedan FUERA de la cuenta
$viejos = [
    fake_activity(1,'Llamada saliente Ana','2026-09-11T09:00:00-05:00') + ['COMPLETED'=>'Y'],
    fake_activity(2,'Llamada saliente Ana','2026-09-15T09:00:00-05:00') + ['COMPLETED'=>'Y'],
    fake_activity(3,'Llamada saliente Ana','2026-09-18T09:00:00-05:00') + ['COMPLETED'=>'Y'],
];
$pv = cobranza_calcular_protocolo($viejos, null, $ini);
test_same(0, $pv['sinContestar'], 'los 3 intentos de septiembre no cuentan en el ciclo de octubre');
test_same(3, $pv['fueraDelCiclo'], 'quedan contados como fuera del ciclo');
// con el ciclo viejo (desde la entrada) habrian bloqueado el boton
$pvViejo = cobranza_calcular_protocolo($viejos, null, '2026-09-10T14:00:00+03:00');
test_same(3, $pvViejo['sinContestar'], 'y con el criterio anterior si bloqueaban: el tope de 3 estaba agotado');
