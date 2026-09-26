<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/apps/local-web/bootstrap.php';

use Sokna\Local\Domain\Supply\SupplyException;
use Sokna\Local\Domain\Supply\SupplyStateConflict;

function m56_fail(string $message): never { fwrite(STDERR,$message.PHP_EOL); exit(1); }
function m56_assert(bool $condition,string $message): void { if(!$condition)m56_fail($message); }

$core=sokna_local_bootstrap([
    'app'=>['timezone'=>'Asia/Tehran','data_dir'=>sys_get_temp_dir().'/sokna-v3-m56-'.bin2hex(random_bytes(4))],
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
foreach(['inventory_supply_needs','inventory_supply_receipts','inventory_supply_receipt_allocations','deferred_work_receipts','deferred_review_items'] as $table)
    m56_assert(in_array($table,$tables,true),"missing {$table}");
foreach(['print_jobs'] as $later)
    m56_assert(!in_array($later,$tables,true),"M5.6 leaked later-domain owner {$later}");

$setting=$pdo->prepare('INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
foreach(['module.inventory.enabled'=>'1','module.supply.enabled'=>'1','inventory_initialized'=>'1','inventory_reconciliation_required'=>'0'] as $k=>$v)$setting->execute([$k,$v]);

$makeUser=function(string $name,string $role,array $caps,array $areas=[])use($pdo):array{
    $pdo->prepare('INSERT INTO users(username,password_hash,display_name,role,active) VALUES(?,?,?,?,1)')
        ->execute([$name,password_hash('x',PASSWORD_DEFAULT),$name,$role]);
    $id=(int)$pdo->lastInsertId();
    $cap=$pdo->prepare('INSERT INTO user_capabilities(user_id,capability,enabled) VALUES(?,?,1)');
    foreach($caps as $c)$cap->execute([$id,$c]);
    $area=$pdo->prepare('INSERT INTO user_preparation_areas(user_id,area_key) VALUES(?,?)');
    foreach($areas as $a)$area->execute([$id,$a]);
    return ['id'=>$id,'username'=>$name,'display_name'=>$name,'role'=>$role,'active'=>1];
};
$buyer=$makeUser('m56-buyer','operator',['inventory_operations','inventory_manage']);
$prep=$makeUser('m56-prep','operator',['preparation'],['kitchen']);
$super=$makeUser('m56-super','operator',['shift_supervision']);
$admin=$makeUser('m56-admin','admin',[]);
$viewer=$makeUser('m56-viewer','operator',['inventory_view']);

m56_assert($core->supplyAccess()->canReportNeeds($prep),'Preparation user cannot report needs');
m56_assert($core->supplyAccess()->allowedNeedDepartments($prep)===['kitchen'],'Preparation reporter escaped assigned area');
m56_assert($core->supplyAccess()->allowedNeedDepartments($super)===['kitchen','bar','shared'],'Supervisor did not receive global reporting scope');
m56_assert($core->supplyAccess()->canManagePurchases($prep)===false,'Preparation-only user gained purchase authority');
m56_assert($core->supplyAccess()->canManagePurchases($buyer)===true,'Inventory operator lost purchase authority');

$item=$core->inventory()->createItem([
    'item_code'=>'M56-ITEM','name'=>'Supply Item','category'=>'ingredient','base_unit'=>'count','default_department'=>'kitchen',
],$buyer);
$itemId=(int)$item['id'];

$need=$core->supply()->upsertNeed([
    'inventory_item_id'=>$itemId,'department'=>'kitchen','quantity_major'=>'10','source'=>'staff','note'=>'first',
],$prep);
$needId=(int)$need['need_id'];
m56_assert($needId>0,'Supply need was not created');

$forbiddenDept=false;
try{$core->supply()->upsertNeed([
    'inventory_item_id'=>$itemId,'department'=>'shared','quantity_major'=>'1',
],$prep);}catch(SupplyException $e){$forbiddenDept=$e->errorCode==='forbidden_department';}
m56_assert($forbiddenDept,'Preparation reporter wrote an unassigned Supply department');

$prepared=$core->supply()->prepare('item:'.$itemId,10,$buyer);
m56_assert((int)$prepared['quantity_base']===10,'Supply prepare did not freeze uncommitted quantity');
$beforeReturnBalance=(int)$pdo->query("SELECT quantity_base FROM inventory_balances WHERE inventory_item_id={$itemId}")->fetchColumn();
$returned=$core->supply()->returnPreparing('item:'.$itemId,'returned',10,$buyer);
$afterReturnBalance=(int)$pdo->query("SELECT quantity_base FROM inventory_balances WHERE inventory_item_id={$itemId}")->fetchColumn();
m56_assert((int)$returned['quantity_base']===10&&$beforeReturnBalance===$afterReturnBalance,'Return from Preparing mutated Inventory');

$core->supply()->prepare('item:'.$itemId,10,$buyer);
$core->supply()->upsertNeed([
    'inventory_item_id'=>$itemId,'department'=>'kitchen','quantity_major'=>'5','note'=>'late demand',
],$prep);
$row=(array)$pdo->query("SELECT * FROM inventory_supply_needs WHERE id={$needId}")->fetch(PDO::FETCH_ASSOC);
m56_assert((int)$row['requested_quantity_base']===15&&(int)$row['preparing_quantity_base']===10,'Interactive need edit changed buyer-committed quantity');

$receipt1=$core->supply()->receive('item:'.$itemId,[
    'request_token'=>'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    'expected_preparing_quantity_base'=>10,
    'unit_count'=>'6','total_cost'=>'600','supplier'=>'A',
    'occurred_at'=>'2026-09-25 10:00:00',
],$buyer);
m56_assert((int)$receipt1['received_quantity_base']===6&&(int)$receipt1['preparing_remaining_base']===4&&(int)$receipt1['uncommitted_remaining_base']===5,'Partial receive allocation drifted');
$balance=(int)$pdo->query("SELECT quantity_base FROM inventory_balances WHERE inventory_item_id={$itemId}")->fetchColumn();
m56_assert($balance===6,'Physical receipt did not increase Inventory exactly once');

$movementCount=(int)$pdo->query("SELECT COUNT(*) FROM inventory_movements WHERE idempotency_key='supply:receive:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'")->fetchColumn();
$retry=$core->supply()->receive('item:'.$itemId,[
    'request_token'=>'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    'expected_preparing_quantity_base'=>999,
    'unit_count'=>'99','total_cost'=>'9999',
],$buyer);
$movementCount2=(int)$pdo->query("SELECT COUNT(*) FROM inventory_movements WHERE idempotency_key='supply:receive:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'")->fetchColumn();
m56_assert(!empty($retry['duplicate'])&&$movementCount===1&&$movementCount2===1,'Receipt retry created a second Inventory movement');

$receipt2=$core->supply()->receive('item:'.$itemId,[
    'request_token'=>'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
    'expected_preparing_quantity_base'=>4,
    'unit_count'=>'10','total_cost'=>'1000',
],$buyer);
m56_assert((int)$receipt2['preparing_remaining_base']===0&&(int)$receipt2['uncommitted_remaining_base']===5&&(int)$receipt2['total_unmet_base']===5,'Over-receipt created phantom/negative demand');
$balance2=(int)$pdo->query("SELECT quantity_base FROM inventory_balances WHERE inventory_item_id={$itemId}")->fetchColumn();
m56_assert($balance2===16,'Over-receipt did not add full physical stock');

$core->supply()->prepare('item:'.$itemId,5,$buyer);
$receipt3=$core->supply()->receive('item:'.$itemId,[
    'request_token'=>'cccccccccccccccccccccccccccccccc',
    'expected_preparing_quantity_base'=>5,'unit_count'=>'5',
],$buyer);
$closed=(array)$pdo->query("SELECT status,fulfilled_quantity_base,preparing_quantity_base,open_item_guard FROM inventory_supply_needs WHERE id={$needId}")->fetch(PDO::FETCH_ASSOC);
m56_assert($closed['status']==='closed'&&(int)$closed['fulfilled_quantity_base']===15&&(int)$closed['preparing_quantity_base']===0&&$closed['open_item_guard']===null,'Fully received need did not close cleanly');

$free=$core->supply()->upsertNeed([
    'free_name'=>'ماده ناشناخته','base_unit'=>'count','department'=>'kitchen','quantity_major'=>'2',
],$prep);
$groups=$core->supply()->purchaseGroups();
$freeGroup=null;
foreach($groups as $g)if((int)$g['item_id']===0&&$g['name']==='ماده ناشناخته'){$freeGroup=$g;break;}
m56_assert(is_array($freeGroup),'Free-name Supply group missing');
$core->supply()->prepare((string)$freeGroup['group_key'],2,$buyer);
$freeReceipt=$core->supply()->receive((string)$freeGroup['group_key'],[
    'request_token'=>'dddddddddddddddddddddddddddddddd','expected_preparing_quantity_base'=>2,'unit_count'=>'2',
],$buyer);
$newItemId=(int)$freeReceipt['item_id'];
$newItem=(array)$pdo->query("SELECT review_status,name FROM inventory_items WHERE id={$newItemId}")->fetch(PDO::FETCH_ASSOC);
m56_assert($newItemId>0&&$newItem['review_status']==='needs_review'&&$newItem['name']==='ماده ناشناخته','Free-name receive bypassed Inventory-owned needs_review item creation');

$itemB=$core->inventory()->createItem([
    'item_code'=>'M56-B','name'=>'Batch B','category'=>'ingredient','base_unit'=>'count','default_department'=>'bar',
],$buyer);
$itemC=$core->inventory()->createItem([
    'item_code'=>'M56-C','name'=>'Batch C','category'=>'ingredient','base_unit'=>'count','default_department'=>'bar',
],$buyer);
$itemBId=(int)$itemB['id'];$itemCId=(int)$itemC['id'];
$core->supply()->upsertNeed(['inventory_item_id'=>$itemBId,'department'=>'bar','quantity_major'=>'3'],$super);
$core->supply()->upsertNeed(['inventory_item_id'=>$itemCId,'department'=>'bar','quantity_major'=>'4'],$super);
$core->supply()->prepare('item:'.$itemBId,3,$buyer);
$core->supply()->prepare('item:'.$itemCId,4,$buyer);

$beforeBatchMoves=(int)$pdo->query("SELECT COUNT(*) FROM inventory_movements WHERE source_type='inventory_supply_group'")->fetchColumn();
$batchFailed=false;
try{$core->supply()->receiveBatch([
    ['group_key'=>'item:'.$itemBId,'expected_preparing_quantity_base'=>3,'unit_count'=>'3'],
    ['group_key'=>'item:'.$itemCId,'expected_preparing_quantity_base'=>999,'unit_count'=>'4'],
],'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee',$buyer);}
catch(SupplyStateConflict $e){$batchFailed=true;}
$afterFailedBatchMoves=(int)$pdo->query("SELECT COUNT(*) FROM inventory_movements WHERE source_type='inventory_supply_group'")->fetchColumn();
m56_assert($batchFailed&&$beforeBatchMoves===$afterFailedBatchMoves,'Invalid batch partially mutated Inventory');

$batch=$core->supply()->receiveBatch([
    ['group_key'=>'item:'.$itemBId,'expected_preparing_quantity_base'=>3,'unit_count'=>'3'],
    ['group_key'=>'item:'.$itemCId,'expected_preparing_quantity_base'=>4,'unit_count'=>'4'],
],'ffffffffffffffffffffffffffffffff',$buyer);
m56_assert(empty($batch['duplicate'])&&count($batch['results'])===2,'Valid batch did not commit atomically');
$batchRetry=$core->supply()->receiveBatch([
    ['group_key'=>'item:'.$itemBId,'expected_preparing_quantity_base'=>3,'unit_count'=>'3'],
    ['group_key'=>'item:'.$itemCId,'expected_preparing_quantity_base'=>4,'unit_count'=>'4'],
],'ffffffffffffffffffffffffffffffff',$buyer);
m56_assert(!empty($batchRetry['duplicate']),'Batch retry was not idempotent');

$deferredItem=$core->inventory()->createItem([
    'item_code'=>'M56-D','name'=>'Deferred Item','category'=>'ingredient','base_unit'=>'count','default_department'=>'kitchen',
],$buyer);
$deferredItemId=(int)$deferredItem['id'];
$env=[
    'request_id'=>'m56-need-1','kind'=>'supply.need.create',
    'created_at'=>date(DATE_ATOM),'occurred_at'=>date(DATE_ATOM),
    'actor_projection_id'=>'user:'.$prep['id'],
    'payload'=>['inventory_item_id'=>$deferredItemId,'department'=>'kitchen','quantity_major'=>'2','note'=>'remote'],
];
$d1=$core->supplyDeferred()->dispatch('installation-test',$env);
$d2=$core->supplyDeferred()->dispatch('installation-test',$env);
m56_assert($d1['state']==='committed'&&empty($d1['idempotent'])&&$d2['state']==='committed'&&!empty($d2['idempotent']),'Deferred Supply need lost exactly-once receipt semantics');
$deferredNeed=(array)$pdo->query("SELECT * FROM inventory_supply_needs WHERE inventory_item_id={$deferredItemId} AND status='open'")->fetch(PDO::FETCH_ASSOC);
m56_assert((int)$deferredNeed['requested_quantity_base']===2,'Deferred Supply retry added demand twice');

$prepareEnv=[
    'request_id'=>'m56-prepare-stale','kind'=>'supply.status.prepare',
    'created_at'=>date(DATE_ATOM),'occurred_at'=>date(DATE_ATOM),
    'actor_projection_id'=>'user:'.$buyer['id'],
    'payload'=>['group_key'=>'item:'.$deferredItemId,'expected_quantity_base'=>999],
];
$review=$core->supplyDeferred()->dispatch('installation-test',$prepareEnv);
m56_assert($review['state']==='needs_review'&&$review['error_code']==='supply_state_changed','Stale Deferred prepare did not become durable review');
$reviewRetry=$core->supplyDeferred()->dispatch('installation-test',$prepareEnv);
m56_assert($reviewRetry['state']==='needs_review'&&!empty($reviewRetry['idempotent']),'Deferred review retry did not replay terminal Local receipt');

$reviews=$core->deferredReceipts()->pendingReviews();
$reviewId=0;
foreach($reviews as $r)if($r['request_id']==='m56-prepare-stale'){$reviewId=(int)$r['review_id'];break;}
m56_assert($reviewId>0,'Deferred Supply review item missing');
$approved=$core->supplyDeferred()->resolveReview($reviewId,'approve','state checked',$admin);
m56_assert($approved['state']==='committed','Admin approval did not apply reviewed Supply state');
$afterApprove=(array)$pdo->query("SELECT preparing_quantity_base FROM inventory_supply_needs WHERE inventory_item_id={$deferredItemId} AND status='open'")->fetch(PDO::FETCH_ASSOC);
m56_assert((int)$afterApprove['preparing_quantity_base']===2,'Approved Deferred prepare did not apply current Local demand');

$publicDeferred=(string)file_get_contents(dirname(__DIR__).'/apps/public/src/Deferred/DeferredService.php');
foreach([
    "'supply.need.create' => 'supply.need.defer'",
    "'supply.status.prepare' => 'supply.manage.defer'",
    "'supply.status.return' => 'supply.manage.defer'",
    "'supply.receipt' => 'supply.manage.defer'",
] as $contract)m56_assert(str_contains($publicDeferred,$contract),'Public Deferred Supply capability contract drifted: '.$contract);

$supplySource=(string)file_get_contents(dirname(__DIR__).'/apps/local-web/src/Domain/Supply/SupplyService.php');
m56_assert(!preg_match('/INSERT\s+INTO\s+inventory_movements/i',$supplySource),'Supply became a second Inventory movement SQL owner');
m56_assert(!preg_match('/UPDATE\s+inventory_balances/i',$supplySource),'Supply became a second Inventory balance SQL owner');
$migration=(string)file_get_contents(dirname(__DIR__).'/apps/local-web/database/migrations/0007_m5_supply.sql');
m56_assert(!preg_match('/CREATE TABLE IF NOT EXISTS\s+(financial|settlement|print_)/i',$migration),'Supply migration pulled Finance/Print ownership forward');

fwrite(STDOUT,"Local M5.6 Supply/Purchase self-test: OK\n");
