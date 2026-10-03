<?php
declare(strict_types=1);

namespace Sokna\Local\Core;

use RuntimeException;
use Throwable;

final class Observability
{
    private ?string $requestCorrelationId = null;

    public function __construct(private readonly string $dataRoot)
    {
        if (trim($this->dataRoot) === '') {
            throw new RuntimeException('Local data root must be configured explicitly.');
        }
    }

    public static function fromConfig(Config $config): self
    {
        $environment = trim((string)(getenv('SOKNA_DATA_DIR') ?: ''));
        $root = $environment !== '' ? $environment : $config->requiredString('app.data_dir');
        $instance = new self(rtrim($root, "\\/"));
        $instance->bootHttp();
        return $instance;
    }

    public function dataRoot(): string
    {
        return $this->dataRoot;
    }

    public function runtimeDir(): string
    {
        return $this->dataRoot . DIRECTORY_SEPARATOR . 'runtime';
    }

    public function logDir(): string
    {
        return $this->dataRoot . DIRECTORY_SEPARATOR . 'logs';
    }

    public function correlationId(?string $candidate = null): string
    {
        if ($this->requestCorrelationId !== null) return $this->requestCorrelationId;

        if ($candidate === null && PHP_SAPI !== 'cli') {
            $candidate = (string)($_SERVER['HTTP_X_SOKNA_CORRELATION_ID'] ?? '');
        }

        $candidate = trim((string)$candidate);
        if ($candidate !== '' && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,95}$/D', $candidate) === 1) {
            return $this->requestCorrelationId = $candidate;
        }
        return $this->requestCorrelationId = bin2hex(random_bytes(16));
    }

    public function bootHttp(): void
    {
        if (PHP_SAPI === 'cli' || headers_sent()) return;
        header('X-Sokna-Correlation-ID: ' . $this->correlationId());
    }

    public function redact(mixed $value, string $key = '', int $depth = 0): mixed
    {
        if ($depth > 8) return '[DEPTH_LIMIT]';
        if ($key !== '' && $this->isSensitiveKey($key)) return '[REDACTED]';
        if (is_array($value)) {
            $safe = [];
            foreach ($value as $childKey => $childValue) {
                $safe[$childKey] = $this->redact($childValue, (string)$childKey, $depth + 1);
            }
            return $safe;
        }
        if (is_object($value)) return '[OBJECT ' . get_class($value) . ']';
        if (is_resource($value)) return '[RESOURCE]';
        if (is_string($value) && strlen($value) > 4000) return substr($value, 0, 4000) . '…';
        return $value;
    }

    public function logEvent(string $level, string $event, array $context = [], ?string $correlationId = null): void
    {
        $level = strtolower(trim($level));
        if (!in_array($level, ['debug', 'info', 'warning', 'error', 'critical'], true)) $level = 'info';
        $event = preg_replace('/[^A-Za-z0-9_.:-]+/', '_', trim($event)) ?: 'event';
        $resolvedCorrelationId = $this->correlationId($correlationId);
        $entry = [
            'ts' => gmdate('Y-m-d\\TH:i:s\\Z'),
            'level' => $level,
            'event' => $event,
            'correlation_id' => $resolvedCorrelationId,
            'pid' => getmypid(),
            'context' => $this->redact($context),
        ];

        try {
            $json = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
            $dir = $this->logDir();
            if ($this->ensurePrivateDir($dir)
                && @file_put_contents($dir . DIRECTORY_SEPARATOR . 'sokna-' . gmdate('Y-m-d') . '.jsonl', $json, FILE_APPEND | LOCK_EX) !== false) {
                return;
            }
        } catch (Throwable) {
            // Fall through without exposing the original context.
        }
        error_log('[SOKNA][' . $level . '][' . $event . '] correlation_id=' . $resolvedCorrelationId);
    }

    public function atomicJsonWrite(string $path, array $payload): void
    {
        $dir = dirname($path);
        if (!$this->ensurePrivateDir($dir)) throw new RuntimeException('Runtime storage is not writable.');
        $tmp = $path . '.tmp-' . bin2hex(random_bytes(4));
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        if (@file_put_contents($tmp, $json, LOCK_EX) === false || !@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Runtime state could not be written atomically.');
        }
    }

    public function readJsonFile(string $path): array
    {
        if (!is_file($path)) return [];
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') return [];
        try {
            $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : [];
        } catch (Throwable) {
            return [];
        }
    }

    private function ensurePrivateDir(string $path): bool
    {
        if (is_dir($path)) return is_writable($path);
        return @mkdir($path, 0700, true) || is_dir($path);
    }

    private function isSensitiveKey(string $key): bool
    {
        $key = strtolower(trim($key));
        $exact = [
            'password', 'pass', 'passwd', 'authorization', 'cookie', 'set-cookie', 'app_key', 'private_key',
            'secret', 'client_secret', 'token', 'access_token', 'refresh_token', 'bearer', 'token_hash', 'secret_key',
            'db_pass', 'database_password', 'recovery_passphrase', 'passphrase',
        ];
        if (in_array($key, $exact, true)) return true;
        return preg_match('/(?:^|_)(?:password|passwd|secret|private_key|access_token|refresh_token|passphrase)$/', $key) === 1;
    }
}
