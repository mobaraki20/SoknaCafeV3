<?php
declare(strict_types=1);

namespace Sokna\Local\Core;

use DateTimeZone;
use PDO;
use Sokna\Local\Domain\Orders\BusinessClock;
use Sokna\Local\Domain\Orders\OrderCatalogService;
use Sokna\Local\Domain\Orders\OrderCommitService;
use Sokna\Local\Domain\Orders\GuestOrderService;
use Sokna\Local\Domain\Orders\WaiterCallService;
use Sokna\Local\Domain\Orders\StaffQuickOrderService;
use Sokna\Local\Domain\Orders\TableDraftService;
use Sokna\Local\Domain\Orders\OrderStaffActionService;
use Sokna\Local\Domain\Orders\WaiterCallStaffService;
use Sokna\Local\Domain\Orders\OrderWorkspaceService;
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
use Sokna\Local\Domain\Finance\FinancialPeriodService;
use Sokna\Local\Domain\Finance\FinanceWorkspaceService;
use Sokna\Local\Domain\Finance\SettlementService;
use Sokna\Local\Domain\Finance\FinancialPeriodCloseService;
use Sokna\Local\Domain\Integrations\SubscriberService;
use Sokna\Local\Domain\Integrations\SubscriberAccountService;
use Sokna\Local\Domain\Integrations\AccommodationTransport;
use Sokna\Local\Domain\Integrations\AccommodationService;
use Sokna\Local\Domain\Integrations\IntegrationWorkspaceService;
use Sokna\Local\Runtime\RuntimeTriggerService;
use Sokna\Local\Domain\Printing\PrintService;
use Sokna\Local\Domain\Printing\PrintManagementService;
use Sokna\Local\Http\PrintAgentV4HttpAdapter;
use Sokna\Local\Http\RuntimeTriggerHttpAdapter;
use Sokna\Local\Domain\Expenses\ExpenseService;
use Sokna\Local\Domain\Operations\OperationsWorkspaceService;
use Sokna\Local\Domain\Recovery\BusinessBackupService;
use Sokna\Local\Domain\System\SupportBundleWriter;
use Sokna\Local\Domain\System\SystemDiagnosticsService;
use Sokna\Local\Relay\ExpenseDeferredAdapter;
use Sokna\Local\Domain\Supply\SupplyAccessService;
use Sokna\Local\Domain\Supply\SupplyService;
use Sokna\Local\Relay\DeferredReceiptService;
use Sokna\Local\Relay\SupplyDeferredAdapter;
use Sokna\Local\Relay\SubscriberPaymentDeferredAdapter;
use Sokna\Local\Relay\DeferredDispatchService;
use Sokna\Local\Relay\SettlementRealtimeAdapter;
use Sokna\Local\Relay\GuestOrderRealtimeAdapter;
use Sokna\Local\Relay\WaiterCallRealtimeAdapter;
use Sokna\Local\Relay\RealtimeDispatchService;
use Sokna\Local\Domain\Sellables\SellableRepository;
use Sokna\Local\Domain\Sellables\CatalogAdminService;
use Sokna\Local\Domain\Admin\AdminControlService;
use Sokna\Local\Domain\StaffConsumption\PersonnelRepository;
use Sokna\Local\Domain\StaffConsumption\StaffBenefitRepository;
use Sokna\Local\Domain\StaffConsumption\StaffBenefitCalculator;
use Sokna\Local\Domain\StaffConsumption\StaffBenefitCalculationService;
use Sokna\Local\Domain\StaffConsumption\StaffBenefitManagementService;
use Sokna\Local\Domain\StaffConsumption\StaffAccountRepository;
use Sokna\Local\Domain\StaffConsumption\StaffAccountService;
use Sokna\Local\Domain\StaffConsumption\StaffConsumptionFoundationService;
use Sokna\Local\Domain\StaffConsumption\StaffConsumptionRepository;
use Sokna\Local\Domain\StaffConsumption\StaffConsumptionPostingService;
use Sokna\Local\Domain\StaffConsumption\StaffConsumptionWorkspaceService;
use Sokna\Local\Domain\StaffConsumption\StaffConsumptionReportService;
use Sokna\Local\Search\GlobalSearchService;
use Sokna\Local\Search\CatalogSearchProvider;
use Sokna\Local\Search\InventorySearchProvider;
use Sokna\Local\Search\FinanceSearchProvider;
use Sokna\Local\Search\SubscriberSearchProvider;
use Sokna\Local\Search\AdminSearchProvider;

