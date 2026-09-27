<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/apps/local-web/bootstrap.php';
require_once dirname(__DIR__).'/apps/public/bootstrap.php';
use Sokna\Local\Domain\Reporting\ReportingService;use Sokna\Local\Domain\Reporting\ReportingException;use Sokna\Local\Domain\Marketing\MarketingService;use Sokna\Local\Domain\Marketing\MarketingException;use Sokna\Local\Domain\Notifications\NotificationService;use Sokna\Local\Domain\Notifications\NotificationException;use Sokna\PublicEdge\Remote\RemoteReadModelService;
final class G43FakePdo extends PDO{public function __construct(){}}
function g43p(bool $ok,string $m): void{if(!$ok)throw new RuntimeException($m);}
$pdo=new G43FakePdo();
$report=new ReportingService($pdo);$bounded=false;try{$report->report('2020-01-01','2022-01-01');}catch(ReportingException $e){$bounded=$e->errorCode==='range_too_large';}g43p($bounded,'report range guard failed');
$marketing=new MarketingService($pdo);$forbidden=false;try{$marketing->saveCampaign([],['id'=>2,'role'=>'operator']);}catch(MarketingException $e){$forbidden=$e->errorCode==='forbidden';}g43p($forbidden,'marketing admin boundary failed');
$notifications=new NotificationService($pdo);$badPush=false;try{$notifications->registerPush(['endpoint'=>'http://example.test','keys'=>['p256dh'=>'x','auth'=>'y']],['id'=>1,'role'=>'admin']);}catch(NotificationException $e){$badPush=$e->errorCode==='invalid_subscription';}g43p($badPush,'push HTTPS boundary failed');
$badUrl=false;try{$notifications->compose(['title'=>'x','body'=>'y','target_url'=>'https://evil.example'],['id'=>1,'role'=>'admin']);}catch(NotificationException $e){$badUrl=$e->errorCode==='invalid_url';}g43p($badUrl,'notification internal URL boundary failed');
$ref=new ReflectionClass(RemoteReadModelService::class);$obj=$ref->newInstanceWithoutConstructor();$m=$ref->getMethod('filterNotifications');$m->setAccessible(true);$filtered=$m->invoke($obj,['items'=>[['projection_id'=>'user:1','title'=>'mine'],['projection_id'=>'user:2','title'=>'other']]],['projection_id'=>'user:1']);g43p(count($filtered['items'])===1&&($filtered['items'][0]['title']??'')==='mine'&&!isset($filtered['items'][0]['projection_id']),'remote notification privacy filter failed');
fwrite(STDOUT,"G4.3 pure self-test: OK\n");
