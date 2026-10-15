param(
  [Parameter(Mandatory=$true)][string]$RepoRoot,
  [Parameter(Mandatory=$true)][string]$OutputRoot,
  [Parameter(Mandatory=$true)][string]$RuntimeServiceExe,
  [Parameter(Mandatory=$true)][string]$SetupHostExe,
  [Parameter(Mandatory=$true)][string]$SetupUiExe,
  [Parameter(Mandatory=$true)][string]$PrintWorkerBundle,
  [Parameter(Mandatory=$true)][string]$MaintenanceAppBundle,
  [Parameter(Mandatory=$true)][string]$MaintenanceRecoveryBundle,
  [string]$GitSha=''
)
$ErrorActionPreference='Stop'
function Full([string]$p){[IO.Path]::GetFullPath($p).TrimEnd('\')}
$RepoRoot=Full $RepoRoot;$OutputRoot=Full $OutputRoot
foreach($p in @($RuntimeServiceExe,$SetupHostExe,$SetupUiExe)){if(-not(Test-Path -LiteralPath $p -PathType Leaf)){throw "Required executable missing: $p"}}
foreach($p in @($PrintWorkerBundle,$MaintenanceAppBundle,$MaintenanceRecoveryBundle)){if(-not(Test-Path -LiteralPath $p -PathType Container)){throw "Required bundle missing: $p"}}
if(Test-Path $OutputRoot){Remove-Item $OutputRoot -Recurse -Force};New-Item -ItemType Directory -Path $OutputRoot -Force|Out-Null
if(-not$GitSha){$GitSha=(& git -C $RepoRoot rev-parse HEAD).Trim()}
$package=(Get-Content (Join-Path $RepoRoot 'packaging\windows\WINDOWS_SERVICES_VERSION.txt') -Raw).Trim()
$manifestPath=Join-Path $RepoRoot 'packaging\windows\windows-services-component-manifest-v2.json';$manifest=Get-Content $manifestPath -Raw|ConvertFrom-Json
if([string]$manifest.format-ne'sokna-windows-component-manifest-v2'-or[int]$manifest.schema_version-ne2){throw 'Component manifest v2 invalid.'}
if([string]$manifest.package_version-ne$package){throw 'Package version and component manifest disagree.'}

$owned=@{
 'platform\windows\prerequisites.json'='prerequisites.json';
 'platform\windows\release-lock.json'='release-lock.json';
 'packaging\windows\windows-services-component-manifest-v2.json'='windows-services-component-manifest-v2.json';
 'packaging\windows\WINDOWS_SERVICES_VERSION.txt'='WINDOWS_SERVICES_VERSION.txt';
 'packaging\windows\scripts\setup-windows-components-v4.ps1'='setup-windows-components-v4.ps1';
 'packaging\windows\scripts\pair-windows-services.ps1'='pair-windows-services.ps1';
 'packaging\windows\scripts\collect-support.ps1'='collect-support-core.ps1';
 'packaging\windows\scripts\collect-support-v2.ps1'='collect-support-v2.ps1';
 'packaging\windows\Sokna.ico'='Sokna.ico'
}
foreach($rel in $owned.Keys){$src=Join-Path $RepoRoot $rel;if(-not(Test-Path $src -PathType Leaf)){throw "Installer-owned source missing: $rel"};Copy-Item $src (Join-Path $OutputRoot $owned[$rel]) -Force}
$payload=Join-Path $OutputRoot '_payload';New-Item -ItemType Directory -Path (Join-Path $payload 'runtime'),(Join-Path $payload 'maintenance\app'),(Join-Path $payload 'maintenance\recovery') -Force|Out-Null
Copy-Item $RuntimeServiceExe (Join-Path $payload 'runtime\SoknaRuntimeService.exe') -Force
Copy-Item $SetupHostExe (Join-Path $OutputRoot 'SoknaSetupHost.exe') -Force
Copy-Item $SetupUiExe (Join-Path $OutputRoot 'SoknaSetupUi.exe') -Force
Copy-Item $PrintWorkerBundle (Join-Path $payload 'print-worker') -Recurse -Force
Copy-Item (Join-Path $MaintenanceAppBundle '*') (Join-Path $payload 'maintenance\app') -Recurse -Force
Copy-Item (Join-Path $MaintenanceRecoveryBundle '*') (Join-Path $payload 'maintenance\recovery') -Recurse -Force

$required=@('_payload\runtime\SoknaRuntimeService.exe','_payload\print-worker\Service\Sokna.PrintAgent.Service.exe','_payload\print-worker\Worker\Sokna.PrintAgent.Worker.exe','_payload\maintenance\app\SoknaMaintenanceSupervisor.exe','_payload\maintenance\app\SoknaMaintenanceUpdateEngine.exe','_payload\maintenance\recovery\SoknaMaintenanceRecoveryConsole.exe','SoknaSetupHost.exe','SoknaSetupUi.exe','collect-support-core.ps1','collect-support-v2.ps1')
foreach($rel in $required){if(-not(Test-Path (Join-Path $OutputRoot $rel) -PathType Leaf)){throw "Unified shell missing: $rel"}}
$forbidden=@('php.exe','httpd.exe','apache.exe','mysqld.exe','mariadb.exe','SoknaAppPayload.zip');foreach($f in Get-ChildItem $OutputRoot -File -Recurse){if($forbidden -contains $f.Name){throw "Forbidden payload leaked into Windows Services package: $($f.Name)"}}

$runtime=[string](([xml](Get-Content (Join-Path $RepoRoot 'windows\runtime\source\Sokna.Runtime.Service.csproj') -Raw)).Project.PropertyGroup.Version)
$print=[string](([xml](Get-Content (Join-Path $RepoRoot 'windows\print-agent\source\Directory.Build.props') -Raw)).Project.PropertyGroup.SoknaAgentVersion)
$super=[string](([xml](Get-Content (Join-Path $RepoRoot 'windows\maintenance\src\Sokna.Maintenance.Supervisor\Sokna.Maintenance.Supervisor.csproj') -Raw)).Project.PropertyGroup.Version)
$engine=[string](([xml](Get-Content (Join-Path $RepoRoot 'windows\maintenance\src\Sokna.Maintenance.UpdateEngine\Sokna.Maintenance.UpdateEngine.csproj') -Raw)).Project.PropertyGroup.Version)
$recovery=[string](([xml](Get-Content (Join-Path $RepoRoot 'windows\maintenance\src\Sokna.Maintenance.RecoveryConsole\Sokna.Maintenance.RecoveryConsole.csproj') -Raw)).Project.PropertyGroup.Version)
$expected=@{'runtime'=$runtime;'print-agent'=$print;'maintenance'=$super;'maintenance-update-engine'=$engine;'maintenance-recovery-console'=$recovery}
foreach($c in $manifest.components){if($expected.ContainsKey([string]$c.component_id)-and[string]$c.version-ne$expected[[string]$c.component_id]){throw "Version source mismatch: $($c.component_id) manifest=$($c.version) source=$($expected[[string]$c.component_id])"}}

$files=@();Get-ChildItem $OutputRoot -File -Recurse|Sort-Object FullName|ForEach-Object{$files += [ordered]@{path=$_.FullName.Substring($OutputRoot.Length+1).Replace('\','/');size=[long]$_.Length;sha256=(Get-FileHash $_.FullName -Algorithm SHA256).Hash.ToLowerInvariant()}}
$components=@();foreach($c in $manifest.components){$primary=Join-Path $OutputRoot (([string]$c.payload_primary).Replace('/','\'));$components += [ordered]@{component_id=[string]$c.component_id;version=[string]$c.version;primary_path=[string]$c.payload_primary;primary_sha256=(Get-FileHash $primary -Algorithm SHA256).Hash.ToLowerInvariant();transaction_group=[string]$c.transaction_group}}
[ordered]@{format='sokna-windows-services-shell-v3';schema_version=3;package_version=$package;source_git_sha=$GitSha;ownership='windows-services-packaging';components=$components;files=$files;activation=$false;database_mutation=$false;business_data_mutated=$false}|ConvertTo-Json -Depth 12|Set-Content (Join-Path $OutputRoot 'payload-manifest.json') -Encoding UTF8
Write-Host "Prepared unified SOKNA Windows Services $package: Runtime $runtime / Print $print / Maintenance $super"
