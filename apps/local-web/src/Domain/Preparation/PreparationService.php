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
        foreach($this->expandPreparationLines($lineStmt->fetchAll(PDO::FETCH_ASSOC)) as $line){
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

        $adjustmentStmt=$this->pdo->prepare(
            "SELECT a.id,a.order_id,a.order_item_id,a.area_key,a.adjustment_kind,a.from_quantity,a.to_quantity,a.delta_quantity,a.from_fulfillment_mode,a.to_fulfillment_mode,a.from_dine_in_quantity,a.to_dine_in_quantity,a.from_takeaway_quantity,a.to_takeaway_quantity,a.from_item_note,a.to_item_note,a.reason,a.correction_print_job_id,a.inventory_quantity_delta,a.inventory_effect,a.created_at,oi.item_name,u.display_name adjusted_by
             FROM order_item_adjustments a
             JOIN order_items oi ON oi.id=a.order_item_id
             LEFT JOIN users u ON u.id=a.actor_user_id
             WHERE a.order_id IN($ph) AND a.preparation_ack_status='pending'
             ORDER BY a.created_at,a.id"
        );
        $adjustmentStmt->execute($ids);$adjustments=[];
        foreach($adjustmentStmt->fetchAll(PDO::FETCH_ASSOC) as $row){
            $area=(string)($row['area_key']??'');
            if($area===''||!in_array($area,$visible,true))continue;
            $adjustments[(int)$row['order_id']][$area][]=[
                'id'=>(int)$row['id'],'order_item_id'=>(int)$row['order_item_id'],'item_name'=>(string)$row['item_name'],'adjustment_kind'=>(string)($row['adjustment_kind']??'quantity'),
                'from_quantity'=>(int)$row['from_quantity'],'to_quantity'=>(int)$row['to_quantity'],'delta_quantity'=>$row['delta_quantity']===null?0:(int)$row['delta_quantity'],
                'from_fulfillment_mode'=>(string)($row['from_fulfillment_mode']??''),'to_fulfillment_mode'=>(string)($row['to_fulfillment_mode']??''),
                'from_fulfillment_quantities'=>['dine_in'=>(int)($row['from_dine_in_quantity']??0),'takeaway'=>(int)($row['from_takeaway_quantity']??0)],
                'to_fulfillment_quantities'=>['dine_in'=>(int)($row['to_dine_in_quantity']??0),'takeaway'=>(int)($row['to_takeaway_quantity']??0)],
                'from_item_note'=>(string)($row['from_item_note']??''),'to_item_note'=>(string)($row['to_item_note']??''),'reason'=>(string)($row['reason']??''),
                'correction_print_job_id'=>(int)($row['correction_print_job_id']??0),'inventory_quantity_delta'=>(int)$row['inventory_quantity_delta'],'inventory_effect'=>(string)$row['inventory_effect'],
                'adjusted_by'=>(string)($row['adjusted_by']??''),'created_at'=>(string)$row['created_at'],
            ];
        }

        $out=[];
        foreach($orders as $order){
            $orderId=(int)$order['id'];
            $areas=[];
            $areaKeys=array_values(array_unique(array_merge(array_keys($byOrder[$orderId]??[]),array_keys($adjustments[$orderId]??[]))));
            foreach($areaKeys as $area){
                $items=$byOrder[$orderId][$area]??[];
                $claim=$claims[$orderId][$area]??null;
                $signature=self::itemsSignature($items);
                $areas[]=[
                    'area'=>$area,
                    'label'=>self::areaLabel($area),
                    'actionable'=>in_array($area,$context['actionable_areas'],true),
                    'items'=>$items,
                    'adjustments'=>$adjustments[$orderId][$area]??[],
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


    /**
     * Canonical, capability-neutral preparation read model for Public Staff.
     * Public Edge applies the session's visible/actionable areas before returning
     * this payload to a remote user. Local remains the sole business authority.
     *
     * @return array{orders:array<int,array<string,mixed>>}
     */
    public function publicProjection(): array
    {
        $visible=PreparationAccessService::AREAS;
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
        $orders=$ordersStmt->fetchAll(PDO::FETCH_ASSOC)?:[];
        if($orders===[])return ['orders'=>[]];

        $ids=array_map('intval',array_column($orders,'id'));
        $ph=implode(',',array_fill(0,count($ids),'?'));
        $lineStmt=$this->pdo->prepare(
            "SELECT id,order_id,item_name,quantity,item_note,fulfillment_mode,preparation_station
             FROM order_items WHERE order_id IN($ph) AND quantity>0 ORDER BY order_id,id"
        );
        $lineStmt->execute($ids);$byOrder=[];
        foreach($this->expandPreparationLines($lineStmt->fetchAll(PDO::FETCH_ASSOC)?:[]) as $line){
            $area=self::areaForStation((string)$line['preparation_station']);
            if($area===null||!in_array($area,$visible,true))continue;
            $line['id']=(int)$line['id'];$line['order_id']=(int)$line['order_id'];$line['quantity']=(int)$line['quantity'];$line['area']=$area;
            $byOrder[(int)$line['order_id']][$area][]=$line;
        }

        $claimStmt=$this->pdo->prepare(
            "SELECT c.order_id,c.area_key,c.claimed_at,c.claimed_by_user_id,c.item_signature,u.display_name claimed_by
             FROM order_preparation_claims c
             LEFT JOIN users u ON u.id=c.claimed_by_user_id
             WHERE c.order_id IN($ph)"
        );
        $claimStmt->execute($ids);$claims=[];
        foreach($claimStmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $claim)$claims[(int)$claim['order_id']][(string)$claim['area_key']]=$claim;

        $adjustmentStmt=$this->pdo->prepare(
            "SELECT a.id,a.order_id,a.order_item_id,a.area_key,a.adjustment_kind,a.from_quantity,a.to_quantity,a.delta_quantity,a.from_fulfillment_mode,a.to_fulfillment_mode,a.from_dine_in_quantity,a.to_dine_in_quantity,a.from_takeaway_quantity,a.to_takeaway_quantity,a.from_item_note,a.to_item_note,a.reason,a.correction_print_job_id,a.inventory_quantity_delta,a.inventory_effect,a.created_at,oi.item_name,u.display_name adjusted_by
             FROM order_item_adjustments a
             JOIN order_items oi ON oi.id=a.order_item_id
             LEFT JOIN users u ON u.id=a.actor_user_id
             WHERE a.order_id IN($ph) AND a.preparation_ack_status='pending'
             ORDER BY a.created_at,a.id"
        );
        $adjustmentStmt->execute($ids);$adjustments=[];
        foreach($adjustmentStmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){
            $area=(string)($row['area_key']??'');if($area===''||!in_array($area,$visible,true))continue;
            $adjustments[(int)$row['order_id']][$area][]=[
                'id'=>(int)$row['id'],'order_item_id'=>(int)$row['order_item_id'],'item_name'=>(string)$row['item_name'],'adjustment_kind'=>(string)($row['adjustment_kind']??'quantity'),
                'from_quantity'=>(int)$row['from_quantity'],'to_quantity'=>(int)$row['to_quantity'],'delta_quantity'=>$row['delta_quantity']===null?0:(int)$row['delta_quantity'],
                'from_fulfillment_mode'=>(string)($row['from_fulfillment_mode']??''),'to_fulfillment_mode'=>(string)($row['to_fulfillment_mode']??''),
                'from_fulfillment_quantities'=>['dine_in'=>(int)($row['from_dine_in_quantity']??0),'takeaway'=>(int)($row['from_takeaway_quantity']??0)],
                'to_fulfillment_quantities'=>['dine_in'=>(int)($row['to_dine_in_quantity']??0),'takeaway'=>(int)($row['to_takeaway_quantity']??0)],
                'from_item_note'=>(string)($row['from_item_note']??''),'to_item_note'=>(string)($row['to_item_note']??''),'reason'=>(string)($row['reason']??''),
                'correction_print_job_id'=>(int)($row['correction_print_job_id']??0),'inventory_quantity_delta'=>(int)$row['inventory_quantity_delta'],'inventory_effect'=>(string)$row['inventory_effect'],
                'adjusted_by'=>(string)($row['adjusted_by']??''),'created_at'=>(string)$row['created_at'],
            ];
        }

        $out=[];
        foreach($orders as $order){
            $orderId=(int)$order['id'];$areas=[];
            $areaKeys=array_values(array_unique(array_merge(array_keys($byOrder[$orderId]??[]),array_keys($adjustments[$orderId]??[]))));
            foreach($areaKeys as $area){
                $items=$byOrder[$orderId][$area]??[];$claim=$claims[$orderId][$area]??null;$signature=self::itemsSignature($items);
                $areas[]=[
                    'area'=>$area,'label'=>self::areaLabel($area),'items'=>$items,'adjustments'=>$adjustments[$orderId][$area]??[],
                    'claim'=>$claim===null?null:[
                        'claimed_by_user_id'=>$claim['claimed_by_user_id']!==null?(int)$claim['claimed_by_user_id']:null,
                        'claimed_by'=>(string)($claim['claimed_by']??''),'claimed_at'=>(string)$claim['claimed_at'],
                        'current'=>(string)$claim['item_signature']===$signature,
                    ],
                ];
            }
            if($areas===[])continue;
            $out[]=[
                'id'=>$orderId,'order_number'=>(int)$order['business_order_number'],'order_context'=>(string)($order['order_context']??'table_service'),
                'table_id'=>$order['table_id']===null?null:(int)$order['table_id'],'table_name'=>$this->displayContext($order),
                'consumer_personnel_id'=>$order['consumer_personnel_id']===null?null:(int)$order['consumer_personnel_id'],'consumer_name'=>(string)($order['consumer_name_snapshot']??''),
                'status'=>(string)$order['status'],'business_date'=>(string)$order['business_date'],'created_at'=>(string)$order['created_at'],'areas'=>$areas,
            ];
        }
        return ['orders'=>$out];
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
        foreach($this->expandPreparationLines($itemStmt->fetchAll(PDO::FETCH_ASSOC)) as $line){
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

    /** @return array{has_claim:bool,current:bool} */
    public function claimStateForAdjustmentTx(int $orderId,string $area): array
    {
        if(!$this->pdo->inTransaction())throw new \LogicException('Preparation adjustment inspection requires an open transaction.');
        if(!in_array($area,PreparationAccessService::AREAS,true))return ['has_claim'=>false,'current'=>false];
        $items=$this->areaItemsTx($orderId,$area);
        $stmt=$this->pdo->prepare('SELECT item_signature FROM order_preparation_claims WHERE order_id=? AND area_key=? FOR UPDATE');
        $stmt->execute([$orderId,$area]);$signature=$stmt->fetchColumn();
        if($signature===false)return ['has_claim'=>false,'current'=>false];
        return ['has_claim'=>true,'current'=>hash_equals((string)$signature,self::itemsSignature($items))];
    }

    public function refreshClaimSignatureTx(int $orderId,string $area): void
    {
        if(!$this->pdo->inTransaction())throw new \LogicException('Preparation adjustment refresh requires an open transaction.');
        if(!in_array($area,PreparationAccessService::AREAS,true))return;
        $items=$this->areaItemsTx($orderId,$area);
        $this->pdo->prepare('UPDATE order_preparation_claims SET item_signature=?,updated_at=NOW() WHERE order_id=? AND area_key=?')
            ->execute([self::itemsSignature($items),$orderId,$area]);
    }

    public function acknowledgeAdjustment(array $data,array $user): array
    {
        $ownsTransaction=!$this->pdo->inTransaction();if($ownsTransaction)$this->pdo->beginTransaction();
        try{
            $context=$this->access->context($user);$actor=$context['user'];
            if(!is_array($actor))throw new PreparationException('forbidden','حساب کاربری فعال نیست.',403);
            $id=(int)($data['adjustment_id']??0);if($id<1)throw new PreparationException('invalid_adjustment','اصلاح سفارش معتبر نیست.',422);
            $stmt=$this->pdo->prepare('SELECT a.*,o.status order_status FROM order_item_adjustments a JOIN orders o ON o.id=a.order_id WHERE a.id=? FOR UPDATE');
            $stmt->execute([$id]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
            if(!is_array($row))throw new PreparationException('adjustment_not_found','اصلاح سفارش پیدا نشد.',404);
            $area=(string)($row['area_key']??'');
            if($area===''||!in_array($area,$context['actionable_areas'],true))throw new PreparationException('forbidden_area','این اصلاح برای بخش آماده‌سازی شما نیست.',403);
            if((string)$row['preparation_ack_status']==='acknowledged'){
                if($ownsTransaction)$this->pdo->commit();return ['success'=>true,'duplicate'=>true,'adjustment_id'=>$id,'status'=>'acknowledged'];
            }
            if((string)$row['preparation_ack_status']!=='pending')throw new PreparationException('adjustment_not_pending','این اصلاح نیازمند تأیید آماده‌سازی نیست.',409);
            $actorId=(int)$actor['id'];
            $update=$this->pdo->prepare("UPDATE order_item_adjustments SET preparation_ack_status='acknowledged',acknowledged_by_user_id=?,acknowledged_at=NOW() WHERE id=? AND preparation_ack_status='pending'");
            $update->execute([$actorId,$id]);
            if($update->rowCount()!==1)throw new PreparationException('adjustment_changed','وضعیت اصلاح هم‌زمان تغییر کرده است.',409);
            $this->audit('preparation.adjustment_acknowledged','order_item_adjustment',$id,$actor,[
                'order_id'=>(int)$row['order_id'],'order_item_id'=>(int)$row['order_item_id'],'area'=>$area,
                'from_quantity'=>(int)$row['from_quantity'],'to_quantity'=>(int)$row['to_quantity'],'delta_quantity'=>(int)$row['delta_quantity'],
            ]);
            if($ownsTransaction)$this->pdo->commit();return ['success'=>true,'duplicate'=>false,'adjustment_id'=>$id,'status'=>'acknowledged'];
        }catch(Throwable $e){if($ownsTransaction&&$this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public static function areaForPreparationStation(string $station): ?string
    {
        return self::areaForStation($station);
    }

    private function areaItemsTx(int $orderId,string $area): array
    {
        $stmt=$this->pdo->prepare('SELECT id,order_id,item_name,quantity,item_note,fulfillment_mode,preparation_station FROM order_items WHERE order_id=? AND quantity>0 ORDER BY id FOR UPDATE');
        $stmt->execute([$orderId]);$items=[];
        foreach($this->expandPreparationLines($stmt->fetchAll(PDO::FETCH_ASSOC)) as $line){
            if(self::areaForStation((string)$line['preparation_station'])!==$area)continue;
            $line['id']=(int)$line['id'];$line['order_id']=(int)$line['order_id'];$line['quantity']=(int)$line['quantity'];$items[]=$line;
        }
        return $items;
    }

    /** @param list<array<string,mixed>> $lines @return list<array<string,mixed>> */
    private function expandPreparationLines(array $lines): array
    {
        if($lines===[])return [];
        $ids=array_values(array_filter(array_map(static fn(array $r):int=>(int)($r['id']??0),$lines),static fn(int $id):bool=>$id>0));
        $by=[];
        if($ids!==[]){
            $ph=implode(',',array_fill(0,count($ids),'?'));
            $stmt=$this->pdo->prepare("SELECT id,order_item_id,item_name_snapshot,quantity,preparation_station_snapshot,component_mode,choice_label_snapshot FROM order_item_bundle_components WHERE order_item_id IN($ph) ORDER BY order_item_id,id");
            $stmt->execute($ids);foreach($stmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $row)$by[(int)$row['order_item_id']][]=$row;
        }
        $out=[];
        foreach($lines as $line){
            $parentId=(int)($line['id']??0);$components=$by[$parentId]??[];
            if($components===[]){$out[]=$line;continue;}
            foreach($components as $component){
                $copy=$line;$copy['id']=$parentId*1000000+(int)$component['id'];$copy['bundle_parent_order_item_id']=$parentId;$copy['bundle_parent_name']=(string)($line['item_name']??'ترکیب');
                $copy['item_name']=(string)$component['item_name_snapshot'].' — از ترکیب «'.(string)($line['item_name']??'ترکیب').'»';$copy['quantity']=(int)$component['quantity'];
                $copy['preparation_station']=(string)$component['preparation_station_snapshot'];$copy['bundle_component_mode']=(string)$component['component_mode'];$copy['bundle_choice_label']=$component['choice_label_snapshot'];$out[]=$copy;
            }
        }
        return $out;
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
