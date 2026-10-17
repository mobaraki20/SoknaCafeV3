param([Parameter(Mandatory=$true)][string]$PlanFile)
$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest

function Full([string]$p){[IO.Path]::GetFullPath($p)}
function Write-Json([string]$path,$value){$value|ConvertTo-Json -Depth 14|Set-Content -LiteralPath $path -Encoding UTF8}
function Safe([string]$v){$v=([string]$v-replace'[\r\n\0]',' ').Trim();if($v.Length-gt1200){$v=$v.Substring(0,1200)};return $v}
function Add-Event([string]$name,[string]$result,$data=$null){
  $row=[ordered]@{format='sokna-installer-event-v1';at_utc=[DateTime]::UtcNow.ToString('o');transaction_id=$Tx;correlation_id=$Corr;setup_session_id=$Session;event_name=$name;result=$result;component='local-web-layout';phase='Post-commit adoption queue';data=$data}
  Add-Content -LiteralPath $Events -Value ($row|ConvertTo-Json -Compress -Depth 8) -Encoding UTF8
}
function Annotate-Result([string]$adoptionState,[string]$code,[string]$detail){
  if(-not(Test-Path -LiteralPath $Result -PathType Leaf)){return}
  try{
    $r=Get-Content -LiteralPath $Result -Raw|ConvertFrom-Json
    if([string]$r.status-ne'completed'){return}
    $post=[ordered]@{state=$adoptionState;code=$code;detail=(Safe $detail);windows_components_committed=$true;windows_rollback_performed=$false;activation_performed=$false;database_mutation_performed=$false;public_mutation_performed=$false;windows_reboot_required=$false}
    $r|Add-Member -NotePropertyName post_install_local_adoption -NotePropertyValue $post -Force
    switch($adoptionState){
      'queued'{$r.summary='Windows Services component plan completed successfully. Eligible Local layout adoption was queued durably for Maintenance Supervisor.'}
      'skipped'{$r.summary='Windows Services component plan completed successfully. Local layout adoption was not required for this host/baseline.'}
      default{$r.summary='Windows Services component plan completed successfully. Local layout adoption was deferred and remains a separate Maintenance operation.'}
    }
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
$core=Join-Path $ShellRoot 'setup-windows-components-core-v5.ps1'
$queue=Join-Path $ShellRoot 'queue-local-slot-adoption-r7.ps1'
$ps51=Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\powershell.exe'
if(-not(Test-Path -LiteralPath $ps51 -PathType Leaf)){throw 'Windows PowerShell 5.1 executable is unavailable.'}
if(-not(Test-Path -LiteralPath $core -PathType Leaf)){throw 'Qualified Windows component lifecycle core is missing.'}

# Transaction 1: Windows-owned components only. Its exit code is authoritative for the installer.
$coreProcess=Start-Process $ps51 -ArgumentList @('-NoProfile','-NonInteractive','-ExecutionPolicy','Bypass','-File',('"'+$core+'"'),'-PlanFile',('"'+$PlanFile+'"')) -WindowStyle Hidden -Wait -PassThru
if([int]$coreProcess.ExitCode-ne0){exit [int]$coreProcess.ExitCode}

# Transaction 2 is intentionally NOT an installer transaction and must not synchronously execute Local
# migration. The installer only persists an exact, durable adoption request. Supervisor owns execution,
# retry/resume and recovery after the Windows component transaction has already committed.
if(-not(Test-Path -LiteralPath $queue -PathType Leaf)){
  Add-Event 'local_adoption_queue_missing' 'warning' @{code='ADOPTION_QUEUE_ENTRYPOINT_MISSING';windows_components_committed=$true;windows_rollback_performed=$false}
  Annotate-Result 'deferred' 'ADOPTION_QUEUE_ENTRYPOINT_MISSING' 'Local adoption queue entrypoint is unavailable. Windows Services remains successfully updated.'
  exit 0
}

try{
  $qp=Start-Process $ps51 -ArgumentList @('-NoProfile','-NonInteractive','-ExecutionPolicy','Bypass','-File',('"'+$queue+'"'),'-PlanFile',('"'+$PlanFile+'"')) -WindowStyle Hidden -Wait -PassThru
  $queueExit=[int]$qp.ExitCode
}catch{
  Add-Event 'local_adoption_queue_start_failed' 'warning' @{code='ADOPTION_QUEUE_START_FAILED';error=(Safe $_.Exception.Message);windows_components_committed=$true;windows_rollback_performed=$false}
  Annotate-Result 'deferred' 'ADOPTION_QUEUE_START_FAILED' $_.Exception.Message
  exit 0
}

$evidencePath=Join-Path $TxDir 'local-slot-adoption-queue.json';$qe=$null
if(Test-Path -LiteralPath $evidencePath -PathType Leaf){try{$qe=Get-Content -LiteralPath $evidencePath -Raw|ConvertFrom-Json}catch{}}
$state=$(if($qe-and$qe.PSObject.Properties.Name-contains'status'){[string]$qe.status}else{'deferred'})
$code=$(if($qe-and$qe.PSObject.Properties.Name-contains'code'){[string]$qe.code}else{'ADOPTION_QUEUE_RESULT_UNAVAILABLE'})
$detail=$(if($qe-and$qe.PSObject.Properties.Name-contains'detail'){[string]$qe.detail}else{"Local adoption queue helper exit code: $queueExit"})

if($state-notin@('queued','skipped','deferred')){$state='deferred'}
Add-Event 'local_adoption_queue_recorded' $(if($state-eq'deferred'){'warning'}else{'completed'}) @{queue_exit_code=$queueExit;status=$state;code=$code;windows_components_committed=$true;windows_rollback_performed=$false;activation_performed=$false}
Annotate-Result $state $code $detail

# Critical boundary: Local adoption state can never turn a committed Windows Services update into a
# failed installer transaction. The Supervisor/recovery surfaces own that separate state machine.
exit 0
