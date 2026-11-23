<?php
declare(strict_types=1);
// QA-only executable SQL harness: reads queries extracted from the byte-pinned PASS27 ReportingService.
$base=__DIR__; $pin=json_decode(file_get_contents($base.'/PASS27-REPORT-SQL-PIN.json'),true,512,JSON_THROW_ON_ERROR);
$original=$base.'/ReportingService-PASS27.php';
if(!hash_equals($pin['source_sha256'],hash_file('sha256',$original)))throw new RuntimeException('PASS27 source SHA mismatch');
function checkL(bool $ok,string $name):void{static $n=0;if(!$ok)throw new RuntimeException('FAIL '.$name);$n++;echo 'PASS '.$n.' '.$name."\n";}
function runL(PDO $pdo,string $sql,string $start,string $end):array{$q=$pdo->prepare($sql);$q->execute([$start,$end]);return $q->fetchAll(PDO::FETCH_ASSOC);}
$host=getenv('QA_DB_HOST')?:'127.0.0.1';$pass=getenv('QA_DB_PASSWORD')?:'';$dbname='sokna_pass27_disposable_qa';
if(getenv('GITHUB_ACTIONS')!=='true'||getenv('QA_DB_DISPOSABLE')!=='YES')throw new RuntimeException('DISPOSABLE_GITHUB_ONLY');
$pdo=new PDO("mysql:host=$host;port=3306;charset=utf8mb4",'root',$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbname` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");$pdo->exec("USE `$dbname`");
foreach(['settlement_tender_allocations','settlement_records','table_sessions'] as $t)$pdo->exec("DROP TABLE IF EXISTS `$t`");
$pdo->exec("CREATE TABLE table_sessions(id INT PRIMARY KEY,business_date DATE NOT NULL,status VARCHAR(16) NOT NULL) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE settlement_records(id INT PRIMARY KEY,session_id INT NOT NULL,status VARCHAR(16) NOT NULL,total BIGINT NOT NULL,subtotal BIGINT NOT NULL,discount BIGINT NOT NULL,tax_amount BIGINT NOT NULL,business_date DATE NOT NULL,civil_date DATE NULL,destination VARCHAR(32) NOT NULL,tender_capture_status VARCHAR(32) NOT NULL,closes_session TINYINT NOT NULL,business_shift_key VARCHAR(32),business_shift_label VARCHAR(64),FOREIGN KEY(session_id) REFERENCES table_sessions(id)) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE settlement_tender_allocations(id INT PRIMARY KEY AUTO_INCREMENT,settlement_id INT NOT NULL,tender_type VARCHAR(24) NOT NULL,amount BIGINT NOT NULL,FOREIGN KEY(settlement_id) REFERENCES settlement_records(id)) ENGINE=InnoDB");
$pdo->exec("INSERT INTO table_sessions VALUES(10,'2026-11-22','closed'),(11,'2026-11-23','open')");
$pdo->exec("INSERT INTO settlement_records VALUES
(1,10,'completed',470,500,50,20,'2026-11-23','2026-11-24','direct','recorded',1,'night','شب'),
(2,10,'reversal',100,110,10,0,'2026-11-24','2026-11-24','direct','recorded',0,'night','شب'),
(3,11,'completed',200,200,0,0,'2026-11-23','2026-11-23','direct','recorded',0,'night','شب')");
$pdo->exec("INSERT INTO settlement_tender_allocations(settlement_id,tender_type,amount) VALUES(1,'cash',300),(1,'pos',170),(2,'pos',100),(3,'bank_transfer',200)");
$q=$pin['queries'];
$summary=runL($pdo,$q['summary'],'2026-11-22','2026-11-22')[0];
checkL((int)$summary['net_sales']===370,'sales on session opening day, including later refund');
checkL((int)$summary['gross_sales']===390,'signed gross subtotal');
checkL((int)$summary['discount_total']===40,'signed discount including later reversal');
checkL((int)$summary['net_tax_amount']===20,'tax not confused with discount');
checkL((int)$summary['sales_without_tax']===350,'net excluding tax');
$noSale=runL($pdo,$q['summary'],'2026-11-24','2026-11-24')[0];
checkL((int)$noSale['net_sales']===0,'cash reversal day does not become an extra sales day');
$next=runL($pdo,$q['summary'],'2026-11-23','2026-11-23')[0];
checkL((int)$next['net_sales']===200,'independent next-day open table sales');
$c23=runL($pdo,$q['cashSummary'],'2026-11-23','2026-11-23')[0];
checkL((int)$c23['completed_settlements']===2,'cash receipt count on payment day');
checkL((int)$c23['completed_amount']===670,'cash paid on payment day, not opening day');
$c24=runL($pdo,$q['cashSummary'],'2026-11-24','2026-11-24')[0];
checkL((int)$c24['reversals']===1&&(int)$c24['reversed_amount']===100,'refund attributed to actual reversal date');
$invoices=runL($pdo,$q['invoiceAggregate'],'2026-11-22','2026-11-23')[0];
checkL((int)$invoices['invoice_count']===1,'one closed invoice despite two completed payments');
checkL((int)$invoices['avg_ticket']===370,'invoice average uses net closed account');
$salesDays=runL($pdo,$q['salesDaily'],'2026-11-22','2026-11-24');
checkL(count($salesDays)===2,'sales daily excludes empty reversal payment date');
checkL((string)$salesDays[0]['business_date']==='2026-11-22'&&(int)$salesDays[0]['net_sales']===370,'opening-day report row includes signed net');
$cashDays=runL($pdo,$q['cashDaily'],'2026-11-22','2026-11-24');
checkL(count($cashDays)===2 && $cashDays[0]['business_date']==='2026-11-23'&&$cashDays[1]['business_date']==='2026-11-24','two independent cash days');
$bt=runL($pdo,$q['businessTotals'],'2026-11-23','2026-11-24');$sum=[];foreach($bt as $r)$sum[$r['tender_type']]=(int)$r['net_amount'];
checkL(($sum['cash']??0)===300&&($sum['pos']??0)===70&&($sum['bank_transfer']??0)===200,'cash POS transfer separated, reversal allocated to original tender');
$bd=runL($pdo,$q['businessDaily'],'2026-11-23','2026-11-24');
checkL(count($bd)===4,'payment daily tender breakdown includes reversal date');
$cd=runL($pdo,$q['civilDaily'],'2026-11-23','2026-11-24');
checkL(count($cd)===3,'civil-day tender view groups records by their own civil date');
$cross=runL($pdo,$q['crossDay'],'2026-11-23','2026-11-24');
checkL(count($cross)===2 && array_sum(array_map(static fn($r)=>(int)$r['net_amount'],$cross))===470,'cross-day receipts exposed when civil and business dates differ');
// A repeated run must not change any accounting data (all tested queries are read-only).
checkL((int)$pdo->query('SELECT COUNT(*) FROM settlement_records')->fetchColumn()===3,'read-only report did not mutate receipts');
$neg=runL($pdo,$q['summary'],'2026-11-26','2026-11-27')[0];
checkL((int)$neg['net_sales']===0,'empty date range has explicit zero');
$pdo->exec("DROP DATABASE `$dbname`");
echo 'PASS27_REAL_MARIADB_REPORT_SQL_PASS_21_CHECKS_QA_ONLY'."\n";