<?php
declare(strict_types=1);
namespace Sokna\PublicEdge\Emergency;
final class PublicUpdateException extends \RuntimeException {
 public function __construct(public readonly string $errorCode,string $message,int $httpStatus=409,array $context=[]) {parent::__construct($message,$httpStatus);}
}