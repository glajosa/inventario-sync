<?php
/**
 * credito_nativo.php — pantalla del botón «No contestó» de CRÉDITO Y CONTADO,
 * dentro de la barra de actividades del deal (useBuiltInInterface).
 *
 * APRETAR LA PESTAÑA ES LA ACCIÓN. Se registra el intento fallido y se agenda el
 * siguiente con la cadencia del régimen del deal. Adentro solo se muestra qué
 * quedó registrado y para cuándo es la próxima.
 *
 * Hermano de cobranza_nativo.php, con otro texto porque las reglas son otras:
 * acá NO hay techo de intentos, así que nunca se dice "quedan N".
 */
declare(strict_types=1);
require_once __DIR__ . '/lib/credito-protocolo.php';
$CFG_JS = json_encode(credito_config(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
?>
<!-- <?= CREDITO_VER ?> -->
<!doctype html>
<html><head><meta charset="utf-8"><title>No contestó · Crédito</title>
<script src="//api.bitrix24.com/api/v1/"></script></head><body>
<script>
(function () {
  var CFG = <?= $CFG_JS ?>;
  var ETAPAS = {
    'C79:NEW':'X GESTIONAR', 'C79:PREPAYMENT_INVOIC':'INDECISO',
    'C79:PREPARATION':'ANÁLISIS Y PEND. DOCUMENT', 'C79:UC_NGYPXQ':'DE CONTADO',
    'C79:FINAL_INVOICE':'BANCO APROBADO', 'C79:EXECUTING':'RECHAZADO',
    'C79:UC_XJ71GA':'NO PUEDE PAGARLO', 'C79:UC_M21PX3':'NO CONTESTA',
    'C79:UC_GUJ97G':'CRÉDITO DIRECTO', 'C79:UC_TA9OB1':'CEDIÓ DERECHOS',
    'C79:WON':'PAGADO', 'C79:LOSE':'DADO DE BAJA'
  };
  var REGIMEN = {
    'puerta':  'Régimen puerta · a las 2 semanas cambia de etapa',
    'gestion': 'Régimen gestión · mensaje y llamada intercalados',
    'proceso': 'Régimen proceso · manda la fecha pactada',
    'salida':  'Régimen salida · en decisión del directorio'
  };
  var MOTIVOS = {
    'otro_embudo':   'Este botón es solo para deals de CRÉDITO Y CONTADO. Para cobranzas usá el otro «No contestó».',
    'deal_cerrado':  'Este deal ya salió del proceso: no se le llama.',
    'pacto_vigente': 'Hay una fecha pactada con el cliente. El deal queda en silencio hasta entonces.'
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

    if (estado.status === 'rechazado') {
      var bl = {
        a:{ type:'text', properties:{ bold:true, value:'No se registró.' } },
        c:{ type:'text', properties:{ value: MOTIVOS[estado.motivo] || estado.motivo } }
      };
      // Con un pacto vivo lo que importa es LA FECHA: "no se puede" sin decir hasta
      // cuándo obliga a la asesora a ir a buscarlo a mano.
      if (estado.motivo === 'pacto_vigente' && estado.pactoFecha) {
        bl.d = { type:'text', properties:{ bold:true,
                 value:'Pactado hasta: ' + fecha(estado.pactoFecha) } };
        bl.e = { type:'text', properties:{ size:'sm', color:'base_70',
                 value:'Del acuerdo: ' + (estado.pactoAsunto || '—')
                       + '. El pacto siempre se respeta: ni llamada ni mensaje.' } };
      } else {
        bl.d = { type:'text', properties:{ size:'sm', color:'base_70',
                 value: etq + '  ·  intentos hechos: ' + (estado.intentos || 0) } };
      }
      b.caja = { type:'section', properties:{ type:'warning', blocks: bl }};
      return { blocks:b, primaryButton:{title:''}, secondaryButton:{title:''} };
    }

    if (estado.status === 'ya_registrado') {
      var h = estado.haceMinutos === 0 ? 'recién' :
              estado.haceMinutos === 1 ? 'hace 1 minuto' : 'hace ' + estado.haceMinutos + ' minutos';
      b.caja = { type:'section', properties:{ type:'primary', blocks:{
        a:{ type:'text', properties:{ bold:true, value:'Ya se había registrado ' + h + '.' } },
        c:{ type:'text', properties:{ size:'sm', color:'base_70',
            value:'No se duplicó el intento ni la próxima llamada.' } } }}};
      return { blocks:b, primaryButton:{title:''}, secondaryButton:{title:''} };
    }

    // 🔴 Nunca se dice "quedan N intentos": en crédito NO hay techo. Decirlo sería
    // enseñarle a la asesora una regla que no existe.
    b.tit = { type:'text', properties:{ bold:true,
      value:'Intento ' + estado.intentos + ' registrado' } };
    b.sub = { type:'text', properties:{ size:'sm', color:'base_70',
      value: etq + '  ·  ' + (REGIMEN[estado.regimen] || '') } };

    var nota = estado.regimen === 'proceso'
      ? 'Todo deal en proceso tiene que quedar con la llamada agendada. Ya quedó.'
      : 'Entre esta y la próxima llamada va el mensaje: el cliente recibe algo cada 2 días.';
    b.prox = { type:'section', properties:{ type:'primary', blocks:{
      a:{ type:'text', properties:{ bold:true, value:'Próxima llamada: ' + fecha(estado.proximoIntento) } },
      c:{ type:'text', properties:{ size:'sm', color:'base_70', value: nota } } }}};

    if (estado.regimen === 'proceso') {
      b.pie = { type:'text', properties:{ size:'sm', color:'base_70',
        value:'Lo que hay que sacar de la llamada es la FECHA DE PAGO: con ella se pide la minuta, que demora 2 semanas.' } };
    }
    return { blocks:b, primaryButton:{title:''}, secondaryButton:{title:''} };
  }

  function redibujar() { BX24.placement.call('setLayout', layout(), function(){}); }

  function registrar(dealId, auth) {
    fetch('/api/llamadas/credito-no-contesto.php', {
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
    // Bitrix manda el id con nombres distintos según el placement y la versión:
    // se prueban los tres, como en los otros dos botones.
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
