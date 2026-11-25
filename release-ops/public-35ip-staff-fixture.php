<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
$mode=$argv[1]??'';$base='/var/www/sokna-public-host-gate';
$roots=['root'=>'','sub'=>'public/menu','upgrade'=>'','older'=>'public/menu','browser306'=>'','current'=>'public'];
foreach($roots as $name=>$sub){
    // separate PHP processes: exact product classes must not be shared across versions.
    $root=$base.'/'.$name.'/webroot/'.$sub;
    $cmd=[PHP_BINARY,__FILE__,'one',$root,'sokna-ci-'.$name,$mode];
    if($mode==='one')break;
    $p=proc_open($cmd,[0=>['pipe','r'],1=>STDOUT,2=>STDERR],$pipes);
    fclose($pipes[0]);if(proc_close($p)!==0)throw new RuntimeException('fixture failed '.$name);
}
if($mode!=='one')exit(0);
$root=$argv[2];$id=$argv[3];$phase=$argv[4];
if(!str_starts_with($root,$base.'/'))throw new RuntimeException('isolated roots only');
require $root.'/bootstrap.php';$core=sokna_public_bootstrap(require $root.'/config.php');
if($phase==='missing'){
    $count=$core->authProjections()->sync($id,[['projection_id'=>'user:1','username'=>'staff-ci','display_name'=>'Staff CI','role'=>'admin','password_hash'=>password_hash('disposable-staff-ci-20261124',PASSWORD_DEFAULT),'capabilities'=>['*'],'preparation_areas'=>[],'projection_version'=>1,'active'=>true]]);
    if($count!==1)throw new RuntimeException('auth projection fixture failed');
}else{
    $now=new DateTimeImmutable('now',new DateTimeZone('Asia/Tehran'));$day=$now->format('Y-m-d');$clock=['business_date'=>$day,'cutoff'=>'00:00'];$stamp=$now->format(DATE_ATOM);
    $payloads=['reports'=>['business_clock'=>$clock,'dashboard'=>['range'=>['from'=>$day,'to'=>$day],'today'=>['net_sales'=>250000,'order_count'=>2,'avg_ticket'=>125000],'top_items'=>[]],'dashboard_activity'=>['business_date'=>$day,'items'=>[['title'=>'CI operational event','body'=>'test','created_at'=>$stamp]]]],'operations'=>['hall'=>['metrics'=>['open_tables'=>2],'attention_orders'=>[],'waiter_calls'=>[]]],'operations_workspace'=>['inventory'=>['items'=>[['id'=>1,'name'=>'CI inventory','quantity_base'=>2,'warning_threshold'=>3,'base_unit'=>'count','active'=>1]]]],'notifications'=>['business_clock'=>$clock,'items'=>[['id'=>1,'title'=>'CI notice','body'=>'fixture','created_at'=>$stamp,'severity'=>'critical']]]];
    $models=[];foreach($payloads as $k=>$payload)$models[]=['format'=>'sokna-remote-read-v1','model_key'=>$k,'source_version'=>\Sokna\PublicEdge\Core\CanonicalJson::sha256($payload),'generated_at'=>$stamp,'payload'=>$payload];
    $result=$core->remoteReadModels()->sync($id,['models'=>$models]);if(($result['status']??0)!==200||($result['body']['invalid']??0)!==0)throw new RuntimeException('model fixture failed');
    $core->connectivity()->heartbeat($id,'disposable-local','online');
}
echo "FIXTURE $phase $id\n";
