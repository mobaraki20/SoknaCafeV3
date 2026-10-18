param(
  [Parameter(Mandatory=$true)][string]$ShellPayloadRoot,
  [string]$RepoRoot=(Resolve-Path (Join-Path $PSScriptRoot '..\..\..')).Path,
  [string]$IsccExe=''
)
$ErrorActionPreference='Stop'
$RepoRoot=[IO.Path]::GetFullPath($RepoRoot).TrimEnd('\')
$ShellPayloadRoot=[IO.Path]::GetFullPath($ShellPayloadRoot).TrimEnd('\')
$version=(Get-Content (Join-Path $RepoRoot 'packaging\windows\WINDOWS_SERVICES_VERSION.txt') -Raw).Trim()
if($version-notmatch'^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$'){throw "Unsupported Windows Services package version: $version"}
$manifestPath=Join-Path $ShellPayloadRoot 'payload-manifest.json'
if(-not(Test-Path $manifestPath -PathType Leaf)){throw 'Unified shell payload manifest is missing.'}
$manifest=Get-Content $manifestPath -Raw|ConvertFrom-Json
if([string]$manifest.format-ne'sokna-windows-services-shell-v3'-or[int]$manifest.schema_version-ne3-or[string]$manifest.package_version-ne$version){throw 'Unified shell payload format/version mismatch.'}
foreach($required in @('SoknaSetupUi.exe','SoknaSetupHost.exe','windows-services-component-manifest-v2.json','setup-windows-components-v4.ps1','_payload\runtime\SoknaRuntimeService.exe','_payload\print-worker\Service\Sokna.PrintAgent.Service.exe','_payload\maintenance\app\SoknaMaintenanceSupervisor.exe')){if(-not(Test-Path (Join-Path $ShellPayloadRoot $required) -PathType Leaf)){throw "Unified shell file missing: $required"}}

if(-not$IsccExe){
  $candidates=@("${env:ProgramFiles(x86)}\Inno Setup 6\ISCC.exe","$env:ProgramFiles\Inno Setup 6\ISCC.exe")
  $IsccExe=$candidates|Where-Object{$_-and(Test-Path $_ -PathType Leaf)}|Select-Object -First 1
}
if(-not$IsccExe-or-not(Test-Path $IsccExe -PathType Leaf)){throw 'Inno Setup 6 ISCC.exe was not found.'}

$out=Join-Path $RepoRoot 'packaging\windows\installer\out-v4'
$coreOut=Join-Path $out 'core'
$bootstrapOut=Join-Path $out 'bootstrap'
if(Test-Path $out){Remove-Item $out -Recurse -Force}
New-Item -ItemType Directory -Path $coreOut,$bootstrapOut -Force|Out-Null
$iss=Join-Path $RepoRoot 'packaging\windows\installer\SOKNA-v4.iss'
& $IsccExe "/DSourceRoot=$ShellPayloadRoot" "/DProductVersion=$version" "/O$coreOut" $iss
if($LASTEXITCODE-ne0){throw 'Elevated Windows Services installer build failed.'}
$core=Get-ChildItem $coreOut -Filter "SOKNA-Windows-Services-Core-$version.exe" -File|Select-Object -First 1
if(-not$core){throw 'Elevated core installer executable was not produced.'}
$corePath=$core.FullName
$project=Join-Path $RepoRoot 'packaging\windows\installer-bootstrap\Sokna.InstallerBootstrap.csproj'
& dotnet publish $project -c Release -r win-x64 --self-contained true -p:PublishSingleFile=true -p:EnableCompressionInSingleFile=true -p:DebugType=None -p:DebugSymbols=false "-p:CoreInstallerPath=$corePath" -o $bootstrapOut
if($LASTEXITCODE-ne0){throw 'Pre-elevation installer bootstrap publish failed.'}
$raw=Join-Path $bootstrapOut 'SOKNA-Windows-Services-Setup.exe'
if(-not(Test-Path $raw -PathType Leaf)){throw 'Pre-elevation setup executable was not produced.'}
$final=Join-Path $out "SOKNA-Windows-Services-Setup-$version.exe"
Move-Item $raw $final -Force
$sha=(Get-FileHash $final -Algorithm SHA256).Hash.ToLowerInvariant()
$coreSha=(Get-FileHash $corePath -Algorithm SHA256).Hash.ToLowerInvariant()
$shellBytes=[long](Get-ChildItem $ShellPayloadRoot -Recurse -File|Measure-Object Length -Sum).Sum
[ordered]@{
  format='sokna-windows-services-installer-v4-build';
  package_version=$version;
  distributable=[IO.Path]::GetFileName($final);
  distributable_sha256=$sha;
  distributable_size_bytes=[long](Get-Item $final).Length;
  bootstrap_pre_elevation_logging=$true;
  bootstrap_single_file_compression=$true;
  embedded_core=[IO.Path]::GetFileName($corePath);
  embedded_core_sha256=$coreSha;
  embedded_core_size_bytes=[long](Get-Item $corePath).Length;
  shell_payload_size_bytes=$shellBytes;
  shell_manifest_sha256=(Get-FileHash $manifestPath -Algorithm SHA256).Hash.ToLowerInvariant();
  source_git_sha=$(try{(& git -C $RepoRoot rev-parse HEAD).Trim()}catch{'unknown'});
  generated_at_utc=[DateTime]::UtcNow.ToString('o')
}|ConvertTo-Json -Depth 5|Set-Content (Join-Path $out 'INSTALLER_BUILD.json') -Encoding UTF8
Write-Host "SOKNA Windows Services installer v4 complete: $final"
Write-Host "SHA256=$sha"
Write-Host "SIZE_BYTES=$((Get-Item $final).Length)"
