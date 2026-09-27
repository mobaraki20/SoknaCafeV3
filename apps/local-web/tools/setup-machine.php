<?php
declare(strict_types=1);

use Sokna\Local\Domain\Recovery\RecoveryException;

require_once dirname(__DIR__) . '/bootstrap.php';

function setup_fail(string $code,string $message,int $exit=2): never {
    fwrite(STDERR,json_encode(['success'=>false,'code'=>$code,'message'=>$message],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL);exit($exit);
}
function setup_arg(array $argv,string $key): string { foreach($argv as $arg)if(str_starts_with($arg,$key.'='))return substr($arg,strlen($key)+1);return ''; }
function setup_private_write(string $path,string $bytes): void {
    $dir=dirname($path);if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))setup_fail('write_failed','پوشه داده قابل ایجاد نیست.');
    $tmp=$path.'.tmp-'.bin2hex(random_bytes(4));if(file_put_contents($tmp,$bytes,LOCK_EX)===false)setup_fail('write_failed','فایل تنظیمات قابل نوشتن نیست.');@chmod($tmp,0600);if(!rename($tmp,$path)){@unlink($tmp);setup_fail('write_failed','فایل تنظیمات به‌صورت اتمیک ذخیره نشد.');}
}
function setup_config_php(array $config): string { return "<?php\ndeclare(strict_types=1);\nreturn ".var_export($config,true).";\n"; }

