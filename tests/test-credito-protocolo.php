<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../lib/credito-protocolo.php';

function ll(array $x = []): array {
    return array_merge(['ID'=>1,'TYPE_ID'=>2,'DIRECTION'=>2,'SUBJECT'=>'Llamada saliente Juan',
                        'CREATED'=>'2026-09-01T10:00:00-05:00','COMPLETED'=>'Y','DEADLINE'=>''], $x);
}

// ── los regimenes, con las etapas REALES del 79 leidas de Bitrix el 5-sep-2026 ──
test_same('puerta',  credito_regimen('C79:NEW'),               'X GESTIONAR es la puerta');
test_same('gestion', credito_regimen('C79:PREPAYMENT_INVOIC'), 'INDECISO es gestion');
test_same('gestion', credito_regimen('C79:UC_M21PX3'),         'NO CONTESTA es gestion, no un basurero');
test_same('gestion', credito_regimen('C79:EXECUTING'),         'RECHAZADO es gestion: hay que buscarle otra via');
test_same('proceso', credito_regimen('C79:PREPARATION'),       'ANALISIS es proceso');
test_same('proceso', credito_regimen('C79:UC_NGYPXQ'),         'DE CONTADO es proceso');
test_same('proceso', credito_regimen('C79:FINAL_INVOICE'),     'BANCO APROBADO es proceso');
test_same('salida',  credito_regimen('C79:UC_XJ71GA'),         'NO PUEDE PAGARLO es salida');
test_same('fuera',   credito_regimen('C79:UC_TA9OB1'),         'CEDIO DERECHOS queda fuera');
test_same('fuera',   credito_regimen('C79:WON'),               'PAGADO: el deal ya salio');
test_same('fuera',   credito_regimen('C79:LOSE'),              'DADO DE BAJA: el deal ya salio');
// una etapa del 79 que no conocemos NO puede dejar el deal mudo
test_same('gestion', credito_regimen('C79:UC_GUJ97G'),         'CREDITO DIRECTO no esta en el protocolo: cae en gestion');
test_same('gestion', credito_regimen('C79:UC_INVENTADA'),      'una etapa nueva tampoco deja el deal sin gestion');
test_same('',        credito_regimen('C48:UC_1WHC5Q'),         'un deal de cobranzas no es de este boton');
test_same('',        credito_regimen('C28:NEW'),               'ni uno de ventas');

// ── que cuenta como contestada ──
test_same(true,  credito_es_contestada('1234 hablamos con el cliente'), '1234');
test_same(true,  credito_es_contestada('FECHA DE PAGO 20/09'),          'FECHA DE PAGO: el objetivo del credito');
test_same(true,  credito_es_contestada('fecha de pago'),                'en minusculas tambien');
test_same(true,  credito_es_contestada('PROMESA DE PAGO'),              'promesa de pago');
test_same(true,  credito_es_contestada('Refinanciamiento'),             'refinanciamiento');
test_same(false, credito_es_contestada('no contesta'),                  'no contesta');
test_same(false, credito_es_contestada('Llamada saliente Juan'),        'sin marcar no es contestada');
test_same(false, credito_es_contestada(''),                             'vacio no es contestada');

// ── el contador usa la regla de CREDITO, no la de cobranzas ──
$acts = [
    ['ID'=>1,'TYPE_ID'=>2,'DIRECTION'=>2,'SUBJECT'=>'Llamada saliente Ana','CREATED'=>'2026-09-01T10:00:00-05:00','COMPLETED'=>'Y'],
    ['ID'=>2,'TYPE_ID'=>2,'DIRECTION'=>2,'SUBJECT'=>'FECHA DE PAGO 20/09','CREATED'=>'2026-09-02T10:00:00-05:00','COMPLETED'=>'Y'],
    ['ID'=>3,'TYPE_ID'=>2,'DIRECTION'=>2,'SUBJECT'=>'Llamada saliente Ana','CREATED'=>'2026-09-03T10:00:00-05:00','COMPLETED'=>'Y'],
];
$p = credito_calcular_protocolo($acts);
test_same(1, $p['contactos'],    'FECHA DE PAGO cuenta como contacto efectivo');
test_same(1, $p['sinContestar'], 'y reinicia la cuenta: queda 1 intento posterior');
// la misma lista con la regla de COBRANZAS daria otra cosa: ahi FECHA DE PAGO no vale
$pc = cobranza_calcular_protocolo($acts, null, null);
test_same(0, $pc['contactos'],   'con la regla de cobranzas, FECHA DE PAGO NO es contestada');
test_same(3, $pc['sinContestar'],'y los 3 cuentan como fallidos: por eso credito necesita su regla');

