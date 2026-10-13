<?php
/**
 * historialib.php — la historia de Instagram se genera SOLA al reservar.
 * ---------------------------------------------------------------------------
 * Antes: el vendedor ataba la unidad al deal (el sello ya aparecía solo en el
 * plano, vía noral_avisar_generador) pero alguien tenía que entrar al generador,
 * apretar Generar y bajarse la imagen. Ese paso a mano es el atraso.
 *
 * Ahora: cuando un deal del pipeline 44 ENTRA a RESERVA, se genera la historia
 * con sus unidades ya marcadas y le llega una notificación a quien corresponda.
 *
 * ── POR QUE UNA LIBRETA ────────────────────────────────────────────────────
 * ONCRMDEALUPDATE dispara con CUALQUIER cambio del deal, no solo al mover la
 * etapa. Sin memoria de lo ya hecho, cada vez que alguien toque un deal que está
 * en RESERVA llegaría otra historia. La libreta guarda el deal y con qué unidades
 * se generó: si vuelve igual, no se hace nada; si le agregaron una unidad, sí.
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

const HIST_ETAPA_RESERVA = 'C44:NEW';        // en el pipeline 44, RESERVA

/* Las DOS unicas etapas del 44 que significan "la unidad se libero". Leidas del
   portal el 28-ago-2026 con historia.php?etapas=1, no supuestas:
     C44:NEW RESERVA · UC_Z3GY5H ELABORACION PROMESA · UC_4R587H LISTO PARA FIRMA
     UC_2CE2UE PROMESA FIRMADA · UC_N637MD REVENTAS-RESERVA · PREPARATION CANJE
     WON CIERRE DE PROMESA · UC_W4OOQY REVENTAS-CESIONES   -> todas SIGUEN vendidas
     LOSE RESERVAS CAIDAS · APOLOGY FIRMADOS-CAIDOS        -> se libero

   🔴 Se enumeran las CAIDAS y no las vivas a proposito. Si mañana alguien agrega
   una etapa al pipeline, con esta lista la historia SE QUEDA (a lo sumo sobra una);
   con la lista al reves se retiraria sola una historia valida. Ante lo desconocido,
   el lado que no destruye. */
const HIST_ETAPAS_LIBERADA = ['C44:LOSE', 'C44:APOLOGY'];
const HIST_CAT_CLIENTES  = 44;

/** Libreta de lo ya generado: deal => huella de sus unidades. */
function hist_libreta_ruta(): string {
    return (getenv('DATA_DIR') ?: '/data') . '/historias_generadas.json';
}
function hist_libreta(): array {
    $f = hist_libreta_ruta();
    $j = json_decode((string)@file_get_contents($f), true);
    return is_array($j) ? $j : [];
}
function hist_libreta_guardar(array $l): void {
    @file_put_contents(hist_libreta_ruta(), json_encode($l), LOCK_EX);
}

/**
 * Olvida lo generado para un deal. Se llama al SOLTAR una unidad.
 *
 * Sin esto quedaba un hueco: si a un deal se le quita una unidad y despues se le
 * vuelve a poner la misma, la huella regresa al valor anterior, la libreta dice
 * "ya generada" y no se genera nada -- pero la historia vieja ya fue retirada del
 * buzon al desmarcar. Resultado: reserva viva y ninguna historia en ninguna parte.
 */
function hist_libreta_olvidar(int $dealId): void {
    hist_marcas_olvidar_deal($dealId);   // si vuelve la misma unidad, se vuelve a hacer
    $l = hist_libreta();
    if (!array_key_exists((string)$dealId, $l)) return;
    unset($l[(string)$dealId]);
    hist_libreta_guardar($l);
    logline("HISTORIA libreta olvida deal=$dealId (se solto una unidad)");
}

/* ── MARCAS "unidad|deal": UNA historia por venta, venga por donde venga ─────────
 * Desde el 12-oct-2026 hay DOS disparadores: el de siempre (el deal entra a RESERVA,
 * hist_al_reservar) y el de la unidad (se vende o se reubica, hist_procesar_pendientes).
 * En una reserva normal llegan los dos, en cualquier orden. La marca se RECLAMA bajo
 * candado antes de generar: el primero que llega la toma y el otro no hace nada.
 * RESERVADO -> FIRMADO -> VENDIDO del mismo deal es la misma marca: una sola historia. */
