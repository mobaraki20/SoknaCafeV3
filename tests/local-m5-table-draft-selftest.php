<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/apps/local-web/bootstrap.php';

use Sokna\Local\Domain\Orders\StaffQuickOrderException;
use Sokna\Local\Domain\Orders\TableDraftException;

function m53_fail(string $message): never { fwrite(STDERR,$message.PHP_EOL); exit(1); }
function m53_assert(bool $condition,string $message): void { if(!$condition)m53_fail($message); }

$core=sokna_local_bootstrap([
    'app'=>['timezone'=>'Asia/Tehran','data_dir'=>sys_get_temp_dir().'/sokna-v3-m53-'.bin2hex(random_bytes(4))],
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
foreach(['table_drafts','table_draft_items'] as $table)m53_assert(in_array($table,$tables,true),"missing {$table}");
foreach([] as $later)
    m53_assert(!in_array($later,$tables,true),"M5.3 leaked {$later}");

$makeUser=function(string $name,bool $allowed)use($pdo):array{
    $pdo->prepare('INSERT INTO users(username,password_hash,display_name,role,active) VALUES(?,?,?,?,1)')
        ->execute([$name,password_hash('x',PASSWORD_DEFAULT),$name,'operator']);
    $id=(int)$pdo->lastInsertId();
    if($allowed)$pdo->prepare('INSERT INTO user_capabilities(user_id,capability,enabled) VALUES(?,?,1)')->execute([$id,'orders_floor']);
    return ['id'=>$id,'username'=>$name,'display_name'=>$name,'role'=>'operator'];
};
$userA=$makeUser('m53-a',true);
$userB=$makeUser('m53-b',true);
$userDenied=$makeUser('m53-denied',false);

$pdo->exec("INSERT INTO categories(category_key,name,audience,sort_order,active) VALUES('m53','M53','guest_staff',10,1)");
$categoryId=(int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO menus(menu_key,name,status,sort_order) VALUES('m53-main','M53 Main','active',10)");
$menuId=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO menu_categories(menu_id,category_id,sort_order) VALUES(?,?,10)')->execute([$menuId,$categoryId]);
$itemId=$core->sellables()->create([
    'category_id'=>$categoryId,'name'=>'M53 Coffee','price'=>100000,
    'staff_only'=>false,'takeaway_allowed'=>true,'preparation_station'=>'cold_bar','sellable_kind'=>'menu_item',
]);
$pdo->prepare('INSERT INTO menu_items(menu_id,item_id) VALUES(?,?)')->execute([$menuId,$itemId]);

$pdo->exec("INSERT INTO cafe_tables(name,table_number,code,access_token,active,sort_order) VALUES('Draft Table',11,'D11','m53-draft-table',1,1)");
$tableId=(int)$pdo->lastInsertId();

$denied=false;
try{$core->tableDrafts()->save([
    'table_id'=>$tableId,'expected_version'=>0,'expected_session_id'=>0,
    'items'=>[['id'=>$itemId,'quantity'=>1,'expected_price'=>100000]],
],$userDenied);}catch(TableDraftException|StaffQuickOrderException $e){$denied=true;}
m53_assert($denied,'unauthorized actor mutated Table Draft');

$beforeOrders=(int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();
$beforeSeq=(int)$pdo->query('SELECT COUNT(*) FROM order_business_sequences')->fetchColumn();

$first=$core->tableDrafts()->save([
    'table_id'=>$tableId,'expected_version'=>0,'expected_session_id'=>0,'note'=>'shared',
    'items'=>[['id'=>$itemId,'quantity'=>1,'expected_price'=>100000,'fulfillment_mode'=>'dine_in']],
],$userA);
$draftId=(int)$first['draft']['id'];
m53_assert($first['created']===true&&(int)$first['draft']['version']===1,'first draft create failed');
m53_assert((int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn()===$beforeOrders,'draft save created an order');
m53_assert((int)$pdo->query('SELECT COUNT(*) FROM order_business_sequences')->fetchColumn()===$beforeSeq,'draft save allocated a business number');

$shared=$core->tableDrafts()->get($tableId,$userB);
m53_assert((int)($shared['draft']['id']??0)===$draftId,'second authorized context did not see shared server draft');

$second=$core->tableDrafts()->save([
    'table_id'=>$tableId,'expected_version'=>1,'expected_session_id'=>0,'note'=>'updated by B',
    'items'=>[['id'=>$itemId,'quantity'=>2,'expected_price'=>100000,'fulfillment_mode'=>'dine_in']],
],$userB);
m53_assert($second['created']===false&&(int)$second['draft']['version']===2,'draft update did not advance optimistic version');
m53_assert((int)$second['draft']['items'][0]['quantity']===2,'shared draft update did not persist quantity');

$stale=false;
try{
    $core->tableDrafts()->save([
        'table_id'=>$tableId,'expected_version'=>1,'expected_session_id'=>0,
        'items'=>[['id'=>$itemId,'quantity'=>3,'expected_price'=>100000]],
    ],$userA);
}catch(TableDraftException $e){$stale=$e->errorCode==='version_conflict'&&($e->details['current_version']??0)===2;}
m53_assert($stale,'stale editor overwrote newer draft version');
m53_assert((int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn()===$beforeOrders,'stale edit caused an order side effect');

$final=$core->tableDrafts()->finalize(['draft_id'=>$draftId,'expected_version'=>2],$userA);
m53_assert($final['duplicate']===false&&(int)$final['order_id']>0,'draft finalize did not create canonical order');
m53_assert((int)$final['order_number']===1,'finalize did not allocate first business number at commit time');
m53_assert((int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn()===$beforeOrders+1,'finalize created wrong order count');
m53_assert((int)$pdo->query('SELECT COUNT(*) FROM order_business_sequences')->fetchColumn()===1,'finalize did not create business sequence');
$orderId=(int)$final['order_id'];
$orderSession=(int)$pdo->query("SELECT COALESCE(session_id,0) FROM orders WHERE id={$orderId}")->fetchColumn();
m53_assert($orderSession>0,'Staff Quick Order did not create/attach an active table session');

$retry=$core->tableDrafts()->finalize(['draft_id'=>$draftId,'expected_version'=>2],$userB);
m53_assert($retry['duplicate']===true&&(int)$retry['order_id']===$orderId,'repeated finalize did not resolve idempotently');
m53_assert((int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn()===$beforeOrders+1,'repeated finalize created order #2');
$seq=(int)$pdo->query("SELECT last_number FROM order_business_sequences WHERE business_date=(SELECT business_date FROM orders WHERE id={$orderId})")->fetchColumn();
m53_assert($seq===1,'repeated finalize advanced business sequence');

$pdo->exec("INSERT INTO cafe_tables(name,table_number,code,access_token,active,sort_order) VALUES('Price Table',12,'D12','m53-price-table',1,2)");
$priceTable=(int)$pdo->lastInsertId();
$priceDraft=$core->tableDrafts()->save([
    'table_id'=>$priceTable,'expected_version'=>0,'expected_session_id'=>0,
    'items'=>[['id'=>$itemId,'quantity'=>1,'expected_price'=>100000]],
],$userA);
$priceDraftId=(int)$priceDraft['draft']['id'];
$pdo->prepare('UPDATE items SET price=? WHERE id=?')->execute([125000,$itemId]);
$priceRejected=false;
try{$core->tableDrafts()->finalize(['draft_id'=>$priceDraftId,'expected_version'=>1],$userA);}
catch(TableDraftException $e){$priceRejected=$e->errorCode==='finalize_rejected'&&($e->details['cause']??'')==='price_changed';}
m53_assert($priceRejected,'Finalize trusted stale draft price instead of current catalog');
m53_assert((int)$pdo->query("SELECT COUNT(*) FROM orders WHERE table_id={$priceTable}")->fetchColumn()===0,'rejected finalize created order side effect');

$cancel=$core->tableDrafts()->cancel(['table_id'=>$priceTable,'expected_version'=>1],$userB);
m53_assert($cancel['cancelled']===true,'explicit cancel failed');
m53_assert((int)$pdo->query("SELECT COUNT(*) FROM table_drafts WHERE table_id={$priceTable} AND state='active'")->fetchColumn()===0,'cancel did not clear active-draft guard');

$pdo->prepare('UPDATE items SET price=? WHERE id=?')->execute([100000,$itemId]);
$pdo->exec("INSERT INTO cafe_tables(name,table_number,code,access_token,active,sort_order) VALUES('Quick Table',13,'D13','m53-quick-table',1,3)");
$quickTable=(int)$pdo->lastInsertId();
$quick=$core->staffQuickOrders()->commit([
    'table_id'=>$quickTable,'expected_session_id'=>0,'request_token'=>'m53-direct-token-0001',
    'items'=>[['id'=>$itemId,'quantity'=>1,'expected_price'=>100000]],
],$userA);
m53_assert((int)$quick['order_id']>0&&(int)$quick['session_id']>0,'direct Staff Quick Order did not use canonical commit/session owner');

$pdo->exec("INSERT INTO cafe_tables(name,table_number,code,access_token,active,sort_order) VALUES('Remote Table',14,'D14','m53-remote-table',1,4)");
$remoteTable=(int)$pdo->lastInsertId();
$remote=$core->tableDraftRealtime()->dispatch([
    'kind'=>'table_draft.create',
    'actor_projection_id'=>'user:'.$userB['id'],
    'payload'=>[
        'table_id'=>$remoteTable,'expected_version'=>999,'expected_session_id'=>0,
        'items'=>[['id'=>$itemId,'quantity'=>1,'expected_price'=>100000]],
    ],
]);
m53_assert((int)($remote['draft']['id']??0)>0,'Realtime Table Draft adapter did not create Local-owned draft');

$pdo->prepare('UPDATE users SET active=0 WHERE id=?')->execute([$userB['id']]);
$remoteActorRejected=false;
try{
    $core->tableDraftRealtime()->dispatch([
        'kind'=>'table_draft.edit',
        'actor_projection_id'=>'user:'.$userB['id'],
        'payload'=>[
            'table_id'=>$remoteTable,'expected_version'=>(int)$remote['draft']['version'],'expected_session_id'=>0,
            'items'=>[['id'=>$itemId,'quantity'=>2,'expected_price'=>100000]],
        ],
    ]);
}catch(TableDraftException $e){$remoteActorRejected=$e->errorCode==='actor_invalid';}
m53_assert($remoteActorRejected,'Realtime adapter did not revalidate disabled Local actor');

$publicRealtime=(string)file_get_contents(dirname(__DIR__).'/apps/public/src/Realtime/RealtimeService.php');
$publicDeferred=(string)file_get_contents(dirname(__DIR__).'/apps/public/src/Deferred/DeferredService.php');
m53_assert(str_contains($publicRealtime,"'table_draft.finalize'")&&str_contains($publicRealtime,"str_starts_with(\$kind, 'table_draft.')")&&str_contains($publicRealtime,"'local_unavailable'"),'Public Realtime lost Local-required Table Draft boundary');
m53_assert(!str_contains($publicDeferred,'table_draft.'),'Table Draft leaked into Deferred-safe transport');

$draftSource=(string)file_get_contents(dirname(__DIR__).'/apps/local-web/src/Domain/Orders/TableDraftService.php');
m53_assert(!preg_match('/INSERT\s+INTO\s+orders/i',$draftSource),'Table Draft duplicated canonical order INSERT');
m53_assert(!str_contains($draftSource,'order_business_sequences'),'Table Draft allocated business numbers itself');
m53_assert(!preg_match('/expire|ttl/i',(string)file_get_contents(dirname(__DIR__).'/apps/local-web/database/migrations/0004_m5_table_drafts.sql')),'Table Draft introduced auto-expiry');

fwrite(STDOUT,"Local M5.3 Staff Quick Order + Table Draft self-test: OK\n");
