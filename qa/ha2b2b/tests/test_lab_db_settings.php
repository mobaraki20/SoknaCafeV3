<?php
declare(strict_types=1);
require_once __DIR__.'/../source/LabMariaDbSettings.php';
$results=[];
function ck(string $case,bool $ok):void{global $results;$results[]=['case'=>$case,'pass'=>$ok];if(!$ok)fwrite(STDERR,"FAIL $case\n");}
function denied(callable $c):bool{try{$c();return false;}catch(Throwable $e){return true;}}
$lab='sokna_ha2_lab_abcdef12';
ck('normal lab host',LabMariaDbSettings::validate($lab,'127.0.0.1','3306',true)['host']==='127.0.0.1');
ck('dns hostname allowed',LabMariaDbSettings::validate($lab,'db.test.invalid','3306',true)['host']==='db.test.invalid');
ck('port one allowed',LabMariaDbSettings::validate($lab,'localhost','1',true)['port']===1);
ck('port upper bound',LabMariaDbSettings::validate($lab,'localhost','65535',true)['port']===65535);
ck('explicit consent mandatory',denied(fn()=>LabMariaDbSettings::validate($lab,'127.0.0.1','3306',false)));
ck('non lab DB denied',denied(fn()=>LabMariaDbSettings::validate('production','127.0.0.1','3306',true)));
ck('uppercase DB denied',denied(fn()=>LabMariaDbSettings::validate(strtoupper($lab),'127.0.0.1','3306',true)));
ck('path in host denied',denied(fn()=>LabMariaDbSettings::validate($lab,'/var/run/mysql','3306',true)));
ck('host SQL injection denied',denied(fn()=>LabMariaDbSettings::validate($lab,'db;DROP','3306',true)));
ck('host newline denied',denied(fn()=>LabMariaDbSettings::validate($lab,"db\ninvalid",'3306',true)));
ck('zero port denied',denied(fn()=>LabMariaDbSettings::validate($lab,'127.0.0.1','0',true)));
ck('too large port denied',denied(fn()=>LabMariaDbSettings::validate($lab,'127.0.0.1','65536',true)));
ck('non-numeric port denied',denied(fn()=>LabMariaDbSettings::validate($lab,'127.0.0.1','3306;',true)));
ck('environment with no explicit confirmation denied',denied(fn()=>LabMariaDbSettings::fromEnvironment()));
ck('worker and runner use same helper',str_contains(file_get_contents(__DIR__.'/real_mariadb_worker.php'),'LabMariaDbSettings::connect()')&&str_contains(file_get_contents(__DIR__.'/run_real_mariadb.php'),'LabMariaDbSettings::connect()'));
ck('helper never authorizes deployment',!preg_match('/function\\s+(commit|activate|rollback|recover)\\s*\\(/i',file_get_contents(__DIR__.'/../source/LabMariaDbSettings.php')));
$total=count($results);$passed=count(array_filter($results,fn($r)=>$r['pass']));
echo json_encode(['stage'=>'F1-HA2B2-PREFLIGHT','total'=>$total,'passed'=>$passed,'real_db_tested'=>false,'cases'=>$results],JSON_PRETTY_PRINT)."\n";
exit($passed===$total?0:1);
