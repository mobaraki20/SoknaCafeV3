<?php
declare(strict_types=1);
error_reporting(E_ALL);
$root=dirname(__DIR__);
foreach([
'local/Config.php','local/PublicEdgeSyncException.php',
'local/PublicAuthorityStateService.php','local/PublicEdgeSyncClient.php',
'local/PublicReenrollmentService.php',
'public/PublicUpdateException.php','public/EmergencyAccessService.php',
'public/PairingSecretStore.php','public/AuthorityEpochService.php'] as $file)require_once $root.'/'.$file;

use Sokna\Local\Core\Config;
use Sokna\Local\Domain\PublicEdge\PublicReenrollmentService;
use Sokna\Local\Domain\PublicEdge\PublicEdgeSyncClient;
use Sokna\Local\Domain\PublicEdge\PublicAuthorityStateService;
use Sokna\Local\Domain\PublicEdge\PublicEdgeSyncException;
use Sokna\PublicEdge\Authority\AuthorityEpochService;
use Sokna\PublicEdge\Emergency\EmergencyAccessService;
use Sokna\PublicEdge\Emergency\PairingSecretStore;
use Sokna\PublicEdge\Emergency\PublicUpdateException;

class FakeRecoveryPDO extends PDO {
 public bool $txn=false;
 public array $state=['installation_id'=>'local-example-12345','authority_epoch'=>2,'phase'=>'active','reason'=>'','active'=>1,'revoked_at'=>null,'authority_state'=>'active'];
 public array $offers=[];public array $sql=[];public array $secrets=[];
 public function __construct(){}
 public function prepare(string $query,array $options=[]):PDOStatement|false {return new FakeRecoveryStatement($this,$query);}
 public function beginTransaction():bool{$this->txn=true;return true;}
 public function commit():bool{$this->txn=false;return true;}
 public function rollBack():bool{$this->txn=false;return true;}
 public function inTransaction():bool{return $this->txn;}
}
class FakeRecoveryStatement extends PDOStatement {
 public mixed $return=null;
 public function __construct(private FakeRecoveryPDO $db,private string $sql){}
 public function execute(?array $params=null):bool {
   $p=$params??[];$s=$this->sql;$this->db->sql[]=$s;
   if(str_starts_with($s,'SELECT')){
      if(str_contains($s,'installation_authority_reenrollments')){$offer=end($this->db->offers);$this->return=is_array($offer)&&$offer['status']==='pending'?$offer:false;}
      else $this->return=$this->db->state;
   }
   elseif(str_contains($s,"phase='reenroll_required'")){$this->db->state['phase']='reenroll_required';$this->db->state['reason']=$p[0]??'';}
   elseif(str_contains($s,"phase='resync_required'")){$this->db->state['phase']='resync_required';$this->db->state['authority_epoch']=$p[0];}
   elseif(str_contains($s,"authority_state='reenroll_pending'")){$this->db->state['authority_state']='reenroll_pending';}
   elseif(str_contains($s,"authority_state='resync_required'")){$this->db->state['authority_state']='resync_required';$this->db->state['authority_epoch']=$p[0];}
   elseif(str_contains($s,'INSERT INTO installation_authority_reenrollments(')){$this->db->offers[]=['id'=>count($this->db->offers)+1,'installation_id'=>$p[0],'from_epoch'=>$p[1],'to_epoch'=>$p[2],'code_hash'=>$p[3],'status'=>'pending'];}
   elseif(str_contains($s,"UPDATE installation_authority_reenrollments SET status='completed'"))foreach($this->db->offers as &$offer){if($offer['id']===$p[0])$offer['status']='completed';}
   elseif(str_contains($s,"UPDATE installation_authority_reenrollments SET status='cancelled'"))foreach($this->db->offers as &$offer)$offer['status']='cancelled';
   elseif(str_contains($s,'INSERT INTO installation_pairing_secrets(')){$this->db->secrets[$p[0]]=$p[1];}
   return true;
 }
 public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0):mixed{return $this->return;}
 public function fetchColumn(int $column=0):mixed{return is_array($this->return)?reset($this->return):false;}
}
$pass=0;$failures=[];
function check(string $name,bool $yes):void{global $pass,$failures;if(!$yes){$failures[]=$name;echo "FAIL $name\n";}else{$pass++;echo "PASS $name\n";}}
function expectCode(string $name,callable $fn,string $code):void{try{$fn();check($name,false);}catch(PublicEdgeSyncException|PublicUpdateException $e){check($name,$e->errorCode===$code);}}
function makeLocal(callable $remote,?FakeRecoveryPDO $pdo=null):array {
  $pdo??=new FakeRecoveryPDO();$tmp=sys_get_temp_dir().'/sokna-r238-'.bin2hex(random_bytes(6));mkdir($tmp,0700,true);$file=$tmp.'/config.php';
  $data=['public'=>['base_url'=>'https://www.soknaspace.ir','shared_secret'=>'old-secret-from-previous-pair'],'installation'=>['id'=>'local-example-12345']];
  file_put_contents($file,'<?php return '.var_export($data,true).';');
  $config=Config::fromArray($data);$state=new PublicAuthorityStateService($pdo,'local-example-12345');
  $client=new PublicEdgeSyncClient($config,$remote,null,$state);
  return [new PublicReenrollmentService($config,$client,$state,$file),$pdo,$file,$state];
}
/** In-process network with signed proof checking, no Public database and no real HTTP. */
function signedMock(bool $failFirstStatus=false,bool $loseReenrollResponse=false):array {
    $state=(object)['remote_secret'=>'','epoch'=>1,'installation_id'=>'local-example-12345',
        'status_fail_once'=>$failFirstStatus,'lost_reply'=>$loseReenrollResponse,'remote_commits'=>0,'proofs'=>0];
    $transport=function($url,$method,$headers,$body) use ($state):array {
        if(str_contains($url,'authority-recovery.php?action=reenroll')){
            $request=json_decode($body,true);
            $state->remote_secret=(string)$request['new_shared_secret'];
            $state->epoch=3;$state->remote_commits++;
            if($state->lost_reply){$state->lost_reply=false;throw new PublicEdgeSyncException('public_unreachable','Simulated lost reply',503);}
            return ['status'=>200,'body'=>['ok'=>true,'installation_id'=>$state->installation_id,'authority_epoch'=>3,'authority_state'=>'resync_required']];
        }
        if(str_contains($url,'/api/v1/local/authority/status')){
            $state->proofs++;
            if($state->status_fail_once){$state->status_fail_once=false;return ['status'=>503,'body'=>['ok'=>false,'error'=>'public_temporarily_unavailable']];}
            $payload=json_decode($body,true);
            $ts=(string)($headers['X-Sokna-Timestamp']??'');$nonce=(string)($headers['X-Sokna-Nonce']??'');
            $signature=(string)($headers['X-Sokna-Signature']??'');
            $canonical=implode("\n",['sokna-relay-v1','POST','/api/v1/local/authority/status',$ts,$nonce,hash('sha256',$body)]);
            if($state->remote_secret===''||!hash_equals(hash_hmac('sha256',$canonical,$state->remote_secret),$signature))
                return ['status'=>401,'body'=>['ok'=>false,'error'=>'bad_signature']];
            if((int)($payload['authority_epoch']??0)!==$state->epoch)
                return ['status'=>409,'body'=>['ok'=>false,'error'=>'authority_epoch_mismatch']];
            return ['status'=>200,'body'=>['ok'=>true,'installation_id'=>$state->installation_id,
                'authority_epoch'=>$state->epoch,'authority_state'=>'resync_required','remote_enabled'=>false,'order_intake_enabled'=>false]];
        }
        return ['status'=>404,'body'=>['ok'=>false,'error'=>'route_not_found']];
    };
    return [$transport,$state];
}

