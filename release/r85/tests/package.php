<?php
declare(strict_types=1);
$root=$argv[1];$dist=$argv[2];
require $root.'/local/src/Domain/PublicEdge/PublicEdgeSyncException.php';
require $root.'/local/src/Domain/PublicEdge/PublicUpdatePackagePreflight.php';
require dirname(__DIR__,3).'/apps/public/src/Emergency/PublicUpdateException.php';
require $root.'/public/src/Update/RoutinePublicUpdateService.php';
$results=[];
foreach(['R81'=>'r81','R83'=>'r83','R84'=>'baseline'] as $label=>$dir){
 $base=dirname($root).'/'.$dir.'/public';$map=[];
 $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS));foreach($it as $p)if($p->isFile())$map[str_replace('\\','/',substr($p->getPathname(),strlen($base)+1))]=hash_file('sha256',$p->getPathname());ksort($map);$h=hash_init('sha256');foreach($map as $n=>$v)hash_update($h,$n."\0".$v."\n");$tree=hash_final($h);
 $status=['routine_update'=>['current_version'=>trim(file_get_contents($base.'/VERSION.txt')),'active_version'=>trim(file_get_contents($base.'/VERSION.txt')),'managed_tree_sha256'=>$tree],'protocols'=>['public_multi_baseline_exact_v1'=>true]];
 $path=$dist.'/SOKNA-R85-PUBLIC-'.$label.'-TO-R85-ROUTINE-DELTA-HOME-UAT.zip';
 $r=Sokna\Local\Domain\PublicEdge\PublicUpdatePackagePreflight::inspect($path,$status);
 $results[]=['test'=>$label.' native preflight exact baseline','pass'=>$r['mode']==='legacy_exact_delta'];
 $bad=$status;$bad['routine_update']['managed_tree_sha256']=str_repeat('0',64);$blocked=false;try{Sokna\Local\Domain\PublicEdge\PublicUpdatePackagePreflight::inspect($path,$bad);}catch(Throwable){$blocked=true;}
 $results[]=['test'=>$label.' wrong baseline rejected','pass'=>$blocked];
 $svc=new Sokna\PublicEdge\Update\RoutinePublicUpdateService($base,sys_get_temp_dir().'/r85-package-'.bin2hex(random_bytes(5)),sys_get_temp_dir().'/r85-no-trust');
 $staged=$svc->stageUploadedDeltaZip($path,'r85-qa',false);
 $results[]=['test'=>$label.' actual PHP ZipArchive receiver stage','pass'=>$staged['version']===$r['version']];
}
echo json_encode($results,JSON_PRETTY_PRINT).PHP_EOL;
exit(count(array_filter($results,fn($x)=>!$x['pass']))?1:0);
