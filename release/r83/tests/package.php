<?php
declare(strict_types=1);
$root=$argv[1];$dist=$argv[2];
require $root.'/local/src/Domain/PublicEdge/PublicEdgeSyncException.php';
require $root.'/local/src/Domain/PublicEdge/PublicUpdatePackagePreflight.php';
require dirname(__DIR__,3).'/apps/public/src/Emergency/PublicUpdateException.php';
require $root.'/public/src/Update/RoutinePublicUpdateService.php';
$old=file_get_contents(dirname($root).'/baseline/public/VERSION.txt');$map=[];
$base=dirname($root).'/baseline/public';$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS));foreach($it as $p)if($p->isFile())$map[str_replace('\\','/',substr($p->getPathname(),strlen($base)+1))]=hash_file('sha256',$p->getPathname());ksort($map);$h=hash_init('sha256');foreach($map as $n=>$v)hash_update($h,$n."\0".$v."\n");$tree=hash_final($h);
$status=['routine_update'=>['current_version'=>trim($old),'active_version'=>trim($old),'managed_tree_sha256'=>$tree],'protocols'=>['public_multi_baseline_exact_v1'=>true]];
$path=$dist.'/SOKNA-R83-PUBLIC-R81-TO-R83-ROUTINE-DELTA-HOME-UAT.zip';
$r=Sokna\Local\Domain\PublicEdge\PublicUpdatePackagePreflight::inspect($path,$status);
if($r['mode']!=='legacy_exact_delta')throw new RuntimeException('positive preflight');
$bad=$status;$bad['routine_update']['managed_tree_sha256']=str_repeat('0',64);$blocked=false;try{Sokna\Local\Domain\PublicEdge\PublicUpdatePackagePreflight::inspect($path,$bad);}catch(Throwable){$blocked=true;}if(!$blocked)throw new RuntimeException('wrong base accepted');
$svc=new Sokna\PublicEdge\Update\RoutinePublicUpdateService($base,sys_get_temp_dir().'/r83-package-'.bin2hex(random_bytes(5)),sys_get_temp_dir().'/r83-no-trust');
$staged=$svc->stageUploadedDeltaZip($path,'r83-qa',false);
if($staged['version']!==$r['version'])throw new RuntimeException('stage version differs');
echo json_encode(['positive_preflight'=>true,'wrong_baseline_rejected'=>true,'native_zip_receiver_stage'=>true,'live_host_activate'=>'NOT_RUN']).PHP_EOL;
