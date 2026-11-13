<?php
declare(strict_types=1);
/** CI-only, disposable MariaDB with all real Local migrations. Never connect to cafe! */
if(getenv('GITHUB_ACTIONS')!=='true'||getenv('SOKNA_R192_DISPOSABLE_ONLY')!=='YES'){
 throw new RuntimeException('R192 CI disposable DB only');
}
$web=(string)getenv('SOKNA_R192_WEB');
if($web===''||!is_file($web.'/bootstrap.php'))throw new RuntimeException('R192_WEB_MISSING');
// In a throwaway CI workspace, satisfy the photo-bundle migration preflight
// with an isolated, writable media vault. No production host or credentials.
$ciTemp=(string)getenv('RUNNER_TEMP');
if($ciTemp===''||!str_starts_with(realpath($web)?:'',realpath($ciTemp).DIRECTORY_SEPARATOR))
    throw new RuntimeException('R192_NOT_IN_DISPOSABLE_RUNNER_TEMP');
$ciRoot=dirname($web);$ciData=$ciRoot.'/Data';$ciMedia=$ciData.'/guest-media';
if(!is_dir($ciMedia)&&!mkdir($ciMedia,0700,true)&&!is_dir($ciMedia))
    throw new RuntimeException('R192_CI_MEDIA_DIR_UNAVAILABLE');
$ciConfig=$ciRoot.'/config.php';
if(file_exists($ciConfig)||is_link($ciConfig))throw new RuntimeException('R192_EXISTING_HOST_CONFIG_REFUSED');
if(file_put_contents($ciConfig,'<?php return '.var_export(['app'=>['data_dir'=>$ciData]],true).';')===false)
    throw new RuntimeException('R192_CI_HOST_CONFIG_CREATE_FAILED');
