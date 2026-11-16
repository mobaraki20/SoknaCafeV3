<?php
declare(strict_types=1);

require dirname(__DIR__).'/apps/public/src/Auth/PublicSessionStore.php';
require dirname(__DIR__).'/apps/public/src/Security/PublicMoveOwnerBootstrapService.php';
require dirname(__DIR__).'/apps/public/src/Security/PublicMoveExistingOwnerActivation.php';

use Sokna\PublicEdge\Auth\PublicSessionStore;
use Sokna\PublicEdge\Security\PublicMoveOwnerBootstrapService;
use Sokna\PublicEdge\Security\PublicMoveExistingOwnerActivation;

$dsn=getenv('SOKNA_R257_TEST_DSN');
if(!is_string($dsn)||$dsn==='')throw new RuntimeException('Disposable MariaDB required; no production DSN fallback');
$pdo=new PDO($dsn,(string)(getenv('SOKNA_R257_TEST_USER')?:'root'),(string)(getenv('SOKNA_R257_TEST_PASSWORD')?:''),
 [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['public_move_owner_sessions','public_move_owner_accounts','public_move_owner_setup_claims','public_move_owner_slots',
 'public_sessions','auth_projections','installations'] as $t)$pdo->exec("DROP TABLE IF EXISTS ".$t);
$pdo->exec("CREATE TABLE installations(
 installation_id VARCHAR(96) PRIMARY KEY,active TINYINT NOT NULL DEFAULT 1,
 remote_enabled TINYINT NOT NULL DEFAULT 1) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE auth_projections(
 installation_id VARCHAR(96) NOT NULL,projection_id VARCHAR(96) NOT NULL,
 role VARCHAR(32) NOT NULL,active TINYINT NOT NULL DEFAULT 1,
 display_name VARCHAR(160),capabilities_json JSON NOT NULL,
 preparation_areas_json JSON,PRIMARY KEY(installation_id,projection_id)) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE public_sessions(
 installation_id VARCHAR(96) NOT NULL,projection_id VARCHAR(96) NOT NULL,
 token_hash CHAR(64) PRIMARY KEY,expires_at DATETIME NOT NULL) ENGINE=InnoDB");
$sql=preg_replace('/^--.*$/m','',(string)file_get_contents(dirname(__DIR__).'/r255/owner-bootstrap-schema-fixture.sql'));
foreach(explode(';',$sql) as $q){if(trim($q)!=='')$pdo->exec($q);}
$checks=0;
function ck(bool $ok,string $desc):void{global $checks;if(!$ok)throw new RuntimeException('FAIL '.$desc);$checks++;echo "PASS $desc\n";}
function refuse(callable $call,string $desc):void{
 try{$call();}catch(RuntimeException|InvalidArgumentException){ck(true,$desc);return;}
 throw new RuntimeException('EXPECTED_DENIAL '.$desc);
}
$now=time();$base='https://existing.cafe-orange.example/shop';$instance='public-existing-cafe-orange-001';
$install='local-cafe-orange-001';
$pdo->prepare("INSERT INTO installations(installation_id) VALUES (?)")->execute([$install]);
foreach([['admin-proj','admin'],['staff-proj','staff']] as $x)
 $pdo->prepare("INSERT INTO auth_projections(installation_id,projection_id,role,capabilities_json) VALUES (?,?,?,'[]')")->execute([$install,$x[0],$x[1]]);
$sessions=new PublicSessionStore($pdo);
$a=$sessions->issue($install,'admin-proj')['token'];
$s=$sessions->issue($install,'staff-proj')['token'];
$owners=new PublicMoveOwnerBootstrapService($pdo);
$csrfKey=random_bytes(32);
$up=new PublicMoveExistingOwnerActivation($pdo,$sessions,$owners,$instance,$base,$csrfKey);
$origin='https://existing.cafe-orange.example';
refuse(fn()=>$up->preflight('', $origin,'same-origin',$now),'missing staff token denied');
refuse(fn()=>$up->preflight($s,$origin,'same-origin',$now),'ordinary staff cannot own site');
refuse(fn()=>$up->preflight($a,'https://evil.example','same-origin',$now),'cross-origin admin denied');
refuse(fn()=>$up->preflight($a,$origin,'cross-site',$now),'cross-site admin denied');
$p=$up->preflight($a,$origin,'same-origin',$now);
ck($p['status']==='owner_setup_available'&&strlen($p['csrf'])>35,'real admin sees simple owner setup path');
ck(!$owners->initialized($instance),'read-only preflight leaves owner absent');
refuse(fn()=>$up->activate($a,$origin,'same-origin','wrong','correct-horse-staple-owner',$now),'invalid csrf denied');
refuse(fn()=>$up->activate($a,$origin,'same-origin',$p['csrf'],'too-short',$now),'weak password denied');
ck(!$owners->initialized($instance),'bad password cannot create owner');
$r=$up->activate($a,$origin,'same-origin',$p['csrf'],'secure-cafe-owner-pass-123',$now);
ck($r['status']==='owner_initialized'&&$r['safe_to_cutover']===false&&$r['local_binding_changed']===false,'owner created with zero cutover');
ck($owners->initialized($instance),'new owner tied to actual Public instance');
refuse(fn()=>$up->activate($a,$origin,'same-origin',$p['csrf'],'another-owner-pass-123',$now),'cannot replace existing owner');
ck($up->preflight($a,$origin,'same-origin',$now)['status']==='owner_already_ready','second preflight is harmless');
$login=$owners->login($instance,'secure-cafe-owner-pass-123',$now);
ck($login['ok']===true,'owner can independently login after existing Public upgrade');
$other='local-cafe-blue-002';
$pdo->prepare("INSERT INTO installations(installation_id) VALUES (?)")->execute([$other]);
refuse(fn()=>$up->preflight($a,$origin,'same-origin',$now),'multiple tenants fail closed even for staff admin');
$pdo->prepare("UPDATE installations SET active=0 WHERE installation_id=?")->execute([$other]);
ck($up->preflight($a,$origin,'same-origin',$now)['owner_initialized']===true,'deactivated other tenant no longer ambiguous');
$pdo->prepare("UPDATE installations SET remote_enabled=0 WHERE installation_id=?")->execute([$install]);
refuse(fn()=>$up->preflight($a,$origin,'same-origin',$now),'disabled installation session fails closed');
$pdo->prepare("UPDATE installations SET remote_enabled=1 WHERE installation_id=?")->execute([$install]);
$sessions->revoke($a);
refuse(fn()=>$up->preflight($a,$origin,'same-origin',$now),'revoked admin session fails closed');
ck($owners->resolve($login['token'],$now)['public_instance_id']===$instance,'site owner belongs to Public not paired Local');
echo "R257_EXISTING_PUBLIC_OWNER_PASS count=$checks\n";
