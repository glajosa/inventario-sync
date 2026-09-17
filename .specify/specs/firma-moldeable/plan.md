# Plan

## La identidad que no se puede romper
    precio = separacion + firma + Σcuotas + Σextraordinarias + contraentrega
Todo lo de abajo se disena para que esa suma siga cerrando por CONSTRUCCION, no por
una formula paralela que haya que mantener al dia.

## F1 · La firma va antes de las cuotas

Opcion nueva `firmaAntes`. Con ella los pagos de firma dejan de sumarse a la cuota y
pasan a ser FILAS PROPIAS, antes de la cuota 1.

Los meses ya los sabe elegir `firmaPlan` (mes -> monto). Si el asesor solo dice "en 3
meses" (`firmaMeses`), los meses son los 3 que arrancan en el mes de la FIRMA.

🔴 ORDEN DE OPERACIONES. `$primera` se corre al mes siguiente al ULTIMO pago de firma,
y eso hay que hacerlo ANTES de calcular `$plazoMax` -- que se deriva de `$primera`.
Si se hace despues, el plazo queda calculado sobre una fecha que ya cambio y el motor
promete mas cuotas de las que caben antes de la entrega.

El acortamiento del plazo NO se programa: sale solo. La entrega no se mueve, asi que
correr la primera cuota deja menos meses. Medido: 55 -> 52 cuotas.

## F2 · Donde se absorbe el acortamiento

`absorbe` = `cuota` (defecto, lo de hoy) | `extra`.

Con `extra`, la regla es una sola frase: **las cuotas que ya no caben se van a la
extraordinaria que el asesor elija.**

    diferencia = mensualBase x (nSinCorrer - nCorrido)

`mensualBase` y `nSinCorrer` salen de correr el motor UNA vez sin el corrimiento. Es
calculo puro, sin IO, y evita inventar una formula inversa que se desincronice del
reparto real.

Se pasa como `extraTotal` (la opcion YA existe) + `extraAbsorbe` = cual de las
extraordinarias se la lleva. Asi el reparto, el cuadre al centavo y el tope contra lo
disponible siguen siendo los mismos de siempre.

## Archivos
· `cotizarlib.php` — `firmaAntes` (filas propias + corrimiento de `$primera` antes de
  `$plazoMax`), `extraAbsorbe` (a que extraordinaria va la diferencia).
· `cotizar.php` — las dos casillas, el selector de extraordinaria, la pasada previa
  para `mensualBase`, y el aviso de que las extraordinarias pasan del 10%.

## Orden de trabajo
1. `firmaAntes` en el motor. Probar que el total cierra y que sin la bandera nada cambia.
2. Corrimiento de `$primera` ANTES de `$plazoMax`. Probar que el plazo se acorta solo.
3. `absorbe=extra` + `extraAbsorbe`. Probar que el mensual se queda igual y la
   extraordinaria elegida sube EXACTAMENTE la diferencia.
4. Pantalla. Probar la PAGINA, no solo el motor.
5. Banco de pruebas: 300+ combinaciones al centavo, ninguna fila negativa.