require $web.'/bootstrap.php';
$host=(string)(getenv('SOKNA_R192_DB_HOST')?:'127.0.0.1');$pass=(string)getenv('SOKNA_R192_DB_PASSWORD');
$pdo=new PDO('mysql:host='.$host.';port=3306;charset=utf8mb4','root',$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$pdo->exec('CREATE DATABASE IF NOT EXISTS sokna_r192_disposable CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdo->exec('USE sokna_r192_disposable');
$migrations=new \Sokna\Local\Core\Migrations($pdo,$web.'/database/migrations');
// Follow the production BrowserSetupService ordering exactly: base schema first,
// seeded default catalog second, data-dependent migration 0059+ afterwards.
$migrations->migrate('0059_menu_business_reconciliation_v4');
$pdo->prepare('INSERT INTO users(username,password_hash,display_name,role,active) VALUES(?,?,?,?,1)')->execute(['qa.r192',password_hash('CI-only password',PASSWORD_DEFAULT),'QA Admin','admin']);
$userId=(int)$pdo->lastInsertId();$actor=['id'=>$userId];
$icons=new \Sokna\Local\Domain\Sellables\CategoryIconLibrary($web.'/resources/default-content/v1/category-icons.json');
$seeder=new \Sokna\Local\Domain\Sellables\DefaultContentSeeder($pdo,$icons,$web.'/resources/default-content/v1',$ciMedia);
$seedResult=$seeder->seed($userId);
if(($seedResult['status']??null)!=='complete')throw new RuntimeException('R192_CI_CATALOG_SEED_NOT_APPLIED');
$migrations->migrate();
$ledger=(int)$pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
if($ledger<80)throw new RuntimeException('LESS_THAN_80_REAL_MIGRATIONS '.$ledger);
$pdo->exec("INSERT INTO settings(setting_key,setting_value) VALUES('module.inventory.enabled','0') ON DUPLICATE KEY UPDATE setting_value='0'");
$id=new \Sokna\Local\Core\PdoIdentityRepository($pdo);$caps=new \Sokna\Local\Core\Capabilities($id);
$inv=new \Sokna\Local\Domain\Inventory\InventoryService($pdo,$id,$caps);
$inventoryOrders=new \Sokna\Local\Domain\Inventory\InventoryOrderService($pdo,$inv);
$templates=new \Sokna\Local\Domain\Printing\PrintTemplatePackageService($pdo);
$print=new \Sokna\Local\Domain\Printing\PrintService($pdo,$id,$templates);
$prepAccess=new \Sokna\Local\Domain\Preparation\PreparationAccessService($pdo,$id,$caps);
$prep=new \Sokna\Local\Domain\Preparation\PreparationService($pdo,$prepAccess,new \Sokna\Local\Domain\Orders\BusinessClock($pdo));
$service=new \Sokna\Local\Domain\Orders\OrderStaffActionService($pdo,$id,$caps,$inventoryOrders,$print,$prep);
function fixture(PDO $pdo,int $actor,string $station='other',bool $other=false):array{
 static $n=0;$n++;
 $pdo->prepare('INSERT INTO cafe_tables(name,table_number,code,access_token,active) VALUES(?,?,?,?,1)')->execute(['QA table '.$n,$n,'R192-T-'.$n,'R192-TOKEN-'.$n]);$tableId=(int)$pdo->lastInsertId();
 $pdo->prepare("INSERT INTO table_sessions(public_token,table_id,status,live_table_guard,opened_by_user_id,business_date,business_shift_key,business_shift_label,business_cutoff_snapshot) VALUES(?,?,'active',?,?,CURDATE(),'day','QA','05:00')")
  ->execute(['R192-S-'.$n,$tableId,$tableId,$actor]);$sessionId=(int)$pdo->lastInsertId();
 $make=function(int $seq)use($pdo,$tableId,$sessionId,$actor,$station,$n):int{
  $pdo->prepare("INSERT INTO orders(public_code,client_token,table_id,session_id,order_source,status,total_amount,accepted_by_user_id,business_order_number,business_date,business_shift_key,business_shift_label,business_cutoff_snapshot) VALUES(?,?,?,?,'staff','accounted',250,?,?,CURDATE(),'day','QA','05:00')")
   ->execute(['R192-ORDER-'.$n.'-'.$seq,'R192-CLIENT-'.$n.'-'.$seq,$tableId,$sessionId,$actor,$n*10+$seq]);$orderId=(int)$pdo->lastInsertId();
  $pdo->prepare('INSERT INTO order_items(order_id,item_id,item_name,unit_price,quantity,ordered_quantity,fulfillment_mode,preparation_station,line_total) VALUES(?,NULL,?,250,1,1,?, ?,250)')
   ->execute([$orderId,'QA sample','dine_in',$station]);return $orderId;
 };
 $a=$make(1);$b=$other?$make(2):null;
 return [$tableId,$sessionId,$a,$b];
}
function ensure(bool $ok,string $code):void{if(!$ok)throw new RuntimeException('R192_ASSERT_'.$code);}
function status(PDO $p,string $table,int $id):string{$stmt=$p->prepare("SELECT status FROM $table WHERE id=?");$stmt->execute([$id]);return (string)$stmt->fetchColumn();}
function countRows(PDO $p,string $sql,array $params):int{$stmt=$p->prepare($sql);$stmt->execute($params);return (int)$stmt->fetchColumn();}
function cancel(\Sokna\Local\Domain\Orders\OrderStaffActionService $s,int $id,array $actor,string $suffix):array{
 return $s->cancelUnsettledStaffOrder(['order_id'=>$id,'request_id'=>'r192-mariadb-'.$suffix,'reason'=>'ثبت اشتباه توسط QA'],$actor);
}
// A. One unprepared non-inventory order: full order zeroed, status preserved and session closed.
[$table,$sid,$oid]=fixture($pdo,$userId);$out=cancel($service,$oid,$actor,'A');
ensure(($out['success']??false)&&($out['session_closed']??false),'SINGLE_CANCEL');
ensure(status($pdo,'orders',$oid)==='cancelled'&&status($pdo,'table_sessions',$sid)==='closed','SINGLE_STATUSES');
ensure(countRows($pdo,'SELECT COUNT(*) FROM order_items WHERE order_id=? AND quantity=0 AND ordered_quantity=1',[$oid])===1,'ORIGINAL_QUANTITY_AUDITED');
ensure(countRows($pdo,'SELECT COUNT(*) FROM order_item_adjustments WHERE order_id=?',[$oid])===1,'ADJUSTMENT_AUDITED');
ensure(countRows($pdo,'SELECT COUNT(*) FROM order_status_history WHERE order_id=? AND to_status=\'cancelled\'',[$oid])===1,'HISTORY');
ensure(cancel($service,$oid,$actor,'A')['idempotent']===true,'IDEMPOTENT_REPLAY');
echo "PASS_R192_MARIADB_SINGLE_CANCEL\n";
// B. Cancel one order while the other remains accounted in same session.
[$table,$sid,$oid,$other]=fixture($pdo,$userId,'other',true);$out=cancel($service,$oid,$actor,'B');
ensure(($out['success']??false)&&($out['session_closed']??true)===false,'MULTI_KEEP_SESSION');
ensure(status($pdo,'table_sessions',$sid)==='active'&&status($pdo,'orders',$other)==='accounted','MULTI_PRESERVE_OTHER');
ensure(countRows($pdo,"SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE session_id=? AND status='accounted'",[$sid])===250,'MULTI_AMOUNT');
echo "PASS_R192_MARIADB_MULTI_ORDER_ISOLATION\n";
// C. Preparation destination missing: explicit fail closed, all SQL rolled back.
[$table,$sid,$oid]=fixture($pdo,$userId,'bar');
try{cancel($service,$oid,$actor,'C');throw new RuntimeException('MISSING_EXPECTED_PRINT_BLOCK');}
catch(\Sokna\Local\Domain\Orders\OrderStaffActionException $e){ensure($e->errorCode==='correction_print_not_ready','PRINT_BLOCK_CODE');}
ensure(status($pdo,'orders',$oid)==='accounted'&&status($pdo,'table_sessions',$sid)==='active','PRINT_ROLLBACK');
ensure(countRows($pdo,'SELECT COUNT(*) FROM order_item_adjustments WHERE order_id=?',[$oid])===0,'PRINT_ROLLBACK_ADJUSTMENT');
echo "PASS_R192_MARIADB_PRINT_FAIL_CLOSED\n";
// D. Active preparation correction destination enqueues a durable correction slip.
$pdo->exec("UPDATE print_destinations SET active=1 WHERE destination_key='prep_shared'");
[$table,$sid,$oid]=fixture($pdo,$userId,'bar');$out=cancel($service,$oid,$actor,'D');
ensure(($out['success']??false)&&count($out['correction_print_job_ids']??[])===1,'PRINT_QUEUED');
ensure(countRows($pdo,"SELECT COUNT(*) FROM print_jobs WHERE entity_type='order_item_adjustment' AND status='pending' AND entity_id=?",[(string)$out['adjustment_ids'][0]])===1,'PRINT_DURABLE');
echo "PASS_R192_MARIADB_CORRECTION_PRINT_JOB\n";
// E. A claimed order never changes, even with no payments.
[$table,$sid,$oid]=fixture($pdo,$userId,'bar');
$pdo->prepare('INSERT INTO order_preparation_claims(order_id,area_key,claimed_by_user_id,item_signature) VALUES(?,?,?,?)')->execute([$oid,'bar',$userId,str_repeat('a',64)]);
try{cancel($service,$oid,$actor,'E');throw new RuntimeException('MISSING_PREP_CLAIM_BLOCK');}
catch(\Sokna\Local\Domain\Orders\OrderStaffActionException $e){ensure($e->errorCode==='preparation_claimed','PREP_BLOCK_CODE');}
ensure(status($pdo,'orders',$oid)==='accounted','PREP_NOT_CHANGED');
echo "PASS_R192_MARIADB_CLAIM_GUARD\n";
// F. Unreconciled inventory event blocks cancel without rewriting its source.
[$table,$sid,$oid]=fixture($pdo,$userId);
$pdo->prepare("INSERT INTO inventory_order_events(event_type,order_id,idempotency_key,status) VALUES('accounted',?,?,'pending')")->execute([$oid,'r192:pending:'.$oid]);
try{cancel($service,$oid,$actor,'F');throw new RuntimeException('MISSING_INVENTORY_BLOCK');}
catch(\Sokna\Local\Domain\Orders\OrderStaffActionException $e){ensure($e->errorCode==='inventory_pending','INV_BLOCK_CODE');}
ensure(status($pdo,'orders',$oid)==='accounted','INV_NOT_CHANGED');
echo "PASS_R192_MARIADB_INVENTORY_EVENT_GUARD\n";
// G. Do not close a table while a live draft exists.
[$table,$sid,$oid]=fixture($pdo,$userId);
$pdo->prepare("INSERT INTO table_drafts(table_id,state,active_table_guard,expected_session_id) VALUES(?,'active',?,?)")->execute([$table,$table,$sid]);
try{cancel($service,$oid,$actor,'G');throw new RuntimeException('MISSING_DRAFT_BLOCK');}
catch(\Sokna\Local\Domain\Orders\OrderStaffActionException $e){ensure($e->errorCode==='active_draft','DRAFT_BLOCK_CODE');}
ensure(status($pdo,'orders',$oid)==='accounted'&&status($pdo,'table_sessions',$sid)==='active','DRAFT_NOT_CHANGED');
echo "PASS_R192_MARIADB_ACTIVE_DRAFT_GUARD\n";
// No user data, other DB, or production service touched by this script.
echo "PASS_R192_MARIADB_REAL_MIGRATIONS_{$ledger}_AND_7_SCENARIOS\n";
