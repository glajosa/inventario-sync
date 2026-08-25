# Diseño: `No contestó` compartido entre Bitrix y la web app

**Fecha:** 2026-08-25
**Estado:** aprobado por Martín Hidalgo

## Objetivo

Convertir el resultado `No contestó` en una sola operación de negocio. El botón existente dentro de Bitrix y el botón de la web app móvil deben invocar exactamente la misma lógica, completar la actividad pendiente y crear como máximo una próxima actividad según las reglas actuales del desarrollo de Jeshua.

El registro técnico de la llamada se conserva como historial. No cuenta como actividad futura ni como duplicado.

## Alcance aprobado

- `No contestó` usa una única función central desde Bitrix y desde la web app.
- La operación completa la actividad pendiente y crea la próxima actividad con las reglas vigentes de Jeshua.
- `Sí contestó` sigue siendo una función propia de la web app: registra el resultado, completa la actividad pendiente, no mueve etapa y no crea una actividad futura.
- `No le interesa` mantiene el comportamiento aprobado de mover el deal a la etapa correspondiente.
- El comentario móvil permanece opcional.
- El segundo intento de llamada permanece disponible y es opcional. Elegirlo todavía no registra `No contestó`.

## Arquitectura

La lógica actualmente ejecutada por el navegador de Bitrix se trasladará a un servicio central del backend de `inventario-sync`. Este servicio será la fuente única de verdad para el resultado `No contestó`.

Habrá dos entradas al mismo servicio:

1. El botón de Bitrix enviará la identidad del usuario, deal y contexto de la llamada usando la sesión autenticada de Bitrix.
2. La web app enviará el resultado mediante su puente privado autenticado y firmado.

Las entradas no reproducirán la lógica por separado. Ambas validarán sus credenciales y luego llamarán a la misma función interna.

## Flujo de `No contestó`

1. Validar vendedor, deal y pertenencia de la solicitud.
2. Identificar de forma segura la actividad pendiente asociada a la llamada.
3. Construir una clave estable para esa llamada y resultado.
4. Obtener un bloqueo exclusivo para esa clave.
5. Consultar el registro interno de resultados.
6. Si ya fue procesada, devolver el resultado existente sin modificar Bitrix.
7. Completar la actividad pendiente.
8. Aplicar el protocolo, horario y secuencia vigentes del desarrollo de Jeshua.
9. Crear una única próxima actividad.
10. Guardar el resultado interno antes de liberar el bloqueo.
11. Devolver al canal solicitante un mensaje de éxito o de operación ya registrada.

Si no se puede identificar la actividad pendiente sin ambigüedad, la operación no completará una actividad al azar ni creará una próxima actividad. Pedirá revisión manual.

## Prevención de duplicados y conflictos

La protección no dependerá únicamente del texto o la hora visible de las actividades de Bitrix. Existirá un registro interno atómico, relacionado principalmente con la actividad pendiente original.

Casos cubiertos:

- doble toque desde el celular;
- doble clic desde Bitrix;
- celular seguido de Bitrix;
- Bitrix seguido de celular;
- solicitudes simultáneas desde ambos canales;
- reintento después de una pérdida de conexión;
- llamadas distintas al mismo deal.

La primera operación válida aceptada para una llamada prevalece. Un resultado posterior incompatible, por ejemplo `No contestó` después de `Sí contestó`, no modificará nuevamente el deal. Las correcciones de un resultado seleccionado por error se harán manualmente en Bitrix para conservar trazabilidad.

## Otros resultados móviles

### Sí contestó

- conserva el registro técnico de la llamada;
- completa la actividad pendiente asociada;
- registra que la llamada se concretó;
- no crea próxima actividad;
- no cambia la etapa del deal.

### No le interesa

Conserva su funcionamiento aprobado y mueve el deal a la etapa `No le interesa`, aplicando la misma protección de una sola ejecución para la llamada.

### Segundo intento

`Volver a llamar` inicia una nueva marcación opcional sin cerrar ni registrar todavía el resultado definitivo. El resultado se confirma después del segundo intento o cuando el vendedor decida terminar el flujo.

## Compatibilidad y despliegue sin interrupción

El cambio se desplegará de forma aditiva y compatible:

1. Publicar el servicio central sin cambiar todavía ninguno de los botones actuales.
2. Verificarlo con solicitudes de prueba.
3. Conectar el botón de Bitrix manteniendo disponible la implementación anterior como reversión inmediata.
4. Conectar la web app al servicio compartido.
5. Probar primero con Doménica y Nicolás.
6. Habilitarlo para los ocho vendedores tras validar el piloto.

Las vinculaciones, suscripciones a notificaciones y códigos existentes no se reemplazarán. Los vendedores no deberán borrar ni reinstalar la web app.

Un despliegue del servicio puede provocar unos segundos de reinicio técnico. Por eso se hará entre llamadas y con compatibilidad hacia atrás. Una llamada ya abierta en el teléfono no se corta, porque la comunicación ocurre mediante la SIM o WhatsApp; si el envío del resultado coincide con el reinicio, la aplicación conservará la solicitud y permitirá reintentar sin duplicarla.

## Manejo de errores

- Un reintento devuelve el resultado ya almacenado en lugar de repetirlo.
- Una llamada a Bitrix que falle no se marcará internamente como terminada hasta confirmar la operación necesaria.
- Los pasos parciales quedarán identificados para poder continuar de forma segura.
- Una inconsistencia o ambigüedad detendrá la automatización y mostrará revisión manual.
- Los mensajes al vendedor serán simples: procesado, ya registrado, reintentar o revisar manualmente.

## Pruebas de aceptación

- `No contestó` desde Bitrix completa la pendiente y crea una próxima actividad.
- `No contestó` desde móvil produce exactamente el mismo resultado.
- Doble pulsación en cada canal produce una sola próxima actividad.
- Pulsaciones cruzadas y simultáneas producen una sola próxima actividad.
- Un reintento de red no duplica actividades.
- Dos llamadas diferentes al mismo deal pueden registrarse por separado.
- `Sí contestó` completa la pendiente y no crea próxima actividad.
- `Sí contestó` bloquea un `No contestó` posterior para la misma llamada.
- El segundo intento no registra resultado antes de confirmarlo.
- Una actividad pendiente ambigua no se completa automáticamente.
- Doménica y Nicolás continúan recibiendo llamadas durante la transición y mantienen su vinculación.

## Fuera de alcance

- Automatizar correcciones cuando el vendedor eligió un resultado equivocado.
- Cambiar automáticamente de etapa al usar `Sí contestó`.
- Crear automáticamente la próxima actividad al usar `Sí contestó`.
- Reinstalar o volver a vincular los celulares existentes.
