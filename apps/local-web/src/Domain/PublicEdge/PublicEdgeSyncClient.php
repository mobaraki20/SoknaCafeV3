<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\PublicEdge;

use Sokna\Local\Core\Config;

final class PublicEdgeSyncClient
{
    /** @var null|callable */
    private $transport;

    public function __construct(private readonly Config $config, ?callable $transport = null)
    {
        $this->transport = $transport;
    }

    public function configured(): bool
    {
        return $this->baseUrl() !== '' && $this->secret() !== '' && $this->installationId() !== '';
    }

    /**
     * Canonicalize a Public Edge base URL.
     *
     * Subfolder installs are supported, but credentials/query/fragment/path traversal are not.
     * Remote HTTP is rejected; loopback HTTP remains available for local qualification.
     */
    public function canonicalBaseUrl(string $url): string
    {
        return $this->normalizeBase($url);
    }

    public function publicBaseUrl(): string
    {
        return $this->baseUrl();
    }

    public function safeOrigin(): string
    {
        $url = $this->baseUrl();
        if ($url === '') return '';
        $parts = parse_url($url);
        if (!is_array($parts)) return '';
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = trim(strtolower((string)($parts['host'] ?? '')), '[]');
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') return '';
        return $scheme . '://' . $this->authorityHost($host) . (isset($parts['port']) ? ':' . (int)$parts['port'] : '');
    }

    public function post(string $path, array $payload): array
    {
        $response = $this->postRaw($path, $payload);
        $status = (int)$response['status'];
        $body = $response['body'];
        if ($status < 200 || $status >= 300 || ($body['ok'] ?? false) !== true) {
            throw new PublicEdgeSyncException(
                (string)($body['error'] ?? 'public_sync_failed'),
                'همگام‌سازی Public Edge پذیرفته نشد.',
                $status ?: 502,
            );
        }
        return $response;
    }

    /** @return array{status:int,body:array} Signed POST that preserves non-2xx application responses for queue polling. */
    public function postRaw(string $path, array $payload): array
    {
        if (!$this->configured()) {
            throw new PublicEdgeSyncException('public_not_configured', 'Public Edge هنوز در config محلی pair نشده است.', 409);
        }
        if (!preg_match('#^/api/v1/local/[A-Za-z0-9/_-]+$#D', $path)) {
            throw new PublicEdgeSyncException('public_path_invalid', 'مسیر همگام‌سازی Public معتبر نیست.');
        }

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $timestamp = (string)time();
        $nonce = bin2hex(random_bytes(16));
        $signature = hash_hmac('sha256', $this->signatureBase('POST', $path, $timestamp, $nonce, $body), $this->secret());
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'X-Sokna-Installation' => $this->installationId(),
            'X-Sokna-Timestamp' => $timestamp,
            'X-Sokna-Nonce' => $nonce,
            'X-Sokna-Signature' => $signature,
            'X-Correlation-ID' => 'g41-' . bin2hex(random_bytes(8)),
        ];

        if (is_callable($this->transport)) {
            $response = ($this->transport)($this->baseUrl() . $path, 'POST', $headers, $body);
            return $this->normalizeRawResponse($response);
        }

