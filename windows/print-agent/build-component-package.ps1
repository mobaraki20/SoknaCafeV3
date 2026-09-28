param(
  [string]$RepoRoot=(Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path,
  [string]$OutputDir=(Join-Path $PSScriptRoot 'out'),
  [string]$Configuration='Release'
)
$ErrorActionPreference='Stop'
[xml]$props=Get-Content -LiteralPath (Join-Path $RepoRoot 'windows\print-agent\source\Directory.Build.props') -Raw
$version=[string]$props.Project.PropertyGroup.SoknaAgentVersion
if($version -notmatch '^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$'){throw 'Print Agent component version is invalid.'}
$source=Join-Path $RepoRoot 'windows\print-agent\source'
$temp=Join-Path ([IO.Path]::GetTempPath()) ('sokna-print-'+[guid]::NewGuid().ToString('N'))
$service=Join-Path $temp 'Service';$worker=Join-Path $temp 'Worker'
New-Item -ItemType Directory -Path $service,$worker,$OutputDir -Force|Out-Null
try{
 & dotnet publish (Join-Path $source 'src\Sokna.PrintAgent.Service\Sokna.PrintAgent.Service.csproj') -c $Configuration -r win-x64 --self-contained true -p:PublishSingleFile=true -p:DebugType=None -p:DebugSymbols=false -o $service
 if($LASTEXITCODE -ne 0){throw 'Print Agent Service publish failed.'}
 & dotnet publish (Join-Path $source 'src\Sokna.PrintAgent.Worker\Sokna.PrintAgent.Worker.csproj') -c $Configuration -r win-x64 --self-contained true -p:PublishSingleFile=true -p:DebugType=None -p:DebugSymbols=false -o $worker
 if($LASTEXITCODE -ne 0){throw 'Print Agent Worker publish failed.'}
 $manifest=[ordered]@{format='sokna-print-agent-component-v1';component='print-agent';version=$version;files=@()}
 Get-ChildItem $temp -File -Recurse|Sort-Object FullName|ForEach-Object{$manifest.files += [ordered]@{path=$_.FullName.Substring($temp.Length+1).Replace('\','/');size=[long]$_.Length;sha256=(Get-FileHash $_.FullName -Algorithm SHA256).Hash.ToLowerInvariant()}}
 $manifest|ConvertTo-Json -Depth 5|Set-Content (Join-Path $temp 'component-manifest.json') -Encoding UTF8
 $zip=Join-Path $OutputDir ("SoknaPrintAgent-$version-win-x64.zip");if(Test-Path $zip){Remove-Item $zip -Force};Compress-Archive -Path (Join-Path $temp '*') -DestinationPath $zip -CompressionLevel Optimal
 [ordered]@{format='sokna-component-artifact-v1';component='print-agent';version=$version;file=[IO.Path]::GetFileName($zip);sha256=(Get-FileHash $zip -Algorithm SHA256).Hash.ToLowerInvariant()}|ConvertTo-Json|Set-Content (Join-Path $OutputDir 'print-agent-artifact.json') -Encoding UTF8
 Write-Host "Print Agent artifact: $zip"
}finally{Remove-Item $temp -Recurse -Force -ErrorAction SilentlyContinue}
