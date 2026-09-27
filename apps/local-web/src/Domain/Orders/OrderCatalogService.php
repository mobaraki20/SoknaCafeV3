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
        $itemSchedule=self::itemScheduleSql('i');
        $menuSchedule=self::menuScheduleSql('mcat');
        $menuMembership="EXISTS(SELECT 1 FROM menu_items mi_cat
            JOIN menus mcat ON mcat.id=mi_cat.menu_id
            JOIN menu_categories mc_cat ON mc_cat.menu_id=mcat.id AND mc_cat.category_id=i.category_id
            WHERE mi_cat.item_id=i.id AND mcat.status='active' AND ($menuSchedule))";
        $rows=$this->pdo->query("SELECT i.id,i.name,i.price,i.preparation_station,i.sellable_kind,i.takeaway_allowed,
            c.id category_id,c.name category_name,c.sort_order category_sort,i.sort_order
            FROM items i JOIN categories c ON c.id=i.category_id
            WHERE i.active=1 AND i.available=1 AND c.active=1 AND ($itemSchedule) AND ($menuMembership)
            ORDER BY c.sort_order,c.id,i.sort_order,i.id")->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static fn(array $row):array=>[
            'id'=>(int)$row['id'],'name'=>(string)$row['name'],'price'=>(int)$row['price'],
            'preparation_station'=>(string)($row['preparation_station']??'other'),'sellable_kind'=>SellableKind::normalizeRead($row['sellable_kind']??null),
            'takeaway_allowed'=>(int)($row['takeaway_allowed']??1)===1,'category_id'=>(int)$row['category_id'],'category_name'=>(string)$row['category_name'],
        ],$rows);
    }

    /** @return list<array{id:int,quantity:int,expected_price:?int,note:string,fulfillment_mode:string}> */
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
            $mode=(string)($row['fulfillment_mode']??'dine_in')==='takeaway'?'takeaway':'dine_in';
            if($itemId===false||$itemId<1||$quantity===false||$quantity<1||$quantity>50)
                throw new OrderCommitException('invalid_items','شناسه یا تعداد آیتم معتبر نیست.',422);
            $key=$itemId.'|'.$mode;
            if(isset($seen[$key]))throw new OrderCommitException('duplicate_line','یک آیتم با روش سرو یکسان چند بار تکرار شده است.',422);
            $seen[$key]=true;
            $expected=$row['expected_price']??($row['unit_price']??null);
            $expected=$expected===null||$expected===''?null:filter_var($expected,FILTER_VALIDATE_INT);
            if($expected===false||($expected!==null&&$expected<0))throw new OrderCommitException('invalid_price','قیمت مورد انتظار معتبر نیست.',422);
            $result[]=[
                'id'=>(int)$itemId,
                'quantity'=>(int)$quantity,
                'expected_price'=>$expected===null?null:(int)$expected,
                'note'=>self::truncate(trim((string)($row['note']??'')),500),
                'fulfillment_mode'=>$mode,
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
            if($requested['expected_price']!==null&&$requested['expected_price']!==(int)$item['price']){
                throw new OrderCommitException('price_changed','قیمت یکی از آیتم‌ها تغییر کرده است.',409,['item_id'=>$requested['id']]);
            }
            $unit=(int)$item['price'];
            $lines[]=[
                'item_id'=>(int)$item['id'],
                'item_name'=>(string)$item['name'],
                'sellable_kind'=>SellableKind::normalizeRead($item['sellable_kind']??null),
                'unit_price'=>$unit,
                'quantity'=>(int)$requested['quantity'],
                'item_note'=>$requested['note']!==''?$requested['note']:null,
                'fulfillment_mode'=>$requested['fulfillment_mode'],
                'preparation_station'=>self::normalizeStation((string)($item['preparation_station']??'other')),
                'line_total'=>$unit*(int)$requested['quantity'],
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
