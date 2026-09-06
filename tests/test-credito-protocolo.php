<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../lib/credito-protocolo.php';

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

// ── la cadencia: dos velocidades ──
$vie = new DateTimeImmutable('2026-09-04T09:00:00-05:00');   // viernes
$p1 = credito_proximo_intento('proceso', ['sinContestar'=>0], $vie);
test_same('2026-09-07', $p1->format('Y-m-d'), 'PROCESO, primer intento tras el pacto: al dia habil siguiente (salta el finde)');
$p2 = credito_proximo_intento('proceso', ['sinContestar'=>1], $vie);
test_same('2026-09-11', $p2->format('Y-m-d'), 'PROCESO, de ahi en adelante: 5 dias habiles');
$p3 = credito_proximo_intento('gestion', ['sinContestar'=>3], $vie);
test_same('2026-09-10', $p3->format('Y-m-d'), 'GESTION: la llamada vuelve cada 4 dias habiles');
$p4 = credito_proximo_intento('puerta', ['sinContestar'=>0], $vie);
test_same('2026-09-10', $p4->format('Y-m-d'), 'la puerta usa la cadencia de gestion');
// la hora depende del momento del dia, igual que en cobranzas
test_same('12:30', credito_proximo_intento('gestion', [], new DateTimeImmutable('2026-09-08T09:00:00-05:00'))->format('H:i'), 'de mañana temprano -> 12:30');
test_same('09:30', credito_proximo_intento('gestion', [], new DateTimeImmutable('2026-09-08T20:00:00-05:00'))->format('H:i'), 'de noche -> 09:30 del dia siguiente habil');

// ── nunca cae en fin de semana ni feriado ──
foreach (['2026-09-01','2026-09-02','2026-09-03','2026-09-04','2026-09-07','2026-09-08'] as $d) {
    foreach (['proceso','gestion'] as $reg) {
        $x = credito_proximo_intento($reg, ['sinContestar'=>0], new DateTimeImmutable($d.'T10:00:00-05:00'));
        test_same(true, fer_es_habil($x), "el proximo intento de $reg desde $d cae en dia habil");
    }
}
echo "test-credito-protocolo OK\n";
