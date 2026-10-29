<?php
declare(strict_types=1);

namespace Sokna\Local\Core;

/** Configurable, loopback-only Local Web origin contract. */
final class LocalEndpoint
{
    public const CANONICAL_HOST = '127.0.0.1';
    public const MIN_PORT = 1024;
    public const DEFAULT_PORT = 18080;

    /** Canonical origin including trailing slash; fail closed on an invalid Apache port. */
    public static function fromServer(array $server): string
    {
        $https = !empty($server['HTTPS']) && strtolower((string)$server['HTTPS']) !== 'off';
        $scheme = $https ? 'https' : 'http';
        $rawHost = trim((string)($server['HTTP_HOST'] ?? ''));
        $port = filter_var($server['SERVER_PORT'] ?? null, FILTER_VALIDATE_INT);
        if ($port === false || $port < self::MIN_PORT || $port > 65535) {
            throw new \InvalidArgumentException('Invalid Local Web server port');
        }
        $origin = self::normalizeOrigin($scheme.'://'.$rawHost);
        $parts = parse_url($scheme.'://'.$rawHost);
        if ($origin === '' || !is_array($parts) || (int)($parts['port'] ?? 0) !== $port) {
            throw new \InvalidArgumentException('Invalid Local Web loopback origin');
        }
        return $origin;
    }

    /** Reject remote hosts, URLs with credentials, paths, queries and privileged ports. */
    public static function normalizeOrigin(string $input): string
    {
        $value = trim($input);
        if ($value === '') return '';
        $parts = parse_url($value);
        if (!is_array($parts)) return '';
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower((string)($parts['host'] ?? ''));
        if (!in_array($scheme, ['http','https'], true)
            || !in_array($host, [self::CANONICAL_HOST,'localhost','[::1]','::1'], true)) return '';
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) return '';
        if (isset($parts['path']) && !in_array($parts['path'], ['', '/'], true)) return '';
        $port = (int)($parts['port'] ?? 0);
        if ($port < self::MIN_PORT || $port > 65535) return '';
        return $scheme.'://'.self::CANONICAL_HOST.':'.$port.'/';
    }

    /** Redirect only noncanonical loopback hosts; keep a safe relative path and query. */
    public static function canonicalRedirectTarget(array $server): ?string
    {
        $origin = self::fromServer($server);
        $port = (int)($server['SERVER_PORT'] ?? 0);
        if (strtolower(trim((string)($server['HTTP_HOST'] ?? ''))) === self::CANONICAL_HOST.':'.$port) return null;
        $uri = (string)($server['REQUEST_URI'] ?? '/setup/');
        if (!str_starts_with($uri, '/') || str_starts_with($uri, '//') || str_contains($uri, '\\')) $uri = '/setup/';
        return rtrim($origin, '/').$uri;
    }
}
