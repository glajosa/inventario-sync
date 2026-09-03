<?php
/**
 * cobranza_nativo.php — pantalla del botón «No contestó» de COBRANZAS,
 * dentro de la barra de actividades del deal (useBuiltInInterface).
 *
 * APRETAR LA PESTAÑA ES LA ACCIÓN: no hay botón adentro que buscar. Se registra
 * el intento fallido y se agenda el siguiente a +2 días hábiles. Adentro solo se
 * muestra en qué escalón quedó, cuántos le restan y para cuándo quedó la próxima.
 */
declare(strict_types=1);
require_once __DIR__ . '/lib/cobranza-protocolo.php';
$CFG_JS = json_encode(cobranza_config(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
?>
<!doctype html>
<html><head><meta charset="utf-8"><title>No contestó</title>
<script src="//api.bitrix24.com/api/v1/"></script></head><body>
<script>
(function () {
  var CFG = <?= $CFG_JS ?>;
  var ETAPAS = {
    'C48:UC_X35FSA':'MES CORRIENTE', 'C48:UC_1WHC5Q':'1 MES VENCIDO',
    'C48:UC_LLUGGI':'2 MESES VENCIDOS', 'C48:UC_VXD8VQ':'3 MESES VENCIDOS',
    'C48:FINAL_INVOICE':'ABOGADO', 'C48:NEW':'AL DÍA',
    'C48:UC_TPE9QV':'ADELANTADO', 'C48:UC_JW3G4N':'CANJE'
  };
  var MOTIVOS = {
    'etapa_sin_llamadas': 'En esta etapa no se llama. El recordatorio lo manda el sistema.',
    'tope_de_etapa':      'Ya se hicieron todos los intentos que permite esta etapa.',
    'en_pausa':           'Hay un pacto vigente con el cliente. No se le insiste hasta esa fecha.',
    'otro_embudo':        'Este botón es solo para deals de COBRANZAS. Para ventas usá el otro «No contestó».'
  };
  var estado = null, error = null, corriendo = true;

  function fecha(iso) {
    if (!iso) return '';
    var d = new Date(iso);
    var M = ['ene','feb','mar','abr','may','jun','jul','ago','sep','oct','nov','dic'];
    var hh = ('0' + d.getHours()).slice(-2), mm = ('0' + d.getMinutes()).slice(-2);
    return d.getDate() + ' ' + M[d.getMonth()] + ' ' + hh + ':' + mm;
  }

  function layout() {
    var b = {};
    if (corriendo) {
      b.esp = { type:'text', properties:{ value:'Registrando…', color:'base_50' } };
      return { blocks:b, primaryButton:{title:''}, secondaryButton:{title:''} };
    }
    if (error) {
      b.err = { type:'section', properties:{ type:'danger', blocks:{
        a:{ type:'text', properties:{ value:error, bold:true } } }}};
      return { blocks:b, primaryButton:{title:''}, secondaryButton:{title:''} };
    }
    var etq = ETAPAS[estado.etapa] || estado.etapa || '';

    // No se pudo registrar. Se dice POR QUÉ con la regla del protocolo, no un
    // código: la cobradora tiene que entender la negativa sin preguntarle a nadie.
    if (estado.status === 'rechazado') {
      b.caja = { type:'section', properties:{ type:'warning', blocks:{
        a:{ type:'text', properties:{ bold:true, value:'No se registró.' } },
        c:{ type:'text', properties:{ value: MOTIVOS[estado.motivo] || estado.motivo } },
        d:{ type:'text', properties:{ size:'sm', color:'base_70',
            value: etq + '  ·  intentos hechos: ' + (estado.intentos || 0) } } }}};
      return { blocks:b, primaryButton:{title:''}, secondaryButton:{title:''} };
    }

    // Ya estaba registrado. Se avisa en vez de sumar otro en silencio.
    if (estado.status === 'ya_registrado') {
      var h = estado.haceMinutos === 0 ? 'recién' :
              estado.haceMinutos === 1 ? 'hace 1 minuto' : 'hace ' + estado.haceMinutos + ' minutos';
      b.caja = { type:'section', properties:{ type:'primary', blocks:{
        a:{ type:'text', properties:{ bold:true, value:'Ya se había registrado ' + h + '.' } },
        c:{ type:'text', properties:{ size:'sm', color:'base_70',
            value:'No se duplicó el intento ni la próxima llamada.' } } }}};
      return { blocks:b, primaryButton:{title:''}, secondaryButton:{title:''} };
    }

    var quedan = estado.restantes === 0 ? 'era el último de esta etapa'
               : estado.restantes === 1 ? 'queda 1 intento' : 'quedan ' + estado.restantes + ' intentos';
    b.tit = { type:'text', properties:{ bold:true,
      value:'Intento ' + estado.intentos + ' registrado' } };
    b.sub = { type:'text', properties:{ size:'sm', color:'base_70',
      value: etq + '  ·  ' + quedan } };
    b.prox = { type:'section', properties:{ type:'primary', blocks:{
      a:{ type:'text', properties:{ bold:true, value:'Próxima llamada: ' + fecha(estado.proximoIntento) } },
      c:{ type:'text', properties:{ size:'sm', color:'base_70',
          value:'+2 días hábiles. Ya quedó agendada en el deal.' } } }}};
    if (estado.estadoGestion === CFG.gestion_cumplido) {
      b.cum = { type:'text', properties:{ size:'sm', color:'base_70',
        value:'3 intentos sin respuesta: el ciclo queda CUMPLIDO.' } };
    }
    return { blocks:b, primaryButton:{title:''}, secondaryButton:{title:''} };
  }

  function redibujar() { BX24.placement.call('setLayout', layout(), function(){}); }

  function registrar(dealId, auth) {
    fetch('/api/llamadas/cobranza-no-contesto.php', {
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({ auth: auth, dealId: dealId })
    }).then(function (r) {
      return r.json().then(function (j) { return { ok: r.ok, j: j }; });
    }).then(function (res) {
      corriendo = false;
      if (!res.ok) {
        error = res.j && res.j.error === 'bitrix_unavailable'
          ? 'No se pudo hablar con Bitrix. No se registró nada: volvé a intentar.'
          : 'No se pudo registrar (' + ((res.j && res.j.error) || 'error') + ').';
      } else { estado = res.j; }
      redibujar();
    }).catch(function () {
      corriendo = false;
      error = 'No se pudo registrar: falló la conexión. No se escribió nada.';
      redibujar();
    });
  }

  BX24.init(function () {
    var info = {}, dealId = 0, auth = '';
    try { info = BX24.placement.info() || {}; } catch (e) {}
    // 🔴 Bitrix manda el id con NOMBRES DISTINTOS segun el placement y la version.
    // Yo habia asumido 'ID' y el boton moria con "No se pudo leer el deal" en un
    // deal perfectamente normal. El de prospectos ya probaba los tres: se copia.
    try {
      var o = info.options || {};
      dealId = parseInt(o.ENTITY_ID || o.entityId || o.ID || 0, 10) || 0;
    } catch (e) {}
    try { auth = (BX24.getAuth() || {}).access_token || ''; } catch (e) {}
    redibujar();
    if (!dealId) {
      corriendo = false;
      error = 'No se pudo leer el deal desde Bitrix.';
      redibujar(); return;
    }
    registrar(dealId, auth);
  });
})();
</script></body></html>
