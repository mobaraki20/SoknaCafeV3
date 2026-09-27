<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\Orders;

use PDO;
use Sokna\Local\Domain\Inventory\InventoryOrderService;
use Sokna\Local\Domain\Tax\TaxService;
use Sokna\Local\Domain\Printing\PrintService;
use Throwable;

final class OrderCommitService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly BusinessClock $clock,
        private readonly OrderCatalogService $catalog,
        private readonly InventoryOrderService $inventoryOrders,
        private readonly TaxService $tax,
        private readonly PrintService $printing,
    ) {}

    public function commit(array $data): array
    {
        $this->pdo->beginTransaction();
        try {
            $result=$this->commitTx($data);
            $this->pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $e;
        }
    }

    public function commitTx(array $data): array
    {
        if(!$this->pdo->inTransaction())throw new \LogicException('Order commit requires an open transaction.');
        $command=$this->normalizeCommand($data);
        $table=null;
        if($command['order_context']==='table_service'){
            $table=$this->lockTable((int)$command['table_id']);
            $this->assertSession($command['session_id'],(int)$command['table_id']);
        }

        $duplicate=$this->findDuplicate($command['client_token']);
        if($duplicate!==null){
            $this->assertDuplicateOwnership($duplicate,$command);
            return [
                'success'=>true,'duplicate'=>true,
                'order_id'=>(int)$duplicate['id'],
                'order_number'=>(int)$duplicate['business_order_number'],
                'public_code'=>(string)$duplicate['public_code'],
                'total_amount'=>(int)$duplicate['total_amount'],
                'order_context'=>(string)$duplicate['order_context'],
                'lines'=>$this->persistedLines((int)$duplicate['id']),
            ];
        }

        $lines=$this->catalog->snapshotRowsTx($command['items'],$command['source']);
        $taxAt=date('Y-m-d H:i:s');
        foreach($lines as &$line){
            $snapshot=$this->tax->orderLineSnapshotTx((int)($line['item_id']??0),$taxAt);
            $line['tax_policy_snapshot']=$snapshot['policy'];
            $line['tax_rate_bps_snapshot']=$snapshot['rate_bps'];
            $line['tax_rate_version_id']=$snapshot['rate_version_id'];
            $line['tax_item_policy_version_id']=$snapshot['policy_version_id'];
        }
        unset($line);
        $total=array_sum(array_map(static fn(array $line):int=>(int)$line['line_total'],$lines));

        $business=$this->clock->assignment($command['occurred_at']);
        $number=$this->allocateBusinessNumber($business['business_date']);
        $publicCode=strtoupper(bin2hex(random_bytes(8)));
        $acceptedAt=$command['source']==='staff'?date('Y-m-d H:i:s'):null;
        $actor=$command['source']==='staff'?$command['actor_user_id']:null;

        $insert=$this->pdo->prepare(
            'INSERT INTO orders(public_code,client_token,device_token,table_id,session_id,order_source,order_context,status,customer_note,total_amount,'.
            'accepted_at,accepted_by_user_id,created_by_user_id,business_order_number,business_date,business_shift_key,business_shift_label,business_cutoff_snapshot) '.
            'VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $insert->execute([
            $publicCode,$command['client_token'],$command['device_token'],$table===null?null:(int)$table['id'],$command['session_id'],
            $command['source'],$command['order_context'],$command['status'],$command['customer_note'],$total,
            $acceptedAt,$actor,$actor,$number,$business['business_date'],$business['shift_key'],$business['shift_label'],$business['cutoff'],
        ]);
        $orderId=(int)$this->pdo->lastInsertId();

        $lineInsert=$this->pdo->prepare(
            'INSERT INTO order_items(order_id,item_id,item_name,sellable_kind_snapshot,unit_price,quantity,ordered_quantity,item_note,fulfillment_mode,preparation_station,line_total,'.
            'tax_policy_snapshot,tax_rate_bps_snapshot,tax_rate_version_id,tax_item_policy_version_id) '.
            'VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $persisted=[];
        foreach($lines as $line){
            $lineInsert->execute([
                $orderId,$line['item_id'],$line['item_name'],$line['sellable_kind'],$line['unit_price'],
                $line['quantity'],$line['quantity'],$line['item_note'],$line['fulfillment_mode'],$line['preparation_station'],$line['line_total'],
                $line['tax_policy_snapshot'],$line['tax_rate_bps_snapshot'],$line['tax_rate_version_id'],$line['tax_item_policy_version_id'],
            ]);
            $persisted[]=[
                'order_item_id'=>(int)$this->pdo->lastInsertId(),
                'item_id'=>(int)$line['item_id'],
                'category_id'=>(int)($line['category_id']??0),
                'item_name'=>(string)$line['item_name'],
                'unit_price'=>(int)$line['unit_price'],
                'quantity'=>(int)$line['quantity'],
                'fulfillment_mode'=>(string)$line['fulfillment_mode'],
                'preparation_station'=>(string)$line['preparation_station'],
                'line_total'=>(int)$line['line_total'],
            ];
        }
        $this->pdo->prepare('INSERT INTO order_status_history(order_id,from_status,to_status,actor_user_id) VALUES(?,NULL,?,?)')
            ->execute([$orderId,$command['status'],$actor]);

        if($command['status']==='accounted'){
            // Optional Inventory never owns Order success/failure. It captures a durable
            // accounted event and may process it; all Inventory errors are contained there.
            $this->inventoryOrders->enqueueAccountedTx($orderId,(int)($actor??0));
        }

        // Table-service printing can be emitted immediately. Staff Consumption defers
        // printing until its authority document exists so the ticket carries the real consumer identity.
        if($command['order_context']==='table_service')$this->printing->enqueueOrderTx($orderId,(int)($actor??0));

        return [
            'success'=>true,'duplicate'=>false,'order_id'=>$orderId,'order_number'=>$number,
            'public_code'=>$publicCode,'total_amount'=>$total,'order_context'=>$command['order_context'],'lines'=>$persisted,
            'business_date'=>$business['business_date'],'business_shift_key'=>$business['shift_key'],
        ];
    }

    private function normalizeCommand(array $data): array
    {
        $source=(string)($data['source']??'guest');
        if(!in_array($source,['guest','staff'],true))throw new OrderCommitException('invalid_source','منبع سفارش معتبر نیست.',422);
        $context=trim((string)($data['order_context']??'table_service'));
        if(!in_array($context,['table_service','staff_consumption'],true))throw new OrderCommitException('invalid_order_context','زمینه سفارش معتبر نیست.',422);
        $tableRaw=$data['table_id']??null;
        $tableId=$tableRaw===null||$tableRaw===''?null:(int)$tableRaw;
        $sessionRaw=$data['session_id']??null;
        $sessionId=$sessionRaw===null||$sessionRaw===''?null:(int)$sessionRaw;$sessionId=($sessionId??0)>0?$sessionId:null;
        $clientToken=trim((string)($data['client_token']??''));
        $deviceToken=trim((string)($data['device_token']??''));
        $actorUserId=(int)($data['actor_user_id']??0);
        $status=trim((string)($data['status']??($source==='staff'?'accounted':'new')));
        $allowedStatus=$source==='staff'?['accounted']:['new','pending_approval'];
        if(strlen($clientToken)<16||strlen($clientToken)>80||strlen($deviceToken)>80)
            throw new OrderCommitException('invalid_order','اطلاعات پایه سفارش معتبر نیست.',422);
        if($source==='staff'&&$actorUserId<1)throw new OrderCommitException('invalid_actor','کاربر ثبت‌کننده معتبر نیست.',403);
        if(!in_array($status,$allowedStatus,true))throw new OrderCommitException('invalid_status','وضعیت آغازین سفارش معتبر نیست.',422);
        if($context==='table_service'&&($tableId??0)<1)throw new OrderCommitException('invalid_order','میز سفارش معتبر نیست.',422);
        if($context==='staff_consumption'){
            if($source!=='staff'||$actorUserId<1)throw new OrderCommitException('invalid_order_context','مصرف پرسنلی فقط با کاربر ثبت‌کننده معتبر است.',422);
            if($tableId!==null||$sessionId!==null)throw new OrderCommitException('invalid_order_context','مصرف پرسنلی نباید به میز یا نشست متصل باشد.',422);
            if($status!=='accounted')throw new OrderCommitException('invalid_status','مصرف پرسنلی باید در لحظه ثبت accounted باشد.',422);
        }
        if($source==='guest'&&$context!=='table_service')throw new OrderCommitException('invalid_order_context','سفارش مهمان فقط در زمینه سرو میز معتبر است.',422);

        return [
            'source'=>$source,'order_context'=>$context,'status'=>$status,'table_id'=>$tableId,'session_id'=>$sessionId,
            'client_token'=>$clientToken,'device_token'=>$deviceToken!==''?$deviceToken:null,
            'actor_user_id'=>$actorUserId>0?$actorUserId:null,
            'customer_note'=>self::truncate(trim((string)($data['customer_note']??'')),1000),
            'occurred_at'=>$data['occurred_at']??null,
            'items'=>$this->catalog->normalizeRows($data['items']??null,false),
        ];
    }

    private function lockTable(int $tableId): array
    {
        $stmt=$this->pdo->prepare('SELECT id,name,code FROM cafe_tables WHERE id=? AND active=1 LIMIT 1 FOR UPDATE');
        $stmt->execute([$tableId]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row))throw new OrderCommitException('table_unavailable','میز فعال پیدا نشد.',404);
        return $row;
    }

    private function assertSession(?int $sessionId,int $tableId): void
    {
        if($sessionId===null)return;
        $stmt=$this->pdo->prepare("SELECT id,table_id,status FROM table_sessions WHERE id=? LIMIT 1 FOR UPDATE");
        $stmt->execute([$sessionId]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row)||(int)$row['table_id']!==$tableId||!in_array((string)$row['status'],['active','pending'],true))
            throw new OrderCommitException('session_changed','نشست میز تغییر کرده است.',409);
    }

    private function findDuplicate(string $token): ?array
    {
        $stmt=$this->pdo->prepare('SELECT id,public_code,client_token,table_id,order_source,order_context,created_by_user_id,business_order_number,total_amount FROM orders WHERE client_token=? LIMIT 1 FOR UPDATE');
        $stmt->execute([$token]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row)?$row:null;
    }

    private function assertDuplicateOwnership(array $existing,array $command): void
    {
        $existingTable=$existing['table_id']===null?null:(int)$existing['table_id'];
        if($existingTable!==$command['table_id']||(string)$existing['order_source']!==$command['source']||(string)$existing['order_context']!==$command['order_context'])
            throw new OrderCommitException('idempotency_conflict','شناسه این ارسال متعلق به سفارش دیگری است.',409);
        if($command['source']==='staff'&&(int)$existing['created_by_user_id']!==(int)$command['actor_user_id'])
            throw new OrderCommitException('idempotency_conflict','شناسه این ارسال متعلق به کاربر دیگری است.',409);
    }

    /** @return list<array<string,mixed>> */
    private function persistedLines(int $orderId): array
    {
        $stmt=$this->pdo->prepare('SELECT oi.id order_item_id,oi.item_id,i.category_id,oi.item_name,oi.unit_price,oi.quantity,oi.fulfillment_mode,oi.preparation_station,oi.line_total FROM order_items oi LEFT JOIN items i ON i.id=oi.item_id WHERE oi.order_id=? ORDER BY oi.id');
        $stmt->execute([$orderId]);$result=[];
        foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row){
            $result[]=[
                'order_item_id'=>(int)$row['order_item_id'],'item_id'=>(int)($row['item_id']??0),'category_id'=>(int)($row['category_id']??0),
                'item_name'=>(string)$row['item_name'],'unit_price'=>(int)$row['unit_price'],'quantity'=>(int)$row['quantity'],
                'fulfillment_mode'=>(string)$row['fulfillment_mode'],'preparation_station'=>(string)$row['preparation_station'],'line_total'=>(int)$row['line_total'],
            ];
        }
        return $result;
    }

    private function allocateBusinessNumber(string $businessDate): int
    {
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$businessDate))throw new OrderCommitException('invalid_business_date','روز عملیاتی معتبر نیست.',500);
        $this->pdo->prepare('INSERT INTO order_business_sequences(business_date,last_number) VALUES(?,0) ON DUPLICATE KEY UPDATE business_date=VALUES(business_date)')
            ->execute([$businessDate]);
        $lock=$this->pdo->prepare('SELECT last_number FROM order_business_sequences WHERE business_date=? FOR UPDATE');
        $lock->execute([$businessDate]);$current=$lock->fetchColumn();
        if($current===false)throw new OrderCommitException('numbering_unavailable','شمارنده روزانه سفارش در دسترس نیست.',500);
        $next=(int)$current+1;
        if($next<1||$next>4294967295)throw new OrderCommitException('numbering_exhausted','ظرفیت شماره سفارش روزانه تکمیل شده است.',500);
        $this->pdo->prepare('UPDATE order_business_sequences SET last_number=? WHERE business_date=?')->execute([$next,$businessDate]);
        return $next;
    }

    private static function truncate(string $value,int $length): string
    {
        if(function_exists('mb_substr'))return mb_substr($value,0,$length,'UTF-8');
        return substr($value,0,$length);
    }
}
