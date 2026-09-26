<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\Orders;

use PDO;
use Sokna\Local\Domain\Sellables\SellableKind;
use Throwable;

final class OrderCommitService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly BusinessClock $clock,
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
        $table=$this->lockTable($command['table_id']);
        $this->assertSession($command['session_id'],$command['table_id']);

        $duplicate=$this->findDuplicate($command['client_token']);
        if($duplicate!==null){
            $this->assertDuplicateOwnership($duplicate,$command);
            return [
                'success'=>true,'duplicate'=>true,
                'order_id'=>(int)$duplicate['id'],
                'order_number'=>(int)$duplicate['business_order_number'],
                'public_code'=>(string)$duplicate['public_code'],
                'total_amount'=>(int)$duplicate['total_amount'],
            ];
        }

        $catalog=$this->lockCatalog(array_column($command['items'],'id'));
        $lines=[];$total=0;
        foreach($command['items'] as $requested){
            $item=$catalog[$requested['id']]??null;
            if(!$this->isOrderable($item,$command['source'])){
                throw new OrderCommitException('item_unavailable','یکی از آیتم‌های انتخاب‌شده دیگر قابل سفارش نیست.',409,['item_id'=>$requested['id']]);
            }
            if($requested['fulfillment_mode']==='takeaway'&&(int)($item['takeaway_allowed']??1)!==1){
                throw new OrderCommitException('takeaway_not_allowed','این آیتم فقط داخل کافه قابل سرو است.',409,['item_id'=>$requested['id']]);
            }
            if($requested['expected_price']!==null&&$requested['expected_price']!==(int)$item['price']){
                throw new OrderCommitException('price_changed','قیمت یکی از آیتم‌ها تغییر کرده است.',409,['item_id'=>$requested['id']]);
            }
            $unit=(int)$item['price'];$lineTotal=$unit*$requested['quantity'];$total+=$lineTotal;
            $lines[]=[
                'item_id'=>(int)$item['id'],
                'item_name'=>(string)$item['name'],
                'sellable_kind'=>SellableKind::normalizeRead($item['sellable_kind']??null),
                'unit_price'=>$unit,
                'quantity'=>$requested['quantity'],
                'item_note'=>$requested['note']!==''?$requested['note']:null,
                'fulfillment_mode'=>$requested['fulfillment_mode'],
                'preparation_station'=>self::normalizeStation((string)($item['preparation_station']??'other')),
                'line_total'=>$lineTotal,
            ];
        }

        $business=$this->clock->assignment($command['occurred_at']);
        $number=$this->allocateBusinessNumber($business['business_date']);
        $publicCode=strtoupper(bin2hex(random_bytes(8)));
        $acceptedAt=$command['source']==='staff'?date('Y-m-d H:i:s'):null;
        $actor=$command['source']==='staff'?$command['actor_user_id']:null;

        $insert=$this->pdo->prepare(
            'INSERT INTO orders(public_code,client_token,device_token,table_id,session_id,order_source,status,customer_note,total_amount,'.
            'accepted_at,accepted_by_user_id,created_by_user_id,business_order_number,business_date,business_shift_key,business_shift_label,business_cutoff_snapshot) '.
            'VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $insert->execute([
            $publicCode,$command['client_token'],$command['device_token'],(int)$table['id'],$command['session_id'],
            $command['source'],$command['status'],$command['customer_note'],$total,
            $acceptedAt,$actor,$actor,$number,$business['business_date'],$business['shift_key'],$business['shift_label'],$business['cutoff'],
        ]);
        $orderId=(int)$this->pdo->lastInsertId();

        $lineInsert=$this->pdo->prepare(
            'INSERT INTO order_items(order_id,item_id,item_name,sellable_kind_snapshot,unit_price,quantity,ordered_quantity,item_note,fulfillment_mode,preparation_station,line_total) '.
            'VALUES(?,?,?,?,?,?,?,?,?,?,?)'
        );
        foreach($lines as $line){
            $lineInsert->execute([
                $orderId,$line['item_id'],$line['item_name'],$line['sellable_kind'],$line['unit_price'],
                $line['quantity'],$line['quantity'],$line['item_note'],$line['fulfillment_mode'],$line['preparation_station'],$line['line_total'],
            ]);
        }
        $this->pdo->prepare('INSERT INTO order_status_history(order_id,from_status,to_status,actor_user_id) VALUES(?,NULL,?,?)')
            ->execute([$orderId,$command['status'],$actor]);

        return [
            'success'=>true,'duplicate'=>false,'order_id'=>$orderId,'order_number'=>$number,
            'public_code'=>$publicCode,'total_amount'=>$total,
            'business_date'=>$business['business_date'],'business_shift_key'=>$business['shift_key'],
        ];
    }

    private function normalizeCommand(array $data): array
    {
        $source=(string)($data['source']??'guest');
        if(!in_array($source,['guest','staff'],true))throw new OrderCommitException('invalid_source','منبع سفارش معتبر نیست.',422);
        $tableId=(int)($data['table_id']??0);
        $sessionId=(int)($data['session_id']??0);$sessionId=$sessionId>0?$sessionId:null;
        $clientToken=trim((string)($data['client_token']??''));
        $deviceToken=trim((string)($data['device_token']??''));
        $actorUserId=(int)($data['actor_user_id']??0);
        $status=trim((string)($data['status']??($source==='staff'?'accounted':'new')));
        $allowedStatus=$source==='staff'?['accounted']:['new','pending_approval'];
        if($tableId<1||strlen($clientToken)<16||strlen($clientToken)>80||strlen($deviceToken)>80)
            throw new OrderCommitException('invalid_order','اطلاعات پایه سفارش معتبر نیست.',422);
        if($source==='staff'&&$actorUserId<1)throw new OrderCommitException('invalid_actor','کاربر ثبت‌کننده معتبر نیست.',403);
        if(!in_array($status,$allowedStatus,true))throw new OrderCommitException('invalid_status','وضعیت آغازین سفارش معتبر نیست.',422);

        $rows=$data['items']??null;
        if(!is_array($rows)||$rows===[]||count($rows)>30)throw new OrderCommitException('invalid_items','حداقل یک آیتم و حداکثر ۳۰ ردیف لازم است.',422);
        $items=[];$seen=[];$totalQuantity=0;
        foreach($rows as $row){
            if(!is_array($row))throw new OrderCommitException('invalid_items','ساختار آیتم معتبر نیست.',422);
            $itemId=filter_var($row['id']??null,FILTER_VALIDATE_INT);
            $quantity=filter_var($row['quantity']??null,FILTER_VALIDATE_INT);
            $mode=(string)($row['fulfillment_mode']??'dine_in')==='takeaway'?'takeaway':'dine_in';
            if($itemId===false||$itemId<1||$quantity===false||$quantity<1||$quantity>50)
                throw new OrderCommitException('invalid_items','شناسه یا تعداد آیتم معتبر نیست.',422);
            $key=$itemId.'|'.$mode;
            if(isset($seen[$key]))throw new OrderCommitException('duplicate_line','یک آیتم با روش سرو یکسان چند بار تکرار شده است.',422);
            $seen[$key]=true;
            $expected=$row['expected_price']??($row['unit_price']??null);
            $expected=$expected===null||$expected===''?null:filter_var($expected,FILTER_VALIDATE_INT);
            if($expected===false||($expected!==null&&$expected<0))throw new OrderCommitException('invalid_price','قیمت مورد انتظار معتبر نیست.',422);
            $items[]=[
                'id'=>(int)$itemId,'quantity'=>(int)$quantity,
                'expected_price'=>$expected===null?null:(int)$expected,
                'note'=>self::truncate(trim((string)($row['note']??'')),500),
                'fulfillment_mode'=>$mode,
            ];
            $totalQuantity+=(int)$quantity;
            if($totalQuantity>100)throw new OrderCommitException('too_many_items','تعداد کل سفارش نمی‌تواند بیشتر از ۱۰۰ باشد.',422);
        }
        return [
            'source'=>$source,'status'=>$status,'table_id'=>$tableId,'session_id'=>$sessionId,
            'client_token'=>$clientToken,'device_token'=>$deviceToken!==''?$deviceToken:null,
            'actor_user_id'=>$actorUserId>0?$actorUserId:null,
            'customer_note'=>self::truncate(trim((string)($data['customer_note']??'')),1000),
            'occurred_at'=>$data['occurred_at']??null,'items'=>$items,
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
        $stmt=$this->pdo->prepare('SELECT id,public_code,client_token,table_id,order_source,created_by_user_id,business_order_number,total_amount FROM orders WHERE client_token=? LIMIT 1 FOR UPDATE');
        $stmt->execute([$token]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row)?$row:null;
    }

    private function assertDuplicateOwnership(array $existing,array $command): void
    {
        if((int)$existing['table_id']!==$command['table_id']||(string)$existing['order_source']!==$command['source'])
            throw new OrderCommitException('idempotency_conflict','شناسه این ارسال متعلق به سفارش دیگری است.',409);
        if($command['source']==='staff'&&(int)$existing['created_by_user_id']!==(int)$command['actor_user_id'])
            throw new OrderCommitException('idempotency_conflict','شناسه این ارسال متعلق به کاربر دیگری است.',409);
    }

    private function lockCatalog(array $ids): array
    {
        $ids=array_values(array_unique(array_map('intval',$ids)));
        $ph=implode(',',array_fill(0,count($ids),'?'));
        $itemSchedule=self::itemScheduleSql('i');
        $menuSchedule=self::menuScheduleSql('mcat');
        $menuMembership="EXISTS(SELECT 1 FROM menu_items mi_cat
            JOIN menus mcat ON mcat.id=mi_cat.menu_id
            JOIN menu_categories mc_cat ON mc_cat.menu_id=mcat.id AND mc_cat.category_id=i.category_id
            WHERE mi_cat.item_id=i.id AND mcat.status='active' AND ($menuSchedule))";
        $stmt=$this->pdo->prepare("SELECT i.id,i.name,i.price,i.preparation_station,i.sellable_kind,i.available,i.active,i.staff_only,i.takeaway_allowed,
            COALESCE(c.active,0) category_active,COALESCE(c.audience,'guest_staff') category_audience,
            ($itemSchedule) schedule_active,($menuMembership) menu_active
            FROM items i LEFT JOIN categories c ON c.id=i.category_id
            WHERE i.id IN($ph) FOR UPDATE");
        $stmt->execute($ids);$result=[];
        foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row)$result[(int)$row['id']]=$row;
        return $result;
    }

    private function isOrderable(?array $item,string $source): bool
    {
        if($item===null||(int)($item['active']??0)!==1||(int)($item['available']??0)!==1||
            (int)($item['category_active']??0)!==1||(int)($item['schedule_active']??0)!==1||(int)($item['menu_active']??0)!==1)return false;
        if($source==='guest')return (int)($item['staff_only']??0)!==1&&(string)($item['category_audience']??'guest_staff')==='guest_staff';
        return true;
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

    private static function normalizeStation(string $station): string
    {
        if($station==='other')return 'cold_bar';
        return in_array($station,['kitchen','hot_bar','cold_bar','none'],true)?$station:'cold_bar';
    }

    private static function itemScheduleSql(string $a): string
    {
        $a=preg_replace('/[^A-Za-z0-9_]/','',$a)?:'i';
        return "($a.schedule_start IS NULL OR $a.schedule_start<=NOW())
            AND ($a.schedule_end IS NULL OR $a.schedule_end>=NOW())
            AND ($a.daily_start IS NULL OR $a.daily_end IS NULL OR
                ($a.daily_start<=$a.daily_end
                    AND ($a.schedule_days IS NULL OR $a.schedule_days='' OR FIND_IN_SET(DAYOFWEEK(NOW()),$a.schedule_days))
                    AND TIME(NOW()) BETWEEN $a.daily_start AND $a.daily_end)
                OR
                ($a.daily_start>$a.daily_end AND (
                    (TIME(NOW())>=$a.daily_start AND ($a.schedule_days IS NULL OR $a.schedule_days='' OR FIND_IN_SET(DAYOFWEEK(NOW()),$a.schedule_days)))
                    OR
                    (TIME(NOW())<=$a.daily_end AND ($a.schedule_days IS NULL OR $a.schedule_days='' OR FIND_IN_SET(IF(DAYOFWEEK(NOW())=1,7,DAYOFWEEK(NOW())-1),$a.schedule_days)))
                )))";
    }

    private static function menuScheduleSql(string $a): string
    {
        $a=preg_replace('/[^A-Za-z0-9_]/','',$a)?:'m';
        return "($a.daily_start IS NULL OR $a.daily_end IS NULL OR
            ($a.daily_start<=$a.daily_end
                AND ($a.schedule_days IS NULL OR $a.schedule_days='' OR FIND_IN_SET(DAYOFWEEK(NOW()),$a.schedule_days))
                AND TIME(NOW()) BETWEEN $a.daily_start AND $a.daily_end)
            OR
            ($a.daily_start>$a.daily_end AND (
                (TIME(NOW())>=$a.daily_start AND ($a.schedule_days IS NULL OR $a.schedule_days='' OR FIND_IN_SET(DAYOFWEEK(NOW()),$a.schedule_days)))
                OR
                (TIME(NOW())<=$a.daily_end AND ($a.schedule_days IS NULL OR $a.schedule_days='' OR FIND_IN_SET(IF(DAYOFWEEK(NOW())=1,7,DAYOFWEEK(NOW())-1),$a.schedule_days)))
            )))";
    }

    private static function truncate(string $value,int $length): string
    {
        if(function_exists('mb_substr'))return mb_substr($value,0,$length,'UTF-8');
        return substr($value,0,$length);
    }
}
