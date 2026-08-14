<?php
/**
 * eventos-calificacion.php — engancha (o suelta) los eventos que disparan el
 * recálculo de CALIFICACION DEL DEAL / DEL ASESOR en tiempo real.
 * ---------------------------------------------------------------------------
 * Vive acá y no en dashboardbitrix porque `event.bind` EXIGE contexto de
 * APLICACIÓN: con un webhook entrante responde WRONG_AUTH_TYPE. Esta app es la
 * única instalada con tokens OAuth. El handler que recibe los eventos sí vive
 * en dashboardbitrix, junto a la librería que calcula.
 *
 *   ?token=...&accion=ver      qué hay enganchado hoy (por defecto)
 *   ?token=...&accion=poner    engancha los cuatro eventos
 *   ?token=...&accion=quitar   los suelta
 *
 * ⚠ ONCRMACTIVITYADD llega por CADA actividad del portal, no solo las de
 * cat28. El handler descarta lo ajeno; el costo medido es ~700 actividades al
 * día × 3 llamadas = 0,2% del millón que ya mueve el portal.
 * ---------------------------------------------------------------------------
 */
declare(strict_types=1);
require_once __DIR__ . '/appauth.php';

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

$esperado = (string)getenv('OUTBOUND_TOKEN');
if ($esperado === '' || !hash_equals($esperado, (string)($_GET['token'] ?? ''))) {
    http_response_code(403); exit('forbidden');
}

// El secreto va EN LA URL registrada, no en el cuerpo del evento.
//
// Bitrix manda auth[application_token], que lo genera ÉL: compararlo contra un
// token propio no puede funcionar nunca (probado el 13-ago: los eventos
// llegaban y el handler los rechazaba con 403). Con el token en la URL, cada
// POST de Bitrix lo trae consigo y el handler lo valida sin depender de nada
// más. Se pasa por querystring al llamar a este script para no guardarlo acá.
const HANDLER_BASE = 'https://galjosa-dashboardbitrix.pwluu1.easypanel.host/api/recalificar.php';
$RECALIF = (string)($_GET['recalif'] ?? '');
if ($RECALIF === '' && ($_GET['accion'] ?? '') === 'poner') {
    http_response_code(400); exit("falta &recalif=<token del endpoint>\n");
}
define('HANDLER', HANDLER_BASE . ($RECALIF !== '' ? '?token=' . rawurlencode($RECALIF) : ''));
const EVENTOS = [
    'ONCRMACTIVITYADD',      // el vendedor registra una llamada  ← el importante
    'ONCRMACTIVITYUPDATE',   // la marca completada / la reprograma
    'ONCRMDEALUPDATE',       // cambia de etapa → semáforo
    'ONCRMDEALADD',          // nace el deal → NUEVO
    // El "Deshacer" del panel BORRA la actividad. Sin este evento el puntaje
    // se quedaría con la llamada fantasma hasta la pasada de la madrugada.
    'ONCRMACTIVITYDELETE',
];

function mostrar(): void {
    $r = app_bx('event.get');
    $hay = false;
    foreach (($r['result'] ?? []) as $e) {
        $ev = $e['event'] ?? '?';
        $h  = (string)($e['handler'] ?? '');
        if (stripos($h, 'recalificar') === false) continue;
        $hay = true;
        echo "  - {$ev}\n";
    }
    if (!$hay) echo "  (ninguno de calificación)\n";
    if (isset($r['error'])) echo "  error: {$r['error']} {$r['error_description']}\n";
}

$accion = (string)($_GET['accion'] ?? 'ver');
echo "Antes:\n"; mostrar();

if ($accion === 'poner') {
    foreach (EVENTOS as $ev) {
        // Se desengancha primero: re-correr esto actualiza en vez de duplicar
        // (bind repetido da ERROR_EVENT_BINDING_EXISTS y quedarían dos avisos
        // por cada actividad, o sea el doble de carga sobre el portal).
        app_bx('event.unbind', ['event' => $ev, 'handler' => HANDLER]);
        $r = app_bx('event.bind', ['event' => $ev, 'handler' => HANDLER]);
        printf("\n%-22s %s", $ev, json_encode($r, JSON_UNESCAPED_UNICODE));
    }
    echo "\n";
} elseif ($accion === 'quitar') {
    foreach (EVENTOS as $ev) {
        $r = app_bx('event.unbind', ['event' => $ev, 'handler' => HANDLER]);
        printf("\n%-22s %s", $ev, json_encode($r, JSON_UNESCAPED_UNICODE));
    }
    echo "\n";
}

if ($accion !== 'ver') { echo "\nDespués:\n"; mostrar(); }
