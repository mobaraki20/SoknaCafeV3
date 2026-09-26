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
use Sokna\Local\Relay\TableDraftRealtimeAdapter;
use Sokna\Local\Domain\Preparation\PreparationAccessService;
use Sokna\Local\Domain\Preparation\PreparationService;
use Sokna\Local\Relay\PreparationRealtimeAdapter;
use Sokna\Local\Domain\Inventory\InventoryService;
use Sokna\Local\Domain\Inventory\InventoryCountService;
use Sokna\Local\Domain\Inventory\InventoryOrderService;
use Sokna\Local\Relay\InventoryDeferredAdapter;
use Sokna\Local\Domain\Tax\TaxService;
use Sokna\Local\Domain\Finance\FinancialPeriodIdentityService;
use Sokna\Local\Domain\Expenses\ExpenseService;
use Sokna\Local\Relay\ExpenseDeferredAdapter;
use Sokna\Local\Domain\Supply\SupplyAccessService;
use Sokna\Local\Domain\Supply\SupplyService;
use Sokna\Local\Relay\DeferredReceiptService;
use Sokna\Local\Relay\SupplyDeferredAdapter;
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
    private ?TableDraftRealtimeAdapter $tableDraftRealtime = null;
    private ?PreparationAccessService $preparationAccess = null;
    private ?PreparationService $preparation = null;
    private ?PreparationRealtimeAdapter $preparationRealtime = null;
    private ?InventoryService $inventory = null;
    private ?InventoryCountService $inventoryCounts = null;
    private ?InventoryOrderService $inventoryOrders = null;
    private ?InventoryDeferredAdapter $inventoryDeferred = null;
    private ?TaxService $tax = null;
    private ?FinancialPeriodIdentityService $financialPeriodIdentity = null;
    private ?ExpenseService $expenses = null;
    private ?ExpenseDeferredAdapter $expenseDeferred = null;
    private ?SupplyAccessService $supplyAccess = null;
    private ?SupplyService $supply = null;
    private ?DeferredReceiptService $deferredReceipts = null;
    private ?SupplyDeferredAdapter $supplyDeferred = null;

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
        return $this->orders ??= new OrderCommitService($this->database(), $this->businessClock(), $this->orderCatalog(), $this->inventoryOrders(), $this->tax());
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

    public function tableDraftRealtime(): TableDraftRealtimeAdapter
    {
        return $this->tableDraftRealtime ??= new TableDraftRealtimeAdapter($this->database(), $this->tableDrafts());
    }

    public function preparationAccess(): PreparationAccessService
    {
        return $this->preparationAccess ??= new PreparationAccessService($this->database(), $this->identityRepository(), $this->capabilities());
    }

    public function preparation(): PreparationService
    {
        return $this->preparation ??= new PreparationService($this->database(), $this->preparationAccess(), $this->businessClock());
    }

    public function preparationRealtime(): PreparationRealtimeAdapter
    {
        return $this->preparationRealtime ??= new PreparationRealtimeAdapter($this->database(), $this->preparation());
    }

    public function inventory(): InventoryService
    {
        return $this->inventory ??= new InventoryService($this->database(), $this->identityRepository(), $this->capabilities());
    }

    public function inventoryCounts(): InventoryCountService
    {
        return $this->inventoryCounts ??= new InventoryCountService($this->database(), $this->inventory());
    }

    public function inventoryOrders(): InventoryOrderService
    {
        return $this->inventoryOrders ??= new InventoryOrderService($this->database(), $this->inventory());
    }

    public function inventoryDeferred(): InventoryDeferredAdapter
    {
        return $this->inventoryDeferred ??= new InventoryDeferredAdapter($this->database(), $this->inventory(), $this->inventoryCounts());
    }

    public function tax(): TaxService
    {
        return $this->tax ??= new TaxService($this->database(), $this->identityRepository());
    }

    public function financialPeriodIdentity(): FinancialPeriodIdentityService
    {
        return $this->financialPeriodIdentity ??= new FinancialPeriodIdentityService($this->database());
    }

    public function expenses(): ExpenseService
    {
        return $this->expenses ??= new ExpenseService($this->database(), $this->identityRepository(), $this->financialPeriodIdentity());
    }

    public function expenseDeferred(): ExpenseDeferredAdapter
    {
        return $this->expenseDeferred ??= new ExpenseDeferredAdapter(
            $this->database(), $this->identityRepository(), $this->expenses(), $this->financialPeriodIdentity(), $this->deferredReceipts()
        );
    }

    public function supplyAccess(): SupplyAccessService
    {
        return $this->supplyAccess ??= new SupplyAccessService($this->identityRepository(), $this->capabilities(), $this->preparationAccess());
    }

    public function supply(): SupplyService
    {
        return $this->supply ??= new SupplyService($this->database(), $this->inventory(), $this->supplyAccess());
    }

    public function deferredReceipts(): DeferredReceiptService
    {
        return $this->deferredReceipts ??= new DeferredReceiptService($this->database());
    }

    public function supplyDeferred(): SupplyDeferredAdapter
    {
        return $this->supplyDeferred ??= new SupplyDeferredAdapter(
            $this->database(), $this->inventory(), $this->supply(), $this->supplyAccess(), $this->deferredReceipts()
        );
    }

    public function startSession(string $cookiePath = '/', ?bool $secure = null): void
    {
        Session::start($this->config, $this->observability, $cookiePath, $secure);
    }
}
