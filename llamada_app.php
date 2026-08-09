<?php
/**
 * llamada_app.php — "Registrar llamada" con interfaz PROPIA.
 * ---------------------------------------------------------------------------
 * Por qué se abandona el modo nativo (useBuiltInInterface + LayoutDto):
 * ese modo solo dibuja text / link / input / select / textarea / section, no
 * acepta color en los enlaces y no tiene columnas. El calendario había que
 * armarlo con espacios Unicode de ancho medido, y aun así no se podía pintar
 * el fin de semana en rojo ni poner el comentario al lado. Techo alcanzado.
 *
 * Con HTML propio Bitrix abre la app en un panel sobre el deal. Medido en el
 * portal real: 2060 x 1100, y NO se achica -- resizeWindow(380,430) y
 * fitWindow() no lo mueven, y placement.bind no tiene ningún parámetro de
 * tamaño. Pero el formulario nativo de "Llamada saliente" de Bitrix se abre
 * igual, así que el panel no es algo ajeno para el vendedor: es lo que ya usa.
 * Y ese ancho es justamente lo que permite las dos columnas que se pedían.
 *
 * Todo el trabajo contra Bitrix corre en el navegador del vendedor con SU
 * sesión (BX24.callMethod): la actividad queda a su nombre y respeta sus
 * permisos. Este servidor solo entrega el archivo.
 * ---------------------------------------------------------------------------
 */
