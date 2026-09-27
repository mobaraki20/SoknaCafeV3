<?php
declare(strict_types=1);

return [
    'db' => [
        'host' => '127.0.0.1',
        'port' => '3306',
        'name' => 'sokna_public',
        'charset' => 'utf8mb4',
        'user' => 'sokna_public',
        'pass' => 'CHANGE_ME',
    ],
    'app' => [
        'default_installation_id' => 'CHANGE_ME_INSTALLATION_ID',
        'cookie_secure' => true,
        // Keep storage outside the public document root whenever hosting permits.
        'storage_dir' => __DIR__ . '/storage',
    ],
    'relay' => [
        // installation_id => shared HMAC secret provisioned by the Local pairing flow.
        'installation_secrets' => [],
        'clock_skew_seconds' => 300,
    ],
    'auth' => [
        'session_ttl_seconds' => 28800,
        'failure_limit' => 5,
        'failure_window_seconds' => 900,
        'block_seconds' => 900,
    ],
];
