<?php
declare(strict_types=1);
/** Source-derived finance display smoke test; disposable MariaDB only. */
$root=(string)getenv('R307_LOCAL_SOURCE');
$source=file_get_contents($root.'/public/index.php');
if(!is_string($source))throw new RuntimeException('SOURCE_NOT_FOUND');
function function_source(string $code,string $name): string{
 $needle='function '.$name.'(';$start=strpos($code,$needle);if($start===false)throw new RuntimeException('FUNCTION_MISSING_'.$name);
 $open=strpos($code,'{',$start);$depth=0;
 for($i=$open;$i<strlen($code);$i++){if($code[$i]==='{')$depth++;elseif($code[$i]==='}'&&--$depth===0)return substr($code,$start,$i-$start+1);}
 throw new RuntimeException('UNBALANCED_SOURCE_'.$name);
}
foreach(['home_fa_num','home_money','home_open_money','home_dashboard_rial_mode'] as $name)eval(function_source($source,$name));
function check(bool $ok,string $code): void{if(!$ok)throw new RuntimeException($code);}
$pdo=new PDO('mysql:host='.getenv('R307_DB_HOST').';port=3306', 'root', (string)getenv('R307_DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE DATABASE IF NOT EXISTS sokna_r307_test');$pdo->exec('USE sokna_r307_test');
$pdo->exec('CREATE TABLE IF NOT EXISTS settings(setting_key VARCHAR(128) PRIMARY KEY,setting_value VARCHAR(128))');
$pdo->exec("REPLACE INTO settings VALUES('payment.final_amount_rial','1')");
$core=new class($pdo){public function __construct(private PDO $pdo){}public function database(): PDO{return $this->pdo;}};
check(home_dashboard_rial_mode($core)===true,'RIAL_POLICY_NOT_READ');
check(home_money(2350000,true)==='۲۳٬۵۰۰٬۰۰۰ ریال','RIAL_MULTIPLIER');
check(home_money(850000,true)==='۸٬۵۰۰٬۰۰۰ ریال','RIAL_AVG_TICKET');
check(home_open_money(1025000,true)==='۱۰٬۲۵۰٬۰۰۰ ریال','RIAL_OPEN_ACCOUNTS');
check(home_money(-150000,true)==='-۱٬۵۰۰٬۰۰۰ ریال','RIAL_NEGATIVE');
check(home_money(PHP_INT_MAX,true)==='—','RIAL_OVERFLOW');
$pdo->exec("REPLACE INTO settings VALUES('payment.final_amount_rial','0')");
check(home_dashboard_rial_mode($core)===false,'TOMAN_POLICY_NOT_READ');
check(home_money(2350000,false)==='۲٬۳۵۰٬۰۰۰ تومان','TOMAN_SALES');
check(home_open_money(1025000,false)==='۱٬۰۲۵٬۰۰۰ تومان','TOMAN_OPEN');
check(home_money(2350000,null)==='—','UNKNOWN_POLICY_MUST_NOT_GUESS');
echo "PASS_R307_REAL_MARIADB_RIAL_TOMAN_NO_DOUBLE_CONVERSION\n";
