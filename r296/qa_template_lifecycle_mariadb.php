<?php
declare(strict_types=1);
if(getenv('GITHUB_ACTIONS')!=='true'||getenv('R296_QA_ONLY')!=='disposable')throw new RuntimeException('QA_ONLY_DISPOSABLE_MARIADB');
$root=rtrim((string)getenv('R296_SOURCE_ROOT'),'/');
require $root.'/src/Domain/Printing/PrintException.php';
require $root.'/src/Domain/Printing/PrintTemplatePackageService.php';
$db=new PDO('mysql:host=127.0.0.1;dbname=sokna_r296_templates;charset=utf8mb4','root',(string)getenv('R296_DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE users(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,display_name VARCHAR(120) NOT NULL) ENGINE=InnoDB');
$db->exec("INSERT INTO users (id,display_name) VALUES (1,'QA مدیر')");
$db->exec('CREATE TABLE audit_log(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,actor_user_id INT UNSIGNED NOT NULL,action VARCHAR(100) NOT NULL,entity_type VARCHAR(100) NOT NULL,entity_id VARCHAR(100) NOT NULL,details_json JSON NOT NULL) ENGINE=InnoDB');
// Exercise the real migration DDL, not a guessed template table structure.
$source=file_get_contents($root.'/database/migrations/0025_g4_print_template_packages.sql');
foreach(array_filter(array_map('trim',explode(';',preg_replace('/^\s*--.*$/m','',$source)))) as $stmt)$db->exec($stmt);
$svc=new \Sokna\Local\Domain\Printing\PrintTemplatePackageService($db);
$admin=['role'=>'admin','id'=>1];
$c=$svc->installStandard('customer',$admin);$p=$svc->installStandard('preparation',$admin);
$cid=(int)$c['package_id'];$pid=(int)$p['package_id'];
$svc->activate($cid,$admin);$svc->activate($pid,$admin);
$before=$svc->snapshot();
if((int)$before['active']['customer']['id']!==$cid||(int)$before['active']['preparation']['id']!==$pid)throw new RuntimeException('ACTIVE_STANDARD_SETUP_BAD');
$detail=$svc->details($cid,$admin);$tmpl=$detail['template'];$tmpl['footer']='بازنویسی فقط در پیش نویس';
$v=$svc->saveVersion($cid,['name'=>'قالب سفارشی آزمون','version'=>'1.0.1','description'=>'قبل از فعال سازی','template'=>$tmpl],$admin);
$vid=(int)$v['package_id'];
$afterSave=$svc->snapshot();
if(count($afterSave['packages'])!==3||(int)$afterSave['active']['customer']['id']!==$cid)throw new RuntimeException('SAVE_AUTO_ACTIVATED_OR_LOST');
$newRow=array_values(array_filter($afterSave['packages'],fn($x)=>(int)$x['id']===$vid));
if(count($newRow)!==1||(int)$newRow[0]['active']!==0)throw new RuntimeException('PENDING_VERSION_NOT_REPRESENTABLE');
try{$svc->delete($cid,$admin);throw new RuntimeException('ACTIVE_DELETE_SUCCEEDED');}catch(\Sokna\Local\Domain\Printing\PrintException $e){if($e->errorCode!=='print_template_delete_active')throw $e;}
$svc->activate($vid,$admin);
$afterActivate=$svc->snapshot();if((int)$afterActivate['active']['customer']['id']!==$vid)throw new RuntimeException('ACTIVATE_FAILED');
$svc->rollback($cid,$admin);
$afterRollback=$svc->snapshot();if((int)$afterRollback['active']['customer']['id']!==$cid)throw new RuntimeException('ROLLBACK_FAILED');
// Archived version can be deleted, but historical audit records remain.
$svc->delete($vid,$admin);
$end=$svc->snapshot();if(count($end['packages'])!==2||(int)$end['active']['customer']['id']!==$cid)throw new RuntimeException('DELETE_OLD_FAILED');
try{$svc->saveVersion($cid,['version'=>'1.0.2','template'=>$tmpl],['role'=>'operator','id'=>2]);throw new RuntimeException('NON_ADMIN_MUTATED');}catch(\Sokna\Local\Domain\Printing\PrintException $e){if($e->httpStatus!==403)throw $e;}
$audit=(int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action LIKE 'printing.template_%'")->fetchColumn();if($audit<7)throw new RuntimeException('AUDIT_LOST');
$rows=$db->query('SELECT action,entity_id FROM audit_log ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
print json_encode(['result'=>'PASS','standard_packages'=>2,'save_does_not_activate'=>true,'active_delete_blocked'=>true,'activate'=>true,'rollback'=>true,'archived_delete'=>true,'non_admin_blocked'=>true,'audit_events'=>$audit,'actions'=>array_column($rows,'action')],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n";
