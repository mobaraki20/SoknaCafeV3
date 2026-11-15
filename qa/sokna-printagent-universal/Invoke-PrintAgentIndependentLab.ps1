[CmdletBinding()]
param(
  [Parameter(Mandatory=$true)][string]$PackageZip,
  [Parameter(Mandatory=$true)][string]$LabRoot,
  [ValidateSet('Probe','Apply')][string]$Mode='Probe',
  [switch]$AllowMutation,
  [switch]$InjectFailureAfterSwap
)
$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest
$expectedZip='464eb276b446ffe6e30bdf05e36a4ef08671324ac0784f830f241d142d19efb5'
$serviceName='SoknaPrintWorker'
$tx=[guid]::NewGuid().ToString('N')
$report=[ordered]@{format='sokna-printagent-independent-lab-v1';transaction_id=$tx;mode=$Mode;status='BLOCKED';reason='NOT_STARTED';old_version=$null;new_version='6.2.12';service_name=$serviceName;rollback='not_required';data_preserved=$false;other_services_untouched=$false;production_allowed=$false}
$oldMoved=$false;$newActivated=$false;$createdSvc=$false;$wasRunning=$false;$lock=$null;$staging=$null;$backup=$null;$oldReceipt=$null;$targetDir=$null
function Fail([string]$code){throw $code}
function Full([string]$path){[IO.Path]::GetFullPath($path).TrimEnd('\')}
function Hash([string]$path){(Get-FileHash -LiteralPath $path -Algorithm SHA256 -ErrorAction Stop).Hash.ToLowerInvariant()}
function CheckNoLink([string]$path){
  $p=Full $path
  while($p -and (Test-Path -LiteralPath $p)){
    $item=Get-Item -LiteralPath $p -Force
    if(($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0){Fail 'REPARSE_POINT_BLOCKED'}
    $parent=Split-Path $p -Parent
    if(!$parent -or $parent -eq $p){break};$p=$parent
  }
}
function VerifyPayload([string]$root){
  $f=Join-Path $root 'component-manifest.json'
  if(!(Test-Path -LiteralPath $f -PathType Leaf)){Fail 'MANIFEST_MISSING'}
  $m=Get-Content -LiteralPath $f -Raw|ConvertFrom-Json
  if($m.format -ne 'sokna-print-agent-component-v1' -or $m.component -ne 'print-agent'){Fail 'MANIFEST_IDENTITY_INVALID'}
  $ver=$null
  if(![version]::TryParse([string]$m.version,[ref]$ver)){Fail 'MANIFEST_VERSION_INVALID'}
  $entries=@($m.files);if($entries.Count -lt 2 -or $entries.Count -gt 100){Fail 'MANIFEST_COUNT_INVALID'}
  $seen=@{}
  foreach($e in $entries){
    $r=[string]$e.path
    if($r -notmatch '^(Service|Worker)/[A-Za-z0-9._/-]+$' -or $r.Contains('..') -or $r.Contains('//')){Fail 'MANIFEST_UNSAFE_PATH'}
    $key=$r.ToLowerInvariant();if($seen.ContainsKey($key)){Fail 'MANIFEST_DUPLICATE_PATH'};$seen[$key]=$true
    $p=Full (Join-Path $root ($r.Replace('/','\')))
    if(!$p.StartsWith(((Full $root)+'\'),[StringComparison]::OrdinalIgnoreCase)){Fail 'MANIFEST_PATH_ESCAPE'}
    CheckNoLink $p
    if(!(Test-Path -LiteralPath $p -PathType Leaf)){Fail 'MANIFEST_FILE_MISSING'}
    if((Get-Item -LiteralPath $p).Length -ne [long]$e.size){Fail 'MANIFEST_FILE_SIZE_DRIFT'}
    if((Hash $p) -ne [string]$e.sha256){Fail 'MANIFEST_FILE_HASH_DRIFT'}
  }
  foreach($rel in @('Service/Sokna.PrintAgent.Service.exe','Worker/Sokna.PrintAgent.Worker.exe')){
    if(!$seen.ContainsKey($rel.ToLowerInvariant())){Fail 'MANIFEST_REQUIRED_EXE_MISSING'}
  }
  $actual=@(Get-ChildItem -LiteralPath $root -Recurse -File -Force)
  foreach($f in $actual){
    $rel=$f.FullName.Substring((Full $root).Length+1).Replace('\','/').ToLowerInvariant()
    if($rel -ne 'component-manifest.json' -and !$seen.ContainsKey($rel)){Fail 'UNMANAGED_FILE_BLOCKED'}
  }
  return $m
}
function CurrentService(){Get-CimInstance Win32_Service -Filter "Name='$serviceName'" -ErrorAction Stop}
function ReadServiceExe([string]$raw){
  if($raw -match '^"([^"]+\.exe)"(?:\s|$)'){return Full $Matches[1]}
  if($raw -match '^([^\s"]+\.exe)(?:\s|$)'){return Full $Matches[1]}
  Fail 'SERVICE_IMAGE_NOT_PARSEABLE'
}
function WaitService([string]$wanted,[int]$sec){
  $svc=Get-Service -Name $serviceName -ErrorAction Stop
  try{$svc.WaitForStatus($wanted,[TimeSpan]::FromSeconds($sec))}finally{$svc.Dispose()}
}
function StartIfNeeded(){if($wasRunning -or $createdSvc){Start-Service -Name $serviceName -ErrorAction Stop;WaitService 'Running' 25}}
function StopIfNeeded(){
  if($wasRunning){Stop-Service -Name $serviceName -ErrorAction Stop;WaitService 'Stopped' 35}
  $state=CurrentService
  if($state -and [string]$state.State -ne 'Stopped'){Fail 'SERVICE_NOT_QUIESCED'}
  if($state -and [int]$state.ProcessId -gt 0){Fail 'SERVICE_PROCESS_STILL_ALIVE'}
}
function SaveReport(){
  try{
    $dir=Join-Path $LabRoot 'reports'
    New-Item -Path $dir -ItemType Directory -Force|Out-Null
    $path=Join-Path $dir ('SOKNA-PRINT-TRANSACTION-'+$tx+'.json')
    $report|ConvertTo-Json -Depth 9|Set-Content -LiteralPath $path -Encoding UTF8
    Write-Host ('RESULT_JSON='+$path)
  }catch{Write-Warning ('QA_REPORT_UNWRITABLE '+$_.Exception.Message)}
}
try{
  if($env:OS -ne 'Windows_NT'){Fail 'WINDOWS_ONLY'}
  if($env:GITHUB_ACTIONS -ne 'true'){Fail 'GITHUB_QA_ONLY'}
  if(!(Test-Path -LiteralPath $PackageZip -PathType Leaf)){Fail 'PAYLOAD_ZIP_NOT_FOUND'}
  if((Hash $PackageZip) -ne $expectedZip){Fail 'PAYLOAD_ZIP_SHA_MISMATCH'}
  $LabRoot=Full $LabRoot;$runnerRoot=Full $env:RUNNER_TEMP
  if(!$LabRoot.StartsWith(($runnerRoot+'\'),[StringComparison]::OrdinalIgnoreCase)){Fail 'LAB_ROOT_OUTSIDE_RUNNER_TEMP'}
  if($Mode -eq 'Apply' -and !$AllowMutation){Fail 'MUTATION_NOT_AUTHORIZED'}
  $parent=Join-Path $LabRoot 'SOKNA Windows Services';$install=Join-Path $parent 'PrintAgent'
  $data=Join-Path $LabRoot 'ProgramData\SOKNA';$agentData=Join-Path $data 'print-worker'
  $receiptDir=Join-Path $data 'setup\print-agent-independent-v1';$receiptPath=Join-Path $receiptDir 'active.json'
  foreach($p in @($LabRoot,$parent,$install,$data,$agentData,$receiptDir)){CheckNoLink $p}
  $targetDir=Join-Path $LabRoot ('target-'+$tx)
  Expand-Archive -LiteralPath $PackageZip -DestinationPath $targetDir -ErrorAction Stop
  $manifest=VerifyPayload $targetDir
  if([string]$manifest.version -ne '6.2.12'){Fail 'TARGET_VERSION_NOT_6212'}
  $snap=CurrentService
  if($snap){
    if([string]$snap.StartName -ne 'LocalSystem'){Fail 'FOREIGN_SERVICE_ACCOUNT'}
    $seenExe=ReadServiceExe ([string]$snap.PathName)
    $wantExe=Full (Join-Path $install 'Service\Sokna.PrintAgent.Service.exe')
    if($seenExe -ne $wantExe){Fail 'FOREIGN_SERVICE_OWNER'}
    if(!(Test-Path -LiteralPath $install -PathType Container)){Fail 'OWNER_ROOT_MISSING'}
    $old=VerifyPayload $install
    $report.old_version=[string]$old.version
    if([version]$old.version -gt [version]'6.2.12'){Fail 'DOWNGRADE_BLOCKED'}
    $wasRunning=[string]$snap.State -eq 'Running'
  }else{
    if(Test-Path -LiteralPath $install){Fail 'ORPHAN_ROOT_BLOCKED'}
    $legacy=Get-Service -Name 'SoknaPrintAgent6' -ErrorAction SilentlyContinue
    if($legacy){Fail 'LEGACY_SERVICE_MIGRATION_NOT_QUALIFIED'}
  }
  if(Test-Path -LiteralPath $receiptPath -PathType Leaf){
    $oldReceipt=Get-Content -LiteralPath $receiptPath -Raw | ConvertFrom-Json
    if([string]$oldReceipt.status -notin @('COMMITTED','ROLLED_BACK','FAILED_BEFORE_MUTATION')){Fail 'PREVIOUS_INDEPENDENT_TRANSACTION_NOT_TERMINAL'}
  }
  $report.status='READY_FOR_GATED_APPLY';$report.reason='PREFLIGHT_PASS'
  if($Mode -eq 'Probe'){Write-Host 'PASS_PRINT_INDEPENDENT_LAB_PREFLIGHT';return}
  $principal=[Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()
  if(!$principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)){Fail 'ADMIN_REQUIRED'}
  New-Item -Path (Join-Path $data 'Maintenance\update') -ItemType Directory -Force|Out-Null
  $lockPath=Join-Path $data 'Maintenance\update\machine-mutation.lock'
  CheckNoLink $lockPath
  try{
    $lock=[IO.File]::Open($lockPath,[IO.FileMode]::OpenOrCreate,[IO.FileAccess]::ReadWrite,[IO.FileShare]::Read)
    $msg=[Text.Encoding]::UTF8.GetBytes('format=sokna-machine-mutation-lease-v1;owner=printagent-independent-lab;transaction='+$tx)
    $lock.SetLength(0);$lock.Write($msg,0,$msg.Length);$lock.Flush($true)
  }catch{Fail 'MACHINE_MUTATION_LEASE_BUSY'}
  # Only the agent's own service may be stopped, and no data file is opened/changed here.
  $rt=(Get-Service 'SoknaRuntime' -ErrorAction SilentlyContinue)
  $maint=(Get-Service 'SoknaMaintenanceSupervisor' -ErrorAction SilentlyContinue)
  $rtState=$(if($rt){[string]$rt.Status}else{'absent'})
  $maintState=$(if($maint){[string]$maint.Status}else{'absent'})
  $marker=Join-Path $agentData 'qa-preserve-marker.bin'
  $markerBefore=$(if(Test-Path -LiteralPath $marker){Hash $marker}else{''})
  $staging=Join-Path $parent ('PrintAgent.__incoming.'+$tx)
  $backup=Join-Path $parent ('PrintAgent.__backup.'+$tx)
  New-Item -Path $staging -ItemType Directory -Force|Out-Null
  Copy-Item -Path (Join-Path $targetDir '*') -Destination $staging -Recurse -Force
  $null=VerifyPayload $staging
  StopIfNeeded
  if(Test-Path -LiteralPath $install){Move-Item -LiteralPath $install -Destination $backup -ErrorAction Stop;$oldMoved=$true}
  Move-Item -LiteralPath $staging -Destination $install -ErrorAction Stop;$newActivated=$true
  if($InjectFailureAfterSwap){Fail 'QA_INJECTED_AFTER_SWAP'}
  if(!$snap){
    $exe=Join-Path $install 'Service\Sokna.PrintAgent.Service.exe'
    New-Service -Name $serviceName -DisplayName 'SOKNA Print Worker (QA isolated)' -BinaryPathName ('"'+$exe+'"') -StartupType Manual -ErrorAction Stop|Out-Null
    $createdSvc=$true
    $reg='HKLM:\SYSTEM\CurrentControlSet\Services\'+$serviceName
    New-ItemProperty -LiteralPath $reg -Name Environment -PropertyType MultiString -Value @('SOKNA_PRINT_WORKER_DATA_ROOT='+$agentData) -Force|Out-Null
  }
  StartIfNeeded
  $readback=VerifyPayload $install
  $nowSvc=CurrentService
  if(!$nowSvc -or (ReadServiceExe ([string]$nowSvc.PathName)) -ne (Full (Join-Path $install 'Service\Sokna.PrintAgent.Service.exe'))){Fail 'SERVICE_READBACK_MISMATCH'}
  if(($wasRunning -or $createdSvc) -and [string]$nowSvc.State -ne 'Running'){Fail 'SERVICE_NOT_RUNNING_AFTER_APPLY'}
  $rtAfter=Get-Service 'SoknaRuntime' -ErrorAction SilentlyContinue
  if($(if($rtAfter){[string]$rtAfter.Status}else{'absent'}) -ne $rtState){Fail 'RUNTIME_STATUS_CHANGED'}
  $maintAfter=Get-Service 'SoknaMaintenanceSupervisor' -ErrorAction SilentlyContinue
  if($(if($maintAfter){[string]$maintAfter.Status}else{'absent'}) -ne $maintState){Fail 'MAINTENANCE_STATUS_CHANGED'}
  $markerAfter=$(if(Test-Path -LiteralPath $marker){Hash $marker}else{''})
  if($markerBefore -ne $markerAfter){Fail 'DURABLE_DATA_MARKER_CHANGED'}
  $report.data_preserved=$true;$report.other_services_untouched=$true
  New-Item -Path $receiptDir -ItemType Directory -Force|Out-Null
  $receipt=[ordered]@{format='sokna-printagent-independent-receipt-v1';schema_version=1;status='COMMITTED';transaction_id=$tx;source_version=$report.old_version;target_version='6.2.12';service_name=$serviceName;image_path=[string]$nowSvc.PathName;installed_root=$install;component_manifest_sha256=(Hash (Join-Path $install 'component-manifest.json'));service_sha256=(Hash (Join-Path $install 'Service\Sokna.PrintAgent.Service.exe'));worker_sha256=(Hash (Join-Path $install 'Worker\Sokna.PrintAgent.Worker.exe'));programdata_preserved=$true;runtime_unchanged=$true;maintenance_unchanged=$true;data_root=$agentData;completed_at_utc=[DateTime]::UtcNow.ToString('o')}
  if(Test-Path -LiteralPath $receiptPath){Copy-Item -LiteralPath $receiptPath -Destination (Join-Path $receiptDir ('previous-'+$tx+'.json')) -ErrorAction Stop}
  $receipt|ConvertTo-Json -Depth 9 |Set-Content -LiteralPath $receiptPath -Encoding UTF8
  $written=Get-Content -LiteralPath $receiptPath -Raw |ConvertFrom-Json
  if($written.transaction_id -ne $tx -or $written.status -ne 'COMMITTED'){Fail 'RECEIPT_READBACK_FAILED'}
  $report.status='COMMITTED_LAB';$report.reason='LAB_APPLY_PASS';$report.rollback='not_required'
  Write-Host 'PASS_PRINT_INDEPENDENT_LAB_TRANSACTION'
}catch{
  $errorCode=$_.Exception.Message;$report.reason=$errorCode
  if($newActivated -or $oldMoved -or $createdSvc){
    try{
      $svc=Get-Service -Name $serviceName -ErrorAction SilentlyContinue
      if($svc -and $svc.Status -ne 'Stopped'){Stop-Service -Name $serviceName -ErrorAction Stop;WaitService 'Stopped' 35}
      if($createdSvc){& "$env:SystemRoot\System32\sc.exe" delete $serviceName |Out-Null;if($LASTEXITCODE -ne 0){Fail 'QA_SERVICE_DELETE_FAILED'}}
      if($newActivated -and (Test-Path -LiteralPath $install)){Remove-Item -LiteralPath $install -Recurse -Force -ErrorAction Stop}
      if($oldMoved){if(!(Test-Path -LiteralPath $backup)){Fail 'ROLLBACK_BACKUP_MISSING'};Move-Item -LiteralPath $backup -Destination $install -ErrorAction Stop}
      if($wasRunning){Start-Service -Name $serviceName -ErrorAction Stop;WaitService 'Running' 25}
      if($oldMoved){$null=VerifyPayload $install}
      $report.rollback='verified';$report.status='ROLLED_BACK_LAB'
      Write-Host 'PASS_PRINT_INDEPENDENT_LAB_ROLLBACK'
    }catch{$report.rollback='FAILED';$report.status='ROLLBACK_FAILED';$report.reason=$errorCode+' | '+$_.Exception.Message}
  }else{$report.status='BLOCKED'}
  Write-Host ('PRINT_INDEPENDENT_LAB_ERROR='+$report.reason)
}finally{
  if($lock){$lock.Dispose()}
  if($staging -and (Test-Path -LiteralPath $staging)){Remove-Item -LiteralPath $staging -Force -Recurse -ErrorAction SilentlyContinue}
  if($backup -and $report.status -eq 'COMMITTED_LAB' -and (Test-Path -LiteralPath $backup)){Remove-Item -LiteralPath $backup -Force -Recurse -ErrorAction SilentlyContinue}
  if($targetDir -and (Test-Path -LiteralPath $targetDir)){Remove-Item -LiteralPath $targetDir -Force -Recurse -ErrorAction SilentlyContinue}
  SaveReport
}
if($report.status -notin @('READY_FOR_GATED_APPLY','COMMITTED_LAB')){exit 1}