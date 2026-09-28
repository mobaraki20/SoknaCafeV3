param(
    [Parameter(Mandatory=$true)][string]$RepoRoot,
    [Parameter(Mandatory=$true)][string]$OutputRoot,
    [Parameter(Mandatory=$true)][string]$RuntimeServiceExe,
    [Parameter(Mandatory=$true)][string]$SetupHostExe,
    [Parameter(Mandatory=$true)][string]$SetupUiExe,
    [Parameter(Mandatory=$true)][string]$PrintWorkerBundle,
    [string]$GitSha=''
)
$ErrorActionPreference='Stop'
$RepoRoot=[IO.Path]::GetFullPath($RepoRoot).TrimEnd('\')
$OutputRoot=[IO.Path]::GetFullPath($OutputRoot).TrimEnd('\')
foreach($p in @($RuntimeServiceExe,$SetupHostExe,$SetupUiExe)){
    if(-not(Test-Path -LiteralPath $p -PathType Leaf)){throw "Required built executable is missing: $p"}
}
if(-not(Test-Path -LiteralPath $PrintWorkerBundle -PathType Container)){throw 'Print Agent bundle root is missing.'}
if(Test-Path -LiteralPath $OutputRoot){Remove-Item -LiteralPath $OutputRoot -Recurse -Force}
New-Item -ItemType Directory -Path $OutputRoot -Force|Out-Null
if(-not$GitSha){try{$GitSha=(& git -C $RepoRoot rev-parse HEAD 2>$null).Trim()}catch{$GitSha='unknown'}}

$packageVersion=(Get-Content -LiteralPath (Join-Path $RepoRoot 'packaging\windows\WINDOWS_SERVICES_VERSION.txt') -Raw).Trim()
$compatPath=Join-Path $RepoRoot 'packaging\windows\windows-services-compatibility-v1.json'
$compat=Get-Content -LiteralPath $compatPath -Raw|ConvertFrom-Json
if([string]$compat.format-ne'sokna-windows-services-compatibility-v1'-or[int]$compat.schema_version-ne1){throw 'Windows Services compatibility manifest is invalid.'}
$runtimeProject=[xml](Get-Content -LiteralPath (Join-Path $RepoRoot 'windows\runtime\source\Sokna.Runtime.Service.csproj') -Raw)
$runtimeVersion=[string]$runtimeProject.Project.PropertyGroup.Version
$printProps=[xml](Get-Content -LiteralPath (Join-Path $RepoRoot 'windows\print-agent\source\Directory.Build.props') -Raw)
$printVersion=[string]$printProps.Project.PropertyGroup.SoknaAgentVersion
if([string]$compat.components.runtime.version-ne$runtimeVersion){throw 'Runtime version source does not match Windows Services compatibility manifest.'}
if([string]$compat.components.'print-agent'.version-ne$printVersion){throw 'Print Agent version source does not match Windows Services compatibility manifest.'}
if($packageVersion -notmatch '^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$'){throw 'Windows Services package version is invalid.'}

$owned=@{
 'platform\windows\prerequisites.json'='prerequisites.json';
 'platform\windows\release-lock.json'='release-lock.json';
 'packaging\windows\windows-services-compatibility-v1.json'='windows-services-compatibility-v1.json';
 'packaging\windows\WINDOWS_SERVICES_VERSION.txt'='WINDOWS_SERVICES_VERSION.txt';
 'packaging\windows\scripts\setup-windows-services.ps1'='setup-windows-services.ps1';
 'packaging\windows\scripts\remove-windows-services.ps1'='remove-windows-services.ps1';
 'packaging\windows\Sokna.ico'='Sokna.ico'
}
foreach($rel in $owned.Keys){$src=Join-Path $RepoRoot $rel;if(-not(Test-Path -LiteralPath $src -PathType Leaf)){throw "Missing installer-owned source: $rel"};Copy-Item -LiteralPath $src -Destination (Join-Path $OutputRoot $owned[$rel]) -Force}
Copy-Item -LiteralPath $RuntimeServiceExe -Destination (Join-Path $OutputRoot 'SoknaRuntimeService.exe') -Force
Copy-Item -LiteralPath $SetupHostExe -Destination (Join-Path $OutputRoot 'SoknaSetupHost.exe') -Force
Copy-Item -LiteralPath $SetupUiExe -Destination (Join-Path $OutputRoot 'SoknaSetupUi.exe') -Force
Copy-Item -LiteralPath $PrintWorkerBundle -Destination (Join-Path $OutputRoot 'print-worker') -Recurse -Force
foreach($rel in @('component-manifest.json','Service\Sokna.PrintAgent.Service.exe','Worker\Sokna.PrintAgent.Worker.exe')){if(-not(Test-Path -LiteralPath (Join-Path $OutputRoot ('print-worker\'+$rel)) -PathType Leaf)){throw "Print Agent bundle is incomplete: $rel"}}

# A41 ownership fence: the Windows Services package must never contain Local/Public or shared infrastructure payloads.
$forbiddenLeaf=@('php.exe','httpd.exe','apache.exe','mysqld.exe','mariadb.exe','SoknaAppPayload.zip')
foreach($f in Get-ChildItem -LiteralPath $OutputRoot -File -Recurse){if($forbiddenLeaf -contains $f.Name){throw "Forbidden external/application payload leaked into Windows Services package: $($f.Name)"}}

$files=@()
Get-ChildItem -LiteralPath $OutputRoot -File -Recurse|Sort-Object FullName|ForEach-Object{
    $files += [ordered]@{path=$_.FullName.Substring($OutputRoot.Length+1).Replace('\','/');size=[long]$_.Length;sha256=(Get-FileHash -LiteralPath $_.FullName -Algorithm SHA256).Hash.ToLowerInvariant()}
}
$manifest=[ordered]@{
    format='sokna-windows-services-shell-v2';schema_version=2;package_version=$packageVersion;source_git_sha=$GitSha;ownership='windows-services-packaging';
    components=[ordered]@{
      runtime=[ordered]@{version=$runtimeVersion;contracts=[ordered]@{runtime_contract=[string]$compat.components.runtime.runtime_contract}};
      'print-agent'=[ordered]@{version=$printVersion;contracts=[ordered]@{print_server_protocol=[int]$compat.components.'print-agent'.print_server_protocol;loopback_protocol=[int]$compat.components.'print-agent'.loopback_protocol}}
    };
    external_infrastructure=[ordered]@{owner='external';installer_ownership=$false;prerequisites_sha256=(Get-FileHash -LiteralPath (Join-Path $OutputRoot 'prerequisites.json') -Algorithm SHA256).Hash.ToLowerInvariant();release_lock_sha256=(Get-FileHash -LiteralPath (Join-Path $OutputRoot 'release-lock.json') -Algorithm SHA256).Hash.ToLowerInvariant()};
    files=$files
}
$manifest|ConvertTo-Json -Depth 10|Set-Content -LiteralPath (Join-Path $OutputRoot 'payload-manifest.json') -Encoding UTF8
Write-Host "Prepared SOKNA Windows Services shell $packageVersion (Runtime $runtimeVersion / Print Agent $printVersion) at $OutputRoot"
