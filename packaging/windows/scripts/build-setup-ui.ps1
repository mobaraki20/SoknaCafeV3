param(
    [string]$RepoRoot=(Resolve-Path (Join-Path $PSScriptRoot '..\..\..')).Path,
    [Parameter(Mandatory=$true)][string]$OutputPath,
    [string]$Configuration='Release'
)
$ErrorActionPreference='Stop'
$project=Join-Path $RepoRoot 'installer\windows\setup-ui\Sokna.SetupUi.csproj'
$out=[IO.Path]::GetFullPath($OutputPath)
$dir=[IO.Path]::GetDirectoryName($out)
New-Item -ItemType Directory -Path $dir -Force|Out-Null
$temp=Join-Path ([IO.Path]::GetTempPath()) ('sokna-setup-ui-'+[guid]::NewGuid().ToString('N'))
try{
    & dotnet publish $project -c $Configuration -r win-x64 --self-contained true -p:PublishSingleFile=true -p:DebugType=None -p:DebugSymbols=false -o $temp
    if($LASTEXITCODE -ne 0){throw 'SOKNA Setup UI build failed.'}
    $built=Join-Path $temp 'SoknaSetupUi.exe'
    if(-not(Test-Path -LiteralPath $built -PathType Leaf)){throw 'SOKNA Setup UI executable was not produced.'}
    Copy-Item -LiteralPath $built -Destination $out -Force
}finally{Remove-Item -LiteralPath $temp -Recurse -Force -ErrorAction SilentlyContinue}
Write-Host "Built SOKNA Setup UI: $out"
