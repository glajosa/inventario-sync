<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/bot-quote-http.php';

$now = 1787640600;
$dir = sys_get_temp_dir().'/bot-quotes-'.bin2hex(random_bytes(5));
mkdir($dir, 0700, true);
$catalog = ['built'=>$now-20,'units'=>[
    ['id'=>1,'codigo'=>'A-1-1','cat'=>'39','stage'=>'DISPONIBLE','dealId'=>0,'m2'=>'75','pvp'=>'135000|USD','tipo'=>1793,'torre'=>'A','piso'=>'1'],
]];
file_put_contents($dir.'/selector_cache.json', json_encode($catalog, JSON_THROW_ON_ERROR));
$env = [
    'BOT_QUOTE_API_ENABLED'=>'1',
    'BOT_QUOTE_SHARED_SECRET'=>'quote-service-test-secret-at-least-32-bytes',
    'DATA_DIR'=>$dir,
    'PUBLIC_BASE_URL'=>'https://inventario.example.test',
    'OUTBOUND_TOKEN'=>'quote-outbound-test-secret',
];
$headers = function(string $body) use ($now, $env): array {
    $nonce = '11111111-1111-4111-8111-'.substr(bin2hex(random_bytes(6)), 0, 12);
    return ['content-type'=>'application/json','x-galjosa-timestamp'=>(string)$now,
        'x-galjosa-nonce'=>$nonce,
        'x-galjosa-signature'=>hash_hmac('sha256',$now."\n".$nonce."\n".$body,$env['BOT_QUOTE_SHARED_SECRET'])];
};
$fake = fn(string $method,array $params): array => ['ok'=>true,'result'=>['item'=>[
    'id'=>1,'title'=>'A-1-1','categoryId'=>39,'stageId'=>'DT1072_39:PREPARATION','parentId2'=>0,
    'ufCrm25_1782615822688'=>'75','ufCrm25_1784563253861'=>'135000|USD','ufCrm25_1782616418179'=>1793,
]]];
$input = ['request_id'=>'22222222-2222-4222-8222-222222222222','project'=>'Noral Apartments',
    'unit_code'=>'A-1-1','deal_id'=>10,'payment'=>['installments'=>44,'modality'=>'estandar','start_month'=>'2026-09']];
$body = json_encode($input, JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
test_same(401, bot_quote_preview_http('POST',$body,['content-type'=>'application/json'],$env,$fake,$now)['status'], 'unsigned preview rejected');
$previewHeaders = $headers($body);
$preview = bot_quote_preview_http('POST',$body,$previewHeaders,$env,$fake,$now);
test_same(200, $preview['status'], 'signed preview succeeds');
test_same('preview', $preview['body']['status'], 'preview is not final document');
test_same(401, bot_quote_preview_http('POST',$body,$previewHeaders,$env,$fake,$now)['status'], 'replayed nonce is rejected');

$finalBody = json_encode(['quote_id'=>$preview['body']['quote_id']], JSON_THROW_ON_ERROR);
$final = bot_quote_finalize_http('POST',$finalBody,$headers($finalBody),$env,$fake,$now);
test_same(200, $final['status'], 'finalize succeeds after revalidation');
test_same('finalized', $final['body']['status'], 'document is frozen only on finalize');
test_same('text/html', $final['body']['document']['mime_type'], 'existing print-ready cotizador is reused');
$status = bot_quote_status_http('POST',$finalBody,$headers($finalBody),$env,$now);
test_same(200, $status['status'], 'quote status is queryable');
test_same($final['body']['document']['fingerprint'], $status['body']['document']['fingerprint'], 'status returns frozen document');
$again = bot_quote_finalize_http('POST',$finalBody,$headers($finalBody),$env,$fake,$now);
test_same($final['body']['document'], $again['body']['document'], 'finalize is idempotent');

foreach (glob($dir.'/bot_quote_previews/*') ?: [] as $file) @unlink($file);
foreach (glob($dir.'/bot_quote_nonces/*') ?: [] as $file) @unlink($file);
@rmdir($dir.'/bot_quote_nonces'); @rmdir($dir.'/bot_quote_previews'); @unlink($dir.'/selector_cache.json'); @rmdir($dir);
