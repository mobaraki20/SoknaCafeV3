<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/apps/local-web/bootstrap.php';

use Sokna\Local\Core\Migrations;
use Sokna\Local\Domain\Recovery\RecoveryException;

function m10_fail(string $m): never { fwrite(STDERR,$m.PHP_EOL); exit(1); }
function m10_assert(bool $v,string $m): void { if(!$v)m10_fail($m); }

$host=(string)(getenv('SOKNA_TEST_DB_HOST')?:'127.0.0.1');
$port=(string)(getenv('SOKNA_TEST_DB_PORT')?:'3306');
$name=(string)(getenv('SOKNA_TEST_DB_NAME')?:'sokna_m2');
$user=(string)(getenv('SOKNA_TEST_DB_USER')?:'sokna');
$pass=(string)(getenv('SOKNA_TEST_DB_PASS')?:'sokna');
$rootUser=(string)(getenv('SOKNA_TEST_DB_ROOT_USER')?:'root');
$rootPass=(string)(getenv('SOKNA_TEST_DB_ROOT_PASS')?:'root');
$sourceData=sys_get_temp_dir().'/sokna-v3-m10-src-'.bin2hex(random_bytes(4));
$targetData=sys_get_temp_dir().'/sokna-v3-m10-dst-'.bin2hex(random_bytes(4));
$cfg=function(string $db,string $data,string $installation)use($host,$port,$user,$pass):array{return [
  'app'=>['timezone'=>'Asia/Tehran','data_dir'=>$data],
  'db'=>['host'=>$host,'port'=>$port,'name'=>$db,'charset'=>'utf8mb4','user'=>$user,'pass'=>$pass],
  'installation'=>['id'=>$installation],
];};
$core=sokna_local_bootstrap($cfg($name,$sourceData,'m10-source-installation'));
$core->migrations()->migrate();$pdo=$core->database();

$uname='m10-admin-'.bin2hex(random_bytes(3));
$pdo->prepare("INSERT INTO users(username,password_hash,display_name,role,active) VALUES(?,?,?,'admin',1)")
    ->execute([$uname,password_hash('x',PASSWORD_DEFAULT),'M10 Admin']);
$adminId=(int)$pdo->lastInsertId();$admin=['id'=>$adminId,'username'=>$uname,'display_name'=>'M10 Admin','role'=>'admin','active'=>1];
$pdo->prepare("INSERT INTO cafe_tables(name,table_number,code,access_token,active,sort_order) VALUES('M10 Table',990,'M10T',?,1,990)")
    ->execute([bin2hex(random_bytes(24))]);
$agent=$core->printing()->createAgent('M10 machine agent',$admin);
m10_assert((int)$agent['agent_id']>0,'print fixture missing');

$backup=sys_get_temp_dir().'/m10-'.bin2hex(random_bytes(5)).'.skb';$secret='M10-Strong-Recovery-Secret!';
$created=$core->businessBackup()->createEncrypted($backup,$secret,$adminId);
m10_assert(is_file($backup)&&filesize($backup)>100,'encrypted business backup missing');
m10_assert((string)file_get_contents($backup, false, null, 0, 10)==="SOKNA-SKB1\n",'secure backup magic missing');
m10_assert(strpos((string)file_get_contents($backup),$uname)===false,'plaintext business identity leaked into encrypted backup');
$manifest=$core->businessBackup()->inspectEncrypted($backup,$secret);
m10_assert(($manifest['source_installation_id']??'')==='m10-source-installation','backup installation identity drifted');
foreach(['runtime_machine_secret','print_agent_identity','tls_private_key','center_machine_signing_secret'] as $excluded)
    m10_assert(in_array($excluded,(array)($manifest['excluded_machine_identity']??[]),true),'machine identity exclusion missing: '.$excluded);
