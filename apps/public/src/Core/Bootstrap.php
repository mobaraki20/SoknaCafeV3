<?php
declare(strict_types=1);

namespace Sokna\PublicEdge\Core;

use PDO;
use Sokna\PublicEdge\Auth\AuthProjectionService;

final class Bootstrap
{
    private ?PDO $database = null;
    private ?Migrations $migrations = null;
    private ?AuthProjectionService $authProjectionService = null;

    private function __construct(private readonly Config $config)
    {
    }

    public static function fromArray(array $values): self
    {
        return new self(Config::fromArray($values));
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function database(): PDO
    {
        return $this->database ??= Database::connect($this->config);
    }

    public function migrations(): Migrations
    {
        $directory = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations';
        return $this->migrations ??= new Migrations($this->database(), $directory);
    }

    public function authProjections(): AuthProjectionService
    {
        return $this->authProjectionService ??= new AuthProjectionService($this->database());
    }
}
