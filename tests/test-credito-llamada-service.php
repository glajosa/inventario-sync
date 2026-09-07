<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../lib/credito-llamada-service.php';

/** $bx falso que anota cada llamada, para poder contarlas. */
function cre_fake_bx(array $deal, array $acts, array &$log): callable {
    return function (string $m, array $p = []) use ($deal, $acts, &$log) {
        $log[] = ['m' => $m, 'p' => $p];
        return match ($m) {
            'crm.deal.get'      => ['result' => $deal],
            'crm.activity.list' => ['result' => $acts],
            'crm.activity.add'  => ['result' => 7001],
            default             => ['result' => true],
        };
    };
}
function cre_act(int $id, string $subject, string $created, string $completed = 'Y', string $deadline = ''): array {
    $a = ['ID'=>$id,'TYPE_ID'=>2,'DIRECTION'=>2,'SUBJECT'=>$subject,
          'CREATED'=>$created,'COMPLETED'=>$completed,'ORIGIN_ID'=>''];
    if ($deadline !== '') $a['DEADLINE'] = $deadline;
    return $a;
}
$ahora = new DateTimeImmutable('2026-09-03T09:00:00-05:00');   // jueves

// ── pulsacion limpia en DE CONTADO (regimen PROCESO) ──
$log = [];
$bx = cre_fake_bx(['ID'=>500,'STAGE_ID'=>'C79:UC_NGYPXQ'], [], $log);
$r = credito_no_contesto(['dealId'=>500,'bitrixUserId'=>42,'contactName'=>'Marco'], $bx, $ahora);
test_same('procesado', $r['status'],  'pulsacion valida en DE CONTADO');
test_same('proceso',   $r['regimen'], 'regimen de proceso');
test_same(1,  $r['intentos'],  'primer intento');
test_same(-1, $r['restantes'], 'sin techo: -1, que NO es cero');
// 🔴 Este deal NUNCA tuvo fecha de pago, asi que NO le toca el "+1 dia": el
// protocolo manda el ritmo intercalado "hasta conseguir la primera".
test_same(true, $r['sinFechaAun'], 'todavia no hay fecha de pago');
test_same(5, $r['cadencia'], 'sin fecha: ritmo intercalado, 5 dias habiles');
test_same('2026-09-10', substr($r['proximoIntento'],0,10), 'y cae 5 dias habiles despues');

// 🔴 la prueba que evita el conteo doble
test_same(1, count(array_filter($log, fn($c)=>$c['m']==='crm.activity.add')), 'UNA pulsacion crea UNA actividad');
// 🔴 y este boton NO escribe campos del deal
test_same(0, count(array_filter($log, fn($c)=>$c['m']==='crm.deal.update')), 'el boton de credito NO toca ningun campo del deal');

// ── la actividad nueva queda AGENDADA con deadline: el protocolo lo exige ──
$add = null; foreach ($log as $c) if ($c['m']==='crm.activity.add') $add = $c['p']['fields'];
test_same('N', $add['COMPLETED'], 'la nueva nace planificada, no completada');
test_same(true, !empty($add['DEADLINE']), 'y con DEADLINE: un deal de proceso sin llamada agendada es un deal soltado');
test_same('VOXIMPLANT_CALL', $add['PROVIDER_ID'], 'se registra como llamada, que es lo que cuentan los tableros');
test_same('Llamada saliente Marco', $add['SUBJECT'], 'el nombre real del contacto en el asunto');

// ── PROCESO con una fecha de pago que ya se incumplio ──
// Primer intento despues del pacto roto: al dia habil siguiente.
$log = [];
$conFechaRota = [cre_act(1,'FECHA DE PAGO 28/08','2026-08-20T09:00:00-05:00','Y','2026-08-28T10:00:00-05:00')];
$bx = cre_fake_bx(['ID'=>500,'STAGE_ID'=>'C79:UC_NGYPXQ'], $conFechaRota, $log);
$r = credito_no_contesto(['dealId'=>500,'bitrixUserId'=>42], $bx, $ahora);
test_same(false, $r['sinFechaAun'], 'aca SI hubo fecha de pago');
test_same(1, $r['cadencia'], 'primer intento tras la fecha incumplida: al dia siguiente');
test_same('2026-09-04', substr($r['proximoIntento'],0,10), 'y cae el dia habil siguiente');

