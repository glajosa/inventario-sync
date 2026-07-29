<?php
/**
 * warm-catalogo.php — refresca el catálogo de unidades del campo.
 * ---------------------------------------------------------------------------
 * Por qué existe: el campo dibuja su lista desde selector_cache.json, y NADIE lo
 * reconstruía por su cuenta. rebuild.php solo mantiene la lista blanca de deals,
 * y selector.php solo refresca cuando alguien abre esa página a mano — que ya no
 * usa nadie desde que existe el campo. Resultado real medido: el caché llegó a
 * tener 5.4 horas de edad con un TTL de 15 minutos.
 *
 * Consecuencia: una unidad NUEVA en el SPA no aparecía en el campo, y una unidad
 * movida a mano en el Kanban mostraba el estado viejo. (La disponibilidad de
 * verdad la valida el portero contra Bitrix en vivo, así que nunca fue un riesgo
 * de doble venta, solo de lo que se ve.)
 *
 * Se ejecuta por cron. Reutiliza el rebuild de selector.php en vez de duplicarlo:
 * ese ya trae el candado contra reconstrucciones simultáneas y la comprobación de
 * completitud (no guardar un catálogo parcial).
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);
set_time_limit(0);

// selector.php se protege por token vía GET; en CLI se le pasa el del entorno.
$_GET['warm']  = '1';
$_GET['token'] = (string)getenv('OUTBOUND_TOKEN');
$_REQUEST      = $_GET;

require __DIR__ . '/selector.php';