// ── SIN TECHO: es la diferencia de fondo con cobranzas ──
$deal = [];
$muchos = ['sinContestar' => 50, 'contactos' => 0];
$ok = credito_puede_llamar('C79:PREPARATION', $muchos, $deal);
test_same(true, $ok['puede'],     '50 intentos y sigue pudiendo: en credito NO hay techo');
test_same(-1,   $ok['restantes'], 'restantes -1 = sin techo, que no es lo mismo que cero');
$ok2 = credito_puede_llamar('C79:NEW', ['sinContestar'=>0], $deal);
test_same(true, $ok2['puede'], 'la puerta tambien deja llamar');
$fuera = credito_puede_llamar('C48:UC_1WHC5Q', ['sinContestar'=>0], $deal);
test_same('otro_embudo', $fuera['motivo'], 'un deal de cobranzas se rechaza con su motivo');
$cerrado = credito_puede_llamar('C79:WON', ['sinContestar'=>0], $deal);
test_same('deal_cerrado', $cerrado['motivo'], 'un deal pagado no se llama');

// ── el PACTO es lo unico que lo calla ──
$ahora = strtotime('2026-09-05T10:00:00-05:00');
$conPacto = [['ID'=>9,'TYPE_ID'=>2,'DIRECTION'=>2,'SUBJECT'=>'1234 quedamos','DEADLINE'=>'2026-09-20T10:00:00-05:00']];
$pact = credito_pacto_vigente($conPacto, $ahora);
test_same(true, $pact !== null, 'un 1234 con fecha futura es un pacto vivo');
$bloq = credito_puede_llamar('C79:UC_NGYPXQ', ['sinContestar'=>0], ['_pacto'=>$pact]);
test_same(false, $bloq['puede'],           'con pacto vivo el boton se calla');
test_same('pacto_vigente', $bloq['motivo'],'y dice por que');
$vencido = [['ID'=>9,'TYPE_ID'=>2,'DIRECTION'=>2,'SUBJECT'=>'1234','DEADLINE'=>'2026-09-01T10:00:00-05:00']];
test_same(null, credito_pacto_vigente($vencido, $ahora), 'un pacto vencido ya no calla');
$absurdo = [['ID'=>9,'TYPE_ID'=>2,'DIRECTION'=>2,'SUBJECT'=>'1234','DEADLINE'=>'2030-01-01T10:00:00-05:00']];
test_same(null, credito_pacto_vigente($absurdo, $ahora), 'una fecha a 4 años no muta el deal para siempre');
$noContestada = [['ID'=>9,'TYPE_ID'=>2,'DIRECTION'=>2,'SUBJECT'=>'no contesta','DEADLINE'=>'2026-09-20T10:00:00-05:00']];
test_same(null, credito_pacto_vigente($noContestada, $ahora), 'una planificada sin contestada NO es un pacto');

