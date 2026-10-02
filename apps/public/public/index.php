<?php
declare(strict_types=1);

use Sokna\PublicEdge\Core\SafeErrors;
use Sokna\PublicEdge\Http\PublicHttpKernel;
if (function_exists('header_remove')) header_remove('X-Powered-By');

$componentRoot = dirname(__DIR__);
require_once $componentRoot . '/bootstrap.php';

$configPath = trim((string)(getenv('SOKNA_PUBLIC_CONFIG') ?: ($componentRoot . '/config.php')));

try {
    if (!is_file($configPath) || !is_readable($configPath)) {
        $path=(string)(parse_url((string)($_SERVER['REQUEST_URI']??'/'),PHP_URL_PATH)?:'/');
        if(($_SERVER['REQUEST_METHOD']??'GET')==='GET'&&($path==='/'||$path==='')){
            http_response_code(302);header('Location: /setup.php');header('Cache-Control: no-store');exit;
        }
        $result = SafeErrors::response(503, 'public_not_configured');
    } else {
        $config = require $configPath;
        if (!is_array($config)) throw new RuntimeException('Public config must return an array.');
        $core = sokna_public_bootstrap($config);
        $kernel = new PublicHttpKernel($core, $componentRoot);
        $headers = function_exists('getallheaders') ? (array)getallheaders() : [];
        $result = $kernel->handle(
            (string)($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            (string)($_SERVER['REQUEST_URI'] ?? '/'),
            $_GET,
            (string)file_get_contents('php://input'),
            $headers,
        );
    }
} catch (\Throwable $error) {
    $result = SafeErrors::fromThrowable($error);
}

http_response_code((int)($result['status'] ?? 500));
foreach ((array)($result['headers'] ?? []) as $name => $value) {
    if (!headers_sent()) header((string)$name . ': ' . (string)$value, true);
}
if (isset($result['file_path']) && is_string($result['file_path'])) {
    readfile($result['file_path']);
} else {
    if (!isset($result['headers']['Content-Type']) && !headers_sent()) header('Content-Type: application/json; charset=utf-8');
    echo (string)($result['body'] ?? '');
}
