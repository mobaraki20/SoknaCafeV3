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
# in the composed shell. The compatibility predicate is injected once and used by both
# action resolution and subordinate KEEP verification so Planner/Executor cannot diverge.
$corePath=Join-Path $OutputRoot 'setup-windows-components-core-v5.ps1'
if(-not(Test-Path -LiteralPath $corePath -PathType Leaf)){throw 'Qualified Windows component lifecycle core is missing from composed shell.'}
$coreRaw=[IO.File]::ReadAllText($corePath,[Text.Encoding]::UTF8)

$oldProp="function Prop(`$o,[string]`$n,`$default=`$null){if(`$null-ne`$o-and`$o.PSObject.Properties.Name-contains`$n){return `$o.`$n};`$default}"
$independentProof=@'
function Verified-IndependentMaintenancePair($m,[string]$actual){
  $id=[string](Prop $m 'component_id' '')
  if($id-notin@('maintenance','maintenance-update-engine')-or[string](Prop $m 'version' '')-ne'0.3.10'-or$actual.Length-ne64){return $false}
  try{
    $receiptPath=Join-Path $DataRoot 'setup\maintenance-only-component-receipt-v1.json'
    $supervisor=Join-Path $InstallRoot 'Maintenance\App\SoknaMaintenanceSupervisor.exe'
    $engine=Join-Path $InstallRoot 'Maintenance\App\SoknaMaintenanceUpdateEngine.exe'
    if(-not(Test-Path -LiteralPath $receiptPath -PathType Leaf)-or-not(Test-Path -LiteralPath $supervisor -PathType Leaf)-or-not(Test-Path -LiteralPath $engine -PathType Leaf)){return $false}
    $receipt=Get-Content -LiteralPath $receiptPath -Raw -ErrorAction Stop|ConvertFrom-Json -ErrorAction Stop
    $tx=[Guid]::Empty
    if([string]$receipt.format-ne'sokna-maintenance-only-component-receipt-v1'-or[int]$receipt.schema_version-ne1-or[string]$receipt.release_scope-ne'maintenance_only'-or[string]$receipt.version-ne'0.3.10'-or-not[Guid]::TryParseExact([string]$receipt.transaction_id,'N',[ref]$tx)-or-not[bool]$receipt.windows_shell_state_unchanged-or-not[bool]$receipt.runtime_unchanged-or-not[bool]$receipt.print_agent_unchanged-or-not[bool]$receipt.recovery_console_unchanged){return $false}
    $supervisorHash=Sha $supervisor
    $engineHash=Sha $engine
    if($supervisorHash.Length-ne64-or$engineHash.Length-ne64-or$supervisorHash-ne[string]$receipt.supervisor_sha256-or$engineHash-ne[string]$receipt.engine_sha256-or(VersionOf $supervisor)-ne'0.3.10'-or(VersionOf $engine)-ne'0.3.10'){return $false}
    $svc=Service-Info 'SoknaMaintenanceSupervisor'
    if(-not$svc.Installed-or[string]$svc.State-ne'Running'-or-not(Owned ([string]$svc.ImagePath) $InstallRoot)-or-not(Registration-Matches 'maintenance' ([string]$svc.ImagePath) $supervisor $DataRoot)){return $false}
    return ($actual-eq$(if($id-eq'maintenance'){$supervisorHash}else{$engineHash}))
  }catch{return $false}
}
'@.Trim()
$newProp=$oldProp+"`r`n"+$independentProof+"`r`nfunction Compatible-Fingerprint(`$m,[string]`$actual,[string]`$target){if([string]::IsNullOrWhiteSpace(`$actual)){return `$false};if(`$actual-eq`$target){return `$true};`$accepted=@(Prop `$m 'accepted_installed_sha256' `$null);if(@(`$accepted|Where-Object{[string]`$_-eq`$actual}).Count-gt0){return `$true};return (Verified-IndependentMaintenancePair `$m `$actual)}"
if(-not$coreRaw.Contains($oldProp)){throw 'Expected R8 Prop contract was not found; refusing an unbounded core rewrite.'}
if(([regex]::Matches($coreRaw,[regex]::Escape($oldProp))).Count-ne1){throw 'Expected exactly one R8 Prop contract occurrence.'}
$coreRaw=$coreRaw.Replace($oldProp,$newProp)

