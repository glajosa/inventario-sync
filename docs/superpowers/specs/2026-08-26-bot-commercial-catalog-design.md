# Catálogo comercial actualizado para el vendedor virtual

## Objetivo

Dar al bot una fotografía comercial periódica del inventario disponible para que responda con naturalidad precios "desde", metrajes, dormitorios, plantas, parqueos y posiciones, sin consultar Bitrix en cada mensaje.

## Fuente de verdad

- El SPA de inventario de Bitrix (`entityTypeId=1072`) define disponibilidad, código, metraje y PVP.
- Las matrices `matrices/proyecto_*.json` definen tipologías, niveles, posiciones, parqueos, uniones válidas y financiamiento.
- La salida debe reutilizar la misma lógica con la que `lista.php` arma las listas visuales. No se mantendrá una segunda tabla manual de precios.

## Flujo

1. Inventario Sync conserva su caché completo y seguro de Bitrix.
2. Un generador puro transforma ese caché y las matrices en un catálogo comercial compacto.
3. Un endpoint privado y de solo lectura entrega el catálogo, su fecha de generación y su antigüedad.
4. El bot de laboratorio lo descarga al iniciar y cada 30 minutos.
5. Si una actualización falla, el bot conserva la última fotografía válida; nunca expone errores, marcadores ni instrucciones técnicas al prospecto.
6. El prompt recibe un resumen compacto del catálogo y responde consultas generales desde esa memoria.
7. Solo la selección de una unidad exacta, una cotización o una reserva requiere revalidación puntual en vivo.

## Contenido de cada oferta

- proyecto y familia comercial;
- dormitorios cuando aplique;
- planta o nivel;
- posición comercial (medianero, esquinero, esquinero interno, vista, etc.);
- metraje o rango de metrajes;
- parqueos;
- precio vigente "desde" y, cuando sea útil, rango;
- cantidad de unidades disponibles y códigos internos de respaldo;
- plan de pago calculado con las reglas vigentes;
- entrega y observaciones comerciales;
- indicador de si es una unidad individual o una unión válida.

## Reglas críticas

- Solo entran unidades disponibles, liberadas comercialmente, con PVP y metraje válidos.
- Los dormitorios no se infieren únicamente del nombre de la ficha: se derivan de la matriz, grupo, posición y reglas del proyecto.
- Las uniones solo existen si todas las unidades que la componen siguen disponibles y cumplen las reglas de contigüidad del proyecto.
- Noral Plaza es un único proyecto con familias comercial y residencial.
- Una respuesta general puede usar precios "desde" de la fotografía; un código exacto nunca se afirma disponible sin revalidación puntual.
- La conversación no debe mencionar API, caché, inventario vivo, validaciones internas ni marcadores como `[CONSULTAR INVENTARIO VIVO]`.

## Seguridad de despliegue

- El desarrollo y las pruebas se hacen en ramas y servicios de laboratorio separados.
- Producción no cambia hasta aprobar casos de conversación con datos reales.
- El endpoint es privado, firmado y de solo lectura.

## Criterios de aceptación

- Noral Apartments devuelve correctamente opciones de tres dormitorios en G/H y sus diferencias por nivel.
- Noral Plaza representa correctamente la alternativa residencial de tres monoambientes unidos, con metraje, dos parqueos y precio vigente.
- Galero Torre D distingue dos y tres dormitorios y sus niveles.
- Galero Casas reconoce sus casas como tres dormitorios.
- Las posiciones comerciales y precios coinciden con la lista visual del mismo inventario.
- Una caída temporal de Bitrix no elimina el último catálogo válido del bot.
