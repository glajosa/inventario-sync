<?php
/**
 * selector.php — Selector visual de unidades del Inventario (SPA 1072).
 * ---------------------------------------------------------------------------
 * Por qué existe: el campo nativo "Inventario" del deal usa el componente
 * `ui.entityselector` de Bitrix, que depende de un índice de búsqueda que se
 * atrasa con importaciones masivas (verificado 2026-07-27: unidades reales no
 * aparecían al buscar por código). Además el selector de Bitrix no agrupa por
 * proyecto ni distingue unidades ya ocupadas.
 *
 * Esta página resuelve las dos cosas: lee las unidades por API (sin índice, así
 * que SIEMPRE las encuentra) y las muestra agrupadas por proyecto, marcando en
 * gris las que ya están tomadas para evitar doble asignación.
 *
 * Uso:  selector.php?deal=<ID>&token=<OUTBOUND_TOKEN>
 * Al elegir una unidad se escribe en el primer slot libre del deal:
 *   slot 1 = PARENT_ID_1072 (relación nativa) ; slots 2-4 = Inventario 2/3/4.
 * NUNCA escribe en deals de Cobranzas (48): ese pipeline es read-only.
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);
set_time_limit(0);

const SPA_ENTITY   = 1072;
const CLIENTES_CAT = 44;
const FIELDS_EXTRA = [
    'UF_CRM_DEAL_1784994996',   // Inventario 2
    'UF_CRM_DEAL_1784995021',   // Inventario 3
    'UF_CRM_DEAL_1784995044',   // Inventario 4
];
// campos de la unidad (nombres camelCase que devuelve crm.item.*)
const U_NUM = 'ufCrm25_1782615753112';
const U_M2  = 'ufCrm25_1782615822688';
const U_PVP = 'ufCrm25_1784563253861';
const U_TOR = 'ufCrm25_1784314119';
const U_PIS = 'ufCrm25_1784313244';

// 15 min: recorrer el catálogo son ~30 páginas de API; con el refresco en segundo
// plano el vendedor nunca espera, y el estado real lo garantiza hook.php en vivo.
const CACHE_TTL = 900;

$DATA_DIR   = getenv('DATA_DIR') ?: '/data';
$WEBHOOK_IN = rtrim((string)getenv('BITRIX_WEBHOOK'), '/') . '/';
$TOKEN      = (string)getenv('OUTBOUND_TOKEN');

// ---- auth -------------------------------------------------------------------
$got = (string)($_GET['token'] ?? $_POST['token'] ?? '');
if ($TOKEN === '' || !hash_equals($TOKEN, $got)) { http_response_code(403); exit('forbidden'); }

function bx(string $method, array $params = []): array {
    global $WEBHOOK_IN;
    // throttle: sin esto la paginación de 30+ páginas dispara QUERY_LIMIT_EXCEEDED
    // y el catálogo salía parcial (200/300 de 1500, distinto en cada corrida).
    usleep(250000);
    for ($try = 0; $try < 5; $try++) {
        $ch = curl_init($WEBHOOK_IN . $method);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($params),
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $raw = curl_exec($ch); $errno = curl_errno($ch); curl_close($ch);
        if ($errno) { if ($try < 4) { sleep(1); continue; } return ['ok' => false, 'error' => "curl:$errno"]; }
        $j = json_decode((string)$raw, true);
        if (is_array($j) && isset($j['error'])) {
            if (in_array($j['error'], ['QUERY_LIMIT_EXCEEDED', 'OPERATION_TIME_LIMIT'], true) && $try < 4) {
                sleep(2 + $try); continue;
            }
            return ['ok' => false, 'error' => (string)$j['error']];
        }
        if (!is_array($j)) { if ($try < 4) { sleep(1); continue; } return ['ok' => false, 'error' => 'bad-json'] ; }
        return ['ok' => true, 'result' => $j['result'] ?? null, 'next' => $j['next'] ?? null,
                'total' => isset($j['total']) ? (int)$j['total'] : null];
    }
    return ['ok' => false, 'error' => 'retries-exhausted'];
}

// refresco en segundo plano (lo llama warm_en_segundo_plano y también el cron)
if (isset($_GET['warm'])) {
    ignore_user_abort(true);   // el que dispara corta a los 300ms; hay que terminar igual
    $c = catalogo(true);
    header('Content-Type: text/plain; charset=utf-8');
    // 'stale' = el rebuild falló y se devolvió el caché anterior (no confundir con éxito)
    $estado = !empty($c['parcial']) ? 'PARCIAL' : (!empty($c['stale']) ? 'FALLO-sirvio-cache-viejo' : 'OK');
    echo "warm $estado units=" . count($c['units']);
    sellog("warm $estado units=" . count($c['units']));
    exit;
}

// diagnóstico: ?debug=1 — muestra qué responde Bitrix, sin tocar el caché
if (isset($_GET['debug'])) {
    header('Content-Type: text/plain; charset=utf-8');
    echo 'webhook_set=' . ($WEBHOOK_IN !== '/' ? 'si' : 'NO') . "\n";
    $c = bx('crm.category.list', ['entityTypeId' => SPA_ENTITY]);
    echo "category.list ok=" . var_export($c['ok'], true) . ' err=' . ($c['error'] ?? '-')
       . ' n=' . count($c['result']['categories'] ?? []) . "\n";
    $i = bx('crm.item.list', ['entityTypeId' => SPA_ENTITY]);
    echo "item.list ok=" . var_export($i['ok'], true) . ' err=' . ($i['error'] ?? '-')
       . ' n=' . count($i['result']['items'] ?? []) . ' next=' . var_export($i['next'], true) . "\n";
    $cachePath = $DATA_DIR . '/selector_cache.json';
    echo 'cache_exists=' . (is_file($cachePath) ? 'si age=' . (time() - (int)filemtime($cachePath)) . 's size=' . filesize($cachePath) : 'no') . "\n";
    if (is_file($cachePath)) {
        $cj = json_decode((string)@file_get_contents($cachePath), true);
        echo 'cache_json_ok=' . var_export(is_array($cj), true)
           . ' units=' . (is_array($cj) ? count($cj['units'] ?? []) : 0)
           . ' proyectos=' . (is_array($cj) ? count($cj['proyectos'] ?? []) : 0) . "\n";
    }
    $t0 = microtime(true);
    $fresh = catalogo(true);
    echo 'REBUILD units=' . count($fresh['units']) . ' proyectos=' . count($fresh['proyectos'])
       . ' secs=' . round(microtime(true) - $t0, 1) . "\n";
    exit;
}

function sellog(string $msg): void {
    global $DATA_DIR;
    @file_put_contents($DATA_DIR . '/sync.log',
        gmdate('Y-m-d\TH:i:s\Z') . '  SELECTOR ' . $msg . "\n", FILE_APPEND | LOCK_EX);
}

/** Dispara un refresco del catálogo sin esperarlo (fire-and-forget contra sí mismo). */
function warm_en_segundo_plano(): void {
    global $TOKEN;
    $url = 'http://127.0.0.1/selector.php?warm=1&token=' . urlencode($TOKEN);
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT_MS     => 300,   // se corta enseguida; el rebuild sigue del otro lado
        CURLOPT_NOSIGNAL       => true,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

/** Lee el caché en disco, sin importar su edad. null si no hay o está corrupto. */
function cache_leer(): ?array {
    global $DATA_DIR;
    $j = json_decode((string)@file_get_contents($DATA_DIR . '/selector_cache.json'), true);
    return (is_array($j) && !empty($j['units'])) ? $j : null;
}

/**
 * Catálogo completo (unidades + proyectos + stages), cacheado en /data.
 *
 * Dos protecciones aprendidas a golpes:
 *  - CANDADO: dos rebuilds simultáneos se pelean el límite de API de Bitrix y
 *    ambos terminan incompletos. Si ya hay uno corriendo, se sirve el caché viejo.
 *  - COMPLETITUD: solo se guarda el caché si la paginación trajo TODAS las
 *    unidades (se compara contra `total` de Bitrix). Antes se guardaban parciales
 *    (200 de 1500) y la página quedaba mostrando de menos.
 */
function catalogo(bool $force = false): array {
    global $DATA_DIR;
    $path = $DATA_DIR . '/selector_cache.json';
    $lock = $DATA_DIR . '/selector_rebuild.lock';

    if (!$force) {
        $c = cache_leer();
        if ($c) {
            $edad = time() - (int)@filemtime($path);
            if ($edad < CACHE_TTL) return $c;
            // vencido: se entrega igual (instantáneo) y se refresca por detrás.
            // Reconstruir en primer plano toma ~40s y el vendedor no va a esperar.
            warm_en_segundo_plano();
            $c['stale'] = true;
            return $c;
        }
    }

    // candado: si otro proceso ya está reconstruyendo, servir lo que haya
    $fh = @fopen($lock, 'c');
    if ($fh === false || !flock($fh, LOCK_EX | LOCK_NB)) {
        if ($fh) fclose($fh);
        $c = cache_leer();
        if ($c) { $c['stale'] = true; return $c; }
        return ['units' => [], 'proyectos' => [], 'built' => time(), 'building' => true];
    }

    // proyectos
    $proyectos = [];
    $c = bx('crm.category.list', ['entityTypeId' => SPA_ENTITY]);
    foreach (($c['result']['categories'] ?? []) as $cat) $proyectos[(string)$cat['id']] = (string)$cat['name'];

    // stages por categoría (los STATUS_ID difieren por pipeline -> resolver por nombre)
    $stageName = [];
    foreach (array_keys($proyectos) as $cid) {
        $st = bx('crm.status.list', ['filter' => ['ENTITY_ID' => 'DYNAMIC_' . SPA_ENTITY . '_STAGE_' . $cid]]);
        foreach (($st['result'] ?? []) as $s) $stageName[(string)$s['STATUS_ID']] = strtoupper((string)$s['NAME']);
    }

    // unidades — sin `select`: con select Bitrix devuelve title/id en null (bug verificado)
    $units = [];
    $start = 0;
    $total = null;
    $completo = true;
    do {
        $r = bx('crm.item.list', ['entityTypeId' => SPA_ENTITY, 'start' => $start]);
        if (!$r['ok']) {
            sellog("item.list FALLO start=$start traidas=" . count($units) . " err=" . ($r['error'] ?? '?'));
            $completo = false; break;
        }
        if ($total === null) $total = $r['total'];
        foreach (($r['result']['items'] ?? []) as $it) {
            $cid   = (string)($it['categoryId'] ?? '');
            $title = (string)($it['title'] ?? '');
            $units[] = [
                'id'     => (int)$it['id'],
                'codigo' => trim(explode('(', $title)[0]),
                'cat'    => $cid,
                'stage'  => $stageName[(string)($it['stageId'] ?? '')] ?? '',
                'm2'     => (string)($it[U_M2] ?? ''),
                'pvp'    => (string)($it[U_PVP] ?? ''),
                'torre'  => (string)($it[U_TOR] ?? ''),
                'piso'   => (string)($it[U_PIS] ?? ''),
                'dealId' => (int)($it['parentId2'] ?? 0),
            ];
        }
        $start = $r['next'] ?? null;
    } while ($start !== null && $start !== '');

    if ($total !== null && count($units) < $total) {
        sellog('incompleto: traidas=' . count($units) . ' esperadas=' . $total);
        $completo = false;
    }

    // si la paginación quedó a medias, NO se pisa el caché bueno con datos parciales
    if (!$completo) {
        flock($fh, LOCK_UN); fclose($fh);
        $c = cache_leer();
        if ($c) { $c['stale'] = true; return $c; }
        return ['units' => $units, 'proyectos' => $proyectos, 'built' => time(), 'parcial' => true,
                'esperadas' => $total, 'traidas' => count($units)];
    }

    // quién está ocupada por el campo nativo (PARENT_ID_1072 de deals de Clientes)
    $ocupadas = [];
    $start = 0;
    do {
        $r = bx('crm.deal.list', [
            'filter' => ['CATEGORY_ID' => CLIENTES_CAT, '!PARENT_ID_1072' => ''],
            'select' => ['ID', 'PARENT_ID_1072'],
            'start'  => $start,
        ]);
        if (!$r['ok']) break;
        foreach (($r['result'] ?? []) as $d) {
            $u = (int)($d['PARENT_ID_1072'] ?? 0);
            if ($u > 0) $ocupadas[$u] = (string)$d['ID'];
        }
        $start = $r['next'] ?? null;
    } while ($start !== null && $start !== '');

    foreach ($units as &$u) {
        if (!$u['dealId'] && isset($ocupadas[$u['id']])) $u['dealId'] = (int)$ocupadas[$u['id']];
    }
    unset($u);

    // el orden natural por código se aplica al agrupar por proyecto en la vista
    $out = ['units' => $units, 'proyectos' => $proyectos, 'built' => time()];
    @file_put_contents($path, json_encode($out));
    flock($fh, LOCK_UN); fclose($fh);
    return $out;
}

// ---- acción: asignar --------------------------------------------------------
if (($_POST['action'] ?? '') === 'assign') {
    header('Content-Type: application/json; charset=utf-8');
    $dealId = (int)($_POST['deal'] ?? 0);
    $unitId = (int)($_POST['unit'] ?? 0);
    if ($dealId <= 0 || $unitId <= 0) { echo json_encode(['ok' => false, 'error' => 'params']); exit; }

    $g = bx('crm.deal.get', ['id' => $dealId]);
    if (!$g['ok']) { echo json_encode(['ok' => false, 'error' => 'deal-no-existe']); exit; }
    $deal = $g['result'];
    // guarda dura: Cobranzas (48) es read-only por regla del negocio
    if ((int)($deal['CATEGORY_ID'] ?? -1) !== CLIENTES_CAT) {
        echo json_encode(['ok' => false, 'error' => 'Solo se puede atar en el pipeline CLIENTES (44).']);
        exit;
    }

    // primer slot libre: nativo -> Inventario 2 -> 3 -> 4
    if ((int)($deal['PARENT_ID_1072'] ?? 0) === 0) {
        $u = bx('crm.deal.update', ['id' => $dealId, 'fields' => ['PARENT_ID_1072' => $unitId]]);
        echo json_encode($u['ok'] ? ['ok' => true, 'slot' => 'Inventario'] : ['ok' => false, 'error' => $u['error']]);
        exit;
    }
    foreach (FIELDS_EXTRA as $i => $f) {
        if ((string)($deal[$f] ?? '') === '' || (int)($deal[$f] ?? 0) === 0) {
            $u = bx('crm.deal.update', ['id' => $dealId, 'fields' => [$f => $unitId]]);
            if ($u['ok']) bx('crm.item.update', ['entityTypeId' => SPA_ENTITY, 'id' => $unitId, 'fields' => ['parentId2' => $dealId]]);
            echo json_encode($u['ok'] ? ['ok' => true, 'slot' => 'Inventario ' . ($i + 2)] : ['ok' => false, 'error' => $u['error']]);
            exit;
        }
    }
    echo json_encode(['ok' => false, 'error' => 'Este deal ya tiene 4 unidades atadas (máximo).']);
    exit;
}

// ---- vista ------------------------------------------------------------------
$dealId = (int)($_GET['deal'] ?? 0);
$cat    = catalogo(isset($_GET['refresh']));
$dealInfo = null;
if ($dealId > 0) {
    $g = bx('crm.deal.get', ['id' => $dealId]);
    if ($g['ok']) {
        $d = $g['result'];
        $usados = [];
        if ((int)($d['PARENT_ID_1072'] ?? 0) > 0) $usados[] = (int)$d['PARENT_ID_1072'];
        foreach (FIELDS_EXTRA as $f) if ((int)($d[$f] ?? 0) > 0) $usados[] = (int)$d[$f];
        $dealInfo = [
            'id'      => $dealId,
            'title'   => (string)($d['TITLE'] ?? ''),
            'catOk'   => (int)($d['CATEGORY_ID'] ?? -1) === CLIENTES_CAT,
            'usados'  => $usados,
            'libres'  => 4 - count($usados),
        ];
    }
}

$porProyecto = [];
foreach ($cat['units'] as $u) $porProyecto[$u['cat']][] = $u;
foreach ($porProyecto as $cid => &$lista) {
    usort($lista, fn($a, $b) => strnatcasecmp($a['codigo'], $b['codigo']));
}
unset($lista);

function money(string $v): string {
    if ($v === '') return '—';
    $n = (float)str_replace(['|USD', ','], '', $v);
    return $n > 0 ? '$' . number_format($n, 0) : '—';
}
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Inventario — elegir unidad</title>
<style>
  :root{
    --bg:#0d1117; --panel:#161b22; --panel2:#1c2430; --line:#2a3441;
    --tx:#e6edf3; --tx2:#8b949e;
    --ok:#2ea043; --okbg:rgba(46,160,67,.12);
    --res:#d29922; --resbg:rgba(210,153,34,.12);
    --fir:#1f6feb; --firbg:rgba(31,111,235,.12);
    --ven:#8957e5; --venbg:rgba(137,87,229,.12);
    --blo:#6e7681; --blobg:rgba(110,118,129,.12);
  }
  @media (prefers-color-scheme: light){
    :root{ --bg:#f6f8fa; --panel:#fff; --panel2:#f6f8fa; --line:#d0d7de; --tx:#1f2328; --tx2:#656d76; }
  }
  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);color:var(--tx);
       font:15px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}
  .wrap{max-width:1180px;margin:0 auto;padding:20px}
  header{position:sticky;top:0;z-index:20;background:var(--bg);
         border-bottom:1px solid var(--line);padding:16px 0 12px}
  h1{margin:0 0 4px;font-size:19px;font-weight:650;letter-spacing:-.01em}
  .sub{color:var(--tx2);font-size:13px}
  .warn{margin-top:10px;padding:10px 12px;border-radius:8px;font-size:13px;
        background:var(--resbg);border:1px solid var(--res);color:var(--tx)}
  .bar{display:flex;gap:10px;align-items:center;margin-top:12px;flex-wrap:wrap}
  input[type=search]{flex:1;min-width:220px;padding:9px 12px;border-radius:8px;
        border:1px solid var(--line);background:var(--panel);color:var(--tx);font-size:14px}
  input[type=search]:focus{outline:2px solid var(--fir);outline-offset:-1px}
  .toggle{display:flex;align-items:center;gap:7px;color:var(--tx2);font-size:13px;cursor:pointer;user-select:none}
  .chips{display:flex;gap:6px;flex-wrap:wrap;margin-top:12px}
  .chip{padding:5px 11px;border-radius:99px;border:1px solid var(--line);background:var(--panel);
        color:var(--tx2);font-size:12.5px;cursor:pointer}
  .chip[aria-pressed=true]{background:var(--fir);border-color:var(--fir);color:#fff}
  section{margin-top:22px}
  .head{display:flex;align-items:baseline;gap:9px;margin-bottom:10px;
        padding-bottom:7px;border-bottom:1px solid var(--line)}
  .head h2{margin:0;font-size:15px;font-weight:620}
  .head .n{color:var(--tx2);font-size:12.5px}
  .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(178px,1fr));gap:9px}
  .u{position:relative;text-align:left;padding:10px 11px;border-radius:9px;cursor:pointer;
     border:1px solid var(--line);background:var(--panel);color:var(--tx);font:inherit;
     transition:transform .07s,border-color .12s}
  .u:hover:not([disabled]){border-color:var(--ok);transform:translateY(-1px)}
  .u[disabled]{cursor:not-allowed;opacity:.45}
  .u .cod{font-weight:640;font-size:14.5px;letter-spacing:-.01em}
  .u .meta{color:var(--tx2);font-size:12px;margin-top:3px}
  .u .pvp{font-variant-numeric:tabular-nums;font-size:13px;margin-top:5px}
  .b{position:absolute;top:9px;right:9px;font-size:10px;font-weight:640;
     padding:2px 6px;border-radius:99px;text-transform:uppercase;letter-spacing:.03em}
  .b.DISPONIBLE{background:var(--okbg);color:var(--ok)}
  .b.RESERVADO{background:var(--resbg);color:var(--res)}
  .b.FIRMADO{background:var(--firbg);color:var(--fir)}
  .b.VENDIDO{background:var(--venbg);color:var(--ven)}
  .b.BLOQUEADO,.b.PERDIDO{background:var(--blobg);color:var(--blo)}
  .empty{color:var(--tx2);font-size:13px;padding:14px 0}
  .toast{position:fixed;left:50%;bottom:22px;transform:translateX(-50%);
         background:var(--panel);border:1px solid var(--line);border-radius:9px;
         padding:11px 16px;font-size:14px;box-shadow:0 8px 26px rgba(0,0,0,.35);
         opacity:0;pointer-events:none;transition:opacity .18s}
  .toast.on{opacity:1}
  .toast.err{border-color:#f85149;color:#f85149}
  footer{margin:34px 0 10px;color:var(--tx2);font-size:12px;text-align:center}
  a{color:var(--fir)}
</style>
</head>
<body>
<div class="wrap">
<header>
  <h1>Elegir unidad del inventario</h1>
  <div class="sub">
    <?php if ($dealInfo): ?>
      Deal #<?= (int)$dealInfo['id'] ?> · <?= h($dealInfo['title']) ?>
      · <?= (int)$dealInfo['libres'] ?> espacio(s) libre(s)
    <?php else: ?>
      Modo consulta — abre con <code>?deal=&lt;ID&gt;</code> para poder asignar.
    <?php endif; ?>
  </div>

  <?php if (!empty($cat['building'])): ?>
    <div class="warn">El catálogo se está armando por primera vez (~1 min). Recarga en un momento.</div>
  <?php elseif (!empty($cat['parcial'])): ?>
    <div class="warn">Bitrix cortó la lectura por límite de consultas: se trajeron
      <?= (int)($cat['traidas'] ?? 0) ?> de <?= (int)($cat['esperadas'] ?? 0) ?> unidades.
      <a href="?<?= http_build_query(array_merge($_GET, ['refresh' => 1])) ?>">reintentar</a></div>
  <?php endif; ?>

  <?php if ($dealInfo && !$dealInfo['catOk']): ?>
    <div class="warn">Este deal no está en el pipeline <b>CLIENTES</b>. Por seguridad solo se puede atar ahí
    (Cobranzas es de solo lectura).</div>
  <?php elseif ($dealInfo && $dealInfo['libres'] <= 0): ?>
    <div class="warn">Este deal ya tiene 4 unidades atadas — es el máximo.</div>
  <?php endif; ?>

  <div class="bar">
    <input type="search" id="q" placeholder="Buscar código… (ej: I-4-5, J-3, A-1)" autocomplete="off" autofocus>
    <label class="toggle"><input type="checkbox" id="soloLibres" checked> Solo disponibles</label>
  </div>
  <div class="chips" id="chips">
    <button class="chip" data-cat="" aria-pressed="true">Todos</button>
    <?php foreach ($cat['proyectos'] as $cid => $nombre): ?>
      <button class="chip" data-cat="<?= h((string)$cid) ?>" aria-pressed="false"><?= h($nombre) ?></button>
    <?php endforeach; ?>
  </div>
</header>

<?php foreach ($cat['proyectos'] as $cid => $nombre):
        $lista = $porProyecto[(string)$cid] ?? [];
        if (!$lista) continue;
        $libres = count(array_filter($lista, fn($u) => $u['stage'] === 'DISPONIBLE' && !$u['dealId']));
?>
  <section data-cat="<?= h((string)$cid) ?>">
    <div class="head">
      <h2><?= h($nombre) ?></h2>
      <span class="n"><?= $libres ?> disponibles · <?= count($lista) ?> totales</span>
    </div>
    <div class="grid">
      <?php foreach ($lista as $u):
            $libre = ($u['stage'] === 'DISPONIBLE' && !$u['dealId']);
            $torpi = trim(($u['torre'] !== '' ? 'Torre ' . $u['torre'] : '')
                        . ($u['piso']  !== '' ? ' · Piso ' . $u['piso'] : ''));
      ?>
        <button class="u" data-cod="<?= h(strtoupper($u['codigo'])) ?>" data-libre="<?= $libre ? 1 : 0 ?>"
                data-id="<?= (int)$u['id'] ?>" data-nom="<?= h($u['codigo'] . ' — ' . $nombre) ?>"
                <?= $libre && $dealInfo && $dealInfo['catOk'] && $dealInfo['libres'] > 0 ? '' : 'disabled' ?>>
          <span class="b <?= h($u['stage'] ?: 'BLOQUEADO') ?>"><?= h($u['stage'] ?: '—') ?></span>
          <div class="cod"><?= h($u['codigo']) ?></div>
          <div class="meta"><?= h($torpi !== '' ? $torpi : '—') ?><?= $u['m2'] !== '' ? ' · ' . h($u['m2']) . ' m²' : '' ?></div>
          <div class="pvp"><?= money($u['pvp']) ?></div>
        </button>
      <?php endforeach; ?>
    </div>
  </section>
<?php endforeach; ?>

<footer>
  Catálogo leído por API (no usa el buscador de Bitrix) ·
  actualizado <?= (int)floor((time() - (int)$cat['built']) / 60) ?> min atrás ·
  <a href="?<?= http_build_query(array_merge($_GET, ['refresh' => 1])) ?>">recargar catálogo</a>
</footer>
</div>

<div class="toast" id="toast"></div>

<script>
const TOKEN = <?= json_encode($got) ?>;
const DEAL  = <?= (int)($dealInfo['id'] ?? 0) ?>;
const q = document.getElementById('q');
const soloLibres = document.getElementById('soloLibres');
const toast = document.getElementById('toast');
let catFilter = '';

function say(msg, err) {
  toast.textContent = msg;
  toast.className = 'toast on' + (err ? ' err' : '');
  clearTimeout(say._t);
  say._t = setTimeout(() => toast.className = 'toast', 3200);
}

function apply() {
  const term = q.value.trim().toUpperCase();
  const onlyFree = soloLibres.checked;
  document.querySelectorAll('section').forEach(sec => {
    let shown = 0;
    const catOk = !catFilter || sec.dataset.cat === catFilter;
    sec.querySelectorAll('.u').forEach(b => {
      const okTerm = !term || b.dataset.cod.includes(term);
      const okFree = !onlyFree || b.dataset.libre === '1';
      const vis = catOk && okTerm && okFree;
      b.style.display = vis ? '' : 'none';
      if (vis) shown++;
    });
    sec.style.display = (catOk && shown) ? '' : 'none';
  });
}
q.addEventListener('input', apply);
soloLibres.addEventListener('change', apply);

document.getElementById('chips').addEventListener('click', e => {
  const c = e.target.closest('.chip'); if (!c) return;
  catFilter = c.dataset.cat;
  document.querySelectorAll('.chip').forEach(x => x.setAttribute('aria-pressed', String(x === c)));
  apply();
});

document.addEventListener('click', async e => {
  const b = e.target.closest('.u'); if (!b || b.disabled) return;
  if (!DEAL) { say('Abre esta página con ?deal=<ID> para poder asignar.', true); return; }
  if (!confirm('¿Atar ' + b.dataset.nom + ' al deal #' + DEAL + '?')) return;
  b.disabled = true;
  try {
    const r = await fetch(location.pathname, {
      method: 'POST',
      headers: {'Content-Type': 'application/x-www-form-urlencoded'},
      body: new URLSearchParams({action:'assign', deal:DEAL, unit:b.dataset.id, token:TOKEN})
    });
    const j = await r.json();
    if (j.ok) {
      say('Listo — quedó en "' + j.slot + '". Recarga el deal en Bitrix para verlo.');
      b.dataset.libre = '0';
      b.querySelector('.b').className = 'b RESERVADO';
      b.querySelector('.b').textContent = 'RESERVADO';
      setTimeout(apply, 900);
    } else {
      say(j.error || 'No se pudo asignar', true);
      b.disabled = false;
    }
  } catch (err) {
    say('Error de red: ' + err.message, true);
    b.disabled = false;
  }
});

apply();
</script>
</body>
</html>