$network=0;
[$svc,$pdo,$path,$state]=makeLocal(function()use(&$network){$network++;return ['status'=>200,'body'=>['ok'=>true]];});
expectCode('already paired initialPair rejected before remote call',fn()=>$svc->initialPair('https://www.soknaspace.ir','unused'),'public_already_paired');
check('initialPair rejection made zero remote requests',$network===0);
$pre=$svc->replacementPreflight();check('preflight detects local epoch=2, asks next>=3',$pre['current_epoch']===2&&$pre['minimum_next_epoch']===3);
check('preflight not mutation',$pdo->state['phase']==='active');
expectCode('stale epoch fail-closed',fn()=>$svc->recoverReplacement('https://www.soknaspace.ir','code','Test',1,'REPLACE_PUBLIC',1),'public_replacement_stale_preflight');
expectCode('wrong origin refused',fn()=>$svc->recoverReplacement('https://example.net','code','Test',1,'REPLACE_PUBLIC',2),'public_replacement_url_changed');
expectCode('confirmation mandatory',fn()=>$svc->recoverReplacement('https://www.soknaspace.ir','code','Test',1,'',2),'public_replacement_confirmation_required');
check('precheck failures leave local phase active',$pdo->state['phase']==='active');
[$svc2,$pdo2,$path2,$state2]=makeLocal(fn()=>['status'=>200,'body'=>['ok'=>true,'installation_id'=>'local-example-12345','authority_epoch'=>2]]);
expectCode('remote equal epoch rejected',fn()=>$svc2->recoverReplacement('https://www.soknaspace.ir','code','Test',1,'REPLACE_PUBLIC',2),'public_replacement_epoch_rejected');
check('equal epoch leaves local config unchanged',(require $path2)['public']['shared_secret']==='old-secret-from-previous-pair');
check('recovery failure leaves local in fenced state',$pdo2->state['phase']==='reenroll_required');
[$transport3,$remote3]=signedMock();
[$svc3,$pdo3,$path3,$state3]=makeLocal($transport3);
$result=$svc3->recoverReplacement('https://www.soknaspace.ir','one-time','Cafe',1,'REPLACE_PUBLIC',2);
check('remote authority recovery endpoint used',$remote3->remote_commits===1);
check('signed proof required after remote response',$remote3->proofs===1);
check('recovery response has authority epoch3',$result['public']['authority_epoch']===3);
check('local epoch advanced and in resync phase',$pdo3->state['authority_epoch']===3&&$pdo3->state['phase']==='resync_required');
check('new shared secret persisted but not leaked',(require $path3)['public']['shared_secret']!=='old-secret-from-previous-pair'&&!str_contains(json_encode($result),'secret'));

