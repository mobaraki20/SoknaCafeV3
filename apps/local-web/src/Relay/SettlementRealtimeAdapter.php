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
        $kind=trim((string)($envelope['kind']??''));
        if(!in_array($kind,['settlement.commit','settlement.review','settlement.discount_preview','settlement.discount','settlement.receipt_reprint','settlement.followup_detach','settlement.followup_destination'],true))
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
        if($kind==='settlement.review')return $this->settlements->review($payload,$actor);
        if($kind==='settlement.discount_preview')return $this->settlements->previewDiscount((int)($payload['session_id']??0),array_key_exists('discount_type',$payload)&&$payload['discount_type']!==null?(string)$payload['discount_type']:null,(int)($payload['discount_value']??0),$actor);
        if($kind==='settlement.discount')return $this->settlements->setDiscount(
            (int)($payload['session_id']??0),
            array_key_exists('discount_type',$payload)&&$payload['discount_type']!==null?(string)$payload['discount_type']:null,
            (int)($payload['discount_value']??0),
            $actor
        );
        if($kind==='settlement.receipt_reprint')return $this->settlements->reprintReceipt(
            (int)($payload['settlement_id']??0),$requestId,$actor
        );
        if($kind==='settlement.followup_detach')return $this->settlements->detachForFollowup($payload,$actor);
        if($kind==='settlement.followup_destination')return $this->settlements->setFollowupDestination(
            (int)($payload['session_id']??0),(string)($payload['destination']??''),$actor
        );
        // Relay request_id is the canonical idempotency owner for remote settlement.
        $payload['request_id']=$requestId;
        return $this->settlements->settle($payload,$actor,true);
    }
}
