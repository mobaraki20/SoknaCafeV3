# Disposable Windows PowerShell 5.1 end-to-end original-proof verdict tests.
$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest
$repo=(Resolve-Path (Join-Path $PSScriptRoot '../..')).Path
$auditor=Join-Path $repo 'packaging/windows/scripts/collect-r125-proof-verdict-v1.ps1'
$collector=Join-Path $repo 'packaging/windows/scripts/collect-support-unified-v1.ps1'
$root=Join-Path $env:RUNNER_TEMP ('SOKNA-R125-SAFE-PROOF-QA-'+[Guid]::NewGuid().ToString('N'))
$base=Join-Path $root 'ProgramData\SOKNA'
$local=Join-Path $root 'SOKNA'
$ops=Join-Path $base 'Maintenance\operations'
$out=Join-Path $root 'output'
$report=Join-Path $out 'verdict.json'
New-Item -ItemType Directory -Path $ops,$out,(Join-Path $local 'lifecycle\local') -Force|Out-Null
function Need([bool]$ok,[string]$message){if(-not$ok){throw $message}}
function Save([string]$p,$obj){
 New-Item -ItemType Directory -Path (Split-Path -Parent $p) -Force|Out-Null
 [IO.File]::WriteAllText($p,($obj|ConvertTo-Json -Depth 25),[Text.UTF8Encoding]::new($false))
}
function Check([string]$expected){
 & powershell.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass -File $auditor -DataRoot $base -LocalRoot $local -OutputFile $report
 Need ($LASTEXITCODE-eq0) 'R125_AUDITOR_PROCESS_FAILED'
 $j=Get-Content $report -Raw|ConvertFrom-Json
 Need ($j.format-eq'sokna-r125-local-proof-verdict-v1' -and $j.authorizes_install-eq$false -and
       $j.authorizes_cleanup-eq$false -and $j.read_only-eq$true) 'R125_AUDITOR_UNSAFE_AUTHORIZATION'
 Need ($j.operation_ledger.status-eq$expected) ('R125_AUDITOR_UNEXPECTED_STATUS_'+$j.operation_ledger.status)
 return $j
}
try{
 $pkg=('c'*64);$manifest=('d'*64);$identityHash=('e'*64)
 $proofs=@()
 for($i=1;$i-le9;$i++){
  $id=('{0:x32}' -f $i)
  $p=Join-Path $ops ('preflight-'+$id+'.json')
  Save $p @{format='sokna-local-package-preflight-v1';schema_version=1;operation_id=$id;package_ref=$pkg;
    installed_identity_sha256=$identityHash;verification=@{release_set_id=('test-'+$i);sequence=$i;package_sha256=$pkg;
      manifest_sha256=$manifest;activation_performed=$false;database_mutation_performed=$false;artifact_integrity_verified=$true}}
  $proofs+=@{path=$p;id=$id}
  Save (Join-Path $ops ('op-'+$id+'.json')) @{format='sokna-maintenance-operation-v1';schema_version=1;operation_id=$id;
   operation_type='update.local-package-preflight';state='verified';result_code='PACKAGE_INTEGRITY_VERIFIED';
   package_ref=$pkg;activation_performed=$false;database_mutation_performed=$false;preflight_receipt_path=$p}
 }
 for($i=1;$i-le9;$i++){
  $id=('{0:x32}' -f (100+$i))
  $dir=Join-Path $base ('Maintenance\staging\release-sets\'+('{0:d20}' -f $i)+'-test-'+$i)
  $st=Join-Path $dir 'stage-receipt.json'
  Save $st @{format='sokna-maintenance-staged-release-v1';schema_version=1;release_set_id=('test-'+$i);sequence=$i;
    manifest_sha256=$manifest;package_sha256=$pkg;stage_root=$dir;activation_performed=$false;
    database_mutation_performed=$false;preflight_identity_match=$true;artifact_integrity_verified=$true;signature_verified=$false;operator_authorized=$true}
  Save (Join-Path $ops ('op-'+$id+'.json')) @{format='sokna-maintenance-operation-v1';schema_version=1;operation_id=$id;
    operation_type='update.local-stage';state='staged';result_code='STAGED';package_ref=$pkg;
    activation_performed=$false;database_mutation_performed=$false;preflight_receipt_path=$proofs[$i-1].path;
    stage_receipt_path=$st}
 }
 for($i=1;$i-le7;$i++){
  $id=('{0:x32}' -f (200+$i))
  Save (Join-Path $ops ('op-'+$id+'.json')) @{format='sokna-maintenance-operation-v1';schema_version=1;operation_id=$id;
   operation_type='update.local-code-only-activate';state=$(if($i-le3){'failed'}else{'committed'});package_ref=$pkg;activation_performed=$true;database_mutation_performed=$false}
 }
 $state=Join-Path $local 'lifecycle\local\state.json'
 $managed=Join-Path $local 'lifecycle\local\managed-files.json'
 $identity=Join-Path $base 'Maintenance\installed-release-identity.json'
 [IO.File]::WriteAllText($state,'{"state":"safe-disposable"}')
 [IO.File]::WriteAllText($managed,'{"managed":true}')
 [IO.File]::WriteAllText($identity,'{"local":{"version":"test"}}')
 $proof=Join-Path $base 'Maintenance\local-slots\routine-local-lifecycle-before.json'
 Save $proof @{format='sokna-routine-local-lifecycle-backup-v1';active_build='test';
  state_path=$state;managed_path=$managed;installed_identity_path=$identity;
  state_base64=[Convert]::ToBase64String([IO.File]::ReadAllBytes($state));
  managed_base64=[Convert]::ToBase64String([IO.File]::ReadAllBytes($managed));
  installed_identity_base64=[Convert]::ToBase64String([IO.File]::ReadAllBytes($identity))}
 $before=(Get-FileHash -LiteralPath $proof -Algorithm SHA256).Hash
 $ok=Check 'RECEIPTS_CROSS_CHECKED_NO_INSTALL_APPROVAL'
 Need ($ok.operation_ledger.total-eq25 -and $ok.operation_ledger.verified-eq9 -and
       $ok.operation_ledger.staged-eq9 -and $ok.operation_ledger.terminal-eq7 -and
       $ok.operation_ledger.blocking-eq0) 'R125_25_OPERATION_COUNT_OR_PROOF_FAIL'
 Need ($ok.r125.bytes_match-eq'MATCH' -and $ok.r125.live_db_ledger-eq'NOT_CHECKED' -and
       $ok.r125.isolated_db_restore-eq'NOT_CHECKED') 'R125_LIFECYCLE_READONLY_PROOF_FAIL'
 Need ((Get-FileHash -LiteralPath $proof -Algorithm SHA256).Hash-eq$before) 'AUDITOR_CHANGED_ORIGINAL_PROOF'
 $stagePath=Join-Path $base 'Maintenance\staging\release-sets\00000000000000000001-test-1\stage-receipt.json'
 $stageBytes=[IO.File]::ReadAllBytes($stagePath)
 try{
  $bad=Get-Content $stagePath -Raw|ConvertFrom-Json
  $bad.release_set_id='FORGED'
  Save $stagePath $bad
  $blocked=Check 'BLOCKED_OR_UNPROVEN'
  Need ($blocked.operation_ledger.first_blocker_code-eq'R125_HISTORICAL_STAGE_CONTENT_INVALID' -and
        $blocked.operation_ledger.first_blocker_id-eq('{0:x32}' -f 101)) 'AUDITOR_DID_NOT_LOCATE_FORGED_STAGE'
 }finally{[IO.File]::WriteAllBytes($stagePath,$stageBytes)}
 $one=Join-Path $ops ('op-'+('{0:x32}' -f 101)+'.json')
 $op=Get-Content $one -Raw|ConvertFrom-Json
 $original=[IO.File]::ReadAllBytes($one)
 try{
  $op.state='recovery_required';Save $one $op
  $active=Check 'BLOCKED_OR_UNPROVEN'
  Need ($active.operation_ledger.first_blocker_code-eq'R125_ACTIVE_OR_UNRESOLVED_UPDATE_OPERATION') 'ACTIVE_OP_NOT_BLOCKED'
 }finally{[IO.File]::WriteAllBytes($one,$original)}
 [IO.File]::WriteAllText($managed,'changed-without-authorization')
 $drift=Check 'RECEIPTS_CROSS_CHECKED_NO_INSTALL_APPROVAL'
 Need ($drift.r125.bytes_match-eq'MISMATCH') 'R125_PROOF_DRIFT_NOT_DETECTED'
 # The One Support ZIP is the single supported user-facing carrier.
 & powershell.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass -File $collector -DataRoot $base -LocalRoot $local -OutputRoot $out -SkipLegacyCollector
 Need ($LASTEXITCODE-eq0) 'R125_ONE_SUPPORT_COLLECTOR_FAILED'
 $zip=Get-ChildItem $out -Filter 'SOKNA-ONE-SUPPORT-*.zip'|Select-Object -First 1
 Need ($null-ne$zip) 'ONE_SUPPORT_ARCHIVE_MISSING'
 Add-Type -AssemblyName System.IO.Compression.FileSystem
 $z=[IO.Compression.ZipFile]::OpenRead($zip.FullName)
 try{
  $entry=@($z.Entries|Where-Object{($_.FullName -replace '\','/') -eq 'unified/r125-proof-verdict-safe.json'}|Select-Object -First 1)
  Need ($null-ne$entry) 'ONE_SUPPORT_PROOF_VERDICT_MISSING'
  $stream=$entry.Open();$reader=New-Object IO.StreamReader($stream)
  try{$payload=$reader.ReadToEnd()}finally{$reader.Dispose();$stream.Dispose()}
  $j=$payload|ConvertFrom-Json
  Need ($j.operation_ledger.total-eq25 -and $j.r125.bytes_match-eq'MISMATCH') 'ONE_SUPPORT_PROOF_VERDICT_WRONG'
  Need ($payload-notmatch 'safe-disposable|managed":true|state_base64|installed_identity_base64') 'RAW_ROLLBACK_PROOF_EXPORTED'
 }finally{$z.Dispose()}
 Write-Host 'PASS 25 actual-format operation receipts / forged / active / R125 byte drift / One Support privacy; QA only'
}finally{Remove-Item -LiteralPath $root -Recurse -Force -ErrorAction SilentlyContinue}
