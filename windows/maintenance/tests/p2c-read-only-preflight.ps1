param(
  [Parameter(Mandatory=$true)][string]$EngineDll,
  [Parameter(Mandatory=$true)][string]$FixtureDll
)
$ErrorActionPreference = 'Stop'
$PSNativeCommandUseErrorActionPreference = $false
Set-StrictMode -Version Latest

function Assert([bool]$Condition,[string]$Message) { if(-not $Condition) { throw $Message } }
function New-Root([string]$Name) {
  $p = Join-Path $env:RUNNER_TEMP ('sokna-p2c-' + $Name + '-' + [guid]::NewGuid().ToString('N'))
  New-Item -ItemType Directory -Path $p -Force | Out-Null
  return $p
}
function New-Fixture([string]$Root,[string]$Variant,[long]$Sequence,[bool]$SeedStage=$false) {
  $fixtureArgs = @('--root',$Root,'--variant',$Variant,'--sequence',([string]$Sequence))
  if($SeedStage){$fixtureArgs += '--seed-stage'}
  $output = @(& dotnet $FixtureDll @fixtureArgs 2>&1)
  Assert ($LASTEXITCODE -eq 0) ("Fixture creation failed: " + ($output -join ' '))
  return (($output -join [Environment]::NewLine) | ConvertFrom-Json)
}
function Verify($Fixture,[string]$Action='verify-only') {
  $output = @(& dotnet $EngineDll $Action --package ([string]$Fixture.package) --identity ([string]$Fixture.identity) --data-root ([string]$Fixture.data_root) 2>&1)
  $exit = $LASTEXITCODE
  $value = ($output -join [Environment]::NewLine)
  try { $json = $value | ConvertFrom-Json } catch { throw ("Non-JSON engine result: " + $value) }
  return [pscustomobject]@{ Exit=$exit; Data=$json; Raw=$value }
}
function Assert-NoMutation([string]$Root,[string]$Case) {
  foreach($rel in @('update', 'staging', 'operations', 'local-slots')) {
    Assert (-not(Test-Path -LiteralPath (Join-Path $Root $rel))) "$Case created $rel during read-only verification"
  }
}
function Assert-ReadOnly($Result,[string]$Case,[bool]$ExpectedValid) {
  Assert ([bool]$Result.Data.ok -eq $ExpectedValid) "$Case reported unexpected ok flag"
  Assert (-not[bool]$Result.Data.activation_performed) "$Case activated Local"
  Assert (-not[bool]$Result.Data.database_mutation_performed) "$Case mutated DB"
  Assert (-not[bool]$Result.Data.stage_performed) "$Case staged a package"
  Assert (-not[bool]$Result.Data.installation_allowed) "$Case advertised installability"
  if($ExpectedValid) {
    Assert ($Result.Exit -eq 0) "$Case expected success: $($Result.Raw)"
    Assert ([string]$Result.Data.phase -eq 'VERIFIED_READ_ONLY') "$Case wrong read-only phase"
    Assert ([bool]$Result.Data.receipt.signature_verified) "$Case signature not proved"
    Assert ([bool]$Result.Data.receipt.artifact_integrity_verified) "$Case artifact integrity not proved"
    Assert ([bool]$Result.Data.receipt.exact_baseline_verified) "$Case baseline not proved"
    Assert (-not[bool]$Result.Data.receipt.panel_activation_ready) "$Case falsely advertised panel activation"
  } else {
    Assert ($Result.Exit -eq 2) "$Case should be rejected: $($Result.Raw)"
    Assert ([string]$Result.Data.phase -eq 'REJECTED_READ_ONLY') "$Case wrong rejection phase"
  }
}

$EngineDll = (Resolve-Path -LiteralPath $EngineDll).Path
$FixtureDll = (Resolve-Path -LiteralPath $FixtureDll).Path
$checks = [System.Collections.Generic.List[string]]::new()

$root=New-Root 'signed'
$f=New-Fixture $root 'valid' 100
$r=Verify $f
Assert-ReadOnly $r 'signed baseline exact' $true
Assert-NoMutation $root 'signed baseline exact'
$checks.Add('valid_signature_artifacts_baseline_without_mutation')

foreach($variant in @('bad-signature','bad-artifact','expired','unexpected-entry','path-traversal','design-draft','for-install-unqualified')) {
  $root=New-Root $variant
  $f=New-Fixture $root $variant 101
  $r=Verify $f
  Assert-ReadOnly $r $variant $false
  Assert-NoMutation $root $variant
  $checks.Add($variant + '_no_mutation')
}

$root=New-Root 'identity-drift'
$f=New-Fixture $root 'valid' 102
$identity=Get-Content -LiteralPath $f.identity -Raw | ConvertFrom-Json
$identity.local.managed_tree_sha256=('b'*64)
$identity | ConvertTo-Json -Depth 20 | Set-Content -LiteralPath $f.identity -Encoding utf8
$r=Verify $f
Assert-ReadOnly $r 'identity-drift' $false
Assert-NoMutation $root 'identity-drift'
$checks.Add('exact_baseline_mismatch_no_mutation')

$root=New-Root 'sequence-monotonic'
$f=New-Fixture $root 'valid' 200 $true
$stageReceiptObject=$f.seeded_stage
Assert ($null -ne $stageReceiptObject) 'Isolated signed fixture failed to seed a Stage'
$sequencePath=Join-Path $root 'update/release-sequence.json'
$before=(Get-FileHash -LiteralPath $sequencePath -Algorithm SHA256).Hash
$stageRoot=[string]$stageReceiptObject.stage_root
$stageReceipt=Join-Path $stageRoot 'stage-receipt.json'
$receiptBefore=(Get-FileHash -LiteralPath $stageReceipt -Algorithm SHA256).Hash
$older=New-Fixture $root 'valid' 199
$r=Verify $older
Assert-ReadOnly $r 'sequence-rollback' $false
Assert ((Get-FileHash -LiteralPath $sequencePath -Algorithm SHA256).Hash -eq $before) 'Rejected preflight changed anti-rollback sequence'
Assert ((Get-FileHash -LiteralPath $stageReceipt -Algorithm SHA256).Hash -eq $receiptBefore) 'Rejected preflight changed staged receipt'
$checks.Add('anti_rollback_read_only_rejection')
$collision=New-Fixture $root 'valid' 200
$r=Verify $collision
Assert-ReadOnly $r 'sequence-collision' $false
Assert ((Get-FileHash -LiteralPath $sequencePath -Algorithm SHA256).Hash -eq $before) 'Sequence collision modified anti-rollback state'
$checks.Add('same_sequence_collision_read_only_rejection')

[ordered]@{
  format='sokna-f1-read-only-release-set-preflight-v1'
  result='PASS'
  checks=$checks
  count=$checks.Count
  writes_to_active_web=$false
  writes_to_stage=$false
  sequence_changed=$false
  production_qualification=$false
} | ConvertTo-Json -Depth 6
# Reset the native exit code from the last expected rejection fixture.
exit 0
