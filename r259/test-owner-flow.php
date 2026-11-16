<?php
declare(strict_types=1);

/** Only critical path: Local challenge -> real Public owner -> signed receipt
 * -> independently authenticated Local admin -> durable approval. No cutover. */
$root=dirname(__DIR__);
$files=[
 'apps/local-web/src/Domain/PublicEdge/PublicMoveDecisionEngine.php',
 'apps/local-web/src/Domain/PublicEdge/PublicMoveApprovalMac.php',
 'apps/local-web/src/Domain/PublicEdge/PublicMoveIdentityVerifier.php',
 'apps/local-web/src/Domain/PublicEdge/PublicMoveReceiptVerifier.php',
 'apps/local-web/src/Domain/PublicEdge/PublicMoveApprovalLedger.php',
 'apps/local-web/src/Domain/PublicEdge/PublicMoveLocalApprovalService.php',
 'apps/local-web/src/Domain/PublicEdge/PublicMoveLocalKeyStore.php',
 'apps/local-web/src/Domain/PublicEdge/PublicMoveLocalFlow.php',
 'apps/public/src/Security/PublicMoveOwnerConsentGuard.php',
 'apps/public/src/Security/PublicMoveAttestationIssuer.php',
 'apps/public/src/Security/PublicMoveInstanceKeyStore.php',
 'apps/public/src/Security/PublicMoveOwnerBootstrapService.php',
 'apps/public/src/Security/PublicMoveIndependentOwnerBridge.php',
 'apps/public/src/Security/PublicMoveConsentReceiptStore.php',
 'apps/public/src/Http/PublicMoveConsentPage.php',
];
foreach($files as $file)require_once $root.'/'.$file;
use Sokna\Local\Domain\PublicEdge\PublicMoveApprovalLedger;
use Sokna\Local\Domain\PublicEdge\PublicMoveLocalApprovalService;
use Sokna\Local\Domain\PublicEdge\PublicMoveLocalKeyStore;
use Sokna\Local\Domain\PublicEdge\PublicMoveLocalFlow;
use Sokna\PublicEdge\Security\PublicMoveOwnerBootstrapService;
use Sokna\PublicEdge\Security\PublicMoveInstanceKeyStore;
use Sokna\PublicEdge\Security\PublicMoveIndependentOwnerBridge;
use Sokna\PublicEdge\Security\PublicMoveConsentReceiptStore;
use Sokna\PublicEdge\Http\PublicMoveConsentPage;
function assertion(bool $pass,string $label):void{
  global $checks;
  if(!$pass)throw new RuntimeException('R259_FAIL '.$label);
  $checks++;echo 'PASS '.$label."\n";
}
$checks=0;
$localDsn=getenv('R259_LOCAL_DSN');$publicDsn=getenv('R259_PUBLIC_DSN');
if(!$localDsn||!$publicDsn)throw new RuntimeException('Disposable two-DB MariaDB required');
$username=(string)(getenv('R259_DB_USER')?:'root');$password=(string)(getenv('R259_DB_PASSWORD')?:'');
$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false];
$local=new PDO($localDsn,$username,$password,$options);
$public=new PDO($publicDsn,$username,$password,$options);
$schemas=[
 [$local,['apps/local-web/database/migrations/0075_public_move_owner_approval.sql']],
 [$public,['r255/owner-bootstrap-schema-fixture.sql','r257/receipt-schema-fixture.sql']]
];
foreach($schemas as [$db,$paths])foreach($paths as $p){
 $sql=preg_replace('/^\s*--.*$/m','',(string)file_get_contents($root.'/'.$p));
 foreach(explode(';',$sql) as $fragment)if(trim($fragment)!=='')$db->exec($fragment);
}
$temporary=sys_get_temp_dir().'/r259-owner-'.bin2hex(random_bytes(7));
mkdir($temporary,0700);mkdir($temporary.'/web',0700);mkdir($temporary.'/store',0700);mkdir($temporary.'/private',0700);
$localId='local-cafe-gamma-001';$sourceId='public-old-gamma-01';
$targetId='public-dest-gamma-01';
$source='https://old.cafe-gamma.example';
$target='https://new.cafe-gamma.example/site';
$now=time();
try{
 $keys=(new PublicMoveLocalKeyStore($temporary.'/store',$temporary.'/web'))->keys();
 assertion(strlen($keys['mac'])===32&&strlen($keys['csrf'])===32,
   'Local generates private per-installation MAC/CSRF keys');
 $keysAgain=(new PublicMoveLocalKeyStore($temporary.'/store',$temporary.'/web'))->keys();
 assertion($keys===$keysAgain,'Local keys durable across repeat process');
 $session=bin2hex(random_bytes(32));
 $resolve=static fn(string $given)=>hash_equals($session,$given)
   ?['authenticated'=>true,'role'=>'admin','installation_id'=>$localId]:null;
 $ledger=new PublicMoveApprovalLedger($local);
 $approval=new PublicMoveLocalApprovalService($ledger,$resolve,$keys['mac'],$keys['csrf']);
 $flow=new PublicMoveLocalFlow($ledger,$approval,$localId);
 $started=$flow->begin($target,$source,$sourceId,$now);
 assertion($started['status']==='awaiting_destination_owner'&&!$started['safe_to_cutover'],
   'Local records one challenge without changing the connection');
 $challenge=$started['challenge'];$op=$started['operation_id'];
 $resumed=$flow->begin($target,$source,$sourceId,$now+1,$op);
 assertion($resumed['resumed']===true&&$resumed['challenge']===$challenge,
   'same administrator reopens same unexpired request');
 $owners=new PublicMoveOwnerBootstrapService($public);
 $keysPublic=new PublicMoveInstanceKeyStore($temporary.'/private',$temporary.'/web');
 $keysPublic->provision($targetId,true);
 $claim=$owners->stageClaim($targetId,$now);
 $owners->initialize($targetId,$claim['setup_token'],'strong-public-owner-password-123',$now);
 $login=$owners->login($targetId,'strong-public-owner-password-123',$now);
 assertion($login['ok']===true,'destination owner authenticates on independent Public');
 $bridge=new PublicMoveIndependentOwnerBridge($owners,$keysPublic,$target,$targetId,random_bytes(32));
 $csrf=$bridge->issueCsrf($login['token'],$challenge,$now);
 $proof=$bridge->attest($login['token'],$challenge,$csrf,
   'https://new.cafe-gamma.example','same-origin',$now+1);
 $receipt=(new PublicMoveConsentReceiptStore($public,$keysPublic))
   ->create($challenge,$proof,$targetId,$now+2);
 assertion(($receipt['already_recorded']??true)===false,
   'Public persists signed target owner receipt');
 $result=$flow->confirm($op,$session,$receipt['attestation'],$receipt['receipt_envelope'],$now+3);
 assertion($result['status']==='owner_approved'&&$result['safe_to_cutover']===false,
   'independent Local administrator confirms signed destination receipt');
 assertion($ledger->status($localId,$op)['status']==='owner_approved',
   'Local durable ledger readback is approved but not transferred');
 $wrong=bin2hex(random_bytes(32));
 try{$flow->confirm($op,$wrong,$receipt['attestation'],$receipt['receipt_envelope'],$now+4);
   throw new RuntimeException('wrong Local session accepted');}
 catch(RuntimeException|InvalidArgumentException $e){
   assertion($e->getMessage()!=='wrong Local session accepted',
     'different Local session cannot approve');
 }
 assertion($flow->cancel($op)['status']==='canceled',
   'operator can cancel approved preflight without cutover');
 $offline=$flow->begin($target,$source,'',$now+10);
 assertion($offline['status']==='awaiting_destination_owner' &&
   ($offline['challenge']['source_public_instance_id']??null)==='' &&
   $offline['safe_to_cutover']===false,
   'lost-old-Public recovery begins without old Public instance proof');
 $page=PublicMoveConsentPage::render($target);
 assertion($page['status']===200 && str_contains($page['body'],$target.'/assets/sokna-public-move.js') &&
   str_contains($page['body'],$target.'/staff/login') &&
   str_contains($page['headers']['Content-Security-Policy'],"script-src 'self'"),
   'Public page works at nested install path with strict CSP');
}finally{
 $remove=static function(string $p)use(&$remove):void{
   foreach(scandir($p)?:[] as $file){
     if($file==='.'||$file==='..')continue;$f=$p.'/'.$file;
     if(is_dir($f)&&!is_link($f))$remove($f);else @unlink($f);
   }@rmdir($p);
 };
 $remove($temporary);
}
echo "R259_OWNER_FLOW_REAL_MARIADB_PASS count=$checks\n";
