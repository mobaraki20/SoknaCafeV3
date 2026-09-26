<?php
declare(strict_types=1);

namespace Sokna\PublicEdge\Core;

use PDO;
use Sokna\PublicEdge\Auth\AuthProjectionService;
use Sokna\PublicEdge\Auth\AuthSecurityAudit;
use Sokna\PublicEdge\Auth\AuthThrottle;
use Sokna\PublicEdge\Auth\PublicLoginService;
use Sokna\PublicEdge\Auth\PublicSessionStore;
use Sokna\PublicEdge\Connectivity\ConnectivityService;
use Sokna\PublicEdge\Security\SignedLocalRequestVerifier;

final class Bootstrap
{
    private ?PDO $database = null;
    private ?Migrations $migrations = null;
    private ?AuthProjectionService $authProjectionService = null;
    private ?AuthThrottle $authThrottle = null;
    private ?AuthSecurityAudit $authSecurityAudit = null;
    private ?PublicSessionStore $publicSessions = null;
    private ?PublicLoginService $publicLoginService = null;
    private ?SignedLocalRequestVerifier $signedLocalRequestVerifier = null;
    private ?ConnectivityService $connectivityService = null;

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

    public function authThrottle(): AuthThrottle
    {
        return $this->authThrottle ??= new AuthThrottle(
            $this->database(),
            max(2, (int)$this->config->get('auth.failure_limit', 5)),
            max(60, (int)$this->config->get('auth.failure_window_seconds', 900)),
            max(60, (int)$this->config->get('auth.block_seconds', 900)),
        );
    }

    public function authAudit(): AuthSecurityAudit
    {
        return $this->authSecurityAudit ??= new AuthSecurityAudit($this->database());
    }

    public function publicSessions(): PublicSessionStore
    {
        return $this->publicSessions ??= new PublicSessionStore(
            $this->database(),
            max(900, (int)$this->config->get('auth.session_ttl_seconds', 28800)),
        );
    }

    public function loginService(): PublicLoginService
    {
        return $this->publicLoginService ??= new PublicLoginService(
            $this->database(),
            $this->authThrottle(),
            $this->publicSessions(),
            $this->authAudit(),
        );
    }

    public function signedLocalRequests(): SignedLocalRequestVerifier
    {
        $secrets = $this->config->get('relay.installation_secrets', []);
        if (!is_array($secrets)) $secrets = [];
        return $this->signedLocalRequestVerifier ??= new SignedLocalRequestVerifier(
            $this->database(),
            $secrets,
            max(30, (int)$this->config->get('relay.clock_skew_seconds', 300)),
        );
    }

    public function connectivity(): ConnectivityService
    {
        return $this->connectivityService ??= new ConnectivityService($this->database());
    }
}
