<?php
/**
 * conciliar-cron.php — el latido que mantiene el buzón al día y suelta la cola.
 * ---------------------------------------------------------------------------
 * Corre cada 5 minutos y hace DOS trabajos con UNA sola llamada a Bitrix:
 *
 *   1. PUBLICA lo que ya cumplió su espera y cuya reserva sigue viva.
 *   2. RETIRA del buzón las historias de unidades que se liberaron.
 *
 * Las dos necesitan la misma respuesta —"¿qué unidades están en RESERVA ahora
 * mismo?"— así que se pregunta una vez y se usa para las dos. 1 llamada cada 5
 * min = 288 al día; el portal hace decenas de miles.
 *
 * 🔴 Por que 5 minutos y no 1: la espera de la cola es de 5 min (COLA_ESPERA_SEG).
 * Correr mas seguido no publica antes —la historia no ha madurado— solo gasta
 * llamadas. Correr cada 15 haria esperar hasta 20 min a una historia. 5 empareja
 * el latido con la ventana.
 *
 * Si la lectura de Bitrix falla, NO se toca nada: hist_codigos_en_reserva()
 * devuelve null y el bloque de historia.php frena. Un fallo de lectura no puede
 * parecerse a "no hay ninguna reserva", que vaciaria el buzon entero.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('solo cli'); }

/* ── EL LATIDO ────────────────────────────────────────────────────────────────
   Se escribe ANTES de hacer nada, y por eso sirve: separa "el cron no corre" de
   "el cron corre y falla". Sin esto los dos se ven igual desde afuera —nada pasa—
   y se puede perder una tarde arreglando lo que no estaba roto.
   Se lee con historia.php?latido=1. Vive en /data, que es volumen: sobrevive al
   despliegue, asi que tambien dice hace cuanto que no corre. */
$ruta   = rtrim((string)(getenv('DATA_DIR') ?: '/data'), '/') . '/conciliar-latido.json';
$latido = ['ultima' => date('c'), 'sapi' => PHP_SAPI];
@file_put_contents($ruta, json_encode($latido));

/* 🔴 El sello de "termine" va en un register_shutdown_function y NO despues del
   require: historia.php cierra con `exit`, asi que cualquier linea escrita despues
   del require no se ejecuta NUNCA. Tal como estaba, el latido decia "murio en el
   medio" siempre — un diagnostico que miente es peor que no tenerlo, porque manda
   a buscar una falla que no existe. */
register_shutdown_function(function () use ($ruta, $latido) {
    $latido['fin'] = date('c');
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true))
        $latido['murio'] = $e['message'];      // ahora "no termino" significa algo
    @file_put_contents($ruta, json_encode($latido));
});

$_GET = ['token' => (string)getenv('OUTBOUND_TOKEN'), 'conciliar' => 1];
require __DIR__ . '/historia.php';
