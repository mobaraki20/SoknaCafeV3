# Disposable Windows fixture for P0 support bridge. Does not touch real SOKNA.
$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest
$repo=(Resolve-Path (Join-Path $PSScriptRoot '../..')).Path
$source=Join-Path $repo 'packaging/windows/scripts/collect-support-v3.ps1'
$workspace=Join-Path $env:TEMP ('SOKNA-P0-WINDOWS-FIXTURE-'+[Guid]::NewGuid().ToString('N'))
$scripts=Join-Path $workspace 'scripts'
New-Item -ItemType Directory -Path $scripts -Force|Out-Null
try {
  Copy-Item -LiteralPath $source -Destination (Join-Path $scripts 'collect-support-v3.ps1')
  @'
param([string]$DataRoot,[string]$OutputRoot,[int]$InstallerTransactions=5)
$payload=Join-Path $OutputRoot 'v2-payload'
New-Item -ItemType Directory -Path $payload -Force|Out-Null
[IO.File]::WriteAllText((Join-Path $payload 'support-v2-summary.json'),'{"format":"fixture-v2"}')
Compress-Archive -Path (Join-Path $payload '*') -DestinationPath (Join-Path $OutputRoot 'SOKNA-Support-v2-fixture.zip') -Force
'@ | Set-Content -LiteralPath (Join-Path $scripts 'collect-support-v2-core.ps1') -Encoding UTF8
  @'
param([string]$BundleRoot)
[IO.File]::WriteAllText((Join-Path $BundleRoot 'TECHNICIAN-SUMMARY.txt'),'fixture')
[IO.File]::WriteAllText((Join-Path $BundleRoot 'TECHNICIAN-REPORT.html'),'<html>fixture</html>')
[IO.File]::WriteAllText((Join-Path $BundleRoot 'technician-findings.json'),'{"format":"fixture-findings"}')
'@ | Set-Content -LiteralPath (Join-Path $scripts 'write-technician-report-v1.ps1') -Encoding UTF8
  @'
param([string]$OutputRoot,[string]$DataRoot,[switch]$SkipLegacyCollector)
if(-not $SkipLegacyCollector){throw 'Recursion protection failed'}
$root=Join-Path $OutputRoot 'fixture'
$path=Join-Path $root 'unified'
New-Item -ItemType Directory -Path $path -Force|Out-Null
$hostResult=[ordered]@{
 format='sokna-host-readiness-safe-v1';evidence_available=$true
 status='LIVE_READ_ONLY_ENGINE_INSPECTION'
 blocking_code='LOCAL_HOST_PRECONDITIONS_UNPROVEN'
 blockers=@('ACTIVE_R8_JUNCTION_UNQUALIFIED','UNSAFE_MALICIOUS_CODE')
 host_preparation_preconditions_met=$false
 apache_healthy=$true
 active_junction_qualified=$false
 installed_identity_and_tree_qualified=$true
 machine_binding_qualified=$true
 persistent_overlay_qualified=$true
 local_lifecycle_idle=$true
 mutation_ledger_idle=$true
 unsafe_raw_path='C:\sensitive\never-copy'
}
$v=[ordered]@{format='sokna-physical-component-versions-v1'
 supervisor=@{present=$true;sha256=('a'*64);version='0.3.22';private_path='C:\sensitive'}
 update_engine=@{present=$true;sha256=('b'*64);version='0.3.22'}
}
$hostResult|ConvertTo-Json -Depth 10|Set-Content -LiteralPath (Join-Path $path 'maintenance-host-readiness-safe.json') -Encoding UTF8
$v|ConvertTo-Json -Depth 10|Set-Content -LiteralPath (Join-Path $path 'maintenance-physical-versions.json') -Encoding UTF8
Compress-Archive -Path (Join-Path $root '*') -DestinationPath (Join-Path $OutputRoot 'SOKNA-ONE-SUPPORT-fixture.zip') -Force
'@ | Set-Content -LiteralPath (Join-Path $scripts 'collect-support-unified-v1.ps1') -Encoding UTF8
  function TestBundle([string]$kind) {
    $out=Join-Path $workspace $kind
    New-Item -ItemType Directory -Path $out -Force|Out-Null
    & (Join-Path $scripts 'collect-support-v3.ps1') -DataRoot $workspace -OutputRoot $out|Out-Null
    $archive=Get-ChildItem -LiteralPath $out -Filter 'SOKNA-Support-v2-*.zip' -File|Select-Object -First 1
    if($null-eq$archive){throw ('No ZIP in '+$kind)}
    $ex=Join-Path $out 'extracted'
    Expand-Archive -LiteralPath $archive.FullName -DestinationPath $ex -Force
    $observedHost=Get-Content -LiteralPath (Join-Path $ex 'maintenance/host-readiness-safe.json') -Raw|ConvertFrom-Json
    $versions=Get-Content -LiteralPath (Join-Path $ex 'maintenance/physical-versions-safe.json') -Raw|ConvertFrom-Json
    if($kind-eq'positive'){
      if($observedHost.evidence_available-ne$true){throw 'No real readiness flag from fixture'}
      if($observedHost.blockers.Count-ne1 -or $observedHost.blockers[0]-ne'ACTIVE_R8_JUNCTION_UNQUALIFIED'){throw 'Whitelist leaked or lost reason'}
      if($versions.supervisor.sha256-ne('a'*64)){throw 'Binary identity not preserved'}
      if(($observedHost|ConvertTo-Json -Depth 10)-match 'sensitive|UNSAFE_MALICIOUS_CODE'){throw 'Private diagnostic escaped whitelist'}
      if(($versions|ConvertTo-Json -Depth 10)-match 'sensitive'){throw 'Private binary path leaked'}
    }else{
      if($observedHost.evidence_available-ne$false -or $observedHost.status-ne'INSPECTION_UNAVAILABLE'){throw 'Missing inspector reported as success'}
    }
    Write-Host ('PASS P0 support '+$kind)
  }
  TestBundle 'positive'
  Remove-Item -LiteralPath (Join-Path $scripts 'collect-support-unified-v1.ps1') -Force
  TestBundle 'negative'
  Write-Host 'PASS P0 Windows support bridge fixture (safe, read-only)'
}finally {
  Remove-Item -LiteralPath $workspace -Recurse -Force -ErrorAction SilentlyContinue
}

# Successful positive and negative assertions should not inherit the
# nonzero exit code of a harmless sc.exe query on a disposable CI runner.
$global:LASTEXITCODE=0
