<?php
declare(strict_types=1);

namespace Sokna\Local\Relay;

use RuntimeException;

final class RealtimeDispatchService
{
    public function __construct(
        private readonly GuestOrderRealtimeAdapter $guestOrders,
        private readonly WaiterCallRealtimeAdapter $waiterCalls,
        private readonly SettlementRealtimeAdapter $settlement,
        private readonly PreparationRealtimeAdapter $preparation,
        private readonly TableDraftRealtimeAdapter $tableDrafts,
    ) {}

    public function dispatch(array $envelope): array
    {
        $kind=trim((string)($envelope['kind']??''));
        if(in_array($kind,['guest_order.submit','guest_order.quote','guest_order.list','guest_order.status','guest_table.context','order.edit','order.cancel'],true))return $this->guestOrders->dispatch($envelope);
        if(in_array($kind,['waiter_call.create','waiter_call.status','waiter_call.cancel'],true))return $this->waiterCalls->dispatch($envelope);
        if($kind==='settlement.commit')return $this->settlement->dispatch($envelope);
        if($kind==='preparation.mutate')return $this->preparation->dispatch($envelope);
        if(str_starts_with($kind,'table_draft.'))return $this->tableDrafts->dispatch($envelope);
        throw new RuntimeException('Realtime operation is not owned by Local dispatcher.');
    }
}
