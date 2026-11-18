<?php
declare(strict_types=1);
/**
 * R280: actual 82-migration database, separate Local source/target MariaDB
 * and encrypted source business archive. Disposable GitHub CI only.
 * Cannot prove Windows service or physical print handoff.
 */
if(PHP_SAPI!=='cli'||getenv('GITHUB_ACTIONS')!=='true')
    throw new RuntimeException('R280_NO_LIVE_CAFE_EXECUTION');
$root=(string)getenv('R280_LOCAL_SOURCE_ROOT');
if(!is_dir($root)||!is_file($root.'/bootstrap.php'))throw new RuntimeException('R280_SOURCE_ROOT_MISSING');
require_once $root.'/bootstrap.php';
require_once (is_file($root.'/src/Domain/Recovery/SafeRestoreStagingService.php') ? $root.'/src/Domain/Recovery/SafeRestoreStagingService.php' : __DIR__.'/../overlay/local/src/Domain/Recovery/SafeRestoreStagingService.php');

$host=(string)(getenv('R280_DB_HOST')?:'127.0.0.1');
$port=(int)(getenv('R280_DB_PORT')?:3306);
$envPassword=getenv('R280_DB_PASSWORD');$pass=$envPassword===false?'qa-only':(string)$envPassword;
$dbPrefix='sokna_r280_';
$dsn='mysql:host='.$host.';port='.$port.';charset=utf8mb4';
$admin=new PDO($dsn,'root',$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$sourceDb=$dbPrefix.'source';$targetDb=$dbPrefix.'newmachine';
$dirs=sys_get_temp_dir().'/sokna-r280-e2e-'.bin2hex(random_bytes(6));
if(!mkdir($dirs,0700,true))throw new RuntimeException('R280_TEMP_ROOT');
$assertions=0;
$check=static function(bool $ok,string $name)use(&$assertions):void{
    if(!$ok)throw new RuntimeException('R280_ASSERT_FAIL '.$name);
    $assertions++; echo 'PASS '.$name.PHP_EOL;
};
$pdo=static fn(string $db):PDO=>new PDO($dsn.';dbname='.$db,'root',$pass,
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
try {
    foreach([$sourceDb,$targetDb] as $db){
        $admin->exec('DROP DATABASE IF EXISTS `'.$db.'`');
        $admin->exec('CREATE DATABASE `'.$db.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    }
    $configs=[];
    foreach(['old'=>$sourceDb,'new'=>$targetDb] as $key=>$db){
        $data=$dirs.'/'.$key;
        mkdir($data,0700,true);
        $configs[$key]=[
            'app'=>['timezone'=>'Asia/Tehran','data_dir'=>$data],
            'installation'=>['id'=>'r280-disposable-'.$key.'-installation'],
            'db'=>['host'=>$host,'port'=>(string)$port,'name'=>$db,
                    'charset'=>'utf8mb4','user'=>'root','pass'=>$pass],
            'public'=>['base_url'=>'https://r280-public-offline.test',
                      'shared_secret'=>str_repeat($key==='old'?'a':'b',64)],
        ];
    }
    // Real Local migration 0060 requires a writable private media vault and
    // the original installer-style config outside Web. Keep both CI-only.
    foreach($configs as $config){
        $media=$config['app']['data_dir'].'/guest-media';
        if(!is_dir($media))mkdir($media,0700,true);
    }
    $hostConfig=dirname($root).'/config.php';
    if(is_file($hostConfig)||is_link($hostConfig))
        throw new RuntimeException('R280_REFUSE_REAL_HOST_CONFIG_OVERWRITE');
    file_put_contents($hostConfig,"<?php\nreturn ".var_export($configs['old'],true).";\n");
    $cores=[];
    foreach($configs as $key=>$config){
        $core=sokna_local_bootstrap($config);
        $first=$core->migrations()->migrate('0059_menu_business_reconciliation_v4');
        // The original setup seeds catalogue between the two exact SQL phases.
        // Schema 0059 expects the canonical seed even for a fresh target.
        // The target still has no transactional users/orders/inventory.
        $core->defaultContentSeeder()->seed();
        $rest=$core->migrations()->migrate();
        $count=(int)$core->database()->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
        $check($count===82 && count($first)+count($rest)===82, $key.' complete 82 real migrations');
        $cores[$key]=$core;
    }
    $old=$cores['old']->database();
    $new=$cores['new']->database();
    $userInsert=$old->prepare("INSERT INTO users(username,password_hash,display_name,role) VALUES(?,?,?,?)");
    $userInsert->execute(['r280-admin',str_repeat('x',60),'R280 migration admin','admin']);
    $old->exec("INSERT INTO cafe_tables(name,table_number,code,access_token)
       VALUES('R280 real table',407,'r280-407','r280-qr-token')");
    $tableId=(int)$old->lastInsertId();
    $stmt=$old->prepare("INSERT INTO orders(public_code,client_token,table_id,order_source,
      status,total_amount,business_order_number,business_date,business_shift_key,business_shift_label,business_cutoff_snapshot)
      VALUES('R280-ORDER-01','r280-unique-client-01',?,'staff','new',525000,1,'2026-11-18',
        'r280-shift','test','04:00')");
    $stmt->execute([$tableId]);
    $orderId=(int)$old->lastInsertId();
    $stmt=$old->prepare("INSERT INTO order_items(order_id,item_name,unit_price,quantity,line_total)
          VALUES(?,'R280 tea',175000,3,525000)");
    $stmt->execute([$orderId]);
    $old->prepare("INSERT INTO settings(setting_key,setting_value) VALUES(?,?)")->execute(
        ['settings.payment.final_amount_rial','1']);
    $old->prepare("INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")
        ->execute(['installation.id',$configs['old']['installation']['id']]);
    $check((int)$old->query('SELECT COUNT(*) FROM orders')->fetchColumn()===1,
        'source contains actual staff order and immutable line snapshot');
    $obs=new \Sokna\Local\Core\Observability($configs['old']['app']['data_dir']);
    $archive=$dirs.'/r280-local-source.skbf';$pw='r280-local-machine-qa-only-password';
    $sourceBackup=new \Sokna\Local\Domain\Recovery\BusinessBackupService($old,$obs,$configs['old']['installation']['id']);
    $created=$sourceBackup->createEncrypted($archive,$pw);
    $check(is_file($archive)&&hash_file('sha256',$archive)===$created['sha256'],
        'encrypted business backup written with verified SHA');
    $man=$sourceBackup->inspectEncrypted($archive,$pw);
    $check(($man['snapshot_consistency']??'')==='innodb-repeatable-read-single-transaction-v1' &&
        count((array)($man['tables']??[]))>30,
        'one consistent database snapshot across real business tables');

    $newAuthority=new \Sokna\Local\Domain\PublicEdge\PublicAuthorityStateService(
        $new,$configs['new']['installation']['id']);
    $newConfig=\Sokna\Local\Core\Config::fromArray($configs['new']);
    $newObs=new \Sokna\Local\Core\Observability($configs['new']['app']['data_dir']);
    $remoteRequests=0;
    $newClient=new \Sokna\Local\Domain\PublicEdge\PublicEdgeSyncClient(
       $newConfig,static function()use(&$remoteRequests):array{
           $remoteRequests++;
           return ['status'=>503,'body'=>['ok'=>false,'error'=>'old_public_offline']];
       },$newObs,$newAuthority);
    $restore=new \Sokna\Local\Domain\Recovery\BusinessBackupService(
        $new,$newObs,$configs['new']['installation']['id'],$newAuthority,$newClient);
    foreach(['users','orders','inventory_items','expenses','settlement_records','subscriber_ledger','accommodation_transfers'] as $marker){
        $markerCount=(int)$new->query('SELECT COUNT(*) FROM `'.$marker.'`')->fetchColumn();
        echo 'R280_FRESH_SETUP_MARKER '.$marker.'='.$markerCount.PHP_EOL;
    }
    try{
        $restore->restoreEncryptedToEmptyTarget($archive,'incorrect-r280-passphrase');
        $check(false,'reject incorrect passphrase');
    }catch(\Sokna\Local\Domain\Recovery\RecoveryException $e){
        $check($e->errorCode==='backup_auth_failed','reject incorrect passphrase before mutation');
    }
    $check((int)$new->query('SELECT COUNT(*) FROM orders')->fetchColumn()===0,
        'target still empty after wrong password');
    // Current installer seeds business rows (7 users, 117 inventory items in
    // the 2026-11-18 R280 fixture). This is NOT an empty restoration target.
    // Never clean them with ad-hoc SQL or silently overwrite. Prove it refuses
    // the restore without altering either installation.
    $newUsers=(int)$new->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $newInventory=(int)$new->query('SELECT COUNT(*) FROM inventory_items')->fetchColumn();
    $check($newUsers>0&&$newInventory>0,
        'standard clean install prepopulates users and inventory; not a restore-ready empty target');
    try{
        $restore->restoreEncryptedToEmptyTarget($archive,$pw);
        throw new RuntimeException('R280_UNSAFE_FRESH_INSTALL_RESTORE_ALLOWED');
    }catch(\Sokna\Local\Domain\Recovery\RecoveryException $e){
        $check($e->errorCode==='target_not_empty' &&
            in_array((string)($e->details['table']??''),['users','inventory_items'],true),
            'restore fail-closed on seed-only standard setup; dedicated restore-mode missing');
    }
    $missing=(int)$admin->query("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='sokna_restore_r281_wrongpass'")->fetchColumn();
    $check($missing===0,'invalid backup password rejected BEFORE creating any staging database');
    $check((int)$new->query('SELECT COUNT(*) FROM users')->fetchColumn()===$newUsers &&
        (int)$new->query('SELECT COUNT(*) FROM inventory_items')->fetchColumn()===$newInventory &&
        (int)$new->query('SELECT COUNT(*) FROM orders')->fetchColumn()===0,
        'blocked restore leaves target initial data unchanged');
    $check((int)$old->query('SELECT COUNT(*) FROM orders')->fetchColumn()===1 &&
        (int)$old->query('SELECT total_amount FROM orders LIMIT 1')->fetchColumn()===525000,
        'blocked restore leaves source orders and finance unchanged');
    $check($newAuthority->phase()==='active'&&$remoteRequests===0,
        'refused restore neither contacts old Public nor asserts a completed migration');
    $check((int)$new->query('SELECT COUNT(*) FROM print_jobs')->fetchColumn()===0 &&
        (int)$new->query('SELECT COUNT(*) FROM print_agents')->fetchColumn()===0,
        'new Windows machine has no cloned print queue or PrintAgent association');
    echo 'R280_82_SCHEMA_STANDARD_INSTALL_RESTORE_EXPECTED_SAFE_BLOCK_'.(string)$assertions.'_PASS'.PHP_EOL;
    echo 'R280_OPERATOR_TRANSFER_REQUIRES_DEDICATED_RESTORE_ONBOARDING_NO_GO'.PHP_EOL;
    // R281: stage to a brand-new schema-only database; the installed target
    // (7 users, 117 inventory items) is never truncated or reconfigured.
    $stageName='sokna_restore_r281_stage';
    $wrongStage='sokna_restore_r281_wrongpass';
    $stage=new \Sokna\Local\Domain\Recovery\SafeRestoreStagingService(
        $admin,static fn(string $db):PDO=>$pdo($db),$obs);
    $result=$stage->stage($targetDb,$stageName,$archive,$pw,
        $configs['new']['installation']['id']);
    $check(($result['ok']??false)===true &&
        ($result['stage_only']??false)===true &&
        ($result['activation_authorized']??true)===false &&
        ($result['old_machine_fencing_verified']??true)===false,
        'staged database has restored business rows but zero activation authority');
    $staged=$pdo($stageName);
    $check((int)$staged->query('SELECT COUNT(*) FROM orders')->fetchColumn()===1 &&
        (int)$staged->query('SELECT total_amount FROM orders LIMIT 1')->fetchColumn()===525000,
        'original real order fully restored in separate DB');
    $check((int)$staged->query('SELECT SUM(line_total) FROM order_items')->fetchColumn()===525000 &&
        (int)$staged->query("SELECT COUNT(*) FROM users WHERE username='r280-admin'")->fetchColumn()===1,
        'order line snapshot and staff account restored');
    $newIdentity=$staged->query("SELECT setting_value FROM settings WHERE setting_key='installation.id'")
        ->fetchColumn();
    $check($newIdentity===$configs['new']['installation']['id'] &&
        $newIdentity!==$configs['old']['installation']['id'],
        'old Local identity not silently cloned into new staged machine');
    $check($staged->query("SELECT setting_value FROM settings WHERE setting_key='settings.payment.final_amount_rial'")
        ->fetchColumn()==='1',
        'persistent Rial display setting retained in restored business data');
    $stageAuthority=new \Sokna\Local\Domain\PublicEdge\PublicAuthorityStateService(
        $staged,$configs['new']['installation']['id']);
    $check($stageAuthority->phase()==='reenroll_required',
        'new separate database prevents Public sync until owner-authorized re-enrollment');
    $check((int)$staged->query('SELECT COUNT(*) FROM print_jobs')->fetchColumn()===0 &&
           (int)$staged->query('SELECT COUNT(*) FROM print_agents')->fetchColumn()===0,
        'no Windows printer identities or pending print jobs copied');
    try{
        $stage->stage($targetDb,$stageName,$archive,$pw,
            $configs['new']['installation']['id']);
        throw new RuntimeException('R281_EXISTING_STAGE_WAS_MODIFIED');
    }catch(\Sokna\Local\Domain\Recovery\RecoveryException $e){
        $check($e->errorCode==='restore_staging_already_exists',
            'cannot overwrite even a previously staged database');
    }
    try{
        $stage->stage($targetDb,$wrongStage,$archive,'r281-incorrect-but-strong-passphrase-2026',
            $configs['new']['installation']['id']);
        throw new RuntimeException('R281_WRONG_PASS_STAGE_DID_NOT_FAIL');
    }catch(\Sokna\Local\Domain\Recovery\RecoveryException $e){
        echo 'R282_WRONG_PASS_FAILURE_CODE '.$e->errorCode.PHP_EOL;
        $check($e->errorCode==='backup_auth_failed',
            'wrong passphrase aborts isolated staging without touching existing database');
    }
    $check((int)$new->query('SELECT COUNT(*) FROM users')->fetchColumn()===$newUsers &&
        (int)$new->query('SELECT COUNT(*) FROM inventory_items')->fetchColumn()===$newInventory,
        'original seeded destination unchanged after successful staging and wrong-pass failure');
    $check((int)$old->query('SELECT COUNT(*) FROM orders')->fetchColumn()===1,
        'source business database unchanged after staged restoration');
    echo 'R282_SAFE_SEPARATE_DATABASE_BUSINESS_RESTORE_END_TO_END_'.(string)$assertions.'_PASS'.PHP_EOL;
    // R284 disposable old-machine seal: test the real 82-migration Local DB.
    $oldData=$configs['old']['app']['data_dir'];
    @mkdir($oldData.'/guest-media/original',0700,true);
    file_put_contents($oldData.'/guest-media/original/coffee.webp',random_bytes(1024));
    $oldObs=new \Sokna\Local\Core\Observability($oldData);
    $Fence='\Sokna\Local\Domain\Recovery\LocalMachineTransferFence';
    $noWorkers=new $Fence($old,$oldObs,$oldData,$configs['old']['installation']['id'],
        static fn():bool=>false);
    $businessOut=$dirs.'/r284-final-source.skbf';$mediaOut=$dirs.'/r284-source-media.smv';
    $handoffOut=$dirs.'/r284-machine-handoff.json';
    $mediaKey='sokna-r284-authenticated-media-test-2026';
    $handoffKey='sokna-r284-offline-handoff-key-at-least-32-characters';
    try {
        $noWorkers->seal($businessOut,$mediaOut,$pw,$mediaKey,$handoffKey,$handoffOut);
        throw new RuntimeException('R284_UNSTOPPED_WORKERS_ACCEPTED');
    } catch(\Sokna\Local\Domain\Recovery\RecoveryException $e) {
        $check($e->errorCode==='cutover_workers_running','cannot seal old Local while workers are running');
    }
    $fenceMarker=$oldData.'/lifecycle/local/offline-machine-fenced.json';
    $check(!is_file($fenceMarker),'no persistent marker written before workers stop');
    $dest=$old->query("SELECT destination_key FROM print_destinations ORDER BY destination_key LIMIT 1")->fetchColumn();
    $insert=$old->prepare("INSERT INTO print_jobs(public_token,idempotency_key,job_type,destination_key,status,payload_json,content_sha256,entity_type)
       VALUES(?,?,?,?,?,?,?,?)");
    $insert->execute([bin2hex(random_bytes(16)),bin2hex(random_bytes(32)),'prep',strval($dest),
        'pending','{}',str_repeat('e',64),'r284-fixture']);
    $safeWorkers=new $Fence($old,$oldObs,$oldData,$configs['old']['installation']['id'],
        static fn():bool=>true);
    try {
        $safeWorkers->seal($businessOut,$mediaOut,$pw,$mediaKey,$handoffKey,$handoffOut);
        throw new RuntimeException('R284_PENDING_PRINT_ACCEPTED');
    } catch(\Sokna\Local\Domain\Recovery\RecoveryException $e) {
        $check($e->errorCode==='cutover_unresolved_print_jobs','source refuses pending/uncertain print jobs');
    }
    $check(!is_file($fenceMarker)&&!is_file($businessOut),'no source fence or stale backup after failed preflight');
    $old->exec("UPDATE print_jobs SET status='cancelled' WHERE entity_type='r284-fixture'");
    $sealed=$safeWorkers->seal($businessOut,$mediaOut,$pw,$mediaKey,$handoffKey,$handoffOut);
    $check($sealed['source_fenced']===true && is_file($fenceMarker),
        'old Local persistent offline HTTP/Runtime/Guest fence created');
    $check(is_file($businessOut) && is_file($mediaOut) && is_file($handoffOut),
        'fresh encrypted business, encrypted media and signed handoff files generated');
    $verified=$Fence::verify($handoffOut,$handoffKey,$businessOut,$mediaOut,
        $configs['old']['installation']['id']);
    $check(($verified['source_receipt_verified']??false)===true &&
        $verified['source_public_fenced_remotely']===false &&
        $verified['target_business_activated']===false,
        'authenticated source receipt says Public and new Local are NOT activated');
    $check(hash_equals((string)$verified['business_backup_sha256'],hash_file('sha256',$businessOut)) &&
        hash_equals((string)$verified['media_archive_sha256'],hash_file('sha256',$mediaOut)),
        'signed receipt binds both exact encrypted archive bytes');
    $inspected=(new \Sokna\Local\Domain\Recovery\PrivateMediaTransferService())
        ->inspect($mediaOut,$mediaKey,$configs['old']['installation']['id'],
            (string)$verified['business_backup_sha256']);
    $check($inspected['media_files']===1,'encrypted private media archive independently authenticated');
    try {
        $safeWorkers->seal($dirs.'/second.skbf',$dirs.'/second.smv',$pw,$mediaKey,$handoffKey,$dirs.'/second.json');
        throw new RuntimeException('R284_REPEATED_FENCE_ACCEPTED');
    } catch(\Sokna\Local\Domain\Recovery\RecoveryException $e) {
        $check($e->errorCode==='cutover_already_fenced','second offline handoff cannot silently diverge');
    }
    try {
        $Fence::verify($handoffOut,$handoffKey,$businessOut,$mediaOut,'another-old-machine-2040');
        throw new RuntimeException('R284_WRONG_MACHINE_ACCEPTED');
    } catch(\Sokna\Local\Domain\Recovery\RecoveryException $e) {
        $check($e->errorCode==='cutover_receipt_wrong_source','foreign Local identity never accepted');
    }
    try {
        $Fence::verify($handoffOut,'wrong-source-handoff-key-longer-than-32-characters',
            $businessOut,$mediaOut,$configs['old']['installation']['id']);
        throw new RuntimeException('R284_BAD_HMAC_ACCEPTED');
    } catch(\Sokna\Local\Domain\Recovery\RecoveryException $e) {
        $check($e->errorCode==='cutover_receipt_bad_signature','forged source handoff HMAC refused');
    }
    $fakeMedia=$dirs.'/r284-tampered.smv';copy($mediaOut,$fakeMedia);
    $encrypted=file_get_contents($fakeMedia);
    $encrypted[strlen($encrypted)-3]=chr(ord($encrypted[strlen($encrypted)-3])^1);
    file_put_contents($fakeMedia,$encrypted);
    try{
        $Fence::verify($handoffOut,$handoffKey,$businessOut,$fakeMedia,$configs['old']['installation']['id']);
        throw new RuntimeException('R284_MODIFIED_MEDIA_ACCEPTED');
    }catch(\Sokna\Local\Domain\Recovery\RecoveryException $e){
        $check($e->errorCode==='cutover_archive_hash_mismatch','tampered encrypted media SHA rejected at receiver');
    }
    $check((int)$old->query('SELECT COUNT(*) FROM orders')->fetchColumn()===1 &&
        (int)$old->query('SELECT SUM(total_amount) FROM orders')->fetchColumn()===525000,
        'source database orders and money unchanged after source offline fence');
    echo 'R284_82_SCHEMA_SOURCE_OFFLINE_FENCE_AND_CRYPTO_HANDOFF_'.(string)$assertions.'_PASS'.PHP_EOL;

}finally{
    foreach([$sourceDb,$targetDb,'sokna_restore_r281_stage','sokna_restore_r281_wrongpass'] as $db)$admin->exec('DROP DATABASE IF EXISTS `'.$db.'`');
}
