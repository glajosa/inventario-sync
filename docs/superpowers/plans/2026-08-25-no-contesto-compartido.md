# No contestó compartido Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Hacer que Bitrix y la web app ejecuten una sola operación `No contestó`, completen la actividad pendiente y creen como máximo una próxima actividad.

**Architecture:** `inventario-sync` conservará el servicio actual de resultados móviles y añadirá un registro atómico de ciclos compartidos. El panel de Bitrix tendrá una entrada autenticada con el token del vendedor, pero tanto esa entrada como la API privada móvil terminarán en `llamada_procesar_resultado()`. El puente y la PWA no cambian de contrato ni pierden sus vinculaciones.

**Tech Stack:** PHP 8.2, SQLite/PDO, Bitrix24 REST, JavaScript ES5 compatible con el placement de Bitrix, Node.js para la prueba aislada del cliente.

**Spec:** `docs/superpowers/specs/2026-08-25-no-contesto-compartido-design.md`

## Global Constraints

- El registro técnico de cada llamada se conserva.
- `No contestó` completa la actividad pendiente y crea una única próxima actividad con las reglas de Jeshua.
- `Sí contestó` completa la pendiente, no crea próxima actividad y no cambia etapa.
- La primera operación válida para una llamada prevalece.
- Las solicitudes simultáneas o repetidas desde cualquier canal no duplican efectos.
- Una actividad pendiente ausente o ambigua provoca revisión manual; nunca se completa una actividad al azar.
- Las vinculaciones, suscripciones push y códigos existentes no cambian.
- El puente móvil debe conservar el contrato JSON `inventario-sync-call-result-v1` sin cambios.

---

### Task 1: Registro atómico del ciclo compartido

**Files:**
- Modify: `lib/llamada-idempotencia.php`
- Create: `tests/test-llamada-ciclos.php`
- Modify: `tests/run.php`

**Interfaces:**
- Consumes: `LlamadaIdempotenciaStore` y la base `llamada-resultados.sqlite` existentes.
- Produces: `LlamadaIdempotenciaStore::claimCycle(string $operationKey, int $dealId, int $bitrixUserId, ?int $pendingActivityId, string $source, string $outcome, int $now): array`.
- Return: `['is_new' => bool, 'operation_key' => string, 'source' => string, 'outcome' => string]`.

- [ ] **Step 1: Escribir las pruebas que fallen para el candado compartido**

```php
$first = $store->claimCycle('member:a', 77, 42, 630, 'mobile', 'no_answer', 1000);
test_same(true, $first['is_new'], 'first cycle claim wins');

$samePending = $store->claimCycle('panel:b', 77, 42, 630, 'panel', 'no_answer', 1001);
test_same(false, $samePending['is_new'], 'same pending activity is shared across channels');
test_same('member:a', $samePending['operation_key'], 'duplicate points to first operation');

$crossChannel = $store->claimCycle('panel:c', 77, 42, 631, 'panel', 'no_answer', 1002);
test_same(false, $crossChannel['is_new'], 'recent mobile then panel is one result');

$otherMobileCall = $store->claimCycle('member:d', 77, 42, 631, 'mobile', 'no_answer', 1003);
test_same(true, $otherMobileCall['is_new'], 'different mobile calls remain independently registrable');

$otherDeal = $store->claimCycle('panel:e', 78, 42, 700, 'panel', 'no_answer', 1004);
test_same(true, $otherDeal['is_new'], 'another deal is independent');
```

- [ ] **Step 2: Ejecutar la prueba y verificar que falla por el método inexistente**

Run: `php tests/test-llamada-ciclos.php`

Expected: FAIL con `Call to undefined method LlamadaIdempotenciaStore::claimCycle()`.

- [ ] **Step 3: Añadir la tabla y el reclamo transaccional mínimo**

```php
$this->pdo->exec('CREATE TABLE IF NOT EXISTS result_cycles (
    operation_key TEXT PRIMARY KEY,
    deal_id INTEGER NOT NULL,
    bitrix_user_id INTEGER NOT NULL,
    pending_activity_id INTEGER,
    source TEXT NOT NULL,
    outcome TEXT NOT NULL,
    created_at INTEGER NOT NULL
)');
$this->pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS result_cycles_pending
    ON result_cycles(pending_activity_id)
    WHERE pending_activity_id IS NOT NULL');
```