declare(strict_types=1);
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Registrar llamada</title>
<script src="//api.bitrix24.com/api/v1/"></script>
<style>
  :root{
    --tinta:#333;         --tinta-suave:#6a737f;  --apagado:#a8adb4;
    --azul:#2066b0;       --azul-claro:#e8f2fb;   --rojo:#e05c4b;
    --linea:#eef2f4;      --borde:#dfe3e8;        --fondo:#fff;
    --verde:#37b34a;
  }
  *{box-sizing:border-box}
  html,body{margin:0;padding:0;background:transparent;
    font:14px/1.45 system-ui,-apple-system,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
    color:var(--tinta);-webkit-font-smoothing:antialiased}

  /* La app se centra: el panel de Bitrix es ancho, pero el trabajo no debe
     quedar desparramado de punta a punta. */
  /* Una sola columna, angosta y centrada: el panel de Bitrix es ancho pero
     el trabajo se lee mejor en una columna. Comentario arriba, calendario
     abajo -- el orden que ya tenían. */
  .marco{max-width:520px;margin:0 auto;padding:22px 24px 28px}

  h1{font-size:19px;font-weight:600;margin:0 0 18px;letter-spacing:-.2px}

  .cols{display:flex;flex-direction:column;gap:18px}

  .tarjeta{background:var(--fondo);border:1px solid var(--borde);border-radius:12px;padding:16px}
  .rotulo{font-size:12px;font-weight:600;color:var(--tinta-suave);
    text-transform:uppercase;letter-spacing:.04em;margin:0 0 8px}

  textarea{width:100%;min-height:104px;resize:vertical;padding:12px 14px;
    border:1px solid var(--borde);border-radius:10px;font:inherit;color:var(--tinta);
    background:#fff;outline:none;transition:border-color .12s,box-shadow .12s}
  textarea:focus{border-color:var(--azul);box-shadow:0 0 0 3px rgba(32,102,176,.12)}
  textarea::placeholder{color:var(--apagado)}

  /* ── calendario ─────────────────────────────────────────────── */
  .cal-cab{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px}
  .cal-mes{font-size:15px;font-weight:600;text-transform:capitalize}
  .flecha{width:28px;height:28px;border:0;background:transparent;border-radius:8px;
    color:var(--azul);font-size:15px;cursor:pointer;line-height:1;
    display:flex;align-items:center;justify-content:center;transition:background .12s}
  .flecha:hover{background:var(--azul-claro)}

  table.cal{width:100%;border-collapse:collapse;table-layout:fixed}
  table.cal th{font-size:11px;font-weight:500;color:var(--tinta-suave);
    padding:6px 0;text-align:center}
  table.cal thead tr{border-bottom:1px solid var(--linea)}
  table.cal td{padding:2px;text-align:center}
  .dia{width:100%;height:32px;border:0;background:transparent;border-radius:8px;
    font:inherit;font-size:13px;color:var(--tinta);cursor:pointer;
    transition:background .12s,color .12s}
  .dia:hover:not(:disabled){background:var(--azul-claro)}
  .dia.finde{color:var(--rojo)}
  .dia.otro,.dia:disabled{color:var(--apagado);cursor:default}
  .dia.otro.finde,.dia:disabled.finde{color:#eab5ae}
  .dia.hoy{font-weight:700;box-shadow:inset 0 0 0 1px var(--borde)}
  .dia.elegido{background:var(--azul);color:#fff;font-weight:600}
  .dia.elegido:hover{background:var(--azul)}

  /* ── hora ───────────────────────────────────────────────────── */
  .reloj{display:flex;align-items:center;justify-content:center;gap:10px;margin-top:4px}
  .rueda{display:flex;flex-direction:column;align-items:center;gap:2px}
  .paso{width:44px;height:20px;border:0;background:transparent;border-radius:6px;
    color:var(--tinta-suave);cursor:pointer;font-size:11px;line-height:1;
    display:flex;align-items:center;justify-content:center;transition:background .12s,color .12s}
  .paso:hover{background:var(--azul-claro);color:var(--azul)}
  .rueda input{width:56px;height:40px;text-align:center;font:600 20px/1 inherit;
    color:var(--tinta);border:1px solid var(--borde);border-radius:9px;background:#fff;
    outline:none;padding:0;transition:border-color .12s,box-shadow .12s}
  .rueda input:focus{border-color:var(--azul);box-shadow:0 0 0 3px rgba(32,102,176,.12)}
  .dospuntos{font:600 20px/1 inherit;color:var(--apagado);padding-bottom:2px}
  .atajos{display:flex;flex-wrap:wrap;gap:6px;justify-content:center;margin-top:12px}
  .chip{border:1px solid var(--borde);background:#fff;border-radius:999px;
    padding:5px 11px;font-size:12px;color:var(--tinta);cursor:pointer;
    transition:background .12s,border-color .12s,color .12s}
  .chip:hover{border-color:var(--azul);color:var(--azul)}
  .chip.on{background:var(--azul);border-color:var(--azul);color:#fff}

  /* ── pie ────────────────────────────────────────────────────── */
  .resumen{margin:0 0 14px;font-size:14px;color:var(--tinta)}
  .resumen b{font-weight:600}
  .acciones{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
  .btn{border:0;border-radius:9px;padding:11px 20px;font:600 14px/1 inherit;
    cursor:pointer;transition:filter .12s,background .12s}
  .btn:disabled{opacity:.5;cursor:default}
  .btn-si{background:var(--azul);color:#fff}
  .btn-si:hover:not(:disabled){filter:brightness(1.08)}
  .btn-no{background:#f0f2f5;color:var(--tinta)}
  .btn-no:hover:not(:disabled){background:#e6e9ee}
  .fuego{margin-left:auto;display:flex;align-items:center;gap:7px;
    font-size:13px;color:var(--tinta-suave);cursor:pointer;user-select:none}
  .fuego input{width:15px;height:15px;accent-color:var(--rojo);cursor:pointer}
  .link{background:none;border:0;color:var(--azul);font:inherit;font-size:13px;
    cursor:pointer;padding:6px 2px}
  .link:hover{text-decoration:underline}

  #aviso{margin-top:12px;font-size:13px;min-height:18px}
  #aviso.mal{color:var(--rojo)}
  #aviso.bien{color:var(--verde)}

  /* Nada de esto se puede seleccionar de más al hacer doble click */
  .flecha,.dia,.paso,.chip,.btn,table.cal th{user-select:none}
</style>
</head>
<body>
<div class="marco">
  <h1>Registrar llamada</h1>

  <div class="cols">
    <!-- comentario -->
    <div>
      <p class="rotulo">Comentario</p>
      <textarea id="coment" placeholder="Qué pasó en la llamada…"></textarea>
    </div>

    <!-- calendario y hora -->
    <div class="tarjeta">
      <div class="cal-cab">
        <button class="flecha" id="ant" title="Mes anterior">◀</button>
        <span class="cal-mes" id="mes"></span>
        <button class="flecha" id="sig" title="Mes siguiente">▶</button>
      </div>
      <table class="cal">
        <thead><tr><th>Lun</th><th>Mar</th><th>Mié</th><th>Jue</th><th>Vie</th><th>Sáb</th><th>Dom</th></tr></thead>
        <tbody id="dias"></tbody>
      </table>

      <p class="rotulo" style="margin:16px 0 6px">Hora</p>
      <div class="reloj">
        <div class="rueda">
          <button class="paso" data-mueve="60">▲</button>
          <input id="hh" inputmode="numeric" maxlength="2" aria-label="Hora">
          <button class="paso" data-mueve="-60">▼</button>
        </div>
        <span class="dospuntos">:</span>
        <div class="rueda">
          <button class="paso" data-mueve="5">▲</button>
          <input id="mm" inputmode="numeric" maxlength="2" aria-label="Minuto">
          <button class="paso" data-mueve="-5">▼</button>
        </div>
      </div>
      <div class="atajos" id="atajos"></div>
    </div>

    <!-- cierre -->
    <div>
      <div class="resumen" id="resumen"></div>
      <div class="acciones">
        <button class="btn btn-si" id="si">Sí, contestó</button>
        <button class="btn btn-no" id="no">No contestó</button>
        <label class="fuego"><input type="checkbox" id="imp"> Importante 🔥</label>
      </div>
      <div id="aviso"></div>
    </div>
  </div>
</div>

<script>
(function () {
  var MESES = ['enero','febrero','marzo','abril','mayo','junio',
               'julio','agosto','septiembre','octubre','noviembre','diciembre'];
  var DIAN  = ['dom','lun','mar','mié','jue','vie','sáb'];
  var HORAS_RAPIDAS = ['09:00','10:00','11:00','14:00','15:00','16:00','17:00','18:00'];

  var dealId = 0, ctx = null, ctxCargando = false, cola = [];
  var vista = new Date(), sel = null, hhmm = '09:00';
  var $ = function (id) { return document.getElementById(id); };

  function pad(n){ return n < 10 ? '0'+n : ''+n; }
  function hoy(){ var d = new Date(); return {y:d.getFullYear(), m:d.getMonth(), d:d.getDate()}; }
  function mismo(a,b){ return a && b && a.y===b.y && a.m===b.m && a.d===b.d; }
  function pasado(y,m,d){ var h=hoy();
    return (y<h.y) || (y===h.y && m<h.m) || (y===h.y && m===h.m && d<h.d); }

  function ahoraHHMM(){
    var d = new Date(), t = d.getHours()*60 + Math.round(d.getMinutes()/5)*5;
    t = ((t % 1440) + 1440) % 1440;
    return pad(Math.floor(t/60)) + ':' + pad(t % 60);
  }
  function mover(min){
    var t = parseInt(hhmm.slice(0,2),10)*60 + parseInt(hhmm.slice(3),10) + min;
    t = ((t % 1440) + 1440) % 1440;
    hhmm = pad(Math.floor(t/60)) + ':' + pad(t % 60);
    pintarHora(); pintarResumen();
  }
  function hora12(){
    var h = parseInt(hhmm.slice(0,2),10), h12 = h % 12; if (h12===0) h12 = 12;
    return h12 + ':' + hhmm.slice(3) + ' ' + (h < 12 ? 'a.m.' : 'p.m.');
  }

  // ── calendario ────────────────────────────────────────────────
  function pintarCal(){
    var y = vista.getFullYear(), m = vista.getMonth();
    $('mes').textContent = MESES[m] + ' ' + y;

    var offset   = (new Date(y,m,1).getDay() + 6) % 7;
    var total    = new Date(y,m+1,0).getDate();
    var totalAnt = new Date(y,m,0).getDate();

    var celdas = [];
    for (var o=offset;o>0;o--) celdas.push({d:totalAnt-o+1, otro:true, dm:m-1});
    for (var d=1;d<=total;d++) celdas.push({d:d, otro:false, dm:m});
    var sg = 1;
    while (celdas.length < 42) celdas.push({d:sg++, otro:true, dm:m+1});

    var html = '';
    for (var s=0;s<6;s++){
      html += '<tr>';
      for (var c=0;c<7;c++){
        var cel = celdas[s*7+c];
        var f = new Date(y, cel.dm, cel.d);          // normaliza mes -1 / +1
        var cls = ['dia'];
        if (c >= 5) cls.push('finde');
        if (cel.otro) cls.push('otro');
        var bloq = cel.otro || pasado(f.getFullYear(), f.getMonth(), f.getDate());
        if (mismo({y:f.getFullYear(),m:f.getMonth(),d:f.getDate()}, hoy())) cls.push('hoy');
        if (mismo({y:f.getFullYear(),m:f.getMonth(),d:f.getDate()}, sel))   cls.push('elegido');
        html += '<td><button class="'+cls.join(' ')+'"'+(bloq?' disabled':'')
             +  ' data-f="'+f.getFullYear()+'-'+f.getMonth()+'-'+f.getDate()+'">'
             +  pad(cel.d) + '</button></td>';
      }
      html += '</tr>';
    }
    $('dias').innerHTML = html;
  }

  function pintarHora(){
    $('hh').value = hhmm.slice(0,2);
    $('mm').value = hhmm.slice(3);
    var chips = $('atajos').children;
    for (var i=0;i<chips.length;i++)
      chips[i].classList.toggle('on', chips[i].dataset.h === hhmm);
  }

  function pintarResumen(){
    if (!sel) { $('resumen').textContent = 'Elegí un día en el calendario'; return; }
    var f = new Date(sel.y, sel.m, sel.d);
    $('resumen').innerHTML = 'Vuelvo a llamar el <b>' + DIAN[f.getDay()] + ' ' + sel.d
      + ' de ' + MESES[sel.m] + (mismo(sel,hoy()) ? ' (hoy)' : '')
      + '</b>, a las <b>' + hora12() + '</b>';
  }

  function habilitar(v){ $('si').disabled = !v; $('no').disabled = !v; }
  function decir(txt, mal){
    $('aviso').textContent = txt || '';
    $('aviso').className = txt ? (mal ? 'mal' : 'bien') : '';
  }

  // ── datos del deal, adelantados ───────────────────────────────
  function precargar(){
    if (ctx || ctxCargando || !dealId) return;
    ctxCargando = true;
    function listo(v){ ctxCargando=false; ctx=v; var q=cola; cola=[]; q.forEach(function(f){f();}); }
    BX24.callMethod('crm.deal.get', {id:dealId}, function (rd) {
      if (rd.error()) { listo(null); return; }
      var deal = rd.data(), cid = parseInt(deal.CONTACT_ID||0,10);
      var base = {resp:deal.ASSIGNED_BY_ID, contactId: cid>0?cid:0, nombre:null, tel:null};
      if (!base.contactId) { listo(base); return; }
      BX24.callMethod('crm.contact.get', {id:base.contactId}, function (rc) {
        if (!rc.error()) {
          var c = rc.data();
          base.nombre = [c.NAME,c.LAST_NAME].filter(Boolean).join(' ').trim() || null;
          base.tel = (c.PHONE && c.PHONE[0] && c.PHONE[0].VALUE) || null;
        }
        listo(base);
      });
    });
  }

  function iso(){ return sel.y+'-'+pad(sel.m+1)+'-'+pad(sel.d)+'T'+hhmm+':00-05:00'; }
  function masUnaHora(s){
    var m = s.match(/^(\d{4}-\d{2}-\d{2})T(\d{2}):(\d{2}):00(-05:00)$/);
    return m ? m[1]+'T'+pad((parseInt(m[2],10)+1)%24)+':'+m[3]+':00'+m[4] : s;
  }

  function comentar(luego){
    var txt = $('coment').value.replace(/^\s+|\s+$/g,'');
    if (!txt) { luego(); return; }
    BX24.callMethod('crm.timeline.comment.add', {
      fields:{ ENTITY_ID:dealId, ENTITY_TYPE:'deal', COMMENT:txt }
    }, function (r) {
      if (r.error()) { decir('No se pudo comentar: '+r.error(), true); habilitar(true); return; }
      $('coment').value = ''; luego();
    });
  }

  function registrar(contesto){
    if (!dealId || !sel) return;
    habilitar(false); decir('Guardando…');

    function guardar(){
      if (!ctx) { decir('No se pudo leer la negociación', true); habilitar(true); return; }
      var inicio = iso();
      var campos = {
        OWNER_TYPE_ID:2, OWNER_ID:dealId,
        TYPE_ID:2, DIRECTION:2,
        PROVIDER_ID:'VOXIMPLANT_CALL', PROVIDER_TYPE_ID:'CALL',
        SUBJECT: contesto ? '1234' : ('Llamada saliente ' + (ctx.nombre || 'cliente')),
        COMPLETED:'N', RESPONSIBLE_ID:ctx.resp,
        START_TIME:inicio, END_TIME:masUnaHora(inicio), DEADLINE:inicio,
        PRIORITY: $('imp').checked ? 3 : 2,
        NOTIFY_TYPE:1, NOTIFY_VALUE:15, DESCRIPTION_TYPE:1
      };
      if (ctx.contactId && ctx.tel) {
        campos.COMMUNICATIONS = [{VALUE:ctx.tel, ENTITY_ID:ctx.contactId, ENTITY_TYPE_ID:3, TYPE:'PHONE'}];
      }
      BX24.callMethod('crm.activity.add', {fields:campos}, function (ra) {
        if (ra.error()) { decir('No se pudo guardar: '+ra.error(), true); habilitar(true); return; }
        comentar(function(){
          decir('Guardado ✓');
          setTimeout(function(){
            try { BX24.closeApplication(); } catch (e) { location.reload(); }
          }, 450);
        });
      });
    }
    if (ctx) guardar(); else { cola.push(guardar); precargar(); }
  }

  // ── arranque ──────────────────────────────────────────────────
  BX24.init(function () {
    var opt = {};
    try { opt = BX24.placement.info().options || {}; } catch (e) {}
    dealId = parseInt(opt.ENTITY_ID || opt.entityId || opt.ID || 0, 10);

    var h = hoy(); sel = {y:h.y, m:h.m, d:h.d};
    hhmm = ahoraHHMM();

    $('atajos').innerHTML = HORAS_RAPIDAS.map(function (t) {
      return '<button class="chip" data-h="'+t+'">'+t+'</button>';
    }).join('');

    pintarCal(); pintarHora(); pintarResumen();
    precargar();
    BX24.fitWindow();

    $('ant').onclick = function(){ vista.setMonth(vista.getMonth()-1); pintarCal(); };
    $('sig').onclick = function(){ vista.setMonth(vista.getMonth()+1); pintarCal(); };

    $('dias').addEventListener('click', function (e) {
      var b = e.target.closest('.dia'); if (!b || b.disabled) return;
      var p = b.dataset.f.split('-');
      sel = {y:+p[0], m:+p[1], d:+p[2]};
      pintarCal(); pintarResumen();
    });

    document.querySelectorAll('.paso').forEach(function (b) {
      b.onclick = function(){ mover(parseInt(b.dataset.mueve,10)); };
    });

    $('atajos').addEventListener('click', function (e) {
      var c = e.target.closest('.chip'); if (!c) return;
      hhmm = c.dataset.h; pintarHora(); pintarResumen();
    });

    // Los campos aceptan tecleo directo; se corrigen al salir del campo.
    function leerCampo(){
      var h = parseInt($('hh').value,10), m = parseInt($('mm').value,10);
      if (isNaN(h) || h > 23) h = parseInt(hhmm.slice(0,2),10);
      if (isNaN(m) || m > 59) m = parseInt(hhmm.slice(3),10);
      hhmm = pad(h)+':'+pad(m); pintarHora(); pintarResumen();
    }
    ['hh','mm'].forEach(function (id) {
      $(id).addEventListener('blur', leerCampo);
      $(id).addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); leerCampo(); this.blur(); }
        if (e.key === 'ArrowUp')   { e.preventDefault(); mover(id==='hh'? 60: 5); }
        if (e.key === 'ArrowDown') { e.preventDefault(); mover(id==='hh'?-60:-5); }
      });
    });

    $('si').onclick = function(){ registrar(true);  };
    $('no').onclick = function(){ registrar(false); };
  });
})();
</script>
</body>
</html>