$mode=strtolower(setup_arg($argv,'--mode'));$configFile=setup_arg($argv,'--config-file');$validate=in_array('--validate-only',$argv,true);
if(!in_array($mode,['new','recover'],true))setup_fail('invalid_mode','حالت Setup معتبر نیست.');
if($configFile===''||!is_file($configFile))setup_fail('config_missing','فایل تنظیمات Setup پیدا نشد.');
try{$input=json_decode((string)file_get_contents($configFile),true,64,JSON_THROW_ON_ERROR);}catch(Throwable){setup_fail('config_invalid','فایل تنظیمات Setup معتبر نیست.');}
if(!is_array($input))setup_fail('config_invalid','فایل تنظیمات Setup معتبر نیست.');
$db=is_array($input['db']??null)?$input['db']:[];$dataDir=trim((string)($input['data_dir']??''));$timezone=trim((string)($input['timezone']??'Asia/Tehran'))?:'Asia/Tehran';
if($dataDir===''||!preg_match('/^(?:[A-Za-z]:[\\\\\/]|\/)/',$dataDir))setup_fail('data_dir_invalid','مسیر داده باید کامل باشد.');
$root=dirname(__DIR__,3);$configPath=$root.DIRECTORY_SEPARATOR.'config.php';$lockPath=$root.DIRECTORY_SEPARATOR.'install.lock';
$secrets=$dataDir.DIRECTORY_SEPARATOR.'secrets';$runtimeDir=$dataDir.DIRECTORY_SEPARATOR.'runtime';
$installationId=trim((string)($input['installation_id']??''));if($installationId==='')$installationId='local-'.bin2hex(random_bytes(16));
$localToken=bin2hex(random_bytes(32));$healthToken=bin2hex(random_bytes(32));
$appConfig=[
    'app'=>['timezone'=>$timezone,'data_dir'=>$dataDir,'session_lifetime'=>43200],
    'db'=>['host'=>trim((string)($db['host']??'')),'port'=>(string)($db['port']??'3306'),'name'=>trim((string)($db['name']??'')),'charset'=>'utf8mb4','user'=>trim((string)($db['user']??'')),'pass'=>(string)($db['pass']??'')],
    'installation'=>['id'=>$installationId],
    'runtime'=>['local_token'=>$localToken],
    'integrations'=>['accommodation'=>['base_url'=>'','secret'=>'']],
];
try{$core=sokna_local_bootstrap($appConfig);$pdo=$core->database();$pdo->query('SELECT 1')->fetchColumn();}catch(Throwable $e){setup_fail('database_unavailable','اتصال دیتابیس کامل نشد: '.$e->getMessage());}
if($validate){echo json_encode(['success'=>true,'mode'=>$mode,'database'=>'ready','mutation'=>'none'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;exit(0);}
try{$core->migrations()->migrate();}catch(Throwable $e){setup_fail('migration_failed','آماده‌سازی ساختار دیتابیس کامل نشد: '.$e->getMessage());}
try{
    if($mode==='new'){
        $count=(int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();if($count>0)setup_fail('target_not_empty','نصب جدید فقط روی دیتابیس بدون کاربر مجاز است.');
        $username=trim((string)($input['admin_user']??''));$password=(string)($input['admin_password']??'');if(strlen($username)<3||strlen($password)<8)setup_fail('admin_invalid','اطلاعات مدیر اولیه معتبر نیست.');
        $pdo->beginTransaction();
        $pdo->prepare("INSERT INTO users(username,password_hash,display_name,role,active) VALUES(?,?,?,'admin',1)")->execute([$username,password_hash($password,PASSWORD_DEFAULT),$username]);$adminId=(int)$pdo->lastInsertId();
        $setting=$pdo->prepare('INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');$setting->execute(['cafe.name',trim((string)($input['cafe_name']??'SOKNA'))]);
        $tables=max(1,min(100,(int)($input['table_count']??10)));$ins=$pdo->prepare('INSERT INTO cafe_tables(name,table_number,code,access_token,active,sort_order) VALUES(?,?,?,?,1,?)');for($i=1;$i<=$tables;$i++)$ins->execute(['میز '.$i,$i,'T'.$i,bin2hex(random_bytes(24)),$i]);
        $pdo->prepare("INSERT INTO audit_log(actor_user_id,actor_display_name_snapshot,action,entity_type,entity_id,details_json) VALUES(?,?,'setup.initialized','installation',?,?)")->execute([$adminId,$username,$installationId,json_encode(['table_count'=>$tables],JSON_UNESCAPED_UNICODE)]);
        $pdo->commit();
    }
    if($mode==='recover'){
        $recovery=setup_arg($argv,'--recovery-file');$passFile=setup_arg($argv,'--passphrase-file');if($recovery===''||!is_file($recovery))setup_fail('recovery_missing','فایل بازیابی پیدا نشد.');if($passFile===''||!is_file($passFile))setup_fail('passphrase_missing','فایل رمز بازیابی پیدا نشد.');$pass=rtrim((string)file_get_contents($passFile),"\r\n");if(strtolower(pathinfo($recovery,PATHINFO_EXTENSION))==='json'){$set=json_decode((string)file_get_contents($recovery),true);$mandatory=['runtime_machine_secret','print_agent_identity','tls_private_key'];if(!is_array($set)||($set['format']??'')!=='sokna-recovery-set-v1'||array_diff($mandatory,(array)($set['excluded_machine_identity']??[])))setup_fail('recovery_contract','Recovery Set معتبر نیست.');$business=(array)($set['business_backup']??[]);$candidate=(string)($business['path']??'');if($candidate==='')setup_fail('recovery_contract','فایل Business Backup در Recovery Set مشخص نیست.');if(!preg_match('/^(?:[A-Za-z]:[\\\/]|\/)/',$candidate))$candidate=dirname($recovery).DIRECTORY_SEPARATOR.$candidate;if(!is_file($candidate)||!hash_equals(strtolower((string)($business['sha256']??'')),strtolower((string)hash_file('sha256',$candidate))))setup_fail('recovery_integrity','Business Backup داخل Recovery Set معتبر نیست.');$recovery=$candidate;}$core->businessBackup()->restoreEncryptedToEmptyTarget($recovery,$pass,0);
    }
    setup_private_write($secrets.DIRECTORY_SEPARATOR.'runtime-local.token',$localToken."\n");setup_private_write($secrets.DIRECTORY_SEPARATOR.'runtime-health.token',$healthToken."\n");
    $runtime=[
        'contractVersion'=>1,'instanceId'=>'runtime-'.bin2hex(random_bytes(12)),'dataRoot'=>$runtimeDir,'healthPort'=>17621,
        'runtimeTokenFile'=>$secrets.DIRECTORY_SEPARATOR.'runtime-health.token','localTokenFile'=>$secrets.DIRECTORY_SEPARATOR.'runtime-local.token',
        'localBaseUrl'=>'https://127.0.0.1','printAgentServiceName'=>'SoknaPrintWorker','supervisePrintAgent'=>true,
        'triggers'=>[['key'=>'inventory.order_events','intervalSeconds'=>15],['key'=>'public.relay_sync','intervalSeconds'=>5],['key'=>'public.projection_sync','intervalSeconds'=>30],['key'=>'maintenance.health','intervalSeconds'=>60]],
    ];
    setup_private_write($runtimeDir.DIRECTORY_SEPARATOR.'runtime-config.json',json_encode($runtime,JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n");
    setup_private_write($configPath,setup_config_php($appConfig));setup_private_write($lockPath,json_encode(['format'=>'sokna-install-lock-v3','installation_id'=>$installationId,'created_at'=>gmdate('c')],JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT)."\n");
    echo json_encode(['success'=>true,'mode'=>$mode,'installation_id'=>$installationId,'machine_identity_reprovisioned'=>true],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();if($e instanceof RecoveryException)setup_fail($e->errorCode,$e->getMessage());setup_fail('setup_failed',$e->getMessage());}
