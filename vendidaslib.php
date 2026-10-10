<?php
/**
 * vendidaslib.php — "COTIZARON Y NO COMPRARON".
 * ---------------------------------------------------------------------------
 * Pedido de Jesua (3-oct-2026): cuando una unidad se vende, tener lista la gente que la
 * habia cotizado y no la compro, para que una automatizacion les escriba ("se vendio la
 * que te interesaba, aun tenemos estas"). Escasez y urgencia.
 *
 * Tres piezas, todas sobre la MISMA base del historial de cotizaciones
 * (/data/cotizaciones.sqlite, que ya guarda deal, asesor, cliente y unidades):
 *
 *   1. vend_desde_cambio()  se llama cuando una unidad cambia de etapa. La etapa de
 *      ANTES sale del catalogo guardado, no del aviso: el aviso no la trae, y una
 *      edicion cualquiera de una unidad ya reservada (un PVP) llega con la misma etapa
 *      y no puede volver a armar la lista.
 *   2. vend_registrar()     anota la venta y arma la lista de quienes la cotizaron,
 *      sin el comprador. Idempotente por (unidad, deal comprador).
 *   3. vend_anular()        si la reserva se cae y la unidad vuelve a DISPONIBLE, las
 *      pendientes se anulan: decirle a alguien "se vendio" cuando no se vendio es
 *      mentirle.
 *
 * 🔴 NADA de esto manda mensajes. El envio lo hace otro sistema, y la entrega de datos
 * de clientes esta detras de la perilla `avisos_vendidas`, que arranca APAGADA hasta que
 * Jesua apruebe (condicion del orquestador, 3-oct-2026).
 */
declare(strict_types=1);

/* ── Perillas en /data/config.json (se cambian sin redesplegar) ───────────────────── */

function inv_cfg(string $clave, $porDefecto) {
    static $cfg = null;
    if ($cfg === null) {
        $f = (getenv('DATA_DIR') ?: '/data') . '/config.json';
        $j = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
        $cfg = is_array($j) ? $j : [];
    }
    return array_key_exists($clave, $cfg) ? $cfg[$clave] : $porDefecto;
}

/** Que etapas cuentan como "se vendio". Decision de Jesua; por defecto, desde la reserva. */
function vend_etapas_venta(): array {
    $e = inv_cfg('avisos_etapas', ['RESERVADO', 'FIRMADO', 'VENDIDO']);
    return array_values(array_map('strtoupper', (array)$e));
}

/* ── Funciones PURAS (sin base, sin red): lo que decide ───────────────────────────── */

/**
 * ¿Este cambio de etapa es una VENTA nueva? Solo si viene de una etapa que no es de
 * venta y entra a una que si. RESERVADO -> FIRMADO no es otra venta: es la misma.
 * Una etapa de antes vacia (unidad recien creada, catalogo sin dato) NO cuenta: sin
 * saber de donde viene no se puede afirmar que "se acaba de vender".
 */
function vend_es_venta(string $antes, string $ahora, array $etapasVenta): bool {
    $antes = strtoupper(trim($antes)); $ahora = strtoupper(trim($ahora));
    if ($antes === '' || $ahora === '') return false;
    return !in_array($antes, $etapasVenta, true) && in_array($ahora, $etapasVenta, true);
}

/** ¿La venta se cayo? De una etapa de venta de vuelta a DISPONIBLE. */
function vend_es_caida(string $antes, string $ahora, array $etapasVenta): bool {
    return in_array(strtoupper(trim($antes)), $etapasVenta, true)
        && strtoupper(trim($ahora)) === 'DISPONIBLE';
}

/** ¿La cotizacion incluye esta unidad? Coincidencia EXACTA dentro de la lista "A-1-2, A-1-3". */
function vend_cotizo_unidad(string $unidades, string $unidad): bool {
    $u = strtoupper(trim($unidad));
    foreach (explode(',', $unidades) as $x) if (strtoupper(trim($x)) === $u) return true;
    return false;
}

/* ── Base ─────────────────────────────────────────────────────────────────────────── */

