<?php
declare(strict_types=1);
// Real MariaDB test, disposable GitHub Actions runner ONLY. Refuse all other targets.
if (getenv('GITHUB_ACTIONS') !== 'true' || getenv('SOKNA_R216_DISPOSABLE_ONLY') !== 'YES') {
    throw new RuntimeException('R216_GITHUB_DISPOSABLE_ONLY');
}
$web = (string)getenv('SOKNA_R216_WEB');
$tmp = realpath((string)getenv('RUNNER_TEMP'));
$realWeb = realpath($web);
if (!$tmp || !$realWeb || !str_starts_with($realWeb, $tmp.DIRECTORY_SEPARATOR) || !is_file($web.'/bootstrap.php')) {
    throw new RuntimeException('R216_REFUSE_NON_TEMP_WEB');
}
$root = dirname($web);
$data = $root.'/Data';
$media = $data.'/guest-media';
if (!is_dir($media) && !mkdir($media,0700,true) && !is_dir($media)) throw new RuntimeException('R216_CANNOT_CREATE_MEDIA');
if (file_exists($root.'/config.php')) throw new RuntimeException('R216_EXISTING_CONFIG_REFUSED');
file_put_contents($root.'/config.php', '<?php return '.var_export(['app'=>['data_dir'=>$data]],true).';');
require $web.'/bootstrap.php';
$pdo = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4','root',(string)getenv('SOKNA_R216_DB_PASSWORD'),[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES=>false,
]);
$pdo->exec('CREATE DATABASE sokna_r216_disposable CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdo->exec('USE sokna_r216_disposable');
$migrations = new \Sokna\Local\Core\Migrations($pdo,$web.'/database/migrations');
$migrations->migrate('0059_menu_business_reconciliation_v4');
$pdo->prepare('INSERT INTO users(username,password_hash,display_name,role,active) VALUES(?,?,?,?,1)')->execute([
    'r216.print.qa',password_hash('disposable-qa',PASSWORD_DEFAULT),'QA Printing Admin','admin',
]);
$uid=(int)$pdo->lastInsertId();
$actor=['id'=>$uid,'role'=>'admin'];
$icons=new \Sokna\Local\Domain\Sellables\CategoryIconLibrary($web.'/resources/default-content/v1/category-icons.json');
$seeder=new \Sokna\Local\Domain\Sellables\DefaultContentSeeder($pdo,$icons,$web.'/resources/default-content/v1',$media);
if(($seeder->seed($uid)['status']??'')!=='complete')throw new RuntimeException('R216_SEED_FAILED');
$migrations->migrate();
$count=(int)$pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
if($count!==81)throw new RuntimeException('R216_MIGRATION_COUNT_'.$count);
function ok216(bool $test,string $name):void{
    if(!$test)throw new RuntimeException('FAIL_R216_'.$name);
    echo 'PASS_R216_'.$name."\n";
}
function scalar216(PDO $db,string $sql,array $params=[]):mixed{
    $s=$db->prepare($sql);$s->execute($params);return $s->fetchColumn();
}
function insert216(PDO $db,int $uid,string $key,string $kind,string $version):int{
    $payload=['format'=>'sokna-print-template-package-v1','template_key'=>$key,'name'=>'R216 '. $key,'version'=>$version,'document_kind'=>$kind,'template'=>['design'=>['item_layout'=>'columnar']]];
    $json=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    $template=json_encode($payload['template'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    $hash=hash('sha256',$json);
    $q=$db->prepare('INSERT INTO print_template_packages(template_key,display_name,version,document_kind,package_format,package_json,template_json,content_sha256,description,created_by_user_id) VALUES(?,?,?,?,?,?,?,?,?,?)');
    $q->execute([$key,'R216 '.$key,$version,$kind,'sokna-print-template-package-v1',$json,$template,$hash,'R216 disposable QA',$uid]);
    return (int)$db->lastInsertId();
}
$svc=new \Sokna\Local\Domain\Printing\PrintTemplatePackageService($pdo);
$active=insert216($pdo,$uid,'qa-active','preparation','1.0.0');
$inactive=insert216($pdo,$uid,'qa-inactive','preparation','1.0.0');
$rollback=insert216($pdo,$uid,'qa-rollback','customer','1.0.0');
$pdo->prepare('INSERT INTO print_template_activations(document_kind,package_id,activated_by_user_id) VALUES(?,?,?)')->execute(['preparation',$active,$uid]);
try{$svc->delete($inactive,['id'=>$uid,'role'=>'staff']);throw new RuntimeException('FAIL_R216_STAFF_ALLOWED');}
catch(\Sokna\Local\Domain\Printing\PrintException $e){ok216($e->errorCode==='forbidden','STAFF_BLOCKED');}
ok216((int)scalar216($pdo,'SELECT COUNT(*) FROM print_template_packages WHERE id=?',[$inactive])===1,'STAFF_NO_MUTATION');
try{$svc->delete($active,$actor);throw new RuntimeException('FAIL_R216_ACTIVE_ALLOWED');}
catch(\Sokna\Local\Domain\Printing\PrintException $e){ok216($e->errorCode==='print_template_delete_active','ACTIVE_BLOCKED');}
ok216((int)scalar216($pdo,'SELECT COUNT(*) FROM print_template_packages WHERE id=?',[$active])===1,'ACTIVE_ROW_PRESERVED');
ok216(!$pdo->inTransaction(),'ACTIVE_TRANSACTION_ROLLED_BACK');
// Force FK failure of audit insertion AFTER DELETE; deletion must roll back atomically.
try{$svc->delete($rollback,['id'=>999999999,'role'=>'admin']);throw new RuntimeException('FAIL_R216_AUDIT_FK_ALLOWED');}
catch(\Sokna\Local\Domain\Printing\PrintException $e){ok216($e->errorCode==='print_template_delete_referenced','AUDIT_FK_FAILURE_BLOCKED');}
ok216((int)scalar216($pdo,'SELECT COUNT(*) FROM print_template_packages WHERE id=?',[$rollback])===1,'AUDIT_FAILURE_ROLLBACK_PRESERVES_TEMPLATE');
ok216(!$pdo->inTransaction(),'AUDIT_FAILURE_TRANSACTION_ROLLED_BACK');
$res=$svc->delete($inactive,$actor);
ok216(($res['deleted']??false)===true && (int)$res['package_id']===$inactive,'INACTIVE_DELETED');
ok216((int)scalar216($pdo,'SELECT COUNT(*) FROM print_template_packages WHERE id=?',[$inactive])===0,'DELETED_ROW_GONE');
ok216((int)scalar216($pdo,"SELECT COUNT(*) FROM audit_log WHERE action='printing.template_deleted' AND entity_id=? AND actor_user_id=?",[(string)$inactive,$uid])===1,'DELETE_AUDIT_EXACT_ONCE');
ok216((int)scalar216($pdo,'SELECT COUNT(*) FROM print_template_activations WHERE document_kind=?',['preparation'])===1,'ACTIVE_ACTIVATION_INTACT');
try{$svc->delete($inactive,$actor);throw new RuntimeException('FAIL_R216_REPEAT_DELETED_ACCEPTED');}
catch(\Sokna\Local\Domain\Printing\PrintException $e){ok216($e->errorCode==='print_template_not_found','REPEAT_NOT_FOUND');}
ok216((int)scalar216($pdo,'SELECT COUNT(*) FROM print_template_packages')>=2,'OTHER_TEMPLATES_UNCHANGED');
echo "PASS_R216_REAL_MARIADB_81_MIGRATIONS_TEMPLATE_DELETE\n";