function hist_marcas_ruta(): string {
    return (getenv('DATA_DIR') ?: '/data') . '/historias_marcas.json';
}
/* 🔴 DOS USUARIOS escriben este archivo: Apache (www-data, el aviso de Bitrix) y el
   reloj de 5 min (root). Si lo crea root con 0644, Apache ya no puede escribirlo — paso
   igual con la cola del boton "No contesto" el 9-sep. Por eso se deja en 0666 al abrirlo,
   y si aun asi no se puede abrir, quien llama GENERA igual (ver hist_marca_reclamar). */
function hist_marcas_mutar(callable $fn, ?string $ruta = null) {
    $ruta = $ruta ?? hist_marcas_ruta();
    $fh = @fopen($ruta, 'c+');
    if (!$fh) return null;
    @chmod($ruta, 0666);
    flock($fh, LOCK_EX);
    $m = json_decode((string)stream_get_contents($fh), true);
    if (!is_array($m)) $m = [];
    $res = $fn($m);
    ftruncate($fh, 0); rewind($fh);
    fwrite($fh, json_encode($m));
    flock($fh, LOCK_UN); fclose($fh);
    return $res;
}
/** true si ESTA llamada se quedo con la marca; false si ya la tenia otro.
 *  Si el archivo no se puede abrir, true: una historia de mas se ve y se borra; una
 *  historia perdida no se entera nadie. Queda en el log. */
function hist_marca_reclamar(string $cod, int $deal, ?string $ruta = null): bool {
    $k = strtoupper(trim($cod)) . '|' . $deal;
    $r = hist_marcas_mutar(function (array &$m) use ($k) {
        if (isset($m[$k])) return false;
        $m[$k] = gmdate('c');
        return true;
    }, $ruta);
    if ($r === null) {
        if (function_exists('logline')) logline("HISTORIA marcas: no se pudo abrir el archivo, se genera igual ($k)");
        return true;
    }
    return (bool)$r;
}
function hist_marca_existe(string $cod, int $deal, ?string $ruta = null): bool {
    $m = json_decode((string)@file_get_contents($ruta ?? hist_marcas_ruta()), true);
    return is_array($m) && isset($m[strtoupper(trim($cod)) . '|' . $deal]);
}
function hist_marca_soltar(string $cod, int $deal, ?string $ruta = null): void {
    $k = strtoupper(trim($cod)) . '|' . $deal;
    hist_marcas_mutar(function (array &$m) use ($k) { unset($m[$k]); }, $ruta);
}
function hist_marcas_olvidar_deal(int $deal, ?string $ruta = null): void {
    hist_marcas_mutar(function (array &$m) use ($deal) {
        foreach (array_keys($m) as $k) if (str_ends_with($k, '|' . $deal)) unset($m[$k]);
    }, $ruta);
}

/**
 * Procesa las ventas de unidad anotadas por vend_anotar_historia (vendidaslib.php):
 * reubicaciones y cualquier venta que no paso por "deal entra a RESERVA".
 * Lo llama el reloj de 5 min (historia.php?conciliar). Devuelve lineas para el log.
 *
 * Por cada pendiente, en este orden:
 *  - proyecto sin plano (solo Plaza y Apartments)  -> se descarta
 *  - mas de 2 dias anotado                          -> se descarta (no se publica viejo)
 *  - la unidad YA NO esta vendida a ese deal         -> se descarta (la reserva se cayo)
 *  - la marca unidad|deal ya la tiene otro          -> se descarta (ya hay historia)
 *  - si no: se genera, se encola (la cola espera 5 min y vuelve a mirar si sigue viva)
 * Tope por corrida: $tope. Lo que sobra espera la siguiente vuelta.
 *
 * $catalogo, $generar y $encolar se inyectan para poder probarlo sin red.
 */
