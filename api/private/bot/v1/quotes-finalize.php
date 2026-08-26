<?php
declare(strict_types=1);
$root = dirname(__DIR__, 4);
require_once $root . '/appauth.php';
require_once $root . '/lib/bot-quote-http.php';
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $env = $_ENV + (is_array(getenv()) ? getenv() : []);
    $result = bot_quote_finalize_http((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'), (string)file_get_contents('php://input'), is_array($headers)?$headers:[], $env, 'app_bx', time());
    http_response_code($result['status']); header('Content-Type: application/json; charset=utf-8');
    foreach ($result['headers'] as $name=>$value) header($name.': '.$value);
    echo json_encode($result['body'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
}
