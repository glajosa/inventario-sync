# Firma moldeable en el cotizador

Pedido del usuario, 16-sep-2026. Textual lo que importa:
· "que si yo quiero pagar la firma el mismo mes de la reserva, poder ponerle ese mes
   mismo y poder escoger el dia"
· "que se pueda dividir asi como hoy, pero que por ejm pago la firma en 3 meses, hasta
   diciembre pero NETAMENTE la firma, y de ahi, en enero recien la primera cuota"
· "eso va a incrementar los valores de las cuotas... tienes que poner ahi la opcion, si
   quiere aumentarlas en las extraordinarias, en cual, que puedas escoger, o que se
   agregue a las cuotas"

## Lo que YA existe (medido, no supuesto)

| | estado |
|---|---|
| Elegir la FECHA de la firma, dia incluido, incluso el mes de la reserva | ✅ `ffirma`, `<input type="date">` |
| Elegir el mes de la PRIMERA CUOTA | ✅ `mes`, `<input type="month">` |
| Partir la firma en N meses | ✅ `firmaMeses` / `firmaCuota` |
| Elegir MES y MONTO de cada pago de firma | ✅ `firmaPlan` (09-sep) |
| Personalizar el monto de cada extraordinaria | ✅ `extraMontos` |

Medido en A-1-7 ($111.000, entrega 2031-04):
    automatico                  55 cuotas · 1ra 16/10/2026 · mensual 403,64
    primera cuota a 2027-01     52 cuotas · mensual 426,92
    firmaMeses=3                55 cuotas desde octubre, la firma ENCIMA de las 3 primeras

## Lo que falta

### F1 · La firma partida va ANTES de las cuotas, no encima
Hoy los pagos de firma se SUMAN a la cuota de esos meses. Se quiere que sean pagos
propios ("netamente la firma"): filas aparte en la tabla, y la primera CUOTA arranca
despues del ultimo pago de firma.

### F2 · Donde se absorbe el acortamiento del plazo
Al correr la primera cuota, la entrega no se mueve: caben MENOS cuotas y cada una sube.
El asesor elige:
  a) en la CUOTA (lo de hoy: sube el mensual)
  b) en las EXTRAORDINARIAS que el elija (una, dos, las que diga)

## Lo que NO entra
· No se toca el tope de 12 meses de firma.
· No se mueve la fecha de entrega ni el plazo maximo: siguen mandando.
· No se cambia `extraMontos` (personalizar el reparto del pote). F2b AGREGA al pote.
· No se toca el cuadre al centavo ni el descuento de parqueo.

## Como se verifica
1. El plan sigue cumpliendo la identidad: separacion + firma + Σcuotas + Σextras +
   contraentrega = precio. En las 300+ combinaciones del banco de pruebas.
2. Ninguna cuota ni extraordinaria negativa.
3. F1: con la firma en 3 pagos hasta diciembre, la tabla muestra 3 filas FIRMA y la
   cuota 1 cae en enero. El total no cambia.
4. F2b: con la diferencia mandada a la extraordinaria elegida, el mensual se queda en
   el valor de 55 cuotas y la extraordinaria elegida sube exactamente la diferencia.
5. Lo de antes no se mueve: sin las banderas nuevas, cada caso da lo mismo que hoy.

## Riesgo declarado
🔴 F2b hace que las extraordinarias pasen del 10% declarado en el modelo del proyecto.
Es inevitable -- la plata tiene que salir de algun lado -- y es lo que el pidio. Queda
dicho en pantalla para que el asesor lo vea antes de cotizar.
