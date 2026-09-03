<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../api/llamadas/cobranza-no-contesto.php';

$now = (new DateTimeImmutable('2026-09-03T09:00:00-05:00'))->getTimestamp();
$env = ['BITRIX_DOMAIN' => 'galjosa.bitrix24.com'];
$quienSoy = fn(string $t): int => $t === 'buen-token' ? 42 : 0;
$log = [];
$bx = function (string $m, array $p = []) use (&$log) {
    $log[] = $m;
    return match ($m) {
        'crm.deal.get'      => ['ok'=>true,'result'=>['ID'=>77,'STAGE_ID'=>'C48:UC_LLUGGI']],
        'crm.activity.list' => ['ok'=>true,'result'=>[]],
        'crm.activity.add'  => ['ok'=>true,'result'=>9001],
        default             => ['ok'=>true,'result'=>true],
    };
};

// pulsacion valida
$r = cobranza_panel_http('POST', json_encode(['auth'=>'buen-token','dealId'=>77]), $env, $quienSoy, $bx, $now);
test_same(200, $r['status'], 'pulsacion valida devuelve 200');
test_same('procesado', $r['body']['status'], 'y la registra');
test_same('2026-09-07', substr($r['body']['proximoIntento'],0,10), 'con la proxima a +2 habiles');

// sin token -> 401, sin tocar Bitrix
$log = [];
$r = cobranza_panel_http('POST', json_encode(['auth'=>'malo','dealId'=>77]), $env, $quienSoy, $bx, $now);
test_same(401, $r['status'], 'token invalido: 401');
test_same(0, count(array_filter($log, fn($m)=>$m==='crm.activity.add')), 'y no escribe nada');

// GET no vale
test_same(400, cobranza_panel_http('GET','{}',$env,$quienSoy,$bx,$now)['status'], 'GET rechazado');
// cuerpo vacio
test_same(400, cobranza_panel_http('POST','',$env,$quienSoy,$bx,$now)['status'], 'cuerpo vacio rechazado');
// json roto
test_same(400, cobranza_panel_http('POST','{no json',$env,$quienSoy,$bx,$now)['status'], 'json roto rechazado');
// cuerpo gigante
test_same(400, cobranza_panel_http('POST',str_repeat('x',70000),$env,$quienSoy,$bx,$now)['status'], 'cuerpo gigante rechazado');

// Bitrix caido -> 503 y NADA escrito
$log = [];
$bxCaido = function (string $m, array $p = []) use (&$log) {
    $log[] = $m;
    if ($m === 'crm.deal.get') return ['ok'=>true,'result'=>['ID'=>77,'STAGE_ID'=>'C48:UC_LLUGGI']];
    if ($m === 'crm.activity.list') return ['ok'=>false,'error'=>'QUERY_LIMIT_EXCEEDED'];
    return ['ok'=>true,'result'=>true];
};
$r = cobranza_panel_http('POST', json_encode(['auth'=>'buen-token','dealId'=>77]), $env, $quienSoy, $bxCaido, $now);
test_same(503, $r['status'], 'Bitrix caido: 503');
test_same(0, count(array_filter($log, fn($m)=>$m==='crm.activity.add')), 'y no escribe nada');
