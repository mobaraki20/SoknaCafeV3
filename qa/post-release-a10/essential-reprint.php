<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('SOKNA_QA_DISPOSABLE')!=='YES'||getenv('QA_DB_PASSWORD')!=='synthetic-cp116-ci-only')exit(2);
$root=realpath($argv[1]);if(!$root||basename($root)!=='local')exit(2);require $root.'/bootstrap.php';
$cfg=require dirname($root).'/config.php';if(($cfg['db']['host']??'')!=='127.0.0.1'||($cfg['db']['name']??'')!=='sokna_cp150'||($cfg['db']['pass']??'')!==getenv('QA_DB_PASSWORD'))exit(2);
$c=sokna_local_bootstrap($cfg);$p=$c->database();$f=json_decode(file_get_contents(dirname($root).'/cp144-fixture.json'),true,64,JSON_THROW_ON_ERROR);$actor=$p->query("SELECT id,role FROM users WHERE username='qa-target'")->fetch(PDO::FETCH_ASSOC);$s=$c->settlements();$clock=$c->businessClock()->assignment();$item=(int)$f['tax_item'];
function ck($x,$n){if(!$x)throw new RuntimeException($n);echo "PASS $n\n";}
function one(PDO $p,string $q,array $a=[]){$s=$p->prepare($q);$s->execute($a);return $s->fetchColumn();}
function rows(PDO $p,string $q,array $a=[]){$s=$p->prepare($q);$s->execute($a);return $s->fetchAll(PDO::FETCH_ASSOC);}
function reject(callable $fn,string $code){try{$fn();}catch(Throwable $e){ck(($e->errorCode??'')===$code,'rejected '.$code);return;}throw new RuntimeException('unexpected success '.$code);}
function insertOrder(PDO $p,array $clock,int $table,int $session,int $item,int $seq,int $qty=1,string $station='hot_bar',string $status='accounted'):int{
 $p->prepare("INSERT INTO orders(public_code,client_token,table_id,session_id,order_source,status,total_amount,business_order_number,business_date,business_shift_key,business_shift_label,business_cutoff_snapshot) VALUES(?,?,?,?,'staff',?,?,?,?,?,?,?)")->execute(['qa-fp2-'.$seq,'qa-fp2-'.$seq,$table,$session,$status,100000*$qty,$seq,$clock['business_date'],$clock['shift_key'],$clock['shift_label'],$clock['cutoff']]);$oid=(int)$p->lastInsertId();$p->prepare('INSERT INTO order_items(order_id,item_id,item_name,unit_price,quantity,ordered_quantity,preparation_station,line_total) VALUES(?,?,?,?,?,?,?,?)')->execute([$oid,$item,'QA-FP2-'.$seq,100000,$qty,$qty,$station,100000*$qty]);return $oid;
}
function createSession(PDO $p,array $clock,int $seq):array{$p->prepare('INSERT INTO cafe_tables(name,table_number,code,access_token) VALUES(?,?,?,?)')->execute(['QA table '.$seq,$seq,'qa-fp2-'.$seq,'qa-fp2-t-'.$seq]);$t=(int)$p->lastInsertId();$p->prepare("INSERT INTO table_sessions(public_token,table_id,status,live_table_guard,business_date,business_shift_key,business_shift_label,business_cutoff_snapshot) VALUES(?,?,'active',?,?,?,?,?)")->execute(['qa-fp2-s-'.$seq,$t,$t,$clock['business_date'],$clock['shift_key'],$clock['shift_label'],$clock['cutoff']]);return [$t,(int)$p->lastInsertId()];}


$st=$p->prepare('INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
$st->execute(['printing.quick.auto_preparation','0']);
[$t,$sid]=createSession($p,$clock,681);$oid=insertOrder($p,$clock,$t,$sid,$item,26801,2);
$before=(int)one($p,"SELECT COUNT(*) FROM print_jobs");
$args=['order_id'=>$oid,'scope'=>'round','request_id'=>'qa-a21-manual-reprint'];
$r=$c->orderStaffActions()->reprintPreparation($args,$actor);
ck(($r['success']??false)&&((int)($r['job_id']??0)>0),'manual mode permits actual requested reprint job');
ck((int)one($p,"SELECT COUNT(*) FROM print_jobs WHERE id=?",[(int)$r['job_id']])===1,'actual job row');
ck((int)one($p,"SELECT COUNT(*) FROM print_jobs")===$before+1,'exactly one requested job');
$rr=$c->orderStaffActions()->reprintPreparation($args,$actor);
ck((int)($rr['job_id']??0)===(int)$r['job_id']&&(int)one($p,"SELECT COUNT(*) FROM print_jobs")===$before+1,'same request replay does not duplicate');
ck(one($p,"SELECT setting_value FROM settings WHERE setting_key='printing.quick.auto_preparation'")==='0','manual preference retained');
echo "GROUP PASS A21-13 manual reprint queue; physical output not tested\n";