// Remote committed but reply lost: journal must survive and resume without a second code.
[$transport4,$remote4]=signedMock(false,true);
[$svc4,$pdo4,$path4,$state4]=makeLocal($transport4);
expectCode('ambiguous network reply remains explicitly pending',fn()=>$svc4->recoverReplacement('https://www.soknaspace.ir','one-time','Cafe',1,'REPLACE_PUBLIC',2),'public_unreachable');
check('lost remote reply keeps protected journal',is_file($path4.'.public-recovery-pending.json'));
check('lost remote reply leaves old Local secret',(require $path4)['public']['shared_secret']==='old-secret-from-previous-pair');
check('remote only consumed one recovery code',$remote4->remote_commits===1);
$resume4=$svc4->resumePendingRecovery(1);
check('signed recovery resume updates epoch',$resume4['authority_epoch']===3&&$pdo4->state['authority_epoch']===3);
check('resume never rotates remote again',$remote4->remote_commits===1);
check('resume clears journal after success',!is_file($path4.'.public-recovery-pending.json'));

// Public already committed, but read-only proof temporarily fails.
[$transport5,$remote5]=signedMock(true,false);
[$svc5,$pdo5,$path5,$state5]=makeLocal($transport5);
expectCode('remote status outage leaves pending journal',fn()=>$svc5->recoverReplacement('https://www.soknaspace.ir','one-time','Cafe',1,'REPLACE_PUBLIC',2),'public_temporarily_unavailable');
expectCode('new recovery attempt forbidden while journal exists',fn()=>$svc5->recoverReplacement('https://www.soknaspace.ir','second-code','Cafe',1,'REPLACE_PUBLIC',2),'public_recovery_pending_resume_required');
$resume5=$svc5->resumePendingRecovery(1);
check('resume after status outage succeeds',$resume5['authority_epoch']===3);
check('resume after status outage uses same remote rotation',$remote5->remote_commits===1);

