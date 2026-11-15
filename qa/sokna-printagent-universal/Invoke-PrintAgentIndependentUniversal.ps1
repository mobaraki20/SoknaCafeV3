# R239 universal updater candidate. The same transaction core runs in isolated CI or production location.
# NOT PRODUCTION APPROVED until Windows Services integration, real 6.2.11, queue and rollback gates pass.
[CmdletBinding()]
param(
  [Parameter(Mandatory=$true)][string]$PackageZip,
  [string]$LabRoot='',
  [Parameter(Mandatory=$true)][string]$QueueGuardExe,
  [ValidateSet('Probe','Apply')][string]$Mode='Probe',
  [switch]$AllowMutation,
  [switch]$InjectFailureAfterSwap,
  [switch]$InjectHardCrashAfterOldMove,
  [switch]$InjectHardCrashAfterSwap
)
$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest
$expectedZip='464eb276b446ffe6e30bdf05e36a4ef08671324ac0784f830f241d142d19efb5'
$serviceName='SoknaPrintWorker'
$tx=[guid]::NewGuid().ToString('N')
$report=[ordered]@{format='sokna-printagent-independent-updater-v1';transaction_id=$tx;mode=$Mode;status='BLOCKED';reason='NOT_STARTED';old_version=$null;new_version='6.2.12';service_name=$serviceName;rollback='not_required';data_preserved=$false;other_services_untouched=$false;production_approved_by_ci=$false}
$oldMoved=$false;$newActivated=$false;$createdSvc=$false;$wasRunning=$false;$lock=$null;$staging=$null;$backup=$null;$oldReceipt=$null;$targetDir=$null;$runtimeStopped=$false;$runtimeWasRunning=$false;$journal=$null;$pending=$null
function Fail([string]$code){throw $code}
function Full([string]$path){[IO.Path]::GetFullPath($path).TrimEnd('\')}
function Hash([string]$path){
  $fs=[IO.File]::Open($path,[IO.FileMode]::Open,[IO.FileAccess]::Read,[IO.FileShare]::Read)
  try{
    $sha=[Security.Cryptography.SHA256]::Create()
    try{return ([BitConverter]::ToString($sha.ComputeHash($fs))).Replace('-','').ToLowerInvariant()}
    finally{$sha.Dispose()}
  }finally{$fs.Dispose()}
}
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
  if(!(Test-Path -LiteralPath $PackageZip -PathType Leaf)){Fail 'PAYLOAD_ZIP_NOT_FOUND'}
  if((Hash $PackageZip) -ne $expectedZip){Fail 'PAYLOAD_ZIP_SHA_MISMATCH'}
  if(!(Test-Path -LiteralPath $QueueGuardExe -PathType Leaf)){Fail 'READONLY_QUEUE_GUARD_MISSING'}
  if($LabRoot){
    if($env:GITHUB_ACTIONS -ne 'true'){Fail 'QA_ROOT_NOT_ALLOWED_OUTSIDE_WINDOWS_RUNNER'}
    $LabRoot=Full $LabRoot;$runnerRoot=Full $env:RUNNER_TEMP
    if(!$LabRoot.StartsWith(($runnerRoot+'\'),[StringComparison]::OrdinalIgnoreCase)){Fail 'QA_ROOT_OUTSIDE_RUNNER_TEMP'}
    $parent=Join-Path $LabRoot 'SOKNA Windows Services'
    $data=Join-Path $LabRoot 'ProgramData\SOKNA'
    $agentData=Join-Path $data 'print-worker'
    $report.test_mode=$true
  }else{
    $parent=Join-Path ([Environment]::GetFolderPath('ProgramFiles')) 'SOKNA Windows Services'
    $data=Join-Path ([Environment]::GetFolderPath('CommonApplicationData')) 'SOKNA'
    $agentData=Join-Path $data 'print-worker'
    # Match historical AgentPaths.Default() resolution exactly: service-specific
    # process Environment (primary/fallback), then Local registry, then legacy
    # PrintAgent registry. Never assume a newer default when upgrading old data.
    $configured=''
    if(Get-Service -Name $serviceName -ErrorAction SilentlyContinue){
      $envKey='HKLM:\SYSTEM\CurrentControlSet\Services\'+$serviceName
      $entries=@((Get-ItemProperty -LiteralPath $envKey -Name Environment -ErrorAction SilentlyContinue).Environment)
      foreach($key in @('SOKNA_PRINT_WORKER_DATA_ROOT','SOKNA_PRINT_AGENT_DATA_ROOT')){
        foreach($entry in $entries){
          $prefix=$key+'='
          if(([string]$entry).StartsWith($prefix,[StringComparison]::OrdinalIgnoreCase)){
            $configured=([string]$entry).Substring($prefix.Length).Trim()
            if($configured){break}
          }
        }
        if($configured){break}
      }
    }
    if(!$configured){
      foreach($agentRegistry in @('HKLM:\SOFTWARE\Sokna\Local\PrintWorker','HKLM:\SOFTWARE\Sokna\PrintAgent')){
        if(Test-Path -LiteralPath $agentRegistry){
          $value=Get-ItemProperty -LiteralPath $agentRegistry -Name DataRoot -ErrorAction SilentlyContinue
          $configured=[string]$value.DataRoot
          if($configured){break}
        }
      }
    }
    if($configured){$agentData=Full ([Environment]::ExpandEnvironmentVariables($configured))}
    $LabRoot=Join-Path $data 'setup\print-agent-independent-v1\work'
    $report.test_mode=$false
  }
  $install=Join-Path $parent 'PrintAgent'
  if(($InjectHardCrashAfterOldMove -or $InjectHardCrashAfterSwap) -and
      (!$LabRoot -or $env:GITHUB_ACTIONS -ne 'true')){Fail 'HARD_CRASH_INJECTION_QA_ONLY'}
  if($Mode -eq 'Apply' -and !$AllowMutation){Fail 'MUTATION_NOT_AUTHORIZED'}
  # A customized queue location must be read, never overwritten or reset.
  if($agentData.Length -lt 10 -or !([IO.Path]::IsPathRooted($agentData))){Fail 'UNQUALIFIED_QUEUE_DATA_ROOT'}
  $receiptDir=Join-Path $data 'setup\print-agent-independent-v1';$receiptPath=Join-Path $receiptDir 'active.json'
  foreach($p in @($LabRoot,$parent,$install,$data,$agentData,$receiptDir)){CheckNoLink $p}

  $journal=Join-Path $receiptDir 'pending.json'
  CheckNoLink $journal
  function AcquireLease {
    if($script:lock){return}
    New-Item -Path (Join-Path $data 'Maintenance\update') -ItemType Directory -Force|Out-Null
    $lockPath=Join-Path $data 'Maintenance\update\machine-mutation.lock'
    CheckNoLink $lockPath
    try{
      $script:lock=[IO.File]::Open($lockPath,[IO.FileMode]::OpenOrCreate,[IO.FileAccess]::ReadWrite,[IO.FileShare]::Read)
      $bytes=[Text.Encoding]::UTF8.GetBytes('format=sokna-machine-mutation-lease-v1;owner=printagent-independent;transaction='+$tx)
      $script:lock.SetLength(0);$script:lock.Write($bytes,0,$bytes.Length);$script:lock.Flush($true)
    }catch{Fail 'MACHINE_MUTATION_LEASE_BUSY'}
  }
  function WritePending([string]$phase){
    if(!$script:pending){Fail 'JOURNAL_NOT_PREPARED'}
    $script:pending.phase=$phase
    New-Item -Path $receiptDir -ItemType Directory -Force|Out-Null
    $temp=Join-Path $receiptDir ('pending-'+$tx+'.tmp')
    $script:pending | ConvertTo-Json -Depth 8 | Set-Content -LiteralPath $temp -Encoding UTF8 -ErrorAction Stop
    if(Test-Path -LiteralPath $journal){
      [IO.File]::Replace($temp,$journal,$null)
    }else{
      [IO.File]::Move($temp,$journal)
    }
  }
  function IsOld([string]$path,$j){
    if(!(Test-Path -LiteralPath $path -PathType Container)){return $false}
    try{
      $v=VerifyPayload $path
      return ([string]$v.version -eq [string]$j.old_version -and
        (Hash (Join-Path $path 'component-manifest.json')) -eq [string]$j.old_manifest_sha -and
        (Hash (Join-Path $path 'Service\Sokna.PrintAgent.Service.exe')) -eq [string]$j.old_service_sha -and
        (Hash (Join-Path $path 'Worker\Sokna.PrintAgent.Worker.exe')) -eq [string]$j.old_worker_sha)
    }catch{return $false}
  }
  function IsTarget([string]$path){
    if(!(Test-Path -LiteralPath $path -PathType Container)){return $false}
    try{
      $v=VerifyPayload $path
      return ([string]$v.version -eq '6.2.12' -and
        (Hash (Join-Path $path 'Service\Sokna.PrintAgent.Service.exe')) -eq 'b28f0028f3be5f0bf4a1ee49d5ddce06e60b95a56f28300d4ac17b05f9f6051b' -and
        (Hash (Join-Path $path 'Worker\Sokna.PrintAgent.Worker.exe')) -eq 'cc638a50f191374a015341b27b48b5dccdf0f3017481a7b0454e2c67ea95873b')
    }catch{return $false}
  }
  # Only a recorded, SHA-pinned in-flight transaction may be recovered.
  # No unrecognized PrintAgent.__backup/incoming path is ever assumed safe.
  if(Test-Path -LiteralPath $journal -PathType Leaf){
    $j=Get-Content -LiteralPath $journal -Raw -ErrorAction Stop|ConvertFrom-Json -ErrorAction Stop
    $oldId=[Guid]::Empty
    if([string]$j.format-ne'sokna-printagent-pending-transaction-v1' -or
       -not[Guid]::TryParseExact([string]$j.transaction_id,'N',[ref]$oldId) -or
       [string]$j.target_version-ne'6.2.12' -or
       [string]$j.service_name-ne'SoknaPrintWorker' -or
       [string]$j.phase-notin@('PREPARED','OLD_MOVED','NEW_ACTIVE','COMMITTED')){
      Fail 'PENDING_JOURNAL_NOT_QUALIFIED'
    }
    $restoredBackup=Join-Path $parent ('PrintAgent.__backup.'+[string]$j.transaction_id)
    $incoming=Join-Path $parent ('PrintAgent.__incoming.'+[string]$j.transaction_id)
    foreach($p in @($restoredBackup,$incoming)){CheckNoLink $p}
    AcquireLease
    $oldPresent=([string]$j.old_version -ne '')
    $backupPresent=Test-Path -LiteralPath $restoredBackup -PathType Container
    if($backupPresent -and (!$oldPresent -or !(IsOld $restoredBackup $j))){Fail 'JOURNAL_BACKUP_IDENTITY_UNQUALIFIED'}
    $originalHere=($oldPresent -and (IsOld $install $j))
    $targetHere=IsTarget $install
    if((Test-Path -LiteralPath $install -PathType Container) -and !$originalHere -and !$targetHere){Fail 'JOURNAL_CURRENT_INSTALL_IDENTITY_UNQUALIFIED'}
    if($oldPresent -and !$backupPresent -and !$originalHere){
      # Commit may have finished before cleanup, accept only an exact signed receipt.
      $previousReceipt=$null
      if(Test-Path -LiteralPath $receiptPath -PathType Leaf){
        $previousReceipt=Get-Content -LiteralPath $receiptPath -Raw|ConvertFrom-Json
      }
      if(!$targetHere -or !$previousReceipt -or
         [string]$previousReceipt.status-ne'COMMITTED' -or
         [string]$previousReceipt.service_sha256-ne'b28f0028f3be5f0bf4a1ee49d5ddce06e60b95a56f28300d4ac17b05f9f6051b'){
        Fail 'JOURNAL_OLD_BACKUP_MISSING'
      }
    }
    $restoreSvc=Get-CimInstance Win32_Service -Filter "Name='$serviceName'" -ErrorAction SilentlyContinue
    if($restoreSvc){
      if([string]$restoreSvc.StartName-ne'LocalSystem' -or
         (ReadServiceExe ([string]$restoreSvc.PathName)) -ne
            (Full (Join-Path $install 'Service\Sokna.PrintAgent.Service.exe'))){
        Fail 'JOURNAL_SERVICE_OWNER_UNSAFE'
      }
    }
    $rtRecover=Get-CimInstance Win32_Service -Filter "Name='SoknaRuntime'" -ErrorAction SilentlyContinue
    $runRuntime=($rtRecover -and [string]$rtRecover.State-eq'Running')
    $shouldRestoreRuntime=([string]$j.runtime_previously_running-eq'True')
    if($shouldRestoreRuntime -and !$rtRecover){Fail 'JOURNAL_RUNTIME_SERVICE_MISSING'}
    if($runRuntime -or $shouldRestoreRuntime){
      if((ReadServiceExe ([string]$rtRecover.PathName)) -ne
          (Full (Join-Path $parent 'Runtime\SoknaRuntimeService.exe'))){Fail 'JOURNAL_RUNTIME_OWNER_UNSAFE'}
      if($runRuntime){
        Stop-Service SoknaRuntime -ErrorAction Stop
        (Get-Service SoknaRuntime).WaitForStatus('Stopped',[TimeSpan]::FromSeconds(35))
      }
      $runtimeStopped=$shouldRestoreRuntime;$runtimeWasRunning=$shouldRestoreRuntime
    }
    if($restoreSvc -and [string]$restoreSvc.State-eq'Running'){
      Stop-Service $serviceName -ErrorAction Stop
      (Get-Service $serviceName).WaitForStatus('Stopped',[TimeSpan]::FromSeconds(35))
    }
    $committedReceipt=$null
    if($targetHere -and (Test-Path -LiteralPath $receiptPath -PathType Leaf)){
      $committedReceipt=Get-Content -LiteralPath $receiptPath -Raw|ConvertFrom-Json
    }
    $committedTarget=($targetHere -and $committedReceipt -and
      [string]$committedReceipt.status-eq'COMMITTED' -and
      [string]$committedReceipt.target_version-eq'6.2.12' -and
      [string]$committedReceipt.service_sha256-eq'b28f0028f3be5f0bf4a1ee49d5ddce06e60b95a56f28300d4ac17b05f9f6051b' -and
      [string]$committedReceipt.worker_sha256-eq'cc638a50f191374a015341b27b48b5dccdf0f3017481a7b0454e2c67ea95873b')
    if($backupPresent -and $committedTarget){
      # Crash after successful commit: keep committed target; only remove an exact backup.
      Remove-Item -LiteralPath $restoredBackup -Recurse -Force -ErrorAction Stop
      $report.rollback='not_required'
    }elseif($backupPresent){
      if($targetHere){Remove-Item -LiteralPath $install -Recurse -Force -ErrorAction Stop}
      if($originalHere){Remove-Item -LiteralPath $restoredBackup -Recurse -Force -ErrorAction Stop}
      else{Move-Item -LiteralPath $restoredBackup -Destination $install -ErrorAction Stop}
      if(!(IsOld $install $j)){Fail 'JOURNAL_OLD_RESTORE_READBACK_FAILED'}
      $report.rollback='verified'
    }elseif(!$oldPresent -and $targetHere){
      if($restoreSvc){
        Stop-Service -Name $serviceName -ErrorAction SilentlyContinue
        & "$env:SystemRoot\System32\sc.exe" delete $serviceName|Out-Null
        if($LASTEXITCODE -ne 0){Fail 'JOURNAL_CLEAN_SERVICE_DELETE_FAILED'}
      }
      Remove-Item -LiteralPath $install -Recurse -Force -ErrorAction Stop
      $report.rollback='verified'
    }elseif($oldPresent -and $targetHere){
      # Committed new bits, matching receipt; keep target.
      $report.rollback='not_required'
    }elseif($oldPresent -and $originalHere){
      $report.rollback='not_required'
    }elseif($oldPresent){
      Fail 'JOURNAL_UNEXPECTED_FILESYSTEM_STATE'
    }
    if(Test-Path -LiteralPath $incoming -PathType Container){
      if(!(IsTarget $incoming)){Fail 'JOURNAL_INCOMING_FILES_UNQUALIFIED'}
      Remove-Item -LiteralPath $incoming -Recurse -Force -ErrorAction Stop
    }
    if($restoreSvc -and [string]$j.print_previously_running-eq'True'){
      $now=Get-Service -Name $serviceName -ErrorAction Stop
      if([string]$now.Status -ne 'Running'){
        Start-Service -Name $serviceName -ErrorAction Stop
        $now.WaitForStatus('Running',[TimeSpan]::FromSeconds(35))
      }
    }
    if($runtimeStopped){
      Start-Service SoknaRuntime -ErrorAction Stop
      (Get-Service SoknaRuntime).WaitForStatus('Running',[TimeSpan]::FromSeconds(35))
      $runtimeStopped=$false
    }
    Remove-Item -LiteralPath $journal -Force -ErrorAction Stop
    Write-Host 'PASS_PRINT_INDEPENDENT_PREVIOUS_CRASH_RECOVERED'
    $report.previous_crash_recovered=$true
  }
  $targetDir=Join-Path $LabRoot ('target-'+$tx)
  Add-Type -AssemblyName System.IO.Compression.FileSystem -ErrorAction Stop
  [IO.Compression.ZipFile]::ExtractToDirectory($PackageZip,$targetDir)
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
    if([string]$oldReceipt.status -eq 'COMMITTED'){
      if(!$snap -or [string]$oldReceipt.target_version -ne [string]$report.old_version -or
         [string]$oldReceipt.component_manifest_sha256 -ne (Hash (Join-Path $install 'component-manifest.json')) -or
         [string]$oldReceipt.service_sha256 -ne (Hash (Join-Path $install 'Service\Sokna.PrintAgent.Service.exe')) -or
         [string]$oldReceipt.worker_sha256 -ne (Hash (Join-Path $install 'Worker\Sokna.PrintAgent.Worker.exe'))){
        Fail 'PREVIOUS_INDEPENDENT_RECEIPT_IDENTITY_DRIFT'
      }
    }
  }
  if(Test-Path -LiteralPath $parent){
    $incomplete=@(Get-ChildItem -LiteralPath $parent -Directory -ErrorAction Stop | Where-Object {$_.Name -match '^PrintAgent\.__(incoming|backup)\.'})
    if($incomplete.Count -gt 0){Fail 'PREVIOUS_TRANSACTION_DIRECTORY_REQUIRES_RECOVERY'}
  }
  # Idempotent verified replay must not inspect or block active jobs: it does
  # not touch services, queue data, registry, or binary files at all.
  if($Mode -eq 'Apply' -and $snap -and [string]$report.old_version -eq '6.2.12' -and $oldReceipt -and
     [string]$oldReceipt.status -eq 'COMMITTED' -and
     [string]$oldReceipt.component_manifest_sha256 -eq (Hash (Join-Path $install 'component-manifest.json'))){
    $report.status='UNCHANGED';$report.reason='BYTE_IDENTICAL_INDEPENDENT_INSTALL_ALREADY_COMMITTED'
    $report.data_preserved=$true;$report.other_services_untouched=$true
    Write-Host 'PASS_PRINT_INDEPENDENT_UNIVERSAL_IDEMPOTENT_NO_STOP_OR_RESTART'
    return
  }
  # Preflight must be read-only and block any in-flight print work or corrupt SQLite.
  # This check is repeated after quiescing services to close the claim/stop race.
  $queueGuardChecks=0
  function CheckQueueIdle {
    $script:queueGuardChecks++
    $output=@(& $QueueGuardExe --data-root $agentData)
    $exitCode=$LASTEXITCODE
    $safeReason='UNKNOWN'
    try{
      $json=($output -join '')|ConvertFrom-Json -ErrorAction Stop
      $safeReason=[string]$json.reason
      if($safeReason -notmatch '^[A-Z0-9_]{2,64}
  if($snap -and !(Test-Path -LiteralPath (Join-Path $agentData 'queue.db') -PathType Leaf)){Fail 'EXISTING_AGENT_QUEUE_DATABASE_MISSING'}
  CheckQueueIdle
  $report.status='READY_FOR_GATED_APPLY';$report.reason='PREFLIGHT_PASS'
  if($Mode -eq 'Probe'){Write-Host 'PASS_PRINT_INDEPENDENT_LAB_PREFLIGHT';return}
  $principal=[Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()
  if(!$principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)){Fail 'ADMIN_REQUIRED'}
  AcquireLease
  # Only the agent's own service may be stopped, and no data file is opened/changed here.
  $rt=(Get-Service 'SoknaRuntime' -ErrorAction SilentlyContinue)
  $maint=(Get-Service 'SoknaMaintenanceSupervisor' -ErrorAction SilentlyContinue)
  $rtState=$(if($rt){[string]$rt.Status}else{'absent'})
  $maintState=$(if($maint){[string]$maint.Status}else{'absent'})
  # Windows Runtime auto-restarts SoknaPrintWorker every ~2 sec. To avoid a racing
  # restart while its owned binaries are renamed, temporarily quiesce only this
  # known supervisor. Its executable, settings and durable data are never changed.
  $runtimeWasRunning=($null -ne $rt -and [string]$rt.Status -eq 'Running')
  $marker=Join-Path $agentData 'qa-preserve-marker.bin'
  $markerBefore=$(if(Test-Path -LiteralPath $marker){Hash $marker}else{''})
  $staging=Join-Path $parent ('PrintAgent.__incoming.'+$tx)
  $backup=Join-Path $parent ('PrintAgent.__backup.'+$tx)
  New-Item -Path $staging -ItemType Directory -Force|Out-Null
  Copy-Item -Path (Join-Path $targetDir '*') -Destination $staging -Recurse -Force
  $null=VerifyPayload $staging
  # Durable rollback identity is committed before stopping the old process.
  $pending=[ordered]@{
    format='sokna-printagent-pending-transaction-v1';transaction_id=$tx;phase='PREPARED'
    service_name=$serviceName;target_version='6.2.12'
    old_version=$(if($snap){[string]$old.version}else{''})
    old_manifest_sha=$(if($snap){Hash (Join-Path $install 'component-manifest.json')}else{''})
    old_service_sha=$(if($snap){Hash (Join-Path $install 'Service\Sokna.PrintAgent.Service.exe')}else{''})
    old_worker_sha=$(if($snap){Hash (Join-Path $install 'Worker\Sokna.PrintAgent.Worker.exe')}else{''})
    print_previously_running=[string]$wasRunning
    runtime_previously_running=[string]$runtimeWasRunning
  }
  WritePending 'PREPARED'
  if($runtimeWasRunning){
    $rinfo=Get-CimInstance Win32_Service -Filter "Name='SoknaRuntime'" -ErrorAction Stop
    $runtimeImage=ReadServiceExe ([string]$rinfo.PathName)
    $expectedRuntime=Full (Join-Path $parent 'Runtime\SoknaRuntimeService.exe')
    if($runtimeImage -ne $expectedRuntime){Fail 'FOREIGN_RUNTIME_SUPERVISOR_OWNER'}
    Stop-Service -Name 'SoknaRuntime' -ErrorAction Stop
    $rservice=Get-Service -Name 'SoknaRuntime' -ErrorAction Stop
    try{$rservice.WaitForStatus('Stopped',[TimeSpan]::FromSeconds(40))}finally{$rservice.Dispose()}
    $runtimeStopped=$true
    Write-Host 'PASS_PRINT_R238_RUNTIME_SUPERVISION_QUIESCED_WITHOUT_BINARY_MUTATION'
  }
  StopIfNeeded
  # Windows print spooler must be idle before replacing binaries; fail closed.
  $pendingSpool=@(Get-CimInstance Win32_PrintJob -ErrorAction Stop)
  if($pendingSpool.Count -gt 0){Fail 'WINDOWS_SPOOLER_JOBS_PENDING'}
  $workers=@(Get-CimInstance Win32_Process -Filter "Name='Sokna.PrintAgent.Worker.exe'" -ErrorAction Stop)
  if($workers.Count -gt 0){Fail 'PRINT_WORKER_PROCESS_NOT_QUIESCENT'}
  CheckQueueIdle
  if(Test-Path -LiteralPath $install){Move-Item -LiteralPath $install -Destination $backup -ErrorAction Stop;$oldMoved=$true}
  WritePending 'OLD_MOVED'
  if($InjectHardCrashAfterOldMove){Stop-Process -Id $PID -Force}
  Move-Item -LiteralPath $staging -Destination $install -ErrorAction Stop;$newActivated=$true
  WritePending 'NEW_ACTIVE'
  if($InjectHardCrashAfterSwap){Stop-Process -Id $PID -Force}
  if($InjectFailureAfterSwap){Fail 'QA_INJECTED_AFTER_SWAP'}
  if(!$snap){
    $exe=Join-Path $install 'Service\Sokna.PrintAgent.Service.exe'
    New-Service -Name $serviceName -DisplayName 'SOKNA Print Worker' -BinaryPathName ('"'+$exe+'"') -StartupType Manual -ErrorAction Stop|Out-Null
    $createdSvc=$true
    $reg='HKLM:\SYSTEM\CurrentControlSet\Services\'+$serviceName
    if($report.test_mode){
      New-ItemProperty -LiteralPath $reg -Name Environment -PropertyType MultiString -Value @('SOKNA_PRINT_WORKER_DATA_ROOT='+$agentData) -Force|Out-Null
    }else{
      $cfg='HKLM:\SOFTWARE\Sokna\Local\PrintWorker'
      New-Item -Path $cfg -Force|Out-Null
      New-ItemProperty -LiteralPath $cfg -Name DataRoot -PropertyType String -Value $agentData -Force|Out-Null
      Set-Service -Name $serviceName -StartupType Automatic -ErrorAction Stop
    }
  }
  StartIfNeeded
  $readback=VerifyPayload $install
  $nowSvc=CurrentService
  if(!$nowSvc -or (ReadServiceExe ([string]$nowSvc.PathName)) -ne (Full (Join-Path $install 'Service\Sokna.PrintAgent.Service.exe'))){Fail 'SERVICE_READBACK_MISMATCH'}
  if(($wasRunning -or $createdSvc) -and [string]$nowSvc.State -ne 'Running'){Fail 'SERVICE_NOT_RUNNING_AFTER_APPLY'}
  if($runtimeStopped){
    Start-Service -Name 'SoknaRuntime' -ErrorAction Stop
    $rservice=Get-Service -Name 'SoknaRuntime' -ErrorAction Stop
    try{$rservice.WaitForStatus('Running',[TimeSpan]::FromSeconds(35))}finally{$rservice.Dispose()}
    $runtimeStopped=$false
  }
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
  WritePending 'COMMITTED'
  $report.status='COMMITTED';$report.reason='LAB_APPLY_PASS';$report.rollback='not_required'
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
      $report.rollback='verified';$report.status='ROLLED_BACK'
      Write-Host 'PASS_PRINT_INDEPENDENT_LAB_ROLLBACK'
    }catch{$report.rollback='FAILED';$report.status='ROLLBACK_FAILED';$report.reason=$errorCode+' | '+$_.Exception.Message}
  }else{
    # A failed preflight after quiescing services must restore Print before
    # the Runtime supervisor is restarted; never leave a healthy cafe offline.
    if($wasRunning){
      try{
        $previous=Get-Service -Name $serviceName -ErrorAction Stop
        if([string]$previous.Status -ne 'Running'){
          Start-Service -Name $serviceName -ErrorAction Stop
          WaitService 'Running' 35
        }
        $report.status='BLOCKED_RESTORED'
      }catch{
        $report.status='PREFLIGHT_RESTORE_FAILED'
        $report.reason=[string]$report.reason+' | PRINT_RESTART_FAILED: '+$_.Exception.Message
      }
    }else{$report.status='BLOCKED'}
  }
  Write-Host ('PRINT_INDEPENDENT_UPDATER_ERROR='+$report.reason)
}finally{
  if($runtimeStopped -and $runtimeWasRunning){
    try{
      Start-Service -Name 'SoknaRuntime' -ErrorAction Stop
      $rservice=Get-Service 'SoknaRuntime' -ErrorAction Stop
      try{$rservice.WaitForStatus('Running',[TimeSpan]::FromSeconds(35))}finally{$rservice.Dispose()}
      $runtimeStopped=$false
    }catch{
      $report.status='RUNTIME_RESTORE_FAILED'
      $report.reason=[string]$report.reason+' | RUNTIME_RESTART_FAILED: '+$_.Exception.Message
      $report.other_services_untouched=$false
    }
  }
  if($lock){$lock.Dispose()}
  if($staging -and (Test-Path -LiteralPath $staging)){Remove-Item -LiteralPath $staging -Force -Recurse -ErrorAction SilentlyContinue}
  if($backup -and $report.status -eq 'COMMITTED' -and (Test-Path -LiteralPath $backup)){Remove-Item -LiteralPath $backup -Force -Recurse -ErrorAction SilentlyContinue}
  if($journal -and (Test-Path -LiteralPath $journal) -and $report.status -in @('COMMITTED','ROLLED_BACK','BLOCKED_RESTORED','BLOCKED')){
    # Leave the journal if any verified old backup is still present.
    if(!$backup -or !(Test-Path -LiteralPath $backup)){
      Remove-Item -LiteralPath $journal -Force -ErrorAction SilentlyContinue
    }
  }
  if($targetDir -and (Test-Path -LiteralPath $targetDir)){Remove-Item -LiteralPath $targetDir -Force -Recurse -ErrorAction SilentlyContinue}
  SaveReport
}
if($report.status -notin @('READY_FOR_GATED_APPLY','COMMITTED','UNCHANGED')){exit 1}){$safeReason='UNREADABLE'}
    }catch{$safeReason='UNREADABLE'}
    Write-Host ('PRINT_QUEUE_INSPECTION_PHASE='+$script:queueGuardChecks+' STATUS='+$safeReason+' EXIT='+$exitCode)
    if($exitCode -ne 0){Fail ('PRINT_QUEUE_BLOCKED_PHASE_'+$script:queueGuardChecks+'_CODE_'+$exitCode+'_REASON_'+$safeReason)}
    $global:LASTEXITCODE=0
  }
  if($snap -and !(Test-Path -LiteralPath (Join-Path $agentData 'queue.db') -PathType Leaf)){Fail 'EXISTING_AGENT_QUEUE_DATABASE_MISSING'}
  CheckQueueIdle
  $report.status='READY_FOR_GATED_APPLY';$report.reason='PREFLIGHT_PASS'
  if($Mode -eq 'Probe'){Write-Host 'PASS_PRINT_INDEPENDENT_LAB_PREFLIGHT';return}
  $principal=[Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()
  if(!$principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)){Fail 'ADMIN_REQUIRED'}
  AcquireLease
  # Only the agent's own service may be stopped, and no data file is opened/changed here.
  $rt=(Get-Service 'SoknaRuntime' -ErrorAction SilentlyContinue)
  $maint=(Get-Service 'SoknaMaintenanceSupervisor' -ErrorAction SilentlyContinue)
  $rtState=$(if($rt){[string]$rt.Status}else{'absent'})
  $maintState=$(if($maint){[string]$maint.Status}else{'absent'})
  # Windows Runtime auto-restarts SoknaPrintWorker every ~2 sec. To avoid a racing
  # restart while its owned binaries are renamed, temporarily quiesce only this
  # known supervisor. Its executable, settings and durable data are never changed.
  $runtimeWasRunning=($null -ne $rt -and [string]$rt.Status -eq 'Running')
  $marker=Join-Path $agentData 'qa-preserve-marker.bin'
  $markerBefore=$(if(Test-Path -LiteralPath $marker){Hash $marker}else{''})
  $staging=Join-Path $parent ('PrintAgent.__incoming.'+$tx)
  $backup=Join-Path $parent ('PrintAgent.__backup.'+$tx)
  New-Item -Path $staging -ItemType Directory -Force|Out-Null
  Copy-Item -Path (Join-Path $targetDir '*') -Destination $staging -Recurse -Force
  $null=VerifyPayload $staging
  # Durable rollback identity is committed before stopping the old process.
  $pending=[ordered]@{
    format='sokna-printagent-pending-transaction-v1';transaction_id=$tx;phase='PREPARED'
    service_name=$serviceName;target_version='6.2.12'
    old_version=$(if($snap){[string]$old.version}else{''})
    old_manifest_sha=$(if($snap){Hash (Join-Path $install 'component-manifest.json')}else{''})
    old_service_sha=$(if($snap){Hash (Join-Path $install 'Service\Sokna.PrintAgent.Service.exe')}else{''})
    old_worker_sha=$(if($snap){Hash (Join-Path $install 'Worker\Sokna.PrintAgent.Worker.exe')}else{''})
    print_previously_running=[string]$wasRunning
    runtime_previously_running=[string]$runtimeWasRunning
  }
  WritePending 'PREPARED'
  if($runtimeWasRunning){
    $rinfo=Get-CimInstance Win32_Service -Filter "Name='SoknaRuntime'" -ErrorAction Stop
    $runtimeImage=ReadServiceExe ([string]$rinfo.PathName)
    $expectedRuntime=Full (Join-Path $parent 'Runtime\SoknaRuntimeService.exe')
    if($runtimeImage -ne $expectedRuntime){Fail 'FOREIGN_RUNTIME_SUPERVISOR_OWNER'}
    Stop-Service -Name 'SoknaRuntime' -ErrorAction Stop
    $rservice=Get-Service -Name 'SoknaRuntime' -ErrorAction Stop
    try{$rservice.WaitForStatus('Stopped',[TimeSpan]::FromSeconds(40))}finally{$rservice.Dispose()}
    $runtimeStopped=$true
    Write-Host 'PASS_PRINT_R238_RUNTIME_SUPERVISION_QUIESCED_WITHOUT_BINARY_MUTATION'
  }
  StopIfNeeded
  # Windows print spooler must be idle before replacing binaries; fail closed.
  $pendingSpool=@(Get-CimInstance Win32_PrintJob -ErrorAction Stop)
  if($pendingSpool.Count -gt 0){Fail 'WINDOWS_SPOOLER_JOBS_PENDING'}
  $workers=@(Get-CimInstance Win32_Process -Filter "Name='Sokna.PrintAgent.Worker.exe'" -ErrorAction Stop)
  if($workers.Count -gt 0){Fail 'PRINT_WORKER_PROCESS_NOT_QUIESCENT'}
  CheckQueueIdle
  if(Test-Path -LiteralPath $install){Move-Item -LiteralPath $install -Destination $backup -ErrorAction Stop;$oldMoved=$true}
  WritePending 'OLD_MOVED'
  if($InjectHardCrashAfterOldMove){Stop-Process -Id $PID -Force}
  Move-Item -LiteralPath $staging -Destination $install -ErrorAction Stop;$newActivated=$true
  WritePending 'NEW_ACTIVE'
  if($InjectHardCrashAfterSwap){Stop-Process -Id $PID -Force}
  if($InjectFailureAfterSwap){Fail 'QA_INJECTED_AFTER_SWAP'}
  if(!$snap){
    $exe=Join-Path $install 'Service\Sokna.PrintAgent.Service.exe'
    New-Service -Name $serviceName -DisplayName 'SOKNA Print Worker' -BinaryPathName ('"'+$exe+'"') -StartupType Manual -ErrorAction Stop|Out-Null
    $createdSvc=$true
    $reg='HKLM:\SYSTEM\CurrentControlSet\Services\'+$serviceName
    if($report.test_mode){
      New-ItemProperty -LiteralPath $reg -Name Environment -PropertyType MultiString -Value @('SOKNA_PRINT_WORKER_DATA_ROOT='+$agentData) -Force|Out-Null
    }else{
      $cfg='HKLM:\SOFTWARE\Sokna\Local\PrintWorker'
      New-Item -Path $cfg -Force|Out-Null
      New-ItemProperty -LiteralPath $cfg -Name DataRoot -PropertyType String -Value $agentData -Force|Out-Null
      Set-Service -Name $serviceName -StartupType Automatic -ErrorAction Stop
    }
  }
  StartIfNeeded
  $readback=VerifyPayload $install
  $nowSvc=CurrentService
  if(!$nowSvc -or (ReadServiceExe ([string]$nowSvc.PathName)) -ne (Full (Join-Path $install 'Service\Sokna.PrintAgent.Service.exe'))){Fail 'SERVICE_READBACK_MISMATCH'}
  if(($wasRunning -or $createdSvc) -and [string]$nowSvc.State -ne 'Running'){Fail 'SERVICE_NOT_RUNNING_AFTER_APPLY'}
  if($runtimeStopped){
    Start-Service -Name 'SoknaRuntime' -ErrorAction Stop
    $rservice=Get-Service -Name 'SoknaRuntime' -ErrorAction Stop
    try{$rservice.WaitForStatus('Running',[TimeSpan]::FromSeconds(35))}finally{$rservice.Dispose()}
    $runtimeStopped=$false
  }
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
  WritePending 'COMMITTED'
  $report.status='COMMITTED';$report.reason='LAB_APPLY_PASS';$report.rollback='not_required'
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
      $report.rollback='verified';$report.status='ROLLED_BACK'
      Write-Host 'PASS_PRINT_INDEPENDENT_LAB_ROLLBACK'
    }catch{$report.rollback='FAILED';$report.status='ROLLBACK_FAILED';$report.reason=$errorCode+' | '+$_.Exception.Message}
  }else{
    # A failed preflight after quiescing services must restore Print before
    # the Runtime supervisor is restarted; never leave a healthy cafe offline.
    if($wasRunning){
      try{
        $previous=Get-Service -Name $serviceName -ErrorAction Stop
        if([string]$previous.Status -ne 'Running'){
          Start-Service -Name $serviceName -ErrorAction Stop
          WaitService 'Running' 35
        }
        $report.status='BLOCKED_RESTORED'
      }catch{
        $report.status='PREFLIGHT_RESTORE_FAILED'
        $report.reason=[string]$report.reason+' | PRINT_RESTART_FAILED: '+$_.Exception.Message
      }
    }else{$report.status='BLOCKED'}
  }
  Write-Host ('PRINT_INDEPENDENT_UPDATER_ERROR='+$report.reason)
}finally{
  if($runtimeStopped -and $runtimeWasRunning){
    try{
      Start-Service -Name 'SoknaRuntime' -ErrorAction Stop
      $rservice=Get-Service 'SoknaRuntime' -ErrorAction Stop
      try{$rservice.WaitForStatus('Running',[TimeSpan]::FromSeconds(35))}finally{$rservice.Dispose()}
      $runtimeStopped=$false
    }catch{
      $report.status='RUNTIME_RESTORE_FAILED'
      $report.reason=[string]$report.reason+' | RUNTIME_RESTART_FAILED: '+$_.Exception.Message
      $report.other_services_untouched=$false
    }
  }
  if($lock){$lock.Dispose()}
  if($staging -and (Test-Path -LiteralPath $staging)){Remove-Item -LiteralPath $staging -Force -Recurse -ErrorAction SilentlyContinue}
  if($backup -and $report.status -eq 'COMMITTED' -and (Test-Path -LiteralPath $backup)){Remove-Item -LiteralPath $backup -Force -Recurse -ErrorAction SilentlyContinue}
  if($journal -and (Test-Path -LiteralPath $journal) -and $report.status -in @('COMMITTED','ROLLED_BACK','BLOCKED_RESTORED','BLOCKED')){
    # Leave the journal if any verified old backup is still present.
    if(!$backup -or !(Test-Path -LiteralPath $backup)){
      Remove-Item -LiteralPath $journal -Force -ErrorAction SilentlyContinue
    }
  }
  if($targetDir -and (Test-Path -LiteralPath $targetDir)){Remove-Item -LiteralPath $targetDir -Force -Recurse -ErrorAction SilentlyContinue}
  SaveReport
}
if($report.status -notin @('READY_FOR_GATED_APPLY','COMMITTED','UNCHANGED')){exit 1}