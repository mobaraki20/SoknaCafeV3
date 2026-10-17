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

# R8 core predates the independent Recovery lifecycle rule. Patch only exact known contracts
# in the composed shell. A healthy or manifest-declared compatible Recovery Console remains
# KEEP; unknown Recovery drift is still rejected before privileged mutation.
$corePath=Join-Path $OutputRoot 'setup-windows-components-core-v5.ps1'
if(-not(Test-Path -LiteralPath $corePath -PathType Leaf)){throw 'Qualified Windows component lifecycle core is missing from composed shell.'}
$coreRaw=[IO.File]::ReadAllText($corePath,[Text.Encoding]::UTF8)

$oldCoreLine="if([string]`$plan.mode-eq'repair'-and`$action-eq'KEEP'){`$action='REPAIR';`$reason='explicit_repair'}"
$newCoreLine="if([string]`$plan.mode-eq'repair'-and`$action-eq'KEEP'-and`$id-ne'maintenance-recovery-console'){`$action='REPAIR';`$reason='explicit_repair'}"
if(-not$coreRaw.Contains($oldCoreLine)){throw 'Expected R8 explicit-repair core contract was not found; refusing an unbounded core rewrite.'}
if(([regex]::Matches($coreRaw,[regex]::Escape($oldCoreLine))).Count-ne1){throw 'Expected exactly one R8 explicit-repair core contract occurrence.'}
$coreRaw=$coreRaw.Replace($oldCoreLine,$newCoreLine)

$oldHashDrift="elseif(`$ih-ne`$targetHash){`$action='REPAIR';`$reason='hash_drift'}"
$newHashDrift="elseif(`$ih-ne`$targetHash){`$accepted=@(Prop `$m 'accepted_installed_sha256' `$null);`$compatible=@(`$accepted|Where-Object{[string]`$_-eq`$ih}).Count-gt0;if(`$compatible){`$action='KEEP';`$reason='accepted_legacy_fingerprint'}else{`$action='REPAIR';`$reason='hash_drift'}}"
if(-not$coreRaw.Contains($oldHashDrift)){throw 'Expected R8 hash-drift core contract was not found; refusing an unbounded core rewrite.'}
if(([regex]::Matches($coreRaw,[regex]::Escape($oldHashDrift))).Count-ne1){throw 'Expected exactly one R8 hash-drift core contract occurrence.'}
$coreRaw=$coreRaw.Replace($oldHashDrift,$newHashDrift)

# 1.1.12: the executor must honor the same released-fingerprint KEEP contract as Resolve-One.
# 1.1.11 accepted the legacy Recovery fingerprint while resolving the plan, but the later
# subordinate no-mutation verifier compared only with TargetHash and rejected that same KEEP.
$oldSubordinateKeep=@'
if($r.Action-eq'KEEP'){$CurrentPhase='No mutation';if((Sha $r.InstalledPath)-ne$r.TargetHash){throw "Maintenance subordinate fingerprint mismatch: $($r.Id)"};Event 'component_action_completed' 'kept' @{action='KEEP';proof='no_stop_no_copy_no_registration_no_restart';after_sha256=(Sha $r.InstalledPath)};continue}
'@.Trim()
$newSubordinateKeep=@'
if($r.Action-eq'KEEP'){$CurrentPhase='No mutation';$installedHash=Sha $r.InstalledPath;$componentManifest=@($manifest.components|Where-Object{[string]$_.component_id-eq[string]$r.Id})|Select-Object -First 1;$accepted=@(Prop $componentManifest 'accepted_installed_sha256' $null);$compatible=($installedHash-eq$r.TargetHash)-or(@($accepted|Where-Object{[string]$_-eq$installedHash}).Count-gt0);if(-not$compatible){throw "Maintenance subordinate fingerprint mismatch: $($r.Id)"};Event 'component_action_completed' 'kept' @{action='KEEP';proof='no_stop_no_copy_no_registration_no_restart';after_sha256=$installedHash;compatible_fingerprint=($installedHash-ne$r.TargetHash)};continue}
'@.Trim()
if(-not$coreRaw.Contains($oldSubordinateKeep)){throw 'Expected R8 subordinate KEEP verifier was not found; refusing an unbounded executor rewrite.'}
if(([regex]::Matches($coreRaw,[regex]::Escape($oldSubordinateKeep))).Count-ne1){throw 'Expected exactly one R8 subordinate KEEP verifier occurrence.'}
$coreRaw=$coreRaw.Replace($oldSubordinateKeep,$newSubordinateKeep)

[IO.File]::WriteAllText($corePath,$coreRaw,(New-Object Text.UTF8Encoding($true)))

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
Write-Host 'Prepared Windows Services shell: symmetric compatible Recovery KEEP + guarded legacy Print migration + technician diagnostics.'
