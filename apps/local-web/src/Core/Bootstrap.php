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
use Sokna\Local\Domain\Orders\OrderAcceptanceService;
use Sokna\Local\Domain\Orders\WaiterCallStaffService;
use Sokna\Local\Domain\Orders\OrderWorkspaceService;
use Sokna\Local\Domain\Orders\TableChangeService;
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
use Sokna\Local\Domain\Integrations\IntegrationSettingsService;
use Sokna\Local\Runtime\RuntimeTriggerService;
use Sokna\Local\Runtime\RuntimeHealthClient;
use Sokna\Local\Domain\Printing\PrintService;
use Sokna\Local\Domain\Printing\PrintManagementService;
use Sokna\Local\Domain\Printing\PrintTemplatePackageService;
use Sokna\Local\Http\PrintAgentV4HttpAdapter;
use Sokna\Local\Http\RuntimeTriggerHttpAdapter;
use Sokna\Local\Domain\Expenses\ExpenseService;
use Sokna\Local\Domain\Operations\OperationsWorkspaceService;
use Sokna\Local\Domain\Recovery\BusinessBackupService;
use Sokna\Local\Domain\System\SupportBundleWriter;
use Sokna\Local\Domain\System\SystemDiagnosticsService;
use Sokna\Local\Domain\System\MaintenanceSupervisorClient;
use Sokna\Local\Domain\System\WindowsServicesPairingService;
use Sokna\Local\Relay\ExpenseDeferredAdapter;
use Sokna\Local\Domain\Supply\SupplyAccessService;
use Sokna\Local\Domain\Supply\SupplyService;
use Sokna\Local\Relay\DeferredReceiptService;
use Sokna\Local\Relay\SupplyDeferredAdapter;
use Sokna\Local\Relay\SubscriberPaymentDeferredAdapter;
use Sokna\Local\Relay\DeferredDispatchService;
use Sokna\Local\Relay\SettlementRealtimeAdapter;
use Sokna\Local\Relay\GuestOrderRealtimeAdapter;
use Sokna\Local\Relay\HallStaffRealtimeAdapter;
use Sokna\Local\Relay\WaiterCallRealtimeAdapter;
use Sokna\Local\Relay\RealtimeDispatchService;
use Sokna\Local\Relay\StaffOrderRealtimeAdapter;
use Sokna\Local\Relay\NotificationPushRealtimeAdapter;
use Sokna\Local\Domain\Sellables\SellableRepository;
use Sokna\Local\Domain\Sellables\CatalogAdminService;
use Sokna\Local\Domain\Sellables\CategoryIconLibrary;
use Sokna\Local\Domain\Sellables\DefaultContentSeeder;
use Sokna\Local\Domain\Admin\AdminControlService;
use Sokna\Local\Domain\Account\AccountService;
use Sokna\Local\Domain\Account\UserUiPreferenceService;
use Sokna\Local\Domain\GuestContent\ThemePackageManager;
use Sokna\Local\Domain\GuestContent\GuestContentService;
use Sokna\Local\Domain\Marketing\MarketingService;
use Sokna\Local\Domain\Reporting\ReportingService;
use Sokna\Local\Domain\Notifications\NotificationService;
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
use Sokna\Local\Search\NavigationSearchProvider;

