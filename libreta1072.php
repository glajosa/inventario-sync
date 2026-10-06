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
/* /deals en paginas de 100 (orquestador, 6-oct): la libreta tardaba 1,7 s, 20 s o 33 s
   la MISMA consulta segun el momento; un pedido corto tiene menos chance de cortarse. */
const L1072_PAGINA_DEALS = 100;
const L1072_MAX_PAGS_DEALS = 60;

/** ¿Leer de la libreta? env LIBRETA_ON=0 o config "libreta_1072": 0 la apagan. */
function l1072_on(): bool {
    if (getenv('LIBRETA_ON') === '0') return false;
    $f = (getenv('DATA_DIR') ?: '/data') . '/config.json';
    $c = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
    if (is_array($c) && array_key_exists('libreta_1072', $c)) return (int)$c['libreta_1072'] === 1;
    return true;
}

/** GET a la libreta con UN reintento si se corta por tiempo o falla la red: medido el
 *  6-oct, la misma lista tardo 2 s y despues mas de 60. Caer a Bitrix por un corte
 *  pasajero cuesta cientos de llamadas; reintentar cuesta 20 s. */
function l1072_http(string $ruta): array {
    $r = l1072_http_una($ruta);
    if ($r['err'] !== '' || $r['status'] === 0 || $r['status'] >= 502) $r = l1072_http_una($ruta);
    return $r;
}