// No successful remote pairing: never activate Local on an unverified network failure.
[$transport6,$remote6]=signedMock(false,false);
$rejectAll=function($url,$method,$headers,$body)use($transport6){
 if(str_contains($url,'authority-recovery.php'))throw new PublicEdgeSyncException('public_unreachable','simulated outage',503);
 return $transport6($url,$method,$headers,$body);
};
[$svc6,$pdo6,$path6,$state6]=makeLocal($rejectAll);
expectCode('failed remote call remains pending but not activated',fn()=>$svc6->recoverReplacement('https://www.soknaspace.ir','one-time','Cafe',1,'REPLACE_PUBLIC',2),'public_unreachable');
expectCode('unsigned resume cannot pretend recovery',fn()=>$svc6->resumePendingRecovery(1),'public_recovery_remote_unverified');
check('unverified remote leaves Local fenced',$pdo6->state['phase']==='reenroll_required'&&$pdo6->state['authority_epoch']===2);

$pubDb=new FakeRecoveryPDO();$pubDb->state=['installation_id'=>'local-example-12345','authority_epoch'=>1,'active'=>1,'revoked_at'=>null,'authority_state'=>'active'];
$key=base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
$pubAccess=new EmergencyAccessService(sys_get_temp_dir().'/sokna-r238-pub-'.bin2hex(random_bytes(5)));
$pubAuthority=new AuthorityEpochService($pubDb,new PairingSecretStore($pubDb,$key),$pubAccess);
expectCode('Public rejects minimum next 30 from epoch1',fn()=>$pubAuthority->createOffer('local-example-12345',1800,'test',30),'authority_target_invalid');
$offer=$pubAuthority->createOffer('local-example-12345',1800,'test',3);
check('Public creates monotonic skip 1->3 only with explicit minimum',$offer['from_epoch']===1&&$offer['authority_epoch']===3);
check('Public offer fences guest ordering',$pubDb->state['authority_state']==='reenroll_pending');
$publicNewSecret=bin2hex(random_bytes(32));
$done=$pubAuthority->complete('local-example-12345',$offer['enrollment_code'],$publicNewSecret,'Cafe');
check('Public accepts 1->3 and returns resync state',$done['authority_epoch']===3&&$done['authority_state']==='resync_required');
check('Public persists new secret and disables orders',!empty($pubDb->secrets['local-example-12345'])&&$pubDb->state['authority_state']==='resync_required');
expectCode('one-time recovery offer cannot be reused',fn()=>$pubAuthority->complete('local-example-12345',$offer['enrollment_code'],$publicNewSecret),'reenroll_denied');
$result=['passed'=>$pass,'failed'=>$failures,'note'=>'Fake PDO and in-process transport: simulated recovery logic, not an installed MariaDB/Windows qualification'];
file_put_contents(__DIR__.'/RECOVERY-LOGIC-TEST.json',json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));echo 'SUMMARY '.json_encode($result,JSON_UNESCAPED_UNICODE).'\n';exit($failures?1:0);