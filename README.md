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
