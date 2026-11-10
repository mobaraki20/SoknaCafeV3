param(
 [Parameter(Mandatory)][string]$Installer,
 [Parameter(Mandatory)][string]$BaselineRoot,
 [Parameter(Mandatory)][string]$LegacyRecovery011,
 [Parameter(Mandatory)][string]$Output
)
$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest
if($env:GITHUB_ACTIONS-ne'true' -or $env:RUNNER_OS-ne'Windows'){throw 'DISPOSABLE_WINDOWS_QA_ONLY'}
if(Get-Service SoknaMaintenanceSupervisor -ErrorAction SilentlyContinue){throw 'REFUSE_EXISTING_MAINTENANCE'}
$data=Join-Path ([Environment]::GetFolderPath('CommonApplicationData')) 'SOKNA'
if(Test-Path -LiteralPath $data){throw 'REFUSE_EXISTING_BUSINESS_DATA'}
$install=Join-Path $env:RUNNER_TEMP 'sokna-universal-legacy-0322-real-service'
$names=@('SoknaMaintenanceSupervisor.exe','SoknaMaintenanceUpdateEngine.exe','SoknaMaintenanceRecoveryConsole.exe')
$projects=@('Sokna.Maintenance.Supervisor','Sokna.Maintenance.UpdateEngine','Sokna.Maintenance.RecoveryConsole')
$paths=@('Maintenance\App\SoknaMaintenanceSupervisor.exe','Maintenance\App\SoknaMaintenanceUpdateEngine.exe','Maintenance\Recovery\SoknaMaintenanceRecoveryConsole.exe')
function Hash([string]$path){(Get-FileHash -LiteralPath $path -Algorithm SHA256).Hash.ToLowerInvariant()}
function Need([bool]$ok,[string]$reason){if(-not $ok){throw $reason}}
function Version([string]$path){
 $v=[Diagnostics.FileVersionInfo]::GetVersionInfo($path)
 "$($v.FileMajorPart).$($v.FileMinorPart).$($v.FileBuildPart).$($v.FilePrivatePart)"
}
function Install(){
 $p=Start-Process -FilePath $Installer -ArgumentList @('/VERYSILENT','/SUPPRESSMSGBOXES','/NORESTART','/SP-') -PassThru -Wait
 if($null-eq$p){throw 'UNIVERSAL_PROCESS_COULD_NOT_START'}
 $p.ExitCode
}
. ./packaging/windows/scripts/maintenance-signed-health-v1.ps1
. ./packaging/windows/scripts/maintenance-installed-versions-v1.ps1
New-Item -ItemType Directory -Path $install,(Join-Path $data 'Maintenance\operations'),(Join-Path $data 'setup') -Force|Out-Null
for($i=0;$i-lt3;$i++){
 $src=if($i-eq2){$LegacyRecovery011}else{Join-Path $BaselineRoot ($projects[$i]+'/'+$names[$i])}
 $dst=Join-Path $install $paths[$i]
 New-Item -ItemType Directory -Path (Split-Path -Parent $dst) -Force|Out-Null
 Copy-Item $src $dst
}
Need ((Version (Join-Path $install $paths[0]))-eq'0.3.22.0') 'LEGACY_SUPERVISOR_VERSION_WRONG'
Need ((Version (Join-Path $install $paths[1]))-eq'0.3.22.0') 'LEGACY_ENGINE_VERSION_WRONG'
Need ((Version (Join-Path $install $paths[2]))-eq'0.1.1.0') 'LEGACY_RECOVERY_VERSION_WRONG'
$recoveryHash=Hash (Join-Path $install $paths[2])
Need ($recoveryHash -match '^[a-f0-9]{64}$') 'LEGACY_011_FINGERPRINT_UNREADABLE'
$identity=Join-Path $data 'Maintenance\installed-release-identity.json'
@{maintenance_supervisor_version='0.3.21';update_engine_version='0.3.21';local=@{version='synthetic-home';keep='local'};public=@{version='synthetic-public';keep='public'}}|
 ConvertTo-Json -Depth 8|Set-Content $identity