function hist_procesar_pendientes(array $catalogo, callable $generar, callable $encolar,
                                  int $tope = 5, ?int $ahora = null,
                                  ?string $rutaPend = null, ?string $rutaMarcas = null): array {
    $ahora = $ahora ?? time();
    $rutaPend = $rutaPend ?? ((getenv('DATA_DIR') ?: '/data') . '/historias_unidad_pendientes.json');
    $fh = @fopen($rutaPend, 'c+');
    if (!$fh) return ["HISTORIA-U no se pudo abrir $rutaPend"];
    @chmod($rutaPend, 0666);
    flock($fh, LOCK_EX);
    $pend = json_decode((string)stream_get_contents($fh), true);
    if (!is_array($pend)) $pend = [];

    $porCod = [];
    foreach ((array)($catalogo['units'] ?? []) as $u)
        $porCod[(int)($u['cat'] ?? 0) . '|' . strtoupper((string)($u['codigo'] ?? ''))] = $u;
    $vendida = ['RESERVADO', 'FIRMADO', 'VENDIDO'];

    $log = []; $hechas = 0;
    foreach ($pend as $k => $p) {
        $cod = (string)$p['unidad']; $deal = (int)$p['deal']; $cat = (int)$p['cat'];
        $proy = NORAL_PROY[$cat] ?? null;
        if ($proy === null) { unset($pend[$k]); continue; }               // sin plano: silencio
        if ($ahora - (int)$p['cuando'] > 2 * 86400) { unset($pend[$k]); $log[] = "HISTORIA-U $cod deal=$deal descartada: anotada hace mas de 2 dias"; continue; }
        $u = $porCod[$cat . '|' . strtoupper($cod)] ?? null;
        if (!$u || !in_array(strtoupper((string)($u['stage'] ?? '')), $vendida, true) || (int)($u['dealId'] ?? 0) !== $deal) {
            unset($pend[$k]); $log[] = "HISTORIA-U $cod deal=$deal descartada: ya no esta vendida a ese deal"; continue;
        }
        if ($hechas >= $tope) continue;                                   // la siguiente vuelta
        if (!hist_marca_reclamar($cod, $deal, $rutaMarcas)) { unset($pend[$k]); $log[] = "HISTORIA-U $cod deal=$deal ya tenia historia"; continue; }
        $hechas++;
        $r = $generar($proy, $cod);
        $video = hist_solo_archivo((string)($r['video'] ?? ''));
        if (!$r || $video === '') {
            hist_marca_soltar($cod, $deal, $rutaMarcas);                  // que se pueda reintentar
            $pend[$k]['intentos'] = (int)($p['intentos'] ?? 0) + 1;
            if ($pend[$k]['intentos'] >= 3) { unset($pend[$k]); $log[] = "HISTORIA-U $cod deal=$deal FALLO 3 veces, se abandona"; }
            else $log[] = "HISTORIA-U $cod deal=$deal el generador no devolvio video (intento {$pend[$k]['intentos']})";
            continue;
        }
        $encolar($video, (string)($r['etiqueta'] ?? $cod));
        unset($pend[$k]);
        $log[] = "HISTORIA-U $cod deal=$deal generada y encolada (" . (string)($p['origen'] ?? '') . ")";
    }
    ftruncate($fh, 0); rewind($fh);
    fwrite($fh, json_encode($pend));
    flock($fh, LOCK_UN); fclose($fh);
    return $log;
}

/** Codigos de Plaza/Apartments que el CATALOGO da por vendidos. Cero llamadas.
 *  Se suman a los "vivos" de la cola: una reubicacion queda con el deal en
 *  elaboracion de promesa, no en RESERVA, y sin esto la cola la daba por caida. */
function hist_codigos_vendidos_catalogo(?array $catalogo = null): array {
    if ($catalogo === null) {
        $catalogo = json_decode((string)@file_get_contents((getenv('DATA_DIR') ?: '/data') . '/selector_cache.json'), true);
        if (!is_array($catalogo)) return [];
    }
    $c = [];
    foreach ((array)($catalogo['units'] ?? []) as $u) {
        if (!isset(NORAL_PROY[(int)($u['cat'] ?? 0)])) continue;
        if (!in_array(strtoupper((string)($u['stage'] ?? '')), ['RESERVADO', 'FIRMADO', 'VENDIDO'], true)) continue;
        if ((int)($u['dealId'] ?? 0) <= 0) continue;
        $c[hist_codigo_con_proyecto($u)] = true;
    }
    return array_keys($c);
}