`claimCycle()` debe ejecutar `BEGIN IMMEDIATE`, buscar primero `pending_activity_id` y después un registro de los últimos 1.800 segundos cuando al menos uno de los dos orígenes sea `panel`. Si no existe, inserta el reclamo; si existe, devuelve la operación ganadora. La consulta nunca bloquea dos llamadas móviles distintas solo por compartir deal.

- [ ] **Step 4: Ejecutar las pruebas específicas y generales**

Run: `php tests/test-llamada-ciclos.php; php tests/run.php`

Expected: ambos comandos terminan con `OK` y código 0.

- [ ] **Step 5: Commit**

```bash
git add lib/llamada-idempotencia.php tests/test-llamada-ciclos.php tests/run.php
git commit -m "feat: proteger resultados compartidos por llamada"
```

### Task 2: Unificar el servicio de resultados móvil y panel

**Files:**
- Modify: `lib/llamada-resultado-service.php`
- Modify: `tests/test-llamada-resultado-service.php`

**Interfaces:**
- Consumes: `claimCycle()` de Task 1.
- Produces: `llamada_procesar_resultado(array $input, callable $bx, LlamadaIdempotenciaStore $store, DateTimeImmutable $now, string $noInterestStage, string $source = 'mobile'): array`.
- `source` acepta solo `mobile` y `panel`.
- En `panel`, `bitrixActivityId` es `null`; en `mobile` continúa siendo entero positivo.

- [ ] **Step 1: Escribir pruebas fallidas para el mismo resultado desde ambos canales**

Añadir fixtures con una pendiente identificable `ID=630` y estas verificaciones literales:

```php
$mobile = llamada_procesar_resultado(
    llamada_test_input(), $fake, $store, $now, $noInterestStage, 'mobile'
);
$panel = llamada_procesar_resultado(
    llamada_test_input([
        'callRequestId' => '22222222-2222-4222-8222-222222222222',
        'memberId' => 'panel-42',
        'bitrixActivityId' => null,
    ]), $fake, $store, $now, $noInterestStage, 'panel'
);
test_same('processed', $mobile['status'], 'mobile owns first shared result');
test_same('already_processed', $panel['status'], 'panel sees the same completed result');
test_same(1, count(llamada_calls($fake, 'crm.activity.add')), 'cross-channel retry creates one future activity');
```

Añadir estos casos independientes, cada uno con tienda temporal y `FakeBitrix` nuevos:

```php
$fake->pendingActivities = [[
    'ID' => '630', 'SUBJECT' => 'Llamada pendiente',
    'DEADLINE' => '2026-08-20T10:00:00-05:00',
    'COMMUNICATIONS' => [['VALUE' => '+593991234567', 'TYPE' => 'PHONE']],
]];
$panelFirst = llamada_procesar_resultado(llamada_test_input([
    'callRequestId' => '33333333-3333-4333-8333-333333333333',
    'memberId' => 'panel-42', 'bitrixActivityId' => null,
]), $fake, $store, $now, $noInterestStage, 'panel');
$mobileSecond = llamada_procesar_resultado(llamada_test_input([
    'callRequestId' => '44444444-4444-4444-8444-444444444444',
]), $fake, $store, $now, $noInterestStage, 'mobile');
test_same('processed', $panelFirst['status'], 'panel may own the shared result');
test_same('already_processed', $mobileSecond['status'], 'mobile observes panel result');
test_same(1, count(llamada_calls($fake, 'crm.activity.add')), 'panel then mobile adds once');

$answered = llamada_procesar_resultado(llamada_test_input([
    'callRequestId' => '55555555-5555-4555-8555-555555555555',
    'outcome' => 'answered',
]), $fake, $store, $now, $noInterestStage, 'mobile');
test_same('processed', $answered['status'], 'answered owns its cycle');
test_throws(
    fn() => llamada_procesar_resultado(llamada_test_input([
        'callRequestId' => '66666666-6666-4666-8666-666666666666',
        'memberId' => 'panel-42', 'bitrixActivityId' => null,
    ]), $fake, $store, $now, $noInterestStage, 'panel'),
    LlamadaIdempotenciaConflict::class,
    'no answer cannot replace answered'
);

$fake->pendingActivities = [];
$manual = llamada_procesar_resultado(llamada_test_input([
    'callRequestId' => '77777777-7777-4777-8777-777777777777',
]), $fake, $store, $now, $noInterestStage, 'mobile');
test_same('manual_review', $manual['status'], 'missing pending activity stops automation');
test_same([], llamada_calls($fake, 'crm.activity.update'), 'missing pending performs no write');

$fake->pendingActivities = [
    ['ID' => '640', 'SUBJECT' => 'Pendiente A', 'DEADLINE' => '2026-08-19T10:00:00-05:00'],
    ['ID' => '641', 'SUBJECT' => 'Pendiente B', 'DEADLINE' => '2026-08-20T10:00:00-05:00'],
];
$ambiguous = llamada_procesar_resultado(llamada_test_input([
    'callRequestId' => '88888888-8888-4888-8888-888888888888',
]), $fake, $store, $now, $noInterestStage, 'mobile');
test_same('manual_review', $ambiguous['status'], 'ambiguous pending activity stops automation');
test_same([], llamada_calls($fake, 'crm.activity.add'), 'ambiguous pending creates no future activity');
```

