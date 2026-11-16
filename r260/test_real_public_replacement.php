<?php
declare(strict_types=1);

/**
 * One decisive disposable Public test using the actual pinned R249 kernel,
 * actual setup/pair SQL, owner session/receipt, HMAC Local transport and the
 * existing installation projection. No old Public access or host mutation.
 */
$root=(string)getenv('R260_QA_PUBLIC_ROOT');
if(getenv('GITHUB_ACTIONS')!=='true'||$root===''||!is_file($root.'/install.lock'))
    throw new RuntimeException('R260_MUST_USE_DISPOSABLE_INSTALLED_PUBLIC');
$_SERVER['HTTPS']='on';
$_SERVER['HTTP_HOST']='fresh.cafe-delta.example';
$_SERVER['DOCUMENT_ROOT']=$root;
$_SERVER['REQUEST_URI']='/new-public/move/approve';
$_SERVER['SCRIPT_NAME']='/new-public/index.php';
require_once $root.'/bootstrap.php';
require_once __DIR__.'/../apps/local-web/src/Core/Config.php';
require_once __DIR__.'/../apps/local-web/src/Domain/PublicEdge/PublicEdgeSyncException.php';
require_once __DIR__.'/../apps/local-web/src/Domain/PublicEdge/PublicEdgeSyncClient.php';
$cfg=require $root.'/config.php';
$core=sokna_public_bootstrap($cfg);
$kernel=new \Sokna\PublicEdge\Http\PublicHttpKernel($core,$root);
$pdo=$core->database();
$setup=new \Sokna\PublicEdge\Setup\PublicSetupService($root);
$base=(string)$core->config()->string('app.base_url');
$prefix='/new-public';
$checks=0;
function check260(bool $good,string $label):void{
 global $checks;
 if(!$good)throw new RuntimeException('R260_GATE_FAILED '.$label);
 $checks++;echo "PASS ".$label."\n";
}
check260($setup->status()['paired']===false,'real_new_public_is_unpaired');
$headers=['Origin'=>'https://fresh.cafe-delta.example','Sec-Fetch-Site'=>'same-origin'];
$api=$prefix.'/api/v1/owner/';
$login=$kernel->handle('POST',$api.'login',[],
    json_encode(['password'=>'isolated-owner-strong-2574']),$headers);
check260(($login['status']??0)===200,'owner_signs_into_actual_public_kernel');
$headers['Cookie']=explode(';',(string)$login['headers']['Set-Cookie'])[0];
$now=time();$source='https://unreachable.previous-cafe.example';
$challenge=[
 'contract'=>'sokna-public-move-attestation-v1','operation_id'=>'move-delta-replace-001',
 'local_installation_id'=>'local-delta-replacement-001',
 'source_public_instance_id'=>'','source_base_url'=>$source,'target_base_url'=>$base,
 'nonce'=>bin2hex(random_bytes(24)),'issued_at'=>$now,'expires_at'=>$now+300
];
$prepare=$kernel->handle('POST',$api.'consent_prepare',[],
    json_encode(['challenge'=>$challenge]),$headers);
$csrf=(string)(json_decode((string)$prepare['body'],true)['csrf']??'');
check260(($prepare['status']??0)===200&&strlen($csrf)>30,
    'owner_approval_challenge_without_old_public');
$signed=$kernel->handle('POST',$api.'consent_sign',[],
    json_encode(['challenge'=>$challenge,'csrf'=>$csrf]),$headers);
$receipt=json_decode((string)($signed['body']??''),true);
$code=(string)($receipt['pairing_code']??'');
check260(($signed['status']??0)===200 &&
   isset($receipt['receipt_envelope']['signature_b64']) &&
   preg_match('/^[A-F0-9]{8}(?:-[A-F0-9]{8}){2}$/D',$code)===1,
   'signed_destination_owner_generates_one_fresh_pairing_code');
$installation=$challenge['local_installation_id'];
$secret=bin2hex(random_bytes(32));
$pair=$setup->pair([
 'installation_id'=>$installation,'pairing_code'=>$code,
 'shared_secret'=>$secret,'replacement_mode'=>true,'display_name'=>'Delta'
]);
check260(($pair['paired']??false)===true &&
  ($pair['replacement_mode']??null)===true&&
  ($pair['guest_intake_enabled']??null)===false,
  'existing_pairing_engine_accepts_new_destination_with_intake_paused');
$q=$pdo->prepare('SELECT order_intake_enabled,public_move_intake_paused FROM installations WHERE installation_id=?');
$q->execute([$installation]);$first=$q->fetch(PDO::FETCH_ASSOC);
check260((int)$first['order_intake_enabled']===0&&(int)$first['public_move_intake_paused']===1,
    'persisted_intake_gate_disabled_before_catalog_sync');
$localConfig=\Sokna\Local\Core\Config::fromArray([
 'installation'=>['id'=>$installation],
 'public'=>['base_url'=>$base,'shared_secret'=>$secret]
]);
$transport=static function(string $url,string $method,array $h,string $json)use($kernel):array{
 return $kernel->handle($method,(string)(parse_url($url,PHP_URL_PATH)?:'/'),[], $json,$h);
};
$client=new \Sokna\Local\Domain\PublicEdge\PublicEdgeSyncClient($localConfig,$transport);
$projection=$client->postRaw('/api/v1/local/installation',[
 'display_name'=>'Delta','remote_enabled'=>true,'order_intake_enabled'=>true
],10);
$q->execute([$installation]);$middle=$q->fetch(PDO::FETCH_ASSOC);
check260(($projection['status']??0)===200 &&
    (int)$middle['order_intake_enabled']===0 &&
    (int)$middle['public_move_intake_paused']===1,
    'NORMAL_Local_installation_sync_cannot_prematurely_enable_orders');
$notReady=$client->postRaw('/api/v1/local/move/activate',[],10);
check260(($notReady['status']??0)===409,'cannot_open_guest_ordering_before_guest_catalog_and_availability');
$pdo->prepare('INSERT INTO guest_publish_revisions(installation_id,revision_id,content_hash,snapshot_json,media_manifest_json,generated_at) VALUES(?,?,?,?,?,UTC_TIMESTAMP())')
 ->execute([$installation,'r260-test-revision-01',str_repeat('a',64),'{}','[]']);
$pdo->prepare('INSERT INTO guest_active_revisions(installation_id,revision_id,activated_at) VALUES(?,?,UTC_TIMESTAMP())')
 ->execute([$installation,'r260-test-revision-01']);
$pdo->prepare('INSERT INTO guest_availability_state(installation_id,version,payload_json,generated_at,last_sync_at) VALUES(?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())')
 ->execute([$installation,str_repeat('b',64),'{}']);
$ready=$client->postRaw('/api/v1/local/move/activate',[],10);
check260(($ready['status']??0)===200 && ($ready['body']['guest_intake_enabled']??false)===true,
    'signed_new_Local_secret_activates_guest_only_after_sync_records');
$q->execute([$installation]);$last=$q->fetch(PDO::FETCH_ASSOC);
check260((int)$last['order_intake_enabled']===1 && (int)$last['public_move_intake_paused']===0,
    'persisted_gate_cleared_only_at_explicit_activation');
$again=$client->postRaw('/api/v1/local/move/activate',[],10);
check260(($again['status']??0)===200,'activation_is_idempotent_after_success');
echo "R260_REAL_PUBLIC_REPLACEMENT_GATE_PASS count=$checks\n";
