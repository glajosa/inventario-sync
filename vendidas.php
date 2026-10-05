<?php
/**
 * vendidas.php — API "cotizaron y no compraron".
 * ---------------------------------------------------------------------------
 * Para el desarrollo que manda el WhatsApp (de Galjosa, aprobado por Jesua el 5-oct-2026):
 *
 *   GET  vendidas.php
 *        Authorization: Bearer <token del sistema de avisos>
 *        Las ventas listas para avisar, con a quien escribirle (nombre + celular), la
 *        unidad vendida y hasta 5 alternativas DISPONIBLES del mismo proyecto y tipo.
 *        Solo trae avisos PENDIENTES; ?todo=1 trae tambien enviados y anulados.
 *
 *   POST vendidas.php   accion=enviado&unidad=D-2-12&comprador=999&deal=555
 *        Marca un aviso como enviado. Idempotente.
 *
 * Administracion (cabecera X-Token: <OUTBOUND_TOKEN>):
 *   POST accion=nuevo_token     crea el token del sistema de avisos y lo muestra UNA vez.
 *                               Se guarda solo su sha256 en /data. Uno nuevo invalida el viejo.
 *   POST accion=config&clave=…&valor=…   perillas en /data/config.json (lista cerrada).
 *   GET  (con X-Token)          lo mismo que el sistema de avisos, para revisar.
 *
 * Reglas (Jesua, 5-oct-2026): vendida = el deal entra a RESERVA en CLIENTES (unidad
 * RESERVADO). Se excluye al comprador y a cualquiera que ya compro otra unidad (deal en
 * CLIENTES no caido), por CONTACTO y recalculado en cada consulta. A los demas se les
 * escribe aunque no les haya interesado: es lo que genera la urgencia.
 *
 * 🔴 Nunca se responde con tokens ni con la llave de la libreta. Cada consulta queda
 * anotada en /data/vendidas_lecturas.log.
 */
declare(strict_types=1);
require_once __DIR__ . '/vendidaslib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$DIR = getenv('DATA_DIR') ?: '/data';

