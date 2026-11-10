param(
 [Parameter(Mandatory=$true)][string]$RepoRoot,
 [Parameter(Mandatory=$true)][string]$Supervisor,
 [Parameter(Mandatory=$true)][string]$Engine,
 [Parameter(Mandatory=$true)][string]$OutputRoot
)
$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest
$RepoRoot=[IO.Path]::GetFullPath($RepoRoot)
$OutputRoot=[IO.Path]::GetFullPath($OutputRoot)
if(Test-Path -LiteralPath $OutputRoot){throw 'MAINTENANCE_PAIR_REFUSE_OVERWRITE'}
New-Item -ItemType Directory -Path (Join-Path $OutputRoot 'Maintenance\App') -Force|Out-Null
$spec=@(
 @{src=$Supervisor;name='SoknaMaintenanceSupervisor.exe';rel='Maintenance/App/SoknaMaintenanceSupervisor.exe'},
 @{src=$Engine;name='SoknaMaintenanceUpdateEngine.exe';rel='Maintenance/App/SoknaMaintenanceUpdateEngine.exe'}
)
$entries=@()
foreach($item in $spec){
 if(-not(Test-Path -LiteralPath $item.src -PathType Leaf)){throw 'MAINTENANCE_PAIR_BINARY_MISSING'}
 $v=[string]([Diagnostics.FileVersionInfo]::GetVersionInfo($item.src).FileVersion)
 if($v-ne'0.3.26.0'){throw ('MAINTENANCE_PAIR_WRONG_VERSION_'+$item.name)}
 $dest=Join-Path $OutputRoot $item.rel.Replace('/','\')
 Copy-Item -LiteralPath $item.src -Destination $dest
 $entries+=@{path=$item.rel;bytes=(Get-Item -LiteralPath $dest).Length;sha256=(Get-FileHash -LiteralPath $dest -Algorithm SHA256).Hash.ToLowerInvariant()}
}
$baselines=Get-Content -LiteralPath (Join-Path $RepoRoot 'packaging/windows/maintenance-supported-baselines-v4.json') -Raw|ConvertFrom-Json
if(@($baselines).Count-ne4){throw 'MAINTENANCE_PAIR_BASELINE_MANIFEST_INCOMPLETE'}
$manifest=[ordered]@{
 format='sokna-maintenance-independent-installer-v1'
 source_commit='aba774d67e9d3890aa0f69d00f214ae82b395927'
 wrapper_source_commit=$env:GITHUB_SHA
 target_version='0.3.26'
 scope='maintenance_pair_only'
 recovery_console_action='KEEP_UNCHANGED'
 supported_baselines=@($baselines)
 files=@($entries)
}
$manifest|ConvertTo-Json -Depth 12|Set-Content (Join-Path $OutputRoot 'maintenance-independent-manifest-v1.json') -Encoding UTF8
foreach($name in @('invoke-maintenance-independent-v1.ps1','invoke-maintenance-independent-logged-v1.ps1','maintenance-signed-health-v1.ps1')){
 Copy-Item -LiteralPath (Join-Path $RepoRoot ('packaging/windows/scripts/'+$name)) -Destination (Join-Path $OutputRoot $name) -Force
}
Write-Host 'PASS Maintenance two-component standalone payload; Recovery and Local/Public excluded'
