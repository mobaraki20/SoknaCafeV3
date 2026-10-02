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
        $method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
        $requestPath=(string)(parse_url((string)($_SERVER['REQUEST_URI']??'/'),PHP_URL_PATH)?:'/');
        $isPageRequest=in_array($method,['GET','HEAD'],true)
            && !str_starts_with($requestPath,'/api/')
            && $requestPath!=='/health';
        if($isPageRequest){
            header('Cache-Control: no-store, max-age=0');
            header('Location: /setup/',true,302);
            exit;
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
