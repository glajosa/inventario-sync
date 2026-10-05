# Plan — Flujo completo de REUBICACIÓN

Base: spec.md (revisada por VIGILANTE el 5-oct). Supuestos marcados **[D1]** y **[D2]**:
son las decisiones pendientes de Jesua. Mientras no contesta, se usa lo recomendado.
- **[D1]** el deal nuevo va a LOSE con el título `FUSIONADO → <id antiguo>` (no se borra).
- **[D2]** se vacía y se borran las cuotas del antiguo **al fusionar**, no al entrar a REUBICACIÓN.

## Tramo 1 — inventario-sync (reubicalib.php), perilla `REUBICA_ETAPA_48`
- La reubicación mueve el 48 a `C48:UC_1WR2BM` (REUBICACIÓN) en el mismo update de
  ACTIVO COMPRADO y del trío. Perilla de env, `0` = no se mueve.
- Se agrega `C48:UC_1WR2BM` a las listas de inventario-sync que nombran etapas del 48:
  - `lib/cobranza-protocolo.php`: la tabla de llamadas por etapa → **0 llamadas**; NO
    entra a `etapas_ciclo_mensual`; el orden de etapa la pone fuera del ciclo;
  - `stagelib.php`, `reconcile.php` y `cobranza_nativo.php` (el botón "No contestó"):
    REUBICACIÓN = "en esta etapa no se llama".
  - Prueba: los tests de cobranza-protocolo con la etapa nueva.

## Tramo 2 — cobranza2: la etapa no la toca el motor, perilla `reubica_etapa_on`
- `cobranza2.php:252` (mapa de etapas) agrega `'REUBICACION'=>'C48:UC_1WR2BM'`.
- En `procesar_deal()`: un 48 en REUBICACIÓN **no se recalcula ni se mueve de etapa**.
  Sale con el log "esperando fusión". Lo mismo en el barrido (`?barrer`) y en
  `$activas` (línea ~4190).
- `lib_campos_cob.php` (`cc_orden_etapa`, agenda, gestión): REUBICACIÓN = sin llamadas,
  como ADELANTADO.
- Puntaje (`score_cobranzas.php`): un deal en REUBICACIÓN no se re-puntúa; queda con
  el último puntaje calculado (que es la base).
- Dashboard de cobranzas: la etapa cuenta aparte (aviso a la sesión del dashboard).

## Tramo 3 — cobranza2: fusión, perilla `reubica_fusion_on`
Disparo: evento ADD (o primer UPDATE) de un 48 en `C48:NEW` (RESERVA) con tabla.
🔴 **Sin carrera (VIGILANTE, ajuste 1):** en el MISMO handler va primero el
emparejamiento y después el import. Si hay un antiguo en REUBICACIÓN, el import
del NUEVO no corre y las cuotas se crean en el antiguo.
Prueba: ADD del nuevo con un antiguo en REUBICACIÓN → 0 cuotas creadas en el nuevo.
1. Buscar el antiguo: los 48 del mismo contacto (libreta `/deals?contact=`) en
   REUBICACIÓN con el mismo (proyecto, código) en ACTIVO COMPRADO / título. Exactamente
   uno → sigue. Cero → import normal. Dos o más → no fusiona y avisa a falla.php.
2. **Foto del puntaje base** del antiguo, ANTES de tocar nada:
   `.bxstate/score_base_<antiguo>.json` con el historial FIFO completo (toda la plata:
   pagos sin cuota, reserva, notaría) y la guarda `score_fifo_cuadra` = OK. Si no
   cuadra → no fusiona y avisa (lección del 2525).
3. **[D2] Vaciar y borrar en el antiguo:**
   - copia en `respaldo_deps` y se comprueba que existe (sin copia no se borra);
   - anti-eco: se marcan como propios SOLO esos IDs de cuota. La marca
     `reubicando_<deal>` (15 min) tapa SOLO los eventos de cuotas de ese deal
     (ONCRMDYNAMICITEMDELETE/ADD), NUNCA el ONCRMDEALUPDATE: una marca a nivel de
     deal se come el cambio de una persona (ya pasó el 1-oct). Para el deal alcanza
     con el mark_self de cada escritura propia (ajuste 3);
   - se borra a 2-3 cuotas por segundo, sin anunciar barrido;
   - se vacían los 8 campos (spec §8).
4. Copiar la TABLA DE PAGOS del nuevo al antiguo (las mismas dos formas de
   `refi_cerrar`). El import crea las cuotas nuevas EN EL ANTIGUO (lo pagado entra
   por la RESERVA de la tabla). Recién **cuando ya existen las cuotas**, el antiguo
   sale de REUBICACIÓN: lo pone el motor FIFO; nunca se sale con 0 cuotas (ajuste 2).
