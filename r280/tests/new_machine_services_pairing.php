<?php
declare(strict_types=1);
/**
 * Real WindowsServicesPairingService class with two independent data vaults
 * and different machine secrets. Cryptographic pairing is exercised, not a
 * mock parser. It does NOT start Windows services or print a physical receipt.
 */
if(PHP_SAPI!=='cli'||getenv('GITHUB_ACTIONS')!=='true')throw new RuntimeException('R280_SERVICES_CI_ONLY');
$web=(string)getenv('R280_LOCAL_SOURCE_ROOT');
if(!is_file($web.'/src/Domain/System/WindowsServicesPairingService.php'))throw new RuntimeException('R280_REAL_SVC_CLASS_MISSING');
require_once $web.'/src/Core/Config.php';
require_once $web.'/src/Domain/System/WindowsServicesPairingException.php';
require_once $web.'/src/Domain/System/WindowsServicesPairingService.php';
use Sokna\Local\Core\Config;
use Sokna\Local\Domain\System\WindowsServicesPairingService as Pairing;
use Sokna\Local\Domain\System\WindowsServicesPairingException as PairingException;
$base=sys_get_temp_dir().'/sokna-r280-pairing-'.bin2hex(random_bytes(6));
if(!mkdir($base,0700,true))throw new RuntimeException('CI_PAIRING_TMP_FAILED');
$checks=0;
$assert=static function(bool $condition,string $name)use(&$checks):void {
    if(!$condition)throw new RuntimeException('R280_PAIRING_ASSERT_FAILED_'.$name);
    $checks++;echo 'PASS '.$name.PHP_EOL;
};
$create=static function(string $machine)use($base):array {
    $root=$base.'/'.$machine;
    mkdir($root.'/secrets',0700,true);
    $localToken=bin2hex(random_bytes(32));
    $runtimeToken=bin2hex(random_bytes(32));
    file_put_contents($root.'/secrets/runtime-health.token',$runtimeToken);
    return [$root,Config::fromArray(['runtime'=>[
      'local_base_url'=>'http://127.0.0.1:39841',
      'local_token'=>$localToken]]),$runtimeToken,$localToken];
};
[$oldRoot,$oldConfig,$oldHealth,$oldLocal]=$create('old-machine');
[$newRoot,$newConfig,$newHealth,$newLocal]=$create('new-machine');
$assert($oldHealth!==$newHealth&&$oldLocal!==$newLocal,'no Local/Runtime machine token cloning');
$actor=['id'=>7,'role'=>'admin'];
function makeService(Config $config,string $root,array &$agents,int &$nextId,array &$rotated):Pairing
{
    return new Pairing($config,$root,
       static function(string $display,array $actor)use(&$agents,&$nextId):array{
           $id=$nextId++;$token=bin2hex(random_bytes(32));
           $agents[$id]=['id'=>$id,'name'=>$display,'hostname'=>'qa-only','token'=>$token];
           return ['agent_id'=>$id,'token'=>$token];
       },
       static function(int $id,int $who,string $reason)use(&$agents):array{
           unset($agents[$id]);return ['retired'=>true,'id'=>$id];
       },
       static function(int $id,array $actor)use(&$agents):array{
           if(!isset($agents[$id]))throw new RuntimeException('NO_ACTIVE_AGENT');
           return ['agent_id'=>$id,'token'=>bin2hex(random_bytes(32)),'name'=>$agents[$id]['name']];
       },
       static function(int $id,string $token,int $actor)use(&$agents,&$rotated):array{
           if(!isset($agents[$id]))throw new RuntimeException('NO_ACTIVE_AGENT');
           $agents[$id]['token']=$token;$rotated[$id]=true;
           return ['updated'=>true];
       },
       static function()use(&$agents):array{return array_values($agents);}
    );
}
$oldAgents=[];$newAgents=[];$oldNext=1;$newNext=1;$oldRot=[];$newRot=[];
$old=makeService($oldConfig,$oldRoot,$oldAgents,$oldNext,$oldRot);
$new=makeService($newConfig,$newRoot,$newAgents,$newNext,$newRot);
$assert(($new->status()['state']??null)==='not_created','new machine starts without old Windows pairing');
$oldTicket=$old->create($actor,'Old cafe printer');
$assert(!empty($oldTicket['pairing_code']),'old machine has old-only pairing ticket');
$oldPayload=$old->exchange($oldTicket['pairing_code'],'127.0.0.1');
$old->confirm($oldTicket['pairing_code'],'127.0.0.1');
$assert(($old->status()['association_active']??false)===true,'old agent exists independently');
try{$new->exchange($oldTicket['pairing_code'],'127.0.0.1');$assert(false,'old code must not work on new');}
catch(PairingException $e){$assert($e->errorCode==='pairing_not_found','old pairing token rejected on new machine');}
$newTicket=$new->create($actor,'New cafe printer');
try{$new->exchange($newTicket['pairing_code'],'203.0.113.10');$assert(false,'remote exchange refused');}
catch(PairingException $e){$assert($e->errorCode==='pairing_loopback_required','remote pairing refused');}
$bundle=$new->exchange($newTicket['pairing_code'],'127.0.0.1')['bundle'];
$assert($bundle['runtime_token']===$newHealth &&
        $bundle['local_token']===$newLocal &&
        $bundle['runtime_token']!==$oldPayload['bundle']['runtime_token'],
        'fresh Windows pairing uses only new machine credentials');
$confirmation=$new->confirm($newTicket['pairing_code'],'127.0.0.1');
$assert(($confirmation['confirmed']??false)===true,'new machine receives independently confirmed token');
$again=$new->confirm($newTicket['pairing_code'],'127.0.0.1');
$assert(($again['idempotent']??false)===true,'confirmation idempotent');
$assert(($new->status()['association_active']??false)===true,'new PrintAgent associated only after local confirmation');
try{$new->create($actor,'Inadvertent double printer');$assert(false,'duplicate agent rejected');}
catch(PairingException $e){$assert($e->errorCode==='pairing_existing_agent_requires_reconnect','duplicate association blocked');}
$newId=(int)$confirmation['print_agent_id'];
$newRotation=$new->createRotation($actor,$newId,'Same device token rotate');
$token=$new->exchange($newRotation['pairing_code'],'127.0.0.1')['bundle']['print_agent_token'];
$new->confirm($newRotation['pairing_code'],'127.0.0.1');
$assert(($newRot[$newId]??false)===true&&$newAgents[$newId]['token']===$token,
        'reconnect rotates agent credential rather than cloning old token');
$old->retireConnection($actor,(int)$oldPayload['bundle']['print_agent_id']??1,'ci-old-retire');
$assert(($old->status()['association_active']??false)===false,'old machine retirement explicit');
$assert(($new->status()['association_active']??false)===true,'old-machine retirement does not delete new pairing');
echo 'R280_TWO_SEPARATE_LOCAL_WINDOWS_SERVICES_PAIRING_'.(string)$checks.'_PASS'.PHP_EOL;