use Sokna\Local\Domain\Update\LocalUpdateService;
use Sokna\Local\Domain\Update\LocalUpdateUploadService;
use Sokna\Local\Domain\Update\ComponentUpdateCenterService;
use Sokna\Local\Domain\Recovery\RecoveryWorkspaceService;
use Sokna\Local\Domain\PublicEdge\RemoteCredentialService;
use Sokna\Local\Domain\PublicEdge\PublicEdgeSyncClient;
use Sokna\Local\Domain\PublicEdge\PublicProjectionBuilder;
use Sokna\Local\Domain\PublicEdge\PublicReenrollmentService;
use Sokna\Local\Domain\PublicEdge\PublicOriginRebindService;
use Sokna\Local\Domain\PublicEdge\PublicEdgePublisherService;
use Sokna\Local\Domain\PublicEdge\PublicAuthorityStateService;
use Sokna\Local\Domain\PublicEdge\PublicEdgeRelayService;
use Sokna\Local\Domain\LanStaff\LanStaffRealtimeReceiptService;
use Sokna\Local\Domain\LanStaff\LanStaffGatewayService;
final class Bootstrap
{
    private ?PDO $database = null;
    private ?IdentityRepository $identityRepository = null;
    private ?Capabilities $capabilities = null;
    private ?Auth $auth = null;
    private ?LoginAccessService $loginAccess = null;
    private ?RemoteCredentialService $remoteCredentials = null;
    private ?Migrations $migrations = null;
    private ?AdminControlService $adminControls = null;
    private ?AccountService $accountService = null;
    private ?UserUiPreferenceService $uiPreferenceService = null;
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
    private ?CategoryIconLibrary $categoryIcons = null;
    private ?DefaultContentSeeder $defaultContentSeeder = null;
    private ?TimeAuthority $timeAuthority = null;
    private ?BusinessClock $businessClock = null;
    private ?OrderCatalogService $orderCatalog = null;
    private ?OrderCommitService $orders = null;
    private ?StaffQuickOrderService $staffQuickOrders = null;
    private ?TableDraftService $tableDrafts = null;
    private ?OrderStaffActionService $orderStaffActions = null;
    private ?OrderAcceptanceService $orderAcceptance = null;
    private ?WaiterCallStaffService $waiterCallStaff = null;
    private ?OrderWorkspaceService $orderWorkspace = null;
    private ?TableChangeService $tableChanges = null;
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
    private ?SnapshotGuard $snapshotGuard = null;
    private ?SettlementService $settlements = null;
    private ?FinancialPeriodCloseService $financialPeriodClose = null;
    private ?SubscriberService $subscribers = null;
    private ?SubscriberAccountService $subscriberAccounts = null;
    private ?AccommodationTransport $accommodationTransport = null;
    private ?AccommodationService $accommodation = null;
    private ?IntegrationWorkspaceService $integrationWorkspace = null;
    private ?IntegrationSettingsService $integrationSettings = null;
    private ?RuntimeTriggerService $runtimeTriggers = null;
    private ?RuntimeHealthClient $runtimeHealthClient = null;
    private ?PrintService $printing = null;
    private ?PrintManagementService $printManagement = null;
    private ?PrintTemplatePackageService $printTemplates = null;
    private ?PrintAgentV4HttpAdapter $printAgentV4Http = null;
    private ?RuntimeTriggerHttpAdapter $runtimeTriggerHttp = null;
    private ?ExpenseService $expenses = null;
    private ?OperationsWorkspaceService $operationsWorkspace = null;
    private ?BusinessBackupService $businessBackup = null;
    private ?SupportBundleWriter $supportBundles = null;
    private ?SystemDiagnosticsService $systemDiagnostics = null;
    private ?MaintenanceSupervisorClient $maintenanceSupervisor = null;
    private ?WindowsServicesPairingService $windowsServicesPairing = null;
    private ?LocalUpdateService $localUpdates = null;
    private ?LocalUpdateUploadService $localUpdateUploads = null;
    private ?ComponentUpdateCenterService $updateCenter = null;
    private ?RecoveryWorkspaceService $recoveryWorkspace = null;
    private ?ThemePackageManager $themePackages = null;
    private ?GuestContentService $guestContent = null;
    private ?MarketingService $marketing = null;
    private ?ReportingService $reporting = null;
    private ?NotificationService $notifications = null;
    private ?PublicEdgeSyncClient $publicEdgeSyncClient = null;
    private ?PublicProjectionBuilder $publicProjectionBuilder = null;
    private ?PublicEdgePublisherService $publicEdgePublisher = null;
    private ?PublicEdgeRelayService $publicEdgeRelay = null;
    private ?PublicReenrollmentService $publicReenrollmentService = null;
    private ?PublicOriginRebindService $publicOriginRebindService = null;
    private ?PublicAuthorityStateService $publicAuthorityStateService = null;
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
    private ?StaffOrderRealtimeAdapter $staffOrderRealtime = null;
    private ?HallStaffRealtimeAdapter $hallStaffRealtime = null;
    private ?NotificationPushRealtimeAdapter $notificationPushRealtime = null;
    private ?LanStaffRealtimeReceiptService $lanStaffRealtimeReceipts = null;
    private ?LanStaffGatewayService $lanStaffGateway = null;

