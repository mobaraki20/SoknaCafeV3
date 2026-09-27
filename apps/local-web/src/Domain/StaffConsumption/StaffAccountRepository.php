<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\StaffConsumption;

use PDO;

final class StaffAccountRepository
{
    private const TYPES=['charge','payment','waiver','charge_reversal','payment_reversal','waiver_reversal'];

    public function __construct(private readonly PDO $pdo) {}

    public function balanceForPersonnel(int $personnelId): int
    {
        if($personnelId<1)return 0;
        $stmt=$this->pdo->prepare('SELECT balance_after FROM staff_account_ledger WHERE personnel_id=? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$personnelId]);$value=$stmt->fetchColumn();
        return $value===false?0:(int)$value;
    }

    public function historyForPersonnel(int $personnelId,int $limit=100): array
    {
        if($personnelId<1)return [];$limit=max(1,min(250,$limit));
        $stmt=$this->pdo->prepare(
            "SELECT l.*,u.display_name actor_display_name FROM staff_account_ledger l " .
            "JOIN users u ON u.id=l.actor_user_id WHERE l.personnel_id=? ORDER BY l.id DESC LIMIT {$limit}"
        );
        $stmt->execute([$personnelId]);return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findByIdempotencyForUpdate(string $key): ?array
    {
        $this->requireTx();$stmt=$this->pdo->prepare('SELECT * FROM staff_account_ledger WHERE idempotency_key=? LIMIT 1 FOR UPDATE');
        $stmt->execute([$key]);$row=$stmt->fetch(PDO::FETCH_ASSOC);return is_array($row)?$row:null;
    }

    public function findEntryForUpdate(int $entryId): ?array
    {
        $this->requireTx();$stmt=$this->pdo->prepare('SELECT * FROM staff_account_ledger WHERE id=? FOR UPDATE');
        $stmt->execute([$entryId]);$row=$stmt->fetch(PDO::FETCH_ASSOC);return is_array($row)?$row:null;
    }

    public function findReversalForUpdate(int $entryId): ?array
    {
        $this->requireTx();$stmt=$this->pdo->prepare('SELECT * FROM staff_account_ledger WHERE related_entry_id=? LIMIT 1 FOR UPDATE');
        $stmt->execute([$entryId]);$row=$stmt->fetch(PDO::FETCH_ASSOC);return is_array($row)?$row:null;
    }

    public function chargeForConsumptionTx(int $consumptionId): ?array
    {
        $this->requireTx();$stmt=$this->pdo->prepare("SELECT * FROM staff_account_ledger WHERE consumption_id=? AND entry_type='charge' LIMIT 1 FOR UPDATE");
        $stmt->execute([$consumptionId]);$row=$stmt->fetch(PDO::FETCH_ASSOC);return is_array($row)?$row:null;
    }

    public function balanceTx(int $personnelId): int
    {
        $this->requireTx();$stmt=$this->pdo->prepare('SELECT balance_after FROM staff_account_ledger WHERE personnel_id=? ORDER BY id DESC LIMIT 1 FOR UPDATE');
        $stmt->execute([$personnelId]);$value=$stmt->fetchColumn();return $value===false?0:(int)$value;
    }

    public function insertEntryTx(array $data): array
    {
        $this->requireTx();
        $personnelId=(int)($data['personnel_id']??0);$type=(string)($data['entry_type']??'');$delta=(int)($data['amount_delta']??0);
        $actorId=(int)($data['actor_user_id']??0);$periodId=(int)($data['financial_period_id']??0);$key=self::clip(trim((string)($data['idempotency_key']??'')),190);
        if($personnelId<1||$actorId<1||$periodId<1||$key===''||!in_array($type,self::TYPES,true)||$delta===0)
            throw new StaffConsumptionException('staff_account_entry_invalid','سند حساب پرسنل معتبر نیست.',422);
        $this->assertSign($type,$delta);

        $person=$this->pdo->prepare('SELECT id,display_name,archived_at FROM personnel WHERE id=? FOR UPDATE');$person->execute([$personnelId]);$personnel=$person->fetch(PDO::FETCH_ASSOC);
        if(!is_array($personnel))throw new StaffConsumptionException('personnel_unavailable','پرسنل پیدا نشد.',404);

        $existing=$this->findByIdempotencyForUpdate($key);
        if($existing!==null){
            if((int)$existing['personnel_id']!==$personnelId||(string)$existing['entry_type']!==$type||(int)$existing['amount_delta']!==$delta||
               (int)($existing['consumption_id']??0)!==(int)($data['consumption_id']??0)||(int)($existing['related_entry_id']??0)!==(int)($data['related_entry_id']??0))
                throw new StaffConsumptionException('staff_account_idempotency_conflict','شناسه درخواست حساب پرسنل قبلاً برای عملیات دیگری استفاده شده است.',409);
            return $this->result($existing,$personnel,true);
        }

        $current=$this->balanceTx($personnelId);$next=$current+$delta;
        if($next<0)throw new StaffConsumptionException('staff_account_balance_underflow','مبلغ پرداخت یا بخشودگی از مانده حساب پرسنل بیشتر است.',409);
        $stmt=$this->pdo->prepare(
            'INSERT INTO staff_account_ledger(personnel_id,financial_period_id,consumption_id,entry_type,amount_delta,balance_after,related_entry_id,payment_method,reference,reason,actor_user_id,idempotency_key,occurred_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            $personnelId,$periodId,$data['consumption_id']??null,$type,$delta,$next,$data['related_entry_id']??null,
            self::nullable($data['payment_method']??null,32),self::nullable($data['reference']??null,120),self::nullable($data['reason']??null,500),
            $actorId,$key,(string)$data['occurred_at'],
        ]);
        $row=[
            'id'=>(int)$this->pdo->lastInsertId(),'personnel_id'=>$personnelId,'financial_period_id'=>$periodId,
            'consumption_id'=>$data['consumption_id']??null,'entry_type'=>$type,'amount_delta'=>$delta,'balance_after'=>$next,
            'related_entry_id'=>$data['related_entry_id']??null,'payment_method'=>$data['payment_method']??null,'reference'=>$data['reference']??null,
            'reason'=>$data['reason']??null,'actor_user_id'=>$actorId,'idempotency_key'=>$key,'occurred_at'=>(string)$data['occurred_at'],
        ];
        return $this->result($row,$personnel,false);
    }

    private function result(array $row,array $personnel,bool $idempotent): array
    {
        return [
            'id'=>(int)$row['id'],'personnel_id'=>(int)$row['personnel_id'],'personnel'=>$personnel,
            'financial_period_id'=>(int)$row['financial_period_id'],'consumption_id'=>isset($row['consumption_id'])&&$row['consumption_id']!==null?(int)$row['consumption_id']:null,
            'entry_type'=>(string)$row['entry_type'],'amount_delta'=>(int)$row['amount_delta'],
            'balance_before'=>(int)$row['balance_after']-(int)$row['amount_delta'],'balance_after'=>(int)$row['balance_after'],
            'related_entry_id'=>isset($row['related_entry_id'])&&$row['related_entry_id']!==null?(int)$row['related_entry_id']:null,
            'payment_method'=>$row['payment_method']??null,'reference'=>$row['reference']??null,'reason'=>$row['reason']??null,
            'occurred_at'=>(string)($row['occurred_at']??''),'idempotent'=>$idempotent,
        ];
    }

    private function assertSign(string $type,int $delta): void
    {
        $positive=in_array($type,['charge','payment_reversal','waiver_reversal'],true);
        if(($positive&&$delta<1)||(!$positive&&$delta>-1))throw new StaffConsumptionException('staff_account_entry_sign_invalid','علامت مبلغ سند حساب پرسنل معتبر نیست.',422);
    }

    private function requireTx(): void
    {
        if(!$this->pdo->inTransaction())throw new \LogicException('Staff Account ledger mutation requires an open transaction.');
    }
    private static function nullable(mixed $value,int $max): ?string{$v=self::clip(trim((string)($value??'')),$max);return $v===''?null:$v;}
    private static function clip(string $value,int $max): string{return function_exists('mb_substr')?mb_substr($value,0,$max,'UTF-8'):substr($value,0,$max);}
}
