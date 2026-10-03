<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\Inventory;

use RuntimeException;

class InventoryException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 409,
        public readonly array $details = [],
    ) { parent::__construct($message); }
}

final class InventoryStateConflict extends InventoryException {}
