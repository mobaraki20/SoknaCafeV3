<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\Preparation;

use PDO;
use Sokna\Local\Domain\Orders\BusinessClock;
use Throwable;

final class PreparationService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly PreparationAccessService $access,
        private readonly BusinessClock $clock,
    ) {}

    /**
     * Side-effect-free Preparation feed.
     * @return array<string,mixed>
     */
    public function feed(array $user): array
    {
        $context=$this->access->context($user);
        $visible=$context['visible_areas'];
        if($visible===[]){
            return ['success'=>true,'permissions'=>$this->permissionPayload($context),'orders'=>[]];
        }

        $businessDate=$this->clock->assignment()['business_date'];
        $ordersStmt=$this->pdo->prepare(
            "SELECT o.id,o.table_id,o.order_context,o.status,o.business_order_number,o.business_date,o.created_at,t.name table_name,
                    sc.consumer_personnel_id,sc.consumer_name_snapshot
             FROM orders o
             LEFT JOIN cafe_tables t ON t.id=o.table_id
             LEFT JOIN staff_consumptions sc ON sc.order_id=o.id
             WHERE o.business_date=? AND o.status IN ('accounted','completed')
             ORDER BY o.created_at,o.id"
        );
        $ordersStmt->execute([$businessDate]);
        $orders=$ordersStmt->fetchAll(PDO::FETCH_ASSOC);
        if(!$orders){
            return ['success'=>true,'permissions'=>$this->permissionPayload($context),'orders'=>[]];
        }

        $ids=array_map('intval',array_column($orders,'id'));
        $ph=implode(',',array_fill(0,count($ids),'?'));
        $lineStmt=$this->pdo->prepare(
            "SELECT id,order_id,item_name,quantity,item_note,fulfillment_mode,preparation_station
             FROM order_items WHERE order_id IN($ph) AND quantity>0 ORDER BY order_id,id"
        );
        $lineStmt->execute($ids);
        $byOrder=[];
        foreach($lineStmt->fetchAll(PDO::FETCH_ASSOC) as $line){
            $area=self::areaForStation((string)$line['preparation_station']);
            if($area===null||!in_array($area,$visible,true))continue;
            $line['id']=(int)$line['id'];
            $line['order_id']=(int)$line['order_id'];
            $line['quantity']=(int)$line['quantity'];
            $line['area']=$area;
            $byOrder[(int)$line['order_id']][$area][]=$line;
        }

        $claimStmt=$this->pdo->prepare(
            "SELECT c.order_id,c.area_key,c.claimed_at,c.claimed_by_user_id,c.item_signature,u.display_name claimed_by
             FROM order_preparation_claims c
             LEFT JOIN users u ON u.id=c.claimed_by_user_id
             WHERE c.order_id IN($ph)"
        );
        $claimStmt->execute($ids);
        $claims=[];
        foreach($claimStmt->fetchAll(PDO::FETCH_ASSOC) as $claim){
            $claims[(int)$claim['order_id']][(string)$claim['area_key']]=$claim;
        }

        $out=[];
        foreach($orders as $order){
            $orderId=(int)$order['id'];
            $areas=[];
            foreach($byOrder[$orderId]??[] as $area=>$items){
                $claim=$claims[$orderId][$area]??null;
                $signature=self::itemsSignature($items);
                $areas[]=[
                    'area'=>$area,
                    'label'=>self::areaLabel($area),
                    'actionable'=>in_array($area,$context['actionable_areas'],true),
                    'items'=>$items,
                    'claim'=>$claim===null?null:[
                        'claimed_by_user_id'=>$claim['claimed_by_user_id']!==null?(int)$claim['claimed_by_user_id']:null,
                        'claimed_by'=>(string)($claim['claimed_by']??''),
                        'claimed_at'=>(string)$claim['claimed_at'],
                        'current'=>(string)$claim['item_signature']===$signature,
                    ],
                ];
            }
            if($areas===[])continue;
            $out[]=[
                'id'=>$orderId,
                'order_number'=>(int)$order['business_order_number'],
                'order_context'=>(string)($order['order_context']??'table_service'),
                'table_id'=>$order['table_id']===null?null:(int)$order['table_id'],
                'table_name'=>$this->displayContext($order),
                'consumer_personnel_id'=>$order['consumer_personnel_id']===null?null:(int)$order['consumer_personnel_id'],
                'consumer_name'=>(string)($order['consumer_name_snapshot']??''),
                'status'=>(string)$order['status'],
                'business_date'=>(string)$order['business_date'],
                'created_at'=>(string)$order['created_at'],
                'areas'=>$areas,
            ];
        }

        return ['success'=>true,'permissions'=>$this->permissionPayload($context),'orders'=>$out];
    }

    public function claim(array $data,array $user): array
    {
        $this->pdo->beginTransaction();
        try{
            $result=$this->claimTx($data,$user);
            $this->pdo->commit();
            return $result;
        }catch(Throwable $e){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $e;
        }
    }

    public function claimTx(array $data,array $user): array
    {
        if(!$this->pdo->inTransaction())throw new \LogicException('Preparation claim requires an open transaction.');
        $context=$this->access->context($user);
        $actor=$context['user'];
        if(!is_array($actor))throw new PreparationException('forbidden','حساب کاربری فعال نیست.',403);

        $orderId=(int)($data['order_id']??0);
        $area=trim((string)($data['area']??''));
        if($orderId<1)throw new PreparationException('invalid_order','سفارش معتبر نیست.',422);
        if(!in_array($area,PreparationAccessService::AREAS,true))
            throw new PreparationException('invalid_area','بخش آماده‌سازی معتبر نیست.',422);
        if(!in_array($area,$context['actionable_areas'],true))
            throw new PreparationException('forbidden_area','این بخش آماده‌سازی برای حساب شما فعال نیست.',403,['area'=>$area]);

        $orderStmt=$this->pdo->prepare(
            "SELECT o.id,o.status,o.table_id,o.order_context,t.name table_name,sc.consumer_personnel_id,sc.consumer_name_snapshot
             FROM orders o
             LEFT JOIN cafe_tables t ON t.id=o.table_id
             LEFT JOIN staff_consumptions sc ON sc.order_id=o.id
             WHERE o.id=? FOR UPDATE"
        );
        $orderStmt->execute([$orderId]);
        $order=$orderStmt->fetch(PDO::FETCH_ASSOC);
        if(!is_array($order))throw new PreparationException('not_found','سفارش پیدا نشد.',404);
        if(!in_array((string)$order['status'],['accounted','completed'],true))
            throw new PreparationException('order_not_confirmed','فقط سفارش تأییدشده قابل دریافت است.',409);

        $itemStmt=$this->pdo->prepare(
            'SELECT id,order_id,item_name,quantity,item_note,fulfillment_mode,preparation_station
             FROM order_items WHERE order_id=? AND quantity>0 ORDER BY id FOR UPDATE'
        );
        $itemStmt->execute([$orderId]);
        $items=[];
        foreach($itemStmt->fetchAll(PDO::FETCH_ASSOC) as $line){
            if(self::areaForStation((string)$line['preparation_station'])!==$area)continue;
            $line['id']=(int)$line['id'];
            $line['order_id']=(int)$line['order_id'];
            $line['quantity']=(int)$line['quantity'];
            $items[]=$line;
        }
        if($items===[])throw new PreparationException('empty_area','این سفارش آیتمی برای بخش انتخاب‌شده ندارد.',409);

        $signature=self::itemsSignature($items);
        $claimStmt=$this->pdo->prepare(
            'SELECT c.*,u.display_name claimed_by
             FROM order_preparation_claims c
             LEFT JOIN users u ON u.id=c.claimed_by_user_id
             WHERE c.order_id=? AND c.area_key=? FOR UPDATE'
        );
        $claimStmt->execute([$orderId,$area]);
        $existing=$claimStmt->fetch(PDO::FETCH_ASSOC);
        $actorId=(int)$actor['id'];

        if(is_array($existing)&&hash_equals((string)$existing['item_signature'],$signature)){
            if((int)($existing['claimed_by_user_id']??0)===$actorId){
                return [
                    'success'=>true,'duplicate'=>true,'order_id'=>$orderId,'area'=>$area,
                    'claimed_by_user_id'=>$actorId,'item_signature'=>$signature,
                ];
            }
            throw new PreparationException(
                'already_claimed',
                'این بخش قبلاً توسط '.((string)($existing['claimed_by']??'یکی از همکاران')).' گرفته شده است.',
                409,
                ['claimed_by_user_id'=>$existing['claimed_by_user_id']!==null?(int)$existing['claimed_by_user_id']:null]
            );
        }

        $upsert=$this->pdo->prepare(
            'INSERT INTO order_preparation_claims(order_id,area_key,claimed_at,claimed_by_user_id,item_signature)
             VALUES(?,?,NOW(),?,?)
             ON DUPLICATE KEY UPDATE claimed_at=NOW(),claimed_by_user_id=VALUES(claimed_by_user_id),item_signature=VALUES(item_signature)'
        );
        $upsert->execute([$orderId,$area,$actorId,$signature]);

        $this->audit('preparation.claimed','order',$orderId,$actor,[
            'area'=>$area,'order_context'=>(string)($order['order_context']??'table_service'),
            'table_id'=>$order['table_id']===null?null:(int)$order['table_id'],
            'consumer_personnel_id'=>$order['consumer_personnel_id']===null?null:(int)$order['consumer_personnel_id'],
            'consumer_name'=>(string)($order['consumer_name_snapshot']??''),'item_signature'=>$signature,
        ]);

        return [
            'success'=>true,'duplicate'=>false,'order_id'=>$orderId,'area'=>$area,
            'claimed_by_user_id'=>$actorId,'item_signature'=>$signature,
        ];
    }

    private function displayContext(array $order): string
    {
        if((string)($order['order_context']??'table_service')==='staff_consumption'){
            $name=trim((string)($order['consumer_name_snapshot']??''));
            return 'مصرف پرسنل'.($name!==''?' · '.$name:'');
        }
        return (string)($order['table_name']??'');
    }

    private function permissionPayload(array $context): array
    {
        return [
            'preparation_visible'=>(bool)$context['can_view'],
            'preparation_actionable'=>(bool)$context['can_mutate'],
            'monitor_only'=>(bool)$context['monitor_only'],
            'visible_preparation_areas'=>$context['visible_areas'],
            'actionable_preparation_areas'=>$context['actionable_areas'],
        ];
    }

    private function audit(string $action,string $entityType,int $entityId,array $actor,array $details): void
    {
        $json=json_encode($details,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $stmt=$this->pdo->prepare(
            'INSERT INTO audit_log(actor_user_id,actor_display_name_snapshot,action,entity_type,entity_id,details_json)
             VALUES(?,?,?,?,?,?)'
        );
        $stmt->execute([
            (int)$actor['id'],(string)$actor['display_name'],$action,$entityType,(string)$entityId,$json?:'{}',
        ]);
    }

    private static function areaForStation(string $station): ?string
    {
        return match($station){
            'none'=>null,
            'kitchen'=>'kitchen',
            default=>'bar',
        };
    }

    private static function areaLabel(string $area): string
    {
        return $area==='kitchen'?'آشپزخانه':'بار';
    }

    private static function itemsSignature(array $items): string
    {
        $normalized=[];
        foreach($items as $item){
            $quantity=(int)($item['quantity']??0);
            if($quantity<1)continue;
            $normalized[]=[
                'id'=>(int)($item['id']??0),
                'name'=>(string)($item['item_name']??''),
                'quantity'=>$quantity,
                'note'=>trim((string)($item['item_note']??'')),
                'fulfillment_mode'=>(string)($item['fulfillment_mode']??'dine_in')==='takeaway'?'takeaway':'dine_in',
                'station'=>self::normalizeStation((string)($item['preparation_station']??'cold_bar')),
            ];
        }
        usort($normalized,static fn(array $a,array $b):int=>($a['id']<=>$b['id'])?:strcmp($a['name'],$b['name'])?:strcmp($a['fulfillment_mode'],$b['fulfillment_mode']));
        $json=json_encode($normalized,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if(!is_string($json))throw new \RuntimeException('Preparation signature encoding failed.');
        return hash('sha256',$json);
    }

    private static function normalizeStation(string $station): string
    {
        if($station==='other')return 'cold_bar';
        return in_array($station,['kitchen','hot_bar','cold_bar','none'],true)?$station:'cold_bar';
    }
}