// Ya reintentando: de ahi en adelante, cada 5 dias habiles.
$log = [];
$yaReintenta = [
    cre_act(1,'FECHA DE PAGO 28/08','2026-08-20T09:00:00-05:00','Y','2026-08-28T10:00:00-05:00'),
    cre_act(2,'Llamada saliente Marco','2026-08-31T09:00:00-05:00'),
];
$bx = cre_fake_bx(['ID'=>500,'STAGE_ID'=>'C79:UC_NGYPXQ'], $yaReintenta, $log);
$r = credito_no_contesto(['dealId'=>500,'bitrixUserId'=>42], $bx, $ahora);
test_same(2, $r['intentos'], 'segundo intento');
test_same(5, $r['cadencia'], 'de ahi en adelante, cada 5 dias habiles');
test_same('2026-09-10', substr($r['proximoIntento'],0,10), 'y cae 5 habiles despues');

// ── MANTENIMIENTO: 3 meses en una etapa de gestion -> una llamada al mes ──
$log = [];
$bx = cre_fake_bx(['ID'=>500,'STAGE_ID'=>'C79:PREPAYMENT_INVOIC','MOVED_TIME'=>'2026-06-01T10:00:00+03:00'], [], $log);
$r = credito_no_contesto(['dealId'=>500,'bitrixUserId'=>42], $bx, $ahora);
test_same(true, $r['mantenimiento'], 'pasado el techo de 2 meses baja a mantenimiento');
test_same(20, $r['cadencia'], 'una llamada al mes, pero NO se apaga');
// y en la misma etapa recien entrado, sigue el intercalado
$log = [];
$bx = cre_fake_bx(['ID'=>500,'STAGE_ID'=>'C79:PREPAYMENT_INVOIC','MOVED_TIME'=>'2026-08-28T10:00:00+03:00'], [], $log);
$r = credito_no_contesto(['dealId'=>500,'bitrixUserId'=>42], $bx, $ahora);
test_same(false, $r['mantenimiento'], 'recien entrado NO es mantenimiento');
test_same(5, $r['cadencia'], 'sigue el intercalado');

// ── SIN TECHO: 12 intentos y sigue dejando ──
$log = [];
$muchos = [];
for ($i=1; $i<=12; $i++) $muchos[] = cre_act($i,'Llamada saliente Marco', sprintf('2026-07-%02dT09:00:00-05:00', $i));
$bx = cre_fake_bx(['ID'=>500,'STAGE_ID'=>'C79:PREPAYMENT_INVOIC'], $muchos, $log);
$r = credito_no_contesto(['dealId'=>500,'bitrixUserId'=>42], $bx, $ahora);
test_same('procesado', $r['status'], '12 intentos y sigue: en credito NO hay techo');
test_same(13, $r['intentos'], 'cuenta el intento 13');
test_same('2026-09-10', substr($r['proximoIntento'],0,10), 'gestion recien entrada: cada 5 dias habiles');

// ── el PACTO lo calla ──
$log = [];
$conPacto = [cre_act(9,'1234 quedamos el 20','2026-09-02T09:00:00-05:00','Y','2026-09-20T10:00:00-05:00')];
$bx = cre_fake_bx(['ID'=>500,'STAGE_ID'=>'C79:FINAL_INVOICE'], $conPacto, $log);
$r = credito_no_contesto(['dealId'=>500,'bitrixUserId'=>42], $bx, $ahora);
test_same('rechazado', $r['status'], 'con pacto vivo no se llama');
test_same('pacto_vigente', $r['motivo'], 'y se dice por que');
test_same(0, count(array_filter($log, fn($c)=>$c['m']==='crm.activity.add')), 'y NO se crea nada');

