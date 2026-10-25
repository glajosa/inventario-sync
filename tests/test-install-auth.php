<?php
/**
 * Instalación / apertura de las apps locales (install.php, app.php, cobranza_app.php),
 * campo «Registrar llamada» (llamada_campo.php), host público (cot_base / base_url)
 * y el wrapper de cron warm-catalogo.php.
 *
 * Bitrix va SIEMPRE simulado (función inyectada o PL_FAKE_BITRIX en el runner) y los
 * dominios son `.invalid`. Pensado para correr con red apagada:
 *   podman run --rm --network none -v "$PWD":/app:ro,Z -w /app php:8.2-cli php tests/test-install-auth.php
 */
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../lib/placement-auth.php';

if (!class_exists('FakeBitrixInstall')) {
    final class FakeBitrixInstall {
        public array $calls = [];
        public function __construct(public mixed $respuesta) {}
        public function __invoke(string $dominio, string $metodo, array $params): ?array {
            $this->calls[] = [$dominio, $metodo, $params];
            return $this->respuesta;
        }
    }
}
function bx_inst(int $usuario, bool $admin, string $app = 'local.abc123', array $errores = []): array {
    return ['result' => ['result' => ['yo' => ['ID' => (string)$usuario], 'admin' => $admin, 'app' => ['CODE' => $app]],
                         'result_error' => $errores, 'result_total' => []]];
}

$SRV = ['REQUEST_METHOD' => 'POST'];
$REQ = ['AUTH_ID' => str_repeat('a1', 35), 'REFRESH_ID' => str_repeat('r2', 35),
        'member_id' => str_repeat('f', 32), 'DOMAIN' => 'galjosa.bitrix24.com'];
$CFG = ['dominios' => ['galjosa.bitrix24.com'], 'members' => [], 'app' => ''];

// ---------------------------------------------------------------- lib: camino bueno
$fx = new FakeBitrixInstall(bx_inst(7, true));
$r = pl_verificar_instalacion($SRV, $REQ + ['client_secret' => 'del-atacante'], $fx, $CFG);
test_same([true, true, 7, 'galjosa.bitrix24.com'], [$r['ok'], $r['admin'], $r['usuario'], $r['dominio']], 'instalación de un admin');
test_same(false, array_key_exists('client_secret', $r), 'el client_secret de la petición no viaja');
test_same(1, count($fx->calls), 'una sola llamada');
test_same(['galjosa.bitrix24.com', 'batch'], [$fx->calls[0][0], $fx->calls[0][1]], 'al dominio de la lista, por batch');
test_same(['yo' => 'user.current', 'admin' => 'user.admin', 'app' => 'app.info'], $fx->calls[0][2]['cmd'], 'solo lectura');
test_same(str_repeat('a1', 35), $fx->calls[0][2]['auth'], 'con el token que llegó');
// el dominio que se devuelve es el de la LISTA aunque llegue con mayúsculas/punto final
$r = pl_verificar_instalacion($SRV, ['DOMAIN' => 'GALJOSA.Bitrix24.com.'] + $REQ, new FakeBitrixInstall(bx_inst(7, true)), $CFG);
test_same('galjosa.bitrix24.com', $r['dominio'], 'dominio normalizado de la lista');
// forma de evento ONAPPINSTALL (auth[...])
$ev = ['auth' => ['access_token' => str_repeat('e5', 35), 'refresh_token' => str_repeat('r6', 35),
                  'member_id' => str_repeat('a', 32), 'domain' => 'galjosa.bitrix24.com']];
$fx = new FakeBitrixInstall(bx_inst(9, true));
$r = pl_verificar_instalacion($SRV, $ev, $fx, $CFG);
test_same([true, str_repeat('e5', 35), str_repeat('r6', 35)], [$r['ok'], $r['access_token'], $r['refresh_token']], 'forma de evento');
// no admin: ok pero admin=false (el llamador no guarda)
$r = pl_verificar_instalacion($SRV, $REQ, new FakeBitrixInstall(bx_inst(7, false)), $CFG);
test_same([true, false], [$r['ok'], $r['admin']], 'usuario que no es admin');
$r = pl_verificar_instalacion($SRV, $REQ, new FakeBitrixInstall(bx_inst(7, true, 'local.abc123', ['admin' => ['error' => 'x']])), $CFG);
test_same(false, $r['admin'], 'error en user.admin = no admin');

