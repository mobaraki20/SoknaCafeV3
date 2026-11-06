<?php
declare(strict_types=1);
$base='/tmp/sokna-r115-exact/local';
spl_autoload_register(static function(string $class) use($base):void {
    foreach(['Sokna\\Local\\'=>'/src/','Sokna\\Shared\\'=>'/src/Shared/'] as $prefix=>$segment){
        if(!str_starts_with($class,$prefix))continue;
        $path=$base.$segment.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
        if(is_file($path))require_once $path;
    }
});
require_once __DIR__.'/FinanceProjectionAudit.php';
$pdo=new PDO('mysql:host=127.0.0.1;port=3306;dbname=sokna_r115_full;charset=utf8mb4','sokna','sokna',[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES=>false,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
]);
$pdo->exec("INSERT INTO cafe_tables(name,table_number,code,access_token,active) VALUES ('Finance audit QA 1',997,'qa-finance-997','qa-finance-token-997',1),('Finance audit QA 2',998,'qa-finance-998','qa-finance-token-998',1)");
$tables=$pdo->query("SELECT id FROM cafe_tables WHERE table_number IN(997,998) ORDER BY table_number")->fetchAll(PDO::FETCH_COLUMN);
$pdo->prepare("INSERT INTO table_sessions(public_token,table_id,status,live_table_guard,business_date,business_shift_key,business_shift_label,business_cutoff_snapshot) VALUES(?,?,'active',?,CURDATE(),'qa','QA','04:00')")
    ->execute(['qa-finance-session-1',$tables[0],$tables[0]]);
$session1=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO table_sessions(public_token,table_id,status,live_table_guard,business_date,business_shift_key,business_shift_label,business_cutoff_snapshot) VALUES(?,?,'active',?,CURDATE(),'qa','QA','04:00')")
    ->execute(['qa-finance-session-2',$tables[1],$tables[1]]);
$session2=(int)$pdo->lastInsertId();
$addOrder=$pdo->prepare("INSERT INTO orders(public_code,client_token,table_id,session_id,order_source,status,total_amount,business_order_number,business_date,business_shift_key,business_shift_label,business_cutoff_snapshot) VALUES(?,?,?,?,'staff','accounted',?,?,CURDATE(),'qa','QA','04:00')");
$addItem=$pdo->prepare("INSERT INTO order_items(order_id,item_name,unit_price,quantity,ordered_quantity,line_total,preparation_station) VALUES(?,'Finance QA coffee',500,2,2,1000,'bar')");
$addOrder->execute(['audit-ok','audit-client-ok',$tables[0],$session1,1000,998101]);$order1=(int)$pdo->lastInsertId();$addItem->execute([$order1]);
$addOrder->execute(['audit-mismatch','audit-client-mismatch',$tables[1],$session2,900,998102]);$order2=(int)$pdo->lastInsertId();$addItem->execute([$order2]);
$ref=new ReflectionClass(Sokna\Local\Domain\Finance\SettlementService::class);
$settlements=$ref->newInstanceWithoutConstructor();
$ref->getProperty('pdo')->setValue($settlements,$pdo);
$audit=new Sokna\Local\Domain\Finance\FinanceProjectionAudit($pdo,$settlements);
$results=$audit->inspect();
$blocked=$results['blocked_sessions']??[];
if($results['status']!=='blocked'||$results['blocked_count']!==1||count($blocked)!==1||$blocked[0]['reason_code']!=='subtotal_mismatch'||$blocked[0]['session_id']!==$session2){
    throw new RuntimeException('Expected account-specific subtotal_mismatch was not detected: '.json_encode($results));
}
if(str_contains(json_encode($results),'Finance QA coffee')||str_contains(json_encode($results),'1000'))throw new RuntimeException('Sensitive item or price leaked');
if($pdo->inTransaction())throw new RuntimeException('Read-only audit left a database transaction open');
$pdo->beginTransaction();$nested=$audit->inspect();$pdo->rollBack();
if($nested['status']!=='not_checked'||$nested['reason']!=='transaction_already_open')throw new RuntimeException('Nested audit did not fail safely');
$pdo->prepare('UPDATE orders SET total_amount=1000 WHERE id=?')->execute([$order2]);
$clean=$audit->inspect();
if($clean['status']!=='checked'||$clean['blocked_count']!==0)throw new RuntimeException('Audit still reports a corrected account: '.json_encode($clean));
$truncated=$audit->inspect(1);
if($truncated['status']!=='partial_checked'||!$truncated['truncated'])throw new RuntimeException('Bounded coverage not identified');
echo "PASS finance audit: subtotal_mismatch isolated; no private values; transaction safe; clean state; bounded status\n";