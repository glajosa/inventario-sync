# Plan de implementación del catálogo comercial del bot

1. Extraer de `lista.php` el armado de filas por tipología a funciones puras reutilizables, sin cambiar el HTML actual.
2. Crear pruebas con inventario controlado para clasificación de dormitorios, niveles, posiciones, parqueos, precios y uniones.
3. Construir el documento de catálogo comercial a partir del caché y de las matrices.
4. Exponerlo en un endpoint privado, firmado y de solo lectura, con metadatos de frescura.
5. Verificar que la lista visual y el catálogo del bot producen los mismos precios y opciones.
6. Desplegar únicamente el endpoint en un servicio de laboratorio y contrastarlo con el inventario real.
7. En el repositorio del bot, añadir refresco cada 30 minutos, persistencia de la última foto válida e inyección compacta al prompt del laboratorio.
8. Añadir pruebas de conversación para tres dormitorios, precios "desde", financiamiento y lenguaje natural.
9. Ejecutar la batería completa y una prueba manual de aceptación antes de proponer el pase a producción.
