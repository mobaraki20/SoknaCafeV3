param([Parameter(Mandatory=$true)][string]$Installer,[Parameter(Mandatory=$true)][string]$BuildRoot,[Parameter(Mandatory=$true)][string]$Output)
$ErrorActionPreference='Stop';Set-StrictMode -Version Latest
if($env:GITHUB_ACTIONS-ne'true' -or $env:RUNNER_OS-ne'Windows'){throw 'DISPOSABLE_GITHUB_WINDOWS_ONLY'}
if(Get-Service -Name 'SoknaMaintenanceSupervisor' -ErrorAction SilentlyContinue){throw 'REFUSE_EXISTING_SERVICE'}
$data=Join-Path ([Environment]::GetFolderPath('CommonApplicationData')) 'SOKNA'
if(Test-Path (Join-Path $data 'Maintenance/installed-release-identity.json')){throw 'REFUSE_EXISTING_INSTALLED_IDENTITY'}
function Hash([string]$p){(Get-FileHash -LiteralPath $p -Algorithm SHA256).Hash.ToLowerInvariant()}
function Need([bool]$ok,[string]$label){if(-not$ok){throw ('REAL_0327_SERVICE_QA_'+$label)};Write-Host ('PASS '+$label)}
function Json([string]$p,[object]$o){New-Item -ItemType Directory -Path (Split-Path -Parent $p) -Force|Out-Null;$o|ConvertTo-Json -Depth 20|Set-Content -LiteralPath $p -Encoding UTF8}
function Install(){ $p=Start-Process -FilePath $Installer -ArgumentList @('/VERYSILENT','/SUPPRESSMSGBOXES','/NORESTART','/SP-') -Wait -PassThru;if($null-eq$p){throw 'INSTALL_PROCESS_FAILED'};return $p.ExitCode}
$dir=Join-Path $env:RUNNER_TEMP 'sokna-0327-native-service'
$install=Join-Path $dir 'SOKNA Windows Services'
$names=@('Maintenance/App/SoknaMaintenanceSupervisor.exe','Maintenance/App/SoknaMaintenanceUpdateEngine.exe','Maintenance/Recovery/SoknaMaintenanceRecoveryConsole.exe')
$old=@('old-0/SoknaMaintenanceSupervisor.exe','old-1/SoknaMaintenanceUpdateEngine.exe','recovery/SoknaMaintenanceRecoveryConsole.exe')
$before=@('v25-0/SoknaMaintenanceSupervisor.exe','v25-1/SoknaMaintenanceUpdateEngine.exe')
foreach($i in 0..2){
 $dest=Join-Path $install $names[$i];New-Item -ItemType Directory -Force (Split-Path -Parent $dest)|Out-Null
 Copy-Item -LiteralPath (Join-Path $BuildRoot $old[$i]) -Destination $dest -Force
}
$recoveryHash=Hash (Join-Path $install $names[2])
$binaryHashes=@((Hash (Join-Path $install $names[0])),(Hash (Join-Path $install $names[1])))
Need ($binaryHashes[0]-eq'88ba73a97e273a4f818f0200bcc32f7b8cc9df4522b554cca6b88e32e0832aae' -and
      $binaryHashes[1]-eq'd9f8295976a1a549191797303f6a6046877a96d3f1e23fe022bf3ebcfce61199') 'PUBLISHED_BASELINE_SHA'
