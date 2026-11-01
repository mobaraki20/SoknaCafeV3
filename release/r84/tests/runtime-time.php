<?php
declare(strict_types=1);
$root=$argv[1];
require $root.'/local/src/Runtime/RuntimeEvidence.php';
require $root.'/local/src/Shared/IranTime.php';
require $root.'/public/src/Http/PublicHttpKernel.php';
use Sokna\Local\Runtime\RuntimeEvidence;
$p=new PDO('mysql:host=127.0.0.1;port='.(getenv('DB_PORT')?:3306).';dbname=r84_time;charset=utf8mb4','root',getenv('DB_PASSWORD')?:'r84-test',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$p->exec('CREATE TABLE IF NOT EXISTS runtime_trigger_receipts(id INT AUTO_INCREMENT PRIMARY KEY,runtime_instance_id VARCHAR(128),state VARCHAR(20),accepted_at DATETIME)');
$results=[];
function check(bool $value,string $name):void{global $results;$results[]=['test'=>$name,'pass'=>$value];}
$kernel=(new ReflectionClass(Sokna\PublicEdge\Http\PublicHttpKernel::class))->newInstanceWithoutConstructor();
$serialize=(new ReflectionClass($kernel))->getMethod('json');
foreach(['Asia/Tehran','UTC'] as $zone){
 date_default_timezone_set($zone);$p->exec('TRUNCATE runtime_trigger_receipts');
 $now=(new DateTimeImmutable('2026-11-01T04:30:00Z'))->getTimestamp();
 $local=(new DateTimeImmutable('@'.($now-12)))->setTimezone(new DateTimeZone($zone))->format('Y-m-d H:i:s');
 $p->prepare('INSERT INTO runtime_trigger_receipts(runtime_instance_id,state,accepted_at) VALUES(?,?,?)')->execute(['r84-runtime','completed',$local]);
 $heartbeat=RuntimeEvidence::heartbeatSnapshot($p,$now);
 check(($heartbeat['age_seconds']??null)===12,$zone.' receipt age uses database basis');
 check(preg_match('/(?:Z|[+-]\d{2}:\d{2})$/D',$heartbeat['last_seen_at'])===1,$zone.' heartbeat carries explicit timezone');
 $wire=$serialize->invoke($kernel,['status'=>200,'body'=>['telemetry'=>['runtime_last_seen_at'=>$heartbeat['last_seen_at']]]]);
 $seen=json_decode($wire['body'],true)['telemetry']['runtime_last_seen_at'];
 check(strtotime($seen)===$now-12,$zone.' actual Public HTTP serializer preserves source instant');
 $again=$serialize->invoke($kernel,['status'=>200,'body'=>json_decode($wire['body'],true)]);
 check(json_decode($again['body'],true)['telemetry']['runtime_last_seen_at']===$seen,$zone.' repeated serialization never shifts instant');
 $s=RuntimeEvidence::snapshot($p,$now);check($s['last_seen_at']===$local,$zone.' Local diagnostic timestamp contract preserved');
 $future=(new DateTimeImmutable('@'.($now+300)))->setTimezone(new DateTimeZone($zone))->format('Y-m-d H:i:s');
 $p->prepare('UPDATE runtime_trigger_receipts SET accepted_at=?')->execute([$future]);
 $s=RuntimeEvidence::heartbeatSnapshot($p,$now);check($s['age_seconds']===null&&$s['status']==='observed_stale',$zone.' future receipt does not become healthy');
}
$p->exec('TRUNCATE runtime_trigger_receipts');$empty=RuntimeEvidence::heartbeatSnapshot($p);
check($empty['last_seen_at']===''&&$empty['status']==='not_seen','absent evidence stays absent');
echo json_encode($results,JSON_PRETTY_PRINT).PHP_EOL;
exit(count(array_filter($results,fn($x)=>!$x['pass']))?1:0);
