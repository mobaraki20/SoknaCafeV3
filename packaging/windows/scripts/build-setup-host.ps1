param(
    [string]$RepoRoot=(Resolve-Path (Join-Path $PSScriptRoot '..\..\..')).Path,
    [Parameter(Mandatory=$true)][string]$OutputPath,
    [string]$Configuration='Release'
)
$ErrorActionPreference='Stop'
$project=Join-Path $RepoRoot 'installer\windows\setup-host\Sokna.SetupHost.csproj'
$out=[IO.Path]::GetFullPath($OutputPath)
$dir=[IO.Path]::GetDirectoryName($out)
New-Item -ItemType Directory -Path $dir -Force|Out-Null
$temp=Join-Path ([IO.Path]::GetTempPath()) ('sokna-setup-host-'+[guid]::NewGuid().ToString('N'))
try{
    & dotnet publish $project -c $Configuration -r win-x64 --self-contained true -p:PublishSingleFile=true -p:DebugType=None -p:DebugSymbols=false -o $temp
    if($LASTEXITCODE -ne 0){throw 'SOKNA Setup Host build failed.'}
    $built=Join-Path $temp 'SoknaSetupHost.exe'
    if(-not(Test-Path -LiteralPath $built -PathType Leaf)){throw 'SOKNA Setup Host executable was not produced.'}
    Copy-Item -LiteralPath $built -Destination $out -Force
}finally{Remove-Item -LiteralPath $temp -Recurse -Force -ErrorAction SilentlyContinue}
Write-Host "Built SOKNA Setup Host: $out"
