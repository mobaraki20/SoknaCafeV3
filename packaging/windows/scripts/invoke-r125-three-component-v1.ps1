param([Parameter(Mandatory=$true)][string]$PayloadRoot,[string]$LabRoot='',[string]$FailAt='')
$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest
function Fail([string]$code){throw [InvalidDataException]::new($code)}
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
 if(Test-Path -LiteralPath $p -PathType Leaf){[IO.File]::Replace($tmp,$p,$null)}else{[IO.File]::Move($tmp,$p)}
}
function Version([string]$p){
 if($lab){if($p -match 'Recovery'){return '0.1.3'}else{return '0.3.22'}}
 $v=[string]([Diagnostics.FileVersionInfo]::GetVersionInfo($p).FileVersion)
 if($v-notmatch '^\d+\.\d+\.\d+\.0$'){Fail 'R125_INSTALLED_VERSION_UNQUALIFIED'}
 $v.Substring(0,$v.Length-2)
}
function Live([string]$version){
 if($lab){if(-not(Test-Path -LiteralPath (Join-Path $LabRoot 'service-running.marker'))){Fail 'R125_LAB_NOT_RUNNING'};return}
 Assert-MaintenanceSignedHealth -ExpectedVersion $version -Attempts 40
}
function Stop(){
 if($lab){Remove-Item -LiteralPath (Join-Path $LabRoot 'service-running.marker') -Force -ErrorAction SilentlyContinue;return}
 $svc=Get-Service -Name SoknaMaintenanceSupervisor -ErrorAction Stop
 try{if($svc.Status-ne'Stopped'){Stop-Service -Name SoknaMaintenanceSupervisor -ErrorAction Stop;$svc.WaitForStatus('Stopped',[TimeSpan]::FromSeconds(40))}}finally{$svc.Dispose()}
}
function Start(){
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
    [IO.Path]::GetFileName($r)-notlike'sokna-r125-txn-lab-*'){Fail 'R125_LAB_GUARD_DENIED'}
}elseif($FailAt-ne''){Fail 'R125_FAULT_ARGUMENT_PROHIBITED'}
$PayloadRoot=[IO.Path]::GetFullPath($PayloadRoot)
$manifestPath=Join-Path $PayloadRoot 'r125-component-manifest.json'
FileSafe $manifestPath
$manifest=Get-Content -LiteralPath $manifestPath -Raw|ConvertFrom-Json -ErrorAction Stop
if($manifest.format-ne'sokna-r125-three-component-installer-v1' -or
 $manifest.target_version-ne'0.3.23' -or $manifest.recovery_console_version-ne'0.1.4' -or
 $manifest.scope-ne'maintenance_three_components_only'){Fail 'R125_MANIFEST_SCOPE_FAILED'}
