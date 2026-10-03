<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/apps/local-web/bootstrap.php';

use Sokna\Local\Domain\Inventory\InventoryException;
use Sokna\Local\Domain\Inventory\InventoryStateConflict;

function m55_fail(string $message): never { fwrite(STDERR,$message.PHP_EOL); exit(1); }
function m55_assert(bool $condition,string $message): void { if(!$condition)m55_fail($message); }

$core=sokna_local_bootstrap([
    'app'=>['timezone'=>'Asia/Tehran','data_dir'=>sys_get_temp_dir().'/sokna-v3-m55-'.bin2hex(random_bytes(4))],
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
foreach([
    'inventory_categories','inventory_items','inventory_purchase_units','inventory_balances','inventory_movements',
    'inventory_recipe_versions','inventory_recipe_components','inventory_count_sessions','inventory_count_lines','inventory_order_events'
] as $table)m55_assert(in_array($table,$tables,true),"missing {$table}");
foreach([] as $later)
    m55_assert(!in_array($later,$tables,true),"M5.5 leaked later/dependent owner {$later}");

$makeUser=function(string $name,array $caps)use($pdo):array{
    $pdo->prepare('INSERT INTO users(username,password_hash,display_name,role,active) VALUES(?,?,?,?,1)')
        ->execute([$name,password_hash('x',PASSWORD_DEFAULT),$name,'operator']);
    $id=(int)$pdo->lastInsertId();
    $stmt=$pdo->prepare('INSERT INTO user_capabilities(user_id,capability,enabled) VALUES(?,?,1)');
    foreach($caps as $cap)$stmt->execute([$id,$cap]);
    return ['id'=>$id,'username'=>$name,'display_name'=>$name,'role'=>'operator','active'=>1];
};
$operator=$makeUser('m55-operator',['orders_floor','inventory_view','inventory_operations','inventory_manage']);
$finalizer=$makeUser('m55-finalizer',['inventory_view','inventory_finalize']);
$viewer=$makeUser('m55-viewer',['inventory_view']);

$item1=$core->inventory()->createItem([
    'item_code'=>'M55-OPEN','name'=>'Opening Item','category'=>'ingredient',
    'base_unit'=>'count','default_department'=>'kitchen',
],$operator);
$item1Id=(int)$item1['id'];

$opening=$core->inventoryCounts()->start([
    'title'=>'Opening','session_type'=>'opening','scope_type'=>'full',
],$operator);
$openingId=(int)$opening['session_id'];
$line=(array)$pdo->query("SELECT id,updated_at FROM inventory_count_lines WHERE session_id={$openingId} AND inventory_item_id={$item1Id}")->fetch(PDO::FETCH_ASSOC);
m55_assert((int)($line['id']??0)>0,'opening count line missing');
$core->inventoryCounts()->updateLine([
    'session_id'=>$openingId,'line_id'=>(int)$line['id'],'expected_version'=>(string)$line['updated_at'],
    'actual_major'=>'10','opening_total_cost'=>'1000','note'=>'baseline',
],$operator);

$opsFinalizeDenied=false;
try{$core->inventoryCounts()->finalize($openingId,$operator);}
catch(InventoryException $e){$opsFinalizeDenied=$e->errorCode==='forbidden';}
m55_assert($opsFinalizeDenied,'inventory_operations user finalized without inventory_finalize');

$openingFinal=$core->inventoryCounts()->finalize($openingId,$finalizer);
m55_assert($openingFinal['movements']===1&&$openingFinal['differences']===1,'opening count did not create canonical movement');
$init=(string)$pdo->query("SELECT setting_value FROM settings WHERE setting_key='inventory_initialized'")->fetchColumn();
m55_assert($init==='1','opening finalize did not initialize Inventory');
$bal1=(array)$pdo->query("SELECT * FROM inventory_balances WHERE inventory_item_id={$item1Id}")->fetch(PDO::FETCH_ASSOC);
m55_assert((int)$bal1['quantity_base']===10,'opening balance quantity wrong');
m55_assert(abs((float)$bal1['average_unit_cost']-100.0)<0.0001,'opening average cost wrong');

$item2=$core->inventory()->createItem([
    'item_code'=>'M55-CHRONO','name'=>'Chronology Item','category'=>'ingredient',
    'base_unit'=>'count','default_department'=>'bar',
],$operator);
$item2Id=(int)$item2['id'];

$r1=$core->inventory()->recordMovement([
    'item_id'=>$item2Id,'movement_type'=>'purchase_receive','quantity_base'=>10,'total_cost_delta'=>1000,
    'idempotency_key'=>'m55:receive:1','occurred_at'=>'2026-09-20 10:00:00',
],$operator);
$core->inventory()->recordMovement([
    'item_id'=>$item2Id,'movement_type'=>'waste','quantity_base'=>-2,
    'idempotency_key'=>'m55:waste:1','occurred_at'=>'2026-09-22 10:00:00',
],$operator);
$r2=$core->inventory()->recordMovement([
    'item_id'=>$item2Id,'movement_type'=>'purchase_receive','quantity_base'=>10,'total_cost_delta'=>3000,
    'idempotency_key'=>'m55:receive:2','occurred_at'=>'2026-09-21 10:00:00',
],$operator);
$chrono=(array)$pdo->query("SELECT * FROM inventory_balances WHERE inventory_item_id={$item2Id}")->fetch(PDO::FETCH_ASSOC);
m55_assert((int)$chrono['quantity_base']===18,'backdated movement replay produced wrong quantity');
m55_assert(abs((float)$chrono['average_unit_cost']-200.0)<0.0001,'backdated movement replay produced wrong moving average');

$pdo->exec("INSERT INTO categories(category_key,name,audience,sort_order,active) VALUES('m55-menu','M55 Menu','guest_staff',10,1)");
$menuCategoryId=(int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO menus(menu_key,name,status,sort_order) VALUES('m55-main','M55 Main','active',10)");
$menuId=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO menu_categories(menu_id,category_id,sort_order) VALUES(?,?,10)')->execute([$menuId,$menuCategoryId]);
$menuItemId=$core->sellables()->create([
    'category_id'=>$menuCategoryId,'name'=>'Recipe Drink','price'=>50000,'preparation_station'=>'kitchen',
    'sellable_kind'=>'menu_item','staff_only'=>false,'takeaway_allowed'=>true,
]);
$pdo->prepare('INSERT INTO menu_items(menu_id,item_id) VALUES(?,?)')->execute([$menuId,$menuItemId]);
$recipe=$core->inventoryOrders()->saveRecipe($menuItemId,[
    ['inventory_item_id'=>$item1Id,'quantity_base'=>2],
],$operator);
m55_assert(!empty($recipe['recipe_id']),'Inventory recipe version was not created');

$beforeOrderQty=(int)$pdo->query("SELECT quantity_base FROM inventory_balances WHERE inventory_item_id={$item1Id}")->fetchColumn();
$pdo->exec("INSERT INTO cafe_tables(name,table_number,code,access_token,active,sort_order) VALUES('Inventory Table',31,'I31','m55-inventory-table',1,1)");
$orderTable=(int)$pdo->lastInsertId();
$order=$core->staffQuickOrders()->commit([
    'table_id'=>$orderTable,'expected_session_id'=>0,'request_token'=>'m55-order-inventory-0001',
    'items'=>[['id'=>$menuItemId,'quantity'=>2,'expected_price'=>50000]],
],$operator);
$orderId=(int)$order['order_id'];
m55_assert($orderId>0,'Inventory fixture order did not commit');
$event=(array)$pdo->query("SELECT id,status,attempt_count FROM inventory_order_events WHERE order_id={$orderId}")->fetch(PDO::FETCH_ASSOC);
m55_assert((int)($event['id']??0)>0&&(string)($event['status']??'')==='done','accounted Order did not produce/process durable Inventory event');
$recipeMoves=(int)$pdo->query("SELECT COUNT(*) FROM inventory_movements WHERE source_type='order_item' AND metadata_json LIKE '%\"order_id\":{$orderId}%'")->fetchColumn();
m55_assert($recipeMoves===1,'accounted Order produced duplicate/missing recipe movement');
$afterOrderQty=(int)$pdo->query("SELECT quantity_base FROM inventory_balances WHERE inventory_item_id={$item1Id}")->fetchColumn();
m55_assert($afterOrderQty===$beforeOrderQty-4,'recipe consumption did not use order quantity × recipe snapshot');

$orderRetry=$core->staffQuickOrders()->commit([
    'table_id'=>$orderTable,'expected_session_id'=>(int)$order['session_id'],'request_token'=>'m55-order-inventory-0001',
    'items'=>[['id'=>$menuItemId,'quantity'=>2,'expected_price'=>50000]],
],$operator);
m55_assert(!empty($orderRetry['duplicate'])&&(int)$orderRetry['order_id']===$orderId,'Order retry lost canonical idempotency');
$recipeMovesAfterRetry=(int)$pdo->query("SELECT COUNT(*) FROM inventory_movements WHERE source_type='order_item' AND metadata_json LIKE '%\"order_id\":{$orderId}%'")->fetchColumn();
m55_assert($recipeMovesAfterRetry===1,'Order retry duplicated Inventory recipe consumption');

$movementCountBefore=(int)$pdo->query("SELECT COUNT(*) FROM inventory_movements WHERE inventory_item_id={$item2Id}")->fetchColumn();
$duplicate=$core->inventory()->recordMovement([
    'item_id'=>$item2Id,'movement_type'=>'purchase_receive','quantity_base'=>10,'total_cost_delta'=>3000,
    'idempotency_key'=>'m55:receive:2','occurred_at'=>'2026-09-21 10:00:00',
],$operator);
$movementCountAfter=(int)$pdo->query("SELECT COUNT(*) FROM inventory_movements WHERE inventory_item_id={$item2Id}")->fetchColumn();
m55_assert((int)$duplicate['movement_id']===(int)$r2['movement_id']&&$movementCountBefore===$movementCountAfter,'movement idempotency duplicated stock effect');

$periodic=$core->inventoryCounts()->start(['title'=>'Periodic','session_type'=>'periodic','scope_type'=>'full'],$operator);
$periodicId=(int)$periodic['session_id'];
$lines=$pdo->query("SELECT id,inventory_item_id,updated_at FROM inventory_count_lines WHERE session_id={$periodicId} ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
m55_assert(count($lines)===2,'periodic count did not snapshot active inventory catalogue');
$targetLine=null;
foreach($lines as $row){
    $item=(int)$row['inventory_item_id'];
    $balance=(int)$pdo->query("SELECT quantity_base FROM inventory_balances WHERE inventory_item_id={$item}")->fetchColumn();
    $actual=$item===$item2Id?$balance-1:$balance;
    $updated=$core->inventoryCounts()->updateLine([
        'session_id'=>$periodicId,'line_id'=>(int)$row['id'],'expected_version'=>(string)$row['updated_at'],
        'actual_major'=>(string)$actual,'note'=>$item===$item2Id?'variance':'same',
    ],$operator);
    if($item===$item2Id)$targetLine=['initial_version'=>(string)$row['updated_at'],'fresh'=>$updated,'actual'=>$actual];
}
m55_assert(is_array($targetLine),'periodic target line missing');
m55_assert((int)$targetLine['fresh']['system_quantity_snapshot']===18,'periodic line did not capture stock at first count');

$stale=false;
try{
    $core->inventoryCounts()->updateLine([
        'session_id'=>$periodicId,'line_id'=>(int)$targetLine['fresh']['line_id'],
        'expected_version'=>$targetLine['initial_version'],'actual_major'=>'16','note'=>'stale',
    ],$operator);
}catch(InventoryStateConflict $e){$stale=$e->errorCode==='count_line_changed';}
m55_assert($stale,'stale count writer overwrote a newer line');

$core->inventory()->recordMovement([
    'item_id'=>$item2Id,'movement_type'=>'waste','quantity_base'=>-1,
    'idempotency_key'=>'m55:waste:after-count','occurred_at'=>'2026-09-23 10:00:00',
],$operator);
$periodicFinal=$core->inventoryCounts()->finalize($periodicId,$finalizer);
m55_assert($periodicFinal['differences']===1,'periodic count did not persist exactly one variance');
$afterPeriodic=(int)$pdo->query("SELECT quantity_base FROM inventory_balances WHERE inventory_item_id={$item2Id}")->fetchColumn();
m55_assert($afterPeriodic===16,'periodic finalize ignored count-time snapshot semantics');

$balBefore=(array)$pdo->query("SELECT quantity_base,updated_at FROM inventory_balances WHERE inventory_item_id={$item1Id}")->fetch(PDO::FETCH_ASSOC);
$wasteEnvelope=[
    'request_id'=>'m55-deferred-waste-1','kind'=>'inventory.waste',
    'created_at'=>date(DATE_ATOM),'occurred_at'=>date(DATE_ATOM),
    'actor_projection_id'=>'user:'.$operator['id'],
    'expected_version'=>(string)$balBefore['updated_at'],
    'payload'=>[
        'inventory_item_id'=>$item1Id,'quantity_major'=>'1','department'=>'kitchen','note'=>'remote waste',
    ],
];
$wasteResult=$core->inventoryDeferred()->dispatch($wasteEnvelope);
m55_assert($wasteResult['state']==='committed'&&empty($wasteResult['idempotent']),'deferred waste did not commit');
$wasteRetry=$core->inventoryDeferred()->dispatch($wasteEnvelope);
m55_assert($wasteRetry['state']==='committed'&&!empty($wasteRetry['idempotent']),'deferred waste retry was not idempotent');
$wasteMoves=(int)$pdo->query("SELECT COUNT(*) FROM inventory_movements WHERE idempotency_key='deferred:waste:m55-deferred-waste-1'")->fetchColumn();
m55_assert($wasteMoves===1,'deferred waste created more than one movement');

$staleWaste=$wasteEnvelope;
$staleWaste['request_id']='m55-deferred-waste-2';
$staleWasteResult=$core->inventoryDeferred()->dispatch($staleWaste);
m55_assert($staleWasteResult['state']==='needs_review'&&$staleWasteResult['error_code']==='inventory_balance_changed','stale deferred waste did not become needs_review');
$staleMoves=(int)$pdo->query("SELECT COUNT(*) FROM inventory_movements WHERE idempotency_key='deferred:waste:m55-deferred-waste-2'")->fetchColumn();
m55_assert($staleMoves===0,'stale deferred waste mutated stock before review');

$count2=$core->inventoryCounts()->start(['title'=>'Remote count','session_type'=>'periodic'],$operator);
$count2Id=(int)$count2['session_id'];
$remoteLine=(array)$pdo->query("SELECT id,updated_at FROM inventory_count_lines WHERE session_id={$count2Id} AND inventory_item_id={$item1Id}")->fetch(PDO::FETCH_ASSOC);
$currentQty=(int)$pdo->query("SELECT quantity_base FROM inventory_balances WHERE inventory_item_id={$item1Id}")->fetchColumn();
$countEnvelope=[
    'request_id'=>'m55-deferred-count-1','kind'=>'inventory.count_draft',
    'created_at'=>date(DATE_ATOM),'occurred_at'=>date(DATE_ATOM),
    'actor_projection_id'=>'user:'.$operator['id'],
    'expected_version'=>(string)$remoteLine['updated_at'],
    'payload'=>[
        'session_id'=>$count2Id,'line_id'=>(int)$remoteLine['id'],'actual_major'=>(string)$currentQty,'note'=>'remote count',
    ],
];
$countResult=$core->inventoryDeferred()->dispatch($countEnvelope);
m55_assert($countResult['state']==='committed','deferred count draft did not commit');
$countRetry=$core->inventoryDeferred()->dispatch($countEnvelope);
m55_assert($countRetry['state']==='committed'&&!empty($countRetry['idempotent']),'deferred count retry was not desired-state idempotent');

$countConflict=$countEnvelope;
$countConflict['request_id']='m55-deferred-count-2';
$countConflict['payload']['actual_major']=(string)max(0,$currentQty-1);
$countConflictResult=$core->inventoryDeferred()->dispatch($countConflict);
m55_assert($countConflictResult['state']==='needs_review'&&$countConflictResult['error_code']==='count_line_changed','stale deferred count edit did not become needs_review');

$denied=$wasteEnvelope;
$denied['request_id']='m55-deferred-denied';
$denied['actor_projection_id']='user:'.$viewer['id'];
$deniedResult=$core->inventoryDeferred()->dispatch($denied);
m55_assert($deniedResult['state']==='rejected'&&$deniedResult['error_code']==='permission_denied','Local deferred actor capability was not revalidated');

$publicDeferred=(string)file_get_contents(dirname(__DIR__).'/apps/public/src/Deferred/DeferredService.php');
m55_assert(str_contains($publicDeferred,"'inventory.waste' => 'inventory.waste.defer'"),'Public Deferred lost inventory.waste capability');
m55_assert(str_contains($publicDeferred,"'inventory.count_draft' => 'inventory.count_draft.defer'"),'Public Deferred lost inventory.count_draft capability');
m55_assert(!str_contains($publicDeferred,'inventory.count_finalize'),'Local-only count finalize leaked into Deferred-safe transport');

$migration=(string)file_get_contents(dirname(__DIR__).'/apps/local-web/database/migrations/0006_m5_inventory.sql');
m55_assert(!preg_match('/CREATE TABLE IF NOT EXISTS\s+inventory_supply_/i',$migration),'Inventory migration pulled Supply tables forward');
m55_assert(!preg_match('/CREATE TABLE IF NOT EXISTS\s+(financial|settlement|print_)/i',$migration),'Inventory migration pulled Finance/Print ownership forward');

fwrite(STDOUT,"Local M5.5 Inventory core/count/deferred self-test: OK\n");