    private function __construct(
        private readonly Config $config,
        private readonly Observability $observability,
    ) {
    }

    public static function fromArray(array $values): self
    {
        $config = Config::fromArray($values);
        $timezone = \Sokna\Shared\IranTime::ZONE;
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

    public function snapshotGuard(): SnapshotGuard
    {
        return $this->snapshotGuard ??= new SnapshotGuard($this->observability);
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

    public function loginAccess(): LoginAccessService
    {
        return $this->loginAccess ??= new LoginAccessService($this->database());
    }


    public function remoteCredentials(): RemoteCredentialService
    {
        return $this->remoteCredentials ??= new RemoteCredentialService($this->database());
    }

    public function auth(): Auth
    {
        $lifetime = max(300, (int)$this->config->get('app.session_lifetime', Session::DEFAULT_LIFETIME));
        return $this->auth ??= new Auth($this->identityRepository(), $this->capabilities(), $lifetime, $this->loginAccess(), $this->remoteCredentials());
    }

    public function migrations(): Migrations
    {
        $directory = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations';
        return $this->migrations ??= new Migrations($this->database(), $directory);
    }

    public function adminControls(): AdminControlService
    {
        return $this->adminControls ??= new AdminControlService($this->database(), $this->identityRepository(), $this->snapshotGuard(), $this->remoteCredentials());
    }

    public function uiPreferences(): UserUiPreferenceService
    {
        return $this->uiPreferenceService ??= new UserUiPreferenceService($this->database());
    }

    public function account(): AccountService
    {
        return $this->accountService ??= new AccountService($this->database(), $this->remoteCredentials());
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
            new NavigationSearchProvider($this->auth()),
            new FinanceSearchProvider($this->database(), $this->auth()),
            new CatalogSearchProvider($this->database(), $this->auth()),
            new InventorySearchProvider($this->database(), $this->auth()),
            new SubscriberSearchProvider($this->database(), $this->auth()),
            new AdminSearchProvider($this->database()),
        ],$this->snapshotGuard());
    }

    public function sellables(): SellableRepository
    {
        return $this->sellables ??= new SellableRepository($this->database());
    }

    public function categoryIcons(): CategoryIconLibrary
    {
        return $this->categoryIcons ??= new CategoryIconLibrary(dirname(__DIR__,2).'/resources/default-content/v1/category-icons.json');
    }

    public function defaultContentSeeder(): DefaultContentSeeder
    {
        $mediaRoot=rtrim($this->config->requiredString('app.data_dir'), "\\/") . DIRECTORY_SEPARATOR . 'guest-media';
        return $this->defaultContentSeeder ??= new DefaultContentSeeder($this->database(),$this->categoryIcons(),dirname(__DIR__,2).'/resources/default-content/v1',$mediaRoot);
    }

    public function catalogAdmin(): CatalogAdminService
    {
        return $this->catalogAdmin ??= new CatalogAdminService($this->database(), $this->identityRepository(), $this->categoryIcons());
    }

    public function time(): TimeAuthority
    {
        return $this->timeAuthority ??= new TimeAuthority(\Sokna\Shared\IranTime::ZONE);
    }

