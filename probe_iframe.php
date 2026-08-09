<?php
/**
 * probe_iframe.php — SONDA temporal, no es parte del producto.
 *
 * Pregunta que contesta: cuando la app dibuja su PROPIO HTML en el placement
 * CRM_DEAL_DETAIL_ACTIVITY (es decir, sin useBuiltInInterface), ¿Bitrix lo
 * mete en el panel deslizante grande o lo pone en línea dentro de la barra de
 * actividades? ¿Y se le puede fijar el alto?
 *
 * La documentación no lo dice: la página del placement solo describe el modo
 * useBuiltInInterface = Y. Así que se mide.
 *
 * Publica lo que averigua en window.name y en un <pre> visible, para poder
 * leerlo desde el DOM del padre.
 */
declare(strict_types=1);
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>sonda</title>
<script src="//api.bitrix24.com/api/v1/"></script>
<style>
  html,body { margin:0; padding:0; font:12px/1.4 monospace; background:#eef; }
  #caja { padding:8px; }
  pre { margin:0; white-space:pre-wrap; }
</style>
</head>
<body>
<div id="caja"><pre id="out">midiendo…</pre></div>
<script>
(function () {
  var lineas = [];
  function log(k, v) { lineas.push(k + ' = ' + v); pintar(); }
  function pintar() { document.getElementById('out').textContent = lineas.join('\n'); }

  log('iframe interno', window.innerWidth + ' x ' + window.innerHeight);

  BX24.init(function () {
    var opt = {};
    try { opt = BX24.placement.info().options || {}; } catch (e) {}
    log('placement', (BX24.placement.info() || {}).placement || '?');
    log('opciones', JSON.stringify(opt).slice(0, 120));

    // ¿se puede achicar el contenedor desde adentro?
    try {
      BX24.resizeWindow(380, 430, function () { log('resizeWindow', 'callback ok'); });
      log('resizeWindow', 'pedido');
    } catch (e) { log('resizeWindow', 'ERROR ' + e.message); }

    setTimeout(function () {
      log('tras resize', window.innerWidth + ' x ' + window.innerHeight);
      try { BX24.fitWindow(function () { log('fitWindow', 'callback ok'); }); }
      catch (e) { log('fitWindow', 'ERROR ' + e.message); }
      setTimeout(function () {
        log('tras fitWindow', window.innerWidth + ' x ' + window.innerHeight);
      }, 700);
    }, 700);
  });
})();
</script>
</body>
</html>
