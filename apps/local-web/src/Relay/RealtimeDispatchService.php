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
        private readonly StaffOrderRealtimeAdapter $staffOrders,
        private readonly HallStaffRealtimeAdapter $hallStaff,
        private readonly NotificationPushRealtimeAdapter $notificationPush,
        private readonly OperationsRealtimeAdapter $operations,
    ) {}

    public function isMutation(array $envelope): bool
    {
        $kind=trim((string)($envelope['kind']??''));
        return in_array($kind,[
            'guest_order.submit','guest_table.context','order.edit','order.cancel',
            'waiter_call.create','waiter_call.cancel',
            'settlement.commit','settlement.discount','settlement.receipt_reprint','settlement.followup_detach','settlement.followup_destination',
            'order.staff_status','hall.waiter_status','hall.table_move','hall.order_reprint','hall.order_item_adjust',
            'operations.mutate','preparation.mutate','notification.push.subscribe','notification.push.unsubscribe','notification.preferences.save',
            'table_draft.create','table_draft.edit','table_draft.finalize','table_draft.finalize_atomic','table_draft.cancel',
        ],true);
    }

    public function dispatch(array $envelope): array
    {
        $kind=trim((string)($envelope['kind']??''));
        if(in_array($kind,['operations.mutate','operations.query'],true)){$envelope['operations_remote']=true;return $this->operations->dispatch($envelope);}
        if($kind==='diagnostics.ping'){
            $payload=is_array($envelope['payload']??null)?$envelope['payload']:[];
            $probeId=trim((string)($payload['probe_id']??''));
            if($probeId===''||!hash_equals($probeId,trim((string)($envelope['request_id']??''))))throw new RuntimeException('Diagnostic relay probe identity is invalid.');
            return [
                'success'=>true,
                'kind'=>'diagnostics.ping',
                'probe_id'=>$probeId,
                'correlation_id'=>trim((string)($payload['correlation_id']??'')),
                'local_received_at'=>gmdate('c'),
            ];
        }
        if(in_array($kind,['guest_order.submit','guest_order.quote','guest_order.list','guest_order.status','guest_table.context','order.edit','order.cancel'],true))return $this->guestOrders->dispatch($envelope);
        if(in_array($kind,['waiter_call.create','waiter_call.status','waiter_call.cancel'],true))return $this->waiterCalls->dispatch($envelope);
        if(in_array($kind,['settlement.commit','settlement.review','settlement.discount_preview','settlement.discount','settlement.receipt_reprint','settlement.followup_detach','settlement.followup_destination'],true))return $this->settlement->dispatch($envelope);
        if($kind==='order.staff_status')return $this->staffOrders->dispatch($envelope);
        if(str_starts_with($kind,'hall.'))return $this->hallStaff->dispatch($envelope);
        if($kind==='preparation.mutate')return $this->preparation->dispatch($envelope);
        if(in_array($kind,['notification.push.subscribe','notification.push.unsubscribe','notification.preferences.save'],true))return $this->notificationPush->dispatch($envelope);
        if(str_starts_with($kind,'table_draft.'))return $this->tableDrafts->dispatch($envelope);
        throw new RuntimeException('Realtime operation is not owned by Local dispatcher.');
    }
}
