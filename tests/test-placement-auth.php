<?php
/**
 * Verificación del placement (lib/placement-auth.php) y de las firmas v2 que usan
 * field.php, guardar.php, copiar.php y cotizar.php.
 *
 * Bitrix va SIEMPRE simulado: la lib recibe la llamada HTTP inyectada, y los
 * endpoints que corren en subproceso apuntan a dominios `.invalid`, que no
 * resuelven nunca. Pensado para correr con red apagada:
 *   podman run --rm --network none -v "$PWD":/app:ro,Z -w /app php:8-cli php tests/test-placement-auth.php
 */
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../lib/placement-auth.php';

$CLAVE = pl_clave('outbound-token-de-prueba-0123456789');
$AHORA = 1_790_000_000;

// ---------------------------------------------------------------- allowlist
test_same(['galjosa.bitrix24.com'], pl_dominios_permitidos(' Galjosa.Bitrix24.com , '), 'allowlist normaliza');
test_same([], pl_dominios_permitidos(''), 'allowlist vacía');
test_same([], pl_dominios_permitidos('https://galjosa.bitrix24.com/'), 'allowlist rechaza URL');
test_same('', pl_normalizar_dominio('galjosa.bitrix24.com/rest'), 'dominio con ruta');
test_same('', pl_normalizar_dominio('galjosa.bitrix24.com:443'), 'dominio con puerto');
test_same('', pl_normalizar_dominio('127.0.0.1'), 'IP no es dominio');

// ---------------------------------------------------------------- firmas v2
$f = pl_firmar('campo', 402071, 42, $AHORA + 3600, $CLAVE);
test_same(1, preg_match('/^v2\.402071\.42\.' . ($AHORA + 3600) . '\.[a-f0-9]{64}$/', $f), 'forma de la firma v2');
test_same(['ok' => true, 'usuario' => 42, 'vence' => $AHORA + 3600],
    pl_verificar_firma('campo', $f, 402071, $AHORA, PL_FIRMA_CAMPO_TTL, $CLAVE), 'firma v2 válida');
test_same('otro-deal', pl_verificar_firma('campo', $f, 402072, $AHORA, PL_FIRMA_CAMPO_TTL, $CLAVE)['motivo'], 'otro deal');
test_same('firma', pl_verificar_firma('cot', $f, 402071, $AHORA, PL_FIRMA_COT_TTL, $CLAVE)['motivo'], 'propósito cruzado');
test_same('vencida', pl_verificar_firma('campo', $f, 402071, $AHORA + 3601, PL_FIRMA_CAMPO_TTL, $CLAVE)['motivo'], 'vencida');
test_same('firma', pl_verificar_firma('campo', str_replace('.42.', '.43.', $f), 402071, $AHORA, PL_FIRMA_CAMPO_TTL, $CLAVE)['motivo'], 'usuario alterado');
test_same('firma', pl_verificar_firma('campo', $f, 402071, $AHORA, PL_FIRMA_CAMPO_TTL, pl_clave('otra-clave'))['motivo'], 'otra clave');
$lejos = pl_firmar('campo', 402071, 42, $AHORA + 30 * 86400, $CLAVE);
test_same('vence-demasiado-lejos', pl_verificar_firma('campo', $lejos, 402071, $AHORA, PL_FIRMA_CAMPO_TTL, $CLAVE)['motivo'], 'vencimiento eterno');
$vieja = hash_hmac('sha256', '402071', 'outbound-token-de-prueba-0123456789');
test_same('formato', pl_verificar_firma('campo', $vieja, 402071, $AHORA, PL_FIRMA_CAMPO_TTL, $CLAVE)['motivo'], 'la firma vieja (sin vencimiento) ya no vale');
test_same('sin-clave', pl_verificar_firma('campo', $f, 402071, $AHORA, PL_FIRMA_CAMPO_TTL, '')['motivo'], 'sin clave no verifica');
test_same('', pl_firmar('campo', 402071, 42, $AHORA, ''), 'sin clave no firma');
test_same('', pl_firmar('campo', 402071, 0, $AHORA, $CLAVE), 'sin usuario no firma');
test_same(false, pl_clave('outbound-token-de-prueba-0123456789') === 'outbound-token-de-prueba-0123456789', 'la clave es derivada');

