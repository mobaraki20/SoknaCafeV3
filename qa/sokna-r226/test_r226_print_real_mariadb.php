<?php
declare(strict_types=1);
// Strictly disposable GitHub Actions DB; no installed Local/Cafe host may run this.
if (getenv('GITHUB_ACTIONS')!=='true'||getenv('SOKNA_R225_DISPOSABLE_ONLY')!=='YES') {
    throw new RuntimeException('REFUSE_NON_DISPOSABLE_DB');
}
$web=realpath((string)getenv('SOKNA_R213_WEB'));$runner=realpath((string)getenv('RUNNER_TEMP'));
if (!$web||!$runner||!str_starts_with($web,$runner.DIRECTORY_SEPARATOR)||!is_file($web.'/bootstrap.php')) {
    throw new RuntimeException('REFUSE_NON_TEMP_SOURCE');
}
require_once $web.'/bootstrap.php';
$cfg=require dirname($web).'/config.php';$core=sokna_local_bootstrap($cfg);
$pdo=$core->database();$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
if ((int)$pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn()!==81)throw new RuntimeException('WRONG_MIGRATIONS');
$fixture=json_decode((string)file_get_contents($runner.'/r225-fixture.json'),true,512,JSON_THROW_ON_ERROR);
$admin=['id'=>(int)$fixture['admin_id'],'role'=>'admin'];$testCount=0;
$ok=static function(bool $valid,string $label)use(&$testCount):void{
    if(!$valid)throw new RuntimeException('FAIL_R226_'.$label);
    ++$testCount;echo 'PASS_R226_'.$label."\n";
};
$one=static function(string $query,array $params=[])use($pdo):mixed{$s=$pdo->prepare($query);$s->execute($params);return $s->fetchColumn();};
$rows=static function(string $query,array $params=[])use($pdo):array{$s=$pdo->prepare($query);$s->execute($params);return $s->fetchAll(PDO::FETCH_ASSOC)?:[];};
// A dedicated synthetic four-line order, not an already accounted real guest fixture:
// two components of one parent + independent kitchen and cold-bar lines.
// This proves the server's genuine durable print intent/payload, not actual paper or Windows spooler.
$table=(int)$fixture['table_id'];$parent=(int)$fixture['parent'];
$fixed=(int)$fixture['fixed'];$choice=(int)$fixture['choice'];
$number=(int)$one('SELECT COALESCE(MAX(business_order_number),0)+1 FROM orders WHERE business_date=CURDATE()');
$nonce=bin2hex(random_bytes(6));
$pdo->prepare("INSERT INTO orders(public_code,client_token,table_id,order_source,status,business_order_number,business_date,business_shift_key,business_shift_label,business_cutoff_snapshot,total_amount) VALUES(?,?,?,'staff','accounted',?,CURDATE(),'r226','R226 QA','04:00',0)")->execute(['PR226'.$nonce,'pr226-'.$nonce,$table,$number]);
$orderId=(int)$pdo->lastInsertId();
$addItem=$pdo->prepare('INSERT INTO order_items(order_id,item_id,item_name,unit_price,quantity,ordered_quantity,item_note,fulfillment_mode,preparation_station,line_total) VALUES(?,?,?,0,?,?,?,\'dine_in\',?,0)');
$addItem->execute([$orderId,$parent,'R226 bundle parent',2,2,'یادداشت مادر باندل','none']);$oi=(int)$pdo->lastInsertId();
$addComp=$pdo->prepare('INSERT INTO order_item_bundle_components(order_item_id,component_item_id,item_name_snapshot,quantity,preparation_station_snapshot,component_mode) VALUES(?,?,?,?,?,?)');
$addComp->execute([$oi,$fixed,'R226 fixed',4,'kitchen','fixed']);
$addComp->execute([$oi,$choice,'R226 selected',2,'hot_bar','choice']);
$addItem->execute([$orderId,$fixed,'R226 soup',1,1,'بدون ادویه','kitchen']);
$addItem->execute([$orderId,$choice,'R226 juice',1,1,'کم یخ','cold_bar']);
$pdo->prepare("UPDATE print_destinations SET active=1 WHERE destination_key='prep_shared'")->execute();
$pre=(int)$one("SELECT COUNT(*) FROM print_jobs WHERE entity_type='order' AND entity_id=? AND job_type='preparation'",[(string)$orderId]);
$ok($pre===0,'FRESH_ORDER_HAS_NO_PRINT_JOB');
$pdo->beginTransaction();$first=$core->printing()->enqueueOrderTx($orderId,(int)$admin['id']);$pdo->commit();
$ok(is_int($first)&&$first>0,'FOUR_ITEM_PREP_JOB_CREATED');
$j=$rows("SELECT id,status,destination_key,idempotency_key,payload_json,content_sha256 FROM print_jobs WHERE entity_type='order' AND entity_id=? AND job_type='preparation'",[(string)$orderId]);
$ok(count($j)===1&&(int)$j[0]['id']===$first,'ONLY_ONE_DURABLE_PREP_JOB');
$payload=json_decode((string)$j[0]['payload_json'],true,512,JSON_THROW_ON_ERROR);
$sections=$payload['sections']??[];$items=$sections[0]['items']??[];
$ok(count($sections)===1&&($sections[0]['title']??null)===''&&count($items)===4,'SINGLE_UNTITLED_SECTION_FOUR_LINES');
$names=array_column($items,'name');
$ok($names===['R226 fixed','R226 soup','R226 selected','R226 juice'],'KITCHEN_FIRST_BAR_CONTINUOUS_ORDER');
$ok(array_map('intval',array_column($items,'quantity'))===[4,1,2,1],'PREPARATION_COMPONENT_AND_STANDALONE_QUANTITIES');
$notes=array_map(static fn($row)=>trim((string)($row['note']??'')),$items);
$ok(str_contains($notes[0],'یادداشت مادر باندل')&&$notes[1]==='بدون ادویه'&&str_contains($notes[2],'یادداشت مادر باندل')&&$notes[3]==='کم یخ','NOTES_PRESERVED_PER_PARENT_STATION_AND_ITEM');
$ok(!str_contains((string)json_encode($sections,JSON_UNESCAPED_UNICODE),'عنوان بار گرم')&&!str_contains((string)json_encode($sections,JSON_UNESCAPED_UNICODE),'عنوان بار سرد'),'NO_ADDED_BAR_HEADERS');
$ok((string)$j[0]['idempotency_key']==='prep:order:'.$orderId,'ORDER_SCOPED_IDEMPOTENCY');
$pdo->beginTransaction();$again=$core->printing()->enqueueOrderTx($orderId,(int)$admin['id']);$pdo->commit();
$ok($again===$first&&(int)$one("SELECT COUNT(*) FROM print_jobs WHERE entity_type='order' AND entity_id=? AND job_type='preparation'",[(string)$orderId])===1,'RETRY_DOES_NOT_ENQUEUE_SECOND_JOB');
$ok((int)$one('SELECT COUNT(*) FROM print_jobs WHERE id=? AND content_sha256=?',[$first,(string)$j[0]['content_sha256']])===1,'RETRY_PRESERVES_CONTENT_SHA');
// Real DB safety for inactive template deletion: active template is protected;
// inactive template can be deleted, audit survives, and printing job survives.
$insert=$pdo->prepare("INSERT INTO print_template_packages(template_key,display_name,version,document_kind,package_format,package_json,template_json,content_sha256,created_by_user_id) VALUES(?,?,'1','preparation','sokna-print-template-package-v1','{}','{}',?,?)");
$insert->execute(['r226-active-'.$nonce,'R226 active test',hash('sha256','r226-active-'.$nonce),(int)$admin['id']]);$active=(int)$pdo->lastInsertId();
$insert->execute(['r226-inactive-'.$nonce,'R226 inactive test',hash('sha256','r226-inactive-'.$nonce),(int)$admin['id']]);$inactive=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO print_template_activations(document_kind,package_id,activated_by_user_id) VALUES('preparation',?,?) ON DUPLICATE KEY UPDATE package_id=VALUES(package_id),activated_by_user_id=VALUES(activated_by_user_id)")->execute([$active,(int)$admin['id']]);
$blocked=false;
try{$core->printTemplates()->delete($active,$admin);}catch(\Sokna\Local\Domain\Printing\PrintException $e){$blocked=$e->errorCode==='print_template_delete_active';}
$ok($blocked&&$one('SELECT id FROM print_template_packages WHERE id=?',[$active])!==false,'ACTIVE_TEMPLATE_HARD_DELETE_DENIED');
$deleted=$core->printTemplates()->delete($inactive,$admin);
$ok(($deleted['deleted']??false)===true&&$one('SELECT id FROM print_template_packages WHERE id=?',[$inactive])===false,'INACTIVE_TEMPLATE_HARD_DELETE_DONE');
$ok((int)$one("SELECT COUNT(*) FROM audit_log WHERE action='printing.template_deleted' AND entity_id=?",[(string)$inactive])===1,'TEMPLATE_DELETE_AUDIT_RECORDED');
$ok((int)$one('SELECT COUNT(*) FROM print_jobs WHERE id=?',[$first])===1,'EXISTING_JOB_NOT_DELETED_WITH_TEMPLATE');
$ok(!$pdo->inTransaction(),'NO_LEFTOVER_TRANSACTION');
echo 'PASS_R226_REAL_MARIADB_PRINT_QUEUE_TEMPLATE_'.$testCount."_CHECKS\n";