5. **[D1]** El nuevo a `C48:LOSE` con el título `FUSIONADO → <antiguo>`, con mark_self.
6. Libreta de fusiones: `.bxstate/reubica_fusiones.jsonl` con el antes y el después.

## Tramo 4 — puntaje con base histórica, perilla `score_base_reubica_on`
- `score_fifo_hist()` recibe, si existe `score_base_<deal>.json`, el historial base y
  lo **antepone** a los meses de la tabla nueva: los meses en mora, las recaídas y
  la racha siguen desde ahí. Un mes nuevo pagado a tiempo corta la racha y la
  tendencia mejora; no arranca de cero.
- Guarda: `score_fifo_cuadra` contra el vencido del motor antes y después de la fusión.

## Tramo 5 — vigilante
- Cron de cobranza2 (en el `?reactivo` existente, compuerta de 1 h): los 48 en
  REUBICACIÓN hace más de `reubica_alerta_horas` (por defecto 72) → falla.php con
  el deal y desde cuándo. Lectura por libreta (`/deals?category=48&stages=C48:UC_1WR2BM`).

## Pruebas (antes de cada despliegue)
- Funciones puras: emparejamiento (Marcel: 25 deals; 0, 1 y 2 candidatos), la
  decisión de vaciar, el título FUSIONADO y la combinación base + nuevo del puntaje.
- Cada guarda rota a propósito → la prueba en rojo.
- Prueba real con un deal de prueba de Jesua antes de encender `reubica_fusion_on`.

## Interacción con "cotizaron y no compraron" (inventario, ajuste 4)
Una reubicación reserva la unidad nueva y para `vendidas` cuenta como VENTA: a
quienes la cotizaron les llega "se vendió", y la vieja vuelve a DISPONIBLE.
Jesua (5-oct, a la sesión de inventario): "claro, porque es otra unidad como tal"
→ **cuenta como venta** (en confirmación por inventario).

## Llamadas durante la reubicación (pregunta de negocio, VIGILANTE 5-oct)
`cc_cierra_si_etapa_sin_gestion` CIERRA las llamadas pendientes de cobranza cuando
el deal entra a REUBICACIÓN.
**Por confirmar con Jesua:** ¿es lo que quiere? Y después de la fusión, el deal
vuelve a una etapa de mora y la gestión normal (evento + barrido de agenda) crea la
llamada que corresponda. Esto hay que verificarlo en la prueba real.

## Orden obligatorio
T3 (reubicalib mueve el 48 a REUBICACIÓN) NO se enciende antes de T2 (el botón
"No contestó" en inventario-sync tiene que conocer la etapa).

## DECISIONES DE JESUA (5-oct, tarde) — mandan sobre los supuestos [D1]/[D2]
- **D1 = FUSIONAR**: sobrevive el viejo y el nuevo se BORRA. Es la vía probada de
  prospectosventas `fusionar_duplicado_en_madre` (reparent); `crm.entity.mergeBatch`
  NO sirve (STATUS CONFLICT). Antes de borrar el nuevo (VIGILANTE):
  - en `reubica_fusiones.jsonl` se guarda el deal completo (get), sus actividades y
    los comentarios de la línea de tiempo;
  - `crm.activity.binding.add` mueve las ACTIVIDADES; los comentarios
    (`crm.timeline.comment`) NO son actividades: se copian al viejo;
  - se comprueba que el nuevo tiene 0 cuotas y 0 ítems SPA atados. Si tiene alguno,
    NO se borra y se avisa a falla.php.
- **D2 = B1**: los 8 campos se vacían y las cuotas se borran AL CAMBIAR LA UNIDAD
  (con el paso a REUBICACIÓN). El 48 queda vacío hasta la fusión, protegido por
  v269. El vigilante es obligatorio.
- **Llamadas**: OK cerrarlas al entrar. REUBICACIÓN cumple el papel que hoy, a mano,
  cumple DADO DE BAJA (soltar la unidad vieja antes de cambiarla).
- **Cuenta como venta**: sí.

## CAMINO DE VUELTA (reubicación cancelada o hecha por error, como el 9610)
1. Volver la unidad en Inventario a la vieja. Eso es otra reubicación al revés y la
   hace reubicalib (el trío 2 guarda el paso).
2. Restaurar los 8 campos desde la línea `antes` de `/data/reubicaciones.jsonl`
   (inventario-sync) o desde `reubica_fusiones.jsonl` (cobranza2):
   `php ~/diag/reubica_restaurar.php <deal48> --campos` (se escribe en T5; lee la
   foto y hace 1 update).
3. Restaurar las cuotas desde `.bxstate/respaldo_deps/<deal>-reubica-*.json` con el
   mismo restaurador de v267 (`--cuotas`). Recrea los ítems con su presupuestado y su
   ejecutado.
4. Sacar el 48 de REUBICACIÓN: el motor lo pone en su etapa por el dinero.
