param([string]$SupervisorDll,[string]$FixtureDll,[string]$LeaseProbeDll)
$ErrorActionPreference='Stop'
$PSNativeCommandUseErrorActionPreference=$false
Set-StrictMode -Version Latest
function Assert([bool]$Ok,[string]$Reason) { if(-not $Ok){throw $Reason} }
$SupervisorDll=(Resolve-Path -LiteralPath $SupervisorDll).Path
$FixtureDll=(Resolve-Path -LiteralPath $FixtureDll).Path
$LeaseProbeDll=(Resolve-Path -LiteralPath $LeaseProbeDll).Path
$root=Join-Path $env:RUNNER_TEMP ('sokna-f1-p2d-'+[guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $root -Force|Out-Null
$output=@(& dotnet $FixtureDll --root $root --variant valid --sequence 110 2>&1)
Assert ($LASTEXITCODE -eq 0) 'Fixture generation failed'
$f=($output -join [Environment]::NewLine)|ConvertFrom-Json
$inbox=Join-Path $root 'update/inbox';New-Item -ItemType Directory -Path $inbox -Force|Out-Null
$ref=[IO.Path]::GetFileName([string]$f.package)
$package=Join-Path $inbox $ref
Copy-Item -LiteralPath $f.package -Destination $package
Copy-Item -LiteralPath $f.identity -Destination (Join-Path $root 'installed-release-identity.json')
$port=18574;$base="http://127.0.0.1:$port"
$serviceKey='HKLM:\SYSTEM\CurrentControlSet\Services\SoknaApache'
Assert (-not(Test-Path -LiteralPath $serviceKey)) 'Runner has actual SoknaApache; refusing fixture'
$bin=Join-Path $root 'fake/Apache24/bin';New-Item -ItemType Directory -Path $bin -Force|Out-Null
$exe=Join-Path $bin 'httpd.exe'
Copy-Item -LiteralPath "$env:SystemRoot\System32\cmd.exe" -Destination $exe
New-Item -Path $serviceKey -Force|Out-Null
New-ItemProperty -Path $serviceKey -Name 'ImagePath' -PropertyType ExpandString -Value ('"'+$exe+'" -k runservice') -Force|Out-Null
New-ItemProperty -Path $serviceKey -Name 'ObjectName' -PropertyType String -Value 'LocalSystem' -Force|Out-Null
function Start-S {
  $p=Start-Process dotnet -ArgumentList @($SupervisorDll,'--data-root',$root,'--port',"$port") -PassThru
  $until=[DateTime]::UtcNow.AddSeconds(25)
  while([DateTime]::UtcNow -lt $until) {
    Assert (-not$p.HasExited) 'Supervisor crashed on startup'
    $aclFile=Join-Path $root 'web-adapter-acl.json'
    if((Test-Path -LiteralPath (Join-Path $root 'control-plane.json')) -and (Test-Path -LiteralPath $aclFile)) {
      try {
        $acl=Get-Content -LiteralPath $aclFile -Raw|ConvertFrom-Json
        if([string]$acl.state -eq 'ready'){return $p}
      }catch{}
    }
    Start-Sleep -Milliseconds 150
  }
  throw 'Supervisor ACL did not become ready'
}
function Stop-S($p) {
  if($null -ne $p -and -not $p.HasExited){
    Stop-Process -Id $p.Id -Force -ErrorAction SilentlyContinue
    $p.WaitForExit(5000)|Out-Null
  }
}
function Signed([string]$Method,[string]$Route,[string]$KeyId,[object]$Body,[string]$Idem=''){
  [byte[]]$bytes=[byte[]]::new(0)
  if($null -ne $Body) {
    $json=$Body|ConvertTo-Json -Compress -Depth 8
    $bytes=[Text.Encoding]::UTF8.GetBytes($json)
  }
  $timestamp=[DateTimeOffset]::UtcNow.ToString('O',[Globalization.CultureInfo]::InvariantCulture)
  $nonce=[Convert]::ToHexString([Security.Cryptography.RandomNumberGenerator]::GetBytes(16)).ToLowerInvariant()
  $digest=[Convert]::ToHexString([Security.Cryptography.SHA256]::HashData($bytes)).ToLowerInvariant()
  $canonical=[string]::Join([char]10,@($Method,$Route,'1.0',$timestamp,$nonce,$digest))
  $key=[Convert]::FromBase64String((Get-Content -LiteralPath (Join-Path $root "secrets/$KeyId.key") -Raw).Trim())
  $mac=[Security.Cryptography.HMACSHA256]::new($key)
  try{$sig=[Convert]::ToHexString($mac.ComputeHash([Text.Encoding]::UTF8.GetBytes($canonical))).ToLowerInvariant()}
  finally{$mac.Dispose()}
  $headers=@{'X-Sokna-Maintenance-Protocol'='1.0';'X-Sokna-Timestamp'=$timestamp;'X-Sokna-Nonce'=$nonce;'X-Sokna-Key-Id'=$KeyId;'X-Sokna-Body-SHA256'=$digest;'X-Sokna-Signature'=$sig}
  if($Idem){$headers['X-Sokna-Idempotency-Key']=$Idem}
  $args=@{Uri=($base+$Route);Method=$Method;Headers=$headers;SkipHttpErrorCheck=$true;TimeoutSec=12}
  if($bytes.Length){$args.Body=$bytes;$args.ContentType='application/json; charset=utf-8'}
  $response=Invoke-WebRequest @args
  return [pscustomobject]@{Code=[int]$response.StatusCode;Data=($response.Content|ConvertFrom-Json)}
}
function No-Stage {
  Assert (-not(Test-Path -LiteralPath (Join-Path $root 'update/release-sequence.json'))) 'Preflight wrote release sequence'
  Assert (-not(Test-Path -LiteralPath (Join-Path $root 'staging/release-sets'))) 'Preflight created stage root'
}
$p=$null
try {
  $p=Start-S
  # F1-S7C3: a fake or unqualified Apache registration must never
  # produce a false safe-host/activation permission. This probe is read-only.
  $identityBefore=(Get-FileHash -LiteralPath (Join-Path $root 'installed-release-identity.json') -Algorithm SHA256).Hash
  $hostReadiness=Signed 'GET' '/v1/local-updates/inactive-host-readiness' 'web-adapter' $null
  Assert ($hostReadiness.Code -eq 200 -and $hostReadiness.Data.code -eq 'HOST_UAT_AND_RELEASE_GATES_REQUIRED') 'Signed host readiness unavailable'
  $host=$hostReadiness.Data.data
  Assert (-not[bool]$host.apache_discovery_and_http_healthy -and -not[bool]$host.active_junction_qualified -and -not[bool]$host.host_preparation_preconditions_met) 'Fake SoknaApache passed real-host qualification'
  Assert (-not[bool]$host.real_host_uat_performed -and -not[bool]$host.backup_qualified -and -not[bool]$host.database_qualified -and -not[bool]$host.public_pair_qualified) 'Read-only preflight fabricated external qualification'
  Assert (-not[bool]$host.activation_allowed -and -not[bool]$host.panel_update_ready -and -not[bool]$host.activation_performed -and -not[bool]$host.database_mutation_performed -and -not[bool]$host.public_mutation_performed) 'Read-only host inspector changed mutating flags'
  Assert ($host.blockers -contains 'SOKNA_APACHE_DISCOVERY_OR_HEALTH_UNPROVEN' -and $host.blockers -contains 'REAL_HOST_UAT_EVIDENCE_REQUIRED') 'Missing host failure evidence'
  Assert ((Get-FileHash -LiteralPath (Join-Path $root 'installed-release-identity.json') -Algorithm SHA256).Hash -eq $identityBefore) 'Read-only readiness changed installed version'
  Assert (-not(Test-Path -LiteralPath (Join-Path $root 'update/release-sequence.json'))) 'Host readiness changed anti-rollback state'
  $unsigned=Invoke-WebRequest ($base+'/v1/local-updates/preflight') -Method POST -Body '{}' -ContentType 'application/json' -SkipHttpErrorCheck
  Assert ([int]$unsigned.StatusCode -eq 401) 'Unsigned POST accepted'
  $body=[ordered]@{package_ref=$ref;operator_id='user:test'}
  $forbidden=Signed 'POST' '/v1/local-updates/preflight' 'recovery-console' $body 'recovery-not-allowed'
  Assert ($forbidden.Code -eq 403) 'Recovery key gained ordinary preflight authority'
  $missing=Signed 'POST' '/v1/local-updates/preflight' 'web-adapter' $body
  Assert ($missing.Code -eq 400 -and $missing.Data.code -eq 'IDEMPOTENCY_KEY_REQUIRED') ('Missing idempotency response: HTTP '+$missing.Code+' '+($missing.Data | ConvertTo-Json -Depth 8 -Compress))
  $traversal=Signed 'POST' '/v1/local-updates/preflight' 'web-adapter' ([ordered]@{package_ref='../bad.zip';operator_id='user:test'}) 'unsafe-path'
  Assert ($traversal.Code -eq 400) 'Traversal path accepted'
  $good=Signed 'POST' '/v1/local-updates/preflight' 'web-adapter' $body 'p2d-valid-001'
  Assert ($good.Code -eq 200) ('Signed preflight rejected: '+($good.Data|ConvertTo-Json -Depth 7))
  Assert ($good.Data.data.operation.state -eq 'verified') 'Durable status not verified'
  Assert ([bool]$good.Data.data.receipt.verification.signature_verified) 'Signed proof missing'
  Assert ([bool]$good.Data.data.receipt.verification.exact_baseline_verified) 'Exact baseline proof missing'
  Assert (-not[bool]$good.Data.data.panel_update_ready) 'False panel readiness'
  $id=[string]$good.Data.operation_id
  Assert (Test-Path -LiteralPath (Join-Path $root ('operations/preflight-'+$id+'.json'))) 'Preflight receipt not persisted'
  No-Stage
  $retry=Signed 'POST' '/v1/local-updates/preflight' 'web-adapter' $body 'p2d-valid-001'
  Assert ($retry.Code -eq 200 -and $retry.Data.operation_id -eq $id -and [bool]$retry.Data.data.reconciled) 'Duplicate operation created'
  $conflict=Signed 'POST' '/v1/local-updates/preflight' 'web-adapter' ([ordered]@{package_ref=$ref;operator_id='user:other'}) 'p2d-valid-001'
  Assert ($conflict.Code -eq 409) 'Idempotency collision not rejected'
  $caps=Signed 'GET' '/v1/updates/capabilities' 'web-adapter' $null
  Assert ($caps.Code -eq 200 -and -not[bool]$caps.Data.data.panel_update_ready) 'Capability claimed panel-ready'
  Stop-S $p;$p=$null
  $p=Start-S
  $read=Signed 'GET' ('/v1/operations/'+$id) 'web-adapter' $null
  Assert ($read.Code -eq 200 -and $read.Data.data.state -eq 'verified') 'Operation lost after Supervisor restart'
  $again=Signed 'POST' '/v1/local-updates/preflight' 'web-adapter' $body 'p2d-valid-001'
  Assert ($again.Code -eq 200 -and $again.Data.operation_id -eq $id) 'Restart broke idempotency'
  $bytes=[IO.File]::ReadAllBytes($package);$bytes[$bytes.Length-12]=$bytes[$bytes.Length-12] -bxor 16;[IO.File]::WriteAllBytes($package,$bytes)
  $drift=Signed 'POST' '/v1/local-updates/preflight' 'web-adapter' $body 'p2d-valid-001'
  Assert ($drift.Code -eq 422 -and $drift.Data.data.operation.state -eq 'rejected_pre_mutation') 'Stale success returned after tampering'
  $after=Signed 'GET' ('/v1/operations/'+$id) 'web-adapter' $null
  Assert ($after.Data.data.state -eq 'rejected_pre_mutation') 'Stale durable result not revoked'
  No-Stage

  # F1-S2: Stage cannot be invoked without a verified Preflight operation.
  $unqualified=Signed 'POST' '/v1/local-updates/stage' 'web-adapter' ([ordered]@{preflight_operation_id='00000000000000000000000000000000';operator_id='user:test'}) 'p2d-unqualified'
  Assert ($unqualified.Code -eq 422) 'Stage accepted unqualified Preflight ID'
  $legacy=Signed 'POST' '/v1/update/stage' 'web-adapter' ([ordered]@{package_ref=$ref;operator_id='user:test'}) 'p2d-legacy-stage'
  Assert ($legacy.Code -eq 410 -and $legacy.Data.code -eq 'LEGACY_STAGE_ROUTE_DISABLED') 'Legacy bypass route is still active'
  No-Stage

  # Restore the original byte-identical signed package, preflight it under a
  # new durable operation ID, then Stage only that exact approved release.
  Copy-Item -LiteralPath $f.package -Destination $package -Force
  $fresh=Signed 'POST' '/v1/local-updates/preflight' 'web-adapter' $body 'p2d-for-stage'
  Assert ($fresh.Code -eq 200 -and $fresh.Data.data.operation.state -eq 'verified') 'Fresh preflight failed'
  $approvedId=[string]$fresh.Data.operation_id
  $wrongActor=Signed 'POST' '/v1/local-updates/stage' 'web-adapter' ([ordered]@{preflight_operation_id=$approvedId;operator_id='user:other'}) 'p2d-stage-wrong-actor'
  Assert ($wrongActor.Code -eq 422) 'Stage allowed wrong operator'
  $stageBody=[ordered]@{preflight_operation_id=$approvedId;operator_id='user:test'}
  # An independent process holding the machine lease must prevent canonical
  # Stage BEFORE any operation or anti-rollback state is written.
  $externalHolder=Start-Process dotnet -ArgumentList @($LeaseProbeDll,'hold',$root,'30000') -PassThru
  try {
    $until=[DateTime]::UtcNow.AddSeconds(10)
    $held=$false
    while([DateTime]::UtcNow -lt $until){
      Assert (-not$externalHolder.HasExited) 'External lease holder exited unexpectedly'
      $gatePath=Join-Path $root 'update/machine-mutation.lock'
      if(Test-Path -LiteralPath $gatePath){
        try{if((Get-Content -LiteralPath $gatePath -Raw).Contains('owner=test-owner-A')){$held=$true;break}}catch{}
      }
      Start-Sleep -Milliseconds 80
    }
    Assert $held 'Could not obtain external machine mutation lease'
    $stageBlocked=Signed 'POST' '/v1/local-updates/stage' 'web-adapter' $stageBody 'p2d-external-machine-gate'
    Assert ($stageBlocked.Code -eq 409 -and $stageBlocked.Data.code -eq 'MACHINE_MUTATION_LEASE_BUSY') 'Signed Supervisor Stage bypassed live machine-wide lease'
    Assert (-not(Test-Path -LiteralPath (Join-Path $root 'update/release-sequence.json'))) 'Blocked Stage advanced anti-rollback state'
  }
  finally {
    if(-not$externalHolder.HasExited){Stop-Process -Id $externalHolder.Id -Force -ErrorAction SilentlyContinue}
    $externalHolder.WaitForExit(5000) | Out-Null
  }
  $stage=Signed 'POST' '/v1/local-updates/stage' 'web-adapter' $stageBody 'p2d-stage-valid'
  Assert ($stage.Code -eq 200 -and $stage.Data.data.operation.state -eq 'staged') ('Canonical Stage failed: '+($stage.Data|ConvertTo-Json -Depth 8))
  $stageId=[string]$stage.Data.operation_id
  Assert ([bool]$stage.Data.data.receipt.signature_verified -and [bool]$stage.Data.data.receipt.artifact_integrity_verified) 'Staged receipt lacks signature/hash evidence'
  Assert (-not[bool]$stage.Data.data.receipt.activation_performed -and -not[bool]$stage.Data.data.receipt.database_mutation_performed) 'Stage mutated app or database'
  Assert ((Test-Path -LiteralPath (Join-Path $root 'update/release-sequence.json'))) 'Stage did not commit monotonic sequence'
  Assert (Test-Path -LiteralPath (Join-Path ([string]$stage.Data.data.receipt.stage_root) 'stage-receipt.json')) 'Stage receipt not durable'
  # F1-S7B immutable dry-run plan. A fully valid Stage still cannot
  # authorize Apache, database, Public or release activation.
  $planBody=[ordered]@{stage_operation_id=$stageId;operator_id='user:test'}
  $planLockHolder=Start-Process dotnet -ArgumentList @($LeaseProbeDll,'hold',$root,'30000') -PassThru
  try {
    $planLockUntil=[DateTime]::UtcNow.AddSeconds(10)
    $planLockHeld=$false
    while([DateTime]::UtcNow -lt $planLockUntil){
      Assert (-not$planLockHolder.HasExited) 'Plan lock holder exited unexpectedly'
      $planLockFile=Join-Path $root 'update/machine-mutation.lock'
      if(Test-Path $planLockFile){
        try{
          if((Get-Content -LiteralPath $planLockFile -Raw).Contains('owner=test-owner-A')){$planLockHeld=$true;break}
        }catch{}
      }
      Start-Sleep -Milliseconds 80
    }
    Assert $planLockHeld 'Independent machine lease for dry-run conflict not acquired'
    $blockedPlan=Signed 'POST' '/v1/local-updates/activation-plan' 'web-adapter' $planBody 'p2d-plan-contended'
    Assert ($blockedPlan.Code -eq 409 -and $blockedPlan.Data.code -eq 'MACHINE_MUTATION_LEASE_BUSY') 'Activation snapshot ignored concurrent OS mutator'
    Assert (-not(Test-Path -LiteralPath (Join-Path $root 'update/activation-plans'))) 'Blocked dry-run wrote transaction metadata'
  } finally {
    if(-not$planLockHolder.HasExited){Stop-Process -Id $planLockHolder.Id -Force -ErrorAction SilentlyContinue}
    $planLockHolder.WaitForExit(5000)|Out-Null
  }
  $dry=Signed 'POST' '/v1/local-updates/activation-plan' 'web-adapter' $planBody 'p2d-activation-plan'
  Assert ($dry.Code -eq 200 -and $dry.Data.code -eq 'ACTIVATION_DRY_RUN_BLOCKED' -and [bool]$dry.Data.data.created) ('Dry-run plan failed: '+($dry.Data|ConvertTo-Json -Depth 7))
  $dryPlan=$dry.Data.data.plan
  Assert ([bool]$dryPlan.dry_run_only -and -not[bool]$dryPlan.activation_allowed -and -not[bool]$dryPlan.activation_performed -and -not[bool]$dryPlan.database_mutation_performed -and -not[bool]$dryPlan.public_mutation_performed) 'Dry-run accidentally authorized a mutation'
  Assert ($dryPlan.public_execution_owner -eq 'public-maintenance-kernel' -and -not[bool]$dryPlan.public_release_included -and -not[bool]$dryPlan.paired_compatibility_qualified) 'Windows updater claimed ownership of Public deployment'
  Assert ($dryPlan.blockers -contains 'PUBLIC_PAIR_PROTOCOL_COMPATIBILITY_NOT_ATTESTED') 'Plan omitted compatibility gate'
  Assert ($dryPlan.steps.Count -ge 6 -and @($dryPlan.steps | Where-Object { $_.executable }).Count -eq 0) 'Dry-run contains executable steps'
  $dryId=[string]$dryPlan.plan_id
  $dryFile=Join-Path $root ('update/activation-plans/plan-'+$dryId+'.json')
  $dryMarker=Join-Path $root ('update/activation-plans/plan-'+$dryId+'.committed')
  Assert ((Test-Path -LiteralPath $dryFile) -and (Test-Path -LiteralPath $dryMarker)) 'Dry-run plan and committed marker were not durable'
  $planStatusPath='/v1/local-updates/activation-plans/'+$dryId
  $firstQuery=Signed 'GET' $planStatusPath 'web-adapter' $null
  Assert ($firstQuery.Code -eq 200 -and $firstQuery.Data.data.plan.plan_id -eq $dryId -and -not[bool]$firstQuery.Data.data.plan.activation_allowed) 'Committed plan cannot be queried independently'
  $originalDry=[IO.File]::ReadAllBytes($dryFile)
  $originalMarker=[IO.File]::ReadAllBytes($dryMarker)
  $dryHash=(Get-FileHash -LiteralPath $dryFile -Algorithm SHA256).Hash
  $repeatDry=Signed 'POST' '/v1/local-updates/activation-plan' 'web-adapter' $planBody 'p2d-activation-plan'
  Assert ($repeatDry.Code -eq 200 -and -not[bool]$repeatDry.Data.data.created -and [bool]$repeatDry.Data.data.reconciled -and $repeatDry.Data.data.plan.plan_id -eq $dryId) 'Dry-run idempotency retry was not stable'
  Assert ((Get-FileHash -LiteralPath $dryFile -Algorithm SHA256).Hash -eq $dryHash) 'Dry-run idempotent retry rewrote immutable evidence'
  $tamperPlan=Get-Content -LiteralPath $dryFile -Raw|ConvertFrom-Json
  $tamperPlan.activation_allowed=$true
  $tamperPlan|ConvertTo-Json -Depth 20|Set-Content -LiteralPath $dryFile -Encoding utf8
  $tamperedPlan=Signed 'POST' '/v1/local-updates/activation-plan' 'web-adapter' $planBody 'p2d-activation-plan'
  Assert ($tamperedPlan.Code -eq 422 -and $tamperedPlan.Data.code -eq 'ACTIVATION_PLAN_EVIDENCE_UNPROVEN') 'Tampered immutable plan was accepted'
  [IO.File]::WriteAllBytes($dryFile,$originalDry)
  Remove-Item -LiteralPath $dryMarker -Force
  $crashPlan=Signed 'POST' '/v1/local-updates/activation-plan' 'web-adapter' $planBody 'p2d-activation-plan'
  Assert ($crashPlan.Code -eq 409 -and $crashPlan.Data.code -eq 'ACTIVATION_PLAN_RECONCILIATION_REQUIRED') 'Missing commit marker was silently repaired'
  $missingCommitQuery=Signed 'GET' $planStatusPath 'web-adapter' $null
  Assert ($missingCommitQuery.Code -eq 422 -and $missingCommitQuery.Data.code -eq 'ACTIVATION_PLAN_EVIDENCE_UNPROVEN') 'Incomplete plan was exposed through the status API'
  [IO.File]::WriteAllBytes($dryMarker,$originalMarker)
  $forgedOperator=Signed 'POST' '/v1/local-updates/activation-plan' 'web-adapter' ([ordered]@{stage_operation_id=$stageId;operator_id='other:operator'}) 'p2d-activation-plan'
  Assert ($forgedOperator.Code -eq 422) 'Another operator reused a qualified Stage'

  $readinessPath='/v1/local-updates/activation-readiness/'+$stageId
  $assessment=Signed 'GET' $readinessPath 'web-adapter' $null
  Assert ($assessment.Code -eq 200 -and $assessment.Data.code -eq 'FOUNDATION_UPDATE_REQUIRED') 'Readiness did not give a fail-closed result'
  Assert ([bool]$assessment.Data.data.stage_evidence_verified -and -not[bool]$assessment.Data.data.activation_allowed -and -not[bool]$assessment.Data.data.panel_update_ready) 'Stage readiness confused valid Stage with approved Activate'
  Assert ($assessment.Data.data.blockers -contains 'RELEASE_NOT_QUALIFIED_FOR_INSTALL') 'Non-installable signed fixture was approved'
  Assert ($assessment.Data.data.blockers -contains 'BACKUP_POLICY_NOT_INSTALL_QUALIFIED') 'Missing backup policy approval not reported'
  Assert ($assessment.Data.data.blockers -contains 'MARIADB_SCHEMA_AND_CONSISTENCY_NOT_ATTESTED') 'MariaDB proof not reported'
  Assert (-not[bool]$assessment.Data.data.activation_performed -and -not[bool]$assessment.Data.data.database_mutation_performed) 'Readiness mutated running system'
  $unknownReadiness=Signed 'GET' '/v1/local-updates/activation-readiness/00000000000000000000000000000000' 'web-adapter' $null
  Assert ($unknownReadiness.Code -eq 422 -and $unknownReadiness.Data.code -eq 'ACTIVATION_STAGE_EVIDENCE_UNPROVEN') 'Missing Stage produced false activation readiness'
  $stageRetry=Signed 'POST' '/v1/local-updates/stage' 'web-adapter' $stageBody 'p2d-stage-valid'
  Assert ($stageRetry.Code -eq 200 -and $stageRetry.Data.operation_id -eq $stageId -and [bool]$stageRetry.Data.data.reconciled) 'Stage retry not idempotent'
  $secondPreflight=Signed 'POST' '/v1/local-updates/preflight' 'web-adapter' $body 'p2d-second-qualified'
  Assert ($secondPreflight.Code -eq 200) 'Second valid preflight failed'
  $secondId=[string]$secondPreflight.Data.operation_id
  Assert ($secondId -ne $approvedId) 'Different preflight requests collapsed into one operation'
  $stageConflict=Signed 'POST' '/v1/local-updates/stage' 'web-adapter' ([ordered]@{preflight_operation_id=$secondId;operator_id='user:test'}) 'p2d-stage-valid'
  Assert ($stageConflict.Code -eq 409 -and $stageConflict.Data.code -eq 'IDEMPOTENCY_CONFLICT') 'Stage allowed key reuse for different valid preflight'
  Stop-S $p;$p=$null
  $p=Start-S
  $afterRestart=Signed 'POST' '/v1/local-updates/stage' 'web-adapter' $stageBody 'p2d-stage-valid'
  Assert ($afterRestart.Code -eq 200 -and $afterRestart.Data.operation_id -eq $stageId) 'Restart Stage lost durable identity'
  $stageState=Signed 'GET' ('/v1/operations/'+$stageId) 'web-adapter' $null
  Assert ($stageState.Code -eq 200 -and $stageState.Data.data.state -eq 'staged') 'Staged state lost after restart'
  $capsAfter=Signed 'GET' '/v1/updates/capabilities' 'web-adapter' $null
  Assert ($capsAfter.Code -eq 200 -and -not[bool]$capsAfter.Data.data.panel_update_ready) 'Stage falsely enabled Activate'
  # F1-S3 deterministic power-loss fixture: the stage receipt and anti-rollback
  # high-water mark already exist, but the operation's terminal commit was lost.
  # Supervisor MUST NOT infer Stage success or repeat any mutation.
  $beforeSequenceHash=(Get-FileHash -LiteralPath (Join-Path $root 'update/release-sequence.json') -Algorithm SHA256).Hash
  $beforeStageReceiptHash=(Get-FileHash -LiteralPath (Join-Path ([string]$stage.Data.data.receipt.stage_root) 'stage-receipt.json') -Algorithm SHA256).Hash
  Stop-S $p;$p=$null
  $opFile=Join-Path $root ('operations/op-'+$stageId+'.json')
  $storedOp=Get-Content -LiteralPath $opFile -Raw | ConvertFrom-Json
  $storedOp.state='running'
  $storedOp.result_code='STAGE_RUNNING'
  $storedOp | ConvertTo-Json -Depth 20 | Set-Content -LiteralPath $opFile -Encoding utf8
  $p=Start-S
  $halted=Signed 'GET' ('/v1/operations/'+$stageId) 'web-adapter' $null
  Assert ($halted.Code -eq 200 -and $halted.Data.data.state -eq 'recovery_required' -and $halted.Data.data.result_code -eq 'STAGE_INTERRUPTED_OUTCOME_UNPROVEN') 'Restart did not quarantine interrupted Stage'
  $recovery=Signed 'GET' '/v1/local-updates/recovery-status' 'web-adapter' $null
  Assert ($recovery.Code -eq 200 -and [bool]$recovery.Data.data.blocked) 'Recovery status reported idle after interrupted Stage'
  Assert ($recovery.Data.data.stageRecoveryRequiredIds -contains $stageId) 'Recovery status omitted halted Stage id'
  Assert (-not[bool]$recovery.Data.data.panelUpdateReady -and -not[bool]$recovery.Data.data.automaticRestageEnabled) 'Recovery status falsely allowed update'
  $stageAfterCrash=Signed 'POST' '/v1/local-updates/stage' 'web-adapter' $stageBody 'p2d-stage-valid'
  Assert ($stageAfterCrash.Code -eq 503 -and $stageAfterCrash.Data.data.operation.state -eq 'recovery_required') 'Halted Stage was silently restaged'
  $newPreflight=Signed 'POST' '/v1/local-updates/preflight' 'web-adapter' $body 'p2d-after-interrupt'
  Assert ($newPreflight.Code -eq 409 -and $newPreflight.Data.code -eq 'UPDATER_OPERATION_BUSY') 'Unresolved Stage allowed a new Preflight'
  # F1-S4: none of the pre-Foundation slot owners may cross the common gate
  # while a canonical Stage is quarantined.
  $legacyMigration=Signed 'POST' '/v1/local-slots/migrate' 'web-adapter' ([ordered]@{
    expected_build='3.0.0-g6.2-workspace.231ai-browser-recovery-managed-inventory'
    expected_managed_files=497
    expected_managed_tree_sha256='b0e252b62b804c00e25c925d31ded8986fa23c3ab0d58b668eb79a79b961f56a'
    operator_id='user:test'
  }) 'p2d-legacy-migrate-during-stage'
  Assert ($legacyMigration.Code -eq 409 -and $legacyMigration.Data.code -eq 'UPDATER_OPERATION_BUSY') ('Legacy migration bypassed Stage quarantine: '+($legacyMigration.Data|ConvertTo-Json -Depth 4))
  $legacyRecovery=Signed 'POST' '/v1/local-slots/recover' 'recovery-console' ([ordered]@{operator_id='recovery:test'}) 'p2d-legacy-recover-during-stage'
  Assert ($legacyRecovery.Code -eq 409 -and $legacyRecovery.Data.code -eq 'UPDATER_OPERATION_BUSY') 'Legacy recovery bypassed Stage quarantine'
  $legacyHome=Signed 'POST' '/v1/local-slots/home-cycle' 'web-adapter' ([ordered]@{
    expected_build='3.0.0-g6.2-workspace.231ai-browser-recovery-managed-inventory'
    expected_managed_files=497
    expected_managed_tree_sha256='b0e252b62b804c00e25c925d31ded8986fa23c3ab0d58b668eb79a79b961f56a'
    operator_id='user:test'
  }) 'p2d-legacy-home-during-stage'
  Assert ($legacyHome.Code -eq 409 -and $legacyHome.Data.code -eq 'UPDATER_OPERATION_BUSY') 'Legacy home-cycle bypassed Stage quarantine'
  $activeWhileRecovering=Signed 'GET' '/v1/operations/active' 'web-adapter' $null
  Assert ($activeWhileRecovering.Code -eq 200 -and $activeWhileRecovering.Data.state -eq 'recovery_required' -and [bool]$activeWhileRecovering.Data.data.requires_recovery -and $activeWhileRecovering.Data.data.blocking_count -ge 1) 'Active endpoint hid durable recovery blocker'
  # Bootstrap Adoption Worker is an independent background mutation owner.
  # A queued adoption must NOT consume an attempt while Stage is quarantined.
  $adoptionRequestPath=Join-Path $root 'bootstrap/local-slot-adoption-request.json'
  $adoptionStatusPath=Join-Path $root 'bootstrap/local-slot-adoption-status.json'
  [ordered]@{
    format='sokna-local-slot-adoption-request-v1';schema_version=1
    request_id='p2d-background-gate';expected_build='3.0.0-g6.2-workspace.231ai-browser-recovery-managed-inventory'
    expected_managed_files=497;expected_managed_tree_sha256='b0e252b62b804c00e25c925d31ded8986fa23c3ab0d58b668eb79a79b961f56a'
    source_package_version='fixture-1.1.0';created_at_utc=[DateTimeOffset]::UtcNow.ToString('O')
  } | ConvertTo-Json -Depth 10 | Set-Content -LiteralPath $adoptionRequestPath -Encoding utf8
  [ordered]@{
    format='sokna-local-slot-adoption-status-v1';schema_version=1
    request_id='p2d-background-gate';state='pending';attempt_count=2
    last_operation_id=$null;last_code=$null;last_message=$null;next_attempt_at_utc=$null
    activation_performed=$false;database_mutation_performed=$false;public_mutation_performed=$false
    windows_reboot_required=$false;updated_at_utc=[DateTimeOffset]::UtcNow.ToString('O')
  } | ConvertTo-Json -Depth 10 | Set-Content -LiteralPath $adoptionStatusPath -Encoding utf8
  $statusBefore=(Get-FileHash -LiteralPath $adoptionStatusPath -Algorithm SHA256).Hash
  Start-Sleep -Seconds 7
  $statusAfter=(Get-FileHash -LiteralPath $adoptionStatusPath -Algorithm SHA256).Hash
  Assert ($statusBefore -eq $statusAfter) 'Bootstrap Worker mutated/consumed attempt while Stage recovery was pending'
  $stalledAdoption=Get-Content -LiteralPath $adoptionStatusPath -Raw | ConvertFrom-Json
  Assert ($stalledAdoption.state -eq 'pending' -and $stalledAdoption.attempt_count -eq 2) 'Bootstrap Worker burned retry budget behind shared gate'
  Remove-Item -LiteralPath $adoptionRequestPath -Force
  Remove-Item -LiteralPath $adoptionStatusPath -Force
  Assert ((Get-FileHash -LiteralPath (Join-Path $root 'update/release-sequence.json') -Algorithm SHA256).Hash -eq $beforeSequenceHash) 'Restart mutated anti-rollback state'
  Assert ((Get-FileHash -LiteralPath (Join-Path ([string]$stage.Data.data.receipt.stage_root) 'stage-receipt.json') -Algorithm SHA256).Hash -eq $beforeStageReceiptHash) 'Restart rewrote staged receipt'
  Stop-S $p;$p=$null
  $p=Start-S
  $stillHalted=Signed 'GET' ('/v1/operations/'+$stageId) 'web-adapter' $null
  Assert ($stillHalted.Data.data.state -eq 'recovery_required') 'Second restart cleared recovery barrier'
  # F1-S3 manual Recovery Console: first prove tampered stage and anti-rollback
  # evidence can NEVER turn a quarantined operation green.
  $recoveryBody=[ordered]@{stage_operation_id=$stageId;operator_id='recovery:operator'}
  $wrongRecoveryKey=Signed 'POST' '/v1/local-updates/reconcile-stage' 'web-adapter' $recoveryBody
  Assert ($wrongRecoveryKey.Code -eq 403 -and $wrongRecoveryKey.Data.code -eq 'RECOVERY_CONSOLE_KEY_REQUIRED') 'Web adapter gained Recovery Console privilege'
  $stageRoot=[string]$stage.Data.data.receipt.stage_root
  $manifest=Get-Content -LiteralPath (Join-Path $stageRoot 'release-set.json') -Raw | ConvertFrom-Json
  $artifact=$manifest.artifacts.PSObject.Properties | Select-Object -First 1
  $relative=[string]$artifact.Value.path
  $artifactFile=Join-Path $stageRoot ($relative.Replace('/',[IO.Path]::DirectorySeparatorChar))
  Assert (Test-Path -LiteralPath $artifactFile) 'Stage artifact fixture missing'
  [byte[]]$artifactBytes=[IO.File]::ReadAllBytes($artifactFile)
  Assert ($artifactBytes.Length -gt 0) 'Fixture artifact unexpectedly empty'
  [byte[]]$brokenArtifact=[byte[]]$artifactBytes.Clone()
  $brokenArtifact[0]=$brokenArtifact[0] -bxor 1
  [IO.File]::WriteAllBytes($artifactFile,$brokenArtifact)
  $unproven=Signed 'POST' '/v1/local-updates/reconcile-stage' 'recovery-console' $recoveryBody
  Assert ($unproven.Code -eq 422 -and $unproven.Data.code -eq 'STAGE_RECOVERY_EVIDENCE_UNPROVEN') ('Corrupt Stage was accepted: '+($unproven.Data|ConvertTo-Json -Depth 5))
  $quarantined=Signed 'GET' ('/v1/operations/'+$stageId) 'web-adapter' $null
  Assert ($quarantined.Data.data.state -eq 'recovery_required') 'Tampering cleared recovery barrier'
  $duringQuarantine=Signed 'GET' $readinessPath 'web-adapter' $null
  Assert ($duringQuarantine.Code -eq 422 -and $duringQuarantine.Data.code -eq 'ACTIVATION_STAGE_EVIDENCE_UNPROVEN') 'Interrupted/corrupt Stage received activation readiness'
  [IO.File]::WriteAllBytes($artifactFile,$artifactBytes)

  $seqPath=Join-Path $root 'update/release-sequence.json'
  $originalSequence=Get-Content -LiteralPath $seqPath -Raw
  $badSequence=$originalSequence | ConvertFrom-Json
  $badSequence.highest_sequence=[long]$badSequence.highest_sequence+1
  $badSequence | ConvertTo-Json -Depth 12 | Set-Content -LiteralPath $seqPath -Encoding utf8
  $badHighwater=Signed 'POST' '/v1/local-updates/reconcile-stage' 'recovery-console' $recoveryBody
  Assert ($badHighwater.Code -eq 422) 'Advanced anti-rollback mark falsely approved Stage recovery'
  Set-Content -LiteralPath $seqPath -Value $originalSequence -Encoding utf8 -NoNewline

  $goodRecovery=Signed 'POST' '/v1/local-updates/reconcile-stage' 'recovery-console' $recoveryBody
  Assert ($goodRecovery.Code -eq 200 -and $goodRecovery.Data.code -eq 'STAGE_RECOVERED_FROM_VERIFIED_EVIDENCE') ('Qualified Stage could not reconcile: '+($goodRecovery.Data | ConvertTo-Json -Depth 8))
  Assert ($goodRecovery.Data.data.operation.state -eq 'staged' -and [bool]$goodRecovery.Data.data.recovered) 'Verified recovery did not commit a durable staged status'
  Assert ((Get-FileHash -LiteralPath $seqPath -Algorithm SHA256).Hash -eq $beforeSequenceHash) 'Recovery changed anti-rollback sequence'
  Assert ((Get-FileHash -LiteralPath (Join-Path $stageRoot 'stage-receipt.json') -Algorithm SHA256).Hash -eq $beforeStageReceiptHash) 'Recovery changed Stage receipt'
  $againRecovery=Signed 'POST' '/v1/local-updates/reconcile-stage' 'recovery-console' $recoveryBody
  Assert ($againRecovery.Code -eq 200 -and [bool]$againRecovery.Data.data.reconciled) 'Recovery Console retry was not idempotent'
  Stop-S $p;$p=$null
  $p=Start-S
  $afterRecoveredRestart=Signed 'GET' ('/v1/operations/'+$stageId) 'web-adapter' $null
  Assert ($afterRecoveredRestart.Code -eq 200 -and $afterRecoveredRestart.Data.data.state -eq 'staged') 'Durably reconciled Stage did not survive restart'
  $afterPlanRestart=Signed 'POST' '/v1/local-updates/activation-plan' 'web-adapter' $planBody 'p2d-activation-plan'
  Assert ($afterPlanRestart.Code -eq 200 -and [bool]$afterPlanRestart.Data.data.reconciled -and -not[bool]$afterPlanRestart.Data.data.plan.activation_allowed) 'Immutable dry-run plan was lost or mutated after Supervisor restart'
  $reopenedPlan=Signed 'GET' $planStatusPath 'web-adapter' $null
  Assert ($reopenedPlan.Code -eq 200 -and $reopenedPlan.Data.data.plan.plan_id -eq $dryId -and -not[bool]$reopenedPlan.Data.data.plan.activation_allowed) 'Plan status not durable across Supervisor restart'
  $afterReconcileAssessment=Signed 'GET' $readinessPath 'web-adapter' $null
  Assert ($afterReconcileAssessment.Code -eq 200 -and -not[bool]$afterReconcileAssessment.Data.data.activation_allowed -and [bool]$afterReconcileAssessment.Data.data.stage_evidence_verified) 'Recovery produced false install permission'
  $statusAfterRecovery=Signed 'GET' '/v1/local-updates/recovery-status' 'web-adapter' $null
  Assert ($statusAfterRecovery.Code -eq 200 -and -not[bool]$statusAfterRecovery.Data.data.blocked) 'Successful Stage evidence reconciliation left a false busy state'
  $finalCaps=Signed 'GET' '/v1/updates/capabilities' 'web-adapter' $null
  Assert (-not[bool]$finalCaps.Data.data.panel_update_ready) 'Recovery prematurely enabled panel update'
  # F1-S7C: generic signed payload.bin MUST NOT be accepted as a Local Web tree.
  $genericRejected=Signed 'POST' '/v1/local-updates/prepare-inactive' 'web-adapter' ([ordered]@{
    stage_operation_id=$stageId;operator_id='user:test'
  }) 'p2d-generic-local-payload'
  Assert ($genericRejected.Code -eq 422 -and $genericRejected.Data.code -eq 'INACTIVE_LOCAL_PAYLOAD_UNQUALIFIED') 'Generic local payload gained Web promotion eligibility'
  Assert (-not(Test-Path -LiteralPath (Join-Path $root 'update/inactive-local-candidates'))) 'Generic signed bytes created an inactive candidate'

  function SignedSibling([string]$variant,[int]$sequence,[string]$keySuffix){
    $generated=@(& dotnet $FixtureDll --root $root --variant $variant --sequence "$sequence" 2>&1)
    Assert ($LASTEXITCODE -eq 0) ('Nested signed fixture failed: '+($generated -join ' '))
    $pkg=($generated -join [Environment]::NewLine)|ConvertFrom-Json
    $name=[IO.Path]::GetFileName([string]$pkg.package)
    Copy-Item -LiteralPath ([string]$pkg.package) -Destination (Join-Path $inbox $name) -Force
    $pre=Signed 'POST' '/v1/local-updates/preflight' 'web-adapter' ([ordered]@{
      package_ref=$name;operator_id='user:test'
    }) ('p2d-sibling-pre-'+$keySuffix)
    Assert ($pre.Code -eq 200 -and $pre.Data.data.operation.state -eq 'verified') ('Nested signed Preflight failed: '+($pre.Data|ConvertTo-Json -Depth 5))
    $candidateStage=Signed 'POST' '/v1/local-updates/stage' 'web-adapter' ([ordered]@{
      preflight_operation_id=[string]$pre.Data.data.operation.operation_id;operator_id='user:test'
    }) ('p2d-sibling-stage-'+$keySuffix)
    Assert ($candidateStage.Code -eq 200 -and $candidateStage.Data.data.operation.state -eq 'staged') ('Nested signed Stage failed: '+($candidateStage.Data|ConvertTo-Json -Depth 5))
    return [string]$candidateStage.Data.data.operation.operation_id
  }

  $siblingId=SignedSibling 'sibling-ready' 111 'ready'
  $candidateRequest=[ordered]@{stage_operation_id=$siblingId;operator_id='user:test'}
  $candidateRoot=Join-Path $root 'update/inactive-local-candidates'
  New-Item -ItemType Directory -Force -Path (Join-Path $candidateRoot ('local-'+$siblingId+'.__incoming.aborted-test'))|Out-Null
  $aborted=Signed 'POST' '/v1/local-updates/prepare-inactive' 'web-adapter' $candidateRequest 'p2d-inactive-ready'
  Assert ($aborted.Code -eq 409 -and $aborted.Data.code -eq 'INACTIVE_CANDIDATE_RECONCILIATION_REQUIRED') 'Interrupted extraction was silently cleaned/retried'
  Remove-Item -LiteralPath (Join-Path $candidateRoot ('local-'+$siblingId+'.__incoming.aborted-test')) -Recurse -Force
  $prepared=Signed 'POST' '/v1/local-updates/prepare-inactive' 'web-adapter' $candidateRequest 'p2d-inactive-ready'
  Assert ($prepared.Code -eq 200 -and $prepared.Data.code -eq 'PRIVATE_LOCAL_CANDIDATE_NOT_INSTALLABLE' -and [bool]$prepared.Data.data.created) ('Valid inactive ZIP was not prepared: '+($prepared.Data|ConvertTo-Json -Depth 6))
  $preparedReceipt=$prepared.Data.data.receipt
  # The generic CI Windows runner is NOT an installed SoknaApache host.
  # It must NEVER claim successful physical slot preparation based on a
  # valid private candidate without real host discovery and health.
  $noHostSlot=Signed 'POST' '/v1/local-updates/prepare-inactive-slot' 'web-adapter' ([ordered]@{
    stage_operation_id=$siblingId;operator_id='user:test'
    private_candidate_idempotency_key='p2d-inactive-ready'
  }) 'p2d-inactive-real-slot'
  Assert ($noHostSlot.Code -eq 503 -and $noHostSlot.Data.code -eq 'INACTIVE_SLOT_PREPARE_UNAVAILABLE') 'Supervisor falsely prepared a live Local slot without Apache'
  Assert (-not(Test-Path -LiteralPath (Join-Path $root 'update/inactive-slot-promotions'))) 'No-host sibling preparation wrote commit evidence'
  Assert (-not[bool]$preparedReceipt.promotion_qualified -and -not[bool]$preparedReceipt.activation_performed -and -not[bool]$preparedReceipt.database_mutation_performed -and -not[bool]$preparedReceipt.public_mutation_performed) 'Private candidate falsely authorized installation or Public'
  $candidateWeb=[string]$preparedReceipt.candidate_root
  Assert ((Test-Path (Join-Path $candidateWeb 'public/index.php')) -and (Test-Path (Join-Path $candidateWeb 'VERSION.txt'))) 'Inactive prepared tree missing required files'
  $candidateSha=(Get-FileHash -LiteralPath (Join-Path $candidateWeb 'public/index.php') -Algorithm SHA256).Hash
  $candidateAgain=Signed 'POST' '/v1/local-updates/prepare-inactive' 'web-adapter' $candidateRequest 'p2d-inactive-ready'
  Assert ($candidateAgain.Code -eq 200 -and -not[bool]$candidateAgain.Data.data.created -and [bool]$candidateAgain.Data.data.reconciled) 'Prepared candidate was silently overwritten or non-idempotent'
  $candidateFile=Join-Path $candidateWeb 'public/index.php'
  $candidateBytes=[IO.File]::ReadAllBytes($candidateFile)
  [IO.File]::WriteAllText($candidateFile,'tampered')
  $tamperedCandidate=Signed 'POST' '/v1/local-updates/prepare-inactive' 'web-adapter' $candidateRequest 'p2d-inactive-ready'
  Assert ($tamperedCandidate.Code -eq 422 -and $tamperedCandidate.Data.code -eq 'INACTIVE_LOCAL_PAYLOAD_UNQUALIFIED') 'Tampered candidate passed full tree attestation'
  [IO.File]::WriteAllBytes($candidateFile,$candidateBytes)
  Assert ((Get-FileHash -LiteralPath $candidateFile -Algorithm SHA256).Hash -eq $candidateSha) 'Could not restore private fixture'
  $otherKeyCandidate=Signed 'POST' '/v1/local-updates/prepare-inactive' 'web-adapter' $candidateRequest 'p2d-inactive-conflict'
  Assert ($otherKeyCandidate.Code -eq 409 -and $otherKeyCandidate.Data.code -eq 'INACTIVE_CANDIDATE_CONFLICT_OR_TAMPER') 'Second idempotency key silently took ownership'

  foreach($case in @(
    @{name='sibling-zip-traversal';seq=112;key='traversal'},
    @{name='sibling-zip-overlay';seq=113;key='overlay'},
    @{name='sibling-corrupt-tree';seq=114;key='tree'}))
  {
    $badId=SignedSibling $case.name ([int]$case.seq) ([string]$case.key)
    $bad=Signed 'POST' '/v1/local-updates/prepare-inactive' 'web-adapter' ([ordered]@{
      stage_operation_id=$badId;operator_id='user:test'
    }) ('p2d-bad-private-'+$case.key)
    Assert ($bad.Code -eq 422 -and $bad.Data.code -eq 'INACTIVE_LOCAL_PAYLOAD_UNQUALIFIED') ('Unsafe signed candidate accepted: '+$case.name)
  }
  Assert (-not[bool]$finalCaps.Data.data.panel_update_ready) 'Private preparation enabled release'
  # Reset the comparison baseline: preparing additional signed Release Sets
  # advances only the Stage anti-rollback high water mark by design.
  $beforeSequenceHash=(Get-FileHash -LiteralPath $seqPath -Algorithm SHA256).Hash
  # F1-S6C: a machine Job child may die mid-migration. Startup must NOT
  # treat its durable running operation as success or as an idle updater.
  $seedOutput=@(& dotnet $LeaseProbeDll seed-interrupted-migration $root 2>&1)
  Assert ($LASTEXITCODE -eq 0 -and (($seedOutput -join ' ') -match 'INTERRUPTED_MIGRATION_OPERATION ([0-9a-f]{32})')) ('Could not seed crashed migration: '+($seedOutput -join ' '))
  $interruptedMigrationId=[string]$Matches[1]
  Stop-S $p;$p=$null
  $p=Start-S
  $quarantinedMigration=Signed 'GET' ('/v1/operations/'+$interruptedMigrationId) 'web-adapter' $null
  Assert ($quarantinedMigration.Code -eq 200 -and $quarantinedMigration.Data.data.state -eq 'recovery_required' -and $quarantinedMigration.Data.data.result_code -eq 'SLOT_ENGINE_PARENT_INTERRUPTED_OUTCOME_UNPROVEN') 'Crashed Engine operation was not quarantined on Supervisor restart'
  $busyMigration=Signed 'GET' '/v1/operations/active' 'web-adapter' $null
  Assert ($busyMigration.Data.data.requires_recovery -and $busyMigration.Data.data.blocking_count -ge 1) 'Crashed migration was falsely reported idle'
  Assert ((Get-FileHash -LiteralPath $seqPath -Algorithm SHA256).Hash -eq $beforeSequenceHash) 'Migration startup quarantine unexpectedly wrote anti-rollback state'
  [ordered]@{format='sokna-f1-s3-manual-stage-evidence-recovery-v1';result='PASS';preflight_operation_id=$approvedId;stage_operation_id=$stageId;interrupted_stage_quarantined=$true;corrupt_stage_rejected=$true;advanced_highwater_rejected=$true;recovery_console_only=$true;explicit_stage_reconciled=$true;legacy_running_crash_quarantined=$true;no_restage=$true;no_activation=$true;no_db_mutation=$true;panel_ready=$false}|ConvertTo-Json
}
finally {
  Stop-S $p
  if(Test-Path -LiteralPath $serviceKey){Remove-Item -LiteralPath $serviceKey -Recurse -Force}
}
exit 0
