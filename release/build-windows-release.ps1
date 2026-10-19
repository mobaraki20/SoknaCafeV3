param(
 [string]$RepoRoot=(Resolve-Path (Join-Path $PSScriptRoot '..')).Path,
 [string]$OutputRoot=(Join-Path $PSScriptRoot 'out-windows'),
 [string]$IsccExe=''
)
$ErrorActionPreference='Stop'
$OutputRoot=[IO.Path]::GetFullPath($OutputRoot);if(Test-Path $OutputRoot){Remove-Item $OutputRoot -Recurse -Force};New-Item -ItemType Directory -Path $OutputRoot -Force|Out-Null
$runtimeOut=Join-Path $OutputRoot 'runtime';$printOut=Join-Path $OutputRoot 'print';$extract=Join-Path $OutputRoot 'extract';$shell=Join-Path $OutputRoot 'shell'
& (Join-Path $RepoRoot 'windows\runtime\build-component-package.ps1') -RepoRoot $RepoRoot -OutputDir $runtimeOut
& (Join-Path $RepoRoot 'windows\print-agent\build-component-package.ps1') -RepoRoot $RepoRoot -OutputDir $printOut
$runtimeZip=Get-ChildItem $runtimeOut -Filter 'SoknaRuntime-*-win-x64.zip'|Select-Object -First 1
$printZip=Get-ChildItem $printOut -Filter 'SoknaPrintAgent-*-win-x64.zip'|Select-Object -First 1
if(-not$runtimeZip-or-not$printZip){throw 'Independent Runtime/Print artifacts were not produced.'}
$runtimeExtract=Join-Path $extract 'runtime';$printExtract=Join-Path $extract 'print';Expand-Archive $runtimeZip.FullName $runtimeExtract -Force;Expand-Archive $printZip.FullName $printExtract -Force
$runtimeExe=Join-Path $runtimeExtract 'SoknaRuntimeService.exe';if(-not(Test-Path $runtimeExe)){throw 'Runtime artifact is incomplete.'}
$setupHost=Join-Path $OutputRoot 'SoknaSetupHost.exe';$setupUi=Join-Path $OutputRoot 'SoknaSetupUi.exe'
& (Join-Path $RepoRoot 'packaging\windows\scripts\build-setup-host.ps1') -RepoRoot $RepoRoot -OutputPath $setupHost
& (Join-Path $RepoRoot 'packaging\windows\scripts\build-setup-ui.ps1') -RepoRoot $RepoRoot -OutputPath $setupUi
$sha=(& git -C $RepoRoot rev-parse HEAD).Trim()
& (Join-Path $RepoRoot 'packaging\windows\scripts\prepare-shell-payload.ps1') -RepoRoot $RepoRoot -OutputRoot $shell -RuntimeServiceExe $runtimeExe -SetupHostExe $setupHost -SetupUiExe $setupUi -PrintWorkerBundle $printExtract -GitSha $sha
& (Join-Path $RepoRoot 'packaging\windows\scripts\build-installer.ps1') -RepoRoot $RepoRoot -ShellPayloadRoot $shell -IsccExe $IsccExe
$installerOut=Join-Path $RepoRoot 'packaging\windows\installer\out';$setup=Get-ChildItem $installerOut -Filter 'SOKNA-Windows-Services-Setup-*.exe'|Select-Object -First 1
if(-not$setup){throw 'Windows Services installer artifact was not produced.'}
Copy-Item $setup.FullName (Join-Path $OutputRoot $setup.Name) -Force

# The legacy shell above remains only for the existing isolated SCM smoke.
# It is NOT the distributable. Every real Installer must use the authoritative
# five-component V4/R9 shell, including both Maintenance executables and the
# independently frozen Recovery Console. Mixing V4 UI with the legacy
# two-component shell caused a real-host failed upgrade (2026-10-19).
$maintenanceApp=Join-Path $OutputRoot 'maintenance-app'
$maintenanceRecovery=Join-Path $OutputRoot 'maintenance-recovery'
$unifiedShell=Join-Path $OutputRoot 'unified-shell-v4'
New-Item -ItemType Directory -Path $maintenanceApp,$maintenanceRecovery -Force|Out-Null
& dotnet publish (Join-Path $RepoRoot 'windows\maintenance\src\Sokna.Maintenance.Supervisor\Sokna.Maintenance.Supervisor.csproj') -c Release -r win-x64 --self-contained true -p:PublishSingleFile=true -p:PublishTrimmed=false -p:DebugType=None -p:DebugSymbols=false -o $maintenanceApp
if($LASTEXITCODE-ne0){throw 'Maintenance Supervisor full publish failed.'}
& dotnet publish (Join-Path $RepoRoot 'windows\maintenance\src\Sokna.Maintenance.UpdateEngine\Sokna.Maintenance.UpdateEngine.csproj') -c Release -r win-x64 --self-contained true -p:PublishSingleFile=true -p:PublishTrimmed=false -p:DebugType=None -p:DebugSymbols=false -o $maintenanceApp
if($LASTEXITCODE-ne0){throw 'Maintenance Update Engine full publish failed.'}
& dotnet publish (Join-Path $RepoRoot 'windows\maintenance\src\Sokna.Maintenance.RecoveryConsole\Sokna.Maintenance.RecoveryConsole.csproj') -c Release -r win-x64 --self-contained true -p:PublishSingleFile=true -p:PublishTrimmed=false -p:DebugType=None -p:DebugSymbols=false -o $maintenanceRecovery
if($LASTEXITCODE-ne0){throw 'Independent Recovery Console full publish failed.'}

