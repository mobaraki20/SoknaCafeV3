<?php
declare(strict_types=1);

namespace Sokna\PublicEdge\Core;

use RuntimeException;

final class Config
{
    private function __construct(private readonly array $values)
    {
    }

    public static function fromArray(array $values): self
    {
        return new self($values);
    }

    public function get(string $path, mixed $default = null): mixed
    {
        $value = $this->values;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) return $default;
            $value = $value[$segment];
        }
        return $value;
    }

    public function string(string $path, string $default = ''): string
    {
        $value = $this->get($path, $default);
        return is_scalar($value) ? (string)$value : $default;
    }

    public function requiredString(string $path): string
    {
        $value = trim($this->string($path));
        if ($value === '') throw new RuntimeException("Required Public config value '{$path}' is missing.");
        return $value;
    }
}
