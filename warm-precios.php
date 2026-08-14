<?php
/**
 * inventario-sync — warm-precios.php
 * ---------------------------------------------------------------------------
 * Mantiene fresca la foto del inventario que usa el cotizador madre.
 *
 * La pantalla NO habla con Bitrix: lee el archivo que deja este cron. Así abre en
 * menos de un segundo y, sobre todo, no depende de que el portal tenga llamadas
 * disponibles en el instante en que a alguien se le ocurre mirar precios — que fue
 * exactamente lo que la tumbó con un QUERY_LIMIT_EXCEEDED.
 *
 * Solo lectura. Corre por cron; también por HTTP con ?token=OUTBOUND_TOKEN.
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);
require_once __DIR__ . '/campolib.php';
require_once __DIR__ . '/matrizlib.php';

$isHttp = PHP_SAPI !== 'cli';
if ($isHttp) {
    header('Content-Type: text/plain; charset=utf-8');
    $esperado = (string)getenv('OUTBOUND_TOKEN');
    if ($esperado === '' || !hash_equals($esperado, (string)($_GET['token'] ?? ''))) {
        http_response_code(403); exit('forbidden');
    }
}

// Barrido de fondo: freno amplio. Nadie está esperando esto en pantalla, así que
// vale más ir lento que quitarle llamadas al resto del portal.
$BX_FRENO_US = 350000;

foreach (mz_proyectos() as $cat => $_) {
    $cfg = mz_cfg((int)$cat);
    try {
        $u = mz_unidades($cfg);
        $f = mz_ruta_cache($cfg);
        file_put_contents($f . '.tmp', json_encode($u), LOCK_EX);
        rename($f . '.tmp', $f);
        $msg = "WARM-PRECIOS cat=$cat ok · " . count($u) . ' unidades';
    } catch (Throwable $e) {
        // Falla en silencio a propósito: la copia anterior sigue en disco y la
        // pantalla la sirve fechada. Reventar aquí solo borraría lo que sirve.
        $msg = "WARM-PRECIOS cat=$cat ERR · {$e->getMessage()}";
    }
    logline($msg);
    if ($isHttp) echo $msg . "\n";
}
