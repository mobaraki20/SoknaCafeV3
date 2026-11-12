<?php
declare(strict_types=1);
// GH Actions disposable MariaDB ONLY. Never point this at Home or Cafe DB.
if(PHP_SAPI!=='cli'||getenv('GITHUB_ACTIONS')!=='true'||getenv('SOKNA_R177_DISPOSABLE')!=='YES'||count($argv)!==2)exit(87);
$root=rtrim($argv[1],'/');
foreach([
 '/src/Domain/PublicEdge/PublicEdgePublisherService.php',
 '/src/Domain/PublicEdge/PublicProjectionBuilder.php',
 '/src/Domain/PublicEdge/PublicAuthorityStateService.php',
 '/database/migrations/0062_projection_model_target_watermarks.sql',
] as $file)if(!is_file($root.$file))exit(88);
require_once $root.'/src/Domain/PublicEdge/PublicAuthorityStateService.php';
require_once $root.'/src/Domain/PublicEdge/PublicProjectionBuilder.php';
require_once $root.'/src/Domain/PublicEdge/PublicEdgePublisherService.php';
function check(bool $ok,string $name):void{if(!$ok)throw new RuntimeException('R177_FAIL_'.$name);echo 'PASS '.$name."\n";}
$db='sokna_r177_disposable';
$pdo=new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4','root','ci-root-only',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$pdo->exec('DROP DATABASE IF EXISTS `'.$db.'`');
$pdo->exec('CREATE DATABASE `'.$db.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdo->exec('USE `'.$db.'`');
try {
$pdo->exec("CREATE TABLE public_authority_state(installation_id VARCHAR(96) PRIMARY KEY, authority_epoch INT NOT NULL DEFAULT 1, phase VARCHAR(40) NOT NULL DEFAULT 'active') ENGINE=InnoDB");
$pdo->exec("INSERT INTO public_authority_state(installation_id,authority_epoch,phase) VALUES('ci-installation',2,'active')");
$pdo->exec("CREATE TABLE public_projection_outbox(sequence_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, event_type VARCHAR(96) NOT NULL,aggregate_type VARCHAR(96) NOT NULL, aggregate_id VARCHAR(96) NOT NULL,model_keys VARCHAR(255) NOT NULL,delivered_at DATETIME(6) DEFAULT NULL) ENGINE=InnoDB");
$sql=(string)file_get_contents($root.'/database/migrations/0062_projection_model_target_watermarks.sql');
check(str_contains($sql,'CREATE TRIGGER tr_h322_projection_outbox_model_targets_ai'),'ACTUAL_MIGRATION_HAS_TRIGGER');
foreach(preg_split('/;\s*(?:\r?\n|$)/',$sql)?:[] as $statement){$statement=trim($statement);if($statement!=='')$pdo->exec($statement);}
check((int)$pdo->query('SELECT COUNT(*) FROM public_projection_model_catalog')->fetchColumn()===11,'PRE_RESET_MIGRATION_HAS_11_MODELS');
// Mimic policy CLEAR and the actual 8 post-reset/epoch outbox rows. The
// unchanged H322 trigger silently sees zero keys while catalog is empty.
$pdo->exec('DELETE FROM public_projection_model_targets');
$pdo->exec('DELETE FROM public_projection_model_catalog');
for($i=0;$i<8;$i++)$pdo->exec("INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('reset.tail','table','*','operations,reports')");
check((int)$pdo->query('SELECT COUNT(*) FROM public_projection_model_targets')->fetchColumn()===0,'EXACT_OLD_BUG_CATALOG_EMPTY_TRIGGER_NO_TARGETS');
$publisherClass=new ReflectionClass(\Sokna\Local\Domain\PublicEdge\PublicEdgePublisherService::class);
$publisher=$publisherClass->newInstanceWithoutConstructor();
foreach(['pdo'=>$pdo,'builder'=>new \Sokna\Local\Domain\PublicEdge\PublicProjectionBuilder($pdo),'authorityState'=>new \Sokna\Local\Domain\PublicEdge\PublicAuthorityStateService($pdo,'ci-installation')] as $key=>$val){$prop=$publisherClass->getProperty($key);$prop->setValue($publisher,$val);}
$restore=$publisherClass->getMethod('restoreProjectionCatalogAfterGoLive');
$target=$publisherClass->getMethod('pendingProjectionTargets');
// Normal active mode must NEVER repair empty catalog without a go-live fence.
$restore->invoke($publisher);
check((int)$pdo->query('SELECT COUNT(*) FROM public_projection_model_catalog')->fetchColumn()===0,'ACTIVE_MODE_NO_CATALOG_MUTATION');
$pdo->exec("UPDATE public_authority_state SET phase='resync_required' WHERE installation_id='ci-installation'");
$restore->invoke($publisher);
check((int)$pdo->query('SELECT COUNT(*) FROM public_projection_model_catalog')->fetchColumn()===11,'RESYNC_RESTORES_EXACT_11_CATALOG_KEYS');
$keys=(new \Sokna\Local\Domain\PublicEdge\PublicProjectionBuilder($pdo))->remoteModelKeys();
check(count($keys)===11,'SOURCE_KEY_COUNT_11');
check((int)$pdo->query('SELECT COUNT(*) FROM public_projection_model_targets')->fetchColumn()===0,'RESTORE_DOES_NOT_FABRICATE_ACKS');
$n=$publisher->seedProjectionInvalidations($keys,'manual.full_sync');
check($n>0,'FULL_SYNC_SEEDS_NEW_INVALIDATIONS');
$targets=$target->invoke($publisher,$keys);
check(count($targets)===11&&count(array_filter($targets,fn($x)=>$x>0))===11,'ACTUAL_TRIGGER_RESTORES_11_TARGET_WATERMARKS');
$restore->invoke($publisher);
check((int)$pdo->query('SELECT COUNT(*) FROM public_projection_model_catalog')->fetchColumn()===11,'REPEATED_RESYNC_REPAIR_IDEMPOTENT');
$pdo->exec("INSERT INTO public_projection_model_catalog(model_key) VALUES('unexpected_extra_model')");
try{$restore->invoke($publisher);throw new RuntimeException('FAIL_OPEN_UNKNOWN_CATALOG_KEY');}
catch(ReflectionException $e){throw $e;}
catch(\RuntimeException $e){check($e->getMessage()==='GOLIVE_PROJECTION_MODEL_CATALOG_UNEXPECTED','UNKNOWN_CATALOG_KEY_FAILS_CLOSED');}
check((int)$pdo->query('SELECT COUNT(*) FROM public_projection_model_catalog')->fetchColumn()===12,'UNEXPECTED_KEY_NOT_DELETED');
// Business-domain tables were not even part of the source-validated repair.
check(str_contains((string)file_get_contents($root.'/src/Domain/PublicEdge/PublicEdgePublisherService.php'),'$this->restoreProjectionCatalogAfterGoLive()'),'PRODUCTION_SYNC_ALL_CALLS_REPAIR_BEFORE_SEED');
echo "PASS R177_POST_GOLIVE_REAL_MARIADB_CATALOG_AND_TRIGGER_RECOVERY\n";
}finally{$pdo->exec('DROP DATABASE IF EXISTS `'.$db.'`');}