function vend_migrar(PDO $d): void {
    $d->exec("CREATE TABLE IF NOT EXISTS vendidas (
        unidad         TEXT NOT NULL,
        comprador_deal INTEGER NOT NULL,
        etapa          TEXT NOT NULL,
        cuando         INTEGER NOT NULL,
        origen         TEXT NOT NULL,              -- 'aviso' | 'barrido'
        estado         TEXT NOT NULL DEFAULT 'firme', -- 'firme' | 'anulada'
        anulada_en     INTEGER NOT NULL DEFAULT 0,
        PRIMARY KEY (unidad, comprador_deal))");
    $d->exec("CREATE TABLE IF NOT EXISTS vendidas_avisos (
        unidad            TEXT NOT NULL,
        comprador_deal    INTEGER NOT NULL,
        deal_id           INTEGER NOT NULL,
        asesor_id         INTEGER NOT NULL DEFAULT 0,
        cliente           TEXT NOT NULL DEFAULT '',
        ultima_cotizacion INTEGER NOT NULL DEFAULT 0,
        veces             INTEGER NOT NULL DEFAULT 1,
        estado            TEXT NOT NULL DEFAULT 'pendiente', -- 'pendiente' | 'enviado' | 'anulado'
        enviado_en        INTEGER NOT NULL DEFAULT 0,
        PRIMARY KEY (unidad, comprador_deal, deal_id))");
    /* El PROYECTO de la unidad. Hizo falta despues del primer despliegue: el mismo codigo
       ("D-2-12") puede existir en dos proyectos, y sin esto se mezclaban cotizaciones. */
    $cols = array_column($d->query("PRAGMA table_info(vendidas)")->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('categoria', $cols, true))
        $d->exec("ALTER TABLE vendidas ADD COLUMN categoria INTEGER NOT NULL DEFAULT 0");
    /* POR QUE se anulo: 'caida' (la unidad volvio a DISPONIBLE) o 'cambio_comprador' (otro
       deal tomo la unidad sin que se liberara). Solo una caida marca el piso de la reventa:
       si contara un cambio de comprador, el nuevo dueno no avisaria a nadie. */
    if (!in_array('motivo', $cols, true))
        $d->exec("ALTER TABLE vendidas ADD COLUMN motivo TEXT NOT NULL DEFAULT ''");
}

/** La base del historial. Para pruebas se le pasa la ruta. Null si no se puede abrir. */
function vend_db(?string $ruta = null): ?PDO {
    try {
        $ruta = $ruta ?? ((getenv('DATA_DIR') ?: '/data') . '/cotizaciones.sqlite');
        @mkdir(dirname($ruta), 0775, true);
        $d = new PDO('sqlite:' . $ruta);
        $d->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $d->exec('PRAGMA busy_timeout = 4000');
        vend_migrar($d);
        return $d;
    } catch (Throwable $e) {
        error_log('vendidas: no se pudo abrir la base: ' . $e->getMessage());
        return null;
    }
}

/* ── Registrar / anular ───────────────────────────────────────────────────────────── */

/**
 * Anota la venta y arma la lista de quienes cotizaron esa unidad y no la compraron.
 * Devuelve cuantos posibles avisos NUEVOS quedaron. Idempotente: llamarla dos veces
 * con la misma (unidad, comprador) no duplica nada.
 *
 * Por ahora se excluye al comprador por DEAL. La exclusion por CONTACTO (el mismo
 * cliente con otro deal), la de quien ya compro otra unidad y la de "no interesado"
 * necesitan leer la libreta y se aplican al ENTREGAR la lista, no aqui.
 */
