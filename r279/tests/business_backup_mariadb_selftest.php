<?php
declare(strict_types=1);
/** Native MariaDB 11.4 regression: encrypted actual backup, restore, and safety gates. */
require_once __DIR__.'/../../apps/local-web/src/Core/Observability.php';
require_once __DIR__.'/../../apps/local-web/src/Domain/Recovery/RecoveryException.php';
require_once __DIR__.'/../../apps/local-web/src/Domain/Recovery/BusinessBackupService.php';

use Sokna\Local\Core\Observability;
use Sokna\Local\Domain\Recovery\BusinessBackupService;
use Sokna\Local\Domain\Recovery\RecoveryException;

$pass=(string)(getenv('R279_DB_PASSWORD')?:'qa-only');
$admin=new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4','root',$pass,
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$prefix='sokna_r279_';
$sourceDb=$prefix.'source';$targetDb=$prefix.'target';
foreach([$sourceDb,$targetDb] as $database){
    $admin->exec('DROP DATABASE IF EXISTS `'.$database.'`');
    $admin->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
}
$connect=static fn(string $name):PDO=>new PDO(
    'mysql:host=127.0.0.1;port=3306;dbname='.$name.';charset=utf8mb4','root',$pass,
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$source=$connect($sourceDb);$target=$connect($targetDb);
$dir=sys_get_temp_dir().'/sokna-r279-recovery-'.bin2hex(random_bytes(5));
if(!mkdir($dir,0700,true))throw new RuntimeException('Cannot initialize temp directory');
$count=0;
$assert=static function(bool $ok,string $name)use(&$count):void{
    if(!$ok)throw new RuntimeException('R279_BACKUP_ASSERT_FAILED: '.$name);
    $count++;echo 'PASS '.$name.PHP_EOL;
};
try{
    foreach([$source,$target] as $pdo){
        $pdo->exec('CREATE TABLE schema_migrations(version VARCHAR(100) NOT NULL PRIMARY KEY) ENGINE=InnoDB');
        $pdo->exec('CREATE TABLE users(id INT PRIMARY KEY,display_name VARCHAR(90) NOT NULL) ENGINE=InnoDB');
        $pdo->exec('CREATE TABLE orders(id INT PRIMARY KEY,user_id INT NOT NULL,total_amount INT NOT NULL,FOREIGN KEY(user_id) REFERENCES users(id)) ENGINE=InnoDB');
        $pdo->exec('CREATE TABLE inventory_items(id INT PRIMARY KEY,on_hand INT NOT NULL) ENGINE=InnoDB');
        $pdo->exec('CREATE TABLE print_jobs(id INT PRIMARY KEY,queue_state VARCHAR(30)) ENGINE=InnoDB');
        $pdo->exec("INSERT INTO schema_migrations(version) VALUES('0001_r279')");
    }
    $source->exec("INSERT INTO users VALUES(11,'R279 Cafe Admin')");
    $source->exec("INSERT INTO orders VALUES(2,11,150000),(3,11,230000)");
    $source->exec("INSERT INTO inventory_items VALUES(1,30),(2,16)");
    $source->exec("INSERT INTO print_jobs VALUES(1,'queued')");

    $obs=new Observability($dir);
    $backup=new BusinessBackupService($source,$obs,'qa-local-source');
    $archive=$dir.'/source.skbf';$pw='qa-only-backup-passphrase-123';
    $result=$backup->createEncrypted($archive,$pw);
    $assert(is_file($archive) && ($result['sha256']??'')===hash_file('sha256',$archive),
        'encrypted archive sha and disk bytes');
    $inspected=$backup->inspectEncrypted($archive,$pw);
    $assert(($inspected['snapshot_consistency']??'')==='innodb_repeatable_read_v1',
        'snapshot manifest declares repeatable-read InnoDB');
    $assert(!in_array('print_jobs',(array)$inspected['tables'],true) &&
        in_array('orders',(array)$inspected['tables'],true),
        'business rows included, device-specific print jobs excluded');
    $restorer=new BusinessBackupService($target,$obs,'qa-local-destination');
    $restored=$restorer->restoreEncryptedToEmptyTarget($archive,$pw);
    $assert(($restored['restored']??false)===true,
        'encrypted archive decrypts and restores to empty target');
    $assert((int)$target->query('SELECT COUNT(*) FROM orders')->fetchColumn()===2 &&
        (int)$target->query('SELECT SUM(total_amount) FROM orders')->fetchColumn()===380000,
        'financial orders restored without loss');
    $assert((int)$target->query('SELECT SUM(on_hand) FROM inventory_items')->fetchColumn()===46,
        'inventory rows restored unchanged');
    $assert((int)$target->query('SELECT COUNT(*) FROM print_jobs')->fetchColumn()===0,
        'device-specific print queue not migrated');
    $assert(($restored['machine_identity_reprovision_required']??null)===true &&
        ($restored['public_authority_fence_confirmed']??null)===false,
        'machine identity re-provision and Public fence uncertainty are explicit');
    try{$restorer->restoreEncryptedToEmptyTarget($archive,$pw);$assert(false,'nonempty target refused');}
    catch(RecoveryException $e){
        $assert($e->errorCode==='target_not_empty','nonempty target refused');
    }
    $source->beginTransaction();
    try{$backup->createEncrypted($dir.'/bad-transaction.skbf',$pw);$assert(false,'active txn refused');}
    catch(RecoveryException $e){$assert($e->errorCode==='backup_transaction_active','active txn refused');}
    finally{$source->rollBack();}
    $source->exec('CREATE TABLE legacy_nontransactional(id INT PRIMARY KEY) ENGINE=MyISAM');
    try{$backup->createEncrypted($dir.'/nontransactional.skbf',$pw);$assert(false,'MyISAM refused');}
    catch(RecoveryException $e){
        $assert($e->errorCode==='backup_nontransactional_table','non-transactional table refused');
    }
    $source->exec('DROP TABLE legacy_nontransactional');
    $assert((int)$source->query('SELECT COUNT(*) FROM orders')->fetchColumn()===2,
        'backup did not alter source orders');
    echo 'R279_REAL_MARIADB_RECOVERY_'.(string)$count.'_CHECKS_PASS'.PHP_EOL;
}finally{
    foreach([$sourceDb,$targetDb] as $db)$admin->exec('DROP DATABASE IF EXISTS `'.$db.'`');
}
