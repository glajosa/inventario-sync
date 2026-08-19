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
 * Le pide al generador la historia de una unidad. Devuelve la URL o null.
 * El generador traduce el código a su propia nomenclatura y estampa el sello.
 */
function hist_generar_una(string $proyecto, string $codigo): ?array {
    $url = rtrim((string)getenv('NORAL_URL'), '/');
    $tok = (string)getenv('NORAL_SYNC_TOKEN');
    if ($url === '' || $tok === '') return null;

    $ch = curl_init($url . '/generar.php');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(
            ['proyecto' => $proyecto, 'id' => $codigo, 'token' => $tok]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 45,          // estampar una imagen tarda mas que un aviso
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $raw = (string)curl_exec($ch);
    $j = json_decode($raw, true);
    if (!is_array($j) || empty($j['ok']) || empty($j['url'])) {
        logline('HISTORIA generar ' . $codigo . ' -> ' . substr($raw, 0, 120));
        return null;
    }
    // La url que devuelve es relativa ("salidas/x.jpg"): se absolutiza aca, que es
    // donde se sabe cual es el host del generador.
    $j['url_abs'] = $url . '/' . ltrim((string)$j['url'], '/');
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
    $urls = []; $vistas = [];
    foreach ($unis as $u) {
        $r = hist_generar_una($u['proy'], $u['cod']);
        if (!$r) continue;
        $celda = (string)($r['etiqueta'] ?? $u['cod']);
        if (isset($vistas[$celda])) continue;
        $vistas[$celda] = true;
        $urls[] = ['cod' => $celda, 'url' => $r['url_abs']];
    }
    if (!$urls) return ['ok' => false, 'motivo' => 'el generador no devolvio ninguna imagen'];

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
