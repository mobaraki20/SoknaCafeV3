<?php
declare(strict_types=1);
// QA only: isolated MariaDB 11.4 databases. Never connects to a cafe/host database.
$root=dirname(__DIR__);
foreach ([
  'local/Config.php','local/PublicEdgeSyncException.php','local/PublicAuthorityStateService.php',
  'local/PublicEdgeSyncClient.php','local/PublicReenrollmentService.php',
  'public/PublicUpdateException.php','public/EmergencyAccessService.php','public/PairingSecretStore.php',
  'public/AuthorityEpochService.php',
] as $name)require_once $root.'/'.$name;
use Sokna\Local\Core\Config;
use Sokna\Local\Domain\PublicEdge\{PublicEdgeSyncClient,PublicReenrollmentService,PublicAuthorityStateService,PublicEdgeSyncException};
use Sokna\PublicEdge\Authority\AuthorityEpochService;
use Sokna\PublicEdge\Emergency\{PairingSecretStore,EmergencyAccessService,PublicUpdateException};
$host=getenv('MYSQL_HOST')?:'127.0.0.1';$password=getenv('MYSQL_PASSWORD')?:'testpass';
$admin=new PDO("mysql:host=$host;charset=utf8mb4",'root',$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
foreach(['sokna_r239_local','sokna_r239_public'] as $db){$admin->exec('DROP DATABASE IF EXISTS `'.$db.'`');$admin->exec('CREATE DATABASE `'.$db.'` CHARACTER SET utf8mb4');}
$connect=static fn($db)=>new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4",'root',$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$local=$connect('sokna_r239_local');$public=$connect('sokna_r239_public');
$local->exec(file_get_contents(__DIR__.'/public_authority_state.sql'));
foreach(array_filter(array_map('trim',explode(";\n",file_get_contents(__DIR__.'/public_fixture.sql')))) as $sql)$public->exec($sql);
$ins='local-example-12345';
$local->prepare("INSERT INTO public_authority_state (installation_id,authority_epoch,phase) VALUES (?,2,'active')")->execute([$ins]);
$public->prepare("INSERT INTO installations(installation_id,authority_epoch,authority_state,remote_enabled,order_intake_enabled) VALUES (?,1,'active',0,0)")->execute([$ins]);
$key=base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));$secretStore=new PairingSecretStore($public,$key);
$accessDir=sys_get_temp_dir().'/sokna-r239-db-access-'.bin2hex(random_bytes(6));mkdir($accessDir,0700,true);
$auth=new AuthorityEpochService($public,$secretStore,new EmergencyAccessService($accessDir));
$localState=new PublicAuthorityStateService($local,$ins);
$oldSecret='old-secret-for-qa-only';$secretStore->put($ins,$oldSecret);
$cfgDir=sys_get_temp_dir().'/sokna-r239-db-local-'.bin2hex(random_bytes(6));mkdir($cfgDir,0700,true);$cfgPath=$cfgDir.'/config.php';
$cfg=['installation'=>['id'=>$ins],'public'=>['base_url'=>'https://www.soknaspace.ir','shared_secret'=>$oldSecret]];
file_put_contents($cfgPath,'<?php return '.var_export($cfg,true).';');
$checks=[];function ck(string $name,bool $ok):void {global $checks;$checks[$name]=$ok;echo ($ok?'PASS ':'FAIL ').$name."\n";if(!$ok)throw new RuntimeException('ASSERTION_FAILED:'.$name);}
function expectError(callable $fn,string $code):bool{try{$fn();return false;}catch(PublicEdgeSyncException|PublicUpdateException $e){return $e->errorCode===$code;}}

