<?php
declare(strict_types=1);
$root=$argv[1];require $root.'/public/src/Remote/RemoteStaffPageRenderer.php';
$class=new ReflectionClass(Sokna\PublicEdge\Remote\RemoteStaffPageRenderer::class);$host=$class->newInstanceWithoutConstructor();$class->getProperty('basePath')->setValue($host,'');
$source=file_get_contents($root.'/public/src/Remote/RemoteStaffPageRenderer.php');$results=[];
foreach(['operator','offlineBootstrap'] as $method){
 $start=strpos($source,'function '.$method.'(');$a=strpos($source,'$hallConfig=[',$start);$end=strpos($source,'$hallAsset=',$a);if($method==='offlineBootstrap')$end=strpos($source,'require dirname',$a);
 $fragment=substr($source,$a,$end-$a);
 foreach([[true,false],[false,true],[true,true],[false,false]] as [$order,$cashier]){
  $run=Closure::bind(function($fragment,$canOrder,$canCashier){eval($fragment);return $hallConfig;},$host,$class->getName());$config=$run($fragment,$order,$cashier);
  foreach(['committed_adjust'=>$order,'reprint'=>$order||$cashier,'discount'=>$cashier] as $key=>$expected)$results[]=['test'=>$method.' '.(int)$order.(int)$cashier.' '.$key.' follows role','pass'=>($config['features'][$key]??null)===$expected];
  $results[]=['test'=>$method.' supervision remains explicit','pass'=>$config['can_supervise']===false&&$config['features']['settlement_subscriber']===false&&$config['features']['settlement_accommodation']===false];
  $html=(static function($hallConfig,$root){$hallAsset=fn($p)=>$p;ob_start();require $root.'/public/resources/shared-staff-ui/hall-workspace.php';return ob_get_clean();})($config,$root);
  $results[]=['test'=>$method.' rendered controls carry authorized feature flags','pass'=>str_contains($html,'data-feature-committed-adjust="'.($order?'1':'0').'"')&&str_contains($html,'data-feature-discount="'.($cashier?'1':'0').'"')&&str_contains($html,'data-feature-reprint="'.($order||$cashier?'1':'0').'"')];
 }
}
echo json_encode($results,JSON_PRETTY_PRINT).PHP_EOL;exit(count(array_filter($results,fn($r)=>!$r['pass']))?1:0);
