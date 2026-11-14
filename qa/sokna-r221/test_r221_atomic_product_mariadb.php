<?php
declare(strict_types=1);
if(getenv('GITHUB_ACTIONS')!=='true'||getenv('SOKNA_R212_DISPOSABLE_ONLY')!=='YES')throw new RuntimeException('R221_ATOMIC_CI_ONLY');
$web=(string)getenv('SOKNA_R212_WEB');$temp=realpath((string)getenv('RUNNER_TEMP'));
if(!$temp||!is_file($web.'/bootstrap.php')||!str_starts_with((string)realpath($web),$temp.DIRECTORY_SEPARATOR))throw new RuntimeException('R221_ATOMIC_NON_TEMP_REFUSED');
require $web.'/bootstrap.php';
$pdo=new PDO('mysql:host=127.0.0.1;port=3306;dbname=sokna_r212_disposable;charset=utf8mb4','root',(string)getenv('SOKNA_R212_DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
if((int)$pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn()!==81)throw new RuntimeException('R221_ATOMIC_MIGRATION_GATE');
$seed=$pdo->query("SELECT c.id category_id,m.id menu_id FROM categories c JOIN menu_categories mc ON mc.category_id=c.id JOIN menus m ON m.id=mc.menu_id WHERE c.lifecycle_status='active' AND c.guest_visible=1 AND m.status='active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if(!$seed)throw new RuntimeException('R221_ATOMIC_NO_GUEST_MENU');
$cid=(int)$seed['category_id'];$mid=(int)$seed['menu_id'];
$id=(int)$pdo->query("SELECT id FROM users WHERE role='admin' AND active=1 ORDER BY id LIMIT 1")->fetchColumn();if(!$id)throw new RuntimeException('R221_ATOMIC_NO_ADMIN');
$user=['id'=>$id,'role'=>'admin'];$identity=new \Sokna\Local\Core\PdoIdentityRepository($pdo);
$icons=new \Sokna\Local\Domain\Sellables\CategoryIconLibrary($web.'/resources/default-content/v1/category-icons.json');
$catalog=new \Sokna\Local\Domain\Sellables\CatalogAdminService($pdo,$identity,$icons);
$inventory=new \Sokna\Local\Domain\Inventory\InventoryService($pdo,$identity,new \Sokna\Local\Core\Capabilities($identity));
$recipes=new \Sokna\Local\Domain\Inventory\InventoryOrderService($pdo,$inventory);
$pdo->prepare("INSERT INTO inventory_items(item_code,name,category,base_unit,active) VALUES(?,'R221 inventory ingredient','ingredient','g',1)")->execute(['R221-CI-'+bin2hex(random_bytes(5))]);
$ingredientId=(int)$pdo->lastInsertId();
$seq=0;$pass=function(bool $ok,string $name)use(&$seq):void{if(!$ok)throw new RuntimeException('FAIL_R221_ATOMIC_'.$name);$seq++;echo "PASS_R221_ATOMIC_{$name}\n";};
$create=function(string $name)use($catalog,$cid,$mid,$user):int{
 $a=$catalog->saveItem(['name'=>$name,'price'=>10000,'category_id'=>$cid,'menu_ids'=>[$mid],'guest_visible'=>true,'staff_visible'=>true,'available'=>true],$user);return (int)$a['id'];
};
$child=$create('R221 atomic child');$choice=$create('R221 atomic choice');
$baseCount=(int)$pdo->query("SELECT COUNT(*) FROM audit_log WHERE action='catalog.item_created'")->fetchColumn();
$before=$pdo->query("SELECT COUNT(*) FROM item_bundles")->fetchColumn();
$badId=0;
$pdo->beginTransaction();
try{
 $badId=$create('R221 atomic rollback parent');
 $recipes->saveRecipeEditor($badId,[['inventory_item_id'=>$ingredientId,'quantity_base'=>'30','quantity_status'=>'known']],$user,true,true);
 $catalog->saveVariantFamily(['name'=>'R221 rollback family','category_id'=>$cid,'members'=>[['item_id'=>$badId,'label'=>'S'],['item_id'=>$child,'label'=>'M']]],$user);
 $catalog->saveSuggestions($badId,[$choice],$user);
 $catalog->saveBundle(['bundle_item_id'=>$badId,'components'=>[['component_item_id'=>$child,'mode'=>'fixed','quantity'=>1,'price_delta'=>-20000]]],$user);
 throw new RuntimeException('R221_EXPECTED_LAST_STAGE_FAILURE');
}catch(\Sokna\Local\Domain\Sellables\CatalogAdminException $e){
 if($e->errorCode!=='bundle_price_invalid')throw $e;
 if($pdo->inTransaction())$pdo->rollBack();
 $pass(true,'FINAL_STAGE_INVALID_BUNDLE_FAILED');
}
$pass(!$pdo->inTransaction(),'ROLLBACK_CLOSES_OUTER_TRANSACTION');
$pass((int)$pdo->query('SELECT COUNT(*) FROM items WHERE id='.$badId)->fetchColumn()===0,'ROLLBACK_REMOVES_CREATED_PRODUCT');
$pass((int)$pdo->query("SELECT COUNT(*) FROM item_variant_families WHERE name='R221 rollback family'")->fetchColumn()===0,'ROLLBACK_REMOVES_VARIANT_FAMILY');
$pass((int)$pdo->query('SELECT COUNT(*) FROM item_suggestions WHERE source_item_id='.$badId)->fetchColumn()===0,'ROLLBACK_REMOVES_EARLIER_SUGGESTIONS');
$pass((int)$pdo->query('SELECT COUNT(*) FROM inventory_recipe_versions WHERE menu_item_id='.$badId)->fetchColumn()===0,'ROLLBACK_REMOVES_RECIPE_AND_COMPONENTS');
$pass((int)$pdo->query('SELECT COUNT(*) FROM item_bundles WHERE bundle_item_id='.$badId)->fetchColumn()===0,'ROLLBACK_NO_PARTIAL_BUNDLE');
$pass((int)$pdo->query("SELECT COUNT(*) FROM audit_log WHERE action='catalog.item_created'")->fetchColumn()===$baseCount,'ROLLBACK_REMOVES_AUDIT_OF_PARTIAL_PRODUCT');
$goodId=0;$pdo->beginTransaction();
try{
 $goodId=$create('R221 atomic complete product');
 $recipes->saveRecipeEditor($goodId,[['inventory_item_id'=>$ingredientId,'quantity_base'=>'30','quantity_status'=>'known']],$user,true,true);
 $fam=$catalog->saveVariantFamily(['name'=>'R221 committed family','category_id'=>$cid,'members'=>[['item_id'=>$goodId,'label'=>'S'],['item_id'=>$child,'label'=>'M']]],$user);
 $catalog->saveSuggestions($goodId,[$choice],$user);
 $catalog->saveBundle(['bundle_item_id'=>$goodId,'components'=>[['component_item_id'=>$child,'mode'=>'fixed','quantity'=>1,'price_delta'=>250],['component_item_id'=>$choice,'mode'=>'choice','quantity'=>1,'price_delta'=>500]]],$user);
 $pass($pdo->inTransaction(),'SUBMETHODS_PRESERVE_OUTER_TRANSACTION');
 $pdo->commit();
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
$pass((int)$pdo->query('SELECT COUNT(*) FROM items WHERE id='.$goodId)->fetchColumn()===1,'ALL_PRODUCT_WRITES_COMMITTED');
$pass((int)$pdo->query('SELECT COUNT(*) FROM inventory_recipe_versions WHERE menu_item_id='.$goodId)->fetchColumn()===1,'RECIPE_VERSION_COMMITTED');
$pass((int)$pdo->query('SELECT COUNT(*) FROM item_bundles WHERE bundle_item_id='.$goodId)->fetchColumn()===1,'BUNDLE_COMMITTED');
$pass((int)$pdo->query('SELECT COUNT(*) FROM item_suggestions WHERE source_item_id='.$goodId)->fetchColumn()===1,'SUGGESTIONS_COMMITTED');
$pass((int)$pdo->query("SELECT COUNT(*) FROM item_variant_families WHERE name='R221 committed family'")->fetchColumn()===1,'VARIANT_COMMITTED');
// Preserve older independent APIs: a standalone operation must commit itself.
$legacy=$create('R221 independent legacy save');
$pass((int)$pdo->query('SELECT COUNT(*) FROM items WHERE id='.$legacy)->fetchColumn()===1 && !$pdo->inTransaction(),'INDEPENDENT_ITEM_SAVE_STILL_COMMITS');
echo "PASS_R221_REAL_MARIADB_COMPOSITE_SAVE_{$seq}_CASES\n";
