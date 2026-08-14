<?php
require __DIR__.'/appauth.php';
header('Content-Type: text/plain; charset=utf-8');
$esperado = (string)getenv('OUTBOUND_TOKEN');
if ($esperado === '' || !hash_equals($esperado, (string)($_GET['token'] ?? ''))) { http_response_code(403); exit('forbidden'); }
$r = app_bx('events');
$ev = $r['result'] ?? [];
printf("%d eventos disponibles\n\n", count($ev));
$hits = [];
foreach ($ev as $e) {
    $n = is_array($e) ? ($e['event'] ?? json_encode($e)) : (string)$e;
    if (preg_match('/DEADLINE|EXPIR|OVERDUE|TIMER|SCHEDUL|REMIND|ACTIVITY/i', $n)) $hits[] = $n;
}
echo "relacionados con plazos o actividades:\n";
foreach ($hits as $h) echo "  $h\n";
if (isset($r['error'])) echo "\nerror: {$r['error']} {$r['error_description']}\n";
