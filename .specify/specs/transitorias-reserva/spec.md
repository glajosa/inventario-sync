# Spec — Transitorias: la reserva nace pagada y el resto va a "A la firma"

Pedido: Jesua, 5-oct-2026. Revisado por VIGILANTE. Código en cobranza2 (`lib_transitorias.php`).

## Qué
1. Al crear las 10 cuotas transitorias, la **cuota 1 nace con EJECUTADO = monto de
   la reserva**, "para tener contabilizado al cliente". El nombre ("1 cuota
   transitoria dd/mm/aaaa -($1,000.00)") y el ANTICIPO CLIENTE se actualizan por el
   camino de siempre (trans_evento_item).
   - **De dónde sale el monto:** en los 44 NO hay ningún campo con la reserva
     (medido en 12 deals el 5-oct). Primero la fila de Reserva/Separación de la
     tabla del 44, si existe. Si no, `cfg('trans_reserva_monto', 1000)` (la regla de
     Jesua). Si no se puede determinar, no se escribe y se avisa a falla.php.
   - Qué cuota recibió el automático queda en `.bxstate/trans_auto_reserva_<item>`
     con el monto.
2. **Traspaso al 48:** el automático NO se suma (ya viene en la RESERVA de la tabla).
   Se reconoce por ESE registro, nunca por el valor (un pago real de 1000 no se
   descuenta). **Todo lo demás pagado en transitorias va al EJECUTADO de "A la
   firma" del 48**, ya no a RESERVA. Si el 48 no tiene "A la firma", no se escribe
   y se avisa.
3. **Etapas en las que se crean:** RESERVA (`C44:NEW`), ELABORACIÓN PROMESA
   (`C44:UC_Z3GY5H`) y LISTO PARA FIRMA (`C44:UC_4R587H`).
4. **Relleno de las que faltan** (medido el 5-oct): 34 deals sin transitorias. Se
   EXCLUYEN los 5 que ya tienen un 48 de la misma unidad (5788, 5792, 241608, 9348,
   244550) y los "(Copy)" sin proyecto. Quedan unos 27.
   - Primero en SECO: lista por deal de qué se escribiría → Jesua da el OK.
   - Ventana nocturna 01-04, tope 30, 1 deal por vez, altas a ~1/s. Sin anunciar
     barrido (son menos de 50 deals).
   - Cada deal se relee fresco antes de escribir: si ya tiene transitorias, se salta.
   - **Sin mark_self en las altas**: el 1-oct un mark_self de creación se comió un
     pago de 5.000 (411037). El eco cuesta 1 lectura y sale temprano
     (trans_evento_item, el nombre ya es el correcto).
5. **Los 50 que ya tienen sus 10**: no se tocan sin que Jesua lo decida. Si se hace,
   solo donde la cuota 1 tiene ejecutado 0.

## Perillas
`trans_reserva_auto_on`, `trans_traspaso_a_firma_on`, `trans_etapas_extra_on`, `trans_reserva_monto`.

## Verificación
- Pruebas puras: monto de reserva (tabla, config, ninguno), el registro del
  automático, y el traspaso con un pago real de 1000 que NO se descuenta.
- La corrida en seco de los 27, aprobada por Jesua antes de la noche.
