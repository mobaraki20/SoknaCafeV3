<?php
declare(strict_types=1);
// Real database test, ONLY a fresh isolated CI BrowserSetup installation.
if(getenv('GITHUB_ACTIONS')!=='true'||getenv('SOKNA_R232_DISPOSABLE_ONLY')!=='YES')throw new RuntimeException('REFUSE_NON_DISPOSABLE');
$web=realpath((string)getenv('SOKNA_R213_WEB'));$tmp=realpath((string)getenv('RUNNER_TEMP'));
if(!$web||!$tmp||!str_starts_with($web,$tmp.DIRECTORY_SEPARATOR))throw new RuntimeException('NOT_RUNNER_TEMP');
$cfgFile=dirname($web).'/config.php';if(!is_file($cfgFile))throw new RuntimeException('UNINSTALLED');
require_once $web.'/bootstrap.php';$cfg=require $cfgFile;$core=sokna_local_bootstrap($cfg);
$pdo=$core->database();$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$uid=(int)$pdo->query("SELECT id FROM users WHERE role='admin' AND active=1 ORDER BY id LIMIT 1")->fetchColumn();
$actor=['id'=>$uid,'role'=>'admin'];$svc=$core->catalogAdmin();$checks=0;
$ok=function(bool $v,string $name)use(&$checks):void{if(!$v)throw new RuntimeException('FAIL_R232_'.$name);++$checks;echo 'PASS_R232_'.$name."\n";};
$code=bin2hex(random_bytes(5));
$expect=function(callable $cb,string $code)use($ok):void{
 try{$cb();throw new RuntimeException('EXPECTED_REJECTION_'.$code);}catch(\Sokna\Local\Domain\Sellables\CatalogAdminException $e){$ok($e->errorCode===$code,'GUARD_'.$code);}
};
$menu=(int)$pdo->query("SELECT id FROM menus WHERE status='active' ORDER BY id LIMIT 1")->fetchColumn();
$ok($menu>0,'ACTIVE_MENU_IN_REAL_INSTALL');
$makeCat=function(string $name,array $m=[])use($svc,$actor):int{return (int)$svc->saveCategory(['name'=>$name,'lifecycle_status'=>'active','menu_ids'=>$m,'guest_visible'=>true,'staff_visible'=>true],$actor)['id'];};
// 1: Empty archived category can be deleted; active category and wrong confirmation cannot.
$emptyName='R232 empty '.$code;$cid=$makeCat($emptyName);
$ok(!$svc->categoryDeletePreview($cid,$actor)['can_delete'],'ACTIVE_CATEGORY_CANNOT_DELETE');
$expect(fn()=>$svc->permanentlyDeleteArchivedCategory($cid,$emptyName,$actor),'category_not_retired');
$svc->retireCategory($cid,'R232 synthetic fixture',$actor);
$ok($svc->categoryDeletePreview($cid,$actor)['can_delete'],'EMPTY_ARCHIVED_CATEGORY_PREVIEW');
$expect(fn()=>$svc->permanentlyDeleteArchivedCategory($cid,'WRONG',$actor),'category_delete_name_mismatch');
$ok((int)$pdo->query("SELECT COUNT(*) FROM categories WHERE id=$cid")->fetchColumn()===1,'WRONG_CONFIRMATION_NO_DELETE');
$res=$svc->permanentlyDeleteArchivedCategory($cid,$emptyName,$actor);
$ok(($res['deleted']??false)===true&&(int)$pdo->query("SELECT COUNT(*) FROM categories WHERE id=$cid")->fetchColumn()===0,'EMPTY_CATEGORY_PERMANENT_DELETION');
$ok((int)$pdo->query("SELECT COUNT(*) FROM audit_log WHERE action='catalog.category_permanently_deleted' AND entity_id=$cid")->fetchColumn()>=1,'CATEGORY_DELETE_AUDIT_LOG');
$expect(fn()=>$svc->permanentlyDeleteArchivedCategory($cid,$emptyName,$actor),'category_missing');
// 2: Retired child item continues to protect retired category from deletion.
$catChildName='R232 with archived item '.$code;$cchild=$makeCat($catChildName,[$menu]);
$mkItem=function(int $cat,string $name,bool $featured=false,int $sort=10)use($actor,$svc,$menu):int{
 return (int)$svc->saveItem(['name'=>$name,'category_id'=>$cat,'price'=>25000,'featured'=>$featured,'sort_order'=>$sort,'lifecycle_status'=>'active','guest_visible'=>true,'staff_visible'=>true,'available'=>true,'preparation_station'=>'hot_bar','menu_ids'=>[$menu]],$actor)['id'];
};
$iid=$mkItem($cchild,'R232 retired child '.$code);
$svc->retireItem($iid,'Synthetic',$actor);$svc->retireCategory($cchild,'Synthetic',$actor);
$p=$svc->categoryDeletePreview($cchild,$actor);
$ok($p['item_count']>=1&&$p['can_delete']===false,'RETIRED_ITEM_STILL_PROTECTS_CATEGORY');
$expect(fn()=>$svc->permanentlyDeleteArchivedCategory($cchild,$catChildName,$actor),'category_delete_has_items');
$ok((int)$pdo->query("SELECT COUNT(*) FROM items WHERE id=$iid")->fetchColumn()===1,'RETIRED_ITEM_RETAINED');
// 3: 56 retired synthetic items all show in catalog without LIMIT 50.
$moreCatName='R232 bulk archive '.$code;$bulkCat=$makeCat($moreCatName,[$menu]);
$bulkIds=[];for($j=0;$j<56;$j++){$id=$mkItem($bulkCat,'R232 archived '.$code.' '.$j);$bulkIds[]=$id;$svc->retireItem($id,'Archive test',$actor);}
$bulk=$svc->snapshot($actor);$visibleIds=array_map(static fn($i)=>(int)$i['id'],$bulk['archived_items']);
$ok(count(array_intersect($bulkIds,$visibleIds))===56,'ALL_56_RETIRED_ITEMS_VISIBLE_DB');
$ok(($bulk['archive_counts']['items']??0)>=56&&($bulk['archive_counts']['categories']??-1)>=1,'ARCHIVE_COUNTS_DISTINCT');
// 4: featured reordering is independent of normal sort and propagated by actual PublicProjectionBuilder.
$featureCat=$makeCat('R232 featured '.$code,[$menu]);
$ids=[];for($j=0;$j<6;$j++)$ids[]=$mkItem($featureCat,'R232 feature '.$code.' '.$j,true,100+$j);
$before=$svc->snapshot($actor);$existing=array_map('intval',$before['featured_order']);
$ok(count(array_intersect($ids,$existing))===6,'SIX_FEATURED_IN_SNAPSHOT');
$testOrder=array_values(array_reverse($existing));
$stored=$svc->reorderFeaturedItems($testOrder,$actor);
$ok($stored['featured_order']===$testOrder&&$stored['visible_limit']===5,'FEATURED_REORDER_PERSISTS');
$after=$svc->snapshot($actor);
$ok($after['featured_order']===$testOrder,'FEATURED_REORDER_READBACK');
$normal=$pdo->prepare('SELECT id,sort_order FROM items WHERE id IN('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY sort_order,id');$normal->execute($ids);$orderRows=$normal->fetchAll();
$ok(array_map('intval',array_column($orderRows,'id'))===$ids,'NORMAL_CATALOG_SORT_UNCHANGED');
$expect(fn()=>$svc->reorderFeaturedItems([$ids[0],$ids[0]],$actor),'featured_order_invalid');
$expect(fn()=>$svc->reorderFeaturedItems(array_slice($testOrder,1),$actor),'featured_order_changed');
$pub=$core->publicProjectionBuilder()->guestPublish();
$items=[];foreach((array)($pub['snapshot']['catalogs']??[]) as $catalog)foreach((array)($catalog['items']??[]) as $item)$items[(int)$item['id']]=$item;
$ranked=[];foreach($ids as $id){if(isset($items[$id]))$ranked[$id]=$items[$id]['featured_position']??null;}
$ok(count($ranked)===6,'SIX_FEATURED_IN_PUBLIC_PROJECTION');
foreach($ids as $id){$expected=array_search($id,$testOrder,true)+1;$ok((int)$ranked[$id]===$expected,'PUBLIC_RANK_'.$id);}
$ok((int)$pdo->query("SELECT COUNT(*) FROM schema_migrations")->fetchColumn()===81,'UNCHANGED_81_MIGRATIONS');
$ok(!$pdo->inTransaction(),'NO_LEAKED_TRANSACTION');
echo 'PASS_R232_REAL_MARIADB_ARCHIVE_DELETE_AND_FEATURED_'.$checks."_CHECKS\n";