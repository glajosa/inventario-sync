<?php
/**
 * LATIDO — "¿esto está actualizado?" del inventario, contestado en fechas de archivo.
 *
 * Pedido del usuario (2026-09-10): *"quiero todo detallado, cada php, y que diga cuándo fue
 * la última vez que se actualizó… quiero tener tranquilidad más que todo."*
 *
 * Lo sondea el monitor (bitrix-control, api/latidos.php). NO llama a Bitrix: solo mira
 * fechas de archivo y cuenta filas de la cola. Es el vigilante, no puede ser parte del
 * problema que vigila. Sin dato, la edad va `null` y el panel dice "no sé" — nunca 0.
 *
 * Perilla: LATIDO_OFF=1 -> 503 y el monitor lo pinta "apagado a propósito".
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

const LATIDO_KEY = '2e225115e02e675cfee28eb2a1a9cecdf335baf60ea8f0e4';
if ((string)($_GET['key'] ?? '') !== LATIDO_KEY) { http_response_code(403); echo json_encode(['error'=>'forbidden']); exit; }
if (getenv('LATIDO_OFF') === '1') {
    http_response_code(503);
    echo json_encode(['sistema'=>'inventario','apagado'=>true,'motivo'=>'LATIDO_OFF=1 en el servicio'], JSON_UNESCAPED_UNICODE);
    exit;
}

$DATA = getenv('DATA_DIR') ?: '/data';

function iv_pieza(string $id, string $tipo, string $que, ?string $ruta, ?int $cada, string $detalle = '', string $nota = '', string $alerta = ''): array {
    $mt = null; $legible = null; $existe = false;
    if ($ruta !== null) {
        $existe = file_exists($ruta);
        if ($existe) {
            $m = @filemtime($ruta);
            if ($m !== false) $mt = (int)$m;
            $legible = is_readable($ruta);
            if ($legible === false) $nota = trim($nota . ' · el servidor web NO puede leerlo (permisos)');
        }
    }
    $p = ['id'=>$id,'tipo'=>$tipo,'que'=>$que,'senal'=>$ruta,'existe'=>$existe,'legible'=>$legible,
        'actualizado'=>$mt === null ? null : date('c', $mt),
        'edad_s'=>$mt === null ? null : max(0, time() - $mt),
        'cada_s'=>$cada,'detalle'=>$detalle,'nota'=>$nota];
    if ($alerta !== '') $p['alerta'] = $alerta;
    return $p;
}

$piezas = [];

/* La conciliación deja su propio latido con la hora de inicio y de fin: si el bucle del
 * contenedor muere, esta fecha se congela y es lo único que lo delata. El bucle corre cada
 * 300 s (entrypoint.sh). */
$piezas[] = iv_pieza('conciliacion', 'trabajo',
    'La conciliación con el inventario: revisa lo que cambió y lo acomoda',
    "$DATA/conciliar-latido.json", 300,
    (function ($f) {
        $j = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
        return is_array($j) ? ('última: ' . substr((string)($j['ultima'] ?? '?'), 11, 8) . ' UTC') : '';
    })("$DATA/conciliar-latido.json"));

/* La libreta: una ficha por deal para no volver a preguntarle a Bitrix. Se mira la fecha de
 * la CARPETA (una consulta) en vez de las 7.836 fichas. */
$dc = "$DATA/dealcache";
$nDc = is_dir($dc) ? max(0, count((array)@scandir($dc)) - 2) : 0;
$piezas[] = iv_pieza('libreta-de-deals', 'libreta',
    'La libreta de deals: una ficha por negocio para no volver a preguntarle a Bitrix',
    $dc, null, number_format($nDc, 0, ',', '.') . ' fichas');

/* 🔴 LA COLA DEL BOTÓN "NO CONTESTÓ". Cuando el portal está saturado la pulsación del
 * vendedor se GUARDA acá y se crea después. Si nadie la drena, son llamadas que el vendedor
 * cree haber registrado y no existen. Por eso se cuenta lo ENCOLADO, no solo la fecha: el
 * archivo se escribe igual cuando entra algo, así que su frescura no dice nada. */
