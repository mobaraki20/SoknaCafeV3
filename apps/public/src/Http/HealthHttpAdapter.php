<?php
declare(strict_types=1);

namespace Sokna\PublicEdge\Http;

use Sokna\PublicEdge\Core\Bootstrap;

final class HealthHttpAdapter
{
    public function __construct(private readonly Bootstrap $core)
    {
    }

    public function status(?string $correlationId = null): array
    {
        return $this->core->health()->status($correlationId);
    }
}