// ---------------------------------------------------------------- placement
final class FakeBitrixPlacement {
    public array $calls = [];
    public function __construct(public mixed $respuesta) {}
    public function __invoke(string $dominio, string $metodo, array $params): ?array {
        $this->calls[] = [$dominio, $metodo, $params];
        return is_callable($this->respuesta) ? ($this->respuesta)($params) : $this->respuesta;
    }
}
function bx_ok(int $usuario, ?array $deal, string $app = 'local.abc123', array $errores = []): array {
    $res = ['yo' => ['ID' => (string)$usuario], 'app' => ['CODE' => $app]];
    if ($deal !== null) $res['deal'] = $deal;
    return ['result' => ['result' => $res, 'result_error' => $errores, 'result_total' => []]];
}
$SRV  = ['REQUEST_METHOD' => 'POST'];
$POST = ['AUTH_ID' => str_repeat('a1', 35), 'AUTH_EXPIRES' => '3600', 'member_id' => str_repeat('f', 32)];
$GET  = ['DOMAIN' => 'galjosa.bitrix24.com', 'PROTOCOL' => '1'];
$CFG  = ['dominios' => ['galjosa.bitrix24.com'], 'members' => [], 'app' => '', 'cache' => false, 'ahora' => $AHORA];
$DEAL = ['ID' => '402071', 'CATEGORY_ID' => '28', 'STAGE_ID' => 'C28:NEW', 'CONTACT_ID' => '9'];

// camino bueno: UNA llamada batch, al dominio de la lista, solo métodos de lectura
$fx = new FakeBitrixPlacement(bx_ok(42, $DEAL));
$r = pl_verificar_placement($SRV, $POST, $GET, 402071, $fx, $CFG);
test_same(true, $r['ok'], 'placement válido');
test_same(42, $r['usuario'], 'usuario que dijo Bitrix');
test_same(1, count($fx->calls), 'una sola llamada');
test_same('galjosa.bitrix24.com', $fx->calls[0][0], 'llama al dominio de la lista');
test_same('batch', $fx->calls[0][1], 'por batch');
test_same(['yo' => 'user.current', 'app' => 'app.info', 'deal' => 'crm.deal.get?id=402071'], $fx->calls[0][2]['cmd'], 'solo lectura');
foreach ($fx->calls as $c) foreach ($c[2]['cmd'] as $m) {
    test_same(0, preg_match('/\.(add|update|delete|set|bind|unbind)\b/i', $m), 'ningún método de escritura: ' . $m);
}
test_same(str_repeat('a1', 35), $fx->calls[0][2]['auth'], 'con el token del usuario');

// rechazos que NO deben ni llamar a Bitrix
$casos = [
    'sin-allowlist'        => [$SRV, $POST, $GET, ['dominios' => []] + $CFG],
    'no-post'              => [['REQUEST_METHOD' => 'GET'], $POST, $GET, $CFG],
    'sin-auth'             => [$SRV, ['AUTH_ID' => ''] + $POST, $GET, $CFG],
    'sin-member'           => [$SRV, ['member_id' => ''] + $POST, $GET, $CFG],
    'dominio-no-permitido' => [$SRV, $POST, ['DOMAIN' => 'atacante.bitrix24.com'], $CFG],
    'member-no-permitido'  => [$SRV, $POST, $GET, ['members' => ['otro0000000000000000000000000000']] + $CFG],
];
foreach ($casos as $motivo => [$s, $p, $g, $c]) {
    $fx = new FakeBitrixPlacement(bx_ok(42, $DEAL));
    $r = pl_verificar_placement($s, $p, $g, 402071, $fx, $c);
    test_same(['ok' => false, 'motivo' => $motivo], $r, 'rechazo ' . $motivo);
    test_same(0, count($fx->calls), 'sin llamar a Bitrix: ' . $motivo);
}
// un dominio malicioso con forma rara tampoco se usa para armar URL
$fx = new FakeBitrixPlacement(bx_ok(42, $DEAL));
test_same('dominio-no-permitido', pl_verificar_placement($SRV, $POST, ['DOMAIN' => 'galjosa.bitrix24.com@evil.example'], 402071, $fx, $CFG)['motivo'], 'dominio con @');
test_same(0, count($fx->calls), 'dominio con @ sin llamar');