$receipt=Join-Path $data 'setup\maintenance-only-component-receipt-v1.json'
@{format='sokna-maintenance-only-component-receipt-v1';version='0.3.22';
  supervisor_sha256=(Hash (Join-Path $install $paths[0]));
  engine_sha256=(Hash (Join-Path $install $paths[1]))}|ConvertTo-Json -Depth 5|Set-Content $receipt
$svcExe=Join-Path $install $paths[0]
New-Service -Name SoknaMaintenanceSupervisor -BinaryPathName ('"'+$svcExe+'"') -DisplayName 'SOKNA disposable universal bridge QA' -StartupType Automatic|Out-Null
try{
 Start-Service SoknaMaintenanceSupervisor
 Assert-MaintenanceSignedHealth -ExpectedVersion '0.3.22' -Attempts 40
 $evidence=Join-Path $data ('setup\component-transactions\maintenance-'+[Guid]::NewGuid().ToString('N'))
 Sync-MaintenanceInstalledVersions -AppRoot (Join-Path $install 'Maintenance\App') -EvidenceRoot $evidence
 $idOld=Get-Content $identity -Raw|ConvertFrom-Json
 Need ($idOld.maintenance_supervisor_version-eq'0.3.22' -and $idOld.update_engine_version-eq'0.3.22') 'LEGACY_IDENTITY_SYNC_FAILED'
 $originalRecordSha=Hash $receipt
 Need ((Install)-eq0) 'UNIVERSAL_FROM_0322_FAILED'
 Assert-MaintenanceSignedHealth -ExpectedVersion '0.3.27' -Attempts 40
 Need ((Version (Join-Path $install $paths[0]))-eq'0.3.27.0') '0327_SUPERVISOR_NOT_ACTIVE'
 Need ((Version (Join-Path $install $paths[1]))-eq'0.3.27.0') '0327_ENGINE_NOT_ACTIVE'
 Need ((Hash (Join-Path $install $paths[2]))-eq$recoveryHash) 'PRESERVED_RECOVERY_CHANGED'
 Need ((Hash $receipt)-eq$originalRecordSha) 'ORIGINAL_0322_RECEIPT_CHANGED'
 $idNew=Get-Content $identity -Raw|ConvertFrom-Json
 Need ($idNew.maintenance_supervisor_version-eq'0.3.27' -and
       $idNew.update_engine_version-eq'0.3.27' -and
       $idNew.local.keep-eq'local' -and $idNew.public.keep-eq'public') 'UNRELATED_IDENTITY_CHANGED'
 $before0=Hash (Join-Path $install $paths[0]);$before1=Hash (Join-Path $install $paths[1]);$beforeId=Hash $identity
 Need ((Install)-eq0) 'UNIVERSAL_0327_REPLAY_FAILED'
 Assert-MaintenanceSignedHealth -ExpectedVersion '0.3.27' -Attempts 40
 Need ((Hash (Join-Path $install $paths[0]))-eq$before0 -and
       (Hash (Join-Path $install $paths[1]))-eq$before1 -and
       (Hash $identity)-eq$beforeId) 'REPLAY_MUTATED_CURRENT_HOST'
 $result=[ordered]@{format='sokna-universal-native-0322-0327-qa-v1';baseline='0.3.22';target='0.3.27';real_windows_service_upgrade='PASS';replay_without_changes='PASS';recovery_011_preserved=$true;original_receipt_preserved=$true;local_public_identity_unchanged=$true;physical_home_tested=$false;production_approved=$false}
 $result|ConvertTo-Json -Depth 6|Set-Content -LiteralPath $Output
 Write-Host 'PASS REAL WINDOWS SERVICE UNIVERSAL 0322 => 0326 => 0327 + VERIFIED REPLAY'
}finally{
 try{Stop-Service SoknaMaintenanceSupervisor -ErrorAction SilentlyContinue}catch{}
 & "$env:WINDIR\System32\sc.exe" delete SoknaMaintenanceSupervisor|Out-Null
 try{Remove-Item -LiteralPath $data -Recurse -Force -ErrorAction SilentlyContinue}catch{}
 try{Remove-Item -LiteralPath $install -Recurse -Force -ErrorAction SilentlyContinue}catch{}
}
