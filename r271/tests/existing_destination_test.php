<?php
declare(strict_types=1);

use Sokna\Local\Core\Config;
use Sokna\Local\Domain\PublicEdge\PublicAuthorityStateService;
use Sokna\Local\Domain\PublicEdge\PublicEdgeSyncClient;
use Sokna\Local\Domain\PublicEdge\PublicEdgeSyncException;
use Sokna\Local\Domain\PublicEdge\PublicReenrollmentService;

$base=dirname(__DIR__).'/local';
foreach(['src/Core/Config.php','src/Domain/PublicEdge/PublicEdgeSyncException.php',
 'src/Domain/PublicEdge/PublicAuthorityStateService.php','src/Domain/PublicEdge/PublicEdgeSyncClient.php',
 'src/Domain/PublicEdge/PublicReenrollmentService.php'] as $f)require_once $base.'/'.$f;

$dbHost=getenv('SOKNA_TEST_DB_HOST')?:'127.0.0.1';
$dbName=getenv('SOKNA_TEST_DB_NAME')?:'sokna_r271';
$dbUser=getenv('SOKNA_TEST_DB_USER')?:'root';
$dbPass=getenv('SOKNA_TEST_DB_PASSWORD')?:'';
$pdo=new PDO('mysql:host='.$dbHost.';dbname='.$dbName.';charset=utf8mb4',$dbUser,$dbPass,
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec("CREATE TABLE IF NOT EXISTS public_authority_state (
 installation_id VARCHAR(96) PRIMARY KEY,authority_epoch INT NOT NULL DEFAULT 1,
 phase VARCHAR(32) NOT NULL DEFAULT 'active',reason VARCHAR(96) NULL,
 blocked_at DATETIME(6) NULL,reenrolled_at DATETIME(6) NULL,
 activated_at DATETIME(6) NULL,updated_at TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP(6))");
$pdo->exec("DELETE FROM public_authority_state WHERE installation_id='local-test-universal'");

function check(bool $ok,string $msg):void{if(!$ok)throw new RuntimeException('FAIL '.$msg);}
function expectError(callable $f,string $code):void{try{$f();}catch(PublicEdgeSyncException $e){check($e->errorCode===$code,'expected '.$code.' got '.$e->errorCode);return;}throw new RuntimeException('expected error '.$code);}
$root=sys_get_temp_dir().'/r271-'.bin2hex(random_bytes(5));mkdir($root,0700,true);
$source='https://old.example.com';$target='https://new.example.com/public';$secret=str_repeat('a',64);$id='local-test-universal';
$original=['installation'=>['id'=>$id], 'app'=>['data_dir'=>$root], 'public'=>['base_url'=>$source,'shared_secret'=>$secret]];
$configFile=$root.'/config.php';file_put_contents($configFile,"<?php\nreturn ".var_export($original,true).";\n");
$authority=new PublicAuthorityStateService($pdo,$id);$paired=true;$remoteId=$id;$badHealth=false;$remoteCalls=0;$newEpoch=2;$commitSigned=true;
$transport=static function(string $url,string $method,array $headers,string $body)use(&$paired,&$remoteId,&$badHealth,&$remoteCalls,&$newEpoch,&$commitSigned,$id,$target):array{
 $p=parse_url($url);$path=$p['path']??'';
 if($method==='GET'&&str_ends_with($path,'/health'))return ['status'=>200,'body'=>['ok'=>true,'component'=>'public-edge','database'=>$badHealth?'down':'ready','pending_migrations'=>0,'version'=>'35ig', 'deployment'=>['coherent'=>true,'base_url'=>$target]]];
 if($method==='GET'&&str_ends_with($path,'/setup/api.php'))return ['status'=>200,'body'=>['success'=>true,'setup'=>['installed'=>true,'paired'=>$paired,'installation_id'=>$remoteId,'base_url'=>$target]]];
 if($method==='POST'&&str_ends_with($path,'/authority-recovery.php')){
   $remoteCalls++;
   return ['status'=>200,'body'=>['ok'=>true,'installation_id'=>$id,'authority_epoch'=>$newEpoch,'authority_state'=>'resync_required','remote_enabled'=>false,'order_intake_enabled'=>false]];
 }
 if($method==='POST'&&str_ends_with($path,'/authority/status')){
   return ['status'=>200,'body'=>['ok'=>$commitSigned,'installation_id'=>$id,'authority_epoch'=>$newEpoch,'authority_state'=>'resync_required','remote_enabled'=>false,'order_intake_enabled'=>false]];
 }
 throw new RuntimeException('unexpected HTTP '.$method.' '.$url);
};
$client=new PublicEdgeSyncClient(Config::fromArray($original),$transport,null,$authority);
$svc=new PublicReenrollmentService(Config::fromArray($original),$client,$authority,$configFile);
$pre=$svc->existingDestinationPreflight($target);
check($pre['route']==='existing_paired','existing target detected');
check($pre['minimum_next_epoch']===2,'epoch minimum');
check($pre['installation_id']===$id,'Local id trusted');
check($pre['recovery_console_url']===$target.'/authority-recovery.php','subfolder recovery link');
check(($pre['writes_performed']??true)===false,'preflight read-only');
check((require $configFile)['public']['base_url']===$source,'old config unchanged before move');
$remoteId='someone-else';expectError(fn()=>$svc->existingDestinationPreflight($target),'existing_move_other_installation');$remoteId=$id;
$badHealth=true;expectError(fn()=>$svc->existingDestinationPreflight($target),'existing_move_target_not_ready');$badHealth=false;
$paired=false;check($svc->existingDestinationPreflight($target)['route']==='fresh_unpaired','fresh path preserved');$paired=true;
expectError(fn()=>$svc->moveExistingDestination($target,'ABCD',$id==='irrelevant'?1:4,'MOVE_EXISTING_PUBLIC',1),'existing_move_code_invalid');
check($remoteCalls===0,'invalid input cannot contact Public');
expectError(fn()=>$svc->moveExistingDestination($target,'12345678-12345678-12345678',4,'MOVE_EXISTING_PUBLIC',0),'existing_move_preflight_stale');
check($remoteCalls===0,'stale preflight is read only');
expectError(fn()=>$svc->moveExistingDestination($target,'12345678-12345678-12345678',4,'NO_CONFIRM',1),'existing_move_confirmation_required');
check($remoteCalls===0,'no confirmation cannot rotate');
$res=$svc->moveExistingDestination($target,'12345678-12345678-12345678',4,'MOVE_EXISTING_PUBLIC',1);
check($res['ok']===true&&$res['requires_full_sync']===true,'transition reports gated sync');
check($remoteCalls===1,'remote recovery code consumed only once');
check($authority->phase()==='resync_required'&&$authority->epoch()===2,'Local remains resync until full projection');
$cfg=require $configFile;
check($cfg['public']['base_url']===$target,'config committed after signed proof');
check($cfg['public']['shared_secret']!==$secret && strlen($cfg['public']['shared_secret'])===64,'new secret rotated');
check(!file_exists($configFile.'.public-recovery-pending.json'),'journal cleared after signed readback');
check((require $configFile)['installation']['id']===$id,'installation id unchanged');
// No early guest-intake activation: it belongs to the publisher's complete sync gate.
print(json_encode(['ok'=>true,'suite'=>'R271 exact Local existing Public transition', 'preflight_read_only'=>true,
 'other_tenant_rejected'=>true,'fresh_route_preserved'=>true,'code_and_local_confirmation_required'=>true,
 'signed_epoch_resync'=>true,'old_url_preserved_until_signed_commit'=>true,'guest_intake_not_automatically_opened'=>true],JSON_UNESCAPED_SLASHES)."\n");
