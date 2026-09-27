<?php
declare(strict_types=1);

namespace Sokna\Local\Relay;

use DateTimeImmutable;
use PDO;
use Sokna\Local\Core\Capabilities;
use Sokna\Local\Core\IdentityRepository;
use Sokna\Local\Domain\Finance\FinancialPeriodIdentityService;
use Sokna\Local\Domain\Integrations\IntegrationException;
use Sokna\Local\Domain\Integrations\SubscriberService;
use Throwable;

final class SubscriberPaymentDeferredAdapter
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly IdentityRepository $identity,
        private readonly Capabilities $capabilities,
        private readonly SubscriberService $subscribers,
        private readonly FinancialPeriodIdentityService $periods,
        private readonly DeferredReceiptService $receipts,
    ) {}

    public function dispatch(string $installationId,array $envelope): array
    {
        $installationId=trim($installationId);
        $errors=$this->validateEnvelope($envelope);
        if($installationId===''||$errors!==[])
            return ['state'=>'rejected','result'=>[],'error_code'=>'invalid_envelope','fields'=>$errors,'idempotent'=>false];

        $this->pdo->beginTransaction();
        try{
            $replay=$this->receipts->replayTx($installationId,$envelope);
            if($replay!==null){$this->pdo->commit();return $replay;}

            $actor=$this->actor((string)$envelope['actor_projection_id']);
            if($actor===null){$this->pdo->rollBack();return ['state'=>'rejected','result'=>[],'error_code'=>'actor_invalid','idempotent'=>false];}
            if((string)($actor['role']??'')!=='admin'&&!$this->capabilities->has('cashier_accounts',$actor)){
                $out=$this->receipts->rejectedTx($installationId,$envelope,$actor,'permission_denied');
                $this->pdo->commit();return $out;
            }

            $occurred=$this->normalizeOccurredAt((string)$envelope['occurred_at']);
            $period=$this->periods->forDateTx(substr($occurred,0,10),(int)$actor['id']);
            if((string)$period['status']==='closed'){
                $out=$this->receipts->reviewTx(
                    $installationId,$envelope,$actor,
                    'closed_financial_period',
                    'این پرداخت مربوط به یک دوره مالی بسته است و باید صریحاً بررسی شود.',
                    'late_correction',(int)$period['id']
                );
                $this->pdo->commit();return $out;
            }

            try{
                $result=$this->applyTx($envelope,$actor,(int)$period['id'],false);
                $out=$this->receipts->committedTx($installationId,$envelope,$actor,$result,(int)$period['id']);
            }catch(IntegrationException $e){
                // Historical deferred subscriber payments route mutable balance conflicts to review,
                // rather than silently rejecting a payment that may need manager reconciliation.
                $out=$this->receipts->reviewTx(
                    $installationId,$envelope,$actor,
                    $e->errorCode==='balance_underflow'?'subscriber_payment_review':$e->errorCode,
                    $e->getMessage(),'conflict',(int)$period['id']
                );
            }
            $this->pdo->commit();return $out;
        }catch(Throwable $e){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $e;
        }
    }

    public function resolveReview(int $reviewId,string $decision,string $reason,array $reviewer): array
    {
        if(!in_array($decision,['approve','reject'],true))
            throw new IntegrationException('invalid_decision','تصمیم بررسی معتبر نیست.',422);
        $reason=self::clip(trim($reason),500);
        if($reason==='')throw new IntegrationException('reason_required','دلیل تصمیم را ثبت کن.',422);

        $this->pdo->beginTransaction();
        try{
            $manager=$this->identity->findActiveById((int)($reviewer['id']??0));
            if($manager===null||(string)($manager['role']??'')!=='admin')
                throw new IntegrationException('forbidden','فقط مدیر فعال می‌تواند پرداخت Deferred را تعیین تکلیف کند.',403);

            $review=$this->receipts->reviewLockedTx($reviewId);
            if($review===null)throw new IntegrationException('review_not_found','مورد بررسی پیدا نشد.',404);
            if((string)$review['state']!=='pending'){
                $this->pdo->commit();return ['state'=>(string)$review['state'],'idempotent'=>true];
            }
            if((string)$review['kind']!=='subscriber.payment')
                throw new IntegrationException('wrong_review_owner','این مورد بررسی متعلق به حساب مشتری نیست.',409);

            if($decision==='reject'){
                $out=$this->receipts->rejectReviewTx($review,(int)$manager['id'],$reason);
                $this->pdo->commit();return $out;
            }

            $envelope=json_decode((string)$review['envelope_json'],true);
            if(!is_array($envelope))throw new IntegrationException('invalid_envelope','اطلاعات رخداد Deferred قابل بازیابی نیست.',500);
            $actor=$this->actor((string)$review['actor_projection_id']);
            if($actor===null)throw new IntegrationException('actor_invalid','حساب کاربر اصلی دیگر فعال نیست.',409);
            $periodId=(int)($review['financial_period_id']??0);
            if($periodId<1)throw new IntegrationException('period_not_found','دوره مالی رخداد مشخص نیست.',409);

            $result=$this->applyTx($envelope,$actor,$periodId,true);
            $out=$this->receipts->approveReviewTx($review,(int)$manager['id'],$reason,$result);
            $this->pdo->commit();return $out;
        }catch(Throwable $e){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $e;
        }
    }

    private function applyTx(array $envelope,array $actor,int $periodId,bool $allowConflict): array
    {
        $payload=is_array($envelope['payload']??null)?$envelope['payload']:[];
        $subscriberId=(int)($payload['subscriber_id']??0);$amount=(int)($payload['amount']??0);
        if($subscriberId<1||$amount<1)throw new IntegrationException('invalid_payment','اطلاعات پرداخت مشتری کامل نیست.',422);

        $current=$this->subscribers->balanceTx($subscriberId);
        if(!$allowConflict&&array_key_exists('expected_balance',$payload)&&$current!==(int)$payload['expected_balance'])
            throw new IntegrationException('subscriber_balance_changed','مانده حساب مشتری بعد از نسخه راه‌دور تغییر کرده است.',409,['current_balance'=>$current]);

        $entry=$this->subscribers->insertLedgerTx(
            $subscriberId,'payment',-$amount,(int)$actor['id'],$periodId,
            null,null,
            self::clip(trim((string)($payload['reference']??'')),120),
            self::clip(trim((string)($payload['reason']??'پرداخت ثبت‌شده در حالت راه‌دور')),300),
            null,'deferred:subscriber-payment:'.(string)$envelope['request_id']
        );
        return ['ledger_entry_id'=>(int)$entry['id'],'balance_after'=>(int)$entry['balance_after']];
    }

    private function actor(string $projectionId): ?array
    {
        if(!preg_match('/^user:(\d+)$/',trim($projectionId),$m))return null;
        return $this->identity->findActiveById((int)$m[1]);
    }

    private function validateEnvelope(array $envelope): array
    {
        $errors=[];$requestId=trim((string)($envelope['request_id']??''));
        if($requestId===''||strlen($requestId)>96||preg_match('/^[A-Za-z0-9._:-]+$/',$requestId)!==1)$errors[]='request_id';
        if((string)($envelope['kind']??'')!=='subscriber.payment')$errors[]='kind';
        try{$this->normalizeOccurredAt((string)($envelope['occurred_at']??''));}catch(IntegrationException){$errors[]='occurred_at';}
        if(!is_array($envelope['payload']??null))$errors[]='payload';
        if(trim((string)($envelope['actor_projection_id']??''))==='')$errors[]='actor_projection_id';
        return $errors;
    }

    private function normalizeOccurredAt(string $value): string
    {
        $value=trim($value);if($value==='')throw new IntegrationException('invalid_time','زمان پرداخت معتبر نیست.',422);
        try{$dt=new DateTimeImmutable($value);}catch(Throwable){throw new IntegrationException('invalid_time','زمان پرداخت معتبر نیست.',422);}
        if($dt->getTimestamp()>time()+300)throw new IntegrationException('invalid_time','زمان پرداخت در آینده است.',422);
        return $dt->format('Y-m-d H:i:s');
    }

    private static function clip(string $v,int $n): string
    {return function_exists('mb_substr')?mb_substr($v,0,$n,'UTF-8'):substr($v,0,$n);}
}
