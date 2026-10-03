<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\Finance;

use DateTimeImmutable;
use PDO;
use Sokna\Local\Core\IdentityRepository;
use Throwable;

final class FinancialPeriodCloseService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly IdentityRepository $identity,
        private readonly FinancialPeriodIdentityService $identityPeriods,
        private readonly FinancialPeriodService $periods,
    ) {}

    public function close(int $periodId,array $publicStatus,string $overrideReason,array $user): array
    {
        $this->pdo->beginTransaction();
        try{
            $actor=$this->assertAdmin($user);
            $period=$this->identityPeriods->byIdTx($periodId);
            if((string)$period['status']==='closed'){
                $summary=json_decode((string)($period['close_summary_json']??''),true);
                $this->pdo->commit();
                return ['financial_period_id'=>$periodId,'closed'=>true,'idempotent'=>true,'summary'=>is_array($summary)?$summary:[]];
            }

            $preflight=$this->periods->closePreflightTx($periodId,$publicStatus);
            $hard=array_values(array_intersect($preflight['blockers'],['period_not_ended','open_table_sessions','period_closed']));
            if($hard)throw new FinancialPeriodException('close_blocked','پیش‌شرط‌های قطعی بستن دوره کامل نیست.',409,['blockers'=>$hard]);

            $deferred=array_values(array_intersect($preflight['blockers'],['local_pending_reviews','public_unknown','public_deferred_blocking']));
            $overrideId=null;
            if($deferred){
                $overrideReason=trim($overrideReason);
                if($overrideReason==='')
                    throw new FinancialPeriodException('deferred_blocked','کار Deferred تعیین‌تکلیف‌نشده یا وضعیت Public نامشخص است.',409,['blockers'=>$deferred]);
                $override=$this->periods->recordCloseOverrideTx($periodId,$overrideReason,$publicStatus,$actor);
                $overrideId=(int)$override['override_id'];
            }

            $settle=$this->pdo->prepare(
                "SELECT COALESCE(SUM(status='completed'),0) invoice_count,
                        COALESCE(SUM(status='reversal'),0) reversal_count,
                        COALESCE(SUM(CASE WHEN status='completed' THEN subtotal-discount WHEN status='reversal' THEN -(subtotal-discount) ELSE 0 END),0) sales_amount,
                        COALESCE(SUM(CASE WHEN status='completed' THEN tax_amount WHEN status='reversal' THEN -tax_amount ELSE 0 END),0) tax_amount,
                        COALESCE(SUM(CASE WHEN status='completed' THEN total WHEN status='reversal' THEN -total ELSE 0 END),0) total_amount,
                        COALESCE(SUM(CASE WHEN status='completed' THEN discount WHEN status='reversal' THEN -discount ELSE 0 END),0) discount_amount
                 FROM settlement_records WHERE financial_period_id=?"
            );
            $settle->execute([$periodId]);$s=$settle->fetch(PDO::FETCH_ASSOC)?:[];
            $expenses=$this->pdo->prepare(
                "SELECT COALESCE(SUM(status='committed'),0) expense_count,
                        COALESCE(SUM(status='reversal'),0) expense_reversal_count,
                        COALESCE(SUM(CASE WHEN status='committed' THEN amount WHEN status='reversal' THEN -amount ELSE 0 END),0) expense_net_amount
                 FROM expenses WHERE financial_period_id=?"
            );
            $expenses->execute([$periodId]);$e=$expenses->fetch(PDO::FETCH_ASSOC)?:[];
            $summary=[
                'invoice_count'=>(int)($s['invoice_count']??0),'reversal_count'=>(int)($s['reversal_count']??0),
                'sales_amount'=>(int)($s['sales_amount']??0),'tax_amount'=>(int)($s['tax_amount']??0),
                'total_amount'=>(int)($s['total_amount']??0),'discount_amount'=>(int)($s['discount_amount']??0),
                'general_expenses'=>[
                    'committed_count'=>(int)($e['expense_count']??0),'reversal_count'=>(int)($e['expense_reversal_count']??0),
                    'net_amount'=>(int)($e['expense_net_amount']??0)
                ],
                'deferred_close_status'=>$preflight['public_status'],
                'local_pending_reviews'=>(int)$preflight['local_pending_reviews'],
                'deferred_override'=>$overrideId!==null,
                'deferred_override_id'=>$overrideId,
            ];
            $json=json_encode($summary,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            $this->pdo->prepare(
                "UPDATE financial_periods SET status='closed',closed_at=NOW(),closed_by_user_id=?,close_summary_json=? WHERE id=?"
            )->execute([(int)$actor['id'],$json,$periodId]);

            $nextDate=(new DateTimeImmutable((string)$period['end_date']))->modify('+1 day')->format('Y-m-d');
            $next=$this->identityPeriods->forDateTx($nextDate,(int)$actor['id']);
            $this->audit('financial_period.closed','financial_period',$periodId,(int)$actor['id'],$summary);
            $this->pdo->commit();
            return [
                'financial_period_id'=>$periodId,'closed'=>true,'idempotent'=>false,
                'next_financial_period_id'=>(int)$next['id'],'summary'=>$summary
            ];
        }catch(Throwable $e){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $e;
        }
    }

    private function assertAdmin(array $user): array
    {
        $id=(int)($user['id']??0);
        if($id<1)throw new FinancialPeriodException('forbidden','حساب کاربری معتبر نیست.',403);
        $fresh=$this->identity->findActiveById($id);
        if($fresh===null||(string)($fresh['role']??'')!=='admin')
            throw new FinancialPeriodException('forbidden','فقط مدیر فعال می‌تواند دوره مالی را ببندد.',403);
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
}
