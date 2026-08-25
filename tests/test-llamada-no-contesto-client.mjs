import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { readFile } from 'node:fs/promises';

const require = createRequire(import.meta.url);
const client = require('../assets/llamada-no-contesto-client.js');

const input = {
  requestId: '33333333-3333-4333-8333-333333333333',
  auth: 'seller-token',
  dealId: 77,
  selectedPhone: '+593991234567',
  comment: '',
};

const sent = [];
const result = await client.enviar(input, async (url, init) => {
  sent.push({ url, init });
  return {
    ok: true,
    status: 200,
    json: async () => ({
      status: 'processed',
      nextActivityAt: '2026-08-26T12:30:00-05:00',
    }),
  };
});
assert.equal(sent[0].url, '/api/llamadas/no-contesto.php');
assert.equal(sent[0].init.method, 'POST');
assert.equal(sent[0].init.headers['Content-Type'], 'application/json');
assert.deepEqual(JSON.parse(sent[0].init.body), input);
assert.equal(result.status, 'processed');

const statuses = [
  [200, { status: 'already_processed' }, 'already_processed'],
  [409, { error: 'result_conflict' }, 'conflict'],
  [422, { status: 'manual_review', reason: 'pending_activity_not_found' }, 'manual_review'],
  [503, { error: 'bitrix_unavailable' }, 'retryable'],
];

for (const [httpStatus, body, expectedCode] of statuses) {
  try {
    const response = await client.enviar(input, async () => ({
      ok: httpStatus >= 200 && httpStatus < 300,
      status: httpStatus,
      json: async () => body,
    }));
    assert.equal(expectedCode, 'already_processed');
    assert.equal(response.status, 'already_processed');
  } catch (error) {
    assert.equal(error.code, expectedCode);
    assert.equal(String(error).includes(input.auth), false);
    assert.equal(JSON.stringify(error).includes(input.auth), false);
  }
}

const placement = await readFile(new URL('../llamada_nativo.php', import.meta.url), 'utf8');
assert.match(placement, /GaljosaNoContesto\.enviar\(/);
assert.doesNotMatch(placement, /crm\.activity\.add/);

console.log('OK');
