<?php
declare(strict_types=1);
if(getenv('GITHUB_ACTIONS')!=='true'||getenv('SOKNA_R212_DISPOSABLE_ONLY')!=='YES')throw new RuntimeException('R221_DISPOSABLE_CI_ONLY');
$web=(string)getenv('SOKNA_R212_WEB');$temp=realpath((string)getenv('RUNNER_TEMP'));
if(!$temp||!is_file($web.'/bootstrap.php')||!str_starts_with((string)realpath($web),$temp.DIRECTORY_SEPARATOR))throw new RuntimeException('R221_NON_TEMP_WEB_REFUSED');
require $web.'/bootstrap.php';
$pdo=new PDO('mysql:host=127.0.0.1;port=3306;dbname=sokna_r212_disposable;charset=utf8mb4','root',(string)getenv('SOKNA_R212_DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
if((int)$pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn()!==81)throw new RuntimeException('R221_MIGRATION_GATE');
$seed=$pdo->query("SELECT c.id category_id,m.id menu_id FROM categories c JOIN menu_categories mc ON mc.category_id=c.id JOIN menus m ON m.id=mc.menu_id WHERE c.lifecycle_status='active' AND c.guest_visible=1 AND m.status='active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if(!$seed)throw new RuntimeException('R221_NO_MENU');
$cid=(int)$seed['category_id'];$mid=(int)$seed['menu_id'];
$adminId=(int)$pdo->query("SELECT id FROM users WHERE role='admin' AND active=1 ORDER BY id LIMIT 1")->fetchColumn();if(!$adminId)throw new RuntimeException('R221_NO_ADMIN');
$admin=['id'=>$adminId,'role'=>'admin'];
$icons=new \Sokna\Local\Domain\Sellables\CategoryIconLibrary($web.'/resources/default-content/v1/category-icons.json');
$svc=new \Sokna\Local\Domain\Sellables\CatalogAdminService($pdo,new \Sokna\Local\Core\PdoIdentityRepository($pdo),$icons);
$orders=new \Sokna\Local\Domain\Orders\OrderCatalogService($pdo);
$create=function(string $label,int $price)use($pdo,$cid,$mid):int{
    $s=$pdo->prepare("INSERT INTO items(category_id,name,price,available,active,guest_visible,staff_visible,lifecycle_status,preparation_station,takeaway_allowed) VALUES(?,?,?,1,1,1,1,'active','kitchen',1)");$s->execute([$cid,$label,$price]);$id=(int)$pdo->lastInsertId();$pdo->prepare('INSERT INTO menu_items(menu_id,item_id) VALUES(?,?)')->execute([$mid,$id]);return $id;
};
$root=$create('R221 root item',10000);$fixed=$create('R221 fixed component',2000);$choiceA=$create('R221 choice A',1200);$choiceB=$create('R221 choice B',1300);
$n=0;$pass=function(bool $yes,string $label)use(&$n):void{if(!$yes)throw new RuntimeException('FAIL_R221_'.$label);echo 'PASS_R221_'.$label."\n";++$n;};
$builder=function(int $fixedDelta,int|string $choiceDeltaA,int $choiceDeltaB)use($root,$fixed,$choiceA,$choiceB):array{
  return ['bundle_item_id'=>$root,'choice_label'=>'نوع همراه','components'=>[
   ['component_item_id'=>$fixed,'mode'=>'fixed','quantity'=>2,'price_delta'=>$fixedDelta],
   ['component_item_id'=>$choiceA,'mode'=>'choice','quantity'=>1,'price_delta'=>$choiceDeltaA],
   ['component_item_id'=>$choiceB,'mode'=>'choice','quantity'=>1,'price_delta'=>$choiceDeltaB],
  ]];
};
$reject=function(callable $f,string $code,string $label)use($pass):void{
 try{$f();throw new RuntimeException('FAIL_R221_'.$label.'_ACCEPTED');}
 catch(\Sokna\Local\Domain\Sellables\CatalogAdminException|\Sokna\Local\Domain\Orders\OrderCommitException $e){$pass($e->errorCode===$code,$label);}
};
$reject(fn()=>$svc->saveBundle($builder(-2000,500,-9000),$admin),'bundle_price_invalid','ADMIN_REJECTS_ANY_NEGATIVE_CHOICE');
$pass((int)$pdo->query('SELECT COUNT(*) FROM item_bundles WHERE bundle_item_id='.$root)->fetchColumn()===0,'INVALID_SAVE_LEAVES_NO_BUNDLE');
$reject(fn()=>$svc->saveBundle($builder(0,'4000000000000',0),$admin),'bundle_price_invalid','ADMIN_REJECTS_OUT_OF_SAFE_DELTA_RANGE');
$svc->saveBundle($builder(-2000,500,-3000),$admin);$pass((int)$pdo->query('SELECT COUNT(*) FROM item_bundle_components WHERE bundle_item_id='.$root)->fetchColumn()===3,'VALID_CHOICE_SET_SAVED');
$pdo->beginTransaction();
$input=[['id'=>$root,'quantity'=>2,'bundle_choice_item_id'=>$choiceA,'expected_price'=>8500,'fulfillment_mode'=>'dine_in']];
$out=$orders->snapshotRowsTx($orders->normalizeRows($input),'guest');$pass((int)$out[0]['unit_price']===8500&&(int)$out[0]['line_total']===17000,'SERVER_SNAPSHOT_VALID_PRICE');
$components=$out[0]['bundle_components'];$pass(count($components)===2&&(int)$components[0]['quantity_total']===4,'COMPONENT_QUANTITY_UNCHANGED');
$pdo->rollBack();
$reject(fn()=>$svc->saveBundle($builder(-13000,500,1000),$admin),'bundle_price_invalid','FIXED_PLUS_CHOICES_INVALID_REJECTED');
$pass((int)$pdo->query('SELECT COUNT(*) FROM item_bundle_components WHERE bundle_item_id='.$root)->fetchColumn()===3,'REJECT_PRESERVES_PREVIOUS_COMPONENTS');
$reject(fn()=>$svc->saveItem(['id'=>$root,'name'=>'R221 root item','price'=>100,'category_id'=>$cid,'menu_ids'=>[$mid]],$admin),'bundle_price_invalid','PARENT_PRICE_UPDATE_REJECTED_IF_BUNDLE_NEGATIVE');
$pass((int)$pdo->query('SELECT price FROM items WHERE id='.$root)->fetchColumn()===10000,'INVALID_PARENT_PRICE_ROLLED_BACK');
$pdo->prepare('UPDATE item_bundle_components SET price_delta=-12000 WHERE bundle_item_id=? AND component_item_id=?')->execute([$root,$fixed]);
$pdo->beginTransaction();
$reject(fn()=>$orders->snapshotRowsTx($orders->normalizeRows($input),'guest'),'bundle_price_invalid','LEGACY_NEGATIVE_PRICE_REJECTED_ON_GUEST_QUOTE');
$reject(fn()=>$orders->snapshotRowsTx($orders->normalizeRows($input),'staff'),'bundle_price_invalid','LEGACY_NEGATIVE_PRICE_REJECTED_ON_STAFF_QUOTE');
$pdo->rollBack();
$pdo->prepare('UPDATE item_bundle_components SET price_delta=9223372036854775807 WHERE bundle_item_id=? AND component_item_id=?')->execute([$root,$fixed]);
$pdo->beginTransaction();
$reject(fn()=>$orders->snapshotRowsTx($orders->normalizeRows($input),'guest'),'bundle_price_invalid','INTEGER_OVERFLOW_DELTA_REJECTED');
$pdo->rollBack();
$pdo->prepare('UPDATE item_bundle_components SET price_delta=-2000 WHERE bundle_item_id=? AND component_item_id=?')->execute([$root,$fixed]);
$pdo->beginTransaction();$other=$input;$other[0]['bundle_choice_item_id']=$choiceB;$other[0]['expected_price']=5000;
$out=$orders->snapshotRowsTx($orders->normalizeRows($other),'guest');$pass((int)$out[0]['unit_price']===5000,'VALID_SECOND_CHOICE_FINAL_PRICE');$pdo->rollBack();
echo "PASS_R221_REAL_MARIADB_81_MIGRATIONS_PRICE_GUARD_{$n}_CASES\n";
