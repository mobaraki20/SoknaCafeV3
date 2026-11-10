param(
 [string]$DataRoot='',
 [string]$LocalRoot='',
 [Parameter(Mandatory=$true)][string]$OutputFile
)
# Inspect private evidence locally; export only bounded verdicts. NEVER copy raw
# operation JSON, rollback base64, Local DB rows or credentials to support ZIP.
$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest
if(-not$DataRoot){$DataRoot=Join-Path ([Environment]::GetFolderPath('CommonApplicationData')) 'SOKNA'}
$DataRoot=[IO.Path]::GetFullPath($DataRoot)
$OutputFile=[IO.Path]::GetFullPath($OutputFile)
if($OutputFile.StartsWith($DataRoot.TrimEnd('\')+'\',[StringComparison]::OrdinalIgnoreCase)){throw 'EVIDENCE_OUTPUT_INSIDE_LIVE_DATA_FORBIDDEN'}
$ops=Join-Path $DataRoot 'Maintenance\operations'
$stageRoot=Join-Path $DataRoot 'Maintenance\staging\release-sets'
function CheckFile([string]$p,[long]$max=4194304){
 if(-not(Test-Path -LiteralPath $p -PathType Leaf)){throw 'EVIDENCE_FILE_MISSING'}
 $f=Get-Item -LiteralPath $p -Force
 if($f.Length-gt$max -or $f.Length-le0){throw 'EVIDENCE_FILE_SIZE_UNSAFE'}
 if($f.Attributes -band [IO.FileAttributes]::ReparsePoint){throw 'EVIDENCE_FILE_REPARSE_FORBIDDEN'}
}
function ReadJson([string]$p,[long]$max=4194304){
 CheckFile $p $max
 return (Get-Content -LiteralPath $p -Raw -ErrorAction Stop|ConvertFrom-Json -ErrorAction Stop)
}
function SafeChild([string]$p,[string]$root,[string]$filename){
 if([string]::IsNullOrWhiteSpace($p)){throw 'R125_PROOF_PATH_MISSING'}
 $full=[IO.Path]::GetFullPath($p)
 $prefix=[IO.Path]::GetFullPath($root).TrimEnd('\')+'\'
 if(-not$full.StartsWith($prefix,[StringComparison]::OrdinalIgnoreCase)){throw 'R125_PROOF_PATH_OUTSIDE_TRUSTED_ROOT'}
 if($filename -and [IO.Path]::GetFileName($full)-cne$filename){throw 'R125_PROOF_FILENAME_INVALID'}
 return $full
}
function BoolProperty($o,[string]$name,[bool]$value){
 $p=$o.PSObject.Properties[$name]
 if($null-eq$p -or $p.Value-isnot[bool] -or $p.Value-ne$value){throw 'R125_HISTORICAL_MUTATION_OR_FLAG_UNPROVEN'}
}
function ValidateHistorical($op,[string]$opsRoot,[string]$staging){
 BoolProperty $op 'activation_performed' $false
 BoolProperty $op 'database_mutation_performed' $false
 if($op.state-ceq'verified'){
  if($op.operation_type-cne'update.local-package-preflight' -or $op.result_code-cne'PACKAGE_INTEGRITY_VERIFIED'){throw 'R125_HISTORICAL_TYPE_OR_RESULT_INVALID'}
 }elseif($op.operation_type-cne'update.local-stage' -or $op.result_code-cne'STAGED'){throw 'R125_HISTORICAL_TYPE_OR_RESULT_INVALID'}
 $preflight=SafeChild ([string]$op.preflight_receipt_path) $opsRoot ''
 $name=[IO.Path]::GetFileName($preflight)
 if($name-cnotmatch '^preflight-[a-f0-9]{32}\.json$'){throw 'R125_HISTORICAL_PREFLIGHT_NAME_INVALID'}
 $id=[IO.Path]::GetFileNameWithoutExtension($preflight).Substring(10)
 if($op.state-ceq'verified' -and $id-cne$op.operation_id){throw 'R125_HISTORICAL_PREFLIGHT_ID_INVALID'}
 $proof=ReadJson $preflight
 if($proof.format-cne'sokna-local-package-preflight-v1' -or $proof.schema_version-ne1 -or $proof.operation_id-cne$id -or
    $proof.package_ref-cne$op.package_ref -or [string]$proof.installed_identity_sha256 -cnotmatch '^[a-f0-9]{64}$' -or
    $null-eq$proof.verification -or $proof.verification.activation_performed-ne$false -or
    $proof.verification.database_mutation_performed-ne$false -or $proof.verification.artifact_integrity_verified-ne$true -or
    [string]$proof.verification.package_sha256 -cnotmatch '^[a-f0-9]{64}$'){
   throw 'R125_HISTORICAL_PREFLIGHT_CONTENT_INVALID'
 }
 if($op.state-ceq'staged'){
  $stage=SafeChild ([string]$op.stage_receipt_path) $staging 'stage-receipt.json'
  $dir=Split-Path -Parent $stage
  if(-not(Test-Path -LiteralPath $dir -PathType Container) -or
     ((Get-Item -LiteralPath $dir -Force).Attributes -band [IO.FileAttributes]::ReparsePoint)){
   throw 'R125_HISTORICAL_STAGE_DIR_INVALID'
  }
  $s=ReadJson $stage
  if($s.format-cne'sokna-maintenance-staged-release-v1' -or $s.schema_version-ne1 -or
     [IO.Path]::GetFullPath([string]$s.stage_root)-cne$dir -or
     $s.package_sha256-cne$proof.verification.package_sha256 -or
     $s.manifest_sha256-cne$proof.verification.manifest_sha256 -or
     $s.release_set_id-cne$proof.verification.release_set_id -or
     $s.sequence-ne$proof.verification.sequence -or $s.activation_performed-ne$false -or
     $s.database_mutation_performed-ne$false -or $s.preflight_identity_match-ne$true -or
     $s.artifact_integrity_verified-ne$true -or (-not$s.signature_verified -and -not$s.operator_authorized)){
    throw 'R125_HISTORICAL_STAGE_CONTENT_INVALID'
  }
 }
}
function MatchB64([string]$path,[string]$encoded,[string]$trustedRoot,[string]$suffix){
 if([string]::IsNullOrWhiteSpace($trustedRoot)){return 'NOT_PROVEN'}
 try{
  $file=SafeChild $path $trustedRoot ''
  if(-not$file.EndsWith($suffix,[StringComparison]::OrdinalIgnoreCase)){return 'NOT_PROVEN'}
  if([string]::IsNullOrWhiteSpace($encoded)){return 'NOT_PROVEN'}
  CheckFile $file
  $expected=[Convert]::FromBase64String($encoded)
  if($expected.Length-gt4194304){return 'NOT_PROVEN'}
  $actual=[IO.File]::ReadAllBytes($file)
  $sha=[Security.Cryptography.SHA256]::Create()
  try{
   $a=$sha.ComputeHash($actual);$b=$sha.ComputeHash($expected)
   return $(if([Convert]::ToBase64String($a)-ceq[Convert]::ToBase64String($b)){'MATCH'}else{'MISMATCH'})
  }finally{$sha.Dispose()}
 }catch{return 'NOT_PROVEN'}
}
$summary=[ordered]@{
 format='sokna-r125-local-proof-verdict-v1'
 checked_at_utc=[DateTime]::UtcNow.ToString('o')
 read_only=$true;authorizes_install=$false;authorizes_cleanup=$false
 operation_ledger=[ordered]@{status='NOT_PROVEN';total=0;verified=0;staged=0;terminal=0;blocking=0;first_blocker_id='';first_blocker_code=''}
 r125=[ordered]@{rollback_proof_present=$false;rollback_proof_sha256='';snapshot_state='NOT_PROVEN';snapshot_managed='NOT_PROVEN';snapshot_identity='NOT_PROVEN';bytes_match='NOT_PROVEN';live_db_ledger='NOT_CHECKED';isolated_db_restore='NOT_CHECKED';lifecycle_clearance='BLOCKED'}
 next_action='EVIDENCE_REVIEW_ONLY'
}
$ledger=$summary.operation_ledger
try{
 if(-not(Test-Path -LiteralPath $ops -PathType Container)){throw 'R125_OPERATION_LEDGER_UNAVAILABLE'}
 $entries=@(Get-ChildItem -LiteralPath $ops -Filter 'op-*.json' -File -ErrorAction Stop|Sort-Object Name)
 if($entries.Count-gt20000){throw 'R125_OPERATION_LEDGER_LIMIT'}
 if($entries.Count-eq0){throw 'R125_OPERATION_LEDGER_EMPTY_UNPROVEN'}
 foreach($e in $entries){
  $ledger.total++
  try{
   $op=ReadJson $e.FullName 1048576
   if($op.format-cne'sokna-maintenance-operation-v1' -or $op.schema_version-ne1 -or
      [string]$op.operation_id -cnotmatch '^[a-f0-9]{32}$' -or
      $e.Name-cne('op-'+[string]$op.operation_id+'.json') -or
      [string]$op.idempotency_key -cnotmatch '^[A-Za-z0-9_.:-]{1,128}$' -or
      [string]$op.operator_id -cnotmatch '^[A-Za-z0-9_.:-]{1,128}$' -or
      [string]$op.operation_type -cnotmatch '^[A-Za-z0-9_.:-]{1,128}$' -or
      [string]$op.state -cnotmatch '^[A-Za-z0-9_.:-]{1,64}$' -or
      [string]$op.canonical_request_sha256 -cnotmatch '^[a-fA-F0-9]{64}$' -or
      [string]$op.package_ref -eq '' -or [string]$op.package_ref -match '^.{4097}' -or
      $null-eq$op.attempt_count -or [int]$op.attempt_count-lt0 -or
      [string]::IsNullOrWhiteSpace([string]$op.created_at_utc) -or
      [string]::IsNullOrWhiteSpace([string]$op.updated_at_utc) -or
      [DateTimeOffset]::Parse([string]$op.updated_at_utc)-lt[DateTimeOffset]::Parse([string]$op.created_at_utc)){
     throw 'R125_OPERATION_LEDGER_CORRUPT'
   }
   if($op.state-in @('accepted','running','activating','recovering','recovery_required')){
     throw 'R125_ACTIVE_OR_UNRESOLVED_UPDATE_OPERATION'
   }
   if($op.state-in @('verified','staged')){
    ValidateHistorical $op $ops $stageRoot
    if($op.state-eq'verified'){$ledger.verified++}else{$ledger.staged++}
   }elseif($op.state-in @('committed','failed_pre_mutation','failed','code_only_engine_rolled_back_verified','rolled_back','recovered','cancelled')){
    $ledger.terminal++
   }else{throw 'R125_UNKNOWN_OPERATION_STATE_NOT_IDLE'}
  }catch{
   $ledger.blocking++
   if(-not$ledger.first_blocker_code){
    $ledger.first_blocker_id=([IO.Path]::GetFileNameWithoutExtension($e.Name)-replace '^op-','')
    $message=[string]$_.Exception.Message
    $known=@('R125_OPERATION_LEDGER_CORRUPT','R125_ACTIVE_OR_UNRESOLVED_UPDATE_OPERATION','R125_UNKNOWN_OPERATION_STATE_NOT_IDLE',
      'R125_PROOF_PATH_MISSING','R125_PROOF_PATH_OUTSIDE_TRUSTED_ROOT','R125_PROOF_FILENAME_INVALID',
      'R125_HISTORICAL_MUTATION_OR_FLAG_UNPROVEN','R125_HISTORICAL_TYPE_OR_RESULT_INVALID',
      'R125_HISTORICAL_PREFLIGHT_NAME_INVALID','R125_HISTORICAL_PREFLIGHT_ID_INVALID',
      'R125_HISTORICAL_PREFLIGHT_CONTENT_INVALID','R125_HISTORICAL_STAGE_DIR_INVALID',
      'R125_HISTORICAL_STAGE_CONTENT_INVALID','EVIDENCE_FILE_MISSING','EVIDENCE_FILE_SIZE_UNSAFE','EVIDENCE_FILE_REPARSE_FORBIDDEN')
    $ledger.first_blocker_code=$(if($message-in$known){$message}else{'R125_EVIDENCE_READ_OR_PARSE_UNAVAILABLE'})
   }
  }
 }
 $ledger.status=$(if($ledger.blocking-eq0){'RECEIPTS_CROSS_CHECKED_NO_INSTALL_APPROVAL'}else{'BLOCKED_OR_UNPROVEN'})
}catch{
 $ledger.status='BLOCKED_OR_UNPROVEN'
 $ledger.blocking++
 $code=[string]$_.Exception.Message
 $ledger.first_blocker_code=$(if($code-in @('R125_OPERATION_LEDGER_UNAVAILABLE','R125_OPERATION_LEDGER_LIMIT','R125_OPERATION_LEDGER_EMPTY_UNPROVEN')){$code}else{'R125_LEDGER_READ_UNAVAILABLE'})
}
$prior=Join-Path $DataRoot 'Maintenance\local-slots\routine-local-lifecycle-before.json'
if(Test-Path -LiteralPath $prior -PathType Leaf){
 $summary.r125.rollback_proof_present=$true
 try{
  CheckFile $prior 4194304
  $summary.r125.rollback_proof_sha256=(Get-FileHash -LiteralPath $prior -Algorithm SHA256).Hash.ToLowerInvariant()
  $p=ReadJson $prior
  if($p.format-cne'sokna-routine-local-lifecycle-backup-v1'){throw 'R125_LIFECYCLE_PROOF_FORMAT_INVALID'}
  $trusted=$(if($LocalRoot){[IO.Path]::GetFullPath($LocalRoot)}else{''})
  $summary.r125.snapshot_state=MatchB64 ([string]$p.state_path) ([string]$p.state_base64) $trusted '\lifecycle\local\state.json'
  if($null-eq$p.managed_base64){
   $summary.r125.snapshot_managed='NOT_PROVEN'
  }else{
   $summary.r125.snapshot_managed=MatchB64 ([string]$p.managed_path) ([string]$p.managed_base64) $trusted '\lifecycle\local\managed-files.json'
  }
  $identity=Join-Path $DataRoot 'Maintenance\installed-release-identity.json'
  if([IO.Path]::GetFullPath([string]$p.installed_identity_path)-ieq[IO.Path]::GetFullPath($identity)){
   $summary.r125.snapshot_identity=MatchB64 ([string]$identity) ([string]$p.installed_identity_base64) $DataRoot '\Maintenance\installed-release-identity.json'
  }
  if($summary.r125.snapshot_state-eq'MATCH' -and $summary.r125.snapshot_managed-eq'MATCH' -and $summary.r125.snapshot_identity-eq'MATCH'){
   $summary.r125.bytes_match='MATCH'
  }elseif('MISMATCH'-in @($summary.r125.snapshot_state,$summary.r125.snapshot_managed,$summary.r125.snapshot_identity)){
   $summary.r125.bytes_match='MISMATCH'
  }
 }catch{$summary.r125.bytes_match='NOT_PROVEN'}
}
# NEVER infer database safety or authorization from file-only evidence.
$parent=Split-Path -Parent $OutputFile
if(-not(Test-Path -LiteralPath $parent -PathType Container)){New-Item -ItemType Directory -Path $parent -Force|Out-Null}
[IO.File]::WriteAllText($OutputFile,($summary|ConvertTo-Json -Depth 12),[Text.UTF8Encoding]::new($false))
