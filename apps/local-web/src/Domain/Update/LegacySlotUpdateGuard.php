<?php
declare(strict_types=1);
namespace Sokna\Local\Domain\Update;

/**
 * The legacy Local updater modifies active Web in place. It must never run
 * against an R8 release-slot installation owned by Maintenance V4.
 * This detector deliberately fails closed when a sibling release exists:
 * on Windows some PHP runtimes do not report NTFS junctions via is_link().
 * A false positive blocks the obsolete updater, not business traffic.
 */
final class LegacySlotUpdateGuard
{
    public static function isSlotManaged(string $webRoot): bool
    {
        $webRoot=rtrim($webRoot, '/\\');
        if (is_link($webRoot)) return true;
        $base=basename($webRoot);
        $parent=dirname($webRoot);
        $actual=realpath($webRoot);
        if ($actual!==false && self::isDirectSiblingRelease($actual,$parent)) return true;
        // The release root can itself be Web.release.<version> when resolved by PHP.
        if (str_starts_with($base,'Web.release.')) return true;
        // Fail closed also for environments where PHP realpath doesn't resolve
        // a Windows junction, or an interrupted slot adoption left a sibling.
        $siblings=glob($parent.DIRECTORY_SEPARATOR.'Web.release.*',GLOB_ONLYDIR);
        return is_array($siblings) && $siblings!==[];
    }

    private static function isDirectSiblingRelease(string $path,string $parent): bool
    {
        $normalized=str_replace('\\','/',$path);
        $parentPath=str_replace('\\','/',realpath($parent)?:$parent);
        return strcasecmp(dirname($normalized),rtrim($parentPath,'/'))===0
            && str_starts_with(basename($normalized),'Web.release.');
    }

    public static function requireLegacyLayout(string $webRoot): void
    {
        if (self::isSlotManaged($webRoot)) {
            throw new LocalUpdateException('MAINTENANCE_SLOT_OWNED',
                'این نصب از ساختار نسخه‌های مستقل Maintenance استفاده می‌کند؛ موتور قدیمی اجازه تغییر Web را ندارد.',409);
        }
    }
}
