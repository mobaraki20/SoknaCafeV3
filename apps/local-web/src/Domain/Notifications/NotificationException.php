<?php
declare(strict_types=1);
namespace Sokna\Local\Domain\Notifications;
use RuntimeException;
final class NotificationException extends RuntimeException
{
    public function __construct(public readonly string $errorCode,string $message,public readonly int $httpStatus=409,public readonly array $details=[]){parent::__construct($message);}
}
