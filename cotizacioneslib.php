<?php
/**
 * cotizacioneslib.php — el historial de las tablas de pago del cotizador.
 * ---------------------------------------------------------------------------
 * Pedido del usuario (22-sep-2026): "una historia de las tablas de pago que se hayan
 * generado en el cotizador... que guarde QUE TABLA se generó, cuál es su plan de pagos".
 *
 * Guarda el plan ENTERO -- las filas con su fecha y su monto, como las vio el cliente --
 * no solo los parámetros. Sirve para cuando alguien llama con un papel diciendo "a mí
 * me dieron 53 cuotas de $418.87" y hay que saber quién se la dio y cuándo.
 *
 * ── TRES DECISIONES QUE HAY QUE CONOCER ANTES DE TOCAR ESTO ────────────────
 *
 * 1. SE REGISTRA AL DIBUJAR LA TABLA, no al bajar el PDF. Una tabla que el asesor le
 *    mostró al cliente en pantalla ya circuló, se haya bajado el PDF o no.
 *
 * 2. LA MISMA TABLA DOS VECES ES UNA SOLA FILA. El cotizador se recarga con cada
 *    cambio de campo; registrar cada recarga llenaría la base de borradores. La huella
 *    (`huella`) cubre todo lo que afecta al plan: mover un campo da OTRA tabla y otra
 *    fila; volver a la misma sube `veces` y `ultima_vez`.
 *
 * 3. 🔴 SI ESTO FALLA, EL COTIZADOR NO SE CAE. Registrar es secundario frente a
 *    cotizarle a un cliente delante. Todo va en try/catch y un fallo es silencioso
 *    para el asesor (queda en `sync.log`). Probado dejando la base en solo lectura.
 *
 * 🔴 EL DIA SE CUENTA EN HORA DE ECUADOR. El contenedor no corre en America/Guayaquil:
 * el "hoy" del servidor no es el "hoy" del vendedor. Se guarda el instante en UTC
 * (`creada`) Y el día ya resuelto (`dia`), para que el filtro no dependa de dónde se
 * consulte ni de cómo esté el reloj de quien pregunta.
 * ---------------------------------------------------------------------------
 */
declare(strict_types=1);

const COTHIST_TZ = 'America/Guayaquil';

function cothist_ruta(): string {
    return (getenv('DATA_DIR') ?: '/data') . '/cotizaciones.sqlite';
}

/** El día del negocio, en hora de Ecuador. NUNCA date('Y-m-d') pelado. */
function cothist_dia(?int $ts = null): string {
    return (new DateTimeImmutable('@' . ($ts ?? time())))
        ->setTimezone(new DateTimeZone(COTHIST_TZ))->format('Y-m-d');
}

/** Abre (y crea) la base. Devuelve null si no se puede: quien llama sigue igual. */
function cothist_db(bool $escribir = true): ?PDO {
    static $db = null, $intentado = false;
    if ($db instanceof PDO) return $db;
    if ($intentado) return null;
    $intentado = true;
    try {
        $ruta = cothist_ruta();
        @mkdir(dirname($ruta), 0775, true);
        $d = new PDO('sqlite:' . $ruta);
        $d->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $d->exec('PRAGMA journal_mode = WAL');
        $d->exec('PRAGMA busy_timeout = 4000');
        if ($escribir) cothist_migrar($d);
        $db = $d;
        return $db;
    } catch (Throwable $e) {
        cothist_queja('abrir', $e);
        return null;
    }
}

function cothist_migrar(PDO $d): void {
    $d->exec("CREATE TABLE IF NOT EXISTS cotizaciones (
        huella        TEXT PRIMARY KEY,
        creada        INTEGER NOT NULL,
        ultima_vez    INTEGER NOT NULL,
        dia           TEXT NOT NULL,
        veces         INTEGER NOT NULL DEFAULT 1,
        deal_id       INTEGER NOT NULL DEFAULT 0,
        asesor_id     INTEGER NOT NULL DEFAULT 0,
        cliente       TEXT NOT NULL DEFAULT '',
        proyecto      TEXT NOT NULL DEFAULT '',
        categoria     INTEGER NOT NULL DEFAULT 0,
        unidades      TEXT NOT NULL DEFAULT '',
        valor         REAL NOT NULL DEFAULT 0,
        separacion    REAL NOT NULL DEFAULT 0,
        firma         REAL NOT NULL DEFAULT 0,
        contraentrega REAL NOT NULL DEFAULT 0,
        mensual       REAL NOT NULL DEFAULT 0,
        cuotas        INTEGER NOT NULL DEFAULT 0,
        modalidad     TEXT NOT NULL DEFAULT '',
        hasta         TEXT NOT NULL DEFAULT '',
        params        TEXT NOT NULL DEFAULT '',
        plan          TEXT NOT NULL DEFAULT '',
        hitos         TEXT NOT NULL DEFAULT ''
    )");
    foreach (['dia', 'creada', 'deal_id', 'cliente'] as $c)
        $d->exec("CREATE INDEX IF NOT EXISTS ix_cot_$c ON cotizaciones($c)");
    /* Columnas que se agregaron despues. SQLite no tiene "ADD COLUMN IF NOT EXISTS",
       asi que se pregunta primero: una base ya creada no se puede quedar sin ellas. */
    $tiene = [];
    foreach ($d->query('PRAGMA table_info(cotizaciones)') as $c) $tiene[$c['name']] = true;
    foreach (['hitos' => "TEXT NOT NULL DEFAULT ''"] as $col => $tipo)
        if (empty($tiene[$col])) $d->exec("ALTER TABLE cotizaciones ADD COLUMN $col $tipo");
}

