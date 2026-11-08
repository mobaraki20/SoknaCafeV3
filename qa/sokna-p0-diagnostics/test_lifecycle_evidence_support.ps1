# Disposable Windows fixtures for the actual One Support collector.
# Reads synthetic files only. No process/service/database mutation.
$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest
$repo=(Resolve-Path (Join-Path $PSScriptRoot '../..')).Path
$collector=Join-Path $repo 'packaging/windows/scripts/collect-support-unified-v1.ps1'
$root=Join-Path $env:TEMP ('SOKNA-P0-R125-LIFECYCLE-QA-'+[Guid]::NewGuid().ToString('N'))
$base=Join-Path $root 'host'
$slot=Join-Path $base 'Maintenance\local-slots'
$out=Join-Path $root 'out'
New-Item -ItemType Directory -Path $slot,$out -Force|Out-Null
try {
  $version='3.0.0-g6.2-workspace.231dg-r112-guest-safe-append-uat'
  $identity=[ordered]@{
    local=[ordered]@{version=$version;release_id='sokna-r112-guest-safe-append-uat-20261105'
                    ;managed_tree_sha256=('a'*64);schema_fingerprint='f'
                    ;migration_head='0076_late_served_accounting'}
    maintenance_supervisor_version='0.3.22';update_engine_version='0.3.22'
  }
  $identity|ConvertTo-Json -Depth 8|Set-Content -LiteralPath (Join-Path $base 'Maintenance/installed-release-identity.json') -Encoding UTF8
  $lifecycle=[ordered]@{format='sokna-local-slot-routine-state-v1'
    phase='RECOVERED_TO_PREVIOUS_SLOT';recovery_required=$false;current_build=$version
    previous_slot='D:\PRIVATE_HOST_PATH_NOT_FOR_EXPORT';events=@()}
  $lifecycle|ConvertTo-Json -Depth 8|Set-Content -LiteralPath (Join-Path $slot 'routine-state.json') -Encoding UTF8
  $proof=[ordered]@{
    format='sokna-routine-local-lifecycle-backup-v1'
    active_build=$version
    state_path='C:\PRIVATE_IDENTITY_UNSAFE_PATH'
    state_base64=[Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes('credential=very_private_fixture'))
  }
  $proofPath=Join-Path $slot 'routine-local-lifecycle-before.json'
  $proof|ConvertTo-Json -Depth 8|Set-Content -LiteralPath $proofPath -Encoding UTF8
  function Check($name,$expected){
    & $collector -DataRoot $base -OutputRoot $out -SkipLegacyCollector 6>$null|Out-Null
    $z=Get-ChildItem -LiteralPath $out -Filter 'SOKNA-ONE-SUPPORT-*.zip' -File|Sort-Object LastWriteTimeUtc -Descending|Select-Object -First 1
    if($null-eq$z){throw ('Collector failed: '+$name)}
    $ex=Join-Path $root ('extract-'+$name)
    Expand-Archive -LiteralPath $z.FullName -DestinationPath $ex -Force
    $result=Get-Content -LiteralPath (Join-Path $ex 'unified/local-lifecycle-reconciliation-safe.json') -Raw|ConvertFrom-Json
    if([string]$result.assessment-ne$expected){throw ('Unexpected assessment: '+$name+', '+$result.assessment)}
    if($result.automatic_reconciliation_authorized-ne$false -or $result.live_db_ledger_validated-ne$false){
      throw 'An unqualified diagnostic appears to authorize mutation'
    }
    if((Get-Content -LiteralPath (Join-Path $ex 'unified/local-lifecycle-reconciliation-safe.json') -Raw) -match
        'PRIVATE|very_private|credential=|state_base64|D:\\'){
      throw 'Private lifecycle/identity fields leaked in exported diagnostic'
    }
    Write-Host ('PASS '+$name)
    # Prevent second-resolution archive filename reuse.
    Remove-Item -LiteralPath $z.FullName -Force
    return $result
  }
  $before=(Get-FileHash -LiteralPath $proofPath -Algorithm SHA256).Hash
  $positive=Check 'recovered-with-proof' 'POSSIBLE_R125_STALE_PROOF__FURTHER_VALIDATION_REQUIRED'
  if($positive.backup_proof_present -ne $true -or
     $positive.external_recovery_settled_marker -ne $true -or
     $positive.backup_proof_header_matches_active -ne $true){
    throw 'Recovered proof not detected'
  }
  if((Get-FileHash -LiteralPath $proofPath -Algorithm SHA256).Hash-ne$before){
    throw 'Read-only collector changed source proof'
  }
  $proof.active_build='mismatched-build'
  $proof|ConvertTo-Json -Depth 8|Set-Content -LiteralPath $proofPath -Encoding UTF8
  $bad=Check 'wrong-proof-build' 'PROOF_PRESENT__ORIGIN_OR_RECOVERY_UNPROVEN'
  if($bad.backup_proof_header_matches_active-ne$false){throw 'Mismatched build accepted'}
  Remove-Item -LiteralPath $proofPath -Force
  $none=Check 'proof-absent' 'NO_ROLLBACK_PROOF_OBSERVED__CHECK_OTHER_LIFECYCLE_CONDITIONS'
  if($none.backup_proof_present-ne$false){throw 'False proof'}
  Write-Host 'PASS P0 R125 read-only lifecycle evidence 3 fixture scenarios'
} finally {
  Remove-Item -LiteralPath $root -Recurse -Force -ErrorAction SilentlyContinue
}
$global:LASTEXITCODE=0
