<?php
declare(strict_types=1);
namespace Sokna\PublicEdge\Emergency;
use RuntimeException;
final class PublicUpdateException extends RuntimeException
{
    public function __construct(public readonly string $errorCode,string $message,public readonly int $httpStatus=422,public readonly array $context=[]){parent::__construct($message);}
}