- [ ] **Step 2: Ejecutar la prueba y observar el fallo del parámetro/origen nuevo**

Run: `php tests/test-llamada-resultado-service.php`

Expected: FAIL porque `panel` todavía exige `bitrixActivityId` y no consulta el ciclo compartido.

- [ ] **Step 3: Implementar la validación por origen y el reclamo antes de escribir en Bitrix**

```php
function llamada_validar_origen(string $source): string {
    if (!in_array($source, ['mobile', 'panel'], true)) {
        throw new LlamadaValidationError('invalid source');
    }
    return $source;
}
```

El servicio debe:

1. validar el deal y su responsable;
2. validar la actividad técnica solo en `mobile`;
3. validar el teléfono contra la actividad o contacto;
4. localizar la pendiente excluyendo la técnica cuando exista;
5. devolver `manual_review` con `reason=pending_activity_not_found` si la pendiente no es única;
6. ejecutar `claimCycle()` antes del primer `crm.activity.update`;
7. si el ciclo pertenece a otra operación con el mismo resultado, completar la operación actual con respuesta `already_processed` sin escribir;
8. si el resultado previo es distinto, lanzar `LlamadaIdempotenciaConflict`;
9. actualizar la técnica solo en `mobile`;
10. completar la pendiente y continuar con la creación/comentario/etapa existentes.

- [ ] **Step 4: Ejecutar la prueba y corregir únicamente el servicio hasta dejarla verde**

Run: `php tests/test-llamada-resultado-service.php`

Expected: `OK`, una única alta de actividad en todos los casos cruzados.

- [ ] **Step 5: Ejecutar toda la suite PHP**

Run: `php tests/run.php`

Expected: `OK`, código 0.

- [ ] **Step 6: Commit**

```bash
git add lib/llamada-resultado-service.php tests/test-llamada-resultado-service.php
git commit -m "feat: compartir no contestado entre movil y Bitrix"
```

### Task 3: Entrada autenticada para el botón de Bitrix

**Files:**
- Create: `api/llamadas/no-contesto.php`
- Create: `tests/test-no-contesto-panel-endpoint.php`
- Modify: `tests/run.php`

**Interfaces:**
- Consumes: token temporal de `BX24.getAuth().access_token` y `llamada_procesar_resultado(..., 'panel')`.
- Produces: `llamada_no_contesto_panel_http(string $method, string $body, array $env, callable $userCurrent, callable $bx, int $now): array`.
- Request JSON: `{requestId, auth, dealId, selectedPhone, comment}`.
- Success JSON: `{status, requestId, outcome, nextActivityAt}`.

- [ ] **Step 1: Escribir pruebas fallidas del endpoint**

```php
$response = llamada_no_contesto_panel_http('POST', json_encode([
    'requestId' => '33333333-3333-4333-8333-333333333333',
    'auth' => 'seller-token',
    'dealId' => 77,
    'selectedPhone' => '+593991234567',
    'comment' => '',
]), $env, fn(string $token): int => $token === 'seller-token' ? 42 : 0, $fake, $now->getTimestamp());
test_same(200, $response['status'], 'valid seller request is accepted');
test_same('processed', $response['body']['status'], 'panel returns shared result');
```

Añadir una tabla literal para las entradas inválidas y afirmar que ninguna escribe en CRM:

