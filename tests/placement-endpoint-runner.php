<?php
/**
 * Corre UN endpoint (field.php, guardar.php, copiar.php, cotizar.php) como si
 * llegara una petición web, en un proceso aparte (los endpoints hacen exit).
 * Uso: php placement-endpoint-runner.php <script> <METODO> <get-json> <post-json> <env-json>
 * Imprime una línea JSON: {"code":N,"body":"..."}.
 * Solo para pruebas: tests/ está denegado en Apache (apache-tests-deny.conf).
 */
declare(strict_types=1);

[$_, $script, $metodo, $getJ, $postJ, $envJ] = $argv + [null, '', 'GET', '{}', '{}', '{}'];
foreach ((array)json_decode((string)$envJ, true) as $k => $v) putenv($k . '=' . $v);
$_GET  = (array)json_decode((string)$getJ, true);
$_POST = (array)json_decode((string)$postJ, true);
$_REQUEST = $_GET + $_POST;
$_SERVER['REQUEST_METHOD'] = (string)$metodo;
$_SERVER['HTTP_HOST'] = 'inventario.test';

ini_set('display_errors', 'stderr');   // los avisos de PHP no se mezclan con el cuerpo
ob_start();
register_shutdown_function(function (): void {
    $body = (string)ob_get_clean();
    $code = http_response_code();
    fwrite(STDOUT, "\n@@RES@@" . json_encode(['code' => $code === false ? 200 : $code, 'body' => $body]) . "\n");
});
chdir(dirname(__DIR__));
require dirname(__DIR__) . '/' . basename((string)$script);