    public function businessClock(): BusinessClock
    {
        return $this->businessClock ??= new BusinessClock($this->database(), $this->time()->timezone());
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
            $this->database(),$this->identityRepository(),$this->capabilities(),$this->inventoryOrders(),$this->printing(),$this->preparation()
        );
    }

    public function orderAcceptance(): OrderAcceptanceService
    {
        return $this->orderAcceptance ??= new OrderAcceptanceService($this->database(),$this->identityRepository(),$this->capabilities());
    }

    public function waiterCallStaff(): WaiterCallStaffService
    {
        return $this->waiterCallStaff ??= new WaiterCallStaffService($this->database(),$this->identityRepository(),$this->capabilities());
    }

    public function orderWorkspace(): OrderWorkspaceService
    {
        return $this->orderWorkspace ??= new OrderWorkspaceService($this->database(),$this->identityRepository(),$this->capabilities(),$this->orderCatalog());
    }

    public function tableChanges(): TableChangeService
    {
        return $this->tableChanges ??= new TableChangeService(
            $this->database(),$this->identityRepository(),$this->capabilities(),$this->observability()
        );
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
        return $this->financeWorkspace ??= new FinanceWorkspaceService($this->database(),$this->snapshotGuard());
    }

    public function settlements(): SettlementService
    {
        return $this->settlements ??= new SettlementService(
            $this->database(), $this->identityRepository(), $this->capabilities(), $this->businessClock(), $this->time(), $this->financialPeriods(), $this->tax(), $this->subscribers(), $this->printing()
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

    public function integrationSettings(): IntegrationSettingsService
    {
        return $this->integrationSettings ??= new IntegrationSettingsService($this->config,dirname(__DIR__,3).'/config.php',$this->publicEdgeSyncClient());
    }

    public function printing(): PrintService
    {
        return $this->printing ??= new PrintService($this->database(), $this->identityRepository(), $this->printTemplates());
    }

    public function printTemplates(): PrintTemplatePackageService
    {
        return $this->printTemplates ??= new PrintTemplatePackageService($this->database());
    }

    public function printManagement(): PrintManagementService
    {
        return $this->printManagement ??= new PrintManagementService($this->database(), $this->printTemplates());
    }

    public function printAgentV4Http(): PrintAgentV4HttpAdapter
    {
        return $this->printAgentV4Http ??= new PrintAgentV4HttpAdapter($this->printing());
    }

    public function runtimeTriggers(): RuntimeTriggerService
    {
        return $this->runtimeTriggers ??= new RuntimeTriggerService($this->database(), [
            // Compatibility fallback for installed Runtime services that loaded an older
            // trigger catalog before Local Web was upgraded. `inventory.order_events` is the
            // oldest always-present machine trigger, so opportunistically drain Public relay
            // here as well. Current Runtime 1.0.3 exposes the dedicated effective one-second public.relay_sync
            // lane in Runtime Health. When that lane is active this legacy fallback is skipped;
            // older runtimes without that proof retain the compatibility drain.
            'inventory.order_events'=>function():array{
                $inventory=$this->inventoryOrders()->processPending(30);
                $public=['attempted'=>false,'success'=>true,'heartbeat'=>null,'relay'=>null,'projection_events'=>null];
                try{
                    if($this->publicEdgeSyncClient()->configured()){
                        $runtimeProbe=$this->runtimeHealthClient()->probe();
                        $runtimeHealth=is_array($runtimeProbe['health']??null)?$runtimeProbe['health']:[];
                        $relayTrigger=is_array(($runtimeHealth['triggers']??[])['public.relay_sync']??null)?$runtimeHealth['triggers']['public.relay_sync']:[];
                        $dedicatedRelay=($runtimeProbe['status']??'')==='ok'&&(int)($relayTrigger['effective_interval_seconds']??0)===1;
                        if($dedicatedRelay){
                            // The fast realtime circuit can fail while the Public heartbeat
                            // endpoint remains healthy. Always keep this 15-second fallback
                            // independent from Public relay/projection backoff.
                            $public['attempted']=true;
                            $public['heartbeat']=$this->publicEdgePublisher()->syncHeartbeatIfDue(15,2);
                            $public['success']=($public['heartbeat']['success']??false)===true;
                            if(!$public['success'])$public['error_code']='independent_heartbeat_failed';
                            $public['skipped_reason']='dedicated_relay_only_heartbeat_independent';
                        }else{
                            $public['attempted']=true;
                            $public['heartbeat']=$this->publicEdgePublisher()->syncHeartbeat();
                            $public['relay']=$this->publicEdgeRelay()->sync();
                            $public['projection_events']=$this->publicEdgePublisher()->syncProjectionEvents();
                        }
                    }
                }catch(\Throwable $e){
                    $public['success']=false;
                    $public['error_code']=$e instanceof \Sokna\Local\Domain\PublicEdge\PublicEdgeSyncException?$e->errorCode:'public_relay_compat_failed';
                    $public['exception_class']=get_class($e);
                    $public['exception_fingerprint']=substr(hash('sha256',get_class($e).'|'.$e->getMessage()),0,20);
                    $this->observability()->logEvent('warning','public.relay_compat_failed',[
                        'trigger'=>'inventory.order_events','error_code'=>$public['error_code'],
                        'exception_class'=>$public['exception_class'],'exception_fingerprint'=>$public['exception_fingerprint'],
                    ]);
                }
                return ['inventory'=>$inventory,'public_compat'=>$public];
            },
            'maintenance.health'=>function():array{
                $receipts=$this->runtimeTriggers()->recoverAbandonedAccepted();
                return $this->systemDiagnostics()->maintain()+['runtime_receipts'=>$receipts];
            },
            'public.live_projection_sync'=>function(array $runtime=[]):array{
                $deadline=(int)($runtime['deadline_epoch_ms']??0);
                $publisher=$this->publicEdgePublisher();$permit=$publisher->transportLanePermit('live_projection');
                if(($permit['active']??false)===true)return ['success'=>false,'skipped'=>true,'error_code'=>'live_projection_circuit_open','reason'=>'circuit_open','breaker'=>$permit];
                try{
                    $projection=$publisher->syncLiveProjection($deadline>0?$deadline:null);
                    if(($projection['transport_unreachable']??false)===true){
                        $failure=(array)($projection['transport_failure']??[]);
                        throw new \Sokna\Local\Domain\PublicEdge\PublicEdgeSyncException((string)($failure['error_code']??'public_unreachable'),'ارسال وضعیت زنده کامل نشد.',(int)($failure['http_status']??503),(string)($failure['detail_code']??'transport_failure'),(string)($failure['failure_class']??'transport'),(int)($failure['retry_after_seconds']??0));
                    }
                    $publisher->clearTransportLaneFailure('live_projection');
                    return ['success'=>($projection['success']??false)===true,'projection'=>$projection];
                }catch(\Sokna\Local\Domain\PublicEdge\PublicEdgeSyncException $e){
                    $publisher->recordTransportLaneFailure('live_projection',$e);
                    return ['success'=>false,'error_code'=>$e->errorCode,'detail_code'=>$e->detailCode,'failure_class'=>$e->failureClass];
                }
            },
            'public.projection_sync'=>function(array $runtime=[]):array{
                $deadline=(int)($runtime['deadline_epoch_ms']??0);
                $hasBudget=static fn(int $reserve=75):bool=>$deadline<=0||($deadline-(int)floor(microtime(true)*1000))>$reserve;
                // H289: downstream Public outages are business-transport state, not Runtime
                // scheduler failures. Each transport lane has an independent circuit breaker.
                $out=['success'=>true,'control'=>null,'deferred'=>null,'projection'=>null];

                $controlPermit=$this->publicEdgePublisher()->transportLanePermit('control');
                if(($controlPermit['active']??false)===true){$out['control']=['success'=>true,'skipped'=>true,'reason'=>'circuit_open','breaker'=>$controlPermit];}
                else{
                    try{$out['control']=$this->publicEdgePublisher()->syncAutomaticProjection(3,$deadline>0?$deadline:null);$this->publicEdgePublisher()->clearTransportLaneFailure('control');}
                    catch(\Sokna\Local\Domain\PublicEdge\PublicEdgeSyncException $e){$this->publicEdgePublisher()->recordTransportLaneFailure('control',$e);$out['control']=['success'=>false,'error_code'=>$e->errorCode,'detail_code'=>$e->detailCode,'failure_class'=>$e->failureClass,'retry_after_seconds'=>$e->retryAfterSeconds];}
                }
                if(!$hasBudget())return array_replace($out,['success'=>false,'deadline_exhausted'=>true]);

                $deferredPermit=$this->publicEdgePublisher()->transportLanePermit('deferred');
                if(($deferredPermit['active']??false)===true){$out['deferred']=['success'=>true,'skipped'=>true,'reason'=>'circuit_open','breaker'=>$deferredPermit];}
                else{
                    try{$out['deferred']=$this->publicEdgeRelay()->syncDeferredOnly(10,50,3,$deadline>0?$deadline:null);$this->publicEdgePublisher()->clearTransportLaneFailure('deferred');}
                    catch(\Sokna\Local\Domain\PublicEdge\PublicEdgeSyncException $e){$this->publicEdgePublisher()->recordTransportLaneFailure('deferred',$e);$out['deferred']=['success'=>false,'error_code'=>$e->errorCode,'detail_code'=>$e->detailCode,'failure_class'=>$e->failureClass,'retry_after_seconds'=>$e->retryAfterSeconds];}
                }
                if(!$hasBudget())return array_replace($out,['success'=>false,'deadline_exhausted'=>true]);

                $projectionPermit=$this->publicEdgePublisher()->transportLanePermit('projection');
                if(($projectionPermit['active']??false)===true){$out['projection']=['success'=>true,'skipped'=>true,'reason'=>'circuit_open','breaker'=>$projectionPermit];}
                else{
                    try{
                        $out['projection_safety']=$this->publicEdgePublisher()->seedProjectionSafetyInvalidations(600,3);
                        $allowed=$this->publicEdgePublisher()->liveProjectionWorkerFresh()
                            ?array_values(array_diff($this->publicProjectionBuilder()->remoteModelKeys(),['operations','guest_operational','table_draft_context','preparation'])):null;
                        $out['projection']=$this->publicEdgePublisher()->syncProjectionModelsV2(250,4,4,$deadline>0?$deadline:null,$allowed);
                        if(($out['projection']['transport_unreachable']??false)===true){$tf=is_array($out['projection']['transport_failure']??null)?$out['projection']['transport_failure']:[];throw new \Sokna\Local\Domain\PublicEdge\PublicEdgeSyncException((string)($tf['error_code']??'public_unreachable'),'ارتباط replication با Public Edge برقرار نشد.',(int)($tf['http_status']??503),(string)($tf['detail_code']??'transport_failure'),(string)($tf['failure_class']??'transport'),(int)($tf['retry_after_seconds']??0));}
                        $this->publicEdgePublisher()->clearTransportLaneFailure('projection');
                    }catch(\Sokna\Local\Domain\PublicEdge\PublicEdgeSyncException $e){$this->publicEdgePublisher()->recordTransportLaneFailure('projection',$e);$out['projection']=['success'=>false,'error_code'=>$e->errorCode,'detail_code'=>$e->detailCode,'failure_class'=>$e->failureClass,'retry_after_seconds'=>$e->retryAfterSeconds];}
                }
                foreach(['control','deferred','projection'] as $lane)if(is_array($out[$lane])&&($out[$lane]['success']??true)===false)$out['success']=false;
                return $out;
            },
            'public.relay_sync'=>function(array $runtime=[]):array{
                $deadline=(int)($runtime['deadline_epoch_ms']??0);
                // H289 fast lane: one signed exchange request per tick. ACKs are durable and
                // returned on the next exchange; projection/deferred work can never block it.
                $permit=$this->publicEdgePublisher()->transportLanePermit('realtime');
                if(($permit['active']??false)===true)return ['success'=>true,'skipped'=>true,'reason'=>'circuit_open','breaker'=>$permit];
                try{
                    $relay=$this->publicEdgeRelay()->syncRealtimeExchange(8,3,$deadline>0?$deadline:null);
                    $this->publicEdgePublisher()->clearTransportLaneFailure('realtime');
                    return ['success'=>true,'relay'=>$relay];
                }catch(\Sokna\Local\Domain\PublicEdge\PublicEdgeSyncException $e){
                    $this->publicEdgePublisher()->recordTransportLaneFailure('realtime',$e);
                    // Expected WAN failure is contained here: Runtime scheduler stays healthy.
                    return ['success'=>false,'error_code'=>$e->errorCode,'detail_code'=>$e->detailCode,'failure_class'=>$e->failureClass,'retry_after_seconds'=>$e->retryAfterSeconds,'contained'=>true];
                }
            },
            'notifications.outbox'=>fn(array $runtime=[]):array=>$this->notifications()->processPending(100,(int)($runtime['deadline_epoch_ms']??0)>0?(int)$runtime['deadline_epoch_ms']:null),
        ],$this->observability());
    }

    public function runtimeTriggerHttp(): RuntimeTriggerHttpAdapter
    {
        return $this->runtimeTriggerHttp ??= new RuntimeTriggerHttpAdapter(
            $this->runtimeTriggers(), $this->config->string('runtime.local_token','')
        );
    }

    public function runtimeHealthClient(): RuntimeHealthClient
    {
        return $this->runtimeHealthClient ??= new RuntimeHealthClient($this->config->requiredString('app.data_dir'));
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
        return $this->operationsWorkspace ??= new OperationsWorkspaceService($this->database(),$this->supply(),$this->expenses(),$this->snapshotGuard(),$this->inventory());
    }

    public function businessBackup(): BusinessBackupService
    {
        return $this->businessBackup ??= new BusinessBackupService(
            $this->database(),
            $this->observability(),
            $this->config->string('installation.id',''),
            $this->publicAuthorityState(),
            $this->publicEdgeSyncClient()
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
            dirname(__DIR__,3),dirname(__DIR__,2),dirname(__DIR__,2).'/VERSION.txt',$this->publicEdgeSyncClient(),$this->runtimeHealthClient(),$this->time(),$this->businessClock(),$this->notifications(),$this->maintenanceSupervisor()
        );
    }

    public function maintenanceSupervisor(): MaintenanceSupervisorClient
    {
        return $this->maintenanceSupervisor ??= new MaintenanceSupervisorClient();
    }

    public function windowsServicesPairing(): WindowsServicesPairingService
    {
        return $this->windowsServicesPairing ??= new WindowsServicesPairingService(
            $this->config,
            $this->config->requiredString('app.data_dir'),
            fn(string $name,array $actor): array => $this->printing()->createAgent($name,$actor),
            fn(int $agentId,int $actorId,string $reason): array => $this->printing()->retireAgent($agentId,$actorId,$reason),
            fn(int $agentId,array $actor): array => $this->printing()->prepareAgentTokenRotation($agentId,$actor),
            fn(int $agentId,string $token,int $actorId): array => $this->printing()->commitAgentTokenRotation($agentId,$token,$actorId),
            fn(): array => $this->printing()->activeAgentSummaries(),
        );
    }

    public function localUpdates(): LocalUpdateService
    {
        return $this->localUpdates ??= new LocalUpdateService(
            $this->observability(),$this->migrations(),dirname(__DIR__,2),dirname(__DIR__,2),
            dirname(__DIR__,2).'/resources/compatibility-v1.json',dirname(__DIR__,2).'/resources/update-trust-v1.json'
        );
    }

    public function localUpdateUploads(): LocalUpdateUploadService
    {
        return $this->localUpdateUploads ??= new LocalUpdateUploadService(
            $this->localUpdates()->incomingDir(),
            fn(string $path,int $actorId): array => $this->localUpdates()->stageUploadedZip($path,$actorId)
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

    public function themePackages(): ThemePackageManager
    {
        return $this->themePackages ??= new ThemePackageManager(dirname(__DIR__,2).'/resources/guest-themes');
    }

    public function guestContent(): GuestContentService
    {
        $root = rtrim($this->config->requiredString('app.data_dir'), "\\/") . DIRECTORY_SEPARATOR . 'guest-media';
        return $this->guestContent ??= new GuestContentService($this->database(),$this->identityRepository(),$this->themePackages(),$root);
    }

    public function marketing(): MarketingService
    {
        return $this->marketing ??= new MarketingService($this->database());
    }

    public function reporting(): ReportingService
    {
        return $this->reporting ??= new ReportingService($this->database(),$this->time());
    }

    public function notifications(): NotificationService
    {
        return $this->notifications ??= new NotificationService($this->database(),$this->config);
    }

    public function publicAuthorityState(): PublicAuthorityStateService
    {
        return $this->publicAuthorityStateService ??= new PublicAuthorityStateService($this->database(),$this->config->string('installation.id',''));
    }

    public function publicEdgeSyncClient(): PublicEdgeSyncClient
    {
        return $this->publicEdgeSyncClient ??= new PublicEdgeSyncClient($this->config, null, $this->observability(),$this->publicAuthorityState());
    }

    public function publicProjectionBuilder(): PublicProjectionBuilder
    {
        return $this->publicProjectionBuilder ??= new PublicProjectionBuilder($this->database(),$this->settlements(),$this->supply(),$this->guestContent(),$this->marketing(),$this->reporting(),$this->notifications(),$this->categoryIcons(),$this->orderCatalog(),$this->preparation(),$this->orderWorkspace());
    }

    public function publicEdgePublisher(): PublicEdgePublisherService
    {
        $v=@file_get_contents(dirname(__DIR__,2).'/VERSION.txt');
        return $this->publicEdgePublisher ??= new PublicEdgePublisherService($this->database(),$this->publicEdgeSyncClient(),$this->publicProjectionBuilder(),$this->observability(),$this->publicAuthorityState(),$this->config,is_string($v)&&trim($v)!==''?trim($v):'unknown');
    }

    public function publicEdgeRelay(): PublicEdgeRelayService
    {
        return $this->publicEdgeRelay ??= new PublicEdgeRelayService(
            $this->database(),$this->publicEdgeSyncClient(),$this->realtimeDispatch(),$this->deferredDispatch(),$this->observability(),$this->lanStaffRealtimeReceipts(),$this->publicEdgePublisher()
        );
    }

    public function publicReenrollment(): PublicReenrollmentService
    {
        return $this->publicReenrollmentService ??= new PublicReenrollmentService($this->config,$this->publicEdgeSyncClient(),$this->publicAuthorityState(),dirname(__DIR__,3).'/config.php');
    }

    public function publicOriginRebind(): PublicOriginRebindService
    {
        return $this->publicOriginRebindService ??= new PublicOriginRebindService($this->config,$this->publicEdgeSyncClient(),$this->publicAuthorityState(),dirname(__DIR__,3).'/config.php',$this->notifications());
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

    public function dailyDeferred(): \Sokna\Local\Relay\DailyOperationsDeferredAdapter
    {
        return new \Sokna\Local\Relay\DailyOperationsDeferredAdapter($this->database(),$this->operationsCommands(),new \Sokna\Local\Relay\DeferredReceiptService($this->database()),$this->inventory());
    }

    public function deferredDispatch(): DeferredDispatchService
    {
        return $this->deferredDispatch ??= new DeferredDispatchService(
            $this->supplyDeferred(), $this->inventoryDeferred(), $this->expenseDeferred(), $this->subscriberPaymentDeferred(), new \Sokna\Local\Relay\DailyOperationsDeferredAdapter($this->database(),$this->operationsCommands(),new \Sokna\Local\Relay\DeferredReceiptService($this->database()),$this->inventory())
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

    public function staffOrderRealtime(): StaffOrderRealtimeAdapter
    {
        return $this->staffOrderRealtime ??= new StaffOrderRealtimeAdapter($this->identityRepository(), $this->orderStaffActions());
    }

    public function hallStaffRealtime(): HallStaffRealtimeAdapter
    {
        return $this->hallStaffRealtime ??= new HallStaffRealtimeAdapter(
            $this->identityRepository(), $this->waiterCallStaff(), $this->tableChanges(), $this->orderStaffActions()
        );
    }

    public function notificationPushRealtime(): NotificationPushRealtimeAdapter
    {
        return $this->notificationPushRealtime ??= new NotificationPushRealtimeAdapter($this->identityRepository(),$this->capabilities(),$this->notifications());
    }

    public function operationsCommands(): \Sokna\Local\Relay\OperationsRealtimeAdapter
    {
        return new \Sokna\Local\Relay\OperationsRealtimeAdapter($this->database(),$this->inventory(),$this->inventoryCounts(),$this->supply(),$this->operationsWorkspace(),$this->expenses());
    }

    public function realtimeDispatch(): RealtimeDispatchService
    {
        return $this->realtimeDispatch ??= new RealtimeDispatchService(
            $this->guestOrderRealtime(), $this->waiterCallRealtime(), $this->settlementRealtime(),
            $this->preparationRealtime(), $this->tableDraftRealtime(), $this->staffOrderRealtime(),
            $this->hallStaffRealtime(), $this->notificationPushRealtime(), $this->operationsCommands()
        );
    }
    public function lanStaffRealtimeReceipts(): LanStaffRealtimeReceiptService
    {
        return $this->lanStaffRealtimeReceipts ??= new LanStaffRealtimeReceiptService($this->database());
    }

    public function lanStaffGateway(): LanStaffGatewayService
    {
        return $this->lanStaffGateway ??= new LanStaffGatewayService(
            $this->config,$this->database(),$this->publicProjectionBuilder(),$this->realtimeDispatch(),$this->deferredDispatch(),$this->lanStaffRealtimeReceipts()
        );
    }

    public function startSession(?string $cookiePath = null, ?bool $secure = null): void
    {
        Session::start($this->config, $this->observability, $cookiePath, $secure);
    }
}