function cothist_queja(string $donde, Throwable $e): void {
    @file_put_contents((getenv('DATA_DIR') ?: '/data') . '/sync.log',
        date('c') . " cotizaciones[$donde] " . $e->getMessage() . "\n", FILE_APPEND);
}

/** Los parámetros que de verdad cambian la tabla. Lo que no está aquí, no la cambia:
 *  `token`, `s`, `exp` y el nombre del cliente no pueden hacer que un número mueva. */
const COTHIST_PARAMS = [
    'u', 'd', 'mod', 'n', 'mes', 'ffirma', 'presu', 'entregameses', 'financiar',
    'firmames', 'firmacuota', 'firmapers', 'firmaantes', 'absorbe', 'extracual',
    'extrapartes', 'extrames1', 'extrames2', 'extrapers', 'sep', 'addparq',
    'unificar', 'pichanios', 'pichsis',
];

function cothist_params(array $get): array {
    $out = [];
    foreach ($get as $k => $v) {
        if (is_array($v)) continue;
        $k = (string)$k;
        // los montos a la medida son firmamonto1..N y extramonto1..N: no se listan uno a uno
        if (in_array($k, COTHIST_PARAMS, true) || preg_match('/^(firma|extra)monto\d+$/', $k))
            if ((string)$v !== '') $out[$k] = (string)$v;
    }
    ksort($out);
    return $out;
}

/**
 * Registra una tabla de pagos. Devuelve la huella, o '' si no se pudo (y no importa).
 *
 * `$plan` es el que devuelve cot_plan(); `$filas` sale de ahí y se guarda tal cual,
 * que es el punto del pedido: lo que se guarda es LA TABLA, no cómo se pidió.
 */
