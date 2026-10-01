<?php
declare(strict_types=1);

namespace Sokna\Local\Runtime;

use Throwable;

/**
 * Loopback-only client for the Windows Runtime v1 health endpoint.
 * Secrets stay on the machine; probe results never include the bearer token.
 */
final class RuntimeHealthClient
{
    /** @var null|callable */
    private $transport;

    public function __construct(private readonly string $dataRoot, ?callable $transport = null)
    {
        $this->transport = $transport;
    }

    /** @return array{status:string,http_status:int,error_code:string,health:?array} */
    public function probe(): array
    {
        try {
            $config = $this->runtimeConfig();
            $token = $this->healthToken();
            if ($config === null || $token === '') {
                return $this->result('not_configured', 0, 'runtime_health_not_configured');
            }
            $port = (int)($config['healthPort'] ?? 0);
            if (($config['contractVersion'] ?? null) !== 1 || $port < 1024 || $port > 65535) {
                return $this->result('configuration_error', 0, 'runtime_health_config_invalid');
            }
            $url = 'http://127.0.0.1:' . $port . '/v1/health';
            $headers = [
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . $token,
                'X-Sokna-Runtime-Contract' => '1',
            ];
            if (is_callable($this->transport)) {
                $response = ($this->transport)($url, 'GET', $headers, '');
                return $this->normalizeResponse(is_array($response) ? $response : []);
            }

            $lines = [];
            foreach ($headers as $key => $value) $lines[] = $key . ': ' . $value;
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'header' => implode("\r\n", $lines),
                    'timeout' => 2,
                    'ignore_errors' => true,
                ],
            ]);
            $raw = @file_get_contents($url, false, $context);
            $status = $this->httpStatus($http_response_header ?? []);
            if ($raw === false && $status === 0) return $this->result('unreachable', 0, 'runtime_health_unreachable');
            return $this->normalizeResponse(['status' => $status, 'body' => (string)$raw]);
        } catch (Throwable) {
            return $this->result('configuration_error', 0, 'runtime_health_probe_failed');
        }
    }

    private function runtimeConfig(): ?array
    {
        $path = $this->root() . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'runtime-config.json';
        if (!is_file($path)) return null;
        $raw = @file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') return null;
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    private function healthToken(): string
    {
        $path = $this->root() . DIRECTORY_SEPARATOR . 'secrets' . DIRECTORY_SEPARATOR . 'runtime-health.token';
        if (!is_file($path)) return '';
        $token = trim((string)@file_get_contents($path));
        return strlen($token) >= 32 && strlen($token) <= 512 ? $token : '';
    }

    private function root(): string
    {
        return rtrim($this->dataRoot, "\\/");
    }

    /** @param array<string,mixed> $response */
    private function normalizeResponse(array $response): array
    {
        $status = (int)($response['status'] ?? 0);
        $body = $response['body'] ?? '';
        if ($status === 401) return $this->result('unauthorized', 401, 'runtime_health_unauthorized');
        if ($status === 426) return $this->result('contract_mismatch', 426, 'runtime_health_contract_mismatch');
        if ($status < 200 || $status >= 300) return $this->result('http_error', $status, 'runtime_health_http_' . ($status ?: 0));
        $data = is_array($body) ? $body : json_decode((string)$body, true);
        if (!is_array($data) || !$this->validHealth($data)) return $this->result('invalid_response', $status, 'runtime_health_invalid_response');
        return ['status' => 'ok', 'http_status' => $status, 'error_code' => '', 'health' => $data];
    }

    /** @param array<string,mixed> $data */
    private function validHealth(array $data): bool
    {
        if (($data['success'] ?? null) !== true || (int)($data['contract_version'] ?? 0) !== 1) return false;
        $version = trim((string)($data['runtime_version'] ?? ''));
        $instance = trim((string)($data['instance_id'] ?? ''));
        if ($version === '' || strlen($version) > 64 || strlen($instance) < 8 || strlen($instance) > 128) return false;
        if (!in_array((string)($data['status'] ?? ''), ['starting','running','degraded','maintenance_paused','failed'], true)) return false;
        if (!$this->timestamp((string)($data['started_at'] ?? '')) || !$this->timestamp((string)($data['last_seen_at'] ?? ''))) return false;
        if (!is_array($data['capabilities'] ?? null)) return false;
        foreach ($data['capabilities'] as $capability) if (!is_string($capability) || $capability === '' || strlen($capability) > 128) return false;

        $scheduler = $data['scheduler'] ?? null;
        if (!is_array($scheduler) || !is_bool($scheduler['healthy'] ?? null) || !array_key_exists('last_cycle_at', $scheduler)) return false;
        $lastCycle = $scheduler['last_cycle_at'];
        if ($lastCycle !== null && (!$this->timestamp((string)$lastCycle))) return false;
        $lastError = $scheduler['last_error_code'] ?? null;
        if ($lastError !== null && !$this->safeCode((string)$lastError)) return false;

        $components = $data['supervised_components'] ?? null;
        if (!is_array($components)) return false;
        foreach ($components as $component) {
            if (!is_array($component)) return false;
            if (!in_array((string)($component['status'] ?? ''), ['unknown','starting','running','degraded','stopped','failed','disabled'], true)) return false;
            $seen = $component['last_seen_at'] ?? null;
            if ($seen !== null && !$this->timestamp((string)$seen)) return false;
            $error = $component['error_code'] ?? null;
            if ($error !== null && !$this->safeCode((string)$error)) return false;
        }
        return true;
    }

    private function timestamp(string $value): bool
    {
        if ($value === '' || !preg_match('/(?:Z|[+-]\d{2}:\d{2})$/', $value)) return false;
        return strtotime($value) !== false;
    }

    private function safeCode(string $value): bool
    {
        return (bool)preg_match('/^[a-z0-9][a-z0-9_.-]{0,127}$/', $value);
    }

    /** @param array<int,string> $headers */
    private function httpStatus(array $headers): int
    {
        $status = 0;
        foreach ($headers as $header) if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $m)) $status = (int)$m[1];
        return $status;
    }

    private function result(string $status, int $httpStatus, string $errorCode): array
    {
        return ['status' => $status, 'http_status' => $httpStatus, 'error_code' => $errorCode, 'health' => null];
    }
}
