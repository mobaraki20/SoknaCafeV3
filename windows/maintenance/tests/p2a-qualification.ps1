param(
  [Parameter(Mandatory=$true)][string]$EngineExe,
  [Parameter(Mandatory=$true)][string]$FixtureExe
)
$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest

function Assert([bool]$Condition,[string]$Message){if(-not$Condition){throw $Message}}
function New-Root([string]$Name){$p=Join-Path $env:RUNNER_TEMP ('sokna-p2a-'+$Name+'-'+[guid]::NewGuid().ToString('N'));New-Item -ItemType Directory -Path $p -Force|Out-Null;return $p}
function New-Fixture([string]$Root,[string]$Variant='valid',[long]$Sequence=1,[string]$ReleaseId=''){
  $args=@('--root',$Root,'--variant',$Variant,'--sequence',[string]$Sequence)
  if($ReleaseId){$args+=@('--release-id',$ReleaseId)}
  $raw=@(& $FixtureExe @args 2>&1);if($LASTEXITCODE-ne0){throw "Fixture generation failed: $($raw -join "`n")"}
  return (($raw -join "`n")|ConvertFrom-Json)
}
function Invoke-Engine($Fixture){
  $raw=@(& $EngineExe verify-stage --package ([string]$Fixture.package) --identity ([string]$Fixture.identity) --data-root ([string]$Fixture.data_root) 2>&1)
  $code=$LASTEXITCODE
  $text=$raw -join "`n"
  $json=$null
  try{$json=$text|ConvertFrom-Json}catch{}
  return [pscustomobject]@{Code=$code;Text=$text;Json=$json}
}
function Assert-Rejected($Result,[string]$Label){
  Assert ($Result.Code-eq2) "$Label should fail closed with exit 2. Output: $($Result.Text)"
  Assert ($null-ne$Result.Json) "$Label rejection must be machine-readable JSON."
  Assert (-not[bool]$Result.Json.ok) "$Label rejection must report ok=false."
  Assert ([string]$Result.Json.phase-eq'REJECTED_PRE_MUTATION') "$Label must reject before mutation."
  Assert (-not[bool]$Result.Json.activation_performed) "$Label must not activate anything."
  Assert (-not[bool]$Result.Json.database_mutation_performed) "$Label must not mutate DB."
}

$EngineExe=(Resolve-Path -LiteralPath $EngineExe).Path
$FixtureExe=(Resolve-Path -LiteralPath $FixtureExe).Path
$checks=[System.Collections.Generic.List[string]]::new()

# 1) Valid signed exact-baseline package stages and remains pre-mutation.
$root=New-Root 'valid'
$f=New-Fixture $root 'valid' 10 'release-valid-10'
$r=Invoke-Engine $f
Assert ($r.Code-eq0) "Valid Release Set did not stage: $($r.Text)"
Assert ([bool]$r.Json.ok) 'Valid stage must report ok=true.'
Assert ([string]$r.Json.phase-eq'STAGED') 'Valid stage must end in STAGED.'
Assert (-not[bool]$r.Json.activation_performed) 'P2A may not activate application files.'
Assert (-not[bool]$r.Json.database_mutation_performed) 'P2A may not mutate DB.'
$receipt=$r.Json.receipt
Assert ([bool]$receipt.signature_verified) 'Stage receipt must prove signature verification.'
Assert ([bool]$receipt.artifact_integrity_verified) 'Stage receipt must prove artifact integrity.'
Assert ([bool]$receipt.preflight_identity_match) 'Stage receipt must prove exact baseline preflight.'
Assert (Test-Path -LiteralPath ([string]$receipt.stage_root) -PathType Container) 'Final stage root missing.'
$checks.Add('valid_signed_exact_baseline_stage')

# 2) Same package is idempotent and maps to the same immutable stage.
$r2=Invoke-Engine $f
Assert ($r2.Code-eq0) "Idempotent re-stage failed: $($r2.Text)"
Assert ([string]$r2.Json.receipt.stage_root-eq[string]$receipt.stage_root) 'Idempotent stage root changed.'
Assert ([string]$r2.Json.receipt.manifest_sha256-eq[string]$receipt.manifest_sha256) 'Idempotent manifest identity changed.'
$checks.Add('idempotent_same_release_stage')

# 3) Exact baseline mismatch fails before any stage/sequence mutation.
$root=New-Root 'baseline-mismatch';$f=New-Fixture $root 'valid' 11 'release-baseline-mismatch'
$identity=Get-Content -LiteralPath $f.identity -Raw|ConvertFrom-Json
$identity.local.managed_tree_sha256=('b'*64)
$identity|ConvertTo-Json -Depth 20|Set-Content -LiteralPath $f.identity -Encoding utf8
$r=Invoke-Engine $f;Assert-Rejected $r 'baseline mismatch'
Assert (-not(Test-Path -LiteralPath (Join-Path $root 'update\release-sequence.json'))) 'Rejected baseline mismatch advanced anti-rollback state.'
$checks.Add('exact_baseline_mismatch_rejected')

# 4-9) Signature, artifact, expiry, unexpected-entry, traversal and design-only gates.
foreach($variant in @('bad-signature','bad-artifact','expired','unexpected-entry','path-traversal','design-draft','for-install-unqualified')){
  $root=New-Root $variant;$f=New-Fixture $root $variant 12 ('release-'+$variant)
  $r=Invoke-Engine $f;Assert-Rejected $r $variant
  Assert (-not(Test-Path -LiteralPath (Join-Path $root 'update\release-sequence.json'))) "$variant advanced anti-rollback state."
  $checks.Add($variant+'_rejected')
}

# A fully signed, intact exact-baseline package is valid for read-only
# verification but must NOT consume the anti-rollback high-water mark when
# for_install=false. The next install-qualified release must remain stageable.
$root=New-Root 'signed-readonly-stage-forbidden'
$f=New-Fixture $root 'signed-readonly-stage-forbidden' 14 'signed-readonly-14'
$verifyRaw=@(& $EngineExe verify-only --package ([string]$f.package) --identity ([string]$f.identity) --data-root ([string]$f.data_root) 2>&1)
Assert ($LASTEXITCODE-eq0) ('Signed test-only VerifyOnly must still pass: '+($verifyRaw -join "`n"))
$verify=($verifyRaw -join "`n")|ConvertFrom-Json
Assert ([bool]$verify.receipt.signature_verified -and [bool]$verify.receipt.artifact_integrity_verified) 'Signed test-only verification failed'
$r=Invoke-Engine $f
Assert-Rejected $r 'signed non-installable Stage'
Assert ($r.Text.Contains('RELEASE_SET_NOT_INSTALLABLE_STAGE_FORBIDDEN')) 'Missing non-installable Stage denial reason'
Assert (-not(Test-Path -LiteralPath (Join-Path $root 'update/release-sequence.json'))) 'Test-only Stage poisoned anti-rollback high-water mark'
Assert (-not(Test-Path -LiteralPath (Join-Path $root 'staging/release-sets'))) 'Test-only Stage persisted a forbidden stage'
$checks.Add('signed_verifyonly_passes_but_stage_never_consumes_sequence')

# 10) Requirement mismatch is fail-closed.
$root=New-Root 'requirement';$f=New-Fixture $root 'valid' 13 'release-requirement'
$identity=Get-Content -LiteralPath $f.identity -Raw|ConvertFrom-Json
$identity.update_engine_version='0.1.0'
$identity|ConvertTo-Json -Depth 20|Set-Content -LiteralPath $f.identity -Encoding utf8
$r=Invoke-Engine $f;Assert-Rejected $r 'minimum engine version mismatch'
$checks.Add('minimum_component_version_rejected')

# 11) Monotonic anti-rollback survives independent process executions.
$root=New-Root 'rollback';$f20=New-Fixture $root 'valid' 20 'release-seq-20';$r20=Invoke-Engine $f20
Assert ($r20.Code-eq0) "Sequence 20 failed: $($r20.Text)"
$f19=New-Fixture $root 'valid' 19 'release-seq-19';$r19=Invoke-Engine $f19;Assert-Rejected $r19 'lower sequence'
$seq=Get-Content -LiteralPath (Join-Path $root 'update\release-sequence.json') -Raw|ConvertFrom-Json
Assert ([int64]$seq.highest_sequence-eq20) 'Anti-rollback high-water mark moved backwards.'
Assert ([string]$seq.release_set_id-eq'release-seq-20') 'Anti-rollback release identity changed on rejected lower sequence.'
$checks.Add('monotonic_sequence_anti_rollback')

# 12) Same sequence cannot be reused by a different signed release.
$root=New-Root 'collision';$fa=New-Fixture $root 'valid' 30 'release-A';$ra=Invoke-Engine $fa
Assert ($ra.Code-eq0) "Initial sequence collision fixture failed: $($ra.Text)"
$fb=New-Fixture $root 'valid' 30 'release-B';$rb=Invoke-Engine $fb;Assert-Rejected $rb 'same-sequence different release'
$seq=Get-Content -LiteralPath (Join-Path $root 'update\release-sequence.json') -Raw|ConvertFrom-Json
Assert ([string]$seq.release_set_id-eq'release-A') 'Sequence collision replaced authoritative staged release.'
$checks.Add('sequence_collision_rejected')

# 13) Stage receipts remain explicitly non-activation/non-DB mutation.
$receiptFile=Join-Path ([string]$ra.Json.receipt.stage_root) 'stage-receipt.json'
$stored=Get-Content -LiteralPath $receiptFile -Raw|ConvertFrom-Json
Assert (-not[bool]$stored.activation_performed) 'Stored receipt falsely claims activation.'
Assert (-not[bool]$stored.database_mutation_performed) 'Stored receipt falsely claims DB mutation.'
$checks.Add('staging_only_boundary_persisted')

[ordered]@{
  format='sokna-maintenance-v4-windows-p2a-qualification-v1'
  result='PASS'
  update_engine_version='0.2.0'
  release_set_schema='2.1'
  signature_algorithm='ecdsa-p256-sha256'
  checks=$checks
  check_count=$checks.Count
  activation='NOT_IMPLEMENTED_P2A'
  database_mutation='NOT_IMPLEMENTED_P2A'
  apache_restart='NOT_IMPLEMENTED_P2A'
  evidence_tier='windows-2022-ci-runtime-not-real-cafe-host'
}|ConvertTo-Json -Depth 6
