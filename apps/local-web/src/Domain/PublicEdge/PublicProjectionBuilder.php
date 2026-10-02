<?php
declare(strict_types=1);
namespace Sokna\Local\Domain\PublicEdge;
use PDO;
use Throwable;
use Sokna\Local\Domain\Finance\SettlementService;
use Sokna\Local\Domain\Supply\SupplyService;
use Sokna\Local\Domain\GuestContent\GuestContentService;
use Sokna\Local\Domain\Marketing\MarketingService;
use Sokna\Local\Domain\Reporting\ReportingService;
use Sokna\Local\Domain\Notifications\NotificationService;
use Sokna\Local\Domain\Sellables\CategoryIconLibrary;
use Sokna\Local\Domain\Orders\OrderCatalogService;
final class PublicProjectionBuilder
{
    public function __construct(private readonly PDO $pdo,private readonly ?SettlementService $settlements=null,private readonly ?SupplyService $supply=null,private readonly ?GuestContentService $guestContent=null,private readonly ?MarketingService $marketing=null,private readonly ?ReportingService $reporting=null,private readonly ?NotificationService $notifications=null,private readonly ?CategoryIconLibrary $categoryIcons=null,private readonly ?OrderCatalogService $orderCatalog=null){}
    public function installation(string $installationId): array{return ['installation_id'=>$installationId,'display_name'=>$this->setting('cafe.name','SOKNA'),'remote_enabled'=>true,'order_intake_enabled'=>$this->settingBool('orders_accepting.cafe',true)];}
    public function authProjections(): array
    {
        $rows=$this->pdo->query('SELECT u.id,u.username,u.display_name,u.role,u.active,u.updated_at,rc.password_hash remote_password_hash,rc.credential_version,rc.updated_at remote_credential_updated_at FROM users u LEFT JOIN remote_user_credentials rc ON rc.user_id=u.id WHERE u.active=1 ORDER BY u.id')->fetchAll(PDO::FETCH_ASSOC);$out=[];
        foreach($rows as $u){$id=(int)$u['id'];$remotePasswordHash=trim((string)($u['remote_password_hash']??''));if($remotePasswordHash==='')continue;$caps=$this->caps($id);$admin=(string)$u['role']==='admin';if(!$admin&&!in_array('remote_access',$caps,true))continue;$remote=[];if($admin)$remote=['*'];else{foreach(['remote_operations'=>'operations.read','remote_preparation'=>'preparation.read','remote_inventory'=>'inventory.read','remote_inventory_cost'=>'inventory.cost.read','remote_reports'=>'reports.read','remote_notifications'=>'notifications.read','remote_deferred_context'=>'deferred.context','remote_settlement'=>'finance.settle','remote_supply'=>'supply.need.defer','remote_subscriber_payments'=>'subscriber.payment.defer'] as $local=>$public)if(in_array($local,$caps,true))$remote[]=$public;if(in_array('remote_table_drafts',$caps,true)&&in_array('orders_floor',$caps,true))$remote[]='orders.table_draft';if(in_array('remote_order_actions',$caps,true)&&in_array('orders_floor',$caps,true)){$remote[]='orders.mutate';$remote[]='operations.read';}if(in_array('remote_preparation_actions',$caps,true)&&in_array('preparation',$caps,true)){$remote[]='preparation.mutate';$remote[]='preparation.read';}if(in_array('remote_supply',$caps,true)){$remote[]='supply.manage.defer';$remote[]='deferred.context';}if(in_array('remote_subscriber_payments',$caps,true))$remote[]='deferred.context';if(in_array('remote_settlement',$caps,true))$remote[]='operations.read';$remote=array_values(array_unique($remote));}
            $areas=$this->areas($id);$projectionVersion=max(1,(int)(strtotime((string)$u['updated_at'])?:1),(int)(strtotime((string)($u['remote_credential_updated_at']??''))?:1));$out[]=['projection_id'=>'user:'.$id,'username'=>(string)$u['username'],'display_name'=>(string)$u['display_name'],'role'=>(string)$u['role'],'password_hash'=>$remotePasswordHash,'capabilities'=>$remote,'preparation_areas'=>$areas,'projection_version'=>$projectionVersion,'active'=>true];}
        return $out;
    }
    public function guestPublish(): array
    {
        $menus=$this->pdo->query("SELECT id,menu_key,name,sort_order FROM menus WHERE status='active' ORDER BY sort_order,id")->fetchAll(PDO::FETCH_ASSOC);
        $menuList=[];$catalogs=[];$manifest=[];
        foreach($menus as $m){
            $menu=['menu_key'=>(string)$m['menu_key'],'name'=>(string)$m['name'],'sort_order'=>(int)$m['sort_order']];
            $menuList[]=$menu;
            $q=$this->pdo->prepare("SELECT DISTINCT c.id,c.name,c.image_path,c.icon_key,mc.sort_order FROM categories c JOIN menu_categories mc ON mc.category_id=c.id WHERE mc.menu_id=? AND c.active=1 AND c.audience<>'staff_only' ORDER BY mc.sort_order,c.id");
            $q->execute([(int)$m['id']]);
            $cats=[];
            foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){
                $source='';
                if($this->guestContent!==null){$resolved=$this->guestContent->publicMediaForSource((string)($r['image_path']??''));if(is_array($resolved)){$source=(string)$resolved['source'];$manifest[$source]=(array)$resolved['manifest'];}}
                $cats[]=['id'=>(int)$r['id'],'name'=>(string)$r['name'],'sort_order'=>(int)$r['sort_order'],'icon_key'=>$this->categoryIcons?->resolve((string)($r['icon_key']??''),(string)$r['name'])??'list','image_path'=>$source];
            }
            $iq=$this->pdo->prepare("SELECT i.id,i.category_id,c.name category_name,i.name,i.description,i.price,i.available,i.featured,i.takeaway_allowed,i.preparation_station,i.suggested_item_id,i.image_path,i.sort_order FROM items i JOIN categories c ON c.id=i.category_id JOIN menu_items mi ON mi.item_id=i.id WHERE mi.menu_id=? AND i.active=1 AND i.staff_only=0 AND i.sellable_kind='menu_item' ORDER BY c.sort_order,i.sort_order,i.id");
            $iq->execute([(int)$m['id']]);
            $items=[];
            foreach($iq->fetchAll(PDO::FETCH_ASSOC) as $r){
                $source='';
                if($this->guestContent!==null){
                    $resolved=$this->guestContent->publicMediaForSource((string)($r['image_path']??''));
                    if(is_array($resolved)){
                        $source=(string)$resolved['source'];
                        $manifest[$source]=(array)$resolved['manifest'];
                    }
                }
                $items[]=[
                    'id'=>(int)$r['id'],'category_id'=>(int)$r['category_id'],'category_name'=>(string)$r['category_name'],
                    'name'=>(string)$r['name'],'description'=>(string)($r['description']??''),'price'=>(int)$r['price'],
                    'available'=>(bool)$r['available'],'featured'=>(bool)($r['featured']??false),
                    'takeaway_allowed'=>(bool)($r['takeaway_allowed']??true),'preparation_station'=>(string)($r['preparation_station']??'other'),
                    'suggested_item_id'=>$r['suggested_item_id']!==null?(int)$r['suggested_item_id']:null,'image_path'=>$source,
                ];
            }
            $catalogs[(string)$m['menu_key']]=['menu'=>$menu,'categories'=>$cats,'items'=>$items];
        }
        $tables=array_map(fn($r)=>['id'=>(int)$r['id'],'name'=>(string)$r['name'],'code'=>(string)$r['code'],'token'=>(string)$r['access_token']],$this->pdo->query('SELECT id,name,code,access_token FROM cafe_tables WHERE active=1 ORDER BY sort_order,id')->fetchAll(PDO::FETCH_ASSOC));
        $snapshot=['format'=>'sokna-guest-snapshot-v1','cafe_name'=>$this->setting('cafe.name','SOKNA'),'features'=>['table_sessions_enabled'=>true],'tables'=>$tables,'menus'=>$menuList,'catalogs'=>$catalogs];
        if($this->guestContent!==null)$snapshot['presentation']=$this->guestContent->publishedPresentation();
        if($this->marketing!==null)$snapshot['marketing']=$this->marketing->publicFeed();
        if($menuList!==[]){$first=(string)$menuList[0]['menu_key'];$snapshot['categories']=$catalogs[$first]['categories'];$snapshot['items']=$catalogs[$first]['items'];}
        ksort($manifest,SORT_STRING);
        $hash=self::hash(['format'=>'sokna-guest-snapshot-v1','snapshot'=>$snapshot,'media_manifest'=>$manifest]);
        return ['revision_id'=>'guest-'.substr($hash,0,32),'content_hash'=>$hash,'generated_at'=>gmdate('c'),'snapshot'=>$snapshot,'media_manifest'=>$manifest];
    }

    public function guestMediaPayloads(array $manifest): array
    {
        return $this->guestContent?->mediaPayloads($manifest) ?? [];
    }
    public function availability(): array
    {
        $items=[];foreach($this->pdo->query("SELECT id,available FROM items WHERE active=1 AND staff_only=0 AND sellable_kind='menu_item'")->fetchAll(PDO::FETCH_ASSOC) as $r)$items[(string)$r['id']]=['available'=>(bool)$r['available']];
        $tables=[];$q=$this->pdo->query("SELECT t.id,s.public_token,s.started_at,s.status FROM cafe_tables t LEFT JOIN table_sessions s ON s.table_id=t.id AND s.status='active' WHERE t.active=1 ORDER BY t.id");foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r)$tables[(string)$r['id']]=['session'=>!empty($r['public_token'])?['token'=>(string)$r['public_token'],'started_at'=>gmdate('c',strtotime((string)$r['started_at'])),'status'=>(string)$r['status']]:null];
        $core=['generated_at'=>gmdate('c'),'items'=>$items,'order_acceptance'=>['cafe'=>$this->settingBool('orders_accepting.cafe',true),'kitchen'=>$this->settingBool('orders_accepting.kitchen',true),'bar'=>$this->settingBool('orders_accepting.bar',true)],'waiter_enabled_table'=>$this->settingBool('waiter_call_enabled',true),'waiter_enabled_public'=>$this->settingBool('public_waiter_call_enabled',false),'tables'=>$tables];return ['version'=>self::hash($core)]+$core;
    }
    public function remoteModels(): array
    {
        $now=gmdate('c');$models=[];
        $models[]=$this->model('operations',['orders'=>$this->rows("SELECT o.id,o.public_code,o.status,o.total_amount,o.order_source,o.created_at,t.name table_name FROM orders o LEFT JOIN cafe_tables t ON t.id=o.table_id WHERE o.status NOT IN ('cancelled','settled') ORDER BY o.id DESC LIMIT 100"),'waiter_calls'=>$this->rows("SELECT w.id,w.public_code,w.status,w.created_at,t.name table_name FROM waiter_calls w JOIN cafe_tables t ON t.id=w.table_id WHERE w.status IN ('new','accepted') ORDER BY w.id DESC LIMIT 50"),'settlement_accounts'=>$this->settlementAccounts()],$now);
        $models[]=$this->model('preparation',['tasks'=>$this->rows("SELECT o.id order_id,oi.id order_item_id,o.public_code order_code,o.business_order_number order_number,o.status,oi.item_name,oi.quantity,CASE WHEN oi.preparation_station='kitchen' THEN 'kitchen' WHEN oi.preparation_station='none' THEN NULL ELSE 'bar' END area,t.name table_name FROM order_items oi JOIN orders o ON o.id=oi.order_id LEFT JOIN cafe_tables t ON t.id=o.table_id WHERE o.status IN ('accounted','completed') AND oi.quantity>0 AND oi.preparation_station<>'none' ORDER BY o.created_at,o.id,oi.id LIMIT 150"),'adjustments'=>[]],$now);
        $models[]=$this->model('inventory',['items'=>$this->rows("SELECT ii.id,ii.item_code,ii.name,ii.base_unit,ii.default_department,COALESCE(b.quantity_base,0) quantity_base,b.cost_status,b.updated_at FROM inventory_items ii LEFT JOIN inventory_balances b ON b.inventory_item_id=ii.id WHERE ii.active=1 ORDER BY ii.name LIMIT 500")],$now);
        $models[]=$this->model('inventory_cost',['items'=>$this->rows("SELECT ii.id,ii.item_code,ii.name,ii.base_unit,COALESCE(b.quantity_base,0) quantity_base,b.average_unit_cost,b.cost_status,b.updated_at FROM inventory_items ii LEFT JOIN inventory_balances b ON b.inventory_item_id=ii.id WHERE ii.active=1 ORDER BY ii.name LIMIT 500")],$now);
        $models[]=$this->model('reports',$this->reporting?->remoteSummary()??['today'=>['order_count'=>(int)$this->scalar("SELECT COUNT(*) FROM orders WHERE business_date=CURDATE()"),'sales_total'=>(int)$this->scalar("SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE business_date=CURDATE() AND status<>'cancelled'")]],$now);
        $models[]=$this->model('notifications',['items'=>$this->notifications?->remoteRows()??[]],$now);
        $models[]=$this->model('deferred_context',['inventory_items'=>$this->rows("SELECT id,item_code,name,base_unit,default_department FROM inventory_items WHERE active=1 ORDER BY name LIMIT 500"),'count_drafts'=>$this->rows("SELECT id,status,created_at FROM inventory_count_sessions WHERE status='draft' ORDER BY id DESC LIMIT 50"),'subscribers'=>$this->rows("SELECT s.id,s.name,s.active,COALESCE((SELECT l.balance_after FROM subscriber_ledger l WHERE l.subscriber_id=s.id ORDER BY l.id DESC LIMIT 1),0) balance FROM subscribers s WHERE s.active=1 ORDER BY s.name LIMIT 300"),'expense_categories'=>$this->rows("SELECT category_key,name FROM expense_categories WHERE active=1 ORDER BY sort_order,category_key"),'supply_groups'=>$this->supply?->purchaseGroups()??[]],$now);
        $models[]=$this->model('table_draft_context',['tables'=>$this->tableDraftTables(),'catalog'=>$this->orderCatalog?->staffCatalogRows()??[],'catalog_groups'=>$this->orderCatalog?->staffCatalogGroups()??[]],$now);
        return $models;
    }
    private function tableDraftTables(): array
    {
        $sql="SELECT t.id,t.name,t.table_number,t.sort_order,t.zone_label,
            s.id session_id,s.status session_status,s.started_at,
            COALESCE((SELECT SUM(o.total_amount) FROM orders o WHERE o.session_id=s.id AND o.status='accounted'),0) account_total,
            COALESCE((SELECT COUNT(*) FROM orders o WHERE o.session_id=s.id AND o.status IN('pending_approval','new')),0) pending_order_count
            FROM cafe_tables t
            LEFT JOIN table_sessions s ON s.id=(SELECT s2.id FROM table_sessions s2 WHERE s2.table_id=t.id AND s2.status IN('active','pending') ORDER BY FIELD(s2.status,'active','pending'),s2.id DESC LIMIT 1)
            WHERE t.active=1 ORDER BY t.sort_order,t.table_number,t.id";
        return array_map(static fn(array $r):array=>[
            'id'=>(int)$r['id'],'name'=>(string)$r['name'],'table_number'=>(int)$r['table_number'],
            'sort_order'=>(int)$r['sort_order'],'zone_label'=>(string)($r['zone_label']??''),
            'session_id'=>(int)($r['session_id']??0),'session_status'=>(string)($r['session_status']??''),
            'started_at'=>(string)($r['started_at']??''),'account_total'=>(int)$r['account_total'],
            'pending_order_count'=>(int)$r['pending_order_count'],
        ],$this->rows($sql));
    }
    private function settlementAccounts(): array
    {
        if($this->settlements===null)return [];
        $ids=$this->pdo->query("SELECT id FROM table_sessions WHERE status IN ('active','pending') ORDER BY id DESC LIMIT 50")->fetchAll(PDO::FETCH_COLUMN)?:[];$out=[];
        foreach($ids as $id){try{$a=$this->settlements->account((int)$id);$out[]=['session_id'=>(int)($a['session']['id']??$id),'table_name'=>(string)($a['session']['table_name']??''),'remaining_total'=>(int)($a['remaining_total']??0),'signature'=>(string)($a['signature']??'')];}catch(Throwable){}}
        return $out;
    }
    private function model(string $key,array $payload,string $at): array{return ['format'=>'sokna-remote-read-v1','model_key'=>$key,'source_version'=>self::hash($payload),'generated_at'=>$at,'payload'=>$payload];}
    private function caps(int $id): array{$q=$this->pdo->prepare('SELECT capability FROM user_capabilities WHERE user_id=? AND enabled=1');$q->execute([$id]);return array_map('strval',$q->fetchAll(PDO::FETCH_COLUMN));}
    private function areas(int $id): array{$q=$this->pdo->prepare("SELECT area_key FROM user_preparation_areas WHERE user_id=? AND area_key IN ('kitchen','bar')");$q->execute([$id]);return array_map('strval',$q->fetchAll(PDO::FETCH_COLUMN));}
    private function setting(string $k,string $d=''): string{$q=$this->pdo->prepare('SELECT setting_value FROM settings WHERE setting_key=?');$q->execute([$k]);$v=$q->fetchColumn();return $v===false?$d:(string)$v;}
    private function settingBool(string $k,bool $d): bool{$v=strtolower(trim($this->setting($k,$d?'1':'0')));return in_array($v,['1','true','yes','on'],true);}
    private function rows(string $sql): array{return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC)?:[];}
    private function scalar(string $sql): mixed{return $this->pdo->query($sql)->fetchColumn();}
    public static function hash(mixed $v): string{return hash('sha256',self::json($v));}
    private static function json(mixed $v): string{return json_encode(self::norm($v),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);}
    private static function norm(mixed $v): mixed{if(!is_array($v))return $v;if(array_is_list($v))return array_map([self::class,'norm'],$v);ksort($v,SORT_STRING);foreach($v as $k=>$x)$v[$k]=self::norm($x);return $v;}
}
