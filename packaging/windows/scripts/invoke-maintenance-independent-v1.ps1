param([Parameter(Mandatory=$true)][string]$PayloadRoot,[string]$LabRoot='',[string]$FailAt='',[ValidateSet('0.3.22','0.3.23','0.3.24','0.3.25')][string]$LabBaseline='0.3.22')
$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest
$targetVersion='0.3.26';$targetRecovery='0.1.7'
$baselineVersion=$LabBaseline;$baselineRecovery=if($baselineVersion-eq'0.3.22'){'0.1.3'}elseif($baselineVersion-eq'0.3.23'){'0.1.4'}elseif($baselineVersion-eq'0.3.24'){'0.1.5'}else{'0.1.6'}
$script:InstallerStage='ARGUMENTS'
function Stage([string]$name){$script:InstallerStage=$name;Write-Host ('SOKNA_INSTALLER_STAGE='+$name)}
function Fail([string]$code){Write-Host ('SOKNA_INSTALLER_FAILURE_STAGE='+$script:InstallerStage);Write-Host ('SOKNA_INSTALLER_FAILURE='+$code);throw [IO.InvalidDataException]::new($code)}
function AssertMaintenanceBaselinePair([string]$SupervisorVersion,[string]$EngineVersion){
 if($SupervisorVersion-notin@('0.3.22','0.3.23','0.3.24','0.3.25')){Fail 'MAINTENANCE_BASELINE_UNSUPPORTED_OR_REINSTALL'}
 if($EngineVersion-ne$SupervisorVersion){Fail 'MAINTENANCE_BASELINE_COMPONENT_VERSION_MISMATCH'}
 return $SupervisorVersion
}
function Hash([string]$p){
 if(-not(Test-Path -LiteralPath $p -PathType Leaf)){return ''}
 $f=[IO.File]::OpenRead($p)
 try{$h=[Security.Cryptography.SHA256]::Create();try{return ([BitConverter]::ToString($h.ComputeHash($f))).Replace('-','').ToLowerInvariant()}finally{$h.Dispose()}}finally{$f.Dispose()}
}
function Same([string]$p,[string]$sha){if((Hash $p)-cne$sha){Fail 'R125_FILE_HASH_UNQUALIFIED'}}
function FileSafe([string]$p){
 if(-not(Test-Path -LiteralPath $p -PathType Leaf)){Fail 'R125_REQUIRED_FILE_MISSING'}
 if((Get-Item -LiteralPath $p -Force).Attributes -band [IO.FileAttributes]::ReparsePoint){Fail 'R125_REPARSE_FILE_FORBIDDEN'}
}
function Save([string]$p,[object]$value){
 $raw=($value|ConvertTo-Json -Depth 40 -Compress)+[Environment]::NewLine
 $bytes=[Text.UTF8Encoding]::new($false).GetBytes($raw)
 $tmp=$p+'.tmp.'+[Guid]::NewGuid().ToString('N')
 $stream=[IO.FileStream]::new($tmp,[IO.FileMode]::CreateNew,[IO.FileAccess]::Write,[IO.FileShare]::None,4096,[IO.FileOptions]::WriteThrough)
 try{$stream.Write($bytes,0,$bytes.Length);$stream.Flush($true)}finally{$stream.Dispose()}
 if(Test-Path -LiteralPath $p -PathType Leaf){
  $prior=$p+'.atomic-before.'+[Guid]::NewGuid().ToString('N')
  [IO.File]::Replace($tmp,$p,$prior)
 }else{[IO.File]::Move($tmp,$p)}
}
function Version([string]$p){
 if($lab){if($p -match 'Recovery'){return $baselineRecovery}else{return $baselineVersion}}
 $v=[string]([Diagnostics.FileVersionInfo]::GetVersionInfo($p).FileVersion)
 if($v-notmatch '^\d+\.\d+\.\d+\.0$'){Fail 'R125_INSTALLED_VERSION_UNQUALIFIED'}
 $v.Substring(0,$v.Length-2)
}
function Live([string]$version){
 if($lab){if(-not(Test-Path -LiteralPath (Join-Path $LabRoot 'service-running.marker'))){Fail 'R125_LAB_NOT_RUNNING'};return}
 Assert-MaintenanceSignedHealth -ExpectedVersion $version -Attempts 40
}
function StopMaintenanceService(){
 if($lab){Remove-Item -LiteralPath (Join-Path $LabRoot 'service-running.marker') -Force -ErrorAction SilentlyContinue;return}
 $svc=Get-Service -Name SoknaMaintenanceSupervisor -ErrorAction Stop
 try{if($svc.Status-ne'Stopped'){Stop-Service -Name SoknaMaintenanceSupervisor -ErrorAction Stop;$svc.WaitForStatus('Stopped',[TimeSpan]::FromSeconds(40))}}finally{$svc.Dispose()}
}
function StartMaintenanceService(){
 if($lab){Set-Content -LiteralPath (Join-Path $LabRoot 'service-running.marker') -Value 'TEST' -Encoding Ascii;return}
 Start-Service -Name SoknaMaintenanceSupervisor -ErrorAction Stop
 $svc=Get-Service -Name SoknaMaintenanceSupervisor -ErrorAction Stop
 try{$svc.WaitForStatus('Running',[TimeSpan]::FromSeconds(40))}finally{$svc.Dispose()}
}
function Fault([string]$phase){if($FailAt-eq$phase){Fail 'R125_INJECTED_FAULT'}}
function ArchiveDir([string]$dir){
 if(-not(Test-Path -LiteralPath $dir -PathType Container)){New-Item -ItemType Directory -Path $dir -Force|Out-Null}
 if((Get-Item -LiteralPath $dir -Force).Attributes -band [IO.FileAttributes]::ReparsePoint){Fail 'R125_ARCHIVE_REPARSE_FORBIDDEN'}
 if(-not$lab){
  & (Join-Path $env:WINDIR 'System32\icacls.exe') $dir /inheritance:r /grant:r '*S-1-5-18:(OI)(CI)F' '*S-1-5-32-544:(OI)(CI)F'|Out-Null
  if($LASTEXITCODE-ne0){Fail 'R125_ARCHIVE_ACL_UNQUALIFIED'}
 }
}
$lab=($LabRoot-ne'')
if($lab){
 $r=[IO.Path]::GetFullPath($LabRoot)
 $temp=([IO.Path]::GetFullPath($env:RUNNER_TEMP)).TrimEnd('\')+'\'
 if($env:GITHUB_ACTIONS-ne'true' -or $env:RUNNER_OS-ne'Windows' -or
    -not$r.StartsWith($temp,[StringComparison]::OrdinalIgnoreCase) -or
    [IO.Path]::GetFileName($r)-notlike'sokna-maint-txn-lab-*'){Fail 'R125_LAB_GUARD_DENIED'}
}elseif($FailAt-ne''){Fail 'R125_FAULT_ARGUMENT_PROHIBITED'}
Stage 'PAYLOAD'
$PayloadRoot=[IO.Path]::GetFullPath($PayloadRoot)
$manifestPath=Join-Path $PayloadRoot 'maintenance-independent-manifest-v1.json'
FileSafe $manifestPath
$manifest=Get-Content -LiteralPath $manifestPath -Raw|ConvertFrom-Json -ErrorAction Stop
if($manifest.format-ne'sokna-maintenance-independent-installer-v1' -or
 $manifest.target_version-ne$targetVersion -or $manifest.recovery_console_action-ne'KEEP_UNCHANGED' -or
 $manifest.scope-ne'maintenance_pair_only'){Fail 'R125_MANIFEST_SCOPE_FAILED'}
$names=@('Maintenance/App/SoknaMaintenanceSupervisor.exe','Maintenance/App/SoknaMaintenanceUpdateEngine.exe')
$files=@($manifest.files)
if($files.Count-ne2){Fail 'MAINTENANCE_PAIR_MANIFEST_TWO_FILES_REQUIRED'}
$seen=[Collections.Generic.HashSet[string]]::new([StringComparer]::OrdinalIgnoreCase)
foreach($item in $files){
 $rel=[string]$item.path
 if($rel-notin$names -or -not$seen.Add($rel) -or
  [string]$item.sha256 -notmatch '^[a-f0-9]{64}$'){Fail 'R125_MANIFEST_FILE_INVALID'}
 $source=Join-Path $PayloadRoot ($rel.Replace('/','\'))
 FileSafe $source
 Same $source ([string]$item.sha256)
 if((Get-Item -LiteralPath $source).Length-ne[long]$item.bytes){Fail 'R125_PAYLOAD_SIZE_CHANGED'}
}
if(@(Get-ChildItem -LiteralPath (Join-Path $PayloadRoot 'Maintenance') -File -Recurse).Count-ne2){Fail 'MAINTENANCE_PAIR_PAYLOAD_EXTRAS_FORBIDDEN'}
foreach($n in $names){if(-not$seen.Contains($n)){Fail 'MAINTENANCE_PAIR_PAYLOAD_MISSING'}}
Stage 'SERVICE_LAYOUT'
if($lab){
 $installRoot=Join-Path $LabRoot 'SOKNA Windows Services'
 $dataRoot=Join-Path $LabRoot 'ProgramData\SOKNA'
}else{
 $svc=Get-CimInstance Win32_Service -Filter "Name='SoknaMaintenanceSupervisor'" -ErrorAction Stop
 if(-not$svc){Fail 'R125_BASELINE_SERVICE_MISSING'}
  $existingPending=Join-Path ([Environment]::GetFolderPath('CommonApplicationData')) 'SOKNA\setup\maintenance-independent-pair-v1\active.json'
  if([string]$svc.State-ne'Running' -and -not(Test-Path -LiteralPath $existingPending -PathType Leaf)){
   Fail 'R125_BASELINE_SERVICE_NOT_RUNNING'
  }
 $image=[Environment]::ExpandEnvironmentVariables(([string]$svc.PathName).Trim())
 if($image-match'^"([^"]+\.exe)"'){$exe=$Matches[1]}
 elseif($image-match'^([^\s"]+\.exe)'){$exe=$Matches[1]}
 else{Fail 'R125_BASELINE_IMAGE_NOT_QUALIFIED'}
 $exe=[IO.Path]::GetFullPath($exe)
 if([IO.Path]::GetFileName($exe)-cne'SoknaMaintenanceSupervisor.exe' -or
 [IO.Path]::GetFileName([IO.Path]::GetDirectoryName($exe))-cne'App' -or
 [IO.Path]::GetFileName([IO.Path]::GetDirectoryName([IO.Path]::GetDirectoryName($exe)))-cne'Maintenance'){
 Fail 'R125_BASELINE_SERVICE_OWNER_FAILED'}
 $installRoot=Split-Path (Split-Path (Split-Path $exe -Parent) -Parent) -Parent
 $dataRoot=Join-Path ([Environment]::GetFolderPath('CommonApplicationData')) 'SOKNA'
 $health=Join-Path $PayloadRoot 'maintenance-signed-health-v1.ps1'
 FileSafe $health
 . $health
}
if(-not$lab){
 foreach($part in @($installRoot,
   (Join-Path $installRoot 'Maintenance'),
   (Join-Path $installRoot 'Maintenance\App'),
   (Join-Path $installRoot 'Maintenance\Recovery'),
   (Join-Path $dataRoot 'Maintenance'))){
  if(-not(Test-Path -LiteralPath $part -PathType Container) -or
    ((Get-Item -LiteralPath $part -Force).Attributes -band [IO.FileAttributes]::ReparsePoint)){
    Fail 'R125_INSTALL_OWNED_DIRECTORY_UNQUALIFIED'
  }
 }
}
# Never overlap this service upgrade with any Local/Public mutation. A probe
# followed by closing the handle would leave a race; keep this handle to exit.
Stage 'MACHINE_LEASE'
$leaseRoot=Join-Path $dataRoot 'Maintenance\update'
ArchiveDir $leaseRoot
$leasePath=Join-Path $leaseRoot 'machine-mutation.lock'
if(Test-Path -LiteralPath $leasePath){FileSafe $leasePath}
try{
 $machineLease=[IO.File]::Open($leasePath,[IO.FileMode]::OpenOrCreate,[IO.FileAccess]::ReadWrite,[IO.FileShare]::Read)
 $owner=[Text.Encoding]::UTF8.GetBytes(('format=sokna-machine-mutation-lease-v1;owner=maintenance-installer;pid='+$PID+';started_utc='+[DateTimeOffset]::UtcNow.ToString('O')+[Environment]::NewLine))
 $machineLease.SetLength(0);$machineLease.Write($owner,0,$owner.Length);$machineLease.Flush($true)
}
catch{Fail 'MAINTENANCE_MACHINE_MUTATION_LEASE_BUSY'}
try {
# A previous v1 service upgrade must be terminal before the v2 owner may proceed.
Stage 'PRIOR_TRANSACTION'
$previousThree=Join-Path $dataRoot 'setup\maintenance-components-v2\active.json'
if(Test-Path -LiteralPath $previousThree -PathType Leaf){
 FileSafe $previousThree
 $p=Get-Content -LiteralPath $previousThree -Raw|ConvertFrom-Json
 if($p.phase-notin@('COMMITTED','ROLLED_BACK','FAILED_BEFORE_MUTATION')){
  Fail 'PREVIOUS_THREE_COMPONENT_UPGRADE_REQUIRES_RECOVERY'
 }
}
$legacyMarker=Join-Path $dataRoot 'setup\r125-0323-three-component\active.json'
if(Test-Path -LiteralPath $legacyMarker){
 FileSafe $legacyMarker
 $legacy=Get-Content -LiteralPath $legacyMarker -Raw|ConvertFrom-Json
 if($legacy.phase-notin@('COMMITTED','ROLLED_BACK','FAILED_BEFORE_MUTATION')){Fail 'PREVIOUS_V1_INSTALL_REQUIRES_RECOVERY'}
}
# Crash recovery runs before checking the installed version/health: after an
# unexpected process kill, the service may be stopped and the EXEs mixed.
$crashRoot=Join-Path $dataRoot 'setup\maintenance-independent-pair-v1'
$crashMarker=Join-Path $crashRoot 'active.json'
if(Test-Path -LiteralPath $crashMarker -PathType Leaf){
 ArchiveDir $crashRoot
 $crashLock=[IO.File]::Open((Join-Path $crashRoot 'transaction.lock'),[IO.FileMode]::OpenOrCreate,[IO.FileAccess]::ReadWrite,[IO.FileShare]::None)
 try{
  $pending=Get-Content -LiteralPath $crashMarker -Raw|ConvertFrom-Json -ErrorAction Stop
  if($pending.phase-notin@('COMMITTED','ROLLED_BACK','FAILED_BEFORE_MUTATION')){
   if($pending.baseline-notin@('0.3.22','0.3.23','0.3.24','0.3.25')){Fail 'CRASH_BASELINE_UNKNOWN'}
   $baselineVersion=[string]$pending.baseline
   $baselineRecovery=if($baselineVersion-eq'0.3.22'){'0.1.3'}elseif($baselineVersion-eq'0.3.23'){'0.1.4'}elseif($baselineVersion-eq'0.3.24'){'0.1.5'}else{'0.1.6'}
  }
  if($pending.phase-eq'RECOVERY_REQUIRED'){Fail 'R125_PREVIOUS_CRASH_REQUIRES_MANUAL_RECOVERY'}
  if($pending.phase-notin@('COMMITTED','ROLLED_BACK','FAILED_BEFORE_MUTATION')){
   if($pending.format-ne'sokna-maintenance-independent-transaction-v1' -or
      [string]$pending.id -notmatch '^[a-f0-9]{32}$' -or
      $pending.baseline-ne$baselineVersion -or $pending.target-ne$targetVersion){
    Fail 'R125_CRASH_INTENT_UNQUALIFIED'
   }
   $crashDir=Join-Path $crashRoot ('tx-'+$pending.id)
   $backup=Join-Path $crashDir 'before'
   ArchiveDir $crashDir
   ArchiveDir $backup
   $snap=Join-Path $crashDir 'snapshot.json'
   FileSafe $snap
   $snapshot=Get-Content -LiteralPath $snap -Raw|ConvertFrom-Json -ErrorAction Stop
   if($snapshot.format-ne'sokna-maintenance-independent-snapshot-v1' -or
      [string]$snapshot.identity_sha256 -notmatch '^[a-f0-9]{64}$' -or
      [string]$snapshot.recovery_sha256 -notmatch '^[a-f0-9]{64}$' -or
      @($snapshot.files).Count-ne2){Fail 'R125_CRASH_SNAPSHOT_UNQUALIFIED'}
   $oldHashes=@{}
   foreach($row in $snapshot.files){
    $name=[string]$row.name
    if($name-notin$names -or $oldHashes.ContainsKey($name) -or
       [string]$row.sha256 -notmatch '^[a-f0-9]{64}$'){
      Fail 'R125_CRASH_SNAPSHOT_FILES_INVALID'
    }
    $file=Join-Path $backup ([IO.Path]::GetFileName($name))
    FileSafe $file
    Same $file ([string]$row.sha256)
    $oldHashes[$name]=[string]$row.sha256
   }
   if($oldHashes.Count-ne2){Fail 'MAINTENANCE_PAIR_CRASH_SNAPSHOT_INCOMPLETE'}
   $oldIdentity=Join-Path $backup 'installed-release-identity.json'
   FileSafe $oldIdentity
   Same $oldIdentity ([string]$snapshot.identity_sha256)
   $doc=Get-Content -LiteralPath $oldIdentity -Raw|ConvertFrom-Json -ErrorAction Stop
   if($doc.maintenance_supervisor_version-ne$baselineVersion -or
      $doc.update_engine_version-ne$baselineVersion){Fail 'R125_CRASH_ORIGINAL_IDENTITY_INVALID'}
   try{
    StopMaintenanceService
    foreach($name in $names){
     $to=Join-Path $installRoot ($name.Replace('/','\'))
     $from=Join-Path $backup ([IO.Path]::GetFileName($name))
     if((Hash $to)-cne$oldHashes[$name]){Copy-Item -LiteralPath $from -Destination $to -Force -ErrorAction Stop}
     Same $to $oldHashes[$name]
    }
    Same (Join-Path $installRoot 'Maintenance\Recovery\SoknaMaintenanceRecoveryConsole.exe') ([string]$snapshot.recovery_sha256)
    $idDest=Join-Path $dataRoot 'Maintenance\installed-release-identity.json'
    Copy-Item -LiteralPath $oldIdentity -Destination $idDest -Force -ErrorAction Stop
    Same $idDest ([string]$snapshot.identity_sha256)
    StartMaintenanceService
    Live $baselineVersion
    $pending.phase='ROLLED_BACK'
    Save $crashMarker $pending
    Save (Join-Path $crashDir 'result.json') ([ordered]@{
      format='sokna-maintenance-independent-result-v1';status='ROLLED_BACK_AFTER_PROCESS_CRASH'
      original_files_restored=$true;identity_restored=$true
      local_mutated=$false;public_mutated=$false;database_mutated=$false;print_mutated=$false
    })
    Write-Host 'R125_0323_PREVIOUS_PROCESS_CRASH_RECOVERED'
    exit 67
   }catch{
    if($lab){Write-Host ('R125_CI_CRASH_RECOVERY_FAILURE='+$_.Exception.GetType().Name)}
    $pending.phase='RECOVERY_REQUIRED'
    Save $crashMarker $pending
    Write-Host 'R125_0323_CRASH_RECOVERY_UNPROVEN'
    exit 68
   }
  }
 }finally{$crashLock.Dispose()}
}
Stage 'CURRENT_COMPONENTS'
$installed=@{}
$original=@{}
foreach($rel in $names){
 $installed[$rel]=Join-Path $installRoot ($rel.Replace('/','\'))
 FileSafe $installed[$rel]
 $original[$rel]=Hash $installed[$rel]
}
$recoveryPath=Join-Path $installRoot 'Maintenance\Recovery\SoknaMaintenanceRecoveryConsole.exe'
FileSafe $recoveryPath
$recoveryBeforeSha=Hash $recoveryPath
# Safe file fingerprint is retained in the private log and can be reported in
# sanitized JSON on failure. No customer paths, secrets or file bytes.
Write-Host ('SOKNA_INSTALLER_OBSERVED_RECOVERY_SHA256='+$recoveryBeforeSha)
$baselineVersion=Version $installed[$names[0]]
# A replay of these exact already-installed bytes is not a new upgrade.
# Keep all receipts unchanged and let the signed recovery workflow resume/read back.
if(-not$lab -and $baselineVersion-eq$targetVersion){
 foreach($rel in $names){
  $entry=@($files|Where-Object{$_.path-eq$rel})
  if($entry.Count-ne1){Fail 'MAINTENANCE_REPLAY_PAYLOAD_INVALID'}
  Same $installed[$rel] ([string]$entry[0].sha256)
  $want=if($rel-like'*/Recovery/*'){$targetRecovery}else{$targetVersion}
  if((Version $installed[$rel])-ne$want){Fail 'MAINTENANCE_REPLAY_VERSION_MISMATCH'}
 }
 $replayIdentity=Get-Content (Join-Path $dataRoot 'Maintenance/installed-release-identity.json') -Raw|ConvertFrom-Json
 if($replayIdentity.maintenance_supervisor_version-ne$targetVersion -or $replayIdentity.update_engine_version-ne$targetVersion){Fail 'MAINTENANCE_REPLAY_IDENTITY_UNQUALIFIED'}
 Live $targetVersion
 Write-Host 'MAINTENANCE_0326_ALREADY_VERIFIED_NO_CHANGES' 
 exit 0
}
Stage 'BASELINE_VERSION'
if($baselineVersion-notin@('0.3.22','0.3.23','0.3.24','0.3.25')){Fail 'MAINTENANCE_BASELINE_UNSUPPORTED_OR_REINSTALL'}
$observedSupervisor=Version $installed[$names[0]]
$observedEngine=Version $installed[$names[1]]
$observedRecovery=Version $installed[$names[2]]
Write-Host ('SOKNA_INSTALLER_OBSERVED_VERSIONS='+$observedSupervisor+','+$observedEngine+','+$observedRecovery)
$baselinePair=AssertMaintenanceBaselinePair -SupervisorVersion $observedSupervisor -EngineVersion $observedEngine
# Versions alone never attest a baseline. 0322 uses the existing privileged
# receipt; 0323 must match the three exact published binary hashes in this payload.
if(-not$lab){
 foreach($item in $files){
  $candidate=Join-Path $PayloadRoot ([string]$item.path).Replace('/','\')
  $want=if([string]$item.path-like'*/Recovery/*'){$targetRecovery}else{$targetVersion}
  if((Version $candidate)-ne$want){Fail 'TARGET_PE_VERSION_MISMATCH'}
 }
 if($baselineVersion-eq'0.3.22'){
  $receiptPath=Join-Path $dataRoot 'setup\maintenance-only-component-receipt-v1.json'
  FileSafe $receiptPath
  $receipt=Get-Content -LiteralPath $receiptPath -Raw|ConvertFrom-Json
  if($receipt.format-ne'sokna-maintenance-only-component-receipt-v1' -or
     $receipt.version-ne$baselineVersion -or
     $receipt.supervisor_sha256-cne$original[$names[0]] -or
     $receipt.engine_sha256-cne$original[$names[1]]){Fail 'INSTALLED_BINARIES_NOT_RECEIPT_ATTESTED'}
 }else{
  $approved=@($manifest.supported_baselines|Where-Object{$_.version-eq$baselineVersion})
  if($approved.Count-ne1 -or @($approved[0].files).Count-ne3){Fail 'PUBLISHED_BASELINE_PROOF_MISSING'}
  foreach($rel in $names){
   $match=@($approved[0].files|Where-Object{$_.path-eq$rel})
   if($match.Count-ne1 -or $match[0].sha256-cne$original[$rel]){Fail 'PUBLISHED_BASELINE_BINARY_DRIFT'}
  }
 }
}
Stage 'INSTALLED_IDENTITY'
$identity=Join-Path $dataRoot 'Maintenance\installed-release-identity.json'
FileSafe $identity
$identityHash=Hash $identity
# Runtime and Print Agent are outside the transaction: snapshot hashes before
# touching any Maintenance components and recheck before commit/rollback receipt.
$otherHashes=@{};$otherServices=@{}
if(-not$lab){
 # Maintenance owns only its three files. Other components may be installed
 # separately or absent. Observe actual SCM paths without repairing/restarting.
 foreach($name in @('SoknaRuntime','SoknaPrintWorker')){
  $other=Get-CimInstance Win32_Service -Filter ("Name='"+$name+"'") -ErrorAction Stop
  if($other){
   $otherServices[$name]=[string]$other.PathName
   $image=[Environment]::ExpandEnvironmentVariables(([string]$other.PathName).Trim())
   if($image-match'^"([^"]+\.exe)"(?:\s|$)'){$p=$Matches[1]}
   elseif($image-match'^([^\s"]+\.exe)(?:\s|$)'){$p=$Matches[1]}
   else{Fail 'R125_UNTOUCHED_SERVICE_IMAGE_UNQUALIFIED'}
   $p=[IO.Path]::GetFullPath($p);FileSafe $p;$otherHashes[$p]=Hash $p
  }
 }
 # Preserve legacy unregistered sibling files too, when they actually exist.
 foreach($rel in @('Runtime\SoknaRuntimeService.exe','PrintAgent\Service\Sokna.PrintAgent.Service.exe')){
  $p=Join-Path $installRoot $rel
  if(Test-Path -LiteralPath $p){FileSafe $p;$otherHashes[$p]=Hash $p}
 }
}
function AssertOtherComponents(){
 foreach($p in $otherHashes.Keys){Same $p $otherHashes[$p]}
 foreach($name in $otherServices.Keys){
  $actual=Get-CimInstance Win32_Service -Filter ("Name='"+$name+"'") -ErrorAction Stop
  if(-not$actual -or [string]$actual.PathName-cne$otherServices[$name]){Fail 'R125_UNTOUCHED_SERVICE_REGISTRATION_CHANGED'}
 }
}
$oldDoc=Get-Content -LiteralPath $identity -Raw|ConvertFrom-Json
if($oldDoc.maintenance_supervisor_version-ne$baselineVersion -or
 $oldDoc.update_engine_version-ne$baselineVersion){Fail 'MAINTENANCE_INSTALLED_IDENTITY_BASELINE_MISMATCH'}
# Do not stop a Supervisor that is executing a Local/Public maintenance
# operation. Historical recovered/failed records remain untouched.
# Historical Local recovery belongs to Local, NOT this independent Maintenance upgrade.
# Never delete/modify that journal or grant Local readiness. Only *active* writer
# operations block a Maintenance service restart. Hold the OS mutation lease.
Stage 'MAINTENANCE_INDEPENDENT_ACTIVITY'
$operationsRoot=Join-Path $dataRoot 'Maintenance\operations'
$historicalUnknownCount=0
if(-not$lab -and -not(Test-Path -LiteralPath $operationsRoot -PathType Container)){
 Fail 'MAINTENANCE_OPERATION_LEDGER_UNAVAILABLE'
}
if(Test-Path -LiteralPath $operationsRoot -PathType Container){
 $operations=@(Get-ChildItem -LiteralPath $operationsRoot -Filter 'op-*.json' -File -ErrorAction Stop)
 if($operations.Count-gt20000){Fail 'MAINTENANCE_OPERATION_LEDGER_LIMIT'}
 foreach($entry in $operations){
  FileSafe $entry.FullName
  $op=Get-Content -LiteralPath $entry.FullName -Raw|ConvertFrom-Json -ErrorAction Stop
  if($op.format-ne'sokna-maintenance-operation-v1' -or
     [string]$op.operation_id -notmatch '^[a-f0-9]{32}$' -or
     $entry.Name-cne('op-'+$op.operation_id+'.json') -or
     [string]::IsNullOrWhiteSpace([string]$op.state)){
    Fail 'MAINTENANCE_HISTORY_RECORD_UNREADABLE'
  }
  if($op.state-in @('accepted','running','activating','recovering')){
    Fail 'MAINTENANCE_IN_FLIGHT_MUTATION_BLOCKED'
  }
  if($op.state-notin @('committed','failed_pre_mutation','failed','code_only_engine_rolled_back_verified','rolled_back','recovered','cancelled','staged','verified','recovery_required')){
    # Unknown state remains exactly as stored. Allow a Maintenance-only
    # service replacement if it is historical, not recent, and the OS writer
    # lease was acquired. Never regard it as approved for Local activation.
    $stamp=[DateTimeOffset]::MinValue
    if(-not[DateTimeOffset]::TryParse([string]$op.updated_at_utc,[ref]$stamp) -or
       $stamp-gt([DateTimeOffset]::UtcNow.AddMinutes(-30))){
      Fail 'MAINTENANCE_RECENT_UNKNOWN_OPERATION_BLOCKED'
    }
    $historicalUnknownCount++
  }
 }
}
Write-Host ('SOKNA_MAINTENANCE_ONLY_HISTORICAL_UNKNOWN_RETAINED='+$historicalUnknownCount)
$root=Join-Path $dataRoot 'setup\maintenance-independent-pair-v1'
ArchiveDir $root
$exclusive=Join-Path $root 'transaction.lock'
$lock=[IO.File]::Open($exclusive,[IO.FileMode]::OpenOrCreate,[IO.FileAccess]::ReadWrite,[IO.FileShare]::None)
try{
 $marker=Join-Path $root 'active.json'
 if(Test-Path -LiteralPath $marker -PathType Leaf){
  $prior=Get-Content -LiteralPath $marker -Raw|ConvertFrom-Json
  if($prior.phase-notin@('COMMITTED','ROLLED_BACK','FAILED_BEFORE_MUTATION')){
   Fail 'R125_PREVIOUS_TRANSACTION_REQUIRES_RECOVERY'
  }
 }
Stage 'BASELINE_SIGNED_HEALTH'
 Live $baselineVersion
 $id=[Guid]::NewGuid().ToString('N')
 $dir=Join-Path $root ('tx-'+$id)
 ArchiveDir $dir
 $backup=Join-Path $dir 'before'
 ArchiveDir $backup
 $result=Join-Path $dir 'result.json'
 $state=[ordered]@{format='sokna-maintenance-independent-transaction-v1';id=$id;phase='PREPARED';baseline=$baselineVersion;target=$targetVersion;database_mutated=$false;local_mutated=$false;public_mutated=$false;print_mutated=$false}
 foreach($rel in $names){
  $dst=Join-Path $backup ([IO.Path]::GetFileName($rel))
  Copy-Item -LiteralPath $installed[$rel] -Destination $dst
  Same $dst $original[$rel]
 }
 $idBackup=Join-Path $backup 'installed-release-identity.json'
 Copy-Item -LiteralPath $identity -Destination $idBackup
 Same $idBackup $identityHash
 Save (Join-Path $dir 'snapshot.json') ([ordered]@{format='sokna-maintenance-independent-snapshot-v1';identity_sha256=$identityHash;recovery_sha256=$recoveryBeforeSha;files=@($names|ForEach-Object{@{name=$_;sha256=$original[$_]}})})
 Save $marker $state
 try{
Stage 'STOP_SERVICE'
  $state.phase='STOPPING';Save $marker $state
  StopMaintenanceService
Stage 'REPLACE_COMPONENTS'
  $state.phase='REPLACING';Save $marker $state
  for($i=0;$i-lt2;$i++){
   $rel=$names[$i]
   Copy-Item -LiteralPath (Join-Path $PayloadRoot ($rel.Replace('/','\'))) -Destination $installed[$rel] -Force
   Same $installed[$rel] ([string](@($files|Where-Object{$_.path-eq$rel})[0].sha256))
   if($lab -and $i-eq0 -and $FailAt-eq'crash-after-copy-1'){
    [Diagnostics.Process]::GetCurrentProcess().Kill()
   }
   Fault ('after-copy-'+($i+1))
  }
Stage 'START_SERVICE'
  $state.phase='STARTING';Save $marker $state
  StartMaintenanceService
  Live $targetVersion
  Fault 'after-service-start'
Stage 'COMMIT_IDENTITY'
  $state.phase='IDENTITY';Save $marker $state
  $doc=Get-Content -LiteralPath $identity -Raw|ConvertFrom-Json
  if((Hash $identity)-cne$identityHash){Fail 'R125_IDENTITY_CHANGED_DURING_INSTALL'}
  $doc.maintenance_supervisor_version=$targetVersion
  $doc.update_engine_version=$targetVersion
  $roundTrip=($doc|ConvertTo-Json -Depth 100 -Compress)|ConvertFrom-Json
  $roundTrip.maintenance_supervisor_version=$baselineVersion
  $roundTrip.update_engine_version=$baselineVersion
  if(($roundTrip|ConvertTo-Json -Depth 100 -Compress)-cne
    ($oldDoc|ConvertTo-Json -Depth 100 -Compress)){Fail 'R125_IDENTITY_SCOPE_DRIFT'}
  Save $identity $doc
  if($lab -and $FailAt-eq'crash-after-identity'){
   [Diagnostics.Process]::GetCurrentProcess().Kill()
  }
  Fault 'after-identity'
  foreach($rel in $names){Same $installed[$rel] ([string](@($files|Where-Object{$_.path-eq$rel})[0].sha256))}
  Live $targetVersion
  AssertOtherComponents
  Same $recoveryPath $recoveryBeforeSha
  # Durable complete receipt precedes the commit marker: a crash in the gap
  # is safely recoverable rather than falsely advertising committed install.
  Save $result ([ordered]@{format='sokna-maintenance-independent-result-v1';status='COMMITTED';supervisor_version=$targetVersion;engine_version=$targetVersion;recovery_console_version=$observedRecovery;recovery_preserved=$true;source_version=$baselineVersion;source_commit=$manifest.source_commit;files=$files;archive_reconciled=$false;database_mutated=$false;local_mutated=$false;public_mutated=$false;print_mutated=$false})
  $state.phase='COMMITTED';Save $marker $state
  Write-Host 'MAINTENANCE_PAIR_0326_INSTALL_COMMITTED'
  exit 0
 }catch{
  if($_.Exception-isnot[IO.InvalidDataException]){Write-Host ('SOKNA_INSTALLER_FAILURE_STAGE='+$script:InstallerStage);Write-Host 'SOKNA_INSTALLER_FAILURE=R125_COMPONENT_TRANSACTION_FAILED'}
  [Console]::Error.WriteLine($_.Exception.ToString())
  if($lab){Write-Host ('R125_CI_ORIGINAL_FAILURE='+$_.Exception.GetType().Name+':'+$_.Exception.Message)}
Stage 'ROLLBACK'
  $state.phase='ROLLING_BACK';Save $marker $state
  try{
   StopMaintenanceService
   foreach($rel in $names){
    $src=Join-Path $backup ([IO.Path]::GetFileName($rel))
    Same $src $original[$rel]
    if((Hash $installed[$rel])-cne$original[$rel]){Copy-Item -LiteralPath $src -Destination $installed[$rel] -Force}
    Same $installed[$rel] $original[$rel]
   }
   Same $idBackup $identityHash
   Copy-Item -LiteralPath $idBackup -Destination $identity -Force
   Same $identity $identityHash
   StartMaintenanceService
   Live $baselineVersion
   AssertOtherComponents
   Same $recoveryPath $recoveryBeforeSha
   $state.phase='ROLLED_BACK';Save $marker $state
   Save $result ([ordered]@{format='sokna-maintenance-independent-result-v1';status='ROLLED_BACK';original_files_restored=$true;identity_restored=$true;database_mutated=$false;local_mutated=$false;public_mutated=$false;print_mutated=$false})
   Write-Host 'MAINTENANCE_0326_INSTALL_ROLLED_BACK'
   exit 65
  }catch{
   if($lab){Write-Host ('R125_CI_ROLLBACK_FAILURE='+$_.Exception.GetType().Name+':'+$_.Exception.Message)}
   $state.phase='RECOVERY_REQUIRED';Save $marker $state
   Save $result ([ordered]@{format='sokna-maintenance-independent-result-v1';status='RECOVERY_REQUIRED';backup_retained=$true;auto_retry_forbidden=$true;database_mutated=$false;local_mutated=$false;public_mutated=$false})
   Write-Host 'MAINTENANCE_0326_RECOVERY_REQUIRED'
   exit 66
  }
 }
}finally{$lock.Dispose()}


}finally{$machineLease.Dispose()}
