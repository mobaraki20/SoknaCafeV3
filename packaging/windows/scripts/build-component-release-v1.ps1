param(
  [Parameter(Mandatory=$true)][string]$RepoRoot,
  [Parameter(Mandatory=$true)][string]$OutputRoot,
  [Parameter(Mandatory=$true)][ValidateSet('runtime','print-agent','maintenance')][string]$ComponentId,
  [Parameter(Mandatory=$true)][string]$PayloadSource,
  [string]$SourceCommit=''
)
$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest

function Absolute([string]$p) { return [IO.Path]::GetFullPath($p).TrimEnd([IO.Path]::DirectorySeparatorChar) }
function SourceVersion([string]$path,[string]$property) {
  if(-not(Test-Path -LiteralPath $path -PathType Leaf)){throw "Version source missing: $path"}
  $xml=[xml][IO.File]::ReadAllText($path)
  $node=$xml.SelectSingleNode("//Project/PropertyGroup/$property")
  if(-not$node -or [string]::IsNullOrWhiteSpace($node.InnerText)){throw "Component source version missing: $property"}
  return $node.InnerText.Trim()
}
function Sha([string]$p){(Get-FileHash -LiteralPath $p -Algorithm SHA256).Hash.ToLowerInvariant()}
function CopyFile([string]$src,[string]$dst){
  if(-not(Test-Path -LiteralPath $src -PathType Leaf)){throw "Component payload missing: $src"}
  New-Item -ItemType Directory -Path ([IO.Path]::GetDirectoryName($dst)) -Force|Out-Null
  Copy-Item -LiteralPath $src -Destination $dst -Force
}
function CopyDirectory([string]$src,[string]$dst){
  if(-not(Test-Path -LiteralPath $src -PathType Container)){throw "Component payload directory missing: $src"}
  New-Item -ItemType Directory -Path $dst -Force|Out-Null
  Copy-Item -Path (Join-Path $src '*') -Destination $dst -Recurse -Force
}
$RepoRoot=Absolute $RepoRoot
$OutputRoot=Absolute $OutputRoot
$PayloadSource=Absolute $PayloadSource
$manifestPath=Join-Path $RepoRoot 'packaging/windows/windows-services-component-manifest-v2.json'
$baseManifest=Get-Content -LiteralPath $manifestPath -Raw|ConvertFrom-Json
if($baseManifest.format -ne 'sokna-windows-component-manifest-v2'){throw 'Existing component manifest invalid.'}
$component=@($baseManifest.components|Where-Object component_id -eq $ComponentId)
if($component.Count-ne1){throw 'Unknown or duplicate component.'}
$component=$component[0]
$version=$null
$paths=@()
switch($ComponentId){
  'runtime' {
    $version=SourceVersion (Join-Path $RepoRoot 'windows/runtime/source/Sokna.Runtime.Service.csproj') 'Version'
    $paths=@('runtime/SoknaRuntimeService.exe')
  }
  'print-agent' {
    $version=SourceVersion (Join-Path $RepoRoot 'windows/print-agent/source/Directory.Build.props') 'SoknaAgentVersion'
    $paths=@('print-agent/Service/Sokna.PrintAgent.Service.exe','print-agent/Worker/Sokna.PrintAgent.Worker.exe')
  }
  'maintenance' {
    $version=SourceVersion (Join-Path $RepoRoot 'windows/maintenance/src/Sokna.Maintenance.Supervisor/Sokna.Maintenance.Supervisor.csproj') 'Version'
    $engineVersion=SourceVersion (Join-Path $RepoRoot 'windows/maintenance/src/Sokna.Maintenance.UpdateEngine/Sokna.Maintenance.UpdateEngine.csproj') 'Version'
    if($engineVersion-ne$version){throw 'Maintenance and Update Engine must have the same version for a group update.'}
    $paths=@('maintenance/app/SoknaMaintenanceSupervisor.exe','maintenance/app/SoknaMaintenanceUpdateEngine.exe')
  }
}
if($version-notmatch '^\d+\.\d+\.\d+$'){throw "Component version invalid: $version"}
if($ComponentId -eq 'runtime'){
  if(-not(Test-Path -LiteralPath $PayloadSource -PathType Leaf)){throw 'Runtime EXE not found.'}
}else{
  if(-not(Test-Path -LiteralPath $PayloadSource -PathType Container)){throw "Component bundle directory not found: $PayloadSource"}
}
if(-not$SourceCommit){
  $SourceCommit=(& git -C $RepoRoot rev-parse HEAD)
  if($LASTEXITCODE-ne0){throw 'Source commit not resolved.'}
  $SourceCommit=$SourceCommit.Trim()
}
if($SourceCommit-notmatch '^[0-9a-fA-F]{40}$'){throw 'Source commit must be a full SHA-1 Git commit.'}

