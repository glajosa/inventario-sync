# Historial de cotizaciones

Pedido del usuario (22-sep-2026): *"necesito que hagas una historia de las tablas de
pago que se hayan generado en el cotizador... una base de datos. Que estén todos los
que se hayan generado. Ya sea en filtro, en hoy, en ayer, en el mes, en la semana"*
y, aclarando: *"que guarde qué tabla se generó... cuál es su plan de pagos"*.

## Qué se quiere

Cada vez que el cotizador arma una tabla de pagos, queda guardada **entera** —no solo
los parámetros: las filas, con su fecha y su monto, tal como las vio el cliente. Se
consultan desde una pantalla con filtros por hoy / ayer / esta semana / este mes / un
rango, y se busca por cliente, unidad o deal. Cada cotización se puede **reabrir
exacta**.

Para qué sirve, en palabras del negocio: un cliente llama con un papel en la mano y
dice "a mí me dieron 53 cuotas de $418.87". Hoy no hay forma de saber quién se la dio,
cuándo, ni si ese número salió de este cotizador. Con esto, sí.

## Qué NO entra

- **No cambia un solo número del cálculo.** El cotizador sigue dando lo mismo.
- No escribe en Bitrix.
- No borra ni edita cotizaciones: es un registro, no un editor.
- No lleva usuarios ni contraseñas nuevas: se entra con el `OUTBOUND_TOKEN` del
  servicio, igual que `preciomadre.php`.

## Decisiones, con su porqué

1. **Se guarda al RENDERIZAR, no al descargar el PDF.** Una tabla que el asesor le
   mostró al cliente en pantalla ya circuló, se haya bajado el PDF o no.

2. **Misma tabla dos veces = UNA fila, con un contador.** El cotizador es una página
   que se recarga con cada cambio de campo; registrar cada recarga llenaría la base de
   borradores. Se identifica por una huella de todo lo que afecta al plan: si el asesor
   mueve un campo, es OTRA tabla y es otra fila; si vuelve a la misma, sube `veces` y
   `ultima_vez`.

3. **El día se cuenta en `America/Guayaquil`.** El contenedor no corre en hora de
   Ecuador: "hoy" del servidor no es el "hoy" del vendedor. Se guarda el instante en
   UTC y además el día ya resuelto en hora de Ecuador, para que el filtro no dependa
   de dónde se consulte.

4. **Si la base falla, el cotizador NO se cae.** Guardar el historial es secundario
   frente a cotizarle a un cliente. Todo el registro va en try/catch y, si falla, la
   cotización se muestra igual. Se prueba rompiéndola a propósito.

## Cómo se verifica

- Generar N tablas distintas -> N filas; repetir una -> `veces` sube y NO se duplica.
- El plan guardado se compara **fila a fila** contra el que devuelve el motor.
- Los filtros se cuentan contra una consulta independiente (no con el contador de la
  pantalla).
- Dejar la base en solo lectura y comprobar que la cotización igual se muestra.
