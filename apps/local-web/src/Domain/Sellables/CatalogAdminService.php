<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\Sellables;

use PDO;
use PDOException;
use Sokna\Local\Core\IdentityRepository;
use Throwable;

final class CatalogAdminService
{
    private const MENU_STATUSES=['active','draft','inactive'];
    private const AUDIENCES=['guest_staff','staff_only'];
    private const STATIONS=['kitchen','hot_bar','cold_bar','none'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly IdentityRepository $identity,
        private readonly CategoryIconLibrary $icons,
    ){}

    public function snapshot(array $actor): array
    {
        $this->assertAdmin($actor);
        $menus=$this->pdo->query('SELECT id,menu_key,name,status,sort_order,schedule_days,daily_start,daily_end,created_at,updated_at FROM menus ORDER BY sort_order,id')->fetchAll(PDO::FETCH_ASSOC);
        foreach($menus as &$menu){$menu['id']=(int)$menu['id'];$menu['sort_order']=(int)$menu['sort_order'];$menu['schedule_days']=$this->daysFromCsv($menu['schedule_days']??null);}unset($menu);

        $categories=$this->pdo->query('SELECT id,category_key,name,audience,image_path,icon_key,sort_order,active,created_at,updated_at FROM categories ORDER BY sort_order,id')->fetchAll(PDO::FETCH_ASSOC);
        $categoryMenus=$this->pdo->query('SELECT category_id,menu_id,sort_order FROM menu_categories ORDER BY category_id,sort_order,menu_id')->fetchAll(PDO::FETCH_ASSOC);
        $categoryMenuMap=[];foreach($categoryMenus as $row)$categoryMenuMap[(int)$row['category_id']][]=(int)$row['menu_id'];
        foreach($categories as &$category){$category['id']=(int)$category['id'];$category['sort_order']=(int)$category['sort_order'];$category['active']=(int)$category['active']===1;$category['menu_ids']=$categoryMenuMap[$category['id']]??[];}unset($category);

        $items=$this->pdo->query('SELECT i.*,c.name category_name,c.audience category_audience FROM items i JOIN categories c ON c.id=i.category_id ORDER BY c.sort_order,c.id,i.sort_order,i.id')->fetchAll(PDO::FETCH_ASSOC);
        $itemMenus=$this->pdo->query('SELECT item_id,menu_id FROM menu_items ORDER BY item_id,menu_id')->fetchAll(PDO::FETCH_ASSOC);
        $itemMenuMap=[];foreach($itemMenus as $row)$itemMenuMap[(int)$row['item_id']][]=(int)$row['menu_id'];
        foreach($items as &$item){
            $item['id']=(int)$item['id'];$item['category_id']=(int)$item['category_id'];$item['price']=(int)$item['price'];$item['sort_order']=(int)$item['sort_order'];
            foreach(['available','active','featured','staff_only','takeaway_allowed'] as $flag)$item[$flag]=(int)$item[$flag]===1;
            $item['sellable_kind']=SellableKind::normalizeRead($item['sellable_kind']??null);
            $item['sellable_kind_label']=SellableKind::label($item['sellable_kind']);
            $item['preparation_station']=$this->normalizeStation((string)($item['preparation_station']??'cold_bar'));
            $item['schedule_days']=$this->daysFromCsv($item['schedule_days']??null);
            $item['menu_ids']=$itemMenuMap[$item['id']]??[];
        }unset($item);

        return [
            'menus'=>$menus,
            'categories'=>$categories,
            'items'=>$items,
            'definitions'=>[
                'menu_statuses'=>['active'=>'فعال','draft'=>'پیش‌نویس','inactive'=>'غیرفعال'],
                'audiences'=>['guest_staff'=>'مهمان و کارکنان','staff_only'=>'فقط کارکنان'],
                'sellable_kinds'=>SellableKind::labels(),
                'stations'=>['kitchen'=>'آشپزخانه','hot_bar'=>'بار گرم','cold_bar'=>'بار سرد','none'=>'بدون آماده‌سازی'],
                'days'=>[7=>'شنبه',1=>'یکشنبه',2=>'دوشنبه',3=>'سه‌شنبه',4=>'چهارشنبه',5=>'پنجشنبه',6=>'جمعه'],
                'category_icons'=>$this->icons->groups(),
            ],
            'notes'=>[
                'media'=>'تصویر آیتم از Media Library مدیریت می‌شود؛ آیکن دسته از کتابخانه داخلی امن انتخاب می‌شود.',
                'history'=>'Order snapshots مستقل‌اند؛ تغییر نام/قیمت/نوع آیتم، سفارش‌های گذشته را بازنویسی نمی‌کند.',
            ],
        ];
    }

