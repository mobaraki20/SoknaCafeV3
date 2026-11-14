<?php
declare(strict_types=1);
// Uses only the isolated, seeded MariaDB created by R212 QA. Hard-fail on other hosts.
if (getenv('GITHUB_ACTIONS') !== 'true' || getenv('SOKNA_R212_DISPOSABLE_ONLY') !== 'YES') throw new RuntimeException('R220_CI_DISPOSABLE_ONLY');
$web=(string)getenv('SOKNA_R212_WEB');$temp=realpath((string)getenv('RUNNER_TEMP'));
if (!$temp || !is_file($web.'/bootstrap.php') || !str_starts_with((string)realpath($web),$temp.DIRECTORY_SEPARATOR)) throw new RuntimeException('R220_REFUSE_NON_TEMP_APP');
$sha=hash_file('sha256',$web.'/src/Domain/Orders/OrderCatalogService.php');
if($sha!=='d7ed2a03b85b36c8f7e25d8eabaa13c7215633f7d11d7ab90d0a5751165c788c')throw new RuntimeException('R220_ORDER_CATALOG_SOURCE_HASH_CHANGED');
require $web.'/bootstrap.php';
$pdo=new PDO('mysql:host=127.0.0.1;port=3306;dbname=sokna_r212_disposable;charset=utf8mb4','root',(string)getenv('SOKNA_R212_DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
if((int)$pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn()!==81)throw new RuntimeException('R220_MIGRATION_GATE_FAILED');
$pdo->beginTransaction();
try {
 $row=$pdo->query("SELECT c.id category_id,m.id menu_id FROM categories c JOIN menu_categories mc ON mc.category_id=c.id JOIN menus m ON m.id=mc.menu_id WHERE c.lifecycle_status='active' AND c.guest_visible=1 AND m.status='active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
 if(!is_array($row))throw new RuntimeException('R220_SEED_HAS_NO_ACTIVE_GUEST_MENU');
 $cid=(int)$row['category_id'];$mid=(int)$row['menu_id'];
 $create=function(string $name,int $price,string $station)use($pdo,$cid,$mid):int{
    $q=$pdo->prepare("INSERT INTO items(category_id,name,price,available,active,guest_visible,staff_visible,lifecycle_status,preparation_station,takeaway_allowed) VALUES(?,?,?,1,1,1,1,'active',?,1)");$q->execute([$cid,$name,$price,$station]);$id=(int)$pdo->lastInsertId();$pdo->prepare('INSERT INTO menu_items(menu_id,item_id) VALUES(?,?)')->execute([$mid,$id]);return $id;
 };
 $root=$create('R220 parent size S',95000,'hot_bar');$large=$create('R220 variant size L',35000,'hot_bar');$fixed=$create('R220 fixed cake',14000,'kitchen');$choice=$create('R220 optional cake',15000,'kitchen');$wrong=$create('R220 other choice',16000,'hot_bar');
 $pdo->prepare('INSERT INTO item_variant_families(family_key,category_id,name,active) VALUES(?,?,?,1)')->execute(['r220-disposable-'.random_int(1000,9999),$cid,'R220 synthetic family']);$family=(int)$pdo->lastInsertId();
 $member=$pdo->prepare('INSERT INTO item_variant_members(family_id,item_id,label,sort_order) VALUES(?,?,?,?)');$member->execute([$family,$root,'S',10]);$member->execute([$family,$large,'L',20]);
 $pdo->prepare('INSERT INTO item_bundles(bundle_item_id,choice_label,choice_min,choice_max) VALUES(?,?,1,1)')->execute([$large,'کیک انتخابی']);
 $comp=$pdo->prepare('INSERT INTO item_bundle_components(bundle_item_id,component_item_id,mode,quantity,price_delta,sort_order) VALUES(?,?,?,?,?,?)');$comp->execute([$large,$fixed,'fixed',2,7000,10]);$comp->execute([$large,$choice,'choice',1,3000,20]);
 $pdo->prepare('INSERT INTO item_suggestions(source_item_id,suggested_item_id,sort_order,active) VALUES(?,?,10,1)')->execute([$root,$large]);
 $service=new \Sokna\Local\Domain\Orders\OrderCatalogService($pdo);
 $count=0;$pass=function(bool $ok,string $name)use(&$count):void{if(!$ok)throw new RuntimeException('FAIL_R220_REAL_DB_'.$name);++$count;echo 'PASS_R220_REAL_DB_'.$name."\n";};
 $request=[['id'=>$large,'quantity'=>2,'bundle_choice_item_id'=>$choice,'expected_price'=>45000,'note'=>'کم شکر','fulfillment_mode'=>'dine_in']];
 $lines=$service->snapshotRowsTx($service->normalizeRows($request),'guest');
 $pass(count($lines)===1 && (int)$lines[0]['item_id']===$large,'VARIANT_SKU_NOT_FAMILY_PARENT');
 $pass((int)$lines[0]['unit_price']===45000 && (int)$lines[0]['line_total']===90000,'SERVER_PRICE_EQUALS_VARIANT_BASE_PLUS_FIXED_AND_CHOICE');
 $pass((int)$lines[0]['bundle_choice_item_id']===$choice,'CHOICE_ATTACHED_TO_CORRECT_VARIANT');
 $pass(count($lines[0]['bundle_components'])===2,'FIXED_AND_CHOICE_COMPONENTS_SNAPSHOTTED');
 $totals=array_column($lines[0]['bundle_components'],'quantity_total','component_item_id');
 $pass((int)($totals[$fixed]??0)===4 && (int)($totals[$choice]??0)===2,'BUNDLE_QUANTITIES_MULTIPLIED_ONCE');
 $pass($lines[0]['item_note']==='کم شکر','NOTE_PRESERVED_FOR_CORRECT_LINE');
 try{$bad=$request;$bad[0]['bundle_choice_item_id']=0;$service->snapshotRowsTx($service->normalizeRows($bad),'guest');throw new RuntimeException('FAIL_R220_NO_CHOICE_ACCEPTED');}
 catch(\Sokna\Local\Domain\Orders\OrderCommitException $e){$pass($e->errorCode==='bundle_choice_required','REQUIRED_CHOICE_NOT_BYPASSED');}
 try{$bad=$request;$bad[0]['bundle_choice_item_id']=$wrong;$service->snapshotRowsTx($service->normalizeRows($bad),'guest');throw new RuntimeException('FAIL_R220_FOREIGN_CHOICE_ACCEPTED');}
 catch(\Sokna\Local\Domain\Orders\OrderCommitException $e){$pass($e->errorCode==='bundle_choice_required','FOREIGN_CHOICE_REJECTED');}
 try{$bad=$request;$bad[0]['expected_price']=42000;$service->snapshotRowsTx($service->normalizeRows($bad),'guest');throw new RuntimeException('FAIL_R220_WRONG_PRICE_ACCEPTED');}
 catch(\Sokna\Local\Domain\Orders\OrderCommitException $e){$pass($e->errorCode==='price_changed','CLIENT_CANNOT_FORCE_LOWER_PRICE');}
 $plain=[['id'=>$root,'quantity'=>1,'bundle_choice_item_id'=>0,'expected_price'=>95000,'fulfillment_mode'=>'dine_in']];
 $raw=$service->snapshotRowsTx($service->normalizeRows($plain),'guest');$pass((int)$raw[0]['unit_price']===95000 && !$raw[0]['bundle_components'],'ROOT_MEMBER_WITHOUT_BUNDLE_DOES_NOT_INHERIT_OTHER_SIZE');
 // All writes are rolled back: no test order, catalog record or migration leaks out.
 $pdo->rollBack();echo 'PASS_R220_REAL_DB_VARIANT_BUNDLE_QUOTE_'. $count ."_CASES\n";
} catch(\Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