/**
 * "39:D-2-4" — el codigo CON su proyecto. 13-oct-2026: D-2-4 y E-1-6 existen en Noral
 * Plaza (33) y en Noral Apartments (39). El generador recibia "D-2-4" a secas, lo
 * buscaba en los planos de los dos proyectos juntos, lo encontraba en Plaza, y la
 * historia de Apartments (D2.4) no figuraba como viva: la cola la descartaba como
 * "reserva caida". Paso con D-2-4 (deal 6364) y E-1-6 (deal 6644). Lo encontro y lo
 * reprodujo la sesion de cobranzas. El generador (conciliar.php) busca cada codigo SOLO
 * en el plano de su proyecto.
 */
function hist_codigo_con_proyecto(array $u): string {
    $c = strtoupper(trim((string)($u['codigo'] ?? '')));
    return $c === '' ? '' : (int)($u['cat'] ?? 0) . ':' . $c;
}

/**
 * Le pide al generador la historia de una unidad. Devuelve la URL o null.
 * El generador traduce el código a su propia nomenclatura y estampa el sello.
 */
function hist_generar_una(string $proyecto, string $codigo): ?array {
    $url = rtrim((string)getenv('NORAL_URL'), '/');
    $tok = (string)getenv('NORAL_SYNC_TOKEN');
    if ($url === '' || $tok === '') return null;

    /**
     * 🔴 Se pide a `video.php`, NO a `generar.php`.
     *
     * `generar.php` hace solo el JPG. Lo que el equipo sube a Instagram es el MP4
     * con el sello animado, que es lo que hace el boton "Generar videos" del panel
     * — o sea la automatica entregaba media historia y alguien tenia que ir al
     * panel a completar lo que ya deberia estar hecho.
     *
     * `video.php` recibe los MISMOS parametros y hace las dos cosas: escribe el JPG
     * y despues compone el MP4 con ffmpeg, y registra en el historial igual.
     *
     * Si ffmpeg falla, `video.php` responde ok:false — pero el JPG ya quedo escrito
     * antes de llegar a ffmpeg. Por eso se cae de vuelta a `generar.php`: quedarse
     * sin nada porque no se pudo armar el video seria peor que quedarse con la
     * imagen.
     */
    $pedir = function (string $endpoint, int $timeout) use ($url, $proyecto, $codigo, $tok): ?array {
        $ch = curl_init($url . '/' . $endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(
                ['proyecto' => $proyecto, 'id' => $codigo, 'token' => $tok]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $raw = (string)curl_exec($ch);
        curl_close($ch);
        $j = json_decode($raw, true);
        if (!is_array($j) || empty($j['ok'])) {
            logline("HISTORIA $endpoint $codigo -> " . substr($raw, 0, 160));
            return null;
        }
        return $j;
    };

    // 45 frames de overlay mas ffmpeg: bastante mas que estampar un JPG
    $j = $pedir('video.php', 150);
    if ($j !== null) {
        // video.php devuelve `image` y `video`; el resto del flujo espera `url`
        $j['url']   = (string)($j['image'] ?? '');
        $j['video'] = (string)($j['video'] ?? '');
    } else {
        $j = $pedir('generar.php', 45);
        if ($j !== null) logline("HISTORIA $codigo sin video: se entrega solo la imagen");
    }
    if ($j === null || ($j['url'] ?? '') === '') return null;

    // Las urls que devuelve son relativas ("salidas/x.jpg"): se absolutizan aca, que
    // es donde se sabe cual es el host del generador.
    $j['url_abs'] = $url . '/' . ltrim((string)$j['url'], '/');
    if (($j['video'] ?? '') !== '') $j['video_abs'] = $url . '/' . ltrim((string)$j['video'], '/');
    return $j;
}

/**
 * Avisa por notificación de Bitrix. Se eligió esto y no correo ni WhatsApp porque
 * no necesita ninguna credencial nueva y el aviso queda dentro del portal.
 */
function hist_notificar(int $userId, string $texto): bool {
    $r = bx('im.notify.personal.add', ['USER_ID' => $userId, 'MESSAGE' => $texto]);
    if (!($r['ok'] ?? false)) logline('HISTORIA notificar -> ' . ($r['error'] ?? '?'));
    return (bool)($r['ok'] ?? false);
}

/**
 * El trabajo completo para un deal. Devuelve un resumen para el log.
 * `$forzar` salta la libreta: sirve para regenerar a mano una que salió mal.
 */
/**
 * Intenta generar la historia desde CUALQUIER camino que sincronice el deal.
 *
 * 🔴 Antes esto vivia solo en hook.php, o sea solo cuando Bitrix mandaba el evento
 * del deal. Pero `sincronizar_deal()` se llama desde tres lados, y atar la unidad
 * desde la app (guardar.php) NO mueve el deal: se ponia el sello en el plano y la
 * historia nunca se generaba. El vendedor ataba la unidad de un deal que YA estaba
 * en RESERVA y no aparecia nada en el buzon.
 *
 * `hist_al_reservar()` valida sola el pipeline 44 y la etapa RESERVA, asi que
 * llamarla desde todos los caminos es seguro: si no corresponde, no hace nada.
 *
 * Va envuelto: si el generador esta caido, guardar la unidad NO se cae por eso.
 */
function hist_intentar(string $dealId, ?array $deal, string $origen): void {
    try {
        $h = hist_al_reservar($dealId, $deal);
        $m = (string)($h['motivo'] ?? '');
        // los "no aplica" son la mayoria y taparian el log
        if (!in_array($m, ['ya generada para estas unidades', 'no es del pipeline 44',
                           'no esta en RESERVA'], true)) {
            logline("$origen deal=$dealId historia=" . json_encode($h, JSON_UNESCAPED_SLASHES));
        }
    } catch (Throwable $e) {
        logline("$origen deal=$dealId historia FALLO: " . $e->getMessage());
    }
}

function hist_al_reservar(string $dealId, ?array $deal = null, bool $forzar = false): array {
    if ($deal === null) {
        $g = bx('crm.deal.get', ['id' => $dealId]);
        if (!($g['ok'] ?? false)) return ['ok' => false, 'motivo' => 'no se pudo leer el deal'];
        $deal = $g['result'];
    }
    if ((int)($deal['CATEGORY_ID'] ?? -1) !== HIST_CAT_CLIENTES)
        return ['ok' => true, 'motivo' => 'no es del pipeline 44'];
    if ((string)($deal['STAGE_ID'] ?? '') !== HIST_ETAPA_RESERVA)
        return ['ok' => true, 'motivo' => 'no esta en RESERVA'];

    // Unidades del deal (parentId2 + campo Inventario + PARENT_ID_1072)
    $ids = units_of_clientes_deal($dealId, $deal);
    if (!$ids) return ['ok' => true, 'motivo' => 'sin unidades atadas'];

    // Solo los dos proyectos que tienen plano en el generador
    $unis = [];
    foreach ($ids as $uid) {
        $it = bx('crm.item.get', ['entityTypeId' => 1072, 'id' => $uid]);
        if (!($it['ok'] ?? false)) continue;
        $u   = $it['result']['item'] ?? [];
        $cat = (int)($u['categoryId'] ?? 0);
        $proy = NORAL_PROY[$cat] ?? null;
        if ($proy === null) continue;               // Sun Bay, Galero, Barranca: sin plano
        $cod = trim(explode('(', (string)($u['title'] ?? ''))[0]);
        if ($cod !== '') $unis[] = ['proy' => $proy, 'cod' => $cod];
    }
    if (!$unis) return ['ok' => true, 'motivo' => 'ninguna unidad tiene plano en el generador'];

    // Libreta: misma huella => ya se hizo
    $huella = md5(json_encode($unis));
    $lib = hist_libreta();
    if (!$forzar && ($lib[$dealId]['huella'] ?? '') === $huella)
        return ['ok' => true, 'motivo' => 'ya generada para estas unidades'];

    // Se deduplica por la CELDA que devuelve el generador, no por el codigo: un
    // local unido (F-1-13 + F-1-14) son dos registros en Bitrix y una sola celda
    // F1-13.14 en el plano. Sin esto llegaba la misma imagen dos veces.
    $urls = []; $vistas = []; $yaPorUnidad = 0;
    foreach ($unis as $u) {
        /* Marca unidad|deal: si el disparador por unidad ya la hizo, no se repite.
           `forzar` (rehacer a mano) la pasa por alto a proposito. */
        if (!$forzar && !hist_marca_reclamar($u['cod'], (int)$dealId)) { $yaPorUnidad++; continue; }
        if ($forzar) hist_marca_reclamar($u['cod'], (int)$dealId);
        $r = hist_generar_una($u['proy'], $u['cod']);
        if (!$r) { hist_marca_soltar($u['cod'], (int)$dealId); continue; }   // que se pueda reintentar
        $celda = (string)($r['etiqueta'] ?? $u['cod']);
        if (isset($vistas[$celda])) continue;
        $vistas[$celda] = true;
        $urls[] = ['cod' => $celda, 'url' => $r['url_abs'],
                   // 🔴 El generador devuelve `video_X.mp4?t=1787946132` — la coletilla
                   // anti-cache sirve para el navegador, NO es parte del nombre del
                   // archivo. basename() no la quita, asi que el encolado buscaba un
                   // archivo llamado "video_X.mp4?t=..." y daba 404. Paso con E2-18.
                   'video' => hist_solo_archivo((string)($r['video'] ?? ''))];
    }
    if (!$urls && $yaPorUnidad > 0 && $yaPorUnidad === count($unis)) {
        $lib[$dealId] = ['huella' => $huella, 'fecha' => gmdate('c'), 'unidades' => '(por unidad)', 'n' => 0];
        hist_libreta_guardar($lib);
        return ['ok' => true, 'motivo' => 'ya generada por unidad'];
    }
    if (!$urls) {
        // nada salio: se sueltan las marcas para que el proximo aviso lo reintente
        foreach ($unis as $u) hist_marca_soltar($u['cod'], (int)$dealId);
        return ['ok' => false, 'motivo' => 'el generador no devolvio ninguna imagen'];
    }

    // ── publicar en Instagram ────────────────────────────────────────────────
    /* Se le pide al GENERADOR que publique, no se publica desde aca. Tres razones:
       la llave de Vista Social vive alla y no tiene por que viajar; la lista blanca
       del perfil tambien; y el interruptor lo maneja el equipo desde el buzon, que es
       la misma pantalla donde ve las historias.
       🔴 Y NO se publica en el acto: se ENCOLA. Una reserva se cae en minutos —alguien
       ata la unidad equivocada y la suelta— y una historia publicada ya no se baja
       (Instagram no deja borrarlas por nuestra via; caducan solas a las 24 h). Ver
       colalib.php en el generador. */
    foreach ($urls as $u) {
        if (($u['video'] ?? '') === '') continue;   // sin video no hay historia que subir
        hist_encolar($u['video'], $u['cod']);
    }

    // A quien avisar: al dueño del aviso configurado, no al responsable del deal —
    // la historia es material de marketing, no del vendedor.
    $dest = (int)(getenv('HISTORIA_AVISAR_A') ?: 0);
    $codigos = implode(', ', array_column($urls, 'cod'));
    if ($dest > 0) {
        $txt = "[B]Historia lista[/B] — reserva de {$codigos}\n"
             . "Deal #{$dealId}: " . (string)($deal['TITLE'] ?? '') . "\n";
        foreach ($urls as $u) $txt .= "[URL={$u['url']}]{$u['cod']}[/URL]\n";
        hist_notificar($dest, $txt);
    }

    $lib[$dealId] = ['huella' => $huella, 'fecha' => gmdate('c'),
                     'unidades' => $codigos, 'n' => count($urls)];
    hist_libreta_guardar($lib);
    logline("HISTORIA deal {$dealId} · {$codigos} · " . count($urls) . ' imagen(es)');
    return ['ok' => true, 'motivo' => 'generada', 'unidades' => $codigos, 'urls' => $urls];
}

/**
 * Le pide al generador que suba la historia a Instagram. Fire-and-forget.
 *
 * Timeout corto y errores tragados A PROPOSITO: si el generador esta caido o Vista
 * Social no contesta, la RESERVA en Bitrix no se puede caer por eso. La historia
 * queda en el buzon y alguien la sube a mano; el negocio sigue.
 *
 * El interruptor NO se consulta aca: lo aplica el generador (`auto=1`). Tener la
 * decision en dos sitios es tener dos verdades.
 */
/** Deja solo el nombre del archivo: sin ruta y sin `?t=...`. */
function hist_solo_archivo(string $v): string {
    $v = explode('?', $v, 2)[0];
    $v = explode('#', $v, 2)[0];
    return basename(rawurldecode($v));
}

function hist_encolar(string $archivo, string $unidad): void {
    // Red de seguridad: aunque el llamador ya lo limpie, aca se vuelve a limpiar.
    // Es el unico punto por el que pasan todos los caminos hacia la cola.
    $archivo = hist_solo_archivo($archivo);
    $base = rtrim((string)getenv('NORAL_URL'), '/');
    $tok  = (string)getenv('NORAL_SYNC_TOKEN');
    if ($base === '' || $tok === '') return;        // sin config, no se intenta

    $url = $base . '/publicar.php?encolar=1'
         . '&a=' . rawurlencode($archivo) . '&u=' . rawurlencode($unidad)
         . '&token=' . rawurlencode($tok);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
                            CURLOPT_CONNECTTIMEOUT => 5]);
    $raw = (string)curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $j = json_decode($raw, true);
    if (!empty($j['ok']))  logline("HISTORIA $unidad en cola, sale en "
                                 . (int)(($j['espera_seg'] ?? 300) / 60) . " min"
                                 . (!empty($j['ya_estaba']) ? ' (ya estaba encolada)' : ''));
    else                   logline("HISTORIA $unidad no se pudo encolar: http=$http "
                                 . (string)($j['error'] ?? substr($raw, 0, 120)));
}

