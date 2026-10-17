param(
  [Parameter(Mandatory=$true)][string]$RepoRoot,
  [Parameter(Mandatory=$true)][string]$OutputRoot,
  [Parameter(Mandatory=$true)][string]$RuntimeServiceExe,
  [Parameter(Mandatory=$true)][string]$SetupHostExe,
  [Parameter(Mandatory=$true)][string]$SetupUiExe,
  [Parameter(Mandatory=$true)][string]$PrintWorkerBundle,
  [Parameter(Mandatory=$true)][string]$MaintenanceAppBundle,
  [Parameter(Mandatory=$true)][string]$MaintenanceRecoveryBundle,
  [string]$GitSha=''
)
$ErrorActionPreference='Stop'
function Full([string]$p){[IO.Path]::GetFullPath($p).TrimEnd('\')}
$RepoRoot=Full $RepoRoot;$OutputRoot=Full $OutputRoot
$coreBuilder=Join-Path $RepoRoot 'packaging\windows\scripts\prepare-shell-payload-v4.ps1'
if(-not(Test-Path -LiteralPath $coreBuilder -PathType Leaf)){throw 'Qualified shell builder v4 is missing.'}
& $coreBuilder -RepoRoot $RepoRoot -OutputRoot $OutputRoot -RuntimeServiceExe $RuntimeServiceExe -SetupHostExe $SetupHostExe -SetupUiExe $SetupUiExe -PrintWorkerBundle $PrintWorkerBundle -MaintenanceAppBundle $MaintenanceAppBundle -MaintenanceRecoveryBundle $MaintenanceRecoveryBundle -GitSha $GitSha
if($LASTEXITCODE-ne0){throw 'Qualified shell builder v4 failed.'}

$legacyEntry=Join-Path $OutputRoot 'setup-windows-components-v4.ps1'
$coreEntry=Join-Path $OutputRoot 'setup-windows-components-core-v5.ps1'
$wrapperSource=Join-Path $RepoRoot 'packaging\windows\scripts\setup-windows-components-v6.ps1'
$bootstrapSource=Join-Path $RepoRoot 'packaging\windows\scripts\bootstrap-local-slot-r6.ps1'
$contractSource=Join-Path $RepoRoot 'packaging\windows\local-slot-bootstrap-contract-v1.json'
foreach($p in @($legacyEntry,$wrapperSource,$bootstrapSource,$contractSource)){if(-not(Test-Path -LiteralPath $p -PathType Leaf)){throw "R6 shell integration source missing: $p"}}
Copy-Item -LiteralPath $legacyEntry -Destination $coreEntry -Force
Copy-Item -LiteralPath $wrapperSource -Destination $legacyEntry -Force
Copy-Item -LiteralPath $bootstrapSource -Destination (Join-Path $OutputRoot 'bootstrap-local-slot-r6.ps1') -Force
Copy-Item -LiteralPath $contractSource -Destination (Join-Path $OutputRoot 'local-slot-bootstrap-contract-v1.json') -Force

# Preserve Windows PowerShell 5.1 encoding requirements for every newly injected script.
$utf8Bom=New-Object Text.UTF8Encoding($true)
foreach($name in @('setup-windows-components-v4.ps1','setup-windows-components-core-v5.ps1','bootstrap-local-slot-r6.ps1')){
  $path=Join-Path $OutputRoot $name
  $raw=[IO.File]::ReadAllText($path,[Text.Encoding]::UTF8).Replace([char]0x2014,'-')
  [IO.File]::WriteAllText($path,$raw,$utf8Bom)
}

$required=@('setup-windows-components-v4.ps1','setup-windows-components-core-v5.ps1','bootstrap-local-slot-r6.ps1','local-slot-bootstrap-contract-v1.json')
foreach($name in $required){if(-not(Test-Path -LiteralPath (Join-Path $OutputRoot $name) -PathType Leaf)){throw "R6 shell integration output missing: $name"}}

# The v4 builder emitted payload-manifest.json before the wrapper/bootstrap injection.
# Recompute its file inventory so the manifest describes the exact bytes shipped.
$payloadManifestPath=Join-Path $OutputRoot 'payload-manifest.json'
$payloadManifest=Get-Content -LiteralPath $payloadManifestPath -Raw|ConvertFrom-Json
$files=@()
Get-ChildItem $OutputRoot -File -Recurse|Where-Object{$_.FullName-ne$payloadManifestPath}|Sort-Object FullName|ForEach-Object{
  $files += [ordered]@{path=$_.FullName.Substring($OutputRoot.Length+1).Replace('\','/');size=[long]$_.Length;sha256=(Get-FileHash $_.FullName -Algorithm SHA256).Hash.ToLowerInvariant()}
}
$payloadManifest.files=$files
$payloadManifest|ConvertTo-Json -Depth 14|Set-Content -LiteralPath $payloadManifestPath -Encoding UTF8

Write-Host 'Prepared R6 shell integration: qualified Windows lifecycle core + post-commit Supervisor Local migration bootstrap.'
