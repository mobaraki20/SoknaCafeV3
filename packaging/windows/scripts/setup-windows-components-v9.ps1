param([Parameter(Mandatory=$true)][string]$PlanFile)
$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest

function Full([string]$p){[IO.Path]::GetFullPath($p)}
function Safe([string]$v){$v=([string]$v-replace'[\r\n\0]',' ').Trim();if($v.Length-gt1200){$v=$v.Substring(0,1200)};return $v}
function Write-Json([string]$path,$value){$value|ConvertTo-Json -Depth 14|Set-Content -LiteralPath $path -Encoding UTF8}
function Add-Event([string]$name,[string]$result,$data=$null){
  $row=[ordered]@{format='sokna-installer-event-v1';at_utc=[DateTime]::UtcNow.ToString('o');transaction_id=$Tx;correlation_id=$Corr;setup_session_id=$Session;event_name=$name;result=$result;component='legacy-print-migration';phase=$Phase;data=$data}
  Add-Content -LiteralPath $Events -Value ($row|ConvertTo-Json -Compress -Depth 8) -Encoding UTF8
}
function Assert-PlanPreflight {
  if([string]$plan.mode-eq'uninstall'){Add-Event 'privileged_plan_preflight_completed' 'completed' @{mode='uninstall';mutations_validated=0};return}
  $rows=@($plan.components)
  if($rows.Count-eq0){throw 'PRIVILEGED_PREFLIGHT_EMPTY_COMPONENT_PLAN'}
  $seen=@{}
  foreach($row in $rows){
    $id=[string]$row.component_id
    if([string]::IsNullOrWhiteSpace($id)){throw 'PRIVILEGED_PREFLIGHT_COMPONENT_ID_MISSING'}
    if($seen.ContainsKey($id)){throw ('PRIVILEGED_PREFLIGHT_DUPLICATE_COMPONENT: '+$id)}
    $seen[$id]=$true
    $action=([string]$row.action).Trim().ToUpperInvariant().Replace('_','')
    if($action-notin@('KEEP','INSTALL','UPDATE','REPAIR','BLOCKED','NOACTIONOPTIONAL','PENDINGRESTART')){throw ('PRIVILEGED_PREFLIGHT_ACTION_UNSUPPORTED: '+$id+' / '+$action)}
    if($action-eq'BLOCKED'){throw ('PRIVILEGED_PREFLIGHT_COMPONENT_BLOCKED: '+$id)}
    if($id-eq'maintenance-recovery-console'-and$action-ne'KEEP'){
      throw ('PRIVILEGED_PREFLIGHT_RECOVERY_LIFECYCLE_REQUIRED: '+$action)
    }
    if($action-in@('INSTALL','UPDATE','REPAIR','KEEP')){
      $sha=([string]$row.target_sha256).Trim().ToLowerInvariant()
      if($sha-notmatch'^[a-f0-9]{64}$'){throw ('PRIVILEGED_PREFLIGHT_TARGET_SHA_INVALID: '+$id)}
    }
  }
  Add-Event 'privileged_plan_preflight_completed' 'completed' @{mode=[string]$plan.mode;components=$rows.Count;recovery_console_mutation=$false}
}
function Service-Exe([string]$image){
  if([string]::IsNullOrWhiteSpace($image)){return ''}
  if($image-match'^\s*"([^"]+\.exe)"'){return [string]$Matches[1]}
  if($image-match'^\s*([^\s"]+\.exe)'){return [string]$Matches[1]}
  return ''
}
function Get-LegacySnapshot {
  $name='SoknaPrintAgent6'
  $svc=Get-CimInstance Win32_Service -Filter "Name='SoknaPrintAgent6'" -ErrorAction SilentlyContinue
  if($null-eq$svc){return $null}
  $image=[string]$svc.PathName
  $exe=Service-Exe $image
  if([string]::IsNullOrWhiteSpace($exe)){throw 'LEGACY_PRINT_IMAGE_UNRESOLVED'}
  $full=[IO.Path]::GetFullPath($exe)
  $norm=$full.Replace('/','\')
  if(-not$norm.EndsWith('\Sokna.PrintAgent.Service.exe',[StringComparison]::OrdinalIgnoreCase)-or$norm.IndexOf('\Sokna\PrintAgent\',[StringComparison]::OrdinalIgnoreCase)-lt0){throw ('LEGACY_PRINT_IMAGE_UNRECOGNIZED: '+$full)}
  if(([string]$svc.StartName)-notin@('LocalSystem','LocalSystem ')){throw ('LEGACY_PRINT_ACCOUNT_UNSUPPORTED: '+[string]$svc.StartName)}
  $reg='HKLM:\SYSTEM\CurrentControlSet\Services\SoknaPrintAgent6'
  $rp=Get-ItemProperty -LiteralPath $reg -ErrorAction Stop
  $start=[int]$rp.Start
  $delayed=0
  if($rp.PSObject.Properties.Name-contains'DelayedAutoStart'){$delayed=[int]$rp.DelayedAutoStart}
  return [pscustomobject]@{Name=$name;ImagePath=$image;Exe=$full;WasRunning=([string]$svc.State-eq'Running');ProcessId=[uint32]$svc.ProcessId;StartValue=$start;DelayedAutoStart=$delayed;DisplayName=[string]$svc.DisplayName;StartName=[string]$svc.StartName}
}
function Wait-PidExit([uint32]$pid,[int]$seconds){
  if($pid-le0){return}
  $deadline=[DateTime]::UtcNow.AddSeconds($seconds)
  while([DateTime]::UtcNow-lt$deadline){if(-not(Get-Process -Id $pid -ErrorAction SilentlyContinue)){return};Start-Sleep -Milliseconds 200}
  $p=Get-Process -Id $pid -ErrorAction SilentlyContinue
  if($p){Stop-Process -Id $pid -Force -ErrorAction Stop}
  Start-Sleep -Milliseconds 500
  if(Get-Process -Id $pid -ErrorAction SilentlyContinue){throw 'LEGACY_PRINT_PROCESS_DID_NOT_EXIT'}
}
function Quiesce-Legacy($snapshot){
  if($null-eq$snapshot){return}
  $script:Phase='Legacy print quiesce'
  Add-Event 'legacy_print_migration_started' 'started' @{service='SoknaPrintAgent6';was_running=$snapshot.WasRunning;image=$snapshot.Exe;files_preserved=$true}
  $svc=Get-Service 'SoknaPrintAgent6' -ErrorAction Stop
  try{
    if($svc.Status-ne'Stopped'){
      Stop-Service 'SoknaPrintAgent6' -ErrorAction Stop
      $svc.WaitForStatus('Stopped',[TimeSpan]::FromSeconds(30))
    }
  }finally{$svc.Dispose()}
  Wait-PidExit ([uint32]$snapshot.ProcessId) 12
  Set-Service 'SoknaPrintAgent6' -StartupType Disabled -ErrorAction Stop
  $script:LegacyTouched=$true
  Add-Event 'legacy_print_quiesced' 'completed' @{service='SoknaPrintAgent6';registration_preserved_until_new_worker_verified=$true}
}
function Restore-Legacy($snapshot){
  if($null-eq$snapshot-or-not$script:LegacyTouched){return}
  $script:Phase='Legacy print rollback'
  $exists=$null-ne(Get-Service 'SoknaPrintAgent6' -ErrorAction SilentlyContinue)
  if(-not$exists){throw 'LEGACY_PRINT_ROLLBACK_REGISTRATION_MISSING'}
  switch([int]$snapshot.StartValue){2{Set-Service 'SoknaPrintAgent6' -StartupType Automatic -ErrorAction Stop};3{Set-Service 'SoknaPrintAgent6' -StartupType Manual -ErrorAction Stop};4{Set-Service 'SoknaPrintAgent6' -StartupType Disabled -ErrorAction Stop};default{Set-Service 'SoknaPrintAgent6' -StartupType Manual -ErrorAction Stop}}
  $reg='HKLM:\SYSTEM\CurrentControlSet\Services\SoknaPrintAgent6'
  Set-ItemProperty -LiteralPath $reg -Name DelayedAutoStart -Type DWord -Value ([int]$snapshot.DelayedAutoStart) -Force -ErrorAction SilentlyContinue
  if($snapshot.WasRunning){Start-Service 'SoknaPrintAgent6' -ErrorAction Stop}
  Add-Event 'legacy_print_rollback_completed' 'completed' @{service='SoknaPrintAgent6';restored_running=$snapshot.WasRunning;files_preserved=$true}
}
function Verify-NewPrintStable {
  $script:Phase='New print stability verification'
  for($i=0;$i-lt10;$i++){
    $svc=Get-Service 'SoknaPrintWorker' -ErrorAction SilentlyContinue
    if($null-eq$svc){throw 'NEW_PRINT_SERVICE_MISSING_AFTER_CORE'}
    try{if($svc.Status-ne'Running'){throw ('NEW_PRINT_NOT_RUNNING_AFTER_CORE: '+[string]$svc.Status)}}finally{$svc.Dispose()}
    Start-Sleep -Milliseconds 500
  }
  Add-Event 'new_print_stability_verified' 'completed' @{service='SoknaPrintWorker';stable_seconds=5}
}
function Retire-Legacy($snapshot){
  if($null-eq$snapshot){return}
  $script:Phase='Legacy print retirement'
  Verify-NewPrintStable
  & "$env:SystemRoot\System32\sc.exe" delete 'SoknaPrintAgent6'|Out-Null
  if($LASTEXITCODE-ne0){throw ('LEGACY_PRINT_SERVICE_DELETE_FAILED: '+$LASTEXITCODE)}
  for($i=0;$i-lt100;$i++){
    $s=Get-Service 'SoknaPrintAgent6' -ErrorAction SilentlyContinue
    if($null-eq$s){Add-Event 'legacy_print_migration_completed' 'completed' @{service='SoknaPrintAgent6';legacy_files_deleted=$false;legacy_files_preserved=$true;new_service='SoknaPrintWorker'};return}
    $s.Dispose();Start-Sleep -Milliseconds 100
  }
  throw 'LEGACY_PRINT_SERVICE_DELETE_TIMEOUT'
}
function Annotate-Result([string]$adoptionState,[string]$code,[string]$detail){
  if(-not(Test-Path -LiteralPath $Result -PathType Leaf)){return}
  try{
    $r=Get-Content -LiteralPath $Result -Raw|ConvertFrom-Json
    if([string]$r.status-ne'completed'){return}
    $post=[ordered]@{state=$adoptionState;code=$code;detail=(Safe $detail);windows_components_committed=$true;windows_rollback_performed=$false;activation_performed=$false;database_mutation_performed=$false;public_mutation_performed=$false;windows_reboot_required=$false;legacy_print_service_retired=($null-ne$LegacySnapshot)}
    $r|Add-Member -NotePropertyName post_install_local_adoption -NotePropertyValue $post -Force
    Write-Json $Result $r
  }catch{}
}

$PlanFile=Full $PlanFile
if(-not(Test-Path -LiteralPath $PlanFile -PathType Leaf)){throw 'Plan file missing.'}
$plan=Get-Content -LiteralPath $PlanFile -Raw|ConvertFrom-Json
if([string]$plan.format-ne'sokna-windows-install-plan-v3'){throw 'Unsupported install plan.'}
$ShellRoot=Full ([string]$plan.shell_root)
$Tx=[string]$plan.transaction_id;$Corr=[string]$plan.correlation_id;$Session=[string]$plan.setup_session_id
$TxDir=[IO.Path]::GetDirectoryName($PlanFile);$Events=Join-Path $TxDir 'system-events.jsonl';$Result=Join-Path $TxDir 'install-result.json'
$core=Join-Path $ShellRoot 'setup-windows-components-core-v5.ps1';$queue=Join-Path $ShellRoot 'queue-local-slot-adoption-r8.ps1'
$ps51=Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\powershell.exe'
if(-not(Test-Path -LiteralPath $ps51 -PathType Leaf)){throw 'Windows PowerShell 5.1 executable is unavailable.'}
if(-not(Test-Path -LiteralPath $core -PathType Leaf)){throw 'Qualified Windows component lifecycle core is missing.'}
$Phase='Privileged plan preflight';$LegacyTouched=$false;$LegacySnapshot=$null
try{Assert-PlanPreflight}catch{Add-Event 'privileged_plan_preflight_failed' 'failed' @{error=(Safe $_.Exception.Message);mutation_started=$false};throw}
$Phase='Legacy print preflight'
try{$LegacySnapshot=Get-LegacySnapshot;Quiesce-Legacy $LegacySnapshot}catch{Add-Event 'legacy_print_preflight_failed' 'failed' @{error=(Safe $_.Exception.Message)};throw}

$coreProcess=$null
try{
  $Phase='Windows component transaction'
  $coreProcess=Start-Process $ps51 -ArgumentList @('-NoProfile','-NonInteractive','-ExecutionPolicy','Bypass','-File',('"'+$core+'"'),'-PlanFile',('"'+$PlanFile+'"')) -WindowStyle Hidden -Wait -PassThru
  if([int]$coreProcess.ExitCode-ne0){Restore-Legacy $LegacySnapshot;exit [int]$coreProcess.ExitCode}
  Retire-Legacy $LegacySnapshot
}catch{
  $msg=Safe $_.Exception.Message
  try{Restore-Legacy $LegacySnapshot}catch{Add-Event 'legacy_print_rollback_failed' 'failed' @{error=(Safe $_.Exception.Message)}}
  Add-Event 'legacy_print_migration_failed' 'failed' @{error=$msg;windows_components_may_have_committed=($null-ne$coreProcess-and[int]$coreProcess.ExitCode-eq0)}
  Write-Error $msg
  exit 23
}

if(-not(Test-Path -LiteralPath $queue -PathType Leaf)){
  $Phase='Post-commit adoption queue';Add-Event 'local_adoption_queue_missing' 'warning' @{code='ADOPTION_QUEUE_ENTRYPOINT_MISSING'};Annotate-Result 'deferred' 'ADOPTION_QUEUE_ENTRYPOINT_MISSING' 'Local adoption queue entrypoint is unavailable.';exit 0
}
try{
  $Phase='Post-commit adoption queue'
  $qp=Start-Process $ps51 -ArgumentList @('-NoProfile','-NonInteractive','-ExecutionPolicy','Bypass','-File',('"'+$queue+'"'),'-PlanFile',('"'+$PlanFile+'"')) -WindowStyle Hidden -Wait -PassThru
  $queueExit=[int]$qp.ExitCode
}catch{
  Add-Event 'local_adoption_queue_start_failed' 'warning' @{code='ADOPTION_QUEUE_START_FAILED';error=(Safe $_.Exception.Message)};Annotate-Result 'deferred' 'ADOPTION_QUEUE_START_FAILED' $_.Exception.Message;exit 0
}
$evidencePath=Join-Path $TxDir 'local-slot-adoption-queue.json';$qe=$null
if(Test-Path -LiteralPath $evidencePath -PathType Leaf){try{$qe=Get-Content -LiteralPath $evidencePath -Raw|ConvertFrom-Json}catch{}}
$state=$(if($qe-and$qe.PSObject.Properties.Name-contains'status'){[string]$qe.status}else{'deferred'})
$code=$(if($qe-and$qe.PSObject.Properties.Name-contains'code'){[string]$qe.code}else{'ADOPTION_QUEUE_RESULT_UNAVAILABLE'})
$detail=$(if($qe-and$qe.PSObject.Properties.Name-contains'detail'){[string]$qe.detail}else{"Local adoption queue helper exit code: $queueExit"})
if($state-notin@('queued','skipped','deferred')){$state='deferred'}
Add-Event 'local_adoption_queue_recorded' $(if($state-eq'deferred'){'warning'}else{'completed'}) @{queue_exit_code=$queueExit;status=$state;code=$code}
Annotate-Result $state $code $detail
exit 0
