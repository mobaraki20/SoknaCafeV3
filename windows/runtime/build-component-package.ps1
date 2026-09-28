param(
  [string]$RepoRoot=(Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path,
  [string]$OutputDir=(Join-Path $PSScriptRoot 'out'),
  [string]$Configuration='Release'
)
$ErrorActionPreference='Stop'
$project=Join-Path $RepoRoot 'windows\runtime\source\Sokna.Runtime.Service.csproj'
[xml]$xml=Get-Content -LiteralPath $project -Raw
$version=[string]$xml.Project.PropertyGroup.Version
if($version -notmatch '^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$'){throw 'Runtime component version is invalid.'}
$temp=Join-Path ([IO.Path]::GetTempPath()) ('sokna-runtime-'+[guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $OutputDir -Force|Out-Null
try{
  & dotnet publish $project -c $Configuration -r win-x64 --self-contained true -p:PublishSingleFile=true -p:DebugType=None -p:DebugSymbols=false -o $temp
  if($LASTEXITCODE -ne 0){throw 'Runtime publish failed.'}
  $exe=Join-Path $temp 'SoknaRuntimeService.exe'; if(-not(Test-Path -LiteralPath $exe)){throw 'Runtime executable missing.'}
  $zip=Join-Path $OutputDir ("SoknaRuntime-$version-win-x64.zip")
  if(Test-Path $zip){Remove-Item $zip -Force}
  Compress-Archive -Path $exe -DestinationPath $zip -CompressionLevel Optimal
  [ordered]@{format='sokna-component-artifact-v1';component='windows-runtime';version=$version;file=[IO.Path]::GetFileName($zip);sha256=(Get-FileHash $zip -Algorithm SHA256).Hash.ToLowerInvariant()}|ConvertTo-Json|Set-Content (Join-Path $OutputDir 'runtime-artifact.json') -Encoding UTF8
  Write-Host "Runtime artifact: $zip"
}finally{Remove-Item $temp -Recurse -Force -ErrorAction SilentlyContinue}