New-Item -ItemType Directory -Path $OutputRoot -Force|Out-Null
$work=Join-Path $OutputRoot ('.staging-'+$ComponentId+'-'+[guid]::NewGuid().ToString('N'))
$zip=Join-Path $OutputRoot ("SOKNA-"+$ComponentId+"-"+$version+"-COMPONENT-CANDIDATE.zip")
if(Test-Path -LiteralPath $zip){throw "Existing candidate will not be overwritten: $zip"}
New-Item -ItemType Directory -Path $work -Force|Out-Null
try{
  switch($ComponentId){
    'runtime' { CopyFile $PayloadSource (Join-Path $work $paths[0]) }
    'print-agent' { CopyDirectory $PayloadSource (Join-Path $work 'print-agent') }
    'maintenance' { CopyDirectory $PayloadSource (Join-Path $work 'maintenance/app') }
  }
  foreach($p in $paths){if(-not(Test-Path -LiteralPath (Join-Path $work $p) -PathType Leaf)){throw "Required component file missing: $p"}}
  $files=@(Get-ChildItem -LiteralPath $work -Recurse -File|Sort-Object FullName|ForEach-Object{
    $relative=$_.FullName.Substring($work.Length+1).Replace('\','/')
    if($relative -match '(^|/)\.\.?(/|$)' -or $_.Length -le 0){throw "Unsafe payload: $relative"}
    [ordered]@{path=$relative;size_bytes=[long]$_.Length;sha256=(Sha $_.FullName)}
  })
  if($files.Count -eq 0){throw 'Component package is empty.'}
  $release=[ordered]@{
    format='sokna-windows-component-release-v1'
    schema_version=1
    component_id=$ComponentId
    component_version=$version
    source_commit=$SourceCommit.ToLowerInvariant()
    release_scope='component_only'
    installable=$false
    installer_qualification='NOT_YET_QUALIFIED'
    manager_ownership='external_existing_windows_services'
    service_name=[string]$component.service_name
    transaction_group=[string]$component.transaction_group
    requires_print_supervision_pause=($ComponentId-eq'print-agent')
    required_files=$paths
    files=$files
  }
  $json=Join-Path $work 'component-release-v1.json'
  [IO.File]::WriteAllText($json,($release|ConvertTo-Json -Depth 12),[Text.UTF8Encoding]::new($false))
  [IO.Compression.ZipFile]::CreateFromDirectory($work,$zip,[IO.Compression.CompressionLevel]::Optimal,$false)
  $summary=[ordered]@{
    format='sokna-component-build-result-v1'
    component_id=$ComponentId
    version=$version
    zip=$zip
    zip_sha256=(Sha $zip)
    zip_size_bytes=[long](Get-Item $zip).Length
    included_files=$files.Count
    installable=$false
  }
  $summary|ConvertTo-Json -Depth 4
} finally {
  if(Test-Path -LiteralPath $work){Remove-Item -LiteralPath $work -Recurse -Force}
}
