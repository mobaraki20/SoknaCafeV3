<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\Finance;

use PDO;
use Sokna\Local\Core\IdentityRepository;
use Sokna\Local\Core\Capabilities;
use Sokna\Local\Domain\Orders\OrderCatalogService;
use Sokna\Local\Domain\Orders\OrderCommitService;
use Sokna\Local\Domain\Orders\OrderCommitException;
use Throwable;

/**
 * Account correction for items already delivered but omitted from an open bill.
 * Not a preparation command. Local retains accounting authority for both hosts.
 */
final class LateServedAccountingService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly IdentityRepository $identity,
        private readonly Capabilities $capabilities,
        private readonly SettlementService $settlements,
        private readonly OrderCatalogService $catalog,
        private readonly OrderCommitService $orders,
    ) {}

    public function catalog(array $user): array
    {
        $this->cashier($user);
        // Bundles require a separate server-selected component configuration.
        // Do not silently post an incomplete bundle from the quick correction picker.
        return ['success'=>true,'items'=>array_values(array_filter(
            $this->catalog->staffCatalogRows(),
            static fn(array $r):bool=>empty($r['is_bundle'])
        ))];
    }

    public function commit(array $payload,array $user): array
    {
        $actor=$this->cashier($user);
        $sessionId=(int)($payload['session_id']??0);
        $requestId=trim((string)($payload['request_id']??''));
        if($sessionId<1)throw new SettlementException('invalid_session','حساب میز معتبر نیست.',422);
        if(!preg_match('/^[A-Za-z0-9-]{16,65}$/D',$requestId))
            throw new SettlementException('invalid_request_id','شناسه ثبت قلم جاافتاده معتبر نیست.',422);
        $items=$payload['items']??null;
        if(!is_array($items)||count($items)<1||count($items)>20)
            throw new SettlementException('invalid_items','حداقل یک قلم و حداکثر ۲۰ قلم را انتخاب کن.',422);
        $safe=[];
        foreach($items as $item){
            if(!is_array($item))throw new SettlementException('invalid_items','اطلاعات قلم معتبر نیست.',422);
            $id=filter_var($item['id']??null,FILTER_VALIDATE_INT);
            $qty=filter_var($item['quantity']??null,FILTER_VALIDATE_INT);
            $price=filter_var($item['expected_price']??null,FILTER_VALIDATE_INT);
            if($id===false||$id<1||$qty===false||$qty<1||$qty>50||$price===false||$price<0)
                throw new SettlementException('invalid_items','قلم، تعداد یا قیمت معتبر نیست.',422);
            $safe[]=['id'=>(int)$id,'quantity'=>(int)$qty,'expected_price'=>(int)$price,
                'fulfillment_mode'=>'dine_in','note'=>''];
        }
        $expectedSession=(int)($payload['expected_session_id']??0);
        $expectedTotal=(int)($payload['expected_remaining_total']??-1);
        $expectedSignature=strtolower(trim((string)($payload['expected_signature']??'')));
        if($expectedSession!==$sessionId||$expectedTotal<0||preg_match('/^[a-f0-9]{64}$/D',$expectedSignature)!==1)
            throw new SettlementStateConflict('expected_state_required','حساب را تازه کن و سپس قلم را ثبت کن.',409);
        $fingerprint=hash('sha256',json_encode([$sessionId,(int)$actor['id'],$safe,$expectedTotal,$expectedSignature],JSON_THROW_ON_ERROR));
        // Match OrderCommitService lock order: table first, then session. A regular
        // order could otherwise lock the table while this correction holds the
        // session and waits for that table, creating an avoidable DB deadlock.
        $tableLookup=$this->pdo->prepare('SELECT table_id FROM table_sessions WHERE id=? LIMIT 1');
        $tableLookup->execute([$sessionId]);$tableId=(int)$tableLookup->fetchColumn();
        if($tableId<1)
            throw new SettlementStateConflict('session_closed','حساب این میز بسته شده یا در وضعیت قابل ثبت نیست.',409);
        // Public/LAN realtime already owns the atomic receipt + business transaction.
        // Keep the standalone Local path transactional without starting a nested PDO
        // transaction or committing/rolling back the caller's receipt transaction.
        $ownsTransaction=!$this->pdo->inTransaction();
        if($ownsTransaction)$this->pdo->beginTransaction();
        try{
            $tableLock=$this->pdo->prepare('SELECT id,active FROM cafe_tables WHERE id=? LIMIT 1 FOR UPDATE');
            $tableLock->execute([$tableId]);
            $lockedTable=$tableLock->fetch(PDO::FETCH_ASSOC);
            if(!is_array($lockedTable))
                throw new SettlementStateConflict('table_unavailable','میز پیدا نشد.',409);
            $session=$this->pdo->prepare("SELECT id,table_id,status,live_table_guard FROM table_sessions WHERE id=? FOR UPDATE");
            $session->execute([$sessionId]);$row=$session->fetch(PDO::FETCH_ASSOC);
            // A previously committed request must remain idempotent even if its
            // account was settled between the original commit and an ACK retry.
            // The receipt follows table/session locks to avoid gap-lock races.
            $receipt=$this->pdo->prepare('SELECT request_fingerprint,order_id,session_id,actor_user_id FROM late_served_accounting_receipts WHERE request_id=? FOR UPDATE');
            $receipt->execute([$requestId]);$existing=$receipt->fetch(PDO::FETCH_ASSOC);
            if(is_array($existing)){
                if(!hash_equals((string)$existing['request_fingerprint'],$fingerprint)||(int)$existing['session_id']!==$sessionId||(int)$existing['actor_user_id']!==(int)$actor['id'])
                    throw new SettlementStateConflict('idempotency_conflict','شناسه درخواست قبلاً برای ثبت دیگری استفاده شده است.',409);
                if($ownsTransaction)$this->pdo->commit();
                return ['success'=>true,'duplicate'=>true,'order_id'=>(int)$existing['order_id'],'session_id'=>$sessionId,'accounting_origin'=>'late_served'];
            }
            if(!is_array($row)||(int)$row['table_id']!==$tableId)
                throw new SettlementStateConflict('account_changed','میز این حساب جابه‌جا شده؛ وضعیت را تازه کن.',409);
            if((int)$lockedTable['active']!==1)
                throw new SettlementStateConflict('table_unavailable','میز دیگر فعال نیست.',409);
            if((string)$row['status']!=='active'||(int)($row['live_table_guard']??0)<1)
                throw new SettlementStateConflict('session_closed','حساب این میز بسته شده یا در وضعیت قابل ثبت نیست.',409);
            $account=$this->settlements->accountTx($sessionId);
            if((int)($account['session']['id']??0)!==$sessionId||(int)($account['remaining_total']??-1)!==$expectedTotal||
                !hash_equals((string)($account['signature']??''),$expectedSignature))
                throw new SettlementStateConflict('account_changed','حساب در این فاصله تغییر کرده؛ مبلغ جدید را بررسی کن.',409);
            // Discounts are allocated by immutable accounting cohort in
            // SettlementService/TaxService; existing receipt lines stay fixed.
            // An accommodation charge already sent externally must not be altered.
            $transfers=$this->pdo->prepare('SELECT status,resolved_at,suspicious_response FROM accommodation_transfers WHERE session_id=? ORDER BY id DESC LIMIT 1 FOR UPDATE');
            $transfers->execute([$sessionId]);$transfer=$transfers->fetch(PDO::FETCH_ASSOC);
            if(is_array($transfer)&&empty($transfer['resolved_at'])&&(
                in_array((string)$transfer['status'],['pending','posted','void_pending','void_failed'],true)||(int)($transfer['suspicious_response']??0)===1
            ))throw new SettlementStateConflict('accommodation_transfer_open','انتقال اقامتگاه در جریان است؛ ابتدا آن را تعیین تکلیف کن.',409);
            // Bundles require configured component choices; reject them at the
            // server even if an untrusted client bypasses the correction picker.
            $ids=array_values(array_unique(array_map(static fn(array $x):int=>$x['id'],$safe)));
            $holders=implode(',',array_fill(0,count($ids),'?'));
            $bundleCheck=$this->pdo->prepare("SELECT bundle_item_id FROM item_bundles WHERE bundle_item_id IN ($holders) LIMIT 1 FOR UPDATE");
            $bundleCheck->execute($ids);
            if($bundleCheck->fetchColumn()!==false)
                throw new SettlementException('bundle_not_supported','قلم ترکیبی باید از مسیر ثبت سفارش دارای انتخاب اجزا ثبت شود.',409);
            // Strict catalog validation of price/availability and accounting happens
            // inside the canonical OrderCommitService transaction.
            try{
                $posted=$this->orders->commitLateServedTx([
                    'source'=>'staff','actor_user_id'=>(int)$actor['id'],
                    'table_id'=>(int)$row['table_id'],'session_id'=>$sessionId,
                    'client_token'=>'late-'.$requestId,'status'=>'accounted',
                    'order_context'=>'table_service','accounting_origin'=>'late_served',
                    'customer_note'=>'قلم قبلاً تحویل‌شده؛ صرفاً ثبت در حساب',
                    'items'=>$safe,
                ]);
            }catch(OrderCommitException $e){
                throw new SettlementException($e->errorCode,$e->getMessage(),$e->httpStatus,$e->details);
            }
            $orderId=(int)$posted['order_id'];
            if(!empty($posted['duplicate']))throw new SettlementStateConflict('receipt_missing','سفارش از قبل ثبت شده ولی رسید قلم جاافتاده ناقص است؛ نیازمند بررسی.',409);
            $this->pdo->prepare('INSERT INTO late_served_accounting_receipts(request_id,request_fingerprint,order_id,session_id,actor_user_id) VALUES(?,?,?,?,?)')
                ->execute([$requestId,$fingerprint,$orderId,$sessionId,(int)$actor['id']]);
            $after=$this->settlements->accountTx($sessionId);
            // Keep the denormalized session discount in sync with the actual,
            // cohort-stable calculation; never rewrite prior payment records.
            if((int)($after['session']['discount_amount']??0)!==(int)$after['discount'])
                $this->pdo->prepare('UPDATE table_sessions SET discount_amount=? WHERE id=?')
                    ->execute([(int)$after['discount'],$sessionId]);
            if($ownsTransaction)$this->pdo->commit();
            return ['success'=>true,'duplicate'=>false,'order_id'=>$orderId,'session_id'=>$sessionId,
                'accounting_origin'=>'late_served','added_total'=>(int)$posted['total_amount'],
                'remaining_total'=>(int)$after['remaining_total'],'account_signature'=>(string)$after['signature']];
        }catch(Throwable $e){
            if($ownsTransaction&&$this->pdo->inTransaction())$this->pdo->rollBack();
            throw $e;
        }
    }

    private function cashier(array $user): array
    {
        $id=(int)($user['id']??0);$actor=$id>0?$this->identity->findActiveById($id):null;
        if(!is_array($actor)||((string)($actor['role']??'')!=='admin'&&!$this->capabilities->has('cashier_accounts',$actor)))
            throw new SettlementException('forbidden','ثبت قلم جاافتاده فقط برای صندوق‌دار مجاز است.',403);
        return $actor;
    }
}
