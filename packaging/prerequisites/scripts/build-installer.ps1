param(
  [string]$RepoRoot=(Resolve-Path (Join-Path $PSScriptRoot '..\..\..')).Path,
  [string]$OutputRoot='',
  [string]$IsccExe=''
)
$ErrorActionPreference='Stop'
$version=(Get-Content (Join-Path $RepoRoot 'packaging\prerequisites\VERSION.txt') -Raw).Trim()
if($version -notmatch '^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$'){throw "Unsupported prerequisite setup version: $version"}
if(-not $OutputRoot){$OutputRoot=Join-Path $RepoRoot 'packaging\prerequisites\out'}
$publish=Join-Path $OutputRoot 'publish'
$installerOut=Join-Path $OutputRoot 'installer'
if(Test-Path $OutputRoot){Remove-Item $OutputRoot -Recurse -Force}
New-Item -ItemType Directory -Path $publish,$installerOut -Force|Out-Null

dotnet publish (Join-Path $RepoRoot 'packaging\prerequisites\setup-ui\Sokna.Prerequisites.Setup.csproj') -c Release -o $publish
if($LASTEXITCODE -ne 0){throw 'Prerequisites Setup UI publish failed.'}

if(-not $IsccExe){
  $candidates=@("${env:ProgramFiles(x86)}\Inno Setup 6\ISCC.exe","$env:ProgramFiles\Inno Setup 6\ISCC.exe")
  $IsccExe=$candidates|Where-Object{$_ -and (Test-Path -LiteralPath $_ -PathType Leaf)}|Select-Object -First 1
}
if(-not $IsccExe){throw 'Inno Setup 6 ISCC.exe was not found.'}
$iss=Join-Path $RepoRoot 'packaging\prerequisites\installer\SOKNA-Prerequisites.iss'
& $IsccExe "/DSourceRoot=$publish" "/DProductVersion=$version" "/O$installerOut" $iss
if($LASTEXITCODE -ne 0){throw 'Prerequisites installer build failed.'}
$setup=Get-ChildItem $installerOut -Filter "SOKNA-Prerequisites-Setup-$version.exe" -File|Select-Object -First 1
if(-not $setup){throw 'Prerequisites Setup.exe was not produced.'}
[ordered]@{
 format='sokna-prerequisites-artifact-v1'
 schema_version=1
 version=$version
 file=$setup.Name
 sha256=(Get-FileHash $setup.FullName -Algorithm SHA256).Hash.ToLowerInvariant()
}|ConvertTo-Json|Set-Content (Join-Path $installerOut 'artifact-index.json') -Encoding UTF8
Write-Host "SOKNA Prerequisites installer build complete: $($setup.FullName)"
