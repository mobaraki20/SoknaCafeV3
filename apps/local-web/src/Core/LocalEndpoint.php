<?php
declare(strict_types=1);

namespace Sokna\Local\Core;

/** Canonical loopback-only endpoint authority for Local Web setup and Windows peers. */
final class LocalEndpoint
{
    public const CANONICAL_HOST = '127.0.0.1';
    public const MIN_PORT = 1024;
    public const DEFAULT_PORT = 18080;

    /** @return array{origin:string,port:int,valid_request:bool} */
    public static function fromServer(array $server): array
    {
        $https = !empty($server['HTTPS']) && strtolower((string)$server['HTTPS']) !== 'off';
        $scheme = $https ? 'https' : 'http';
        $raw = trim((string)($server['HTTP_HOST'] ?? ''));
        if (preg_match('/^(?:127\\.0\\.0\\.1|localhost):([0-9]{1,5})$/iD', $raw, $matches) === 1) {
            $port = (int)$matches[1];
            if ($port >= self::MIN_PORT && $port <= 65535) {
                return ['origin'=>$scheme.'://'.self::CANONICAL_HOST.':'.$port, 'port'=>$port, 'valid_request'=>true];
            }
        }
        return ['origin'=>'http://'.self::CANONICAL_HOST.':'.self::DEFAULT_PORT, 'port'=>self::DEFAULT_PORT, 'valid_request'=>false];
    }

    /** Normalize an installed server origin, rejecting remote hosts and privileged ports. */
    public static function normalizeOrigin(string $input): string
    {
        $value = trim($input);
        if ($value === '') return '';
        $parts = parse_url($value);
        if (!is_array($parts)) return '';
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower((string)($parts['host'] ?? ''));
        if (!in_array($scheme, ['http','https'], true) || !in_array($host, [self::CANONICAL_HOST,'localhost'], true)) return '';
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) return '';
        if (isset($parts['path']) && !in_array($parts['path'], ['', '/'], true)) return '';
        $port = (int)($parts['port'] ?? 0);
        if ($port < self::MIN_PORT || $port > 65535) return '';
        return $scheme.'://'.self::CANONICAL_HOST.':'.$port;
    }

    public static function canonicalRedirectTarget(array $server, string $path = '/setup/'): string
    {
        if (!str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, '\\')) {
            throw new \InvalidArgumentException('Invalid local path');
        }
        return self::fromServer($server)['origin'].$path;
    }
}
