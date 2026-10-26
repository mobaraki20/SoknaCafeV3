param([Parameter(Mandatory=$true)][string]$PayloadRoot)
$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest
# Persist safe diagnostic context for Inno's hidden Windows PowerShell 5.1 process.
# No keys, HMAC signatures or package bytes are written.
trap {
  try{
    $logRoot=Join-Path ([Environment]::GetFolderPath('CommonApplicationData')) 'SOKNA\\setup'
    New-Item -ItemType Directory -Path $logRoot -Force|Out-Null
    $errorText=([string]$_.Exception.Message -replace '[\r\n\0]',' ').Trim()
    if($errorText.Length-gt800){$errorText=$errorText.Substring(0,800)}
    [IO.File]::WriteAllText((Join-Path $logRoot 'maintenance-only-last-error.txt'),('at_utc='+[DateTime]::UtcNow.ToString('o')+"`n"+'code='+$errorText),[Text.UTF8Encoding]::new($false))
    Write-Host ('MAINTENANCE_ONLY_FAILED: '+$errorText)
    Write-Host ('MAINTENANCE_ONLY_FAILED_AT: '+$_.ScriptStackTrace)
  }catch{}
  exit 2
}
function Fail([string]$v){throw ('MAINTENANCE_ONLY_PRECHECK: '+$v)}
function Hex([string]$file){
 $fs=[IO.File]::OpenRead($file)
 try{
  $sha=[Security.Cryptography.SHA256]::Create()
  try{$bytes=$sha.ComputeHash($fs)}finally{$sha.Dispose()}
 }finally{$fs.Dispose()}
 return (([BitConverter]::ToString($bytes))-replace'-','').ToLowerInvariant()
}
function Version([string]$file) {
 if(-not(Test-Path -LiteralPath $file -PathType Leaf)){Fail "Missing installed file."}
 $v=[string]([Diagnostics.FileVersionInfo]::GetVersionInfo($file).FileVersion)
 if($v-match '^(\d+\.\d+\.\d+)\.0$'){return $Matches[1]}
 Fail 'Installed component file version is not verifiable.'
}
function SafeChild([string]$relative){
 $p=$relative.Replace('/','\')
 if([string]::IsNullOrWhiteSpace($relative)-or [IO.Path]::IsPathRooted($p)-or $p.Contains(':')-or $p.Split('\')|Where-Object{$_-in @('','.', '..')}){Fail 'Unsafe package file path.'}
 return $p
}
$PayloadRoot=[IO.Path]::GetFullPath($PayloadRoot)
$manifestFile=Join-Path $PayloadRoot 'component-install-manifest-v1.json'
if(-not(Test-Path -LiteralPath $manifestFile -PathType Leaf)){Fail 'Package integrity manifest unavailable.'}
$m=Get-Content -LiteralPath $manifestFile -Raw|ConvertFrom-Json
if($m.format-ne'sokna-maintenance-only-installer-v1'-or $m.schema_version-ne1 -or $m.component_version-ne'0.3.17' -or $m.release_scope-ne'maintenance_only'){Fail 'Release scope or version not qualified.'}
$files=@($m.files)
if($files.Count-lt2 -or $files.Count-gt300){Fail 'Unexpected package contents.'}
$seen=New-Object 'System.Collections.Generic.HashSet[string]' ([StringComparer]::OrdinalIgnoreCase)
foreach($f in $files){
 $rel=[string]$f.path
 $safe=SafeChild $rel
 if(-not$seen.Add($safe) -or -not$safe.StartsWith('maintenance\app\',[StringComparison]::OrdinalIgnoreCase)){Fail 'Package contains out-of-scope or duplicate paths.'}
 $file=Join-Path $PayloadRoot $safe
 if(-not(Test-Path -LiteralPath $file -PathType Leaf) -or (Hex $file)-ne [string]$f.sha256 -or (Get-Item -LiteralPath $file).Length-ne [long]$f.size_bytes){Fail ('Package file hash mismatch: '+$rel)}
}
$actualFiles=@(Get-ChildItem -LiteralPath (Join-Path $PayloadRoot 'maintenance\app') -File -Recurse)
if($actualFiles.Count-ne$files.Count){Fail 'Unlisted package files exist.'}
$superPayload=Join-Path $PayloadRoot 'maintenance\app\SoknaMaintenanceSupervisor.exe'
$enginePayload=Join-Path $PayloadRoot 'maintenance\app\SoknaMaintenanceUpdateEngine.exe'
if((Version $superPayload)-ne'0.3.17'-or (Version $enginePayload)-ne'0.3.17'){Fail 'Supervisor and Engine release versions differ.'}

$svc=Get-CimInstance Win32_Service -Filter "Name='SoknaMaintenanceSupervisor'" -ErrorAction Stop
if(-not$svc -or $svc.State-ne'Running'){Fail 'Existing Maintenance service must be running.'}
$raw=[Environment]::ExpandEnvironmentVariables(([string]$svc.PathName).Trim())
if($raw-match '^"([^"]+\.exe)"'){$exe=$Matches[1]}
elseif($raw-match '^([^\s"]+\.exe)'){$exe=$Matches[1]}
else{Fail 'Maintenance service binary cannot be resolved safely.'}
$exe=[IO.Path]::GetFullPath($exe)
if([IO.Path]::GetFileName($exe)-cne'SoknaMaintenanceSupervisor.exe'){Fail 'Unrecognized Maintenance service executable.'}
$appRoot=[IO.Path]::GetDirectoryName($exe)
if([IO.Path]::GetFileName($appRoot)-cne'App' -or [IO.Path]::GetFileName([IO.Path]::GetDirectoryName($appRoot))-cne'Maintenance'){Fail 'Maintenance component ownership boundary mismatch.'}
$installRoot=[IO.Path]::GetDirectoryName([IO.Path]::GetDirectoryName($appRoot))
if(-not(Test-Path -LiteralPath (Join-Path $installRoot 'Runtime\SoknaRuntimeService.exe') -PathType Leaf)-or
   -not(Test-Path -LiteralPath (Join-Path $installRoot 'PrintAgent\Service\Sokna.PrintAgent.Service.exe') -PathType Leaf)-or
   -not(Test-Path -LiteralPath (Join-Path $installRoot 'Maintenance\Recovery\SoknaMaintenanceRecoveryConsole.exe') -PathType Leaf)){Fail 'Existing managed installation incomplete; use the full Windows Services installer.'}
$superInstalled=Join-Path $appRoot 'SoknaMaintenanceSupervisor.exe'
$engineInstalled=Join-Path $appRoot 'SoknaMaintenanceUpdateEngine.exe'
$installedVersion=Version $superInstalled
if($installedVersion-notin @('0.3.15','0.3.16','0.3.17') -or (Version $engineInstalled)-ne$installedVersion){Fail 'This UAT package requires matching Maintenance 0.3.15 or 0.3.16 components, or an already qualified 0.3.17.'}
$healthProbe=Join-Path $PayloadRoot 'maintenance-signed-health-v1.ps1'
if(-not(Test-Path -LiteralPath $healthProbe -PathType Leaf)){Fail 'Signed read-only health probe missing.'}
. $healthProbe
Assert-MaintenanceSignedHealth -ExpectedVersion $installedVersion -Attempts 40

$dataRoot=Join-Path ([Environment]::GetFolderPath('CommonApplicationData')) 'SOKNA'
$txDir=Join-Path $dataRoot ('setup\component-transactions\maintenance-'+[guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $txDir -Force | Out-Null
# The plan and rollback directory are privileged state. No world-write inherited ACL.
& (Join-Path $env:WINDIR 'System32\icacls.exe') $txDir /inheritance:r /grant:r '*S-1-5-18:(OI)(CI)F' '*S-1-5-32-544:(OI)(CI)F' | Out-Null
if($LASTEXITCODE-ne0){Fail 'Privileged transaction ACL could not be established.'}
. (Join-Path $PayloadRoot 'maintenance-installed-versions-v1.ps1')
if($installedVersion-eq'0.3.17'){
 # An already-installed 0.3.17 is not reinstalled. Bind its physical hashes to
 # the previous privileged scoped receipt, then repair only stale metadata.
 $receiptPath=Join-Path $dataRoot 'setup\maintenance-only-component-receipt-v1.json'
 $receipt=Get-Content -LiteralPath $receiptPath -Raw|ConvertFrom-Json
 if($receipt.format-ne'sokna-maintenance-only-component-receipt-v1' -or $receipt.version-ne'0.3.17' -or
    $receipt.supervisor_sha256-cne(Hex $superInstalled) -or $receipt.engine_sha256-cne(Hex $engineInstalled)){
  Fail 'Installed 0.3.17 component receipt does not match physical binaries.'
 }
 Sync-MaintenanceInstalledVersions -AppRoot $appRoot -EvidenceRoot $txDir
 Write-Host 'Maintenance 0.3.17 verified; component version metadata synchronized without service changes.'
 exit 0
}
$group=Get-Content -LiteralPath (Join-Path $PayloadRoot 'windows-services-component-manifest-v2.json') -Raw|ConvertFrom-Json
if($group.format-ne'sokna-windows-component-manifest-v2' -or $group.package_version-ne'0.3.17' -or @($group.components).Count-ne2){Fail 'Scoped service-group manifest invalid.'}
$tx=[guid]::NewGuid().ToString('N')
$entries=@()
foreach($c in $group.components){
 if([string]$c.component_id -notin @('maintenance','maintenance-update-engine')){Fail 'Out-of-scope component.'}
 $primary=Join-Path $PayloadRoot ([string]$c.payload_primary).Replace('/','\')
 $installed=Join-Path $installRoot ([string]$c.installed_primary).Replace('/','\')
 if([string]$c.version-ne'0.3.17' -or -not(Test-Path -LiteralPath $primary -PathType Leaf) -or (Hex $primary)-ne ([string]$c.target_sha256)){Fail 'Component source mismatch.'}
 if((Version $installed)-ne$installedVersion){Fail 'Installed component changed during plan creation.'}
 $entries+=@{component_id=[string]$c.component_id;action='UPDATE';target_sha256=(Hex $primary)}
}
if(@($entries).Count-ne2){Fail 'Only Supervisor and Engine can be updated.'}
$plan=[ordered]@{
 format='sokna-windows-install-plan-v3';schema_version=3;release_scope='maintenance_only';mode='update'
 shell_root=$PayloadRoot;install_root=$installRoot;data_root=$dataRoot;package_version='0.3.17'
 transaction_id=$tx;correlation_id=('corr-'+$tx);setup_session_id=('maintenance-only-'+$tx);components=$entries
}
$planPath=Join-Path $txDir 'install-plan.json'
[IO.File]::WriteAllText($planPath,($plan|ConvertTo-Json -Depth 14),[Text.UTF8Encoding]::new($false))
$runtime=Join-Path $installRoot 'Runtime\SoknaRuntimeService.exe'
$print=Join-Path $installRoot 'PrintAgent\Service\Sokna.PrintAgent.Service.exe'
$recovery=Join-Path $installRoot 'Maintenance\Recovery\SoknaMaintenanceRecoveryConsole.exe'
$before=@{runtime=(Hex $runtime);print=(Hex $print);recovery=(Hex $recovery)}
$script=Join-Path $PayloadRoot 'setup-windows-components-maintenance-v1.ps1'
if(-not(Test-Path -LiteralPath $script -PathType Leaf)){Fail 'Qualified lifecycle missing.'}
& $script -PlanFile $planPath
if(-not$?){Fail 'Maintenance-only scoped lifecycle failed (see transaction log).'}
$result=Get-Content -LiteralPath (Join-Path $txDir 'install-result.json') -Raw|ConvertFrom-Json
if($result.status-ne'completed' -or $result.exit_code-ne0){Fail 'Maintenance-only lifecycle was not committed.'}
$after=@{runtime=(Hex $runtime);print=(Hex $print);recovery=(Hex $recovery)}
foreach($key in @('runtime','print','recovery')){if($before[$key]-ne$after[$key]){Fail ('Out-of-scope component was modified: '+$key)}}
if((Version $superInstalled)-ne'0.3.17' -or (Version $engineInstalled)-ne'0.3.17'){Fail 'Installed version readback did not match.'}
Assert-MaintenanceSignedHealth -ExpectedVersion '0.3.17' -Attempts 40
Write-Host ('Maintenance-only upgraded and verified 0.3.15/0.3.16 -> 0.3.17. Transaction: '+$txDir)

Sync-MaintenanceInstalledVersions -AppRoot $appRoot -EvidenceRoot $txDir

