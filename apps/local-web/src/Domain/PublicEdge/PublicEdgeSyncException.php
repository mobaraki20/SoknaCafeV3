<?php
declare(strict_types=1);
namespace Sokna\Local\Domain\PublicEdge;
use RuntimeException;
final class PublicEdgeSyncException extends RuntimeException
{
    public function __construct(public readonly string $errorCode,string $message,public readonly int $httpStatus=400){parent::__construct($message);}
}
