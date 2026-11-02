<?php
declare(strict_types=1);
require __DIR__.'/../overlays/local/src/Domain/System/SystemDiagnosticsService.php';
require dirname(__DIR__,3).'/apps/local-web/src/Domain/Orders/BusinessClock.php';
use Sokna\Local\Domain\System\SystemDiagnosticsService;
use Sokna\Local\Domain\Orders\BusinessClock;
final class SalesSupportFixturePdo extends PDO {
    public array $rows=[];public bool $fail=false;public int $queries=0;
    public function __construct(){}
    public function prepare(string $q,array $options=[]):PDOStatement|false{return new SalesSupportFixtureStmt($this,$q);}
}
final class SalesSupportFixtureStmt extends PDOStatement {
    public function __construct(private SalesSupportFixturePdo $p,private string $sql){}
    public function execute(?array $params=null):bool{if(str_contains($this->sql,'FROM settlement_records'))$this->p->queries++;return !$this->p->fail;}
    public function fetchColumn(int $column=0):mixed{return str_contains($this->sql,'FROM settings')?'04:00':false;}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,...$args):array{return str_contains($this->sql,'FROM settlement_records')?$this->p->rows:[];}
}
function evidence(SalesSupportFixturePdo $pdo,bool $clock=true):array {
    $rc=new ReflectionClass(SystemDiagnosticsService::class);$o=$rc->newInstanceWithoutConstructor();
    $rc->getProperty('pdo')->setValue($o,$pdo);
    $rc->getProperty('timeAuthority')->setValue($o,null);
    $rc->getProperty('businessClock')->setValue($o,$clock?new BusinessClock($pdo):null);
    return $rc->getMethod('salesSupportEvidence')->invoke($o);
}
$n=0;function qa(bool $ok,string $msg):void{global $n;$n++;if(!$ok)throw new RuntimeException($msg);}
$pdo=new SalesSupportFixturePdo;
$r=evidence($pdo);
qa($r['status']==='complete','empty successful ledger');
qa(count($r['daily'])===14,'bounded 14 days');
qa($pdo->queries===1,'one aggregate query');
qa(array_sum(array_column($r['daily'],'net_sales'))===0,'confirmed zero');
qa($r['coverage']==='complete_local_ledger_range','confirmed coverage');
qa($r['business_cutoff']==='04:00','business clock');
qa(!isset($r['error_code']),'no false error');
$today=$r['range_to'];$prev=(new DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');
$pdo->rows=[
 ['business_date'=>$prev,'net_sales'=>'-20000','settlement_count'=>'2','completed_settlements'=>'1','reversal_count'=>'1'],
 ['business_date'=>$today,'net_sales'=>'100000','settlement_count'=>'4','completed_settlements'=>'4','reversal_count'=>'0'],
];
$r=evidence($pdo);
qa($r['daily'][13]['net_sales']===100000,'positive amount');
qa($r['daily'][12]['net_sales']===-20000,'reversal');
qa($r['daily'][0]['net_sales']===0&&$r['daily'][0]['settlement_count']===0,'zero no-record day');
qa(!str_contains(json_encode($r),'invoice_number'),'no invoice details');
qa(!str_contains(json_encode($r),'customer'),'no customer details');
$pdo->rows=[['business_date'=>$today,'net_sales'=>'1000'],['business_date'=>$today,'net_sales'=>'2000']];
$r=evidence($pdo);qa($r['status']==='unavailable'&&$r['daily']===[],'duplicate day');
$pdo->rows=[['business_date'=>$today,'net_sales'=>'NaN']];
$r=evidence($pdo);qa($r['status']==='unavailable'&&$r['daily']===[],'invalid amount');
$pdo->rows=[['business_date'=>'2020-01-01','net_sales'=>'1000']];
$r=evidence($pdo);qa($r['status']==='unavailable'&&$r['daily']===[],'out-of-range row');
$pdo->rows=[];$pdo->fail=true;$r=evidence($pdo);
qa($r['status']==='unavailable'&&$r['daily']===[]&&isset($r['error_code']),'failed query never zero');
$pdo->fail=false;$r=evidence($pdo,false);qa($r['status']==='unavailable'&&$r['daily']===[],'missing business clock');
$code=file_get_contents(__DIR__.'/../overlays/local/src/Domain/System/SystemDiagnosticsService.php');
qa(str_contains($code,'$snapshot['."'sales_reconciliation'".']=$this->salesSupportEvidence();'),'bundled evidence field');
qa(substr_count($code,'$this->salesSupportEvidence()')===1,'no extra health cost');
echo 'PASS '.$n.' support evidence assertions'.PHP_EOL;
