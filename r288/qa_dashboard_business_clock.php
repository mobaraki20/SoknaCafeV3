<?php
declare(strict_types=1);
if(getenv('GITHUB_ACTIONS')!=='true'||getenv('R288_QA_MODE')!=='disposable_only')throw new RuntimeException('QA_ONLY');
$root=getenv('R288_SOURCE_ROOT');
require_once $root.'/src/Domain/Orders/BusinessClock.php';
$db=new PDO('mysql:host=127.0.0.1;port=3306;dbname=sokna_r288_print;charset=utf8mb4','root',getenv('R288_DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE settings(setting_key VARCHAR(100) PRIMARY KEY,setting_value TEXT) ENGINE=InnoDB');
$db->exec("INSERT INTO settings VALUES('business_day_cutoff','04:00')");
$clock=new Sokna\Local\Domain\Orders\BusinessClock($db,'Asia/Tehran');
if($clock->assignment('2026-11-19 03:59:59')['business_date']!=='2026-11-18')throw new RuntimeException('BEFORE_CUTOFF_FAILED');
if($clock->assignment('2026-11-19 04:00:00')['business_date']!=='2026-11-19')throw new RuntimeException('AT_CUTOFF_FAILED');
if($clock->assignment('2026-11-18T23:59:59Z')['business_date']!=='2026-11-19')throw new RuntimeException('UTC_TO_TEHRAN_FAILED');
$source=file_get_contents($root.'/public/index.php');
$start=strpos($source,'$recent=[];');$stop=strpos($source,'$attentionErrors=', $start);
if($start===false||$stop===false)throw new RuntimeException('DASHBOARD_FILTER_CODE_NOT_FOUND');
$filter=substr($source,$start,$stop-$start);
// Execute the actual production filter without including the HTML/controller itself.
$todayKey='2026-11-19';
$notificationItems=[
 ['id'=>5,'title'=>'old read','created_at'=>'2026-11-18 20:00:00','read_at'=>'2026-11-18 21:00:00'],
 ['id'=>4,'title'=>'pre-cutoff read','created_at'=>'2026-11-19 03:59:59','read_at'=>'2026-11-19 03:59:59'],
 ['id'=>3,'title'=>'current read','created_at'=>'2026-11-19 04:00:00','read_at'=>'2026-11-19 04:01:00'],
 ['id'=>2,'title'=>'old open','created_at'=>'2026-11-18 14:00:00','read_at'=>null],
 ['id'=>1,'title'=>'already in attention','created_at'=>'2026-11-19 12:00:00','read_at'=>null],
];
$usedNotificationIds=[1=>true];
$core=new class($clock){public function __construct(private Sokna\Local\Domain\Orders\BusinessClock $clock){}public function businessClock(){return $this->clock;}};
eval($filter);
$actual=array_map(static fn(array $n)=>(int)$n['id'],$recent);
if($actual!==[3,2])throw new RuntimeException('FILTER_ACTUAL_DIFF '.json_encode($actual));
echo json_encode(['result'=>'PASS','business_cutoff'=>'04:00','previous_day_read_hidden'=>true,'same_calendar_before_cutoff_hidden'=>true,'unread_old_preserved'=>true,'current_day_read_kept'=>true,'already_in_attention_not_duplicated'=>true,'records_deleted'=>false],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),"\n";
