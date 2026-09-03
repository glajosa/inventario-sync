<?php
/**
 * cobranza_app.php — controlador e instalador de la app local de COBRANZAS.
 * Bitrix le pega aqui al instalar (guarda los tokens) y al abrir la app.
 */
declare(strict_types=1);
require_once __DIR__ . '/lib/cobranza-appauth.php';
require_once __DIR__ . '/lib/cobranza-protocolo.php';

// ?ver -> que version esta desplegada. Sin secreto: no revela nada.
if (isset($_GET['ver'])) {
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo COBRANZA_VER, "\n"; exit;
}

@file_put_contents((getenv('DATA_DIR') ?: '/data') . '/sync.log',
    gmdate('Y-m-d\TH:i:s\Z') . '  COBRANZA_APP method=' . ($_SERVER['REQUEST_METHOD'] ?? '?')
    . ' claves=[' . implode(',', array_keys($_REQUEST)) . ']'
    . ' tieneAUTH=' . (empty($_REQUEST['AUTH_ID']) ? 'NO' : 'si') . "\n",
    FILE_APPEND | LOCK_EX);

// Bitrix manda los tokens en CADA llamada al handler, no solo al instalar:
// se aprovechan para mantenerlos frescos sin reinstalar.
if (!empty($_REQUEST['AUTH_ID'])) {
    cob_auth_guardar([
        'access_token'  => $_REQUEST['AUTH_ID'],
        'refresh_token' => $_REQUEST['REFRESH_ID'] ?? '',
        'domain'        => $_REQUEST['DOMAIN'] ?? '',
    ]);
}

$a = cob_auth_cargar();
$instalada = ($a['access_token'] ?? '') !== '' && ($a['domain'] ?? '') !== '';
$prueba = $instalada ? cob_app_bx('placement.get') : ['ok' => false, 'error' => 'not-installed'];
$enlazados = [];
foreach (($prueba['result'] ?? []) as $p) {
    if (is_array($p)) $enlazados[] = ($p['placement'] ?? '?') . '  ' . ($p['handler'] ?? '');
}
?>
<!doctype html>
<meta charset="utf-8">
<script src="//api.bitrix24.com/api/v1/"></script>
<style>
  body{font:15px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;padding:22px;color:#1f2328}
  h2{margin:0 0 14px;font-size:18px}
  .ok{color:#1a7f37;font-weight:600} .err{color:#cf222e;font-weight:600}
  code{background:#f6f8fa;padding:2px 6px;border-radius:4px;font-size:13px}
  .caja{border:1px solid #d0d7de;border-radius:8px;padding:14px;margin:14px 0;background:#f6f8fa}
  li{margin:4px 0}
</style>
<h2>Cobranzas · botón «No contestó»</h2>
<div class="caja">
  Instalación: <?= $instalada ? '<span class="ok">tokens guardados</span>' : '<span class="err">sin tokens — dale Reinstalar</span>' ?><br>
  Portal: <code><?= htmlspecialchars((string)($a['domain'] ?? '-'), ENT_QUOTES) ?></code><br>
  <?php if (!($prueba['ok'] ?? false)): ?>
    <span class="err">Bitrix: <code><?= htmlspecialchars((string)($prueba['error'] ?? '?'), ENT_QUOTES) ?></code></span>
  <?php else: ?>
    Botones enlazados por <b>esta</b> app: <b><?= count($enlazados) ?></b>
    <ul><?php foreach ($enlazados as $e): ?><li><code><?= htmlspecialchars($e, ENT_QUOTES) ?></code></li><?php endforeach; ?></ul>
  <?php endif; ?>
</div>
<p>Esta app es <b>solo de cobranzas</b>. Reinstalarla no toca los botones de ventas,
que cuelgan de la app «Inventario».</p>
<script>if (typeof BX24 !== 'undefined') { BX24.init(function(){ BX24.installFinish(); BX24.fitWindow(); }); }</script>