// ---------------------------------------------------------------- lib: rechazos sin llamar a Bitrix
$casos = [
    'sin-allowlist'        => [$SRV, $REQ, ['dominios' => []] + $CFG],
    'no-post'              => [['REQUEST_METHOD' => 'GET'], $REQ, $CFG],
    'sin-auth'             => [$SRV, ['AUTH_ID' => ''] + $REQ, $CFG],
    'refresh-raro'         => [$SRV, ['REFRESH_ID' => 'x"y'] + $REQ, $CFG],
    'sin-member'           => [$SRV, ['member_id' => ''] + $REQ, $CFG],
    'dominio-no-permitido' => [$SRV, ['DOMAIN' => 'atacante.example'] + $REQ, $CFG],
    'member-no-permitido'  => [$SRV, $REQ, ['members' => ['otro0000000000000000000000000000']] + $CFG],
];
foreach ($casos as $motivo => [$s, $q, $c]) {
    $fx = new FakeBitrixInstall(bx_inst(7, true));
    test_same(['ok' => false, 'motivo' => $motivo], pl_verificar_instalacion($s, $q, $fx, $c), 'rechazo ' . $motivo);
    test_same(0, count($fx->calls), 'sin llamar a Bitrix: ' . $motivo);
}
$fx = new FakeBitrixInstall(bx_inst(7, true));
test_same('dominio-no-permitido', pl_verificar_instalacion($SRV, ['DOMAIN' => 'galjosa.bitrix24.com@evil.example'] + $REQ, $fx, $CFG)['motivo'], 'dominio con @');
test_same(0, count($fx->calls), 'dominio con @ sin llamar');

// ---------------------------------------------------------------- lib: rechazos de Bitrix
test_same('token-rechazado:invalid_token', pl_verificar_instalacion($SRV, $REQ, new FakeBitrixInstall(['error' => 'invalid_token']), $CFG)['motivo'], 'token inventado');
test_same('bitrix-sin-respuesta', pl_verificar_instalacion($SRV, $REQ, new FakeBitrixInstall(null), $CFG)['motivo'], 'Bitrix caído');
test_same('token-sin-usuario', pl_verificar_instalacion($SRV, $REQ, new FakeBitrixInstall(bx_inst(0, true)), $CFG)['motivo'], 'sin usuario');
test_same('token-de-otra-app', pl_verificar_instalacion($SRV, $REQ, new FakeBitrixInstall(bx_inst(7, true, 'local.otra')), ['app' => 'local.abc123'] + $CFG)['motivo'], 'otra app');
test_same(true, pl_verificar_instalacion($SRV, $REQ, new FakeBitrixInstall(bx_inst(7, true, 'local.abc123')), ['app' => 'local.abc123'] + $CFG)['ok'], 'app esperada');

// ---------------------------------------------------------------- lib: host público
test_same('inventario.test', pl_host_publico(['HTTP_HOST' => 'inventario.test', 'HTTP_X_FORWARDED_HOST' => 'evil.example'], ''), 'X-Forwarded-Host se ignora');
test_same('fijo.example', pl_host_publico(['HTTP_HOST' => 'inventario.test'], 'fijo.example'), 'PUBLIC_HOST manda');
test_same('', pl_host_publico(['HTTP_HOST' => 'inventario.test:8080'], ''), 'Host con puerto no vale');
test_same('', pl_host_publico(['HTTP_HOST' => 'a/b'], ''), 'Host con ruta no vale');

// ---------------------------------------------------------------- endpoints reales (subproceso)
function correr_i(string $script, string $metodo, array $get, array $post, array $env, array $srv = []): array {
    $cmd = [PHP_BINARY, __DIR__ . '/placement-endpoint-runner.php', $script, $metodo,
            json_encode($get), json_encode($post), json_encode($env), json_encode($srv ?: new stdClass())];
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]); stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    $i = strrpos((string)$out, '@@RES@@');
    if ($i === false) throw new RuntimeException("$script sin resultado: " . substr((string)$out, 0, 300));
    return json_decode(substr($out, $i + 7), true);
}
$tmp = sys_get_temp_dir() . '/inst-test-' . bin2hex(random_bytes(4));
mkdir($tmp, 0700, true);
$TOKEN = 'outbound-token-de-prueba-0123456789';
$ENV = ['DATA_DIR' => $tmp, 'OUTBOUND_TOKEN' => $TOKEN, 'BITRIX_WEBHOOK' => 'https://webhook.invalid/rest/1/x/',
        'BITRIX_PLACEMENT_DOMAINS' => 'portal.invalid', 'APP_CLIENT_ID' => '', 'APP_CLIENT_SECRET' => ''];