$colaF = "$DATA/cola-no-contesto.sqlite";
$colaDet = ''; $colaAlerta = ''; $colaNota = '';
if (is_file($colaF) && class_exists('SQLite3')) {
    try {
        $db = new SQLite3($colaF, SQLITE3_OPEN_READONLY);
        $db->busyTimeout(2000);
        $r = $db->querySingle("SELECT COUNT(*) c, COALESCE(MIN(creada),0) v FROM cola_no_contesto WHERE estado = 'encolada'", true);
        /* 🔴 LAS QUE YA NO SE VAN A CREAR SOLAS. Esta pieza miraba unicamente las
         * 'encolada', o sea lo que todavia tiene esperanza. Las que se dieron por
         * perdidas no aparecian en ningun lado: medido el 17-sep-2026, 7 pulsaciones
         * llevaban hasta 8 DIAS muertas y el panel decia 0. Un termometro que solo
         * mira lo que va bien no es un termometro.
         * Cada una es una llamada que el vendedor hizo y que no le quedo registrada. */
        $m = $db->querySingle("SELECT COUNT(*) c FROM cola_no_contesto WHERE estado IN ('bloqueada','fallida')", true);
        $deudas = $db->query("SELECT estado, input_json, ultimo_error FROM cola_no_contesto
                              WHERE estado IN ('bloqueada','fallida') ORDER BY creada DESC LIMIT 5");
        $detalles = [];
        while ($deudas && ($d = $deudas->fetchArray(SQLITE3_ASSOC))) {
            $deal = (int)(json_decode((string)($d['input_json'] ?? ''), true)['dealId'] ?? 0);
            $detalles[] = ($d['estado'] === 'bloqueada' ? 'sin acceso' : 'datos') . " · deal $deal · "
                . mb_substr((string)($d['ultimo_error'] ?? ''), 0, 70);
        }
        $db->close();
        $n = (int)($r['c'] ?? 0); $viejo = (int)($r['v'] ?? 0); $perdidas = (int)($m['c'] ?? 0);
        $colaDet = $n . ' pulsación(es) esperando turno'
            . ($perdidas > 0 ? ' · ' . $perdidas . ' SIN CREAR' : '');
        if ($n > 0 && $viejo > 0 && (time() - $viejo) > 1800) {
            $colaAlerta = $n . ' pulsación(es) del botón sin crear; la más vieja hace '
                . round((time() - $viejo) / 60) . ' min';
        }
        if ($perdidas > 0) {
            /* El veredicto va en la alerta, no un "revisalo": el portal respondio y
             * nego el acceso con la credencial del servicio, asi que no es saturacion
             * ni la sesion del vendedor. */
            $colaAlerta = trim($colaAlerta . ' · ' . $perdidas
                . ' pulsación(es) que NO se van a crear solas — no es saturación: '
                . implode(' | ', $detalles), ' ·');
        }
    } catch (Throwable $e) {
        /* Una lectura que falla NO puede parecerse a "cero en cola": se dice que no se pudo. */
        $colaDet = ''; $colaNota = 'no se pudo leer la cola: ' . $e->getMessage();
    }
} elseif (!is_file($colaF)) {
    $colaNota = 'todavía no existe la base de la cola';
}
$piezas[] = iv_pieza('cola-no-contesto', 'libreta',
    'La cola del botón "No contestó": lo que se guardó porque el portal estaba saturado',
    $colaF, null, $colaDet, $colaNota, $colaAlerta);

$piezas[] = iv_pieza('bucle-del-contenedor', 'trabajo',
    'El bucle propio que hace de reloj (acá el cron del sistema no ejecuta)',
    "$DATA/cron.log", 300);

$piezas[] = iv_pieza('catalogo', 'libreta',
    'El catálogo del selector (unidades y precios ya resueltos)',
    "$DATA/selector_cache.json", null);

$piezas[] = iv_pieza('permisos', 'libreta',
    'La lista de quién puede usar la app dentro de Bitrix',
    "$DATA/allowlist.json", null);

echo json_encode([
    'sistema' => 'inventario',
    'titulo'  => 'Inventario (SPA + cotizador)',
    /* No hay sello de build en esta imagen (lo verifiqué dentro del contenedor: no existe
     * IMAGE_BUILD ni version.txt). Se usa la fecha del propio archivo del código, que al
     * menos dice de cuándo es lo que está corriendo. */
    'ver'     => date('Ymd-Hi', (int)(@filemtime(__FILE__) ?: 0)),
    'ts'      => date('c'),
    'piezas'  => $piezas,
], JSON_UNESCAPED_UNICODE);