/**
 * Los CODIGOS de unidad que hoy tienen una reserva viva en CLIENTES(44).
 *
 * ── POR QUE ASI Y NO DE OTRA FORMA ────────────────────────────────────────
 * Cuesta UNA llamada, y no crece con el numero de historias. La tentacion es
 * preguntar por cada historia "¿sigue valida?": son 68 llamadas hoy y 300 en un mes,
 * y el portal ya vive cerca de su techo. Aca se hace UNA pregunta —"¿que deals estan
 * en RESERVA?"— pidiendo de paso el campo Inventario en el mismo `select`, y el mapa
 * de id a codigo sale del catalogo compartido, que no cuesta nada.
 *
 * 🔴 El disparador NO puede ser el campo. Bitrix manda `desmarcar` y `marcar` seguidos
 * al editar un deal, y reaccionar a eso se llevaba historias de reservas vivas. Lo que
 * manda es la ETAPA del deal, que es lo que se pregunta aca.
 *
 * Devuelve null si la lectura FALLA: null significa "no se pudo saber", y quien llame
 * tiene que frenar. Una lista vacia significaria "no hay ninguna reserva" y retiraria
 * todas las historias del buzon.
 */
/**
 * Los codigos de unidad que se LIBERARON hace poco.
 * ---------------------------------------------------------------------------
 * 🔴 POR QUE ESTA Y NO LA DE "EN RESERVA". Antes se retiraba una historia por
 * AUSENCIA: si la unidad no aparecia en la lista de RESERVA, fuera. Pero la
 * ausencia tiene causas inocentes — el deal AVANZO a elaboracion de promesa (paso
 * con B-1-10 y su historia desaparecio aunque la unidad sigue vendida), la lectura
 * fallo, o la consulta se quedo corta. Ahora se retira solo por PRESENCIA en una
 * etapa de caida, que es un hecho, no una falta de dato.
 *
 * Solo se miran las movidas en los ultimos $dias: una liberada hace un mes ya se
 * retiro en su momento, y pedir el historico entero costaria decenas de paginas
 * cada 5 minutos. Las etapas de caida acumulan cientos de deals viejos.
 *
 * 🔴 `>MOVED_TIME` y no `>=`: el `=` parte el par en el POST y Bitrix devuelve TODO
 * ignorando el filtro. Ya costo caro antes.
 *
 * Devuelve null si NO se pudo saber. Con null quien llame no debe tocar nada: una
 * lista vacia significa "no se libero ninguna", que es lo normal.
 */
