<?php
/**
 * "Cotizaron y no compraron": que la venta arme la lista correcta, una sola vez, sin el
 * comprador, sin confundir D-2-1 con D-2-12, y que una reserva caida la anule.
 */
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../vendidaslib.php';

$E = ['RESERVADO', 'FIRMADO', 'VENDIDO'];

// ── decisiones puras ─────────────────────────────────────────────────────────
test_same(true,  vend_es_venta('DISPONIBLE', 'RESERVADO', $E), 'disponible -> reservado es venta');
test_same(false, vend_es_venta('RESERVADO', 'FIRMADO', $E),   'reservado -> firmado NO es otra venta');
test_same(false, vend_es_venta('RESERVADO', 'RESERVADO', $E), 'misma etapa (un PVP editado) NO es venta');
test_same(false, vend_es_venta('', 'RESERVADO', $E),          'sin etapa de antes no se afirma una venta');
test_same(true,  vend_es_venta('BLOQUEADO', 'RESERVADO', $E), 'bloqueado -> reservado tambien es venta');
test_same(true,  vend_es_caida('RESERVADO', 'DISPONIBLE', $E), 'reservado -> disponible es caida');
test_same(false, vend_es_caida('DISPONIBLE', 'DISPONIBLE', $E), 'disponible -> disponible no es caida');
test_same(true,  vend_cotizo_unidad('D-2-21, D-2-12', 'D-2-12'), 'encuentra la unidad en la lista');
test_same(false, vend_cotizo_unidad('D-2-1, D-2-3', 'D-2-12'),   'D-2-1 no es D-2-12');
test_same(false, vend_cotizo_unidad('D-2-12', 'D-2-1'),          'D-2-12 no es D-2-1');

// ── con base de mentira ─────────────────────────────────────────────────────
$ruta = sys_get_temp_dir() . '/vend-' . bin2hex(random_bytes(4)) . '.sqlite';
$d = vend_db($ruta);
test_same(true, $d instanceof PDO, 'abre la base');
$d->exec("CREATE TABLE cotizaciones (huella TEXT PRIMARY KEY, deal_id INTEGER, asesor_id INTEGER,
          cliente TEXT, unidades TEXT, ultima_vez INTEGER, veces INTEGER, creada INTEGER,
          categoria INTEGER NOT NULL DEFAULT 0)");  // como la tabla real (cothist_migrar)
/* Una hora atras y no una fecha fija: el barrido usa la hora REAL, y con una venta
   "en 2027" todas las cotizaciones quedaban despues de ella. */
