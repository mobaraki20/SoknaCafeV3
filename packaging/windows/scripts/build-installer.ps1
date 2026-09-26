param(
    [Parameter(Mandatory=$true)][string]$ShellPayloadRoot,
    [string]$RepoRoot=(Resolve-Path (Join-Path $PSScriptRoot '..\..\..')).Path,
    [string]$Configuration='Release',
    [switch]$AcceptWix7Eula
)
$ErrorActionPreference='Stop'
$version=(Get-Content (Join-Path $RepoRoot 'VERSION.txt') -Raw).Trim()
if($version -notmatch '^(\d+)\.(\d+)\.(\d+)(?:-dev\.(\d+))?$'){throw "Unsupported SOKNA version for MSI mapping: $version"}
$major=[int]$matches[1];$minor=[int]$matches[2];$patch=[int]$matches[3];$dev=if($matches[4]){[int]$matches[4]}else{0}
$build=$patch*1000+$dev
if($major -gt 255 -or $minor -gt 255 -or $build -gt 65535){throw 'Version cannot be represented safely as Windows Installer ProductVersion.'}
$msiVersion="$major.$minor.$build"
$wix=Join-Path $RepoRoot 'installer\windows\wix'
$msiProject=Join-Path $wix 'SoknaShell.wixproj'
$bundleProject=Join-Path $wix 'SoknaBundle.wixproj'

# WiX v7 requires explicit wix7 EULA acceptance by the build/release environment.
# This repository intentionally does not set AcceptEula automatically.
$eulaArgs=@()
if($AcceptWix7Eula){$eulaArgs += '-p:AcceptEula=wix7'}
& dotnet build $msiProject -c $Configuration -p:ProductVersion=$msiVersion -p:AppVersion=$version -p:ShellPayloadRoot=$ShellPayloadRoot @eulaArgs
if($LASTEXITCODE -ne 0){throw 'SOKNA MSI build failed.'}
$msi=(Get-ChildItem (Join-Path $wix "bin\$Configuration") -Filter 'SOKNA-Cafe-Local.msi' -Recurse|Select-Object -First 1).FullName
if(-not $msi){throw 'Built MSI was not found.'}
& dotnet build $bundleProject -c $Configuration -p:ProductVersion=$msiVersion -p:MsiPath=$msi @eulaArgs
if($LASTEXITCODE -ne 0){throw 'SOKNA Burn bundle build failed.'}
$bundle=(Get-ChildItem (Join-Path $wix "bin\$Configuration") -Filter 'SOKNA-Cafe-Setup.exe' -Recurse|Select-Object -First 1).FullName
if(-not $bundle){throw 'Built Setup.exe was not found.'}
$hashes=@($msi,$bundle)|ForEach-Object{[ordered]@{file=[IO.Path]::GetFileName($_);sha256=(Get-FileHash $_ -Algorithm SHA256).Hash.ToLowerInvariant()}}
$hashes|ConvertTo-Json|Set-Content (Join-Path ([IO.Path]::GetDirectoryName($bundle)) 'SHA256SUMS.json') -Encoding UTF8
Write-Host "SOKNA Windows package build complete: $bundle"