function hist_codigos_liberados(int $dias = 7): ?array {
    $desde = (new DateTime("-{$dias} days", new DateTimeZone('UTC')))->format('c');
    $ids = []; $start = 0;
    do {
        $r = bx('crm.deal.list', [
            'filter' => ['CATEGORY_ID' => HIST_CAT_CLIENTES,
                         'STAGE_ID'    => HIST_ETAPAS_LIBERADA,
                         '>MOVED_TIME' => $desde],
            'select' => ['ID', CAMPO_NUEVO, 'PARENT_ID_1072'],
            'order'  => ['ID' => 'DESC'],
            'start'  => $start,
        ]);
        if (!($r['ok'] ?? false)) return null;          // no se pudo saber: frenar
        foreach (($r['result'] ?? []) as $d) {
            foreach (preg_split('/[,;\s]+/', (string)($d[CAMPO_NUEVO] ?? '')) as $x)
                if (ctype_digit(trim($x)) && (int)$x > 0) $ids[(int)$x] = true;
            $p = (int)($d['PARENT_ID_1072'] ?? 0);
            if ($p > 0) $ids[$p] = true;
        }
        $start = (int)($r['next'] ?? 0);
    } while ($start > 0 && count($ids) < 2000);

    if (!$ids) return [];        // ninguna liberada: es un resultado valido

    $f = (getenv('DATA_DIR') ?: '/data') . '/selector_cache.json';
    $j = json_decode((string)@file_get_contents($f), true);
    if (!is_array($j) || empty($j['units'])) return null;   // sin catalogo no se sabe

    $cods = [];
    foreach ($j['units'] as $u) {
        $id = (int)($u['id'] ?? 0);
        if ($id > 0 && isset($ids[$id])) {
            $c = hist_codigo_con_proyecto($u);
            if ($c !== '') $cods[$c] = true;
        }
    }
    return array_keys($cods);
}

