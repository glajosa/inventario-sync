<?php
/**
 * dbg.php — TEMPORAL. Recibe desde el iframe del campo la lista de comandos que
 * expone el placement (BX24.placement.getInterface) y la deja en el log. Sirve
 * para saber, con hechos y no por documentación, cómo reportarle el valor al
 * formulario de Bitrix. SE BORRA al terminar.
 */
declare(strict_types=1);
$DATA_DIR = getenv('DATA_DIR') ?: '/data';
@file_put_contents($DATA_DIR . '/web.log',
    gmdate('Y-m-d\TH:i:s\Z') . '  DBGPLACEMENT ' . substr((string)($_REQUEST['i'] ?? '-'), 0, 2000) . "\n",
    FILE_APPEND | LOCK_EX);
header('Content-Type: text/plain'); echo 'ok';
