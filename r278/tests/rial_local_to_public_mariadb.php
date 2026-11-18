<?php
declare(strict_types=1);
// Two isolated schemas. No real cafe host or live DB data.
if(getenv('GITHUB_ACTIONS')!=='true')throw new RuntimeException('CI_ONLY');
$pass=getenv('SOKNA_QA_DB_PASSWORD')?:'';
$host=getenv('SOKNA_QA_DB_HOST')?:'127.0.0.1';
if($host!=='127.0.0.1')throw new RuntimeException('HOST_NOT_LOCAL');
$server=new PDO("mysql:host=127.0.0.1;port=3306;charset=utf8mb4",'root',$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
foreach(['sokna_r278_local','sokna_r278_public'] as $name){$server->exec("DROP DATABASE IF EXISTS `$name`");$server->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");}
$db=static fn(string $name)=>new PDO("mysql:host=127.0.0.1;port=3306;dbname=$name;charset=utf8mb4",'root',$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$local=$db('sokna_r278_local');$public=$db('sokna_r278_public');
// Required Local financial control schema and durable outbox.
$local->exec('CREATE TABLE settings(setting_key VARCHAR(150) PRIMARY KEY, setting_value VARCHAR(255) NOT NULL) ENGINE=InnoDB');
$local->exec('CREATE TABLE audit_log(id BIGINT AUTO_INCREMENT PRIMARY KEY,actor_user_id BIGINT,action VARCHAR(80),entity_type VARCHAR(60),entity_id VARCHAR(120),details_json TEXT) ENGINE=InnoDB');
$local->exec('CREATE TABLE public_projection_outbox(sequence_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,event_type VARCHAR(96),aggregate_type VARCHAR(48),aggregate_id VARCHAR(96),model_keys VARCHAR(255),created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),attempt_count INT UNSIGNED DEFAULT 0,last_attempt_at DATETIME(6) NULL,delivered_at DATETIME(6) NULL) ENGINE=InnoDB');
$local->exec("INSERT INTO settings VALUES('payment.final_amount_rial','0')");
// Exact H289 settings trigger contract from existing R277, which omits 'operations'.
$local->exec("CREATE TRIGGER tr_h289_settings_au AFTER UPDATE ON settings FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('settings.update','settings','*','operations_workspace,notifications,deferred_context,reports,guest_operational')");
$local->exec("CREATE TRIGGER tr_h289_settings_ai AFTER INSERT ON settings FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('settings.insert','settings','*','operations_workspace,notifications,deferred_context,reports,guest_operational')");
$local->exec('CREATE TABLE orders(id BIGINT PRIMARY KEY,public_code VARCHAR(50),status VARCHAR(32),total_amount BIGINT,order_source VARCHAR(32),created_at DATETIME,customer_note TEXT,table_id BIGINT) ENGINE=InnoDB');
$local->exec('CREATE TABLE cafe_tables(id BIGINT PRIMARY KEY,name VARCHAR(80)) ENGINE=InnoDB');
$local->exec('CREATE TABLE waiter_calls(id BIGINT PRIMARY KEY,public_code VARCHAR(50),status VARCHAR(24),created_at DATETIME,table_id BIGINT) ENGINE=InnoDB');
$local->exec("INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('unrelated.pending','order','pending-check','operations')");
// Exact Public read model v2 schema, separate from Local. The signed HTTP wrapper
// calls syncModelV2 AFTER verifying HMAC; this test isolates the domain projection.
$public->exec('CREATE TABLE installations(installation_id VARCHAR(160) PRIMARY KEY,active TINYINT NOT NULL,remote_enabled TINYINT NOT NULL,authority_epoch BIGINT NOT NULL) ENGINE=InnoDB');
$public->exec('CREATE TABLE installation_heartbeats(installation_id VARCHAR(160) PRIMARY KEY,local_version VARCHAR(180),runtime_status VARCHAR(40),telemetry_json TEXT,last_seen_at DATETIME) ENGINE=InnoDB');
$public->exec('CREATE TABLE remote_read_models(installation_id VARCHAR(160),model_key VARCHAR(65),source_version VARCHAR(64),payload_json LONGTEXT,generated_at DATETIME,last_sync_at DATETIME,PRIMARY KEY(installation_id,model_key)) ENGINE=InnoDB');
$public->exec('CREATE TABLE remote_projection_model_cursors(installation_id VARCHAR(160),authority_epoch BIGINT,model_key VARCHAR(65),last_sequence BIGINT,source_version VARCHAR(64) NULL,PRIMARY KEY(installation_id,model_key)) ENGINE=InnoDB');
$id='qa-only-r278-installation';
$public->prepare('INSERT INTO installations VALUES(?,1,1,2)')->execute([$id]);
$public->prepare("INSERT INTO installation_heartbeats VALUES(?,'R278','ready','{}',UTC_TIMESTAMP())")->execute([$id]);
require_once __DIR__.'/../source/src/Domain/Admin/AdminControlException.php';
require_once __DIR__.'/../source/src/Core/IdentityRepository.php';
require_once __DIR__.'/../source/src/Domain/Admin/AdminControlService.php';
require_once __DIR__.'/../source/src/Shared/IranTime.php';
require_once __DIR__.'/../source/src/Domain/PublicEdge/PublicProjectionBuilder.php';
require_once __DIR__.'/../public/src/Core/CanonicalJson.php';
require_once __DIR__.'/../public/src/Connectivity/ConnectivityService.php';
require_once __DIR__.'/../public/src/Remote/RemoteReadModelService.php';
$ident=new class implements \Sokna\Local\Core\IdentityRepository{
 public function findActiveById(int $id):?array{return $id===1?['id'=>1,'role'=>'admin']:null;}
 public function findActiveByUsername(string $username):?array{return null;}
 public function capabilitiesForUser(int $id):array{return [];}
 public function preparationAreasForUser(int $id):array{return [];}
};
$ref=new ReflectionClass(\Sokna\Local\Domain\Admin\AdminControlService::class);
$control=$ref->newInstanceWithoutConstructor();
(new ReflectionProperty($ref->getName(),'pdo'))->setValue($control,$local);
(new ReflectionProperty($ref->getName(),'identity'))->setValue($control,$ident);
$builder=new \Sokna\Local\Domain\PublicEdge\PublicProjectionBuilder($local);
$remote=new \Sokna\PublicEdge\Remote\RemoteReadModelService($public,new \Sokna\PublicEdge\Connectivity\ConnectivityService($public));
$submit=static fn(bool $expected,bool $choice)=>$control->saveSettings(['cafe_name'=>'QA only','brand_subtitle'=>'QA','business_day_cutoff'=>'04:00','waiter_call_enabled'=>true,'final_amount_rial'=>$choice,'expected_final_amount_rial'=>$expected],['id'=>1]);
$assert=static function(bool $ok,string $message){if(!$ok)throw new RuntimeException('FAIL_'.$message);};
$model=static function()use($builder,$assert){$m=$builder->remoteModelsFor(['operations']);$assert(count($m)===1,'ONE_OPERATIONS_MODEL');return $m[0];};
$sync=static function(int $seq,array $m)use($remote,$id,$assert){$r=$remote->syncModelV2($id,['format'=>'sokna-read-model-v2','up_to_sequence'=>$seq,'model'=>$m]);$assert($r['status']===200&&($r['body']['ok']??false)===true,'PUBLIC_ACCEPT_MODEL_'.($r['body']['error']??'HTTP'.$r['status']));return $r;};
$read=static function()use($remote,$id,$assert){$r=$remote->read(['installation_id'=>$id,'capabilities'=>['*']],'operations');$assert($r['status']===200,'PUBLIC_MODEL_READ');return $r['body']['payload']['hall']['final_amount_rial']??null;};
$initial=$model();$assert($initial['payload']['hall']['final_amount_rial']===false,'INITIAL_TOMAN');$sync(1,$initial);$assert($read()===false,'PUBLIC_INITIAL_TOMAN');
// First browser activates Rial. Save commits without any HTTP or Public connection.
$r=$submit(false,true);$assert($r['saved']===true&&$r['final_amount_rial']===true,'BROWSER_A_RIAL_SAVED');
$stmt=$local->query("SELECT sequence_id,model_keys FROM public_projection_outbox WHERE event_type='settings.currency_policy_changed' ORDER BY sequence_id");$events=$stmt->fetchAll(PDO::FETCH_ASSOC);$assert(count($events)===1&&$events[0]['model_keys']==='operations','CURRENCY_DIRTY_OPERATIONS');
$max=(int)$events[0]['sequence_id'];$assert($max>1,'SEQUENCE_MONOTONIC');
$assert($read()===false,'PUBLIC_BEFORE_ACK_OLD_TOMAN');
// Unrelated, older browser tries to change it back: no new currency event or data loss.
try{$submit(false,false);throw new RuntimeException('STALE_FORM_ACCEPTED');}catch(\Sokna\Local\Domain\Admin\AdminControlException $e){$assert($e->errorCode==='currency_policy_changed','OLD_BROWSER_409');}
$assert((int)$local->query("SELECT COUNT(*) FROM public_projection_outbox WHERE event_type='settings.currency_policy_changed'")->fetchColumn()===1,'NO_SECOND_ON_CONFLICT');
// The exact Local operations projection is delivered over Public v2 service and read back.
$new=$model();$assert($new['payload']['hall']['final_amount_rial']===true&&$initial['source_version']!==$new['source_version'],'NEW_MODEL_RIAL');
$sync($max,$new);$assert($read()===true,'PUBLIC_RIAL_AFTER_ACK');
// An old retry cannot revert Public's newer currency policy.
$reject=$remote->syncModelV2($id,['format'=>'sokna-read-model-v2','up_to_sequence'=>1,'model'=>$initial]);
$assert($reject['status']===409,'OLDER_PUBLIC_UPDATE_REJECTED');$assert($read()===true,'PUBLIC_REMAINS_RIAL');
// Local unrelated stale save still cannot regress value, but a current explicit
// administrator opt-out can return to Toman and trigger a new durable event.
$priorCount=(int)$local->query("SELECT COUNT(*) FROM public_projection_outbox WHERE event_type='settings.currency_policy_changed'")->fetchColumn();
$submit(true,true);$assert((int)$local->query("SELECT COUNT(*) FROM public_projection_outbox WHERE event_type='settings.currency_policy_changed'")->fetchColumn()===$priorCount,'UNCHANGED_NO_EXTRA_EVENT');
$submit(true,false);
$nextSeq=(int)$local->query("SELECT MAX(sequence_id) FROM public_projection_outbox WHERE event_type='settings.currency_policy_changed'")->fetchColumn();
$assert($nextSeq>$max,'NEW_TOMAN_EVENT');
$sync($nextSeq,$model());$assert($read()===false,'PUBLIC_EXPLICIT_TOMAN');
$assert((int)$local->query("SELECT COUNT(*) FROM public_projection_outbox WHERE event_type='unrelated.pending' AND delivered_at IS NULL")->fetchColumn()===1,'OLDER_PENDING_EVENT_PRESERVED');
// If Public is offline, saving still commits a future retriable model invalidation.
$submit(false,true);$pending=(int)$local->query("SELECT COUNT(*) FROM public_projection_outbox WHERE event_type='settings.currency_policy_changed' AND delivered_at IS NULL")->fetchColumn();$assert($pending===3,'OFFLINE_EVENT_DURABLE');
$assert($read()===false,'PUBLIC_NOT_MAGICALLY_CHANGED_OFFLINE');
echo "R278_LOCAL_DB_BROWSER_A_BROWSER_B_TO_PUBLIC_V2_REAL_MODEL_RIAL_PASS\n";
echo json_encode(['ok'=>true,'from'=>'Local','to'=>'Public','models'=>'operations','root_cause'=>'settings_trigger_missing_operations','fix'=>'transactional_outbox_currency_model_invalidation','received_rial'=>true,'out_of_order_rejected'=>true,'manual_toman'=>true,'offline_pending_safe'=>true,'no_new_migration'=>true],JSON_UNESCAPED_SLASHES)."\n";
