<?php
declare(strict_types=1);
// Immutable CI-only contract: refuse any non-disposable host or source tree.
if (getenv('GITHUB_ACTIONS') !== 'true' || getenv('SOKNA_R212_DISPOSABLE_ONLY') !== 'YES') throw new RuntimeException('R221_DISPOSABLE_CI_ONLY');
$web=(string)getenv('SOKNA_R212_WEB');$temp=realpath((string)getenv('RUNNER_TEMP'));
if (!$temp || !is_file($web.'/bootstrap.php') || !str_starts_with((string)realpath($web),(string)$temp.DIRECTORY_SEPARATOR)) throw new RuntimeException('R221_REFUSE_NON_TEMP_WEB');
require_once $web.'/bootstrap.php';
use Sokna\Local\Domain\Sellables\BundlePricePolicy as Policy;
use Sokna\Local\Domain\Sellables\CatalogAdminException;
use Sokna\Local\Domain\Orders\OrderCommitException;
$pdo=new PDO('mysql:host=127.0.0.1;port=3306;dbname=sokna_r212_disposable;charset=utf8mb4','root',(string)getenv('SOKNA_R212_DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
if((int)$pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn()!==81)throw new RuntimeException('R221_MIGRATIONS_NOT_81');
$checkCount=0;
function checkR221(bool $condition,string $label):void {global $checkCount;if(!$condition)throw new RuntimeException('FAIL_R221_'.$label);$checkCount++;echo 'PASS_R221_REAL_DB_'.$label."\n";}
checkR221(Policy::unitPrice(35000,[7000,3000])===45000,'VALID_BUNDLE_PRICE');
checkR221(Policy::unitPrice(45000,[-45000])===0,'ZERO_PRICE_ALLOWED');
checkR221(Policy::unitPrice(45000,[-45001])===null,'NEGATIVE_UNIT_REJECTED');
checkR221(Policy::unitPrice(Policy::MAX_UNIT_PRICE,[1])===null,'OVERBOUND_UNIT_REJECTED');
checkR221(Policy::deltaSum([PHP_INT_MAX,PHP_INT_MAX])===null,'OVERFLOW_DELTA_REJECTED');
$row=$pdo->query("SELECT c.id category_id,m.id menu_id FROM categories c JOIN menu_categories mc ON mc.category_id=c.id JOIN menus m ON m.id=mc.menu_id WHERE c.lifecycle_status='active' AND c.guest_visible=1 AND m.status='active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if(!is_array($row))throw new RuntimeException('R221_SEED_HAS_NO_ACTIVE_MENU');
$cid=(int)$row['category_id'];$mid=(int)$row['menu_id'];
$create=function(string $name,int $price) use($pdo,$cid,$mid):int{
 $q=$pdo->prepare("INSERT INTO items(category_id,name,price,available,active,guest_visible,staff_visible,lifecycle_status,preparation_station,takeaway_allowed) VALUES(?,?,?,1,1,1,1,'active','kitchen',1)");$q->execute([$cid,$name,$price]);$id=(int)$pdo->lastInsertId();$pdo->prepare('INSERT INTO menu_items(menu_id,item_id) VALUES(?,?)')->execute([$mid,$id]);return $id;
};
$parent=$create('R221 parent',35000);$fixed=$create('R221 fixed',15000);$choiceA=$create('R221 choice A',12000);$choiceB=$create('R221 choice B',13000);
$uid=(int)$pdo->query("SELECT id FROM users WHERE role='admin' AND active=1 ORDER BY id LIMIT 1")->fetchColumn();if($uid<1)throw new RuntimeException('R221_NO_ADMIN');
$idRepo=new \Sokna\Local\Core\PdoIdentityRepository($pdo);
$icons=new \Sokna\Local\Domain\Sellables\CategoryIconLibrary($web.'/resources/default-content/v1/category-icons.json');
$adminService=new \Sokna\Local\Domain\Sellables\CatalogAdminService($pdo,$idRepo,$icons);
$orderService=new \Sokna\Local\Domain\Orders\OrderCatalogService($pdo);
$components=function(int $fixedDelta,int $choiceDeltaA,int $choiceDeltaB) use($fixed,$choiceA,$choiceB):array{return [
 ['component_item_id'=>$fixed,'mode'=>'fixed','quantity'=>2,'price_delta'=>$fixedDelta],
 ['component_item_id'=>$choiceA,'mode'=>'choice','quantity'=>1,'price_delta'=>$choiceDeltaA],
 ['component_item_id'=>$choiceB,'mode'=>'choice','quantity'=>1,'price_delta'=>$choiceDeltaB],
];};
$payload=function(array $components)use($parent):array{return ['bundle_item_id'=>$parent,'choice_label'=>'انتخاب شیرینی','components'=>$components];};
$adminService->saveBundle($payload($components(-2000,3000,5000)),['id'=>$uid]);
$rows=$pdo->query('SELECT price_delta FROM item_bundle_components WHERE bundle_item_id='.$parent.' ORDER BY sort_order,id')->fetchAll(PDO::FETCH_COLUMN);
checkR221(count($rows)===3,'VALID_ADMIN_SAVE');
$request=[['id'=>$parent,'quantity'=>2,'bundle_choice_item_id'=>$choiceA,'expected_price'=>36000,'fulfillment_mode'=>'dine_in']];
$pdo->beginTransaction();$line=$orderService->snapshotRowsTx($orderService->normalizeRows($request),'guest');$pdo->rollBack();
checkR221(count($line)===1 && $line[0]['unit_price']===36000 && $line[0]['line_total']===72000,'GUEST_SERVER_QUOTE_AND_QUANTITY');
try{$adminService->saveBundle($payload($components(-30000,-6000,1000)),['id'=>$uid]);throw new RuntimeException('R221_ADMIN_NEGATIVE_ACCEPTED');}
catch(CatalogAdminException $e){checkR221($e->errorCode==='bundle_price_invalid','ADMIN_REJECTS_ONE_INVALID_CHOICE');}
$after=$pdo->query('SELECT price_delta FROM item_bundle_components WHERE bundle_item_id='.$parent.' ORDER BY sort_order,id')->fetchAll(PDO::FETCH_COLUMN);
checkR221($after===$rows,'ADMIN_REJECT_PRESERVES_PREVIOUS_BUNDLE');
try{$adminService->saveBundle($payload($components(PHP_INT_MAX,0,0)),['id'=>$uid]);throw new RuntimeException('R221_ADMIN_HUGE_ACCEPTED');}
catch(CatalogAdminException $e){checkR221($e->errorCode==='bundle_price_invalid','ADMIN_REJECTS_OVERFLOW');}
$pdo->prepare('UPDATE item_bundle_components SET price_delta=-? WHERE bundle_item_id=? AND component_item_id=?')->execute([40000,$parent,$fixed]);
$pdo->beginTransaction();
try{$orderService->snapshotRowsTx($orderService->normalizeRows($request),'guest');throw new RuntimeException('R221_LEGACY_INVALID_GUEST_ACCEPTED');}
catch(OrderCommitException $e){checkR221($e->errorCode==='bundle_price_invalid','LEGACY_INVALID_GUEST_REJECTED');}
$pdo->rollBack();
$pdo->beginTransaction();
try{$orderService->snapshotDraftRowsTx($orderService->normalizeRows($request),[]);throw new RuntimeException('R221_LEGACY_INVALID_DRAFT_ACCEPTED');}
catch(OrderCommitException $e){checkR221($e->errorCode==='bundle_price_invalid','LEGACY_INVALID_STAFF_DRAFT_REJECTED');}
$pdo->rollBack();
$pdo->prepare('UPDATE item_bundle_components SET price_delta=? WHERE bundle_item_id=? AND component_item_id=?')->execute([-2000,$parent,$fixed]);
$pdo->beginTransaction();$line=$orderService->snapshotRowsTx($orderService->normalizeRows($request),'guest');$pdo->rollBack();
checkR221($line[0]['unit_price']===36000,'VALID_ORDER_STILL_WORKS_AFTER_REJECT');
$pdo->prepare('UPDATE items SET price=? WHERE id=?')->execute([Policy::MAX_UNIT_PRICE,$parent]);
$pdo->beginTransaction();
try{$orderService->snapshotRowsTx($orderService->normalizeRows([['id'=>$parent,'quantity'=>1,'bundle_choice_item_id'=>$choiceB,'fulfillment_mode'=>'dine_in']]),'guest');throw new RuntimeException('R221_ABOVE_SAFE_PRICE_ACCEPTED');}
catch(OrderCommitException $e){checkR221($e->errorCode==='bundle_price_invalid','OLD_BUNDLE_NOW_OVERPRICE_BLOCKED');}
$pdo->rollBack();
checkR221($checkCount>=14,'SCENARIOS_COMPLETE');
echo 'PASS_R221_REAL_MARIADB_SAFE_BUNDLE_PRICING_'.$checkCount."_CHECKS\n";
