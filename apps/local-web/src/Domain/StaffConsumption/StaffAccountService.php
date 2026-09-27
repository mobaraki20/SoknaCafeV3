<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\StaffConsumption;

use PDO;
use Sokna\Local\Core\Capabilities;
use Sokna\Local\Core\IdentityRepository;
use Sokna\Local\Domain\Finance\FinancialPeriodIdentityService;
use Throwable;

final class StaffAccountService
{
    public const ACCOUNT_VERSION='f1.4-v1';
    private const METHODS=['cash','card','bank','payroll','other'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly IdentityRepository $identity,
        private readonly Capabilities $capabilities,
        private readonly StaffAccountRepository $repository,
        private readonly FinancialPeriodIdentityService $periods,
    ) {}

    /** Called only from Staff Consumption posting while the caller owns the transaction. */
    public function chargeConsumptionTx(int $consumptionId,int $actorUserId,string $occurredAt): ?array
    {
        $this->requireTx();$actor=$this->identity->findActiveById($actorUserId);
        if($actor===null)throw new StaffConsumptionException('staff_account_actor_invalid','ثبت‌کننده فعال حساب پرسنل پیدا نشد.',403);
        $occurredAt=$this->dateTime($occurredAt);
        $stmt=$this->pdo->prepare('SELECT id,public_code,consumer_personnel_id,payable_amount,status,business_date FROM staff_consumptions WHERE id=? FOR UPDATE');
        $stmt->execute([$consumptionId]);$consumption=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!is_array($consumption)||(string)$consumption['status']!=='posted')throw new StaffConsumptionException('staff_consumption_unavailable','سند مصرف برای ایجاد بدهی پیدا نشد.',409);
        $amount=(int)$consumption['payable_amount'];if($amount===0)return null;
        $existing=$this->repository->chargeForConsumptionTx($consumptionId);if($existing!==null)return $this->entryResult($existing,true);
        $businessDate=(string)$consumption['business_date'];$period=$this->periods->forDateTx($businessDate,$actorUserId);
        $this->periods->assertAccepts($period,$businessDate.' 12:00:00');
        $entry=$this->repository->insertEntryTx([
            'personnel_id'=>(int)$consumption['consumer_personnel_id'],'financial_period_id'=>(int)$period['id'],'consumption_id'=>$consumptionId,
            'entry_type'=>'charge','amount_delta'=>$amount,'actor_user_id'=>$actorUserId,'idempotency_key'=>'staff-consumption:charge:'.$consumptionId,
            'occurred_at'=>$occurredAt,'reference'=>(string)$consumption['public_code'],
        ]);
        $this->audit('staff_account.charge_created','staff_account_ledger',(int)$entry['id'],$actor,[
            'account_version'=>self::ACCOUNT_VERSION,'consumption_id'=>$consumptionId,'personnel_id'=>(int)$entry['personnel_id'],
            'amount'=>$amount,'financial_period_id'=>(int)$entry['financial_period_id'],'balance_after'=>(int)$entry['balance_after'],
        ]);
        return $entry;
    }

    public function payment(array $data,array $user): array
    {
        return $this->transactional(function(array $actor) use($data): array {
            $personnelId=$this->personnelId($data['personnel_id']??0);$amount=$this->amount($data['amount']??0);$request=$this->requestId($data['request_id']??'');
            $method=(string)($data['payment_method']??'');if(!in_array($method,self::METHODS,true))throw new StaffConsumptionException('staff_account_payment_method_invalid','روش پرداخت حساب پرسنل معتبر نیست.',422);
            $occurredAt=$this->dateTime($data['occurred_at']??null);$period=$this->periods->forDateTx(substr($occurredAt,0,10),(int)$actor['id']);$this->periods->assertAccepts($period,$occurredAt);
            $entry=$this->repository->insertEntryTx([
                'personnel_id'=>$personnelId,'financial_period_id'=>(int)$period['id'],'entry_type'=>'payment','amount_delta'=>-$amount,
                'payment_method'=>$method,'reference'=>$data['reference']??null,'reason'=>$data['reason']??null,'actor_user_id'=>(int)$actor['id'],
                'idempotency_key'=>'staff-account:payment:'.$request,'occurred_at'=>$occurredAt,
            ]);
            if(!$entry['idempotent'])$this->audit('staff_account.payment_recorded','staff_account_ledger',(int)$entry['id'],$actor,$this->auditDetails($entry));
            return $entry;
        },$user);
    }

    public function waiver(array $data,array $user): array
    {
        return $this->transactional(function(array $actor) use($data): array {
            $personnelId=$this->personnelId($data['personnel_id']??0);$amount=$this->amount($data['amount']??0);$request=$this->requestId($data['request_id']??'');
            $reason=$this->text($data['reason']??'',500);if($reason==='')throw new StaffConsumptionException('staff_account_waiver_reason_required','دلیل بخشودگی حساب پرسنل لازم است.',422);
            $occurredAt=$this->dateTime($data['occurred_at']??null);$period=$this->periods->forDateTx(substr($occurredAt,0,10),(int)$actor['id']);$this->periods->assertAccepts($period,$occurredAt);
            $entry=$this->repository->insertEntryTx([
                'personnel_id'=>$personnelId,'financial_period_id'=>(int)$period['id'],'entry_type'=>'waiver','amount_delta'=>-$amount,
                'reference'=>$data['reference']??null,'reason'=>$reason,'actor_user_id'=>(int)$actor['id'],
                'idempotency_key'=>'staff-account:waiver:'.$request,'occurred_at'=>$occurredAt,
            ]);
            if(!$entry['idempotent'])$this->audit('staff_account.waiver_recorded','staff_account_ledger',(int)$entry['id'],$actor,$this->auditDetails($entry));
            return $entry;
        },$user);
    }

    public function reverse(array $data,array $user): array
    {
        return $this->transactional(function(array $actor) use($data): array {
            $entryId=(int)($data['entry_id']??0);if($entryId<1)throw new StaffConsumptionException('staff_account_entry_invalid','سند حساب پرسنل معتبر نیست.',422);
            $reason=$this->text($data['reason']??'',500);if($reason==='')throw new StaffConsumptionException('staff_account_reversal_reason_required','دلیل برگشت سند حساب پرسنل لازم است.',422);
            $request=$this->requestId($data['request_id']??'');$original=$this->repository->findEntryForUpdate($entryId);
            if($original===null||!in_array((string)$original['entry_type'],['charge','payment','waiver'],true))throw new StaffConsumptionException('staff_account_not_reversible','این سند حساب پرسنل قابل برگشت نیست.',409);
            $existing=$this->repository->findReversalForUpdate($entryId);if($existing!==null)return $this->entryResult($existing,true);
            $occurredAt=$this->dateTime($data['occurred_at']??null);$period=$this->periods->forDateTx(substr($occurredAt,0,10),(int)$actor['id']);$this->periods->assertAccepts($period,$occurredAt);
            $map=['charge'=>'charge_reversal','payment'=>'payment_reversal','waiver'=>'waiver_reversal'];$type=$map[(string)$original['entry_type']];
            $entry=$this->repository->insertEntryTx([
                'personnel_id'=>(int)$original['personnel_id'],'financial_period_id'=>(int)$period['id'],'consumption_id'=>$original['consumption_id']??null,
                'entry_type'=>$type,'amount_delta'=>-(int)$original['amount_delta'],'related_entry_id'=>$entryId,
                'payment_method'=>str_starts_with($type,'payment_')?($original['payment_method']??null):null,
                'reference'=>$original['reference']??null,'reason'=>$reason,'actor_user_id'=>(int)$actor['id'],
                'idempotency_key'=>'staff-account:reversal:'.$request,'occurred_at'=>$occurredAt,
            ]);
            $this->audit('staff_account.entry_reversed','staff_account_ledger',(int)$entry['id'],$actor,[
                'account_version'=>self::ACCOUNT_VERSION,'original_entry_id'=>$entryId,'original_entry_type'=>(string)$original['entry_type'],
                'reversal_entry_type'=>$type,'reason'=>$reason,'personnel_id'=>(int)$entry['personnel_id'],'balance_after'=>(int)$entry['balance_after'],
            ]);
            return $entry;
        },$user);
    }

    public function account(int $personnelId,array $user,int $limit=100): array
    {
        $this->assertManager($user);$personnelId=$this->personnelId($personnelId);$this->assertPersonnelExists($personnelId);
        return ['personnel_id'=>$personnelId,'balance'=>$this->repository->balanceForPersonnel($personnelId),'history'=>$this->repository->historyForPersonnel($personnelId,$limit)];
    }

    private function transactional(callable $fn,array $user): array
    {
        $this->pdo->beginTransaction();try{$actor=$this->assertManager($user);$result=$fn($actor);$this->pdo->commit();return $result;}
        catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }
    private function assertManager(array $user): array
    {
        $fresh=$this->identity->findActiveById((int)($user['id']??0));
        if($fresh===null||!$this->capabilities->has('staff_account_manage',$fresh))throw new StaffConsumptionException('forbidden','دسترسی مدیریت حساب پرسنل فعال نیست.',403);return $fresh;
    }
    private function personnelId(mixed $value): int{$id=(int)$value;if($id<1)throw new StaffConsumptionException('personnel_invalid','پرسنل معتبر نیست.',422);return $id;}
    private function assertPersonnelExists(int $personnelId): void{$q=$this->pdo->prepare('SELECT id FROM personnel WHERE id=? LIMIT 1');$q->execute([$personnelId]);if($q->fetchColumn()===false)throw new StaffConsumptionException('personnel_unavailable','پرسنل پیدا نشد.',404);}
    private function amount(mixed $value): int{$amount=(int)$value;if($amount<1)throw new StaffConsumptionException('staff_account_amount_invalid','مبلغ حساب پرسنل معتبر نیست.',422);return $amount;}
    private function requestId(mixed $value): string{$v=trim((string)$value);if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,95}$/',$v))throw new StaffConsumptionException('staff_account_request_invalid','شناسه درخواست حساب پرسنل معتبر نیست.',422);return $v;}
    private function dateTime(mixed $value): string{$v=trim((string)($value??''));if($v==='')return date('Y-m-d H:i:s');$d=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$v);if($d===false||$d->format('Y-m-d H:i:s')!==$v)throw new StaffConsumptionException('staff_account_date_invalid','زمان سند حساب پرسنل معتبر نیست.',422);return $v;}
    private function text(mixed $value,int $max): string{$v=trim((string)$value);return function_exists('mb_substr')?mb_substr($v,0,$max,'UTF-8'):substr($v,0,$max);}
    private function auditDetails(array $entry): array{return ['account_version'=>self::ACCOUNT_VERSION,'personnel_id'=>(int)$entry['personnel_id'],'entry_type'=>(string)$entry['entry_type'],'amount_delta'=>(int)$entry['amount_delta'],'financial_period_id'=>(int)$entry['financial_period_id'],'balance_after'=>(int)$entry['balance_after'],'reference'=>$entry['reference']??null,'reason'=>$entry['reason']??null];}
    private function audit(string $action,string $entity,int $id,array $actor,array $details): void{$q=$this->pdo->prepare('INSERT INTO audit_log(actor_user_id,actor_display_name_snapshot,action,entity_type,entity_id,details_json) VALUES(?,?,?,?,?,?)');$q->execute([(int)$actor['id'],(string)($actor['display_name']??''),$action,$entity,(string)$id,json_encode($details,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);}
    private function entryResult(array $row,bool $idempotent): array{return ['id'=>(int)$row['id'],'personnel_id'=>(int)$row['personnel_id'],'financial_period_id'=>(int)$row['financial_period_id'],'consumption_id'=>isset($row['consumption_id'])&&$row['consumption_id']!==null?(int)$row['consumption_id']:null,'entry_type'=>(string)$row['entry_type'],'amount_delta'=>(int)$row['amount_delta'],'balance_before'=>(int)$row['balance_after']-(int)$row['amount_delta'],'balance_after'=>(int)$row['balance_after'],'related_entry_id'=>isset($row['related_entry_id'])&&$row['related_entry_id']!==null?(int)$row['related_entry_id']:null,'payment_method'=>$row['payment_method']??null,'reference'=>$row['reference']??null,'reason'=>$row['reason']??null,'occurred_at'=>(string)($row['occurred_at']??''),'idempotent'=>$idempotent];}
    private function requireTx(): void{if(!$this->pdo->inTransaction())throw new \LogicException('Staff Account charge requires an open transaction.');}
}