$oldCoreLine="if([string]`$plan.mode-eq'repair'-and`$action-eq'KEEP'){`$action='REPAIR';`$reason='explicit_repair'}"
$newCoreLine="if([string]`$plan.mode-eq'repair'-and`$action-eq'KEEP'-and`$id-ne'maintenance-recovery-console'){`$action='REPAIR';`$reason='explicit_repair'}"
if(-not$coreRaw.Contains($oldCoreLine)){throw 'Expected R8 explicit-repair core contract was not found; refusing an unbounded core rewrite.'}
if(([regex]::Matches($coreRaw,[regex]::Escape($oldCoreLine))).Count-ne1){throw 'Expected exactly one R8 explicit-repair core contract occurrence.'}
$coreRaw=$coreRaw.Replace($oldCoreLine,$newCoreLine)

$oldHashDrift="elseif(`$ih-ne`$targetHash){`$action='REPAIR';`$reason='hash_drift'}"
$newHashDrift="elseif(`$ih-ne`$targetHash){if(Compatible-Fingerprint `$m `$ih `$targetHash){`$action='KEEP';`$reason='accepted_legacy_fingerprint'}else{`$action='REPAIR';`$reason='hash_drift'}}"
if(-not$coreRaw.Contains($oldHashDrift)){throw 'Expected R8 hash-drift core contract was not found; refusing an unbounded core rewrite.'}
if(([regex]::Matches($coreRaw,[regex]::Escape($oldHashDrift))).Count-ne1){throw 'Expected exactly one R8 hash-drift core contract occurrence.'}
$coreRaw=$coreRaw.Replace($oldHashDrift,$newHashDrift)

# Keep released, SHA256-allowlisted Recovery Console 0.1.1 under the separately
# frozen Recovery lifecycle. Unknown versions/hashes remain fail-closed.
$oldVersionOlder="elseif(`$cmp-lt0){`$action='UPDATE';`$reason='version_older'}"
$newVersionOlder="elseif(`$cmp-lt0){if(`$id-eq'maintenance-recovery-console'-and`$current-eq'0.1.1'-and(Compatible-Fingerprint `$m `$ih `$targetHash)){`$action='KEEP';`$reason='accepted_frozen_recovery_011'}else{`$action='UPDATE';`$reason='version_older'}}"
if(([regex]::Matches($coreRaw,[regex]::Escape($oldVersionOlder))).Count-ne1){throw 'Expected exactly one Recovery version-older contract; refusing unsafe shell rewrite.'}
$coreRaw=$coreRaw.Replace($oldVersionOlder,$newVersionOlder)

$oldSubordinateKeep=@'
if($r.Action-eq'KEEP'){$CurrentPhase='No mutation';if((Sha $r.InstalledPath)-ne$r.TargetHash){throw "Maintenance subordinate fingerprint mismatch: $($r.Id)"};Event 'component_action_completed' 'kept' @{action='KEEP';proof='no_stop_no_copy_no_registration_no_restart';after_sha256=(Sha $r.InstalledPath)};continue}
'@.Trim()
$newSubordinateKeep=@'
if($r.Action-eq'KEEP'){$CurrentPhase='No mutation';$installedHash=Sha $r.InstalledPath;$componentManifest=@($manifest.components|Where-Object{[string]$_.component_id-eq[string]$r.Id})|Select-Object -First 1;if(-not(Compatible-Fingerprint $componentManifest $installedHash $r.TargetHash)){throw "Maintenance subordinate fingerprint mismatch: $($r.Id)"};Event 'component_action_completed' 'kept' @{action='KEEP';proof='no_stop_no_copy_no_registration_no_restart';after_sha256=$installedHash;compatible_fingerprint=($installedHash-ne$r.TargetHash)};continue}
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
Write-Host 'Prepared Windows Services shell: symmetric Recovery fingerprint contract + guarded legacy Print migration + technician diagnostics.'