$ahora = time() - 3600;
$cot = $d->prepare('INSERT INTO cotizaciones (huella, deal_id, asesor_id, cliente, unidades, ultima_vez, veces, creada) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
$cot->execute(['h1', 501, 7, 'ANA',     'D-2-12',         $ahora - 3 * 86400, 2, $ahora - 3 * 86400]);  // cotizo
$cot->execute(['h2', 502, 8, 'BETO',    'D-2-11, D-2-12', $ahora - 10 * 86400, 1, $ahora - 10 * 86400]); // cotizo (en par)
$cot->execute(['h3', 503, 7, 'CARLA',   'D-2-1',          $ahora - 1 * 86400, 1, $ahora - 1 * 86400]);  // OTRA unidad
$cot->execute(['h4', 600, 9, 'COMPRA',  'D-2-12',         $ahora - 2 * 86400, 1, $ahora - 2 * 86400]);  // el comprador
$cot->execute(['h5', 504, 7, 'VIEJO',   'D-2-12',         $ahora - 200 * 86400, 1, $ahora - 200 * 86400]); // fuera de ventana
$cot->execute(['h6', 501, 7, 'ANA',     'D-2-12, D-2-13', $ahora - 1 * 86400, 1, $ahora - 1 * 86400]);  // misma persona, otra tabla

$cot->execute(['h7', 505, 7, 'DESPUES', 'D-2-12', $ahora + 600, 1, $ahora + 600]); // cotizo DESPUES de la venta
$n = vend_registrar($d, 'D-2-12', 600, 'RESERVADO', 'aviso', $ahora);
test_same(2, $n, 'arma 2 avisos: Ana y Beto (no Carla, no el comprador, no el de hace 200 dias)');
$deals = $d->query("SELECT deal_id FROM vendidas_avisos WHERE unidad='D-2-12' ORDER BY deal_id")
           ->fetchAll(PDO::FETCH_COLUMN);
test_same(['501', '502'], array_map('strval', $deals), 'son exactamente los deals 501 y 502');
$ana = $d->query("SELECT veces, ultima_cotizacion FROM vendidas_avisos WHERE deal_id=501")->fetch(PDO::FETCH_ASSOC);
test_same(3, (int)$ana['veces'], 'Ana junta sus dos tablas (2 + 1 veces)');
test_same($ahora - 86400, (int)$ana['ultima_cotizacion'], 'y se queda con su cotizacion mas reciente');

test_same(0, vend_registrar($d, 'D-2-12', 600, 'RESERVADO', 'barrido', $ahora), 'registrar dos veces no duplica');
test_same(2, (int)$d->query("SELECT COUNT(*) FROM vendidas_avisos")->fetchColumn(), 'siguen siendo 2');

// ── la reserva se cae ───────────────────────────────────────────────────────
$d->exec("UPDATE vendidas_avisos SET estado='enviado' WHERE deal_id=502");   // a Beto ya se le escribio
$r = vend_anular($d, 'D-2-12', $ahora + 3600);
test_same(1, $r['anulados'], 'la caida anula el pendiente (Ana)');
test_same([502], $r['ya_enviados'], 'y avisa que a Beto YA se le habia escrito');
test_same('anulada', (string)$d->query("SELECT estado FROM vendidas WHERE unidad='D-2-12'")->fetchColumn(),
          'la venta queda anulada');

// ── vuelve a venderse al mismo comprador: se reactiva ───────────────────────
vend_registrar($d, 'D-2-12', 600, 'RESERVADO', 'aviso', $ahora + 7200);
test_same('firme', (string)$d->query("SELECT estado FROM vendidas WHERE unidad='D-2-12'")->fetchColumn(),
          're-reservada vuelve a firme');
test_same('pendiente', (string)$d->query("SELECT estado FROM vendidas_avisos WHERE deal_id=501")->fetchColumn(),
          'el aviso anulado de Ana vuelve a pendiente');
test_same('enviado', (string)$d->query("SELECT estado FROM vendidas_avisos WHERE deal_id=502")->fetchColumn(),
          'el ya enviado sigue enviado');

// ── el barrido recupera la venta que no llego por aviso ─────────────────────
$viejas = [['id' => 1, 'codigo' => 'D-2-11', 'stage' => 'DISPONIBLE', 'dealId' => 0],
           ['id' => 2, 'codigo' => 'D-2-1',  'stage' => 'DISPONIBLE', 'dealId' => 0]];
$nuevas = [['id' => 1, 'codigo' => 'D-2-11', 'stage' => 'RESERVADO',  'dealId' => 700],
           ['id' => 2, 'codigo' => 'D-2-1',  'stage' => 'DISPONIBLE', 'dealId' => 0]];
$log = vend_comparar_catalogos($d, $viejas, $nuevas);
test_same(1, count($log), 'el barrido detecta UNA venta (D-2-11), no la que sigue disponible');
test_same(1, (int)$d->query("SELECT COUNT(*) FROM vendidas_avisos WHERE unidad='D-2-11'")->fetchColumn(),
          'y arma su aviso: Beto, que cotizo D-2-11 en par');

// venta en DOS pasos: primero la etapa sin comprador, despues se ata el deal
test_same(true, str_contains(vend_desde_cambio($d, 'D-3-12', 'DISPONIBLE', 'RESERVADO', 0, 'aviso'), 'SIN deal'),
          'paso 1: etapa sin comprador, no arma');
$cot->execute(['h8', 506, 7, 'DOSPASOS', 'D-3-12', $ahora - 86400, 1, $ahora - 86400]);
$l2 = vend_desde_cambio($d, 'D-3-12', 'RESERVADO', 'RESERVADO', 801, 'aviso', 0);
test_same(true, str_contains($l2, 'vendida a deal 801'), 'paso 2: al atarse el comprador SI se reconoce la venta');
// candado: una unidad que YA tenia comprador y se edita hoy NO es venta nueva
test_same('', vend_desde_cambio($d, 'D-3-12', 'RESERVADO', 'RESERVADO', 801, 'aviso', 801),
          'edicion de una vendida con comprador de antes: nada');
test_same('', vend_desde_cambio($d, 'D-2-12', 'RESERVADO', 'RESERVADO', 600, 'aviso', -1),
          'sin saber el comprador de antes (aviso normal): nada');

// sin deal comprador no se arma lista (no se sabe a quien excluir)
$l = vend_desde_cambio($d, 'D-2-1', 'DISPONIBLE', 'RESERVADO', 0, 'aviso');
test_same(true, str_contains($l, 'SIN deal comprador'), 'sin comprador lo dice y no arma lista');
test_same(0, (int)$d->query("SELECT COUNT(*) FROM vendidas WHERE unidad='D-2-1'")->fetchColumn(), 'no registro nada');

// ── el mismo codigo en OTRO proyecto es otra unidad ─────────────────────────
$cot2 = $d->prepare('INSERT INTO cotizaciones (huella, deal_id, asesor_id, cliente, unidades, ultima_vez, veces, creada, categoria) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
$cot2->execute(['p1', 701, 7, 'PLAZA', 'A-1-1', $ahora - 86400, 1, $ahora - 86400, 33]);
$cot2->execute(['p2', 702, 7, 'APARTMENTS', 'A-1-1', $ahora - 86400, 1, $ahora - 86400, 39]);
test_same(1, vend_registrar($d, 'A-1-1', 900, 'RESERVADO', 'aviso', $ahora, 33), 'A-1-1 de Plaza solo avisa al que cotizo en Plaza');
test_same('701', (string)$d->query("SELECT deal_id FROM vendidas_avisos WHERE unidad='A-1-1'")->fetchColumn(),
          'es el deal 701, no el 702 de Apartments');

// ── alternativas ────────────────────────────────────────────────────────────
$cat = [
  ['codigo' => 'D-2-12', 'cat' => 33, 'tipo' => 1793, 'stage' => 'RESERVADO',  'pvp' => '99582.5|USD', 'dealId' => 600],
  ['codigo' => 'D-3-12', 'cat' => 33, 'tipo' => 1793, 'stage' => 'DISPONIBLE', 'pvp' => '100732.5|USD', 'dealId' => 0, 'm2' => '32,50'],
  ['codigo' => 'D-2-11', 'cat' => 33, 'tipo' => 1793, 'stage' => 'DISPONIBLE', 'pvp' => '92990|USD', 'dealId' => 0],
  ['codigo' => 'D-4-12', 'cat' => 33, 'tipo' => 1793, 'stage' => 'RESERVADO',  'pvp' => '101883|USD', 'dealId' => 1],   // no disponible
  ['codigo' => 'L-1-1',  'cat' => 33, 'tipo' => 1791, 'stage' => 'DISPONIBLE', 'pvp' => '99000|USD', 'dealId' => 0],   // un LOCAL
  ['codigo' => 'D-2-10', 'cat' => 39, 'tipo' => 1793, 'stage' => 'DISPONIBLE', 'pvp' => '99600|USD', 'dealId' => 0],   // otro proyecto
  ['codigo' => 'D-2-9',  'cat' => 33, 'tipo' => 1793, 'stage' => 'DISPONIBLE', 'pvp' => '', 'dealId' => 0],            // sin precio
];
$alt = vend_alternativas($cat, 'D-2-12', 33);
test_same(['D-3-12', 'D-2-11'], array_column($alt, 'codigo'),
          'alternativas: solo departamentos DISPONIBLES de Plaza con precio, el mas cercano primero');
test_same(100732.5, $alt[0]['precio'], 'con su precio');
test_same([], vend_alternativas($cat, 'Z-9-9', 33), 'si la vendida no esta en el catalogo, no se inventa nada');

// ── telefonos ───────────────────────────────────────────────────────────────
test_same('593991234567', vend_tel_normalizado('099 123 4567'), '09... -> 5939...');
test_same('593991234567', vend_tel_normalizado('+593 99 123 4567'), '+593... -> 593...');
test_same('593991234567', vend_tel_normalizado('991234567'), '9... -> 5939...');
test_same('', vend_tel_normalizado('042123456'), 'un fijo no sirve para WhatsApp');
test_same('593987654321', vend_mejor_telefono([['VALUE' => '042123456', 'VALUE_TYPE' => 'WORK'],
                                               ['VALUE' => '0987654321', 'VALUE_TYPE' => 'MOBILE']]),
          'prefiere el MOVIL');
/* El caso de arriba no prueba la PREFERENCIA: el fijo se descarta igual por su forma.
   Este si: dos numeros con forma de celular, solo uno marcado MOBILE, y va SEGUNDO. */
test_same('593987654321', vend_mejor_telefono([['VALUE' => '0991111111', 'VALUE_TYPE' => 'WORK'],
                                               ['VALUE' => '0987654321', 'VALUE_TYPE' => 'MOBILE']]),
          'entre dos celulares, el marcado MOBILE aunque venga segundo');
test_same(true,  vend_ya_compro([['CATEGORY_ID' => '44', 'STAGE_SEMANTIC_ID' => 'P']]), 'deal en CLIENTES = ya compro');
test_same(false, vend_ya_compro([['CATEGORY_ID' => '44', 'STAGE_SEMANTIC_ID' => 'F']]), 'reserva CAIDA no cuenta');
test_same(false, vend_ya_compro([['CATEGORY_ID' => '28', 'STAGE_SEMANTIC_ID' => 'P']]), 'prospecto no cuenta');

// ── resolver con una libreta de mentira ─────────────────────────────────────
$llamadas = [];
$lib = function (string $r) use (&$llamadas) {
    $llamadas[] = $r;
    $deals = ['10' => 100, '11' => 110, '12' => 120, '13' => 130, '14' => 140];   // deal -> contacto
    if (str_starts_with($r, '/deals?ids=')) {
        $it = [];
        foreach (explode(',', substr($r, 11)) as $id) if (isset($deals[$id])) $it[] = ['dato' => ['ID' => $id, 'CONTACT_ID' => (string)$deals[$id]]];
        return ['status' => 200, 'json' => ['items' => $it]];
    }
    if (str_starts_with($r, '/deals?contact=')) {
        $c = substr($r, 15);
        $d = $c === '110' ? [['dato' => ['CATEGORY_ID' => '44', 'STAGE_SEMANTIC_ID' => 'S']]]      // 110 ya compro
                          : [['dato' => ['CATEGORY_ID' => '28', 'STAGE_SEMANTIC_ID' => 'P']]];
        return ['status' => 200, 'json' => ['items' => $d]];
    }
    if (str_starts_with($r, '/contacts?ids=')) {     // la libreta OMITE el 130
        $tel = ['100' => '0991111111', '120' => '042222222', '140' => '+593 99 111 1111'];
        $it = [];
        foreach (explode(',', substr($r, 14)) as $id) if (isset($tel[$id]))
            $it[] = ['dato' => ['ID' => $id, 'NAME' => "N$id", 'PHONE' => [['VALUE' => $tel[$id], 'VALUE_TYPE' => 'MOBILE']]]];
        return ['status' => 200, 'json' => ['items' => $it]];
    }
    if ($r === '/contact/130?fresco=1') return ['status' => 404, 'json' => null];
    return ['status' => 500, 'json' => null];
};
$res = vend_resolver($d, [10, 11, 12, 13, 14, 15], $lib, $ahora);
test_same('avisar',       $res[10]['estado'], 'deal 10: se le avisa');
test_same('593991111111', $res[10]['telefono'], 'con su celular normalizado');
test_same('ya_compro',    $res[11]['estado'], 'deal 11: ya compro otra unidad -> excluido');
test_same('sin_telefono', $res[12]['estado'], 'deal 12: solo tiene fijo -> sin telefono');
test_same('sin_telefono', $res[13]['estado'], 'deal 13: la libreta no lo tiene ni fresco -> sin telefono');
test_same('repetido',     $res[14]['estado'], 'deal 14: MISMO celular que el 10 -> no se le escribe dos veces');
test_same('sin_contacto', $res[15]['estado'], 'deal 15: sin contacto');
test_same(true, in_array('/contact/130?fresco=1', $llamadas, true), 'al omitido lo pide fresco a la libreta');
test_same(false, (bool)array_filter($llamadas, fn($x) => str_contains($x, 'contact/110')), 'del que ya compro no pide telefono');

// 🔴 "ya compro" NO se guarda: segunda entrega, el 10 reservo otra unidad entre medio
$lib2 = function (string $r) use ($lib) {
    if ($r === '/deals?contact=100') return ['status' => 200, 'json' => ['items' => [['dato' => ['CATEGORY_ID' => '44', 'STAGE_SEMANTIC_ID' => 'P']]]]];
    return $lib($r);
};
test_same('ya_compro', vend_resolver($d, [10], $lib2, $ahora + 60)[10]['estado'],
          'si reservo otra unidad entre dos consultas, la segunda ya lo excluye');

@unlink($ruta);
echo 'vendidas: ' . $GLOBALS['TEST_N'] . " comprobaciones\n";
