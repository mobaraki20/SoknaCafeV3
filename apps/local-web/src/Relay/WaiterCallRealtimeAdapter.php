<?php
declare(strict_types=1);

namespace Sokna\Local\Relay;

use Sokna\Local\Domain\Orders\WaiterCallException;
use Sokna\Local\Domain\Orders\WaiterCallService;

final class WaiterCallRealtimeAdapter
{
    private const KINDS=['waiter_call.create','waiter_call.status','waiter_call.cancel'];
    public function __construct(private readonly WaiterCallService $calls) {}
    public function dispatch(array $envelope): array
    {
        $kind=trim((string)($envelope['kind']??''));if(!in_array($kind,self::KINDS,true))throw new WaiterCallException('unsupported_kind','عملیات فراخوان معتبر نیست.',422);
        $projection=trim((string)($envelope['actor_projection_id']??''));if(!preg_match('/^guest:[a-f0-9]{32}$/',$projection))throw new WaiterCallException('actor_invalid','هویت مهمان معتبر نیست.',403);
        $payload=is_array($envelope['payload']??null)?$envelope['payload']:[];
        return match($kind){'waiter_call.create'=>$this->calls->create($payload),'waiter_call.status'=>$this->calls->status($payload),'waiter_call.cancel'=>$this->calls->cancel($payload)};
    }
}
