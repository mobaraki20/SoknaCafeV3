<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\Preparation;

use RuntimeException;

final class PreparationException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 409,
        public readonly array $details = [],
    ) { parent::__construct($message); }
}
