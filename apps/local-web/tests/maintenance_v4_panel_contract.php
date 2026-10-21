<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Domain/Update/LocalUpdateException.php';
require dirname(__DIR__).'/src/Domain/Update/MaintenanceV4PanelService.php';

use Sokna\Local\Domain\Update\LocalUpdateException;
use Sokna\Local\Domain\Update\MaintenanceV4PanelService;

function check(bool $ok,string $message): void {if(!$ok)throw new RuntimeException('FAIL '.$message);}
function reject(callable $fn,string $message): void {
    try{$fn();}catch(LocalUpdateException){return;}
    throw new RuntimeException('DID_NOT_REJECT '.$message);
}
if(!class_exists(ZipArchive::class))throw new RuntimeException('ZIP extension missing');
$root=sys_get_temp_dir().'/sokna-bridge-'.bin2hex(random_bytes(6));
@mkdir($root.'/supervisor/secrets',0700,true);
@mkdir($root.'/local',0700,true);
$key=random_bytes(32);
file_put_contents($root.'/supervisor/secrets/web-adapter.key',base64_encode($key));
$descriptor=['format'=>'sokna-maintenance-control-plane-v1','protocol'=>'1.0',
    'base_url'=>'http://127.0.0.1:17631/'];
file_put_contents($root.'/supervisor/control-plane.json',json_encode($descriptor));
$manifest=['format'=>'sokna-maintenance-release-set-v2.1','schema_version'=>'2.1',
    'release_type'=>'local_only','release_set_id'=>'ci-local',
    'targets'=>['local'=>['version'=>'3.0.0.test-ci']],
    'artifacts'=>['local'=>['component'=>'local-web']]];
