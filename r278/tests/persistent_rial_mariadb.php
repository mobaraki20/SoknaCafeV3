<?php
declare(strict_types=1);
// Isolated test DB only; never points to live café data.
if (getenv('GITHUB_ACTIONS') !== 'true') throw new RuntimeException('CI_ONLY');
$name = (string)getenv('SOKNA_QA_DB_NAME');
if ($name !== 'sokna_qa_rial_persist') throw new RuntimeException('TEST_DB_NAME_MISMATCH');
$pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname='.$name.';charset=utf8mb4',
    'root', (string)getenv('SOKNA_QA_DB_PASSWORD'),
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false]);
$pdo->exec('CREATE TABLE IF NOT EXISTS settings(setting_key VARCHAR(150) PRIMARY KEY,setting_value VARCHAR(255) NOT NULL) ENGINE=InnoDB');
$pdo->exec('CREATE TABLE IF NOT EXISTS audit_log(id BIGINT AUTO_INCREMENT PRIMARY KEY,actor_user_id BIGINT NOT NULL,action VARCHAR(80),entity_type VARCHAR(50),entity_id VARCHAR(80),details_json TEXT) ENGINE=InnoDB');
$pdo->exec("DELETE FROM settings WHERE setting_key='payment.final_amount_rial'");
$pdo->exec('DELETE FROM audit_log');
require_once __DIR__.'/../source/src/Domain/Admin/AdminControlException.php';
require_once __DIR__.'/../source/src/Core/IdentityRepository.php';
require_once __DIR__.'/../source/src/Domain/Admin/AdminControlService.php';
use Sokna\Local\Core\IdentityRepository;
use Sokna\Local\Domain\Admin\AdminControlException;
use Sokna\Local\Domain\Admin\AdminControlService;
$identity = new class implements IdentityRepository {
    public function findActiveById(int $id): ?array {return $id===1?['id'=>1,'role'=>'admin']:null;}
    public function findActiveByUsername(string $username): ?array {return null;}
    public function capabilitiesForUser(int $id): array {return [];}
    public function preparationAreasForUser(int $id): array {return [];}
};
$service = (new ReflectionClass(AdminControlService::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(AdminControlService::class,'pdo'))->setValue($service,$pdo);
(new ReflectionProperty(AdminControlService::class,'identity'))->setValue($service,$identity);
$val = static function() use ($pdo):bool {
    return (string)$pdo->query("SELECT setting_value FROM settings WHERE setting_key='payment.final_amount_rial'")->fetchColumn()==='1';
};
$submit=static function(bool $expected,bool $chosen,array $extra=[])use($service):array {
    return $service->saveSettings(array_merge(['cafe_name'=>'QA café','brand_subtitle'=>'QA','business_day_cutoff'=>'04:00','waiter_call_enabled'=>true,'final_amount_rial'=>$chosen,'expected_final_amount_rial'=>$expected],$extra),['id'=>1]);
};
$expectFailure=static function(string $error,callable $call) use($pdo):void {
    try{$call();}catch(AdminControlException $e){if($e->errorCode!==$error)throw new RuntimeException('EXPECTED_'.$error.'_GOT_'.$e->errorCode);if($pdo->inTransaction())throw new RuntimeException('TRANSACTION_NOT_ROLLED_BACK');return;}
    throw new RuntimeException('EXPECTED_REJECTION_'.$error);
};
// Browser A starts from no preference and writes Rial to the canonical DB.
$a=$submit(false,true);if($a['final_amount_rial']!==true||!$val())throw new RuntimeException('FIRST_BROWSER_SAVE_FAILED');
// Browser B was opened before A saved. Its unrelated Save must not revert Rial.
$expectFailure('currency_policy_changed',fn()=>$submit(false,false,['cafe_name'=>'Other browser']));
if(!$val())throw new RuntimeException('STALE_BROWSER_RESET_RIAL');
// Legacy or partial requests must not supply a hidden fallback to Toman.
$expectFailure('currency_policy_missing',fn()=>$submit(true,false,['expected_final_amount_rial'=>null]));
$expectFailure('currency_policy_missing',fn()=>$submit(true,false,['final_amount_rial'=>'0']));
if(!$val())throw new RuntimeException('MISSING_BROWSER_RESET_RIAL');
// Refreshing Browser B obtains true from the DB; a correct new form can save.
$b=$submit(true,true,['cafe_name'=>'After refresh']);if($b['final_amount_rial']!==true||!$val())throw new RuntimeException('REFRESH_LOST_RIAL');
// Explicit opt-out is allowed and must stay persisted across connections.
$c=$submit(true,false);if($c['final_amount_rial']!==false||$val())throw new RuntimeException('USER_TOMAN_OPT_OUT_FAILED');
// Simulate new browser restoring Rial from the installed setting.
$d=$submit(false,true);if($d['final_amount_rial']!==true||!$val())throw new RuntimeException('RESTORE_RIAL_FAILED');
$audits=$pdo->query("SELECT COUNT(*) FROM audit_log WHERE action='settings.local_updated'")->fetchColumn();
if((int)$audits!==4)throw new RuntimeException('AUDIT_COUNT_WRONG');
print "R278_RIAL_SETTING_PERSISTENT_DIFFERENT_BROWSERS_MARIADB_PASS\n";