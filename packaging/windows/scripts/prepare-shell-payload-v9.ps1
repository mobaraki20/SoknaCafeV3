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
$base=Join-Path $RepoRoot 'packaging\windows\scripts\prepare-shell-payload-v8.ps1'
if(-not(Test-Path -LiteralPath $base -PathType Leaf)){throw 'R8 shell composer is missing.'}
& $base -RepoRoot $RepoRoot -OutputRoot $OutputRoot -RuntimeServiceExe $RuntimeServiceExe -SetupHostExe $SetupHostExe -SetupUiExe $SetupUiExe -PrintWorkerBundle $PrintWorkerBundle -MaintenanceAppBundle $MaintenanceAppBundle -MaintenanceRecoveryBundle $MaintenanceRecoveryBundle -GitSha $GitSha

$setupSource=Join-Path $RepoRoot 'packaging\windows\scripts\setup-windows-components-v9.ps1'
$supportSource=Join-Path $RepoRoot 'packaging\windows\scripts\collect-support-v3.ps1'
$supportCoreSource=Join-Path $RepoRoot 'packaging\windows\scripts\collect-support-v2.ps1'
$technicianSource=Join-Path $RepoRoot 'packaging\windows\scripts\write-technician-report-v1.ps1'
foreach($p in @($setupSource,$supportSource,$supportCoreSource,$technicianSource)){if(-not(Test-Path -LiteralPath $p -PathType Leaf)){throw "Windows Services shell source missing: $p"}}
Copy-Item -LiteralPath $setupSource -Destination (Join-Path $OutputRoot 'setup-windows-components-v4.ps1') -Force
Copy-Item -LiteralPath $supportCoreSource -Destination (Join-Path $OutputRoot 'collect-support-v2-core.ps1') -Force
Copy-Item -LiteralPath $supportSource -Destination (Join-Path $OutputRoot 'collect-support-v2.ps1') -Force
Copy-Item -LiteralPath $technicianSource -Destination (Join-Path $OutputRoot 'write-technician-report-v1.ps1') -Force

$utf8Bom=New-Object Text.UTF8Encoding($true)
foreach($ps1 in Get-ChildItem $OutputRoot -Filter '*.ps1' -File){
  $raw=[IO.File]::ReadAllText($ps1.FullName,[Text.Encoding]::UTF8).Replace([char]0x2014,'-')
  [IO.File]::WriteAllText($ps1.FullName,$raw,$utf8Bom)
}

$payloadManifestPath=Join-Path $OutputRoot 'payload-manifest.json'
$payloadManifest=Get-Content -LiteralPath $payloadManifestPath -Raw|ConvertFrom-Json
$files=@()
Get-ChildItem $OutputRoot -File -Recurse|Where-Object{$_.FullName-ne$payloadManifestPath}|Sort-Object FullName|ForEach-Object{
  $files += [ordered]@{path=$_.FullName.Substring($OutputRoot.Length+1).Replace('\','/');size=[long]$_.Length;sha256=(Get-FileHash $_.FullName -Algorithm SHA256).Hash.ToLowerInvariant()}
}
$payloadManifest.files=$files
$payloadManifest|ConvertTo-Json -Depth 14|Set-Content -LiteralPath $payloadManifestPath -Encoding UTF8
Write-Host 'Prepared Windows Services shell: guarded legacy Print migration + technician diagnostics.'
