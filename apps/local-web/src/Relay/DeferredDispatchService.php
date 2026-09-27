<?php
declare(strict_types=1);

namespace Sokna\Local\Relay;

use Sokna\Local\Domain\Integrations\IntegrationException;

final class DeferredDispatchService
{
    public function __construct(
        private readonly SupplyDeferredAdapter $supply,
        private readonly InventoryDeferredAdapter $inventory,
        private readonly ExpenseDeferredAdapter $expenses,
        private readonly SubscriberPaymentDeferredAdapter $subscriberPayments,
    ) {}

    public function dispatch(string $installationId,array $envelope): array
    {
        return match(trim((string)($envelope['kind']??''))){
            'supply.need.create','supply.status.prepare','supply.status.return','supply.receipt'=>$this->supply->dispatch($installationId,$envelope),
            'inventory.waste','inventory.count_draft'=>$this->inventory->dispatch($installationId,$envelope),
            'expense.create'=>$this->expenses->dispatch($installationId,$envelope),
            'subscriber.payment'=>$this->subscriberPayments->dispatch($installationId,$envelope),
            default=>throw new IntegrationException('unsupported_kind','نوع کار Deferred پشتیبانی نمی‌شود.',422),
        };
    }
}