// rechazos que dice Bitrix
$r = pl_verificar_placement($SRV, $POST, $GET, 402071,
    new FakeBitrixPlacement(['error' => 'invalid_token', 'error_description' => 'x']), $CFG);
test_same('token-rechazado:invalid_token', $r['motivo'], 'token inventado');
test_same('bitrix-sin-respuesta', pl_verificar_placement($SRV, $POST, $GET, 402071, new FakeBitrixPlacement(null), $CFG)['motivo'], 'Bitrix caído = no se firma');
test_same('sin-acceso-al-deal', pl_verificar_placement($SRV, $POST, $GET, 402071,
    new FakeBitrixPlacement(bx_ok(42, null, 'local.abc123', ['deal' => ['error' => 'ACCESS_DENIED']])), $CFG)['motivo'], 'usuario sin permiso sobre el deal');
test_same('sin-acceso-al-deal', pl_verificar_placement($SRV, $POST, $GET, 402071,
    new FakeBitrixPlacement(bx_ok(42, ['ID' => '999'])), $CFG)['motivo'], 'Bitrix devolvió otro deal');
test_same('token-sin-usuario', pl_verificar_placement($SRV, $POST, $GET, 402071,
    new FakeBitrixPlacement(bx_ok(0, $DEAL)), $CFG)['motivo'], 'sin usuario');
test_same('token-de-otra-app', pl_verificar_placement($SRV, $POST, $GET, 402071,
    new FakeBitrixPlacement(bx_ok(42, $DEAL, 'local.otra')), ['app' => 'local.abc123'] + $CFG)['motivo'], 'token de otra app');
test_same(true, pl_verificar_placement($SRV, $POST, $GET, 402071,
    new FakeBitrixPlacement(bx_ok(42, $DEAL, 'local.abc123')), ['app' => 'local.abc123'] + $CFG)['ok'], 'app esperada');
test_same(true, pl_verificar_placement($SRV, $POST, $GET, 402071,
    new FakeBitrixPlacement(bx_ok(42, $DEAL)), ['members' => [strtoupper(str_repeat('f', 32))]] + $CFG)['ok'], 'member permitido');

// deal 0 (formulario de alta): solo el token, sin crm.deal.get
$fx = new FakeBitrixPlacement(bx_ok(42, null));
test_same(true, pl_verificar_placement($SRV, $POST, $GET, 0, $fx, $CFG)['ok'], 'deal 0 con token válido');
test_same(['yo' => 'user.current', 'app' => 'app.info'], $fx->calls[0][2]['cmd'], 'deal 0 no lee deals');

