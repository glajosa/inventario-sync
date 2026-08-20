# Partir un deal en uno por unidad

**2026-08-20** · pendiente de luz verde del usuario

## El problema

Un deal puede llevar varias unidades en el campo Inventario. Eso sirve para una **fusión**
—dos locales que se unen, una sola compra— pero hay clientes que compran **activos
independientes**, cada uno con su contrato.

Hoy eso se resuelve a mano: el deal llega a RESERVA en CLIENTES y el director usa el
**"Copiar" nativo de Bitrix** (menú de engranaje del deal). El problema es que la copia nativa
duplica el campo Inventario tal cual, así que las dos unidades quedan nombradas en los dos
deals. Al quitar una de la copia, `propagar_quitada` se la quita también al original. El
director la vuelve a poner, y otra vez. Verificado en el log: `u=2061` quitada del 404143 a las
00:17 y repuesta a mano en el 404141 a las 00:24.

## Qué se quiere

Que el vendedor declare **al elegir las unidades** si van juntas o separadas, y que el sistema
haga la división solo.

### La marca gobierna DOS cosas
1. **Si el deal se parte** al llegar a RESERVA en CLIENTES.
2. **Si aplica el descuento de parqueo.** Regla del usuario, cerrada:
   - **fusión** → se restan $20.000 a una unidad
   - **separado** → **NO se resta nada**

Esa es la razón de fondo por la que los vendedores separan o fusionan. Un solo interruptor.

## Alcance

### A) Al pasar a RESERVA con varias unidades separadas
El deal se duplica tantas veces como unidades, y queda **una unidad en cada deal**.

### B) Ya en CLIENTES, el cliente escoge otra unidad
Opción en el desplegable del campo Inventario: **"copiar este deal con otra unidad"**. Elige la
unidad y se crea el deal duplicado. Mismo mecanismo, disparado a mano.

### Qué cambia en cada duplicado
La copia nativa de Bitrix deja **307 campos idénticos** y solo 6 distintos (medido sobre el par
404141/404143). Los que hay que fijar por unidad son estos, y son constantes que ya existen:

| Campo | Constante | A qué |
|---|---|---|
| `TITLE` | — | `Cliente--Proyecto--Unidad` con su código |
| `OPPORTUNITY` | — | PVP de esa unidad |
| `UF_CRM_1731969538` | `D_VALOR` | `<pvp>\|USD` |
| `UF_CRM_1732047127` | `D_ACTIVO` | código de la unidad |
| `UF_CRM_5EECED2074CC5` | `D_PROYECTO` | proyecto **de esa unidad** |
| `UF_CRM_1785205972989` | `CAMPO_NUEVO` | el ID de su unidad, solo el suyo |

🔴 **El proyecto va por unidad, no copiado del original**: las dos unidades pueden ser de
proyectos distintos — una de Noral Plaza y otra de Noral Apartments. Ese fue un pedido explícito.

Todo lo demás se copia igual: contacto, responsable, comentarios, cédula, referidor, origen.

## Qué NO entra
- No se toca el cronograma del 48. El plan de pagos lo arma `cobranza2` cuando se sube la
  **tabla de pagos**, no al entrar a RESERVA, así que cada deal nuevo lo recibe cuando suban su
  tabla. Confirmado por el usuario.
- No se deshace nada. Si el vendedor se equivoca marcando "separadas", **borrar el deal de más
  es manual**. El botón vive en esa ventana justamente para que lo haga consciente.
- No se fusionan deals de vuelta.

## Cómo se verifica
- Deal con 2 suites marcado **fusión** → un solo deal, monto con −$20.000.
- Deal con 2 suites marcado **separado** → dos deals, cada uno con su PVP completo, **sin**
  descuento. Con $77.315 y $80.863: $77.315 y $80.863, no $57.315.
- Deal con 2 unidades de proyectos distintos separado → cada deal con **su** proyecto en
  `Proyectos 1`.
- Deal de una unidad en CLIENTES + "copiar con otra unidad" → dos deals, uno por unidad.
- Una fusión real (un local partido en dos fichas, como `C-1-23.24`) marcada fusión → NO se parte.

## 🔴 Defecto que este trabajo corrige, y que hoy está costando plata
El cotizador da el descuento de parqueo **estén juntas o separadas**: solo mira "¿son 2+
suites?". En el modo "un plan por unidad" le pone los $20.000 enteros a la primera. O sea que
**toda cotización separada de dos suites sale $20.000 por debajo**. Lo mismo en
`autollenar_ficha`, que resta cuando hay más de una unidad sin preguntar si es fusión.

## Riesgos
- Crea deals reales en Bitrix. Un error duplica ventas.
- El interruptor tiene que vivir en el cuadro de campos obligatorios del cambio de etapa. Hay
  que confirmar que en **ese** contexto el iframe recibe el ID del deal y puede guardar; si no
  llega, la marca va en la ficha del deal.
- El "Copiar" nativo sigue existiendo, así que el arreglo de `propagar_quitada`
  (rama `arreglo-propagacion`) hace falta igual, como red.

## Orden propuesto
1. El descuento de parqueo atado a fusión/separado. **No depende de lo demás y es lo que hoy
   cuesta plata.**
2. Campo "Unidades juntas / separadas" en el SPA (hay que crearlo por UI).
3. El interruptor en `field.php`, visible en la ventana del cambio de etapa.
4. `duplicarlib.php`: duplicar un deal fijando los 6 campos por unidad.
5. Partición automática al entrar a RESERVA con "separadas".
6. Opción "copiar este deal con otra unidad" en el desplegable.
