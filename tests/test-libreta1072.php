<?php
/**
 * Lectura del 1072 desde la libreta: que use la copia SOLO si esta completa, y que en
 * cualquier otro caso devuelva null con el motivo (el que llama cae a Bitrix).
 */
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../libreta1072.php';

$dir = sys_get_temp_dir() . '/l1072-' . bin2hex(random_bytes(4));
@mkdir($dir, 0775, true);
putenv("DATA_DIR=$dir");
putenv('LIBRETA_ON');

$item = fn(int $id, $p = null) => ['dato' => ['id' => $id, 'title' => "U-$id", 'parentId2' => $p], 'borrada_at' => null];
/* Libreta falsa: 3 unidades en 2 paginas. $cambio deja romper UNA cosa por caso. */
$falsa = function (array $cambio = []) use ($item) {
    return function (string $ruta) use ($cambio, $item) {
        $pag2 = strpos($ruta, 'cursor=') !== false;
        $deal = strpos($ruta, 'deal=') !== false;              // las de UN deal: una pagina
        $r = ['status' => 200, 'err' => '', 'h' => ['x-libreta-total' => $deal ? '1' : '3'],
              'json' => ['items' => $deal ? [$item(1, 900)] : ($pag2 ? [$item(3)] : [$item(1, 900), $item(2)]),
                         'siguiente' => ($pag2 || $deal) ? null : 'c2', 'copia_completa_at' => '2026-10-06T20:25:47+00:00']];
        foreach ($cambio as $k => $v) {
            if ($k === 'status') $r['status'] = $v;
            elseif ($k === 'sin_total') unset($r['h']['x-libreta-total']);
            elseif ($k === 'total') $r['h']['x-libreta-total'] = (string)$v;
            elseif ($k === 'incompleta') $r['json']['copia_completa_at'] = null;
            elseif ($k === 'cursor_eterno') $r['json']['siguiente'] = 'otra';
        }
        return $r;
    };
};

$m = '';
$u = l1072_todas($m, $falsa());
test_same(3, is_array($u) ? count($u) : -1, 'copia completa en 2 paginas: trae las 3');
test_same('', $m, 'sin motivo cuando sirve');
test_same(null, $u[1]['parentId2'], 'parentId2 null queda null (no 0)');
test_same(900, $u[0]['parentId2'], 'y el atado conserva su deal');

$casos = [
    'libreta caida (503)'          => [['status' => 503], 'http 503'],
    'sin cabecera de total'        => [['sin_total' => 1], 'sin cabecera'],
    'faltan unidades'              => [['total' => 4], 'traidas 3 de 4'],
    'copia incompleta'             => [['incompleta' => 1], 'incompleta'],
    'cursor que no termina'        => [['cursor_eterno' => 1], 'cursor no termino'],
];
foreach ($casos as $nombre => [$cambio, $esperado]) {
    $m = '';
    $u = l1072_todas($m, $falsa($cambio));
    test_same(null, $u, "$nombre -> null (cae a Bitrix)");
    test_same(true, strpos($m, $esperado) !== false, "$nombre -> motivo dice '$esperado': $m");
}

// la perilla: config.json manda, y env LIBRETA_ON=0 tambien apaga
file_put_contents("$dir/config.json", json_encode(['libreta_1072' => 0]));
$m = ''; test_same(null, l1072_todas($m, $falsa()), 'perilla libreta_1072=0 -> no usa la libreta');
test_same('apagada (perilla)', $m, 'y lo dice');
file_put_contents("$dir/config.json", json_encode(['libreta_1072' => 1]));
putenv('LIBRETA_ON=0');
$m = ''; test_same(null, l1072_todas($m, $falsa()), 'env LIBRETA_ON=0 tambien apaga');
putenv('LIBRETA_ON');
$m = ''; test_same(3, count((array)l1072_todas($m, $falsa())), 'perilla en 1 -> vuelve a la libreta');

// unidades de un deal
$m = ''; $d = l1072_de_deal(900, $m, $falsa());
test_same([1], array_map(fn($x) => (int)$x['id'], (array)$d), 'de_deal con copia completa trae la unidad del deal');
$m = ''; test_same(null, l1072_de_deal(900, $m, $falsa(['cursor_eterno' => 1])), 'de_deal con mas de una pagina -> null');
$m = ''; test_same(null, l1072_de_deal(900, $m, $falsa(['incompleta' => 1])), 'de_deal con copia incompleta -> null');
$m = ''; test_same(null, l1072_de_deal(900, $m, $falsa(['status' => 410])), 'de_deal 410 -> null');

// el contador cuenta y guarda el motivo
l1072_contar('prueba', true);
l1072_contar('prueba', false, 'http 503');
$c = json_decode((string)file_get_contents("$dir/libreta1072.json"), true);
$hoy = $c[gmdate('Y-m-d')]['prueba'] ?? [];
test_same(1, (int)($hoy['libreta'] ?? 0), 'contador: 1 de libreta');
test_same(1, (int)($hoy['bitrix'] ?? 0), 'contador: 1 a bitrix');
test_same('http 503', (string)($hoy['ultimo_motivo'] ?? ''), 'contador: guarda el motivo');