$FAKE_LOG = $tmp . '/fake.log';
$fake = fn(bool $admin, int $u = 7) => ['PL_FAKE_BITRIX' => json_encode(bx_inst($u, $admin)), 'PL_FAKE_LOG' => $FAKE_LOG];
$INST = ['AUTH_ID' => str_repeat('a1', 35), 'REFRESH_ID' => str_repeat('r2', 35), 'member_id' => str_repeat('f', 32)];
$ataque = $INST + ['DOMAIN' => 'atacante.example', 'client_id' => 'local.atacante', 'client_secret' => 'secreto-del-atacante'];

$authF = $tmp . '/app_auth.json';
$cobF  = $tmp . '/cobranza_auth.json';
$SEMILLA = json_encode(['access_token' => 'tok-legitimo-0000000000', 'refresh_token' => 'ref-legitimo-0000000000',
    'client_id' => 'local.nuestra', 'client_secret' => 'secreto-nuestro', 'domain' => 'portal.invalid', 'expires' => 1]);
file_put_contents($authF, $SEMILLA);
file_put_contents($cobF, $SEMILLA);

// install.php: el POST anónimo del hallazgo (dominio y client_secret del atacante)
$res = correr_i('install.php', 'POST', [], $ataque, $ENV);
test_same(403, $res['code'], 'install anónimo con dominio ajeno = 403');
test_same($SEMILLA, file_get_contents($authF), 'install anónimo no toca app_auth.json');
// mismo POST con NUESTRO dominio pero token que Bitrix no confirma (portal.invalid no resuelve)
$res = correr_i('install.php', 'POST', ['DOMAIN' => 'portal.invalid'], $INST + ['client_secret' => 'x'], $ENV);
test_same(403, $res['code'], 'install con token sin confirmar = 403');
test_same($SEMILLA, file_get_contents($authF), 'install sin confirmar no toca app_auth.json');
// sin allowlist: fail-closed aunque Bitrix "diga" que sí
$res = correr_i('install.php', 'POST', ['DOMAIN' => 'portal.invalid'], $INST, ['BITRIX_PLACEMENT_DOMAINS' => ''] + $fake(true) + $ENV);
test_same(403, $res['code'], 'install sin allowlist = 403');
test_same(false, file_exists($FAKE_LOG), 'sin allowlist ni se llama a Bitrix');
test_same($SEMILLA, file_get_contents($authF), 'install sin allowlist no toca app_auth.json');
// token válido pero de alguien que no es admin
$res = correr_i('install.php', 'POST', ['DOMAIN' => 'portal.invalid'], $INST, $fake(false) + $ENV);
test_same(403, $res['code'], 'install de un no-admin = 403');
test_same($SEMILLA, file_get_contents($authF), 'install de no-admin no toca app_auth.json');
// GET con AUTH_ID en la query: no-post
$res = correr_i('install.php', 'GET', $INST + ['DOMAIN' => 'portal.invalid'], [], $fake(true) + $ENV);
test_same(403, $res['code'], 'install por GET = 403');
// admin confirmado: guarda, con el dominio de la lista y SIN el client_secret de la petición
@unlink($FAKE_LOG);
$res = correr_i('install.php', 'POST', ['DOMAIN' => 'PORTAL.invalid'], $INST + ['client_id' => 'local.atacante', 'client_secret' => 'secreto-del-atacante'], $fake(true) + $ENV);
test_same(200, $res['code'], 'install de un admin confirmado = 200');
$g = json_decode((string)file_get_contents($authF), true);
test_same([str_repeat('a1', 35), str_repeat('r2', 35), 'portal.invalid', 'local.nuestra', 'secreto-nuestro'],
    [$g['access_token'], $g['refresh_token'], $g['domain'], $g['client_id'], $g['client_secret']],
    'guarda token y dominio de la lista; client_id/secret NO salen de la petición');
