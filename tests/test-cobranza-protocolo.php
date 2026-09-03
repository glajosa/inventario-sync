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
test_same('2026-09-01 00:00:00', cobranza_inicio_ciclo('C48:FINAL_INVOICE', '2026-03-02 08:00:00', $ahora),
    'ABOGADO cuenta por mes, no desde que entro a la etapa');
test_same('2026-03-02 08:00:00', cobranza_inicio_ciclo('C48:UC_LLUGGI', '2026-03-02 08:00:00', $ahora),
    'el resto cuenta desde que entro a la etapa');

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
