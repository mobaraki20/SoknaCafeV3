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
use Sokna\Local\Domain\Orders\OrderWorkspaceService;
use Sokna\Local\Domain\Preparation\PreparationService;
use Sokna\Local\Domain\Tax\TaxService;
final class PublicProjectionBuilder
{
    public function __construct(private readonly PDO $pdo,private readonly ?SettlementService $settlements=null,private readonly ?SupplyService $supply=null,private readonly ?GuestContentService $guestContent=null,private readonly ?MarketingService $marketing=null,private readonly ?ReportingService $reporting=null,private readonly ?NotificationService $notifications=null,private readonly ?CategoryIconLibrary $categoryIcons=null,private readonly ?OrderCatalogService $orderCatalog=null,private readonly ?PreparationService $preparation=null,private readonly ?OrderWorkspaceService $orderWorkspace=null){}
    public function installation(string $installationId): array{return ['installation_id'=>$installationId,'display_name'=>$this->setting('cafe.name','SOKNA'),'remote_enabled'=>true,'order_intake_enabled'=>$this->settingBool('orders_accepting.cafe',true)];}
    public function authProjections(): array
    {
        $rows=$this->pdo->query('SELECT u.id,u.username,u.display_name,u.role,u.active,u.updated_at,rc.password_hash remote_password_hash,rc.credential_version,rc.source_mode remote_source_mode,rc.updated_at remote_credential_updated_at FROM users u LEFT JOIN remote_user_credentials rc ON rc.user_id=u.id WHERE u.active=1 ORDER BY u.id')->fetchAll(PDO::FETCH_ASSOC);$out=[];
        foreach($rows as $u){
            $id=(int)$u['id'];$remotePasswordHash=trim((string)($u['remote_password_hash']??''));$remoteSource=(string)($u['remote_source_mode']??'legacy_separate');
            if($remotePasswordHash===''||$remoteSource!=='local_password')continue;
            $caps=$this->caps($id);$admin=(string)$u['role']==='admin';
            // Remote access is a single entry gate. Once admitted, the Public Staff
            // projection derives every operation from the user's canonical Local
            // responsibilities instead of maintaining a second remote_* authority.
            if(!$admin&&!in_array('remote_access',$caps,true))continue;
            $projected=$admin?['*']:$this->publicCapabilitiesFromLocal($caps);
            $areas=$this->areas($id);$projectionVersion=max(1,(int)(strtotime((string)$u['updated_at'])?:1),(int)(strtotime((string)($u['remote_credential_updated_at']??''))?:1));
            $out[]=['projection_id'=>'user:'.$id,'username'=>(string)$u['username'],'display_name'=>(string)$u['display_name'],'role'=>(string)$u['role'],'password_hash'=>$remotePasswordHash,'capabilities'=>$projected,'preparation_areas'=>$areas,'projection_version'=>$projectionVersion,'active'=>true];
        }
        return $out;
    }

    /** @param list<string> $caps @return list<string> */
    private function publicCapabilitiesFromLocal(array $caps): array
    {
        // Preserve canonical Local capabilities in the projection so the exact
        // ProductShell/navigation grammar can run on both hosts.
        $out=array_values(array_filter(array_map('strval',$caps),static fn(string $cap):bool=>!str_starts_with($cap,'remote_')||$cap==='remote_access'));
        $has=static fn(string $cap):bool=>in_array($cap,$caps,true);
        $add=static function(array &$target,string ...$items):void{foreach($items as $item)if(!in_array($item,$target,true))$target[]=$item;};
        if($has('orders_floor'))$add($out,'operations.read','orders.table_draft','orders.mutate');
        if($has('cashier_accounts'))$add($out,'operations.read','finance.settle','reports.read','subscriber.read','subscriber.payment.defer','deferred.context');
        if($has('preparation'))$add($out,'preparation.read','preparation.mutate');
        if($has('shift_supervision'))$add($out,'operations.read','reports.read','preparation.read','preparation.monitor');
        if($has('inventory_view')||$has('inventory_operations')||$has('inventory_manage')||$has('inventory_finalize'))$add($out,'inventory.read');
        if($has('inventory_cost_view'))$add($out,'inventory.cost.read');
        if($has('inventory_operations')||$has('inventory_manage')||$has('inventory_finalize'))$add($out,'supply.need.defer','supply.manage.defer','deferred.context');
        if($has('staff_consumption_reports'))$add($out,'reports.read');
        if($has('inventory_view')||$has('inventory_cost_view')||$has('inventory_operations')||$has('inventory_manage')||$has('inventory_finalize')||$has('preparation')||$has('shift_supervision'))$add($out,'operations.workspace.read');
        if($has('inventory_operations')||$has('inventory_manage')||$has('inventory_finalize')||$has('preparation')||$has('shift_supervision'))$add($out,'operations.write');
        // Notifications are part of every signed-in Local workspace. Public push
        // still requires a live Local relay and is enforced server-side.
        $add($out,'notifications.read','notifications.push');
        return array_values(array_unique($out));
    }

