<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../lib/cobranza-llamada-service.php';

/** $bx falso que anota cada llamada, para poder contarlas. */
function cob_fake_bx(array $deal, array $acts, array &$log): callable {
    return function (string $m, array $p = []) use ($deal, $acts, &$log) {
        $log[] = ['m' => $m, 'p' => $p];
        return match ($m) {
            'crm.deal.get'       => ['result' => $deal],
            'crm.activity.list'  => ['result' => $acts],
            'crm.activity.add'   => ['result' => 9001],
            default              => ['result' => true],
        };
    };
}
$ahora = new DateTimeImmutable('2026-09-03T09:00:00-05:00');   // jueves

// ── una pulsacion limpia en 2 MESES VENCIDOS ──
$log = [];
$bx = cob_fake_bx(['ID'=>77,'STAGE_ID'=>'C48:UC_LLUGGI'], [], $log);
$r = cobranza_no_contesto(['dealId'=>77,'bitrixUserId'=>42,'contactName'=>'Ana'], $bx, $ahora);
test_same('procesado', $r['status'], 'pulsacion valida');
test_same(1, $r['intentos'], 'primer intento');
test_same(2, $r['restantes'], 'quedan 2 de 3');
test_same('2026-09-07', substr($r['proximoIntento'],0,10), '+2 habiles salta el finde');
test_same(2107, $r['estadoGestion'], 'NO CONTESTA');

// 🔴 la prueba que evita el conteo doble
$adds = count(array_filter($log, fn($c) => $c['m'] === 'crm.activity.add'));
test_same(1, $adds, 'UNA pulsacion crea UNA sola actividad');

// y NO se tocan los contadores del ciclo
$updates = array_filter($log, fn($c) => $c['m'] === 'crm.deal.update');
$campos = [];
foreach ($updates as $u) $campos = array_merge($campos, array_keys($u['p']['fields'] ?? []));
test_same(false, in_array('UF_CRM_CICLOS_EXIG', $campos, true), 'el boton NO toca CICLOS EXIGIBLES');
test_same(false, in_array('UF_CRM_CICLOS_CUMPL', $campos, true), 'el boton NO toca CICLOS CUMPLIDOS');
test_same(true,  in_array('UF_CRM_ESTADO_GESTION', $campos, true), 'si escribe ESTADO DE GESTION');

// ── cierra la planificada abierta antes de crear la nueva ──
$log = [];
$abierta = fake_activity(500, 'Llamada saliente Ana', '2026-09-01T09:00:00-05:00') + ['COMPLETED'=>'N'];
$bx = cob_fake_bx(['ID'=>77,'STAGE_ID'=>'C48:UC_LLUGGI'], [$abierta], $log);
$r = cobranza_no_contesto(['dealId'=>77,'bitrixUserId'=>42], $bx, $ahora);
test_same(500, $r['actividadCerrada'], 'cierra la planificada que estaba abierta');
test_same(1, count(array_filter($log, fn($c)=>$c['m']==='crm.activity.add')), 'sigue siendo UNA sola nueva');

// ── el tope de la etapa frena ──
$log = [];
$tres = [
    fake_activity(1,'Llamada saliente Ana','2026-08-20T09:00:00-05:00') + ['COMPLETED'=>'Y'],
    fake_activity(2,'Llamada saliente Ana','2026-08-24T09:00:00-05:00') + ['COMPLETED'=>'Y'],
    fake_activity(3,'Llamada saliente Ana','2026-08-26T09:00:00-05:00') + ['COMPLETED'=>'Y'],
];
$bx = cob_fake_bx(['ID'=>77,'STAGE_ID'=>'C48:UC_LLUGGI'], $tres, $log);
$r = cobranza_no_contesto(['dealId'=>77,'bitrixUserId'=>42], $bx, $ahora);
test_same('rechazado', $r['status'], '3 intentos en 2 MESES: se acabo');
test_same('tope_de_etapa', $r['motivo'], 'motivo claro');
test_same(0, count(array_filter($log, fn($c)=>$c['m']==='crm.activity.add')), 'rechazado NO escribe nada');

// ── MES CORRIENTE: el boton no se ofrece ──
$log = [];
$bx = cob_fake_bx(['ID'=>77,'STAGE_ID'=>'C48:UC_X35FSA'], [], $log);
$r = cobranza_no_contesto(['dealId'=>77,'bitrixUserId'=>42], $bx, $ahora);
test_same('etapa_sin_llamadas', $r['motivo'], 'MES CORRIENTE es 100% automatica');
test_same(0, count(array_filter($log, fn($c)=>$c['m']==='crm.activity.add')), 'no escribe nada');

// ── la pausa frena, pero solo con planificada a futuro ──
$log = [];
$futura = fake_activity(600,'Llamada saliente Ana','2026-09-02T09:00:00-05:00')
        + ['COMPLETED'=>'N','END_TIME'=>'2026-09-20T10:00:00-05:00'];