use Sokna\Local\Domain\Update\LocalUpdateService;
use Sokna\Local\Domain\Update\ComponentUpdateCenterService;
use Sokna\Local\Domain\Recovery\RecoveryWorkspaceService;
use Sokna\Local\Domain\PublicEdge\PublicEdgeSyncClient;
use Sokna\Local\Domain\PublicEdge\PublicProjectionBuilder;
use Sokna\Local\Domain\PublicEdge\PublicReenrollmentService;
use Sokna\Local\Domain\PublicEdge\PublicEdgePublisherService;
use Sokna\Local\Domain\PublicEdge\PublicEdgeRelayService;
final class Bootstrap
{
    private ?PDO $database = null;
    private ?IdentityRepository $identityRepository = null;
    private ?Capabilities $capabilities = null;
    private ?Auth $auth = null;
    private ?Migrations $migrations = null;
    private ?AdminControlService $adminControls = null;
    private ?PersonnelRepository $personnel = null;
    private ?StaffBenefitRepository $staffBenefits = null;
    private ?StaffBenefitCalculator $staffBenefitCalculator = null;
    private ?StaffBenefitCalculationService $staffBenefitCalculation = null;
    private ?StaffBenefitManagementService $staffBenefitManagement = null;
    private ?StaffAccountRepository $staffAccounts = null;
    private ?StaffAccountService $staffAccountService = null;
    private ?StaffConsumptionFoundationService $staffConsumptionFoundation = null;
    private ?StaffConsumptionRepository $staffConsumptionRepository = null;
    private ?StaffConsumptionPostingService $staffConsumptionPosting = null;
    private ?StaffConsumptionWorkspaceService $staffConsumptionWorkspace = null;
    private ?StaffConsumptionReportService $staffConsumptionReports = null;
    private ?GlobalSearchService $globalSearch = null;
    private ?SellableRepository $sellables = null;
    private ?CatalogAdminService $catalogAdmin = null;
    private ?BusinessClock $businessClock = null;
    private ?OrderCatalogService $orderCatalog = null;
    private ?OrderCommitService $orders = null;
    private ?StaffQuickOrderService $staffQuickOrders = null;
    private ?TableDraftService $tableDrafts = null;
    private ?OrderStaffActionService $orderStaffActions = null;
    private ?WaiterCallStaffService $waiterCallStaff = null;
    private ?OrderWorkspaceService $orderWorkspace = null;
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
    private ?FinancialPeriodService $financialPeriods = null;
    private ?FinanceWorkspaceService $financeWorkspace = null;
    private ?SettlementService $settlements = null;
    private ?FinancialPeriodCloseService $financialPeriodClose = null;
    private ?SubscriberService $subscribers = null;
    private ?SubscriberAccountService $subscriberAccounts = null;
    private ?AccommodationTransport $accommodationTransport = null;
    private ?AccommodationService $accommodation = null;
    private ?IntegrationWorkspaceService $integrationWorkspace = null;
    private ?RuntimeTriggerService $runtimeTriggers = null;
    private ?PrintService $printing = null;
    private ?PrintManagementService $printManagement = null;
    private ?PrintAgentV4HttpAdapter $printAgentV4Http = null;
    private ?RuntimeTriggerHttpAdapter $runtimeTriggerHttp = null;
    private ?ExpenseService $expenses = null;
    private ?OperationsWorkspaceService $operationsWorkspace = null;
    private ?BusinessBackupService $businessBackup = null;
    private ?SupportBundleWriter $supportBundles = null;
    private ?SystemDiagnosticsService $systemDiagnostics = null;
    private ?LocalUpdateService $localUpdates = null;
    private ?ComponentUpdateCenterService $updateCenter = null;
    private ?RecoveryWorkspaceService $recoveryWorkspace = null;
    private ?PublicEdgeSyncClient $publicEdgeSyncClient = null;
    private ?PublicProjectionBuilder $publicProjectionBuilder = null;
    private ?PublicEdgePublisherService $publicEdgePublisher = null;
    private ?PublicEdgeRelayService $publicEdgeRelay = null;
    private ?PublicReenrollmentService $publicReenrollmentService = null;
    private ?ExpenseDeferredAdapter $expenseDeferred = null;
    private ?SupplyAccessService $supplyAccess = null;
    private ?SupplyService $supply = null;
    private ?DeferredReceiptService $deferredReceipts = null;
    private ?SupplyDeferredAdapter $supplyDeferred = null;
    private ?SubscriberPaymentDeferredAdapter $subscriberPaymentDeferred = null;
    private ?DeferredDispatchService $deferredDispatch = null;
    private ?SettlementRealtimeAdapter $settlementRealtime = null;
    private ?GuestOrderService $guestOrders = null;
    private ?WaiterCallService $waiterCalls = null;
    private ?GuestOrderRealtimeAdapter $guestOrderRealtime = null;
    private ?WaiterCallRealtimeAdapter $waiterCallRealtime = null;
    private ?RealtimeDispatchService $realtimeDispatch = null;

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

