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
// Reconstruct an EXISTING seeded installation, not a new clean install:
// historical 0059 deliberately requires the cafe catalog to exist already.
$pre=$root.'/fixture-before-0059';@mkdir($pre,0700,true);
foreach(glob($web.'/database/migrations/*.sql') as $f)if(strcmp(basename($f),'0059_')<0)copy($f,$pre.'/'.basename($f));
(new \Sokna\Local\Core\Migrations($pdo,$pre))->migrate();
$doc=json_decode(file_get_contents($web.'/resources/default-content/v1/catalog.json'),true,512,JSON_THROW_ON_ERROR);
foreach($doc['categories'] as $c){$pdo->prepare('INSERT IGNORE INTO categories(category_key,name,audience) VALUES(?,?,?)')->execute([$c['category_key'],$c['name'],$c['audience']??'guest_staff']);}
foreach($doc['items'] as $i){$q=$pdo->prepare('SELECT id FROM categories WHERE category_key=?');$q->execute([$i['category_key']]);$category=$q->fetchColumn();if(!$category)throw new RuntimeException('FIXTURE_CATEGORY_MISSING');$pdo->prepare('INSERT IGNORE INTO items(item_code,category_id,name,price) VALUES(?,?,?,?)')->execute([$i['item_code'],$category,$i['name'],$i['price']]);}
// Historical existing-host SKUs referenced by immutable 0059, absent from the current clean catalog.
foreach(['1019','1113'] as $code)$pdo->prepare('INSERT IGNORE INTO items(item_code,category_id,name,price) VALUES(?,?,?,?)')->execute([$code,$category,'Existing-host fixture '.$code,1000]);
    $m->migrate();
    $pdo->exec("CREATE TABLE u01_sentinel (id INT PRIMARY KEY, value VARCHAR(40)) ENGINE=InnoDB;INSERT INTO u01_sentinel VALUES(1,'preserve-business-data')");
}
if($mode==='verify'){
    if(count($m->appliedVersions())!==count($m->catalog()) || $pdo->query('SELECT value FROM u01_sentinel WHERE id=1')->fetchColumn()!=='preserve-business-data' || file_get_contents($root.'/Data/guest-media/sentinel.txt')!=='preserve-media')exit(65);
    if((int)$pdo->query("SELECT COUNT(*) FROM schema_migration_statements WHERE state<>'applied'")->fetchColumn()!==0)exit(66);
}
echo 'U01_DATABASE_FIXTURE_OK';
