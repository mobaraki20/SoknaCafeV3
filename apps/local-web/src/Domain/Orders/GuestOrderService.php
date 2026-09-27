<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\Orders;

use PDO;
use Sokna\Local\Domain\Tax\TaxService;
use Throwable;

final class GuestOrderService
{
    private const MUTABLE=['pending_approval','new'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly BusinessClock $clock,
        private readonly OrderCatalogService $catalog,
        private readonly OrderCommitService $orders,
        private readonly TaxService $tax,
    ) {}

    public function submit(array $data): array
    {
        $this->pdo->beginTransaction();
        try{$result=$this->submitTx($data);$this->pdo->commit();return $result;}
        catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function submitTx(array $data): array
    {
        $this->requireTx();
        $ctx=$this->normalizeContext($data,true);
        if(!$this->acceptance()['cafe'])throw new GuestOrderException('ordering_paused',$this->acceptanceMessage('cafe'),423,['scope'=>'cafe']);
        $table=$this->table($ctx['table_token'],true);
        if($table===null)throw new GuestOrderException('invalid_qr','کد میز معتبر نیست.',404);

        $duplicate=$this->findOrderByClient($ctx['client_token'],true);
        if($duplicate!==null){
            if((int)$duplicate['table_id']!==(int)$table['id'])throw new GuestOrderException('invalid_order','شناسه این ارسال با میز دیگری ثبت شده است.',409);
            return $this->orderResponse($duplicate,true);
        }

        $session=$this->resolveSessionForSubmit((int)$table['id'],$ctx['session_token']);
        $this->assertSessionEditable($session,'new');
        if($ctx['device_token']!==''){
            $pending=$this->pdo->prepare("SELECT public_code,client_token,business_order_number FROM orders WHERE session_id=? AND device_token=? AND status IN('pending_approval','new') ORDER BY id DESC LIMIT 1 FOR UPDATE");
            $pending->execute([(int)$session['id'],$ctx['device_token']]);$row=$pending->fetch(PDO::FETCH_ASSOC);
            if(is_array($row))throw new GuestOrderException('pending_order_exists','یک سفارش از همین دستگاه هنوز منتظر تأیید است؛ همان سفارش را باز کن و تغییر بده.',409,[
                'order_code'=>(string)$row['public_code'],'client_token'=>(string)$row['client_token'],'order_number'=>(int)$row['business_order_number']
            ]);
            $this->registerSessionClient((int)$session['id'],$ctx['device_token']);
        }

        $rows=$this->catalog->normalizeRows($data['items']??null,false);
        $this->assertExpectedPrices($rows);
        $snapshots=$this->catalog->snapshotRowsTx($rows,'guest');
        $this->assertStationsAvailable($snapshots);
        $status=(string)$session['status']==='pending'?'pending_approval':'new';
        $result=$this->orders->commitTx([
            'source'=>'guest','status'=>$status,'table_id'=>(int)$table['id'],'session_id'=>(int)$session['id'],
            'client_token'=>$ctx['client_token'],'device_token'=>$ctx['device_token'],'customer_note'=>$ctx['customer_note'],'items'=>$rows,
        ]);
        $result['status']=$status;$result['client_token']=$ctx['client_token'];
        return $this->orderResponse($result,false);
    }

    public function list(array $data): array
    {
        $ctx=$this->normalizeManageContext($data);$table=$this->table($ctx['table_token'],false);
        if($table===null)throw new GuestOrderException('invalid_qr','کد میز معتبر نیست.',404);
        $session=$this->findLiveSession((int)$table['id'],$ctx['session_token'],false);
        return ['success'=>true,'session_status'=>$session['status']??null,'orders'=>$session?$this->rows((int)$session['id'],$ctx['device_token']):[]];
    }

    public function status(array $data): array
    {
        $code=trim((string)($data['order_code']??''));$client=trim((string)($data['client_token']??''));
        if($code===''||$client===''||strlen($code)>32||strlen($client)>80)throw new GuestOrderException('invalid_tracking','اطلاعات پیگیری معتبر نیست.',422);
        $stmt=$this->pdo->prepare("SELECT o.status,o.accepted_at,o.updated_at,ts.status session_status,ts.ended_at session_ended_at FROM orders o LEFT JOIN table_sessions ts ON ts.id=o.session_id WHERE o.public_code=? AND o.client_token=? LIMIT 1");
        $stmt->execute([$code,$client]);$order=$stmt->fetch(PDO::FETCH_ASSOC);if(!is_array($order))throw new GuestOrderException('order_not_found','سفارش پیدا نشد.',404);
        if(($order['session_status']??null)==='closed')return ['success'=>true,'expired'=>true,'status'=>'session_closed','status_label'=>'نشست این میز پایان یافته است.','updated_at'=>$order['session_ended_at']?:$order['updated_at']];
        $status=(string)$order['status'];return ['success'=>true,'status'=>$status,'status_label'=>self::statusLabel($status),'accepted'=>$order['accepted_at']!==null||in_array($status,['accounted','completed'],true),'updated_at'=>(string)$order['updated_at']];
    }

    public function tableContext(array $data): array
    {
        $tableToken=trim((string)($data['table_token']??''));$device=trim((string)($data['device_token']??''));
        if($tableToken===''||strlen($tableToken)>80||strlen($device)>80)throw new GuestOrderException('invalid_qr','کد میز معتبر نیست.',422);
        $table=$this->table($tableToken,false);if($table===null)throw new GuestOrderException('invalid_qr','کد میز معتبر نیست.',404);
        $this->pdo->beginTransaction();
        try{
            $session=$this->findSessionByStatus((int)$table['id'],'active',true);$pending=$session===null?$this->findSessionByStatus((int)$table['id'],'pending',true):null;$late=false;
            if($session!==null&&$device!==''){
                $known=$this->pdo->prepare('SELECT 1 FROM table_session_clients WHERE session_id=? AND device_token=? LIMIT 1');$known->execute([(int)$session['id'],$device]);$isKnown=$known->fetchColumn()!==false;
                $minutes=max(5,(int)$this->setting('new_device_alert_minutes','20'));
                $late=!$isKnown&&(time()-(strtotime((string)$session['started_at'])?:time())>$minutes*60);
                $this->registerSessionClient((int)$session['id'],$device);
            }
            $call=$this->pdo->prepare("SELECT public_code,status,created_at FROM waiter_calls WHERE table_id=? AND status IN('new','accepted') ORDER BY created_at DESC,id DESC LIMIT 1");$call->execute([(int)$table['id']]);$active=$call->fetch(PDO::FETCH_ASSOC);$active=is_array($active)?$active:null;
            $acceptance=$this->acceptance();$this->pdo->commit();
            return [
                'success'=>true,'table'=>['id'=>(int)$table['id'],'name'=>(string)$table['name'],'code'=>(string)$table['code']],
                'session'=>$session?['token'=>(string)$session['public_token'],'started_at'=>(string)$session['started_at'],'status'=>(string)$session['status']]:null,
                'pending_session'=>$pending?['token'=>(string)$pending['public_token'],'started_at'=>(string)$pending['started_at'],'status'=>(string)$pending['status']]:null,
                'can_order'=>$acceptance['cafe'],'order_acceptance'=>$acceptance,'order_acceptance_messages'=>[
                    'cafe'=>$this->acceptanceMessage('cafe'),'kitchen'=>$this->acceptanceMessage('kitchen'),'bar'=>$this->acceptanceMessage('bar')],
                'station_states'=>[],'station_state_hash'=>substr(hash('sha256',json_encode($acceptance)),0,16),
                'waiter_enabled'=>$this->settingBool('waiter_call_enabled',true),'active_call'=>$active,'late_join'=>$late,'requires_operator_confirmation'=>$session===null,
            ];
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function mutate(array $data): array
    {
        $this->pdo->beginTransaction();
        try{$result=$this->mutateTx($data);$this->pdo->commit();return $result;}
        catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function mutateTx(array $data): array
    {
        $this->requireTx();$ctx=$this->normalizeManageContext($data);$action=trim((string)($data['action']??''));
        if(!in_array($action,['update','cancel'],true))throw new GuestOrderException('invalid_action','عملیات سفارش معتبر نیست.',422);
        $code=trim((string)($data['order_code']??''));if($code===''||strlen($code)>32)throw new GuestOrderException('invalid_order','سفارش معتبر نیست.',422);
        $table=$this->table($ctx['table_token'],true);if($table===null)throw new GuestOrderException('invalid_qr','کد میز معتبر نیست.',404);
        $identity=$this->pdo->prepare('SELECT id,session_id FROM orders WHERE public_code=? AND table_id=? AND device_token=? LIMIT 1');$identity->execute([$code,(int)$table['id'],$ctx['device_token']]);$id=$identity->fetch(PDO::FETCH_ASSOC);
        if(!is_array($id))throw new GuestOrderException('order_not_found','این سفارش روی همین دستگاه پیدا نشد.',404);
        $sessionStmt=$this->pdo->prepare('SELECT * FROM table_sessions WHERE id=? AND table_id=? LIMIT 1 FOR UPDATE');$sessionStmt->execute([(int)$id['session_id'],(int)$table['id']]);$session=$sessionStmt->fetch(PDO::FETCH_ASSOC);
        if(!is_array($session))throw new GuestOrderException('session_inactive','نشست این میز پیدا نشد.',409);
        if($ctx['session_token']!==''&&!hash_equals((string)$session['public_token'],$ctx['session_token']))throw new GuestOrderException('session_inactive','نشست این میز تغییر کرده؛ صفحه را تازه کن.',409);
        $orderStmt=$this->pdo->prepare('SELECT * FROM orders WHERE id=? AND table_id=? AND device_token=? LIMIT 1 FOR UPDATE');$orderStmt->execute([(int)$id['id'],(int)$table['id'],$ctx['device_token']]);$order=$orderStmt->fetch(PDO::FETCH_ASSOC);
        if(!is_array($order))throw new GuestOrderException('order_not_found','این سفارش روی همین دستگاه پیدا نشد.',404);

        if($action==='cancel'){
            if((string)$order['status']==='cancelled')return ['success'=>true,'status'=>'cancelled','status_label'=>self::statusLabel('cancelled'),'duplicate'=>true,'message'=>'این سفارش قبلاً لغو شده است.'];
            if(!self::isMutable((string)$order['status']))throw new GuestOrderException('order_not_editable','این سفارش تأیید شده و دیگر لغو مستقیم ندارد.',409);
            $this->pdo->prepare("UPDATE orders SET status='cancelled',updated_at=NOW() WHERE id=?")->execute([(int)$order['id']]);
            $this->pdo->prepare("INSERT INTO order_status_history(order_id,from_status,to_status,actor_user_id) VALUES(?,?,'cancelled',NULL)")->execute([(int)$order['id'],(string)$order['status']]);
            $remaining=$this->pdo->prepare("SELECT COUNT(*) FROM orders WHERE session_id=? AND status IN('pending_approval','new')");$remaining->execute([(int)$session['id']]);
            if((string)$session['status']==='pending'&&(int)$remaining->fetchColumn()===0)$this->closePendingSession((int)$session['id']);
            return ['success'=>true,'status'=>'cancelled','status_label'=>self::statusLabel('cancelled'),'message'=>'سفارش لغو شد.'];
        }

        if(!in_array((string)$session['status'],['active','pending'],true))throw new GuestOrderException('session_inactive','نشست این میز پایان یافته است.',409);
        if(!self::isMutable((string)$order['status']))throw new GuestOrderException('order_not_editable','این سفارش تأیید شده و دیگر ویرایش مستقیم ندارد.',409);
        $this->assertSessionEditable($session,'edit');
        $existing=$this->orderLines((int)$order['id'],true);$rows=$this->catalog->normalizeRows($data['items']??null,false);
        $expected=trim((string)($data['expected_signature']??''));$signature=self::editSignature($order,$existing);
        $payloadNote=self::truncate(trim((string)($data['customer_note']??'')),1000);
        if($expected===''||!hash_equals($signature,$expected)){
            if($this->payloadMatchesCurrent($rows,$payloadNote,$order,$existing))return ['success'=>true,'idempotent'=>true,'updated'=>true,'order_code'=>(string)$order['public_code'],'order_number'=>(int)$order['business_order_number'],'client_token'=>(string)$order['client_token'],'status'=>(string)$order['status'],'status_label'=>self::statusLabel((string)$order['status']),'edit_signature'=>$signature,'message'=>'این تغییرات قبلاً ذخیره شده‌اند.'];
            throw new GuestOrderException('order_changed','این سفارش در جای دیگری تغییر کرده؛ فهرست سفارش را تازه کن و دوباره ویرایش کن.',409,['current_signature'=>$signature]);
        }
        $validated=$this->validateUpdateLines($rows,$existing);$this->replaceOrder((int)$order['id'],$payloadNote,$validated);
        $fresh=$order;$fresh['customer_note']=$payloadNote;$fresh['updated_at']=date('Y-m-d H:i:s');
        return ['success'=>true,'order_code'=>(string)$order['public_code'],'order_number'=>(int)$order['business_order_number'],'client_token'=>(string)$order['client_token'],'status'=>(string)$order['status'],'status_label'=>self::statusLabel((string)$order['status']),'updated'=>true,'edit_signature'=>self::editSignature($fresh,$validated['lines']),'message'=>'تغییرات سفارش ذخیره شد.'];
    }

    public function quote(array $data): array
    {
        $this->pdo->beginTransaction();
        try{$result=$this->quoteTx($data);$this->pdo->rollBack();return $result;}
        catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function quoteTx(array $data): array
    {
        $this->requireTx();$mode=trim((string)($data['quote_mode']??'create'));if(!in_array($mode,['create','update','append'],true))throw new GuestOrderException('invalid_order','نوع پیش‌نمایش سفارش معتبر نیست.',422);
        $tableToken=trim((string)($data['table_token']??''));$device=trim((string)($data['device_token']??''));if($tableToken===''||strlen($tableToken)>80||strlen($device)>80)throw new GuestOrderException('invalid_qr','کد میز معتبر نیست.',422);
        $table=$this->table($tableToken,true);if($table===null)throw new GuestOrderException('invalid_qr','کد میز معتبر نیست.',404);
        $session=null;$draft=[];
        if($mode==='create'){
            if(!$this->acceptance()['cafe'])throw new GuestOrderException('ordering_paused',$this->acceptanceMessage('cafe'),423,['scope'=>'cafe']);
            $session=$this->findLiveSession((int)$table['id'],trim((string)($data['session_token']??'')),true);
            if(trim((string)($data['session_token']??''))!==''&&$session===null)throw new GuestOrderException('session_inactive','نشست قبلی این میز بسته شده؛ صفحه را تازه کن و دوباره سفارش بده.',409);
            if($session!==null){$this->assertSessionEditable($session,'new');if($device!==''){$pending=$this->pdo->prepare("SELECT public_code,client_token,business_order_number FROM orders WHERE session_id=? AND device_token=? AND status IN('pending_approval','new') ORDER BY id DESC LIMIT 1 FOR UPDATE");$pending->execute([(int)$session['id'],$device]);$p=$pending->fetch(PDO::FETCH_ASSOC);if(is_array($p))throw new GuestOrderException('pending_order_exists','یک سفارش از همین دستگاه هنوز منتظر تأیید است؛ همان سفارش را باز کن و تغییر بده.',409,['order_code'=>$p['public_code'],'client_token'=>$p['client_token'],'order_number'=>(int)$p['business_order_number']]);}}
            $rows=$this->catalog->normalizeRows($data['items']??null,false);$this->assertExpectedPrices($rows);$draft=$this->snapshotDraft($rows);
        }else{
            $ctx=$this->normalizeManageContext($data);$code=trim((string)($data['order_code']??''));if($code===''||strlen($code)>32)throw new GuestOrderException('invalid_order','سفارش معتبر نیست.',422);
            $identity=$this->pdo->prepare('SELECT id,session_id FROM orders WHERE public_code=? AND table_id=? AND device_token=? LIMIT 1 FOR UPDATE');$identity->execute([$code,(int)$table['id'],$ctx['device_token']]);$id=$identity->fetch(PDO::FETCH_ASSOC);if(!is_array($id))throw new GuestOrderException('order_not_found','این سفارش روی همین دستگاه پیدا نشد.',404);
            $s=$this->pdo->prepare('SELECT * FROM table_sessions WHERE id=? AND table_id=? LIMIT 1 FOR UPDATE');$s->execute([(int)$id['session_id'],(int)$table['id']]);$session=$s->fetch(PDO::FETCH_ASSOC);if(!is_array($session)||!in_array((string)$session['status'],['active','pending'],true))throw new GuestOrderException('session_inactive','نشست این میز پایان یافته است.',409);
            if($ctx['session_token']!==''&&!hash_equals((string)$session['public_token'],$ctx['session_token']))throw new GuestOrderException('session_inactive','نشست این میز تغییر کرده؛ صفحه را تازه کن.',409);$this->assertSessionEditable($session,'edit');
            $o=$this->pdo->prepare('SELECT * FROM orders WHERE id=? AND table_id=? AND device_token=? LIMIT 1 FOR UPDATE');$o->execute([(int)$id['id'],(int)$table['id'],$ctx['device_token']]);$order=$o->fetch(PDO::FETCH_ASSOC);if(!is_array($order)||!self::isMutable((string)$order['status']))throw new GuestOrderException('order_not_editable','این سفارش تأیید شده و دیگر ویرایش مستقیم ندارد.',409);
            $existing=$this->orderLines((int)$order['id'],true);$sig=trim((string)($data['expected_signature']??''));$current=self::editSignature($order,$existing);if($sig===''||!hash_equals($current,$sig))throw new GuestOrderException('order_changed','این سفارش در جای دیگری تغییر کرده؛ فهرست سفارش را تازه کن.',409,['current_signature'=>$current]);
            $rows=$this->catalog->normalizeRows($data['items']??null,false);$draft=$this->validateUpdateLines($rows,$existing)['lines'];
        }
        return $this->quoteFinancials($session,$draft);
    }

    private function quoteFinancials(?array $session,array $draft): array
    {
        $baseLines=[];if($session!==null){$stmt=$this->pdo->prepare("SELECT oi.id order_item_id,oi.unit_price,oi.quantity,oi.tax_policy_snapshot,oi.tax_rate_bps_snapshot FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE o.session_id=? AND o.status='accounted' AND oi.quantity>0 ORDER BY oi.id FOR UPDATE");$stmt->execute([(int)$session['id']]);$baseLines=$stmt->fetchAll(PDO::FETCH_ASSOC);}
        $type=(string)($session['discount_type']??'');$value=(int)($session['discount_value']??0);$baseSubtotal=array_sum(array_map(static fn(array $l):int=>(int)$l['unit_price']*(int)$l['quantity'],$baseLines));$base=TaxService::calculateInvoiceLines($baseLines,self::discountAmount($baseSubtotal,$type,$value));
        $projected=$baseLines;$next=1;foreach($baseLines as $line)$next=max($next,(int)($line['order_item_id']??0)+1);foreach($draft as $line)$projected[]=['order_item_id'=>$next++,'unit_price'=>(int)$line['unit_price'],'quantity'=>(int)$line['quantity'],'tax_policy_snapshot'=>(string)($line['tax_policy_snapshot']??'disabled'),'tax_rate_bps_snapshot'=>(int)($line['tax_rate_bps_snapshot']??0)];
        $subtotal=array_sum(array_map(static fn(array $l):int=>(int)$l['unit_price']*(int)$l['quantity'],$projected));$after=TaxService::calculateInvoiceLines($projected,self::discountAmount($subtotal,$type,$value));$quote=[];
        foreach(['subtotal','discount','net','taxable','tax','total'] as $key){$delta=(int)$after[$key]-(int)$base[$key];if($delta<0)throw new GuestOrderException('quote_inconsistent','پیش‌نمایش مالی سفارش با وضعیت حساب سازگار نیست.',409);$quote[$key]=$delta;}
        return ['success'=>true,'financial_preview'=>$quote+['currency'=>'تومان','discount_active'=>$quote['discount']>0,'tax_active'=>$quote['tax']>0||array_sum(array_map(static fn(array $l):int=>(string)($l['tax_policy_snapshot']??'disabled')!=='disabled'?1:0,$draft))>0]];
    }

    private function snapshotDraft(array $rows): array
    {
        $snapshots=$this->catalog->snapshotRowsTx($rows,'guest');$this->assertStationsAvailable($snapshots);$at=date('Y-m-d H:i:s');
        foreach($snapshots as &$line){$tax=$this->tax->orderLineSnapshotTx((int)$line['item_id'],$at);$line['tax_policy_snapshot']=$tax['policy'];$line['tax_rate_bps_snapshot']=$tax['rate_bps'];$line['tax_rate_version_id']=$tax['rate_version_id'];$line['tax_item_policy_version_id']=$tax['policy_version_id'];}unset($line);return $snapshots;
    }

    private function validateUpdateLines(array $rows,array $existingRows): array
    {
        $existing=[];foreach($existingRows as $row)$existing[(int)$row['item_id'].'|'.(string)$row['fulfillment_mode']]=$row;$lines=[];$total=0;
        foreach($rows as $requested){$key=(int)$requested['id'].'|'.$requested['fulfillment_mode'];$old=$existing[$key]??null;$oldQty=$old?(int)$old['quantity']:0;$newQty=(int)$requested['quantity'];$increase=$newQty>$oldQty;
            if(!$increase&&$old){$unit=(int)$old['unit_price'];$line=$this->preservedLine($old,$newQty,(string)$requested['note']);$lines[]=$line;$total+=(int)$line['line_total'];continue;}
            $probe=$requested;if($old)$probe['expected_price']=(int)$old['unit_price'];if($probe['expected_price']===null)throw new GuestOrderException('prices_changed','قیمت یکی از آیتم‌هایی که می‌خواهی بیشتر کنی تغییر کرده؛ سفارش را تازه کن و دوباره انتخاب کن.',409);
            try{$snapshot=$this->catalog->snapshotRowsTx([$probe],'guest')[0];}catch(OrderCommitException $e){throw new GuestOrderException($e->errorCode,$e->getMessage(),$e->httpStatus,$e->details);}
            $this->assertStationsAvailable([$snapshot]);$tax=$old?['policy'=>(string)($old['tax_policy_snapshot']??'disabled'),'rate_bps'=>(int)($old['tax_rate_bps_snapshot']??0),'rate_version_id'=>$old['tax_rate_version_id']!==null?(int)$old['tax_rate_version_id']:null,'policy_version_id'=>$old['tax_item_policy_version_id']!==null?(int)$old['tax_item_policy_version_id']:null]:$this->tax->orderLineSnapshotTx((int)$snapshot['item_id']);
            $line=['item_id'=>(int)$snapshot['item_id'],'item_name'=>$old?(string)$old['item_name']:(string)$snapshot['item_name'],'sellable_kind'=>$old?(string)($old['sellable_kind_snapshot']??$snapshot['sellable_kind']):(string)$snapshot['sellable_kind'],'unit_price'=>$old?(int)$old['unit_price']:(int)$snapshot['unit_price'],'quantity'=>$newQty,'item_note'=>(string)$requested['note'],'fulfillment_mode'=>$requested['fulfillment_mode'],'preparation_station'=>$old?(string)$old['preparation_station']:(string)$snapshot['preparation_station'],'line_total'=>($old?(int)$old['unit_price']:(int)$snapshot['unit_price'])*$newQty,'tax_policy_snapshot'=>$tax['policy'],'tax_rate_bps_snapshot'=>$tax['rate_bps'],'tax_rate_version_id'=>$tax['rate_version_id'],'tax_item_policy_version_id'=>$tax['policy_version_id']];$lines[]=$line;$total+=(int)$line['line_total'];
        }
        return ['lines'=>$lines,'total'=>$total];
    }

    private function preservedLine(array $old,int $qty,string $note): array
    {
        return ['item_id'=>(int)$old['item_id'],'item_name'=>(string)$old['item_name'],'sellable_kind'=>(string)($old['sellable_kind_snapshot']??'menu_item'),'unit_price'=>(int)$old['unit_price'],'quantity'=>$qty,'item_note'=>$note,'fulfillment_mode'=>(string)$old['fulfillment_mode'],'preparation_station'=>(string)$old['preparation_station'],'line_total'=>(int)$old['unit_price']*$qty,'tax_policy_snapshot'=>(string)($old['tax_policy_snapshot']??'disabled'),'tax_rate_bps_snapshot'=>(int)($old['tax_rate_bps_snapshot']??0),'tax_rate_version_id'=>$old['tax_rate_version_id']!==null?(int)$old['tax_rate_version_id']:null,'tax_item_policy_version_id'=>$old['tax_item_policy_version_id']!==null?(int)$old['tax_item_policy_version_id']:null];
    }

    private function replaceOrder(int $orderId,string $note,array $validated): void
    {
        $this->pdo->prepare('DELETE FROM order_items WHERE order_id=?')->execute([$orderId]);$insert=$this->pdo->prepare('INSERT INTO order_items(order_id,item_id,item_name,sellable_kind_snapshot,unit_price,quantity,ordered_quantity,item_note,fulfillment_mode,preparation_station,line_total,tax_policy_snapshot,tax_rate_bps_snapshot,tax_rate_version_id,tax_item_policy_version_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        foreach($validated['lines'] as $line)$insert->execute([$orderId,$line['item_id'],$line['item_name'],$line['sellable_kind'],$line['unit_price'],$line['quantity'],$line['quantity'],$line['item_note']?:null,$line['fulfillment_mode'],$line['preparation_station'],$line['line_total'],$line['tax_policy_snapshot'],$line['tax_rate_bps_snapshot'],$line['tax_rate_version_id'],$line['tax_item_policy_version_id']]);
        $this->pdo->prepare('UPDATE orders SET customer_note=?,total_amount=?,updated_at=NOW() WHERE id=?')->execute([$note,$validated['total'],$orderId]);
    }

    private function rows(int $sessionId,string $device): array
    {
        $stmt=$this->pdo->prepare('SELECT id,public_code,client_token,business_order_number,status,customer_note,total_amount,created_at,updated_at FROM orders WHERE session_id=? AND device_token=? ORDER BY created_at,id');$stmt->execute([$sessionId,$device]);$orders=$stmt->fetchAll(PDO::FETCH_ASSOC);if(!$orders)return [];$ids=array_map('intval',array_column($orders,'id'));$ph=implode(',',array_fill(0,count($ids),'?'));$line=$this->pdo->prepare("SELECT order_id,item_id,item_name,sellable_kind_snapshot,unit_price,quantity,item_note,fulfillment_mode,preparation_station,line_total,tax_policy_snapshot,tax_rate_bps_snapshot,tax_rate_version_id,tax_item_policy_version_id FROM order_items WHERE order_id IN($ph) ORDER BY order_id,id");$line->execute($ids);$by=[];foreach($line->fetchAll(PDO::FETCH_ASSOC) as $r)$by[(int)$r['order_id']][]=$r;
        return array_map(static function(array $order)use($by):array{$lines=$by[(int)$order['id']]??[];$taxLines=[];foreach($lines as $i=>$l)$taxLines[]=['order_item_id'=>$i+1,'unit_price'=>(int)$l['unit_price'],'quantity'=>(int)$l['quantity'],'tax_policy_snapshot'=>(string)($l['tax_policy_snapshot']??'disabled'),'tax_rate_bps_snapshot'=>(int)($l['tax_rate_bps_snapshot']??0)];$tax=TaxService::calculateInvoiceLines($taxLines,0);$status=(string)$order['status'];return ['order_code'=>(string)$order['public_code'],'client_token'=>(string)$order['client_token'],'order_number'=>(int)$order['business_order_number'],'status'=>$status,'status_label'=>self::statusLabel($status),'customer_note'=>(string)($order['customer_note']??''),'total_amount'=>(int)$order['total_amount'],'tax_amount'=>(int)$tax['tax'],'final_amount'=>(int)$tax['total'],'created_at'=>(string)$order['created_at'],'updated_at'=>(string)$order['updated_at'],'edit_signature'=>self::editSignature($order,$lines),'can_edit'=>self::isMutable($status),'can_cancel'=>self::isMutable($status),'items'=>array_map(static fn(array $l):array=>['id'=>(int)$l['item_id'],'name'=>(string)$l['item_name'],'unit_price'=>(int)$l['unit_price'],'quantity'=>(int)$l['quantity'],'note'=>(string)($l['item_note']??''),'fulfillment_mode'=>(string)$l['fulfillment_mode'],'line_total'=>(int)$l['line_total'],'tax_policy'=>(string)($l['tax_policy_snapshot']??'disabled'),'tax_rate_bps'=>(int)($l['tax_rate_bps_snapshot']??0)],$lines)];},$orders);
    }

    private function normalizeContext(array $data,bool $needsClient): array
    {
        $table=trim((string)($data['table_token']??''));$session=trim((string)($data['session_token']??''));$device=trim((string)($data['device_token']??''));$client=trim((string)($data['client_token']??''));
        if($table===''||strlen($table)>80||strlen($session)>80||strlen($device)>80||($needsClient&&(strlen($client)<16||strlen($client)>80)))throw new GuestOrderException('invalid_order','اطلاعات پایه سفارش معتبر نیست.',422);
        return ['table_token'=>$table,'session_token'=>$session,'device_token'=>$device,'client_token'=>$client,'customer_note'=>self::truncate(trim((string)($data['customer_note']??'')),1000)];
    }

    private function normalizeManageContext(array $data): array
    {
        $ctx=$this->normalizeContext($data,false);if($ctx['device_token']==='')throw new GuestOrderException('invalid_context','اطلاعات میز یا دستگاه معتبر نیست.',422);return $ctx;
    }

    private function resolveSessionForSubmit(int $tableId,string $token): array
    {
        if($token!==''){$session=$this->findLiveSession($tableId,$token,true);if($session===null||(string)$session['status']!=='active')throw new GuestOrderException('session_inactive','نشست قبلی این میز بسته شده؛ صفحه را تازه کن و دوباره سفارش بده.',409);return $session;}
        $session=$this->findLiveSession($tableId,'',true);if($session!==null)return $session;$business=$this->clock->assignment();$public=bin2hex(random_bytes(24));
        try{$this->pdo->prepare("INSERT INTO table_sessions(public_token,table_id,status,live_table_guard,business_date,business_shift_key,business_shift_label,business_cutoff_snapshot) VALUES(?,?,'pending',?,?,?,?,?)")->execute([$public,$tableId,$tableId,$business['business_date'],$business['shift_key'],$business['shift_label'],$business['cutoff']]);}
        catch(Throwable){$again=$this->findLiveSession($tableId,'',true);if($again!==null)return $again;throw new GuestOrderException('session_unavailable','نشست میز قابل ایجاد نیست.',409);}
        $stmt=$this->pdo->prepare('SELECT * FROM table_sessions WHERE id=? FOR UPDATE');$stmt->execute([(int)$this->pdo->lastInsertId()]);$row=$stmt->fetch(PDO::FETCH_ASSOC);if(!is_array($row))throw new GuestOrderException('session_unavailable','نشست میز قابل ایجاد نیست.',409);return $row;
    }

    private function findLiveSession(int $tableId,string $token,bool $lock): ?array
    {
        $params=[$tableId];$sql="SELECT * FROM table_sessions WHERE table_id=? AND status IN('active','pending')";if($token!==''){$sql.=' AND public_token=?';$params[]=$token;}$sql.=" ORDER BY FIELD(status,'active','pending'),id DESC LIMIT 1".($lock?' FOR UPDATE':'');$stmt=$this->pdo->prepare($sql);$stmt->execute($params);$row=$stmt->fetch(PDO::FETCH_ASSOC);return is_array($row)?$row:null;
    }

    private function findSessionByStatus(int $tableId,string $status,bool $lock): ?array
    {
        $stmt=$this->pdo->prepare('SELECT * FROM table_sessions WHERE table_id=? AND status=? ORDER BY started_at DESC,id DESC LIMIT 1'.($lock?' FOR UPDATE':''));$stmt->execute([$tableId,$status]);$row=$stmt->fetch(PDO::FETCH_ASSOC);return is_array($row)?$row:null;
    }

    private function table(string $token,bool $lock): ?array
    {
        $stmt=$this->pdo->prepare('SELECT id,name,code,access_token FROM cafe_tables WHERE access_token=? AND active=1 LIMIT 1'.($lock?' FOR UPDATE':''));$stmt->execute([$token]);$row=$stmt->fetch(PDO::FETCH_ASSOC);return is_array($row)?$row:null;
    }

    private function findOrderByClient(string $token,bool $lock): ?array
    {
        $stmt=$this->pdo->prepare('SELECT id,public_code,client_token,table_id,status,business_order_number,total_amount,updated_at FROM orders WHERE client_token=? LIMIT 1'.($lock?' FOR UPDATE':''));$stmt->execute([$token]);$row=$stmt->fetch(PDO::FETCH_ASSOC);return is_array($row)?$row:null;
    }

    private function orderLines(int $orderId,bool $lock): array
    {
        $stmt=$this->pdo->prepare('SELECT item_id,item_name,sellable_kind_snapshot,unit_price,quantity,item_note,fulfillment_mode,preparation_station,line_total,tax_policy_snapshot,tax_rate_bps_snapshot,tax_rate_version_id,tax_item_policy_version_id FROM order_items WHERE order_id=? ORDER BY id'.($lock?' FOR UPDATE':''));$stmt->execute([$orderId]);return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function assertSessionEditable(array $session,string $action): void
    {
        $sessionId=(int)$session['id'];$itemized=$this->pdo->prepare("SELECT 1 FROM settlement_records sr WHERE sr.session_id=? AND sr.status='completed' AND sr.settlement_kind='itemized' AND NOT EXISTS(SELECT 1 FROM settlement_records rv WHERE rv.reverses_settlement_id=sr.id AND rv.status='reversal') LIMIT 1");$itemized->execute([$sessionId]);if($itemized->fetchColumn()!==false)throw new GuestOrderException('itemized_settlement_active',$action==='new'?'پرداخت جداگانه این حساب شروع شده است؛ برای سفارش تازه با صندوق هماهنگ کنید.':'پرداخت جداگانه این حساب شروع شده است؛ برای تغییر سفارش با صندوق هماهنگ کنید.',409);
        $acc=$this->pdo->prepare("SELECT status,suspicious_response,resolved_at FROM accommodation_transfers WHERE session_id=? ORDER BY id DESC LIMIT 1 FOR UPDATE");$acc->execute([$sessionId]);$row=$acc->fetch(PDO::FETCH_ASSOC);if(is_array($row)&&empty($row['resolved_at'])&&(in_array((string)$row['status'],['pending','posted','void_pending','void_failed','voided'],true)||(int)($row['suspicious_response']??0)===1))throw new GuestOrderException('settlement_pending','نتیجه ثبت حساب اقامتگاه هنوز مشخص نشده است؛ لطفاً با همکاران ما هماهنگ کنید.',409);
    }

    private function assertExpectedPrices(array $rows): void { foreach($rows as $r)if($r['expected_price']===null)throw new GuestOrderException('prices_changed','قیمت فعلی یکی از آیتم‌ها باید دوباره تأیید شود.',409); }
    private function assertStationsAvailable(array $lines): void { foreach($lines as $line){$station=(string)($line['preparation_station']??'none');$scope=$station==='kitchen'?'kitchen':(in_array($station,['hot_bar','cold_bar'],true)?'bar':null);if($scope!==null&&!$this->acceptance()[$scope])throw new GuestOrderException('service_unavailable',$this->acceptanceMessage($scope),409,['scope'=>$scope,'item'=>(string)($line['item_name']??'')]);} }
    private function acceptance(): array { return ['cafe'=>$this->settingBool('orders_accepting.cafe',true),'kitchen'=>$this->settingBool('orders_accepting.kitchen',true),'bar'=>$this->settingBool('orders_accepting.bar',true)]; }
    private function acceptanceMessage(string $scope): string { return match($scope){'kitchen'=>'آشپزخانه فعلاً سفارش تازه نمی‌پذیرد.','bar'=>'بار فعلاً سفارش تازه نمی‌پذیرد.',default=>'سفارش‌گیری فعلاً متوقف است.'}; }
    private function setting(string $key,string $default): string {$stmt=$this->pdo->prepare('SELECT setting_value FROM settings WHERE setting_key=? LIMIT 1'.($this->pdo->inTransaction()?' FOR UPDATE':''));$stmt->execute([$key]);$v=$stmt->fetchColumn();return $v===false||$v===null?$default:(string)$v;}
    private function settingBool(string $key,bool $default): bool { return in_array(strtolower(trim($this->setting($key,$default?'1':'0'))),['1','true','yes','on'],true); }
    private function registerSessionClient(int $session,string $device): void {$this->pdo->prepare('INSERT INTO table_session_clients(session_id,device_token,first_seen_at,last_seen_at) VALUES(?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE last_seen_at=NOW()')->execute([$session,$device]);}
    private function closePendingSession(int $sessionId): void {$this->pdo->prepare("UPDATE table_sessions SET status='closed',live_table_guard=NULL,ended_at=NOW(),ended_reason='guest_cancelled' WHERE id=? AND status='pending'")->execute([$sessionId]);}
    private function orderResponse(array $order,bool $duplicate): array {$status=(string)($order['status']??'new');return ['success'=>true,'order_code'=>(string)($order['public_code']??''),'order_number'=>(int)($order['business_order_number']??$order['order_number']??0),'client_token'=>(string)($order['client_token']??''),'status'=>$status,'status_label'=>self::statusLabel($status),'duplicate'=>$duplicate,'message'=>$duplicate?'این سفارش قبلاً ثبت شده است.':($status==='pending_approval'?'سفارش ثبت شد؛ منتظر تأیید حضور هستیم.':'سفارش دریافت شد.')];}
    private function payloadMatchesCurrent(array $rows,string $note,array $order,array $existing): bool {if(trim((string)($order['customer_note']??''))!==$note)return false;$cur=[];foreach($existing as $r)$cur[(int)$r['item_id'].'|'.(string)$r['fulfillment_mode']]=['q'=>(int)$r['quantity'],'n'=>trim((string)($r['item_note']??'')),'p'=>(int)$r['unit_price']];if(count($cur)!==count($rows))return false;foreach($rows as $r){$c=$cur[(int)$r['id'].'|'.$r['fulfillment_mode']]??null;if(!$c||(int)$r['quantity']!==$c['q']||trim((string)$r['note'])!==$c['n']||($r['expected_price']!==null&&(int)$r['expected_price']!==$c['p']))return false;}return true;}
    private static function editSignature(array $order,array $items): string {$rows=[];foreach($items as $line)$rows[]=['item_id'=>(int)($line['item_id']??$line['id']??0),'quantity'=>(int)($line['quantity']??0),'unit_price'=>(int)($line['unit_price']??0),'note'=>trim((string)($line['item_note']??$line['note']??'')),'fulfillment_mode'=>(string)($line['fulfillment_mode']??'dine_in')];usort($rows,static fn(array $a,array $b):int=>($a['item_id']<=>$b['item_id'])?:strcmp($a['fulfillment_mode'],$b['fulfillment_mode'])?:strcmp($a['note'],$b['note']));return substr(hash('sha256',json_encode(['id'=>(int)($order['id']??0),'status'=>(string)($order['status']??''),'customer_note'=>trim((string)($order['customer_note']??'')),'updated_at'=>(string)($order['updated_at']??''),'items'=>$rows],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)),0,32);}
    private static function isMutable(string $status): bool { return in_array($status,self::MUTABLE,true); }
    private static function statusLabel(string $status): string { return ['pending_approval'=>'منتظر تأیید','new'=>'جدید','accounted'=>'تأییدشده','completed'=>'تسویه‌شده','cancelled'=>'لغوشده'][$status]??$status; }
    private static function discountAmount(int $subtotal,string $type,int $value): int {$subtotal=max(0,$subtotal);$value=max(0,$value);return match($type){'percent'=>min($subtotal,(int)round($subtotal*min(100,$value)/100,0,PHP_ROUND_HALF_UP)),'fixed'=>min($subtotal,$value),default=>0};}
    private static function truncate(string $value,int $length): string { return function_exists('mb_substr')?mb_substr($value,0,$length,'UTF-8'):substr($value,0,$length); }
    private function requireTx(): void { if(!$this->pdo->inTransaction())throw new \LogicException('Guest order operation requires an open transaction.'); }
}
