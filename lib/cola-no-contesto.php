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

const COLA_NC_ARCHIVO = 'cola-no-contesto.sqlite';
const COLA_NC_TOPE_INTENTOS = 60;   // ~2 h a un intento cada 2 min

/** Abre (y crea si hace falta) la libreta de la cola. */
function cola_nc_db(string $dataDir): SQLite3 {
    $db = new SQLite3(rtrim($dataDir, '/') . '/' . COLA_NC_ARCHIVO);
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
function cola_nc_drenar(
    SQLite3 $db, callable $bx, object $store, string $stageEnv,
    int $lote = 10, ?callable $log = null
): array {
    $decir = $log ?? static function (string $_): void {};
    $pendientes = cola_nc_pendientes($db, $lote);
    $hechas = 0; $fallidas = 0; $cortado = false;

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
            // sin permiso NO se reintenta para siempre: se marca y se sigue
            cola_nc_fallo($db, $rid, 'forbidden: ' . $e->getMessage(), 1);
            $fallidas++;
            $decir("  ✗ $rid sin permiso, no se reintenta");
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
    return ['hechas' => $hechas, 'fallidas' => $fallidas,
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