// ── la cadencia: CUATRO situaciones, no una ──
$vie = new DateTimeImmutable('2026-09-04T09:00:00-05:00');   // viernes
$CON = ['hubo_fecha'=>true,  'meses_en_etapa'=>0];
$SIN = ['hubo_fecha'=>false, 'meses_en_etapa'=>0];
$p1 = credito_proximo_intento('proceso', ['sinContestar'=>0], $vie, $CON);
test_same('2026-09-07', $p1->format('Y-m-d'), 'PROCESO con fecha incumplida: al dia habil siguiente (salta el finde)');
$p2 = credito_proximo_intento('proceso', ['sinContestar'=>1], $vie, $CON);
test_same('2026-09-11', $p2->format('Y-m-d'), 'PROCESO, de ahi en adelante: 5 dias habiles');
// 🔴 sin fecha todavia NO es el ritmo de 5: el protocolo manda el intercalado
$p2b = credito_proximo_intento('proceso', ['sinContestar'=>3], $vie, $SIN);
test_same('2026-09-10', $p2b->format('Y-m-d'), 'PROCESO sin fecha aun: ritmo intercalado hasta conseguir la primera');
test_same(4, credito_cadencia('proceso', ['sinContestar'=>9], $SIN), 'y da igual cuantos intentos lleve: sigue intercalado');
test_same(1, credito_cadencia('proceso', ['sinContestar'=>0], $CON), 'con fecha incumplida y sin intentos: al dia siguiente');
test_same(5, credito_cadencia('proceso', ['sinContestar'=>2], $CON), 'con fecha incumplida y ya reintentando: 5');
$p3 = credito_proximo_intento('gestion', ['sinContestar'=>3], $vie, $SIN);
test_same('2026-09-10', $p3->format('Y-m-d'), 'GESTION: la llamada vuelve cada 4 dias habiles');
$p4 = credito_proximo_intento('puerta', ['sinContestar'=>0], $vie, $SIN);
test_same('2026-09-10', $p4->format('Y-m-d'), 'la puerta usa la cadencia de gestion');
// 🔴 MANTENIMIENTO: pasado el techo de 2 meses, una llamada al MES. No se apaga.
$VIEJO = ['hubo_fecha'=>false, 'meses_en_etapa'=>3];
test_same(20, credito_cadencia('gestion', ['sinContestar'=>8], $VIEJO), '3 meses en gestion: mantenimiento, 1 llamada al mes');
test_same(20, credito_cadencia('puerta',  ['sinContestar'=>1], $VIEJO), 'la puerta tambien baja a mantenimiento');
test_same(4,  credito_cadencia('gestion', ['sinContestar'=>8], ['hubo_fecha'=>false,'meses_en_etapa'=>1]), 'al mes todavia no: sigue el intercalado');
test_same(5,  credito_cadencia('proceso', ['sinContestar'=>2], ['hubo_fecha'=>true,'meses_en_etapa'=>9]), 'proceso NO baja a mantenimiento: ahi no se deja de cobrar nunca');

// ── el contexto sale de las actividades y del MOVED_TIME ──
$ahoraX = new DateTimeImmutable('2026-09-05T10:00:00-05:00');
$cx = credito_contexto([ll(['SUBJECT'=>'FECHA DE PAGO 20/08','DEADLINE'=>'2026-08-20T10:00:00-05:00'])], null, $ahoraX);
test_same(true, $cx['hubo_fecha'], 'una FECHA DE PAGO ya registrada cuenta, aunque haya vencido');
$cx2 = credito_contexto([ll(['SUBJECT'=>'1234 hablamos','DEADLINE'=>'2026-08-20T10:00:00-05:00'])], null, $ahoraX);
test_same(false, $cx2['hubo_fecha'], 'un 1234 no es una fecha de pago');
$cx3 = credito_contexto([ll(['SUBJECT'=>'FECHA DE PAGO','DEADLINE'=>''])], null, $ahoraX);
test_same(false, $cx3['hubo_fecha'], 'FECHA DE PAGO sin deadline no fija nada');
test_same(3, credito_contexto([], '2026-06-01T10:00:00+03:00', $ahoraX)['meses_en_etapa'], 'tres meses en la etapa');
test_same(0, credito_contexto([], '2026-09-01T10:00:00+03:00', $ahoraX)['meses_en_etapa'], 'cuatro dias son cero meses');
test_same(0, credito_contexto([], null, $ahoraX)['meses_en_etapa'], 'sin MOVED_TIME no se inventa antiguedad');
test_same(0, credito_contexto([], 'basura', $ahoraX)['meses_en_etapa'], 'una fecha ilegible tampoco');
// la hora depende del momento del dia, igual que en cobranzas
test_same('13:00', credito_proximo_intento('gestion', [], new DateTimeImmutable('2026-09-08T09:00:00-05:00'), [])->format('H:i'), 'llamo de mañana -> la proxima en la TARDE');
test_same('09:30', credito_proximo_intento('gestion', [], new DateTimeImmutable('2026-09-08T13:00:00-05:00'), [])->format('H:i'), 'llamo de tarde -> la proxima en la MAÑANA');
// 🔴 nunca a las 17:00 ni a las 19:00: eso era de la escalera de vendedores
foreach (['07:00','09:15','11:59','12:00','15:45','18:30'] as $hh) {
    $hp = credito_proximo_intento('gestion', [], new DateTimeImmutable("2026-09-08T$hh:00-05:00"), [])->format('H:i');
    test_same(true, in_array($hp, ['09:30','13:00'], true), "desde las $hh la proxima cae 09:30 o 13:00, nunca 17:00 (dio $hp)");
}

