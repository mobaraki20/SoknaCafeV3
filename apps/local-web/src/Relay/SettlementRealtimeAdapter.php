<?php
declare(strict_types=1);

namespace Sokna\Local\Relay;

use Sokna\Local\Core\IdentityRepository;
use Sokna\Local\Domain\Finance\SettlementException;
use Sokna\Local\Domain\Finance\SettlementService;

final class SettlementRealtimeAdapter
{
    public function __construct(
        private readonly IdentityRepository $identity,
        private readonly SettlementService $settlements,
    ) {}

    public function dispatch(array $envelope): array
    {
        if(trim((string)($envelope['kind']??''))!=='settlement.commit')
            throw new SettlementException('unsupported_kind','عملیات تسویه معتبر نیست.',422);

        $projection=trim((string)($envelope['actor_projection_id']??''));
        if(!preg_match('/^user:(\d+)$/',$projection,$match))
            throw new SettlementException('actor_invalid','هویت کاربر راه‌دور معتبر نیست.',403);
        $actor=$this->identity->findActiveById((int)$match[1]);
        if($actor===null)throw new SettlementException('actor_invalid','حساب کاربری راه‌دور فعال نیست.',403);

        $requestId=trim((string)($envelope['request_id']??''));
        if($requestId===''||strlen($requestId)>96||preg_match('/^[A-Za-z0-9._:-]+$/',$requestId)!==1)
            throw new SettlementException('invalid_request_id','شناسه درخواست تسویه معتبر نیست.',422);

        $payload=is_array($envelope['payload']??null)?$envelope['payload']:[];
        // Relay request_id is the canonical idempotency owner for remote settlement.
        $payload['request_id']=$requestId;
        return $this->settlements->settle($payload,$actor);
    }
}