/** Un solo GET a la libreta. Devuelve status, json y las cabeceras (en minuscula). */
function l1072_http_una(string $ruta): array {
    $base = rtrim((string)getenv('LIBRETA_URL'), '/');
    $tok  = (string)getenv('LIBRETA_TOKEN');
    if ($base === '' || $tok === '') return ['status' => 0, 'json' => null, 'h' => [], 'err' => 'sin LIBRETA_URL/TOKEN'];
    $h = [];
    $ch = curl_init($base . $ruta);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 4,
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

/*
 * ANTES DE ESCRIBIR, EL DATO FRESCO (orquestador + Jesua, 6-oct-2026).
 * La copia va ~30 s atrasada. Si reconcile escribiera mirando solo la copia, podria
 * reescribir en Bitrix un valor que ya esta bien (y ese aviso despierta a todos los
 * handlers) o soltar una unidad que alguien acaba de atar. Por eso cada escritura de
 * reconcile relee la unidad con crm.item.get y decide con ESE dato.
 *   $fresco: parentId2 que devolvio Bitrix (0 = sin deal), o null si no se pudo leer.
 */

/** Atar la unidad al deal: 'escribir' | 'ya_estaba' | 'sin_dato'. */
function l1072_decidir_set(?int $fresco, string $deal): string {
    if ($fresco === null) return 'sin_dato';                 // sin dato fresco no se escribe
    return (string)$fresco === $deal ? 'ya_estaba' : 'escribir';
}

/** Soltar la unidad que la copia ve atada a $dealCopia: 'escribir' | 'ya_suelta' | 'cambio' | 'sin_dato'.
 *  'cambio' = en Bitrix ya es de OTRO deal: alguien la movio despues de la copia, no se toca. */
function l1072_decidir_clear(?int $fresco, string $dealCopia): string {
    if ($fresco === null) return 'sin_dato';
    if ($fresco === 0) return 'ya_suelta';
    return (string)$fresco === $dealCopia ? 'escribir' : 'cambio';
}

/**
 * Los deals de UN embudo (y de UNA etapa si se pide; '' = todas) desde la libreta (dato = crm.deal.get), o null con
 * $motivo. La copia de deals no trae copia_completa_at, asi que la prueba de que esta
 * entera es que las traidas cuadren con X-Libreta-Total.
 */
function l1072_deals(int $cat, string $stage, ?string &$motivo = null, ?callable $http = null): ?array {
    $http = $http ?? 'l1072_http';
    if (!l1072_on()) { $motivo = 'apagada (perilla)'; return null; }
    $out = []; $total = null; $cursor = '';
    for ($p = 0; $p < L1072_MAX_PAGS_DEALS; $p++) {
        $r = $http('/deals?category=' . $cat . ($stage !== '' ? '&stage=' . rawurlencode($stage) : '') . '&limit=' . L1072_PAGINA_DEALS
                   . ($cursor !== '' ? '&cursor=' . rawurlencode($cursor) : ''));
        if ($r['status'] !== 200 || !is_array($r['json'])) {
            $motivo = 'http ' . $r['status'] . ($r['err'] ? ' ' . $r['err'] : '') . " en pagina $p"; return null;
        }
        if ($total === null) $total = isset($r['h']['x-libreta-total']) ? (int)$r['h']['x-libreta-total'] : null;
        foreach ((array)($r['json']['items'] ?? []) as $i) {
            if (!empty($i['borrada_at']) || !is_array($i['dato'] ?? null)) continue;
            // si la libreta ignorara el filtro devolveria TODO: se comprueba deal por deal
            if ($stage !== '' && (string)($i['dato']['STAGE_ID'] ?? '') !== $stage) { $motivo = 'la libreta devolvio otra etapa'; return null; }
            if ((string)($i['dato']['CATEGORY_ID'] ?? $cat) !== (string)$cat) { $motivo = 'la libreta devolvio otro embudo'; return null; }
            $out[] = $i['dato'];
        }
        $cursor = (string)($r['json']['siguiente'] ?? '');
        if ($cursor === '') break;
    }
    if ($cursor !== '')            { $motivo = 'el cursor no termino'; return null; }
    if ($total === null)           { $motivo = 'sin cabecera X-Libreta-Total'; return null; }
    if (count($out) !== $total)    { $motivo = 'traidos ' . count($out) . ' de ' . $total; return null; }
    $motivo = '';
    return $out;
}

/*
 * ETAPAS EN RECONCILE DECIDIDAS CON LA COPIA (orquestador + Jesua, 6-oct-2026).
 * Antes cada unidad de cada deal 44/48 en las etapas de cierre costaba un crm.item.get
 * (dos si habia que liberarla), aunque no hubiera nada que cambiar: ~3.000 llamadas y
 * ~20 min por corrida, con 0 cambios. Ahora la copia dice si hace falta mirar; solo
 * entonces se relee en Bitrix y se escribe con ese dato fresco, como siempre.
 *   $objetivoCopia: lo que stage_objetivo() devuelve sobre el item de la COPIA
 *                   (null = ya esta, o esta protegida: BLOQUEADO / PERDIDO / VENDIDO).
 *   $dealId: el deal que la suelta (solo cuenta para DISPONIBLE); '' si no aplica.
 * Devuelve 'releer' (ir a Bitrix como antes) o 'saltar' (no hace falta nada).
 */
function l1072_decidir_etapa(?array $itemCopia, ?string $objetivoCopia, string $target, string $dealId): string {
    if ($itemCopia === null) return 'releer';                  // la copia no la tiene: como antes
    if ($objetivoCopia === null) return 'saltar';              // segun la copia ya esta bien
    if ($target === 'DISPONIBLE' && $dealId !== '') {
        $p = (int)($itemCopia['parentId2'] ?? 0);
        if ($p !== 0 && $p !== (int)$dealId) return 'saltar';   // ya es de otro deal: no se le quita
    }
    return 'releer';
}

/** Los ids de unidad que nombra un deal 44: los atados por parentId2 (de la copia) + el
 *  campo "Inventario" + PARENT_ID_1072. La misma regla que units_of_clientes_deal(),
 *  sin sus dos llamadas. */
function l1072_unidades_del_deal(array $deal, array $porDeal, string $campo): array {
    $ids = [];
    foreach ($porDeal[(int)($deal['ID'] ?? 0)] ?? [] as $u) $ids[(int)$u] = true;
    $v = $deal[$campo] ?? '';
    foreach (preg_split('/[,;\s]+/', is_array($v) ? implode(',', $v) : (string)$v) as $x) {
        $x = trim($x);
        if ($x !== '' && ctype_digit($x) && (int)$x > 0) $ids[(int)$x] = true;
    }
    $p = (int)($deal['PARENT_ID_1072'] ?? 0);
    if ($p > 0) $ids[$p] = true;
    return array_keys($ids);
}