```php
$invalidCases = [
    ['GET', $validBody, fn(string $token): int => 42, 400],
    ['POST', '{', fn(string $token): int => 42, 400],
    ['POST', $validBody, fn(string $token): int => 0, 401],
];
foreach ($invalidCases as [$method, $body, $currentUser, $expectedStatus]) {
    $fake = new FakeBitrix();
    $response = llamada_no_contesto_panel_http(
        $method, $body, $env, $currentUser, $fake, $now->getTimestamp()
    );
    test_same($expectedStatus, $response['status'], 'invalid panel request status');
    test_same([], llamada_calls($fake, 'crm.activity.update'), 'invalid panel request updates nothing');
    test_same([], llamada_calls($fake, 'crm.activity.add'), 'invalid panel request adds nothing');
}
```

Para deal ajeno, preparar `ASSIGNED_BY_ID=99` y esperar 403; para ciclo duplicado, enviar dos UUID distintos sobre la misma pendiente y esperar 200 con `already_processed`; para conflicto, registrar primero `answered` con el servicio móvil y esperar 409 al enviar `no_answer`; para pendiente ambigua, preparar dos candidatas sin teléfono y esperar 422; para error Bitrix, devolver `['ok'=>false,'error'=>'NETWORK']` en `crm.activity.update` y esperar 503. En los cuatro errores se afirman literalmente cero llamadas `crm.activity.add`.

- [ ] **Step 2: Ejecutar y verificar el fallo por endpoint inexistente**

Run: `php tests/test-no-contesto-panel-endpoint.php`

Expected: FAIL porque no existe `llamada_no_contesto_panel_http()`.

- [ ] **Step 3: Implementar el endpoint sin guardar el token del vendedor**

La capa HTTP valida el cuerpo, verifica el token mediante `user.current`, construye el input canónico y llama al servicio:

```php
$input = [
    'callRequestId' => strtolower($decoded->requestId),
    'memberId' => 'panel-' . $bitrixUserId,
    'dealId' => (int)$decoded->dealId,
    'bitrixUserId' => $bitrixUserId,
    'bitrixActivityId' => null,
    'outcome' => 'no_answer',
    'selectedPhone' => (string)$decoded->selectedPhone,
    'nextActivityAt' => null,
    'comment' => (string)($decoded->comment ?? ''),
];
```

La función de producción usa el mismo token para las llamadas REST del vendedor y nunca lo escribe en SQLite, respuesta o logs.

- [ ] **Step 4: Ejecutar pruebas del endpoint y suite completa**

Run: `php tests/test-no-contesto-panel-endpoint.php; php tests/run.php`

Expected: ambos terminan con `OK` y código 0.

- [ ] **Step 5: Commit**

```bash
git add api/llamadas/no-contesto.php tests/test-no-contesto-panel-endpoint.php tests/run.php
git commit -m "feat: exponer no contestado al panel Bitrix"
```

### Task 4: Conectar el placement de Bitrix al servicio compartido

**Files:**
- Create: `assets/llamada-no-contesto-client.js`
- Create: `tests/test-llamada-no-contesto-client.mjs`
- Modify: `llamada_nativo.php`

**Interfaces:**
- Consumes: `POST /api/llamadas/no-contesto.php` de Task 3.
- Produces: `GaljosaNoContesto.enviar(input, transport?) -> Promise<PanelResult>`.

- [ ] **Step 1: Escribir la prueba fallida del cliente real**

```js
const sent = [];
const result = await client.enviar({
  requestId: '33333333-3333-4333-8333-333333333333',
  auth: 'seller-token',
  dealId: 77,
  selectedPhone: '+593991234567',
  comment: '',
}, async (url, init) => {
  sent.push({ url, init });
  return { ok: true, status: 200, json: async () => ({ status: 'processed', nextActivityAt: '2026-08-26T12:30:00-05:00' }) };
});
assert.equal(sent[0].url, '/api/llamadas/no-contesto.php');
assert.equal(JSON.parse(sent[0].init.body).auth, 'seller-token');
assert.equal(result.status, 'processed');
```

También probar `already_processed`, 409, 422, 503 y que ningún error incluya el token.

- [ ] **Step 2: Ejecutar y verificar que falla por módulo inexistente**

Run: `node tests/test-llamada-no-contesto-client.mjs`

Expected: FAIL con `ERR_MODULE_NOT_FOUND`.