function hist_codigos_en_reserva(): ?array {
    // CAMPO_NUEVO vive en campolib.php. No se duplica el id del campo aca: ya hay 8
    // copias sueltas en el repo y cada una es una oportunidad de que se desincronicen.
    // Depender de campolib no agrega nada: esta funcion ya necesita bx(), que esta ahi.
    $ids = [];
    $start = 0;
    do {
        $r = bx('crm.deal.list', [
            'filter' => ['CATEGORY_ID' => HIST_CAT_CLIENTES, 'STAGE_ID' => HIST_ETAPA_RESERVA],
            'select' => ['ID', CAMPO_NUEVO, 'PARENT_ID_1072'],
            'order'  => ['ID' => 'DESC'],
            'start'  => $start,
        ]);
        if (!($r['ok'] ?? false)) return null;          // no se pudo saber: frenar
        foreach (($r['result'] ?? []) as $d) {
            foreach (preg_split('/[,;\s]+/', (string)($d[CAMPO_NUEVO] ?? '')) as $x)
                if (ctype_digit(trim($x)) && (int)$x > 0) $ids[(int)$x] = true;
            $p = (int)($d['PARENT_ID_1072'] ?? 0);
            if ($p > 0) $ids[$p] = true;
        }
        $start = (int)($r['next'] ?? 0);
    } while ($start > 0 && count($ids) < 2000);

    // id -> codigo con el catalogo compartido: CERO llamadas
    $f = (getenv('DATA_DIR') ?: '/data') . '/selector_cache.json';
    $j = json_decode((string)@file_get_contents($f), true);
    if (!is_array($j) || empty($j['units'])) return null;   // sin catalogo tampoco se sabe

    $cods = [];
    foreach ($j['units'] as $u) {
        $id = (int)($u['id'] ?? 0);
        if ($id > 0 && isset($ids[$id])) {
            $c = hist_codigo_con_proyecto($u);
            if ($c !== '') $cods[$c] = true;
        }
    }
    return array_keys($cods);
}
