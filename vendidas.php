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
 *   GET  vendidas.php?resumen_cotizaciones=1[&dias=90]
 *        Cuantos clientes distintos cotizaron cada unidad, haya venta o no. Sin datos de
 *        clientes (solo conteos), por eso no depende de la perilla. Pedido del conector de
 *        WhatsApp (9-oct-2026) para medir de antemano cuanto va a mover la automatizacion.
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

$cat    = json_decode((string)@file_get_contents("$DIR/selector_cache.json"), true) ?: [];
$units  = (array)($cat['units'] ?? []);
$proyectos = (array)($cat['proyectos'] ?? []);

// ── resumen de cotizaciones (sin datos de clientes) ───────────────────────────
if ($metodo === 'GET' && !empty($_GET['resumen_cotizaciones'])) {
    $dias  = max(1, min(365, (int)($_GET['dias'] ?? inv_cfg('avisos_ventana_dias', 90))));
    $desde = time() - $dias * 86400;
    $etapa = [];
    foreach ($units as $u) $etapa[(int)($u['cat'] ?? 0) . '|' . strtoupper((string)($u['codigo'] ?? ''))] = (string)($u['stage'] ?? '');
    $acc = [];
    $q = $d->prepare('SELECT deal_id, categoria, proyecto, unidades, ultima_vez FROM cotizaciones WHERE ultima_vez >= ? AND deal_id > 0');
    $q->execute([$desde]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        foreach (explode(',', (string)$r['unidades']) as $un) {
            $un = strtoupper(trim($un)); if ($un === '') continue;
            $k = (int)$r['categoria'] . '|' . $un;
            $acc[$k]['unidad']   = $un;
            $acc[$k]['proyecto'] = (string)$r['proyecto'];
            $acc[$k]['cat']      = (int)$r['categoria'];
            $acc[$k]['deals'][(int)$r['deal_id']] = true;
            $acc[$k]['ultima']   = max((int)($acc[$k]['ultima'] ?? 0), (int)$r['ultima_vez']);
        }
    }
    $q->closeCursor();
    $out = [];
    foreach ($acc as $k => $x) {
        $f = vend_ficha($units, $x['unidad'], $x['cat']);
        $out[] = ['unidad' => $x['unidad'], 'proyecto' => $x['proyecto'], 'tipo' => $f['tipo'] ?? '',
                  'estado_unidad' => $etapa[$k] ?? '', 'clientes_que_cotizaron' => count($x['deals']),
                  'ultima_cotizacion_utc' => gmdate('c', $x['ultima'])];
    }
    usort($out, fn($a, $b) => [$b['clientes_que_cotizaron'], $b['ultima_cotizacion_utc']] <=> [$a['clientes_que_cotizaron'], $a['ultima_cotizacion_utc']]);
    vapi_fin(200, ['ok' => true, 'dias' => $dias, 'unidades' => count($out), 'resumen' => $out]);
}

// ── consultar ───────────────────────────────────────────────────────────────
if (!$perilla && !$admin) vapi_fin(409, ['ok' => false, 'error' => 'avisos apagados']);
$todo   = !empty($_GET['todo']);
$espera = max(0, (int)inv_cfg('avisos_espera_horas', 48)) * 3600;

$ventas = []; $resumen = ['registradas' => 0, 'ventas' => 0, 'candidatos' => 0, 'avisar' => 0, 'ya_compro' => 0,
                          'sin_telefono' => 0, 'sin_contacto' => 0, 'repetido' => 0];
/* Sin ?todo: TODAS las ventas que tengan algun aviso pendiente, por viejas que sean. Antes
   eran las ultimas 100 ventas: un pendiente de la venta 101 desaparecia de la lista sin
   haberse enviado ni anulado. Con ?todo (administracion) siguen siendo las ultimas 100. */
$sqlVentas = $todo
    ? "SELECT * FROM vendidas WHERE estado = 'firme' ORDER BY cuando DESC LIMIT 100"
    : "SELECT v.* FROM vendidas v WHERE v.estado = 'firme' AND EXISTS (SELECT 1 FROM vendidas_avisos a
         WHERE a.unidad = v.unidad AND a.comprador_deal = v.comprador_deal AND a.estado = 'pendiente')
       ORDER BY v.cuando DESC";
foreach ($d->query($sqlVentas)->fetchAll(PDO::FETCH_ASSOC) as $v) {
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
        // asesor y asesor_id son lo mismo (ASSIGNED_BY_ID del deal que cotizo); asesor_id es
        // el nombre que pidio el conector, asesor queda para no romper a quien ya lo lee.
        $lista[] = ['deal' => (int)$x['deal_id'], 'asesor' => (int)$x['asesor_id'], 'asesor_id' => (int)$x['asesor_id'],
                    'cotizo_utc' => gmdate('c', (int)$x['ultima_cotizacion']), 'veces' => (int)$x['veces'],
                    'estado' => $r['estado'], 'nombre' => $r['nombre'], 'celular' => $r['telefono'],
                    'aviso' => $x['estado']];
    }
    $catId = (int)($v['categoria'] ?? 0);
    $alt   = vend_alternativas($units, (string)$v['unidad'], $catId);
    $ficha = vend_ficha($units, (string)$v['unidad'], $catId) ?? [];
    $ventas[] = [
        'unidad' => $v['unidad'], 'proyecto' => (string)($proyectos[(string)$catId] ?? ''),
        'tipo' => $ficha['tipo'] ?? '', 'edificio' => $ficha['edificio'] ?? '', 'piso' => $ficha['piso'] ?? '',
        'm2' => $ficha['m2'] ?? '',
        'comprador_deal' => (int)$v['comprador_deal'], 'vendida_utc' => gmdate('c', (int)$v['cuando']),
        // Abre los planos de la vendida y de sus alternativas. '' si el proyecto no tiene pagina.
        'link_disponibilidad' => vend_link_disponibilidad($catId, array_merge([(string)$v['unidad']], array_column($alt, 'codigo'))),
        'alternativas' => $alt,
        'clientes' => $lista,
    ];
    $resumen['ventas']++;
}
vapi_fin(200, ['ok' => true, 'envio_aprobado' => $perilla, 'resumen' => $resumen, 'ventas' => $ventas]);
