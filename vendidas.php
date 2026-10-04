<?php
/**
 * vendidas.php — "cotizaron y no compraron", para consultar.
 * ---------------------------------------------------------------------------
 *   GET  vendidas.php                         JSON con las ventas y a quien avisar
 *        cabecera X-Token: <OUTBOUND_TOKEN>   (o ?token= desde el navegador, uso interno)
 *   POST vendidas.php  accion=enviado&unidad=D-2-12&comprador=999&deal=555
 *        marca un aviso como enviado. Idempotente: marcarlo dos veces no cambia nada.
 *
 * 🔴 Lo que NO hace: no manda mensajes y no entrega TELEFONOS. Eso es para la
 * automatizacion de un tercero y necesita la aprobacion explicita de Jesua (datos de
 * clientes). Hasta entonces la perilla `avisos_vendidas` esta en 0 y marcar "enviado"
 * responde 409: nadie deberia estar enviando.
 *
 * Una venta solo aparece como LISTA para avisar cuando lleva `avisos_espera_horas`
 * firme (por defecto 48): una reserva que se cae en la primera tarde no debe convertirse
 * en un "se vendio la que te interesaba".
 */
declare(strict_types=1);
require_once __DIR__ . '/vendidaslib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$esperado = (string)getenv('OUTBOUND_TOKEN');
$tok = (string)($_SERVER['HTTP_X_TOKEN'] ?? ($_REQUEST['token'] ?? ''));
if ($esperado === '' || !hash_equals($esperado, $tok)) {
    http_response_code(403); exit(json_encode(['ok' => false, 'error' => 'forbidden']));
}

$d = vend_db();
if (!$d) { http_response_code(503); exit(json_encode(['ok' => false, 'error' => 'base no disponible'])); }

$perilla = (int)inv_cfg('avisos_vendidas', 0) === 1;
$dir = getenv('DATA_DIR') ?: '/data';
@file_put_contents($dir . '/vendidas_lecturas.log',
    gmdate('c') . ' ' . ($_SERVER['REQUEST_METHOD'] ?? '?') . ' ' . ($_SERVER['REMOTE_ADDR'] ?? '?')
    . ' ' . substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 80) . "\n", FILE_APPEND | LOCK_EX);

// ── marcar enviado ──────────────────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['accion'] ?? '') === 'enviado') {
    if (!$perilla) {
        http_response_code(409);
        exit(json_encode(['ok' => false, 'error' => 'avisos apagados: falta la aprobacion de Jesua']));
    }
    $u = $d->prepare("UPDATE vendidas_avisos SET estado = 'enviado', enviado_en = ?
                      WHERE unidad = ? AND comprador_deal = ? AND deal_id = ? AND estado = 'pendiente'");
    $u->execute([time(), strtoupper(trim((string)($_POST['unidad'] ?? ''))),
                 (int)($_POST['comprador'] ?? 0), (int)($_POST['deal'] ?? 0)]);
    exit(json_encode(['ok' => true, 'marcados' => $u->rowCount()]));
}

// ── consultar ───────────────────────────────────────────────────────────────
$espera = max(0, (int)inv_cfg('avisos_espera_horas', 48)) * 3600;
$ventas = [];
foreach ($d->query("SELECT * FROM vendidas ORDER BY cuando DESC LIMIT 200") as $v) {
    $a = $d->prepare("SELECT deal_id, asesor_id, cliente, ultima_cotizacion, veces, estado, enviado_en
                      FROM vendidas_avisos WHERE unidad = ? AND comprador_deal = ? ORDER BY ultima_cotizacion DESC");
    $a->execute([$v['unidad'], (int)$v['comprador_deal']]);
    $avisos = $a->fetchAll(PDO::FETCH_ASSOC);
    $a->closeCursor();
    $firmeDesde = time() - (int)$v['cuando'];
    $ventas[] = [
        'unidad'         => $v['unidad'],
        'comprador_deal' => (int)$v['comprador_deal'],
        'etapa'          => $v['etapa'],
        'vendida_utc'    => gmdate('c', (int)$v['cuando']),
        'origen'         => $v['origen'],
        'estado'         => $v['estado'],
        'lista_para_avisar' => $v['estado'] === 'firme' && $firmeDesde >= $espera,
        'avisos'         => array_map(fn($x) => [
            'deal'          => (int)$x['deal_id'],
            'asesor'        => (int)$x['asesor_id'],
            'cliente'       => $x['cliente'],
            'cotizo_utc'    => gmdate('c', (int)$x['ultima_cotizacion']),
            'veces'         => (int)$x['veces'],
            'estado'        => $x['estado'],
        ], $avisos),
    ];
}
echo json_encode([
    'ok'            => true,
    'envio_aprobado'=> $perilla,
    'etapas_venta'  => vend_etapas_venta(),
    'ventana_dias'  => (int)inv_cfg('avisos_ventana_dias', 90),
    'espera_horas'  => (int)($espera / 3600),
    'ventas'        => $ventas,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
