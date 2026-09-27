<?php
declare(strict_types=1);

namespace Sokna\Local\Relay;

use Sokna\Local\Domain\Orders\GuestOrderException;
use Sokna\Local\Domain\Orders\GuestOrderService;

final class GuestOrderRealtimeAdapter
{
    private const KINDS=['guest_order.submit','guest_order.quote','guest_order.list','guest_order.status','guest_table.context','order.edit','order.cancel'];
    public function __construct(private readonly GuestOrderService $orders) {}
    public function dispatch(array $envelope): array
    {
        $kind=trim((string)($envelope['kind']??''));if(!in_array($kind,self::KINDS,true))throw new GuestOrderException('unsupported_kind','عملیات مهمان معتبر نیست.',422);
        $projection=trim((string)($envelope['actor_projection_id']??''));if(!preg_match('/^guest:[a-f0-9]{32}$/',$projection))throw new GuestOrderException('actor_invalid','هویت مهمان معتبر نیست.',403);
        $payload=is_array($envelope['payload']??null)?$envelope['payload']:[];
        return match($kind){
            'guest_order.submit'=>$this->orders->submit($payload),
            'guest_order.quote'=>$this->orders->quote($payload),
            'guest_order.list'=>$this->orders->list($payload),
            'guest_order.status'=>$this->orders->status($payload),
            'guest_table.context'=>$this->orders->tableContext($payload),
            'order.edit'=>$this->orders->mutate($payload+['action'=>'update']),
            'order.cancel'=>$this->orders->mutate($payload+['action'=>'cancel']),
        };
    }
}
