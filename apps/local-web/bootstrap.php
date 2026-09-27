<?php
declare(strict_types=1);

use Sokna\Local\Core\Bootstrap;

require_once __DIR__ . '/src/Core/Config.php';
require_once __DIR__ . '/src/Core/Database.php';
require_once __DIR__ . '/src/Core/Observability.php';
require_once __DIR__ . '/src/Core/IdentityRepository.php';
require_once __DIR__ . '/src/Core/PdoIdentityRepository.php';
require_once __DIR__ . '/src/Core/Capabilities.php';
require_once __DIR__ . '/src/Core/Session.php';
require_once __DIR__ . '/src/Core/Auth.php';
require_once __DIR__ . '/src/Core/Migrations.php';
require_once __DIR__ . '/src/Domain/Sellables/SellableKind.php';
require_once __DIR__ . '/src/Domain/Sellables/SellableRepository.php';
require_once __DIR__ . '/src/Domain/Orders/BusinessClock.php';
require_once __DIR__ . '/src/Domain/Orders/OrderCommitException.php';
require_once __DIR__ . '/src/Domain/Orders/OrderCatalogService.php';
require_once __DIR__ . '/src/Domain/Orders/OrderCommitService.php';
require_once __DIR__ . '/src/Domain/Orders/StaffQuickOrderException.php';
require_once __DIR__ . '/src/Domain/Orders/StaffQuickOrderService.php';
require_once __DIR__ . '/src/Domain/Orders/TableDraftException.php';
require_once __DIR__ . '/src/Domain/Orders/TableDraftService.php';
require_once __DIR__ . '/src/Relay/TableDraftRealtimeAdapter.php';
require_once __DIR__ . '/src/Domain/Preparation/PreparationAccessService.php';
require_once __DIR__ . '/src/Domain/Preparation/PreparationException.php';
require_once __DIR__ . '/src/Domain/Preparation/PreparationService.php';
require_once __DIR__ . '/src/Relay/PreparationRealtimeAdapter.php';
require_once __DIR__ . '/src/Domain/Inventory/InventoryException.php';
require_once __DIR__ . '/src/Domain/Inventory/InventoryService.php';
require_once __DIR__ . '/src/Domain/Inventory/InventoryCountService.php';
require_once __DIR__ . '/src/Domain/Inventory/InventoryOrderService.php';
require_once __DIR__ . '/src/Relay/InventoryDeferredAdapter.php';
require_once __DIR__ . '/src/Domain/Tax/TaxException.php';
require_once __DIR__ . '/src/Domain/Tax/TaxService.php';
require_once __DIR__ . '/src/Domain/Expenses/ExpenseException.php';
require_once __DIR__ . '/src/Domain/Finance/FinancialPeriodException.php';
require_once __DIR__ . '/src/Domain/Finance/FinancialPeriodIdentityService.php';
require_once __DIR__ . '/src/Domain/Finance/FinancialPeriodService.php';
require_once __DIR__ . '/src/Domain/Integrations/IntegrationException.php';
require_once __DIR__ . '/src/Domain/Integrations/SubscriberService.php';
require_once __DIR__ . '/src/Domain/Integrations/AccommodationTransport.php';
require_once __DIR__ . '/src/Domain/Integrations/CenterIntegrationService.php';
require_once __DIR__ . '/src/Domain/Finance/SettlementException.php';
require_once __DIR__ . '/src/Domain/Finance/SettlementService.php';
require_once __DIR__ . '/src/Domain/Integrations/AccommodationService.php';
require_once __DIR__ . '/src/Runtime/RuntimeTriggerException.php';
require_once __DIR__ . '/src/Runtime/RuntimeTriggerService.php';
require_once __DIR__ . '/src/Domain/Printing/PrintException.php';
require_once __DIR__ . '/src/Domain/Printing/PrintService.php';
require_once __DIR__ . '/src/Http/PrintAgentV4HttpAdapter.php';
require_once __DIR__ . '/src/Http/RuntimeTriggerHttpAdapter.php';
require_once __DIR__ . '/src/Domain/Finance/FinancialPeriodCloseService.php';
require_once __DIR__ . '/src/Domain/Expenses/ExpenseService.php';
require_once __DIR__ . '/src/Domain/Recovery/RecoveryException.php';
require_once __DIR__ . '/src/Domain/Recovery/BusinessBackupService.php';
require_once __DIR__ . '/src/Relay/ExpenseDeferredAdapter.php';
require_once __DIR__ . '/src/Domain/Supply/SupplyException.php';
require_once __DIR__ . '/src/Domain/Supply/SupplyAccessService.php';
require_once __DIR__ . '/src/Domain/Supply/SupplyService.php';
require_once __DIR__ . '/src/Relay/DeferredReceiptService.php';
require_once __DIR__ . '/src/Relay/SupplyDeferredAdapter.php';
require_once __DIR__ . '/src/Relay/SubscriberPaymentDeferredAdapter.php';
require_once __DIR__ . '/src/Relay/DeferredDispatchService.php';
require_once __DIR__ . '/src/Relay/SettlementRealtimeAdapter.php';
require_once __DIR__ . '/src/Core/Bootstrap.php';

function sokna_local_bootstrap(array $config): Bootstrap
{
    return Bootstrap::fromArray($config);
}
