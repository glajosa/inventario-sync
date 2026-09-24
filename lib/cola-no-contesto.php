<?php
/**
 * LA COLA DEL BOTÓN "NO CONTESTÓ" — para que una pulsación no se pierda nunca.
 *
 * Pedido del usuario (9-sep-2026), textual: *"que se coloque en cola y el
 * vendedor no se tenga que preocupar; aplastaste y se guarda como un historial
 * para que, en el momento que se desature, se cree esa actividad automáticamente
 * con el formato correcto y la hora correcta, el día correcto... no que después
 * cuando se desature tengan que aplastar, porque eso hace que tarde la
 * gestión"*.
 *
 * 🔴 EL PROBLEMA MEDIDO (9-sep-2026). Cuando Bitrix se satura, el servicio deja
 * la operación en `processing` y le devuelve 503 al vendedor. NADIE la retoma.
 * En la libreta había **13 pulsaciones muertas**: 4 de hoy (13:53-13:55, de
 * Nicolás, Iván y el usuario 124199), 6 del 7-sep, 1 del 31-ago y 2 del 27-ago.
 * Todas con `updated_at == created_at`: nadie las volvió a tocar nunca. El
 * vendedor aplastó, no se creó nada, y no se iba a crear jamás.
 *
 * ⚠ Y el vigilante NO las veía: su consulta exige `updated_at != created_at`, y
 * estas nunca se tocaron. El termómetro marcaba 0 atascadas con 13 perdidas.
 *
 * CÓMO FUNCIONA
 *   1. El endpoint intenta escribir en Bitrix como siempre (camino rápido).
 *   2. Si Bitrix falla o está saturado, la pulsación ENTERA se guarda acá —con
 *      su hora de pulsación— y el vendedor recibe "encolada", no un error.
 *   3. bin/drenar-no-contesto.php la reproduce cuando el portal respira.
 *
 * ⭐⭐ LA HORA ES LA DE LA PULSACIÓN, NO LA DEL DRENADO. Se guarda `now_ts` y el
 * trabajador se lo pasa a llamada_procesar_resultado(), que YA recibe la fecha
 * como parámetro (`DateTimeImmutable $now`). Por eso la actividad queda con el
 * día y la hora en que el vendedor apretó, que es lo que él pidió y lo que hace
 * que la escalera de gestión lo califique bien.
 *
 * ⚠ Las 13 que ya estaban muertas NO se pueden recuperar: la libreta vieja
 * guardaba el hash del pedido, no el pedido. De acá en adelante no se pierde
 * ninguna.
 */
declare(strict_types=1);

/* Cuántas veces se reintenta un ACCESS_DENIED antes de darlo por permiso real.
 * Con el bucle de 120 s son ~20 min de margen para que el vendedor recargue y
 * su sesión vuelva. Perilla: COLA_NC_TOPE_ACCESO en el entorno. */
if (!defined('COLA_NC_TOPE_ACCESO')) {
    define('COLA_NC_TOPE_ACCESO', max(1, (int)(getenv('COLA_NC_TOPE_ACCESO') ?: 10)));
}

const COLA_NC_ARCHIVO = 'cola-no-contesto.sqlite';
const COLA_NC_TOPE_INTENTOS = 60;   // ~2 h a un intento cada 2 min

