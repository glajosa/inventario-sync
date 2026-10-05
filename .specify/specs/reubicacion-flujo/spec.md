# Spec — Flujo completo de REUBICACIÓN (cobranzas ↔ clientes)

Autor del pedido: Jesua (5-oct-2026). Estado: **borrador, falta 1 decisión (ver §6)**.

## 1. Qué se quiere (en palabras del negocio)

Un cliente que ya compró cambia de unidad. Hoy la reubicación cambia la unidad
y nada más: el deal de COBRANZAS se queda con la tabla, las cuotas y los montos
de la unidad VIEJA. Se quiere que la reubicación arranque un ciclo ordenado:

1. **En COBRANZAS (48)**, al reubicar:
   - el deal pasa a la etapa nueva **REUBICACIÓN** (`C48:UC_1WR2BM`);
   - se **vacían los campos que llena la tabla de pagos**: VALOR DEL ACTIVO,
     CRÉDITO DIRECTO, ANTICIPO CLIENTE, CONTRA ENTREGA, VALORES A PAGAR (corte),
     SALDO CRÉDITO DIRECTO, SALDO DEL ACTIVO y VALOR FINAL DEL ACTIVO;
   - se **borran sus dependencias (cuotas)**, siempre con la copia previa en
     `.bxstate/respaldo_deps/` (regla: la copia no se borra nunca);
   - **ACTIVO COMPRADO** se queda: lo pone la reubicación (unidad nueva).
2. **En CLIENTES (44)** el deal de esa compra pasa a **ELABORACIÓN PROMESA DE
   COMPRAVENTA** (ya hecho, b488e7c). Sigue su camino normal: LISTO PARA FIRMA →
   PROMESA FIRMADA POR CLIENTE.
3. En PROMESA FIRMADA la automatización de siempre **copia el 44 a un deal nuevo
   en COBRANZAS (RESERVA)**, con el mismo nombre, la misma unidad y el mismo cliente.
4. **Fusión:** el deal nuevo se junta con el antiguo, que espera en REUBICACIÓN.
   **Sobrevive el ANTIGUO** (mismo número, su historial, su puntaje). Del nuevo
   se toman **la etapa (RESERVA) y la tabla de pagos**. Con esa tabla se crean las
   dependencias nuevas en el antiguo, por el import de siempre.
5. **Lo pagado** no se pierde: en la tabla nueva el negocio pone todo lo pagado
   en la dependencia **RESERVA** (práctica habitual, confirmado por Jesua).
6. **Puntaje:** la base es el **puntaje histórico** de la unidad anterior. Corre
   con la unidad y la tabla nuevas: si paga bien, el puntaje malo va bajando.
   "Es importantísimo."

## 2. Qué NO entra
- No se cambia cómo se calcula la etapa de mora (FIFO) ni la tabla en sí.
- No se toca el deal de CLIENTES más allá de la etapa (ya hecho en b488e7c).
- No se reubican deals que no están en COBRANZAS.

## 3. Cómo se reconoce que el deal nuevo es "el mismo"
Deal nuevo en `C48:NEW` (RESERVA) **y** existe otro 48 del **mismo contacto**,
en etapa **REUBICACIÓN**, con el **mismo ACTIVO COMPRADO** y el mismo proyecto
(llave proyecto + código). Debe haber exactamente uno: si hay 0 se importa normal;
si hay 2 o más, no se fusiona y se avisa al monitor.

## 4. Quién hace cada cosa
| Paso | Sistema | Disparo |
|---|---|---|
| Etapa REUBICACIÓN en el 48 + ACTIVO COMPRADO | inventario-sync (reubicalib) | cambio de Inventario en el 48 |
| Vaciar campos de tabla + borrar cuotas (con copia) + guardar puntaje base | cobranza2 | evento: el 48 entra a REUBICACIÓN |
| 44 a ELABORACIÓN PROMESA | inventario-sync | (ya hecho) |
| Fusión: tabla y etapa del nuevo → antiguo, import, nuevo fuera | cobranza2 | evento ADD del 48 nuevo |
| Puntaje con base histórica | cobranza2 (lib_score) | cron del score |

## 5. Cómo se verifica
- Prueba fija con el caso Marcel Salvador (9610 / 5792 → E-1-20).
- 48 en REUBICACIÓN: los 8 campos vacíos, 0 cuotas, y la copia existe con las
  mismas cuotas y el mismo pagado.
- Deal nuevo en RESERVA → el antiguo vuelve a RESERVA con la tabla nueva y sus
  cuotas, y la suma de la tabla cuadra; el nuevo queda fuera (§6).
- Puntaje: la base guardada + los meses nuevos. Un mes pagado a tiempo con la
  tabla nueva baja la mora seguida; no arranca de cero.
- Perillas: `reubica_etapa_on`, `reubica_vaciar_on`, `reubica_fusion_on`,
  `score_base_reubica_on`. Todas se pueden apagar sin re-subir código.

## 6. Decisión abierta (Jesua)
**¿Qué pasa con el deal NUEVO después de pasarle su tabla y su etapa al antiguo?**
- **(a) Perdido** con el título `FUSIONADO → <id antiguo>`. Queda como rastro y no
  cuenta en cartera. **Recomendado:** no se borra nada.
- (b) Borrarlo. La "fusión" de Bitrix desde la API no deja elegir qué datos
  sobreviven, y un borrado no se puede deshacer.
