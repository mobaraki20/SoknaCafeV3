param([ValidateSet('Run','Sync')][string]$Mode='Run',[string]$Root,[string]$Package,[string]$Probe)
$ErrorActionPreference='Stop'
if($env:GITHUB_ACTIONS-ne'true' -or $env:RUNNER_OS-ne'Windows'){throw 'DISPOSABLE_WINDOWS_ONLY'}
$data=Join-Path ([Environment]::GetFolderPath('CommonApplicationData')) 'SOKNA'
. ./packaging/windows/scripts/maintenance-signed-health-v1.ps1
if($Mode-eq'Sync'){
 . ./packaging/windows/scripts/maintenance-installed-versions-v1.ps1
 Sync-MaintenanceInstalledVersions -AppRoot (Join-Path $env:RUNNER_TEMP 'integrated-service/Maintenance/App') -EvidenceRoot (Join-Path $data ('setup/component-transactions/maintenance-'+[Guid]::NewGuid().ToString('N')))
 exit 0
}
if((Get-Service SoknaMaintenanceSupervisor -ErrorAction SilentlyContinue) -or (Test-Path $data)){throw 'REFUSE_EXISTING_GLOBAL_SOKNA'}
$install=Join-Path $env:RUNNER_TEMP 'integrated-service'
$names=@('Maintenance/App/SoknaMaintenanceSupervisor.exe','Maintenance/App/SoknaMaintenanceUpdateEngine.exe','Maintenance/Recovery/SoknaMaintenanceRecoveryConsole.exe')
$projects=@('Sokna.Maintenance.Supervisor','Sokna.Maintenance.UpdateEngine','Sokna.Maintenance.RecoveryConsole')
New-Item -ItemType Directory -Force (Join-Path $data 'Maintenance'),(Join-Path $data 'setup')|Out-Null
foreach($i in 0..2){
 $to=Join-Path $install $names[$i];New-Item -ItemType Directory -Force (Split-Path $to -Parent)|Out-Null
 Copy-Item (Join-Path $env:SOKNA_TEST_OLD_0322_ROOT ($projects[$i]+'/'+[IO.Path]::GetFileName($to))) $to
}
foreach($rel in @('Runtime/SoknaRuntimeService.exe','PrintAgent/Service/Sokna.PrintAgent.Service.exe')){
 $to=Join-Path $install $rel;New-Item -ItemType Directory -Force (Split-Path $to -Parent)|Out-Null;Set-Content $to 'CI_UNTOUCHED'
}
Copy-Item (Join-Path $Root 'Maintenance/installed-release-identity.json') (Join-Path $data 'Maintenance/installed-release-identity.json')
@{format='sokna-maintenance-only-component-receipt-v1';version='0.3.22';supervisor_sha256=(Get-FileHash (Join-Path $install $names[0]) -Algorithm SHA256).Hash.ToLowerInvariant();engine_sha256=(Get-FileHash (Join-Path $install $names[1]) -Algorithm SHA256).Hash.ToLowerInvariant()}|ConvertTo-Json|Set-Content (Join-Path $data 'setup/maintenance-only-component-receipt-v1.json')
New-Service -Name SoknaMaintenanceSupervisor -BinaryPathName ('"'+(Join-Path $install $names[0])+'"') -StartupType Automatic|Out-Null
try{
 Start-Service SoknaMaintenanceSupervisor
 Assert-MaintenanceSignedHealth -ExpectedVersion '0.3.22' -Attempts 40
 & $Probe exercise-integrated-r125 $Root $Package
 if($LASTEXITCODE-ne0){throw 'INTEGRATED_NATIVE_PROBE_FAILED'}
}finally{
 Stop-Service SoknaMaintenanceSupervisor -ErrorAction SilentlyContinue
 & sc.exe delete SoknaMaintenanceSupervisor|Out-Null
 for($i=0;$i-lt60;$i++){
  $svc=Get-Service SoknaMaintenanceSupervisor -ErrorAction SilentlyContinue
  if(-not$svc){break};$svc.Dispose();Start-Sleep -Milliseconds 200
 }
 if(Get-Service SoknaMaintenanceSupervisor -ErrorAction SilentlyContinue){throw 'CI_SERVICE_DELETE_PENDING'}
 Remove-Item $data -Recurse -Force
}
