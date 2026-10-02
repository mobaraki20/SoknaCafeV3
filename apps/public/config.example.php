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
        // One-time bootstrap code used only before the first active Local installation is paired.
        // Use a random value of at least 16 characters, transmit it only over HTTPS, then rotate/remove it after pairing.
        'initial_pairing_code' => 'CHANGE_ME_ONE_TIME_PAIRING_CODE',
        'clock_skew_seconds' => 300,
        // 32 random bytes, base64-encoded. Used only to encrypt DB-stored re-enrollment pairing secrets.
        'secret_encryption_key_base64' => 'CHANGE_ME_BASE64_32_BYTES',
    ],
    'auth' => [
        'session_ttl_seconds' => 28800,
        'failure_limit' => 5,
        'failure_window_seconds' => 900,
        'block_seconds' => 900,
    ],
];