    public function guestPublish(): array
    {
        $menus=$this->pdo->query("SELECT id,menu_key,name,sort_order FROM menus WHERE status='active' ORDER BY sort_order,id")->fetchAll(PDO::FETCH_ASSOC);
        $menuList=[];$catalogs=[];$manifest=[];
        foreach($menus as $m){
            $menu=['menu_key'=>(string)$m['menu_key'],'name'=>(string)$m['name'],'sort_order'=>(int)$m['sort_order']];
            $menuList[]=$menu;
            $q=$this->pdo->prepare("SELECT DISTINCT c.id,c.name,c.image_path,c.icon_key,mc.sort_order FROM categories c JOIN menu_categories mc ON mc.category_id=c.id WHERE mc.menu_id=? AND c.lifecycle_status='active' AND c.guest_visible=1 ORDER BY mc.sort_order,c.id");
            $q->execute([(int)$m['id']]);
            $cats=[];
            foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){
                $source='';
                if($this->guestContent!==null){$resolved=$this->guestContent->publicMediaForSource((string)($r['image_path']??''));if(is_array($resolved)){$source=(string)$resolved['source'];$manifest[$source]=(array)$resolved['manifest'];}}
                $cats[]=['id'=>(int)$r['id'],'name'=>(string)$r['name'],'sort_order'=>(int)$r['sort_order'],'icon_key'=>$this->categoryIcons?->resolve((string)($r['icon_key']??''),(string)$r['name'])??'list','image_path'=>$source];
            }
            $iq=$this->pdo->prepare("SELECT i.id,i.category_id,c.name category_name,i.name,i.description,i.price,i.available,i.featured,i.takeaway_allowed,i.preparation_station,i.suggested_item_id,i.image_path,i.sort_order FROM items i JOIN categories c ON c.id=i.category_id JOIN menu_items mi ON mi.item_id=i.id WHERE mi.menu_id=? AND i.lifecycle_status='active' AND i.guest_visible=1 AND c.lifecycle_status='active' AND c.guest_visible=1 AND i.sellable_kind='menu_item' ORDER BY c.sort_order,i.sort_order,i.id");
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
            $visibleIds=array_fill_keys(array_map(static fn(array $i):int=>(int)$i['id'],$items),true);
            $families=[];$fq=$this->pdo->prepare("SELECT f.id,f.family_key,f.category_id,f.name,f.description,f.image_path,f.sort_order,f.featured,m.item_id,m.label,m.sort_order member_sort FROM item_variant_families f JOIN item_variant_members m ON m.family_id=f.id JOIN menu_items mi ON mi.item_id=m.item_id WHERE mi.menu_id=? ORDER BY f.sort_order,f.id,m.sort_order,m.item_id");$fq->execute([(int)$m['id']]);foreach($fq->fetchAll(PDO::FETCH_ASSOC)?:[] as $r){$itemId=(int)$r['item_id'];if(!isset($visibleIds[$itemId]))continue;$fid=(int)$r['id'];if(!isset($families[$fid]))$families[$fid]=['id'=>$fid,'family_key'=>(string)$r['family_key'],'category_id'=>(int)$r['category_id'],'name'=>(string)$r['name'],'description'=>(string)($r['description']??''),'image_path'=>(string)($r['image_path']??''),'sort_order'=>(int)$r['sort_order'],'featured'=>(bool)$r['featured'],'members'=>[]];$families[$fid]['members'][]=['item_id'=>$itemId,'label'=>(string)$r['label'],'sort_order'=>(int)$r['member_sort']];}
            $suggestions=[];$sq=$this->pdo->query("SELECT source_item_id,suggested_item_id,sort_order FROM item_suggestions WHERE active=1 ORDER BY source_item_id,sort_order,suggested_item_id");foreach($sq->fetchAll(PDO::FETCH_ASSOC)?:[] as $r){$src=(int)$r['source_item_id'];$dst=(int)$r['suggested_item_id'];if(isset($visibleIds[$src],$visibleIds[$dst])&&count($suggestions[$src]??[])<2)$suggestions[$src][]=$dst;}
            $bundles=[];$bq=$this->pdo->query("SELECT b.bundle_item_id,b.choice_label,c.component_item_id,c.mode,c.quantity,c.price_delta,c.sort_order FROM item_bundles b JOIN item_bundle_components c ON c.bundle_item_id=b.bundle_item_id ORDER BY b.bundle_item_id,c.sort_order,c.id");foreach($bq->fetchAll(PDO::FETCH_ASSOC)?:[] as $r){$bid=(int)$r['bundle_item_id'];$cid=(int)$r['component_item_id'];if(!isset($visibleIds[$bid]))continue;if(!isset($bundles[$bid]))$bundles[$bid]=['bundle_item_id'=>$bid,'choice_label'=>(string)($r['choice_label']??''),'components'=>[]];$bundles[$bid]['components'][]=['item_id'=>$cid,'mode'=>(string)$r['mode'],'quantity'=>(int)$r['quantity'],'price_delta'=>(int)$r['price_delta'],'sort_order'=>(int)$r['sort_order']];}
            $familyByItem=[];foreach($families as $family){foreach((array)$family['members'] as $member)$familyByItem[(int)$member['item_id']]=['id'=>(int)$family['id'],'name'=>(string)$family['name'],'description'=>(string)$family['description'],'image_path'=>(string)$family['image_path'],'featured'=>(bool)$family['featured'],'members'=>$family['members'],'label'=>(string)$member['label']];}
            foreach($items as &$item){$id=(int)$item['id'];$item['suggested_item_ids']=$suggestions[$id]??[];$item['is_bundle']=isset($bundles[$id]);if(isset($bundles[$id]))$item['bundle']=$bundles[$id];if(isset($familyByItem[$id])){$item['variant_family_id']=$familyByItem[$id]['id'];$item['variant_family_name']=$familyByItem[$id]['name'];$item['variant_family_description']=$familyByItem[$id]['description'];$item['variant_family_image_path']=$familyByItem[$id]['image_path'];$item['variant_family_featured']=$familyByItem[$id]['featured'];$item['variant_label']=$familyByItem[$id]['label'];$item['variant_members']=$familyByItem[$id]['members'];}}unset($item);
            $catalogs[(string)$m['menu_key']]=['menu'=>$menu,'categories'=>$cats,'items'=>$items,'variant_families'=>array_values($families),'bundles'=>array_values($bundles)];
        }
        $presentation=$this->guestContent?->publishedPresentation();
        if(is_array($presentation)&&array_key_exists('menu_groups_visible',$presentation)&&$presentation['menu_groups_visible']===false){[$menuList,$catalogs]=$this->flattenGuestCatalogs($menuList,$catalogs);}
        $tables=array_map(fn($r)=>['id'=>(int)$r['id'],'name'=>(string)$r['name'],'code'=>(string)$r['code'],'token'=>(string)$r['access_token']],$this->pdo->query('SELECT id,name,code,access_token FROM cafe_tables WHERE active=1 ORDER BY sort_order,id')->fetchAll(PDO::FETCH_ASSOC));
        $snapshot=['format'=>'sokna-guest-snapshot-v1','cafe_name'=>$this->setting('cafe.name','SOKNA'),'features'=>['table_sessions_enabled'=>true],'tables'=>$tables,'menus'=>$menuList,'catalogs'=>$catalogs];
        if($this->guestContent!==null){$snapshot['presentation']=is_array($presentation)?$presentation:$this->guestContent->publishedPresentation();$identity=$this->guestContent->publishedIdentityProjection();$snapshot['identity']=$identity['snapshot'];foreach((array)$identity['manifest'] as $source=>$meta)$manifest[(string)$source]=(array)$meta;}
        if($this->marketing!==null){
            $marketing=$this->marketing->publicFeed();
            if($this->guestContent!==null){
                foreach(['campaigns','events'] as $section){
                    if(!is_array($marketing[$section]??null))continue;
                    foreach($marketing[$section] as &$entry){
                        if(!is_array($entry))continue;$source=trim((string)($entry['cover_image_path']??''));
                        if($source==='')continue;$resolved=$this->guestContent->publicMediaForSource($source);
                        if(is_array($resolved)){$entry['cover_image_path']=(string)$resolved['source'];$manifest[(string)$resolved['source']]=(array)$resolved['manifest'];}
                        else $entry['cover_image_path']='';
                    }
                    unset($entry);
                }
            }
            $snapshot['marketing']=$marketing;
        }
        if($menuList!==[]){$first=(string)$menuList[0]['menu_key'];$snapshot['categories']=$catalogs[$first]['categories'];$snapshot['items']=$catalogs[$first]['items'];}
        ksort($manifest,SORT_STRING);
        $hash=self::hash(['format'=>'sokna-guest-snapshot-v1','snapshot'=>$snapshot,'media_manifest'=>$manifest]);
        return ['revision_id'=>'guest-'.substr($hash,0,32),'content_hash'=>$hash,'generated_at'=>gmdate('c'),'snapshot'=>$snapshot,'media_manifest'=>$manifest];
    }

    /** @return array{0:array<int,array<string,mixed>>,1:array<string,array<string,mixed>>} */
    private function flattenGuestCatalogs(array $menus,array $catalogs): array
    {
        if($menus===[]||$catalogs===[])return [$menus,$catalogs];
        $first=is_array($menus[0]??null)?$menus[0]:[];
        $key=trim((string)($first['menu_key']??''));if($key==='')$key='main';
        $categories=[];$items=[];
        foreach($menus as $menu){
            if(!is_array($menu))continue;$menuKey=(string)($menu['menu_key']??'');$catalog=is_array($catalogs[$menuKey]??null)?$catalogs[$menuKey]:[];
            foreach((array)($catalog['categories']??[]) as $category){if(!is_array($category))continue;$id=(int)($category['id']??0);if($id>0&&!isset($categories[$id]))$categories[$id]=$category;}
            foreach((array)($catalog['items']??[]) as $item){if(!is_array($item))continue;$id=(int)($item['id']??0);if($id>0&&!isset($items[$id]))$items[$id]=$item;}
        }
        $one=['menu_key'=>$key,'name'=>(string)($first['name']??'منو'),'sort_order'=>(int)($first['sort_order']??0)];
        return [[$one],[$key=>['menu'=>$one,'categories'=>array_values($categories),'items'=>array_values($items)]]];
    }

    public function guestMediaPayloads(array $manifest): array
    {
        return $this->guestContent?->mediaPayloads($manifest) ?? [];
    }
    public function availability(): array
    {
        $items=[];foreach($this->pdo->query("SELECT id,available FROM items WHERE lifecycle_status='active' AND guest_visible=1 AND sellable_kind='menu_item'")->fetchAll(PDO::FETCH_ASSOC) as $r)$items[(string)$r['id']]=['available'=>(bool)$r['available']];
        $tables=[];$q=$this->pdo->query("SELECT t.id,s.public_token,s.started_at,s.status FROM cafe_tables t LEFT JOIN table_sessions s ON s.table_id=t.id AND s.status='active' AND s.live_table_guard IS NOT NULL WHERE t.active=1 ORDER BY t.id");foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r)$tables[(string)$r['id']]=['session'=>!empty($r['public_token'])?['token'=>(string)$r['public_token'],'started_at'=>gmdate('c',strtotime((string)$r['started_at'])),'status'=>(string)$r['status']]:null];
        $state=['items'=>$items,'order_acceptance'=>['cafe'=>$this->settingBool('orders_accepting.cafe',true),'kitchen'=>$this->settingBool('orders_accepting.kitchen',true),'bar'=>$this->settingBool('orders_accepting.bar',true)],'waiter_enabled_table'=>$this->settingBool('waiter_call_enabled',true),'waiter_enabled_public'=>$this->settingBool('public_waiter_call_enabled',false),'tables'=>$tables];return ['version'=>self::hash($state),'generated_at'=>gmdate('c')]+$state;
    }
    /** @return list<string> */
    public function remoteModelKeys(): array
    {
        return [
            'operations','preparation','inventory','inventory_cost','operations_workspace',
            'subscribers','reports','notifications','deferred_context','table_draft_context','guest_operational',
        ];
    }

    /** Causal table cards only, captured with the business receipt; no financial account DTO. */
    public function committedTableStates(array $payload,array $result,int $sequence): ?array
    {
        if($sequence<1||$this->orderWorkspace===null)return null;
        $ids=[];
        foreach([$payload,$result,(array)($result['order']??[]),(array)($result['draft']??[])] as $source){
            foreach(['table_id','source_table_id','target_table_id','destination_table_id','from_table_id','to_table_id'] as $key)if((int)($source[$key]??0)>0)$ids[]=(int)$source[$key];
            foreach(['session_id','expected_session_id'] as $key)if((int)($source[$key]??0)>0){$q=$this->pdo->prepare('SELECT table_id FROM table_sessions WHERE id=? LIMIT 1');$q->execute([(int)$source[$key]]);$id=(int)$q->fetchColumn();if($id>0)$ids[]=$id;}
        }
        $rows=$this->orderWorkspace->publicTableStates($ids);
        return $rows===[]?null:['format'=>'sokna-committed-table-states-v1','sequence'=>$sequence,'rows'=>$rows];
    }

    public function remoteModels(): array
    {
        return $this->remoteModelsFor($this->remoteModelKeys());
    }

    /**
     * Build only the requested Public read models. This is the hot-path companion to the
     * 30-second full projection sync: Local transactional outbox events collapse to a small
     * set of model keys so a Hall/order mutation does not rebuild inventory/reports/etc.
     *
     * @param list<string> $keys
     * @return list<array<string,mixed>>
     */
    public function remoteModelsFor(array $keys): array
    {
        $wanted=[];
        foreach($keys as $key){$key=trim((string)$key);if($key!=='')$wanted[$key]=true;}
        if($wanted===[])return [];
        $now=gmdate('c');$models=[];
        $add=function(string $key,array $payload) use (&$models,$wanted,$now):void {
            if(isset($wanted[$key]))$models[]=$this->model($key,$payload,$now);
        };
        if(isset($wanted['operations'])){
            $hall=$this->orderWorkspace?->publicHallProjection()??[];
            $add('operations',[
                'orders'=>$this->operationOrders(),
                'waiter_calls'=>$hall['waiter_calls']??$this->rows("SELECT w.id,w.public_code,w.status,w.created_at,t.name table_name FROM waiter_calls w JOIN cafe_tables t ON t.id=w.table_id WHERE w.status IN ('new','accepted') ORDER BY w.id DESC LIMIT 50"),
                'settlement_accounts'=>$this->settlementAccounts(),
                'hall'=>$hall,
            ]);
        }
        if(isset($wanted['preparation']))$add('preparation',$this->preparation?->publicProjection()??['orders'=>[]]);
        if(isset($wanted['inventory']))$add('inventory',['items'=>$this->rows("SELECT ii.id,ii.item_code,ii.name,ii.base_unit,ii.default_department,COALESCE(b.quantity_base,0) quantity_base,b.cost_status,b.updated_at FROM inventory_items ii LEFT JOIN inventory_balances b ON b.inventory_item_id=ii.id WHERE ii.active=1 ORDER BY ii.name LIMIT 500")]);
        if(isset($wanted['inventory_cost']))$add('inventory_cost',['items'=>$this->rows("SELECT ii.id,ii.item_code,ii.name,ii.base_unit,COALESCE(b.quantity_base,0) quantity_base,b.average_unit_cost,b.cost_status,b.updated_at FROM inventory_items ii LEFT JOIN inventory_balances b ON b.inventory_item_id=ii.id WHERE ii.active=1 ORDER BY ii.name LIMIT 500")]);
        if(isset($wanted['operations_workspace']))$add('operations_workspace',$this->remoteOperationsWorkspace());
        if(isset($wanted['subscribers']))$add('subscribers',$this->remoteSubscriberWorkspace());
        if(isset($wanted['reports']))$add('reports',$this->remoteReportWorkspace());
        if(isset($wanted['notifications']))$add('notifications',['items'=>$this->notifications?->remoteRows()??[],'push'=>$this->notifications?->remotePushConfig()??['vapid_public_key'=>'','bridge_configured'=>false]]);
        if(isset($wanted['deferred_context']))$add('deferred_context',[
            'inventory_items'=>$this->rows("SELECT id,item_code,name,base_unit,default_department FROM inventory_items WHERE active=1 ORDER BY name LIMIT 500"),
            'count_drafts'=>$this->rows("SELECT id,status,created_at FROM inventory_count_sessions WHERE status='draft' ORDER BY id DESC LIMIT 50"),
            'subscribers'=>$this->rows("SELECT s.id,s.name,s.mobile,s.active,COALESCE((SELECT l.balance_after FROM subscriber_ledger l WHERE l.subscriber_id=s.id ORDER BY l.id DESC LIMIT 1),0) balance,(SELECT MAX(l3.created_at) FROM subscriber_ledger l3 WHERE l3.subscriber_id=s.id) last_activity FROM subscribers s WHERE s.active=1 ORDER BY s.name LIMIT 300"),
            'expense_categories'=>$this->rows("SELECT category_key,name FROM expense_categories WHERE active=1 ORDER BY sort_order,category_key"),
            'supply_groups'=>$this->supply?->purchaseGroups()??[],
        ]);
        if(isset($wanted['table_draft_context']))$add('table_draft_context',[
            'tables'=>$this->tableDraftTables(),
            'drafts'=>$this->tableDraftProjections(),
            'catalog'=>$this->orderCatalog?->staffCatalogRows()??[],
            'catalog_groups'=>$this->orderCatalog?->staffCatalogGroups()??[],
        ]);
        if(isset($wanted['guest_operational']))$add('guest_operational',$this->guestOperationalProjection());
        return $models;
    }

    /**
     * Privacy-bounded Guest operational projection used only by Public's Guest runtime.
     * Raw browser device/client tokens never leave Local in this read model; Public matches
     * SHA-256 ownership/tracking hashes supplied by the browser request.
     */
    private function guestOperationalProjection(): array
    {
        $now=gmdate('c');
        $acceptance=[
            'cafe'=>$this->settingBool('orders_accepting.cafe',true),
            'kitchen'=>$this->settingBool('orders_accepting.kitchen',true),
            'bar'=>$this->settingBool('orders_accepting.bar',true),
        ];
        $messages=[
            'cafe'=>$acceptance['cafe']?'':'سفارش‌گیری فعلاً متوقف است.',
            'kitchen'=>$acceptance['kitchen']?'':'آشپزخانه فعلاً سفارش تازه نمی‌پذیرد.',
            'bar'=>$acceptance['bar']?'':'بار فعلاً سفارش تازه نمی‌پذیرد.',
        ];
        $lateMinutes=max(5,(int)$this->setting('new_device_alert_minutes','20'));

        $tables=[];
        foreach($this->rows("SELECT id,name,code FROM cafe_tables WHERE active=1 ORDER BY sort_order,id") as $table){
            $id=(int)$table['id'];
            $tables[(string)$id]=[
                'table'=>['id'=>$id,'name'=>(string)$table['name'],'code'=>(string)$table['code']],
                'active_session'=>null,'pending_session'=>null,'known_device_hashes'=>[],'active_call'=>null,
            ];
        }

        $liveSessions=$this->rows("SELECT id,public_token,table_id,status,started_at FROM table_sessions WHERE status IN('active','pending') AND live_table_guard IS NOT NULL ORDER BY FIELD(status,'active','pending'),id DESC");
        $liveSessionIds=[];
        foreach($liveSessions as $session){
            $tableId=(int)$session['table_id'];$sid=(int)$session['id'];
            if(!isset($tables[(string)$tableId]))continue;
            $key=(string)$session['status']==='active'?'active_session':'pending_session';
            if($tables[(string)$tableId][$key]!==null)continue;
            $tables[(string)$tableId][$key]=[
                'id'=>$sid,'token'=>(string)$session['public_token'],'started_at'=>(string)$session['started_at'],'status'=>(string)$session['status'],
            ];
            $liveSessionIds[$sid]=true;
        }

        if($liveSessionIds!==[]){
            $ids=array_keys($liveSessionIds);$ph=implode(',',array_fill(0,count($ids),'?'));
            $q=$this->pdo->prepare("SELECT session_id,device_token FROM table_session_clients WHERE session_id IN($ph)");
            $q->execute($ids);
            $sessionToTable=[];
            foreach($tables as $tableId=>$state){
                foreach(['active_session','pending_session'] as $sk){
                    $session=$state[$sk]??null;
                    if(is_array($session))$sessionToTable[(int)$session['id']]=$tableId;
                }
            }
            foreach($q->fetchAll(PDO::FETCH_ASSOC)?:[] as $client){
                $sid=(int)$client['session_id'];$tableId=$sessionToTable[$sid]??null;
                if($tableId===null)continue;
                $token=(string)($client['device_token']??'');
                if($token!=='')$tables[(string)$tableId]['known_device_hashes'][hash('sha256',$token)]=true;
            }
            foreach($tables as &$state)$state['known_device_hashes']=array_keys((array)$state['known_device_hashes']);
            unset($state);
        }

        foreach($this->rows("SELECT w.public_code,w.status,w.created_at,w.table_id FROM waiter_calls w WHERE w.status IN('new','accepted') ORDER BY w.created_at DESC,w.id DESC") as $call){
            $tableId=(string)(int)$call['table_id'];
            if(!isset($tables[$tableId])||$tables[$tableId]['active_call']!==null)continue;
            $tables[$tableId]['active_call']=[
                'public_code'=>(string)$call['public_code'],'status'=>(string)$call['status'],'created_at'=>(string)$call['created_at'],
            ];
        }

        $orders=$this->rows(
            "SELECT o.id,o.public_code,o.client_token,o.device_token,o.table_id,o.session_id,o.business_order_number,o.status,o.customer_note,o.total_amount,o.accepted_at,o.created_at,o.updated_at,"
            ."ts.status session_status,ts.public_token session_token,ts.ended_at session_ended_at "
            ."FROM orders o LEFT JOIN table_sessions ts ON ts.id=o.session_id "
            ."WHERE o.order_source='guest' AND ((ts.status IN('active','pending') AND ts.live_table_guard IS NOT NULL) OR o.created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)) "
            ."ORDER BY o.id DESC LIMIT 1000"
        );
        $ids=array_values(array_filter(array_map(static fn(array $row):int=>(int)($row['id']??0),$orders),static fn(int $id):bool=>$id>0));
        $byOrder=[];
        if($ids!==[]){
            $ph=implode(',',array_fill(0,count($ids),'?'));
            $q=$this->pdo->prepare(
                "SELECT id order_item_id,order_id,item_id,item_name,unit_price,quantity,item_note,fulfillment_mode,line_total,tax_policy_snapshot,tax_rate_bps_snapshot "
                ."FROM order_items WHERE order_id IN($ph) ORDER BY order_id,id"
            );
            $q->execute($ids);
            $rawLines=$q->fetchAll(PDO::FETCH_ASSOC)?:[];$orderItemIds=[];foreach($rawLines as $line)$orderItemIds[]=(int)$line['order_item_id'];$bundleByLine=[];
            if($orderItemIds!==[]){$bph=implode(',',array_fill(0,count($orderItemIds),'?'));$bq=$this->pdo->prepare("SELECT order_item_id,component_item_id,item_name_snapshot,quantity,preparation_station_snapshot,component_mode,choice_label_snapshot FROM order_item_bundle_components WHERE order_item_id IN($bph) ORDER BY order_item_id,id");$bq->execute($orderItemIds);foreach($bq->fetchAll(PDO::FETCH_ASSOC)?:[] as $bc)$bundleByLine[(int)$bc['order_item_id']][]=['component_item_id'=>(int)($bc['component_item_id']??0),'name'=>(string)$bc['item_name_snapshot'],'quantity'=>(int)$bc['quantity'],'preparation_station'=>(string)$bc['preparation_station_snapshot'],'mode'=>(string)$bc['component_mode'],'choice_label'=>$bc['choice_label_snapshot']];}
            foreach($rawLines as $line){$components=$bundleByLine[(int)$line['order_item_id']]??[];$choice=null;foreach($components as $bc)if(($bc['mode']??'')==='choice'){$choice=(int)($bc['component_item_id']??0);break;}$line['bundle_components']=$components;$line['bundle_choice_item_id']=$choice;$byOrder[(int)$line['order_id']][]=$line;}
        }

        $projectedOrders=[];
        foreach($orders as $order){
            $lines=$byOrder[(int)$order['id']]??[];$taxLines=[];
            foreach($lines as $i=>$line)$taxLines[]=[
                'order_item_id'=>$i+1,'unit_price'=>(int)$line['unit_price'],'quantity'=>(int)$line['quantity'],
                'tax_policy_snapshot'=>(string)($line['tax_policy_snapshot']??'disabled'),
                'tax_rate_bps_snapshot'=>(int)($line['tax_rate_bps_snapshot']??0),
            ];
            $tax=TaxService::calculateInvoiceLines($taxLines,0);
            $status=(string)$order['status'];
            $public=[
                'order_code'=>(string)$order['public_code'],'order_number'=>(int)$order['business_order_number'],
                'status'=>$status,'status_label'=>$this->guestStatusLabel($status),'customer_note'=>(string)($order['customer_note']??''),
                'total_amount'=>(int)$order['total_amount'],'tax_amount'=>(int)$tax['tax'],'final_amount'=>(int)$tax['total'],
                'created_at'=>(string)$order['created_at'],'updated_at'=>(string)$order['updated_at'],
                'edit_signature'=>$this->guestEditSignature($order,$lines),
                'can_edit'=>in_array($status,['pending_approval','new'],true),'can_cancel'=>in_array($status,['pending_approval','new'],true),
                'items'=>array_map(static fn(array $line):array=>[
                    'id'=>(int)$line['item_id'],'name'=>(string)$line['item_name'],'unit_price'=>(int)$line['unit_price'],
                    'quantity'=>(int)$line['quantity'],'note'=>(string)($line['item_note']??''),
                    'fulfillment_mode'=>(string)$line['fulfillment_mode'],'line_total'=>(int)$line['line_total'],
                    'bundle_choice_item_id'=>isset($line['bundle_choice_item_id'])&&$line['bundle_choice_item_id']!==null?(int)$line['bundle_choice_item_id']:null,
                    'bundle_components'=>is_array($line['bundle_components']??null)?$line['bundle_components']:[],
                    'tax_policy'=>(string)($line['tax_policy_snapshot']??'disabled'),'tax_rate_bps'=>(int)($line['tax_rate_bps_snapshot']??0),
                ],$lines),
            ];
            $device=(string)($order['device_token']??'');$client=(string)($order['client_token']??'');
            $projectedOrders[]=[
                'table_id'=>(int)$order['table_id'],'session_id'=>(int)($order['session_id']??0),
                'session_status'=>(string)($order['session_status']??''),'session_token'=>(string)($order['session_token']??''),
                'session_ended_at'=>(string)($order['session_ended_at']??''),
                'device_hash'=>$device===''?'':hash('sha256',$device),
                'tracking_hash'=>$client===''?'':hash('sha256',(string)$order['public_code'].'|'.$client),
                'accepted'=>$order['accepted_at']!==null||in_array($status,['accounted','completed'],true),
                'public'=>$public,
            ];
        }

        return [
            'format'=>'sokna-guest-operational-v1','generated_at'=>$now,
            'order_acceptance'=>$acceptance,'order_acceptance_messages'=>$messages,
            'waiter_enabled_table'=>$this->settingBool('waiter_call_enabled',true),
            'waiter_enabled_public'=>$this->settingBool('public_waiter_call_enabled',false),
            'new_device_alert_minutes'=>$lateMinutes,
            'tables'=>$tables,'orders'=>$projectedOrders,
        ];
    }

    private function guestStatusLabel(string $status): string
    {
        return ['pending_approval'=>'منتظر تأیید','new'=>'منتظر تأیید','accounted'=>'تأییدشده','completed'=>'تسویه‌شده','cancelled'=>'لغوشده'][$status]??$status;
    }

    private function guestEditSignature(array $order,array $items): string
    {
        $rows=[];
        foreach($items as $line)$rows[]=[
            'item_id'=>(int)($line['item_id']??0),'quantity'=>(int)($line['quantity']??0),'unit_price'=>(int)($line['unit_price']??0),
            'note'=>trim((string)($line['item_note']??'')),'fulfillment_mode'=>(string)($line['fulfillment_mode']??'dine_in'),
            'bundle_choice_item_id'=>isset($line['bundle_choice_item_id'])&&$line['bundle_choice_item_id']!==null?(int)$line['bundle_choice_item_id']:0,
        ];
        usort($rows,static fn(array $a,array $b):int=>($a['item_id']<=>$b['item_id'])?:($a['bundle_choice_item_id']<=>$b['bundle_choice_item_id'])?:strcmp($a['fulfillment_mode'],$b['fulfillment_mode'])?:strcmp($a['note'],$b['note']));
        return substr(hash('sha256',json_encode([
            'id'=>(int)($order['id']??0),'status'=>(string)($order['status']??''),
            'customer_note'=>trim((string)($order['customer_note']??'')),'updated_at'=>(string)($order['updated_at']??''),'items'=>$rows,
        ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)),0,32);
    }
    private function remoteReportWorkspace(): array
    {
        $owns=!$this->pdo->inTransaction();if($owns){$this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$this->pdo->beginTransaction();}
        try{$report=$this->reporting?->report()??[];if($this->reporting!==null){$report['dashboard']=$this->reporting->dashboardSnapshot();$report['archive']=$this->reporting->remoteArchive();}if($owns)$this->pdo->commit();return $report;}catch(\Throwable $e){if($owns&&$this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    private function remoteOperationsWorkspace(): array
    {
        $owns=!$this->pdo->inTransaction();if($owns){$this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$this->pdo->beginTransaction();}
        try{$cursor=(int)$this->pdo->query('SELECT `cursor` FROM operations_command_clock WHERE id=1')->fetchColumn();$s=$this->remoteOperationsWorkspaceTx();$s['business_cursor']=$cursor;if($owns)$this->pdo->commit();return $s;}catch(\Throwable $e){if($owns&&$this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }
    private function remoteOperationsWorkspaceTx(): array
    {
        $stateRows=$this->pdo->query("SELECT setting_key,setting_value FROM settings WHERE setting_key IN ('module.inventory.enabled','module.supply.enabled','inventory_initialized','inventory_reconciliation_required')")->fetchAll(PDO::FETCH_KEY_PAIR)?:[];
        $flag=static function(string $key,bool $default) use ($stateRows): bool {if(!array_key_exists($key,$stateRows))return $default;return in_array(strtolower(trim((string)$stateRows[$key])),['1','true','yes','on'],true);};
        $inventoryEnabled=$flag('module.inventory.enabled',true);$supplyEnabled=$flag('module.supply.enabled',true);$initialized=$flag('inventory_initialized',false);$reconciliation=$flag('inventory_reconciliation_required',false);$ready=$inventoryEnabled&&$initialized&&!$reconciliation;
        $items=$this->rows("SELECT i.id,i.item_code,i.name,i.category,c.name category_name,i.base_unit,i.default_department,i.warning_threshold,i.review_status,i.review_note,i.active,COALESCE(b.quantity_base,0) quantity_base,b.average_unit_cost,b.cost_status,b.updated_at balance_updated_at,(SELECT r.total_cost/r.received_quantity_base FROM inventory_supply_receipts r JOIN inventory_movements m ON m.id=r.movement_id WHERE r.inventory_item_id=i.id AND r.total_cost IS NOT NULL AND r.received_quantity_base>0 AND NOT EXISTS(SELECT 1 FROM inventory_movements rv WHERE rv.reversal_of_id=m.id) ORDER BY m.occurred_at DESC,m.id DESC LIMIT 1) last_purchase_base_unit_cost,(SELECT m.occurred_at FROM inventory_supply_receipts r JOIN inventory_movements m ON m.id=r.movement_id WHERE r.inventory_item_id=i.id AND r.total_cost IS NOT NULL AND r.received_quantity_base>0 AND NOT EXISTS(SELECT 1 FROM inventory_movements rv WHERE rv.reversal_of_id=m.id) ORDER BY m.occurred_at DESC,m.id DESC LIMIT 1) last_purchase_at FROM inventory_items i JOIN inventory_categories c ON c.category_key=i.category LEFT JOIN inventory_balances b ON b.inventory_item_id=i.id ORDER BY i.active DESC,i.review_status DESC,c.sort_order,i.name,i.id LIMIT 500");
        $aliasMap=[];foreach($this->rows("SELECT inventory_item_id,alias FROM inventory_item_aliases WHERE active=1 ORDER BY inventory_item_id,alias LIMIT 3000") as $a)$aliasMap[(int)$a['inventory_item_id']][]=(string)$a['alias'];
        foreach($items as &$item)$item['aliases']=$aliasMap[(int)$item['id']]??[];unset($item);
        $categories=$this->rows("SELECT category_key,name,active,system_category,sort_order FROM inventory_categories ORDER BY active DESC,sort_order,name");
        $sql="SELECT pu.id,pu.inventory_item_id,pu.name,pu.conversion_mode,pu.base_quantity,pu.review_status,pu.note,pu.active,
                    i.name item_name,i.base_unit,
                    (SELECT r.total_cost FROM inventory_supply_receipts r WHERE r.purchase_unit_id=pu.id AND r.total_cost IS NOT NULL AND r.purchase_unit_count>0 ORDER BY r.received_at DESC,r.id DESC LIMIT 1) last_receipt_total_cost,
                    (SELECT r.purchase_unit_count FROM inventory_supply_receipts r WHERE r.purchase_unit_id=pu.id AND r.total_cost IS NOT NULL AND r.purchase_unit_count>0 ORDER BY r.received_at DESC,r.id DESC LIMIT 1) last_receipt_unit_count,
                    (SELECT r.received_quantity_base FROM inventory_supply_receipts r WHERE r.purchase_unit_id=pu.id AND r.total_cost IS NOT NULL AND r.received_quantity_base>0 ORDER BY r.received_at DESC,r.id DESC LIMIT 1) last_receipt_quantity_base,
                    (SELECT r.received_at FROM inventory_supply_receipts r WHERE r.purchase_unit_id=pu.id AND r.total_cost IS NOT NULL ORDER BY r.received_at DESC,r.id DESC LIMIT 1) last_receipt_at,
                    (SELECT COUNT(*) FROM inventory_supply_receipts r WHERE r.purchase_unit_id=pu.id) receipt_count
             FROM inventory_purchase_units pu JOIN inventory_items i ON i.id=pu.inventory_item_id
             ORDER BY pu.inventory_item_id,pu.active DESC,pu.name,pu.id";
        $rows=$this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as &$row){
            $row['id']=(int)$row['id'];$row['inventory_item_id']=(int)$row['inventory_item_id'];$row['base_quantity']=$row['base_quantity']===null?null:(int)$row['base_quantity'];$row['active']=(bool)$row['active'];$row['receipt_count']=(int)($row['receipt_count']??0);
            $total=$row['last_receipt_total_cost']===null?null:(float)$row['last_receipt_total_cost'];
            $count=$row['last_receipt_unit_count']===null?null:(float)$row['last_receipt_unit_count'];
            $base=$row['last_receipt_quantity_base']===null?null:(int)$row['last_receipt_quantity_base'];
            $row['last_purchase_unit_price']=$total!==null&&$count!==null&&$count>0?$total/$count:null;
            $row['last_base_unit_cost']=$total!==null&&$base!==null&&$base>0?$total/$base:null;
        }unset($row);
        $purchaseUnits=$rows;
        $movements=$this->rows("SELECT m.id,m.inventory_item_id,m.movement_type,m.quantity_base,m.base_unit,m.department,m.total_cost_delta,m.cost_status,m.source_type,m.note,m.occurred_at,i.name item_name,u.display_name actor_name FROM inventory_movements m JOIN inventory_items i ON i.id=m.inventory_item_id LEFT JOIN users u ON u.id=m.actor_user_id ORDER BY m.occurred_at DESC,m.id DESC LIMIT 50");
        $receipts=$this->rows("SELECT r.id,r.received_quantity_base,r.remaining_quantity_after,r.purchase_unit_id,r.purchase_unit_name_snapshot,r.purchase_unit_count,r.conversion_base_quantity_snapshot,r.total_cost,r.supplier,r.note,r.received_at,i.name item_name,i.base_unit,u.display_name actor_name,pe.id purchase_price_event_id,pe.normalized_unit_cost,pe.last_unit_cost,pe.weighted_average_before,pe.weighted_average_after,pe.delta_last_percent,pe.delta_average_percent,pe.direction price_direction,pe.notification_level FROM inventory_supply_receipts r JOIN inventory_items i ON i.id=r.inventory_item_id LEFT JOIN users u ON u.id=r.actor_user_id LEFT JOIN inventory_purchase_price_events pe ON pe.receipt_id=r.id AND pe.raw_total_cost=r.total_cost ORDER BY r.received_at DESC,r.id DESC LIMIT 40");
        $missingCostCount=(int)$this->pdo->query("SELECT COUNT(*) FROM inventory_supply_receipts WHERE total_cost IS NULL")->fetchColumn();
        $missingCosts=$this->rows("SELECT r.id,r.inventory_item_id,r.movement_id,r.received_quantity_base,r.purchase_unit_id,r.purchase_unit_name_snapshot,r.purchase_unit_count,r.conversion_base_quantity_snapshot,r.total_cost,r.supplier,r.note,r.received_at,i.name item_name,i.base_unit,u.display_name actor_name FROM inventory_supply_receipts r JOIN inventory_items i ON i.id=r.inventory_item_id LEFT JOIN users u ON u.id=r.actor_user_id WHERE r.total_cost IS NULL ORDER BY r.received_at DESC,r.id DESC LIMIT 100");
        return ['module_state'=>['inventory_enabled'=>$inventoryEnabled,'supply_enabled'=>$supplyEnabled,'inventory_initialized'=>$initialized,'inventory_reconciliation_required'=>$reconciliation,'inventory_runtime_ready'=>$ready,'supply_runtime_ready'=>$supplyEnabled&&$ready],'inventory'=>['items'=>$items,'categories'=>$categories,'purchase_units'=>$purchaseUnits,'recent_movements'=>$movements,'open_count'=>$this->remoteOpenCount()],'supply'=>['purchases'=>$this->supply?->purchases()??[],'groups'=>$this->supply?->purchaseGroups()??[],'recent_receipts'=>$receipts,'purchase_price_policy'=>$this->supply?->purchasePricePolicy()??['enabled'=>false,'threshold_percent'=>10.0,'high_threshold_percent'=>25.0]],'data_quality'=>['missing_cost_receipt_count'=>$missingCostCount,'missing_cost_receipts'=>$missingCosts],'expenses'=>['categories'=>$this->rows("SELECT category_key,name,active FROM expense_categories WHERE active=1 ORDER BY sort_order,category_key"),'recent'=>$this->rows("SELECT e.id,e.category_key,c.name category_name,e.amount,e.description,e.occurred_at,e.status,fp.title period_title FROM expenses e JOIN expense_categories c ON c.category_key=e.category_key JOIN financial_periods fp ON fp.id=e.financial_period_id ORDER BY e.occurred_at DESC,e.id DESC LIMIT 60")],'errors'=>[]];
    }

    private function remoteOpenCount(): ?array
    {
        $session=$this->pdo->query("SELECT * FROM inventory_count_sessions WHERE status='draft' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if(!is_array($session))return null;
        $q=$this->pdo->prepare('SELECT l.id line_id,l.inventory_item_id,l.system_quantity_snapshot,l.actual_quantity,l.actual_total_cost,l.note,l.counted_at,l.updated_at version,i.name item_name,i.base_unit,c.name category_name FROM inventory_count_lines l JOIN inventory_items i ON i.id=l.inventory_item_id JOIN inventory_categories c ON c.category_key=i.category WHERE l.session_id=? ORDER BY c.sort_order,i.name,i.id');$q->execute([(int)$session['id']]);$session['lines']=$q->fetchAll(PDO::FETCH_ASSOC);return $session;
    }

    private function remoteSubscriberWorkspace(): array
    {
        $owns=!$this->pdo->inTransaction();if($owns){$this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$this->pdo->beginTransaction();}
        try{
            $rows=$this->rows("SELECT s.id,s.name,s.mobile,s.active,(SELECT COALESCE(MAX(lc.id),0) FROM subscriber_ledger lc WHERE lc.subscriber_id=s.id) ledger_cursor,COALESCE((SELECT l.balance_after FROM subscriber_ledger l WHERE l.subscriber_id=s.id ORDER BY l.id DESC LIMIT 1),0) balance,(SELECT MAX(l3.created_at) FROM subscriber_ledger l3 WHERE l3.subscriber_id=s.id) last_activity FROM subscribers s ORDER BY s.active DESC,balance DESC,s.name,s.id");
            $ledger=[];$invoices=[];$coverage=[];
            // Per-customer limits avoid starving quiet accounts behind a global LIMIT.
            $history=$this->rows("SELECT * FROM (SELECT l.id,l.subscriber_id,l.entry_type,l.amount_delta,l.balance_after,l.reference,l.reason note,l.created_at,u.display_name actor_name,fp.title period_title,ROW_NUMBER() OVER(PARTITION BY l.subscriber_id ORDER BY l.id DESC) rn,COUNT(*) OVER(PARTITION BY l.subscriber_id) total_rows FROM subscriber_ledger l LEFT JOIN users u ON u.id=l.actor_user_id LEFT JOIN financial_periods fp ON fp.id=l.financial_period_id) h WHERE rn<=200 ORDER BY subscriber_id,id DESC");
            foreach($history as $r){$id=(string)$r['subscriber_id'];$coverage[$id]['ledger_total']=(int)$r['total_rows'];unset($r['rn'],$r['total_rows']);$ledger[$id][]=$r;}
            $docs=$this->rows("SELECT * FROM (SELECT r.id,l.subscriber_id,r.invoice_number,r.status,r.reverses_settlement_id,r.subtotal,r.discount,r.tax_amount,r.total,r.business_date,r.created_at,ROW_NUMBER() OVER(PARTITION BY l.subscriber_id ORDER BY r.id DESC) rn,COUNT(*) OVER(PARTITION BY l.subscriber_id) total_rows FROM settlement_records r JOIN subscriber_ledger l ON l.id=r.subscriber_ledger_entry_id) h WHERE rn<=50 ORDER BY subscriber_id,id DESC");
            $docIds=[];foreach($docs as $r)$docIds[]=(int)$r['id'];$lines=[];
            foreach(array_chunk($docIds,500) as $chunk){$q=$this->pdo->prepare('SELECT settlement_id,item_name_snapshot,unit_price_snapshot,quantity,discount_amount,tax_amount,final_amount FROM settlement_record_lines WHERE settlement_id IN ('.implode(',',array_fill(0,count($chunk),'?')).') ORDER BY settlement_id,id');$q->execute($chunk);foreach($q->fetchAll(PDO::FETCH_ASSOC) as $line)$lines[(int)$line['settlement_id']][]=$line;}
            foreach($docs as $r){$id=(string)$r['subscriber_id'];$coverage[$id]['invoice_total']=(int)$r['total_rows'];unset($r['rn'],$r['total_rows']);$r['lines']=$lines[(int)$r['id']]??[];$invoices[$id][]=$r;}
            foreach($rows as $r){$id=(string)$r['id'];$coverage[$id]=($coverage[$id]??[])+['ledger_total'=>0,'invoice_total'=>0];$coverage[$id]['ledger_complete']=$coverage[$id]['ledger_total']<=200;$coverage[$id]['invoices_complete']=$coverage[$id]['invoice_total']<=50;$coverage[$id]['ledger_limit']=200;$coverage[$id]['invoice_limit']=50;}
            $out=['rows'=>$rows,'ledger'=>$ledger,'invoices'=>$invoices,'coverage'=>$coverage,'as_of'=>gmdate('c')];if($owns)$this->pdo->commit();return $out;
        }catch(\Throwable $e){if($owns&&$this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    /**
     * Active Staff order drafts projected to Public so opening an existing draft is a local
     * Public read, not a Public->Local relay command. Shape intentionally matches
     * TableDraftService::get()['draft']; mutation validation remains Local-authoritative.
     *
     * @return array<string,array<string,mixed>> keyed by table id
     */
    private function tableDraftProjections(): array
    {
        $draftRows=$this->rows("SELECT id,table_id,state,version,expected_session_id,note,created_by_user_id,updated_by_user_id,final_order_id FROM table_drafts WHERE state='active' ORDER BY table_id,id");
        if($draftRows===[])return [];
        $ids=array_values(array_filter(array_map(static fn(array $row):int=>(int)($row['id']??0),$draftRows),static fn(int $id):bool=>$id>0));
        $itemsByDraft=[];
        if($ids!==[]){
            $ph=implode(',',array_fill(0,count($ids),'?'));
            $q=$this->pdo->prepare("SELECT draft_id,item_id,item_name_snapshot,unit_price_snapshot,sellable_kind_snapshot,quantity,item_note,fulfillment_mode,sort_order FROM table_draft_items WHERE draft_id IN ($ph) ORDER BY draft_id,sort_order,id");
            $q->execute($ids);
            foreach($q->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){
                $itemsByDraft[(int)$row['draft_id']][]=[
                    'id'=>(int)$row['item_id'],
                    'name'=>(string)$row['item_name_snapshot'],
                    'unit_price'=>(int)$row['unit_price_snapshot'],
                    'sellable_kind'=>(string)$row['sellable_kind_snapshot'],
                    'quantity'=>(int)$row['quantity'],
                    'note'=>(string)($row['item_note']??''),
                    'fulfillment_mode'=>(string)($row['fulfillment_mode']??'dine_in'),
                    'sort_order'=>(int)$row['sort_order'],
                ];
            }
        }
        $out=[];
        foreach($draftRows as $row){
            $draftId=(int)($row['id']??0);$tableId=(int)($row['table_id']??0);
            if($draftId<1||$tableId<1)continue;
            $out[(string)$tableId]=[
                'id'=>$draftId,
                'table_id'=>$tableId,
                'state'=>(string)($row['state']??'active'),
                'version'=>(int)($row['version']??0),
                'expected_session_id'=>(int)($row['expected_session_id']??0),
                'note'=>(string)($row['note']??''),
                'created_by_user_id'=>$row['created_by_user_id']!==null?(int)$row['created_by_user_id']:null,
                'updated_by_user_id'=>$row['updated_by_user_id']!==null?(int)$row['updated_by_user_id']:null,
                'final_order_id'=>$row['final_order_id']!==null?(int)$row['final_order_id']:null,
                'items'=>$itemsByDraft[$draftId]??[],
            ];
        }
        return $out;
    }

    private function tableDraftTables(): array
    {
        $sql="SELECT t.id,t.name,t.table_number,t.sort_order,t.zone_label,
            s.id session_id,s.status session_status,s.started_at,
            d.id draft_id,d.version draft_version,d.expected_session_id draft_expected_session_id,d.created_at draft_created_at,d.updated_at draft_updated_at,
            COALESCE((SELECT COUNT(*) FROM table_draft_items di WHERE di.draft_id=d.id AND di.quantity>0),0) draft_item_count,
            COALESCE((SELECT SUM(di.quantity) FROM table_draft_items di WHERE di.draft_id=d.id AND di.quantity>0),0) draft_quantity_count,
            COALESCE((SELECT SUM(di.unit_price_snapshot*di.quantity) FROM table_draft_items di WHERE di.draft_id=d.id AND di.quantity>0),0) draft_total,
            (SELECT MAX(o2.created_at) FROM orders o2 WHERE o2.session_id=s.id AND o2.status='accounted') last_order_at,
            COALESCE((SELECT COUNT(*) FROM orders o3 WHERE o3.session_id=s.id AND o3.status='accounted'),0) order_round_count,
            COALESCE((SELECT SUM(o.total_amount) FROM orders o WHERE o.session_id=s.id AND o.status='accounted'),0) account_total,
            COALESCE((SELECT COUNT(*) FROM orders o WHERE o.session_id=s.id AND o.status IN('pending_approval','new')),0) pending_order_count,
            COALESCE((SELECT SUM(o.total_amount) FROM orders o WHERE o.session_id=s.id AND o.status IN('pending_approval','new')),0) pending_order_total,
            COALESCE((SELECT COUNT(*) FROM order_items oi JOIN orders po ON po.id=oi.order_id WHERE po.session_id=s.id AND po.status IN('pending_approval','new') AND oi.quantity>0),0) pending_item_count,
            COALESCE((SELECT SUM(oi.quantity) FROM order_items oi JOIN orders po ON po.id=oi.order_id WHERE po.session_id=s.id AND po.status IN('pending_approval','new') AND oi.quantity>0),0) pending_quantity_count,
            COALESCE((SELECT COUNT(*) FROM waiter_calls w WHERE w.table_id=t.id AND w.status IN('new','accepted')),0) active_waiter_count
            FROM cafe_tables t
            LEFT JOIN table_sessions s ON s.id=(SELECT s2.id FROM table_sessions s2 WHERE s2.table_id=t.id AND s2.status IN('active','pending') AND s2.live_table_guard IS NOT NULL ORDER BY FIELD(s2.status,'active','pending'),s2.id DESC LIMIT 1)
            LEFT JOIN table_drafts d ON d.table_id=t.id AND d.state='active'
            WHERE t.active=1 ORDER BY t.sort_order,t.table_number,t.id";
        return array_map(static function(array $r):array{
            $sessionId=(int)($r['session_id']??0);$draftId=(int)($r['draft_id']??0);$waiterCount=(int)($r['active_waiter_count']??0);
            $pendingCount=(int)($r['pending_order_count']??0);$roundCount=(int)($r['order_round_count']??0);
            $pendingOnly=$sessionId>0&&$pendingCount>0&&$roundCount===0&&(int)($r['account_total']??0)===0&&$draftId===0;
            $occupancy=$pendingOnly?'pending_guest':($sessionId>0?'account':($draftId>0?'draft':($waiterCount>0?'waiter':'free')));
            return [
                'id'=>(int)$r['id'],'name'=>(string)$r['name'],'table_number'=>(int)$r['table_number'],'sort_order'=>(int)$r['sort_order'],'zone_label'=>(string)($r['zone_label']??''),
                'session_id'=>$sessionId,'session_status'=>(string)($r['session_status']??''),'started_at'=>(string)($r['started_at']??''),'last_order_at'=>(string)($r['last_order_at']??''),
                'has_draft'=>$draftId>0,'draft_id'=>$draftId,'draft_version'=>(int)($r['draft_version']??0),'draft_expected_session_id'=>(int)($r['draft_expected_session_id']??0),
                'draft_created_at'=>(string)($r['draft_created_at']??''),'draft_updated_at'=>(string)($r['draft_updated_at']??''),'draft_item_count'=>(int)($r['draft_item_count']??0),'draft_quantity_count'=>(int)($r['draft_quantity_count']??0),'draft_total'=>(int)($r['draft_total']??0),
                'order_round_count'=>(int)($r['order_round_count']??0),'account_total'=>(int)$r['account_total'],'pending_order_count'=>(int)$r['pending_order_count'],
                'pending_order_total'=>(int)($r['pending_order_total']??0),'pending_item_count'=>(int)($r['pending_item_count']??0),'pending_quantity_count'=>(int)($r['pending_quantity_count']??0),'active_waiter_count'=>$waiterCount,
                'occupancy_kind'=>$occupancy,'transfer_eligible'=>$sessionId<1&&$draftId<1&&$waiterCount<1,
            ];
        },$this->rows($sql));
    }
    private function operationOrders(): array
    {
        $orders=$this->rows("SELECT o.id,o.public_code,o.status,o.total_amount,o.order_source,o.created_at,o.customer_note order_note,t.name table_name FROM orders o LEFT JOIN cafe_tables t ON t.id=o.table_id WHERE o.status NOT IN ('cancelled','settled') ORDER BY o.id DESC LIMIT 100");
        if($orders===[])return [];
        $ids=array_values(array_filter(array_map(static fn(array $row):int=>(int)($row['id']??0),$orders),static fn(int $id):bool=>$id>0));
        if($ids===[])return $orders;
        $ph=implode(',',array_fill(0,count($ids),'?'));
        $q=$this->pdo->prepare("SELECT oi.id order_item_id,oi.order_id,oi.item_id,oi.item_name,oi.unit_price,oi.quantity,oi.line_total,oi.item_note,oi.fulfillment_mode FROM order_items oi WHERE oi.order_id IN($ph) AND oi.quantity>0 ORDER BY oi.order_id,oi.id");
        $q->execute($ids);$byOrder=[];
        foreach($q->fetchAll(PDO::FETCH_ASSOC)?:[] as $line){
            $byOrder[(int)$line['order_id']][]=[
                'order_item_id'=>(int)$line['order_item_id'],'item_id'=>$line['item_id']!==null?(int)$line['item_id']:null,
                'item_name'=>(string)$line['item_name'],'unit_price'=>(int)$line['unit_price'],'quantity'=>(int)$line['quantity'],
                'line_total'=>(int)$line['line_total'],'item_note'=>(string)($line['item_note']??''),
                'fulfillment_mode'=>(string)($line['fulfillment_mode']??'dine_in'),
            ];
        }
        foreach($orders as &$order){
            $order['id']=(int)$order['id'];$order['total_amount']=(int)$order['total_amount'];
            $order['order_note']=(string)($order['order_note']??'');$order['items']=$byOrder[(int)$order['id']]??[];
        }unset($order);
        return $orders;
    }

    private function settlementAccounts(): array
    {
        if($this->settlements===null)return [];
        // Only sessions that are presently settleable belong in the finance projection.
        // A pending Guest order is a normal operational state, not a projection failure:
        // Hall/order projections keep showing it, while settlement stays unavailable until
        // staff accepts/rejects the pending order. Other finance inconsistencies still throw
        // so Public never receives a silently partial financial account.
        $ids=$this->pdo->query("SELECT s.id FROM table_sessions s WHERE s.status IN ('active','pending') AND EXISTS(SELECT 1 FROM orders oa WHERE oa.session_id=s.id AND oa.status='accounted') AND NOT EXISTS(SELECT 1 FROM orders op WHERE op.session_id=s.id AND op.status IN('pending_approval','new')) ORDER BY s.id DESC LIMIT 50")->fetchAll(PDO::FETCH_COLUMN)?:[];$out=[];
        foreach($ids as $id){
            // A partial finance projection is more dangerous than a delayed projection: the
            // Staff UI may interpret an omitted live account as an empty/missing account.
            // Let the event/full sync retry and keep Public's previous coherent model.
            $a=$this->settlements->account((int)$id);
            $out[]=['session_id'=>(int)($a['session']['id']??$id),'table_name'=>(string)($a['session']['table_name']??''),'remaining_total'=>(int)($a['remaining_total']??0),'signature'=>(string)($a['signature']??''),'account'=>$a];
        }
        return $out;
    }
    private function model(string $key,array $payload,string $at): array{$payload=\Sokna\Shared\IranTime::transport($payload);return ['format'=>'sokna-remote-read-v1','model_key'=>$key,'source_version'=>self::hash($payload),'generated_at'=>$at,'payload'=>$payload];}
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
