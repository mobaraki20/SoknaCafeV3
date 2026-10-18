<?php
declare(strict_types=1);
require_once __DIR__.'/../source/PdoMariaDbLabReleaseJournalV2.php';
final class FakeNoDbPDO extends PDO {
  public function __construct(){} 
  public function getAttribute(int $attribute):mixed{return 'sqlite';}
}
final class FakeDriverOnlyPDO extends PDO {
  public function __construct(){}
  public function getAttribute(int $attribute):mixed{return 'mysql';}
  public function query(string $query, ?int $fetchMode=null, mixed ...$fetchModeArgs): PDOStatement|false {throw new RuntimeException('DB_NOT_CONNECTED');}
}
function refuses(callable $f):bool{try {$f();return false;}catch(Throwable $e){return true;}}
$cases=[];function testcase(string $name,bool $pass):void {global $cases;$cases[]=['name'=>$name,'pass'=>$pass];if(!$pass)fwrite(STDERR,"FAIL $name\n");}
$id=str_repeat('a',32);$db='sokna_ha2_lab_a1234567';
testcase('fixture opt-in missing denied',refuses(fn()=>new PdoMariaDbLabReleaseJournalV2(new FakeNoDbPDO(),$id,$db,false)));
testcase('wrong id denied',refuses(fn()=>new PdoMariaDbLabReleaseJournalV2(new FakeNoDbPDO(),'x',$db,true)));
testcase('wrong database name denied',refuses(fn()=>new PdoMariaDbLabReleaseJournalV2(new FakeNoDbPDO(),$id,'sokna_prod',true)));
testcase('database SQL injection denied',refuses(fn()=>new PdoMariaDbLabReleaseJournalV2(new FakeNoDbPDO(),$id,$db.';DROP DATABASE',true)));
testcase('uppercase DB name denied',refuses(fn()=>new PdoMariaDbLabReleaseJournalV2(new FakeNoDbPDO(),$id,strtoupper($db),true)));
testcase('SQLite/native non-mysql driver denied',refuses(fn()=>new PdoMariaDbLabReleaseJournalV2(new FakeNoDbPDO(),$id,$db,true)));
testcase('claimed mysql but disconnected refused',refuses(fn()=>new PdoMariaDbLabReleaseJournalV2(new FakeDriverOnlyPDO(),$id,$db,true)));
testcase('interface extends only non-committing operations',class_exists('PdoMariaDbLabReleaseJournalV2')&&is_subclass_of('PdoMariaDbLabReleaseJournalV2','ReleaseJournalPort'));
testcase('no API commit',!method_exists('PdoMariaDbLabReleaseJournalV2','commit'));
testcase('no API activation',!method_exists('PdoMariaDbLabReleaseJournalV2','activate'));
testcase('no API rollback',!method_exists('PdoMariaDbLabReleaseJournalV2','rollback'));
testcase('no API recovery',!method_exists('PdoMariaDbLabReleaseJournalV2','recover'));
$code=file_get_contents(__DIR__.'/../source/PdoMariaDbLabReleaseJournalV2.php');
testcase('InnoDB row-lock on mutate',str_contains($code,'FOR UPDATE'));
testcase('optimistic fence equality',str_contains($code,'AND sequence_no=? AND fence_no=? AND phase=?'));
testcase('audit event count and last and first',str_contains($code,'MIN(sequence_no)')&&str_contains($code,"$".'tot[\'first\']'));
testcase('all result flags fail-closed',str_contains($code,"'execution_authorized'=>false")&&str_contains($code,"'commit_proven'=>false"));
testcase('idempotent stage not success',str_contains($code,'LAB_IDEMPOTENT_STAGED'));
testcase('unknown cannot become idle',!preg_match('/function\s+(?:commit|clear|repair|reset|recover)\s*\(/i',$code));
$sql=file_get_contents(__DIR__.'/schema_lab_v2.sql');
testcase('DB schema InnoDB',substr_count($sql,'ENGINE=InnoDB')===2);
testcase('DB lab table prefix only',substr_count($sql,'CREATE TABLE sokna_ha2_lab_')===2);
testcase('never updates business tables',!preg_match('/\b(?:INSERT|UPDATE|DELETE|DROP|ALTER)\s+(?:INTO|FROM|TABLE)?\s*(?:orders|installations|schema_migrations|customers)\b/i',$code.$sql));
testcase('no hardcoded provider in core',!preg_match('/\b(?:cpanel|directadmin|plesk|vps|ssh)\b/i',$code));
$pass=count(array_filter($cases,fn($i)=>$i['pass']));$total=count($cases);
$report=['stage'=>'F1-HA2B1','suite'=>'SOURCE_NEGATIVE_NO_REAL_DB','passed'=>$pass,'total'=>$total,'status'=>$pass===$total?'PASS':'FAIL','real_mariadb_run'=>false,'cases'=>$cases,'for_install'=>false];
file_put_contents(__DIR__.'/../results/no_db_source_tests.json',json_encode($report,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT));
echo json_encode(['passed'=>$pass,'total'=>$total,'status'=>$report['status'],'real_mariadb_run'=>false])."\n";exit($pass===$total?0:1);