    public function saveMenu(array $data,array $actor): array
    {
        $admin=$this->assertAdmin($actor);$id=(int)($data['id']??0);$name=$this->text($data['name']??'',120);$status=trim((string)($data['status']??'draft'));$sort=(int)($data['sort_order']??0);
        if($name==='')throw new CatalogAdminException('menu_name','نام منو لازم است.',422);
        if(!in_array($status,self::MENU_STATUSES,true))throw new CatalogAdminException('menu_status','وضعیت منو معتبر نیست.',422);
        [$days,$start,$end]=$this->schedule($data['schedule_days']??[],$data['daily_start']??null,$data['daily_end']??null);
        $this->pdo->beginTransaction();try{
            if($id>0){$q=$this->pdo->prepare('SELECT id,menu_key,name,status FROM menus WHERE id=? FOR UPDATE');$q->execute([$id]);$before=$q->fetch(PDO::FETCH_ASSOC);if(!is_array($before))throw new CatalogAdminException('menu_missing','منو پیدا نشد.',404);$q=$this->pdo->prepare('UPDATE menus SET name=?,status=?,sort_order=?,schedule_days=?,daily_start=?,daily_end=? WHERE id=?');$q->execute([$name,$status,$sort,$days,$start,$end,$id]);$created=false;}
            else{$key=$this->newKey('menus','menu_key');$q=$this->pdo->prepare('INSERT INTO menus(menu_key,name,status,sort_order,schedule_days,daily_start,daily_end) VALUES(?,?,?,?,?,?,?)');$q->execute([$key,$name,$status,$sort,$days,$start,$end]);$id=(int)$this->pdo->lastInsertId();$created=true;}
            $this->audit($created?'catalog.menu_created':'catalog.menu_updated','menu',$id,(int)$admin['id'],['name'=>$name,'status'=>$status,'sort_order'=>$sort,'schedule_days'=>$days,'daily_start'=>$start,'daily_end'=>$end]);
            $this->pdo->commit();return ['id'=>$id];
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function saveCategory(array $data,array $actor): array
    {
        $admin=$this->assertAdmin($actor);$id=(int)($data['id']??0);$name=$this->text($data['name']??'',120);$audience=trim((string)($data['audience']??'guest_staff'));$sort=(int)($data['sort_order']??0);$active=$this->bool($data['active']??true);$icon=$this->nullableText($data['icon_key']??null,40);$menuIds=$this->intList($data['menu_ids']??[]);
        if($name==='')throw new CatalogAdminException('category_name','نام دسته‌بندی لازم است.',422);
        if(!in_array($audience,self::AUDIENCES,true))throw new CatalogAdminException('category_audience','مخاطب دسته‌بندی معتبر نیست.',422);
        if($icon!==null&&!$this->icons->isAllowed($icon))throw new CatalogAdminException('category_icon','آیکن دسته‌بندی معتبر نیست.',422);
        $this->pdo->beginTransaction();try{
            $this->lockMenus($menuIds);
            if($id>0){$q=$this->pdo->prepare('SELECT id,category_key,name FROM categories WHERE id=? FOR UPDATE');$q->execute([$id]);if(!is_array($q->fetch(PDO::FETCH_ASSOC)))throw new CatalogAdminException('category_missing','دسته‌بندی پیدا نشد.',404);$q=$this->pdo->prepare('UPDATE categories SET name=?,audience=?,icon_key=?,sort_order=?,active=? WHERE id=?');$q->execute([$name,$audience,$icon,$sort,$active?1:0,$id]);$created=false;}
            else{$key=$this->newKey('categories','category_key');$q=$this->pdo->prepare('INSERT INTO categories(category_key,name,audience,icon_key,sort_order,active) VALUES(?,?,?,?,?,?)');$q->execute([$key,$name,$audience,$icon,$sort,$active?1:0]);$id=(int)$this->pdo->lastInsertId();$created=true;}
            $this->setCategoryMenusTx($id,$menuIds);
            $this->audit($created?'catalog.category_created':'catalog.category_updated','category',$id,(int)$admin['id'],['name'=>$name,'audience'=>$audience,'active'=>$active,'sort_order'=>$sort,'menu_ids'=>$menuIds]);
            $this->pdo->commit();return ['id'=>$id];
        }catch(PDOException $e){if($this->pdo->inTransaction())$this->pdo->rollBack();if((int)($e->errorInfo[1]??0)===1062)throw new CatalogAdminException('category_duplicate','شناسه دسته‌بندی تکراری شد؛ دوباره تلاش کن.',409);throw $e;}catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function saveItem(array $data,array $actor): array
    {
        $admin=$this->assertAdmin($actor);$id=(int)($data['id']??0);$name=$this->text($data['name']??'',160);$description=$this->nullableText($data['description']??null,2000);$price=$this->amount($data['price']??null);$categoryId=(int)($data['category_id']??0);$kind=SellableKind::requireWrite($data['sellable_kind']??SellableKind::MENU_ITEM);$station=$this->normalizeStation((string)($data['preparation_station']??'cold_bar'));$sort=(int)($data['sort_order']??0);$available=$this->bool($data['available']??true);$active=$this->bool($data['active']??true);$featured=$this->bool($data['featured']??false);$staffOnly=$this->bool($data['staff_only']??false);$takeaway=$this->bool($data['takeaway_allowed']??true);$menuIds=$this->intList($data['menu_ids']??[]);
        [$days,$dailyStart,$dailyEnd]=$this->schedule($data['schedule_days']??[],$data['daily_start']??null,$data['daily_end']??null);$scheduleStart=$this->dateTime($data['schedule_start']??null);$scheduleEnd=$this->dateTime($data['schedule_end']??null);if($scheduleStart!==null&&$scheduleEnd!==null&&$scheduleStart>$scheduleEnd)throw new CatalogAdminException('item_schedule_range','پایان بازه آیتم باید بعد از شروع باشد.',422);
        if($name===''||$categoryId<1)throw new CatalogAdminException('item_required','نام و دسته‌بندی آیتم لازم است.',422);
        $this->pdo->beginTransaction();try{
            $q=$this->pdo->prepare('SELECT id FROM categories WHERE id=? FOR UPDATE');$q->execute([$categoryId]);if(!$q->fetchColumn())throw new CatalogAdminException('category_missing','دسته‌بندی انتخاب‌شده پیدا نشد.',422);
            $allowed=$this->categoryMenuIdsTx($categoryId);if(array_diff($menuIds,$allowed))throw new CatalogAdminException('item_menu_boundary','یکی از منوهای انتخاب‌شده برای این دسته‌بندی مجاز نیست.',422,['allowed_menu_ids'=>$allowed]);
            if($id>0){$q=$this->pdo->prepare('SELECT id,item_code,image_path FROM items WHERE id=? FOR UPDATE');$q->execute([$id]);$before=$q->fetch(PDO::FETCH_ASSOC);if(!is_array($before))throw new CatalogAdminException('item_missing','آیتم پیدا نشد.',404);$q=$this->pdo->prepare('UPDATE items SET category_id=?,name=?,description=?,price=?,available=?,active=?,featured=?,staff_only=?,sellable_kind=?,takeaway_allowed=?,preparation_station=?,schedule_start=?,schedule_end=?,schedule_days=?,daily_start=?,daily_end=?,sort_order=? WHERE id=?');$q->execute([$categoryId,$name,$description,$price,$available?1:0,$active?1:0,$featured?1:0,$staffOnly?1:0,$kind,$takeaway?1:0,$station,$scheduleStart,$scheduleEnd,$days,$dailyStart,$dailyEnd,$sort,$id]);$created=false;}
            else{$q=$this->pdo->prepare('INSERT INTO items(item_code,category_id,name,description,price,available,active,featured,staff_only,sellable_kind,takeaway_allowed,preparation_station,schedule_start,schedule_end,schedule_days,daily_start,daily_end,sort_order) VALUES(NULL,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');$q->execute([$categoryId,$name,$description,$price,$available?1:0,$active?1:0,$featured?1:0,$staffOnly?1:0,$kind,$takeaway?1:0,$station,$scheduleStart,$scheduleEnd,$days,$dailyStart,$dailyEnd,$sort]);$id=(int)$this->pdo->lastInsertId();$this->pdo->prepare('UPDATE items SET item_code=? WHERE id=?')->execute(['ITEM-'.$id,$id]);$created=true;}
            $this->setItemMenusTx($id,$menuIds);
            $this->audit($created?'catalog.item_created':'catalog.item_updated','menu_item',$id,(int)$admin['id'],['name'=>$name,'category_id'=>$categoryId,'price'=>$price,'sellable_kind'=>$kind,'available'=>$available,'active'=>$active,'featured'=>$featured,'staff_only'=>$staffOnly,'takeaway_allowed'=>$takeaway,'preparation_station'=>$station,'menu_ids'=>$menuIds]);
            $this->pdo->commit();return ['id'=>$id];
        }catch(PDOException $e){if($this->pdo->inTransaction())$this->pdo->rollBack();if((int)($e->errorInfo[1]??0)===1062)throw new CatalogAdminException('item_duplicate','شناسه داخلی آیتم تکراری شد؛ دوباره تلاش کن.',409);throw $e;}catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function setItemState(int $id,string $field,bool $enabled,array $actor): array
    {
        $admin=$this->assertAdmin($actor);if($id<1||!in_array($field,['available','active'],true))throw new CatalogAdminException('item_state','آیتم یا وضعیت معتبر نیست.',422);
        $this->pdo->beginTransaction();try{$q=$this->pdo->prepare('SELECT id,name,available,active FROM items WHERE id=? FOR UPDATE');$q->execute([$id]);$row=$q->fetch(PDO::FETCH_ASSOC);if(!is_array($row))throw new CatalogAdminException('item_missing','آیتم پیدا نشد.',404);$before=(int)$row[$field]===1;if($before!==$enabled){$sql='UPDATE items SET '.$field.'=? WHERE id=?';$this->pdo->prepare($sql)->execute([$enabled?1:0,$id]);$this->audit('catalog.item_'.$field.'_changed','menu_item',$id,(int)$admin['id'],['name'=>(string)$row['name'],'before'=>$before,'after'=>$enabled]);}$this->pdo->commit();return ['id'=>$id,'field'=>$field,'enabled'=>$enabled,'changed'=>$before!==$enabled];}catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    private function assertAdmin(array $user): array{$id=(int)($user['id']??0);if($id<1)throw new CatalogAdminException('forbidden','حساب معتبر نیست.',403);$fresh=$this->identity->findActiveById($id);if($fresh===null||(string)($fresh['role']??'')!=='admin')throw new CatalogAdminException('forbidden','مدیریت کاتالوگ فقط برای مدیر فعال است.',403);return $fresh;}
    private function lockMenus(array $ids): void{if($ids===[])return;$ph=implode(',',array_fill(0,count($ids),'?'));$q=$this->pdo->prepare("SELECT id FROM menus WHERE id IN($ph) FOR UPDATE");$q->execute($ids);$found=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));sort($found);$expected=$ids;sort($expected);if($found!==$expected)throw new CatalogAdminException('menu_missing','یکی از منوهای انتخاب‌شده پیدا نشد.',422);}
    private function setCategoryMenusTx(int $categoryId,array $menuIds): void{$this->lockMenus($menuIds);$this->pdo->prepare('DELETE FROM menu_categories WHERE category_id=?')->execute([$categoryId]);if($menuIds!==[]){$ins=$this->pdo->prepare('INSERT INTO menu_categories(menu_id,category_id,sort_order) VALUES(?,?,?)');foreach($menuIds as $index=>$menuId)$ins->execute([$menuId,$categoryId,($index+1)*10]);}$allowed=$menuIds;$q=$this->pdo->prepare('SELECT id FROM items WHERE category_id=?');$q->execute([$categoryId]);foreach(array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN)) as $itemId){$current=$this->itemMenuIdsTx($itemId);$keep=array_values(array_intersect($current,$allowed));$this->setItemMenusTx($itemId,$keep);}}
    private function setItemMenusTx(int $itemId,array $menuIds): void{$this->pdo->prepare('DELETE FROM menu_items WHERE item_id=?')->execute([$itemId]);if($menuIds!==[]){$ins=$this->pdo->prepare('INSERT INTO menu_items(menu_id,item_id) VALUES(?,?)');foreach($menuIds as $menuId)$ins->execute([$menuId,$itemId]);}}
    private function categoryMenuIdsTx(int $categoryId): array{$q=$this->pdo->prepare('SELECT menu_id FROM menu_categories WHERE category_id=? ORDER BY menu_id FOR UPDATE');$q->execute([$categoryId]);return array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));}
    private function itemMenuIdsTx(int $itemId): array{$q=$this->pdo->prepare('SELECT menu_id FROM menu_items WHERE item_id=? ORDER BY menu_id FOR UPDATE');$q->execute([$itemId]);return array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));}
    private function newKey(string $table,string $column): string{$q=$this->pdo->prepare("SELECT 1 FROM $table WHERE $column=? LIMIT 1");for($i=0;$i<5;$i++){$key='custom-'.bin2hex(random_bytes(10));$q->execute([$key]);if(!$q->fetchColumn())return $key;}throw new CatalogAdminException('key_generation','شناسه داخلی ساخته نشد؛ دوباره تلاش کن.',500);}
    private function schedule(mixed $days,mixed $start,mixed $end): array{$dayList=$this->intList($days,1,7);sort($dayList);$start=$this->time($start);$end=$this->time($end);if(($start===null)!==($end===null))throw new CatalogAdminException('schedule_pair','شروع و پایان ساعت سرو را با هم وارد کن.',422);return [$dayList?implode(',',$dayList):null,$start,$end];}
    private function daysFromCsv(mixed $value): array{if($value===null||trim((string)$value)==='')return [];return $this->intList(explode(',',(string)$value),1,7);}
    private function time(mixed $value): ?string{$v=trim((string)$value);if($v==='')return null;if(!preg_match('/^(?:[01]\\d|2[0-3]):[0-5]\\d(?::[0-5]\\d)?$/',$v))throw new CatalogAdminException('time_invalid','ساعت واردشده معتبر نیست.',422);return substr($v,0,5);}
    private function dateTime(mixed $value): ?string{$v=trim((string)$value);if($v==='')return null;$v=str_replace('T',' ',$v);if(!preg_match('/^\\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}(?::\\d{2})?$/',$v))throw new CatalogAdminException('datetime_invalid','تاریخ/ساعت واردشده معتبر نیست.',422);return strlen($v)===16?$v.':00':$v;}
    private function normalizeStation(string $station): string{if($station==='other')$station='cold_bar';if(!in_array($station,self::STATIONS,true))throw new CatalogAdminException('station_invalid','ایستگاه آماده‌سازی معتبر نیست.',422);return $station;}
    private function amount(mixed $value): int{$raw=trim(strtr((string)$value,['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']));if($raw===''||str_starts_with($raw,'-'))throw new CatalogAdminException('price_invalid','قیمت معتبر لازم است.',422);$s=preg_replace('/[^0-9]/','',$raw)??'';if($s==='')throw new CatalogAdminException('price_invalid','قیمت معتبر لازم است.',422);return (int)$s;}
    private function bool(mixed $value): bool{return is_bool($value)?$value:in_array(strtolower(trim((string)$value)),['1','true','yes','on'],true);}
    private function text(mixed $value,int $max): string{$v=trim((string)$value);if(function_exists('mb_substr'))return mb_substr($v,0,$max,'UTF-8');$ok=preg_match_all('/./us',$v,$m);return $ok===false?substr($v,0,$max):implode('',array_slice($m[0],0,$max));}
    private function nullableText(mixed $value,int $max): ?string{$v=$this->text($value,$max);return $v===''?null:$v;}
    private function intList(mixed $value,int $min=1,int $max=PHP_INT_MAX): array{$items=is_array($value)?$value:[];$result=[];foreach($items as $item){$n=filter_var($item,FILTER_VALIDATE_INT);if($n!==false&&$n>=$min&&$n<=$max)$result[]=(int)$n;}return array_values(array_unique($result));}
    private function audit(string $action,string $entity,int $entityId,int $actorId,array $details): void{$q=$this->pdo->prepare('SELECT display_name FROM users WHERE id=? LIMIT 1');$q->execute([$actorId]);$name=$q->fetchColumn();$this->pdo->prepare('INSERT INTO audit_log(actor_user_id,actor_display_name_snapshot,action,entity_type,entity_id,details_json) VALUES(?,?,?,?,?,?)')->execute([$actorId,$name!==false?$name:null,$action,$entity,(string)$entityId,json_encode($details,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);}
}