/** Abre (y crea si hace falta) la libreta de la cola. */
function cola_nc_db(string $dataDir): SQLite3 {
    /* 🔴 DOS USUARIOS DISTINTOS ESCRIBEN ESTA LIBRETA y el que la crea decide si
     * el otro puede.
     *
     * Apache corre como www-data (ahi entra la pulsacion del vendedor) y el
     * trabajador corre en el contenedor. El 9-sep-2026 mi propia comprobacion la
     * creo como root:root 0644: Apache recibio *"attempt to write a readonly
     * database"*, el guardado quedo en el catch, y DOS pulsaciones reales de las
     * 14:48 no dejaron ninguna fila. La cola estaba muerta y no se veia.
     *
     * SQLite necesita escribir el archivo Y sus -wal/-shm, asi que se abren 0666.
     * /data ya es drwxrwxrwx y es un volumen privado del servicio. */
    $ruta = rtrim($dataDir, '/') . '/' . COLA_NC_ARCHIVO;
    $nueva = !is_file($ruta);
    $db = new SQLite3($ruta);
    if ($nueva) { @chmod($ruta, 0666); }
    foreach ([$ruta . '-wal', $ruta . '-shm'] as $lado) {
        if (is_file($lado) && (fileperms($lado) & 0666) !== 0666) @chmod($lado, 0666);
    }
    $db->busyTimeout(5000);
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec('CREATE TABLE IF NOT EXISTS cola_no_contesto (
        request_id     TEXT PRIMARY KEY,
        input_json     TEXT NOT NULL,
        now_ts         INTEGER NOT NULL,
        source         TEXT NOT NULL,
        stage          TEXT NOT NULL,
        motivo         TEXT NOT NULL,
        estado         TEXT NOT NULL DEFAULT \'encolada\',
        intentos       INTEGER NOT NULL DEFAULT 0,
        creada         INTEGER NOT NULL,
        ultimo_intento INTEGER NOT NULL DEFAULT 0,
        ultimo_error   TEXT NOT NULL DEFAULT \'\'
    )');
    $db->exec('CREATE INDEX IF NOT EXISTS cola_nc_estado ON cola_no_contesto (estado, creada)');
    /* intentos_sanos = intentos en los que EL PORTAL CONTESTO y aun asi denego.
     * Va aparte de `intentos` a proposito: si la saturacion gastara el cupo, una
     * tarde apretada daria por "sin acceso" una pulsacion que solo necesitaba
     * esperar. Lo que decide el veredicto es este contador, no el otro. */
    $tiene = false;
    $res = $db->query('PRAGMA table_info(cola_no_contesto)');
    while ($res && ($c = $res->fetchArray(SQLITE3_ASSOC))) {
        if (($c['name'] ?? '') === 'intentos_sanos') { $tiene = true; break; }
    }
    if (!$tiene) {
        $db->exec('ALTER TABLE cola_no_contesto ADD COLUMN intentos_sanos INTEGER NOT NULL DEFAULT 0');
    }
    return $db;
}

/**
 * Guarda la pulsación. Idempotente por request_id: si el vendedor aplasta dos
 * veces el mismo botón, la cola sigue teniendo UNA entrada.
 */
function cola_nc_encolar(
    SQLite3 $db, string $requestId, array $input, int $nowTs,
    string $source, string $stage, string $motivo
): bool {
    if (trim($requestId) === '') return false;
    $st = $db->prepare('INSERT OR IGNORE INTO cola_no_contesto
        (request_id, input_json, now_ts, source, stage, motivo, creada)
        VALUES (:r, :i, :n, :s, :e, :m, :c)');
    $st->bindValue(':r', $requestId, SQLITE3_TEXT);
    $st->bindValue(':i', json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), SQLITE3_TEXT);
    $st->bindValue(':n', $nowTs, SQLITE3_INTEGER);
    $st->bindValue(':s', $source, SQLITE3_TEXT);
    $st->bindValue(':e', $stage, SQLITE3_TEXT);
    $st->bindValue(':m', mb_substr($motivo, 0, 300), SQLITE3_TEXT);
    $st->bindValue(':c', time(), SQLITE3_INTEGER);
    return $st->execute() !== false;
}

/** Las pulsaciones que esperan, la más vieja primero (se atiende por orden). */
function cola_nc_pendientes(SQLite3 $db, int $limite = 10): array {
    $st = $db->prepare('SELECT * FROM cola_no_contesto
        WHERE estado = \'encolada\' ORDER BY creada ASC LIMIT :l');
    $st->bindValue(':l', max(1, $limite), SQLITE3_INTEGER);
    $r = $st->execute();
    $out = [];
    while ($r && ($x = $r->fetchArray(SQLITE3_ASSOC))) $out[] = $x;
    return $out;
}