// caché: el segundo render del mismo token+deal no vuelve a llamar; otro deal sí
$tmp = sys_get_temp_dir() . '/pl-test-' . bin2hex(random_bytes(4));
mkdir($tmp, 0700, true);
putenv('DATA_DIR=' . $tmp);
$fx = new FakeBitrixPlacement(bx_ok(42, $DEAL));
$cc = ['cache' => true] + $CFG;
test_same(true, pl_verificar_placement($SRV, $POST, $GET, 402071, $fx, $cc)['fresco'], 'primera vez va a Bitrix');
$r2 = pl_verificar_placement($SRV, $POST, $GET, 402071, $fx, $cc);
test_same([true, false, 42, 1], [$r2['ok'], $r2['fresco'], $r2['usuario'], count($fx->calls)], 'segunda vez sale del caché');
pl_verificar_placement($SRV, $POST, $GET, 402072, new FakeBitrixPlacement(bx_ok(42, ['ID' => '402072'])), $cc);
$fx2 = new FakeBitrixPlacement(bx_ok(42, $DEAL));
pl_verificar_placement($SRV, ['AUTH_ID' => str_repeat('b2', 35)] + $POST, $GET, 402071, $fx2, $cc);
test_same(1, count($fx2->calls), 'otro token no usa el caché ajeno');
$fx3 = new FakeBitrixPlacement(bx_ok(42, $DEAL));
pl_verificar_placement($SRV, $POST, $GET, 402071, $fx3, ['ahora' => $AHORA + PL_CACHE_TTL + 1] + $cc);
test_same(1, count($fx3->calls), 'caché vencido vuelve a preguntar');
$fx4 = new FakeBitrixPlacement(['error' => 'invalid_token']);
pl_verificar_placement($SRV, ['AUTH_ID' => str_repeat('c3', 35)] + $POST, $GET, 402071, $fx4, $cc);
pl_verificar_placement($SRV, ['AUTH_ID' => str_repeat('c3', 35)] + $POST, $GET, 402071, $fx4, $cc);
test_same(2, count($fx4->calls), 'un rechazo no se recuerda como éxito');

// ---------------------------------------------------------------- endpoints reales (subproceso)
function correr(string $script, string $metodo, array $get, array $post, array $env): array {
    $cmd = [PHP_BINARY, __DIR__ . '/placement-endpoint-runner.php', $script, $metodo,
            json_encode($get), json_encode($post), json_encode($env)];
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]); stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    $i = strrpos((string)$out, '@@RES@@');
    if ($i === false) throw new RuntimeException("$script sin resultado: " . substr((string)$out, 0, 300));
    return json_decode(substr($out, $i + 7), true);
}
$TOKEN = 'outbound-token-de-prueba-0123456789';
$ENV = ['DATA_DIR' => $tmp, 'OUTBOUND_TOKEN' => $TOKEN, 'BITRIX_WEBHOOK' => 'https://webhook.invalid/rest/1/x/',
        'BITRIX_PLACEMENT_DOMAINS' => 'portal.invalid'];
$PO = ['PLACEMENT_OPTIONS' => json_encode(['MODE' => 'view', 'ENTITY_VALUE_ID' => '402071', 'FIELD_NAME' => 'UF_X'])];
function sin_secretos(array $res, string $nombre): void {
    test_same(0, preg_match('/[a-f0-9]{64}/', $res['body']), $nombre . ': sin 64-hex en el cuerpo');
    test_same(false, str_contains($res['body'], 'guardar.php'), $nombre . ': sin el selector');
}

// field.php: el GET anónimo de la auditoría
$res = correr('field.php', 'GET', [], [], $ENV);
test_same(403, $res['code'], 'field GET anónimo = 403'); sin_secretos($res, 'field GET anónimo');
$res = correr('field.php', 'GET', $PO, [], $ENV);
test_same(403, $res['code'], 'field GET con deal elegido = 403'); sin_secretos($res, 'field GET con deal');
// POST con forma de placement pero sin allowlist configurada: fail-closed
$res = correr('field.php', 'POST', ['DOMAIN' => 'portal.invalid'], $PO + $POST, ['BITRIX_PLACEMENT_DOMAINS' => ''] + $ENV);
test_same(403, $res['code'], 'field sin allowlist = 403'); sin_secretos($res, 'field sin allowlist');
// dominio ajeno
$res = correr('field.php', 'POST', ['DOMAIN' => 'atacante.bitrix24.com'], $PO + $POST, $ENV);
test_same(403, $res['code'], 'field dominio ajeno = 403'); sin_secretos($res, 'field dominio ajeno');
// dominio permitido pero Bitrix no confirma (aquí no resuelve): no se firma
$res = correr('field.php', 'POST', ['DOMAIN' => 'portal.invalid'], $PO + $POST, $ENV);
test_same(403, $res['code'], 'field sin confirmación de Bitrix = 403'); sin_secretos($res, 'field sin confirmación');

