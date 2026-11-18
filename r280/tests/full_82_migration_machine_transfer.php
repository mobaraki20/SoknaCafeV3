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
    try{
        $restore->restoreEncryptedToEmptyTarget($archive,'incorrect-r280-passphrase');
        $check(false,'reject incorrect passphrase');
    }catch(\Sokna\Local\Domain\Recovery\RecoveryException $e){
        $check($e->errorCode==='backup_auth_failed','reject incorrect passphrase before mutation');
    }
    $check((int)$new->query('SELECT COUNT(*) FROM orders')->fetchColumn()===0,
        'target still empty after wrong password');
    $restored=$restore->restoreEncryptedToEmptyTarget($archive,$pw);
    $check(($restored['restored']??false)===true,'full real source business rows imported');
    $check((int)$new->query('SELECT COUNT(*) FROM orders')->fetchColumn()===1
       &&(int)$new->query('SELECT total_amount FROM orders LIMIT 1')->fetchColumn()===525000,
       'order value unchanged');
    $check((int)$new->query('SELECT SUM(line_total) FROM order_items')->fetchColumn()===525000,
       'line financial snapshot unchanged');
    $check((int)$new->query("SELECT COUNT(*) FROM users WHERE username='r280-admin'")->fetchColumn()===1,
       'real staff account retained');
    $check((int)$new->query("SELECT COUNT(*) FROM settings WHERE setting_key='settings.payment.final_amount_rial'")->fetchColumn()===1,
       'persistent payment display setting retained');
    $check($newAuthority->phase()==='reenroll_required'
       &&($restored['machine_identity_reprovision_required']??false)===true,
       'new machine Public authority blocked pending explicit reenrollment');
    try{
        $newClient->post('/api/v1/local/heartbeat',[],2);
        $check(false,'no normal guest sync before reenrollment');
    }catch(\Sokna\Local\Domain\PublicEdge\PublicEdgeSyncException $e){
        $check($e->errorCode==='public_authority_reenrollment_required',
            'Public sync stopped before contacting offline previous origin');
    }
    $check($remoteRequests>=1 && ($restored['public_authority_fence_confirmed']??true)===false,
       'remote fence failed and result remains explicitly uncertain');
    $check((int)$old->query('SELECT COUNT(*) FROM orders')->fetchColumn()===1,
        'original machine source database not modified by restore');
    try{
        $restore->restoreEncryptedToEmptyTarget($archive,$pw);
        $check(false,'no overwrite restored machine');
    }catch(\Sokna\Local\Domain\Recovery\RecoveryException $e){
        $check($e->errorCode==='target_not_empty','target cannot be restored over active business data');
    }
    $check((int)$new->query('SELECT COUNT(*) FROM print_jobs')->fetchColumn()===0,
        'print job queue not migrated between machines');
    $check((int)$new->query('SELECT COUNT(*) FROM print_agents')->fetchColumn()===0,
        'PrintAgent associations not cloned between machines');
    echo 'R280_FULL_82_MIGRATIONS_TWO_LOCAL_DATABASES_ENCRYPTED_TRANSFER_'.(string)$assertions.'_PASS'.PHP_EOL;
}finally{
    foreach([$sourceDb,$targetDb] as $db)$admin->exec('DROP DATABASE IF EXISTS `'.$db.'`');
}