function vend_registrar(PDO $d, string $unidad, int $comprador, string $etapa,
                        string $origen, ?int $ahora = null, int $categoria = 0): int {
    $ahora   = $ahora ?? time();
    $unidad  = strtoupper(trim($unidad));
    $ventana = max(1, (int)inv_cfg('avisos_ventana_dias', 90)) * 86400;

    $ins = $d->prepare('INSERT OR IGNORE INTO vendidas (unidad, comprador_deal, etapa, cuando, origen, categoria)
                        VALUES (?, ?, ?, ?, ?, ?)');
    $ins->execute([$unidad, $comprador, strtoupper($etapa), $ahora, $origen, $categoria]);
    /* El momento de la venta es el de la PRIMERA vez que se registro: si se vuelve a
       llamar (barrido, re-reserva), los candidatos siguen siendo los de ANTES de vender. */
    $c = $d->prepare('SELECT cuando FROM vendidas WHERE unidad = ? AND comprador_deal = ?');
    $c->execute([$unidad, $comprador]);
    $cuando = (int)($c->fetchColumn() ?: $ahora);
    $c->closeCursor();

    // Si esta (unidad, comprador) estaba anulada y la reserva volvio, se reactiva.
    $d->prepare("UPDATE vendidas SET estado = 'firme', anulada_en = 0, cuando = ?
                 WHERE unidad = ? AND comprador_deal = ? AND estado = 'anulada'")
      ->execute([$ahora, $unidad, $comprador]);

    /* Reventa (Jesua, 5-oct-2026): si la unidad ya se habia vendido y la reserva se cayo,
       solo cuentan los que la cotizaron DESPUES de que volvio a disponible. Los de antes
       ya recibieron (o tuvieron) su aviso con la primera venta. */
    $piso = $cuando - $ventana;
    $cai = $d->prepare("SELECT MAX(anulada_en) FROM vendidas
                        WHERE unidad = ? AND comprador_deal <> ? AND estado = 'anulada' AND motivo = 'caida'
                          AND anulada_en > 0 AND anulada_en <= ?");
    $cai->execute([$unidad, $comprador, $cuando]);
    $piso = max($piso, (int)$cai->fetchColumn());
    $cai->closeCursor();

    // Candidatos: cotizaciones dentro de la ventana que incluyen la unidad, por deal.
    $q = $d->prepare("SELECT deal_id, asesor_id, cliente, unidades, ultima_vez, veces
                      FROM cotizaciones
                      WHERE unidades LIKE ? AND ultima_vez >= ? AND creada <= ? AND deal_id > 0
                        AND (? = 0 OR categoria = 0 OR categoria = ?)");
    // creada <= cuando: quien la cotizo DESPUES de venderse no "se la perdio".
    // categoria: el mismo codigo en OTRO proyecto es otra unidad.
    $q->execute(['%' . $unidad . '%', $piso, $cuando, $categoria, $categoria]);
    $porDeal = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (!vend_cotizo_unidad((string)$r['unidades'], $unidad)) continue;   // D-2-1 no es D-2-12
        $dl = (int)$r['deal_id'];
        if ($dl === $comprador) continue;
        $prev = $porDeal[$dl] ?? null;
        $porDeal[$dl] = [
            'asesor'  => (int)$r['asesor_id'] ?: (int)($prev['asesor'] ?? 0),
            'cliente' => (string)$r['cliente'] !== '' ? (string)$r['cliente'] : (string)($prev['cliente'] ?? ''),
            'ultima'  => max((int)$r['ultima_vez'], (int)($prev['ultima'] ?? 0)),
            'veces'   => (int)$r['veces'] + (int)($prev['veces'] ?? 0),
        ];
    }
    $q->closeCursor();

    /* A quien YA se le escribio por esta unidad (con cualquier comprador) no se le repite el
       aviso, salvo que la haya vuelto a cotizar despues de ese aviso. */
    $env = $d->prepare("SELECT deal_id, MAX(ultima_cotizacion) FROM vendidas_avisos
                        WHERE unidad = ? AND estado = 'enviado' GROUP BY deal_id");
    $env->execute([$unidad]);
    foreach ($env->fetchAll(PDO::FETCH_KEY_PAIR) as $dl => $ult)
        if (isset($porDeal[(int)$dl]) && $porDeal[(int)$dl]['ultima'] <= (int)$ult) unset($porDeal[(int)$dl]);
    $env->closeCursor();

    $nuevos = 0;
    $a = $d->prepare('INSERT OR IGNORE INTO vendidas_avisos
        (unidad, comprador_deal, deal_id, asesor_id, cliente, ultima_cotizacion, veces)
        VALUES (?, ?, ?, ?, ?, ?, ?)');
    foreach ($porDeal as $dl => $x) {
        $a->execute([$unidad, $comprador, $dl, $x['asesor'], $x['cliente'], $x['ultima'], $x['veces']]);
        $nuevos += $a->rowCount();
    }
    // Avisos que se habian anulado por una caida y la venta volvio: vuelven a pendientes.
    $d->prepare("UPDATE vendidas_avisos SET estado = 'pendiente'
                 WHERE unidad = ? AND comprador_deal = ? AND estado = 'anulado'")
      ->execute([$unidad, $comprador]);
    return $nuevos;
}

/**
 * La venta se cayo: la unidad volvio a DISPONIBLE. Las pendientes se anulan. Las que YA
 * se enviaron no se pueden deshacer: quedan marcadas y se devuelven para que alguien lo
 * sepa (a ese cliente se le dijo algo que dejo de ser cierto).
 */
function vend_anular(PDO $d, string $unidad, ?int $ahora = null): array {
    $ahora  = $ahora ?? time();
    $unidad = strtoupper(trim($unidad));
    $d->prepare("UPDATE vendidas SET estado = 'anulada', anulada_en = ?, motivo = 'caida' WHERE unidad = ? AND estado = 'firme'")
      ->execute([$ahora, $unidad]);
    $u = $d->prepare("UPDATE vendidas_avisos SET estado = 'anulado' WHERE unidad = ? AND estado = 'pendiente'");
    $u->execute([$unidad]);
    $anulados = $u->rowCount();
    $e = $d->prepare("SELECT deal_id FROM vendidas_avisos WHERE unidad = ? AND estado = 'enviado'");
    $e->execute([$unidad]);
    $yaEnviados = array_map('intval', $e->fetchAll(PDO::FETCH_COLUMN));
    $e->closeCursor();
    return ['anulados' => $anulados, 'ya_enviados' => $yaEnviados];
}

/**
 * Otro deal tomo una unidad que ya estaba vendida, sin que pasara por DISPONIBLE (una
 * reubicacion, o una correccion a mano). Se anula SOLO la venta del comprador viejo y sus
 * avisos pendientes; la del nuevo la arma vend_registrar. No es una caida: no mueve el piso.
 */
function vend_anular_comprador(PDO $d, string $unidad, int $compradorViejo, ?int $ahora = null): int {
    $unidad = strtoupper(trim($unidad));
    $u = $d->prepare("UPDATE vendidas SET estado = 'anulada', anulada_en = ?, motivo = 'cambio_comprador'
                      WHERE unidad = ? AND comprador_deal = ? AND estado = 'firme'");
    $u->execute([$ahora ?? time(), $unidad, $compradorViejo]);
    $d->prepare("UPDATE vendidas_avisos SET estado = 'anulado'
                 WHERE unidad = ? AND comprador_deal = ? AND estado = 'pendiente'")
      ->execute([$unidad, $compradorViejo]);
    return $u->rowCount();
}

/**
 * El punto de entrada para quien detecta el cambio (el aviso o el barrido).
 * Devuelve un texto corto para el log, o '' si no habia nada que hacer.
 */
function vend_desde_cambio(?PDO $d, string $unidad, string $antes, string $ahora,
                           int $comprador, string $origen, int $compradorAntes = -1,
                           int $categoria = 0): string {
    if (!$d) return '';
    $etapas = vend_etapas_venta();
    /* Venta en DOS pasos: Bitrix puede cambiar primero la etapa y en otro aviso atar el
       deal. El primero llega sin comprador (no se arma lista) y el segundo ya no es un
       cambio de etapa. Se reconoce porque la unidad YA estaba en etapa de venta, SIN
       comprador, y ahora lo tiene. Si ya tenia comprador antes, NO es venta nueva: asi
       una unidad vendida hace meses que se edita hoy no dispara avisos viejos. */
    $atadaAhora = in_array(strtoupper(trim($antes)), $etapas, true)
               && in_array(strtoupper(trim($ahora)), $etapas, true)
               && $compradorAntes === 0 && $comprador > 0;
    /* Cambio de comprador (VIGILANTE + Jesua, 5-oct-2026): la unidad sigue vendida pero
       ahora es de OTRO deal. Para el que la cotizo es otra venta: se la gano otro. */
    $otroDueno = in_array(strtoupper(trim($antes)), $etapas, true)
              && in_array(strtoupper(trim($ahora)), $etapas, true)
              && $compradorAntes > 0 && $comprador > 0 && $compradorAntes !== $comprador;
    if ($otroDueno) {
        $x = vend_anular_comprador($d, $unidad, $compradorAntes);
        $n = vend_registrar($d, $unidad, $comprador, $ahora, $origen, null, $categoria);
        return "vendidas: $unidad cambio de comprador $compradorAntes -> $comprador ($origen)"
             . " · $x venta vieja anulada · $n posibles avisos nuevos";
    }
    if (vend_es_venta($antes, $ahora, $etapas) || $atadaAhora) {
        if ($comprador <= 0) return "vendidas: $unidad entro a $ahora SIN deal comprador -> no se arma lista";
        $n = vend_registrar($d, $unidad, $comprador, $ahora, $origen, null, $categoria);
        return "vendidas: $unidad vendida a deal $comprador ($antes -> $ahora, $origen) · $n posibles avisos nuevos";
    }
    if (vend_es_caida($antes, $ahora, $etapas)) {
        $r = vend_anular($d, $unidad);
        return "vendidas: $unidad volvio a DISPONIBLE · {$r['anulados']} avisos anulados"
             . ($r['ya_enviados'] ? ' · 🔴 YA ENVIADOS a deals ' . implode(',', $r['ya_enviados']) : '');
    }
    return '';
}

/**
 * Recuperacion en el rebuild completo: compara el catalogo anterior con el nuevo y
 * registra las ventas (y caidas) que no llegaron por aviso. Un aviso perdido es una
 * venta perdida, y la libreta no copia el SPA 1072 para fabricarlo.
 * Devuelve las lineas de log.
 */
function vend_comparar_catalogos(?PDO $d, array $viejas, array $nuevas): array {
    if (!$d || !$viejas) return [];
    $antes = []; $dealAntes = [];
    foreach ($viejas as $u) {
        $antes[(int)($u['id'] ?? 0)] = (string)($u['stage'] ?? '');
        $dealAntes[(int)($u['id'] ?? 0)] = (int)($u['dealId'] ?? 0);
    }
    $log = [];
    foreach ($nuevas as $u) {
        $id = (int)($u['id'] ?? 0);
        if (!isset($antes[$id])) continue;
        $l = vend_desde_cambio($d, (string)($u['codigo'] ?? ''), $antes[$id], (string)($u['stage'] ?? ''),
                               (int)($u['dealId'] ?? 0), 'barrido', $dealAntes[$id], (int)($u['cat'] ?? 0));
        if ($l !== '') $log[] = $l;
    }
    return $log;
}

/* ── Alternativas para el mensaje ─────────────────────────────────────────────────── */

/** Precio de la unidad: el PVP viene como "99582.5|USD". */
function vend_precio($pvp): float {
    return (float)explode('|', (string)$pvp)[0];
}

/**
 * Lo que todavia se puede ofrecer: mismo PROYECTO y mismo TIPO que la vendida (un
 * departamento no se reemplaza con un local), DISPONIBLE, con precio, y las de precio
 * mas cercano primero. PURA: trabaja sobre el catalogo que ya esta en disco, cero
 * llamadas. Si la vendida no esta en el catalogo, devuelve [] (no se inventa).
 */
function vend_alternativas(array $units, string $codigo, int $cat, int $limite = 5): array {
    $codigo = strtoupper(trim($codigo));
    $vendida = null;
    foreach ($units as $u)
        if (strtoupper((string)($u['codigo'] ?? '')) === $codigo && (int)($u['cat'] ?? 0) === $cat) { $vendida = $u; break; }
    if (!$vendida) return [];
    $tipo = (int)($vendida['tipo'] ?? 0);
    $ref  = vend_precio($vendida['pvp'] ?? '');
    $c = [];
    foreach ($units as $u) {
        if ((int)($u['cat'] ?? 0) !== $cat || (int)($u['tipo'] ?? 0) !== $tipo) continue;
        if (strtoupper((string)($u['stage'] ?? '')) !== 'DISPONIBLE' || !empty($u['dealId'])) continue;
        if (strtoupper((string)($u['codigo'] ?? '')) === $codigo) continue;
        $p = vend_precio($u['pvp'] ?? '');
        if ($p <= 0) continue;
        $c[] = ['codigo' => (string)$u['codigo'], 'm2' => (string)($u['m2'] ?? ''), 'precio' => $p,
                'diferencia' => abs($p - $ref)];
        $c[count($c) - 1] += array_intersect_key((array)vend_ficha([$u], (string)$u['codigo'], $cat),
                                                 ['tipo' => 1, 'edificio' => 1, 'piso' => 1]);
    }
    usort($c, fn($a, $b) => [$a['diferencia'], $a['codigo']] <=> [$b['diferencia'], $b['codigo']]);
    return array_map(fn($x) => ['codigo' => $x['codigo'], 'm2' => $x['m2'], 'precio' => $x['precio'],
                                'tipo' => $x['tipo'] ?? '', 'edificio' => $x['edificio'] ?? '', 'piso' => $x['piso'] ?? ''],
                     array_slice($c, 0, $limite));
}

/* ── Ficha de una unidad para el texto del mensaje (pedido del conector, 9-oct-2026) ── */

/** Tipo de bien en SINGULAR, para escribir "el departamento D-2-12". Mismos ids que
 *  LST_TIPOS de listalib.php. 1793 se dice "departamento" en TODOS los proyectos: es la
 *  pestana que el cliente ve en la pagina de disponibilidad, aunque la lista de precios
 *  de Plaza los llame monoambientes. */
const VEND_TIPO_SINGULAR = [
    1791 => 'local', 1951 => 'oficina', 1793 => 'departamento', 1797 => 'suite', 1799 => 'casa',
    1795 => 'solar', 1943 => 'terreno', 1947 => 'casa modelo', 1801 => 'parqueo',
];

/** Proyectos que tienen pagina publica de disponibilidad, y su direccion. */
const VEND_DISPONIBILIDAD = [33 => 'plaza', 39 => 'apartments'];
const VEND_DISPONIBILIDAD_URL = 'https://galjosa.com/disponibilidad/';

function vend_tipo_nombre(int $tipo): string {
    return VEND_TIPO_SINGULAR[$tipo] ?? '';
}

/**
 * Lo que el mensaje necesita decir de una unidad: tipo, edificio, piso, m2. PURA, sobre el
 * catalogo en disco. Null si no esta en el catalogo (no se inventa).
 * El EDIFICIO es la torre de la ficha; si la ficha no la trae, la letra con que empieza
 * el codigo (D-2-12 -> D), que es como se nombran los edificios en todos los proyectos.
 */
function vend_ficha(array $units, string $codigo, int $cat): ?array {
    $codigo = strtoupper(trim($codigo));
    foreach ($units as $u) {
        if (strtoupper((string)($u['codigo'] ?? '')) !== $codigo || (int)($u['cat'] ?? 0) !== $cat) continue;
        $torre = trim((string)($u['torre'] ?? ''));
        if ($torre === '' && preg_match('/^([A-Z])/', $codigo, $m)) $torre = $m[1];
        return ['tipo_id' => (int)($u['tipo'] ?? 0), 'tipo' => vend_tipo_nombre((int)($u['tipo'] ?? 0)),
                'edificio' => $torre, 'piso' => trim((string)($u['piso'] ?? '')), 'm2' => (string)($u['m2'] ?? '')];
    }
    return null;
}

/**
 * Link a la pagina publica de disponibilidad que abre directo los planos de esas unidades
 * (la pagina traduce el codigo al plano). '' si el proyecto no tiene pagina publica.
 */
function vend_link_disponibilidad(int $cat, array $codigos): string {
    $slug = VEND_DISPONIBILIDAD[$cat] ?? '';
    if ($slug === '') return '';
    $c = array_values(array_unique(array_filter(array_map(fn($x) => strtoupper(trim((string)$x)), $codigos))));
    return VEND_DISPONIBILIDAD_URL . $slug . ($c ? '?unidades=' . implode(',', $c) : '');
}

/* ── Libreta: quien es cada cotizante, si ya compro, y su telefono ─────────────────── */

/**
 * Pedido a la libreta central. Devuelve ['status' => int, 'json' => ?array].
 * 🔴 LIBRETA_TOKEN es la llave maestra de la libreta (tambien abre /admin): nunca se
 * escribe en logs ni en respuestas. Si falta la configuracion, devuelve status 0.
 */
function vend_libreta_http(string $ruta): array {
    $base = rtrim((string)getenv('LIBRETA_URL'), '/');
    $tok  = (string)getenv('LIBRETA_TOKEN');
    if ($base === '' || $tok === '') return ['status' => 0, 'json' => null];
    $ch = curl_init($base . $ruta);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $tok, 'X-Libreta-Cliente: inventario-vendidas'],
    ]);
    $body = curl_exec($ch);
    $st = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    // sin curl_close(): no hace nada desde PHP 8.0 y en 8.5 avisa "deprecated" en la
    // SALIDA, que en una API que otro sistema lee como JSON la rompe.
    unset($ch);
    $j = is_string($body) ? json_decode($body, true) : null;
    return ['status' => $st, 'json' => is_array($j) ? $j : null];
}

/** Los `dato` de una respuesta de lista de la libreta. */
function vend_items(?array $j): array {
    $out = [];
    foreach ((array)($j['items'] ?? []) as $i) if (is_array($i['dato'] ?? null)) $out[] = $i['dato'];
    return $out;
}

/**
 * Telefono a 593 + 9 digitos (celular de Ecuador). '' si no tiene esa forma: un fijo o un
 * numero roto no sirve para WhatsApp y es mejor saberlo que mandarlo mal.
 */
function vend_tel_normalizado(string $v): string {
    $n = preg_replace('/\D+/', '', $v) ?? '';
    if (strlen($n) === 12 && str_starts_with($n, '5939')) return $n;
    if (strlen($n) === 10 && str_starts_with($n, '09'))   return '593' . substr($n, 1);
    if (strlen($n) === 9  && str_starts_with($n, '9'))    return '593' . $n;
    return '';
}

/** De los telefonos del contacto, el MOVIL primero; normalizado. '' si ninguno sirve. */
function vend_mejor_telefono(array $phones): string {
    usort($phones, fn($a, $b) => ((string)($b['VALUE_TYPE'] ?? '') === 'MOBILE') <=> ((string)($a['VALUE_TYPE'] ?? '') === 'MOBILE'));
    foreach ($phones as $p) { $t = vend_tel_normalizado((string)($p['VALUE'] ?? '')); if ($t !== '') return $t; }
    return '';
}

/**
 * ¿Ya compro? Tiene algun deal en CLIENTES (44) que no este caido (STAGE_SEMANTIC_ID 'F':
 * RESERVAS CAIDAS, FIRMADOS - CAIDOS). Regla de Jesua: "ya compro una unidad aunque sea otra".
 */
function vend_ya_compro(array $dealsDelContacto): bool {
    foreach ($dealsDelContacto as $dl)
        if ((int)($dl['CATEGORY_ID'] ?? -1) === 44 && (string)($dl['STAGE_SEMANTIC_ID'] ?? '') !== 'F') return true;
    return false;
}

/**
 * Decide, para cada deal que cotizo, si se le avisa y con que datos.
 * `$lib` es el mensajero de la libreta (vend_libreta_http en produccion; uno falso en pruebas).
 *
 * 🔴 "¿Ya compro?" se pregunta en CADA entrega, nunca se guarda: si alguien reserva otra
 * unidad entre dos consultas, no le puede llegar "se vendio la tuya". Solo el nombre y
 * el telefono se recuerdan 6 h (condicion del orquestador).
 *
 * Devuelve [deal_id => ['estado' => avisar|ya_compro|sin_contacto|sin_telefono|repetido,
 *                       'nombre' => ..., 'telefono' => ...]].
 */
function vend_resolver(PDO $d, array $dealIds, callable $lib, ?int $ahora = null): array {
    $ahora = $ahora ?? time();
    $d->exec("CREATE TABLE IF NOT EXISTS vend_contacto_cache (
        contact_id INTEGER PRIMARY KEY, nombre TEXT, telefono TEXT, hasta INTEGER)");
    $dealIds = array_values(array_unique(array_map('intval', $dealIds)));
    $out = [];

    // 1) deal -> contacto, de a 50
    $contacto = [];
    foreach (array_chunk($dealIds, 50) as $lote) {
        $r = $lib('/deals?ids=' . implode(',', $lote));
        foreach (vend_items($r['json']) as $dl) $contacto[(int)$dl['ID']] = (int)($dl['CONTACT_ID'] ?? 0);
    }

    // 2) ¿ya compro? — por CONTACTO, en cada entrega
    $compro = [];
    foreach (array_unique(array_filter($contacto)) as $cid) {
        $r = $lib('/deals?contact=' . $cid);
        $compro[$cid] = vend_ya_compro(vend_items($r['json']));
    }

    // 3) nombre y telefono: cache de 6 h, luego lote, y los omitidos uno por uno ?fresco=1
    $datos = [];
    $faltan = [];
    $q = $d->prepare('SELECT nombre, telefono FROM vend_contacto_cache WHERE contact_id = ? AND hasta > ?');
    foreach (array_unique(array_filter($contacto)) as $cid) {
        if ($compro[$cid] ?? false) continue;              // no hace falta su telefono
        $q->execute([$cid, $ahora]);
        $row = $q->fetch(PDO::FETCH_ASSOC); $q->closeCursor();
        if ($row) $datos[$cid] = $row; else $faltan[] = $cid;
    }
    $guarda = $d->prepare('INSERT OR REPLACE INTO vend_contacto_cache (contact_id, nombre, telefono, hasta) VALUES (?, ?, ?, ?)');
    $anotar = function (array $c) use (&$datos, $guarda, $ahora) {
        $cid = (int)($c['ID'] ?? 0); if ($cid <= 0) return;
        $nom = trim((string)($c['NAME'] ?? '') . ' ' . (string)($c['LAST_NAME'] ?? ''));
        $tel = vend_mejor_telefono((array)($c['PHONE'] ?? []));
        $datos[$cid] = ['nombre' => $nom, 'telefono' => $tel];
        $guarda->execute([$cid, $nom, $tel, $ahora + 6 * 3600]);
    };
    foreach (array_chunk($faltan, 50) as $lote) {
        $r = $lib('/contacts?ids=' . implode(',', $lote));
        foreach (vend_items($r['json']) as $c) $anotar($c);
    }
    foreach ($faltan as $cid) {
        if (isset($datos[$cid])) continue;
        $r = $lib('/contact/' . $cid . '?fresco=1');        // la libreta lo trae de Bitrix
        $c = $r['json']['dato'] ?? $r['json'] ?? null;
        if ($r['status'] === 200 && is_array($c) && isset($c['ID'])) $anotar($c);
        else $datos[$cid] = ['nombre' => '', 'telefono' => ''];   // 404: sin telefono, contado
    }

    // 4) estado de cada deal; los telefonos repetidos se avisan UNA vez
    $vistos = [];
    foreach ($dealIds as $dl) {
        $cid = $contacto[$dl] ?? 0;
        if ($cid <= 0) { $out[$dl] = ['estado' => 'sin_contacto', 'nombre' => '', 'telefono' => '']; continue; }
        if ($compro[$cid] ?? false) { $out[$dl] = ['estado' => 'ya_compro', 'nombre' => '', 'telefono' => '']; continue; }
        $x = $datos[$cid] ?? ['nombre' => '', 'telefono' => ''];
        if ((string)$x['telefono'] === '') { $out[$dl] = ['estado' => 'sin_telefono', 'nombre' => $x['nombre'], 'telefono' => '']; continue; }
        if (isset($vistos[$x['telefono']])) { $out[$dl] = ['estado' => 'repetido', 'nombre' => $x['nombre'], 'telefono' => $x['telefono']]; continue; }
        $vistos[$x['telefono']] = true;
        $out[$dl] = ['estado' => 'avisar', 'nombre' => $x['nombre'], 'telefono' => $x['telefono']];
    }
    return $out;
}