$identity=Join-Path $data 'Maintenance/installed-release-identity.json'
Json $identity @{
 maintenance_supervisor_version='0.3.26';update_engine_version='0.3.26'
 local=@{release_id='qa-r112';version='synthetic-R112';managed_tree_sha256=('a'*64)}
 public=@{release_id='qa-r149';version='synthetic-R149';managed_tree_sha256=('b'*64)}
}
$localSentinel=Join-Path $data 'SOKNA-R125-KEEP-LOCAL-SENTINEL'
Json $localSentinel @{local='PRESERVE';public='PRESERVE';business_data_never_tested=$true}
$localHash=Hash $localSentinel
$markerRoot=Join-Path $data 'setup/maintenance-independent-pair-v1'
$id='abcdef0123456789abcdef0123456789'
$tx=Join-Path $markerRoot ('tx-'+$id)
$archive=Join-Path $tx 'before'
New-Item -ItemType Directory -Force $archive|Out-Null
Json (Join-Path $markerRoot 'active.json') @{
 format='sokna-maintenance-independent-transaction-v1';id=$id;phase='COMMITTED'
 baseline='0.3.25';target='0.3.26';database_mutated=$false;local_mutated=$false;public_mutated=$false;print_mutated=$false
}
$approved=@();$original=@()
for($i=0;$i-lt2;$i++){
 $dest=Join-Path $archive ([IO.Path]::GetFileName($names[$i]))
 Copy-Item (Join-Path $BuildRoot $before[$i]) $dest
 $approved+=@{path=$names[$i];bytes=(Get-Item (Join-Path $install $names[$i])).Length;sha256=$binaryHashes[$i]}
 $original+=@{name=$names[$i];sha256=(Hash $dest)}
}
$preimage=Join-Path $archive 'installed-release-identity.json'
Json $preimage @{
 maintenance_supervisor_version='0.3.25';update_engine_version='0.3.25'
 local=@{release_id='qa-r112';version='synthetic-R112';managed_tree_sha256=('a'*64)}
 public=@{release_id='qa-r149';version='synthetic-R149';managed_tree_sha256=('b'*64)}
}
Json (Join-Path $tx 'snapshot.json') @{
 format='sokna-maintenance-independent-snapshot-v1';identity_sha256=(Hash $preimage)
 recovery_sha256=$recoveryHash;files=$original
}
$versionInfo=[Diagnostics.FileVersionInfo]::GetVersionInfo((Join-Path $install $names[2]))
$recVer="$($versionInfo.FileMajorPart).$($versionInfo.FileMinorPart).$($versionInfo.FileBuildPart)"
Json (Join-Path $tx 'result.json') @{
 format='sokna-maintenance-independent-result-v1';status='COMMITTED'
 supervisor_version='0.3.26';engine_version='0.3.26';source_version='0.3.25';source_commit='aba774d67e9d3890aa0f69d00f214ae82b395927'
 recovery_console_version=$recVer;recovery_preserved=$true;archive_reconciled=$false
 database_mutated=$false;local_mutated=$false;public_mutated=$false;print_mutated=$false;files=$approved
}
New-Item -ItemType Directory -Force (Join-Path $data 'Maintenance/operations')|Out-Null
$oldMarkerHash=Hash (Join-Path $markerRoot 'active.json')
$oldIdentityHash=Hash $identity
$svc=$null
try {
 $exe=Join-Path $install $names[0]
 $svc=New-Service -Name SoknaMaintenanceSupervisor -BinaryPathName ('"'+$exe+'"') -StartupType Automatic
 Start-Service SoknaMaintenanceSupervisor
 . ./packaging/windows/scripts/maintenance-signed-health-v1.ps1
 Assert-MaintenanceSignedHealth -ExpectedVersion '0.3.26' -Attempts 40
 Need $true 'PINNED_0326_ACTUAL_WINDOWS_SERVICE_SIGNED_HEALTH'
 $engineFile=Join-Path $install $names[1]
 $held=[IO.File]::Open($engineFile,[IO.FileMode]::Open,[IO.FileAccess]::Read,[IO.FileShare]::Read)
 try {
  $faultExit=Install
  Need ($faultExit-ne0) 'REAL_EXE_LOCKED_ENGINE_MUST_REFUSE'
 } finally {$held.Dispose()}
 Assert-MaintenanceSignedHealth -ExpectedVersion '0.3.26' -Attempts 40
 Need ((Hash $identity)-eq$oldIdentityHash -and (Hash $engineFile)-eq$binaryHashes[1]) 'LOCKED_ENGINE_EXACT_ROLLBACK'
 Need ((Hash (Join-Path $install $names[0]))-eq$binaryHashes[0]) 'LOCKED_SUPERVISOR_EXACT_ROLLBACK'
 $completed=Install
 Need ($completed-eq0) 'REAL_EXE_0327_INSTALLS_FROM_0326'
 Assert-MaintenanceSignedHealth -ExpectedVersion '0.3.27' -Attempts 40
 $newIdentity=Get-Content $identity -Raw|ConvertFrom-Json
 Need ($newIdentity.maintenance_supervisor_version-eq'0.3.27' -and $newIdentity.update_engine_version-eq'0.3.27') 'MAINTENANCE_IDENTITY_ADVANCED_TO_0327'
 Need ($newIdentity.local.version-eq'synthetic-R112' -and $newIdentity.public.version-eq'synthetic-R149') 'LOCAL_PUBLIC_IDENTITY_PRESERVED'
 Need ((Hash $localSentinel)-eq$localHash) 'BUSINESS_LOCAL_PUBLIC_SENTINEL_UNCHANGED'
 Need ((Hash (Join-Path $install $names[2]))-eq$recoveryHash) 'PRESERVED_RECOVERY_EXACT_SHA'
 Need ((Hash (Join-Path $markerRoot 'active.json'))-eq$oldMarkerHash) '0326_HISTORICAL_TRANSACTION_UNCHANGED'
 $next=Get-Content (Join-Path $data 'setup/maintenance-independent-pair-027-v1/active.json') -Raw|ConvertFrom-Json
 Need ($next.phase-eq'COMMITTED' -and $next.baseline-eq'0.3.26' -and $next.target-eq'0.3.27') '0327_TRANSACTION_COMMITTED_SEPARATELY'
 $manifest=Get-Content (Join-Path $BuildRoot 'COMPONENT-MANIFEST.json') -Raw|ConvertFrom-Json
 foreach($row in $manifest.files){Need ((Hash (Join-Path $install $row.path)) -eq $row.sha256) '0327_TARGET_SHA_MATCHES_EXACT_INSTALLER'}
 $replay=Install
 Need ($replay-eq0) 'EXACT_0327_REPLAY_IDEMPOTENT'
 Need ((Hash (Join-Path $markerRoot 'active.json'))-eq$oldMarkerHash) '0326_EVIDENCE_RETAINED_AFTER_REPLAY'
 Json $Output @{format='sokna-0327-native-windows-service-qa-v1';installer_sha256=(Hash $Installer);real_service_activation='PASS';locked_binary_rollback='PASS';rerun_idempotent='PASS';recovery_sha_preserved=$true;local_public_identity_preserved=$true;r125_history_retained=$true;live_cafe_host_tested=$false;production_approved=$false}
 Write-Host 'PASS R125 MAINTENANCE 0327 REAL WINDOWS SERVICE UPGRADE AND FAULT ROLLBACK'
}
finally {
 try{Stop-Service SoknaMaintenanceSupervisor -ErrorAction SilentlyContinue}catch{}
 & sc.exe delete SoknaMaintenanceSupervisor|Out-Null
}