& (Join-Path $RepoRoot 'packaging\windows\scripts\prepare-shell-payload-v9.ps1') -RepoRoot $RepoRoot -OutputRoot $unifiedShell -RuntimeServiceExe $runtimeExe -SetupHostExe $setupHost -SetupUiExe $setupUi -PrintWorkerBundle $printExtract -MaintenanceAppBundle $maintenanceApp -MaintenanceRecoveryBundle $maintenanceRecovery -GitSha $sha
$manifest=Get-Content -LiteralPath (Join-Path $unifiedShell 'payload-manifest.json') -Raw|ConvertFrom-Json
$componentManifest=Get-Content -LiteralPath (Join-Path $unifiedShell 'windows-services-component-manifest-v2.json') -Raw|ConvertFrom-Json
$version=(Get-Content -LiteralPath (Join-Path $RepoRoot 'packaging\windows\WINDOWS_SERVICES_VERSION.txt') -Raw).Trim()
if([string]$manifest.format-ne'sokna-windows-services-shell-v3'-or[int]$manifest.schema_version-ne3-or[string]$manifest.package_version-ne$version-or[string]$componentManifest.package_version-ne$version){throw 'BLOCK_DISTRIBUTION: V4 shell/package/manifest version mismatch.'}
if(@($manifest.components).Count-ne5-or@($componentManifest.components).Count-ne5){throw 'BLOCK_DISTRIBUTION: expected all five Windows components.'}
$expected=@('runtime','print-agent','maintenance','maintenance-update-engine','maintenance-recovery-console')
foreach($id in $expected){
  $row=@($componentManifest.components|Where-Object{[string]$_.component_id-eq$id})
  if($row.Count-ne1){throw "BLOCK_DISTRIBUTION: missing/duplicate component $id"}
  $payload=Join-Path $unifiedShell (([string]$row[0].payload_primary).Replace('/','\'))
  if(-not(Test-Path -LiteralPath $payload -PathType Leaf)){throw "BLOCK_DISTRIBUTION: missing executable $id"}
  $actualVersion=([Diagnostics.FileVersionInfo]::GetVersionInfo($payload).FileVersion)
  $actualVersion=([string]$actualVersion).TrimEnd('0').TrimEnd('.')
  if([string]::IsNullOrWhiteSpace($actualVersion)){$actualVersion='0'}
  if($actualVersion-ne[string]$row[0].version){throw "BLOCK_DISTRIBUTION: $id compiled version $actualVersion differs from manifest $($row[0].version)"}
}
if(-not(Test-Path -LiteralPath (Join-Path $unifiedShell 'setup-windows-components-core-v5.ps1'))){throw 'BLOCK_DISTRIBUTION: independent Recovery protection missing from unified shell.'}

& (Join-Path $RepoRoot 'packaging\windows\scripts\build-installer-v4.ps1') -RepoRoot $RepoRoot -ShellPayloadRoot $unifiedShell -IsccExe $IsccExe
if($LASTEXITCODE-ne0){throw 'BLOCK_DISTRIBUTION: full V4 bootstrap build failed.'}
$v4Build=Get-Content -LiteralPath (Join-Path $RepoRoot 'packaging\windows\installer\out-v4\INSTALLER_BUILD.json') -Raw|ConvertFrom-Json
if([string]$v4Build.package_version-ne$version-or[string]$v4Build.source_git_sha-ne$sha){throw 'BLOCK_DISTRIBUTION: V4 installer package source identity mismatch.'}
$v4Setup=Join-Path $RepoRoot ('packaging\windows\installer\out-v4\'+[string]$v4Build.distributable)
if(-not(Test-Path -LiteralPath $v4Setup -PathType Leaf)){throw 'BLOCK_DISTRIBUTION: V4 installer missing.'}
Copy-Item -LiteralPath $v4Setup -Destination (Join-Path $OutputRoot ([IO.Path]::GetFileName($v4Setup))) -Force
$dist=Join-Path $OutputRoot ([IO.Path]::GetFileName($v4Setup))
if((Get-FileHash -LiteralPath $dist -Algorithm SHA256).Hash.ToLowerInvariant()-ne[string]$v4Build.distributable_sha256){throw 'BLOCK_DISTRIBUTION: V4 bootstrap installer fingerprint mismatch.'}
# Fail closed: do not leave the legacy installer under the distributable wildcard.
if($setup.Name-ne[IO.Path]::GetFileName($v4Setup)){
  Remove-Item -LiteralPath (Join-Path $OutputRoot $setup.Name) -Force
}
[ordered]@{
  format='sokna-windows-release-index-v2';schema_version=2;source_commit=$sha;
  package_version=$version;source_shell='sokna-windows-services-shell-v3';
  component_count=5;installer_kind='unified-v4-bootstrap';
  runtime=(Get-Content (Join-Path $runtimeOut 'runtime-artifact.json') -Raw|ConvertFrom-Json);
  print_agent=(Get-Content (Join-Path $printOut 'print-agent-artifact.json') -Raw|ConvertFrom-Json);
  windows_services=[ordered]@{file=[IO.Path]::GetFileName($dist);sha256=(Get-FileHash $dist -Algorithm SHA256).Hash.ToLowerInvariant()};
  component_manifest_sha256=(Get-FileHash (Join-Path $unifiedShell 'windows-services-component-manifest-v2.json') -Algorithm SHA256).Hash.ToLowerInvariant();
  shell_manifest_sha256=(Get-FileHash (Join-Path $unifiedShell 'payload-manifest.json') -Algorithm SHA256).Hash.ToLowerInvariant()
}|ConvertTo-Json -Depth 8|Set-Content (Join-Path $OutputRoot 'artifact-index.json') -Encoding UTF8

Write-Host 'SOKNA Windows release artifacts: PASS'
