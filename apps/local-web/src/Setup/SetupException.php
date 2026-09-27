<?php
declare(strict_types=1);

namespace Sokna\Local\Setup;

use RuntimeException;

final class SetupException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 422,
        public readonly array $details = [],
    ) { parent::__construct($message); }
}
