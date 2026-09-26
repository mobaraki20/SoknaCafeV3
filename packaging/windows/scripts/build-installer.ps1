param(
    [Parameter(Mandatory=$true)][string]$ShellPayloadRoot,
    [string]$RepoRoot=(Resolve-Path (Join-Path $PSScriptRoot '..\..\..')).Path,
    [string]$IsccExe=''
)
$ErrorActionPreference='Stop'
$version=(Get-Content (Join-Path $RepoRoot 'VERSION.txt') -Raw).Trim()
if($version -notmatch '^\d+\.\d+\.\d+(?:-(?:rc|dev)\.\d+)?$'){throw "Unsupported SOKNA version: $version"}
if(-not $IsccExe){
    $candidates=@("${env:ProgramFiles(x86)}\Inno Setup 6\ISCC.exe","$env:ProgramFiles\Inno Setup 6\ISCC.exe")
    $IsccExe=$candidates|Where-Object{$_ -and (Test-Path -LiteralPath $_ -PathType Leaf)}|Select-Object -First 1
}
if(-not $IsccExe -or -not(Test-Path -LiteralPath $IsccExe -PathType Leaf)){throw 'Inno Setup 6 ISCC.exe was not found.'}
$iss=Join-Path $RepoRoot 'packaging\windows\installer\SOKNA.iss'
if(-not(Test-Path -LiteralPath $iss -PathType Leaf)){throw 'SOKNA Inno installer source is missing.'}
$out=Join-Path $RepoRoot 'packaging\windows\installer\out'
New-Item -ItemType Directory -Path $out -Force|Out-Null
& $IsccExe "/DSourceRoot=$ShellPayloadRoot" "/DProductVersion=$version" "/O$out" $iss
if($LASTEXITCODE -ne 0){throw 'SOKNA Setup.exe build failed.'}
$setup=Get-ChildItem $out -Filter "SOKNA-Setup-$version.exe" -File|Select-Object -First 1
if(-not $setup){throw 'Built SOKNA Setup.exe was not found.'}
[ordered]@{file=$setup.Name;sha256=(Get-FileHash $setup.FullName -Algorithm SHA256).Hash.ToLowerInvariant();version=$version}|ConvertTo-Json|Set-Content (Join-Path $out 'SHA256SUMS.json') -Encoding UTF8
Write-Host "SOKNA Windows installer build complete: $($setup.FullName)"
