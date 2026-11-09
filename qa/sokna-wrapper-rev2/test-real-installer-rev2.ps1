param([string]$Installer,[string]$ComponentManifest,[string]$BaselineRoot,[string]$Old0323Installer,[string]$Old0324Installer,[string]$Old0325Installer,[string]$Output)
$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest
if($env:GITHUB_ACTIONS-ne'true' -or $env:RUNNER_OS-ne'Windows'){throw 'DISPOSABLE_WINDOWS_ONLY'}
if(Get-Service SoknaMaintenanceSupervisor -ErrorAction SilentlyContinue){throw 'REFUSE_EXISTING_SUPERVISOR'}
$data=Join-Path ([Environment]::GetFolderPath('CommonApplicationData')) 'SOKNA'
if(Test-Path $data){throw 'REFUSE_EXISTING_SOKNA_DATA'}
. ./packaging/windows/scripts/maintenance-signed-health-v1.ps1
$names=@('Maintenance/App/SoknaMaintenanceSupervisor.exe','Maintenance/App/SoknaMaintenanceUpdateEngine.exe','Maintenance/Recovery/SoknaMaintenanceRecoveryConsole.exe')
$projects=@('Sokna.Maintenance.Supervisor','Sokna.Maintenance.UpdateEngine','Sokna.Maintenance.RecoveryConsole')
$target=Get-Content $ComponentManifest -Raw|ConvertFrom-Json
$installerHash=(Get-FileHash $Installer -Algorithm SHA256).Hash.ToLowerInvariant()
function Hash([string]$p){(Get-FileHash -LiteralPath $p -Algorithm SHA256).Hash.ToLowerInvariant()}
function Need([bool]$ok,[string]$why){if(-not$ok){throw $why}}
function InvokeInstaller([string]$p){
 $proc=Start-Process -FilePath $p -ArgumentList @('/VERYSILENT','/SUPPRESSMSGBOXES','/NORESTART','/SP-') -PassThru
 if(-not$proc.WaitForExit(180000)){$proc.Kill();throw 'INSTALLER_TIMEOUT'}
 return $proc.ExitCode
}
$results=@()
foreach($baseline in @('0.3.22','0.3.23','0.3.24','0.3.25')){
 $folder=if($baseline-eq'0.3.22'){'آزمون سرویس خانه'}else{'service-cafe-lab'}
 $install=Join-Path $env:RUNNER_TEMP ($folder+'-'+$baseline)
 New-Item -ItemType Directory -Path $install,(Join-Path $data 'Maintenance/operations'),(Join-Path $data 'setup') -Force|Out-Null
 foreach($i in 0..2){
  $to=Join-Path $install $names[$i]
  New-Item -ItemType Directory -Path (Split-Path -Parent $to) -Force|Out-Null
  Copy-Item (Join-Path $BaselineRoot ($projects[$i]+'/'+[IO.Path]::GetFileName($to))) $to
 }
 foreach($rel in @('Runtime/SoknaRuntimeService.exe','PrintAgent/Service/Sokna.PrintAgent.Service.exe')){
  $to=Join-Path $install $rel
  New-Item -ItemType Directory -Path (Split-Path -Parent $to) -Force|Out-Null
  Set-Content $to 'UNTOUCHED_CI_COMPONENT'
 }
 $identity=Join-Path $data 'Maintenance/installed-release-identity.json'
 @{maintenance_supervisor_version='0.3.22';update_engine_version='0.3.22';local=@{version='synthetic-R112';keep='local'};public=@{version='synthetic-public';keep='public'}}|
  ConvertTo-Json -Depth 8|Set-Content $identity
 @{format='sokna-maintenance-only-component-receipt-v1';version='0.3.22';supervisor_sha256=(Hash (Join-Path $install $names[0]));engine_sha256=(Hash (Join-Path $install $names[1]))}|
  ConvertTo-Json|Set-Content (Join-Path $data 'setup/maintenance-only-component-receipt-v1.json')
 $exe=Join-Path $install $names[0]
 New-Service -Name SoknaMaintenanceSupervisor -BinaryPathName ('"'+$exe+'"') -DisplayName 'SOKNA disposable installer QA' -StartupType Automatic|Out-Null
 try{
  Start-Service SoknaMaintenanceSupervisor
  Assert-MaintenanceSignedHealth -ExpectedVersion '0.3.22' -Attempts 40
  if($baseline-eq'0.3.23'){
   Need ((InvokeInstaller $Old0323Installer)-eq0) 'PUBLISHED_0323_BOOTSTRAP_FAILED'
   Assert-MaintenanceSignedHealth -ExpectedVersion '0.3.23' -Attempts 40
  }
  if($baseline-eq'0.3.24'){
   Need ((InvokeInstaller $Old0324Installer)-eq0) 'BUILT_0324_BOOTSTRAP_FAILED'
   Assert-MaintenanceSignedHealth -ExpectedVersion '0.3.24' -Attempts 40
  }
  if($baseline-eq'0.3.25'){
   Need ((InvokeInstaller $Old0325Installer)-eq0) 'BUILT_0325_BOOTSTRAP_FAILED'
   Assert-MaintenanceSignedHealth -ExpectedVersion '0.3.25' -Attempts 40
  }
  $legacyBefore=$null
  if($baseline-eq'0.3.22'){
   . ./packaging/windows/scripts/maintenance-installed-versions-v1.ps1
   $legacy=Get-Content $identity -Raw|ConvertFrom-Json
   $legacy.maintenance_supervisor_version='0.3.21';$legacy.update_engine_version='0.3.21'
   $legacy|ConvertTo-Json -Depth 20|Set-Content $identity
   $legacyBefore=Join-Path $install 'legacy-before-sync.json';Copy-Item $identity $legacyBefore
   $sync=Join-Path $data ('setup/component-transactions/maintenance-'+[Guid]::NewGuid().ToString('N'))
   Sync-MaintenanceInstalledVersions -AppRoot (Join-Path $install 'Maintenance/App') -EvidenceRoot $sync
  }elseif($baseline-eq'0.3.23'){
   $oldMarker=Get-Content (Join-Path $data 'setup/r125-0323-three-component/active.json') -Raw|ConvertFrom-Json
   $legacyBefore=Join-Path $data ('setup/r125-0323-three-component/tx-'+$oldMarker.id+'/before/installed-release-identity.json')
  }
  if($legacyBefore){
   dotnet run --project qa/sokna-r129-compat/CompatProbe.csproj -c Release -- verify-installed-maintenance-identity $legacyBefore (Join-Path $data 'Maintenance')
   Need ($LASTEXITCODE-eq0) 'ACTUAL_LEGACY_IDENTITY_RECEIPT_REJECTED'
  }
  $beforeIdentity=Join-Path $install 'identity-before-maintenance-upgrade.json'
  Copy-Item $identity $beforeIdentity
  # REV2: same exact installed binaries, but an intentionally mismatched
  # identity must return its precise reason before any replacement occurs.
  $identityBytes=[IO.File]::ReadAllBytes($identity)
  $desktop=[Environment]::GetFolderPath('DesktopDirectory')
  $priorReports=@(Get-ChildItem $desktop -Filter 'SOKNA-MAINTENANCE-INSTALL-FAILED-*.json' -ErrorAction SilentlyContinue|ForEach-Object{$_.FullName})
  try{
   $bad=Get-Content $identity -Raw|ConvertFrom-Json
   $bad.maintenance_supervisor_version='0.3.21';$bad.update_engine_version='0.3.21'
   $bad|ConvertTo-Json -Depth 30|Set-Content $identity
   Need ((InvokeInstaller $Installer)-ne0) 'MISMATCHED_IDENTITY_ACCEPTED'
   $fresh=@(Get-ChildItem $desktop -Filter 'SOKNA-MAINTENANCE-INSTALL-FAILED-*.json'|Where-Object{$_.FullName-notin$priorReports})
   Need ($fresh.Count-eq1) 'ACTUAL_REJECTION_REPORT_MISSING'
   $failure=Get-Content $fresh[0].FullName -Raw|ConvertFrom-Json
   Need ($failure.safe_code-eq'MAINTENANCE_INSTALLED_IDENTITY_BASELINE_MISMATCH' -and $failure.failed_stage-eq'INSTALLED_IDENTITY' -and $null-eq$failure.rollback_verified -and -not$failure.recovery_requested) 'ACTUAL_REJECTION_REASON_WRONG'
   Assert-MaintenanceSignedHealth -ExpectedVersion $baseline -Attempts 40
  }finally{[IO.File]::WriteAllBytes($identity,$identityBytes)}
  $oldHashes=@($names|ForEach-Object{Hash (Join-Path $install $_)})
  $oldIdentity=Hash $identity
  $other=@{};foreach($rel in @('Runtime/SoknaRuntimeService.exe','PrintAgent/Service/Sokna.PrintAgent.Service.exe')){$other[$rel]=Hash (Join-Path $install $rel)}
  # Exercise REAL copy failure through the exact Inno EXE. A readable but
  # write-locked unchanged Engine must not prevent restoring the Supervisor.
  $held=[IO.File]::Open((Join-Path $install $names[1]),[IO.FileMode]::Open,[IO.FileAccess]::Read,[IO.FileShare]::Read)
  try{$failed=InvokeInstaller $Installer;Need ($failed-ne0) 'LOCKED_ENGINE_ACCEPTED'}finally{$held.Dispose()}
  Assert-MaintenanceSignedHealth -ExpectedVersion $baseline -Attempts 40
  foreach($i in 0..2){Need ((Hash (Join-Path $install $names[$i]))-eq$oldHashes[$i]) 'REAL_ROLLBACK_FILE_MISMATCH'}
  Need ((Hash $identity)-eq$oldIdentity) 'REAL_ROLLBACK_IDENTITY_MISMATCH'
  $marker=Join-Path $data 'setup/maintenance-components-v2/active.json'
  Need ((Get-Content $marker -Raw|ConvertFrom-Json).phase-eq'ROLLED_BACK') 'REAL_ROLLBACK_RECEIPT_MISSING'
  Need ((InvokeInstaller $Installer)-eq0) 'EXACT_INSTALLER_UPGRADE_FAILED'
  Assert-MaintenanceSignedHealth -ExpectedVersion '0.3.26' -Attempts 40
  foreach($entry in $target.files){Need ((Hash (Join-Path $install $entry.path))-eq$entry.sha256) 'TARGET_BINARY_HASH_MISMATCH'}
  $doc=Get-Content $identity -Raw|ConvertFrom-Json
  Need ($doc.maintenance_supervisor_version-eq'0.3.26' -and $doc.update_engine_version-eq'0.3.26' -and $doc.local.keep-eq'local' -and $doc.public.keep-eq'public') 'IDENTITY_SCOPE_CHANGED'
  $state=Get-Content $marker -Raw|ConvertFrom-Json
  Need ($state.phase-eq'COMMITTED' -and $state.baseline-eq$baseline) 'COMMIT_RECEIPT_INVALID'
  dotnet run --project qa/sokna-r129-compat/CompatProbe.csproj -c Release -- verify-installed-maintenance-identity $beforeIdentity (Join-Path $data 'Maintenance')
  Need ($LASTEXITCODE-eq0) 'ACTUAL_COMPONENT_IDENTITY_PROOF_REJECTED'
  $resultFile=Join-Path (Split-Path $marker -Parent) ('tx-'+$state.id+'/result.json')
  $resultOriginal=[IO.File]::ReadAllBytes($resultFile)
  try{
   $broken=Get-Content $resultFile -Raw|ConvertFrom-Json
   $broken.files[0].sha256=('f'*64)
   $broken|ConvertTo-Json -Depth 30|Set-Content $resultFile
   dotnet run --project qa/sokna-r129-compat/CompatProbe.csproj -c Release -- verify-installed-maintenance-identity $beforeIdentity (Join-Path $data 'Maintenance')
   Need ($LASTEXITCODE-ne0) 'TAMPERED_COMPONENT_RECEIPT_ACCEPTED'
  }finally{[IO.File]::WriteAllBytes($resultFile,$resultOriginal)}
  $beforeReplay=@($names|ForEach-Object{Hash (Join-Path $install $_)})
  Need ((InvokeInstaller $Installer)-eq0) 'EXACT_VERIFIED_REPLAY_NOT_IDEMPOTENT'
  foreach($i in 0..2){Need ((Hash (Join-Path $install $names[$i]))-eq$beforeReplay[$i]) 'REJECTED_REINSTALL_MUTATED_FILES'}
  foreach($rel in $other.Keys){Need ((Hash (Join-Path $install $rel))-eq$other[$rel]) 'UNRELATED_COMPONENT_CHANGED'}
  $results+=@{baseline=$baseline;target='0.3.26';installer_sha256=$installerHash;precise_pretransaction_rejection_report='PASS';real_service_upgrade='PASS';real_file_lock_rollback='PASS';exact_replay_verified_without_changes=$true;local_public_identity_preserved=$true;runtime_print_files_preserved=$true;physical_host_tested=$false;production_approved=$false}
  Write-Host ('PASS REAL EXACT INNO EXE UPGRADE AND ROLLBACK baseline='+$baseline)
 }finally{
  if(Get-Service SoknaMaintenanceSupervisor -ErrorAction SilentlyContinue){
   Stop-Service SoknaMaintenanceSupervisor -ErrorAction SilentlyContinue
   & sc.exe delete SoknaMaintenanceSupervisor|Out-Null
   for($i=0;$i-lt60;$i++){
    $svc=Get-Service SoknaMaintenanceSupervisor -ErrorAction SilentlyContinue
    if(-not$svc){break};$svc.Dispose();Start-Sleep -Milliseconds 200
   }
  }
  Need (-not(Get-Service SoknaMaintenanceSupervisor -ErrorAction SilentlyContinue)) 'CI_SERVICE_DELETE_PENDING'
  Remove-Item $data -Recurse -Force
 }
}
Need ($results.Count-eq4) 'INSTALLER_FOUR_BASELINES_INCOMPLETE'
$results|ConvertTo-Json -Depth 8|Set-Content $Output -Encoding UTF8
Write-Host 'PASS SAME INNO INSTALLER FROM FOUR MAINTENANCE BASELINES; NO FIELD APPROVAL'
