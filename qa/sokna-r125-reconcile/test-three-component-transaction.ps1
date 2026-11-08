$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest
$txScript=Join-Path $PWD.Path 'packaging/windows/scripts/invoke-r125-three-component-v1.ps1'
if($env:GITHUB_ACTIONS-ne'true' -or $env:RUNNER_OS-ne'Windows' -or
  -not(Test-Path $txScript -PathType Leaf)){throw 'R125_FIXTURE_REQUIRES_DISPOSABLE_WINDOWS_CI'}
$names=@(
 'Maintenance/App/SoknaMaintenanceSupervisor.exe',
 'Maintenance/App/SoknaMaintenanceUpdateEngine.exe',
 'Maintenance/Recovery/SoknaMaintenanceRecoveryConsole.exe'
)
function Fingerprint([string]$p){(Get-FileHash -LiteralPath $p -Algorithm SHA256).Hash.ToLowerInvariant()}
function SetupLab(){
 $root=Join-Path $env:RUNNER_TEMP ('sokna-r125-txn-lab-'+[Guid]::NewGuid().ToString('N'))
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
 $original='{"maintenance_supervisor_version":"0.3.22","update_engine_version":"0.3.22","local":{"version":"local-r112","tree":"must-keep"},"public":{"version":"public-current","tree":"must-keep"}}'
 [IO.File]::WriteAllText($identity,$original)
 $marker=Join-Path $root 'service-running.marker'
 [IO.File]::WriteAllText($marker,'RUNNING')
 @{format='sokna-r125-three-component-installer-v1';target_version='0.3.23';recovery_console_version='0.1.4';scope='maintenance_three_components_only';files=$new}|
   ConvertTo-Json -Depth 9|Set-Content -LiteralPath (Join-Path $payload 'r125-component-manifest.json')
 [pscustomobject]@{Root=$root;Payload=$payload;Install=$installed;Identity=$identity;Original=$original;Running=$marker;OldHashes=@($names|ForEach-Object{Fingerprint (Join-Path $installed $_.Replace('/','\'))})}
}
function RunLab($fixture,[string]$injection=''){
 $out=Join-Path $fixture.Root 'output.txt'
 $err=Join-Path $fixture.Root 'errors.txt'
 $args=@('-NoProfile','-NonInteractive','-ExecutionPolicy','Bypass','-File',('"'+$txScript+'"'),'-PayloadRoot',('"'+$fixture.Payload+'"'),'-LabRoot',('"'+$fixture.Root+'"'))
 if($injection){$args+=@('-FailAt',$injection)}
 $proc=Start-Process -FilePath 'powershell.exe' -Wait -PassThru -ArgumentList $args -RedirectStandardOutput $out -RedirectStandardError $err
 [pscustomobject]@{Code=$proc.ExitCode;Stdout=$(if(Test-Path $out){Get-Content $out -Raw}else{''});Stderr=$(if(Test-Path $err){Get-Content $err -Raw}else{''})}
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
 $p=Join-Path $f.Root 'ProgramData\SOKNA\setup\r125-0323-three-component\active.json'
 $state=Get-Content $p -Raw|ConvertFrom-Json
 if($state.phase-ne'COMMITTED'){throw 'R125_QA_TRANSACTION_NOT_COMMITTED'}
 for($i=0;$i-lt$names.Count;$i++){
  $path=Join-Path $f.Install $names[$i].Replace('/','\')
  $new=Join-Path $f.Payload $names[$i].Replace('/','\')
  if((Fingerprint $path)-ne(Fingerprint $new)){throw 'R125_QA_TARGET_FILE_MISMATCH'}
 }
 $identity=Get-Content $f.Identity -Raw|ConvertFrom-Json
 if($identity.maintenance_supervisor_version-ne'0.3.23' -or $identity.update_engine_version-ne'0.3.23' -or
    $identity.local.version-ne'local-r112' -or $identity.public.version-ne'public-current'){throw 'R125_QA_IDENTITY_SCOPE'}
}
$all=@('happy','after-copy-1','after-copy-2','after-copy-3','after-service-start','after-identity','corrupt','unresolved','baseline','crash-after-copy-1','crash-after-identity')
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
   $marker=Get-Content (Join-Path $f.Root 'ProgramData\SOKNA\setup\r125-0323-three-component\active.json') -Raw|ConvertFrom-Json
   if($marker.phase-ne'ROLLED_BACK'){throw 'R125_QA_CRASH_ROLLBACK_NOT_DURABLE'}
   Write-Host ('PASS R125_TXN_'+$case)
   continue
  }
  switch($case){
   'corrupt'{
    Add-Content -LiteralPath (Join-Path $f.Payload $names[0].Replace('/','\')) -Value 'tampered'
    $r=RunLab $f
    if($r.Code-eq0){throw 'R125_QA_ACCEPTED_UNTRUSTED_PACKAGE'}
    VerifyOld $f
   }
   'unresolved'{
    $priorDir=Join-Path $f.Root 'ProgramData\SOKNA\setup\r125-0323-three-component'
    New-Item -ItemType Directory -Path $priorDir -Force|Out-Null
    @{phase='RECOVERY_REQUIRED'}|ConvertTo-Json|Set-Content (Join-Path $priorDir 'active.json')
    $r=RunLab $f
    if($r.Code-eq0){throw 'R125_QA_ACCEPTED_UNRESOLVED_CRASH'}
    VerifyOld $f
   }
   'baseline'{
    $str=Get-Content $f.Identity -Raw
    [IO.File]::WriteAllText($f.Identity,$str.Replace('"0.3.22"','"0.3.21"'))
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
    $mark=Get-Content (Join-Path $f.Root 'ProgramData\SOKNA\setup\r125-0323-three-component\active.json') -Raw|ConvertFrom-Json
    if($mark.phase-ne'ROLLED_BACK'){throw 'R125_QA_ROLLBACK_EVIDENCE_UNQUALIFIED'}
   }
  }
  Write-Host ('PASS R125_TXN_'+$case)
 }finally{
  Remove-Item -LiteralPath $f.Root -Recurse -Force -ErrorAction SilentlyContinue
 }
}
Write-Host 'PASS all eleven Windows three-component installation, rollback and hard process-kill recovery cases'
