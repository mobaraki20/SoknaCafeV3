<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\Orders;

use PDO;
use Sokna\Local\Core\Capabilities;
use Sokna\Local\Core\IdentityRepository;
use Sokna\Local\Domain\Inventory\InventoryOrderService;
use Sokna\Local\Domain\Printing\PrintService;
use Sokna\Local\Domain\Preparation\PreparationService;
use Throwable;

final class OrderStaffActionService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly IdentityRepository $identity,
        private readonly Capabilities $capabilities,
        private readonly InventoryOrderService $inventoryOrders,
        private readonly PrintService $printing,
        private readonly PreparationService $preparation,
    ){}


    /** @return array{preparation:bool,direct:bool,itemized:bool,subscriber:bool,accommodation:bool} */
    public function printQuickPreferences(array $user): array
    {
        $this->actorAny($user,['orders_floor','cashier_accounts','shift_supervision']);
        $keys=[
            'preparation'=>'printing.quick.auto_preparation',
            'direct'=>'printing.quick.receipt_direct',
            'itemized'=>'printing.quick.receipt_itemized',
            'subscriber'=>'printing.quick.receipt_subscriber',
            'accommodation'=>'printing.quick.receipt_accommodation',
        ];
        $stmt=$this->pdo->prepare('SELECT setting_value FROM settings WHERE setting_key=? LIMIT 1');$out=[];
        foreach($keys as $name=>$key){$stmt->execute([$key]);$raw=$stmt->fetchColumn();$out[$name]=$raw===false?true:in_array(strtolower(trim((string)$raw)),['1','true','yes','on'],true);}
        return $out;
    }

    public function updatePrintQuickPreferences(array $data,array $user): array
    {
        $actor=$this->actorAny($user,['shift_supervision']);$prefs=$this->printQuickPreferences($actor);
        foreach(array_keys($prefs) as $name)if(array_key_exists($name,$data))$prefs[$name]=filter_var($data[$name],FILTER_VALIDATE_BOOLEAN);
        $keys=['preparation'=>'printing.quick.auto_preparation','direct'=>'printing.quick.receipt_direct','itemized'=>'printing.quick.receipt_itemized','subscriber'=>'printing.quick.receipt_subscriber','accommodation'=>'printing.quick.receipt_accommodation'];
        $this->pdo->beginTransaction();try{$up=$this->pdo->prepare('INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');foreach($keys as $name=>$key)$up->execute([$key,$prefs[$name]?'1':'0']);$this->audit('printing.quick_preferences_updated',0,$actor,['preferences'=>$prefs]);$this->pdo->commit();return ['success'=>true,'preferences'=>$prefs];}catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    private function preparationAutoPrintEnabled(): bool
    {
        $stmt=$this->pdo->prepare("SELECT setting_value FROM settings WHERE setting_key='printing.quick.auto_preparation' LIMIT 1");$stmt->execute();$raw=$stmt->fetchColumn();return $raw===false||in_array(strtolower(trim((string)$raw)),['1','true','yes','on'],true);
    }

    public function changeStatus(array $data,array $user): array
    {
        $ownsTransaction=!$this->pdo->inTransaction();if($ownsTransaction)$this->pdo->beginTransaction();
        try{$result=$this->changeStatusTx($data,$user);if($ownsTransaction)$this->pdo->commit();return $result;}
        catch(Throwable $e){if($ownsTransaction&&$this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function changeStatusTx(array $data,array $user): array
    {
        if(!$this->pdo->inTransaction())throw new \LogicException('Order staff action requires an open transaction.');
        $actor=$this->actor($user);$actorId=(int)$actor['id'];
        $orderId=(int)($data['order_id']??0);$target=trim((string)($data['status']??''));
        if($orderId<1||!in_array($target,['accounted','cancelled'],true))
            throw new OrderStaffActionException('invalid_action','این عملیات فقط برای تأیید یا رد سفارش منتظر است.',422);

        $probe=$this->pdo->prepare('SELECT table_id,session_id FROM orders WHERE id=? LIMIT 1');
        $probe->execute([$orderId]);$ids=$probe->fetch(PDO::FETCH_ASSOC);
        if(!is_array($ids))throw new OrderStaffActionException('not_found','سفارش پیدا نشد.',404);
        $tableId=(int)$ids['table_id'];$sessionId=(int)($ids['session_id']??0);

        $table=$this->pdo->prepare('SELECT id,name FROM cafe_tables WHERE id=? FOR UPDATE');
        $table->execute([$tableId]);$tableRow=$table->fetch(PDO::FETCH_ASSOC);
        if(!is_array($tableRow))throw new OrderStaffActionException('table_missing','میز سفارش پیدا نشد.',409);

        $session=null;
        if($sessionId>0){
            $stmt=$this->pdo->prepare('SELECT id,status,table_id FROM table_sessions WHERE id=? FOR UPDATE');
            $stmt->execute([$sessionId]);$session=$stmt->fetch(PDO::FETCH_ASSOC);
            if(!is_array($session)||(int)$session['table_id']!==$tableId)throw new OrderStaffActionException('session_changed','نشست این سفارش تغییر کرده است.',409);
        }

        $stmt=$this->pdo->prepare('SELECT id,status,accepted_by_user_id,session_id,table_id FROM orders WHERE id=? FOR UPDATE');
        $stmt->execute([$orderId]);$order=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!is_array($order))throw new OrderStaffActionException('not_found','سفارش پیدا نشد.',404);
        $old=(string)$order['status'];
        if(!in_array($old,['pending_approval','new'],true)){
            if($old===$target)return ['success'=>true,'idempotent'=>true,'order_id'=>$orderId,'status'=>$old];
            throw new OrderStaffActionException('order_closed','این سفارش دیگر منتظر تأیید نیست.',409,['current_status'=>$old]);
        }

        if($target==='accounted'){
            if($sessionId>0&&is_array($session)&&(string)$session['status']==='pending'){
                $activate=$this->pdo->prepare("UPDATE table_sessions SET status='active',live_table_guard=table_id,opened_by_user_id=COALESCE(opened_by_user_id,?) WHERE id=? AND status='pending'");
                $activate->execute([$actorId,$sessionId]);
                if($activate->rowCount()!==1)throw new OrderStaffActionException('session_changed','نشست میز هم‌زمان تغییر کرده است.',409);
            }elseif($sessionId>0&&is_array($session)&&(string)$session['status']!=='active'){
                throw new OrderStaffActionException('session_closed','نشست این میز پایان یافته است.',409);
            }
            $update=$this->pdo->prepare("UPDATE orders SET status='accounted',accepted_at=COALESCE(accepted_at,NOW()),accepted_by_user_id=COALESCE(accepted_by_user_id,?) WHERE id=? AND status=?");
            $update->execute([$actorId,$orderId,$old]);
            if($update->rowCount()!==1)throw new OrderStaffActionException('order_changed','این سفارش هم‌زمان توسط همکار دیگری بررسی شد.',409);
            $this->pdo->prepare("INSERT INTO order_status_history(order_id,from_status,to_status,actor_user_id) VALUES(?,?,'accounted',?)")->execute([$orderId,$old,$actorId]);
            $this->inventoryOrders->enqueueAccountedTx($orderId,$actorId);
            if($this->preparationAutoPrintEnabled())$this->printing->enqueueOrderTx($orderId,$actorId);
        }else{
            $update=$this->pdo->prepare("UPDATE orders SET status='cancelled',updated_at=NOW() WHERE id=? AND status=?");
            $update->execute([$orderId,$old]);
            if($update->rowCount()!==1)throw new OrderStaffActionException('order_changed','این سفارش هم‌زمان توسط همکار دیگری بررسی شد.',409);
            $this->pdo->prepare("INSERT INTO order_status_history(order_id,from_status,to_status,actor_user_id) VALUES(?,?,'cancelled',?)")->execute([$orderId,$old,$actorId]);
            if($sessionId>0&&is_array($session)&&(string)$session['status']==='pending'){
                $remaining=$this->pdo->prepare("SELECT COUNT(*) FROM orders WHERE session_id=? AND status IN('pending_approval','new')");
                $remaining->execute([$sessionId]);
                if((int)$remaining->fetchColumn()===0){
                    $this->pdo->prepare("UPDATE waiter_calls SET status='cancelled',active_table_guard=NULL,cancelled_at=COALESCE(cancelled_at,NOW()),cancel_reason=COALESCE(cancel_reason,'pending_rejected'),cancelled_by_user_id=COALESCE(cancelled_by_user_id,?) WHERE session_id=? AND status IN('new','accepted')")->execute([$actorId,$sessionId]);
                    $this->pdo->prepare("UPDATE table_sessions SET status='closed',live_table_guard=NULL,ended_at=COALESCE(ended_at,NOW()) WHERE id=? AND status='pending'")->execute([$sessionId]);
                }
            }
        }

        $this->audit('order.status_changed',$orderId,$actor,['from_status'=>$old,'to_status'=>$target,'table_id'=>$tableId,'session_id'=>$sessionId]);
        return ['success'=>true,'idempotent'=>false,'order_id'=>$orderId,'status'=>$target,'table_id'=>$tableId,'table_name'=>(string)$tableRow['name']];
    }

    public function adjustItemQuantity(array $data,array $user): array
    {
        if(!array_key_exists('fulfillment_quantities',$data))return $this->adjustSingleItem($data,$user);
        return $this->adjustItemDistribution($data,$user);
    }

    /**
     * Group-aware committed correction. One menu item may have two physical order-item rows
     * (dine-in / takeaway), but the operator edits them as one semantic quantity.
     * Inventory follows only the total quantity delta; redistribution between service modes is neutral.
     */
    private function adjustItemDistribution(array $data,array $user): array
    {
        $ownsTransaction=!$this->pdo->inTransaction();if($ownsTransaction)$this->pdo->beginTransaction();
        try{
            $actor=$this->actor($user);$actorId=(int)$actor['id'];
            $orderItemId=(int)($data['order_item_id']??0);$toQuantity=(int)($data['quantity']??-1);$requestId=$this->requestId((string)($data['request_id']??''));
            $reason=self::cutText(trim((string)($data['reason']??'')),250);if($reason==='')$reason='اصلاح سفارش توسط اپراتور';
            if($orderItemId<1||$toQuantity<0||$toQuantity>99)throw new OrderStaffActionException('invalid_quantity_adjustment','تعداد جدید باید بین صفر تا ۹۹ باشد.',422);
            $distribution=$data['fulfillment_quantities']??null;if(!is_array($distribution))throw new OrderStaffActionException('invalid_fulfillment_distribution','تفکیک تعداد داخل کافه و بیرون‌بر معتبر نیست.',422);
            $toDine=(int)($distribution['dine_in']??-1);$toTake=(int)($distribution['takeaway']??-1);
            if($toDine<0||$toTake<0||$toDine>99||$toTake>99||$toDine+$toTake!==$toQuantity)throw new OrderStaffActionException('invalid_fulfillment_distribution','جمع تعداد داخل کافه و بیرون‌بر باید با تعداد کل برابر باشد.',422,[
                'quantity'=>$toQuantity,'dine_in'=>$toDine,'takeaway'=>$toTake,
            ]);
            $noteProvided=array_key_exists('item_note',$data);$requestedNote=$noteProvided?self::cutText(trim((string)$data['item_note']),500):null;

            $existing=$this->pdo->prepare('SELECT * FROM order_item_adjustments WHERE request_id=? FOR UPDATE');$existing->execute([$requestId]);$done=$existing->fetch(PDO::FETCH_ASSOC);
            if(is_array($done)){
                $conflict=(int)$done['order_item_id']!==$orderItemId||(int)$done['to_quantity']!==$toQuantity
                    ||(int)($done['to_dine_in_quantity']??-1)!==$toDine||(int)($done['to_takeaway_quantity']??-1)!==$toTake;
                if($noteProvided&&array_key_exists('to_item_note',$done)&&trim((string)($done['to_item_note']??''))!==$requestedNote)$conflict=true;
                if($conflict)throw new OrderStaffActionException('request_id_conflict','این شناسه قبلاً برای اصلاح دیگری استفاده شده است.',409);
                if($ownsTransaction)$this->pdo->commit();return $this->adjustmentResult($done,true);
            }

            $anchorStmt=$this->pdo->prepare("SELECT oi.*,o.status order_status,o.session_id,o.table_id,s.status session_status,s.live_table_guard
                FROM order_items oi JOIN orders o ON o.id=oi.order_id LEFT JOIN table_sessions s ON s.id=o.session_id WHERE oi.id=? FOR UPDATE");
            $anchorStmt->execute([$orderItemId]);$anchor=$anchorStmt->fetch(PDO::FETCH_ASSOC);
            if(!is_array($anchor))throw new OrderStaffActionException('order_item_not_found','قلم سفارش پیدا نشد.',404);
            if((string)$anchor['order_status']!=='accounted')throw new OrderStaffActionException('order_not_adjustable','فقط قلم سفارش ثبت‌شده در حساب باز قابل اصلاح است.',409);
            $sessionId=(int)($anchor['session_id']??0);if($sessionId<1||(string)($anchor['session_status']??'')!=='active'||empty($anchor['live_table_guard']))throw new OrderStaffActionException('session_closed','این حساب دیگر به میز فعال متصل نیست و تغییر سفارش روی آن مجاز نیست.',409);
            $settled=$this->pdo->prepare("SELECT 1 FROM settlement_records sr LEFT JOIN settlement_records rv ON rv.reverses_settlement_id=sr.id AND rv.status='reversal' WHERE sr.session_id=? AND sr.status='completed' AND rv.id IS NULL LIMIT 1");
            $settled->execute([$sessionId]);if($settled->fetchColumn()!==false)throw new OrderStaffActionException('settlement_started','برای این حساب پرداخت ثبت شده است؛ ابتدا وضعیت تسویه را اصلاح کنید.',409);

            $groupStmt=$this->pdo->prepare("SELECT * FROM order_items WHERE order_id=? AND item_name=? AND unit_price=? AND preparation_station=? AND (item_id <=> ?) AND (item_note <=> ?) ORDER BY id FOR UPDATE");
            $groupStmt->execute([(int)$anchor['order_id'],(string)$anchor['item_name'],(int)$anchor['unit_price'],(string)$anchor['preparation_station'],$anchor['item_id'],$anchor['item_note']]);
            $rows=$groupStmt->fetchAll(PDO::FETCH_ASSOC);if(!$rows)$rows=[$anchor];
            $fromDine=0;$fromTake=0;foreach($rows as $r){$q=max(0,(int)$r['quantity']);if((string)($r['fulfillment_mode']??'dine_in')==='takeaway')$fromTake+=$q;else $fromDine+=$q;}
            $fromQuantity=$fromDine+$fromTake;$fromNote=trim((string)($anchor['item_note']??''));$toNote=$noteProvided?$requestedNote:$fromNote;
            $this->assertPriceSnapshotIncreaseAllowed($anchor,$fromQuantity,$toQuantity);
            $quantityChanged=$fromQuantity!==$toQuantity;$distributionChanged=$fromDine!==$toDine||$fromTake!==$toTake;$noteChanged=$fromNote!==$toNote;
            if(!$quantityChanged&&!$distributionChanged&&!$noteChanged){if($ownsTransaction)$this->pdo->commit();return ['success'=>true,'idempotent'=>true,'no_change'=>true,'order_item_id'=>$orderItemId,'display_order_item_id'=>$orderItemId,'quantity'=>$toQuantity,'fulfillment_quantities'=>['dine_in'=>$toDine,'takeaway'=>$toTake],'item_note'=>$toNote];}

            $orderId=(int)$anchor['order_id'];$area=PreparationService::areaForPreparationStation((string)$anchor['preparation_station']);$prepRelevant=$area!==null&&($quantityChanged||$distributionChanged||$noteChanged);$claimedBefore=false;
            if($prepRelevant){
                $claim=$this->preparation->claimStateForAdjustmentTx($orderId,$area);
                if($claim['has_claim']&&!$claim['current'])throw new OrderStaffActionException('preparation_state_changed','آماده‌سازی این سفارش تغییر کرده است؛ ابتدا وضعیت بخش آماده‌سازی را بررسی کنید.',409,['area'=>$area]);
                $claimedBefore=$claim['has_claim']&&$claim['current'];
                if($claimedBefore){$pending=$this->pdo->prepare("SELECT id FROM order_item_adjustments WHERE order_id=? AND area_key=? AND preparation_ack_status='pending' LIMIT 1 FOR UPDATE");$pending->execute([$orderId,$area]);if($pending->fetchColumn()!==false)throw new OrderStaffActionException('preparation_adjustment_pending','یک اصلاح قبلی این سفارش هنوز در بخش آماده‌سازی تأیید نشده است.',409,['area'=>$area]);}
            }

            $byMode=['dine_in'=>[],'takeaway'=>[]];foreach($rows as $r){$m=(string)($r['fulfillment_mode']??'dine_in')==='takeaway'?'takeaway':'dine_in';$byMode[$m][]=$r;}
            $targets=['dine_in'=>$toDine,'takeaway'=>$toTake];$displayOrderItemId=0;
            foreach(['dine_in','takeaway'] as $mode){
                $target=$targets[$mode];$modeRows=$byMode[$mode];$primary=null;
                foreach($modeRows as $candidate)if((int)$candidate['id']===$orderItemId){$primary=$candidate;break;}
                if($primary===null&&$modeRows)$primary=$modeRows[0];
                if($primary!==null){
                    $primaryId=(int)$primary['id'];$this->pdo->prepare('UPDATE order_items SET quantity=?,line_total=?,item_note=? WHERE id=?')->execute([$target,(int)$anchor['unit_price']*$target,$toNote!==''?$toNote:null,$primaryId]);
                    if($target>0&&$displayOrderItemId===0)$displayOrderItemId=$primaryId;
                    foreach($modeRows as $extra)if((int)$extra['id']!==$primaryId)$this->pdo->prepare('UPDATE order_items SET quantity=0,line_total=0,item_note=? WHERE id=?')->execute([$toNote!==''?$toNote:null,(int)$extra['id']]);
                }elseif($target>0){
                    $insert=$this->pdo->prepare('INSERT INTO order_items(order_id,item_id,category_id_snapshot,category_name_snapshot,category_snapshot_quality,item_name,sellable_kind_snapshot,unit_price,quantity,ordered_quantity,item_note,fulfillment_mode,preparation_station,line_total,tax_policy_snapshot,tax_rate_bps_snapshot,tax_rate_version_id,tax_item_policy_version_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
                    $insert->execute([$orderId,$anchor['item_id'],$anchor['category_id_snapshot']??null,$anchor['category_name_snapshot']??null,$anchor['category_snapshot_quality']??'unknown',(string)$anchor['item_name'],$anchor['sellable_kind_snapshot']??null,(int)$anchor['unit_price'],$target,0,$toNote!==''?$toNote:null,$mode,(string)$anchor['preparation_station'],(int)$anchor['unit_price']*$target,(string)($anchor['tax_policy_snapshot']??'disabled'),(int)($anchor['tax_rate_bps_snapshot']??0),$anchor['tax_rate_version_id']??null,$anchor['tax_item_policy_version_id']??null]);
                    $newId=(int)$this->pdo->lastInsertId();if($displayOrderItemId===0)$displayOrderItemId=$newId;
                }
            }
            if($displayOrderItemId===0)$displayOrderItemId=$orderItemId;
            $this->pdo->prepare('UPDATE orders SET total_amount=(SELECT COALESCE(SUM(line_total),0) FROM order_items WHERE order_id=?),updated_at=NOW() WHERE id=?')->execute([$orderId,$orderId]);

            $delta=$quantityChanged?$toQuantity-$fromQuantity:0;
            if($quantityChanged){$inventoryDelta=$delta<0&&$claimedBefore?0:$delta;$inventoryEffect=$delta>0?'consume_delta':($claimedBefore?'preserve_consumption_after_claim':'reverse_unprepared');}else{$inventoryDelta=0;$inventoryEffect='none';}
            $ackStatus=$claimedBefore&&$prepRelevant?'pending':'not_required';$changes=($quantityChanged?1:0)+($distributionChanged?1:0)+($noteChanged?1:0);$kind=$changes>1?'combined':($quantityChanged?'quantity':($distributionChanged?'fulfillment':'note'));
            $fromMode=(string)($anchor['fulfillment_mode']??'dine_in');$toMode=$fromMode;
            if($distributionChanged){$dDine=$toDine-$fromDine;$dTake=$toTake-$fromTake;if($dDine<0&&$dTake>0){$fromMode='dine_in';$toMode='takeaway';}elseif($dTake<0&&$dDine>0){$fromMode='takeaway';$toMode='dine_in';}elseif($toTake>0&&$fromTake===0){$fromMode='dine_in';$toMode='takeaway';}elseif($toDine>0&&$fromDine===0){$fromMode='takeaway';$toMode='dine_in';}}
            $insertAdjustment=$this->pdo->prepare('INSERT INTO order_item_adjustments(request_id,order_id,order_item_id,area_key,adjustment_kind,from_quantity,to_quantity,delta_quantity,from_fulfillment_mode,to_fulfillment_mode,from_dine_in_quantity,to_dine_in_quantity,from_takeaway_quantity,to_takeaway_quantity,from_item_note,to_item_note,reason,preparation_claimed_before,preparation_ack_status,inventory_quantity_delta,inventory_effect,actor_user_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $insertAdjustment->execute([$requestId,$orderId,$orderItemId,$area,$kind,$fromQuantity,$toQuantity,$quantityChanged?$delta:null,$fromMode,$toMode,$fromDine,$toDine,$fromTake,$toTake,$fromNote!==''?$fromNote:null,$toNote!==''?$toNote:null,$reason,$claimedBefore?1:0,$ackStatus,$inventoryDelta,$inventoryEffect,$actorId]);
            $adjustmentId=(int)$this->pdo->lastInsertId();
            if($claimedBefore&&$prepRelevant)$this->preparation->refreshClaimSignatureTx($orderId,$area);
            $inventoryEventId=$quantityChanged?$this->inventoryOrders->enqueueQuantityAdjustedTx($orderId,$orderItemId,$fromQuantity,$toQuantity,$inventoryDelta,$actorId,$requestId):null;
            $printJob=null;if($prepRelevant&&$this->preparationAutoPrintEnabled()){$printJob=$this->printing->enqueueOrderAdjustmentTx($adjustmentId,$actorId);if(is_array($printJob)&&!empty($printJob['job_id']))$this->pdo->prepare('UPDATE order_item_adjustments SET correction_print_job_id=? WHERE id=?')->execute([(int)$printJob['job_id'],$adjustmentId]);}
            $this->audit($kind==='quantity'?'order.item_quantity_adjusted':'order.item_preparation_adjusted',$orderId,$actor,[
                'adjustment_id'=>$adjustmentId,'adjustment_kind'=>$kind,'order_item_id'=>$orderItemId,'display_order_item_id'=>$displayOrderItemId,'item_name'=>(string)$anchor['item_name'],'table_id'=>(int)$anchor['table_id'],'session_id'=>$sessionId,
                'from_quantity'=>$fromQuantity,'to_quantity'=>$toQuantity,'delta_quantity'=>$quantityChanged?$delta:null,'from_fulfillment_quantities'=>['dine_in'=>$fromDine,'takeaway'=>$fromTake],'to_fulfillment_quantities'=>['dine_in'=>$toDine,'takeaway'=>$toTake],'item_note_changed'=>$noteChanged,
                'preparation_area'=>$area,'preparation_claimed_before'=>$claimedBefore,'preparation_ack_status'=>$ackStatus,'inventory_quantity_delta'=>$inventoryDelta,'inventory_effect'=>$inventoryEffect,'inventory_event_id'=>$inventoryEventId,'correction_print_job_id'=>(int)($printJob['job_id']??0),'correction_print_status'=>(string)($printJob['status']??''),'request_id'=>$requestId,'reason'=>$reason,
            ]);
            $fresh=$this->pdo->prepare('SELECT * FROM order_item_adjustments WHERE id=?');$fresh->execute([$adjustmentId]);$adjustment=$fresh->fetch(PDO::FETCH_ASSOC);
            if($ownsTransaction)$this->pdo->commit();return $this->adjustmentResult(is_array($adjustment)?$adjustment:[],false)+['display_order_item_id'=>$displayOrderItemId,'inventory_event_id'=>$inventoryEventId,'correction_print_status'=>(string)($printJob['status']??'')];
        }catch(Throwable $e){if($ownsTransaction&&$this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    private function adjustSingleItem(array $data,array $user): array
    {
        $ownsTransaction=!$this->pdo->inTransaction();if($ownsTransaction)$this->pdo->beginTransaction();
        try{
            $actor=$this->actor($user);$actorId=(int)$actor['id'];
            $orderItemId=(int)($data['order_item_id']??0);$toQuantity=(int)($data['quantity']??-1);$requestId=$this->requestId((string)($data['request_id']??''));
            $reason=self::cutText(trim((string)($data['reason']??'')),250);if($reason==='')$reason='اصلاح سفارش توسط اپراتور';
            if($orderItemId<1||$toQuantity<0||$toQuantity>99)throw new OrderStaffActionException('invalid_quantity_adjustment','تعداد جدید باید بین صفر تا ۹۹ باشد.',422);
            $requestedMode=array_key_exists('fulfillment_mode',$data)?trim((string)$data['fulfillment_mode']):null;
            if($requestedMode!==null&&!in_array($requestedMode,['dine_in','takeaway'],true))throw new OrderStaffActionException('invalid_fulfillment_mode','نحوه سرو انتخاب‌شده معتبر نیست.',422);
            $noteProvided=array_key_exists('item_note',$data);$requestedNote=$noteProvided?self::cutText(trim((string)$data['item_note']),500):null;

            $existing=$this->pdo->prepare('SELECT * FROM order_item_adjustments WHERE request_id=? FOR UPDATE');$existing->execute([$requestId]);$done=$existing->fetch(PDO::FETCH_ASSOC);
            if(is_array($done)){
                $conflict=(int)$done['order_item_id']!==$orderItemId||(int)$done['to_quantity']!==$toQuantity;
                if($requestedMode!==null&&$done['to_fulfillment_mode']!==null&&(string)$done['to_fulfillment_mode']!==$requestedMode)$conflict=true;
                if($noteProvided&&array_key_exists('to_item_note',$done)&&trim((string)($done['to_item_note']??''))!==$requestedNote)$conflict=true;
                if($conflict)throw new OrderStaffActionException('request_id_conflict','این شناسه قبلاً برای اصلاح دیگری استفاده شده است.',409);
                if($ownsTransaction)$this->pdo->commit();return $this->adjustmentResult($done,true);
            }

            $stmt=$this->pdo->prepare("SELECT oi.id order_item_id,oi.order_id,oi.item_name,oi.quantity,oi.ordered_quantity,oi.unit_price,oi.item_note,oi.fulfillment_mode,oi.preparation_station,o.status order_status,o.session_id,o.table_id,s.status session_status,s.live_table_guard
                FROM order_items oi JOIN orders o ON o.id=oi.order_id LEFT JOIN table_sessions s ON s.id=o.session_id WHERE oi.id=? FOR UPDATE");
            $stmt->execute([$orderItemId]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
            if(!is_array($row))throw new OrderStaffActionException('order_item_not_found','قلم سفارش پیدا نشد.',404);
            if((string)$row['order_status']!=='accounted')throw new OrderStaffActionException('order_not_adjustable','فقط قلم سفارش ثبت‌شده در حساب باز قابل اصلاح است.',409);
            $sessionId=(int)($row['session_id']??0);if($sessionId<1||(string)($row['session_status']??'')!=='active'||empty($row['live_table_guard']))throw new OrderStaffActionException('session_closed','این حساب دیگر به میز فعال متصل نیست و تغییر سفارش روی آن مجاز نیست.',409);
            $settled=$this->pdo->prepare("SELECT 1 FROM settlement_records sr LEFT JOIN settlement_records rv ON rv.reverses_settlement_id=sr.id AND rv.status='reversal' WHERE sr.session_id=? AND sr.status='completed' AND rv.id IS NULL LIMIT 1");
            $settled->execute([$sessionId]);if($settled->fetchColumn()!==false)throw new OrderStaffActionException('settlement_started','برای این حساب پرداخت ثبت شده است؛ ابتدا وضعیت تسویه را اصلاح کنید.',409);

            $fromQuantity=(int)$row['quantity'];$fromMode=(string)($row['fulfillment_mode']??'dine_in');$fromNote=trim((string)($row['item_note']??''));
            $this->assertPriceSnapshotIncreaseAllowed($row,$fromQuantity,$toQuantity);
            $toMode=$requestedMode??$fromMode;$toNote=$noteProvided?$requestedNote:$fromNote;
            $quantityChanged=$fromQuantity!==$toQuantity;$modeChanged=$fromMode!==$toMode;$noteChanged=$fromNote!==$toNote;
            if(!$quantityChanged&&!$modeChanged&&!$noteChanged){if($ownsTransaction)$this->pdo->commit();return ['success'=>true,'idempotent'=>true,'no_change'=>true,'order_item_id'=>$orderItemId,'quantity'=>$toQuantity,'fulfillment_mode'=>$toMode,'item_note'=>$toNote];}

            $orderId=(int)$row['order_id'];$area=PreparationService::areaForPreparationStation((string)$row['preparation_station']);$prepRelevant=$area!==null&&($quantityChanged||$modeChanged||$noteChanged);$claimedBefore=false;
            if($prepRelevant){
                $claim=$this->preparation->claimStateForAdjustmentTx($orderId,$area);
                if($claim['has_claim']&&!$claim['current'])throw new OrderStaffActionException('preparation_state_changed','آماده‌سازی این سفارش تغییر کرده است؛ ابتدا وضعیت بخش آماده‌سازی را بررسی کنید.',409,['area'=>$area]);
                $claimedBefore=$claim['has_claim']&&$claim['current'];
                if($claimedBefore){
                    $pending=$this->pdo->prepare("SELECT id FROM order_item_adjustments WHERE order_id=? AND area_key=? AND preparation_ack_status='pending' LIMIT 1 FOR UPDATE");
                    $pending->execute([$orderId,$area]);if($pending->fetchColumn()!==false)throw new OrderStaffActionException('preparation_adjustment_pending','یک اصلاح قبلی این سفارش هنوز در بخش آماده‌سازی تأیید نشده است.',409,['area'=>$area]);
                }
            }

            $delta=$quantityChanged?$toQuantity-$fromQuantity:0;$lineTotal=(int)$row['unit_price']*$toQuantity;
            $this->pdo->prepare('UPDATE order_items SET quantity=?,line_total=?,fulfillment_mode=?,item_note=? WHERE id=?')->execute([$toQuantity,$lineTotal,$toMode,$toNote!==''?$toNote:null,$orderItemId]);
            $this->pdo->prepare('UPDATE orders SET total_amount=(SELECT COALESCE(SUM(line_total),0) FROM order_items WHERE order_id=?),updated_at=NOW() WHERE id=?')->execute([$orderId,$orderId]);

            if($quantityChanged){
                $inventoryDelta=$delta<0&&$claimedBefore?0:$delta;
                $inventoryEffect=$delta>0?'consume_delta':($claimedBefore?'preserve_consumption_after_claim':'reverse_unprepared');
            }else{$inventoryDelta=0;$inventoryEffect='none';}
            $ackStatus=$claimedBefore&&$prepRelevant?'pending':'not_required';
            $changes=($quantityChanged?1:0)+($modeChanged?1:0)+($noteChanged?1:0);$kind=$changes>1?'combined':($quantityChanged?'quantity':($modeChanged?'fulfillment':'note'));
            $insert=$this->pdo->prepare('INSERT INTO order_item_adjustments(request_id,order_id,order_item_id,area_key,adjustment_kind,from_quantity,to_quantity,delta_quantity,from_fulfillment_mode,to_fulfillment_mode,from_item_note,to_item_note,reason,preparation_claimed_before,preparation_ack_status,inventory_quantity_delta,inventory_effect,actor_user_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $insert->execute([$requestId,$orderId,$orderItemId,$area,$kind,$fromQuantity,$toQuantity,$quantityChanged?$delta:null,$fromMode,$toMode,$fromNote!==''?$fromNote:null,$toNote!==''?$toNote:null,$reason,$claimedBefore?1:0,$ackStatus,$inventoryDelta,$inventoryEffect,$actorId]);
            $adjustmentId=(int)$this->pdo->lastInsertId();
            if($claimedBefore&&$prepRelevant)$this->preparation->refreshClaimSignatureTx($orderId,$area);
            $inventoryEventId=$quantityChanged?$this->inventoryOrders->enqueueQuantityAdjustedTx($orderId,$orderItemId,$fromQuantity,$toQuantity,$inventoryDelta,$actorId,$requestId):null;
            $printJob=null;if($prepRelevant&&$this->preparationAutoPrintEnabled()){$printJob=$this->printing->enqueueOrderAdjustmentTx($adjustmentId,$actorId);if(is_array($printJob)&&!empty($printJob['job_id']))$this->pdo->prepare('UPDATE order_item_adjustments SET correction_print_job_id=? WHERE id=?')->execute([(int)$printJob['job_id'],$adjustmentId]);}
            $this->audit($kind==='quantity'?'order.item_quantity_adjusted':'order.item_preparation_adjusted',$orderId,$actor,[
                'adjustment_id'=>$adjustmentId,'adjustment_kind'=>$kind,'order_item_id'=>$orderItemId,'item_name'=>(string)$row['item_name'],'table_id'=>(int)$row['table_id'],'session_id'=>$sessionId,
                'from_quantity'=>$fromQuantity,'to_quantity'=>$toQuantity,'delta_quantity'=>$quantityChanged?$delta:null,'ordered_quantity'=>(int)$row['ordered_quantity'],
                'from_fulfillment_mode'=>$fromMode,'to_fulfillment_mode'=>$toMode,'item_note_changed'=>$noteChanged,
                'preparation_area'=>$area,'preparation_claimed_before'=>$claimedBefore,'preparation_ack_status'=>$ackStatus,
                'inventory_quantity_delta'=>$inventoryDelta,'inventory_effect'=>$inventoryEffect,'inventory_event_id'=>$inventoryEventId,'correction_print_job_id'=>(int)($printJob['job_id']??0),'correction_print_status'=>(string)($printJob['status']??''),'request_id'=>$requestId,'reason'=>$reason,
            ]);
            $fresh=$this->pdo->prepare('SELECT * FROM order_item_adjustments WHERE id=?');$fresh->execute([$adjustmentId]);$adjustment=$fresh->fetch(PDO::FETCH_ASSOC);
            if($ownsTransaction)$this->pdo->commit();return $this->adjustmentResult(is_array($adjustment)?$adjustment:[],false)+['inventory_event_id'=>$inventoryEventId,'correction_print_status'=>(string)($printJob['status']??'')];
        }catch(Throwable $e){if($ownsTransaction&&$this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function reprintPreparation(array $data,array $user): array
    {
        $ownsTransaction=!$this->pdo->inTransaction();if($ownsTransaction)$this->pdo->beginTransaction();
        try{
            $actor=$this->actor($user);$actorId=(int)$actor['id'];$orderId=(int)($data['order_id']??0);$requestId=trim((string)($data['request_id']??''));
            if($orderId<1)throw new OrderStaffActionException('invalid_order','سفارش معتبر نیست.',422);
            $stmt=$this->pdo->prepare("SELECT o.id,o.status,o.table_id,o.session_id,s.status session_status FROM orders o LEFT JOIN table_sessions s ON s.id=o.session_id WHERE o.id=? FOR UPDATE");
            $stmt->execute([$orderId]);$order=$stmt->fetch(PDO::FETCH_ASSOC);
            if(!is_array($order))throw new OrderStaffActionException('not_found','سفارش پیدا نشد.',404);
            if((string)$order['status']!=='accounted')throw new OrderStaffActionException('reprint_closed','فقط سفارش ثبت‌شده در حساب باز قابل چاپ مجدد است.',409);
            if((int)($order['session_id']??0)<1||(string)($order['session_status']??'')!=='active')throw new OrderStaffActionException('session_closed','حساب این میز دیگر باز نیست.',409);
            $job=$this->printing->enqueueOrderReprintTx($orderId,$actorId,$requestId);
            $this->audit('order.preparation_reprint_requested',$orderId,$actor,['table_id'=>(int)$order['table_id'],'session_id'=>(int)$order['session_id'],'print_job_id'=>(int)($job['job_id']??0),'print_status'=>(string)($job['status']??''),'request_id'=>$requestId]);
            if($ownsTransaction)$this->pdo->commit();return ['success'=>true]+$job;
        }catch(Throwable $e){if($ownsTransaction&&$this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    private function adjustmentResult(array $row,bool $idempotent): array
    {
        return [
            'success'=>true,'idempotent'=>$idempotent,'adjustment_id'=>(int)($row['id']??0),'adjustment_kind'=>(string)($row['adjustment_kind']??'quantity'),'order_id'=>(int)($row['order_id']??0),'order_item_id'=>(int)($row['order_item_id']??0),
            'from_quantity'=>(int)($row['from_quantity']??0),'to_quantity'=>(int)($row['to_quantity']??0),'delta_quantity'=>$row['delta_quantity']===null?0:(int)$row['delta_quantity'],
            'from_fulfillment_mode'=>(string)($row['from_fulfillment_mode']??''),'to_fulfillment_mode'=>(string)($row['to_fulfillment_mode']??''),
            'from_fulfillment_quantities'=>['dine_in'=>(int)($row['from_dine_in_quantity']??0),'takeaway'=>(int)($row['from_takeaway_quantity']??0)],
            'to_fulfillment_quantities'=>['dine_in'=>(int)($row['to_dine_in_quantity']??0),'takeaway'=>(int)($row['to_takeaway_quantity']??0)],
            'item_note_changed'=>(string)($row['from_item_note']??'')!==(string)($row['to_item_note']??''),
            'preparation_area'=>$row['area_key']??null,'preparation_ack_required'=>(string)($row['preparation_ack_status']??'')==='pending',
            'inventory_quantity_delta'=>(int)($row['inventory_quantity_delta']??0),'inventory_effect'=>(string)($row['inventory_effect']??'none'),
            'correction_print_job_id'=>(int)($row['correction_print_job_id']??0),'reason'=>(string)($row['reason']??''),
        ];
    }

    private function assertPriceSnapshotIncreaseAllowed(array $row,int $fromQuantity,int $toQuantity): void
    {
        if($toQuantity<=$fromQuantity)return;
        $itemId=(int)($row['item_id']??0);
        if($itemId<1)return;
        $recordedPrice=(int)($row['unit_price']??0);
        $stmt=$this->pdo->prepare('SELECT price FROM items WHERE id=? LIMIT 1');
        $stmt->execute([$itemId]);$current=$stmt->fetchColumn();
        if($current===false||$current===null)return;
        $currentPrice=(int)$current;
        if($currentPrice===$recordedPrice)return;
        throw new OrderStaffActionException(
            'price_segment_required',
            'قیمت این قلم بعد از ثبت تغییر کرده است. تعداد قبلی با قیمت زمان ثبت حفظ می‌شود؛ برای افزایش، مقدار تازه را از «افزودن سفارش» با قیمت فعلی ثبت کنید.',
            409,
            ['item_id'=>$itemId,'recorded_price'=>$recordedPrice,'current_price'=>$currentPrice,'from_quantity'=>$fromQuantity,'requested_quantity'=>$toQuantity]
        );
    }

    private function requestId(string $value): string
    {
        $value=trim($value);
        if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,95}$/',$value))throw new OrderStaffActionException('invalid_request_id','شناسه یکتای اصلاح سفارش معتبر نیست.',422);
        return $value;
    }

    /** @param list<string> $needs */
    private function actorAny(array $user,array $needs): array
    {
        $id=(int)($user['id']??0);$fresh=$id>0?$this->identity->findActiveById($id):null;
        if($fresh===null)throw new OrderStaffActionException('forbidden','حساب کاربری فعال نیست.',403);
        foreach($needs as $cap)if($this->capabilities->has($cap,$fresh))return $fresh;
        throw new OrderStaffActionException('forbidden','دسترسی این بخش برای حساب شما فعال نیست.',403);
    }

    private function actor(array $user): array
    {
        $id=(int)($user['id']??0);$fresh=$id>0?$this->identity->findActiveById($id):null;
        if($fresh===null||!$this->capabilities->has('orders_floor',$fresh))
            throw new OrderStaffActionException('forbidden','دسترسی رسیدگی به سفارش برای این حساب فعال نیست.',403);
        return $fresh;
    }

    private static function cutText(string $value,int $length): string
    {
        if(function_exists('mb_substr'))return mb_substr($value,0,$length,'UTF-8');
        return substr($value,0,$length);
    }

    private function audit(string $action,int $entityId,array $actor,array $details): void
    {
        $json=json_encode($details,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?:'{}';
        $this->pdo->prepare('INSERT INTO audit_log(actor_user_id,actor_display_name_snapshot,action,entity_type,entity_id,details_json) VALUES(?,?,?,?,?,?)')
            ->execute([(int)$actor['id'],(string)$actor['display_name'],$action,'order',(string)$entityId,$json]);
    }
}
