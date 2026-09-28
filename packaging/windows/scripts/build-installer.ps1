param(
    [Parameter(Mandatory=$true)][string]$ShellPayloadRoot,
    [string]$RepoRoot=(Resolve-Path (Join-Path $PSScriptRoot '..\..\..')).Path,
    [string]$IsccExe=''
)
$ErrorActionPreference='Stop'
$version=(Get-Content (Join-Path $RepoRoot 'packaging\windows\WINDOWS_SERVICES_VERSION.txt') -Raw).Trim()
if($version -notmatch '^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$'){throw "Unsupported Windows Services package version: $version"}
if(-not $IsccExe){
    $candidates=@("${env:ProgramFiles(x86)}\Inno Setup 6\ISCC.exe","$env:ProgramFiles\Inno Setup 6\ISCC.exe")
    $IsccExe=$candidates|Where-Object{$_ -and (Test-Path -LiteralPath $_ -PathType Leaf)}|Select-Object -First 1
}
if(-not $IsccExe -or -not(Test-Path -LiteralPath $IsccExe -PathType Leaf)){throw 'Inno Setup 6 ISCC.exe was not found.'}
$iss=Join-Path $RepoRoot 'packaging\windows\installer\SOKNA.iss'
if(-not(Test-Path -LiteralPath $iss -PathType Leaf)){throw 'SOKNA Windows Services Inno source is missing.'}
$manifest=Join-Path $ShellPayloadRoot 'payload-manifest.json'
if(-not(Test-Path -LiteralPath $manifest -PathType Leaf)){throw 'Windows Services shell payload manifest is missing.'}
$m=Get-Content -LiteralPath $manifest -Raw|ConvertFrom-Json
if([string]$m.format-ne'sokna-windows-services-shell-v2'-or[int]$m.schema_version-ne2-or[string]$m.package_version-ne$version){throw 'Windows Services shell payload version/format mismatch.'}
$out=Join-Path $RepoRoot 'packaging\windows\installer\out'
New-Item -ItemType Directory -Path $out -Force|Out-Null
& $IsccExe "/DSourceRoot=$ShellPayloadRoot" "/DProductVersion=$version" "/O$out" $iss
if($LASTEXITCODE -ne 0){throw 'SOKNA Windows Services installer build failed.'}
$setup=Get-ChildItem $out -Filter "SOKNA-Windows-Services-Setup-$version.exe" -File|Select-Object -First 1
if(-not $setup){throw 'Built Windows Services Setup.exe was not found.'}
[ordered]@{file=$setup.Name;sha256=(Get-FileHash $setup.FullName -Algorithm SHA256).Hash.ToLowerInvariant();package_component='windows-services-packaging';version=$version}|ConvertTo-Json|Set-Content (Join-Path $out 'SHA256SUMS.json') -Encoding UTF8
Write-Host "SOKNA Windows Services installer build complete: $($setup.FullName)"
