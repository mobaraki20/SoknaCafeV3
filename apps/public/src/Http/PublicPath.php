<?php
declare(strict_types=1);

namespace Sokna\PublicEdge\Http;

final class PublicPath
{
    public static function normalizeBasePath(string $value): string
    {
        $value = trim(str_replace('\\', '/', $value));
        if ($value === '' || $value === '/') return '';
        $value = '/' . trim($value, '/');
        if (str_contains(rawurldecode($value), '..') || str_contains($value, "\0")) return '';
        return rtrim($value, '/');
    }

    public static function fromSetupRequest(?string $requestUri = null): string
    {
        $path = parse_url((string)($requestUri ?? ($_SERVER['REQUEST_URI'] ?? '/setup/')), PHP_URL_PATH);
        $scriptBase = self::fromScriptName((string)($_SERVER['SCRIPT_NAME'] ?? ''));
        if (!is_string($path) || $path === '') return $scriptBase;
        $path = '/' . ltrim($path, '/');
        $pos = strrpos($path, '/setup');
        if ($pos === false) return $scriptBase;
        $suffix = substr($path, $pos);
        if ($suffix !== '/setup' && !str_starts_with($suffix, '/setup/')) return $scriptBase;
        return self::normalizeBasePath(substr($path, 0, $pos));
    }

    public static function fromScriptName(string $scriptName): string
    {
        $scriptName = str_replace('\\', '/', trim($scriptName));
        if ($scriptName === '') return '';
        foreach(['/setup/index.php','/setup/api.php','/emergency.php','/index.php'] as $suffix) {
            if (str_ends_with($scriptName, $suffix)) return self::normalizeBasePath(substr($scriptName, 0, -strlen($suffix)));
        }
        return self::normalizeBasePath(dirname($scriptName));
    }

    public static function prefix(string $basePath, string $path): string
    {
        $basePath = self::normalizeBasePath($basePath);
        $path = '/' . ltrim($path, '/');
        return $basePath . ($path === '/' ? '/' : $path);
    }

    public static function strip(string $basePath, string $requestPath): string
    {
        $basePath = self::normalizeBasePath($basePath);
        $path = parse_url($requestPath, PHP_URL_PATH);
        if (!is_string($path) || $path === '' || str_contains($path, "\0")) return '';
        $decoded = rawurldecode($path);
        if (str_contains($decoded, '..') || str_contains($decoded, '\\')) return '';
        $path = '/' . ltrim($decoded, '/');
        if ($basePath !== '') {
            if ($path === $basePath) return '/';
            if (!str_starts_with($path, $basePath . '/')) return '';
            $path = substr($path, strlen($basePath));
        }
        return $path !== '/' ? rtrim($path, '/') : '/';
    }
}
