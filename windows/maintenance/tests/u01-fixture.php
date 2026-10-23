<?php
declare(strict_types=1);
[$unused,$root,$web,$port,$mode]=$argv;
if(!is_file($root.'/.u01-ci-only'))exit(64);
foreach(['Config','Database','MigrationExecutionException','Migrations'] as $class)require_once $web.'/src/Core/'.$class.'.php';
if($mode==='init'){
    $server=new PDO('mysql:host=127.0.0.1;port='.$port,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $server->exec('DROP DATABASE IF EXISTS sokna_u01_ci; CREATE DATABASE sokna_u01_ci CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $cfg=['installation'=>['id'=>'u01-disposable-ci'],'app'=>['data_dir'=>$root.'/Data','timezone'=>'Asia/Tehran'],'db'=>['host'=>'127.0.0.1','port'=>$port,'name'=>'sokna_u01_ci','charset'=>'utf8mb4','user'=>'root','pass'=>'']];
    file_put_contents($root.'/config.php',"<?php\nreturn ".var_export($cfg,true).";\n");
    file_put_contents($root.'/install.lock',json_encode(['format'=>'sokna-install-lock-v3','installation_id'=>'u01-disposable-ci']));
    file_put_contents($web.'/.sokna-installation.json',json_encode(['format'=>'sokna-local-installation-binding-v1','installation_id'=>'u01-disposable-ci','web_version'=>trim(file_get_contents($web.'/VERSION.txt'))]));
    @mkdir($root.'/Data/lifecycle/local',0777,true);@mkdir($root.'/Data/guest-media',0777,true);
    file_put_contents($root.'/Data/guest-media/sentinel.txt','preserve-media');
    file_put_contents($root.'/Data/lifecycle/local/state.json',json_encode(['format'=>'sokna-local-update-state-v1','active_version'=>trim(file_get_contents($web.'/VERSION.txt')),'staged'=>null,'activation_journal'=>null,'recovery_mode'=>null,'history'=>[]]));
    copy($web.'/resources/local-managed-files-v1.json',$root.'/Data/lifecycle/local/managed-files.json');
}
$cfg=require $root.'/config.php';$pdo=\Sokna\Local\Core\Database::connect(\Sokna\Local\Core\Config::fromArray($cfg));
$m=new \Sokna\Local\Core\Migrations($pdo,$web.'/database/migrations');
if($mode==='init'){
    $m->migrate();
    $pdo->exec("CREATE TABLE u01_sentinel (id INT PRIMARY KEY, value VARCHAR(40)) ENGINE=InnoDB;INSERT INTO u01_sentinel VALUES(1,'preserve-business-data')");
}
if($mode==='verify'){
    if(count($m->appliedVersions())!==count($m->catalog()) || $pdo->query('SELECT value FROM u01_sentinel WHERE id=1')->fetchColumn()!=='preserve-business-data' || file_get_contents($root.'/Data/guest-media/sentinel.txt')!=='preserve-media')exit(65);
    if((int)$pdo->query("SELECT COUNT(*) FROM schema_migration_statements WHERE state<>'applied'")->fetchColumn()!==0)exit(66);
}
echo 'U01_DATABASE_FIXTURE_OK';
