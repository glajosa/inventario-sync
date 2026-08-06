<?php
/**
 * install.php — instalación de la aplicación local + registro del tipo de campo.
 * ---------------------------------------------------------------------------
 * Bitrix llama aquí al instalar la app y nos entrega los tokens OAuth.
 * Con esos tokens registramos un TIPO DE CAMPO propio (userfieldtype.add) para
 * que el selector de unidades se dibuje DENTRO del formulario del deal, con
 * filtro por proyecto y las unidades ocupadas apagadas.
 *
 * Se puede volver a abrir esta URL (?reinstalar=1&token=...) para re-registrar
 * el tipo de campo sin reinstalar la app.
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);
require_once __DIR__ . '/appauth.php';

const TIPO_CAMPO = 'galjosa_unidad';    // USER_TYPE_ID del campo propio (Inventario)
const TIPO_LLAMADA = 'galjosa_llamada'; // USER_TYPE_ID del campo "Registrar llamada"

$DATA_DIR = getenv('DATA_DIR') ?: '/data';

function ilog(string $m): void {
    global $DATA_DIR;
    @file_put_contents($DATA_DIR . '/sync.log',
        gmdate('Y-m-d\TH:i:s\Z') . '  INSTALL ' . $m . "\n", FILE_APPEND | LOCK_EX);
}

/** Base pública de este servicio (para armar la URL del handler del campo). */
function base_url(): string {
    $host = (string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? '');
    return 'https://' . $host;
}

/** Registra (o re-registra) un tipo de campo propio dado su USER_TYPE_ID/handler/título. */
function registrar_tipo(string $tipo, string $handlerPath, string $titulo, string $desc): array {
    $handler = base_url() . $handlerPath;

    // si ya existe, se borra para que tome el handler nuevo
    $lista = app_bx('userfieldtype.list');
    if ($lista['ok']) {
        foreach (($lista['result'] ?? []) as $t) {
            if (($t['USER_TYPE_ID'] ?? '') === $tipo) {
                app_bx('userfieldtype.delete', ['USER_TYPE_ID' => $tipo]);
                break;
            }
        }
    }

    return app_bx('userfieldtype.add', [
        'USER_TYPE_ID' => $tipo,
        'HANDLER'      => $handler,
        'TITLE'        => $titulo,
        'DESCRIPTION'  => $desc,
    ]);
}

/** Registra (o re-registra) el tipo de campo "Unidad de Inventario". */
function registrar_tipo_campo(): array {
    return registrar_tipo(TIPO_CAMPO, '/field.php', 'Unidad de Inventario',
        'Selector de unidades agrupado por proyecto, con las ocupadas bloqueadas');
}

/** Registra (o re-registra) el tipo de campo "Registrar llamada". */
function registrar_tipo_llamada(): array {
    return registrar_tipo(TIPO_LLAMADA, '/llamada_campo.php', 'Registrar llamada',
        'Calendario chico para planificar la proxima llamada y marcar si contesto');
}

// ---- Bitrix instalando la app -----------------------------------------------
// Llega por POST con AUTH_ID/REFRESH_ID (+ client_id/secret si se configuraron).
if (!empty($_REQUEST['AUTH_ID']) || !empty($_REQUEST['auth']['access_token'])) {
    $auth = $_REQUEST['auth'] ?? [];
    auth_guardar([
        'access_token'  => $_REQUEST['AUTH_ID']    ?? ($auth['access_token']  ?? ''),
        'refresh_token' => $_REQUEST['REFRESH_ID'] ?? ($auth['refresh_token'] ?? ''),
        'domain'        => $_REQUEST['DOMAIN']     ?? ($auth['domain']        ?? ''),
        'client_id'     => $_REQUEST['client_id']     ?? '',
        'client_secret' => $_REQUEST['client_secret'] ?? '',
    ]);

    $r = registrar_tipo_campo();
    $r2 = registrar_tipo_llamada();
    ilog('app instalada; userfieldtype.add(unidad) ok=' . var_export($r['ok'], true)
        . ' err=' . ($r['error'] ?? '-') . ' ' . ($r['desc'] ?? '')
        . ' | userfieldtype.add(llamada) ok=' . var_export($r2['ok'], true)
        . ' err=' . ($r2['error'] ?? '-') . ' ' . ($r2['desc'] ?? ''));

    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!doctype html><meta charset="utf-8">
    <script src="//api.bitrix24.com/api/v1/"></script>
    <style>body{font:15px -apple-system,Segoe UI,Roboto,sans-serif;padding:24px;color:#1f2328}
      .ok{color:#1a7f37} .err{color:#cf222e} code{background:#f6f8fa;padding:2px 5px;border-radius:4px}</style>
    <h2>Inventario Galjosa</h2>
    <?php if ($r['ok']): ?>
      <p class="ok"><b>Instalado.</b> Ya existe el tipo de campo <code>Unidad de Inventario</code>.</p>
    <?php else: ?>
      <p class="err"><b>El tipo de campo Unidad de Inventario NO se pudo registrar.</b></p>
      <p>Error: <code><?= htmlspecialchars((string)($r['error'] ?? '?'), ENT_QUOTES) ?></code>
         <?= htmlspecialchars((string)($r['desc'] ?? ''), ENT_QUOTES) ?></p>
    <?php endif; ?>
    <?php if ($r2['ok']): ?>
      <p class="ok"><b>Instalado.</b> Ya existe el tipo de campo <code>Registrar llamada</code>.</p>
      <p>Ahora hay que crear un campo de cada tipo en Negociaciones y ponerlo en el formulario del deal.</p>
    <?php else: ?>
      <p class="err"><b>El tipo de campo Registrar llamada NO se pudo registrar.</b></p>
      <p>Error: <code><?= htmlspecialchars((string)($r2['error'] ?? '?'), ENT_QUOTES) ?></code>
         <?= htmlspecialchars((string)($r2['desc'] ?? ''), ENT_QUOTES) ?></p>
    <?php endif; ?>
    <script>if (typeof BX24 !== 'undefined') { BX24.init(function(){ BX24.installFinish(); }); }</script>
    <?php
    exit;
}

// ---- Re-registrar el tipo de campo a mano -----------------------------------
if (isset($_GET['reinstalar'])) {
    $esperado = (string)getenv('OUTBOUND_TOKEN');
    if ($esperado === '' || !hash_equals($esperado, (string)($_GET['token'] ?? ''))) {
        http_response_code(403); exit('forbidden');
    }
    header('Content-Type: text/plain; charset=utf-8');
    $r = registrar_tipo_campo();
    echo 'userfieldtype.add(unidad) ok=' . var_export($r['ok'], true)
       . ' err=' . ($r['error'] ?? '-') . ' ' . ($r['desc'] ?? '') . "\n";
    echo 'handler=' . base_url() . "/field.php\n";

    $r2 = registrar_tipo_llamada();
    echo 'userfieldtype.add(llamada) ok=' . var_export($r2['ok'], true)
       . ' err=' . ($r2['error'] ?? '-') . ' ' . ($r2['desc'] ?? '') . "\n";
    echo 'handler=' . base_url() . "/llamada_campo.php\n";

    $l = app_bx('userfieldtype.list');
    echo 'tipos registrados: ' . json_encode($l['result'] ?? $l['error'] ?? null) . "\n";
    exit;
}

header('Content-Type: text/plain; charset=utf-8');
echo "install.php listo. Bitrix debe llamar aquí al instalar la aplicación local.\n";
