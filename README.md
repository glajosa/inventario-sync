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
  interesa”.
- `DATA_DIR` — directorio persistente y escribible para la idempotencia; en
  EasyPanel debe mantenerse montado en `/data`.

La firma es HMAC-SHA256 hexadecimal sobre los bytes exactos de
`<timestamp>\n<cuerpo>`. El timestamp Unix se envía en
`X-Galjosa-Timestamp` y la firma en `X-Galjosa-Signature`; tiene una vigencia
máxima de cinco minutos.

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
