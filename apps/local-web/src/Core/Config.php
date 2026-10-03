<?php
declare(strict_types=1);

namespace Sokna\Local\Core;

use InvalidArgumentException;

final class Config
{
    public function __construct(private readonly array $values)
    {
    }

    public static function fromArray(array $values): self
    {
        return new self($values);
    }

    public function get(string $path, mixed $default = null): mixed
    {
        $cursor = $this->values;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return $default;
            }
            $cursor = $cursor[$segment];
        }
        return $cursor;
    }

    public function string(string $path, ?string $default = null): string
    {
        $value = $this->get($path, $default);
        if ($value === null || is_array($value) || is_object($value) || is_resource($value)) {
            throw new InvalidArgumentException("Configuration value '{$path}' must be a string-compatible scalar.");
        }
        return trim((string)$value);
    }

    public function requiredString(string $path): string
    {
        $value = $this->string($path, null);
        if ($value === '') {
            throw new InvalidArgumentException("Required configuration value '{$path}' is empty.");
        }
        return $value;
    }

    public function all(): array
    {
        return $this->values;
    }
}
