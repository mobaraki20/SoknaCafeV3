<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\Expenses;

use PDO;
use Sokna\Local\Core\IdentityRepository;
use Sokna\Local\Domain\Finance\FinancialPeriodIdentityService;
use Throwable;

final class ExpenseService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly IdentityRepository $identity,
        private readonly FinancialPeriodIdentityService $periods,
    ) {}

    public function create(array $data,array $user): array
    {
        $this->pdo->beginTransaction();
        try{
            $actor=$this->assertAdmin($user);
            $result=$this->createTx($data,(int)$actor['id']);
            $this->pdo->commit();
            return $result;
        }catch(Throwable $e){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $e;
        }
    }

    public function reverse(int $expenseId,string $reason,string $sourceRequestId,array $user): array
    {
        $this->pdo->beginTransaction();
        try{
            $actor=$this->assertAdmin($user);
            $result=$this->reverseTx($expenseId,$reason,(int)$actor['id'],$sourceRequestId,false);
            $this->pdo->commit();
            return $result;
        }catch(Throwable $e){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $e;
        }
    }

    public function correct(int $expenseId,array $replacement,string $reason,string $sourceRequestId,array $user): array
    {
        $this->pdo->beginTransaction();
        try{
            $actor=$this->assertAdmin($user);
            $result=$this->correctTx($expenseId,$replacement,$reason,(int)$actor['id'],$sourceRequestId);
            $this->pdo->commit();
            return $result;
        }catch(Throwable $e){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $e;
        }
    }

    public function createTx(array $data,int $actorUserId,bool $allowClosedPeriod=false): array
    {
        $this->requireTx();
        $categoryKey=trim((string)($data['category_key']??''));
        $amount=(int)($data['amount']??0);
        $description=self::truncate(trim((string)($data['description']??'')),500);
        $sourceRequestId=self::truncate(trim((string)($data['source_request_id']??'')),96);
        if($amount<1)throw new ExpenseException('invalid_amount','مبلغ هزینه باید بیشتر از صفر باشد.',422);
        if($sourceRequestId==='')throw new ExpenseException('source_request_required','شناسه یکتای هزینه مشخص نیست.',422);
        $occurredAt=$this->normalizeOccurredAt((string)($data['occurred_at']??''));

        $dup=$this->pdo->prepare('SELECT * FROM expenses WHERE source_request_id=? LIMIT 1 FOR UPDATE');
        $dup->execute([$sourceRequestId]);$existing=$dup->fetch(PDO::FETCH_ASSOC);
        if(is_array($existing)){
            if((string)$existing['category_key']!==$categoryKey
                ||(int)$existing['amount']!==$amount
                ||(string)$existing['occurred_at']!==$occurredAt){
                throw new ExpenseException('idempotency_conflict','شناسه این هزینه قبلاً برای اطلاعات دیگری استفاده شده است.',409);
            }
            return [
                'id'=>(int)$existing['id'],
                'financial_period_id'=>(int)$existing['financial_period_id'],
                'idempotent'=>true,
            ];
        }

        $cat=$this->pdo->prepare('SELECT category_key FROM expense_categories WHERE category_key=? AND active=1 FOR UPDATE');
        $cat->execute([$categoryKey]);
        if($cat->fetchColumn()===false)throw new ExpenseException('invalid_category','دسته هزینه معتبر نیست.',422);

        $periodId=(int)($data['financial_period_id']??0);
        $period=$periodId>0
            ?$this->periods->byIdTx($periodId)
            :$this->periods->forDateTx(substr($occurredAt,0,10),$actorUserId);
        $this->periods->assertAccepts($period,$occurredAt,$allowClosedPeriod);

        $stmt=$this->pdo->prepare(
            "INSERT INTO expenses(financial_period_id,category_key,amount,description,occurred_at,actor_user_id,source_request_id,status)
             VALUES(?,?,?,?,?,?,?,'committed')"
        );
        $stmt->execute([
            (int)$period['id'],$categoryKey,$amount,$description!==''?$description:null,
            $occurredAt,$actorUserId>0?$actorUserId:null,$sourceRequestId
        ]);
        $id=(int)$this->pdo->lastInsertId();
        $this->audit('expense.created','expense',$id,$actorUserId,[
            'category_key'=>$categoryKey,'amount'=>$amount,'occurred_at'=>$occurredAt,
            'source_request_id'=>$sourceRequestId,'financial_period_id'=>(int)$period['id'],
        ]);
        return ['id'=>$id,'financial_period_id'=>(int)$period['id'],'idempotent'=>false];
    }

    public function reverseTx(
        int $expenseId,string $reason,int $actorUserId,string $sourceRequestId,bool $allowClosedPeriod=false
    ): array {
        $this->requireTx();
        $reason=self::truncate(trim($reason),500);
        $sourceRequestId=self::truncate(trim($sourceRequestId),96);
        if($expenseId<1)throw new ExpenseException('invalid_expense','هزینه معتبر نیست.',422);
        if($reason==='')throw new ExpenseException('reason_required','دلیل برگشت هزینه را وارد کن.',422);
        if($sourceRequestId==='')throw new ExpenseException('source_request_required','شناسه یکتای برگشت هزینه مشخص نیست.',422);

        $stmt=$this->pdo->prepare(
            'SELECT e.*,c.name category_name FROM expenses e JOIN expense_categories c ON c.category_key=e.category_key WHERE e.id=? FOR UPDATE'
        );
        $stmt->execute([$expenseId]);$original=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!is_array($original))throw new ExpenseException('expense_not_found','هزینه پیدا نشد.',404);
        if((string)$original['status']!=='committed')
            throw new ExpenseException('not_reversible','فقط سند هزینه اصلی قابل برگشت است.',409);

        $existing=$this->pdo->prepare('SELECT * FROM expenses WHERE reverses_expense_id=? LIMIT 1 FOR UPDATE');
        $existing->execute([$expenseId]);$row=$existing->fetch(PDO::FETCH_ASSOC);
        if(is_array($row)){
            return [
                'id'=>(int)$row['id'],'original_id'=>$expenseId,
                'financial_period_id'=>(int)$row['financial_period_id'],'idempotent'=>true,
            ];
        }

        $period=$this->periods->byIdTx((int)$original['financial_period_id']);
        $this->periods->assertAccepts($period,(string)$original['occurred_at'],$allowClosedPeriod);
        $description=self::truncate('برگشت هزینه — '.$reason,500);
        $insert=$this->pdo->prepare(
            "INSERT INTO expenses(financial_period_id,category_key,amount,description,occurred_at,actor_user_id,source_request_id,status,reverses_expense_id)
             VALUES(?,?,?,?,?,?,?,'reversal',?)"
        );
        $insert->execute([
            (int)$original['financial_period_id'],(string)$original['category_key'],(int)$original['amount'],
            $description,(string)$original['occurred_at'],$actorUserId>0?$actorUserId:null,$sourceRequestId,$expenseId,
        ]);
        $id=(int)$this->pdo->lastInsertId();
        $this->audit('expense.reversed','expense',$id,$actorUserId,[
            'original_expense_id'=>$expenseId,'amount'=>(int)$original['amount'],'reason'=>$reason,
        ]);
        return [
            'id'=>$id,'original_id'=>$expenseId,
            'financial_period_id'=>(int)$original['financial_period_id'],'idempotent'=>false,
        ];
    }

    public function correctTx(
        int $expenseId,array $replacement,string $reason,int $actorUserId,string $sourceRequestId
    ): array {
        $this->requireTx();
        $sourceRequestId=self::truncate(trim($sourceRequestId),80);
        if($sourceRequestId==='')throw new ExpenseException('source_request_required','شناسه یکتای اصلاح هزینه مشخص نیست.',422);
        $reversal=$this->reverseTx($expenseId,$reason,$actorUserId,$sourceRequestId.':reverse',false);
        $replacement['source_request_id']=$sourceRequestId.':replace';
        $new=$this->createTx($replacement,$actorUserId,false);
        $this->audit('expense.corrected','expense',(int)$new['id'],$actorUserId,[
            'original_expense_id'=>$expenseId,'reversal_expense_id'=>(int)$reversal['id'],
            'replacement_expense_id'=>(int)$new['id'],'reason'=>self::truncate(trim($reason),500),
        ]);
        return [
            'original_id'=>$expenseId,'reversal_id'=>(int)$reversal['id'],
            'replacement_id'=>(int)$new['id'],'financial_period_id'=>(int)$new['financial_period_id'],
        ];
    }

    public function categories(bool $activeOnly=true): array
    {
        $sql='SELECT category_key,name,active,system_category,sort_order FROM expense_categories'
            .($activeOnly?' WHERE active=1':'').' ORDER BY sort_order,name,category_key';
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function periodSummary(int $periodId): array
    {
        $stmt=$this->pdo->prepare(
            "SELECT COALESCE(SUM(status='committed'),0) committed_count,
                    COALESCE(SUM(status='reversal'),0) reversal_count,
                    COALESCE(SUM(CASE WHEN status='committed' THEN amount WHEN status='reversal' THEN -amount ELSE 0 END),0) net_amount
             FROM expenses WHERE financial_period_id=?"
        );
        $stmt->execute([$periodId]);$row=$stmt->fetch(PDO::FETCH_ASSOC)?:[];
        return [
            'committed_count'=>(int)($row['committed_count']??0),
            'reversal_count'=>(int)($row['reversal_count']??0),
            'net_amount'=>(int)($row['net_amount']??0),
        ];
    }

    public function normalizeOccurredAt(string $value): string
    {
        $value=trim($value);$ts=strtotime($value);
        if($value===''||$ts===false||$ts>time()+300)
            throw new ExpenseException('invalid_occurred_at','زمان وقوع هزینه معتبر نیست.',422);
        return date('Y-m-d H:i:s',$ts);
    }

    private function assertAdmin(array $user): array
    {
        $id=(int)($user['id']??0);
        if($id<1)throw new ExpenseException('forbidden','حساب کاربری معتبر نیست.',403);
        $fresh=$this->identity->findActiveById($id);
        if($fresh===null||(string)($fresh['role']??'')!=='admin')
            throw new ExpenseException('forbidden','فقط مدیر فعال می‌تواند هزینه‌های کافه را تغییر دهد.',403);
        return $fresh;
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
        if(!$this->pdo->inTransaction())throw new \LogicException('Expense operation requires an open transaction.');
    }

    private static function truncate(string $value,int $length): string
    {
        return function_exists('mb_substr')?mb_substr($value,0,$length,'UTF-8'):substr($value,0,$length);
    }
}
