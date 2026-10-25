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
require_once __DIR__ . '/lib/placement-auth.php';   // pl_verificar_instalacion(), pl_host_publico()

const TIPO_CAMPO = 'galjosa_unidad';    // USER_TYPE_ID del campo propio (Inventario)
const TIPO_LLAMADA = 'galjosa_llamada'; // USER_TYPE_ID del campo "Registrar llamada"

$DATA_DIR = getenv('DATA_DIR') ?: '/data';

function ilog(string $m): void {
    global $DATA_DIR;
    @file_put_contents($DATA_DIR . '/sync.log',
        gmdate('Y-m-d\TH:i:s\Z') . '  INSTALL ' . $m . "\n", FILE_APPEND | LOCK_EX);
}

/** Base pública de este servicio (para armar la URL del handler del campo).
 *  Ya no sale de X-Forwarded-Host (lo elige el cliente): ver pl_host_publico(). '' = no se sabe. */
function base_url(): string {
    $host = pl_host_publico($_SERVER);
    return $host === '' ? '' : 'https://' . $host;
}

/** Registra (o re-registra) un tipo de campo propio dado su USER_TYPE_ID/handler/título. */
function registrar_tipo(string $tipo, string $handlerPath, string $titulo, string $desc): array {
    if (base_url() === '') return ['ok' => false, 'error' => 'sin-host-publico', 'desc' => 'definir PUBLIC_HOST'];
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
// Llega por POST con AUTH_ID/REFRESH_ID/member_id (+ DOMAIN en la query).
// 🔴 24-oct-2026: antes cualquier POST con esas claves reescribía app_auth.json
// (tokens, dominio y hasta client_secret). Ahora el token tiene que ser de un
// ADMINISTRADOR de nuestro portal, confirmado por el propio Bitrix, y
// client_id/client_secret ya no se aceptan de la petición (quedan los guardados o
// APP_CLIENT_ID/APP_CLIENT_SECRET del entorno). Ver lib/placement-auth.php.
if (!empty($_REQUEST['AUTH_ID']) || !empty($_REQUEST['auth']['access_token'])) {
    $V = pl_verificar_instalacion($_SERVER, $_REQUEST, null,
        ['app' => (string)getenv('BITRIX_PLACEMENT_APP_CODE')]);
    if (!$V['ok'] || !$V['admin']) {
        $motivo = $V['ok'] ? 'no-es-admin' : (string)$V['motivo'];
        pl_log_instalacion('INSTALL', '403 motivo=' . $motivo);
        ilog('instalación rechazada: ' . preg_replace('/[^A-Za-z0-9:_-]/', '', $motivo));
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        exit("forbidden\n");
    }
    auth_guardar([
        'access_token'  => $V['access_token'],
        'refresh_token' => $V['refresh_token'],
        'domain'        => $V['dominio'],        // el de la LISTA, no el que llegó
    ]);
    pl_log_instalacion('INSTALL', 'OK user=' . $V['usuario'] . ' member=' . substr($V['member'], 0, 64));

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