    public function adminControls(): AdminControlService
    {
        return $this->adminControls ??= new AdminControlService($this->database(), $this->identityRepository());
    }

    public function personnel(): PersonnelRepository
    {
        return $this->personnel ??= new PersonnelRepository($this->database());
    }

    public function staffBenefits(): StaffBenefitRepository
    {
        return $this->staffBenefits ??= new StaffBenefitRepository($this->database());
    }

    public function staffBenefitCalculator(): StaffBenefitCalculator
    {
        return $this->staffBenefitCalculator ??= new StaffBenefitCalculator();
    }

    public function staffBenefitCalculation(): StaffBenefitCalculationService
    {
        return $this->staffBenefitCalculation ??= new StaffBenefitCalculationService(
            $this->staffBenefits(), $this->staffBenefitCalculator(), $this->identityRepository(), $this->capabilities()
        );
    }

    public function staffBenefitManagement(): StaffBenefitManagementService
    {
        return $this->staffBenefitManagement ??= new StaffBenefitManagementService(
            $this->database(), $this->identityRepository(), $this->capabilities()
        );
    }

    public function staffAccounts(): StaffAccountRepository
    {
        return $this->staffAccounts ??= new StaffAccountRepository($this->database());
    }

    public function staffAccountService(): StaffAccountService
    {
        return $this->staffAccountService ??= new StaffAccountService(
            $this->database(), $this->identityRepository(), $this->capabilities(), $this->staffAccounts(), $this->financialPeriodIdentity()
        );
    }

    public function staffConsumptionFoundation(): StaffConsumptionFoundationService
    {
        return $this->staffConsumptionFoundation ??= new StaffConsumptionFoundationService(
            $this->database(), $this->identityRepository(), $this->capabilities(), $this->personnel()
        );
    }

    public function staffConsumptionRepository(): StaffConsumptionRepository
    {
        return $this->staffConsumptionRepository ??= new StaffConsumptionRepository($this->database());
    }

