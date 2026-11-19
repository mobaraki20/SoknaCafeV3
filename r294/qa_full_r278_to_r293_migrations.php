<?php
declare(strict_types=1);
// QA-only. Explicit runtime guard to avoid use against cafe systems.
if (getenv('GITHUB_ACTIONS') !== 'true' || getenv('SOKNA_R294_DB_QA') !== 'disposable_only') {
    throw new RuntimeException('QA_ONLY_DISPOSABLE_GITHUB_RUNTIME');
}
$root = rtrim((string)getenv('R294_SOURCE_ROOT'), '/');
if ($root === '' || !is_file($root.'/database/migrations/0083_remaining_account_discount.sql')) {
    throw new RuntimeException('EXACT_R293_SOURCE_REQUIRED');
}
require_once $root.'/src/Core/MigrationExecutionException.php';
require_once $root.'/src/Core/Migrations.php';
use Sokna\Local\Core\Migrations;
$pw=(string)getenv('R294_DB_PASSWORD');
$p=new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4','root',$pw,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$p->exec('CREATE DATABASE sokna_r294_full CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$p->exec('USE sokna_r294_full');
$oldDir=sys_get_temp_dir().'/sokna-r294/old/database/migrations';
@mkdir($oldDir,0777,true);
foreach(glob($root.'/database/migrations/*.sql') as $src) {
    $name=basename($src);
    if(str_starts_with($name,'0083_'))continue;
    if(!copy($src,$oldDir.'/'.$name))throw new RuntimeException('COPY_82_FAILED');
}
$old = new Migrations($p,$oldDir);
$catalog=$old->catalog();
 $catalogKeys=array_keys($catalog);
if(count($catalog)!==82 || end($catalogKeys)!=='0082_public_move_owner_approval') throw new RuntimeException('OLD_CATALOG_WRONG '.count($catalog));
// Actual BrowserSetup does not run the 82 migrations in a single pass:
// it must seed canonical products BEFORE 0059 (which hard-guards item codes).
$beforeSeed=$old->migrate('0059_menu_business_reconciliation_v4');
if(count($beforeSeed)<1 || count($beforeSeed)>=82)throw new RuntimeException('PRE_SEED_BOUNDARY_FAILED');
$p->prepare("INSERT INTO users(username,password_hash,display_name,role,active) VALUES(?,?,?,'admin',1)")
  ->execute(['r294-qa-admin',password_hash('disposable-r294-only',PASSWORD_DEFAULT),'R294 QA']);
$admin=(int)$p->lastInsertId();
$p->prepare("INSERT INTO remote_user_credentials(user_id,password_hash,credential_version,source_mode) VALUES(?,?,1,'local_password')")
  ->execute([$admin,password_hash('disposable-r294-only',PASSWORD_DEFAULT)]);