$bx = cob_fake_bx(['ID'=>77,'STAGE_ID'=>'C48:UC_LLUGGI','UF_CRM_ESTADO_PAUSA'=>'2119'], [$futura], $log);
$r = cobranza_no_contesto(['dealId'=>77,'bitrixUserId'=>42], $bx, $ahora);
test_same('en_pausa', $r['motivo'], 'pacto vigente: no se le insiste');

$log = [];   // misma pausa, SIN planificada futura -> no puede quedar mudo para siempre
$bx = cob_fake_bx(['ID'=>77,'STAGE_ID'=>'C48:UC_LLUGGI','UF_CRM_ESTADO_PAUSA'=>'2119'], [], $log);
$r = cobranza_no_contesto(['dealId'=>77,'bitrixUserId'=>42], $bx, $ahora);
test_same('procesado', $r['status'], 'pausa colgada sin planificada no bloquea');

// ── entradas invalidas ──
$log=[]; $bx = cob_fake_bx([], [], $log);
try { cobranza_no_contesto(['dealId'=>0,'bitrixUserId'=>42], $bx, $ahora); test_same(true,false,'deal 0 debio fallar'); }
catch (CobranzaLlamadaError $e) { test_same('invalid_request', $e->getMessage(), 'deal invalido'); }

// ── 🔴 Bitrix caido NO puede parecerse a "el deal no tiene llamadas" ──
// Sin esto, una crm.activity.list caida daba 0 intentos, la guardia del tope
// pasaba y se creaba la llamada saltandose el maximo de la etapa.
$log = [];
$bxCaido = function (string $m, array $p = []) use (&$log) {
    $log[] = ['m'=>$m,'p'=>$p];
    if ($m === 'crm.deal.get') return ['ok'=>true,'result'=>['ID'=>77,'STAGE_ID'=>'C48:UC_LLUGGI']];
    if ($m === 'crm.activity.list') return ['ok'=>false,'error'=>'QUERY_LIMIT_EXCEEDED'];
    return ['ok'=>true,'result'=>true];
};
try {
    cobranza_no_contesto(['dealId'=>77,'bitrixUserId'=>42], $bxCaido, $ahora);
    test_same(true, false, 'con Bitrix caido debio abortar, no seguir');
} catch (CobranzaLlamadaError $e) {
    test_same('bitrix_unavailable', $e->getMessage(), 'aborta con bitrix_unavailable');
}
test_same(0, count(array_filter($log, fn($c)=>$c['m']==='crm.activity.add')),
    'con Bitrix caido NO se crea ninguna actividad');

// el deal que no se puede leer tampoco pasa por bueno
$log = [];
$bxSinDeal = function (string $m, array $p = []) use (&$log) {
    $log[] = ['m'=>$m,'p'=>$p];
    return $m === 'crm.deal.get' ? ['ok'=>false,'error'=>'ACCESS_DENIED'] : ['ok'=>true,'result'=>true];
};
try {
    cobranza_no_contesto(['dealId'=>77,'bitrixUserId'=>42], $bxSinDeal, $ahora);
    test_same(true, false, 'deal ilegible debio abortar');
} catch (CobranzaLlamadaError $e) {
    test_same('bitrix_unavailable', $e->getMessage(), 'deal ilegible aborta');
}
test_same(0, count(array_filter($log, fn($c)=>$c['m']==='crm.activity.add')), 'y no escribe nada');

// ── doble pulsacion: avisa, no duplica ──
$log = [];
$reciente = fake_activity(700,'Llamada saliente Ana','2026-09-03T08:55:00-05:00') + ['COMPLETED'=>'N'];
$bx = cob_fake_bx(['ID'=>77,'STAGE_ID'=>'C48:UC_LLUGGI'], [$reciente], $log);
$r = cobranza_no_contesto(['dealId'=>77,'bitrixUserId'=>42], $bx, $ahora);   // ahora = 09:00
test_same('ya_registrado', $r['status'], 'la segunda pulsacion no duplica');
test_same(5, $r['haceMinutos'], 'dice hace cuanto fue');
test_same(0, count(array_filter($log, fn($c)=>$c['m']==='crm.activity.add')), 'y no escribe nada');

// pasada la ventana, si registra
$log = [];
$viejo = fake_activity(701,'Llamada saliente Ana','2026-09-03T08:30:00-05:00') + ['COMPLETED'=>'N'];
$bx = cob_fake_bx(['ID'=>77,'STAGE_ID'=>'C48:UC_LLUGGI'], [$viejo], $log);
$r = cobranza_no_contesto(['dealId'=>77,'bitrixUserId'=>42], $bx, $ahora);   // 30 min despues
test_same('procesado', $r['status'], 'pasados 30 min si es un intento nuevo');
test_same(2, $r['intentos'], 'y cuenta como el segundo');