// ── nunca cae en fin de semana ni feriado ──
foreach (['2026-09-01','2026-09-02','2026-09-03','2026-09-04','2026-09-07','2026-09-08'] as $d) {
    foreach (['proceso','gestion'] as $reg) {
        $x = credito_proximo_intento($reg, ['sinContestar'=>0], new DateTimeImmutable($d.'T10:00:00-05:00'), ['hubo_fecha'=>true,'meses_en_etapa'=>0]);
        test_same(true, fer_es_habil($x), "el proximo intento de $reg desde $d cae en dia habil");
    }
}
echo "test-credito-protocolo OK\n";

// ── el pacto tiene que estar PLANIFICADO, no cerrado ──
// Protocolo: "COMPLETED = N (la conversacion pactada todavia no ocurrio)".
$fut = '2026-09-20T10:00:00-05:00';
$plan = [['ID'=>1,'TYPE_ID'=>2,'DIRECTION'=>2,'SUBJECT'=>'1234 quedamos','DEADLINE'=>$fut,'COMPLETED'=>'N']];
$hech = [['ID'=>1,'TYPE_ID'=>2,'DIRECTION'=>2,'SUBJECT'=>'1234 quedamos','DEADLINE'=>$fut,'COMPLETED'=>'Y']];
// 🔴 Las DOS callan al boton. El protocolo describe el pacto como COMPLETED=N,
// pero en el uso real la asesora marca la llamada como hecha y le pone el deadline
// de lo que quedaron -- asi salio en la prueba del 3-sep con el presidente, y lo
// que se esperaba era silencio. Exigir COMPLETED=N rompia ese caso.
test_same(true, credito_pacto_vigente($plan,$ahora) !== null, 'planificada + fecha futura = pacto');
test_same(true, credito_pacto_vigente($hech,$ahora) !== null, 'y una CERRADA con fecha futura tambien: es como se registra de verdad');
// y asi el boton y el campo ESTADO EN PAUSA dicen lo mismo
echo "test-credito-protocolo (pacto planificado) OK\n";

// ══════════════════════════════════════════════════════════════════════════
// 🔴 LA ARITMETICA DEL TECHO. Esta prueba existe para que nadie vuelva a poner
// la cadencia en 4 dias: el documento lo dice en un lugar, pero con 4 las 8
// llamadas se agotan DOS SEMANAS antes de los 2 meses de techo.
// ══════════════════════════════════════════════════════════════════════════
$inicio = new DateTimeImmutable('2026-09-07T09:00:00-05:00');   // lunes
// dias habiles reales que tiene la ventana de 2 meses
$fin = $inicio->modify('+2 months');
$habiles = 0; $d = $inicio;
while ($d < $fin) { $d = $d->modify('+1 day'); if (fer_es_habil($d)) $habiles++; }
test_same(true, $habiles >= 38 && $habiles <= 46, "2 meses son ~42 dias habiles (dieron $habiles)");

// las 8 llamadas del techo, una tras otra con la cadencia de gestion
$paso = credito_cadencia('gestion', ['sinContestar'=>1], ['hubo_fecha'=>false,'meses_en_etapa'=>0]);
$at = $inicio; $habilesGastados = 0;
for ($i = 1; $i < 8; $i++) {           // de la 1a a la 8a hay 7 saltos
    $prox = credito_sumar_habiles($at, $paso);
    $c = $at;
    while ($c < $prox) { $c = $c->modify('+1 day'); if (fer_es_habil($c)) $habilesGastados++; }
    $at = $prox;
}
test_same(true, $habilesGastados <= $habiles,
    "las 8 llamadas entran en los 2 meses (gastan $habilesGastados de $habiles)");
// 🔴 Esta asercion decia que las 8 llamadas NO podian agotarse mucho antes del
// techo. Se quito a proposito el 7-sep-2026: el usuario eligio paso 4 sabiendo que
// se agotan ~2 semanas antes, porque en credito NO hay techo de llamadas -- se
// sigue llamando igual -- y la presion real la ponen los mensajes automaticos.
// Lo que si se sigue exigiendo es que ENTREN en la ventana, que es lo que romperia
// el protocolo (llamar mas alla del techo de la etapa sin haberla cambiado).
test_same(true, $habilesGastados < $habiles,
    "las 8 llamadas entran holgadas en los 2 meses (gastan $habilesGastados de $habiles)");
echo "test-credito-protocolo (aritmetica del techo) OK\n";
