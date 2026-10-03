<?php
declare(strict_types=1);

namespace Sokna\PublicEdge\Core;

use RuntimeException;

final class CanonicalJson
{
    public static function encode(mixed $value): string
    {
        $json = json_encode(
            self::normalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        );
        if (!is_string($json)) throw new RuntimeException('Canonical JSON encoding failed.');
        return $json;
    }

    public static function sha256(mixed $value): string
    {
        return hash('sha256', self::encode($value));
    }

    private static function normalize(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (array_is_list($value)) return array_map([self::class, 'normalize'], $value);
        ksort($value, SORT_STRING);
        foreach ($value as $key => $child) $value[$key] = self::normalize($child);
        return $value;
    }
}
