param([Parameter(Mandatory=$true)][string]$PlanFile)
$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest

function Full([string]$p){[IO.Path]::GetFullPath($p)}
function Write-Json([string]$path,$value){$value|ConvertTo-Json -Depth 12|Set-Content -LiteralPath $path -Encoding UTF8}
function Safe([string]$v){$v=([string]$v-replace'[\r\n\0]',' ').Trim();if($v.Length-gt1200){$v=$v.Substring(0,1200)};return $v}
function Add-Event([string]$name,[string]$result,$data=$null){
  $row=[ordered]@{format='sokna-installer-event-v1';at_utc=[DateTime]::UtcNow.ToString('o');transaction_id=$Tx;correlation_id=$Corr;setup_session_id=$Session;event_name=$name;result=$result;component='local-web-layout';phase='Post-commit migration bootstrap';data=$data}
  Add-Content -LiteralPath $Events -Value ($row|ConvertTo-Json -Compress -Depth 8) -Encoding UTF8
}
function Rewrite-Result([int]$exitCode,[string]$summary){
  if(Test-Path -LiteralPath $Result -PathType Leaf){
    try{
      $r=Get-Content -LiteralPath $Result -Raw|ConvertFrom-Json
      $r.status='failed';$r.exit_code=$exitCode;$r.summary=(Safe $summary);$r.failed_component='local-web-layout';$r.failed_phase='Post-commit migration bootstrap';$r.rollback_complete=$false
      Write-Json $Result $r
      return
    }catch{}
  }
  Write-Json $Result ([ordered]@{format='sokna-install-result-v1';transaction_id=$Tx;correlation_id=$Corr;completed_at_utc=[DateTime]::UtcNow.ToString('o');status='failed';exit_code=$exitCode;summary=(Safe $summary);failed_component='local-web-layout';failed_phase='Post-commit migration bootstrap';rollback_complete=$false;reboot_required=$false;components=@()})
}

$PlanFile=Full $PlanFile
if(-not(Test-Path -LiteralPath $PlanFile -PathType Leaf)){throw 'Plan file missing.'}
$plan=Get-Content -LiteralPath $PlanFile -Raw|ConvertFrom-Json
if([string]$plan.format-ne'sokna-windows-install-plan-v3'){throw 'Unsupported install plan.'}
$ShellRoot=Full ([string]$plan.shell_root)
$Tx=[string]$plan.transaction_id;$Corr=[string]$plan.correlation_id;$Session=[string]$plan.setup_session_id
$TxDir=[IO.Path]::GetDirectoryName($PlanFile);$Events=Join-Path $TxDir 'system-events.jsonl';$Result=Join-Path $TxDir 'install-result.json'
$core=Join-Path $ShellRoot 'setup-windows-components-core-v5.ps1'
$bootstrap=Join-Path $ShellRoot 'bootstrap-local-slot-r6.ps1'
$ps51=Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\powershell.exe'
if(-not(Test-Path -LiteralPath $ps51 -PathType Leaf)){throw 'Windows PowerShell 5.1 executable is unavailable.'}
if(-not(Test-Path -LiteralPath $core -PathType Leaf)){throw 'Qualified Windows component lifecycle core is missing.'}

$coreProcess=Start-Process $ps51 -ArgumentList @('-NoProfile','-NonInteractive','-ExecutionPolicy','Bypass','-File',('"'+$core+'"'),'-PlanFile',('"'+$PlanFile+'"')) -WindowStyle Hidden -Wait -PassThru
if([int]$coreProcess.ExitCode-ne0){exit [int]$coreProcess.ExitCode}

# From this point the Windows component transaction is already committed. Never roll it
# back because Local layout migration failed; the new Maintenance must remain available
# for deterministic recovery.
if(-not(Test-Path -LiteralPath $bootstrap -PathType Leaf)){
  Add-Event 'local_slot_bootstrap_failed' 'failed' @{code='BOOTSTRAP_ENTRYPOINT_MISSING';windows_components_committed=$true;windows_rollback_performed=$false}
  Rewrite-Result 3 'Windows Services was updated, but the Local layout migration bootstrap entrypoint is missing. Maintenance remains updated so recovery stays available.'
  exit 3
}
try{
  $bp=Start-Process $ps51 -ArgumentList @('-NoProfile','-NonInteractive','-ExecutionPolicy','Bypass','-File',('"'+$bootstrap+'"'),'-PlanFile',('"'+$PlanFile+'"')) -WindowStyle Hidden -Wait -PassThru
  $bootstrapExit=[int]$bp.ExitCode
}catch{
  Add-Event 'local_slot_bootstrap_failed' 'failed' @{code='BOOTSTRAP_START_FAILED';error=(Safe $_.Exception.Message);windows_components_committed=$true;windows_rollback_performed=$false}
  Rewrite-Result 3 'Windows Services was updated, but the Local layout migration bootstrap could not start. Maintenance remains updated so recovery stays available.'
  exit 3
}

$evidencePath=Join-Path $TxDir 'local-slot-bootstrap.json';$be=$null
if(Test-Path -LiteralPath $evidencePath -PathType Leaf){try{$be=Get-Content -LiteralPath $evidencePath -Raw|ConvertFrom-Json}catch{}}
if($bootstrapExit-ne0){
  Add-Event 'local_slot_bootstrap_failed' 'failed' @{exit_code=$bootstrapExit;status=$(if($be){[string]$be.status}else{'unknown'});code=$(if($be){[string]$be.code}else{'BOOTSTRAP_FAILED'});operation_id=$(if($be-and$be.PSObject.Properties.Name-contains'operation_id'){[string]$be.operation_id}else{''});windows_components_committed=$true;windows_rollback_performed=$false}
  Rewrite-Result 3 'Windows Services was updated, but the Local layout migration did not complete. Maintenance remains updated so recovery stays available.'
  exit 3
}

Add-Event 'local_slot_bootstrap_completed' 'completed' @{status=$(if($be){[string]$be.status}else{'completed'});code=$(if($be){[string]$be.code}else{'OK'});operation_id=$(if($be-and$be.PSObject.Properties.Name-contains'operation_id'){[string]$be.operation_id}else{''});windows_components_committed=$true;windows_rollback_performed=$false}
if(Test-Path -LiteralPath $Result -PathType Leaf){
  try{$r=Get-Content -LiteralPath $Result -Raw|ConvertFrom-Json;$r.summary='Windows Services component plan completed; eligible Local slot migration bootstrap also completed or was safely skipped.';Write-Json $Result $r}catch{}
}
exit 0
