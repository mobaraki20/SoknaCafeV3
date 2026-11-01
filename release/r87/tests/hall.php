<?php
declare(strict_types=1);
$root=$argv[1];require $root.'/local/bootstrap.php';
$db=(string)(getenv('HALL_DB')?:'r87_hall');$port=(string)(getenv('DB_PORT')?:3306);$password=(string)(getenv('DB_PASSWORD')?:'r87-test');
$cfg=['app'=>['timezone'=>'Asia/Tehran','data_dir'=>sys_get_temp_dir().'/r87-finance-'.bin2hex(random_bytes(4))],'installation'=>['id'=>'r87-test'],'db'=>['host'=>'127.0.0.1','port'=>$port,'name'=>$db,'charset'=>'utf8mb4','user'=>'root','pass'=>$password]];
$core=sokna_local_bootstrap($cfg);$core->migrations()->migrate('0059_menu_business_reconciliation_v4');$core->defaultContentSeeder()->seed();$core->migrations()->migrate();$p=$core->database();
$results=[];function check(bool $pass,string $test):void{global $results;$results[]=['test'=>$test,'pass'=>$pass];}
$p->exec("INSERT INTO users(username,password_hash,display_name,role,active) VALUES('r87', 'test', 'R87', 'admin',1)");$actor=['id'=>(int)$p->lastInsertId(),'role'=>'admin','display_name'=>'R87'];
$p->exec("INSERT INTO categories(category_key,name,audience,sort_order,active) VALUES('r87','R87','guest_staff',10,1)");$cat=(int)$p->lastInsertId();
$item=$core->sellables()->create(['category_id'=>$cat,'name'=>'R87 test coffee','price'=>1000,'preparation_station'=>'bar','sellable_kind'=>'menu_item','staff_only'=>false,'takeaway_allowed'=>true]);
$menu=(int)$p->query("SELECT id FROM menus WHERE status='active' ORDER BY id LIMIT 1")->fetchColumn();$p->prepare('INSERT INTO menu_categories(menu_id,category_id,sort_order) VALUES(?,?,900)')->execute([$menu,$cat]);$p->prepare('INSERT INTO menu_items(menu_id,item_id) VALUES(?,?)')->execute([$menu,$item]);
// Real writer and instrumented independent read connection, same isolated DB.
class R87Statement extends PDOStatement {
 public static ?Closure $hook=null;public static string $after='';public static bool $fail=false;public static array $sql=[];
 protected function __construct(){}
 public function execute(?array $params=null):bool{self::$sql[]=$this->queryString;if(self::$fail&&str_contains($this->queryString,'SELECT sl.order_item_id,SUM(sl.quantity)'))throw new RuntimeException('R87 injected account projection failure');return parent::execute($params);}
 public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array{$r=parent::fetchAll($mode,...$args);self::$sql[]=$this->queryString;if(self::$hook!==null&&str_contains($this->queryString,self::$after)){$f=self::$hook;self::$hook=null;$f();}return $r;}
}
$reader=new PDO('mysql:host=127.0.0.1;port='.$port.';dbname='.$db.';charset=utf8mb4','root',$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_STATEMENT_CLASS=>[R87Statement::class,[]]]);$reader->exec("SET time_zone='+03:30'");
$readCore=sokna_local_bootstrap($cfg);(new ReflectionProperty($readCore,'database'))->setValue($readCore,$reader);
$builder=$readCore->publicProjectionBuilder();$n=0;
$create=function(int $qty)use($core,$p,$actor,$item,&$n):array{
 $i=++$n;$p->prepare('INSERT INTO cafe_tables(name,table_number,code,access_token,active,sort_order) VALUES(?,?,?,?,1,?)')->execute(['R87 '.$i,870+$i,'R87-'.$i,'r87-table-'.$i,$i]);$t=(int)$p->lastInsertId();
 $o=$core->staffQuickOrders()->commit(['table_id'=>$t,'expected_session_id'=>0,'request_token'=>'r87-order-'.$i.'-0000000000000','items'=>[['id'=>$item,'quantity'=>$qty,'expected_price'=>1000]]],$actor);return ['table'=>$t,'session'=>(int)$o['session_id']];
};
$settle=function(array $t,bool $partial=false,?array $expected=null)use($core,$actor,&$n):array{
 $a=$expected??$core->settlements()->account($t['session']);$i=++$n;
 return $core->settlements()->settle(['session_id'=>$t['session'],'destination'=>'direct','mode'=>$partial?'itemized':'full','selection'=>[['order_item_id'=>(int)$a['items'][0]['id'],'quantity'=>1]],'tenders'=>[['type'=>'cash','amount'=>$partial?1000:$a['remaining_total']]],'request_id'=>'r87-payment-'.$i.'-000000000000','expected_session_id'=>$t['session'],'expected_remaining_total'=>$a['remaining_total'],'expected_signature'=>$a['signature'],'print_receipt'=>false],$actor);
};
$read=fn():array=>$builder->remoteModelsFor(['operations'])[0];
$account=function(array $model,int $sid):?array{foreach($model['payload']['settlement_accounts'] as $a)if($a['session_id']===$sid)return $a;return null;};
$table=function(array $model,int $tid):?array{foreach($model['payload']['hall']['tables'] as $t)if($t['id']===$tid)return $t;return null;};
$tablesQuery='SELECT t.id,t.name,t.table_number,t.sort_order,t.zone_label';
$idsQuery="SELECT s.id FROM table_sessions s WHERE s.status IN";
$t=$create(2);$old=$core->settlements()->account($t['session']);$committed=false;
R87Statement::$after=$tablesQuery;R87Statement::$hook=function()use($settle,$t,&$committed){$settle($t);$committed=true;};$m=$read();
check($committed,'real full settlement commits between Hall table and account reads without blocking writer');
$a=$account($m,$t['session']);check(($table($m,$t['table'])['session_id']??0)===$t['session']&&($a['remaining_total']??null)===2000&&($a['signature']??null)===$old['signature'],'full settlement cannot mix old occupied table with new closed or absent account');
check(($m['payload']['hall']['accounts'][(string)$t['table']]['table']['session_id']??0)===$t['session'],'Hall table and detailed account share the same session snapshot');
$next=$read();check(($table($next,$t['table'])['session_id']??0)===0&&$account($next,$t['session'])===null,'next model consistently sees settled table free and finance account removed');
check(!$reader->inTransaction(),'builder closes its own read snapshot');
$t=$create(3);$at=(int)$p->query('SELECT COALESCE(MAX(sequence_id),0) FROM public_projection_outbox')->fetchColumn();
R87Statement::$after=$tablesQuery;R87Statement::$hook=function()use($core,$t,$actor,$item,&$n){$core->staffQuickOrders()->commit(['table_id'=>$t['table'],'expected_session_id'=>$t['session'],'request_token'=>'r87-added-'.(++$n).'-000000000000','items'=>[['id'=>$item,'quantity'=>1,'expected_price'=>1000]]],$actor);};$m=$read();
check(($table($m,$t['table'])['account_total']??null)===3000&&($account($m,$t['session'])['account']['total']??null)===3000,'new order cannot split Hall total and canonical settlement account');
$next=$read();check(($table($next,$t['table'])['account_total']??null)===4000&&($account($next,$t['session'])['remaining_total']??null)===4000,'next projection sees the newly committed order');
check((int)$p->query('SELECT COALESCE(MAX(sequence_id),0) FROM public_projection_outbox')->fetchColumn()>$at,'concurrent order retains its durable projection invalidation');
$old=$core->settlements()->account($t['session']);R87Statement::$after=$idsQuery;R87Statement::$hook=function()use($settle,$t){$settle($t,true);};$m=$read();$a=$account($m,$t['session']);
check(($a['remaining_total']??null)===4000&&($a['signature']??null)===$old['signature']&&($a['account']['paid_total']??null)===0,'partial payment between ID selection and account read keeps prior remaining amount and signature coherent');
$next=$read();$a=$account($next,$t['session']);check(($a['remaining_total']??null)===3000&&($a['account']['paid_total']??null)===1000&&($a['signature']??null)!==$old['signature'],'next snapshot sees the partial receipt and updated signature');
$count=(int)$p->query('SELECT COUNT(*) FROM settlement_records')->fetchColumn();$blocked=false;try{$settle($t,false,$old);}catch(Sokna\Local\Domain\Finance\SettlementStateConflict){$blocked=true;}
check($blocked&&(int)$p->query('SELECT COUNT(*) FROM settlement_records')->fetchColumn()===$count,'mutation revalidates stale projected signature and never creates duplicate financial receipt');
$t2=$create(1);$error=null;$m=null;R87Statement::$after=$idsQuery;R87Statement::$hook=function()use($settle,$t2){$settle($t2);};try{$m=$read();}catch(Throwable $e){$error=$e->getMessage();}
check($error===null&&is_array($m)&&($account($m,$t2['session'])['remaining_total']??null)===1000,'account closing after finance ID selection does not fail or truncate the model');
$next=$read();check($account($next,$t2['session'])===null,'closed account disappears on following coherent snapshot');
// Projection read reuses the same formulas but never acquires mutation locks.
$can=method_exists($readCore->settlements(),'projectionAccountTx');$blocked=false;
if($can){try{$readCore->settlements()->projectionAccountTx($t['session']);}catch(LogicException){$blocked=true;}}
check($blocked,'projection account refuses execution outside a caller snapshot');
$reference=$core->settlements()->account($t['session']);$reader->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$reader->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');R87Statement::$sql=[];$view=null;
if($can)$view=$readCore->settlements()->projectionAccountTx($t['session']);
check($view!==null&&$view['signature']===$reference['signature']&&$view['remaining_total']===$reference['remaining_total']&&$view['items']===$reference['items'],'projection and locked cashier account use identical formulas, item allocations and signature');
check($can&&!array_filter(R87Statement::$sql,fn($sql)=>str_contains($sql,'FOR UPDATE')),'read-only projection contains no current-read write locks');
$joined=false;try{$m=$read();$joined=$reader->inTransaction()&&($account($m,$t['session'])['remaining_total']??null)===3000;}catch(Throwable){}
check($joined,'operations builder joins caller transaction without nesting or committing it');if($reader->inTransaction())$reader->rollBack();
R87Statement::$sql=[];$readCore->settlements()->account($t['session']);check(count(array_filter(R87Statement::$sql,fn($sql)=>str_contains($sql,'FOR UPDATE')))>=3,'cashier account retains session, order and line write locks');
R87Statement::$fail=true;$failed=false;try{$read();}catch(Throwable){$failed=true;}
check($failed&&!$reader->inTransaction(),'account error aborts whole projection and rolls back builder-owned snapshot');
$reader->beginTransaction();$failed=false;try{$read();}catch(Throwable){$failed=true;}
check($failed&&$reader->inTransaction(),'projection failure never rolls back caller-owned transaction');if($reader->inTransaction())$reader->rollBack();R87Statement::$fail=false;
$m=$read();check($m['source_version']===Sokna\Local\Domain\PublicEdge\PublicProjectionBuilder::hash($m['payload']),'canonical model hash covers complete coherent operations payload');
check(is_array($account($m,$t['session']))&&!$reader->inTransaction(),'complete model recovers after injected failure with no stranded transaction');
// Use the actual publisher cache and actual Public MariaDB model receiver.
$t=$create(2); // normal new orders require a session without an itemized receipt.
$publisher=$readCore->publicEdgePublisher();$reflection=new ReflectionClass($publisher);
$prepare=$reflection->getMethod('preparedProjectionModel');$record=$reflection->getMethod('recordProjectionModelSuccess');$retire=$reflection->getMethod('markFullyAckedProjectionRows');
$core->publicEdgePublisher()->seedProjectionInvalidations(['operations'],'r87.test.snapshot');
$sequence=(int)$publisher->captureVisibilityRequirements(['operations'])[0]['min_sequence'];
$before=$core->settlements()->account($t['session']);$beforeTotal=$before['total'];R87Statement::$after=$tablesQuery;
R87Statement::$hook=function()use($core,$t,$actor,$item,&$n){$core->staffQuickOrders()->commit(['table_id'=>$t['table'],'expected_session_id'=>$t['session'],'request_token'=>'r87-cache-added-'.(++$n).'-000000000000','items'=>[['id'=>$item,'quantity'=>1,'expected_price'=>1000]]],$actor);};
$cached=$prepare->invoke($publisher,'operations',$sequence);$newSequence=(int)$publisher->captureVisibilityRequirements(['operations'])[0]['min_sequence'];
check($newSequence>$sequence&&($account($cached,$t['session'])['account']['total']??null)===$beforeTotal&&($table($cached,$t['table'])['account_total']??null)===$beforeTotal,'prepared publisher model binds old cursor to one coherent snapshot while newer invalidation remains');
$replay=$prepare->invoke($publisher,'operations',$sequence);check($replay===$cached,'retry of same cursor reuses identical cached model bytes and hash');
$record->invoke($publisher,'operations',$sequence,$cached['source_version']);$retire->invoke($publisher,1000);
$q=$p->prepare('SELECT delivered_at FROM public_projection_outbox WHERE sequence_id=?');$q->execute([$newSequence]);check($q->fetchColumn()===null,'old model ACK cannot retire the concurrent newer outbox event');
R87Statement::$fail=true;$failed=false;try{$prepare->invoke($publisher,'operations',$newSequence);}catch(Throwable){$failed=true;}R87Statement::$fail=false;
check($failed&&$prepare->invoke($publisher,'operations',$sequence)===$cached,'failed new snapshot preserves previous cached model and cursor for safe retry');
$fresh=$prepare->invoke($publisher,'operations',$newSequence);check(($account($fresh,$t['session'])['account']['total']??null)===$beforeTotal+1000&&$fresh['source_version']!==$cached['source_version'],'next cursor rebuilds complete model including concurrent committed order');
spl_autoload_register(function(string $class)use($root):void{$prefix='Sokna\\PublicEdge\\';if(str_starts_with($class,$prefix)){$path=$root.'/public/src/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';if(is_file($path))require_once $path;}});
$pub=new PDO('mysql:host=127.0.0.1;port='.$port.';dbname='.$db.'_public;charset=utf8mb4','root',$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
(new Sokna\PublicEdge\Core\Migrations($pub,$root.'/public/database/migrations'))->migrate();$pub->exec("INSERT INTO installations(installation_id,display_name) VALUES('r87-test','R87')");
$receiver=new Sokna\PublicEdge\Remote\RemoteReadModelService($pub,new Sokna\PublicEdge\Connectivity\ConnectivityService($pub));
$valid=$reflection->getMethod('validProjectionModelAck');
foreach([[$sequence,$cached],[$newSequence,$fresh]] as [$seq,$model]){$response=$receiver->syncModelV2('r87-test',['format'=>'sokna-read-model-v2','up_to_sequence'=>$seq,'model'=>$model]);check($response['status']===200&&$valid->invoke(null,$response['body'],'operations',$seq,$model['source_version']),'actual Public receiver returns exact coherent model cursor/hash ACK '.$seq);}
$response=$receiver->syncModelV2('r87-test',['format'=>'sokna-read-model-v2','up_to_sequence'=>$sequence,'model'=>$cached]);check($response['status']===409,'late older operations delivery cannot replace newer Public model');
echo json_encode($results,JSON_PRETTY_PRINT).PHP_EOL;
exit(count(array_filter($results,fn($r)=>!$r['pass']))?1:0);
