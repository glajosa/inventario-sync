<?php
/**
 * diag-campos.php — TEMPORAL, solo lectura. Busca los códigos reales de los
 * campos del deal por su ETIQUETA visible, para no adivinar sobre datos de un
 * cliente real. Se borra al terminar.
 */
declare(strict_types=1);
require_once __DIR__ . '/campolib.php';
header('Content-Type: text/plain; charset=utf-8');

$esperado = (string)getenv('OUTBOUND_TOKEN');
if ($esperado === '' || !hash_equals($esperado, (string)($_GET['token'] ?? ''))) {
    http_response_code(403); exit('forbidden');
}

$r = bx('crm.deal.fields', []);
if (!$r['ok']) { echo "ERROR: {$r['error']}\n"; exit; }

$buscar = ['proyectos 1', 'valor del activo', 'activo comprado', 'monto', 'moneda', 'opportunity', 'currency'];
foreach (($r['result'] ?? []) as $code => $f) {
    $titulo = strtolower((string)($f['title'] ?? $f['listLabel'] ?? ''));
    foreach ($buscar as $b) {
        if (strpos($titulo, $b) !== false) {
            echo "$code  [{$f['type']}]  " . ($f['title'] ?? '?') . "\n";
            break;
        }
    }
}

// también el deal real de la captura, para ver el VALOR ya puesto en cada campo
if (!empty($_GET['deal'])) {
    $d = bx('crm.deal.get', ['id' => (int)$_GET['deal']]);
    echo "\n--- deal " . (int)$_GET['deal'] . " ---\n";
    if ($d['ok']) {
        foreach (($d['result'] ?? []) as $k => $v) {
            if (is_scalar($v) && $v !== '' && $v !== null) echo "$k = $v\n";
        }
    } else echo "ERROR: {$d['error']}\n";
}

// ?campo=UF_CRM_XXX -> detalle completo de un campo puntual (tipo, enum, etc)
if (!empty($_GET['campo'])) {
    $r2 = bx('crm.deal.fields', []);
    $c  = (string)$_GET['campo'];
    $f  = $r2['result'][$c] ?? null;
    echo "\n--- campo $c ---\n";
    if (!$f) { echo "no existe\n"; exit; }
    echo "title: " . ($f['title'] ?? '?') . "\n";
    echo "type:  " . ($f['type'] ?? '?') . "\n";
    if (!empty($f['items'])) {
        echo "opciones:\n";
        foreach ($f['items'] as $op) echo "  {$op['ID']} = {$op['VALUE']}\n";
    }
}
