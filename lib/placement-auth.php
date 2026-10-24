<?php
/**
 * placement-auth.php — verificación REAL del placement de Bitrix y firmas cortas.
 * ---------------------------------------------------------------------------
 * 🔴 EL AGUJERO QUE CIERRA (24-oct-2026). field.php leía ENTITY_VALUE_ID de
 * PLACEMENT_OPTIONS tal como llegaba en la petición y, sin preguntarle nada a
 * Bitrix, imprimía la firma HMAC que guardar.php/copiar.php aceptan y la base
 * firmada de cotizar.php. AUTH_ID y DOMAIN se leían y nunca se verificaban. O
 * sea: un GET anónimo a /field.php elegía cualquier deal, se llevaba una firma
 * válida PARA SIEMPRE (la de guardar/copiar no vencía) y con ella escribía en ese
 * deal o leía su cliente desde el cotizador.
 *
 * AHORA, antes de dibujar o firmar nada:
 *   1. El render tiene que venir por POST, con AUTH_ID + member_id (cuerpo) y
 *      DOMAIN (Bitrix lo manda en la query).
 *   2. DOMAIN tiene que estar en BITRIX_PLACEMENT_DOMAINS. Sin esa variable NO se
 *      verifica nada y todo da 403 (fail-closed). La URL del REST se arma con el
 *      valor de la lista, nunca con el que llegó (sin SSRF).
 *   3. Se le pregunta a ESE portal, con el token del propio usuario, una sola
 *      llamada `batch` de SOLO LECTURA: user.current (¿el token es de alguien?),
 *      app.info (¿de qué app es?) y crm.deal.get (¿ese usuario puede leer ESE
 *      deal?). Bitrix aplica los permisos del usuario: si no lo puede leer, no se
 *      firma. Ningún método add/update/delete pasa por aquí.
 *   4. La firma queda amarrada a PROPÓSITO + deal + usuario + vencimiento.
 *
 * El resultado bueno se recuerda unos minutos por (token, dominio, deal): Bitrix
 * dibuja el campo ~8 veces por apertura de deal y el portal va al tope de
 * llamadas. Solo se recuerdan los éxitos.
 *
 * Formato de la firma (viaja en un solo parámetro, así el JS no cambia):
 *     v2.<deal>.<usuario>.<vence>.<hmac hex>
 * HMAC-SHA256 sobre "v2|<propósito>|d<deal>|u<usuario>|e<vence>" con una clave
 * DERIVADA de OUTBOUND_TOKEN (no la misma: OUTBOUND_TOKEN también viaja como
 * ?token= en otras pantallas). Propósitos: "campo" (guardar/copiar, 2 h) y "cot"
 * (enlace del cotizador, 12 h). Uno no sirve para el otro.
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

const PL_FIRMA_CAMPO_TTL = 7200;     // guardar/copiar: 2 h (Bitrix vuelve a dibujar en cada apertura)
const PL_FIRMA_COT_TTL   = 43200;    // cotizador: la jornada del asesor, igual que el bot y el historial
const PL_CACHE_TTL       = 300;      // verificación recordada: 5 min, nunca más que el token

/** Dominios de NUESTRO(S) portal(es), desde BITRIX_PLACEMENT_DOMAINS ("a.bitrix24.com,b..."). */
function pl_dominios_permitidos(?string $crudo = null): array {
    $crudo ??= (string)getenv('BITRIX_PLACEMENT_DOMAINS');
    $out = [];
    foreach (preg_split('/[\s,;]+/', $crudo) ?: [] as $d) {
        $d = pl_normalizar_dominio($d);
        if ($d !== '') $out[$d] = $d;
    }
    return array_values($out);
}

