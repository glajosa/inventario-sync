# Despliegue: No contestó compartido

## Objetivo

El botón **No contestó** de la web app móvil y la pestaña **No contestó** de Bitrix ejecutan una sola operación. La primera solicitud válida completa la actividad pendiente y crea, como máximo, una próxima actividad. Las repeticiones posteriores se reconocen sin duplicar efectos.

## Compatibilidad

- No cambia el contrato `inventario-sync-call-result-v1` de la web app.
- No cambia la base, los códigos ni las suscripciones push de los vendedores.
- Doménica y Nicolás conservan la aplicación instalada y su vínculo actual.
- Solo se despliega `inventario-sync`; `bitrix-sim-bridge` no se vuelve a desplegar.

## Secuencia del piloto

1. Desplegar `inventario-sync` entre llamadas.
2. Comprobar que `GET /` responde 200 y que `GET /api/llamadas/no-contesto.php` responde 400 sin hacer escrituras.
3. Abrir un deal de prueba de Martín y ejecutar una solicitud controlada.
4. Repetir desde el mismo canal y confirmar que existe una sola próxima actividad.
5. Repetir cruzando celular y Bitrix y confirmar `already_processed` sin duplicados.
6. Habilitar la prueba de Doménica y Nicolás.
7. Si falla, volver en EasyPanel al commit de producción anterior. Las vinculaciones del puente permanecen intactas.

## Resultados esperados

- **No contestó:** completa una única actividad pendiente y crea una única próxima actividad con las reglas vigentes.
- **Sí contestó:** completa la actividad pendiente, no crea próxima actividad y no cambia la etapa.
- **Pendiente ausente o ambigua:** pide revisión manual y no modifica una actividad al azar.
- **Dos resultados incompatibles:** conserva el primero y rechaza el segundo.

## Reversión

La reversión afecta únicamente al contenedor `inventario-sync`. No se deben borrar volúmenes ni desplegar de nuevo el puente móvil. Restaurar el commit anterior recupera el comportamiento de producción sin desvincular celulares.
