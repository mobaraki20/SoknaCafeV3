<?php
declare(strict_types=1);
// Never execute this CI fixture against a real host, database, or account.
if(PHP_SAPI!=='cli'||getenv('GITHUB_ACTIONS')!=='true'||getenv('SOKNA_R162_DISPOSABLE')!=='YES')exit(87);
[$script,$localRoot,$publicRoot]=$argv;
$dsn='mysql:host=127.0.0.1;port=3306;dbname=sokna_r140_exact;charset=utf8mb4';
$pdo=new PDO($dsn,'root','ci-root-only',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
require_once $localRoot.'/src/Domain/System/GoLiveResetEngine.php';
function check($v,$label){if(!$v)throw new RuntimeException('R162_CI_FAILED_'.$label);echo "PASS $label\n";}
$local=new Sokna\Local\Domain\System\GoLiveResetEngine($pdo,$localRoot.'/resources/golive-clearance-policy.json');
$inspect=$local->inspect();check($inspect['safe_schema']&&$inspect['table_count']===111,'LOCAL_EXACT_111_TABLE_SCHEMA_AND_FOREIGN_KEY_GATE');
$userBefore=(int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$itemBefore=(int)$pdo->query('SELECT COUNT(*) FROM items')->fetchColumn();
$menuBefore=(int)$pdo->query('SELECT COUNT(*) FROM menu_items')->fetchColumn();
$recipeBefore=(int)$pdo->query('SELECT COUNT(*) FROM inventory_recipe_components')->fetchColumn();
check($itemBefore>100&&$menuBefore>100,'LOCAL_REAL_SEEDED_MENU_ITEMS_AVAILABLE');
$pdo->exec("INSERT INTO audit_log(action,entity_type,entity_id) VALUES('qa-test','test','R162')");
$pdo->exec("INSERT INTO public_sync_state(channel,source_version,status) VALUES('qa-r162','test','ok')");
$pdo->exec("INSERT INTO public_authority_state(installation_id,authority_epoch,phase) VALUES('ci-golive',1,'active') ON DUPLICATE KEY UPDATE phase='active',authority_epoch=1");
$deleted=$local->execute('ci-golive',2);
check(($deleted['audit_log']??0)>=1&&($deleted['public_sync_state']??0)>=1,'LOCAL_TEST_AUDIT_AND_SYNC_ROWS_CLEARED');
check((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn()===$userBefore &&(int)$pdo->query('SELECT COUNT(*) FROM items')->fetchColumn()===$itemBefore && (int)$pdo->query('SELECT COUNT(*) FROM menu_items')->fetchColumn()===$menuBefore &&(int)$pdo->query('SELECT COUNT(*) FROM inventory_recipe_components')->fetchColumn()===$recipeBefore,'LOCAL_USERS_MENU_RECIPE_PRESERVED');
check((int)$pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn()===0,'LOCAL_TEST_HISTORY_EMPTY');
$s=$pdo->query("SELECT authority_epoch,phase FROM public_authority_state WHERE installation_id='ci-golive'")->fetch(PDO::FETCH_ASSOC);
check((int)$s['authority_epoch']===2&&$s['phase']==='resync_required','LOCAL_AUTHORITY_EPOCH_ADVANCED');
try{$local->execute('ci-golive',2);throw new RuntimeException('REPLAY_ALLOWED');}catch(RuntimeException $e){check($e->getMessage()==='GOLIVE_LOCAL_AUTHORITY_EPOCH_MISMATCH','LOCAL_REPLAY_FAIL_CLOSED');}
$pdo->exec('CREATE DATABASE IF NOT EXISTS sokna_r162_public CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$publicPdo=new PDO('mysql:host=127.0.0.1;port=3306;dbname=sokna_r162_public;charset=utf8mb4','root','ci-root-only',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
require_once $publicRoot.'/src/Core/Migrations.php';
$m=new Sokna\PublicEdge\Core\Migrations($publicPdo,$publicRoot.'/database/migrations');$applied=$m->migrate();
check(count($applied)===8,'PUBLIC_EIGHT_EXACT_MIGRATIONS');
require_once $publicRoot.'/src/Maintenance/GoLiveResetEngine.php';
$public=new Sokna\PublicEdge\Maintenance\GoLiveResetEngine($publicPdo,$publicRoot.'/resources/golive-clearance-policy.json');
$publicPdo->exec("INSERT INTO installations(installation_id,display_name,active,remote_enabled,order_intake_enabled) VALUES('qa-purge-test','Test instance',1,1,1),('qa-untouched','Neighbor',1,1,1)");
$publicPdo->exec("INSERT INTO auth_projections(installation_id,projection_id,username,password_hash,capabilities_json) VALUES('qa-purge-test','admin-1','admin', 'hash-only', '[]')");
$publicPdo->exec("INSERT INTO public_sessions(installation_id,projection_id,token_hash,expires_at) VALUES('qa-purge-test','admin-1',REPEAT('f',64),UTC_TIMESTAMP()+INTERVAL 1 DAY)");
$publicPdo->exec("INSERT INTO installation_heartbeats(installation_id,last_seen_at) VALUES('qa-purge-test',UTC_TIMESTAMP()),('qa-untouched',UTC_TIMESTAMP())");
$publicPdo->exec("INSERT INTO request_nonces(installation_id,nonce,expires_at) VALUES('qa-purge-test','seed-qa-nonce',UTC_TIMESTAMP()+INTERVAL 1 DAY),('qa-untouched','other-qa-nonce',UTC_TIMESTAMP()+INTERVAL 1 DAY)");
$ins=$public->inspect('qa-purge-test');check($ins['safe_schema']&&$ins['table_count']===22,'PUBLIC_EXACT_22_TABLE_SCHEMA_GATE');
$reset=$public->execute('qa-purge-test');check($reset['new_epoch']===2&&$reset['remote_paused']===true,'PUBLIC_RESET_ADVANCES_EPOCH_AND_FENCES_INTAKE');
check((int)$publicPdo->query("SELECT COUNT(*) FROM auth_projections WHERE installation_id='qa-purge-test'")->fetchColumn()===1,'PUBLIC_AUTH_CREDENTIALS_PRESERVED');
check((int)$publicPdo->query("SELECT COUNT(*) FROM public_sessions WHERE installation_id='qa-purge-test'")->fetchColumn()===0,'PUBLIC_TEST_SESSIONS_CLEARED');
check((int)$publicPdo->query("SELECT COUNT(*) FROM request_nonces WHERE installation_id='qa-untouched'")->fetchColumn()===1,'PUBLIC_OTHER_INSTALLATION_UNTOUCHED');
check((int)$publicPdo->query("SELECT COUNT(*) FROM installation_heartbeats WHERE installation_id='qa-untouched'")->fetchColumn()===1,'PUBLIC_OTHER_HEARTBEAT_UNTOUCHED');
check((int)$publicPdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn()===8,'PUBLIC_GLOBAL_MIGRATION_LEDGER_PRESERVED');
try{$public->execute('qa-purge-test');throw new RuntimeException('REPLAY_ALLOWED');}catch(RuntimeException $e){check($e->getMessage()==='GOLIVE_PUBLIC_NOT_ACTIVE','PUBLIC_REPLAY_FAIL_CLOSED');}
echo "PASS R162_LOCAL_AND_PUBLIC_DISPOSABLE_MARIADB_REAL_SCHEMA_RESET\n";