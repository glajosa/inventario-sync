# inventario-sync

Servicio dedicado. Recibe webhook de salida de Bitrix24 y sincroniza los campos
**Inventario 2 / 3 / 4** de un deal (pipeline 44 CLIENTES) hacia la relación
nativa `parentId2` de cada unidad del SPA Inventario (entityTypeId 1072).

- `hook.php` — endpoint del webhook de salida (ONCRMDEALUPDATE/ADD/DELETE)
- `rebuild.php` — conserje: reconstruye la allowlist de deals P44 (cron 30 min)
- `index.php` — healthcheck

Env vars requeridas (EasyPanel):
- `BITRIX_WEBHOOK` — webhook ENTRANTE de Bitrix (scope crm), termina en `/`
- `OUTBOUND_TOKEN` — token del webhook de SALIDA (valida quién llama)
- `DATA_DIR` — default `/data` (montar volumen persistente ahí)

## API privada de resultados de llamada

`POST /api/private/llamadas/v1/resultado` recibe un objeto JSON de hasta
64 KiB. Es una integración servidor-servidor: no habilita CORS y rechaza otros
métodos y tipos de contenido.

Variables obligatorias:

- `INVENTARIO_SYNC_SHARED_SECRET` — secreto compartido de 32 caracteres o más;
  debe cargarse directamente en el entorno y nunca guardarse en Git.
- `NO_INTEREST_STAGE_ID` — identificador de la etapa de Bitrix para “No le
  interesa”. Debe identificarse por lectura con `crm.status.list` en la categoría
  del negocio designado para la prueba; no se deduce de la etiqueta ni se copia
  de otro pipeline.
- `DATA_DIR` — directorio persistente y escribible para la idempotencia; en
  EasyPanel debe mantenerse montado en `/data`.

La firma es HMAC-SHA256 hexadecimal sobre los bytes exactos de
`<timestamp>\n<cuerpo>`. El timestamp Unix se envía en
`X-Galjosa-Timestamp` y la firma en `X-Galjosa-Signature`; tiene una vigencia
máxima de cinco minutos.

`Idempotency-Key` también es obligatorio. Debe ser el mismo UUID v4 canónico
en minúsculas que `callRequestId` en el JSON, sin espacios ni otra
normalización. Si falta, no tiene ese formato o no coincide exactamente, la API
responde `400 {"error":"invalid_request"}` antes de crear el registro durable o
llamar a Bitrix. Esta cabecera no forma parte del HMAC: la firma continúa
cubriendo únicamente `<timestamp>\n<cuerpo>`.

El fixture de aceptación v1 está congelado en
`tests/fixtures/call-result-v1.json` y debe ser idéntico al archivo
`contracts/inventario-sync-call-result-v1.json` del puente. Ambos archivos
contienen una sola línea JSON y un terminador de línea de editor; el terminador
se elimina de forma explícita antes de firmar y enviar. La prueba del endpoint
firma esos bytes exactos y exige una respuesta `processed` para `answered` con
la actividad `731`.

Ejemplo local en PowerShell (usa el secreto del entorno, sin imprimirlo):

```powershell
$url = 'https://inventario.example/api/private/llamadas/v1/resultado'
$secret = $env:INVENTARIO_SYNC_SHARED_SECRET
if ([string]::IsNullOrWhiteSpace($secret)) { throw 'Falta INVENTARIO_SYNC_SHARED_SECRET' }

$body = '{"callRequestId":"11111111-1111-4111-8111-111111111111","memberId":"member-1","dealId":77,"bitrixUserId":42,"bitrixActivityId":731,"outcome":"no_answer","selectedPhone":"+593991234567","nextActivityAt":null,"comment":""}'
$timestamp = [DateTimeOffset]::UtcNow.ToUnixTimeSeconds().ToString()
$utf8 = [Text.Encoding]::UTF8
$hmac = [Security.Cryptography.HMACSHA256]::new($utf8.GetBytes($secret))
try {
    $signature = [Convert]::ToHexString($hmac.ComputeHash($utf8.GetBytes("$timestamp`n$body"))).ToLowerInvariant()
} finally {
    $hmac.Dispose()
}

