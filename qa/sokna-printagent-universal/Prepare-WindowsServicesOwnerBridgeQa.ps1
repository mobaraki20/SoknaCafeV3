[CmdletBinding()]
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
Set-StrictMode -Version Latest
$base=Join-Path $RepoRoot 'packaging\windows\scripts\prepare-shell-payload-v9.ps1'
$bridge=Join-Path $RepoRoot 'qa\sokna-printagent-universal\Add-PrintAgentIndependentOwnerBridge.ps1'
foreach($p in @($base,$bridge)){if(!(Test-Path -LiteralPath $p -PathType Leaf)){throw 'R238_SHELL_COMPOSER_PREREQUISITE_MISSING'}}
& $base -RepoRoot $RepoRoot -OutputRoot $OutputRoot -RuntimeServiceExe $RuntimeServiceExe -SetupHostExe $SetupHostExe -SetupUiExe $SetupUiExe -PrintWorkerBundle $PrintWorkerBundle -MaintenanceAppBundle $MaintenanceAppBundle -MaintenanceRecoveryBundle $MaintenanceRecoveryBundle -GitSha $GitSha
if(-not $?) {throw 'R238_V9_COMPOSER_FAILED'}
$core=Join-Path $OutputRoot 'setup-windows-components-core-v5.ps1'
$composedRaw=[IO.File]::ReadAllText($core,[Text.Encoding]::UTF8)
if($composedRaw -notmatch 'function Verified-IndependentPrintAgentReceipt'){
  & $bridge -CorePath $core
  if(-not $?){throw 'R238_BRIDGE_FAILED'}
}else{
  Write-Host 'PASS_R238_PRINT_RECEIPT_CONSUMER_ALREADY_COMPOSED_BY_V9'
}
# Host-v3 verifies payload-manifest hashes at startup: recompute after source transformation.
$manifestPath=Join-Path $OutputRoot 'payload-manifest.json'
if(!(Test-Path -LiteralPath $manifestPath -PathType Leaf)){throw 'R238_HOST_MANIFEST_MISSING'}
$manifest=Get-Content -LiteralPath $manifestPath -Raw|ConvertFrom-Json
$rows=@(Get-ChildItem -LiteralPath $OutputRoot -File -Recurse|Where-Object{$_.FullName -ne $manifestPath}|Sort-Object FullName|ForEach-Object{
 @{path=$_.FullName.Substring($OutputRoot.Length+1).Replace('\','/');size=[long]$_.Length;sha256=(Get-FileHash -LiteralPath $_.FullName -Algorithm SHA256).Hash.ToLowerInvariant()}
})
$manifest.files=$rows
$manifest|ConvertTo-Json -Depth 14|Set-Content -LiteralPath $manifestPath -Encoding UTF8
$token=$null;$errors=$null
[System.Management.Automation.Language.Parser]::ParseFile($core,[ref]$token,[ref]$errors)|Out-Null
if(@($errors).Count -ne 0){throw 'R238_COMPOSED_CORE_POWERSHELL_PARSE_FAILED'}
Write-Host 'PASS_R238_PRINT_OWNER_BRIDGE_COMPOSED_ON_TOP_OF_V9_NO_CURRENT_MUTATION'