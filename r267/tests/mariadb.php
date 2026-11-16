<?php
declare(strict_types=1);
// Self-contained CI test, never point at a user's production database.
$base=__DIR__.'/fixture_public';$target=__DIR__.'/fixture_db_final';
require_once $target.'/src/Core/Database.php';
require_once $target.'/src/Core/Migrations.php';
$host=getenv('SOKNA_QA_DB_HOST')?:'127.0.0.1';$name=getenv('SOKNA_QA_DB_NAME')?:'sokna_public_qa_only';
$user=getenv('SOKNA_QA_DB_USER')?:'root';$pw=getenv('SOKNA_QA_DB_PASSWORD')?:'';
$pdo=new PDO("mysql:host=$host;port=3306;dbname=$name;charset=utf8mb4",$user,$pw,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$assert=static function(bool $yes,string $msg):void{if(!$yes)throw new RuntimeException('FAIL '.$msg);};
$installed=new \Sokna\PublicEdge\Core\Migrations($pdo,$base.'/database/migrations');
$first=$installed->migrate();
$assert(count($first)===8,'R249 baseline 8 migrations');
$pdo->exec("INSERT INTO installations(installation_id,display_name) VALUES ('qa-instance-001','SOKNA-PUBLIC-KEEP-ME')");
$targetMigrations=new \Sokna\PublicEdge\Core\Migrations($pdo,$target.'/database/migrations');
$second=$targetMigrations->migrate();
$assert(count($second)===3,'R266 new 3 migrations');
$assert($targetMigrations->pendingVersions()===[],'11 migrations all applied');
$assert((int)$pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn()===11,'ledger count 11');
$assert($pdo->query("SELECT display_name FROM installations WHERE installation_id='qa-instance-001'")->fetchColumn()==='SOKNA-PUBLIC-KEEP-ME','historical installation preserved');
$cols=$pdo->query("SELECT COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='installations' AND COLUMN_NAME='public_move_intake_paused'")->fetch(PDO::FETCH_ASSOC);
$assert(is_array($cols)&&$cols['IS_NULLABLE']==='NO'&&(string)$cols['COLUMN_DEFAULT']==='0','new intake gate defaults safe');
// Simulate a power loss between DDL commit and ledger commit on migration 0011.
$pdo->exec("DELETE FROM schema_migrations WHERE version='0011_public_move_intake_pause'");
$assert($targetMigrations->pendingVersions()===['0011_public_move_intake_pause'],'resume precondition');
$recovery=$targetMigrations->migrate();
$assert($recovery===['0011_public_move_intake_pause'],'idempotent 0011 replay');
$assert((int)$pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn()===11,'ledger restored');
// A preexisting but incompatible column must be rejected, not accepted blindly.
$pdo->exec('ALTER TABLE installations MODIFY COLUMN public_move_intake_paused VARCHAR(10) NOT NULL DEFAULT \'0\'');
$pdo->exec("DELETE FROM schema_migrations WHERE version='0011_public_move_intake_pause'");
$rejected=false;
try{$targetMigrations->migrate();}catch(Throwable $e){$rejected=str_contains($e->getMessage(),'incompatible schema');}
$assert($rejected,'incompatible column must fail closed');
$assert((int)$pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn()===10,'no false ledger after incompatible schema');
echo json_encode(['ok'=>true,'test'=>'MariaDB real 8-to-11 upgrade, retained row, crash replay, shape rejection','applied_first'=>count($first),'applied_next'=>count($second),'resume_safe'=>true,'invalid_shape_rejected'=>true],JSON_UNESCAPED_SLASHES)."\n";
