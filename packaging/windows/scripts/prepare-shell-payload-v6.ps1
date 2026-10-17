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

# This is an in-process PowerShell script invocation. Do not inspect $LASTEXITCODE here: that variable
# belongs to the most recently executed native process and may legally contain a stale non-zero value
# even when the PowerShell script completed successfully. ErrorActionPreference=Stop + thrown exceptions
# are the authoritative failure contract for this call.
& $coreBuilder -RepoRoot $RepoRoot -OutputRoot $OutputRoot -RuntimeServiceExe $RuntimeServiceExe -SetupHostExe $SetupHostExe -SetupUiExe $SetupUiExe -PrintWorkerBundle $PrintWorkerBundle -MaintenanceAppBundle $MaintenanceAppBundle -MaintenanceRecoveryBundle $MaintenanceRecoveryBundle -GitSha $GitSha

$legacyEntry=Join-Path $OutputRoot 'setup-windows-components-v4.ps1'
$coreEntry=Join-Path $OutputRoot 'setup-windows-components-core-v5.ps1'
$wrapperSource=Join-Path $RepoRoot 'packaging\windows\scripts\setup-windows-components-v7.ps1'
$queueSource=Join-Path $RepoRoot 'packaging\windows\scripts\queue-local-slot-adoption-r7.ps1'
$contractSource=Join-Path $RepoRoot 'packaging\windows\local-slot-bootstrap-contract-v1.json'
foreach($p in @($legacyEntry,$wrapperSource,$queueSource,$contractSource)){if(-not(Test-Path -LiteralPath $p -PathType Leaf)){throw "R7 shell integration source missing: $p"}}
Copy-Item -LiteralPath $legacyEntry -Destination $coreEntry -Force
Copy-Item -LiteralPath $wrapperSource -Destination $legacyEntry -Force
Copy-Item -LiteralPath $queueSource -Destination (Join-Path $OutputRoot 'queue-local-slot-adoption-r7.ps1') -Force
Copy-Item -LiteralPath $contractSource -Destination (Join-Path $OutputRoot 'local-slot-bootstrap-contract-v1.json') -Force

# Windows PowerShell 5.1 requires a BOM for reliable UTF-8 source decoding.
$utf8Bom=New-Object Text.UTF8Encoding($true)
foreach($name in @('setup-windows-components-v4.ps1','setup-windows-components-core-v5.ps1','queue-local-slot-adoption-r7.ps1')){
  $path=Join-Path $OutputRoot $name
  $raw=[IO.File]::ReadAllText($path,[Text.Encoding]::UTF8).Replace([char]0x2014,'-')
  [IO.File]::WriteAllText($path,$raw,$utf8Bom)
}

$required=@('setup-windows-components-v4.ps1','setup-windows-components-core-v5.ps1','queue-local-slot-adoption-r7.ps1','local-slot-bootstrap-contract-v1.json')
foreach($name in $required){if(-not(Test-Path -LiteralPath (Join-Path $OutputRoot $name) -PathType Leaf)){throw "R7 shell integration output missing: $name"}}

# Recompute payload-manifest inventory after R7 wrapper/queue injection so SetupHost verifies exact bytes.
$payloadManifestPath=Join-Path $OutputRoot 'payload-manifest.json'
$payloadManifest=Get-Content -LiteralPath $payloadManifestPath -Raw|ConvertFrom-Json
$files=@()
Get-ChildItem $OutputRoot -File -Recurse|Where-Object{$_.FullName-ne$payloadManifestPath}|Sort-Object FullName|ForEach-Object{
  $files += [ordered]@{path=$_.FullName.Substring($OutputRoot.Length+1).Replace('\','/');size=[long]$_.Length;sha256=(Get-FileHash $_.FullName -Algorithm SHA256).Hash.ToLowerInvariant()}
}
$payloadManifest.files=$files
$payloadManifest|ConvertTo-Json -Depth 14|Set-Content -LiteralPath $payloadManifestPath -Encoding UTF8

Write-Host 'Prepared R7 shell integration: Windows transaction remains authoritative; Local layout adoption is durable Supervisor-owned work.'
