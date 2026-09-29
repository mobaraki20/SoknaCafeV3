<?php
declare(strict_types=1);

use Sokna\PublicEdge\Core\Bootstrap;

$root = dirname(__DIR__);
$configPath = $root . DIRECTORY_SEPARATOR . 'config.php';
foreach (array_slice($argv ?? [], 1) as $arg) {
    if (str_starts_with($arg, '--config=')) {
        $candidate = trim(substr($arg, 9));
        if ($candidate !== '') $configPath = $candidate;
    }
}

function fail_deploy(string $message, int $code = 1): never
{
    fwrite(STDERR, json_encode([
        'ok' => false,
        'component' => 'public-edge',
        'error' => $message,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit($code);
}

try {
    if (!is_file($configPath) || !is_readable($configPath)) {
        fail_deploy("Public config is missing or unreadable: {$configPath}", 2);
    }

    $config = require $configPath;
    if (!is_array($config)) fail_deploy('Public config must return an array.', 3);

    require_once $root . DIRECTORY_SEPARATOR . 'bootstrap.php';
    $core = sokna_public_bootstrap($config);

    $storage = trim((string)($config['app']['storage_dir'] ?? ''));
    if ($storage === '') $storage = $root . DIRECTORY_SEPARATOR . 'storage';
    if (!is_dir($storage) && !@mkdir($storage, 0770, true) && !is_dir($storage)) {
        fail_deploy("Could not create Public storage directory: {$storage}", 4);
    }
    if (!is_writable($storage)) fail_deploy("Public storage directory is not writable: {$storage}", 5);

    $pdo = $core->database();
    if ((int)$pdo->query('SELECT 1')->fetchColumn() !== 1) {
        fail_deploy('MariaDB connectivity probe failed.', 6);
    }

    $applied = $core->migrations()->migrate();
    $health = $core->health()->status('deploy-bootstrap');
    if ((int)($health['status'] ?? 500) !== 200 || (($health['body']['ok'] ?? false) !== true)) {
        fail_deploy('Public health check failed after migrations.', 7);
    }

    $version = trim((string)@file_get_contents($root . DIRECTORY_SEPARATOR . 'VERSION.txt'));
    $result = [
        'ok' => true,
        'component' => 'public-edge',
        'version' => $version !== '' ? $version : 'unknown',
        'config' => realpath($configPath) ?: $configPath,
        'storage' => realpath($storage) ?: $storage,
        'new_migrations' => array_values($applied),
        'applied_migrations' => (int)($health['body']['applied_migrations'] ?? 0),
        'database' => (string)($health['body']['database'] ?? 'unknown'),
    ];
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
} catch (Throwable $e) {
    fail_deploy($e->getMessage(), 10);
}
