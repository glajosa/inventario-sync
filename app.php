<?php
/**
 * app.php — página principal de la aplicación local (handler).
 * Bitrix la abre desde el menú de la app. Sirve para ver el estado de la
 * instalación y re-registrar el tipo de campo si hiciera falta.
 */

declare(strict_types=1);
require_once __DIR__ . '/appauth.php';

// diagnóstico: qué nos manda Bitrix realmente al abrir la app
@file_put_contents((getenv('DATA_DIR') ?: '/data') . '/sync.log',
    gmdate('Y-m-d\TH:i:s\Z') . '  APP method=' . ($_SERVER['REQUEST_METHOD'] ?? '?')
    . ' claves=[' . implode(',', array_keys($_REQUEST)) . ']'
    . ' tieneAUTH=' . (empty($_REQUEST['AUTH_ID']) ? 'NO' : 'si') . "\n",
    FILE_APPEND | LOCK_EX);

// Bitrix manda los tokens en cada llamada al handler: se aprovechan para
// mantenerlos frescos aunque no se haya reinstalado la app.
if (!empty($_REQUEST['AUTH_ID'])) {
    auth_guardar([
        'access_token'  => $_REQUEST['AUTH_ID'],
        'refresh_token' => $_REQUEST['REFRESH_ID'] ?? '',
        'domain'        => $_REQUEST['DOMAIN'] ?? '',
    ]);
}

$tipos = app_bx('userfieldtype.list');
$cache = json_decode((string)@file_get_contents((getenv('DATA_DIR') ?: '/data') . '/selector_cache.json'), true);
$nUnidades = is_array($cache) ? count($cache['units'] ?? []) : 0;

$registrado = false;
foreach (($tipos['result'] ?? []) as $t) {
    if (($t['USER_TYPE_ID'] ?? '') === 'galjosa_unidad') $registrado = true;
}
?>
<!doctype html>
<meta charset="utf-8">
<script src="//api.bitrix24.com/api/v1/"></script>
<style>
  body{font:15px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;padding:22px;color:#1f2328}
  h2{margin:0 0 14px;font-size:18px}
  .ok{color:#1a7f37;font-weight:600}
  .err{color:#cf222e;font-weight:600}
  code{background:#f6f8fa;padding:2px 6px;border-radius:4px;font-size:13px}
  ol{padding-left:20px} li{margin:5px 0}
  .caja{border:1px solid #d0d7de;border-radius:8px;padding:14px;margin:14px 0;background:#f6f8fa}
</style>

<h2>Inventario Galjosa — selector de unidades</h2>

<div class="caja">
  Tipo de campo <code>Unidad de Inventario</code>:
  <?= $registrado ? '<span class="ok">registrado</span>' : '<span class="err">NO registrado</span>' ?><br>
  Catálogo en caché: <b><?= (int)$nUnidades ?></b> unidades
  <?php if (!$tipos['ok']): ?>
    <br><span class="err">Error consultando Bitrix: <code><?= htmlspecialchars((string)$tipos['error'], ENT_QUOTES) ?></code></span>
  <?php endif; ?>
</div>

<?php if ($registrado): ?>
  <p>Para usarlo en las negociaciones:</p>
  <ol>
    <li>Ir a un deal → <b>Editar</b> → <b>Crear campo</b>.</li>
    <li>Elegir el tipo <b>Unidad de Inventario</b> y ponerle nombre (ej. «Unidad»).</li>
    <li>Guardar. El campo ya muestra el selector agrupado por proyecto.</li>
  </ol>
<?php else: ?>
  <p class="err">Falta registrar el tipo de campo. Reinstala la app o usa el enlace de re-registro.</p>
<?php endif; ?>

<script>if (typeof BX24 !== 'undefined') { BX24.init(function(){ BX24.fitWindow(); }); }</script>
