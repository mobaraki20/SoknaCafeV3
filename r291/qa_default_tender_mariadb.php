<?php
declare(strict_types=1);
// QA only: disposable MariaDB, never read production credentials.
if(getenv('GITHUB_ACTIONS')!=='true'||getenv('R291_QA_MODE')!=='disposable_only')throw new RuntimeException('QA_RUNTIME_REQUIRED');
$root=getenv('R291_SOURCE_ROOT');if(!$root||!is_file($root.'/src/Domain/Admin/AdminControlService.php'))throw new RuntimeException('ACTUAL_SOURCE_MISSING');
foreach(['/src/Core/IdentityRepository.php','/src/Core/Capabilities.php','/src/Core/SnapshotGuard.php','/src/Domain/Admin/AdminControlException.php','/src/Domain/Admin/AdminControlService.php'] as $f)require_once $root.$f;
use Sokna\Local\Core\IdentityRepository;
use Sokna\Local\Core\SnapshotGuard;
use Sokna\Local\Domain\Admin\AdminControlService;
use Sokna\Local\Domain\Admin\AdminControlException;
final class QAIdentity implements IdentityRepository{
 public function findActiveById(int $id):?array{return match($id){1=>['id'=>1,'role'=>'admin','display_name'=>'مدیر'],2=>['id'=>2,'role'=>'cashier','display_name'=>'صندوق'],default=>null};}
 public function findActiveByUsername(string $name):?array{return null;}
 public function capabilitiesForUser(int $id):array{return [];}
 public function preparationAreasForUser(int $id):array{return [];}
}
$db=new PDO('mysql:host=127.0.0.1;dbname=sokna_r291;charset=utf8mb4','root',getenv('R291_DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$db->exec('CREATE TABLE settings(setting_key VARCHAR(190) PRIMARY KEY,setting_value TEXT) ENGINE=InnoDB');
$db->exec('CREATE TABLE audit_log(id INT AUTO_INCREMENT PRIMARY KEY,actor_user_id INT,action VARCHAR(120),entity_type VARCHAR(80),entity_id VARCHAR(100),details_json LONGTEXT) ENGINE=InnoDB');
$db->exec('CREATE TABLE public_projection_outbox(id BIGINT AUTO_INCREMENT PRIMARY KEY,event_type VARCHAR(150),aggregate_type VARCHAR(150),aggregate_id VARCHAR(150),model_keys TEXT) ENGINE=InnoDB');
$guard=(new ReflectionClass(SnapshotGuard::class))->newInstanceWithoutConstructor();
$svc=new AdminControlService($db,new QAIdentity(),$guard);
$actor=['id'=>1,'role'=>'admin'];$cashier=['id'=>2,'role'=>'cashier'];
$set=static function($value,$expected,bool $rial=false,bool $expectedRial=false)use($svc,$actor){return $svc->saveSettings(['cafe_name'=>'کافه سکنا','brand_subtitle'=>'QA','business_day_cutoff'=>'04:00','waiter_call_enabled'=>true,'final_amount_rial'=>$rial,'expected_final_amount_rial'=>$expectedRial,'default_tender'=>$value,'expected_default_tender'=>$expected],$actor);};
$setting=static function(string $key)use($db):?string{$q=$db->prepare('SELECT setting_value FROM settings WHERE setting_key=?');$q->execute([$key]);return $q->fetchColumn()?:null;};
$count=static function()use($db):int{return (int)$db->query("SELECT COUNT(*) FROM public_projection_outbox WHERE event_type='settings.payment_default_tender_changed'")->fetchColumn();};
$blocked=static function(callable $fn,string $code)use($setting,$count){$before=$setting('payment.default_tender');$n=$count();try{$fn();}catch(AdminControlException $e){if($e->errorCode!==$code)throw new RuntimeException('WRONG_ERROR '.$e->errorCode.' expected '.$code);if($setting('payment.default_tender')!==$before||$count()!==$n)throw new RuntimeException('BLOCKED_MUTATED');return;}throw new RuntimeException('DID_NOT_BLOCK '.$code);};
$r=$set('pos','pos');if(!$r['saved']||$setting('payment.default_tender')!=='pos'||$count()!==0)throw new RuntimeException('DEFAULT_POS_WRITE');
$r=$set('cash','pos');if($setting('payment.default_tender')!=='cash'||$count()!==1)throw new RuntimeException('CASH_CHANGE');
$blocked(fn()=>$set('bank_transfer','pos'),'tender_policy_changed');
$r=$set('bank_transfer','cash');if($setting('payment.default_tender')!=='bank_transfer'||$count()!==2)throw new RuntimeException('BANK_TRANSFER_CHANGE');
$r=$set('none','bank_transfer');if($setting('payment.default_tender')!=='none'||$count()!==3)throw new RuntimeException('NONE_CHANGE');
$r=$set('pos','none');if($setting('payment.default_tender')!=='pos'||$count()!==4)throw new RuntimeException('POS_REENABLE');
$blocked(fn()=>$set('card','pos'),'tender_policy_missing');
$blocked(fn()=>$set('cash','bogus'),'tender_policy_missing');
$before=$count();$r=$set('pos','pos',true,false);if($count()!==$before||$setting('payment.final_amount_rial')!=='1'||(int)$db->query("SELECT COUNT(*) FROM public_projection_outbox WHERE event_type='settings.currency_policy_changed'")->fetchColumn()!==1)throw new RuntimeException('RIAL_CROSS_POLICY');
try{$svc->saveSettings(['cafe_name'=>'X'], $cashier);throw new RuntimeException('CASHIER_SHOULD_FAIL');}catch(AdminControlException $e){if($e->errorCode!=='forbidden')throw $e;}
if($count()!==4)throw new RuntimeException('EXCESS_EVENT');
$events=$db->query("SELECT aggregate_id,model_keys FROM public_projection_outbox WHERE event_type='settings.payment_default_tender_changed' ORDER BY id")->fetchAll();foreach($events as $e)if($e['aggregate_id']!=='payment.default_tender'||$e['model_keys']!=='operations')throw new RuntimeException('WRONG_OUTBOX');
if((int)$db->query('SELECT COUNT(*) FROM audit_log')->fetchColumn()!==6)throw new RuntimeException('BAD_AUDIT_COUNT');
echo json_encode(['result'=>'PASS','initial_default_pos'=>true,'concurrent_stale_save_blocked'=>true,'cash_bank_none_pos_persisted'=>true,'one_outbox_per_change'=>true,'outbox_operations_projection'=>true,'rial_not_changed_by_tender'=>true,'rial_independent_event'=>true,'invalid_value_blocked'=>true,'non_admin_blocked'=>true,'source'=>'actual R291 AdminControlService + MariaDB 11.4'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE),"\n";
