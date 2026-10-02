<?php
declare(strict_types=1);

namespace Sokna\PublicEdge\Setup;

use PDO;
use RuntimeException;
use Sokna\PublicEdge\Core\Bootstrap;
use Throwable;

final class PublicSetupService
{
    public function __construct(
        private readonly string $componentRoot,
        private readonly string $configPath,
    ) {}

    public function installed(): bool
    {
        return is_file($this->configPath) && is_readable($this->configPath);
    }

    public function install(array $input): array
    {
        if ($this->installed()) throw new RuntimeException('setup_locked');
        if (!extension_loaded('pdo_mysql') || !extension_loaded('sodium')) throw new RuntimeException('environment_incomplete');

        $db = $this->db($input);
        $storage = trim((string)($input['storage_dir'] ?? ''));
        if ($storage === '') $storage = $this->componentRoot . DIRECTORY_SEPARATOR . 'storage';
        if (!$this->absolute($storage)) throw new RuntimeException('storage_path_invalid');
        $this->ensureDir($storage);

        $server = $this->server($db);
        $version = (string)$server->query('SELECT VERSION()')->fetchColumn();
        if (stripos($version, 'MariaDB') === false) throw new RuntimeException('database_not_mariadb');
        if (!empty($input['create_database'])) {
            $server->exec('CREATE DATABASE IF NOT EXISTS ' . $db['name'] . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        }
        $pdo = $this->database($db);
        $pdo->query('SELECT 1')->fetchColumn();

        $config = [
            'db' => [
                'host' => $db['host'],
                'port' => $db['port'],
                'name' => $db['name'],
                'charset' => 'utf8mb4',
                'user' => $db['user'],
                'pass' => $db['pass'],
            ],
            'app' => [
                'default_installation_id' => '',
                'cookie_secure' => (bool)($input['cookie_secure'] ?? true),
                'storage_dir' => $storage,
            ],
            'relay' => [
                'installation_secrets' => [],
                'clock_skew_seconds' => 300,
                'secret_encryption_key_base64' => base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
            ],
            'auth' => [
                'session_ttl_seconds' => 28800,
                'failure_limit' => 5,
                'failure_window_seconds' => 900,
                'block_seconds' => 900,
            ],
        ];

        $this->writeConfig($config);
        try {
            $core = Bootstrap::fromArray($config);
            $core->migrations()->migrate();
            $pairing = new PublicInitialPairingService(
                $core->database(),
                $core->pairingSecrets(),
                $storage,
                $this->configPath,
            );
            $issued = $pairing->issue();
            $health = (array)($core->health()->status()['body'] ?? []);
            if (($health['ok'] ?? false) !== true) throw new RuntimeException('final_health_failed');
            return $issued + ['health' => $health];
        } catch (Throwable $e) {
            @unlink($this->configPath);
            throw $e;
        }
    }

    public function preflight(): array
    {
        return [
            'php' => PHP_VERSION_ID >= 80200,
            'pdo_mysql' => extension_loaded('pdo_mysql'),
            'sodium' => extension_loaded('sodium'),
            'zip' => class_exists(\ZipArchive::class),
            'config_writable' => is_writable($this->componentRoot),
        ];
    }

    private function db(array $input): array
    {
        $db = is_array($input['db'] ?? null) ? $input['db'] : $input;
        $host = trim((string)($db['host'] ?? '127.0.0.1'));
        $port = trim((string)($db['port'] ?? '3306'));
        $name = trim((string)($db['name'] ?? 'sokna_public'));
        $user = trim((string)($db['user'] ?? ''));
        $pass = (string)($db['pass'] ?? '');
        if (
            $host === '' ||
            !ctype_digit($port) ||
            (int)$port < 1 ||
            (int)$port > 65535 ||
            preg_match('/^[A-Za-z0-9_]{1,64}$/D', $name) !== 1 ||
            $user === ''
        ) throw new RuntimeException('database_input_invalid');
        return compact('host', 'port', 'name', 'user', 'pass');
    }

    private function server(array $db): PDO
    {
        return new PDO(
            'mysql:host=' . $db['host'] . ';port=' . $db['port'] . ';charset=utf8mb4',
            $db['user'],
            $db['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false],
        );
    }

    private function database(array $db): PDO
    {
        return new PDO(
            'mysql:host=' . $db['host'] . ';port=' . $db['port'] . ';dbname=' . $db['name'] . ';charset=utf8mb4',
            $db['user'],
            $db['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false],
        );
    }

    private function writeConfig(array $config): void
    {
        $tmp = $this->configPath . '.tmp-' . bin2hex(random_bytes(4));
        $php = "<?php\ndeclare(strict_types=1);\nreturn " . var_export($config, true) . ";\n";
        if (@file_put_contents($tmp, $php, LOCK_EX) === false || !@rename($tmp, $this->configPath)) {
            @unlink($tmp);
            throw new RuntimeException('config_write_failed');
        }
        @chmod($this->configPath, 0600);
    }

    private function ensureDir(string $path): void
    {
        if (!is_dir($path) && !@mkdir($path, 0700, true) && !is_dir($path)) throw new RuntimeException('storage_unavailable');
    }

    private function absolute(string $path): bool
    {
        return preg_match('#^(?:[A-Za-z]:[\\\\/]|/)#', $path) === 1;
    }
}
