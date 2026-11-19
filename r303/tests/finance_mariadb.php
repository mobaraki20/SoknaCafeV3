<?php
declare(strict_types=1);
// R303 disposable MariaDB only; not a production connection.
if (getenv('GITHUB_ACTIONS') !== 'true' || getenv('R303_DB_DISPOSABLE') !== 'yes') throw new RuntimeException('DISPOSABLE_QA_ONLY');
$root = rtrim((string)getenv('R303_LOCAL_SOURCE'), '/');
$host = (string)(getenv('R303_DB_HOST') ?: '127.0.0.1');
$pass = (string)(getenv('R303_DB_PASSWORD') ?: '');
$db = new PDO("mysql:host={$host};port=3306;charset=utf8mb4", 'root', $pass, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE DATABASE sokna_r303_disposable CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$db->exec('USE sokna_r303_disposable');
$db->exec('CREATE TABLE items(id BIGINT PRIMARY KEY,name VARCHAR(255),item_code VARCHAR(100),active TINYINT)');
$db->exec('CREATE TABLE table_sessions(id BIGINT PRIMARY KEY,status VARCHAR(30))');
$db->exec('CREATE TABLE settlement_records(id BIGINT PRIMARY KEY,session_id BIGINT,business_date DATE,status VARCHAR(40),closes_session TINYINT,total BIGINT)');
$db->exec('CREATE TABLE settlement_record_lines(settlement_id BIGINT,item_id_snapshot BIGINT,quantity BIGINT,gross_amount BIGINT,discount_amount BIGINT,final_amount BIGINT)');
$db->exec("INSERT INTO items VALUES (11,'چای','A-11',1),(12,'قهوه','B-12',1),(13,'دسر','C-13',1)");
$db->exec("INSERT INTO table_sessions VALUES (8,'closed'),(9,'open')");
$db->exec("INSERT INTO settlement_records VALUES
 (1,8,'2026-11-19','completed',0,300000),
 (2,8,'2026-11-19','completed',0,400000),
 (3,8,'2026-11-19','completed',1,500000),
 (4,8,'2026-11-19','reversal',0,100000),
 (5,9,'2026-11-19','completed',0,200000)");
$db->exec("INSERT INTO settlement_record_lines VALUES
 (1,11,1,300000,0,300000),
 (2,11,1,400000,0,400000),
 (3,12,2,600000,100000,500000),
 (4,11,1,100000,0,100000),
 (5,11,1,200000,0,200000)");
require_once $root.'/src/Core/TimeAuthority.php';
require_once $root.'/src/Domain/Reporting/ReportingException.php';
require_once $root.'/src/Domain/Reporting/ReportingService.php';
$svc = new \Sokna\Local\Domain\Reporting\ReportingService($db,new \Sokna\Local\Core\TimeAuthority());
function need($p, $name):void { if(!$p)throw new RuntimeException('R303_FINANCE_'.$name); }
$items=$svc->itemSalesReport('2026-11-19','2026-11-19',[11,12,13]);
$idx=[]; foreach($items['rows'] as $r)$idx[$r['item_id']]=$r;
need(count($items['rows'])===3,'THREE_SELECTED_ROWS');
need($idx[11]['net_sales']===800000 && $idx[11]['quantity']===2,'TEA_NET_REVERSAL');
need($idx[12]['net_sales']===500000 && $idx[12]['quantity']===2,'COFFEE_NET_DISCOUNT');
need($idx[13]['net_sales']===0,'ZERO_SELLER');
need($items['totals']['net_sales']===1300000,'NET_TOTAL');
need(count($svc->itemSalesLookup('چای'))===1,'UNICODE_LOOKUP');
$invalid=false;try{$svc->itemSalesReport('2026-11-19','2026-11-19',[]);}catch(\Sokna\Local\Domain\Reporting\ReportingException $e){$invalid=$e->errorCode==='invalid_item_ids';}need($invalid,'EMPTY_SELECTION_REJECTED');
// Actual invoice SQL from the immutable production service, not a hand-written replacement.
$source=file_get_contents($root.'/src/Domain/Reporting/ReportingService.php');
$matched=preg_match('/\$invoiceAggregate=\$this->one\("(SELECT.*?)"\s*,\s*\$p\);/s',$source,$match);
need($matched===1,'AGGREGATE_SOURCE_NOT_FOUND');
$query=$db->prepare($match[1]);$query->execute(['2026-11-19','2026-11-19']);$sum=$query->fetch(PDO::FETCH_ASSOC);
need((int)$sum['invoice_count']===1,'ONE_INVOICE_NOT_THREE');
need((int)$sum['avg_ticket']===1100000,'FULL_SESSION_NET_AVERAGE');
echo json_encode(['status'=>'PASS','test'=>'R303 MariaDB 11.4 selected products and partial payment invoices','invoice_count'=>1,'avg_ticket'=>1100000,'net_items'=>1300000,'database'=>'sokna_r303_disposable','production_modified'=>false],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).'\n';
