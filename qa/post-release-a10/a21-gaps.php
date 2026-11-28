<?php
declare(strict_types=1);
// Independent integration QA only. Never run against a deployed installation.
if(PHP_SAPI!=='cli'||getenv('SOKNA_QA_DISPOSABLE')!=='YES'||getenv('QA_DB_PASSWORD')!=='synthetic-cp116-ci-only')exit(2);
$root=realpath($argv[1]??'');$work=realpath(getenv('SOKNA_GATE_WORK')?:'/not-set');
if(!$root||!$work||$root!==$work.'/local'||!is_file($work.'/.disposable-gate'))exit(2);
$cfg=require $work.'/config.php';$db=$cfg['db']??[];
if(($db['host']??'')!=='127.0.0.1'||($db['name']??'')!=='sokna_cp150'||($db['pass']??'')!==getenv('QA_DB_PASSWORD'))exit(2);
require $root.'/bootstrap.php';$core=sokna_local_bootstrap($cfg);$p=$core->database();
function ck($condition,string $label): void {if(!$condition)throw new RuntimeException($label);echo "PASS $label\n";}
$actor=$p->query("SELECT id,role FROM users WHERE username='qa-target' AND role='admin'")->fetch(PDO::FETCH_ASSOC);ck(is_array($actor),'synthetic administrator');
$mode=$argv[2]??'';
if($mode==='print'){
 $service=$core->printTemplates();
 foreach(['customer','preparation'] as $kind){
  $base=(int)$service->installStandard($kind,$actor)['package_id'];$old=$service->details($base,$actor);$template=$old['template'];$oldFooter=(int)$template['footer_font_size'];$template['footer_font_size']=$oldFooter<72?$oldFooter+1:$oldFooter-1;
  $service->activate($base,$actor);$new=$service->saveVersion($base,['name'=>$old['package']['name'],'version'=>$old['suggested_version'],'template'=>$template],$actor);$id=(int)$new['package_id'];ck($id!==$base,"$kind distinct saved version");
  // New service object reads the persisted canonical DB record.
  $again=new \Sokna\Local\Domain\Printing\PrintTemplatePackageService($p);$got=$again->details($id,$actor);ck((int)$got['template']['footer_font_size']===$template['footer_font_size'],"$kind persisted footer value");
  ck($got['template']===$template,"$kind other settings preserved");ck($again->details($base,$actor)['template']===$old['template'],"$kind original immutable");
  $again->activate($id,$actor);ck($again->details($id,$actor)['active']===true,"$kind new active");$again->rollback($base,$actor);ck($again->details($base,$actor)['active']===true&&!$again->details($id,$actor)['active'],"$kind rollback to original");
 }
}elseif($mode==='catalog'){
 $catalog=$core->catalogAdmin();$menu=(int)$catalog->saveMenu(['name'=>'QA A21 active menu','status'=>'active','sort_order'=>-1000],$actor)['id'];
 $cat=(int)$catalog->saveCategory(['name'=>'QA A21 category','menu_ids'=>[$menu]],$actor)['id'];
 $ids=[];foreach(['Single','Small','Medium','Large'] as $i=>$name){$ids[]=(int)$catalog->saveItem(['name'=>'QA A21 '.$name,'category_id'=>$cat,'menu_ids'=>[$menu],'price'=>100000+10000*$i,'sort_order'=>$i],$actor)['id'];}
 $members=[];foreach(['small','medium','large'] as $i=>$label)$members[]=['item_id'=>$ids[$i+1],'label'=>$label,'sort_order'=>$i+1];
 $family=(int)$catalog->saveVariantFamily(['name'=>'QA A21 family','category_id'=>$cat,'members'=>$members],$actor)['id'];
 $models=$core->publicProjectionBuilder()->remoteModelsFor(['table_draft_context']);$payload=$models[0]['payload'];$rows=array_column($payload['catalog'],null,'id');$groups=array_column($payload['catalog_groups'],null,'id');
 foreach($ids as $i=>$id){ck(isset($rows[$id]),'catalog member '.$id);if($i>0)ck((int)$rows[$id]['variant_family_id']===$family,'variant member '.$id);}
 $category=array_column($groups[$menu]['categories']??[],null,'id');ck(($category[$cat]['item_ids']??[])===$ids,'all four members in real active menu group');
 $free=array_values(array_filter($payload['tables'],fn($t)=>(int)($t['session_id']??0)===0&&!(bool)($t['has_draft']??false)&&!(int)($t['draft_id']??0)));ck(count($free)>0,'free disposable table exists');
 $publisher=$core->publicEdgePublisher();$keys=['table_draft_context','operations','reports','subscribers','guest_operational'];$seq=$publisher->seedProjectionInvalidations($keys,'qa.a21.independent');ck($seq>0,'new read-model invalidation');
 $r=$publisher->syncProjectionModelsV2(250,8,20,null,$keys);foreach($keys as $key)ck(($r['models'][$key]['ok']??false)===true,'signed model ACK '.$key);ck(($publisher->syncHeartbeat(5)['success']??false)===true,'signed heartbeat');
 file_put_contents($work.'/gap-catalog.json',json_encode(['menu'=>$menu,'category'=>$cat,'family'=>$family,'ids'=>$ids,'table'=>(int)$free[0]['id']],JSON_THROW_ON_ERROR));
}else{exit(2);}