// antes de escribir, el dato fresco de Bitrix decide (la copia va ~30 s atrasada)
test_same('escribir',  l1072_decidir_set(0, '700'),     'set: en Bitrix esta suelta -> se ata');
test_same('escribir',  l1072_decidir_set(650, '700'),   'set: en Bitrix es de otro deal -> se corrige');
test_same('ya_estaba', l1072_decidir_set(700, '700'),   'set: en Bitrix YA esta bien -> no se reescribe (no despierta handlers)');
test_same('sin_dato',  l1072_decidir_set(null, '700'),  'set: sin dato fresco no se escribe');
test_same('escribir',  l1072_decidir_clear(700, '700'), 'clear: sigue atada al mismo deal -> se suelta');
test_same('ya_suelta', l1072_decidir_clear(0, '700'),   'clear: ya estaba suelta -> no se reescribe');
test_same('cambio',    l1072_decidir_clear(800, '700'), 'clear: alguien la movio a otro deal despues de la copia -> no se toca');
test_same('sin_dato',  l1072_decidir_clear(null, '700'),'clear: sin dato fresco no se escribe');

// etapas de reconcile decididas con la copia
$u = ['id' => 10, 'parentId2' => 700, 'stageId' => 'DT1072_33:X'];
test_same('releer', l1072_decidir_etapa(null, null, 'FIRMADO', '700'),        'etapa: la copia no tiene la unidad -> como antes (Bitrix)');
test_same('saltar', l1072_decidir_etapa($u, null, 'FIRMADO', '700'),          'etapa: segun la copia ya esta -> 0 llamadas');
test_same('releer', l1072_decidir_etapa($u, 'DT1072_33:F', 'FIRMADO', '700'), 'etapa: la copia dice que difiere -> relee y escribe');
test_same('saltar', l1072_decidir_etapa($u, 'DT1072_33:D', 'DISPONIBLE', '999'), 'liberar: la copia dice que es de OTRO deal -> no se le quita');
test_same('releer', l1072_decidir_etapa($u, 'DT1072_33:D', 'DISPONIBLE', '700'), 'liberar: es de este deal -> relee y suelta');
test_same('releer', l1072_decidir_etapa(['id' => 10, 'parentId2' => null] + $u, 'DT1072_33:D', 'DISPONIBLE', '700'), 'liberar: no es de nadie -> relee y suelta');
test_same('releer', l1072_decidir_etapa($u, 'DT1072_33:D', 'DISPONIBLE', ''), 'cobranzas (sin deal dueno) difiere -> relee');

$porDeal = [700 => [10, 11]];
test_same([10, 11, 12, 13, 14], l1072_unidades_del_deal(['ID' => '700', 'UF_X' => '12, 13;14', 'PARENT_ID_1072' => null], $porDeal, 'UF_X'),
          'unidades del deal: atadas + campo Inventario');
test_same([15, 16], l1072_unidades_del_deal(['ID' => '800', 'UF_X' => ['15', '16'], 'PARENT_ID_1072' => '16'], $porDeal, 'UF_X'),
          'unidades del deal: campo como lista y PARENT_ID_1072 sin repetir');
test_same([17], l1072_unidades_del_deal(['ID' => '802', 'UF_X' => '', 'PARENT_ID_1072' => '17'], $porDeal, 'UF_X'),
          'unidades del deal: la que solo esta en PARENT_ID_1072 tambien cuenta');
test_same([], l1072_unidades_del_deal(['ID' => '801', 'UF_X' => 'abc', 'PARENT_ID_1072' => '0'], $porDeal, 'UF_X'),
          'unidades del deal: basura y 0 no son unidades');

// deals por etapa: cuadre con el total y que la etapa sea la pedida
$dl = function (array $cambio = []) {
    return function (string $ruta) use ($cambio) {
        $r = ['status' => 200, 'err' => '', 'h' => ['x-libreta-total' => '2'],
              'json' => ['items' => [['dato' => ['ID' => '1', 'STAGE_ID' => 'C44:WON']], ['dato' => ['ID' => '2', 'STAGE_ID' => 'C44:WON']]], 'siguiente' => null]];
        if (isset($cambio['total'])) $r['h']['x-libreta-total'] = (string)$cambio['total'];
        if (isset($cambio['otra'])) $r['json']['items'][1]['dato']['STAGE_ID'] = 'C44:NEW';
        if (isset($cambio['status'])) $r['status'] = $cambio['status'];
        return $r;
    };
};
$m = ''; test_same(2, count((array)l1072_deals(44, 'C44:WON', $m, $dl())), 'deals: trae los 2 de la etapa');
$m = ''; test_same(null, l1072_deals(44, 'C44:WON', $m, $dl(['total' => 3])), 'deals: faltan -> null');
$m = ''; test_same(null, l1072_deals(44, 'C44:WON', $m, $dl(['otra' => 1])), 'deals: vino otra etapa (filtro ignorado) -> null');
$m = ''; test_same(null, l1072_deals(44, 'C44:WON', $m, $dl(['status' => 500])), 'deals: libreta caida -> null');
$m = ''; test_same(2, count((array)l1072_deals(44, '', $m, $dl())), 'deals: sin etapa trae todo el embudo');
$dlOtro = function (string $ruta) { return ['status' => 200, 'err' => '', 'h' => ['x-libreta-total' => '1'],
    'json' => ['items' => [['dato' => ['ID' => '9', 'STAGE_ID' => 'C48:WON', 'CATEGORY_ID' => '48']]], 'siguiente' => null]]; };
$m = ''; test_same(null, l1072_deals(44, '', $m, $dlOtro), 'deals: vino otro embudo (filtro ignorado) -> null');

echo "libreta1072: ok\n";