function vapi_fin(int $st, array $j): void {
    http_response_code($st);
    echo json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ── quien llama ─────────────────────────────────────────────────────────────
$admin = false; $amigo = false;
$outbound = (string)getenv('OUTBOUND_TOKEN');
$xtok = (string)($_SERVER['HTTP_X_TOKEN'] ?? '');
if ($outbound !== '' && $xtok !== '' && hash_equals($outbound, $xtok)) $admin = true;
/* Apache con mod_php NO siempre pasa la cabecera Authorization a $_SERVER (con el
   servidor de PHP en local si, por eso en la primera prueba funciono y en produccion
   dio 403). Se busca en los tres lugares donde puede quedar. */
$auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if ($auth === '' && function_exists('getallheaders')) {
    foreach ((array)getallheaders() as $k => $v) if (strcasecmp((string)$k, 'Authorization') === 0) { $auth = (string)$v; break; }
}
if (!$admin && preg_match('/^Bearer\s+(\S+)$/', $auth, $m)) {
    $guardado = json_decode((string)@file_get_contents("$DIR/vendidas_token.json"), true);
    $hash = (string)($guardado['sha256'] ?? '');
    if ($hash !== '' && hash_equals($hash, hash('sha256', $m[1]))) $amigo = true;
}
if (!$admin && !$amigo) vapi_fin(403, ['ok' => false, 'error' => 'forbidden']);

$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$accion = (string)($_POST['accion'] ?? '');
@file_put_contents("$DIR/vendidas_lecturas.log",
    gmdate('c') . ' ' . ($admin ? 'admin' : 'avisos') . " $metodo " . ($accion ?: '-') . ' '
    . ($_SERVER['REMOTE_ADDR'] ?? '?') . "\n", FILE_APPEND | LOCK_EX);

// ── administracion ──────────────────────────────────────────────────────────
if ($metodo === 'POST' && $accion === 'nuevo_token') {
    if (!$admin) vapi_fin(403, ['ok' => false, 'error' => 'solo administracion']);
    $tok = bin2hex(random_bytes(24));
    @file_put_contents("$DIR/vendidas_token.json",
        json_encode(['sha256' => hash('sha256', $tok), 'creado' => gmdate('c')]), LOCK_EX);
    @chmod("$DIR/vendidas_token.json", 0600);
    vapi_fin(200, ['ok' => true, 'token' => $tok,
                   'aviso' => 'Se muestra UNA sola vez. Guardalo en el sistema de avisos; aqui solo queda su huella.']);
}
if ($metodo === 'POST' && $accion === 'config') {
    if (!$admin) vapi_fin(403, ['ok' => false, 'error' => 'solo administracion']);
    $permitidas = ['avisos_vendidas', 'avisos_etapas', 'avisos_ventana_dias', 'avisos_espera_horas'];
    $clave = (string)($_POST['clave'] ?? '');
    if (!in_array($clave, $permitidas, true)) vapi_fin(400, ['ok' => false, 'error' => 'clave no permitida']);
    $valor = json_decode((string)($_POST['valor'] ?? 'null'), true);
    $f = "$DIR/config.json";
    $cfg = is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
    $cfg[$clave] = $valor;
    @file_put_contents($f, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    vapi_fin(200, ['ok' => true, 'config' => $cfg]);
}

$perilla = (int)inv_cfg('avisos_vendidas', 0) === 1;
$d = vend_db();
if (!$d) vapi_fin(503, ['ok' => false, 'error' => 'base no disponible']);

// ── marcar enviado ──────────────────────────────────────────────────────────
if ($metodo === 'POST' && $accion === 'enviado') {
    if (!$perilla) vapi_fin(409, ['ok' => false, 'error' => 'avisos apagados']);
    $u = $d->prepare("UPDATE vendidas_avisos SET estado = 'enviado', enviado_en = ?
                      WHERE unidad = ? AND comprador_deal = ? AND deal_id = ? AND estado = 'pendiente'");
    $u->execute([time(), strtoupper(trim((string)($_POST['unidad'] ?? ''))),
                 (int)($_POST['comprador'] ?? 0), (int)($_POST['deal'] ?? 0)]);
    vapi_fin(200, ['ok' => true, 'marcados' => $u->rowCount()]);
}

// ── consultar ───────────────────────────────────────────────────────────────
if (!$perilla && !$admin) vapi_fin(409, ['ok' => false, 'error' => 'avisos apagados']);
$todo   = !empty($_GET['todo']);
$espera = max(0, (int)inv_cfg('avisos_espera_horas', 48)) * 3600;
$cat    = json_decode((string)@file_get_contents("$DIR/selector_cache.json"), true) ?: [];
$units  = (array)($cat['units'] ?? []);
$proyectos = (array)($cat['proyectos'] ?? []);

$ventas = []; $resumen = ['registradas' => 0, 'ventas' => 0, 'candidatos' => 0, 'avisar' => 0, 'ya_compro' => 0,
                          'sin_telefono' => 0, 'sin_contacto' => 0, 'repetido' => 0];
foreach ($d->query("SELECT * FROM vendidas WHERE estado = 'firme' ORDER BY cuando DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC) as $v) {
    if (time() - (int)$v['cuando'] < $espera) continue;          // todavia no esta firme
    $a = $d->prepare("SELECT deal_id, asesor_id, ultima_cotizacion, veces, estado FROM vendidas_avisos
                      WHERE unidad = ? AND comprador_deal = ?" . ($todo ? '' : " AND estado = 'pendiente'"));
    $a->execute([$v['unidad'], (int)$v['comprador_deal']]);
    $avisos = $a->fetchAll(PDO::FETCH_ASSOC); $a->closeCursor();
    $resumen['registradas']++;
    /* Al sistema de avisos no le sirve una venta sin nadie a quien escribir. A la
       administracion SI (?todo=1): sin verlas no hay forma de saber si la deteccion anda
       o si simplemente nadie habia cotizado esa unidad. */
    if (!$avisos && !($admin && $todo)) continue;

    $res = $perilla ? vend_resolver($d, array_merge(array_column($avisos, 'deal_id'), [(int)$v['comprador_deal']]),
                                    'vend_libreta_http') : [];
    $lista = [];
    foreach ($avisos as $x) {
        $r = $res[(int)$x['deal_id']] ?? ['estado' => 'sin_resolver', 'nombre' => '', 'telefono' => ''];
        $resumen['candidatos']++;
        if (isset($resumen[$r['estado']])) $resumen[$r['estado']]++;
        $lista[] = ['deal' => (int)$x['deal_id'], 'asesor' => (int)$x['asesor_id'],
                    'cotizo_utc' => gmdate('c', (int)$x['ultima_cotizacion']), 'veces' => (int)$x['veces'],
                    'estado' => $r['estado'], 'nombre' => $r['nombre'], 'celular' => $r['telefono'],
                    'aviso' => $x['estado']];
    }
    $catId = (int)($v['categoria'] ?? 0);
    $ventas[] = [
        'unidad' => $v['unidad'], 'proyecto' => (string)($proyectos[(string)$catId] ?? ''),
        'comprador_deal' => (int)$v['comprador_deal'], 'vendida_utc' => gmdate('c', (int)$v['cuando']),
        'alternativas' => vend_alternativas($units, (string)$v['unidad'], $catId),
        'clientes' => $lista,
    ];
    $resumen['ventas']++;
}
vapi_fin(200, ['ok' => true, 'envio_aprobado' => $perilla, 'resumen' => $resumen, 'ventas' => $ventas]);
