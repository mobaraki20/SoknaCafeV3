<?php
declare(strict_types=1);

namespace Sokna\Local\Core;

use DateTimeZone;
use PDO;
use Sokna\Local\Domain\Orders\BusinessClock;
use Sokna\Local\Domain\Orders\OrderCatalogService;
use Sokna\Local\Domain\Orders\OrderCommitService;
use Sokna\Local\Domain\Orders\StaffQuickOrderService;
use Sokna\Local\Domain\Orders\TableDraftService;
use Sokna\Local\Domain\Sellables\SellableRepository;

final class Bootstrap
{
    private ?PDO $database = null;
    private ?IdentityRepository $identityRepository = null;
    private ?Capabilities $capabilities = null;
    private ?Auth $auth = null;
    private ?Migrations $migrations = null;
    private ?SellableRepository $sellables = null;
    private ?BusinessClock $businessClock = null;
    private ?OrderCatalogService $orderCatalog = null;
    private ?OrderCommitService $orders = null;
    private ?StaffQuickOrderService $staffQuickOrders = null;
    private ?TableDraftService $tableDrafts = null;

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

    public function migrations(): Migrations
    {
        $directory = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations';
        return $this->migrations ??= new Migrations($this->database(), $directory);
    }

    public function sellables(): SellableRepository
    {
        return $this->sellables ??= new SellableRepository($this->database());
    }

    public function businessClock(): BusinessClock
    {
        return $this->businessClock ??= new BusinessClock($this->database(), $this->config->string('app.timezone', 'Asia/Tehran'));
    }

    public function orderCatalog(): OrderCatalogService
    {
        return $this->orderCatalog ??= new OrderCatalogService($this->database());
    }

    public function orders(): OrderCommitService
    {
        return $this->orders ??= new OrderCommitService($this->database(), $this->businessClock(), $this->orderCatalog());
    }

    public function staffQuickOrders(): StaffQuickOrderService
    {
        return $this->staffQuickOrders ??= new StaffQuickOrderService(
            $this->database(),
            $this->businessClock(),
            $this->identityRepository(),
            $this->capabilities(),
            $this->orders(),
        );
    }

    public function tableDrafts(): TableDraftService
    {
        return $this->tableDrafts ??= new TableDraftService($this->database(), $this->orderCatalog(), $this->staffQuickOrders());
    }

    public function startSession(string $cookiePath = '/', ?bool $secure = null): void
    {
        Session::start($this->config, $this->observability, $cookiePath, $secure);
    }
}