function cothist_registrar(array $get, array $plan, array $meta): string {
    try {
        $params = cothist_params($get);
        if (!$params) return '';                      // sin parámetros no hubo cotización
        $filas = array_values((array)($plan['filas'] ?? []));
        if (!$filas) return '';                       // sin tabla no hay nada que guardar

        /* La huella cubre los parámetros Y el valor de la unidad: si mañana sube el
           precio, la misma URL da OTRA tabla, y son dos cotizaciones distintas de
           verdad -- no una repetida. */
        $huella = hash('sha256', json_encode([$params, round((float)($plan['valor'] ?? 0), 2)]));

        $db = cothist_db();
        if (!$db) return '';
        $ahora = time();

        /* INSERT ... ON CONFLICT: una sola ida a la base y sin carrera entre dos
           asesores mirando la misma cotización en el mismo segundo. Al repetirse NO se
           pisan `creada` ni `dia`: la primera vez que se generó es un dato. */
        $st = $db->prepare("INSERT INTO cotizaciones
            (huella, creada, ultima_vez, dia, veces, deal_id, asesor_id, cliente, proyecto,
             categoria, unidades, valor, separacion, firma, contraentrega, mensual, cuotas,
             modalidad, hasta, params, plan, hitos)
            VALUES (:h,:t,:t,:dia,1,:deal,:ase,:cli,:proy,:cat,:uni,:val,:sep,:fir,:con,:men,:cuo,:mod,:has,:par,:pla,:hit)
            ON CONFLICT(huella) DO UPDATE SET
                veces      = veces + 1,
                ultima_vez = :t,
                /* 🔴 AL REPETIRSE TAMBIEN SE REFRESCA DE QUIEN ES. Antes solo subia el
                   contador, y eso dejaba un dato parcial congelado para siempre: si la
                   PRIMERA vez Bitrix contestaba 503, el cliente se guardaba vacio y no
                   se llenaba nunca mas, por mucho que las siguientes veces si trajeran
                   el nombre. Medido en el deal 408979: 15 repeticiones, 8 de ellas con
                   el nombre a la vista en pantalla, y la fila seguia con cliente ''.
                   Lo encontro la sesion de COBRANZAS leyendo el JSON.

                   Solo se pisa cuando lo nuevo VALE: un vacio jamas borra un nombre que
                   ya se sabia. Al reves de lo de antes, pero por el mismo motivo -- un
                   fallo de lectura no puede hacerse pasar por un dato.

                   🔴 EL CAST NO ES DECORACION. PDO ata los parametros como TEXTO, y en
                   SQLite '0' <> 0 es VERDADERO porque compara tipos distintos: sin el
                   CAST, un asesor que no se pudo leer PISABA con 0 al que ya estaba.
                   Lo destapo la prueba 3 (Bitrix cae otra vez), no la lectura. */
                cliente   = CASE WHEN :cli <> ''                THEN :cli  ELSE cliente   END,
                proyecto  = CASE WHEN :proy <> ''               THEN :proy ELSE proyecto  END,
                asesor_id = CASE WHEN CAST(:ase AS INTEGER) > 0 THEN CAST(:ase AS INTEGER)
                                 ELSE asesor_id END");
        $st->execute([
            ':h'    => $huella,
            ':t'    => $ahora,
            ':dia'  => cothist_dia($ahora),
            ':deal' => (int)($meta['dealId'] ?? 0),
            ':ase'  => (int)($meta['asesorId'] ?? 0),
            ':cli'  => mb_substr((string)($meta['cliente'] ?? ''), 0, 120),
            ':proy' => mb_substr((string)($meta['proyecto'] ?? ''), 0, 60),
            ':cat'  => (int)($meta['categoria'] ?? 0),
            ':uni'  => mb_substr((string)($meta['unidades'] ?? ''), 0, 200),
            ':val'  => round((float)($plan['valor'] ?? 0), 2),
            ':sep'  => round((float)($plan['separacion'] ?? 0), 2),
            ':fir'  => round((float)($plan['firma'] ?? 0), 2),
            ':con'  => round((float)($plan['contraentrega'] ?? 0), 2),
            ':men'  => round((float)($plan['mensual'] ?? 0), 2),
            ':cuo'  => (int)($plan['cuotas'] ?? 0),
            ':mod'  => (string)($plan['modalidad'] ?? ''),
            ':has'  => (string)($plan['hastaTxt'] ?? ($plan['hasta'] ?? '')),
            ':par'  => json_encode($params, JSON_UNESCAPED_UNICODE),
            ':pla'  => json_encode($filas, JSON_UNESCAPED_UNICODE),
            /* Lo que la tabla impresa muestra ADEMAS de las filas: la SEPARACION y el
               A LA FIRMA van arriba con su fecha, y el TOTAL CUOTA INICIAL cierra la
               entrada. Sin esto el historial mostraba una tabla que al cliente le
               faltaba la primera linea -- se detecto comparando fila a fila contra el
               HTML: 56 filas en pantalla, 55 guardadas, $1.000 de diferencia. */
            ':hit'  => json_encode([
                'fechaReserva' => (string)($plan['fechaReserva'] ?? ''),
                'fechaFirma'   => (string)($plan['fechaFirma'] ?? ''),
                'totalInicial' => round((float)($plan['totalInicial'] ?? 0), 2),
                'inicioTxt'    => (string)($plan['inicioTxt'] ?? ''),
                'hastaTxt'     => (string)($plan['hastaTxt'] ?? ''),
            ], JSON_UNESCAPED_UNICODE),
        ]);
        return $huella;
    } catch (Throwable $e) {
        cothist_queja('registrar', $e);
        return '';
    }
}

/** Los rangos con nombre del filtro, resueltos en hora de Ecuador. La semana
 *  arranca en LUNES, que es como cuenta la oficina. */
function cothist_rango(string $filtro, string $desde = '', string $hasta = ''): array {
    $hoy = new DateTimeImmutable('now', new DateTimeZone(COTHIST_TZ));
    $d = fn(DateTimeImmutable $x) => $x->format('Y-m-d');
    switch ($filtro) {
        case 'hoy':     return [$d($hoy), $d($hoy)];
        case 'ayer':    $a = $hoy->modify('-1 day'); return [$d($a), $d($a)];
        case 'semana':  return [$d($hoy->modify('monday this week')), $d($hoy)];
        case 'semana_pasada':
            $l = $hoy->modify('monday last week');
            return [$d($l), $d($l->modify('+6 days'))];
        case 'mes':     return [$hoy->format('Y-m-01'), $d($hoy)];
        case 'mes_pasado':
            $m = $hoy->modify('first day of last month');
            return [$m->format('Y-m-01'), $m->format('Y-m-t')];
        case 'rango':
            $re = '/^\d{4}-\d{2}-\d{2}$/';
            return [preg_match($re, $desde) ? $desde : $d($hoy->modify('-30 days')),
                    preg_match($re, $hasta) ? $hasta : $d($hoy)];
        default:        return ['', ''];              // todo
    }
}

/** Busca. Devuelve ['filas'=>[], 'total'=>int, 'suma'=>float]. */
function cothist_buscar(array $f): array {
    $vacio = ['filas' => [], 'total' => 0, 'suma' => 0.0];
    try {
        $db = cothist_db(false);
        if (!$db) return $vacio;
        cothist_migrar($db);

        [$desde, $hasta] = cothist_rango((string)($f['filtro'] ?? ''),
            (string)($f['desde'] ?? ''), (string)($f['hasta'] ?? ''));
        $w = []; $p = [];
        if ($desde !== '') { $w[] = 'dia >= :desde'; $p[':desde'] = $desde; }
        if ($hasta !== '') { $w[] = 'dia <= :hasta'; $p[':hasta'] = $hasta; }
        $q = trim((string)($f['q'] ?? ''));
        if ($q !== '') {
            $w[] = '(cliente LIKE :q OR unidades LIKE :q OR proyecto LIKE :q OR CAST(deal_id AS TEXT) = :qe)';
            $p[':q'] = '%' . $q . '%';
            $p[':qe'] = $q;
        }
        $where = $w ? ' WHERE ' . implode(' AND ', $w) : '';

        $st = $db->prepare("SELECT COUNT(*) c, COALESCE(SUM(valor),0) s FROM cotizaciones$where");
        $st->execute($p);
        $tot = $st->fetch(PDO::FETCH_ASSOC) ?: ['c' => 0, 's' => 0];

        $lim = max(1, min(500, (int)($f['limite'] ?? 200)));
        $off = max(0, (int)($f['desplazar'] ?? 0));
        $st = $db->prepare("SELECT * FROM cotizaciones$where ORDER BY ultima_vez DESC LIMIT $lim OFFSET $off");
        $st->execute($p);
        return ['filas' => $st->fetchAll(PDO::FETCH_ASSOC) ?: [],
                'total' => (int)$tot['c'], 'suma' => (float)$tot['s']];
    } catch (Throwable $e) {
        cothist_queja('buscar', $e);
        return $vacio;
    }
}

/** La cotizacion MAS RECIENTE de un deal. La pide cobranzas para armar las
 *  dependencias sin leer el PDF -- hay PDFs bajados con Firefox cuyos montos salen
 *  ilegibles, y el deal se queda en RESERVA. Aca las filas estan en limpio. */
function cothist_por_deal(int $dealId): ?array {
    try {
        $db = cothist_db(false);
        if (!$db || $dealId <= 0) return null;
        cothist_migrar($db);
        $st = $db->prepare('SELECT * FROM cotizaciones WHERE deal_id = ? ORDER BY ultima_vez DESC LIMIT 1');
        $st->execute([$dealId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) { cothist_queja('por_deal', $e); return null; }
}

function cothist_una(string $huella): ?array {
    try {
        $db = cothist_db(false);
        if (!$db) return null;
        $st = $db->prepare('SELECT * FROM cotizaciones WHERE huella = ?');
        $st->execute([$huella]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) { cothist_queja('una', $e); return null; }
}

/**
 * El NOMBRE del asesor a partir de su id de Bitrix. Se resuelve TARDE -- solo cuando
 * alguien abre el historial -- y queda cacheado en /data: pedirlo al cotizar seria una
 * llamada mas a Bitrix en cada recarga de una pantalla que se recarga con cada campo.
 * Si Bitrix no contesta se devuelve "asesor <id>", que sigue siendo util.
 */
function cothist_asesores(array $ids): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) return [];
    $ruta = (getenv('DATA_DIR') ?: '/data') . '/asesores.json';
    $cache = json_decode((string)@file_get_contents($ruta), true);
    if (!is_array($cache)) $cache = [];
    $faltan = array_values(array_filter($ids, fn($i) => !isset($cache[(string)$i])));
    if ($faltan) {
        $wh = rtrim((string)getenv('BITRIX_WEBHOOK'), '/');
        foreach ($faltan as $i) {
            $nombre = '';
            if ($wh !== '') {
                try {
                    $r = json_decode((string)@file_get_contents($wh . '/user.get', false,
                        stream_context_create(['http' => ['method' => 'POST', 'timeout' => 8,
                            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                            'content' => http_build_query(['ID' => $i])]])), true);
                    $u = ($r['result'][0] ?? []);
                    $nombre = trim(($u['NAME'] ?? '') . ' ' . ($u['LAST_NAME'] ?? ''));
                } catch (Throwable $e) { cothist_queja('asesor', $e); }
            }
            $cache[(string)$i] = $nombre !== '' ? $nombre : ('asesor ' . $i);
        }
        @file_put_contents($ruta, json_encode($cache, JSON_UNESCAPED_UNICODE));
    }
    $out = [];
    foreach ($ids as $i) $out[$i] = $cache[(string)$i] ?? ('asesor ' . $i);
    return $out;
}
