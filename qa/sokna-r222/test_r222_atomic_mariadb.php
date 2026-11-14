<?php
declare(strict_types=1);
// QA ONLY. Refuses local or production DB; requires ephemeral MariaDB initialized by R212 fixture.
if(getenv('GITHUB_ACTIONS')!=='true'||getenv('SOKNA_R212_DISPOSABLE_ONLY')!=='YES')throw new RuntimeException('R222_CI_DISPOSABLE_ONLY');
$web=(string)getenv('SOKNA_R212_WEB');$root=realpath((string)getenv('RUNNER_TEMP'));
if(!$root||!is_file($web.'/bootstrap.php')||!str_starts_with((string)realpath($web),$root.DIRECTORY_SEPARATOR))throw new RuntimeException('R222_NON_TEMP_WEB_REFUSED');
require_once $web.'/bootstrap.php';
$pdo=new PDO('mysql:host=127.0.0.1;port=3306;dbname=sokna_r212_disposable;charset=utf8mb4','root',(string)getenv('SOKNA_R212_DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
if((int)$pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn()!==81)throw new RuntimeException('R222_81_MIGRATIONS_REQUIRED');
$uid=(int)$pdo->query("SELECT id FROM users WHERE role='admin' AND active=1 ORDER BY id LIMIT 1")->fetchColumn();if(!$uid)throw new RuntimeException('R222_ADMIN_MISSING');
$actor=['id'=>$uid,'role'=>'admin'];
$idRepo=new \Sokna\Local\Core\PdoIdentityRepository($pdo);
$icons=new \Sokna\Local\Domain\Sellables\CategoryIconLibrary($web.'/resources/default-content/v1/category-icons.json');
$catalog=new \Sokna\Local\Domain\Sellables\CatalogAdminService($pdo,$idRepo,$icons);
$inventory=new \Sokna\Local\Domain\Inventory\InventoryService($pdo,$idRepo,new \Sokna\Local\Core\Capabilities($idRepo));
$orders=new \Sokna\Local\Domain\Inventory\InventoryOrderService($pdo,$inventory);
$row=$pdo->query("SELECT c.id category_id,m.id menu_id FROM categories c JOIN menu_categories mc ON mc.category_id=c.id JOIN menus m ON m.id=mc.menu_id WHERE c.lifecycle_status='active' AND m.status='active' LIMIT 1")->fetch();
if(!is_array($row))throw new RuntimeException('R222_ACTIVE_MENU_NOT_SEEDED');$cid=(int)$row['category_id'];$mid=(int)$row['menu_id'];
$checkCount=0;
$check=function(bool $ok,string $label)use(&$checkCount):void{if(!$ok)throw new RuntimeException('FAIL_R222_REAL_DB_'.$label);$checkCount++;echo 'PASS_R222_REAL_DB_'.$label."\n";};
$newItem=function(string $name,int $price)use($catalog,$actor,$cid,$mid):int{return (int)$catalog->saveItem(['name'=>$name,'price'=>$price,'category_id'=>$cid,'menu_ids'=>[$mid],'lifecycle_status'=>'active','guest_visible'=>true,'staff_visible'=>true,'preparation_station'=>'kitchen','available'=>true],$actor)['id'];};
$parent=$newItem('R222 parent stable',35000);$fixed=$newItem('R222 ingredient cake',10000);$choice=$newItem('R222 selection tea',12000);$other=$newItem('R222 second variant',42000);
$code='R222-INV-'.bin2hex(random_bytes(5));$pdo->prepare("INSERT INTO inventory_items(item_code,name,category,base_unit,active,review_status) VALUES(?,?, 'ingredient','g',1,'ready')")->execute([$code,'R222 synthetic cocoa']);$inv=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO inventory_balances(inventory_item_id,quantity_base,average_unit_cost,cost_status) VALUES(?,100000,3.000000,'known')")->execute([$inv]);
$baseItem=['id'=>$parent,'name'=>'R222 parent amended','price'=>35000,'category_id'=>$cid,'menu_ids'=>[$mid],'lifecycle_status'=>'active','guest_visible'=>true,'staff_visible'=>true,'preparation_station'=>'kitchen','available'=>true];
$full=[
  'item'=>$baseItem,
  'recipe'=>['components'=>[['inventory_item_id'=>$inv,'quantity_base'=>5,'quantity_status'=>'known','source_note'=>null]],'reviewed'=>true,'complete'=>true],
  'variant'=>['enabled'=>true,'id'=>0,'name'=>'R222 pair','category_id'=>$cid,'members'=>[['item_id'=>$parent,'label'=>'Small'],['item_id'=>$other,'label'=>'Large']]],
  'suggested_item_ids'=>[$fixed],
  'bundle'=>['enabled'=>true,'had_bundle'=>false,'choice_label'=>'Select one','components'=>[
    ['component_item_id'=>$fixed,'mode'=>'fixed','quantity'=>2,'price_delta'=>3000],
    ['component_item_id'=>$choice,'mode'=>'choice','quantity'=>1,'price_delta'=>-1000],
  ]],
];
$success=$catalog->saveCompleteItem($full,$actor,$orders);
$check($success['id']===$parent&&$success['atomic']===true&&$success['recipe_updated']===true,'ALL_EDITOR_SECTIONS_ONE_TRANSACTION');
$stored=(string)$pdo->query('SELECT name FROM items WHERE id='.$parent)->fetchColumn();
$check($stored==='R222 parent amended','ITEM_UPDATED');
$recipeId=(int)$pdo->query("SELECT id FROM inventory_recipe_versions WHERE menu_item_id=$parent AND status='active' ORDER BY id DESC LIMIT 1")->fetchColumn();
$check($recipeId>0,'RECIPE_SAVED');
$qty=(int)$pdo->query("SELECT quantity_base FROM inventory_recipe_components WHERE recipe_version_id=$recipeId LIMIT 1")->fetchColumn();
$check($qty===5,'RECIPE_CONTENTS_MATCH');
$familyId=(int)$pdo->query('SELECT family_id FROM item_variant_members WHERE item_id='.$parent.' LIMIT 1')->fetchColumn();
$check($familyId>0,'VARIANT_FAMILY_SAVED');
$check((int)$pdo->query('SELECT COUNT(*) FROM item_suggestions WHERE source_item_id='.$parent)->fetchColumn()===1,'SUGGESTION_SAVED');
$check((int)$pdo->query('SELECT COUNT(*) FROM item_bundle_components WHERE bundle_item_id='.$parent)->fetchColumn()===2,'BUNDLE_FIXED_AND_CHOICE_SAVED');
$full['variant']['id']=$familyId;$full['bundle']['had_bundle']=true;
$bad=$full;
$bad['item']['name']='R222 SHOULD ROLL BACK';$bad['recipe']['components'][0]['quantity_base']=99;
$bad['variant']=['enabled'=>false,'id'=>$familyId];$bad['suggested_item_ids']=[];
$bad['bundle']['components'][1]['price_delta']=-99999999;
try{$catalog->saveCompleteItem($bad,$actor,$orders);throw new RuntimeException('R222_INVALID_BUNDLE_SAVED');}
catch(\Sokna\Local\Domain\Sellables\CatalogAdminException $e){$check($e->errorCode==='bundle_price_invalid','INVALID_BUNDLE_REJECTED');}
$check(!$pdo->inTransaction(),'INVALID_BUNDLE_TX_CLOSED');
$check((string)$pdo->query('SELECT name FROM items WHERE id='.$parent)->fetchColumn()==='R222 parent amended','ITEM_ROLLED_BACK');
$check((int)$pdo->query("SELECT id FROM inventory_recipe_versions WHERE menu_item_id=$parent AND status='active' LIMIT 1")->fetchColumn()===$recipeId,'RECIPE_VERSION_ROLLED_BACK');
$check((int)$pdo->query("SELECT quantity_base FROM inventory_recipe_components WHERE recipe_version_id=$recipeId LIMIT 1")->fetchColumn()===5,'RECIPE_AMOUNT_ROLLED_BACK');
$check((int)$pdo->query('SELECT COUNT(*) FROM item_variant_members WHERE family_id='.$familyId)->fetchColumn()===2,'VARIANT_REMOVAL_ROLLED_BACK');
$check((int)$pdo->query('SELECT COUNT(*) FROM item_suggestions WHERE source_item_id='.$parent)->fetchColumn()===1,'SUGGESTION_CLEAR_ROLLED_BACK');
$check((int)$pdo->query('SELECT COUNT(*) FROM item_bundle_components WHERE bundle_item_id='.$parent)->fetchColumn()===2,'BUNDLE_RETAINED_AFTER_FAILURE');
$badRecipe=$full;$badRecipe['item']['name']='R222 INVALID RECIPE';
$badRecipe['recipe']['components']=[['inventory_item_id'=>987654321,'quantity_base'=>2]];
try{$catalog->saveCompleteItem($badRecipe,$actor,$orders);throw new RuntimeException('R222_INVALID_RECIPE_SAVED');}
catch(\Sokna\Local\Domain\Inventory\InventoryException $e){$check($e->errorCode==='recipe_item_missing','INVALID_RECIPE_REJECTED');}
$check((string)$pdo->query('SELECT name FROM items WHERE id='.$parent)->fetchColumn()==='R222 parent amended','INVALID_RECIPE_ROLLS_BACK_ITEM');
$withoutRecipe=$full;$withoutRecipe['recipe']=null;$withoutRecipe['item']['name']='R222 no recipe touch';
$catalog->saveCompleteItem($withoutRecipe,$actor,$orders);
$check((int)$pdo->query("SELECT id FROM inventory_recipe_versions WHERE menu_item_id=$parent AND status='active'")->fetchColumn()===$recipeId,'RECIPE_UNAVAILABLE_PRESERVES_EXISTING');
// New-item rollback when a late bundle validation fails: no partially created catalog record.
$fresh=$full;$fresh['item']['id']=0;$fresh['item']['name']='R222 NEW MUST ROLLBACK';
$fresh['recipe']=null;$fresh['variant']=['enabled'=>false,'id'=>0];$fresh['bundle']['components'][1]['price_delta']=-99999999;
try{$catalog->saveCompleteItem($fresh,$actor,$orders);throw new RuntimeException('R222_INVALID_NEW_ITEM_SAVED');}
catch(\Sokna\Local\Domain\Sellables\CatalogAdminException $e){$check($e->errorCode==='bundle_price_invalid','NEW_ITEM_LATE_FAILURE_REJECTED');}
$q=$pdo->prepare('SELECT COUNT(*) FROM items WHERE name=?');$q->execute(['R222 NEW MUST ROLLBACK']);$check((int)$q->fetchColumn()===0,'NEW_ITEM_LATE_FAILURE_NO_PARTIAL_ROW');
$check(!$pdo->inTransaction(),'COMPOSITE_NO_LEFTOVER_TRANSACTION');
// Legacy one-step API must still commit independently, preserving backwards compatibility.
$catalog->saveSuggestions($parent,[$choice],$actor);
$check((int)$pdo->query('SELECT suggested_item_id FROM item_suggestions WHERE source_item_id='.$parent)->fetchColumn()===$choice,'LEGACY_STANDALONE_API_COMMITS');
// Cost preview uses the same active component recipes as inventory order snapshots.
// Parent recipe (5 units) is intentionally different, and must NOT be used for the bundle.
$orders->saveRecipeEditor($fixed,[['inventory_item_id'=>$inv,'quantity_base'=>10,'quantity_status'=>'known']],$actor,true,true);
$orders->saveRecipeEditor($choice,[['inventory_item_id'=>$inv,'quantity_base'=>4,'quantity_status'=>'known']],$actor,true,true);
$source=$orders->recipeEditorSnapshot($actor);
$fixedRow=$pdo->query('SELECT id,name,preparation_station FROM items WHERE id='.$fixed)->fetch();
$choiceRow=$pdo->query('SELECT id,name,preparation_station FROM items WHERE id='.$choice)->fetch();
$fixture=[
 'components'=>[['component_item_id'=>$fixed,'mode'=>'fixed','quantity'=>2,'price_delta'=>3000],['component_item_id'=>$choice,'mode'=>'choice','quantity'=>1,'price_delta'=>-1000]],
 'basePrice'=>35000,'items'=>[$fixedRow,$choiceRow],
 'recipes'=>$source['recipes'],'inventory'=>$source['inventory_items'],
 'expectedPrice'=>37000,'expectedCost'=>72,
];
$fixtureFile=(string)getenv('RUNNER_TEMP').'/R222-db-recipe-cost.json';
file_put_contents($fixtureFile,json_encode($fixture,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
$check(is_file($fixtureFile),'REAL_DB_COST_FIXTURE_CREATED');
echo 'PASS_R222_ATOMIC_EDITOR_REAL_MARIADB_'.$checkCount."_CHECKS\n";
