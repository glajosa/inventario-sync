(function (root, factory) {
  var api = factory(root);
  if (typeof module === 'object' && module.exports) module.exports = api;
  root.GaljosaNoContesto = api;
}(typeof globalThis !== 'undefined' ? globalThis : this, function (root) {
  'use strict';

  var ENDPOINT = '/api/llamadas/no-contesto.php';

  function ClientError(code) {
    this.name = 'GaljosaNoContestoError';
    this.code = code;
    this.message = code;
    if (Error.captureStackTrace) Error.captureStackTrace(this, ClientError);
  }
  ClientError.prototype = Object.create(Error.prototype);
  ClientError.prototype.constructor = ClientError;

  function errorCode(status, body) {
    if (status === 409) return 'conflict';
    if (status === 422) return 'manual_review';
    if (status === 503 || status === 502 || status === 504 || status === 429) return 'retryable';
    if (status === 401 || status === 403) return 'authentication';
    if (status >= 500) return 'retryable';
    return (body && body.status === 'manual_review') ? 'manual_review' : 'invalid_request';
  }

  function defaultTransport(url, init) {
    if (!root.fetch) return Promise.reject(new ClientError('unsupported'));
    return root.fetch(url, init);
  }

  function enviar(input, transport) {
    var send = transport || defaultTransport;
    var controller = root.AbortController ? new root.AbortController() : null;
    var timer = controller && root.setTimeout ? root.setTimeout(function () {
      controller.abort();
    }, 12000) : null;
    var init = {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(input)
    };
    if (controller) init.signal = controller.signal;

    return Promise.resolve()
      .then(function () { return send(ENDPOINT, init); })
      .then(function (response) {
        return Promise.resolve(response.json()).catch(function () { return {}; }).then(function (body) {
          if (!response.ok) throw new ClientError(errorCode(response.status, body));
          if (!body || (body.status !== 'processed' && body.status !== 'already_processed')) {
            throw new ClientError('invalid_response');
          }
          return body;
        });
      })
      .catch(function (error) {
        if (error instanceof ClientError) throw error;
        throw new ClientError('retryable');
      })
      .finally(function () {
        if (timer !== null && root.clearTimeout) root.clearTimeout(timer);
      });
  }

  return { enviar: enviar, ClientError: ClientError };
}));
