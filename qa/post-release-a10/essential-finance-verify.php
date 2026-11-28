<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('SOKNA_QA_DISPOSABLE')!=='YES'||getenv('QA_DB_PASSWORD')!=='synthetic-cp116-ci-only')exit(2);
$w=realpath($argv[1]);require $w.'/local/bootstrap.php';$cfg=require $w.'/config.php';
if(($cfg['db']['host']??'')!=='127.0.0.1'||($cfg['db']['name']??'')!=='sokna_cp150')exit(2);
$p=sokna_local_bootstrap($cfg)->database();
if($argv[2]==='seed'){
 if((int)$p->query('SELECT COUNT(*) FROM tax_rate_versions')->fetchColumn()===0)$p->exec("INSERT INTO tax_rate_versions(rate_bps,effective_from) VALUES(500,'2020-01-01 00:00:00')");
 exit;
}
$rows=json_decode(file_get_contents($w.'/receipts/accepted-ui.json'),true,512,JSON_THROW_ON_ERROR);
if(count($rows)!==7)throw new RuntimeException('missing UI mutation receipts '.count($rows));
foreach($rows as $v){$kind=$v['kind'];$table=match($kind){'tax_rate'=>'tax_rate_versions','tax_policy'=>'tax_item_policy_versions','expense_create'=>'expenses'};$q=$p->prepare('SELECT * FROM '.$table.' WHERE id=?');$q->execute([$v['id']]);$r=$q->fetch(PDO::FETCH_ASSOC);
 $time=$kind==='expense_create'?'occurred_at':'effective_from';$value=match($kind){'tax_rate'=>'rate_bps','tax_policy'=>'custom_rate_bps','expense_create'=>'amount'};
 if(!$r||$r[$time]!==$v['expected']||(int)$r[$value]!==(int)($v['rate_bps']??$v['amount']))throw new RuntimeException('UI receipt DB mismatch '.$kind);
 echo 'PASS actual UI saved database row '.$kind.' '.$v['expected'].PHP_EOL;
}