- [ ] **Step 3: Implementar el cliente mínimo y cargarlo desde el placement**

El cliente usa `fetch`, `Content-Type: application/json`, timeout de 12 segundos y mensajes tipados. `llamada_nativo.php` genera un UUID v4 una vez por apertura, obtiene `BX24.getAuth().access_token` al registrar y llama `GaljosaNoContesto.enviar()`.

La ruta `autoRegistrar()` deja de ejecutar `crm.activity.add` directamente. Al recibir `processed` actualiza el mensaje visible con la próxima fecha; al recibir `already_processed` informa que ya fue registrado; 422 pide revisión manual; 503 ofrece reintentar. El botón de deshacer no se muestra para el nuevo flujo porque la primera confirmación es definitiva y las correcciones se hacen en Bitrix.

- [ ] **Step 4: Ejecutar pruebas JavaScript y sintaxis PHP**

Run: `node tests/test-llamada-no-contesto-client.mjs; php -l llamada_nativo.php; php -l api/llamadas/no-contesto.php`

Expected: prueba `OK` y ambos archivos PHP muestran `No syntax errors detected`.

- [ ] **Step 5: Ejecutar toda la suite**

Run: `php tests/run.php`

Expected: `OK`, código 0.

- [ ] **Step 6: Commit**

```bash
git add assets/llamada-no-contesto-client.js tests/test-llamada-no-contesto-client.mjs llamada_nativo.php
git commit -m "feat: conectar boton Bitrix al resultado compartido"
```

### Task 5: Compatibilidad, auditoría y despliegue piloto

**Files:**
- Create: `docs/deployment/no-contesto-compartido.md`
- Verify only: `C:/Users/Pauta 01/Documents/Codex/2026-08-17/o-procesos-que-podr-amos-acortar-2/work/bitrix-sim-bridge/.worktrees/v1-resultados-llamada`

**Interfaces:**
- Consumes: endpoint privado móvil v1 sin cambios y nuevo endpoint panel.
- Produces: versión desplegada de `inventario-sync` compatible con la PWA instalada.

- [ ] **Step 1: Ejecutar la verificación completa local**

Run in `inventario-sync`:

```powershell
php tests/run.php
node tests/test-llamada-no-contesto-client.mjs
php -l llamada_nativo.php
php -l api/llamadas/no-contesto.php
git diff --check origin/main...HEAD
```

Expected: `OK`, sintaxis válida, diff sin errores.

- [ ] **Step 2: Verificar que el puente conserva su contrato**

Run in `bitrix-sim-bridge`:

```powershell
pnpm --filter @bitrix-sim-bridge/api test -- --run
pnpm --filter @bitrix-sim-bridge/pwa test -- --run
pnpm -r build
```

Expected: cero pruebas fallidas y compilación con código 0. No se modifica ni despliega el puente.

- [ ] **Step 3: Documentar el piloto y reversión**

Crear `docs/deployment/no-contesto-compartido.md` con esta secuencia operativa exacta:

1. desplegar `inventario-sync` entre llamadas;
2. comprobar `GET /`=200 y que `GET /api/llamadas/no-contesto.php` devuelve 400 sin hacer escrituras;
3. abrir un deal de prueba de Martín y ejecutar una solicitud controlada;
4. repetir desde el mismo canal y confirmar una sola próxima actividad;
5. repetir cruzando celular/Bitrix y confirmar `already_processed`;
6. habilitar la prueba de Doménica y Nicolás;
7. si falla, volver al commit de producción anterior en EasyPanel; las vinculaciones del puente permanecen intactas.

- [ ] **Step 4: Commit de documentación**

```bash
git add docs/deployment/no-contesto-compartido.md
git commit -m "docs: documentar piloto de no contestado compartido"
```

- [ ] **Step 5: Integrar y desplegar `inventario-sync`**

Publicar la rama verificada en el repositorio remoto, integrarla en `main` sin reescribir historial y lanzar el despliegue del servicio `inventario-sync` en EasyPanel. No desplegar `bitrix-sim-bridge`.

- [ ] **Step 6: Verificar producción con evidencia fresca**

Comprobar estado HTTP, logs sin errores de arranque, carga del placement, recepción normal de llamadas en los celulares ya vinculados y una prueba cruzada en un deal controlado. No declarar completado hasta confirmar una sola actividad futura en Bitrix.
