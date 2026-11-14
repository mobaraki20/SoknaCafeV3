<?php
declare(strict_types=1);
// Disposable CI only. Creates synthetic fixture in the throwaway BrowserSetup installation.
if(getenv('GITHUB_ACTIONS')!=='true'||getenv('SOKNA_R225_DISPOSABLE_ONLY')!=='YES')throw new RuntimeException('CI_DISPOSABLE_ONLY');
$web=realpath((string)getenv('SOKNA_R213_WEB'));$tmp=realpath((string)getenv('RUNNER_TEMP'));
if(!$web||!$tmp||!str_starts_with($web,$tmp.DIRECTORY_SEPARATOR))throw new RuntimeException('NON_TEMP_REFUSED');
$root=dirname($web);$cfgFile=$root.'/config.php';if(!is_file($cfgFile))throw new RuntimeException('NOT_INSTALLED');
$cfg=require $cfgFile;
$cfg['lan_guest']['enabled']=true; // Explicitly enabled ONLY in disposable QA instance, not the product default.
file_put_contents($cfgFile,"<?php\nreturn ".var_export($cfg,true).";\n");
require_once $web.'/bootstrap.php';
$core=sokna_local_bootstrap($cfg);$pdo=$core->database();$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
if((int)$pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn()!==81)throw new RuntimeException('MIGRATIONS_UNEXPECTED');
$uid=(int)$pdo->query("SELECT id FROM users WHERE role='admin' AND active=1 ORDER BY id LIMIT 1")->fetchColumn();
$idRepo=new \Sokna\Local\Core\PdoIdentityRepository($pdo);
$icons=new \Sokna\Local\Domain\Sellables\CategoryIconLibrary($web.'/resources/default-content/v1/category-icons.json');
$catalog=new \Sokna\Local\Domain\Sellables\CatalogAdminService($pdo,$idRepo,$icons);
$inventory=new \Sokna\Local\Domain\Inventory\InventoryService($pdo,$idRepo,new \Sokna\Local\Core\Capabilities($idRepo));
$invOrders=new \Sokna\Local\Domain\Inventory\InventoryOrderService($pdo,$inventory);
$actor=['id'=>$uid,'role'=>'admin'];
$row=$pdo->query("SELECT c.id category_id,m.id menu_id FROM categories c JOIN menu_categories mc ON mc.category_id=c.id JOIN menus m ON m.id=mc.menu_id WHERE c.lifecycle_status='active' AND c.guest_visible=1 AND m.status='active' LIMIT 1")->fetch();
if(!is_array($row))throw new RuntimeException('NO_ACTIVE_GUEST_MENU');
$cid=(int)$row['category_id'];$mid=(int)$row['menu_id'];
$newItem=static fn(string $name,int $price):int=>(int)$catalog->saveItem(['name'=>$name,'price'=>$price,'category_id'=>$cid,'menu_ids'=>[$mid],'lifecycle_status'=>'active','guest_visible'=>true,'staff_visible'=>true,'preparation_station'=>'kitchen','available'=>true],$actor)['id'];
$parent=$newItem('R225 HTTP QA bundle parent',35000);$fixed=$newItem('R225 HTTP QA fixed',10000);$choice=$newItem('R225 HTTP QA choice',12000);
$code='R225-ING-'.bin2hex(random_bytes(5));
$pdo->prepare("INSERT INTO inventory_items(item_code,name,category,base_unit,active,review_status) VALUES(?,?,'ingredient','g',1,'ready')")->execute([$code,'R225 ephemeral ingredient']);
$inv=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO inventory_balances(inventory_item_id,quantity_base,average_unit_cost,cost_status) VALUES(?,100000,3.000000,'known')")->execute([$inv]);
$invOrders->saveRecipeEditor($fixed,[['inventory_item_id'=>$inv,'quantity_base'=>10,'quantity_status'=>'known']],$actor,true,true);
$invOrders->saveRecipeEditor($choice,[['inventory_item_id'=>$inv,'quantity_base'=>4,'quantity_status'=>'known']],$actor,true,true);
$bundle=$catalog->saveCompleteItem([
  'item'=>['id'=>$parent,'name'=>'R225 HTTP QA bundle parent','price'=>35000,'category_id'=>$cid,'menu_ids'=>[$mid],'lifecycle_status'=>'active','guest_visible'=>true,'staff_visible'=>true,'preparation_station'=>'kitchen','available'=>true],
  'recipe'=>null,'variant'=>['enabled'=>false,'id'=>0],
  'suggested_item_ids'=>[],
  'bundle'=>['enabled'=>true,'had_bundle'=>false,'choice_label'=>'Choose','components'=>[
    ['component_item_id'=>$fixed,'mode'=>'fixed','quantity'=>2,'price_delta'=>3000],
    ['component_item_id'=>$choice,'mode'=>'choice','quantity'=>1,'price_delta'=>-1000],
  ]],
],$actor,$invOrders);
if((int)($bundle['id']??0)!==$parent)throw new RuntimeException('BUNDLE_FIXTURE_SAVE_FAILED');
$pdo->prepare("INSERT INTO settings(setting_key,setting_value) VALUES('inventory_initialized','1') ON DUPLICATE KEY UPDATE setting_value='1'")->execute();
$pdo->prepare("INSERT INTO settings(setting_key,setting_value) VALUES('printing.quick.auto_preparation','0') ON DUPLICATE KEY UPDATE setting_value='0'")->execute();
$table=$pdo->query('SELECT id,access_token FROM cafe_tables WHERE active=1 ORDER BY id LIMIT 1')->fetch();
if(!is_array($table))throw new RuntimeException('TABLE_MISSING');
$out=['db'=>(string)$cfg['db']['name'],'table_token'=>(string)$table['access_token'],'table_id'=>(int)$table['id'],'parent'=>$parent,'fixed'=>$fixed,'choice'=>$choice,'inventory'=>$inv,'unit_price'=>37000,'unit_cost'=>72,'admin_id'=>$uid];
file_put_contents((string)getenv('RUNNER_TEMP').'/r225-fixture.json',json_encode($out,JSON_THROW_ON_ERROR));
echo "PASS_R225_REAL_SETUP_81_AND_BUNDLE_INVENTORY_FIXTURE\n";