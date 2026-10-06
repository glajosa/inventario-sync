<?php
/**
 * libreta1072.php — leer las unidades del SPA 1072 desde la LIBRETA central.
 * ---------------------------------------------------------------------------
 * La libreta (servicio del orquestador VIGILANTE BITRIX) guarda una copia del 1072
 * al dia en ~30 s: la actualizan los mismos avisos ONCRMDYNAMICITEM* que nos llegan,
 * un repaso por updatedTime cada 5 min y un cuadre por hora. Cada `dato` es el item
 * igual que crm.item.get, y parentId2 se guarda tal cual (null queda null).
 *
 * Para que sirve: reconcile.php y el catalogo del selector recorrian TODO el 1072 en
 * Bitrix (~31 crm.item.list cada uno, reconcile dos veces por corrida). Con los crons
 * andando serian ~5.000 lecturas al dia; desde aqui son 0.
 *
 * Reglas (orquestador, 6-oct-2026):
 *  - Si la libreta no contesta, da 404/409/410, o la copia no esta COMPLETA, se
 *    devuelve null con el MOTIVO y el que llama va a Bitrix como antes. El motivo
 *    queda en el log y en /data/libreta1072.json: un respaldo que no dice por que
 *    cayo es un respaldo que nadie mira.
 *  - Perilla para volver todo a Bitrix sin re-subir codigo: "libreta_1072": 0 en
 *    /data/config.json, o env LIBRETA_ON=0.
 *  - Solo LECTURAS de barrido. La lectura justo antes de escribir sigue en Bitrix.
 */
declare(strict_types=1);

const L1072_PAGINA   = 500;
const L1072_MAX_PAGS = 20;     // 10.000 unidades: mas que eso es un cursor que no termina

/** ¿Leer de la libreta? env LIBRETA_ON=0 o config "libreta_1072": 0 la apagan. */
function l1072_on(): bool {
    if (getenv('LIBRETA_ON') === '0') return false;
    $f = (getenv('DATA_DIR') ?: '/data') . '/config.json';
    $c = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
    if (is_array($c) && array_key_exists('libreta_1072', $c)) return (int)$c['libreta_1072'] === 1;
    return true;
}

/** GET a la libreta. Devuelve status, json y las cabeceras (en minuscula). */
function l1072_http(string $ruta): array {
    $base = rtrim((string)getenv('LIBRETA_URL'), '/');
    $tok  = (string)getenv('LIBRETA_TOKEN');
    if ($base === '' || $tok === '') return ['status' => 0, 'json' => null, 'h' => [], 'err' => 'sin LIBRETA_URL/TOKEN'];
    $h = [];
    $ch = curl_init($base . $ruta);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $tok, 'X-Libreta-Cliente: inventario'],
        CURLOPT_HEADERFUNCTION => function ($c, $linea) use (&$h) {
            $p = explode(':', $linea, 2);
            if (count($p) === 2) $h[strtolower(trim($p[0]))] = trim($p[1]);
            return strlen($linea);
        },
    ]);
    $body = curl_exec($ch);
    $st   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_errno($ch) ? 'curl:' . curl_errno($ch) : '';
    unset($ch);
    $j = is_string($body) ? json_decode($body, true) : null;
    return ['status' => $st, 'json' => is_array($j) ? $j : null, 'h' => $h, 'err' => $err];
}

/**
 * TODAS las unidades del 1072, o null con $motivo si no se puede confiar en la copia.
 * $http se inyecta en las pruebas; en produccion es l1072_http.
 */
