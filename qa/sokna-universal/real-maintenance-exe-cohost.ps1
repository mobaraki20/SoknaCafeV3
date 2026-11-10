param(
    [Parameter(Mandatory=$true)][ValidateSet('install','verify','cleanup')][string]$Mode,
    [string]$Installer,
    [string]$ComponentManifest,
    [string]$BaselineRoot,
    [string]$LegacyRecovery011
)
$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest

# The real Inno EXE is invoked ONLY in a fresh, disposable GitHub Windows VM.
# This script must never be an installation instruction for the user's hosts.
if($env:GITHUB_ACTIONS -ne 'true' -or $env:RUNNER_OS -ne 'Windows' -or
   $env:SOKNA_REAL_MAINTENANCE_COHOST_QA -ne 'YES' -or
   [string]::IsNullOrWhiteSpace($env:RUNNER_TEMP)){
    throw 'REAL_MAINTENANCE_COHOST_DISPOSABLE_GITHUB_WINDOWS_ONLY'
}
$root=[IO.Path]::GetFullPath((Join-Path $env:RUNNER_TEMP 'sokna-native-maintenance-cohost'))
$temp=[IO.Path]::GetFullPath($env:RUNNER_TEMP).TrimEnd('\')+'\'
if(-not $root.StartsWith($temp,[StringComparison]::OrdinalIgnoreCase)){
    throw 'REAL_MAINTENANCE_COHOST_UNOWNED_ROOT'
}
$data=Join-Path ([Environment]::GetFolderPath('CommonApplicationData')) 'SOKNA'
$install=Join-Path $root 'SOKNA Windows Services'
$names=@(
  'Maintenance/App/SoknaMaintenanceSupervisor.exe',
  'Maintenance/App/SoknaMaintenanceUpdateEngine.exe',
  'Maintenance/Recovery/SoknaMaintenanceRecoveryConsole.exe'
)
$service='SoknaMaintenanceSupervisor'
$marker=Join-Path $root 'qa-ownership.json'
$proof=Join-Path $root 'real-maintenance-cohost.json'
function Need([bool]$ok,[string]$why){if(-not$ok){throw $why}}
function Hash([string]$path){
    Need (Test-Path -LiteralPath $path -PathType Leaf) ('REQUIRED_FILE_MISSING_'+[IO.Path]::GetFileName($path))
    return (Get-FileHash -LiteralPath $path -Algorithm SHA256).Hash.ToLowerInvariant()
}
function IdentityPath{ return (Join-Path $data 'Maintenance/installed-release-identity.json') }
function Installed([string]$rel){return Join-Path $install $rel}
function SafeService {
    $svc=Get-CimInstance Win32_Service -Filter "Name='SoknaMaintenanceSupervisor'" -ErrorAction Stop
    Need ($null -ne $svc) 'REAL_MAINTENANCE_SERVICE_NOT_REGISTERED'
    $want='\"'+(Installed $names[0])+'\"'
    Need ([string]$svc.PathName -ceq $want -and $svc.State -eq 'Running') 'REAL_MAINTENANCE_SERVICE_NOT_OWNED_OR_RUNNING'
}
function AssertSigned([string]$version){
    . ./packaging/windows/scripts/maintenance-signed-health-v1.ps1
    Assert-MaintenanceSignedHealth -ExpectedVersion $version -Attempts 40
}
function Manifest{
    Need (Test-Path -LiteralPath $ComponentManifest -PathType Leaf) 'MAINTENANCE_COMPONENT_MANIFEST_MISSING'
    $m=Get-Content -LiteralPath $ComponentManifest -Raw|ConvertFrom-Json
    Need (@($m.files).Count -eq 3) 'MAINTENANCE_EXACT_COMPONENT_MANIFEST_SHAPE'
    foreach($name in $names){
      $row=@($m.files|Where-Object{$_.path -ceq $name})
      Need ($row.Count -eq 1 -and [string]$row[0].sha256 -match '^[a-f0-9]{64}$') 'MAINTENANCE_COMPONENT_MANIFEST_UNQUALIFIED'
    }
    return $m
}
function CheckTarget([object]$m) {
    SafeService
    AssertSigned '0.3.26'
    foreach($name in $names){
        $row=@($m.files|Where-Object{$_.path -ceq $name})[0]
        Need ((Hash (Installed $name)) -ceq [string]$row.sha256) ('MAINTENANCE_TARGET_SHA_CHANGED_'+$name)
    }
    $identity=Get-Content -LiteralPath (IdentityPath) -Raw|ConvertFrom-Json
    Need ($identity.maintenance_supervisor_version -ceq '0.3.26' -and
          $identity.update_engine_version -ceq '0.3.26' -and
          $identity.local.keep -ceq 'local' -and
          $identity.public.keep -ceq 'public') 'MAINTENANCE_IDENTITY_CHANGED_LOCAL_OR_PUBLIC'
    $transaction=Join-Path $data 'setup/maintenance-components-v2/active.json'
    Need (Test-Path -LiteralPath $transaction -PathType Leaf) 'REAL_MAINTENANCE_TRANSACTION_RECEIPT_MISSING'
    $j=Get-Content -LiteralPath $transaction -Raw|ConvertFrom-Json
    Need ($j.phase -ceq 'COMMITTED' -and $j.baseline -ceq '0.3.22' -and $j.target -ceq '0.3.26') 'REAL_MAINTENANCE_TRANSACTION_NOT_COMMITTED'
    return $j
}
if($Mode -eq 'install'){
    Need (-not(Test-Path -LiteralPath $data)) 'REFUSE_EXISTING_PROGRAMDATA_SOKNA'
    Need (-not(Test-Path -LiteralPath $root)) 'REFUSE_EXISTING_MAINTENANCE_QA_ROOT'
    Need (-not(Get-Service $service -ErrorAction SilentlyContinue)) 'REFUSE_EXISTING_MAINTENANCE_SERVICE'
    Need (-not(Get-Service SoknaApache -ErrorAction SilentlyContinue)) 'REFUSE_EXISTING_LOCAL_SERVICE'
    $m=Manifest
    $installerHash=Hash $Installer
    $recoveryHash=Hash $LegacyRecovery011
    Need ($recoveryHash -ceq '368ea590a52b895d409d0bbc23926eebd58f512ac73a75dffac8163e5dc682dd') 'ORIGINAL_CAFE_RECOVERY_011_MISMATCH'
    $ver=[Diagnostics.FileVersionInfo]::GetVersionInfo($LegacyRecovery011)
    Need ([string]$ver.FileVersion -ceq '0.1.1.0') 'ORIGINAL_CAFE_RECOVERY_PE_VERSION_MISMATCH'
    New-Item -ItemType Directory -Path $root,(Join-Path $data 'Maintenance/operations'),(Join-Path $data 'setup') -Force|Out-Null
    $baselineFiles=@(
      'Sokna.Maintenance.Supervisor/SoknaMaintenanceSupervisor.exe',
      'Sokna.Maintenance.UpdateEngine/SoknaMaintenanceUpdateEngine.exe'
    )
    for($i=0;$i-lt2;$i++){
        $destination=Installed $names[$i]
        New-Item -ItemType Directory -Path (Split-Path $destination -Parent) -Force|Out-Null
        $source=Join-Path $BaselineRoot $baselineFiles[$i]
        Need (Test-Path -LiteralPath $source -PathType Leaf) 'PHYSICAL_0322_BASELINE_BINARY_MISSING'
        Need ([string]([Diagnostics.FileVersionInfo]::GetVersionInfo($source).FileVersion) -ceq '0.3.22.0') 'PHYSICAL_0322_BASELINE_BINARY_VERSION_WRONG'
        Copy-Item -LiteralPath $source -Destination $destination
    }
    $recoveryDestination=Installed $names[2]
    New-Item -ItemType Directory -Path (Split-Path $recoveryDestination -Parent) -Force|Out-Null
    Copy-Item -LiteralPath $LegacyRecovery011 -Destination $recoveryDestination
    @{'maintenance_supervisor_version'='0.3.22';'update_engine_version'='0.3.22';
      'local'=@{'version'='qa-r112';'keep'='local'};
      'public'=@{'version'='qa-r112';'keep'='public'}}|
      ConvertTo-Json -Depth 10|Set-Content -LiteralPath (IdentityPath) -Encoding utf8NoBOM
    @{'format'='sokna-maintenance-only-component-receipt-v1';'version'='0.3.22';
      'supervisor_sha256'=(Hash (Installed $names[0]));
      'engine_sha256'=(Hash (Installed $names[1]))}|
      ConvertTo-Json -Depth 6|
      Set-Content -LiteralPath (Join-Path $data 'setup/maintenance-only-component-receipt-v1.json') -Encoding utf8NoBOM
    @{'format'='sokna-real-maintenance-cohost-qa-v1';'run_id'=$env:GITHUB_RUN_ID;
      'root'=$root;'data_root'=$data;'installer_sha256'=$installerHash}|
      ConvertTo-Json -Depth 5|Set-Content -LiteralPath $marker -Encoding utf8NoBOM
    $supervisor=Installed $names[0]
    New-Service -Name $service -BinaryPathName ('"'+$supervisor+'"') -DisplayName 'SOKNA disposable cohost QA' -StartupType Automatic|Out-Null
    Start-Service $service
    AssertSigned '0.3.22'
    $proc=Start-Process -FilePath $Installer -ArgumentList @('/VERYSILENT','/SUPPRESSMSGBOXES','/NORESTART','/SP-') -PassThru
    Need ($proc.WaitForExit(180000)) 'EXACT_INNO_INSTALLER_TIMEOUT'
    Need ($proc.ExitCode -eq 0) ('EXACT_INNO_INSTALLER_FAILED_'+$proc.ExitCode)
    $j=CheckTarget $m
    $result=[ordered]@{
        format='sokna-real-maintenance-exe-cohost-qa-v1'
        installer_sha256=$installerHash;baseline_supervisor='0.3.22';baseline_recovery='0.1.1'
        target_supervisor='0.3.26';target_recovery='0.1.7'
        maintenance_real_exe_installed=$true;maintenance_real_supervisor_running=$true
        maintenance_signed_health_verified=$true;maintenance_native_engine_sha256=(Hash (Installed $names[1]))
        maintenance_native_supervisor_sha256=(Hash (Installed $names[0]))
        maintenance_native_recovery_sha256=(Hash (Installed $names[2]))
        installed_identity_sha256=(Hash (IdentityPath))
        maintenance_transaction_id=$j.id
        local_public_replacement_by_installer=$false
        local_public_test_executed=$false;production_authorized=$false
        global_three_component_commit_rollback_verified=$false
        physical_customer_machine_tested=$false
    }
    $result|ConvertTo-Json -Depth 10|Set-Content -LiteralPath $proof -Encoding utf8NoBOM
    Write-Host 'PASS EXACT MAINTENANCE INNO EXE INSTALLED REAL SUPERVISOR+ENGINE+RECOVERY FROM CAFE 0322/011'
    Write-Host ('MAINTENANCE_COHOST_ENGINE_EXE='+[IO.Path]::GetFullPath((Installed $names[1])))
}elseif($Mode -eq 'verify'){
    Need (Test-Path -LiteralPath $marker -PathType Leaf) 'UNOWNED_MAINTENANCE_COHOST'
    $owner=Get-Content -LiteralPath $marker -Raw|ConvertFrom-Json
    Need ($owner.format -ceq 'sokna-real-maintenance-cohost-qa-v1' -and
          $owner.root -ceq $root -and $owner.run_id -ceq $env:GITHUB_RUN_ID) 'MAINTENANCE_COHOST_OWNER_MISMATCH'
    $before=Get-Content -LiteralPath $proof -Raw|ConvertFrom-Json
    Need ($before.format -ceq 'sokna-real-maintenance-exe-cohost-qa-v1' -and
          $before.production_authorized -eq $false -and
          $before.global_three_component_commit_rollback_verified -eq $false) 'MAINTENANCE_COHOST_PROOF_INVALID'
    $j=CheckTarget (Manifest)
    Need ($j.id -ceq $before.maintenance_transaction_id -and
          (Hash (IdentityPath)) -ceq $before.installed_identity_sha256 -and
          (Hash (Installed $names[1])) -ceq $before.maintenance_native_engine_sha256 -and
          (Hash (Installed $names[0])) -ceq $before.maintenance_native_supervisor_sha256 -and
          (Hash (Installed $names[2])) -ceq $before.maintenance_native_recovery_sha256) 'MAINTENANCE_CHANGED_DURING_LOCAL_PUBLIC_RUN'
    $localRoot=Join-Path $env:RUNNER_TEMP 'sokna-r149b-windows-exact-e2e/artifacts'
    foreach($source in @('r75','r140')){
        $p=Join-Path $localRoot ('native-colocated-'+$source+'.json')
        Need (Test-Path -LiteralPath $p -PathType Leaf) 'MISSING_NATIVE_LOCAL_PUBLIC_REPORT'
        $record=Get-Content -LiteralPath $p -Raw|ConvertFrom-Json
        Need ($record.exact_installer_engine -eq $true -and
              $record.local_engine_sha256 -ceq $before.maintenance_native_engine_sha256 -and
              $record.database_forward_fence_unresolved -eq $true -and
              $record.production_authorized -eq $false -and
              $record.common_three_component_commit_rollback_verified -eq $false) 'INSTALLED_ENGINE_LOCAL_PUBLIC_EVIDENCE_MISMATCH'
    }
    $before.local_public_test_executed=$true
    $before.co_host_local_profiles=@('r75','r140')
    $before.global_three_component_commit_rollback_verified=$false
    $before.production_authorized=$false
    $before|ConvertTo-Json -Depth 10|Set-Content -LiteralPath $proof -Encoding utf8NoBOM
    Write-Host 'PASS EXACT MAINTENANCE EXE AND INSTALLED ENGINE COHOST WITH NATIVE LOCAL/PUBLIC'
    Write-Host 'NO COORDINATED THREE-COMPONENT TRANSACTION OR FIELD APPROVAL'
}else{
    if(-not(Test-Path -LiteralPath $marker -PathType Leaf)){Write-Host 'NO OWNED QA MAINTENANCE TO CLEAN';exit 0}
    $owner=Get-Content -LiteralPath $marker -Raw|ConvertFrom-Json
    Need ($owner.format -ceq 'sokna-real-maintenance-cohost-qa-v1' -and
          $owner.root -ceq $root -and $owner.run_id -ceq $env:GITHUB_RUN_ID) 'REFUSE_TO_CLEAN_NON_QA_MAINTENANCE'
    $svc=Get-CimInstance Win32_Service -Filter "Name='SoknaMaintenanceSupervisor'" -ErrorAction SilentlyContinue
    if($svc){
        Need ([string]$svc.PathName -ceq ('"'+(Installed $names[0])+'"')) 'REFUSE_TO_CLEAN_FOREIGN_MAINTENANCE_SERVICE'
        Stop-Service $service -ErrorAction SilentlyContinue
        & sc.exe delete $service | Out-Null
    }
    Need (-not(Get-Service SoknaApache -ErrorAction SilentlyContinue)) 'REFUSE_REMOVE_WHILE_LOCAL_APACHE_PRESENT'
    # Only this fresh runner's marked ProgramData/SOKNA tree is eligible.
    if(Test-Path -LiteralPath $data){Remove-Item -LiteralPath $data -Recurse -Force}
    Write-Host 'PASS CLEANED OWNED DISPOSABLE MAINTENANCE SERVICE AND DATA'
}
