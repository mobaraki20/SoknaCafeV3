<?php
declare(strict_types=1);

namespace Sokna\PublicEdge\Setup;

use RuntimeException;

/**
 * Pick a private directory for Public-instance signing keys without operator
 * configuration. Never fall back to a path below the HTTP document root.
 * Caller must use the result through PublicMoveInstanceKeyStore.
 */
final class PublicMoveOwnerPrivatePath
{
    public static function prepare(string $componentRoot, string $documentRoot): string
    {
        $component = realpath($componentRoot);
        $doc = realpath($documentRoot);
        if (!$component || !$doc || !is_dir($component) || !is_dir($doc)) {
            throw new RuntimeException('owner_private_root_not_resolved');
        }
        $component = rtrim(str_replace('\\', '/', $component), '/');
        $doc = rtrim(str_replace('\\', '/', $doc), '/');
        $candidates = [];
        $home = getenv('HOME');
        if (is_string($home) && $home !== '') $candidates[] = $home;
        $cursor = $doc;
        for ($i = 0; $i < 8; $i++) {
            if (in_array(strtolower(basename($cursor)), ['public_html','httpdocs','htdocs','wwwroot','webroot'], true)) {
                $candidates[] = dirname($cursor);
                break;
            }
            $next = dirname($cursor);
            if ($next === $cursor) break;
            $cursor = $next;
        }
        $candidates[] = dirname($doc);
        $suffix = '.sokna-public-owner-'.substr(hash('sha256', $component), 0, 20);
        foreach (array_unique($candidates) as $base) {
            $real = realpath($base);
            if (!$real || !is_dir($real) || !is_writable($real) || is_link($base)) continue;
            $real = rtrim(str_replace('\\', '/', $real), '/');
            if (self::within($real, $doc) || $real === $doc) continue;
            $target = $real.'/'.$suffix;
            if (is_link($target)) continue;
            if (!is_dir($target) && !@mkdir($target, 0700)) continue;
            $actual = realpath($target);
            if (!$actual || is_link($target) || !is_writable($actual)) continue;
            $actual = rtrim(str_replace('\\', '/', $actual), '/');
            if (self::within($actual, $doc) || $actual === $doc) continue;
            if (PHP_OS_FAMILY !== 'Windows' && ((fileperms($actual) & 077) !== 0) && !@chmod($actual, 0700)) continue;
            return $actual;
        }
        throw new RuntimeException('owner_private_storage_not_available');
    }

    private static function within(string $path, string $folder): bool
    {
        return str_starts_with($path.'/', $folder.'/');
    }
}
