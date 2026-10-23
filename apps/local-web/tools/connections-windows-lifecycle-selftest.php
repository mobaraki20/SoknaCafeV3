<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core/Config.php';
require dirname(__DIR__).'/src/Domain/System/WindowsServicesPairingException.php';
require dirname(__DIR__).'/src/Domain/System/WindowsServicesPairingService.php';

use Sokna\Local\Core\Config;
use Sokna\Local\Domain\System\WindowsServicesPairingException;
use Sokna\Local\Domain\System\WindowsServicesPairingService;

$root=sys_get_temp_dir().DIRECTORY_SEPARATOR.'sokna-pair-selftest-'.bin2hex(random_bytes(4));
@mkdir($root.DIRECTORY_SEPARATOR.'secrets',0700,true);
file_put_contents($root.DIRECTORY_SEPARATOR.'secrets'.DIRECTORY_SEPARATOR.'runtime-health.token',str_repeat('h',64));
$config=Config::fromArray(['runtime'=>['local_token'=>str_repeat('l',64),'local_base_url'=>'http://127.0.0.1:18080/']]);
$agents=[];$next=0;$rotations=0;$retirements=[];
$create=function(string $name,array $actor)use(&$agents,&$next):array{$id=++$next;$token=bin2hex(random_bytes(32));$agents[$id]=['id'=>$id,'name'=>$name,'hostname'=>'TESTHOST','agent_version'=>'6.2.9','active'=>1,'last_heartbeat_at'=>gmdate('c'),'created_at'=>gmdate('c')];return ['agent_id'=>$id,'token'=>$token,'token_hint'=>substr($token,-8)];};
$retire=function(int $id,int $actorId,string $reason)use(&$agents,&$retirements):array{if(isset($agents[$id]))$agents[$id]['active']=0;$retirements[]=['id'=>$id,'reason'=>$reason];return ['agent_id'=>$id,'retired'=>true,'idempotent'=>false,'destinations_disabled'=>0,'fallbacks_cleared'=>0,'fallbacks_promoted'=>0];};
$prepare=function(int $id,array $actor)use(&$agents):array{if(!isset($agents[$id])||!$agents[$id]['active'])throw new RuntimeException('inactive');$token=bin2hex(random_bytes(32));return ['agent_id'=>$id,'name'=>$agents[$id]['name'],'token'=>$token,'token_hint'=>substr($token,-8)];};
$commit=function(int $id,string $token,int $actorId)use(&$rotations):array{$rotations++;return ['agent_id'=>$id,'idempotent'=>false,'token_hint'=>substr($token,-8)];};
$active=function()use(&$agents):array{return array_values(array_filter($agents,fn(array $a):bool=>!empty($a['active'])));};
$svc=new WindowsServicesPairingService($config,$root,$create,$retire,$prepare,$commit,$active);
$admin=['id'=>1,'role'=>'admin'];$checks=0;
$ok=function(bool $v,string $m)use(&$checks){if(!$v)throw new RuntimeException('FAIL '.$m);$checks++;};

$first=$svc->create($admin,'دستگاه تست');$ok($first['state']==='issued','initial issued');$ok((int)$first['print_agent_id']===1,'initial agent id');
$svc->exchange((string)$first['pairing_code'],'127.0.0.1');$svc->confirm((string)$first['pairing_code'],'127.0.0.1');
$status=$svc->status();$ok($status['state']==='confirmed','confirmed preferred');$ok($status['association_active']===true,'association active');$ok((int)$status['active_agent_count']===1,'one active agent');
$blocked=false;try{$svc->create($admin,'نباید ساخته شود');}catch(WindowsServicesPairingException $e){$blocked=$e->errorCode==='pairing_existing_agent_requires_reconnect';}$ok($blocked,'duplicate initial blocked');
$rotation=$svc->createRotation($admin,1,'دستگاه تست');$ok($rotation['mode']==='token_rotation','rotation issued');$svc->exchange((string)$rotation['pairing_code'],'127.0.0.1');$svc->confirm((string)$rotation['pairing_code'],'127.0.0.1');$ok($rotations===1,'rotation committed once');
// A newer canceled ticket must not hide an older confirmed association.
$extra=$svc->create($admin,'دستگاه دوم',true);$svc->cancel((string)$extra['pairing_code'],'127.0.0.1');$status=$svc->status();$ok($status['state']==='confirmed','terminal latest does not mask confirmed');$ok((int)$status['confirmed_agent_id']===1,'confirmed agent stable');
$retired=$svc->retireConnection($admin,1,'selftest');$ok($retired['association_active']===false,'retire clears association');$ok(count($retirements)>=2,'retire callback observed');$history=$retired['history']??[];$ok(count(array_filter($history,fn(array $t):bool=>(int)($t['print_agent_id']??0)===1&&($t['state']??'')==='retired'))>=1,'retired ticket retained in history');
$new=$svc->create($admin,'بعد از بازنشستگی');$ok($new['state']==='issued','new initial allowed after no active agents');

function rrmdir(string $dir):void{if(!is_dir($dir))return;foreach(scandir($dir)?:[] as $n){if($n==='.'||$n==='..')continue;$p=$dir.DIRECTORY_SEPARATOR.$n;if(is_dir($p))rrmdir($p);else @unlink($p);}@rmdir($dir);}rrmdir($root);
echo "CONNECTIONS_WINDOWS_LIFECYCLE_SELFTEST_PASS checks={$checks}\n";