$bad=false;try{$core->businessBackup()->inspectEncrypted($backup,'definitely-wrong-secret');}catch(RecoveryException $e){$bad=$e->errorCode==='backup_auth_failed';}
m10_assert($bad,'wrong backup passphrase did not fail closed');
$tampered=$backup.'.tampered';copy($backup,$tampered);$fh=fopen($tampered,'r+b');fseek($fh,-8,SEEK_END);$b=fread($fh,1);fseek($fh,-1,SEEK_CUR);fwrite($fh,chr(ord($b)^0x01));fclose($fh);
$tamperBlocked=false;try{$core->businessBackup()->inspectEncrypted($tampered,$secret);}catch(RecoveryException $e){$tamperBlocked=in_array($e->errorCode,['backup_auth_failed','backup_truncated','backup_trailing_data'],true);}
m10_assert($tamperBlocked,'tampered backup authenticated');

$target='sokna_m10_restore_'.bin2hex(random_bytes(4));
$rootDsn="mysql:host={$host};port={$port};charset=utf8mb4";$root=new PDO($rootDsn,$rootUser,$rootPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$root->exec('CREATE DATABASE `'.$target.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
try{
  $root->exec("GRANT ALL PRIVILEGES ON `{$target}`.* TO '".str_replace("'","''",$user)."'@'%'");
  $dst=sokna_local_bootstrap($cfg($target,$targetData,'m10-new-machine'));
  $dst->migrations()->migrate();$restored=$dst->businessBackup()->restoreEncryptedToEmptyTarget($backup,$secret,0);
  m10_assert(!empty($restored['restored'])&&!empty($restored['machine_identity_reprovision_required']),'machine takeover restore did not require fresh identity');
  $dp=$dst->database();
  $q=$dp->prepare('SELECT COUNT(*) FROM users WHERE username=?');$q->execute([$uname]);m10_assert((int)$q->fetchColumn()===1,'business user not restored');
  m10_assert((int)$dp->query('SELECT COUNT(*) FROM print_agents')->fetchColumn()===0,'machine-bound Print Agent identity was restored');
  $notEmpty=false;try{$dst->businessBackup()->restoreEncryptedToEmptyTarget($backup,$secret,0);}catch(RecoveryException $e){$notEmpty=$e->errorCode==='target_not_empty';}
  m10_assert($notEmpty,'restore did not reject non-empty target');
}finally{$root->exec('DROP DATABASE IF EXISTS `'.$target.'`');}

// Simulate: DDL committed, process died before statement journal became applied.
$tmp=sys_get_temp_dir().'/m10-migrations-'.bin2hex(random_bytes(4));mkdir($tmp,0700,true);
$statement='CREATE TABLE m10_crash_probe (id INT PRIMARY KEY) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
file_put_contents($tmp.'/9998_m10_crash.sql',$statement.';');
$pdo->exec('DROP TABLE IF EXISTS m10_crash_probe');$pdo->exec($statement);
$hash=hash('sha256',$statement);
$pdo->prepare("INSERT INTO schema_migration_statements(version,ordinal_no,statement_sha256,state) VALUES('9998_m10_crash',1,?,'running')")
    ->execute([$hash]);
$m=new Migrations($pdo,$tmp);$applied=$m->migrate();
m10_assert(in_array('9998_m10_crash',$applied,true),'crash-replay migration was not reconciled');
$state=(string)$pdo->query("SELECT state FROM schema_migration_statements WHERE version='9998_m10_crash' AND ordinal_no=1")->fetchColumn();
m10_assert($state==='applied','crash-replay statement journal not finalized');

// Once execution began, changing the migration bytes must be rejected.
file_put_contents($tmp.'/9999_m10_hash.sql','CREATE TABLE m10_hash_probe (id INT PRIMARY KEY);');
$hashStmt='CREATE TABLE m10_hash_probe (id INT PRIMARY KEY)';
$pdo->prepare("INSERT INTO schema_migration_statements(version,ordinal_no,statement_sha256,state) VALUES('9999_m10_hash',1,?,'running')")
    ->execute([hash('sha256',$hashStmt.' changed')]);
$changed=false;try{(new Migrations($pdo,$tmp))->migrate();}catch(RuntimeException $e){$changed=str_contains($e->getMessage(),'changed after execution began');}
m10_assert($changed,'migration hash drift after execution began was not blocked');

@unlink($backup);@unlink($tampered);@unlink($tmp.'/9998_m10_crash.sql');@unlink($tmp.'/9999_m10_hash.sql');@rmdir($tmp);
fwrite(STDOUT,"Local M10 recovery/migration qualification self-test: OK\n");