$zipPath=$root.'/sample.zip';
$z=new ZipArchive();
check($z->open($zipPath,ZipArchive::CREATE|ZipArchive::OVERWRITE)===true,'zip open');
$z->addFromString('release-set.json',json_encode($manifest));
$z->addFromString('artifacts/local/web.zip',str_repeat('a',1000));
$z->close();
$hash=hash_file('sha256',$zipPath);
$stageId=str_repeat('a',32);$preId=str_repeat('b',32);
$ready=false;$calls=[];$mutationCount=0;
$sender=function(string $method,string $url,array $headers,string $body,int $timeout)
    use($key,$hash,$stageId,$preId,&$ready,&$calls,&$mutationCount): array {
    check(str_starts_with($url,'http://127.0.0.1:17631/v1/'),'loopback only');
    check(!str_contains($url,'evil'),'no user-controlled URL');
    $h=[];foreach($headers as $line){[$n,$v]=explode(': ',$line,2);$h[$n]=$v;}
    check(($h['X-Sokna-Key-Id']??'')==='web-adapter','automatic local transport key');
    check(($h['X-Sokna-Maintenance-Protocol']??'')==='1.0','HMAC protocol');
    check(($h['X-Sokna-Body-SHA256']??'')===hash('sha256',$body),'body hash');
    $path=parse_url($url,PHP_URL_PATH);
    $canonical=implode("\n",[$method,$path,'1.0',$h['X-Sokna-Timestamp'],
        $h['X-Sokna-Nonce'],$h['X-Sokna-Body-SHA256']]);
    check(hash_equals(hash_hmac('sha256',$canonical,$key),$h['X-Sokna-Signature']),
        'correct HMAC canonicalization');
    check(preg_match('/^[a-f0-9]{32}$/',$h['X-Sokna-Nonce'])===1,'random nonce');
    $calls[]=$path;
    if($method==='POST')++$mutationCount;
    if($path==='/v1/updates/capabilities')
        $data=['panel_update_ready'=>$ready,'blocking_code'=>$ready?'':'LOCAL_HOST_PRECONDITIONS_UNPROVEN',
            'inbox_transport_available'=>true,'local_routine'=>['activate'=>$ready]];
    elseif(str_starts_with($path,'/v1/operations/by-idempotency/'))
        $data=['operation_id'=>str_repeat('c',32),'state'=>'committed'];
    elseif(str_starts_with($path,'/v1/operations/'))
        $data=['operation_id'=>$stageId,'state'=>'staged'];
    elseif($path==='/v1/local-updates/inbox-chunk'){
        $payload=json_decode($body,true);
        check($payload['expected_sha256']===$hash,'content address');
        check($payload['offset']===0,'offset bound');
        check($payload['total_bytes']===strlen(file_get_contents($GLOBALS['zipPath'])),'total size');
        check(strlen(base64_decode($payload['chunk_base64'],true))>0,'decoded chunk');
        $data=['complete'=>true,'content_sha256_verified'=>true,'package_ref'=>$hash.'.zip'];
    }
    elseif($path==='/v1/local-updates/preflight')
        $data=['operation'=>['operation_id'=>$preId,'state'=>'verified']];
    elseif($path==='/v1/local-updates/stage')
        $data=['operation'=>['operation_id'=>$stageId,'state'=>'staged'],
            'receipt'=>['operator_authorized'=>true,'signature_verified'=>false,
                'artifact_integrity_verified'=>true]];
    elseif($path==='/v1/local-updates/prepare-inactive')
        $data=['receipt'=>['active_slot_unchanged'=>true]];
    elseif($path==='/v1/local-updates/prepare-inactive-slot')
        $data=['receipt'=>['actual_host_health_qualified'=>true]];
    elseif($path==='/v1/local-updates/activate-code-only')
        $data=['operation'=>['operation_id'=>str_repeat('c',32),'state'=>'committed']];
    else throw new RuntimeException('Unexpected API '.$path);
    return ['status'=>200,'body'=>json_encode(['ok'=>true,'state'=>'ok','data'=>$data])];
};
try{
    check(MaintenanceV4PanelService::isV4Archive($zipPath),'V4 auto-detected');
    $service=new MaintenanceV4PanelService($root.'/local',$root.'/supervisor',$sender);
    $initial=$service->snapshot();
    check(!$initial['panel_update_ready'],'host not ready blocks button');
    $stage=$service->stageUploadedZip($zipPath,17);
    check($stage['engine']==='maintenance-v4'&&$stage['stage_operation_id']===$stageId,
        'unsigned V4 Preflight/Stage');
    check($service->hasStaged(),'durable stage readback');
    check(is_file($root.'/local/maintenance-v4-panel-state.json'),'state durability');
    reject(fn()=>$service->stageUploadedZip($zipPath,17),'double stage forbidden');
    reject(fn()=>$service->activateStaged(18),'different actor forbidden');
    $before=$mutationCount;
    reject(fn()=>$service->activateStaged(17),'not-ready activation blocked');
    check($before===$mutationCount,'not-ready cannot mutate');
    $ready=true;
    $result=$service->activateStaged(17);
    check($result['state']==='committed','canonical Supervisor code-only activation');
    reject(fn()=>$service->activateStaged(17),'committed cannot be retried');
    $state=$service->snapshot();
    check(($state['staged']['state']??'')==='completed','completed in status');
    check(in_array('/v1/local-updates/preflight',$calls,true),'actual Preflight endpoint');
    check(in_array('/v1/local-updates/stage',$calls,true),'actual Stage endpoint');
    check(in_array('/v1/local-updates/activate-code-only',$calls,true),'actual Activate endpoint');
    echo "PASS HMAC transport, no release publisher signing, correct routes, no retry, status\n";
    $descriptor['base_url']='http://evil.example:17631/';
    file_put_contents($root.'/supervisor/control-plane.json',json_encode($descriptor));
    reject(fn()=>$service->snapshot()['available']===true?throw new RuntimeException('Bad URL accepted'):throw new LocalUpdateException('blocked','blocked'),
        'malicious endpoint blocked');
    check($service->snapshot()['blocking_code']==='maintenance_adapter_unavailable','bad URL fail closed');
    echo "PASS bad loopback/descriptor denied\n";
}finally{
    $walk=function($dir)use(&$walk){foreach(scandir($dir)?:[] as $name){if($name==='.'||$name==='..')continue;$path=$dir.'/'.$name;if(is_dir($path))$walk($path);else@unlink($path);}@rmdir($dir);};
    $walk($root);
}