function cola_nc_hecha(SQLite3 $db, string $requestId): void {
    $st = $db->prepare('UPDATE cola_no_contesto
        SET estado = \'hecha\', ultimo_intento = :t, ultimo_error = \'\',
            intentos = intentos + 1
        WHERE request_id = :r');
    $st->bindValue(':t', time(), SQLITE3_INTEGER);
    $st->bindValue(':r', $requestId, SQLITE3_TEXT);
    $st->execute();
}

/**
 * Anota el fallo y deja la pulsación en la cola para el siguiente intento.
 *
 * ⚠ SE RINDE AL LLEGAR AL TOPE, pero NO borra la fila: queda en 'fallida' para
 * poder mirarla. Un reintento infinito sobre algo que nunca va a funcionar
 * (un deal borrado, por ejemplo) gasta cuota del portal en cada vuelta.
 */
function cola_nc_fallo(SQLite3 $db, string $requestId, string $error, int $tope = COLA_NC_TOPE_INTENTOS): void {
    $st = $db->prepare('UPDATE cola_no_contesto
        SET intentos = intentos + 1, ultimo_intento = :t, ultimo_error = :e,
            estado = CASE WHEN intentos + 1 >= :tope THEN \'fallida\' ELSE \'encolada\' END
        WHERE request_id = :r');
    $st->bindValue(':t', time(), SQLITE3_INTEGER);
    $st->bindValue(':e', mb_substr($error, 0, 500), SQLITE3_TEXT);
    $st->bindValue(':tope', max(1, $tope), SQLITE3_INTEGER);
    $st->bindValue(':r', $requestId, SQLITE3_TEXT);
    $st->execute();
}

/**
 * El portal CONTESTO y denego el acceso. Eso ya descarta la saturacion: un portal
 * saturado devuelve 503, nunca ACCESS_DENIED.
 *
 * 🔴 Y lo reintenta la credencial DEL SERVICIO, no la sesion del vendedor (ver
 * drenar-no-contesto.php). O sea que si aca sigue denegando, tampoco es la sesion.
 * Descartadas las dos causas comunes, el veredicto se puede afirmar: al servicio
 * le falta acceso a ese deal. Eso NO es "que alguien lo mire": es una causa con
 * nombre, y queda escrita en la fila.
 */
function cola_nc_acceso_denegado(SQLite3 $db, string $requestId, string $error, int $dealId, int $tope): void {
    $st = $db->prepare('UPDATE cola_no_contesto
        SET intentos = intentos + 1, intentos_sanos = intentos_sanos + 1,
            ultimo_intento = :t, ultimo_error = :e,
            estado = CASE WHEN intentos_sanos + 1 >= :tope THEN \'bloqueada\' ELSE \'encolada\' END
        WHERE request_id = :r');
    $st->bindValue(':t', time(), SQLITE3_INTEGER);
    $st->bindValue(':e', mb_substr($error, 0, 500), SQLITE3_TEXT);
    $st->bindValue(':tope', max(1, $tope), SQLITE3_INTEGER);
    $st->bindValue(':r', $requestId, SQLITE3_TEXT);
    $st->execute();
}

/** Cuantos intentos con el portal sano lleva una fila. */
function cola_nc_intentos_sanos(SQLite3 $db, string $requestId): int {
    $st = $db->prepare('SELECT intentos_sanos FROM cola_no_contesto WHERE request_id = :r');
    $st->bindValue(':r', $requestId, SQLITE3_TEXT);
    $res = $st->execute();
    $row = $res ? $res->fetchArray(SQLITE3_ASSOC) : null;
    return (int)($row['intentos_sanos'] ?? 0);
}

/** Cuántas hay de cada estado — para el monitor y para mirarlo de un vistazo. */
function cola_nc_conteo(SQLite3 $db): array {
    $out = ['encolada' => 0, 'hecha' => 0, 'fallida' => 0];
    $r = $db->query('SELECT estado, COUNT(*) n FROM cola_no_contesto GROUP BY estado');
    while ($r && ($x = $r->fetchArray(SQLITE3_ASSOC))) $out[(string)$x['estado']] = (int)$x['n'];
    return $out;
}

/**
 * DRENA LA COLA: reproduce las pulsaciones guardadas.
 *
 * ⭐ Vive acá, y NO dentro de bin/drenar-no-contesto.php, para que la prueba
 * ejercite ESTE código y no una copia. Ya me pasó en el dashboard: la misma
 * lógica escrita dos veces divergió en silencio (hora de Ecuador en un lado,
 * hora del servidor en el otro) y los números no cuadraban con sus propias
 * listas. Una sola copia, dos llamadores: el cron y la prueba.
 *
 * ⭐⭐ Cada fila se reproduce con SU hora (`now_ts`), no con la de ahora.
 *
 * ⚠ Corta el lote al primer LlamadaBitrixError: si el portal sigue apretado,
 * insistir con las demás lo empuja más. Se espera el siguiente turno del cron.
 *
 * @param object        $store  LlamadaIdempotenciaStore (sin tipar para no acoplar la lib)
 * @param callable|null $log    recibe una línea de texto; null = silencio
 * @return array{hechas:int,fallidas:int,cortado:bool,vistas:int}
 */
/**
 * ¿Esta pulsacion YA dejo su actividad en Bitrix?
 *
 * 🔴 ES LO QUE CIERRA EL AGUJERO DE LOS DUPLICADOS, y subir el arriendo no lo cierra:
 * subirlo solo baja la probabilidad. Medido el 23-sep-2026 sobre 858 pulsaciones: 71
 * tardaron mas que el arriendo de 60 s y 38 dejaron actividad DUPLICADA (38 deals, 6
 * asesores). La peor tardo 3.015 s, o sea que ni 600 s cubren todo. Lo unico que lo
 * cierra es PREGUNTAR antes de escribir.
 *
 * Devuelve:
 *   true   ya existe -> la fila se marca hecha SIN volver a escribir
 *   false  no existe -> se reproduce, que es lo que corresponde
 *   null   NO SE PUDO COMPROBAR (Bitrix caido o 503)
 *
 * 🔴 EL `null` NO ES `false`. Si no se puede comprobar, NO se escribe en ese ciclo: se
 * espera al siguiente. Un guardia que no puede comprobar no puede dejar pasar -- si
 * ante la duda escribiera, volveriamos exactamente al duplicado que esto evita.
 *
 * LA VENTANA ES ESTRECHA A PROPOSITO. Se busca una actividad DEL MISMO ASESOR creada
 * entre 30 s antes de la pulsacion y el arriendo mas 5 minutos. Mas ancha y una llamada
 * legitima posterior al mismo deal se confundiria con esta, y nos saltariamos un
 * registro de trabajo real -- que es peor que un duplicado: al vendedor le borra una
 * llamada que si hizo.
 */
function cola_nc_ya_tiene_actividad(callable $bx, int $dealId, int $asesor, int $pulsada, int $arriendo = 600): ?bool
{
    if ($dealId <= 0) return false;
    $desde = $pulsada - 30;
    $hasta = $pulsada + $arriendo + 300;
    $r = $bx('crm.activity.list', [
        'filter' => ['OWNER_TYPE_ID' => 2, 'OWNER_ID' => $dealId],
        'select' => ['ID', 'CREATED', 'RESPONSIBLE_ID', 'TYPE_ID'],
        'order'  => ['ID' => 'DESC'],
    ]);
    if (empty($r['ok'])) return null;                  // no se pudo comprobar
    foreach ((array)($r['result'] ?? []) as $a) {
        $c = strtotime((string)($a['CREATED'] ?? ''));
        if (!$c || $c < $desde || $c > $hasta) continue;
        if ($asesor > 0 && (int)($a['RESPONSIBLE_ID'] ?? 0) !== $asesor) continue;
        return true;
    }
    return false;
}

function cola_nc_drenar(
    SQLite3 $db, callable $bx, object $store, string $stageEnv,
    int $lote = 10, ?callable $log = null
): array {
    $decir = $log ?? static function (string $_): void {};
    $pendientes = cola_nc_pendientes($db, $lote);
    $hechas = 0; $fallidas = 0; $bloqueadas = 0; $cortado = false;

    foreach ($pendientes as $p) {
        $rid = (string)$p['request_id'];
        $pulsada = (int)$p['now_ts'];
        $cuando = cola_nc_ec($pulsada);
        $input = json_decode((string)$p['input_json'], true);
        if (!is_array($input)) {
            cola_nc_fallo($db, $rid, 'input_json ilegible');
            $fallidas++;
            $decir("  ✗ $rid · input_json ilegible");
            continue;
        }
        $stage = (string)$p['stage'] !== '' ? (string)$p['stage'] : $stageEnv;

        /* ── ¿YA ESTA? ────────────────────────────────────────────────────────
         * Antes de reproducir, se pregunta. Cuesta UNA lectura por pulsacion
         * pendiente -- y las pendientes son pocas -- y evita cobrarle al asesor una
         * llamada que no hizo. */
        $yaEsta = cola_nc_ya_tiene_actividad(
            $bx, (int)($input['dealId'] ?? 0), (int)($input['bitrixUserId'] ?? 0), $pulsada);
        if ($yaEsta === true) {
            cola_nc_hecha($db, $rid);
            $hechas++;
            $decir("  ✔ $rid · pulsada $cuando · ya estaba en Bitrix, no se reescribe");
            continue;
        }
        if ($yaEsta === null) {
            $bloqueadas++;
            $decir("  ⏸ $rid · no se pudo comprobar si ya estaba: se espera al proximo ciclo");
            continue;
        }

        try {
            $r = llamada_procesar_resultado(
                $input, $bx, $store,
                new DateTimeImmutable('@' . $pulsada),   // ⭐ la hora de la PULSACIÓN
                $stage,
                (string)$p['source'] !== '' ? (string)$p['source'] : 'panel'
            );
            $estado = (string)($r['status'] ?? '');
            if ($estado === 'processed' || $estado === 'already_processed') {
                cola_nc_hecha($db, $rid);
                $hechas++;
                $decir("  ✔ $rid · pulsada $cuando · $estado");
                continue;
            }
            cola_nc_fallo($db, $rid, 'estado ' . ($estado !== '' ? $estado : 'desconocido'));
            $decir("  … $rid sigue en curso ($estado), se reintenta");
        } catch (LlamadaForbidden $e) {
            /* Dos caminos, porque "sin permiso" son dos cosas:
             *  · TRANSITORIO (ACCESS_DENIED = sesión vencida): se reintenta, con
             *    tope. La actividad es el comprobante del trabajo del vendedor:
             *    tirarla por una sesión vencida le borra una llamada que sí hizo.
             *  · PERMANENTE (los datos no cuadran): se marca y se sigue.
             * 🔴 El tope existe para que un permiso que de verdad no existe no se
             * reintente cada 2 minutos para siempre. Al agotarse queda marcada
             * para que una persona la mire, NO se borra. */
            if ($e->esTransitorio()) {
                /* EL PORTAL CONTESTO Y DENEGO. Eso descarta la saturacion por si
                 * solo: saturado devuelve 503, nunca ACCESS_DENIED. Y este reintento
                 * lo hizo la credencial DEL SERVICIO, no la sesion del vendedor, asi
                 * que tampoco es la sesion. Se cuenta aparte y, al agotarse, se dicta
                 * el veredicto con las dos causas ya descartadas. */
                $deal = (int)(json_decode((string)$p['input_json'], true)['dealId'] ?? 0);
                cola_nc_acceso_denegado($db, $rid, 'acceso denegado con el portal respondiendo: '
                    . $e->getMessage(), $deal, COLA_NC_TOPE_ACCESO);
                $sanos = cola_nc_intentos_sanos($db, $rid);
                if ($sanos >= COLA_NC_TOPE_ACCESO) {
                    $bloqueadas++;
                    $decir("  ⛔ $rid BLOQUEADA · deal $deal · el portal respondió y negó el acceso"
                           . " $sanos veces con la credencial del servicio."
                           . " NO es saturación (saturado da 503) NI la sesión del vendedor"
                           . " (el drenador no la usa). Falta acceso al deal $deal.");
                } else {
                    $decir("  … $rid acceso denegado, intento $sanos de " . COLA_NC_TOPE_ACCESO
                           . " con el portal sano (deal $deal)");
                }
            } else {
                cola_nc_fallo($db, $rid, 'forbidden: ' . $e->getMessage(), 1);
                $fallidas++;
                $decir("  ✗ $rid datos que no cuadran, no se reintenta");
            }
        } catch (LlamadaBitrixError $e) {
            cola_nc_fallo($db, $rid, $e->getMessage());
            $cortado = true;
            $decir("  ⏸ $rid · Bitrix sigue apretado ({$e->getMessage()}) — corto el lote");
            break;
        } catch (Throwable $e) {
            cola_nc_fallo($db, $rid, get_class($e) . ': ' . $e->getMessage());
            $fallidas++;
            $decir("  ✗ $rid · " . $e->getMessage());
        }
    }
    return ['hechas' => $hechas, 'fallidas' => $fallidas, 'bloqueadas' => $bloqueadas,
            'cortado' => $cortado, 'vistas' => count($pendientes)];
}

/**
 * La hora de la pulsación en hora de ECUADOR, para leerla en el log.
 *
 * ⚠ El contenedor corre en UTC: `date()` a secas muestra 5 horas adelantado y
 * uno cree que el vendedor apretó a las 18:55 cuando apretó a las 13:55.
 */
function cola_nc_ec(int $ts): string {
    return (new DateTimeImmutable('@' . $ts))
        ->setTimezone(new DateTimeZone('America/Guayaquil'))
        ->format('Y-m-d H:i');
}

/**
 * Borra las filas ya resueltas viejas.
 *
 * Ahora TODA pulsación deja una fila (se guarda antes de intentar), así que sin
 * esto la libreta crecería para siempre: ~2.000 pulsaciones al día. Solo se borran
 * las `hecha`: las `fallida` se quedan para poder mirarlas.
 *
 * @return int filas borradas
 */
function cola_nc_limpiar(SQLite3 $db, int $dias = 7): int {
    $corte = time() - max(1, $dias) * 86400;
    $st = $db->prepare('DELETE FROM cola_no_contesto WHERE estado = \'hecha\' AND creada < :corte');
    $st->bindValue(':corte', $corte, SQLITE3_INTEGER);
    $st->execute();
    return $db->changes();
}
