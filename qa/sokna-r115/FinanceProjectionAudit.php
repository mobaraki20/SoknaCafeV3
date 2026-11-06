<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\Finance;

use PDO;
use Throwable;

/**
 * Read-only, bounded financial consistency audit for the standard support package.
 * No receipt, customer identity, amounts or external credentials leave the host.
 * The audit never changes a settlement or attempts an automatic financial repair.
 */
final class FinanceProjectionAudit
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly SettlementService $settlements,
    ) {}

    public function inspect(int $limit=50): array
    {
        $limit=max(1,min(50,$limit));
        if($this->pdo->inTransaction())return ['status'=>'not_checked','reason'=>'transaction_already_open'];
        try {
            // Consistent snapshot rather than multiple independent reads: it must
            // never race an operator's concurrently committed settlement.
            $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $this->pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
            $total=(int)$this->pdo->query("SELECT COUNT(*) FROM table_sessions s WHERE s.status IN ('active','pending') AND EXISTS(SELECT 1 FROM orders oa WHERE oa.session_id=s.id AND oa.status='accounted') AND NOT EXISTS(SELECT 1 FROM orders op WHERE op.session_id=s.id AND op.status IN('pending_approval','new'))")->fetchColumn();
            $q=$this->pdo->query("SELECT s.id FROM table_sessions s WHERE s.status IN ('active','pending') AND EXISTS(SELECT 1 FROM orders oa WHERE oa.session_id=s.id AND oa.status='accounted') AND NOT EXISTS(SELECT 1 FROM orders op WHERE op.session_id=s.id AND op.status IN('pending_approval','new')) ORDER BY s.id DESC LIMIT {$limit}");
            $ids=$q->fetchAll(PDO::FETCH_COLUMN)?:[];
            $checked=0;$blocked=[];
            foreach($ids as $sessionId){
                $checked++;
                try {
                    $this->settlements->projectionAccountTx((int)$sessionId);
                } catch (SettlementStateConflict $e) {
                    $reason=(string)$e->errorCode;
                    if(preg_match('/^[a-z][a-z0-9_]{0,79}$/D',$reason)!==1)$reason='unclassified_conflict';
                    $blocked[]=['session_id'=>(int)$sessionId,'reason_code'=>$reason];
                }
            }
            $this->pdo->commit();
            return [
                'format'=>'sokna-finance-projection-audit-v1',
                'status'=>$blocked!==[]?'blocked':($total>$checked?'partial_checked':'checked'),
                'checked_sessions'=>$checked,'eligible_sessions'=>$total,
                'truncated'=>$total>$checked,'blocked_count'=>count($blocked),
                'blocked_sessions'=>$blocked,
                'meaning'=>'read-only diagnostic; no amounts, receipts or customer details; success does not validate other sessions',
            ];
        } catch(Throwable $e) {
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            // Never expose the SQL, DSN, table, message or credentials in a support bundle.
            return ['format'=>'sokna-finance-projection-audit-v1','status'=>'not_checked','reason'=>'audit_unavailable','exception_class'=>basename(str_replace('\\','/',get_class($e))),'test_only_detail'=>$e->getMessage()];
        }
    }
}