    public function staffConsumptionPosting(): StaffConsumptionPostingService
    {
        return $this->staffConsumptionPosting ??= new StaffConsumptionPostingService(
            $this->database(), $this->staffConsumptionFoundation(), $this->staffConsumptionRepository(),
            $this->orderCatalog(), $this->staffBenefitCalculation(), $this->orders(), $this->staffAccountService(),
            $this->inventoryOrders(), $this->printing()
        );
    }

    public function staffConsumptionWorkspace(): StaffConsumptionWorkspaceService
    {
        return $this->staffConsumptionWorkspace ??= new StaffConsumptionWorkspaceService(
            $this->database(), $this->identityRepository(), $this->capabilities(), $this->personnel(),
            $this->staffConsumptionFoundation(), $this->orderCatalog(), $this->staffBenefitCalculation()
        );
    }

    public function staffConsumptionReports(): StaffConsumptionReportService
    {
        return $this->staffConsumptionReports ??= new StaffConsumptionReportService(
            $this->database(), $this->identityRepository(), $this->capabilities()
        );
    }

    public function globalSearch(): GlobalSearchService
    {
        return $this->globalSearch ??= new GlobalSearchService([
            new FinanceSearchProvider($this->database(), $this->auth()),
            new CatalogSearchProvider($this->database(), $this->auth()),
            new InventorySearchProvider($this->database(), $this->auth()),
            new SubscriberSearchProvider($this->database(), $this->auth()),
            new AdminSearchProvider($this->database()),
        ]);
    }

    public function sellables(): SellableRepository
    {
        return $this->sellables ??= new SellableRepository($this->database());
    }

