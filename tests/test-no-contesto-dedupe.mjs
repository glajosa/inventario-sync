import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const source = fs.readFileSync(new URL('../llamada_nativo.php', import.meta.url), 'utf8');
const scriptStart = source.indexOf('<script>') + '<script>'.length;
const scriptEnd = source.lastIndexOf('</script>');
let browserScript = source.slice(scriptStart, scriptEnd)
  .replace('<?= $LLAMADA_CONFIG_JS ?>', JSON.stringify({
    plazo: { 1: 1 },
    plazo_mantenimiento: 30,
    plazo_contesto: 3,
    hora_contesto: '10:00',
    reentry_count_field: 'UF_TEST',
    reentry_stage_id: 'C28:TEST',
    provider_id: 'TEST',
    provider_type_id: 'CALL',
  }))
  .replace('<?= $FERIADOS_JS ?>', '[]');

const close = browserScript.lastIndexOf('})();');
assert.notEqual(close, -1, 'el script debe conservar su cierre principal');
browserScript = browserScript.slice(0, close) + `
  globalThis.__dedupeTest = {
    setState: function (responsibleId, activities) {
      ctx = { resp: responsibleId };
      llamadas = activities;
    },
    latest: ultimaDelAsesor,
    protocolItem: actividadParaProtocolo
  };
` + browserScript.slice(close);

const context = {
  BX24: { init() {} },
  console,
  Date,
  setTimeout,
  clearTimeout,
};
vm.runInNewContext(browserScript, context, { filename: 'llamada_nativo.php' });

const recent = new Date(Date.now() - 14 * 60 * 1000).toISOString();

context.__dedupeTest.setState(111820, [{
  iso: recent,
  resp: 111820,
  contesto: false,
  nuestra: false,
}]);
assert.equal(
  context.__dedupeTest.latest(),
  null,
  'una llamada planificada o tradicional no debe bloquear No contestó'
);

context.__dedupeTest.setState(111820, [{
  iso: recent,
  resp: 111820,
  contesto: false,
  nuestra: true,
}]);
assert.notEqual(
  context.__dedupeTest.latest(),
  null,
  'un registro reciente creado por el panel sí debe impedir el duplicado'
);

assert.equal(
  context.__dedupeTest.protocolItem({
    ID: '90',
    CREATED: recent,
    RESPONSIBLE_ID: '111820',
    SUBJECT: 'App móvil · No contestó',
  }),
  null,
  'el registro técnico móvil no debe duplicar el No contestó visible'
);
assert.equal(
  context.__dedupeTest.protocolItem({
    ID: '91',
    CREATED: recent,
    RESPONSIBLE_ID: '111820',
    SUBJECT: 'App móvil · 1234 · Sí contestó',
  }).contesto,
  true,
  'el registro técnico móvil contestado sí reinicia el protocolo'
);

console.log('OK no-contesto dedupe');
