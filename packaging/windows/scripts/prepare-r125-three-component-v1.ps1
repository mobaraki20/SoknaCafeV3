param(
 [Parameter(Mandatory=$true)][string]$RepoRoot,
 [Parameter(Mandatory=$true)][string]$Supervisor,
 [Parameter(Mandatory=$true)][string]$Engine,
 [Parameter(Mandatory=$true)][string]$RecoveryConsole,
 [Parameter(Mandatory=$true)][string]$OutputRoot
)
$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest
$OutputRoot=[IO.Path]::GetFullPath($OutputRoot)
if(Test-Path -LiteralPath $OutputRoot){throw 'R125_PREP_REFUSE_OVERWRITE'}
New-Item -ItemType Directory -Path (Join-Path $OutputRoot 'Maintenance\App'),(Join-Path $OutputRoot 'Maintenance\Recovery') -Force|Out-Null
$spec=@(
 @{src=$Supervisor;name='SoknaMaintenanceSupervisor.exe';rel='Maintenance/App/SoknaMaintenanceSupervisor.exe';version='0.3.23.0'},
 @{src=$Engine;name='SoknaMaintenanceUpdateEngine.exe';rel='Maintenance/App/SoknaMaintenanceUpdateEngine.exe';version='0.3.23.0'},
 @{src=$RecoveryConsole;name='SoknaMaintenanceRecoveryConsole.exe';rel='Maintenance/Recovery/SoknaMaintenanceRecoveryConsole.exe';version='0.1.4.0'}
)
$entries=@()
foreach($item in $spec){
 if(-not(Test-Path -LiteralPath $item.src -PathType Leaf)){throw 'R125_PREP_BINARY_MISSING'}
 $version=([string]([Diagnostics.FileVersionInfo]::GetVersionInfo($item.src).FileVersion)).Trim()
 if($version-ne$item.version){throw ('R125_PREP_INVALID_VERSION_'+$item.name)}
 $dest=Join-Path $OutputRoot ($item.rel.Replace('/','\'))
 Copy-Item -LiteralPath $item.src -Destination $dest
 $entries+=@{path=$item.rel;bytes=(Get-Item -LiteralPath $dest).Length;sha256=(Get-FileHash -LiteralPath $dest -Algorithm SHA256).Hash.ToLowerInvariant()}
}
$manifest=[ordered]@{format='sokna-r125-three-component-installer-v1';target_version='0.3.23';recovery_console_version='0.1.4';scope='maintenance_three_components_only';files=@($entries)}
$manifest|ConvertTo-Json -Depth 9|Set-Content -LiteralPath (Join-Path $OutputRoot 'r125-component-manifest.json') -Encoding UTF8
foreach($name in @('invoke-r125-three-component-v1.ps1','maintenance-signed-health-v1.ps1')){
 Copy-Item -LiteralPath (Join-Path $RepoRoot ('packaging\windows\scripts\'+$name)) -Destination (Join-Path $OutputRoot $name)
}
Write-Host 'PASS R125 three component exact scoped payload generated'