// ── FECHA DE PAGO reinicia la escalera (la regla propia de credito) ──
$log = [];
$conFecha = [
    cre_act(1,'Llamada saliente Marco','2026-08-01T09:00:00-05:00'),
    cre_act(2,'Llamada saliente Marco','2026-08-05T09:00:00-05:00'),
    cre_act(3,'FECHA DE PAGO 30/08','2026-08-10T09:00:00-05:00','Y','2026-08-30T10:00:00-05:00'),
];
$bx = cre_fake_bx(['ID'=>500,'STAGE_ID'=>'C79:UC_NGYPXQ'], $conFecha, $log);
$r = credito_no_contesto(['dealId'=>500,'bitrixUserId'=>42], $bx, $ahora);
test_same(1, $r['intentos'], 'tras una FECHA DE PAGO la escalera arranca de cero');
test_same(false, $r['sinFechaAun'], 'y ya hubo fecha, asi que rige la cadencia de proceso');
test_same('2026-09-04', substr($r['proximoIntento'],0,10), 'al dia habil siguiente');

// 🔴 pero una FECHA DE PAGO SIN deadline no fija ninguna fecha: no hay dia que poner
$log = [];
$sinDl = [cre_act(1,'FECHA DE PAGO pero sin fecha','2026-08-10T09:00:00-05:00')];
$bx = cre_fake_bx(['ID'=>500,'STAGE_ID'=>'C79:UC_NGYPXQ'], $sinDl, $log);
$r = credito_no_contesto(['dealId'=>500,'bitrixUserId'=>42], $bx, $ahora);
test_same(true, $r['sinFechaAun'], 'sin deadline sigue sin haber fecha de pago');
test_same(5, $r['cadencia'], 'asi que manda el intercalado');

// ── un deal de cobranzas no es de este boton ──
$log = [];
$bx = cre_fake_bx(['ID'=>77,'STAGE_ID'=>'C48:UC_LLUGGI'], [], $log);
$r = credito_no_contesto(['dealId'=>77,'bitrixUserId'=>42], $bx, $ahora);
test_same('rechazado', $r['status'], 'un deal de cobranzas se rechaza');
test_same('otro_embudo', $r['motivo'], 'con su motivo, no uno generico');

// ── doble clic: no duplica ──
$log = [];
$recien = [cre_act(50,'Llamada saliente Marco','2026-09-03T08:56:00-05:00','N')];
$bx = cre_fake_bx(['ID'=>500,'STAGE_ID'=>'C79:UC_NGYPXQ'], $recien, $log);
$r = credito_no_contesto(['dealId'=>500,'bitrixUserId'=>42], $bx, $ahora);
test_same('ya_registrado', $r['status'], 'dos pulsaciones seguidas no cuentan dos veces');
test_same(0, count(array_filter($log, fn($c)=>$c['m']==='crm.activity.add')), 'y no crea nada');

// ── pero si la asesora YA cerro esa planificada, la siguiente ES un intento nuevo ──
$log = [];
$cerrada = [cre_act(50,'Llamada saliente Marco','2026-09-03T08:56:00-05:00','Y')];
$bx = cre_fake_bx(['ID'=>500,'STAGE_ID'=>'C79:UC_NGYPXQ'], $cerrada, $log);
$r = credito_no_contesto(['dealId'=>500,'bitrixUserId'=>42], $bx, $ahora);
test_same('procesado', $r['status'], 'completar la llamada libera el boton al instante');

// ── un fallo de Bitrix NO puede parecerse a "sin actividades" ──
$caido = function (string $m, array $p = []) {
    if ($m === 'crm.activity.list') return ['ok'=>false,'error'=>'QUERY_LIMIT_EXCEEDED'];
    return ['result' => ['ID'=>500,'STAGE_ID'=>'C79:UC_NGYPXQ']];
};
$exploto = false;
try { credito_no_contesto(['dealId'=>500,'bitrixUserId'=>42], $caido, $ahora); }
catch (CreditoLlamadaError $e) { $exploto = ($e->getMessage() === 'bitrix_unavailable'); }
test_same(true, $exploto, 'si Bitrix se cae, se corta: no se llama encima de un pacto invisible');

echo "test-credito-llamada-service OK\n";