// The real Remote service is called inside a simulated transport; signatures are verified
// against the actual encrypted secret stored in MariaDB after its transaction commits.
$remoteCommits=0;$statusProofs=0;$dropLocalPhaseOnce=false;$loseRemoteReply=false;
$transport=function(string $url,string $method,array $headers,string $raw)use($auth,$secretStore,$ins,&$remoteCommits,&$statusProofs,&$dropLocalPhaseOnce,&$loseRemoteReply,$local):array {
    if(str_contains($url,'authority-recovery.php?action=reenroll')){
        $v=json_decode($raw,true);
        $response=$auth->complete((string)$v['new_installation_id'],(string)$v['enrollment_code'],(string)$v['new_shared_secret'],(string)$v['display_name']);
        $remoteCommits++;
        if($dropLocalPhaseOnce){$dropLocalPhaseOnce=false;
            $local->exec("CREATE TRIGGER r239_failure BEFORE UPDATE ON public_authority_state FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected local storage failure'");
        }
        if($loseRemoteReply){$loseRemoteReply=false;throw new PublicEdgeSyncException('public_unreachable','Intentional lost 2xx',503);}
        return ['status'=>200,'body'=>$response];
    }
    if(str_contains($url,'/api/v1/local/authority/status')){
        $statusProofs++;
        $body=json_decode($raw,true);$timestamp=(string)($headers['X-Sokna-Timestamp']??'');$nonce=(string)($headers['X-Sokna-Nonce']??'');
        $signature=(string)($headers['X-Sokna-Signature']??'');
        $canonical=implode("\n",['sokna-relay-v1','POST','/api/v1/local/authority/status',$timestamp,$nonce,hash('sha256',$raw)]);
        $currentSecret=$secretStore->secret($ins);
        if(!hash_equals(hash_hmac('sha256',$canonical,$currentSecret),$signature))return ['status'=>401,'body'=>['ok'=>false,'error'=>'bad_signature']];
        $state=$auth->status($ins);
        if((int)($body['authority_epoch']??0)!==(int)$state['authority_epoch'])return ['status'=>409,'body'=>['ok'=>false,'error'=>'authority_epoch_mismatch']];
        return ['status'=>200,'body'=>$state];
    }
    return ['status'=>404,'body'=>['ok'=>false,'error'=>'route_not_found']];
};
$newLocal=static function() use($cfgPath,$transport,$localState):PublicReenrollmentService {
    return new PublicReenrollmentService(Config::fromArray(require $cfgPath),new PublicEdgeSyncClient(Config::fromArray(require $cfgPath),$transport,null,$localState),$localState,$cfgPath);
};
$svc=$newLocal();
ck('initial-pair-protected-in-existing-local',expectError(fn()=>$svc->initialPair('https://www.soknaspace.ir','wrong'),'public_already_paired'));
$pre=$svc->replacementPreflight();ck('preflight-epoch-2-to-3',$pre['current_epoch']===2&&$pre['minimum_next_epoch']===3);
ck('preflight-readonly',$localState->phase()==='active');
ck('wrong-confirmation-rejected',expectError(fn()=>$svc->recoverReplacement('https://www.soknaspace.ir','wrong','Cafe',1,'',2),'public_replacement_confirmation_required'));
$offer=$auth->createOffer($ins,1800,'db-test',3);
ck('public-real-mariadb-offer-epoch3',$offer['authority_epoch']===3);
ck('public-offer-fences-intake',$auth->status($ins)['order_intake_enabled']===false);

// Recreate R233 public schema failure halfway through complete; verify MySQL transaction rolls back secret.
$public->exec('DROP TABLE remote_projection_events');
try{$auth->complete($ins,$offer['enrollment_code'],'a-valid-32-char-new-secret-of-test','Cafe');$brokenFailed=false;}
catch(Throwable $e){$brokenFailed=true;}
ck('real-mariadb-mid-transaction-failure',$brokenFailed);
ck('public-transaction-rolled-back-epoch',$auth->status($ins)['authority_epoch']===1);
ck('public-transaction-rolled-back-secret',$secretStore->secret($ins)===$oldSecret);
$public->exec('CREATE TABLE remote_projection_events (id BIGINT AUTO_INCREMENT PRIMARY KEY,installation_id VARCHAR(96) NOT NULL) ENGINE=InnoDB');

// Fault after remote success but before Local authority state can update: journal survives.
$dropLocalPhaseOnce=true;
try{$svc->recoverReplacement('https://www.soknaspace.ir',$offer['enrollment_code'],'Cafe',1,'REPLACE_PUBLIC',2);$failed=false;}
catch(Throwable $e){$failed=true;}
ck('local-db-trigger-injected-after-remote-success',$failed);
ck('remote-committed-once',$remoteCommits===1);
ck('local-config-committed-before-db-trigger',(require $cfgPath)['public']['shared_secret']!==$oldSecret);
ck('local-phase-stays-fenced',$localState->phase()==='reenroll_required'&&$localState->epoch()===2);
ck('journal-retained-after-db-failure',is_file($cfgPath.'.public-recovery-pending.json'));
ck('new-code-overwrite-blocked',expectError(fn()=>$svc->recoverReplacement('https://www.soknaspace.ir','other-code','Cafe',1,'REPLACE_PUBLIC',2),'public_recovery_pending_resume_required'));
$local->exec('DROP TRIGGER r239_failure');
$resumed=$newLocal()->resumePendingRecovery(1);
ck('resume-with-real-mariadb-success',$resumed['authority_epoch']===3&&$localState->epoch()===3&&$localState->resyncRequired());
ck('journal-cleared-only-after-durable-state',!is_file($cfgPath.'.public-recovery-pending.json'));
ck('remote-never-rotates-again-on-resume',$remoteCommits===1);
ck('public-guest-intake-remains-blocked',$auth->status($ins)['order_intake_enabled']===false);
ck('signed-proof-happened',$statusProofs>=2);
ck('used-code-cannot-be-replayed',expectError(fn()=>$auth->complete($ins,$offer['enrollment_code'],bin2hex(random_bytes(32))),'reenroll_denied'));

$result=['passed'=>array_sum(array_map(fn($x)=>(int)$x,$checks)),'total'=>count($checks),'real_mariadb'=>true,'workflow'=>'r239','note'=>'Scoped two isolated disposable DBs; no cafe/server communication'];
file_put_contents(__DIR__.'/result.json',json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));echo json_encode($result)."\n";