// placement ya verificado (sembrado en el caché, como lo deja una verificación real):
// el campo se dibuja y las firmas salen amarradas a deal + usuario + vencimiento.
$rutaVer = pl_cache_ruta('portal.invalid', $POST['member_id'], $POST['AUTH_ID'], 402071);
@mkdir(dirname($rutaVer), 0700, true);
file_put_contents($rutaVer, json_encode(['usuario' => 42, 'deal_id' => 402071, 'app' => 'local.x', 'hasta' => time() + 300]));
$res = correr('field.php', 'POST', ['DOMAIN' => 'portal.invalid'], $PO + $POST, $ENV);
test_same(200, $res['code'], 'field verificado = 200');
test_same(true, str_contains($res['body'], 'guardar.php'), 'field verificado dibuja el selector');
test_same(1, preg_match('/firma: "(v2\.402071\.42\.(\d+)\.[a-f0-9]{64})"/', $res['body'], $mF), 'firma del campo v2 con deal y usuario');
test_same(true, pl_verificar_firma('campo', $mF[1], 402071, time(), PL_FIRMA_CAMPO_TTL, pl_clave($TOKEN))['ok'], 'la firma del campo verifica');
test_same(true, (int)$mF[2] <= time() + PL_FIRMA_CAMPO_TTL, 'la firma del campo vence en 2 h');
test_same(1, preg_match('/"s":"(v2\.402071\.42\.\d+\.[a-f0-9]{64})"/', $res['body'], $mC), 'base del cotizador v2');
test_same(true, pl_verificar_firma('cot', $mC[1], 402071, time(), PL_FIRMA_COT_TTL, pl_clave($TOKEN))['ok'], 'la base del cotizador verifica');
test_same(0, preg_match('/' . hash_hmac('sha256', '402071', $TOKEN) . '/', $res['body']), 'ya no sale la firma vieja');
// el mismo caché NO sirve para otro deal
$PO2 = ['PLACEMENT_OPTIONS' => json_encode(['MODE' => 'view', 'ENTITY_VALUE_ID' => '402072', 'FIELD_NAME' => 'UF_X'])];
$res = correr('field.php', 'POST', ['DOMAIN' => 'portal.invalid'], $PO2 + $POST, $ENV);
test_same(403, $res['code'], 'token verificado para un deal no firma otro'); sin_secretos($res, 'field otro deal');

// guardar.php / copiar.php
$firmaVieja = hash_hmac('sha256', '402071', $TOKEN);
$res = correr('guardar.php', 'POST', [], ['deal' => '402071', 'valor' => '581', 'firma' => $firmaVieja], $ENV);
test_same([403, 'firma invalida'], [$res['code'], json_decode($res['body'], true)['error'] ?? null], 'guardar con la firma vieja = 403');
$res = correr('guardar.php', 'GET', ['deal' => '402071', 'valor' => '581', 'firma' => $firmaVieja], [], $ENV);
test_same(405, $res['code'], 'guardar por GET = 405');
$fCot = pl_firmar('cot', 402071, 42, time() + 3600, pl_clave($TOKEN));
$res = correr('guardar.php', 'POST', [], ['deal' => '402071', 'valor' => '581', 'firma' => $fCot], $ENV);
test_same(403, $res['code'], 'guardar con la firma del cotizador = 403');
$fVencida = pl_firmar('campo', 402071, 42, time() - 10, pl_clave($TOKEN));
$res = correr('guardar.php', 'POST', [], ['deal' => '402071', 'valor' => '581', 'firma' => $fVencida], $ENV);
test_same(403, $res['code'], 'guardar con firma vencida = 403');
test_same(true, str_contains((string)(json_decode($res['body'], true)['error'] ?? ''), 'vencio'), 'guardar dice que venció');
$fOtro = pl_firmar('campo', 402072, 42, time() + 3600, pl_clave($TOKEN));
$res = correr('guardar.php', 'POST', [], ['deal' => '402071', 'valor' => '581', 'firma' => $fOtro], $ENV);
test_same(403, $res['code'], 'guardar con firma de otro deal = 403');
// firma buena: pasa la puerta y llega a LEER el deal (el webhook .invalid no responde -> no escribe nada)
$fBuena = pl_firmar('campo', 402071, 42, time() + 3600, pl_clave($TOKEN));
$res = correr('guardar.php', 'POST', [], ['deal' => '402071', 'valor' => '581', 'firma' => $fBuena], $ENV);
test_same([200, 'el deal no existe'], [$res['code'], json_decode($res['body'], true)['error'] ?? null], 'guardar con firma buena pasa la puerta');

