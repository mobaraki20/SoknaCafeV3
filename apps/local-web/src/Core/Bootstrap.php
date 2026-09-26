<?php
declare(strict_types=1);

namespace Sokna\Local\Core;

use DateTimeZone;
use PDO;

final class Bootstrap
{
    private ?PDO $database = null;
    private ?IdentityRepository $identityRepository = null;
    private ?Capabilities $capabilities = null;
    private ?Auth $auth = null;

    private function __construct(
        private readonly Config $config,
        private readonly Observability $observability,
    ) {
    }

    public static function fromArray(array $values): self
    {
        $config = Config::fromArray($values);
        $timezone = $config->string('app.timezone', 'Asia/Tehran');
        new DateTimeZone($timezone);
        date_default_timezone_set($timezone);

        return new self($config, Observability::fromConfig($config));
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function observability(): Observability
    {
        return $this->observability;
    }

    public function database(): PDO
    {
        return $this->database ??= Database::connect($this->config);
    }

    public function identityRepository(): IdentityRepository
    {
        return $this->identityRepository ??= new PdoIdentityRepository($this->database());
    }

    public function capabilities(): Capabilities
    {
        return $this->capabilities ??= new Capabilities($this->identityRepository());
    }

    public function auth(): Auth
    {
        $lifetime = max(300, (int)$this->config->get('app.session_lifetime', Session::DEFAULT_LIFETIME));
        return $this->auth ??= new Auth($this->identityRepository(), $this->capabilities(), $lifetime);
    }

    public function startSession(string $cookiePath = '/', ?bool $secure = null): void
    {
        Session::start($this->config, $this->observability, $cookiePath, $secure);
    }
}
