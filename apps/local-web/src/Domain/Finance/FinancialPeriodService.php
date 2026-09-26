<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\Finance;

use PDO;
use Sokna\Local\Core\IdentityRepository;
use Sokna\Local\Domain\Orders\BusinessClock;
use Throwable;

final class FinancialPeriodService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly IdentityRepository $identity,
        private readonly BusinessClock $clock,
        private readonly FinancialPeriodIdentityService $periods,
    ) {}

    public function periodByIdTx(int $periodId): array
    {
        return $this->periods->byIdTx($periodId);
    }

    public function issueDocumentNumber(string $issuedAt,int $actorUserId,string $prefix='I'): array
    {
        $this->pdo->beginTransaction();
        try{
            $result=$this->issueDocumentNumberTx($issuedAt,$actorUserId,$prefix);
            $this->pdo->commit();
            return $result;
        }catch(Throwable $e){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $e;
        }
    }

    /** Caller owns the transaction. This is the single monotonic period-number owner. */
    public function issueDocumentNumberTx(string $issuedAt,int $actorUserId,string $prefix='I'): array
    {
        $this->requireTx();
        $prefix=strtoupper(trim($prefix));
        if(!in_array($prefix,['I','R'],true))
            throw new FinancialPeriodException('invalid_prefix','پیشوند سند مالی معتبر نیست.',422);

        $periodDate=preg_match('/\d{1,2}:\d{2}/',$issuedAt)
            ?(string)$this->clock->assignment($issuedAt)['business_date']
            :$this->normalizeDay($issuedAt);

        $period=$this->periods->forDateTx($periodDate,$actorUserId);
        if((string)$period['status']!=='open')
            throw new FinancialPeriodException('period_closed','سال مالی مربوط به این سند بسته شده است.',409,[
                'financial_period_id'=>(int)$period['id']
            ]);

        $sequence=max(1,(int)$period['next_invoice_sequence']);
        $this->pdo->prepare('UPDATE financial_periods SET next_invoice_sequence=? WHERE id=?')
            ->execute([$sequence+1,(int)$period['id']]);

        $bounds=FinancialPeriodIdentityService::boundsForDate((string)$period['start_date']);
        return [
            'period'=>$period,
            'sequence'=>$sequence,
            'invoice_number'=>sprintf('%s-%d-%06d',$prefix,(int)$bounds['jalali_year'],$sequence),
            'business_date'=>$periodDate,
        ];
    }

    /**
     * M5.9 close preflight only. Final close remains Settlement-owned in M5.10.
     * $publicStatus is the snapshot returned by the paired Public Deferred period-status contract.
     */
    public function closePreflight(int $periodId,array $publicStatus): array
    {
        $this->pdo->beginTransaction();
        try{
            $result=$this->closePreflightTx($periodId,$publicStatus);
            $this->pdo->commit();
            return $result;
        }catch(Throwable $e){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $e;
        }
    }

    public function closePreflightTx(int $periodId,array $publicStatus): array
    {
        $this->requireTx();
        $period=$this->periods->byIdTx($periodId);

        $local=$this->pdo->prepare(
            "SELECT COUNT(*) FROM deferred_review_items WHERE financial_period_id=? AND state='pending'"
        );
        $local->execute([$periodId]);
        $localPending=(int)$local->fetchColumn();

        $openAccounts=(int)$this->pdo->query(
            "SELECT COUNT(*) FROM table_sessions WHERE status IN('active','pending')"
        )->fetchColumn();

        $paired=(bool)($publicStatus['paired']??false);
        $known=$paired?(bool)($publicStatus['known']??false):true;
        $counts=is_array($publicStatus['counts']??null)?$publicStatus['counts']:[];
        $normalizedCounts=[
            'pending_sync'=>max(0,(int)($counts['pending_sync']??0)),
            'needs_review'=>max(0,(int)($counts['needs_review']??0)),
            'committed'=>max(0,(int)($counts['committed']??0)),
            'rejected'=>max(0,(int)($counts['rejected']??0)),
        ];
        $publicBlocking=$paired
            ?($known?max(0,(int)($publicStatus['blocking']??($normalizedCounts['pending_sync']+$normalizedCounts['needs_review']))):1)
            :0;

        $businessDate=(string)$this->clock->assignment()['business_date'];
        $ended=$businessDate>(string)$period['end_date'];
        $blockers=[];
        if((string)$period['status']!=='open')$blockers[]='period_closed';
        if(!$ended)$blockers[]='period_not_ended';
        if($localPending>0)$blockers[]='local_pending_reviews';
        if($openAccounts>0)$blockers[]='open_table_sessions';
        if($paired&&!$known)$blockers[]='public_unknown';
        elseif($publicBlocking>0)$blockers[]='public_deferred_blocking';

        $preflightReady=$blockers===[];
        return [
            'period'=>$period,
            'business_date'=>$businessDate,
            'period_ended'=>$ended,
            'local_pending_reviews'=>$localPending,
            'open_table_sessions'=>$openAccounts,
            'public_status'=>[
                'paired'=>$paired,'known'=>$known,'counts'=>$normalizedCounts,'blocking'=>$publicBlocking,
                'error'=>(string)($publicStatus['error']??''),
            ],
            'blockers'=>$blockers,
            'preflight_ready'=>$preflightReady,
            'final_close_allowed'=>$preflightReady,
            'final_close_blocker'=>$preflightReady?'':'preflight_blocked',
        ];
    }

    public function recordCloseOverride(int $periodId,string $reason,array $publicStatus,array $user): array
    {
        $this->pdo->beginTransaction();
        try{
            $actor=$this->assertAdmin($user);
            $result=$this->recordCloseOverrideTx($periodId,$reason,$publicStatus,$actor);
            $this->pdo->commit();
            return $result;
        }catch(Throwable $e){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $e;
        }
    }

    /** Caller owns the transaction; used by canonical final-close owner. */
    public function recordCloseOverrideTx(int $periodId,string $reason,array $publicStatus,array $actor): array
    {
        $this->requireTx();
        $period=$this->periods->byIdTx($periodId);
        if((string)$period['status']!=='open')
            throw new FinancialPeriodException('period_closed','دوره مالی قبلاً بسته شده است.',409);

        $reason=self::truncate(trim($reason),500);
        if($reason==='')throw new FinancialPeriodException('reason_required','دلیل عبور از کنترل Deferred را ثبت کن.',422);
        $json=json_encode($publicStatus,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $stmt=$this->pdo->prepare(
            'INSERT INTO financial_period_close_overrides(financial_period_id,actor_user_id,reason,public_status_json) VALUES(?,?,?,?)'
        );
        $stmt->execute([$periodId,(int)$actor['id'],$reason,$json]);
        $id=(int)$this->pdo->lastInsertId();
        $this->audit('financial_period.deferred_override','financial_period',$periodId,(int)$actor['id'],[
            'override_id'=>$id,'reason'=>$reason,'status'=>$publicStatus,
        ]);
        return ['override_id'=>$id,'financial_period_id'=>$periodId];
    }

    private function assertAdmin(array $user): array
    {
        $id=(int)($user['id']??0);
        if($id<1)throw new FinancialPeriodException('forbidden','حساب کاربری معتبر نیست.',403);
        $fresh=$this->identity->findActiveById($id);
        if($fresh===null||(string)($fresh['role']??'')!=='admin')
            throw new FinancialPeriodException('forbidden','فقط مدیر فعال می‌تواند استثنای بستن دوره را ثبت کند.',403);
        return $fresh;
    }

    private function normalizeDay(string $value): string
    {
        $ts=strtotime(trim($value));
        if($ts===false)throw new FinancialPeriodException('invalid_date','تاریخ سند مالی معتبر نیست.',422);
        return date('Y-m-d',$ts);
    }

    private function audit(string $action,string $entityType,int $entityId,int $actorId,array $details): void
    {
        $display=$this->pdo->prepare('SELECT display_name FROM users WHERE id=? LIMIT 1');
        $display->execute([$actorId]);$name=$display->fetchColumn();
        $json=json_encode($details,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $this->pdo->prepare(
            'INSERT INTO audit_log(actor_user_id,actor_display_name_snapshot,action,entity_type,entity_id,details_json) VALUES(?,?,?,?,?,?)'
        )->execute([$actorId,$name!==false?$name:null,$action,$entityType,(string)$entityId,$json?:'{}']);
    }

    private function requireTx(): void
    {
        if(!$this->pdo->inTransaction())throw new \LogicException('Financial Period canonical operation requires an open transaction.');
    }

    private static function truncate(string $value,int $length): string
    {
        return function_exists('mb_substr')?mb_substr($value,0,$length,'UTF-8'):substr($value,0,$length);
    }
}