        $headerLines = [];
        foreach ($headers as $key => $value) $headerLines[] = $key . ': ' . $value;
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headerLines),
                'content' => $body,
                'timeout' => 10,
                'ignore_errors' => true,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $raw = @file_get_contents($this->baseUrl() . $path, false, $context);
        $status = $this->httpStatus($http_response_header ?? []);
        if ($raw === false && $status === 0) {
            throw new PublicEdgeSyncException('public_unreachable', 'ارتباط با Public Edge برقرار نشد.', 503);
        }
        return $this->normalizeRawResponse(['status' => $status, 'body' => (string)$raw]);
    }

    public function diagnostics(): array
    {
        return $this->post('/api/v1/local/diagnostics', []);
    }

    public function pushConfig(): array
    {
        return $this->post('/api/v1/local/push/config', []);
    }

    public function deliverPush(array $payload): array
    {
        return $this->post('/api/v1/local/push/deliver', $payload);
    }

    public function health(): array
    {
        $base = $this->baseUrl();
        if ($base === '') {
            throw new PublicEdgeSyncException('public_not_configured', 'Public Edge هنوز پیکربندی نشده است.', 409);
        }
        if (is_callable($this->transport)) {
            $response = ($this->transport)($base . '/health', 'GET', ['Accept' => 'application/json'], '');
            return $this->normalizeResponse($response);
        }
        $context = stream_context_create([
            'http' => ['method' => 'GET', 'header' => "Accept: application/json\r\n", 'timeout' => 5, 'ignore_errors' => true],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $raw = @file_get_contents($base . '/health', false, $context);
        $status = $this->httpStatus($http_response_header ?? []);
        if ($raw === false && $status === 0) {
            throw new PublicEdgeSyncException('public_unreachable', 'ارتباط با Public Edge برقرار نشد.', 503);
        }
        return $this->normalizeResponse(['status' => $status, 'body' => (string)$raw]);
    }

    public function initialPair(string $baseUrl, string $code, string $newSecret, string $displayName = ''): array
    {
        $base = $this->requireBase($baseUrl, 'نشانی وب عمومی معتبر نیست.');
        $payload = [
            'installation_id' => $this->installationId(),
            'pairing_code' => $code,
            'shared_secret' => $newSecret,
            'display_name' => $displayName,
        ];
        return $this->unsignedPost($base . '/setup/api.php?action=pair', $payload, 'ارتباط با وب عمومی برقرار نشد.');
    }

    public function reenroll(string $baseUrl, string $code, string $newSecret, string $displayName = ''): array
    {
        $base = $this->requireBase($baseUrl, 'نشانی Public معتبر نیست.');
        $payload = [
            'new_installation_id' => $this->installationId(),
            'enrollment_code' => $code,
            'new_shared_secret' => $newSecret,
            'display_name' => $displayName,
        ];
        return $this->unsignedPost($base . '/emergency.php?action=reenroll', $payload, 'ارتباط با Public Edge برقرار نشد.');
    }

    public function installationId(): string
    {
        return trim($this->config->string('installation.id', ''));
    }

    private function unsignedPost(string $url, array $payload, string $unreachableMessage): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (is_callable($this->transport)) {
            $response = ($this->transport)($url, 'POST', ['Content-Type' => 'application/json', 'Accept' => 'application/json'], $body);
            return $this->normalizeResponse($response);
        }
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
                'content' => $body,
                'timeout' => 10,
                'ignore_errors' => true,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $raw = @file_get_contents($url, false, $context);
        $status = $this->httpStatus($http_response_header ?? []);
        if ($raw === false && $status === 0) {
            throw new PublicEdgeSyncException('public_unreachable', $unreachableMessage, 503);
        }
        return $this->normalizeResponse(['status' => $status, 'body' => (string)$raw]);
    }

    private function baseUrl(): string
    {
        return $this->normalizeBase($this->config->string('public.base_url', ''));
    }

    private function requireBase(string $url, string $message): string
    {
        $base = $this->normalizeBase($url);
        if ($base === '') throw new PublicEdgeSyncException('public_url_invalid', $message, 422);
        return $base;
    }

    private function normalizeBase(string $url): string
    {
        $url = trim($url);
        if ($url === '' || preg_match('/[\x00-\x20\x7f]/', $url)) return '';
        $parts = parse_url($url);
        if (!is_array($parts)) return '';
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) return '';

        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower((string)($parts['host'] ?? ''));
        $host = trim($host, '[]');
        if ($host === '' || !in_array($scheme, ['http', 'https'], true)) return '';
        if ($scheme === 'http' && !in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) return '';

        $path = (string)($parts['path'] ?? '');
        if (str_contains($path, '\\')) return '';
        $decodedPath = rawurldecode($path);
        foreach (explode('/', $decodedPath) as $segment) {
            if ($segment === '.' || $segment === '..') return '';
        }
        $path = rtrim($path, '/');
        if ($path === '/') $path = '';

        $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
        return $scheme . '://' . $this->authorityHost($host) . $port . $path;
    }

    private function authorityHost(string $host): string
    {
        return str_contains($host, ':') ? '[' . trim($host, '[]') . ']' : $host;
    }

    private function secret(): string
    {
        return trim($this->config->string('public.shared_secret', ''));
    }

    private function signatureBase(string $method, string $path, string $timestamp, string $nonce, string $body): string
    {
        return implode("\n", [
            'sokna-relay-v1',
            strtoupper($method),
            '/' . ltrim($path, '/'),
            $timestamp,
            $nonce,
            hash('sha256', $body),
        ]);
    }

    private function normalizeResponse(array $response): array
    {
        $normalized = $this->normalizeRawResponse($response);
        $status = (int)$normalized['status'];
        $body = $normalized['body'];
        if ($status < 200 || $status >= 300 || ($body['ok'] ?? false) !== true) {
            throw new PublicEdgeSyncException(
                (string)($body['error'] ?? $body['code'] ?? 'public_sync_failed'),
                (string)($body['message'] ?? 'همگام‌سازی Public Edge پذیرفته نشد.'),
                $status ?: 502,
            );
        }
        return $normalized;
    }

    private function normalizeRawResponse(array $response): array
    {
        $status = (int)($response['status'] ?? 0);
        $body = $response['body'] ?? [];
        if (is_string($body)) {
            $decoded = json_decode($body, true);
            $body = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($body)) $body = [];
        return ['status' => $status, 'body' => $body];
    }

    /** @param array<int,string> $headers */
    private function httpStatus(array $headers): int
    {
        foreach ($headers as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $match)) return (int)$match[1];
        }
        return 0;
    }
}
