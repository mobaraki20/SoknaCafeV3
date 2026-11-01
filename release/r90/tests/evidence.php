<?php
declare(strict_types=1);
require $argv[1].'/src/Domain/System/MaintenanceSupportEvidence.php';
use Sokna\Local\Domain\System\MaintenanceSupportEvidence as Evidence;
$rows=[];
function check(bool $pass,string $test):void{global $rows;$rows[]=['test'=>$test,'pass'=>$pass];if(!$pass)throw new RuntimeException($test);}
function failure(string $message):array{return Evidence::preflightFailure(['ok'=>true,'error_code'=>'','data'=>['operation'=>['state'=>'rejected_pre_mutation','result_message'=>$message]]]);}
check(failure('Maintenance Supervisor does not meet the Release Set minimum version.')['code']==='MAINTENANCE_VERSION_TOO_OLD','known engine minimum-version failure is retained');
check(failure('LOCAL_REBIND_UNIQUE_SIGNED_BASELINE_REQUIRED')['code']==='LOCAL_REBIND_UNIQUE_SIGNED_BASELINE_REQUIRED','exact physical baseline rejection is retained');
check(failure('LOCAL_REBIND_VERIFIED_R8_JUNCTION_REQUIRED')['code']==='LOCAL_REBIND_VERIFIED_R8_JUNCTION_REQUIRED','physical installation path rejection is retained');
check(failure('Installed release identity does not match any exact supported baseline.')['code']==='LOCAL_BASELINE_MISMATCH','identity mismatch is distinguished from version failure');
check(failure('STABLE_RECOVERY_EXISTING_OVERLAY_NOT_QUALIFIED: C:\\private\\secret')['code']==='STABLE_RECOVERY_EXISTING_OVERLAY_NOT_QUALIFIED','known recovery prefix strips private suffix');
$raw='C:\\private\\token=do-not-export';$out=failure($raw);check($out['code']==='PACKAGE_PREFLIGHT_REJECTED'&&!str_contains(json_encode($out),$raw),'unknown message is not copied into response');
$out=Evidence::preflightFailure(['error_code'=>'maintenance_http_timeout']);check($out['code']==='maintenance_http_timeout','transport failure code survives without a receipt');
$out=Evidence::preflightFailure(['error_code'=>'C:\\private']);check($out['code']==='invalid_or_redacted','unsafe transport error is redacted');
$summary=Evidence::summarize([],['ok'=>true,'state'=>'idle','history_available'=>true,'recent_operations'=>[['operation_id'=>str_repeat('a',32),'state'=>'rejected_pre_mutation','result_code'=>'PACKAGE_PREFLIGHT_REJECTED','result_message'=>'LOCAL_REBIND_UNIQUE_SIGNED_BASELINE_REQUIRED: C:\\private\\secret']]]);
check($summary['operations']['recent'][0]['cause_code']==='LOCAL_REBIND_UNIQUE_SIGNED_BASELINE_REQUIRED','support index retains safe cause from signed engine history');check(!str_contains(json_encode($summary),'private'),'support index omits raw reason and machine path');
check(Evidence::summarize([],['ok'=>false])['operations']['active_count']===null,'missing operation evidence remains unknown');
echo json_encode($rows,JSON_PRETTY_PRINT).PHP_EOL;
