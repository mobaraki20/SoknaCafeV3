<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\Sellables;

use InvalidArgumentException;

final class SellableKind
{
    public const MENU_ITEM = 'menu_item';
    public const SERVICE_ITEM = 'service_item';

    /** @return array<string,string> */
    public static function labels(): array
    {
        return [
            self::MENU_ITEM => 'آیتم منو',
            self::SERVICE_ITEM => 'خدمت',
        ];
    }

    /**
     * Legacy-safe read normalization. Historical rows that predate explicit
     * classification behave as menu items; no category/station/name inference is allowed.
     */
    public static function normalizeRead(mixed $value): string
    {
        $kind = trim((string)$value);
        return array_key_exists($kind, self::labels()) ? $kind : self::MENU_ITEM;
    }

    /** Strict write validation; invalid explicit input is never guessed. */
    public static function requireWrite(mixed $value): string
    {
        $kind = trim((string)$value);
        if (!array_key_exists($kind, self::labels())) {
            throw new InvalidArgumentException('نوع مورد معتبر نیست.');
        }
        return $kind;
    }

    public static function label(mixed $value): string
    {
        return self::labels()[self::normalizeRead($value)];
    }

    public static function isService(array $item): bool
    {
        return self::normalizeRead($item['sellable_kind'] ?? null) === self::SERVICE_ITEM;
    }
}