function l1072_todas(?string &$motivo = null, ?callable $http = null): ?array {
    $http = $http ?? 'l1072_http';
    if (!l1072_on()) { $motivo = 'apagada (perilla)'; return null; }
    $items = []; $total = null; $cursor = ''; $completa = '';
    for ($p = 0; $p < L1072_MAX_PAGS; $p++) {
        $r = $http('/items?entity=1072&limit=' . L1072_PAGINA . ($cursor !== '' ? '&cursor=' . rawurlencode($cursor) : ''));
        if ($r['status'] !== 200 || !is_array($r['json'])) {
            $motivo = 'http ' . $r['status'] . ($r['err'] ? ' ' . $r['err'] : '') . " en pagina $p";
            return null;
        }
        if ($total === null) $total = isset($r['h']['x-libreta-total']) ? (int)$r['h']['x-libreta-total'] : null;
        $completa = (string)($r['json']['copia_completa_at'] ?? '');
        foreach ((array)($r['json']['items'] ?? []) as $i) {
            if (!empty($i['borrada_at'])) continue;               // Bitrix la borro: no es inventario
            if (is_array($i['dato'] ?? null)) $items[] = $i['dato'];
        }
        $cursor = (string)($r['json']['siguiente'] ?? '');
        if ($cursor === '') break;
    }
    if ($cursor !== '')       { $motivo = 'el cursor no termino en ' . L1072_MAX_PAGS . ' paginas'; return null; }
    if ($completa === '')     { $motivo = 'copia sin copia_completa_at (incompleta)'; return null; }
    if ($total === null)      { $motivo = 'sin cabecera X-Libreta-Total'; return null; }
    if (count($items) !== $total) { $motivo = 'traidas ' . count($items) . ' de ' . $total; return null; }
    if ($total === 0)         { $motivo = 'copia vacia'; return null; }
    $motivo = '';
    return $items;
}

/** Cuenta por dia, en /data/libreta1072.json, cuantas veces se uso la libreta y cuantas
 *  se cayo a Bitrix y por que. Lo lee /estado y quien quiera auditarlo. */
function l1072_contar(string $quien, bool $ok, string $motivo = ''): void {
    $f = (getenv('DATA_DIR') ?: '/data') . '/libreta1072.json';
    $fh = @fopen($f, 'c+');
    if (!$fh) return;
    flock($fh, LOCK_EX);
    $c = json_decode((string)stream_get_contents($fh), true) ?: [];
    $dia = gmdate('Y-m-d');
    $c[$dia][$quien][$ok ? 'libreta' : 'bitrix'] = (int)($c[$dia][$quien][$ok ? 'libreta' : 'bitrix'] ?? 0) + 1;
    if (!$ok) $c[$dia][$quien]['ultimo_motivo'] = $motivo;
    ksort($c);
    $c = array_slice($c, -14, null, true);                        // dos semanas
    ftruncate($fh, 0); rewind($fh);
    fwrite($fh, json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    flock($fh, LOCK_UN); fclose($fh);
}

/**
 * Las unidades atadas a un deal (parentId2 = $dealId), o null con $motivo. Sirve para
 * BUSCAR candidatas; antes de escribir, cada una se relee en Bitrix igual que siempre.
 */
function l1072_de_deal(int $dealId, ?string &$motivo = null, ?callable $http = null): ?array {
    $http = $http ?? 'l1072_http';
    if (!l1072_on()) { $motivo = 'apagada (perilla)'; return null; }
    if ($dealId <= 0) { $motivo = 'deal invalido'; return null; }
    $r = $http('/items?entity=1072&deal=' . $dealId . '&limit=' . L1072_PAGINA);
    if ($r['status'] !== 200 || !is_array($r['json'])) {
        $motivo = 'http ' . $r['status'] . ($r['err'] ? ' ' . $r['err'] : ''); return null;
    }
    if ((string)($r['json']['copia_completa_at'] ?? '') === '') { $motivo = 'copia incompleta'; return null; }
    if ((string)($r['json']['siguiente'] ?? '') !== '') { $motivo = 'mas de una pagina para un deal'; return null; }
    $out = [];
    foreach ((array)($r['json']['items'] ?? []) as $i)
        if (empty($i['borrada_at']) && is_array($i['dato'] ?? null)) $out[] = $i['dato'];
    $motivo = '';
    return $out;
}