$res = correr('copiar.php', 'POST', [], ['deal' => '402071', 'unidad' => '581', 'firma' => $firmaVieja], $ENV);
test_same(403, $res['code'], 'copiar con la firma vieja = 403');
$res = correr('copiar.php', 'POST', [], ['deal' => '402071', 'unidad' => '581', 'firma' => $fVencida], $ENV);
test_same(403, $res['code'], 'copiar con firma vencida = 403');
$res = correr('copiar.php', 'POST', [], ['deal' => '402071', 'unidad' => '581', 'firma' => $fBuena], $ENV);
test_same(404, $res['code'], 'copiar con firma buena pasa la puerta (y no encuentra el deal)');

// cotizar.php
$cot = fn(array $q) => correr('cotizar.php', 'GET', $q, [], $ENV);
$exp = time() + 3600;
$fCotOk = pl_firmar('cot', 402071, 42, $exp, pl_clave($TOKEN));
test_same(404, $cot(['u' => '581', 'd' => '402071', 'exp' => (string)$exp, 's' => $fCotOk])['code'], 'cotizar con v2 pasa la puerta (sin catálogo = 404)');
test_same(403, $cot(['u' => '581', 'd' => '402072', 'exp' => (string)$exp, 's' => $fCotOk])['code'], 'cotizar v2 con otro deal = 403');
test_same(403, $cot(['u' => '581', 'd' => '402071', 'exp' => (string)($exp + 60), 's' => $fCotOk])['code'], 'cotizar v2 con exp cambiado = 403');
$fCampo = pl_firmar('campo', 402071, 42, $exp, pl_clave($TOKEN));
test_same(403, $cot(['u' => '581', 'd' => '402071', 'exp' => (string)$exp, 's' => $fCampo])['code'], 'cotizar con firma de guardar = 403');
$legacy = fn(int $d, int $e) => hash_hmac('sha256', "d{$d}|e{$e}", $TOKEN);
test_same(404, $cot(['u' => '581', 'd' => '402071', 'exp' => (string)$exp, 's' => $legacy(402071, $exp)])['code'], 'cotizar con la firma del bot/historial sigue valiendo');
$eterno = time() + 30 * 86400;
test_same(403, $cot(['u' => '581', 'd' => '402071', 'exp' => (string)$eterno, 's' => $legacy(402071, $eterno)])['code'], 'cotizar rechaza vencimientos de más de 12 h');
$res = $cot(['u' => '581', 'd' => '402071', 'exp' => (string)(time() - 5), 's' => pl_firmar('cot', 402071, 42, time() - 5, pl_clave($TOKEN))]);
test_same([403, true], [$res['code'], str_contains($res['body'], 'venció')], 'cotizar v2 vencida dice que venció');

array_map('unlink', glob($tmp . '/*/*') ?: []);
array_map('unlink', array_filter(glob($tmp . '/*') ?: [], 'is_file'));
array_map('rmdir', glob($tmp . '/*', GLOB_ONLYDIR) ?: []);
@rmdir($tmp);
putenv('DATA_DIR');

echo "test-placement-auth OK\n";
