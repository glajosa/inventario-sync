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
                        string $origen, ?int $ahora = null): int {
    $ahora   = $ahora ?? time();
    $unidad  = strtoupper(trim($unidad));
    $ventana = max(1, (int)inv_cfg('avisos_ventana_dias', 90)) * 86400;

    $ins = $d->prepare('INSERT OR IGNORE INTO vendidas (unidad, comprador_deal, etapa, cuando, origen)
                        VALUES (?, ?, ?, ?, ?)');
    $ins->execute([$unidad, $comprador, strtoupper($etapa), $ahora, $origen]);
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

    // Candidatos: cotizaciones dentro de la ventana que incluyen la unidad, por deal.
    $q = $d->prepare("SELECT deal_id, asesor_id, cliente, unidades, ultima_vez, veces
                      FROM cotizaciones
                      WHERE unidades LIKE ? AND ultima_vez >= ? AND creada <= ? AND deal_id > 0");
    // creada <= cuando: quien la cotizo DESPUES de venderse no "se la perdio".
    $q->execute(['%' . $unidad . '%', $cuando - $ventana, $cuando]);
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
    $d->prepare("UPDATE vendidas SET estado = 'anulada', anulada_en = ? WHERE unidad = ? AND estado = 'firme'")
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
 * El punto de entrada para quien detecta el cambio (el aviso o el barrido).
 * Devuelve un texto corto para el log, o '' si no habia nada que hacer.
 */
function vend_desde_cambio(?PDO $d, string $unidad, string $antes, string $ahora,
                           int $comprador, string $origen, int $compradorAntes = -1): string {
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
    if (vend_es_venta($antes, $ahora, $etapas) || $atadaAhora) {
        if ($comprador <= 0) return "vendidas: $unidad entro a $ahora SIN deal comprador -> no se arma lista";
        $n = vend_registrar($d, $unidad, $comprador, $ahora, $origen);
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
                               (int)($u['dealId'] ?? 0), 'barrido', $dealAntes[$id]);
        if ($l !== '') $log[] = $l;
    }
    return $log;
}
