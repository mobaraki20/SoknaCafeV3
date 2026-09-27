<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/apps/local-web/bootstrap.php';
function g22_fail(string $m):never{fwrite(STDERR,$m.PHP_EOL);exit(1);}function g22_assert(bool $c,string $m):void{if(!$c)g22_fail($m);}
$root=sys_get_temp_dir().'/sokna-v3-g22-'.bin2hex(random_bytes(5));
$core=sokna_local_bootstrap([
 'app'=>['timezone'=>'Asia/Tehran','data_dir'=>$root],
 'db'=>['host'=>(string)(getenv('SOKNA_TEST_DB_HOST')?:'127.0.0.1'),'port'=>(string)(getenv('SOKNA_TEST_DB_PORT')?:'3306'),'name'=>(string)(getenv('SOKNA_TEST_DB_NAME')?:'sokna_m2'),'charset'=>'utf8mb4','user'=>(string)(getenv('SOKNA_TEST_DB_USER')?:'sokna'),'pass'=>(string)(getenv('SOKNA_TEST_DB_PASS')?:'sokna')],
 'installation'=>['id'=>'g22-installation'],
 'runtime'=>['local_token'=>'g22-runtime-secret'],
 'public'=>['base_url'=>'https://public.example.test/path?secret=bad'],
]);
$core->migrations()->migrate();$pdo=$core->database();
$pdo->prepare("INSERT INTO runtime_trigger_receipts(runtime_instance_id,request_id,request_hash,trigger_key,correlation_id,state,result_json,requested_at,accepted_at) VALUES(?,?,?,?,?,'completed','{}',NOW(),NOW())")
 ->execute(['runtime-g22','request-g22-0001',str_repeat('a',64),'relay.process','corr-g22-0001']);
$pdo->prepare("INSERT INTO print_agents(name,token_hash,token_hint,active,hostname,agent_version,last_heartbeat_at,last_seen_at,local_backlog_count) VALUES(?,?,?,1,?,?,NOW(),NOW(),2)")
 ->execute(['G22 Agent',hash('sha256','secret'),'hint','g22-host','6.2.5']);$agent=(int)$pdo->lastInsertId();
$pdo->prepare("UPDATE print_destinations SET agent_id=?,active=1,required_for_operation=1 WHERE destination_key='prep_shared'")->execute([$agent]);
$s=$core->systemDiagnostics()->snapshot();g22_assert((string)$s['components']['database']['status']==='ok','database health not ok');g22_assert((int)$s['components']['database']['pending_migrations']===0,'migration drift reported');g22_assert((string)$s['components']['runtime']['status']==='observed_recently','runtime receipt evidence missing');g22_assert((int)$s['components']['print_agent']['active_agents']===1,'print agent health missing');g22_assert((int)$s['components']['print_agent']['required_unready']===0,'required print destination incorrectly unready');g22_assert((string)$s['components']['public_edge']['base_origin']==='https://public.example.test','Public origin was not stripped to safe origin');
$b=$core->systemDiagnostics()->createSupportBundle();$path=$core->systemDiagnostics()->supportBundlePath((string)$b['id']);$raw=gzdecode((string)file_get_contents($path));g22_assert(is_string($raw),'support bundle invalid');g22_assert(!str_contains($raw,'g22-runtime-secret'),'runtime token leaked');g22_assert(!str_contains($raw,'secret=bad'),'Public URL query leaked');
fwrite(STDOUT,"G2.2 observability DB self-test: OK\n");
