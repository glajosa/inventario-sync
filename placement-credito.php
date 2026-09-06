<?php
/**
 * placement-credito.php — enlaza (o quita) el botón «No contestó» de CRÉDITO Y
 * CONTADO en la barra de actividades del deal.
 *
 * 🔴 Usa la MISMA app local que cobranzas, a propósito. Bitrix permite varios
 * enlaces sobre el mismo placement mientras el HANDLER sea distinto, y acá son
 * las mismas tres asesoras: el protocolo lo dice textual — "las asesoras de
 * cobranzas siguen manejando crédito y contado: es parte de su trabajo".
 * Crear una tercera app obligaría a registrarla a mano en Bitrix sin ganar nada.
 * Lo que sí está separado es lo que importa: otro handler, otro título y otras
 * reglas, así que son dos botones independientes.
 *
 *   ?token=...&accion=ver | poner | quitar
 */
declare(strict_types=1);
require_once __DIR__ . '/lib/cobranza-appauth.php';

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

$esperado = (string)getenv('OUTBOUND_TOKEN');
if ($esperado === '' || !hash_equals($esperado, (string)($_GET['token'] ?? ''))) {
    http_response_code(403); exit('forbidden');
}

const PLACEMENT = 'CRM_DEAL_DETAIL_ACTIVITY';
const HANDLER   = 'https://galjosa-inventario-sync.pwluu1.easypanel.host/credito_nativo.php';
// Los tres botones viven en la misma barra. Sin un título que diga cuál es cuál,
// la asesora aprieta el equivocado sin darse cuenta — ya pasó con ventas.
const TITULO    = 'No contestó · Crédito';

function cre_mostrar(): void {
    $r = cob_app_bx('placement.get');
    if (!($r['ok'] ?? false)) { echo "  no se pudo listar: {$r['error']} " . ($r['desc'] ?? '') . "\n"; return; }
    $items = $r['result'] ?? [];
    if (!$items) { echo "  (esta app no tiene placements enlazados)\n"; return; }
    foreach ($items as $p) {
        echo '  - ' . (is_array($p) ? ($p['placement'] ?? '?') . '  ' . ($p['handler'] ?? '') : (string)$p) . "\n";
    }
}

$accion = (string)($_GET['accion'] ?? 'ver');
echo "Antes:\n"; cre_mostrar();

if ($accion === 'poner') {
    // unbind primero: re-correr esto ACTUALIZA el handler en vez de fallar con
    // ERROR_PLACEMENT_EXISTS. No toca el de cobranzas: distinto handler.
    cob_app_bx('placement.unbind', ['PLACEMENT' => PLACEMENT, 'HANDLER' => HANDLER]);
    $r = cob_app_bx('placement.bind', [
        'PLACEMENT'   => PLACEMENT,
        'HANDLER'     => HANDLER,
        'TITLE'       => TITULO,
        'DESCRIPTION' => 'Crédito y contado: registra el intento y deja la próxima llamada agendada',
        'OPTIONS'     => ['useBuiltInInterface' => 'Y'],
    ]);
    echo "\nbind: " . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
} elseif ($accion === 'quitar') {
    $r = cob_app_bx('placement.unbind', ['PLACEMENT' => PLACEMENT, 'HANDLER' => HANDLER]);
    echo "\nunbind: " . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
}
if ($accion !== 'ver') { echo "\nDespués:\n"; cre_mostrar(); }
