<?php
declare(strict_types=1);

namespace Sokna\Local\Relay;

use Sokna\Local\Core\IdentityRepository;
use Sokna\Local\Domain\Orders\OrderStaffActionException;
use Sokna\Local\Domain\Orders\OrderStaffActionService;
use Sokna\Local\Domain\Orders\TableChangeService;
use Sokna\Local\Domain\Orders\WaiterCallStaffService;

/**
 * Canonical Local authority for Staff Hall mutations relayed from Public Edge.
 * Public only transports intent; Local resolves the actor and applies the same
 * domain services/capability checks used by the Local operator API.
 */
final class HallStaffRealtimeAdapter
{
    public function __construct(
        private readonly IdentityRepository $identity,
        private readonly WaiterCallStaffService $waiterCalls,
        private readonly TableChangeService $tableChanges,
        private readonly OrderStaffActionService $orderActions,
    ) {}

    public function dispatch(array $envelope): array
    {
        $kind=trim((string)($envelope['kind']??''));
        if(!in_array($kind,['hall.waiter_status','hall.table_move','hall.order_reprint','hall.order_item_adjust'],true))
            throw new OrderStaffActionException('unsupported_kind','عملیات سالن راه‌دور معتبر نیست.',422);

        $projection=trim((string)($envelope['actor_projection_id']??''));
        if(!preg_match('/^user:(\d+)$/',$projection,$match))
            throw new OrderStaffActionException('actor_invalid','هویت کاربر راه‌دور معتبر نیست.',403);
        $actor=$this->identity->findActiveById((int)$match[1]);
        if($actor===null)throw new OrderStaffActionException('actor_invalid','حساب کاربری راه‌دور فعال نیست.',403);

        $payload=is_array($envelope['payload']??null)?$envelope['payload']:[];
        return match($kind){
            'hall.waiter_status'=>$this->waiterCalls->changeStatus($payload,$actor),
            'hall.table_move'=>$this->tableChanges->move($payload,$actor),
            'hall.order_reprint'=>$this->orderActions->reprintPreparation($payload,$actor),
            'hall.order_item_adjust'=>$this->orderActions->adjustItemQuantity($payload,$actor),
        };
    }
}
