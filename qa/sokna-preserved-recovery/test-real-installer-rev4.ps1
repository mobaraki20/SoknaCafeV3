param([string]$Installer,[string]$ComponentManifest,[string]$BaselineRoot,[string]$Old0323Installer,[string]$Old0324Installer,[string]$Old0325Installer,[string]$Output,[string]$LegacyRecovery011)
$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest
if($env:GITHUB_ACTIONS-ne'true' -or $env:RUNNER_OS-ne'Windows'){throw 'DISPOSABLE_WINDOWS_ONLY'}
if(Get-Service SoknaMaintenanceSupervisor -ErrorAction SilentlyContinue){throw 'REFUSE_EXISTING_SUPERVISOR'}
$data=Join-Path ([Environment]::GetFolderPath('CommonApplicationData')) 'SOKNA'
if(Test-Path $data){throw 'REFUSE_EXISTING_SOKNA_DATA'}
. ./packaging/windows/scripts/maintenance-signed-health-v1.ps1
function NewHistoricalOperation([string]$id,[string]$kind,[string]$state,[string]$result,[string]$preflightPath,[string]$stagePath,[bool]$activated){
 $pkg=('c'*64)
 $operator='qa-ledger'
 $canonical=$kind.ToLowerInvariant()+[char]10+$pkg+[char]10+$operator
 $sha=[Security.Cryptography.SHA256]::Create()
 try{$requestHash=([BitConverter]::ToString($sha.ComputeHash([Text.Encoding]::UTF8.GetBytes($canonical))).Replace('-','')).ToLowerInvariant()}
 finally{$sha.Dispose()}
 $when=[DateTimeOffset]::UtcNow.ToString('O')
 return @{
  format='sokna-maintenance-operation-v1';schema_version=1;operation_id=$id;
  idempotency_key=('qa-ledger-'+$id);canonical_request_sha256=$requestHash;
  operation_type=$kind;operator_id=$operator;state=$state;package_ref=$pkg;
  created_at_utc=$when;updated_at_utc=$when;attempt_count=1;
  result_code=$result;result_message='Disposable QA only';
  stage_receipt_path=$stagePath;preflight_receipt_path=$preflightPath;
  activation_performed=$activated;database_mutation_performed=$false
 }
}
function AddHistoricalLedger([string]$data,[string]$fault=''){
 $ops=Join-Path $data 'Maintenance\operations'
 New-Item -ItemType Directory -Path $ops -Force|Out-Null
 $pkg=('c'*64);$manifest=('d'*64);$identity=('e'*64)
 $preflights=@()
 for($i=1;$i-le9;$i++){
  $id=('{0:x32}' -f $i)
  $proofPath=Join-Path $ops ('preflight-'+$id+'.json')
  $verify=@{release_set_id=('test-'+$i);sequence=$i;package_sha256=$pkg;manifest_sha256=$manifest;
    activation_performed=$false;database_mutation_performed=$false;artifact_integrity_verified=$true}
  @{format='sokna-local-package-preflight-v1';schema_version=1;operation_id=$id;
    package_ref=$pkg;installed_identity_sha256=$identity;verification=$verify}|
    ConvertTo-Json -Depth 12|Set-Content -LiteralPath $proofPath
  $op=NewHistoricalOperation $id 'update.local-package-preflight' 'verified' 'PACKAGE_INTEGRITY_VERIFIED' $proofPath $null $false
  $op|ConvertTo-Json -Depth 12|Set-Content -LiteralPath (Join-Path $ops ('op-'+$id+'.json'))
  $preflights+=@{id=$id;path=$proofPath}
 }
 for($i=1;$i-le9;$i++){
  $id=('{0:x32}' -f (100+$i))
  $stageDir=Join-Path $data ('Maintenance\staging\release-sets\'+('{0:d20}'-f $i)+'-test-'+$i)
  New-Item -ItemType Directory -Path $stageDir -Force|Out-Null
  $stagePath=Join-Path $stageDir 'stage-receipt.json'
  @{format='sokna-maintenance-staged-release-v1';schema_version=1;release_set_id=('test-'+$i);
    sequence=$i;manifest_sha256=$manifest;package_sha256=$pkg;stage_root=$stageDir;
    activation_performed=$false;database_mutation_performed=$false;preflight_identity_match=$true;
    artifact_integrity_verified=$true;signature_verified=$false;operator_authorized=$true}|
    ConvertTo-Json -Depth 12|Set-Content -LiteralPath $stagePath
  $stageOp=NewHistoricalOperation $id 'update.local-stage' 'staged' 'STAGED' $preflights[$i-1].path $stagePath $false
  $stageOp|ConvertTo-Json -Depth 12|Set-Content -LiteralPath (Join-Path $ops ('op-'+$id+'.json'))
 }
 for($i=1;$i-le7;$i++){
  $id=('{0:x32}' -f (200+$i))
  $s=if($i-le3){'failed'}else{'committed'}
  $activation=NewHistoricalOperation $id 'update.local-code-only-activate' $s 'QA_HISTORICAL_ACTIVATION' $null $null $true
  $activation|ConvertTo-Json -Depth 12|Set-Content -LiteralPath (Join-Path $ops ('op-'+$id+'.json'))
 }
 $stagedId=('{0:x32}' -f 101)
 $stagedPath=Join-Path $ops ('op-'+$stagedId+'.json')
 $staged=Get-Content $stagedPath -Raw|ConvertFrom-Json
 switch($fault){
  'mutated'{$staged.database_mutation_performed=$true}
  'wrong-kind'{$staged.operation_type='update.local-code-only-activate'}
  'wrong-result'{$staged.result_code='NOT_STAGED'}
  'missing-proof'{Remove-Item -LiteralPath $staged.stage_receipt_path -Force}
  'foreign-proof'{$staged.stage_receipt_path=Join-Path $ops 'stage-receipt.json'}
  'unknown-state'{$staged.state='future_state'}
  'active-state'{$staged.state='recovery_required'}
 }
 $staged|ConvertTo-Json -Depth 12|Set-Content -LiteralPath $stagedPath
 return [pscustomobject]@{FirstStageReceipt=(Join-Path $data 'Maintenance\staging\release-sets\00000000000000000001-test-1\stage-receipt.json')}
}

$names=@('Maintenance/App/SoknaMaintenanceSupervisor.exe','Maintenance/App/SoknaMaintenanceUpdateEngine.exe','Maintenance/Recovery/SoknaMaintenanceRecoveryConsole.exe')
$projects=@('Sokna.Maintenance.Supervisor','Sokna.Maintenance.UpdateEngine','Sokna.Maintenance.RecoveryConsole')
$target=Get-Content $ComponentManifest -Raw|ConvertFrom-Json
$installerHash=(Get-FileHash $Installer -Algorithm SHA256).Hash.ToLowerInvariant()
function Hash([string]$p){(Get-FileHash -LiteralPath $p -Algorithm SHA256).Hash.ToLowerInvariant()}
function Need([bool]$ok,[string]$why){if(-not$ok){throw $why}}
function InvokeInstaller([string]$p){
 $proc=Start-Process -FilePath $p -ArgumentList @('/VERYSILENT','/SUPPRESSMSGBOXES','/NORESTART','/SP-') -PassThru
 if(-not$proc.WaitForExit(180000)){$proc.Kill();throw 'INSTALLER_TIMEOUT'}
 return $proc.ExitCode
}
$results=@()
Need ((Hash $LegacyRecovery011)-eq'368ea590a52b895d409d0bbc23926eebd58f512ac73a75dffac8163e5dc682dd') 'EXACT_011_MANDATORY'
$pv=[Diagnostics.FileVersionInfo]::GetVersionInfo($LegacyRecovery011)
Need ("$($pv.FileMajorPart).$($pv.FileMinorPart).$($pv.FileBuildPart).$($pv.FilePrivatePart)"-eq'0.1.1.0') 'PHYSICAL_011_MANDATORY'
foreach($case in @('0.3.22-preserved011','0.3.22-preserved011-variant','0.3.22','0.3.23','0.3.24','0.3.25')){
 $preserved011=$case-like'0.3.22-preserved011*'
 $opaqueRecoveryVariant=$case-eq'0.3.22-preserved011-variant'
 $baseline=if($preserved011){'0.3.22'}else{$case}
 $folder=if($baseline-eq'0.3.22'){'آزمون سرویس خانه'}else{'service-cafe-lab'}
 $install=Join-Path $env:RUNNER_TEMP ($folder+'-'+$case)
 New-Item -ItemType Directory -Path $install,(Join-Path $data 'Maintenance/operations'),(Join-Path $data 'setup') -Force|Out-Null
 foreach($i in 0..2){
  $to=Join-Path $install $names[$i]
  New-Item -ItemType Directory -Path (Split-Path -Parent $to) -Force|Out-Null
  Copy-Item (Join-Path $BaselineRoot ($projects[$i]+'/'+[IO.Path]::GetFileName($to))) $to
 }
 foreach($rel in @('Runtime/SoknaRuntimeService.exe','PrintAgent/Service/Sokna.PrintAgent.Service.exe')){
  $to=Join-Path $install $rel
  New-Item -ItemType Directory -Path (Split-Path -Parent $to) -Force|Out-Null
  Set-Content $to 'UNTOUCHED_CI_COMPONENT'
 }
 if($preserved011){
  $oldRecovery=Join-Path $install $names[2]
  Copy-Item $LegacyRecovery011 $oldRecovery -Force
  if($opaqueRecoveryVariant){
   # Simulate a different retained 0.1.1 binary, without executing it.
   # A benign trailing PE overlay changes only the hash, not the PE version.
   $extra=[Text.Encoding]::ASCII.GetBytes('SOKNA-CI-OPAQUE-PRESERVED-RECOVERY-011-DIFFERENT-HASH')
   $stream=[IO.File]::Open($oldRecovery,[IO.FileMode]::Append,[IO.FileAccess]::Write,[IO.FileShare]::None)
   try{$stream.Write($extra,0,$extra.Length);$stream.Flush($true)}finally{$stream.Dispose()}
   Need ((Hash $oldRecovery)-ne(Hash $LegacyRecovery011)) 'ALTERNATE_RECOVERY_SHA_NOT_DIFFERENT'
   $pvAlt=[Diagnostics.FileVersionInfo]::GetVersionInfo($oldRecovery)
   Need ("$($pvAlt.FileMajorPart).$($pvAlt.FileMinorPart).$($pvAlt.FileBuildPart).$($pvAlt.FilePrivatePart)"-eq'0.1.1.0') 'ALTERNATE_RECOVERY_PE_VERSION_CHANGED'
   Write-Host ('QA_ALTERNATE_011_SHA256='+(Hash $oldRecovery))
  }
 }
 $identity=Join-Path $data 'Maintenance/installed-release-identity.json'
 @{maintenance_supervisor_version='0.3.22';update_engine_version='0.3.22';local=@{version='synthetic-R112';keep='local'};public=@{version='synthetic-public';keep='public'}}|
  ConvertTo-Json -Depth 8|Set-Content $identity
 @{format='sokna-maintenance-only-component-receipt-v1';version='0.3.22';supervisor_sha256=(Hash (Join-Path $install $names[0]));engine_sha256=(Hash (Join-Path $install $names[1]))}|
  ConvertTo-Json|Set-Content (Join-Path $data 'setup/maintenance-only-component-receipt-v1.json')
 $exe=Join-Path $install $names[0]
 New-Service -Name SoknaMaintenanceSupervisor -BinaryPathName ('"'+$exe+'"') -DisplayName 'SOKNA disposable installer QA' -StartupType Automatic|Out-Null
 try{
  Start-Service SoknaMaintenanceSupervisor
  Assert-MaintenanceSignedHealth -ExpectedVersion '0.3.22' -Attempts 40
  if($baseline-eq'0.3.23'){
   Need ((InvokeInstaller $Old0323Installer)-eq0) 'PUBLISHED_0323_BOOTSTRAP_FAILED'
   Assert-MaintenanceSignedHealth -ExpectedVersion '0.3.23' -Attempts 40
  }
  if($baseline-eq'0.3.24'){
   Need ((InvokeInstaller $Old0324Installer)-eq0) 'BUILT_0324_BOOTSTRAP_FAILED'
   Assert-MaintenanceSignedHealth -ExpectedVersion '0.3.24' -Attempts 40
  }
  if($baseline-eq'0.3.25'){
   Need ((InvokeInstaller $Old0325Installer)-eq0) 'BUILT_0325_BOOTSTRAP_FAILED'
   Assert-MaintenanceSignedHealth -ExpectedVersion '0.3.25' -Attempts 40
  }
  # Read the actual baseline after any 0323/0324/0325 bootstrap completed.
  $originalRecoveryHash=Hash (Join-Path $install $names[2])
  $legacyBefore=$null
  if($baseline-eq'0.3.22'){
   . ./packaging/windows/scripts/maintenance-installed-versions-v1.ps1
   $legacy=Get-Content $identity -Raw|ConvertFrom-Json
   $legacy.maintenance_supervisor_version='0.3.21';$legacy.update_engine_version='0.3.21'
   $legacy|ConvertTo-Json -Depth 20|Set-Content $identity
   $legacyBefore=Join-Path $install 'legacy-before-sync.json';Copy-Item $identity $legacyBefore
   $sync=Join-Path $data ('setup/component-transactions/maintenance-'+[Guid]::NewGuid().ToString('N'))
   Sync-MaintenanceInstalledVersions -AppRoot (Join-Path $install 'Maintenance/App') -EvidenceRoot $sync
  }elseif($baseline-eq'0.3.23'){
   $oldMarker=Get-Content (Join-Path $data 'setup/r125-0323-three-component/active.json') -Raw|ConvertFrom-Json
   $legacyBefore=Join-Path $data ('setup/r125-0323-three-component/tx-'+$oldMarker.id+'/before/installed-release-identity.json')
  }
  if($legacyBefore){
   dotnet run --project qa/sokna-r129-compat/CompatProbe.csproj -c Release -- verify-installed-maintenance-identity $legacyBefore (Join-Path $data 'Maintenance')
   Need ($LASTEXITCODE-eq0) 'ACTUAL_LEGACY_IDENTITY_RECEIPT_REJECTED'
  }
  $externalFiles=@{}
  if($baseline-in@('0.3.22','0.3.23')){
   foreach($rel in @('Runtime/SoknaRuntimeService.exe','PrintAgent/Service/Sokna.PrintAgent.Service.exe')){
    Move-Item (Join-Path $install $rel) ((Join-Path $install $rel)+'.qa-preserved')
   }
   if($baseline-eq'0.3.22'){
    foreach($name in @('SoknaRuntime','SoknaPrintWorker')){
     Need (-not(Get-Service $name -ErrorAction SilentlyContinue)) 'REFUSE_EXISTING_UNRELATED_SERVICE'
     $external=Join-Path $env:RUNNER_TEMP ('separate-layout/'+$name+'/Separate.exe')
     New-Item -ItemType Directory -Force (Split-Path $external -Parent)|Out-Null
     Set-Content $external 'CI_NOT_EXECUTED_COMPONENT'
     New-Service -Name $name -BinaryPathName ('"'+$external+'"') -StartupType Manual|Out-Null
     $externalFiles[$name]=@{path=$external;hash=(Hash $external);image=('"'+$external+'"')}
    }
   }
  }
  $beforeIdentity=Join-Path $install 'identity-before-maintenance-upgrade.json'
  Copy-Item $identity $beforeIdentity
  $desktop=[Environment]::GetFolderPath('DesktopDirectory')
  # A different retained Recovery hash cannot bypass the stronger
  # physical Supervisor/Engine receipt binding. Reject a forged receipt
  # before any file replacement, while leaving old Recovery intact.
  if($opaqueRecoveryVariant){
   $receiptFile=Join-Path $data 'setup/maintenance-only-component-receipt-v1.json'
   $savedReceiptBytes=[IO.File]::ReadAllBytes($receiptFile)
   $beforeRecoverySha=Hash (Join-Path $install $names[2])
   try{
    $damaged=Get-Content $receiptFile -Raw|ConvertFrom-Json
    $damaged.supervisor_sha256=('f'*64)
    $damaged|ConvertTo-Json -Depth 8|Set-Content $receiptFile
    $reportsBefore=@(Get-ChildItem $desktop -Filter 'SOKNA-MAINTENANCE-INSTALL-FAILED-*.json' -ErrorAction SilentlyContinue|ForEach-Object{$_.FullName})
    Need ((InvokeInstaller $Installer)-ne0) 'UNTRUSTED_0322_CORE_RECEIPT_ACCEPTED'
    $fresh=@(Get-ChildItem $desktop -Filter 'SOKNA-MAINTENANCE-INSTALL-FAILED-*.json' -ErrorAction SilentlyContinue|Where-Object{$_.FullName-notin$reportsBefore})
    Need ($fresh.Count-eq1) 'FORGED_CORE_RECEIPT_ERROR_REPORT_COUNT'
    $failure=Get-Content $fresh[0].FullName -Raw|ConvertFrom-Json
    Need ($failure.safe_code-eq'INSTALLED_BINARIES_NOT_RECEIPT_ATTESTED' -and
      $failure.failed_stage-eq'BASELINE_VERSION' -and
      $failure.observed_recovery_sha256-eq$beforeRecoverySha -and
      $null-eq$failure.rollback_verified -and
      -not$failure.recovery_requested) 'FORGED_0322_RECEIPT_REPORT_INCORRECT'
    Need ((Hash (Join-Path $install $names[2]))-eq$beforeRecoverySha) 'FORGED_0322_RECEIPT_CHANGED_RECOVERY'
    Assert-MaintenanceSignedHealth -ExpectedVersion '0.3.22' -Attempts 40
    Write-Host 'PASS ALTERNATE RECOVERY 011 REJECTS FORGED SUPERVISOR RECEIPT'
   }finally{[IO.File]::WriteAllBytes($receiptFile,$savedReceiptBytes)}
  }
  # REV2: same exact installed binaries, but an intentionally mismatched
  # identity must return its precise reason before any replacement occurs.
  $identityBytes=[IO.File]::ReadAllBytes($identity)
  $desktop=[Environment]::GetFolderPath('DesktopDirectory')
  $priorReports=@(Get-ChildItem $desktop -Filter 'SOKNA-MAINTENANCE-INSTALL-FAILED-*.json' -ErrorAction SilentlyContinue|ForEach-Object{$_.FullName})
  try{
   $bad=Get-Content $identity -Raw|ConvertFrom-Json
   $bad.maintenance_supervisor_version='0.3.21';$bad.update_engine_version='0.3.21'
   $bad|ConvertTo-Json -Depth 30|Set-Content $identity
   Need ((InvokeInstaller $Installer)-ne0) 'MISMATCHED_IDENTITY_ACCEPTED'
   $fresh=@(Get-ChildItem $desktop -Filter 'SOKNA-MAINTENANCE-INSTALL-FAILED-*.json'|Where-Object{$_.FullName-notin$priorReports})
   Need ($fresh.Count-eq1) 'ACTUAL_REJECTION_REPORT_MISSING'
   $failure=Get-Content $fresh[0].FullName -Raw|ConvertFrom-Json
   Need ($failure.safe_code-eq'MAINTENANCE_INSTALLED_IDENTITY_BASELINE_MISMATCH' -and $failure.failed_stage-eq'INSTALLED_IDENTITY' -and $null-eq$failure.rollback_verified -and -not$failure.recovery_requested) 'ACTUAL_REJECTION_REASON_WRONG'
   Assert-MaintenanceSignedHealth -ExpectedVersion $baseline -Attempts 40
  }finally{[IO.File]::WriteAllBytes($identity,$identityBytes)}
  $oldHashes=@($names|ForEach-Object{Hash (Join-Path $install $_)})
  $oldIdentity=Hash $identity
  $other=@{};foreach($rel in @('Runtime/SoknaRuntimeService.exe','PrintAgent/Service/Sokna.PrintAgent.Service.exe')){if(Test-Path (Join-Path $install $rel)){$other[$rel]=Hash (Join-Path $install $rel)}}
  # Real Inno EXE consumes this disposable 25-operation ledger, not just PS.
  # Private original device receipts were not included in Support-v2 archives.
  if($preserved011){
   $history=AddHistoricalLedger $data
   Assert-MaintenanceSignedHealth -ExpectedVersion $baseline -Attempts 40
   Need ((Get-Service SoknaMaintenanceSupervisor -ErrorAction Stop).Status-eq'Running') 'QA_HISTORICAL_LEDGER_NOT_LIVE_SAFE'
   Write-Host 'QA_HISTORICAL_25_RECORDS_REAL_SUPERVISOR_HEALTHY'
   $receiptHash=Hash $history.FirstStageReceipt
   $savedReceipt=[IO.File]::ReadAllBytes($history.FirstStageReceipt)
   try{
    $badReceipt=Get-Content -LiteralPath $history.FirstStageReceipt -Raw|ConvertFrom-Json
    $badReceipt.release_set_id='FORGED-NOT-THE-PREFLIGHT'
    $badReceipt|ConvertTo-Json -Depth 20|Set-Content -LiteralPath $history.FirstStageReceipt
    # Inspect ONLY the report for this exact installer invocation. The earlier
    # deliberately bad installed-identity case also writes a Desktop JSON and
    # must not be misread as the result of this historical-ledger test.
    $beforeForge=@(Get-ChildItem -LiteralPath $desktop -Filter 'SOKNA-MAINTENANCE-INSTALL-FAILED-*.json' -ErrorAction SilentlyContinue|
      ForEach-Object{$_.FullName})
    Write-Host ('QA_FORGED_HISTORICAL_REPORTS_BEFORE='+$beforeForge.Count)
    $badInstall=InvokeInstaller $Installer
    Need ($badInstall-ne0) 'EXACT_INNO_ACCEPTED_FORGED_HISTORICAL_STAGE'
    $freshErrors=@(Get-ChildItem -LiteralPath $desktop -Filter 'SOKNA-MAINTENANCE-INSTALL-FAILED-*.json' -ErrorAction SilentlyContinue|
      Where-Object{$_.FullName-notin$beforeForge})
    Write-Host ('QA_FORGED_HISTORICAL_EXIT='+[string]$badInstall+';NEW_REPORT_COUNT='+$freshErrors.Count)
    Need ($freshErrors.Count-eq1) 'EXACT_INNO_FORGED_HISTORICAL_ERROR_REPORT_MISSING_OR_DUPLICATE'
    $ferr=Get-Content -LiteralPath $freshErrors[0].FullName -Raw|ConvertFrom-Json
    Write-Host ('QA_EXACT_EXE_OBSERVED_FAILURE='+[string]$ferr.safe_code+';stage='+[string]$ferr.failed_stage+
      ';private_log_written='+[string]$ferr.private_diagnostic_log_written)
    Need ($ferr.safe_code-eq'R125_HISTORICAL_STAGE_PROOF_INVALID' -and
          $ferr.failed_stage-eq'OPERATION_LEDGER' -and
          $ferr.recovery_requested-eq$false -and
          $null-eq$ferr.rollback_verified -and
          $ferr.database_schema_data_mutated-eq$false) 'EXACT_INNO_HISTORICAL_REJECT_REASON_WRONG'
    Assert-MaintenanceSignedHealth -ExpectedVersion $baseline -Attempts 40
    Need ((Hash $identity)-eq$oldIdentity) 'EXACT_INNO_BAD_STAGE_IDENTITY_CHANGED'
    for($i=0;$i-lt3;$i++){Need ((Hash (Join-Path $install $names[$i]))-eq$oldHashes[$i]) 'EXACT_INNO_BAD_STAGE_COMPONENT_CHANGED'}
    Write-Host 'PASS EXACT INNO EXE FORGED HISTORICAL STAGE REJECTED BEFORE MUTATION'
   }finally{[IO.File]::WriteAllBytes($history.FirstStageReceipt,$savedReceipt)}
   Need ((Hash $history.FirstStageReceipt)-eq$receiptHash) 'EXACT_INNO_STAGE_FIXTURE_RESTORE_FAILED'
  }
  # Exercise REAL copy failure through the exact Inno EXE. A readable but
  # write-locked unchanged Engine must not prevent restoring the Supervisor.
  $held=[IO.File]::Open((Join-Path $install $names[1]),[IO.FileMode]::Open,[IO.FileAccess]::Read,[IO.FileShare]::Read)
  try{$failed=InvokeInstaller $Installer;Need ($failed-ne0) 'LOCKED_ENGINE_ACCEPTED'}finally{$held.Dispose()}
  Assert-MaintenanceSignedHealth -ExpectedVersion $baseline -Attempts 40
  foreach($i in 0..2){Need ((Hash (Join-Path $install $names[$i]))-eq$oldHashes[$i]) 'REAL_ROLLBACK_FILE_MISMATCH'}
  Need ((Hash $identity)-eq$oldIdentity) 'REAL_ROLLBACK_IDENTITY_MISMATCH'
  $marker=Join-Path $data 'setup/maintenance-components-v2/active.json'
  Need ((Get-Content $marker -Raw|ConvertFrom-Json).phase-eq'ROLLED_BACK') 'REAL_ROLLBACK_RECEIPT_MISSING'
  Need ((InvokeInstaller $Installer)-eq0) 'EXACT_INSTALLER_UPGRADE_FAILED'
  Assert-MaintenanceSignedHealth -ExpectedVersion '0.3.26' -Attempts 40
  foreach($entry in $target.files){Need ((Hash (Join-Path $install $entry.path))-eq$entry.sha256) 'TARGET_BINARY_HASH_MISMATCH'}
  $doc=Get-Content $identity -Raw|ConvertFrom-Json
  Need ($doc.maintenance_supervisor_version-eq'0.3.26' -and $doc.update_engine_version-eq'0.3.26' -and $doc.local.keep-eq'local' -and $doc.public.keep-eq'public') 'IDENTITY_SCOPE_CHANGED'
  $state=Get-Content $marker -Raw|ConvertFrom-Json
  Need ($state.phase-eq'COMMITTED' -and $state.baseline-eq$baseline) 'COMMIT_RECEIPT_INVALID'
  dotnet run --project qa/sokna-r129-compat/CompatProbe.csproj -c Release -- verify-installed-maintenance-identity $beforeIdentity (Join-Path $data 'Maintenance')
  Need ($LASTEXITCODE-eq0) 'ACTUAL_COMPONENT_IDENTITY_PROOF_REJECTED'
  $resultFile=Join-Path (Split-Path $marker -Parent) ('tx-'+$state.id+'/result.json')
  $resultOriginal=[IO.File]::ReadAllBytes($resultFile)
  try{
   $broken=Get-Content $resultFile -Raw|ConvertFrom-Json
   $broken.files[0].sha256=('f'*64)
   $broken|ConvertTo-Json -Depth 30|Set-Content $resultFile
   dotnet run --project qa/sokna-r129-compat/CompatProbe.csproj -c Release -- verify-installed-maintenance-identity $beforeIdentity (Join-Path $data 'Maintenance')
   Need ($LASTEXITCODE-ne0) 'TAMPERED_COMPONENT_RECEIPT_ACCEPTED'
  }finally{[IO.File]::WriteAllBytes($resultFile,$resultOriginal)}
  $beforeReplay=@($names|ForEach-Object{Hash (Join-Path $install $_)})
  Need ((InvokeInstaller $Installer)-eq0) 'EXACT_VERIFIED_REPLAY_NOT_IDEMPOTENT'
  foreach($i in 0..2){Need ((Hash (Join-Path $install $names[$i]))-eq$beforeReplay[$i]) 'REJECTED_REINSTALL_MUTATED_FILES'}
  foreach($rel in $other.Keys){Need ((Hash (Join-Path $install $rel))-eq$other[$rel]) 'UNRELATED_COMPONENT_CHANGED'}
  foreach($name in $externalFiles.Keys){
   Need ((Hash $externalFiles[$name].path)-eq$externalFiles[$name].hash) 'SEPARATE_COMPONENT_CHANGED'
   $registered=Get-CimInstance Win32_Service -Filter ("Name='"+$name+"'")
   Need ($registered.PathName-eq$externalFiles[$name].image -and $registered.State-eq'Stopped') 'SEPARATE_COMPONENT_SERVICE_CHANGED'
  }
  $results+=@{baseline=$baseline;baseline_case=$case;baseline_recovery_sha256=$originalRecoveryHash;opaque_recovery_011_variant=$opaqueRecoveryVariant;preserved_original_011=$preserved011;target='0.3.26';component_layout=$(if($baseline-eq'0.3.22'){'separate_registered_paths'}elseif($baseline-eq'0.3.23'){'siblings_absent'}else{'legacy_sibling_files'});installer_sha256=$installerHash;precise_pretransaction_rejection_report='PASS';real_service_upgrade='PASS';real_file_lock_rollback='PASS';exact_replay_verified_without_changes=$true;local_public_identity_preserved=$true;runtime_print_files_preserved=$true;physical_host_tested=$false;production_approved=$false}
  Write-Host ('PASS REAL EXACT INNO EXE UPGRADE AND ROLLBACK baseline='+$baseline)
 }finally{
  foreach($name in @('SoknaRuntime','SoknaPrintWorker')){if($baseline-eq'0.3.22' -and $externalFiles.ContainsKey($name)){& sc.exe delete $name|Out-Null}}
  if(Get-Service SoknaMaintenanceSupervisor -ErrorAction SilentlyContinue){
   Stop-Service SoknaMaintenanceSupervisor -ErrorAction SilentlyContinue
   & sc.exe delete SoknaMaintenanceSupervisor|Out-Null
   for($i=0;$i-lt60;$i++){
    $svc=Get-Service SoknaMaintenanceSupervisor -ErrorAction SilentlyContinue
    if(-not$svc){break};$svc.Dispose();Start-Sleep -Milliseconds 200
   }
  }
  Need (-not(Get-Service SoknaMaintenanceSupervisor -ErrorAction SilentlyContinue)) 'CI_SERVICE_DELETE_PENDING'
  Remove-Item $data -Recurse -Force
 }
}
Need ($results.Count-eq6) 'INSTALLER_SIX_BASELINE_CASES_INCOMPLETE'
$results|ConvertTo-Json -Depth 8|Set-Content $Output -Encoding UTF8
Write-Host 'PASS INNO SIX REAL SERVICE BASELINE CASES INCLUDING DIFFERENT PRESERVED 011 HASH; NO FIELD APPROVAL'