Invoke-RestMethod -Uri $url -Method Post -ContentType 'application/json; charset=utf-8' `
    -Headers @{
        'Idempotency-Key' = '11111111-1111-4111-8111-111111111111'
        'X-Galjosa-Timestamp' = $timestamp
        'X-Galjosa-Signature' = $signature
    } -Body $utf8.GetBytes($body)
```

Respuestas públicas: `200 processed/already_processed`, `400 invalid_request`,
`401 unauthorized`, `403 forbidden`, `409 conflict`, `422 manual_review` y
`503 bitrix_unavailable`. Si la misma operación todavía está activa, responde
`503` con `{"status":"processing","callRequestId":"...","reason":"processing"}`
y `Retry-After: 1`; un `callRequestId` reutilizado con otro cuerpo conserva
`409 {"error":"conflict"}`. No se devuelven mensajes internos de Bitrix ni
datos de autenticación.

## Despliegue seguro de la API privada

`inventario-sync` se despliega antes que `bitrix-sim-bridge`, cuando todavía no
existe un consumidor activo. El orden operativo es:

1. registrar el digest de la imagen actual y la candidata;
2. confirmar que el mismo volumen persistente continúa montado en `/data` y
   crear un backup protegido; no reemplazarlo por un volumen vacío;
3. conservar `BITRIX_WEBHOOK`, `OUTBOUND_TOKEN` y las variables existentes;
4. cargar directamente `INVENTARIO_SYNC_SHARED_SECRET` y
   `NO_INTEREST_STAGE_ID` como variables protegidas, sin imprimir sus valores;
5. pulsar **Implementar** manualmente. EasyPanel puede no registrar el primer
   clic: exigir una ejecución nueva, confirmar en su log el commit completo
   candidato y comparar el digest producido por ese build con el digest del
   contenedor activo; un health correcto de la imagen anterior no alcanza;
6. ejecutar las pruebas del mismo digest en un contenedor aislado, con
   `DATA_DIR=/tmp`, no contra `/data` real:

```powershell
docker run --rm --entrypoint php -e DATA_DIR=/tmp inventario-sync:candidate /var/www/html/tests/run.php
```

7. comprobar `GET /`: debe devolver `service=inventario-sync` y `ok=true`;
8. comprobar la ruta privada con `POST {}` sin firma: debe responder
   `401 {"error":"unauthorized"}`. El rechazo ocurre antes de crear idempotencia
   o llamar a Bitrix;
9. comprobar que `/tests/run.php` responde `403`, que la respuesta privada
   incluye `Cache-Control: no-store` y `X-Content-Type-Options: nosniff`, y que
   no incluye `Access-Control-Allow-Origin`;
10. desde la consola del bridge desplegado, enviar `{}` firmado con sus propias
   variables y sin `Idempotency-Key`: debe responder `400 invalid_request`. Ese
   orden demuestra que la firma fue aceptada, pero se detiene antes de abrir
   SQLite o llamar a Bitrix;
11. comprobar que el panel de llamadas vigente continúa funcionando;
12. solo entonces habilitar llamadas reales del piloto.

El endpoint no expone CORS y Apache niega acceso web a `/tests`. La idempotencia
durable vive en `/data/llamada-resultados.sqlite`; conservar ese archivo evita
que un reinicio o cambio de imagen repita comentarios o acciones comerciales.

## Reversión sin pérdida de actividades

Si la API privada falla, se pausa el rollout y no se repiten manualmente la
actividad, el comentario ni el cambio de etapa. El bridge conserva el resultado
pendiente y su `CRM_ACTIVITY_ID` para reintentar con la misma clave.

Para revertir `inventario-sync`:

1. seleccionar el digest inmutable anterior;
2. desplegarlo con exactamente el mismo volumen `/data` y las mismas variables
   vigentes;
3. no borrar ni recrear `llamada-resultados.sqlite`, `allowlist.json`, logs o
   cachés operativos;
4. verificar `GET /` y el panel existente;
5. conservar para revisión cualquier operación `manual_review` o pendiente.

La reversión cambia el código que atenderá solicitudes futuras. Nunca borra o
deshace actividades, comentarios o etapas ya confirmados en Bitrix. Una
corrección de esos datos se acuerda y realiza manualmente con evidencia.