$llamadas = array_map(fn($l) => json_decode($l, true), file($FAKE_LOG, FILE_IGNORE_NEW_LINES));
test_same([['portal.invalid', 'batch', ['yo' => 'user.current', 'admin' => 'user.admin', 'app' => 'app.info']]], $llamadas, 'una verificación de solo lectura al dominio de la lista');
file_put_contents($authF, $SEMILLA);

// install.php ?reinstalar: el handler ya no sale de X-Forwarded-Host
$res = correr_i('install.php', 'GET', ['reinstalar' => '1', 'token' => $TOKEN], [], $ENV, ['HTTP_X_FORWARDED_HOST' => 'evil.example']);
test_same(200, $res['code'], 'reinstalar con token');
test_same([true, false], [str_contains($res['body'], 'handler=https://inventario.test/field.php'), str_contains($res['body'], 'evil.example')], 'handler con el Host enrutado, no el reenviado');
$res = correr_i('install.php', 'GET', ['reinstalar' => '1', 'token' => 'otro'], [], $ENV);
test_same(403, $res['code'], 'reinstalar sin token = 403');

// app.php
$res = correr_i('app.php', 'GET', [], [], $ENV);
test_same(403, $res['code'], 'app.php anónimo = 403');
$res = correr_i('app.php', 'POST', [], $ataque, $ENV);
test_same(403, $res['code'], 'app.php con dominio ajeno = 403');
test_same($SEMILLA, file_get_contents($authF), 'app.php con dominio ajeno no toca app_auth.json');
$res = correr_i('app.php', 'POST', ['DOMAIN' => 'portal.invalid'], $INST, $fake(false) + $ENV);
test_same(200, $res['code'], 'app.php de un usuario del portal = 200');
test_same($SEMILLA, file_get_contents($authF), 'app.php de un no-admin no guarda tokens');
$res = correr_i('app.php', 'POST', ['DOMAIN' => 'portal.invalid'], $INST, $fake(true) + $ENV);
test_same(200, $res['code'], 'app.php de un admin = 200');
$g = json_decode((string)file_get_contents($authF), true);
test_same([str_repeat('a1', 35), 'portal.invalid', 'secreto-nuestro'], [$g['access_token'], $g['domain'], $g['client_secret']], 'app.php de un admin refresca los tokens');
file_put_contents($authF, $SEMILLA);

// cobranza_app.php
$res = correr_i('cobranza_app.php', 'GET', ['ver' => '1'], [], $ENV);
test_same(200, $res['code'], 'cobranza ?ver sigue público');
$res = correr_i('cobranza_app.php', 'GET', [], [], $ENV);
test_same([403, false], [$res['code'], str_contains($res['body'], 'portal.invalid')], 'cobranza anónimo = 403 sin mostrar el portal');
$res = correr_i('cobranza_app.php', 'POST', [], $ataque, $ENV);
test_same(403, $res['code'], 'cobranza con dominio ajeno = 403');
test_same($SEMILLA, file_get_contents($cobF), 'cobranza con dominio ajeno no toca cobranza_auth.json');
$res = correr_i('cobranza_app.php', 'POST', ['DOMAIN' => 'portal.invalid'], $INST, $fake(false) + $ENV);
test_same([200, $SEMILLA], [$res['code'], file_get_contents($cobF)], 'cobranza de un no-admin: se abre y no guarda');
$res = correr_i('cobranza_app.php', 'POST', ['DOMAIN' => 'portal.invalid'], $INST, $fake(true) + $ENV);
$g = json_decode((string)file_get_contents($cobF), true);
test_same([200, str_repeat('a1', 35), 'portal.invalid'], [$res['code'], $g['access_token'], $g['domain']], 'cobranza de un admin guarda');
$res = correr_i('cobranza_app.php', 'POST', ['DOMAIN' => 'portal.invalid'], $INST,
    ['BITRIX_COBRANZA_APP_CODE' => 'local.cobranzas'] + $fake(true) + $ENV);
test_same(403, $res['code'], 'cobranza con token de otra app = 403');

