<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/apps/local-web/bootstrap.php';
use Sokna\Local\Domain\Printing\PrintException;
function m8f(string $m):never{fwrite(STDERR,$m.PHP_EOL);exit(1);} function m8a(bool $c,string $m):void{if(!$c)m8f($m);}
$core=sokna_local_bootstrap(['app'=>['timezone'=>'Asia/Tehran','data_dir'=>sys_get_temp_dir().'/sokna-m8-'.bin2hex(random_bytes(3))],'db'=>['host'=>(string)(getenv('SOKNA_TEST_DB_HOST')?:'127.0.0.1'),'port'=>(string)(getenv('SOKNA_TEST_DB_PORT')?:'3306'),'name'=>(string)(getenv('SOKNA_TEST_DB_NAME')?:'sokna_m2'),'charset'=>'utf8mb4','user'=>(string)(getenv('SOKNA_TEST_DB_USER')?:'sokna'),'pass'=>(string)(getenv('SOKNA_TEST_DB_PASS')?:'sokna')]]);
$core->migrations()->migrate();$pdo=$core->database();
$pdo->prepare('INSERT INTO users(username,password_hash,display_name,role,active) VALUES(?,?,?,?,1)')->execute(['m8-admin',password_hash('x',PASSWORD_DEFAULT),'M8 Admin','admin']);$aid=(int)$pdo->lastInsertId();$admin=['id'=>$aid,'role'=>'admin','active'=>1];
$agent=$core->printing()->createAgent('M8 Agent',$admin);m8a(strlen($agent['token'])===64,'agent token invalid');
$pdo->prepare("UPDATE print_destinations SET active=1,agent_id=?,windows_queue_name='Sokna Test' WHERE destination_key IN('prep_shared','customer_receipt')")->execute([(int)$agent['agent_id']]);
$auth=$core->printing()->authenticate('Bearer '.$agent['token']);m8a((int)$auth['id']===(int)$agent['agent_id'],'agent auth failed');
$pdo->beginTransaction();$j=$core->printing()->enqueueTx('customer_receipt','customer_receipt',['hello'=>'world'],'test','1',$aid,'m8-job-1');$j2=$core->printing()->enqueueTx('customer_receipt','customer_receipt',['hello'=>'world'],'test','1',$aid,'m8-job-1');$pdo->commit();m8a(!$j['duplicate']&&$j2['duplicate']&&(int)$j['job_id']===(int)$j2['job_id'],'queue idempotency failed');
$c=['request_id'=>'m8-claim-0001','agent_version'=>'6.2.5','protocol_version'=>4,'limit'=>3,'ready_destination_keys'=>['customer_receipt']];$claim=$core->printing()->claim($auth,$c);m8a(count($claim['jobs'])===1,'claim did not reserve job');$claim2=$core->printing()->claim($auth,$c);m8a(!empty($claim2['idempotent'])&&$claim2['jobs'][0]['attempt_id']===$claim['jobs'][0]['attempt_id'],'claim replay drifted');
$job=$claim['jobs'][0];$accept=$core->printing()->accept($auth,['request_id'=>'m8-accept-0001','attempt_id'=>$job['attempt_id'],'lease_token'=>$job['lease_token'],'local_receipt_id'=>'receipt-m8-0001','content_sha256'=>$job['content_sha256']]);m8a($accept['status']==='claimed','accept fence failed');
$start=$core->printing()->start($auth,['request_id'=>'m8-start-00001','attempt_id'=>$job['attempt_id'],'lease_token'=>$job['lease_token']]);m8a($start['status']==='started','start failed');
$unknown=$core->printing()->report($auth,['request_id'=>'m8-report-0001','attempt_id'=>$job['attempt_id'],'lease_token'=>$job['lease_token'],'local_receipt_id'=>'receipt-m8-0001','status'=>'unknown','spooler_job_id'=>null,'retryable'=>false,'error_code'=>'spooler_unknown','error_message'=>'ambiguous']);m8a(!empty($unknown['requires_human_resolution']),'ambiguous print not fenced');
$state=(string)$pdo->query('SELECT status FROM print_jobs WHERE id='.(int)$j['job_id'])->fetchColumn();m8a($state==='unknown','unknown outcome not durable');
$core->printing()->resolveAmbiguous((int)$j['job_id'],'retry','operator verified no print',$admin);$state2=(string)$pdo->query('SELECT status FROM print_jobs WHERE id='.(int)$j['job_id'])->fetchColumn();m8a($state2==='pending','manual retry did not reopen queue');
$http=$core->printAgentV4Http()->handle('Bearer bad',['action'=>'probe','agent_version'=>'6.2.5']);m8a($http['status']===401,'Print API auth did not fail closed');
$source=file_get_contents(dirname(__DIR__).'/apps/local-web/src/Domain/Printing/PrintService.php');m8a(!preg_match('/Winspool|System\.Drawing|windows_queue.*PrintDocument/i',$source),'Local absorbed device/spooler implementation');
fwrite(STDOUT,"Local M8 Printing self-test: OK\n");
