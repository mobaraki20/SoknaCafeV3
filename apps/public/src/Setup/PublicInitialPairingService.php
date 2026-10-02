<?php
declare(strict_types=1);

namespace Sokna\PublicEdge\Setup;

use PDO;
use RuntimeException;
use Sokna\PublicEdge\Emergency\PairingSecretStore;
use Throwable;

final class PublicInitialPairingService
{
    private const FORMAT = 'sokna-public-initial-pairing-v1';
    private const TTL_SECONDS = 1800;
    private const MAX_ATTEMPTS = 8;

    public function __construct(
        private readonly PDO $pdo,
        private readonly PairingSecretStore $secrets,
        private readonly string $storageRoot,
        private readonly string $configPath,
    ) {}

    public function issue(): array
    {
        if ($this->hasInstallation()) throw new RuntimeException('already_paired');
        $code = 'pub1_' . bin2hex(random_bytes(16));
        $this->writeState([
            'format' => self::FORMAT,
            'code_hash' => password_hash($code, PASSWORD_DEFAULT),
            'expires_at' => time() + self::TTL_SECONDS,
            'attempts' => 0,
            'consumed_at' => null,
            'created_at' => gmdate('c'),
        ]);
        return ['pairing_code' => $code, 'expires_in_seconds' => self::TTL_SECONDS];
    }

    public function complete(string $installationId, string $code, string $secret, string $displayName = ''): array
    {
        $installationId = trim($installationId);
        $code = trim($code);
        $displayName = trim($displayName);
        if (preg_match('/^[A-Za-z0-9._:-]{1,96}$/D', $installationId) !== 1) throw new RuntimeException('invalid_installation');
        if (strlen($secret) < 32) throw new RuntimeException('shared_secret_weak');

        $lock = $this->openLock();
        try {
            if (!flock($lock, LOCK_EX)) throw new RuntimeException('pairing_lock_failed');
            if ($this->hasInstallation()) throw new RuntimeException('already_paired');
            $state = $this->state();
            if (($state['format'] ?? '') !== self::FORMAT || !is_string($state['code_hash'] ?? null)) throw new RuntimeException('pairing_not_issued');
            if (!empty($state['consumed_at'])) throw new RuntimeException('pairing_consumed');
            if ((int)($state['expires_at'] ?? 0) < time()) throw new RuntimeException('pairing_expired');
            $attempts = (int)($state['attempts'] ?? 0);
            if ($attempts >= self::MAX_ATTEMPTS) throw new RuntimeException('pairing_locked');
            if (!password_verify($code, (string)$state['code_hash'])) {
                $state['attempts'] = $attempts + 1;
                $this->writeState($state);
                throw new RuntimeException('pairing_denied');
            }

            $oldConfig = @file_get_contents($this->configPath);
            if (!is_string($oldConfig) || $oldConfig === '') throw new RuntimeException('config_unavailable');
            $this->pdo->beginTransaction();
            try {
                $q = $this->pdo->prepare(
                    'INSERT INTO installations(installation_id,display_name,active,remote_enabled,order_intake_enabled,revoked_at) VALUES(?,?,1,1,1,NULL)'
                );
                $q->execute([$installationId, $displayName !== '' ? $displayName : null]);
                $this->secrets->put($installationId, $secret);
                $this->persistDefaultInstallation($installationId);
                $this->pdo->commit();
            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) $this->pdo->rollBack();
                @file_put_contents($this->configPath, $oldConfig, LOCK_EX);
                throw $e;
            }

            $state['consumed_at'] = gmdate('c');
            $state['attempts'] = $attempts;
            $this->writeState($state);
            return ['ok' => true, 'installation_id' => $installationId];
        } finally {
            @flock($lock, LOCK_UN);
            @fclose($lock);
        }
    }

    public function pending(): bool
    {
        if ($this->hasInstallation()) return false;
        $state = $this->state();
        return ($state['format'] ?? '') === self::FORMAT
            && empty($state['consumed_at'])
            && (int)($state['expires_at'] ?? 0) >= time()
            && (int)($state['attempts'] ?? 0) < self::MAX_ATTEMPTS;
    }

    private function hasInstallation(): bool
    {
        try {
            return (int)$this->pdo->query('SELECT COUNT(*) FROM installations WHERE active=1 AND revoked_at IS NULL')->fetchColumn() > 0;
        } catch (Throwable) {
            return false;
        }
    }

    private function persistDefaultInstallation(string $installationId): void
    {
        $raw = require $this->configPath;
        if (!is_array($raw)) throw new RuntimeException('config_invalid');
        $raw['app'] = is_array($raw['app'] ?? null) ? $raw['app'] : [];
        $raw['app']['default_installation_id'] = $installationId;
        $tmp = $this->configPath . '.tmp-' . bin2hex(random_bytes(4));
        $php = "<?php\ndeclare(strict_types=1);\nreturn " . var_export($raw, true) . ";\n";
        if (@file_put_contents($tmp, $php, LOCK_EX) === false || !@rename($tmp, $this->configPath)) {
            @unlink($tmp);
            throw new RuntimeException('config_write_failed');
        }
        @chmod($this->configPath, 0600);
    }

    private function state(): array
    {
        $path = $this->statePath();
        if (!is_file($path)) return [];
        try {
            $value = json_decode((string)file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
            return is_array($value) ? $value : [];
        } catch (Throwable) {
            return [];
        }
    }

    private function writeState(array $state): void
    {
        $dir = $this->stateDir();
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('pairing_storage_unavailable');
        $tmp = $this->statePath() . '.tmp-' . bin2hex(random_bytes(3));
        $json = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
        if (@file_put_contents($tmp, $json, LOCK_EX) === false || !@rename($tmp, $this->statePath())) {
            @unlink($tmp);
            throw new RuntimeException('pairing_state_write_failed');
        }
        @chmod($this->statePath(), 0600);
    }

    private function openLock()
    {
        $dir = $this->stateDir();
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('pairing_storage_unavailable');
        $fh = @fopen($dir . DIRECTORY_SEPARATOR . 'initial-pairing.lock', 'c+');
        if ($fh === false) throw new RuntimeException('pairing_lock_unavailable');
        return $fh;
    }

    private function stateDir(): string
    {
        return rtrim($this->storageRoot, "\\/") . DIRECTORY_SEPARATOR . 'setup';
    }

    private function statePath(): string
    {
        return $this->stateDir() . DIRECTORY_SEPARATOR . 'initial-pairing.json';
    }
}
