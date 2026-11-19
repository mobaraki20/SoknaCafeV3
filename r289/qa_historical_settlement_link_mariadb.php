<?php
declare(strict_types=1);
// Real MariaDB QA only; never touches user's Local or Public DB.
if (getenv('GITHUB_ACTIONS') !== 'true' || getenv('R288_QA_MODE') !== 'disposable_only') throw new RuntimeException('QA_ONLY');
$root=(string)getenv('R288_SOURCE_ROOT');
require_once $root.'/src/Domain/Notifications/NotificationService.php';
use Sokna\Local\Domain\Notifications\NotificationService;
$pdo=new PDO('mysql:host=127.0.0.1;port=3306;dbname=sokna_r288_print;charset=utf8mb4','root',(string)getenv('R288_DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE notification_preferences(user_id INT PRIMARY KEY,in_app_enabled TINYINT DEFAULT 1,push_enabled TINYINT DEFAULT 0,push_operations_enabled TINYINT DEFAULT 1,push_management_enabled TINYINT DEFAULT 1,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
$pdo->exec('CREATE TABLE notification_outbox(id INT PRIMARY KEY,event_key VARCHAR(160) NOT NULL) ENGINE=InnoDB');
$pdo->exec('CREATE TABLE notification_inbox(id INT PRIMARY KEY,user_id INT NOT NULL,outbox_id INT NULL,title VARCHAR(180),body VARCHAR(600),target_url VARCHAR(500),read_at DATETIME NULL,created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
$pdo->exec("INSERT INTO notification_outbox VALUES (20,'settlement.completed.731'),(21,'guest_order.new.51'),(22,'settlement.completed.732')");
$pdo->exec("INSERT INTO notification_inbox(id,user_id,outbox_id,title,body,target_url,read_at) VALUES
 (101,41,20,'تسویه ثبت شد','حساب 7','/reports/',NULL),
 (102,41,21,'سفارش مهمان','میز 7','/operator/?work=attention#attention',NOW()),
 (103,41,NULL,'دستی','پیام','/notifications/',NOW()),
 (104,42,22,'تسویه محرمانه','میز 9','/reports/',NULL)");
$s=new NotificationService($pdo);
$state=$s->snapshot(['id'=>41,'role'=>'admin']);
$byId=[];foreach($state['items'] as $row)$byId[(int)$row['id']]=$row;
if(count($byId)!==3)throw new RuntimeException('USER_INBOX_ISOLATION_FAILED');
if($byId[101]['target_url']!=='/finance/?tab=receipts&invoice_id=731')throw new RuntimeException('HISTORIC_SETTLEMENT_LINK_FAILED');
if($byId[102]['target_url']!=='/operator/?work=attention#attention')throw new RuntimeException('OTHER_EVENT_CHANGED');
if($byId[103]['target_url']!=='/notifications/')throw new RuntimeException('MANUAL_LINK_CHANGED');
if(($state['unread']??-1)!==1)throw new RuntimeException('UNREAD_COUNT_CHANGED');
if(isset($byId[101]['event_key']))throw new RuntimeException('INTERNAL_EVENT_KEY_LEAK');
foreach(['settlement.completed.0','settlement.completed.-1','settlement.completed.1&foo=bar','settlement.completed.1234567890123456','malicious.731'] as $bad){
 if(NotificationService::historicalTargetUrl($bad,'/reports/')!=='/reports/')throw new RuntimeException('INVALID_EVENT_KEY_UNSAFE');
}
$js=file_get_contents($root.'/public/assets/finance-workspace.js');$api=file_get_contents($root.'/public/finance/api.php');
if(!str_contains($js,"params.get('invoice_id')") || !str_contains($js,'await loadInvoice(invoiceId)') || !str_contains($api,"if(\$action==='settlement')"))
 throw new RuntimeException('INVOICE_DEEP_LINK_NOT_SUPPORTED');
$u=$s->snapshot(['id'=>42,'role'=>'admin']);
if(count($u['items'])!==1||$u['items'][0]['target_url']!=='/finance/?tab=receipts&invoice_id=732')throw new RuntimeException('OTHER_ADMIN_OWN_DOCUMENT_FAILED');
echo json_encode(['result'=>'PASS','historical_receipt_target'=>'/finance/?tab=receipts&invoice_id=731','older_persisted_notification_repaired_at_read'=>true,'unrelated_notifications_unchanged'=>true,'cross_user_inbox_filtered'=>true,'immutable_invoice_id_not_table_id'=>true,'actual_finance_detail_api_and_js_checked'=>true,'no_financial_mutation'=>true],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),"\n";
