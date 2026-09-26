<?php
declare(strict_types=1);

namespace Sokna\Local\Relay;

use PDO;
use Sokna\Local\Core\IdentityRepository;
use Sokna\Local\Domain\Expenses\ExpenseException;
use Sokna\Local\Domain\Expenses\ExpenseService;
use Sokna\Local\Domain\Finance\FinancialPeriodIdentityService;
use Throwable;

final class ExpenseDeferredAdapter
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly IdentityRepository $identity,
        private readonly ExpenseService $expenses,
        private readonly FinancialPeriodIdentityService $periods,
        private readonly DeferredReceiptService $receipts,
    ) {}

    public function dispatch(string $installationId,array $envelope): array
    {
        $installationId=trim($installationId);
        $errors=$this->validateEnvelope($envelope);
        if($installationId===''||$errors!==[]){
            return ['state'=>'rejected','result'=>[],'error_code'=>'invalid_envelope','fields'=>$errors,'idempotent'=>false];
        }

        $this->pdo->beginTransaction();
        try{
            $replay=$this->receipts->replayTx($installationId,$envelope);
            if($replay!==null){$this->pdo->commit();return $replay;}

            $actor=$this->actor((string)$envelope['actor_projection_id']);
            if($actor===null){
                $this->pdo->rollBack();
                return ['state'=>'rejected','result'=>[],'error_code'=>'actor_invalid','idempotent'=>false];
            }
            if((string)($actor['role']??'')!=='admin'){
                $out=$this->receipts->rejectedTx($installationId,$envelope,$actor,'permission_denied');
                $this->pdo->commit();
                return $out;
            }

            $occurredAt=$this->expenses->normalizeOccurredAt((string)$envelope['occurred_at']);
            $period=$this->periods->forDateTx(substr($occurredAt,0,10),(int)$actor['id']);
            if((string)$period['status']==='closed'){
                $out=$this->receipts->reviewTx(
                    $installationId,$envelope,$actor,
                    'closed_financial_period',
                    'این رخداد متعلق به یک دوره مالی بسته است و فقط پس از بررسی صریح قابل ثبت است.',
                    'late_correction',
                    (int)$period['id']
                );
                $this->pdo->commit();
                return $out;
            }

            try{
                $result=$this->applyTx($envelope,$actor,(int)$period['id'],false);
                $out=$this->receipts->committedTx(
                    $installationId,$envelope,$actor,$result,(int)$period['id']
                );
            }catch(ExpenseException $e){
                $out=$this->receipts->rejectedTx(
                    $installationId,$envelope,$actor,$e->errorCode,$e->details,(int)$period['id']
                );
            }
            $this->pdo->commit();
            return $out;
        }catch(Throwable $e){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $e;
        }
    }

    public function resolveReview(int $reviewId,string $decision,string $reason,array $reviewer): array
    {
        if(!in_array($decision,['approve','reject'],true))
            throw new ExpenseException('invalid_decision','تصمیم بررسی معتبر نیست.',422);
        $reason=self::truncate(trim($reason),500);
        if($reason==='')throw new ExpenseException('reason_required','دلیل تصمیم را ثبت کن.',422);

        $this->pdo->beginTransaction();
        try{
            $manager=$this->identity->findActiveById((int)($reviewer['id']??0));
            if($manager===null||(string)($manager['role']??'')!=='admin')
                throw new ExpenseException('forbidden','فقط مدیر فعال می‌تواند مورد Deferred را تعیین تکلیف کند.',403);

            $review=$this->receipts->reviewLockedTx($reviewId);
            if($review===null)throw new ExpenseException('review_not_found','مورد بررسی پیدا نشد.',404);
            if((string)$review['state']!=='pending'){
                $this->pdo->commit();
                return ['state'=>(string)$review['state'],'idempotent'=>true];
            }
            if((string)$review['kind']!=='expense.create')
                throw new ExpenseException('wrong_review_owner','این مورد بررسی متعلق به Expenses نیست.',409);

            if($decision==='reject'){
                $out=$this->receipts->rejectReviewTx($review,(int)$manager['id'],$reason);
                $this->pdo->commit();
                return $out;
            }

            $envelope=json_decode((string)$review['envelope_json'],true);
            if(!is_array($envelope))throw new ExpenseException('invalid_envelope','اطلاعات رخداد Deferred قابل بازیابی نیست.',500);
            $actor=$this->actor((string)$review['actor_projection_id']);
            if($actor===null||(string)($actor['role']??'')!=='admin')
                throw new ExpenseException('actor_invalid','حساب مدیر اصلی دیگر فعال نیست.',409);
            $periodId=(int)($review['financial_period_id']??0);
            if($periodId<1)throw new ExpenseException('period_not_found','دوره مالی رخداد بررسی‌شده مشخص نیست.',409);

            $result=$this->applyTx($envelope,$actor,$periodId,true);
            $out=$this->receipts->approveReviewTx($review,(int)$manager['id'],$reason,$result);
            $this->pdo->commit();
            return $out;
        }catch(Throwable $e){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $e;
        }
    }

    private function applyTx(array $envelope,array $actor,int $periodId,bool $allowClosedPeriod): array
    {
        $payload=is_array($envelope['payload']??null)?$envelope['payload']:[];
        return $this->expenses->createTx([
            'category_key'=>(string)($payload['category_key']??''),
            'amount'=>(int)($payload['amount']??0),
            'description'=>(string)($payload['description']??''),
            'occurred_at'=>$this->expenses->normalizeOccurredAt((string)$envelope['occurred_at']),
            'source_request_id'=>'deferred:'.(string)$envelope['request_id'],
            'financial_period_id'=>$periodId,
        ],(int)$actor['id'],$allowClosedPeriod);
    }

    private function actor(string $projectionId): ?array
    {
        if(!preg_match('/^user:(\d+)$/',trim($projectionId),$m))return null;
        return $this->identity->findActiveById((int)$m[1]);
    }

    private function validateEnvelope(array $envelope): array
    {
        $errors=[];
        $requestId=trim((string)($envelope['request_id']??''));
        if($requestId===''||strlen($requestId)>96||preg_match('/^[A-Za-z0-9._:-]+$/',$requestId)!==1)$errors[]='request_id';
        if((string)($envelope['kind']??'')!=='expense.create')$errors[]='kind';
        $occurred=strtotime(trim((string)($envelope['occurred_at']??'')))?:0;
        if($occurred<=0||$occurred>time()+300)$errors[]='occurred_at';
        if(!is_array($envelope['payload']??null))$errors[]='payload';
        if(trim((string)($envelope['actor_projection_id']??''))==='')$errors[]='actor_projection_id';
        return $errors;
    }

    private static function truncate(string $value,int $length): string
    {
        return function_exists('mb_substr')?mb_substr($value,0,$length,'UTF-8'):substr($value,0,$length);
    }
}
