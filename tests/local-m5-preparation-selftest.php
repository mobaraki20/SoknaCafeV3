<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/apps/local-web/bootstrap.php';

use Sokna\Local\Domain\Preparation\PreparationException;

function m54_fail(string $message): never { fwrite(STDERR,$message.PHP_EOL); exit(1); }
function m54_assert(bool $condition,string $message): void { if(!$condition)m54_fail($message); }

$core=sokna_local_bootstrap([
    'app'=>['timezone'=>'Asia/Tehran','data_dir'=>sys_get_temp_dir().'/sokna-v3-m54-'.bin2hex(random_bytes(4))],
    'db'=>[
        'host'=>(string)(getenv('SOKNA_TEST_DB_HOST')?:'127.0.0.1'),
        'port'=>(string)(getenv('SOKNA_TEST_DB_PORT')?:'3306'),
        'name'=>(string)(getenv('SOKNA_TEST_DB_NAME')?:'sokna_m2'),
        'charset'=>'utf8mb4',
        'user'=>(string)(getenv('SOKNA_TEST_DB_USER')?:'sokna'),
        'pass'=>(string)(getenv('SOKNA_TEST_DB_PASS')?:'sokna'),
    ],
]);
$core->migrations()->migrate();
$pdo=$core->database();

$tables=array_map('strval',$pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
foreach(['user_preparation_areas','order_preparation_claims'] as $table)
    m54_assert(in_array($table,$tables,true),"missing {$table}");
foreach(['preparation_adjustments','financial_periods','settlement_records','print_jobs'] as $later)
    m54_assert(!in_array($later,$tables,true),"M5.4 leaked dependency/later-domain table {$later}");

$makeUser=function(string $name,string $role,array $caps,array $areas)use($pdo):array{
    $pdo->prepare('INSERT INTO users(username,password_hash,display_name,role,active) VALUES(?,?,?,?,1)')
        ->execute([$name,password_hash('x',PASSWORD_DEFAULT),$name,$role]);
    $id=(int)$pdo->lastInsertId();
    $cap=$pdo->prepare('INSERT INTO user_capabilities(user_id,capability,enabled) VALUES(?,?,1)');
    foreach($caps as $c)$cap->execute([$id,$c]);
    $area=$pdo->prepare('INSERT INTO user_preparation_areas(user_id,area_key) VALUES(?,?)');
    foreach($areas as $a)$area->execute([$id,$a]);
    return ['id'=>$id,'username'=>$name,'display_name'=>$name,'role'=>$role,'active'=>1];
};

$prep=$makeUser('m54-prep','operator',['preparation'],['kitchen']);
$super=$makeUser('m54-super','operator',['shift_supervision'],[]);
$both=$makeUser('m54-both','operator',['preparation','shift_supervision'],['kitchen']);
$admin=$makeUser('m54-admin','admin',[],[]);
$barPrep=$makeUser('m54-bar','operator',['preparation'],['bar']);
$creator=$makeUser('m54-floor','operator',['orders_floor'],[]);

$ctx=$core->preparationAccess()->context($prep);
m54_assert($ctx['visible_areas']===['kitchen']&&$ctx['actionable_areas']===['kitchen']&&$ctx['can_mutate']===true,'Preparation-only matrix drifted');
$ctx=$core->preparationAccess()->context($super);
m54_assert($ctx['visible_areas']===['kitchen','bar']&&$ctx['actionable_areas']===[]&&$ctx['monitor_only']===true,'Supervisor must be global read-only');
$ctx=$core->preparationAccess()->context($both);
m54_assert($ctx['visible_areas']===['kitchen','bar']&&$ctx['actionable_areas']===['kitchen'],'Supervisor+Preparation must mutate assigned area only');
$ctx=$core->preparationAccess()->context($admin);
m54_assert($ctx['visible_areas']===['kitchen','bar']&&$ctx['actionable_areas']===[]&&$ctx['monitor_only']===true,'Admin role alone gained Preparation mutation');
m54_assert($core->preparationAccess()->canMutateArea('kitchen',$both)===true,'assigned area mutation denied');
m54_assert($core->preparationAccess()->canMutateArea('bar',$both)===false,'unassigned visible area became actionable');
m54_assert($core->preparationAccess()->canMutateArea('bogus',$both)===false,'invalid area normalized into authorization');

$badAreaRejected=false;
try{$pdo->prepare('INSERT INTO user_preparation_areas(user_id,area_key) VALUES(?,?)')->execute([$prep['id'],'bogus']);}
catch(PDOException){$badAreaRejected=true;}
m54_assert($badAreaRejected,'database accepted preparation area outside kitchen/bar');

$pdo->exec("INSERT INTO categories(category_key,name,audience,sort_order,active) VALUES('m54','M54','guest_staff',10,1)");
$categoryId=(int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO menus(menu_key,name,status,sort_order) VALUES('m54-main','M54 Main','active',10)");
$menuId=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO menu_categories(menu_id,category_id,sort_order) VALUES(?,?,10)')->execute([$menuId,$categoryId]);

$kitchenId=$core->sellables()->create([
    'category_id'=>$categoryId,'name'=>'Kitchen item','price'=>100000,'preparation_station'=>'kitchen',
    'sellable_kind'=>'menu_item','staff_only'=>false,'takeaway_allowed'=>true,
]);
$barId=$core->sellables()->create([
    'category_id'=>$categoryId,'name'=>'Bar item','price'=>80000,'preparation_station'=>'cold_bar',
    'sellable_kind'=>'menu_item','staff_only'=>false,'takeaway_allowed'=>true,
]);
$serviceId=$core->sellables()->create([
    'category_id'=>$categoryId,'name'=>'No-prep service','price'=>20000,'preparation_station'=>'none',
    'sellable_kind'=>'service_item','staff_only'=>false,'takeaway_allowed'=>true,
]);
$pdo->prepare('INSERT INTO menu_items(menu_id,item_id) VALUES(?,?),(?,?),(?,?)')
    ->execute([$menuId,$kitchenId,$menuId,$barId,$menuId,$serviceId]);

$pdo->exec("INSERT INTO cafe_tables(name,table_number,code,access_token,active,sort_order) VALUES('Prep Table',21,'P21','m54-prep-table',1,1)");
$tableId=(int)$pdo->lastInsertId();
$order=$core->staffQuickOrders()->commit([
    'table_id'=>$tableId,'expected_session_id'=>0,'request_token'=>'m54-order-token-0001',
    'items'=>[
        ['id'=>$kitchenId,'quantity'=>2,'expected_price'=>100000],
        ['id'=>$barId,'quantity'=>1,'expected_price'=>80000],
        ['id'=>$serviceId,'quantity'=>1,'expected_price'=>20000],
    ],
],$creator);
$orderId=(int)$order['order_id'];
m54_assert($orderId>0,'Preparation fixture order was not committed');

$claimsBefore=(int)$pdo->query('SELECT COUNT(*) FROM order_preparation_claims')->fetchColumn();
$prepFeed=$core->preparation()->feed($prep);
$superFeed=$core->preparation()->feed($super);
$bothFeed=$core->preparation()->feed($both);
$adminFeed=$core->preparation()->feed($admin);
$claimsAfterFeed=(int)$pdo->query('SELECT COUNT(*) FROM order_preparation_claims')->fetchColumn();
m54_assert($claimsBefore===$claimsAfterFeed,'Preparation feed mutated claim state');
m54_assert(($prepFeed['permissions']['visible_preparation_areas']??[])===['kitchen'],'Preparation-only feed leaked unassigned area');
m54_assert(count($prepFeed['orders'])===1&&count($prepFeed['orders'][0]['areas'])===1&&$prepFeed['orders'][0]['areas'][0]['area']==='kitchen','Preparation-only feed shape drifted');
m54_assert(($superFeed['permissions']['visible_preparation_areas']??[])===['kitchen','bar']&&($superFeed['permissions']['actionable_preparation_areas']??[])===[],'Supervisor feed is not global read-only');
m54_assert(count($superFeed['orders'][0]['areas'])===2,'No-prep service leaked into Preparation queue or an operational area disappeared');
m54_assert(($bothFeed['permissions']['actionable_preparation_areas']??[])===['kitchen'],'Combined capability feed broadened mutation scope');
m54_assert(($adminFeed['permissions']['actionable_preparation_areas']??[])===[],'Admin feed exposed operational action');

$first=$core->preparation()->claim(['order_id'=>$orderId,'area'=>'kitchen'],$prep);
m54_assert($first['duplicate']===false&&(int)$first['claimed_by_user_id']===(int)$prep['id'],'Preparation claim failed');
$retry=$core->preparation()->claim(['order_id'=>$orderId,'area'=>'kitchen'],$prep);
m54_assert($retry['duplicate']===true,'Same-user claim retry was not idempotent');

$conflict=false;
try{$core->preparation()->claim(['order_id'=>$orderId,'area'=>'kitchen'],$both);}
catch(PreparationException $e){$conflict=$e->errorCode==='already_claimed';}
m54_assert($conflict,'Same signature was silently stolen from another Preparation user');

$superDenied=false;
try{$core->preparation()->claim(['order_id'=>$orderId,'area'=>'bar'],$super);}
catch(PreparationException $e){$superDenied=$e->errorCode==='forbidden_area';}
m54_assert($superDenied,'Supervisor-only user gained Preparation mutation');

$adminDenied=false;
try{$core->preparation()->claim(['order_id'=>$orderId,'area'=>'bar'],$admin);}
catch(PreparationException $e){$adminDenied=$e->errorCode==='forbidden_area';}
m54_assert($adminDenied,'Admin role alone gained Preparation mutation');

$invalidDenied=false;
try{$core->preparation()->claim(['order_id'=>$orderId,'area'=>'not-real'],$prep);}
catch(PreparationException $e){$invalidDenied=$e->errorCode==='invalid_area';}
m54_assert($invalidDenied,'Invalid area was normalized into a real Preparation area');

$remote=$core->preparationRealtime()->dispatch([
    'kind'=>'preparation.mutate',
    'actor_projection_id'=>'user:'.$barPrep['id'],
    'payload'=>['action'=>'claim_order_area','order_id'=>$orderId,'area'=>'bar'],
]);
m54_assert($remote['duplicate']===false&&(int)$remote['claimed_by_user_id']===(int)$barPrep['id'],'Realtime Preparation mutation did not bind to Local claim owner');

$pdo->prepare('UPDATE users SET active=0 WHERE id=?')->execute([$barPrep['id']]);
$remoteDisabled=false;
try{
    $core->preparationRealtime()->dispatch([
        'kind'=>'preparation.mutate',
        'actor_projection_id'=>'user:'.$barPrep['id'],
        'payload'=>['action'=>'claim_order_area','order_id'=>$orderId,'area'=>'bar'],
    ]);
}catch(PreparationException $e){$remoteDisabled=$e->errorCode==='actor_invalid';}
m54_assert($remoteDisabled,'Realtime Preparation adapter did not revalidate disabled Local actor');

$auditCount=(int)$pdo->query("SELECT COUNT(*) FROM audit_log WHERE action='preparation.claimed'")->fetchColumn();
m54_assert($auditCount===2,'Preparation successful claims were not audited exactly once each');

$publicRealtime=(string)file_get_contents(dirname(__DIR__).'/apps/public/src/Realtime/RealtimeService.php');
$publicDeferred=(string)file_get_contents(dirname(__DIR__).'/apps/public/src/Deferred/DeferredService.php');
m54_assert(str_contains($publicRealtime,"'preparation.mutate' => 'preparation.mutate'"),'Public Realtime lost projected Preparation mutation capability');
m54_assert(!str_contains($publicDeferred,'preparation.mutate'),'Preparation mutation leaked into Deferred-safe transport');

$migration=(string)file_get_contents(dirname(__DIR__).'/apps/local-web/database/migrations/0005_m5_preparation.sql');
m54_assert(!str_contains($migration,'preparation_adjustments'),'M5.4 pulled adjustment producer/state forward before its dependency');
m54_assert(!preg_match('/inventory|financial|settlement|print_/i',$migration),'M5.4 migration leaked later-domain ownership');

fwrite(STDOUT,"Local M5.4 Preparation permission/action self-test: OK\n");
