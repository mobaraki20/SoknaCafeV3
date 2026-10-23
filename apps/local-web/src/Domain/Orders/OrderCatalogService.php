<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\Orders;

use PDO;
use Sokna\Local\Domain\Sellables\SellableKind;

final class OrderCatalogService
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return list<array<string,mixed>> */
    public function staffCatalogRows(): array
    {
        // Staff may intentionally choose Cafe / Breakfast / Lunch regardless of the
        // current service window. Schedules remain a Guest ordering constraint, not
        // a reason to make Staff tabs disappear from the order-entry workspace.
        $menuMembership="EXISTS(SELECT 1 FROM menu_items mi_cat
            JOIN menus mcat ON mcat.id=mi_cat.menu_id
            JOIN menu_categories mc_cat ON mc_cat.menu_id=mcat.id AND mc_cat.category_id=i.category_id
            WHERE mi_cat.item_id=i.id AND mcat.status='active')";
        $rows=$this->pdo->query("SELECT i.id,i.name,i.price,i.preparation_station,i.sellable_kind,i.takeaway_allowed,
            c.id category_id,c.name category_name,c.sort_order category_sort,i.sort_order,
            vm.family_id variant_family_id,vm.label variant_label,vf.name variant_family_name,vf.sort_order variant_family_sort,
            IF(b.bundle_item_id IS NULL,0,1) is_bundle,b.choice_label bundle_choice_label
            FROM items i JOIN categories c ON c.id=i.category_id
            LEFT JOIN item_variant_members vm ON vm.item_id=i.id LEFT JOIN item_variant_families vf ON vf.id=vm.family_id AND vf.active=1
            LEFT JOIN item_bundles b ON b.bundle_item_id=i.id
            WHERE i.lifecycle_status='active' AND i.staff_visible=1 AND i.available=1 AND c.lifecycle_status='active' AND c.staff_visible=1 AND ($menuMembership)
            ORDER BY c.sort_order,c.id,i.sort_order,i.id")->fetchAll(PDO::FETCH_ASSOC);
        $mapped=array_map(static fn(array $row):array=>[
            'id'=>(int)$row['id'],'name'=>(string)$row['name'],'price'=>(int)$row['price'],
            'preparation_station'=>(string)($row['preparation_station']??'other'),'sellable_kind'=>SellableKind::normalizeRead($row['sellable_kind']??null),
            'takeaway_allowed'=>(int)($row['takeaway_allowed']??1)===1,'category_id'=>(int)$row['category_id'],'category_name'=>(string)$row['category_name'],
            'variant_family_id'=>$row['variant_family_id']!==null?(int)$row['variant_family_id']:null,'variant_family_name'=>(string)($row['variant_family_name']??''),'variant_label'=>(string)($row['variant_label']??''),
            'is_bundle'=>(int)($row['is_bundle']??0)===1,'bundle_choice_label'=>$row['bundle_choice_label']??null,'bundle_components'=>[],
        ],$rows);
        $byId=[];foreach($mapped as $i=>$row)$byId[(int)$row['id']]=$i;
        if($byId!==[]){$ids=array_keys($byId);$ph=implode(',',array_fill(0,count($ids),'?'));$q=$this->pdo->prepare("SELECT c.bundle_item_id,c.component_item_id,c.mode,c.quantity,c.price_delta,c.sort_order,i.name,i.price,i.available,i.lifecycle_status,i.staff_visible FROM item_bundle_components c JOIN items i ON i.id=c.component_item_id WHERE c.bundle_item_id IN($ph) ORDER BY c.bundle_item_id,c.sort_order,c.id");$q->execute($ids);foreach($q->fetchAll(PDO::FETCH_ASSOC)?:[] as $r){$bid=(int)$r['bundle_item_id'];if(!isset($byId[$bid]))continue;$mapped[$byId[$bid]]['bundle_components'][]=['item_id'=>(int)$r['component_item_id'],'name'=>(string)$r['name'],'mode'=>(string)$r['mode'],'quantity'=>(int)$r['quantity'],'price_delta'=>(int)$r['price_delta'],'available'=>(string)$r['lifecycle_status']==='active'&&(int)$r['staff_visible']===1&&(int)$r['available']===1,'sort_order'=>(int)$r['sort_order']];}}
        return $mapped;
    }

    /**
     * Canonical main ordering groups. `menus` owns the top level; categories/items
     * are only memberships inside an active menu. Item details stay in
     * staffCatalogRows() so this read model remains small and additive.
     *
     * @return list<array{id:int,menu_key:string,name:string,sort_order:int,categories:list<array{id:int,name:string,icon_key:string,sort_order:int,item_ids:list<int>}>>}
     */
    public function staffCatalogGroups(): array
    {
        $rows=$this->pdo->query("SELECT
            m.id menu_id,m.menu_key,m.name menu_name,m.sort_order menu_sort,
            c.id category_id,c.name category_name,c.icon_key,mc.sort_order category_sort,
            i.id item_id,i.sort_order item_sort
            FROM menus m
            JOIN menu_categories mc ON mc.menu_id=m.id
            JOIN categories c ON c.id=mc.category_id
            JOIN menu_items mi ON mi.menu_id=m.id
            JOIN items i ON i.id=mi.item_id AND i.category_id=c.id
            WHERE m.status='active'
              AND c.lifecycle_status='active' AND c.staff_visible=1 AND i.lifecycle_status='active' AND i.staff_visible=1 AND i.available=1
            ORDER BY m.sort_order,m.id,mc.sort_order,c.id,i.sort_order,i.id")->fetchAll(PDO::FETCH_ASSOC);

        $groups=[];$groupIndex=[];$categoryIndex=[];
        foreach($rows as $row){
            $menuId=(int)$row['menu_id'];$categoryId=(int)$row['category_id'];
            if(!isset($groupIndex[$menuId])){
                $groupIndex[$menuId]=count($groups);
                $groups[]=[
                    'id'=>$menuId,'menu_key'=>(string)$row['menu_key'],'name'=>(string)$row['menu_name'],
                    'sort_order'=>(int)$row['menu_sort'],'categories'=>[],
                ];
                $categoryIndex[$menuId]=[];
            }
            $g=$groupIndex[$menuId];
            if(!isset($categoryIndex[$menuId][$categoryId])){
                $categoryIndex[$menuId][$categoryId]=count($groups[$g]['categories']);
                $groups[$g]['categories'][]=[
                    'id'=>$categoryId,'name'=>(string)$row['category_name'],'icon_key'=>(string)($row['icon_key']??''),
                    'sort_order'=>(int)$row['category_sort'],'item_ids'=>[],
                ];
            }
            $c=$categoryIndex[$menuId][$categoryId];
            $groups[$g]['categories'][$c]['item_ids'][]=(int)$row['item_id'];
        }
        return $groups;
    }

    /** @return list<array{id:int,quantity:int,expected_price:?int,note:string,fulfillment_mode:string,bundle_choice_item_id:int}> */
    public function normalizeRows(mixed $rows, bool $allowEmpty = false): array
    {
        if ($rows === null && $allowEmpty) return [];
        if (!is_array($rows) || (!$allowEmpty && $rows === []) || count($rows) > 30) {
            throw new OrderCommitException('invalid_items', $allowEmpty ? 'حداکثر ۳۰ ردیف مجاز است.' : 'حداقل یک آیتم و حداکثر ۳۰ ردیف لازم است.', 422);
        }
        if ($rows === []) return [];

        $result=[];$seen=[];$totalQuantity=0;
        foreach($rows as $row){
            if(!is_array($row))throw new OrderCommitException('invalid_items','ساختار آیتم معتبر نیست.',422);
            $itemId=filter_var($row['id']??null,FILTER_VALIDATE_INT);
            $quantity=filter_var($row['quantity']??null,FILTER_VALIDATE_INT);
            $mode=(string)($row['fulfillment_mode']??'dine_in')==='takeaway'?'takeaway':'dine_in';$bundleChoice=max(0,(int)($row['bundle_choice_item_id']??0));
            if($itemId===false||$itemId<1||$quantity===false||$quantity<1||$quantity>50)
                throw new OrderCommitException('invalid_items','شناسه یا تعداد آیتم معتبر نیست.',422);
            $expected=$row['expected_price']??($row['unit_price']??null);
            $expected=$expected===null||$expected===''?null:filter_var($expected,FILTER_VALIDATE_INT);
            $key=$itemId.'|'.$mode.'|'.$bundleChoice.'|'.($expected===null?'current':(string)$expected);
            if(isset($seen[$key]))throw new OrderCommitException('duplicate_line','یک آیتم با روش سرو و قیمت یکسان چند بار تکرار شده است.',422);
            $seen[$key]=true;
            if($expected===false||($expected!==null&&$expected<0))throw new OrderCommitException('invalid_price','قیمت مورد انتظار معتبر نیست.',422);
            $result[]=[
                'id'=>(int)$itemId,
                'quantity'=>(int)$quantity,
                'expected_price'=>$expected===null?null:(int)$expected,
                'note'=>self::truncate(trim((string)($row['note']??'')),500),
                'fulfillment_mode'=>$mode,
                'bundle_choice_item_id'=>$bundleChoice,
            ];
            $totalQuantity+=(int)$quantity;
            if($totalQuantity>100)throw new OrderCommitException('too_many_items','تعداد کل سفارش نمی‌تواند بیشتر از ۱۰۰ باشد.',422);
        }
        return $result;
    }

    /** @return list<array<string,mixed>> */
    public function snapshotRowsTx(array $rows, string $source = 'staff'): array
    {
        if(!$this->pdo->inTransaction())throw new \LogicException('Catalog snapshot requires an open transaction.');
        if($rows===[])return [];
        if(!in_array($source,['guest','staff'],true))throw new OrderCommitException('invalid_source','منبع سفارش معتبر نیست.',422);

        $catalog=$this->lockCatalog(array_column($rows,'id'));
        $lines=[];
        foreach($rows as $requested){
            $item=$catalog[$requested['id']]??null;
            if(!$this->isOrderable($item,$source)){
                throw new OrderCommitException('item_unavailable','یکی از آیتم‌های انتخاب‌شده دیگر قابل سفارش نیست.',409,['item_id'=>$requested['id']]);
            }
            if($requested['fulfillment_mode']==='takeaway'&&(int)($item['takeaway_allowed']??1)!==1){
                throw new OrderCommitException('takeaway_not_allowed','این آیتم فقط داخل کافه قابل سرو است.',409,['item_id'=>$requested['id']]);
            }
            $bundle=$this->bundleSelectionTx((int)$item['id'],(int)($requested['bundle_choice_item_id']??0),$source,(int)$requested['quantity']);
            $unit=(int)$item['price']+(int)$bundle['price_delta'];
            if($requested['expected_price']!==null&&$requested['expected_price']!==$unit){
                throw new OrderCommitException('price_changed','قیمت یکی از آیتم‌ها یا انتخاب‌های ترکیب تغییر کرده است.',409,['item_id'=>$requested['id'],'current_price'=>$unit]);
            }
            $lines[]=[
                'item_id'=>(int)$item['id'],
                'category_id'=>(int)$item['category_id'],
                'category_name'=>(string)($item['category_name']??''),
                'item_name'=>(string)$item['name'],
                'sellable_kind'=>SellableKind::normalizeRead($item['sellable_kind']??null),
                'unit_price'=>$unit,
                'quantity'=>(int)$requested['quantity'],
                'item_note'=>$requested['note']!==''?$requested['note']:null,
                'fulfillment_mode'=>$requested['fulfillment_mode'],
                'preparation_station'=>$bundle['is_bundle']?'none':self::normalizeStation((string)($item['preparation_station']??'other')),
                'line_total'=>$unit*(int)$requested['quantity'],
                'bundle_components'=>$bundle['components'],
                'bundle_choice_item_id'=>(int)($requested['bundle_choice_item_id']??0),
                'bundle_choice_label'=>$bundle['choice_label'],
            ];
        }
        return $lines;
    }

    /**
     * Snapshot draft rows while allowing only server-proven saved price segments
     * to retain an older price. A client can never invent or increase an old-price
     * segment: preserved quantities are bounded by the already persisted draft.
     *
     * @param array<string,int> $preservedQuantities key=id|mode|bundle_choice_item_id|unit_price
     * @return list<array<string,mixed>>
     */
    public function snapshotDraftRowsTx(array $rows,array $preservedQuantities): array
    {
        if(!$this->pdo->inTransaction())throw new \LogicException('Catalog snapshot requires an open transaction.');
        if($rows===[])return [];
        $catalog=$this->lockCatalog(array_column($rows,'id'));
        $lines=[];
        foreach($rows as $requested){
            $item=$catalog[$requested['id']]??null;
            if(!$this->isOrderable($item,'staff'))
                throw new OrderCommitException('item_unavailable','یکی از آیتم‌های انتخاب‌شده دیگر قابل سفارش نیست.',409,['item_id'=>$requested['id']]);
            if($requested['fulfillment_mode']==='takeaway'&&(int)($item['takeaway_allowed']??1)!==1)
                throw new OrderCommitException('takeaway_not_allowed','این آیتم فقط داخل کافه قابل سرو است.',409,['item_id'=>$requested['id']]);
            $bundle=$this->bundleSelectionTx((int)$item['id'],(int)($requested['bundle_choice_item_id']??0),'staff',(int)$requested['quantity']);
            $current=(int)$item['price']+(int)$bundle['price_delta'];
            $requestedPrice=$requested['expected_price'];
            $unit=$current;
            if($requestedPrice!==null&&$requestedPrice!==$current){
                $key=(int)$requested['id'].'|'.$requested['fulfillment_mode'].'|'.(int)($requested['bundle_choice_item_id']??0).'|'.(int)$requestedPrice;
                $allowed=max(0,(int)($preservedQuantities[$key]??0));
                if($allowed<(int)$requested['quantity']){
                    throw new OrderCommitException('price_changed','قیمت این آیتم تغییر کرده است؛ مقدار قبلی با قیمت ثبت‌شده حفظ می‌شود و مقدار تازه باید با قیمت جدید اضافه شود.',409,[
                        'item_id'=>(int)$requested['id'],'recorded_price'=>(int)$requestedPrice,'current_price'=>$current,'preserved_quantity'=>$allowed,
                    ]);
                }
                $unit=(int)$requestedPrice;
            }
            $lines[]=[
                'item_id'=>(int)$item['id'],'category_id'=>(int)$item['category_id'],'category_name'=>(string)($item['category_name']??''),
                'item_name'=>(string)$item['name'],'sellable_kind'=>SellableKind::normalizeRead($item['sellable_kind']??null),'unit_price'=>$unit,
                'quantity'=>(int)$requested['quantity'],'item_note'=>$requested['note']!==''?$requested['note']:null,'fulfillment_mode'=>$requested['fulfillment_mode'],
                'preparation_station'=>$bundle['is_bundle']?'none':self::normalizeStation((string)($item['preparation_station']??'other')),'line_total'=>$unit*(int)$requested['quantity'],
                'bundle_components'=>$bundle['components'],'bundle_choice_item_id'=>(int)($requested['bundle_choice_item_id']??0),'bundle_choice_label'=>$bundle['choice_label'],
            ];
        }
        return $lines;
    }

    private function lockCatalog(array $ids): array
    {
        $ids=array_values(array_unique(array_map('intval',$ids)));
        $ph=implode(',',array_fill(0,count($ids),'?'));
        $itemSchedule=self::itemScheduleSql('i');
        $menuSchedule=self::menuScheduleSql('mcat');
        $activeMenuMembership="EXISTS(SELECT 1 FROM menu_items mi_cat
            JOIN menus mcat ON mcat.id=mi_cat.menu_id
            JOIN menu_categories mc_cat ON mc_cat.menu_id=mcat.id AND mc_cat.category_id=i.category_id
            WHERE mi_cat.item_id=i.id AND mcat.status='active')";
        $scheduledMenuMembership="EXISTS(SELECT 1 FROM menu_items mi_cat
            JOIN menus mcat ON mcat.id=mi_cat.menu_id
            JOIN menu_categories mc_cat ON mc_cat.menu_id=mcat.id AND mc_cat.category_id=i.category_id
            WHERE mi_cat.item_id=i.id AND mcat.status='active' AND ($menuSchedule))";
        $stmt=$this->pdo->prepare("SELECT i.id,i.category_id,i.name,i.price,i.preparation_station,i.sellable_kind,i.available,i.active,i.lifecycle_status,i.guest_visible,i.staff_visible,i.staff_only,i.takeaway_allowed,
            COALESCE(c.name,'') category_name,COALESCE(c.active,0) category_active,COALESCE(c.lifecycle_status,'inactive') category_lifecycle_status,COALESCE(c.guest_visible,0) category_guest_visible,COALESCE(c.staff_visible,0) category_staff_visible,COALESCE(c.audience,'guest_staff') category_audience,
            ($itemSchedule) schedule_active,($activeMenuMembership) menu_active,($scheduledMenuMembership) menu_schedule_active
            FROM items i LEFT JOIN categories c ON c.id=i.category_id
            WHERE i.id IN($ph) FOR UPDATE");
        $stmt->execute($ids);$result=[];
        foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row)$result[(int)$row['id']]=$row;
        return $result;
    }

    private function isOrderable(?array $item,string $source): bool
    {
        if($item===null||(string)($item['lifecycle_status']??'inactive')!=='active'||(int)($item['available']??0)!==1||
            (string)($item['category_lifecycle_status']??'inactive')!=='active'||(int)($item['menu_active']??0)!==1)return false;
        if($source==='guest')return (int)($item['guest_visible']??0)===1&&(int)($item['category_guest_visible']??0)===1&&
            (int)($item['schedule_active']??0)===1&&(int)($item['menu_schedule_active']??0)===1;
        return (int)($item['staff_visible']??0)===1&&(int)($item['category_staff_visible']??0)===1;
    }


    public function persistBundleComponentsTx(int $orderItemId,array $line): void
    {
        if(!$this->pdo->inTransaction())throw new \LogicException('Bundle snapshot persistence requires an open transaction.');
        $components=is_array($line['bundle_components']??null)?$line['bundle_components']:[];if($orderItemId<1||$components===[])return;
        $stmt=$this->pdo->prepare('INSERT INTO order_item_bundle_components(order_item_id,component_item_id,item_name_snapshot,quantity,preparation_station_snapshot,component_mode,choice_label_snapshot) VALUES(?,?,?,?,?,?,?)');
        foreach($components as $c){if(!is_array($c))continue;$stmt->execute([$orderItemId,(int)($c['component_item_id']??0)>0?(int)$c['component_item_id']:null,(string)($c['item_name']??'جزء ترکیب'),max(1,(int)($c['quantity_total']??1)),self::normalizeStation((string)($c['preparation_station']??'none')),(string)($c['mode']??'fixed'),$line['bundle_choice_label']??null]);}
    }

    /** @return array{is_bundle:bool,price_delta:int,choice_label:?string,components:list<array<string,mixed>>} */
    private function bundleSelectionTx(int $itemId,int $choiceItemId,string $source,int $parentQuantity): array
    {
        $b=$this->pdo->prepare('SELECT bundle_item_id,choice_label FROM item_bundles WHERE bundle_item_id=? LIMIT 1 FOR UPDATE');$b->execute([$itemId]);$bundle=$b->fetch(PDO::FETCH_ASSOC);if(!is_array($bundle)){if($choiceItemId>0)throw new OrderCommitException('bundle_choice_invalid','این آیتم انتخاب ترکیب ندارد.',422,['item_id'=>$itemId]);return ['is_bundle'=>false,'price_delta'=>0,'choice_label'=>null,'components'=>[]];}
        $q=$this->pdo->prepare("SELECT c.component_item_id,c.mode,c.quantity,c.price_delta,c.sort_order,i.name,i.available,i.lifecycle_status,i.guest_visible,i.staff_visible,i.preparation_station FROM item_bundle_components c JOIN items i ON i.id=c.component_item_id WHERE c.bundle_item_id=? ORDER BY c.sort_order,c.id FOR UPDATE");$q->execute([$itemId]);$rows=$q->fetchAll(PDO::FETCH_ASSOC)?:[];$fixed=[];$choices=[];$choiceConfigured=0;
        foreach($rows as $r){$visible=$source==='guest'?(int)$r['guest_visible']===1:(int)$r['staff_visible']===1;$ok=(string)$r['lifecycle_status']==='active'&&(int)$r['available']===1&&$visible;if((string)$r['mode']==='fixed'){if(!$ok)throw new OrderCommitException('bundle_unavailable','یکی از اجزای ثابت ترکیب ناموجود است.',409,['item_id'=>$itemId,'component_item_id'=>(int)$r['component_item_id']]);$fixed[]=$r;}else{$choiceConfigured++;if($ok)$choices[(int)$r['component_item_id']]=$r;}}
        $selected=null;if($choiceConfigured>0){if($choices===[])throw new OrderCommitException('bundle_unavailable','هیچ انتخاب قابل سفارشی برای این ترکیب باقی نمانده است.',409,['item_id'=>$itemId]);if($choiceItemId<1||!isset($choices[$choiceItemId]))throw new OrderCommitException('bundle_choice_required','یکی از انتخاب‌های موجود ترکیب را انتخاب کن.',409,['item_id'=>$itemId,'available_choice_ids'=>array_keys($choices)]);$selected=$choices[$choiceItemId];}elseif($choiceItemId>0)throw new OrderCommitException('bundle_choice_invalid','این ترکیب انتخاب جداگانه ندارد.',422,['item_id'=>$itemId]);
        $selectedRows=$fixed;if(is_array($selected))$selectedRows[]=$selected;$components=[];$delta=0;foreach($selectedRows as $r){$delta+=(int)$r['price_delta'];$components[]=['component_item_id'=>(int)$r['component_item_id'],'item_name'=>(string)$r['name'],'mode'=>(string)$r['mode'],'quantity_per_bundle'=>(int)$r['quantity'],'quantity_total'=>(int)$r['quantity']*max(1,$parentQuantity),'price_delta'=>(int)$r['price_delta'],'preparation_station'=>self::normalizeStation((string)($r['preparation_station']??'none'))];}
        return ['is_bundle'=>true,'price_delta'=>$delta,'choice_label'=>$selected!==null?(string)($bundle['choice_label']??'انتخاب'):null,'components'=>$components];
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