$p->prepare("INSERT INTO settings(setting_key,setting_value) VALUES(?,?)")->execute(['installation.id','local-disposable-r294-qa']);
$p->prepare("INSERT INTO cafe_tables(name,table_number,code,access_token,active,sort_order) VALUES(?,?,?,?,1,1)")->execute(['میز ۱',1,'T1',bin2hex(random_bytes(24))]);
require_once $root.'/src/Domain/Sellables/CategoryIconLibrary.php';
require_once $root.'/src/Domain/Sellables/DefaultContentSeeder.php';
$icons=new Sokna\Local\Domain\Sellables\CategoryIconLibrary($root.'/resources/default-content/v1/category-icons.json');
$seeder=new Sokna\Local\Domain\Sellables\DefaultContentSeeder($p,$icons,$root.'/resources/default-content/v1',sys_get_temp_dir().'/sokna-r294/guest-media');
$seeder->seed($admin);
$afterSeed=$old->migrate();
$initial=array_merge($beforeSeed,$afterSeed);
$oldDone=$old->appliedVersions();
if(count($initial)!==82 || count($oldDone)!==82)throw new RuntimeException('R278_MIGRATIONS_NOT_82 '.json_encode(['new'=>count($initial),'all'=>count($oldDone)]));
$before=[];
foreach(['users','order_items','orders','settlement_records','audit_log'] as $t){
 $before[$t]=(int)$p->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
}
$oldHistory=$p->query('SELECT version FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_COLUMN);
$oldStatementCount=(int)$p->query('SELECT COUNT(*) FROM schema_migration_statements')->fetchColumn();
if((int)$p->query("SELECT COUNT(*) FROM information_schema.tables WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE 'remaining_account_discount_%'")->fetchColumn()!==0) throw new RuntimeException('TARGET_TABLE_ALREADY_EXISTS');
// The 83rd forward DDL must succeed with the actual Migrations runner,
// retaining unchanged 82-step history and previous business records.
$targetDir=sys_get_temp_dir().'/sokna-r294/new/database/migrations';
@mkdir($targetDir,0777,true);
foreach(glob($root.'/database/migrations/*.sql') as $src)if(!copy($src,$targetDir.'/'.basename($src)))throw new RuntimeException('COPY_83_FAILED');
$new=new Migrations($p,$targetDir);
$changes=$new->migrate();
if($changes!==['0083_remaining_account_discount'])throw new RuntimeException('EXPECTED_ONLY_0083 '.json_encode($changes));
if(count($new->appliedVersions())!==83)throw new RuntimeException('NEW_COUNT_NOT_83');
if($new->migrate()!==[])throw new RuntimeException('SECOND_MIGRATE_NOT_IDEMPOTENT');
$afterHistory=$p->query('SELECT version FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_COLUMN);
if(array_slice($afterHistory,0,82)!==$oldHistory)throw new RuntimeException('OLD_82_LEDGER_CHANGED');
foreach($before as $t=>$count)if((int)$p->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn()!==$count)throw new RuntimeException('BUSINESS_DATA_CHANGED '.$t);
$tables=$p->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE 'remaining_account_discount_%' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
if($tables!==['remaining_account_discount_grants','remaining_account_discount_lines'])throw new RuntimeException('NEW_TABLES_MISMATCH '.json_encode($tables));
$constraints=$p->query("SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('remaining_account_discount_grants','remaining_account_discount_lines') AND CONSTRAINT_TYPE='FOREIGN KEY'")->fetchAll(PDO::FETCH_COLUMN);
foreach(['fk_remaining_grant_session','fk_remaining_grant_actor','fk_remaining_line_grant','fk_remaining_line_item'] as $fk)if(!in_array($fk,$constraints,true))throw new RuntimeException('MISSING_FOREIGN_KEY '.$fk);
$statementCount=(int)$p->query('SELECT COUNT(*) FROM schema_migration_statements')->fetchColumn();
if($statementCount!==$oldStatementCount+2)throw new RuntimeException('MIGRATION_STMT_JOURNAL_NOT_2');
// This was an actual R293 regression: the go-live cleanup policy used to require
// precisely 111 tables and would reject the two new financial tables.
require_once $root.'/src/Domain/System/GoLiveResetEngine.php';
$goLive=new Sokna\Local\Domain\System\GoLiveResetEngine($p,$root.'/resources/golive-clearance-policy.json');
$inspection=$goLive->inspect();
if(($inspection['safe_schema']??false)!==true||(int)$inspection['table_count']!==113) throw new RuntimeException('GOLIVE_SCHEMA_POLICY_NOT_113 '.json_encode($inspection));
foreach(['remaining_account_discount_grants','remaining_account_discount_lines'] as $newTable){
 if(($inspection['counts'][$newTable]['action']??null)!=='CLEAR') throw new RuntimeException('GOLIVE_DISCOUNT_CLEARANCE_MISSING '.$newTable);
}
$report=['result'=>'PASS','origin_catalog'=>82,'origin_applied'=>count($initial),'target_catalog'=>83,'newly_applied'=>['0083_remaining_account_discount'],'idempotent_reapply'=>true,'unchanged_old_ledger'=>true,'unchanged_business_data'=>true,'new_tables'=>$tables,'foreign_keys_verified'=>4,'go_live_policy_includes_new_tables'=>true,'go_live_table_count'=>113,'old_migration_statements'=>$oldStatementCount,'new_migration_statements'=>$statementCount,'environment'=>'real MariaDB 11.4, exact R293 source, actual Sokna Migrations class, empty disposable install'];
echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),"\n";
