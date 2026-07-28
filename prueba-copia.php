<?php
/**
 * prueba-copia.php — TEMPORAL. Simula la copia que la automatización hace del
 * deal de Prospectos(28) al pipeline CLIENTES(44) en RESERVA, para validar que
 * la copia SÍ puede tomar la unidad que su propio original apartó.
 * Solo crea deals con título de prueba. SE BORRA al terminar.
 */
declare(strict_types=1);
require_once __DIR__ . '/campolib.php';
header('Content-Type: text/plain; charset=utf-8');

$esperado = (string)getenv('OUTBOUND_TOKEN');
if ($esperado === '' || !hash_equals($esperado, (string)($_GET['token'] ?? ''))) {
    http_response_code(403); exit('forbidden');
}

// ?apartados=1 -> qué unidades están apartadas hoy y por quién
if (!empty($_GET['apartados'])) {
    foreach (apartados_28() as $u => $a) {
        echo "unidad $u <- deal28 {$a['deal']}  contacto {$a['contacto']}  creado {$a['creado']}\n";
    }
    echo "\nregistro en disco: " . json_encode(apartados_puestos()) . "\n";
    exit;
}

// ?copiar=<dealId28> -> crea en CLIENTES(44)/RESERVA una copia con el MISMO
// contacto, con el campo Inventario VACÍO (el caso peor: la automatización no
// arrastra el campo de tipo propio). Debe adoptar el apartado.
$src = (int)($_GET['copiar'] ?? 0);
if ($src <= 0) exit("uso: ?token=...&copiar=<deal28>  |  ?token=...&apartados=1\n");

$g = bx('crm.deal.get', ['id' => $src]);
if (!$g['ok']) exit("origen no existe: {$g['error']}\n");
$d = $g['result'];
if ((int)($d['CATEGORY_ID'] ?? -1) !== PROSPECTOS_CAT) exit("el origen debe ser del pipeline 28\n");

$r = bx('crm.deal.add', ['fields' => [
    'TITLE'       => 'PRUEBA COPIA 28->44 - no usar',
    'CATEGORY_ID' => CLIENTES_CAT,
    'STAGE_ID'    => 'C44:NEW',
    'CONTACT_ID'  => (int)($d['CONTACT_ID'] ?? 0),
    'ASSIGNED_BY_ID' => (int)($d['ASSIGNED_BY_ID'] ?? 0),
    'OPPORTUNITY' => 0,
]]);
logline('PRUEBA copia 28->44 de ' . $src . ' -> ' . json_encode($r));
echo json_encode($r), "\n";
if ($r['ok']) echo "copia=" . $r['result'] . " contacto=" . (int)($d['CONTACT_ID'] ?? 0) . "\n";
