<?php
/**
 * DRENAR LA COLA DEL BOTÓN "NO CONTESTÓ".
 *
 * Reproduce las pulsaciones que quedaron encoladas porque Bitrix estaba
 * saturado. Pedido del usuario (9-sep-2026): *"que se cree esa actividad
 * automáticamente con el formato correcto y la hora correcta, el día correcto...
 * no que después tengan que aplastar"*.
 *
 * ⭐⭐ CADA PULSACIÓN SE REPRODUCE CON SU PROPIA HORA. `llamada_procesar_resultado`
 * ya recibe la fecha como parámetro, así que se le pasa el `now_ts` guardado: la
 * actividad queda con el día y la hora en que el vendedor apretó, no con la del
 * drenado. Sin esto la llamada aparecería a la medianoche siguiente y la escalera
 * de gestión calificaría mal al asesor.
 *
 * ⚠ ESCRIBE CON LA CREDENCIAL DEL SERVICIO (BITRIX_WEBHOOK), no con el token del
 * vendedor: cuando la cola se drena, ese token ya caducó. La identidad del asesor
 * NO se pierde — viaja como dato (`bitrixUserId`) y el servicio la pone en
 * RESPONSIBLE_ID. La actividad sigue siendo suya.
 *
 * ⚠ SE DETIENE AL PRIMER FALLO DE BITRIX. Si el portal sigue saturado, insistir
 * con las otras nueve lo empuja más. Se espera al siguiente turno del cron.
 *
 * Uso:  php drenar-no-contesto.php [--lote=10] [--seco]
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("solo CLI\n"); }

/* ⚠ VIVE EN LA RAIZ, NO EN bin/.
 * `bin` esta en .dockerignore (son herramientas de despliegue que no deben entrar
 * a la imagen). Un trabajador puesto ahi NO existe dentro del contenedor: el bucle
 * lo invocaria cada 2 minutos y fallaria en silencio para siempre. */
$root = __DIR__;
require_once $root . '/lib/llamada-resultado-service.php';
require_once $root . '/lib/llamada-idempotencia.php';
require_once $root . '/lib/cola-no-contesto.php';

$lote = 10; $seco = false;
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--lote=(\d{1,3})$/', $a, $m)) { $lote = max(1, (int)$m[1]); continue; }
    if ($a === '--seco') { $seco = true; continue; }
    exit("argumento desconocido: $a\n");
}

$dataDir = trim((string)getenv('DATA_DIR'));
$stageEnv = trim((string)getenv('NO_INTEREST_STAGE_ID'));
$webhook = rtrim(trim((string)getenv('BITRIX_WEBHOOK')), '/');
if ($dataDir === '' || $webhook === '') {
    exit("falta DATA_DIR o BITRIX_WEBHOOK en el entorno\n");
}

/** El llamador del SERVICIO, con la misma forma que espera llamada_bx_result(). */
$bx = static function (string $method, array $params) use ($webhook): array {
    if (preg_match('/^[a-z0-9_.]+$/iD', $method) !== 1) return ['ok' => false, 'error' => 'not-configured'];
    $ch = curl_init($webhook . '/' . $method . '.json');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($params),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_CONNECTTIMEOUT => 8,
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $err !== '') return ['ok' => false, 'error' => 'network-error', 'desc' => $err];
    $j = json_decode((string)$body, true);
    if (!is_array($j)) return ['ok' => false, 'error' => 'bad-json'];
    if (isset($j['error'])) {
        // 503 y QUERY_LIMIT_EXCEEDED son "el portal esta lleno": se reintenta luego
        return ['ok' => false, 'error' => (string)$j['error'], 'desc' => (string)($j['error_description'] ?? '')];
    }
    if ($code >= 500) return ['ok' => false, 'error' => 'timeout', 'desc' => 'HTTP ' . $code];
    return ['ok' => true, 'result' => $j['result'] ?? null];
};

$db = cola_nc_db($dataDir);
// las resueltas viejas se van: ahora toda pulsacion deja una fila
$borradas = cola_nc_limpiar($db, 7);
if ($borradas > 0) printf("limpieza: %d filas resueltas de mas de 7 dias\n", $borradas);
$conteo = cola_nc_conteo($db);
printf("cola: encoladas %d · hechas %d · fallidas %d\n",
    $conteo['encolada'], $conteo['hecha'], $conteo['fallida']);

$pendientes = cola_nc_pendientes($db, $lote);
if (!$pendientes) { echo "nada que drenar\n"; exit(0); }
if ($seco) {
    foreach ($pendientes as $p)
        printf("  [seco] %s · pulsada %s · intentos %d · %s\n", $p['request_id'],
            cola_nc_ec((int)$p['now_ts']), (int)$p['intentos'], $p['motivo']);
    exit(0);
}

/* ⭐ El trabajo real vive en cola_nc_drenar() (lib/cola-no-contesto.php), no acá.
 * Este archivo solo arma el entorno: la prueba ejercita la MISMA función. */
$store = new LlamadaIdempotenciaStore($dataDir);
$r = cola_nc_drenar($db, $bx, $store, $stageEnv, $lote,
    static function (string $linea): void { echo $linea . "\n"; });
printf("drenadas %d · con fallo %d%s\n", $r['hechas'], $r['fallidas'],
    $r['cortado'] ? ' · lote cortado, Bitrix sigue apretado' : '');