// llamada_campo.php
$PO = ['PLACEMENT_OPTIONS' => json_encode(['MODE' => 'edit', 'ENTITY_VALUE_ID' => '402071', 'FIELD_NAME' => 'UF_X'])];
$PPOST = ['AUTH_ID' => str_repeat('a1', 35), 'AUTH_EXPIRES' => '3600', 'member_id' => str_repeat('f', 32)];
$res = correr_i('llamada_campo.php', 'GET', [], [], $ENV);
test_same([403, false], [$res['code'], str_contains($res['body'], '_btnSi')], 'llamada_campo anónimo = 403 sin el calendario');
$res = correr_i('llamada_campo.php', 'GET', $PO, [], $ENV);
test_same(403, $res['code'], 'llamada_campo GET con deal elegido = 403');
$res = correr_i('llamada_campo.php', 'POST', ['DOMAIN' => 'atacante.example'], $PO + $PPOST, $ENV);
test_same(403, $res['code'], 'llamada_campo dominio ajeno = 403');
$res = correr_i('llamada_campo.php', 'POST', ['DOMAIN' => 'portal.invalid'], $PO + $PPOST, $ENV);
test_same(403, $res['code'], 'llamada_campo sin confirmación de Bitrix = 403');
putenv('DATA_DIR=' . $tmp);
$rutaVer = pl_cache_ruta('portal.invalid', $PPOST['member_id'], $PPOST['AUTH_ID'], 402071);
@mkdir(dirname($rutaVer), 0700, true);
file_put_contents($rutaVer, json_encode(['usuario' => 42, 'deal_id' => 402071, 'app' => 'local.x', 'hasta' => time() + 300]));
$res = correr_i('llamada_campo.php', 'POST', ['DOMAIN' => 'portal.invalid'], $PO + $PPOST, $ENV);
test_same([200, true], [$res['code'], str_contains($res['body'], '_btnSi')], 'llamada_campo verificado = 200');
test_same(true, str_contains($res['body'], 'var dealId = 402071'), 'llamada_campo con el deal verificado');

// field.php: el enlace del cotizador ya no toma X-Forwarded-Host
$POF = ['PLACEMENT_OPTIONS' => json_encode(['MODE' => 'view', 'ENTITY_VALUE_ID' => '402071', 'FIELD_NAME' => 'UF_X'])];
$res = correr_i('field.php', 'POST', ['DOMAIN' => 'portal.invalid'], $POF + $PPOST, $ENV, ['HTTP_X_FORWARDED_HOST' => 'evil.example']);
test_same(200, $res['code'], 'field verificado = 200');
test_same([true, false], [str_contains($res['body'], '"url":"https://inventario.test/cotizar.php"'), str_contains($res['body'], 'evil.example')], 'cot_base con el Host enrutado');
$res = correr_i('field.php', 'POST', ['DOMAIN' => 'portal.invalid'], $POF + $PPOST, ['PUBLIC_HOST' => 'galjosa-inventario-sync.example'] + $ENV, ['HTTP_X_FORWARDED_HOST' => 'evil.example']);
test_same(true, str_contains($res['body'], '"url":"https://galjosa-inventario-sync.example/cotizar.php"'), 'cot_base con PUBLIC_HOST');

// warm-catalogo.php por HTTP (servidor embebido de PHP, solo loopback): 404 y sin tocar nada
$puerto = random_int(20000, 40000);
$srv = proc_open([PHP_BINARY, '-S', "127.0.0.1:$puerto", '-t', dirname(__DIR__)],
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pp,
    null, ['DATA_DIR' => $tmp, 'OUTBOUND_TOKEN' => $TOKEN, 'BITRIX_WEBHOOK' => 'https://webhook.invalid/rest/1/x/']);
$cod = 0;
for ($i = 0; $i < 50 && $cod === 0; $i++) {
    usleep(100_000);
    $ctx = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 5]]);
    $b = @file_get_contents("http://127.0.0.1:$puerto/warm-catalogo.php", false, $ctx);
    if ($b !== false && isset($http_response_header[0]) && preg_match('/ (\d{3}) /', $http_response_header[0], $m)) $cod = (int)$m[1];
}
proc_terminate($srv); proc_close($srv);
test_same(404, $cod, 'warm-catalogo por HTTP = 404');
test_same(false, file_exists($tmp . '/selector_cache.json'), 'warm-catalogo por HTTP no reconstruye el catálogo');

// limpieza
exec('rm -rf ' . escapeshellarg($tmp));
putenv('DATA_DIR');

echo "test-install-auth OK\n";
