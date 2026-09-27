<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/apps/public/src/Emergency/PublicUpdateException.php';
require_once dirname(__DIR__).'/apps/public/src/Emergency/EmergencyAccessService.php';
use Sokna\PublicEdge\Emergency\EmergencyAccessService;
function g33p(bool $v,string $m):void{if(!$v){fwrite(STDERR,"FAIL $m\n");exit(1);}}
$tmp=sys_get_temp_dir().'/sokna-g33-access-'.bin2hex(random_bytes(4));@mkdir($tmp,0700,true);$svc=new EmergencyAccessService($tmp);g33p(!$svc->configured(),'fresh access unexpectedly configured');$code='G33-'.bin2hex(random_bytes(12));$svc->provisionHash(password_hash($code,PASSWORD_DEFAULT),'local-test');g33p($svc->configured(),'access hash not persisted');g33p($svc->verify($code),'valid emergency code rejected');g33p(!$svc->verify('bad-code'),'invalid emergency code accepted');$svc->audit('test_action','install-a','emergency',['shared_secret'=>'must-not-leak','value'=>'ok']);$raw=(string)file_get_contents($tmp.'/emergency/events.log');g33p(!str_contains($raw,'must-not-leak'),'secret leaked to emergency audit');g33p(str_contains($raw,'[redacted]'),'redaction marker missing');g33p(count($svc->recentAudit(10))>=2,'bounded emergency audit missing');echo "G3.3 Emergency access pure self-test: OK\n";
