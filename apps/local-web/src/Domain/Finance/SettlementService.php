<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\Finance;

use PDO;
use Sokna\Local\Core\Capabilities;
use Sokna\Local\Core\IdentityRepository;
use Sokna\Local\Domain\Orders\BusinessClock;
use Sokna\Local\Domain\Tax\TaxService;
use Sokna\Local\Domain\Integrations\SubscriberService;
use Throwable;

final class SettlementService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly IdentityRepository $identity,
        private readonly Capabilities $capabilities,
        private readonly BusinessClock $clock,
        private readonly FinancialPeriodService $periods,
        private readonly TaxService $tax,
        private readonly SubscriberService $subscribers,
    ) {}

    public function account(int $sessionId): array
    {
        $this->pdo->beginTransaction();
        try{
            $account=$this->accountTx($sessionId);
            $this->pdo->commit();
            return $account;
        }catch(Throwable $e){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $e;
        }
    }

    public function setDiscount(int $sessionId,?string $type,int $value,array $user): array
    {
        $this->pdo->beginTransaction();
        try{
            $actor=$this->assertCashier($user);
            $session=$this->lockSession($sessionId);
            if(!in_array((string)$session['status'],['active','pending'],true))
                throw new SettlementStateConflict('session_closed','این حساب دیگر قابل تغییر نیست.',409);
            if($this->hasActiveItemizedTx($sessionId))
                throw new SettlementStateConflict('itemized_locked','پس از شروع پرداخت جداگانه، تخفیف حساب قابل تغییر نیست.',409);

            $subtotal=$this->confirmedSubtotalTx($sessionId);
            $type=$type===null||trim($type)===''?null:trim($type);
            if($type!==null&&!in_array($type,['percent','fixed'],true))
                throw new SettlementException('invalid_discount','نوع تخفیف معتبر نیست.',422);
            $value=max(0,$value);
            if($type==='percent'&&$value>100)
                throw new SettlementException('invalid_discount','درصد تخفیف نمی‌تواند بیشتر از صد باشد.',422);
            if($type==='fixed'&&$value>$subtotal)
                throw new SettlementException('invalid_discount','تخفیف ثابت نمی‌تواند بیشتر از جمع فاکتور باشد.',422);
            if($type===null)$value=0;
            $amount=self::discountAmount($subtotal,$type,$value);

            $this->pdo->prepare(
                'INSERT INTO invoice_discount_audit(session_id,previous_type,previous_value,previous_amount,new_type,new_value,new_amount,subtotal,actor_user_id)
                 VALUES(?,?,?,?,?,?,?,?,?)'
            )->execute([
                $sessionId,$session['discount_type']??null,(int)($session['discount_value']??0),(int)($session['discount_amount']??0),
                $type,$value,$amount,$subtotal,(int)$actor['id']
            ]);
            $this->pdo->prepare(
                'UPDATE table_sessions SET discount_type=?,discount_value=?,discount_amount=?,discount_by_user_id=?,discount_updated_at=NOW() WHERE id=?'
            )->execute([$type,$value,$amount,(int)$actor['id'],$sessionId]);
            $this->audit('settlement.discount_updated','table_session',$sessionId,(int)$actor['id'],[
                'discount_type'=>$type,'discount_value'=>$value,'discount_amount'=>$amount,'subtotal'=>$subtotal
            ]);
            $this->pdo->commit();
            return ['session_id'=>$sessionId,'discount_type'=>$type,'discount_value'=>$value,'discount_amount'=>$amount];
        }catch(Throwable $e){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $e;
        }
    }

    public function settle(array $data,array $user): array
    {
        $this->pdo->beginTransaction();
        try{
            $actor=$this->assertCashier($user);
            $sessionId=(int)($data['session_id']??0);
            if($sessionId<1)throw new SettlementException('invalid_session','حساب میز معتبر نیست.',422);

            // Stable session lock serializes lost-ACK retries before request lookup.
            $this->lockSession($sessionId);
            $requestId=self::requestId((string)($data['request_id']??''));
            $destination=(string)($data['destination']??'direct');
            if(!in_array($destination,['direct','subscriber','accommodation'],true))
                throw new SettlementException('invalid_destination','مقصد تسویه معتبر نیست.',422,['destination'=>$destination]);
            if($destination!=='accommodation'){
                $acc=$this->pdo->prepare('SELECT * FROM accommodation_transfers WHERE session_id=? LIMIT 1 FOR UPDATE');
                $acc->execute([$sessionId]);$pendingTransfer=$acc->fetch(PDO::FETCH_ASSOC);
                if(is_array($pendingTransfer)&&empty($pendingTransfer['resolved_at'])){
                    $status=(string)$pendingTransfer['status'];$ambiguous=(int)($pendingTransfer['suspicious_response']??0)===1;
                    if(in_array($status,['pending','posted','void_pending','void_failed','voided'],true)||$ambiguous)
                        throw new SettlementStateConflict('accommodation_transfer_open','این حساب به عملیات اقامتگاه در حال پیگیری متصل است؛ ابتدا همان انتقال را تعیین تکلیف کن.',409);
                    if($status==='failed'){
                        $this->pdo->prepare('UPDATE accommodation_transfers SET resolved_at=NOW(),resolved_by_user_id=?,resolution_method=? WHERE id=? AND resolved_at IS NULL')
                            ->execute([(int)$actor['id'],$destination.'_settlement',(int)$pendingTransfer['id']]);
                    }
                }
            }
            $mode=(string)($data['mode']??'full');
            if(!in_array($mode,['full','itemized'],true))
                throw new SettlementException('invalid_mode','نوع تسویه معتبر نیست.',422);
            if($destination!=='direct'&&$mode!=='full')
                throw new SettlementException('adapter_requires_full','تسویه مشتری/اقامتگاه فقط روی کل مانده حساب انجام می‌شود.',409);
            $selection=$mode==='itemized'?self::normalizeSelection((array)($data['selection']??[])):[];
            $subscriberId=$destination==='subscriber'?(int)($data['subscriber_id']??0):0;
            $accommodationTransferId=$destination==='accommodation'?(int)($data['accommodation_transfer_id']??0):0;
            if($destination==='subscriber'&&$subscriberId<1)throw new SettlementException('subscriber_required','مشتری انتخاب نشده است.',422);
            if($destination==='accommodation'&&$accommodationTransferId<1)throw new SettlementException('accommodation_transfer_required','انتقال اقامتگاه مشخص نیست.',422);
            $adapterKey=$destination==='subscriber'?'subscriber:'.$subscriberId:($destination==='accommodation'?'accommodation:'.$accommodationTransferId:'');
            $fingerprint=self::requestFingerprint($sessionId,$destination,$mode,$selection,$adapterKey);

            $existing=$this->findRequestTx($requestId);
            if($existing!==null){
                $this->assertRequestMatch($existing,$sessionId,$destination,$fingerprint);
                $result=$this->recordResult($existing,true);
                $this->pdo->commit();
                return $result;
            }

            $accommodationTransfer=null;
            if($destination==='accommodation'){
                $tr=$this->pdo->prepare("SELECT * FROM accommodation_transfers WHERE id=? FOR UPDATE");
                $tr->execute([$accommodationTransferId]);$accommodationTransfer=$tr->fetch(PDO::FETCH_ASSOC);
                if(!is_array($accommodationTransfer)||(int)$accommodationTransfer['session_id']!==$sessionId)
                    throw new SettlementException('accommodation_transfer_not_found','انتقال اقامتگاه برای این حساب پیدا نشد.',404);
                if((string)$accommodationTransfer['status']!=='posted')
                    throw new SettlementStateConflict('accommodation_not_posted','ثبت هزینه در اقامتگاه هنوز قطعی نشده است.',409);
            }
            $account=$this->accountTx($sessionId);
            $this->assertExpected(
                $account,
                (int)($data['expected_session_id']??0),
                (int)($data['expected_remaining_total']??-1),
                (string)($data['expected_signature']??'')
            );
            $review=$mode==='full'?$this->reviewAllRemaining($account):$this->reviewSelection($account,$selection);
            $settledAt=date('Y-m-d H:i:s');
            if($destination==='accommodation'){
                if((int)$review['total']!==(int)$accommodationTransfer['amount']||!hash_equals((string)$accommodationTransfer['account_signature'],(string)$account['signature']))
                    throw new SettlementStateConflict('accommodation_account_changed','حساب پس از ساخت انتقال اقامتگاه تغییر کرده است؛ تکمیل محلی متوقف شد.',409);
                $period=$this->periods->periodByIdTx((int)$accommodationTransfer['financial_period_id']);
                if((string)$period['status']!=='open')throw new SettlementStateConflict('period_closed','دوره مالی انتقال اقامتگاه بسته شده است.',409);
                $issued=['period'=>$period,'invoice_number'=>(string)$accommodationTransfer['invoice_number']];
                $snapshot=json_decode((string)$accommodationTransfer['invoice_snapshot_json'],true);
                if(!is_array($snapshot))throw new SettlementException('accommodation_snapshot_invalid','snapshot انتقال اقامتگاه معتبر نیست.',409);
            }else{
                $issued=$this->periods->issueDocumentNumberTx($settledAt,(int)$actor['id'],'I');
                $snapshot=$this->paymentSnapshot($account,$review,(string)$issued['invoice_number'],$settledAt);
            }
            $business=$this->clock->assignment($settledAt);
            $subscriberLedgerId=null;
            if($destination==='subscriber'){
                $ledger=$this->subscribers->insertLedgerTx($subscriberId,'invoice',(int)$review['total'],(int)$actor['id'],(int)$issued['period']['id'],$sessionId,null,'S-'.$sessionId,null,$snapshot,'settlement:subscriber:'.$requestId);
                $subscriberLedgerId=(int)$ledger['id'];
            }

            $stmt=$this->pdo->prepare(
                "INSERT INTO settlement_records(session_id,financial_period_id,invoice_number,invoice_snapshot_json,destination,table_name_snapshot,
                 subtotal,discount,taxable_amount,tax_amount,total,status,actor_user_id,subscriber_ledger_entry_id,accommodation_transfer_id,request_id,request_fingerprint,settlement_kind,closes_session,
                 remaining_subtotal,remaining_discount,remaining_tax,remaining_total,allocation_version,settled_at,business_date,business_shift_key,
                 business_shift_label,business_cutoff_snapshot)
                 VALUES(?,?,?,?,?,?,?,?,?,?,?,'completed',?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
            );
            $stmt->execute([
                $sessionId,(int)$issued['period']['id'],(string)$issued['invoice_number'],
                json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),
                $destination,(string)$account['session']['table_name'],
                (int)$review['subtotal'],(int)$review['discount'],(int)$review['taxable'],(int)$review['tax'],(int)$review['total'],
                (int)$actor['id'],$subscriberLedgerId,$accommodationTransferId>0?$accommodationTransferId:null,$requestId,$fingerprint,$mode,!empty($review['closes_session'])?1:0,
                (int)$review['remaining_subtotal'],(int)$review['remaining_discount'],(int)$review['remaining_tax'],(int)$review['remaining_total'],
                (int)$account['allocation_version'],$settledAt,(string)$business['business_date'],(string)$business['shift_key'],
                (string)$business['shift_label'],(string)$business['cutoff']
            ]);
            $settlementId=(int)$this->pdo->lastInsertId();
            if($destination==='accommodation'){
                $this->pdo->prepare('UPDATE accommodation_transfers SET local_finalize_pending=0 WHERE id=?')->execute([$accommodationTransferId]);
            }
            $lineStmt=$this->pdo->prepare(
                'INSERT INTO settlement_record_lines(settlement_id,order_item_id,order_id,item_id_snapshot,item_name_snapshot,unit_price_snapshot,
                 quantity,gross_amount,discount_amount,net_amount,taxable_amount,tax_rate_bps,tax_amount,final_amount)
                 VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            foreach($review['lines'] as $line){
                $lineStmt->execute([
                    $settlementId,(int)$line['order_item_id'],(int)$line['order_id'],$line['item_id_snapshot'],
                    (string)$line['item_name_snapshot'],(int)$line['unit_price_snapshot'],(int)$line['quantity'],
                    (int)$line['gross_amount'],(int)$line['discount_amount'],(int)$line['net_amount'],
                    (int)$line['taxable_amount'],(int)$line['tax_rate_bps'],(int)$line['tax_amount'],(int)$line['final_amount']
                ]);
            }

            $completedOrders=0;
            if(!empty($review['closes_session'])){
                $complete=$this->pdo->prepare("UPDATE orders SET status='completed' WHERE id=? AND status='accounted'");
                $history=$this->pdo->prepare("INSERT INTO order_status_history(order_id,from_status,to_status,actor_user_id) VALUES(?,'accounted','completed',?)");
                foreach($account['orders'] as $order){
                    $complete->execute([(int)$order['id']]);
                    if($complete->rowCount()>0){$history->execute([(int)$order['id'],(int)$actor['id']]);$completedOrders++;}
                }
                $this->pdo->prepare(
                    "UPDATE table_sessions SET status='closed',live_table_guard=NULL,discount_amount=?,checkout_subtotal=?,checkout_discount=?,
                     checkout_taxable=?,checkout_tax=?,checkout_total=?,settlement_destination=?,checkout_voided_at=NULL,checkout_voided_by_user_id=NULL,
                     closed_by_user_id=?,ended_reason='checkout',ended_at=NOW() WHERE id=?"
                )->execute([
                    (int)$account['discount'],(int)$account['subtotal'],(int)$account['discount'],(int)$account['taxable'],
                    (int)$account['tax'],(int)$account['total'],$destination,(int)$actor['id'],$sessionId
                ]);
            }

            $this->audit('settlement.completed','settlement_record',$settlementId,(int)$actor['id'],[
                'session_id'=>$sessionId,'invoice_number'=>$issued['invoice_number'],'financial_period_id'=>(int)$issued['period']['id'],
                'destination'=>$destination,'settlement_kind'=>$mode,'closes_session'=>!empty($review['closes_session']),
                'subtotal'=>(int)$review['subtotal'],'discount'=>(int)$review['discount'],'tax'=>(int)$review['tax'],
                'total'=>(int)$review['total'],'remaining_total'=>(int)$review['remaining_total'],'line_count'=>count($review['lines'])
            ]);
            $record=$this->findRequestTx($requestId);
            if($record===null)throw new SettlementException('record_missing','سند تسویه پس از ثبت قابل بازیابی نیست.',500);
            $result=$this->recordResult($record,false)+['completed_orders'=>$completedOrders];
            $this->pdo->commit();
            return $result;
        }catch(Throwable $e){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $e;
        }
    }

    public function reverse(int $settlementId,string $reason,string $requestId,array $user,bool $externalConfirmed=false): array
    {
        $this->pdo->beginTransaction();
        try{
            $actor=$this->assertCashier($user);
            $reason=self::truncate(trim($reason),300);
            if($reason==='')throw new SettlementException('reason_required','دلیل برگشت رسید را ثبت کن.',422);
            $requestId=self::requestId($requestId);

            $stmt=$this->pdo->prepare('SELECT * FROM settlement_records WHERE id=? FOR UPDATE');
            $stmt->execute([$settlementId]);$record=$stmt->fetch(PDO::FETCH_ASSOC);
            if(!is_array($record)||$record['status']!=='completed')
                throw new SettlementException('settlement_not_found','رسید قابل برگشت پیدا نشد.',404);
            if((string)$record['destination']==='accommodation'&&!$externalConfirmed)
                throw new SettlementStateConflict('external_reversal_required','ابتدا برگشت هزینه در اقامتگاه باید قطعی شود.',409);

            $existing=$this->pdo->prepare("SELECT * FROM settlement_records WHERE reverses_settlement_id=? AND status='reversal' LIMIT 1 FOR UPDATE");
            $existing->execute([$settlementId]);$reversal=$existing->fetch(PDO::FETCH_ASSOC);
            if(is_array($reversal)){
                $result=$this->recordResult($reversal,true);
                $this->pdo->commit();
                return $result;
            }

            $later=$this->pdo->prepare(
                "SELECT 1 FROM settlement_records sr WHERE sr.session_id=? AND sr.id>? AND sr.status='completed'
                 AND NOT EXISTS(SELECT 1 FROM settlement_records rv WHERE rv.reverses_settlement_id=sr.id AND rv.status='reversal') LIMIT 1"
            );
            $later->execute([(int)$record['session_id'],$settlementId]);
            if($later->fetchColumn()!==false)
                throw new SettlementStateConflict('later_settlement_exists','رسید جدیدتری برای این حساب وجود دارد؛ ابتدا آخرین رسید را بررسی کن.',409);

            $dupRequest=$this->findRequestTx($requestId);
            if($dupRequest!==null)
                throw new SettlementStateConflict('request_id_conflict','شناسه برگشت قبلاً برای سند دیگری استفاده شده است.',409);

            $subscriberReversalId=null;
            if((string)$record['destination']==='subscriber'&&(int)($record['subscriber_ledger_entry_id']??0)>0){
                $ledger=$this->subscribers->reverseEntryTx((int)$record['subscriber_ledger_entry_id'],$reason,(int)$actor['id'],'settlement:subscriber:reversal:'.$settlementId);
                $subscriberReversalId=(int)$ledger['id'];
            }
            $issuedAt=date('Y-m-d H:i:s');
            $issued=$this->periods->issueDocumentNumberTx($issuedAt,(int)$actor['id'],'R');
            $snapshot=json_decode((string)$record['invoice_snapshot_json'],true);
            if(!is_array($snapshot))$snapshot=[];
            $snapshot['number']=(string)$issued['invoice_number'];
            $snapshot['issued_at']=date(DATE_ATOM,strtotime($issuedAt));
            $snapshot['document_type']='reversal';
            $snapshot['reverses_invoice_number']=(string)$record['invoice_number'];
            $business=$this->clock->assignment($issuedAt);
            $fingerprint=hash('sha256',json_encode(['reverses'=>$settlementId,'reason'=>$reason],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));

            $insert=$this->pdo->prepare(
                "INSERT INTO settlement_records(session_id,financial_period_id,invoice_number,invoice_snapshot_json,destination,table_name_snapshot,
                 subtotal,discount,taxable_amount,tax_amount,total,status,reverses_settlement_id,actor_user_id,request_id,request_fingerprint,
                 subscriber_ledger_entry_id,accommodation_transfer_id,settlement_kind,closes_session,remaining_subtotal,remaining_discount,remaining_tax,remaining_total,allocation_version,
                 void_reason,voided_by_user_id,voided_at,settled_at,business_date,business_shift_key,business_shift_label,business_cutoff_snapshot)
                 VALUES(?,?,?,?,?,?,?,?,?,?,?,'reversal',?,?,?,?,?,?,'reversal',0,?,?,?,?,?,?,?,?,?,?,?,?,?)"
            );
            $insert->execute([
                (int)$record['session_id'],(int)$issued['period']['id'],(string)$issued['invoice_number'],
                json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),
                (string)$record['destination'],(string)$record['table_name_snapshot'],(int)$record['subtotal'],(int)$record['discount'],
                (int)$record['taxable_amount'],(int)$record['tax_amount'],(int)$record['total'],$settlementId,(int)$actor['id'],$requestId,$fingerprint,
                $subscriberReversalId,(int)($record['accommodation_transfer_id']??0)?:null,
                0,0,0,0,(int)$record['allocation_version'],$reason,(int)$actor['id'],$issuedAt,$issuedAt,
                (string)$business['business_date'],(string)$business['shift_key'],(string)$business['shift_label'],(string)$business['cutoff']
            ]);
            $reversalId=(int)$this->pdo->lastInsertId();
            $copy=$this->pdo->prepare(
                'INSERT INTO settlement_record_lines(settlement_id,order_item_id,order_id,item_id_snapshot,item_name_snapshot,unit_price_snapshot,
                 quantity,gross_amount,discount_amount,net_amount,taxable_amount,tax_rate_bps,tax_amount,final_amount)
                 SELECT ?,order_item_id,order_id,item_id_snapshot,item_name_snapshot,unit_price_snapshot,quantity,gross_amount,discount_amount,
                 net_amount,taxable_amount,tax_rate_bps,tax_amount,final_amount FROM settlement_record_lines WHERE settlement_id=? ORDER BY id'
            );
            $copy->execute([$reversalId,$settlementId]);
            if($copy->rowCount()<1)throw new SettlementException('reversal_lines_missing','خطوط مالی رسید برای برگشت پیدا نشد.',409);

            $this->pdo->prepare(
                "UPDATE settlement_records SET void_reason=?,voided_by_user_id=?,voided_at=NOW() WHERE id=? AND status='completed'"
            )->execute([$reason,(int)$actor['id'],$settlementId]);

            if((int)$record['closes_session']===1){
                $session=$this->lockSession((int)$record['session_id']);
                $other=$this->pdo->prepare("SELECT id FROM table_sessions WHERE table_id=? AND id<>? AND status IN('active','pending') LIMIT 1 FOR UPDATE");
                $other->execute([(int)$session['table_id'],(int)$session['id']]);
                if($other->fetchColumn()!==false)
                    throw new SettlementStateConflict('table_in_use','میز در حساب فعال دیگری استفاده می‌شود و رسید فعلی قابل بازگشت خودکار نیست.',409);
                $this->pdo->prepare(
                    "UPDATE table_sessions SET status='active',live_table_guard=table_id,checkout_subtotal=NULL,checkout_discount=NULL,
                     checkout_taxable=NULL,checkout_tax=NULL,checkout_total=NULL,settlement_destination=NULL,checkout_voided_at=NOW(),
                     checkout_voided_by_user_id=?,closed_by_user_id=NULL,ended_reason=NULL,ended_at=NULL WHERE id=?"
                )->execute([(int)$actor['id'],(int)$session['id']]);
                $orders=$this->pdo->prepare("SELECT id FROM orders WHERE session_id=? AND status='completed' FOR UPDATE");
                $orders->execute([(int)$session['id']]);
                $reopen=$this->pdo->prepare("UPDATE orders SET status='accounted' WHERE id=? AND status='completed'");
                $history=$this->pdo->prepare("INSERT INTO order_status_history(order_id,from_status,to_status,actor_user_id) VALUES(?,'completed','accounted',?)");
                foreach($orders->fetchAll(PDO::FETCH_COLUMN) as $orderId){
                    $reopen->execute([(int)$orderId]);
                    if($reopen->rowCount()>0)$history->execute([(int)$orderId,(int)$actor['id']]);
                }
            }

            if((string)$record['destination']==='accommodation'&&(int)($record['accommodation_transfer_id']??0)>0){
                $this->pdo->prepare('UPDATE accommodation_transfers SET local_reversal_pending=0 WHERE id=?')->execute([(int)$record['accommodation_transfer_id']]);
            }

            $this->audit('settlement.reversed','settlement_record',$settlementId,(int)$actor['id'],[
                'reversal_settlement_id'=>$reversalId,'reversal_invoice_number'=>$issued['invoice_number'],
                'reason'=>$reason,'session_id'=>(int)$record['session_id'],'exact_lines'=>true
            ]);
            $reversal=$this->pdo->query("SELECT * FROM settlement_records WHERE id={$reversalId}")->fetch(PDO::FETCH_ASSOC);
            $result=$this->recordResult($reversal?:[],false);
            $this->pdo->commit();
            return $result;
        }catch(Throwable $e){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $e;
        }
    }

    /** Caller owns transaction. Reserves the canonical invoice number/snapshot used by an external full-payment adapter. */
    public function prepareExternalFullTx(int $sessionId,int $actorUserId): array
    {
        if(!$this->pdo->inTransaction())throw new \LogicException('External settlement preparation requires an open transaction.');
        $account=$this->accountTx($sessionId);
        if((int)$account['receipt_count']>0)throw new SettlementStateConflict('partial_payment_exists','پس از شروع پرداخت جداگانه، انتقال کل حساب به سرویس خارجی مجاز نیست.',409);
        $review=$this->reviewAllRemaining($account);
        $issuedAt=date('Y-m-d H:i:s');
        $issued=$this->periods->issueDocumentNumberTx($issuedAt,$actorUserId,'I');
        return [
            'account'=>$account,'review'=>$review,'issued'=>$issued,'issued_at'=>$issuedAt,
            'snapshot'=>$this->paymentSnapshot($account,$review,(string)$issued['invoice_number'],$issuedAt),
        ];
    }

    public function accountTx(int $sessionId): array
    {
        $session=$this->lockSession($sessionId);
        if(!in_array((string)$session['status'],['active','pending'],true))
            throw new SettlementStateConflict('session_closed','این حساب دیگر در وضعیت قابل تسویه نیست.',409);

        $ordersStmt=$this->pdo->prepare(
            "SELECT id,status,total_amount FROM orders WHERE session_id=? AND status IN('pending_approval','new','accounted') ORDER BY id FOR UPDATE"
        );
        $ordersStmt->execute([$sessionId]);$orders=$ordersStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach($orders as $order){
            if(in_array((string)$order['status'],['pending_approval','new'],true))
                throw new SettlementStateConflict('unconfirmed_orders','یک یا چند سفارش تازه هنوز تأیید نشده است.',409);
        }
        $confirmed=array_values(array_filter($orders,static fn(array $o):bool=>(string)$o['status']==='accounted'));
        if(!$confirmed)throw new SettlementStateConflict('no_accounted_orders','برای این میز حساب تأییدشده‌ای وجود ندارد.',409);
        $subtotal=array_sum(array_map(static fn(array $o):int=>(int)$o['total_amount'],$confirmed));
        $discount=self::discountAmount($subtotal,$session['discount_type']??null,(int)($session['discount_value']??0));

        $ids=array_map('intval',array_column($confirmed,'id'));$ph=implode(',',array_fill(0,count($ids),'?'));
        $lineStmt=$this->pdo->prepare(
            "SELECT oi.id order_item_id,oi.order_id,oi.item_id,oi.item_name,oi.unit_price,oi.quantity,oi.line_total,oi.item_note,
                    oi.tax_policy_snapshot,oi.tax_rate_bps_snapshot,oi.tax_rate_version_id,oi.tax_item_policy_version_id
             FROM order_items oi WHERE oi.order_id IN($ph) AND oi.quantity>0 ORDER BY oi.id FOR UPDATE"
        );
        $lineStmt->execute($ids);$lines=$lineStmt->fetchAll(PDO::FETCH_ASSOC);
        if(!$lines)throw new SettlementStateConflict('no_lines','برای این میز قلم قابل تسویه‌ای وجود ندارد.',409);
        $calculated=TaxService::calculateInvoiceLines($lines,$discount);
        if((int)$calculated['subtotal']!==$subtotal)
            throw new SettlementStateConflict('subtotal_mismatch','جمع اقلام سفارش با مبلغ حساب هماهنگ نیست.',409);

        $taxAware=false;foreach($lines as $line)if((string)($line['tax_policy_snapshot']??'disabled')!=='disabled'){$taxAware=true;break;}
        $allocationVersion=$taxAware?2:1;
        $activeVersions=$this->pdo->prepare(
            "SELECT DISTINCT sr.allocation_version FROM settlement_records sr WHERE sr.session_id=? AND sr.status='completed'
             AND NOT EXISTS(SELECT 1 FROM settlement_records rv WHERE rv.reverses_settlement_id=sr.id AND rv.status='reversal')"
        );
        $activeVersions->execute([$sessionId]);
        foreach($activeVersions->fetchAll(PDO::FETCH_COLUMN) as $version){
            if((int)$version!==$allocationVersion)
                throw new SettlementStateConflict('allocation_version_conflict','نسخه محاسبه پرداخت‌های این حساب با وضعیت فعلی سازگار نیست.',409);
        }

        $paid=$this->pdo->prepare(
            "SELECT sl.order_item_id,SUM(sl.quantity) paid_quantity,SUM(sl.discount_amount) paid_discount,SUM(sl.tax_amount) paid_tax,
                    SUM(sl.gross_amount) paid_gross,SUM(sl.net_amount) paid_net,SUM(sl.taxable_amount) paid_taxable,SUM(sl.final_amount) paid_final
             FROM settlement_record_lines sl JOIN settlement_records sr ON sr.id=sl.settlement_id
             WHERE sr.session_id=? AND sr.status='completed' AND sr.allocation_version=?
               AND NOT EXISTS(SELECT 1 FROM settlement_records rv WHERE rv.reverses_settlement_id=sr.id AND rv.status='reversal')
             GROUP BY sl.order_item_id"
        );
        $paid->execute([$sessionId,$allocationVersion]);$paidBy=[];
        foreach($paid->fetchAll(PDO::FETCH_ASSOC) as $row)$paidBy[(int)$row['order_item_id']]=$row;

        $totals=$this->pdo->prepare(
            "SELECT COALESCE(SUM(subtotal),0) paid_subtotal,COALESCE(SUM(discount),0) paid_discount,
                    COALESCE(SUM(taxable_amount),0) paid_taxable,COALESCE(SUM(tax_amount),0) paid_tax,
                    COALESCE(SUM(total),0) paid_total,COUNT(*) receipt_count
             FROM settlement_records sr WHERE session_id=? AND status='completed' AND allocation_version=?
               AND NOT EXISTS(SELECT 1 FROM settlement_records rv WHERE rv.reverses_settlement_id=sr.id AND rv.status='reversal')"
        );
        $totals->execute([$sessionId,$allocationVersion]);$paidTotals=$totals->fetch(PDO::FETCH_ASSOC)?:[];

        $fullBy=[];foreach($calculated['lines'] as $line)$fullBy[(int)$line['order_item_id']]=$line;
        $remainingItems=[];$paidQuantities=[];$byOrder=[];
        foreach($lines as $line){
            $id=(int)$line['order_item_id'];$ordered=(int)$line['quantity'];$p=$paidBy[$id]??[];
            $paidQty=(int)($p['paid_quantity']??0);
            if($paidQty<0||$paidQty>$ordered)throw new SettlementStateConflict('paid_quantity_invalid','وضعیت پرداخت این حساب ناسازگار است.',409);
            $remaining=$ordered-$paidQty;$calc=$fullBy[$id]??[];
            $line['id']=$id;
            $line['paid_quantity']=$paidQty;
            $line['paid_discount_amount']=(int)($p['paid_discount']??0);
            $line['paid_tax_amount']=(int)($p['paid_tax']??0);
            $line['remaining_quantity']=$remaining;
            $line['invoice_discount_amount']=(int)($calc['invoice_discount_amount']??0);
            $line['invoice_tax_amount']=(int)($calc['invoice_tax_amount']??0);
            $remainingItems[]=$line;$byOrder[(int)$line['order_id']][]=$line;
            if($paidQty>0)$paidQuantities[$id]=$paidQty;
        }

        $paidSubtotal=(int)($paidTotals['paid_subtotal']??0);$paidDiscount=(int)($paidTotals['paid_discount']??0);
        $paidTaxable=(int)($paidTotals['paid_taxable']??0);$paidTax=(int)($paidTotals['paid_tax']??0);$paidTotal=(int)($paidTotals['paid_total']??0);
        if($paidSubtotal>(int)$calculated['subtotal']||$paidDiscount>(int)$calculated['discount']||$paidTax>(int)$calculated['tax']||$paidTotal>(int)$calculated['total'])
            throw new SettlementStateConflict('paid_totals_invalid','مجموع پرداخت‌های این حساب ناسازگار است.',409);

        foreach($confirmed as &$order)$order['items']=$byOrder[(int)$order['id']]??[];unset($order);
        $signature=self::accountSignature($session,$confirmed,[
            'paid_quantities'=>$paidQuantities,'paid_subtotal'=>$paidSubtotal,'paid_discount'=>$paidDiscount,
            'paid_taxable'=>$paidTaxable,'paid_tax'=>$paidTax,'paid_total'=>$paidTotal
        ]);
        return [
            'session'=>$session,'orders'=>$confirmed,'items'=>$remainingItems,'allocation_version'=>$allocationVersion,
            'subtotal'=>(int)$calculated['subtotal'],'discount'=>(int)$calculated['discount'],'net'=>(int)$calculated['net'],
            'taxable'=>(int)$calculated['taxable'],'tax'=>(int)$calculated['tax'],'total'=>(int)$calculated['total'],
            'paid_subtotal'=>$paidSubtotal,'paid_discount'=>$paidDiscount,'paid_taxable'=>$paidTaxable,'paid_tax'=>$paidTax,'paid_total'=>$paidTotal,
            'remaining_subtotal'=>max(0,(int)$calculated['subtotal']-$paidSubtotal),
            'remaining_discount'=>max(0,(int)$calculated['discount']-$paidDiscount),
            'remaining_taxable'=>max(0,(int)$calculated['taxable']-$paidTaxable),
            'remaining_tax'=>max(0,(int)$calculated['tax']-$paidTax),
            'remaining_total'=>max(0,(int)$calculated['total']-$paidTotal),
            'receipt_count'=>(int)($paidTotals['receipt_count']??0),'signature'=>$signature,
        ];
    }

    private function reviewAllRemaining(array $account): array
    {
        $selection=[];foreach($account['items'] as $item)if((int)$item['remaining_quantity']>0)$selection[(int)$item['id']]=(int)$item['remaining_quantity'];
        if(!$selection)throw new SettlementStateConflict('nothing_remaining','برای این حساب مانده‌ای جهت تسویه وجود ندارد.',409);
        return $this->reviewSelection($account,$selection);
    }

    private function reviewSelection(array $account,array $selection): array
    {
        $items=[];foreach($account['items'] as $item)$items[(int)$item['id']]=$item;
        $lines=[];$subtotal=0;$discount=0;$taxable=0;$tax=0;$total=0;
        foreach($selection as $itemId=>$quantity){
            $item=$items[$itemId]??null;
            if(!$item||$quantity<1||(int)$item['remaining_quantity']<$quantity)
                throw new SettlementStateConflict('selection_changed','یکی از اقلام انتخاب‌شده قبلاً پرداخت شده یا تعداد مانده آن تغییر کرده است.',409);
            $ordered=(int)$item['quantity'];$paidQty=(int)$item['paid_quantity'];$newPaidQty=$paidQty+$quantity;
            $gross=(int)$item['unit_price']*$quantity;
            $fullDiscount=(int)$item['invoice_discount_amount'];
            $targetDiscount=TaxService::proportionalTarget($fullDiscount,$ordered,$newPaidQty,$newPaidQty===$ordered);
            $lineDiscount=max(0,$targetDiscount-(int)$item['paid_discount_amount']);$lineDiscount=min($gross,$lineDiscount);
            $net=$gross-$lineDiscount;
            $policy=(string)($item['tax_policy_snapshot']??'disabled');$rate=max(0,min(10000,(int)($item['tax_rate_bps_snapshot']??0)));
            $lineTaxable=($policy!=='disabled'&&$policy!=='exempt')?$net:0;
            $cumulativeGross=(int)$item['unit_price']*$newPaidQty;$cumulativeNet=$cumulativeGross-$targetDiscount;
            $targetTax=$lineTaxable>0?TaxService::roundAmount($cumulativeNet,$rate):0;
            $lineTax=max(0,$targetTax-(int)$item['paid_tax_amount']);$final=$net+$lineTax;
            $lines[]=[
                'order_item_id'=>$itemId,'order_id'=>(int)$item['order_id'],
                'item_id_snapshot'=>$item['item_id']!==null?(int)$item['item_id']:null,
                'item_name_snapshot'=>(string)$item['item_name'],'unit_price_snapshot'=>(int)$item['unit_price'],
                'quantity'=>$quantity,'gross_amount'=>$gross,'discount_amount'=>$lineDiscount,'net_amount'=>$net,
                'taxable_amount'=>$lineTaxable,'tax_rate_bps'=>$rate,'tax_amount'=>$lineTax,'final_amount'=>$final,
                'note'=>trim((string)($item['item_note']??''))?:null
            ];
            $subtotal+=$gross;$discount+=$lineDiscount;$taxable+=$lineTaxable;$tax+=$lineTax;$total+=$final;
        }
        if($subtotal<1)throw new SettlementStateConflict('invalid_payment','مبلغ انتخاب‌شده معتبر نیست.',409);
        $allRemaining=true;
        foreach($items as $id=>$item){
            $remaining=(int)$item['remaining_quantity'];if($remaining<=0)continue;
            if(($selection[$id]??0)!==$remaining){$allRemaining=false;break;}
        }
        return [
            'selection'=>$selection,'lines'=>$lines,'subtotal'=>$subtotal,'discount'=>$discount,'net'=>$subtotal-$discount,
            'taxable'=>$taxable,'tax'=>$tax,'total'=>$total,'closes_session'=>$allRemaining,
            'remaining_subtotal'=>max(0,(int)$account['remaining_subtotal']-$subtotal),
            'remaining_discount'=>max(0,(int)$account['remaining_discount']-$discount),
            'remaining_taxable'=>max(0,(int)$account['remaining_taxable']-$taxable),
            'remaining_tax'=>max(0,(int)$account['remaining_tax']-$tax),
            'remaining_total'=>max(0,(int)$account['remaining_total']-$total),
        ];
    }

    private function paymentSnapshot(array $account,array $review,string $number,string $issuedAt): array
    {
        $taxAware=(int)$account['allocation_version']===2;$items=[];
        foreach($review['lines'] as $line){
            $item=[
                'order_item_id'=>(int)$line['order_item_id'],'name'=>(string)$line['item_name_snapshot'],
                'quantity'=>(int)$line['quantity'],'unit_price'=>(int)$line['unit_price_snapshot'],
                'line_total'=>(int)$line['gross_amount'],'line_discount'=>(int)$line['discount_amount'],
                'line_net'=>(int)$line['net_amount'],'note'=>$line['note']??null
            ];
            if($taxAware)$item += [
                'taxable_amount'=>(int)$line['taxable_amount'],'tax_rate_bps'=>(int)$line['tax_rate_bps'],
                'tax_amount'=>(int)$line['tax_amount'],'line_final'=>(int)$line['final_amount']
            ];
            $items[]=$item;
        }
        $snapshot=[
            'version'=>$taxAware?3:2,'number'=>$number,'issued_at'=>date(DATE_ATOM,strtotime($issuedAt)),
            'table_name'=>(string)$account['session']['table_name'],'account_subtotal'=>(int)$account['subtotal'],
            'account_discount'=>(int)$account['discount'],'account_total'=>(int)$account['total'],
            'paid_before'=>(int)$account['paid_total'],'subtotal'=>(int)$review['subtotal'],
            'discount'=>(int)$review['discount'],'total'=>(int)$review['total'],
            'remaining_after'=>(int)$review['remaining_total'],'items'=>$items
        ];
        if($taxAware)$snapshot += [
            'account_net'=>(int)$account['net'],'account_taxable'=>(int)$account['taxable'],'account_tax'=>(int)$account['tax'],
            'paid_tax_before'=>(int)$account['paid_tax'],'net'=>(int)$review['net'],'taxable'=>(int)$review['taxable'],
            'tax'=>(int)$review['tax'],'remaining_tax_after'=>(int)$review['remaining_tax']
        ];
        return $snapshot;
    }

    private function findRequestTx(string $requestId): ?array
    {
        $stmt=$this->pdo->prepare('SELECT * FROM settlement_records WHERE request_id=? LIMIT 1 FOR UPDATE');
        $stmt->execute([$requestId]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row)?$row:null;
    }

    private function assertRequestMatch(array $record,int $sessionId,string $destination,string $fingerprint): void
    {
        if((int)$record['session_id']!==$sessionId||(string)$record['destination']!==$destination
            ||!hash_equals((string)($record['request_fingerprint']??''),$fingerprint)){
            throw new SettlementStateConflict('request_id_conflict','این شناسه تسویه قبلاً برای درخواست دیگری استفاده شده است.',409);
        }
    }

    private function recordResult(array $record,bool $idempotent): array
    {
        return [
            'settlement_id'=>(int)($record['id']??0),'invoice_number'=>(string)($record['invoice_number']??''),
            'financial_period_id'=>(int)($record['financial_period_id']??0),'session_id'=>(int)($record['session_id']??0),
            'destination'=>(string)($record['destination']??''),'settlement_kind'=>(string)($record['settlement_kind']??''),
            'closes_session'=>(int)($record['closes_session']??0)===1,'subtotal_amount'=>(int)($record['subtotal']??0),
            'discount_amount'=>(int)($record['discount']??0),'taxable_amount'=>(int)($record['taxable_amount']??0),
            'tax_amount'=>(int)($record['tax_amount']??0),'total_amount'=>(int)($record['total']??0),
            'remaining_total'=>(int)($record['remaining_total']??0),'idempotent'=>$idempotent
        ];
    }

    private function lockSession(int $sessionId): array
    {
        $stmt=$this->pdo->prepare(
            'SELECT s.*,t.name table_name FROM table_sessions s JOIN cafe_tables t ON t.id=s.table_id WHERE s.id=? FOR UPDATE'
        );
        $stmt->execute([$sessionId]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row))throw new SettlementException('session_not_found','حساب میز پیدا نشد.',404);
        return $row;
    }

    private function confirmedSubtotalTx(int $sessionId): int
    {
        $stmt=$this->pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE session_id=? AND status='accounted' FOR UPDATE");
        $stmt->execute([$sessionId]);return (int)$stmt->fetchColumn();
    }

    private function hasActiveItemizedTx(int $sessionId): bool
    {
        $stmt=$this->pdo->prepare(
            "SELECT 1 FROM settlement_records sr WHERE sr.session_id=? AND sr.status='completed' AND sr.settlement_kind='itemized'
             AND NOT EXISTS(SELECT 1 FROM settlement_records rv WHERE rv.reverses_settlement_id=sr.id AND rv.status='reversal') LIMIT 1"
        );
        $stmt->execute([$sessionId]);return $stmt->fetchColumn()!==false;
    }

    private function assertExpected(array $account,int $sessionId,int $remainingTotal,string $signature): void
    {
        $signature=strtolower(trim($signature));
        if($sessionId<1||$remainingTotal<0||!preg_match('/^[a-f0-9]{64}$/',$signature))
            throw new SettlementStateConflict('expected_state_required','اطلاعات تأیید تسویه کامل نیست؛ حساب را دوباره باز کن.',409);
        if((int)$account['session']['id']!==$sessionId||(int)$account['remaining_total']!==$remainingTotal
            ||!hash_equals((string)$account['signature'],$signature)){
            throw new SettlementStateConflict('account_changed','حساب در این فاصله تغییر کرده است؛ مانده جدید را دوباره بررسی کن.',409);
        }
    }

    private function assertCashier(array $user): array
    {
        $id=(int)($user['id']??0);
        if($id<1)throw new SettlementException('forbidden','حساب کاربری معتبر نیست.',403);
        $fresh=$this->identity->findActiveById($id);
        if($fresh===null)throw new SettlementException('forbidden','حساب کاربری فعال نیست.',403);
        if((string)($fresh['role']??'')!=='admin'&&!$this->capabilities->has('cashier_accounts',$fresh))
            throw new SettlementException('forbidden','دسترسی تسویه حساب برای این حساب فعال نیست.',403);
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

    private static function accountSignature(array $session,array $orders,array $paid): string
    {
        $normOrders=[];
        foreach($orders as $order){
            $items=[];
            foreach((array)($order['items']??[]) as $line)$items[]=[
                'id'=>(int)$line['id'],'item_name'=>(string)$line['item_name'],'quantity'=>(int)$line['quantity'],
                'unit_price'=>(int)$line['unit_price'],'line_total'=>(int)$line['line_total'],
                'tax_policy_snapshot'=>(string)($line['tax_policy_snapshot']??'disabled'),
                'tax_rate_bps_snapshot'=>(int)($line['tax_rate_bps_snapshot']??0),
                'tax_rate_version_id'=>(int)($line['tax_rate_version_id']??0),
                'tax_item_policy_version_id'=>(int)($line['tax_item_policy_version_id']??0)
            ];
            usort($items,static fn(array $a,array $b):int=>$a['id']<=>$b['id']);
            $normOrders[]=['id'=>(int)$order['id'],'total_amount'=>(int)$order['total_amount'],'items'=>$items];
        }
        usort($normOrders,static fn(array $a,array $b):int=>$a['id']<=>$b['id']);
        $pq=[];foreach((array)($paid['paid_quantities']??[]) as $id=>$q)if((int)$q>0)$pq[(int)$id]=(int)$q;ksort($pq,SORT_NUMERIC);
        $payload=[
            'session_id'=>(int)$session['id'],'discount_type'=>(string)($session['discount_type']??''),
            'discount_value'=>(int)($session['discount_value']??0),'orders'=>$normOrders,
            'paid_state'=>[
                'paid_quantities'=>$pq,'paid_subtotal'=>(int)($paid['paid_subtotal']??0),
                'paid_discount'=>(int)($paid['paid_discount']??0),'paid_taxable'=>(int)($paid['paid_taxable']??0),
                'paid_tax'=>(int)($paid['paid_tax']??0),'paid_total'=>(int)($paid['paid_total']??0)
            ]
        ];
        return hash('sha256',json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    }

    private static function requestId(string $value): string
    {
        $value=substr(preg_replace('/[^A-Za-z0-9._:-]/','',trim($value))??'',0,96);
        if(!preg_match('/^[A-Za-z0-9._:-]{8,96}$/',$value))
            throw new SettlementException('invalid_request_id','شناسه یکتای تسویه معتبر نیست.',422);
        return $value;
    }

    private static function requestFingerprint(int $sessionId,string $destination,string $mode,array $selection,string $adapterKey=''): string
    {
        ksort($selection,SORT_NUMERIC);
        return hash('sha256',json_encode([
            'session_id'=>$sessionId,'destination'=>$destination,'mode'=>$mode,'selection'=>$selection,'adapter_key'=>$adapterKey
        ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    }

    private static function normalizeSelection(array $selection): array
    {
        $out=[];
        if(array_is_list($selection)){
            foreach($selection as $line){
                if(!is_array($line))continue;
                $id=(int)($line['order_item_id']??0);$qty=(int)($line['quantity']??0);
                if($id>0&&$qty>0)$out[$id]=($out[$id]??0)+$qty;
            }
        }else{
            foreach($selection as $id=>$qty)if((int)$id>0&&(int)$qty>0)$out[(int)$id]=(int)$qty;
        }
        ksort($out,SORT_NUMERIC);
        if(!$out)throw new SettlementException('selection_required','حداقل یک قلم برای پرداخت جداگانه انتخاب کن.',422);
        return $out;
    }

    private static function discountAmount(int $subtotal,?string $type,int $value): int
    {
        $subtotal=max(0,$subtotal);$value=max(0,$value);
        return match($type){
            'percent'=>min($subtotal,(int)round($subtotal*min(100,$value)/100,0,PHP_ROUND_HALF_UP)),
            'fixed'=>min($subtotal,$value),
            default=>0
        };
    }

    private static function truncate(string $value,int $length): string
    {
        return function_exists('mb_substr')?mb_substr($value,0,$length,'UTF-8'):substr($value,0,$length);
    }
}