$names=@('Maintenance/App/SoknaMaintenanceSupervisor.exe','Maintenance/App/SoknaMaintenanceUpdateEngine.exe','Maintenance/Recovery/SoknaMaintenanceRecoveryConsole.exe')
$files=@($manifest.files)
if($files.Count-ne3){Fail 'R125_MANIFEST_THREE_FILES_REQUIRED'}
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
if(@(Get-ChildItem -LiteralPath (Join-Path $PayloadRoot 'Maintenance') -File -Recurse).Count-ne3){Fail 'R125_PAYLOAD_EXTRAS_FORBIDDEN'}
foreach($n in $names){if(-not$seen.Contains($n)){Fail 'R125_PAYLOAD_MISSING'}}
if($lab){
 $installRoot=Join-Path $LabRoot 'SOKNA Windows Services'
 $dataRoot=Join-Path $LabRoot 'ProgramData\SOKNA'
}else{
 $svc=Get-CimInstance Win32_Service -Filter "Name='SoknaMaintenanceSupervisor'" -ErrorAction Stop
 if(-not$svc -or [string]$svc.State-ne'Running'){Fail 'R125_BASELINE_SERVICE_NOT_RUNNING'}
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
 foreach($p in @('Runtime\SoknaRuntimeService.exe','PrintAgent\Service\Sokna.PrintAgent.Service.exe')){
  FileSafe (Join-Path $installRoot $p)
 }
 $health=Join-Path $PayloadRoot 'maintenance-signed-health-v1.ps1'
 FileSafe $health
 . $health
}
$installed=@{}
$original=@{}
foreach($rel in $names){
 $installed[$rel]=Join-Path $installRoot ($rel.Replace('/','\'))
 FileSafe $installed[$rel]
 $original[$rel]=Hash $installed[$rel]
}
if((Version $installed[$names[0]])-ne'0.3.22' -or
 (Version $installed[$names[1]])-ne'0.3.22' -or
 (Version $installed[$names[2]])-ne'0.1.3'){Fail 'R125_ONLY_0322_AND_013_BASELINE_QUALIFIED'}
$identity=Join-Path $dataRoot 'Maintenance\installed-release-identity.json'
FileSafe $identity
$identityHash=Hash $identity
$oldDoc=Get-Content -LiteralPath $identity -Raw|ConvertFrom-Json
if($oldDoc.maintenance_supervisor_version-ne'0.3.22' -or
 $oldDoc.update_engine_version-ne'0.3.22'){Fail 'R125_INSTALLED_IDENTITY_NOT_0322'}
$root=Join-Path $dataRoot 'setup\r125-0323-three-component'
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
 Live '0.3.22'
 $id=[Guid]::NewGuid().ToString('N')
 $dir=Join-Path $root ('tx-'+$id)
 ArchiveDir $dir
 $backup=Join-Path $dir 'before'
 ArchiveDir $backup
 $result=Join-Path $dir 'result.json'
 $state=[ordered]@{format='sokna-r125-0323-transaction-v1';id=$id;phase='PREPARED';baseline='0.3.22';target='0.3.23';database_mutated=$false;local_mutated=$false;public_mutated=$false;print_mutated=$false}
 foreach($rel in $names){
  $dst=Join-Path $backup ([IO.Path]::GetFileName($rel))
  Copy-Item -LiteralPath $installed[$rel] -Destination $dst
  Same $dst $original[$rel]
 }
 $idBackup=Join-Path $backup 'installed-release-identity.json'
 Copy-Item -LiteralPath $identity -Destination $idBackup
 Same $idBackup $identityHash
 Save (Join-Path $dir 'snapshot.json') ([ordered]@{format='sokna-r125-0323-snapshot-v1';identity_sha256=$identityHash;files=@($names|ForEach-Object{@{name=$_;sha256=$original[$_]}})})
 Save $marker $state
 try{
  $state.phase='STOPPING';Save $marker $state
  Stop
  $state.phase='REPLACING';Save $marker $state
  for($i=0;$i-lt3;$i++){
   $rel=$names[$i]
   Copy-Item -LiteralPath (Join-Path $PayloadRoot ($rel.Replace('/','\'))) -Destination $installed[$rel] -Force
   Same $installed[$rel] ([string](@($files|Where-Object{$_.path-eq$rel})[0].sha256))
   Fault ('after-copy-'+($i+1))
  }
  $state.phase='STARTING';Save $marker $state
  Start
  Live '0.3.23'
  Fault 'after-service-start'
  $state.phase='IDENTITY';Save $marker $state
  $doc=Get-Content -LiteralPath $identity -Raw|ConvertFrom-Json
  if((Hash $identity)-cne$identityHash){Fail 'R125_IDENTITY_CHANGED_DURING_INSTALL'}
  $doc.maintenance_supervisor_version='0.3.23'
  $doc.update_engine_version='0.3.23'
  $roundTrip=($doc|ConvertTo-Json -Depth 100 -Compress)|ConvertFrom-Json
  $roundTrip.maintenance_supervisor_version='0.3.22'
  $roundTrip.update_engine_version='0.3.22'
  if(($roundTrip|ConvertTo-Json -Depth 100 -Compress)-cne
    ($oldDoc|ConvertTo-Json -Depth 100 -Compress)){Fail 'R125_IDENTITY_SCOPE_DRIFT'}
  Save $identity $doc
  Fault 'after-identity'
  foreach($rel in $names){Same $installed[$rel] ([string](@($files|Where-Object{$_.path-eq$rel})[0].sha256))}
  Live '0.3.23'
  $state.phase='COMMITTED';Save $marker $state
  Save $result ([ordered]@{format='sokna-r125-0323-result-v1';status='COMMITTED';supervisor_version='0.3.23';engine_version='0.3.23';recovery_console_version='0.1.4';archive_reconciled=$false;database_mutated=$false;local_mutated=$false;public_mutated=$false;print_mutated=$false})
  Write-Host 'R125_0323_INSTALL_COMMITTED'
  exit 0
 }catch{
  $state.phase='ROLLING_BACK';Save $marker $state
  try{
   Stop
   foreach($rel in $names){
    $src=Join-Path $backup ([IO.Path]::GetFileName($rel))
    Same $src $original[$rel]
    Copy-Item -LiteralPath $src -Destination $installed[$rel] -Force
    Same $installed[$rel] $original[$rel]
   }
   Same $idBackup $identityHash
   Copy-Item -LiteralPath $idBackup -Destination $identity -Force
   Same $identity $identityHash
   Start
   Live '0.3.22'
   $state.phase='ROLLED_BACK';Save $marker $state
   Save $result ([ordered]@{format='sokna-r125-0323-result-v1';status='ROLLED_BACK';original_files_restored=$true;identity_restored=$true;database_mutated=$false;local_mutated=$false;public_mutated=$false;print_mutated=$false})
   Write-Host 'R125_0323_INSTALL_ROLLED_BACK'
   exit 65
  }catch{
   $state.phase='RECOVERY_REQUIRED';Save $marker $state
   Save $result ([ordered]@{format='sokna-r125-0323-result-v1';status='RECOVERY_REQUIRED';backup_retained=$true;auto_retry_forbidden=$true;database_mutated=$false;local_mutated=$false;public_mutated=$false})
   Write-Host 'R125_0323_RECOVERY_REQUIRED'
   exit 66
  }
 }
}finally{$lock.Dispose()}
