param(
  [Parameter(Mandatory=$true)][string]$RecoveredRoot,
  [string]$RepoRoot=(Resolve-Path (Join-Path $PSScriptRoot '..\..\..')).Path
)

$ErrorActionPreference='Stop'
$RepoRoot=[IO.Path]::GetFullPath($RepoRoot).TrimEnd('\')
$RecoveredRoot=[IO.Path]::GetFullPath($RecoveredRoot).TrimEnd('\')
Set-Location $RepoRoot

$expectedRuntime='26deed62524278fa93a9a576d958f9177fe97c878b75829a8dc93a1440955afd'
$expectedPrint='99565602163463e9bce0ca6b33933411635db87a03acaf03340d2a56d9c85ff4'
$expectedRecovery='85989bc8d1096575b0fcfc7d7d55aa999674c20ac8092fd3ea16d90e574c17e9'
$expectedOldSupervisor='afe538d1c51e276ecc55f442add6dc6c48272aea187e898e0b012630b73787e2'
$packageVersion='1.1.3'
$out=Join-Path $RepoRoot 'out'

function Sha([string]$Path){(Get-FileHash $Path -Algorithm SHA256).Hash.ToLowerInvariant()}
function RequireFile([string]$Path){if(-not(Test-Path $Path -PathType Leaf)){throw "Required file missing: $Path"}}
function Cleanup-QualificationServices {
  foreach($name in @('SoknaMaintenanceSupervisor','SoknaPrintWorker','SoknaRuntime')){
    try{Stop-Service $name -Force -ErrorAction SilentlyContinue}catch{}
    try{
      & "$env:SystemRoot\System32\sc.exe" query $name *> $null
      if($LASTEXITCODE -eq 0){& "$env:SystemRoot\System32\sc.exe" delete $name *> $null}
    }catch{}
    $global:LASTEXITCODE=0
  }
}

try {
  Write-Host '=== R3 source/package/artifact lock ==='
  $parent='e5e4c93b8b8506358b5e019762aca0c2b031a18b'
  & git merge-base --is-ancestor $parent HEAD
  if($LASTEXITCODE-ne0){throw 'R3 must descend from exact qualified R2 source.'}
  if((Get-Content packaging/windows/WINDOWS_SERVICES_VERSION.txt -Raw).Trim()-ne$packageVersion){throw 'Package version must be 1.1.3.'}
  $manifest=Get-Content packaging/windows/windows-services-component-manifest-v2.json -Raw|ConvertFrom-Json
  if([string]$manifest.package_version-ne$packageVersion){throw 'Component manifest package version mismatch.'}
  $lock=Get-Content packaging/windows/component-artifact-lock-v1.json -Raw|ConvertFrom-Json
  if([string]$lock.format-ne'sokna-windows-component-artifact-lock-v1'){throw 'Artifact lock format mismatch.'}
  if([string]$lock.components.runtime.version-ne'1.0.5'-or[string]$lock.components.runtime.primary_sha256-ne$expectedRuntime){throw 'Runtime artifact lock mismatch.'}
  if([string]$lock.components.'print-agent'.version-ne'6.2.9'-or[string]$lock.components.'print-agent'.primary_sha256-ne$expectedPrint){throw 'Print artifact lock mismatch.'}
  if([string]$lock.components.'maintenance-recovery-console'.version-ne'0.1.1'-or[string]$lock.components.'maintenance-recovery-console'.primary_sha256-ne$expectedRecovery){throw 'Recovery Console artifact lock mismatch.'}

  Write-Host '=== Verify exact recovered Home baseline bytes ==='
  $runtime=Join-Path $RecoveredRoot 'runtime\SoknaRuntimeService.exe'
  $print=Join-Path $RecoveredRoot 'print-worker\Service\Sokna.PrintAgent.Service.exe'
  $recovery=Join-Path $RecoveredRoot 'baseline-maintenance\recovery\SoknaMaintenanceRecoveryConsole.exe'
  $oldSupervisor=Join-Path $RecoveredRoot 'baseline-maintenance\app\SoknaMaintenanceSupervisor.exe'
  foreach($p in @($runtime,$print,$recovery,$oldSupervisor)){RequireFile $p}
  if((Sha $runtime)-ne$expectedRuntime){throw 'Recovered Runtime drift.'}
  if((Sha $print)-ne$expectedPrint){throw 'Recovered Print drift.'}
  if((Sha $recovery)-ne$expectedRecovery){throw 'Recovered Recovery Console drift.'}
  if((Sha $oldSupervisor)-ne$expectedOldSupervisor){throw 'Recovered Supervisor baseline drift.'}

  if(Test-Path $out){Remove-Item $out -Recurse -Force}
  New-Item -ItemType Directory -Path (Join-Path $out 'maintenance\app'),(Join-Path $out 'setup-ui'),(Join-Path $out 'setup-host'),(Join-Path $out 'p3-uat') -Force|Out-Null

  Write-Host '=== Publish changed components only ==='
  & dotnet publish windows/maintenance/src/Sokna.Maintenance.Supervisor/Sokna.Maintenance.Supervisor.csproj -c Release -r win-x64 --self-contained true -p:PublishSingleFile=true -p:DebugType=None -p:DebugSymbols=false -o (Join-Path $out 'maintenance\app')
  if($LASTEXITCODE-ne0){throw 'Supervisor publish failed.'}
  & dotnet publish windows/maintenance/src/Sokna.Maintenance.UpdateEngine/Sokna.Maintenance.UpdateEngine.csproj -c Release -r win-x64 --self-contained true -p:PublishSingleFile=true -p:DebugType=None -p:DebugSymbols=false -o (Join-Path $out 'maintenance\app')
  if($LASTEXITCODE-ne0){throw 'Update Engine publish failed.'}
  & dotnet publish packaging/windows/setup-ui/Sokna.SetupUi.csproj -c Release -r win-x64 --self-contained true -p:PublishSingleFile=true -o (Join-Path $out 'setup-ui')
  if($LASTEXITCODE-ne0){throw 'Setup UI publish failed.'}
  & dotnet publish packaging/windows/setup-host-v3/Sokna.SetupHostV3.csproj -c Release -r win-x64 --self-contained true -p:PublishSingleFile=true -p:DebugType=None -p:DebugSymbols=false -o (Join-Path $out 'setup-host')
  if($LASTEXITCODE-ne0){throw 'Setup Host v3 publish failed.'}
  & dotnet publish windows/maintenance/tests/Sokna.Maintenance.P3HomeUat/Sokna.Maintenance.P3HomeUat.csproj -c Release -r win-x64 --self-contained true -p:PublishSingleFile=true -p:IncludeNativeLibrariesForSelfExtract=true -o (Join-Path $out 'p3-uat')
  if($LASTEXITCODE-ne0){throw 'P3 qualification launcher publish failed.'}
  if((Test-Path (Join-Path $out 'runtime')) -or (Test-Path (Join-Path $out 'print-worker'))){throw 'R3 must not rebuild Runtime or Print.'}

  Write-Host '=== Prepare production shell with canonical carry-forward bytes ==='
  $shell=Join-Path $out 'shell'
  & ./packaging/windows/scripts/prepare-shell-payload-v4.ps1 `
    -RepoRoot $RepoRoot `
    -OutputRoot $shell `
    -RuntimeServiceExe $runtime `
    -SetupHostExe (Join-Path $out 'setup-host\SoknaSetupHost.exe') `
    -SetupUiExe (Join-Path $out 'setup-ui\SoknaSetupUi.exe') `
    -PrintWorkerBundle (Join-Path $RecoveredRoot 'print-worker') `
    -MaintenanceAppBundle (Join-Path $out 'maintenance\app') `
    -MaintenanceRecoveryBundle (Join-Path $RecoveredRoot 'baseline-maintenance\recovery') `
    -GitSha ((& git rev-parse HEAD).Trim())
  if($LASTEXITCODE-ne0){throw 'Shell preparation failed.'}
  $shellManifest=Get-Content (Join-Path $shell 'payload-manifest.json') -Raw|ConvertFrom-Json
  if([string]$shellManifest.package_version-ne$packageVersion){throw 'Shell package version mismatch.'}
  $targetChecks=@{
    (Join-Path $shell '_payload\runtime\SoknaRuntimeService.exe')=$expectedRuntime
    (Join-Path $shell '_payload\print-worker\Service\Sokna.PrintAgent.Service.exe')=$expectedPrint
    (Join-Path $shell '_payload\maintenance\recovery\SoknaMaintenanceRecoveryConsole.exe')=$expectedRecovery
  }
  foreach($p in $targetChecks.Keys){RequireFile $p;if((Sha $p)-ne$targetChecks[$p]){throw "Artifact-lock target mismatch: $p"}}

  Write-Host '=== Build fixture from exact Home baseline ==='
  $install=Join-Path $out 'home-baseline-install'
  $data=Join-Path $out 'home-baseline-data'
  New-Item -ItemType Directory -Path $install,$data,(Join-Path $data 'runtime'),(Join-Path $data 'setup'),(Join-Path $install 'Maintenance') -Force|Out-Null
  Copy-Item (Join-Path $RecoveredRoot 'runtime') (Join-Path $install 'Runtime') -Recurse -Force
  Copy-Item (Join-Path $RecoveredRoot 'print-worker') (Join-Path $install 'PrintAgent') -Recurse -Force
  Copy-Item (Join-Path $RecoveredRoot 'baseline-maintenance\app') (Join-Path $install 'Maintenance\App') -Recurse -Force
  Copy-Item (Join-Path $RecoveredRoot 'baseline-maintenance\recovery') (Join-Path $install 'Maintenance\Recovery') -Recurse -Force
  Copy-Item windows/runtime/runtime-config.example.json (Join-Path $data 'runtime\runtime-config.json') -Force
  [ordered]@{format='sokna-windows-services-install-state-v2';paired=$false;local_base_url=''}|ConvertTo-Json|Set-Content (Join-Path $data 'setup\windows-services-state.json') -Encoding utf8

  $installedRuntime=(Resolve-Path (Join-Path $install 'Runtime\SoknaRuntimeService.exe')).Path
  $installedPrint=(Resolve-Path (Join-Path $install 'PrintAgent\Service\Sokna.PrintAgent.Service.exe')).Path
  $installedMaintenance=(Resolve-Path (Join-Path $install 'Maintenance\App\SoknaMaintenanceSupervisor.exe')).Path
  $runtimeConfig=(Resolve-Path (Join-Path $data 'runtime\runtime-config.json')).Path
  & "$env:SystemRoot\System32\sc.exe" create SoknaRuntime binPath= ('"'+$installedRuntime+'" --config "'+$runtimeConfig+'"') start= demand | Out-Null
  if($LASTEXITCODE-ne0){throw 'Runtime fixture registration failed.'}
  & "$env:SystemRoot\System32\sc.exe" create SoknaPrintWorker binPath= ('"'+$installedPrint+'"') start= auto | Out-Null
  if($LASTEXITCODE-ne0){throw 'Print fixture registration failed.'}
  & "$env:SystemRoot\System32\sc.exe" create SoknaMaintenanceSupervisor binPath= ('"'+$installedMaintenance+'"') start= auto | Out-Null
  if($LASTEXITCODE-ne0){throw 'Maintenance fixture registration failed.'}

  Write-Host '=== Execute actual ComponentUpdatePlanner ==='
  $harness=Join-Path $out 'planner-harness'
  New-Item -ItemType Directory -Path $harness -Force|Out-Null
  @'
<Project Sdk="Microsoft.NET.Sdk">
  <PropertyGroup><OutputType>Exe</OutputType><TargetFramework>net10.0-windows</TargetFramework><ImplicitUsings>enable</ImplicitUsings><Nullable>enable</Nullable></PropertyGroup>
  <ItemGroup><Compile Include="..\..\packaging\windows\setup-ui\ComponentUpdatePlanner.cs" Link="ComponentUpdatePlanner.cs" /></ItemGroup>
</Project>
'@ | Set-Content (Join-Path $harness 'PlannerHarness.csproj') -Encoding utf8
  @'
using System.Text.Json;
namespace Sokna.SetupUi;
internal static class Harness {
  static int Main(string[] a) {
    var p=ComponentUpdatePlanner.Build(a[0],a[1],a[2]);
    var d=p.Rows.ToDictionary(x=>x.ComponentId,StringComparer.Ordinal);
    void Need(string id,ComponentAction action){if(!d.TryGetValue(id,out var r)||r.Action!=action)throw new Exception($"{id}: expected {action}, got {r?.Action} / {r?.Reason}");}
    Need("runtime",ComponentAction.Keep);
    Need("print-agent",ComponentAction.Keep);
    Need("maintenance",ComponentAction.Update);
    Need("maintenance-update-engine",ComponentAction.Update);
    Need("maintenance-recovery-console",ComponentAction.Keep);
    var dto=p.Rows.Select(r=>new{component_id=r.ComponentId,primary=r.Primary,current_version=r.CurrentVersion,target_version=r.TargetVersion,health=r.Health.ToString(),update_state=r.UpdateState,action=r.Action.ToString().ToUpperInvariant(),reason=r.Reason,installed_sha256=r.InstalledSha256,target_sha256=r.TargetSha256,service_name=r.ServiceName,transaction_group=r.TransactionGroup}).ToArray();
    File.WriteAllText(a[3],JsonSerializer.Serialize(dto,new JsonSerializerOptions{WriteIndented=true}));
    Console.WriteLine("REAL COMPONENT PLANNER HOME BASELINE: PASS");
    return 0;
  }
}
'@ | Set-Content (Join-Path $harness 'Program.cs') -Encoding utf8
  $planRows=Join-Path $out 'real-home-plan-rows.json'
  & dotnet run --project (Join-Path $harness 'PlannerHarness.csproj') -c Release -- $shell $install $data $planRows
  if($LASTEXITCODE-ne0){throw 'Actual ComponentUpdatePlanner Home-baseline gate failed.'}
  RequireFile $planRows
  Get-Content $planRows -Raw|Write-Host

  Write-Host '=== Execute actual component lifecycle ==='
  $installedRecovery=Join-Path $install 'Maintenance\Recovery\SoknaMaintenanceRecoveryConsole.exe'
  $before=[ordered]@{
    runtime_hash=Sha $installedRuntime
    runtime_time=(Get-Item $installedRuntime).LastWriteTimeUtc.ToString('o')
    runtime_qc=((& "$env:SystemRoot\System32\sc.exe" qc SoknaRuntime)|Out-String)
    print_hash=Sha $installedPrint
    print_time=(Get-Item $installedPrint).LastWriteTimeUtc.ToString('o')
    print_qc=((& "$env:SystemRoot\System32\sc.exe" qc SoknaPrintWorker)|Out-String)
    recovery_hash=Sha $installedRecovery
    recovery_time=(Get-Item $installedRecovery).LastWriteTimeUtc.ToString('o')
  }
  $rows=Get-Content $planRows -Raw|ConvertFrom-Json
  $tx='ins-'+[DateTime]::UtcNow.ToString('yyyyMMddTHHmmssZ')+'-'+[guid]::NewGuid().ToString('N').Substring(0,10)
  $txdir=Join-Path $env:LOCALAPPDATA "SOKNA\Installer\logs\$tx"
  New-Item -ItemType Directory -Path $txdir -Force|Out-Null
  $plan=[ordered]@{
    format='sokna-windows-install-plan-v3';schema_version=3;transaction_id=$tx;
    correlation_id='corr-'+[guid]::NewGuid().ToString('N');setup_session_id='ui-'+[guid]::NewGuid().ToString('N');created_at_utc=[DateTime]::UtcNow.ToString('o');
    mode='install';shell_root=$shell;install_root=$install;data_root=$data;package_version=$packageVersion;components=@($rows)
  }
  $planPath=Join-Path $txdir 'update-plan.json'
  $plan|ConvertTo-Json -Depth 10|Set-Content $planPath -Encoding utf8
  $hostExe=Join-Path $shell 'SoknaSetupHost.exe'
  $proc=Start-Process -FilePath $hostExe -ArgumentList @('--plan-file',('"'+$planPath+'"')) -Wait -PassThru -NoNewWindow
  if($proc.ExitCode-ne0){throw "Real Home-baseline lifecycle failed: $($proc.ExitCode)"}

  $after=[ordered]@{
    runtime_hash=Sha $installedRuntime
    runtime_time=(Get-Item $installedRuntime).LastWriteTimeUtc.ToString('o')
    runtime_qc=((& "$env:SystemRoot\System32\sc.exe" qc SoknaRuntime)|Out-String)
    print_hash=Sha $installedPrint
    print_time=(Get-Item $installedPrint).LastWriteTimeUtc.ToString('o')
    print_qc=((& "$env:SystemRoot\System32\sc.exe" qc SoknaPrintWorker)|Out-String)
    recovery_hash=Sha $installedRecovery
    recovery_time=(Get-Item $installedRecovery).LastWriteTimeUtc.ToString('o')
  }
  foreach($k in $before.Keys){if($before[$k]-ne$after[$k]){throw "KEEP component mutated: $k"}}
  $newSupervisor=Join-Path $install 'Maintenance\App\SoknaMaintenanceSupervisor.exe'
  $newEngine=Join-Path $install 'Maintenance\App\SoknaMaintenanceUpdateEngine.exe'
  $sv=[Diagnostics.FileVersionInfo]::GetVersionInfo($newSupervisor).FileVersion
  $ev=[Diagnostics.FileVersionInfo]::GetVersionInfo($newEngine).FileVersion
  if(-not$sv.StartsWith('0.3.0')-or-not$ev.StartsWith('0.3.0')){throw "Maintenance versions not applied: $sv / $ev"}
  $systemTx=$txdir
  $eventPath=Join-Path $systemTx 'system-events.jsonl'
  RequireFile $eventPath
  $events=@(Get-Content $eventPath|Where-Object{-not[string]::IsNullOrWhiteSpace($_)}|ForEach-Object{$_|ConvertFrom-Json})
  foreach($id in @('runtime','print-agent')){
    $row=@($events|Where-Object{$_.event_name-eq'component_action_completed'-and$_.component-eq$id-and$_.result-eq'kept'})|Select-Object -First 1
    if(-not$row){throw "KEEP evidence missing: $id"}
    if([string]$row.data.action-ne'KEEP'-or[string]$row.data.proof-ne'no_stop_no_copy_no_registration_no_restart'){throw "KEEP proof invalid: $id"}
  }
  $recoveryEvent=@($events|Where-Object{$_.event_name-eq'component_action_completed'-and$_.component-eq'maintenance-recovery-console'-and[string]$_.data.action-eq'KEEP'})|Select-Object -First 1
  if(-not$recoveryEvent){throw 'KEEP evidence missing: maintenance-recovery-console'}
  if([string]$recoveryEvent.data.after_sha256-ne$expectedRecovery){throw 'Recovery Console KEEP hash evidence invalid.'}
  if([string]$recoveryEvent.result-notin@('completed','kept')){throw "Recovery Console KEEP result invalid: $([string]$recoveryEvent.result)"}
  $before|ConvertTo-Json -Depth 4|Set-Content (Join-Path $out 'r3-before.json') -Encoding utf8
  $after|ConvertTo-Json -Depth 4|Set-Content (Join-Path $out 'r3-after.json') -Encoding utf8
  Copy-Item (Join-Path $systemTx 'install-result.json') (Join-Path $out 'r3-install-result.json') -Force
  Copy-Item $eventPath (Join-Path $out 'r3-system-events.jsonl') -Force

  Write-Host '=== Build Windows Services 1.1.3 ==='
  $iscc=@("${env:ProgramFiles(x86)}\Inno Setup 6\ISCC.exe","$env:ProgramFiles\Inno Setup 6\ISCC.exe")|Where-Object{$_ -and (Test-Path $_ -PathType Leaf)}|Select-Object -First 1
  if(-not$iscc){throw 'Inno Setup 6 not found.'}
  & ./packaging/windows/scripts/build-installer-v4.ps1 -ShellPayloadRoot $shell -RepoRoot $RepoRoot -IsccExe $iscc
  if($LASTEXITCODE-ne0){throw 'R3 installer build failed.'}
  $setup=Join-Path $RepoRoot 'packaging\windows\installer\out-v4\SOKNA-Windows-Services-Setup-1.1.3.exe'
  RequireFile $setup
  $setupSha=Sha $setup
  Set-Content (Join-Path $out 'R3_INSTALLER_SHA256.txt') $setupSha -Encoding ascii

  Write-Host '=== Freeze R3 qualification evidence ==='
  $evidence=[ordered]@{
    format='sokna-windows-p3-r3-home-baseline-qualification-v1'
    source_commit=((& git rev-parse HEAD).Trim())
    package_version=$packageVersion
    recovered_baseline_run=38982932863
    recovered_baseline_artifact=12082430274
    runtime_version='1.0.5';runtime_target_sha256=$expectedRuntime
    print_version='6.2.9';print_target_sha256=$expectedPrint
    maintenance_supervisor='0.3.0';update_engine='0.3.0'
    recovery_console='0.1.1';recovery_console_target_sha256=$expectedRecovery
    actual_planner_gate='runtime KEEP / print KEEP / maintenance UPDATE / update-engine UPDATE / recovery KEEP'
    keep_proof='no_stop_no_copy_no_registration_no_restart'
    database_mutation=$false;public_mutation=$false;windows_reboot=$false
    installer_sha256=$setupSha
  }
  $evidence|ConvertTo-Json -Depth 8|Set-Content (Join-Path $out 'P3_R3_QUALIFICATION.json') -Encoding utf8
  $delivery=Join-Path $out 'delivery'
  New-Item -ItemType Directory -Path $delivery -Force|Out-Null
  Copy-Item $setup $delivery
  $launcher=Join-Path $out 'p3-uat\SOKNA-Phase3-Home-UAT.exe'
  RequireFile $launcher
  Copy-Item $launcher (Join-Path $delivery 'SOKNA-Phase3-Qualification.exe') -Force
  foreach($name in @('P3_R3_QUALIFICATION.json','R3_INSTALLER_SHA256.txt','real-home-plan-rows.json','r3-install-result.json','r3-system-events.jsonl')){Copy-Item (Join-Path $out $name) $delivery -Force}
  Get-ChildItem $delivery -File|ForEach-Object{"$((Sha $_.FullName))  $($_.Name)"}|Set-Content (Join-Path $delivery 'SHA256SUMS.txt') -Encoding ascii
  $bundle=Join-Path $out 'SOKNA-Phase3-Qualification-Bundle-R3.zip'
  Compress-Archive -Path (Join-Path $delivery '*') -DestinationPath $bundle -CompressionLevel Optimal
  RequireFile $bundle

  Write-Host "R3_INSTALLER_SHA256=$setupSha"
  Write-Host 'P3 R3 REAL HOME BASELINE QUALIFICATION: PASS'
}
finally {
  Cleanup-QualificationServices
}
