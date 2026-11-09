$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest
$txScript=Join-Path $PWD.Path 'packaging/windows/scripts/invoke-maintenance-components-v4.ps1'
if($env:GITHUB_ACTIONS-ne'true' -or $env:RUNNER_OS-ne'Windows' -or
  -not(Test-Path $txScript -PathType Leaf)){throw 'R125_FIXTURE_REQUIRES_DISPOSABLE_WINDOWS_CI'}
$names=@(
 'Maintenance/App/SoknaMaintenanceSupervisor.exe',
 'Maintenance/App/SoknaMaintenanceUpdateEngine.exe',
 'Maintenance/Recovery/SoknaMaintenanceRecoveryConsole.exe'
)
function Fingerprint([string]$p){(Get-FileHash -LiteralPath $p -Algorithm SHA256).Hash.ToLowerInvariant()}
function SetupLab(){
 $root=Join-Path $env:RUNNER_TEMP ('sokna-maint-txn-lab-'+[Guid]::NewGuid().ToString('N'))
 $installed=Join-Path $root 'SOKNA Windows Services'
 $data=Join-Path $root 'ProgramData\SOKNA\Maintenance'
 $payload=Join-Path $root 'candidate'
 New-Item -ItemType Directory -Path $installed,$data,$payload -Force|Out-Null
 $new=@()
 foreach($rel in $names){
  $old=Join-Path $installed $rel.Replace('/','\')
  $candidate=Join-Path $payload $rel.Replace('/','\')
  New-Item -ItemType Directory -Path (Split-Path -Parent $old),(Split-Path -Parent $candidate) -Force|Out-Null
  [IO.File]::WriteAllText($old,('old:'+ $rel))
  [IO.File]::WriteAllText($candidate,('new:'+ $rel))
  $new+=@{path=$rel;sha256=(Fingerprint $candidate);bytes=(Get-Item $candidate).Length}
 }
 $identity=Join-Path $data 'installed-release-identity.json'
 $original=(@{maintenance_supervisor_version=$script:baselineVersion;update_engine_version=$script:baselineVersion;local=@{version='local-r112';tree='must-keep'};public=@{version='public-current';tree='must-keep'}}|ConvertTo-Json -Depth 9 -Compress)
 [IO.File]::WriteAllText($identity,$original)
 $marker=Join-Path $root 'service-running.marker'
 [IO.File]::WriteAllText($marker,'RUNNING')
 @{source_commit='98838c87e43270d4b402f485f00eb890cc860e7e';supported_baselines=@();format='sokna-maintenance-components-installer-v2';target_version='0.3.26';recovery_console_version='0.1.7';scope='maintenance_three_components_only';files=$new}|
   ConvertTo-Json -Depth 9|Set-Content -LiteralPath (Join-Path $payload 'maintenance-component-manifest-v2.json')
 [pscustomobject]@{Root=$root;Payload=$payload;Install=$installed;Identity=$identity;Original=$original;Running=$marker;OldHashes=@($names|ForEach-Object{Fingerprint (Join-Path $installed $_.Replace('/','\'))})}
}
function RunLab($fixture,[string]$injection=''){
 $out=Join-Path $fixture.Root 'output.txt'
 $err=Join-Path $fixture.Root 'errors.txt'
 $args=@('-NoProfile','-NonInteractive','-ExecutionPolicy','Bypass','-File',('"'+$txScript+'"'),'-PayloadRoot',('"'+$fixture.Payload+'"'),'-LabRoot',('"'+$fixture.Root+'"'))
 $args+=@('-LabBaseline',$script:baselineVersion)
 if($injection){$args+=@('-FailAt',$injection)}
 $proc=Start-Process -FilePath 'powershell.exe' -Wait -PassThru -ArgumentList $args -RedirectStandardOutput $out -RedirectStandardError $err
 [pscustomobject]@{Code=$proc.ExitCode;Stdout=$(if(Test-Path $out){Get-Content $out -Raw}else{''});Stderr=$(if(Test-Path $err){Get-Content $err -Raw}else{''})}
}

# Sanitized reconstruction of 25 operations in the 2026-11-09 One Support
# archive: 9 verified, 9 staged, 3 failed, 4 committed. No production IDs.
function AddHistoricalLedger($f,[string]$fault=''){
 $data=Join-Path $f.Root 'ProgramData\SOKNA'
 $ops=Join-Path $data 'Maintenance\operations'
 New-Item -ItemType Directory -Path $ops -Force|Out-Null
 $pkg=('c'*64);$manifest=('d'*64);$identity=('e'*64)
 $preflights=@()
 for($i=1;$i-le9;$i++){
  $id=('{0:x32}' -f $i)
  $proofPath=Join-Path $ops ('preflight-'+$id+'.json')
  $verify=@{release_set_id=('test-'+$i);sequence=$i;package_sha256=$pkg;manifest_sha256=$manifest;
    activation_performed=$false;database_mutation_performed=$false;artifact_integrity_verified=$true}
  @{format='sokna-local-package-preflight-v1';schema_version=1;operation_id=$id;
    package_ref=$pkg;installed_identity_sha256=$identity;verification=$verify}|
    ConvertTo-Json -Depth 12|Set-Content -LiteralPath $proofPath
  $op=@{format='sokna-maintenance-operation-v1';operation_id=$id;operation_type='update.local-package-preflight';
    state='verified';result_code='PACKAGE_INTEGRITY_VERIFIED';activation_performed=$false;
    database_mutation_performed=$false;package_ref=$pkg;preflight_receipt_path=$proofPath}
  $op|ConvertTo-Json -Depth 12|Set-Content -LiteralPath (Join-Path $ops ('op-'+$id+'.json'))
  $preflights+=@{id=$id;path=$proofPath}
 }
 for($i=1;$i-le9;$i++){
  $id=('{0:x32}' -f (100+$i))
  $stageDir=Join-Path $data ('staging\release-sets\'+('{0:d20}'-f $i)+'-test-'+$i)
  New-Item -ItemType Directory -Path $stageDir -Force|Out-Null
  $stagePath=Join-Path $stageDir 'stage-receipt.json'
  @{format='sokna-maintenance-staged-release-v1';schema_version=1;release_set_id=('test-'+$i);
    sequence=$i;manifest_sha256=$manifest;package_sha256=$pkg;stage_root=$stageDir;
    activation_performed=$false;database_mutation_performed=$false;preflight_identity_match=$true;
    artifact_integrity_verified=$true;signature_verified=$false;operator_authorized=$true}|
    ConvertTo-Json -Depth 12|Set-Content -LiteralPath $stagePath
  @{format='sokna-maintenance-operation-v1';operation_id=$id;operation_type='update.local-stage';
    state='staged';result_code='STAGED';activation_performed=$false;database_mutation_performed=$false;
    package_ref=$pkg;preflight_receipt_path=$preflights[$i-1].path;stage_receipt_path=$stagePath}|
    ConvertTo-Json -Depth 12|Set-Content -LiteralPath (Join-Path $ops ('op-'+$id+'.json'))
 }
 for($i=1;$i-le7;$i++){
  $id=('{0:x32}' -f (200+$i))
  $s=if($i-le3){'failed'}else{'committed'}
  @{format='sokna-maintenance-operation-v1';operation_id=$id;operation_type='update.local-code-only-activate';
    state=$s;activation_performed=$true;database_mutation_performed=$false}|
    ConvertTo-Json -Depth 12|Set-Content -LiteralPath (Join-Path $ops ('op-'+$id+'.json'))
 }
 $stagedId=('{0:x32}' -f 101)
 $stagedPath=Join-Path $ops ('op-'+$stagedId+'.json')
 $staged=Get-Content $stagedPath -Raw|ConvertFrom-Json
 switch($fault){
  'mutated'{$staged.database_mutation_performed=$true}
  'wrong-kind'{$staged.operation_type='update.local-code-only-activate'}
  'wrong-result'{$staged.result_code='NOT_STAGED'}
  'missing-proof'{Remove-Item -LiteralPath $staged.stage_receipt_path -Force}
  'foreign-proof'{$staged.stage_receipt_path=Join-Path $ops 'stage-receipt.json'}
  'unknown-state'{$staged.state='future_state'}
  'active-state'{$staged.state='recovery_required'}
 }
 $staged|ConvertTo-Json -Depth 12|Set-Content -LiteralPath $stagedPath
}

function VerifyOld($f){
 for($i=0;$i-lt$names.Count;$i++){
  $p=Join-Path $f.Install $names[$i].Replace('/','\')
  if((Fingerprint $p)-ne$f.OldHashes[$i]){throw ('R125_QA_OLD_BINARY_NOT_RESTORED:'+ $names[$i])}
 }
 if([IO.File]::ReadAllText($f.Identity)-cne$f.Original){throw 'R125_QA_LOCAL_OR_PUBLIC_IDENTITY_WAS_CHANGED'}
 if(-not(Test-Path $f.Running)){throw 'R125_QA_OLD_SERVICE_NOT_RESTORED'}
}
function VerifyCommitted($f){
 $p=Join-Path $f.Root 'ProgramData\SOKNA\setup\maintenance-components-v2\active.json'
 $state=Get-Content $p -Raw|ConvertFrom-Json
 if($state.phase-ne'COMMITTED'){throw 'R125_QA_TRANSACTION_NOT_COMMITTED'}
 for($i=0;$i-lt$names.Count;$i++){
  $path=Join-Path $f.Install $names[$i].Replace('/','\')
  $new=Join-Path $f.Payload $names[$i].Replace('/','\')
  if((Fingerprint $path)-ne(Fingerprint $new)){throw 'R125_QA_TARGET_FILE_MISMATCH'}
 }
 $identity=Get-Content $f.Identity -Raw|ConvertFrom-Json
 if($identity.maintenance_supervisor_version-ne'0.3.26' -or $identity.update_engine_version-ne'0.3.26' -or
    $identity.local.version-ne'local-r112' -or $identity.public.version-ne'public-current'){throw 'R125_QA_IDENTITY_SCOPE'}
}
$all=@('happy','after-copy-1','after-copy-2','after-copy-3','after-service-start','after-identity','corrupt','unresolved','baseline','crash-after-copy-1','crash-after-identity','recovery-required-operation','unknown-operation-state','machine-lease-busy','locked-unchanged-engine','history-25-valid','history-25-mutated','history-25-wrong-kind','history-25-wrong-result','history-25-missing-proof','history-25-foreign-proof','history-25-unknown-state','history-25-active-state')
foreach($script:baselineVersion in @('0.3.22','0.3.23','0.3.24','0.3.25')){
foreach($case in $all){
 $f=SetupLab
 try{
  if($case -like 'crash-after-*'){
   $killed=RunLab $f $case
   if($killed.Code-eq0){throw 'R125_QA_KILL_FAILED_TO_TERMINATE'}
   $recovery=RunLab $f
   if($recovery.Code-ne67){
    throw ('R125_QA_POWERLOSS_RECOVERY_FAILED:'+ $case +':'+$recovery.Code+':'+$recovery.Stdout+':'+$recovery.Stderr)
   }
   VerifyOld $f
   $marker=Get-Content (Join-Path $f.Root 'ProgramData\SOKNA\setup\maintenance-components-v2\active.json') -Raw|ConvertFrom-Json
   if($marker.phase-ne'ROLLED_BACK'){throw 'R125_QA_CRASH_ROLLBACK_NOT_DURABLE'}
   Write-Host ('PASS R125_TXN_'+$case)
   continue
  }
  switch($case){
   {$_ -like 'history-25-*'}{
    $fault=if($case-eq'history-25-valid'){''}else{$case.Substring('history-25-'.Length)}
    AddHistoricalLedger $f $fault
    $r=RunLab $f
    if($case-eq'history-25-valid'){
     if($r.Code-ne0){throw ('R125_HISTORY_VALID_REJECTED:'+ $r.Code+':'+$r.Stdout+':'+$r.Stderr)}
     VerifyCommitted $f
    }else{
     if($r.Code-eq0){throw ('R125_HISTORY_INVALID_ACCEPTED:'+ $case)}
     if($r.Stdout-notmatch 'SOKNA_INSTALLER_FAILURE=R125_'){
      throw ('R125_HISTORY_SAFE_FAILURE_NOT_REPORTED:'+ $case+':'+$r.Stdout)
     }
     VerifyOld $f
    }
   }
   'machine-lease-busy'{
    $dir=Join-Path $f.Root 'ProgramData\SOKNA\Maintenance\update'
    New-Item -ItemType Directory -Path $dir -Force|Out-Null
    $held=[IO.File]::Open((Join-Path $dir 'machine-mutation.lock'),[IO.FileMode]::OpenOrCreate,[IO.FileAccess]::ReadWrite,[IO.FileShare]::None)
    try{$r=RunLab $f;if($r.Code-eq0){throw 'ACCEPTED_FOREIGN_MACHINE_LEASE'};VerifyOld $f}finally{$held.Dispose()}
   }
   'locked-unchanged-engine'{
    $file=Join-Path $f.Install 'Maintenance\App\SoknaMaintenanceUpdateEngine.exe'
    $held=[IO.File]::Open($file,[IO.FileMode]::Open,[IO.FileAccess]::Read,[IO.FileShare]::Read)
    try{$r=RunLab $f;if($r.Code-ne65){throw ('UNCHANGED_LOCKED_FILE_ROLLBACK_FAILED:'+ $r.Code+':'+$r.Stdout+':'+$r.Stderr)};VerifyOld $f}finally{$held.Dispose()}
   }
   'corrupt'{
    Add-Content -LiteralPath (Join-Path $f.Payload $names[0].Replace('/','\')) -Value 'tampered'
    $r=RunLab $f
    if($r.Code-eq0){throw 'R125_QA_ACCEPTED_UNTRUSTED_PACKAGE'}
    VerifyOld $f
   }
   'unresolved'{
    $priorDir=Join-Path $f.Root 'ProgramData\SOKNA\setup\maintenance-components-v2'
    New-Item -ItemType Directory -Path $priorDir -Force|Out-Null
    @{phase='RECOVERY_REQUIRED'}|ConvertTo-Json|Set-Content (Join-Path $priorDir 'active.json')
    $r=RunLab $f
    if($r.Code-eq0){throw 'R125_QA_ACCEPTED_UNRESOLVED_CRASH'}
    VerifyOld $f
   }
   'recovery-required-operation'{
    $ops=Join-Path $f.Root 'ProgramData\SOKNA\Maintenance\operations'
    New-Item -ItemType Directory -Path $ops -Force|Out-Null
    $id='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'
    @{format='sokna-maintenance-operation-v1';operation_id=$id;state='recovery_required'}|
      ConvertTo-Json|Set-Content -LiteralPath (Join-Path $ops ('op-'+$id+'.json'))
    $r=RunLab $f
    if($r.Code-eq0){throw 'R125_QA_RECONCILIATION_BYPASSED_RECOVERY_REQUIRED'}
    VerifyOld $f
   }
   'unknown-operation-state'{
    $ops=Join-Path $f.Root 'ProgramData\SOKNA\Maintenance\operations'
    New-Item -ItemType Directory -Path $ops -Force|Out-Null
    $id='bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'
    @{format='sokna-maintenance-operation-v1';operation_id=$id;state='unknown_future_state'}|
      ConvertTo-Json|Set-Content -LiteralPath (Join-Path $ops ('op-'+$id+'.json'))
    $r=RunLab $f
    if($r.Code-eq0){throw 'R125_QA_UNKNOWN_STATE_ACCEPTED_AS_IDLE'}
    VerifyOld $f
   }
   'baseline'{
    $str=Get-Content $f.Identity -Raw
    [IO.File]::WriteAllText($f.Identity,$str.Replace(('"'+$script:baselineVersion+'"'),'"0.3.21"'))
    $r=RunLab $f
    if($r.Code-eq0){throw 'R125_QA_ACCEPTED_BASELINE_DRIFT'}
    for($i=0;$i-lt$names.Count;$i++){
     if((Fingerprint (Join-Path $f.Install $names[$i].Replace('/','\')))-ne$f.OldHashes[$i]){throw 'R125_QA_BASELINE_DRIFT_CHANGED_FILES'}
    }
   }
   'happy'{
    $r=RunLab $f
    if($r.Code-ne0){
     $res=Get-ChildItem -LiteralPath $f.Root -Filter result.json -File -Recurse -ErrorAction SilentlyContinue|Select-Object -First 1
     $stateDump=$(if($res){Get-Content $res.FullName -Raw}else{'NO_RECEIPT'})
     throw ("R125_QA_HAPPY_FAILED:"+ $r.Code + ":" + $r.Stderr + " OUTPUT=" + $r.Stdout + " RECEIPT=" + $stateDump)
    }
    VerifyCommitted $f
   }
   default{
    $r=RunLab $f $case
    if($r.Code-ne65){throw ("R125_QA_ROLLBACK_CODE_UNEXPECTED:"+ $case+":"+ $r.Code+":"+$r.Stderr)}
    VerifyOld $f
    $mark=Get-Content (Join-Path $f.Root 'ProgramData\SOKNA\setup\maintenance-components-v2\active.json') -Raw|ConvertFrom-Json
    if($mark.phase-ne'ROLLED_BACK'){throw 'R125_QA_ROLLBACK_EVIDENCE_UNQUALIFIED'}
   }
  }
  Write-Host ('PASS R125_TXN_'+$case)
 }finally{
  Remove-Item -LiteralPath $f.Root -Recurse -Force -ErrorAction SilentlyContinue
 }
}
}
Write-Host 'PASS all 92 Windows three-component installation, history, rollback and fail-closed safety cases'