/** Nombre de host en minúsculas o '' si no tiene forma de host (sin esquema, ruta ni puerto). */
function pl_normalizar_dominio(string $d): string {
    $d = strtolower(trim($d));
    $d = rtrim($d, '.');
    if ($d === '' || strlen($d) > 253) return '';
    if (!preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/D', $d)) return '';
    return $d;
}

/** Clave HMAC de las firmas v2. '' si el servicio no tiene OUTBOUND_TOKEN (=> no se firma nada). */
function pl_clave(?string $base = null): string {
    $base ??= (string)getenv('OUTBOUND_TOKEN');
    if ($base === '') return '';
    return hash_hmac('sha256', 'galjosa/inventario-sync/placement-firma/v2', $base);
}

function pl_mensaje(string $proposito, int $deal, int $usuario, int $vence): string {
    return "v2|{$proposito}|d{$deal}|u{$usuario}|e{$vence}";
}

/** Firma v2. Devuelve '' si no hay clave: el llamador no debe imprimir nada útil. */
function pl_firmar(string $proposito, int $deal, int $usuario, int $vence, ?string $clave = null): string {
    $clave ??= pl_clave();
    if ($clave === '' || $deal < 0 || $usuario <= 0 || $vence <= 0) return '';
    if (!preg_match('/^[a-z]{2,16}$/D', $proposito)) return '';
    $hmac = hash_hmac('sha256', pl_mensaje($proposito, $deal, $usuario, $vence), $clave);
    return "v2.{$deal}.{$usuario}.{$vence}.{$hmac}";
}

/** ¿Tiene forma de firma v2? (para distinguirla de la firma vieja del cotizador). */
function pl_es_firma_v2(string $firma): bool {
    return str_starts_with($firma, 'v2.');
}

/**
 * Verifica una firma v2 para ESTE propósito y ESTE deal.
 * Devuelve ['ok'=>true,'usuario'=>N,'vence'=>T] o ['ok'=>false,'motivo'=>...].
 * $ttlMax: un vencimiento más lejano que ahora+ttlMax (+5 min de holgura) se rechaza,
 * así una clave filtrada no puede fabricar firmas eternas sin que se note.
 */
function pl_verificar_firma(string $proposito, string $firma, int $deal, int $ahora, int $ttlMax, ?string $clave = null): array {
    $clave ??= pl_clave();
    if ($clave === '') return ['ok' => false, 'motivo' => 'sin-clave'];
    if (!preg_match('/^v2\.(0|[1-9][0-9]{0,11})\.([1-9][0-9]{0,11})\.([1-9][0-9]{0,11})\.([a-f0-9]{64})$/D', $firma, $m)) {
        return ['ok' => false, 'motivo' => 'formato'];
    }
    [$_, $fDeal, $fUsuario, $fVence, $fHmac] = $m;
    $fDeal = (int)$fDeal; $fUsuario = (int)$fUsuario; $fVence = (int)$fVence;
    $esperado = hash_hmac('sha256', pl_mensaje($proposito, $fDeal, $fUsuario, $fVence), $clave);
    if (!hash_equals($esperado, $fHmac)) return ['ok' => false, 'motivo' => 'firma'];
    if ($fDeal !== $deal)               return ['ok' => false, 'motivo' => 'otro-deal'];
    if ($ahora > $fVence)               return ['ok' => false, 'motivo' => 'vencida'];
    if ($fVence > $ahora + $ttlMax + 300) return ['ok' => false, 'motivo' => 'vence-demasiado-lejos'];
    return ['ok' => true, 'usuario' => $fUsuario, 'vence' => $fVence];
}

/**
 * Llamada real al REST del portal permitido, con el token del usuario. SOLO se usa
 * con `batch` de métodos de lectura (ver pl_verificar_placement).
 * Devuelve el JSON decodificado o null si no hubo respuesta legible.
 */
function pl_http_bitrix(string $dominio, string $metodo, array $params): ?array {
    $ch = curl_init('https://' . $dominio . '/rest/' . $metodo . '.json');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($params),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 6,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $raw = curl_exec($ch);
    $j = json_decode((string)$raw, true);
    return is_array($j) ? $j : null;
}

function pl_cache_dir(): string {
    return (getenv('DATA_DIR') ?: '/data') . '/placementcache';
}

function pl_cache_ruta(string $dominio, string $memberId, string $authId, int $deal): string {
    return pl_cache_dir() . '/' . hash('sha256', "{$dominio}|{$memberId}|{$authId}|{$deal}") . '.json';
}

/**
 * Verifica que este render lo pidió Bitrix para un usuario real de NUESTRO portal
 * que puede leer el deal. Sin esto no se dibuja ni se firma nada.
 *
 * @param array         $server  $_SERVER
 * @param array         $post    $_POST  (AUTH_ID, AUTH_EXPIRES, member_id)
 * @param array         $get     $_GET   (DOMAIN; también se acepta en el POST)
 * @param int           $deal    id del deal que se va a firmar (0 = formulario de alta: solo token)
 * @param callable|null $http    fn(string $dominio, string $metodo, array $params): ?array  — inyectable en pruebas
 * @param array|null    $cfg     ['dominios'=>[...], 'members'=>[...], 'app'=>'', 'cache'=>bool, 'ahora'=>int]
 * @return array ['ok'=>true,'usuario'=>N,'dominio'=>..,'member'=>..,'deal'=>?array,'app'=>?string,'fresco'=>bool]
 *             | ['ok'=>false,'motivo'=>...]
 */
function pl_verificar_placement(array $server, array $post, array $get, int $deal, ?callable $http = null, ?array $cfg = null): array {
    $http ??= 'pl_http_bitrix';
    $dominios = $cfg['dominios'] ?? pl_dominios_permitidos();
    $members  = $cfg['members']  ?? pl_dominios_o_lista((string)getenv('BITRIX_PLACEMENT_MEMBER_IDS'));
    $appCode  = (string)($cfg['app'] ?? getenv('BITRIX_PLACEMENT_APP_CODE') ?: '');
    $usaCache = (bool)($cfg['cache'] ?? true);
    $ahora    = (int)($cfg['ahora'] ?? time());

    if (!$dominios) return ['ok' => false, 'motivo' => 'sin-allowlist'];
    if (strtoupper((string)($server['REQUEST_METHOD'] ?? '')) !== 'POST') return ['ok' => false, 'motivo' => 'no-post'];
    if ($deal < 0) return ['ok' => false, 'motivo' => 'deal-invalido'];

    $authId   = (string)($post['AUTH_ID'] ?? '');
    $memberId = (string)($post['member_id'] ?? '');
    $dominioR = (string)($get['DOMAIN'] ?? $post['DOMAIN'] ?? '');
    if (!preg_match('/^[A-Za-z0-9._-]{16,512}$/D', $authId))  return ['ok' => false, 'motivo' => 'sin-auth'];
    if (!preg_match('/^[A-Za-z0-9]{8,64}$/D', $memberId))     return ['ok' => false, 'motivo' => 'sin-member'];

    // El dominio que se usa para llamar sale de la LISTA, no de la petición.
    $dominio = '';
    $norm = pl_normalizar_dominio($dominioR);
    foreach ($dominios as $permitido) {
        if ($norm !== '' && hash_equals($permitido, $norm)) { $dominio = $permitido; break; }
    }
    if ($dominio === '') return ['ok' => false, 'motivo' => 'dominio-no-permitido'];
    if ($members && !in_array(strtolower($memberId), array_map('strtolower', $members), true)) {
        return ['ok' => false, 'motivo' => 'member-no-permitido'];
    }

    // ¿Ya se verificó este mismo token para este deal hace poco?
    $ruta = pl_cache_ruta($dominio, $memberId, $authId, $deal);
    if ($usaCache) {
        $c = json_decode((string)@file_get_contents($ruta), true);
        if (is_array($c) && (int)($c['hasta'] ?? 0) > $ahora && (int)($c['usuario'] ?? 0) > 0
            && (int)($c['deal_id'] ?? -1) === $deal) {
            return ['ok' => true, 'usuario' => (int)$c['usuario'], 'dominio' => $dominio, 'member' => $memberId,
                    'deal' => null, 'app' => $c['app'] ?? null, 'fresco' => false];
        }
    }

    // UNA llamada, solo lectura. crm.deal.get con el token del usuario = Bitrix aplica SUS permisos.
    $cmd = ['yo' => 'user.current', 'app' => 'app.info'];
    if ($deal > 0) $cmd['deal'] = 'crm.deal.get?' . http_build_query(['id' => $deal]);
    $r = $http($dominio, 'batch', ['auth' => $authId, 'halt' => 0, 'cmd' => $cmd]);

    if (!is_array($r))           return ['ok' => false, 'motivo' => 'bitrix-sin-respuesta'];
    if (isset($r['error']))      return ['ok' => false, 'motivo' => 'token-rechazado:' . substr(preg_replace('/[^A-Za-z0-9_]/', '', (string)$r['error']), 0, 40)];
    $res  = $r['result']['result'] ?? null;
    $errs = $r['result']['result_error'] ?? [];
    if (!is_array($res))         return ['ok' => false, 'motivo' => 'bitrix-respuesta-rara'];
    if (!is_array($errs)) $errs = [];

    $usuario = (int)($res['yo']['ID'] ?? 0);
    if ($usuario <= 0 || isset($errs['yo'])) return ['ok' => false, 'motivo' => 'token-sin-usuario'];

    $app = isset($res['app']['CODE']) ? (string)$res['app']['CODE'] : null;
    if ($appCode !== '' && ($app === null || !hash_equals($appCode, $app))) {
        return ['ok' => false, 'motivo' => 'token-de-otra-app'];
    }

    $dealLeido = null;
    if ($deal > 0) {
        if (isset($errs['deal'])) return ['ok' => false, 'motivo' => 'sin-acceso-al-deal'];
        $dealLeido = $res['deal'] ?? null;
        if (!is_array($dealLeido) || (int)($dealLeido['ID'] ?? 0) !== $deal) {
            return ['ok' => false, 'motivo' => 'sin-acceso-al-deal'];
        }
    }

    if ($usaCache) {
        $exp  = (int)($post['AUTH_EXPIRES'] ?? 0);
        $vida = PL_CACHE_TTL;
        if ($exp > 0) $vida = min($vida, $exp);
        $dir = pl_cache_dir();
        if (!is_dir($dir)) @mkdir($dir, 0770, true);
        @file_put_contents($ruta, json_encode([
            'usuario' => $usuario, 'deal_id' => $deal, 'app' => $app, 'hasta' => $ahora + $vida,
        ]), LOCK_EX);
        @chmod($ruta, 0600);
        if (random_int(1, 50) === 1) pl_cache_podar($ahora);
    }

    return ['ok' => true, 'usuario' => $usuario, 'dominio' => $dominio, 'member' => $memberId,
            'deal' => $dealLeido, 'app' => $app, 'fresco' => true];
}

/** Lista separada por comas/espacios (sin normalizar como dominio). */
function pl_dominios_o_lista(string $crudo): array {
    $out = [];
    foreach (preg_split('/[\s,;]+/', $crudo) ?: [] as $x) if (($x = trim($x)) !== '') $out[] = $x;
    return $out;
}

/** Borra verificaciones viejas (nadie más limpia esa carpeta). */
function pl_cache_podar(int $ahora): void {
    foreach (glob(pl_cache_dir() . '/*.json') ?: [] as $f) {
        if ($ahora - (int)@filemtime($f) > 3600) @unlink($f);
    }
}

/** Respuesta de "no autorizado" para el iframe: sin catálogo, sin firma, sin nada útil. */
function pl_rechazar_html(string $motivo): void {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    @file_put_contents((getenv('DATA_DIR') ?: '/data') . '/web.log',
        gmdate('Y-m-d\TH:i:s\Z') . '  PLACEMENT 403 motivo=' . preg_replace('/[^A-Za-z0-9:_-]/', '', $motivo) . "\n",
        FILE_APPEND | LOCK_EX);
    echo '<!doctype html><meta charset="utf-8"><p style="font:13px system-ui;color:#8b949e;margin:4px 7px">'
       . 'Abre este campo desde el negocio en Bitrix.</p>';
}