    public function catalogAdmin(): CatalogAdminService
    {
        return $this->catalogAdmin ??= new CatalogAdminService($this->database(), $this->identityRepository());
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
        return $this->orders ??= new OrderCommitService($this->database(), $this->businessClock(), $this->orderCatalog(), $this->inventoryOrders(), $this->tax(), $this->printing());
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

    public function orderStaffActions(): OrderStaffActionService
    {
        return $this->orderStaffActions ??= new OrderStaffActionService(
            $this->database(),$this->identityRepository(),$this->capabilities(),$this->inventoryOrders(),$this->printing()
        );
    }

    public function waiterCallStaff(): WaiterCallStaffService
    {
        return $this->waiterCallStaff ??= new WaiterCallStaffService($this->database(),$this->identityRepository(),$this->capabilities());
    }

    public function orderWorkspace(): OrderWorkspaceService
    {
        return $this->orderWorkspace ??= new OrderWorkspaceService($this->database(),$this->identityRepository(),$this->capabilities(),$this->orderCatalog());
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
        return $this->inventoryDeferred ??= new InventoryDeferredAdapter($this->database(), $this->inventory(), $this->inventoryCounts(), $this->deferredReceipts());
    }

    public function tax(): TaxService
    {
        return $this->tax ??= new TaxService($this->database(), $this->identityRepository());
    }

    public function financialPeriodIdentity(): FinancialPeriodIdentityService
    {
        return $this->financialPeriodIdentity ??= new FinancialPeriodIdentityService($this->database());
    }

    public function financialPeriods(): FinancialPeriodService
    {
        return $this->financialPeriods ??= new FinancialPeriodService(
            $this->database(), $this->identityRepository(), $this->businessClock(), $this->financialPeriodIdentity()
        );
    }

    public function financeWorkspace(): FinanceWorkspaceService
    {
        return $this->financeWorkspace ??= new FinanceWorkspaceService($this->database());
    }

    public function settlements(): SettlementService
    {
        return $this->settlements ??= new SettlementService(
            $this->database(), $this->identityRepository(), $this->capabilities(), $this->businessClock(), $this->financialPeriods(), $this->tax(), $this->subscribers(), $this->printing()
        );
    }

    public function subscribers(): SubscriberService
    {
        return $this->subscribers ??= new SubscriberService($this->database(), $this->identityRepository());
    }

    public function subscriberAccounts(): SubscriberAccountService
    {
        return $this->subscriberAccounts ??= new SubscriberAccountService(
            $this->database(),$this->identityRepository(),$this->financialPeriodIdentity(),$this->businessClock(),$this->subscribers()
        );
    }

    public function accommodationTransport(): AccommodationTransport
    {
        return $this->accommodationTransport ??= new AccommodationTransport($this->config);
    }

    public function accommodation(): AccommodationService
    {
        return $this->accommodation ??= new AccommodationService(
            $this->database(), $this->identityRepository(), $this->capabilities(), $this->settlements(), $this->accommodationTransport()
        );
    }


    public function integrationWorkspace(): IntegrationWorkspaceService
    {
        return $this->integrationWorkspace ??= new IntegrationWorkspaceService($this->database(),$this->config);
    }

    public function printing(): PrintService
    {
        return $this->printing ??= new PrintService($this->database(), $this->identityRepository());
    }

    public function printManagement(): PrintManagementService
    {
        return $this->printManagement ??= new PrintManagementService($this->database());
    }

    public function printAgentV4Http(): PrintAgentV4HttpAdapter
    {
        return $this->printAgentV4Http ??= new PrintAgentV4HttpAdapter($this->printing());
    }

    public function runtimeTriggers(): RuntimeTriggerService
    {
        return $this->runtimeTriggers ??= new RuntimeTriggerService($this->database(), [
            'inventory.order_events'=>fn():array=>$this->inventoryOrders()->processPending(30),
            'maintenance.health'=>fn():array=>['ok'=>true,'checked_at'=>date(DATE_ATOM)],
            'public.projection_sync'=>fn():array=>$this->publicEdgePublisher()->syncAll(),
            'public.relay_sync'=>fn():array=>$this->publicEdgeRelay()->sync(),
        ]);
    }

    public function runtimeTriggerHttp(): RuntimeTriggerHttpAdapter
    {
        return $this->runtimeTriggerHttp ??= new RuntimeTriggerHttpAdapter(
            $this->runtimeTriggers(), $this->config->string('runtime.local_token','')
        );
    }

    public function financialPeriodClose(): FinancialPeriodCloseService
    {
        return $this->financialPeriodClose ??= new FinancialPeriodCloseService(
            $this->database(), $this->identityRepository(), $this->financialPeriodIdentity(), $this->financialPeriods()
        );
    }

    public function expenses(): ExpenseService
    {
        return $this->expenses ??= new ExpenseService($this->database(), $this->identityRepository(), $this->financialPeriodIdentity());
    }

    public function operationsWorkspace(): OperationsWorkspaceService
    {
        return $this->operationsWorkspace ??= new OperationsWorkspaceService($this->database(),$this->supply(),$this->expenses());
    }

    public function businessBackup(): BusinessBackupService
    {
        return $this->businessBackup ??= new BusinessBackupService(
            $this->database(),
            $this->observability(),
            $this->config->string('installation.id','')
        );
    }

    public function supportBundles(): SupportBundleWriter
    {
        return $this->supportBundles ??= new SupportBundleWriter($this->observability);
    }

    public function systemDiagnostics(): SystemDiagnosticsService
    {
        return $this->systemDiagnostics ??= new SystemDiagnosticsService(
            $this->database(),$this->config,$this->observability,$this->migrations(),$this->printManagement(),$this->supportBundles(),
            dirname(__DIR__,4),dirname(__DIR__,2),$this->publicEdgeSyncClient()
        );
    }

    public function localUpdates(): LocalUpdateService
    {
        return $this->localUpdates ??= new LocalUpdateService(
            $this->observability(),dirname(__DIR__,4),dirname(__DIR__,2),
            dirname(__DIR__,2).'/resources/compatibility-v1.json',dirname(__DIR__,2).'/resources/update-trust-v1.json'
        );
    }

    public function updateCenter(): ComponentUpdateCenterService
    {
        return $this->updateCenter ??= new ComponentUpdateCenterService(
            $this->systemDiagnostics(),$this->localUpdates(),dirname(__DIR__,2).'/resources/component-registry-v1.json'
        );
    }

    public function recoveryWorkspace(): RecoveryWorkspaceService
    {
        return $this->recoveryWorkspace ??= new RecoveryWorkspaceService($this->businessBackup(),$this->observability());
    }

    public function publicEdgeSyncClient(): PublicEdgeSyncClient
    {
        return $this->publicEdgeSyncClient ??= new PublicEdgeSyncClient($this->config);
    }

    public function publicProjectionBuilder(): PublicProjectionBuilder
    {
        return $this->publicProjectionBuilder ??= new PublicProjectionBuilder($this->database(),$this->settlements(),$this->supply());
    }

    public function publicEdgePublisher(): PublicEdgePublisherService
    {
        $v=@file_get_contents(dirname(__DIR__,4).'/VERSION.txt');
        return $this->publicEdgePublisher ??= new PublicEdgePublisherService($this->database(),$this->publicEdgeSyncClient(),$this->publicProjectionBuilder(),$this->observability(),is_string($v)&&trim($v)!==''?trim($v):'unknown');
    }

    public function publicEdgeRelay(): PublicEdgeRelayService
    {
        return $this->publicEdgeRelay ??= new PublicEdgeRelayService(
            $this->database(),$this->publicEdgeSyncClient(),$this->realtimeDispatch(),$this->deferredDispatch(),$this->observability()
        );
    }

    public function publicReenrollment(): PublicReenrollmentService
    {
        return $this->publicReenrollmentService ??= new PublicReenrollmentService($this->config,$this->publicEdgeSyncClient(),dirname(__DIR__,4).'/config.php');
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

    public function subscriberPaymentDeferred(): SubscriberPaymentDeferredAdapter
    {
        return $this->subscriberPaymentDeferred ??= new SubscriberPaymentDeferredAdapter(
            $this->database(), $this->identityRepository(), $this->capabilities(), $this->subscribers(),
            $this->financialPeriodIdentity(), $this->deferredReceipts()
        );
    }

    public function deferredDispatch(): DeferredDispatchService
    {
        return $this->deferredDispatch ??= new DeferredDispatchService(
            $this->supplyDeferred(), $this->inventoryDeferred(), $this->expenseDeferred(), $this->subscriberPaymentDeferred()
        );
    }

    public function settlementRealtime(): SettlementRealtimeAdapter
    {
        return $this->settlementRealtime ??= new SettlementRealtimeAdapter($this->identityRepository(), $this->settlements());
    }


    public function guestOrders(): GuestOrderService
    {
        return $this->guestOrders ??= new GuestOrderService($this->database(), $this->businessClock(), $this->orderCatalog(), $this->orders(), $this->tax());
    }

    public function waiterCalls(): WaiterCallService
    {
        return $this->waiterCalls ??= new WaiterCallService($this->database(), $this->businessClock());
    }

    public function guestOrderRealtime(): GuestOrderRealtimeAdapter
    {
        return $this->guestOrderRealtime ??= new GuestOrderRealtimeAdapter($this->guestOrders());
    }

    public function waiterCallRealtime(): WaiterCallRealtimeAdapter
    {
        return $this->waiterCallRealtime ??= new WaiterCallRealtimeAdapter($this->waiterCalls());
    }

    public function realtimeDispatch(): RealtimeDispatchService
    {
        return $this->realtimeDispatch ??= new RealtimeDispatchService(
            $this->guestOrderRealtime(), $this->waiterCallRealtime(), $this->settlementRealtime(),
            $this->preparationRealtime(), $this->tableDraftRealtime()
        );
    }
    public function startSession(string $cookiePath = '/', ?bool $secure = null): void
    {
        Session::start($this->config, $this->observability, $cookiePath, $secure);
    }
}
