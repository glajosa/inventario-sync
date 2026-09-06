<?php
/**
 * credito_app.php — solo sirve la versión desplegada del botón de crédito.
 * No hay flujo de instalación acá: la app local es la de cobranzas (ver
 * placement-credito.php para el porqué).
 */
declare(strict_types=1);
require_once __DIR__ . '/lib/credito-protocolo.php';
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
echo CREDITO_VER, "\n";
