param([Parameter(Mandatory=$true)][string]$PlanFile)
$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest

function Safe([string]$v){
  $v=([string]$v-replace'[\r\n\0]',' ').Trim()
  $v=$v-replace'(?i)(token|secret|credential|password|signature)(\s*[:=]\s*)[^\s,;\}\]]+','$1$2[REDACTED]'
  if($v.Length-gt1600){$v=$v.Substring(0,1600)}
  return $v
}
function Write-Json([string]$path,$value){
  $parent=[IO.Path]::GetDirectoryName($path)
  New-Item -ItemType Directory -Path $parent -Force|Out-Null
  $tmp=$path+'.tmp.'+[guid]::NewGuid().ToString('N')
  $json=$value|ConvertTo-Json -Depth 12
  [IO.File]::WriteAllText($tmp,$json,(New-Object Text.UTF8Encoding($false)))
  $stream=[IO.File]::Open($tmp,[IO.FileMode]::Open,[IO.FileAccess]::ReadWrite,[IO.FileShare]::None)
  try{$stream.Flush($true)}finally{$stream.Dispose()}
  Move-Item -LiteralPath $tmp -Destination $path -Force
}
function Service-Exe([string]$image){
  $text=[Environment]::ExpandEnvironmentVariables(([string]$image).Trim())
  if($text-match'^\s*"([^"]+\.exe)"'){return [IO.Path]::GetFullPath([string]$Matches[1])}
  if($text-match'^\s*([^\s"]+\.exe)'){return [IO.Path]::GetFullPath([string]$Matches[1])}
  return ''
}
function Is-ReparsePoint([string]$path){
  if(-not(Test-Path -LiteralPath $path)){return $false}
  $item=Get-Item -LiteralPath $path -Force
  return (($item.Attributes -band [IO.FileAttributes]::ReparsePoint)-ne0)
}
function Evidence([string]$status,[string]$code,[string]$detail,$extra=$null){
  $row=[ordered]@{
    format='sokna-local-slot-adoption-queue-evidence-v1'
    schema_version=1
    at_utc=[DateTime]::UtcNow.ToString('o')
    status=$status
    code=$code
    detail=(Safe $detail)
    activation_performed=$false
    database_mutation_performed=$false
    public_mutation_performed=$false
    windows_reboot_required=$false
  }
  if($null-ne$extra){foreach($p in $extra.PSObject.Properties){$row[$p.Name]=$p.Value}}
  Write-Json $EvidencePath $row
}

$PlanFile=[IO.Path]::GetFullPath($PlanFile)
if(-not(Test-Path -LiteralPath $PlanFile -PathType Leaf)){throw 'Install plan is missing.'}
$plan=Get-Content -LiteralPath $PlanFile -Raw|ConvertFrom-Json
if([string]$plan.format-ne'sokna-windows-install-plan-v3'){throw 'Unsupported install plan.'}
$ShellRoot=[IO.Path]::GetFullPath([string]$plan.shell_root)
$TxDir=[IO.Path]::GetDirectoryName($PlanFile)
$EvidencePath=Join-Path $TxDir 'local-slot-adoption-queue.json'
$ContractPath=Join-Path $ShellRoot 'local-slot-bootstrap-contract-v1.json'
if(-not(Test-Path -LiteralPath $ContractPath -PathType Leaf)){Evidence 'deferred' 'ADOPTION_CONTRACT_MISSING' 'Local adoption contract is missing from the qualified shell.';exit 0}
$contract=Get-Content -LiteralPath $ContractPath -Raw|ConvertFrom-Json
if([string]$contract.format-ne'sokna-local-slot-bootstrap-contract-v1'-or[int]$contract.schema_version-ne1){Evidence 'deferred' 'ADOPTION_CONTRACT_INVALID' 'Local adoption contract is invalid.';exit 0}
if([bool]$contract.database_mutation-or[bool]$contract.public_mutation-or[bool]$contract.windows_reboot_required){Evidence 'deferred' 'ADOPTION_CONTRACT_UNSAFE' 'Local adoption contract requests a forbidden mutation.';exit 0}

$apache=Get-CimInstance Win32_Service -Filter "Name='SoknaApache'" -ErrorAction SilentlyContinue
if(-not$apache){Evidence 'skipped' 'LOCAL_APACHE_NOT_INSTALLED' 'SoknaApache is not installed on this host.';exit 0}
$apacheExe=Service-Exe ([string]$apache.PathName)
if([string]::IsNullOrWhiteSpace($apacheExe)){Evidence 'skipped' 'LOCAL_ROOT_UNRESOLVED' 'SoknaApache executable path could not be resolved.';exit 0}
$normalized=$apacheExe.Replace('/','\')
$marker='\Infrastructure\'
$markerIndex=$normalized.IndexOf($marker,[StringComparison]::OrdinalIgnoreCase)
if($markerIndex-le2){Evidence 'skipped' 'LOCAL_ROOT_UNRESOLVED' 'SoknaApache is not under the qualified SOKNA Infrastructure boundary.';exit 0}
$packageRoot=$normalized.Substring(0,$markerIndex)
$webRoot=Join-Path $packageRoot 'Web'
$versionPath=Join-Path $webRoot 'VERSION.txt'
if(-not(Test-Path -LiteralPath $versionPath -PathType Leaf)){Evidence 'skipped' 'LOCAL_VERSION_UNAVAILABLE' 'Local VERSION.txt is unavailable.';exit 0}
$currentBuild=(Get-Content -LiteralPath $versionPath -Raw).Trim()
$entry=@($contract.entries|Where-Object{[string]$_.build-eq$currentBuild})|Select-Object -First 1
if(-not$entry){Evidence 'skipped' 'LOCAL_BASELINE_NOT_ALLOWLISTED' ("Current Local build is outside this adoption contract: "+$currentBuild);exit 0}

$common=[Environment]::GetFolderPath([Environment+SpecialFolder]::CommonApplicationData)
$maintenanceRoot=Join-Path $common 'SOKNA\Maintenance'
$bootstrapRoot=Join-Path $maintenanceRoot 'bootstrap'
$requestPath=Join-Path $bootstrapRoot 'local-slot-adoption-request.json'
$migrationStatePath=Join-Path $maintenanceRoot 'local-slots\migration-state.json'
$priorMigrationArchived=$false

# R7 Home real-host UAT exposed a valid post-activation rollback state from the old nested
# release layout. Supervisor intentionally treats any previous activation as unsafe unless
# a trusted boundary proves the machine is back on the exact physical baseline. The installer
# is that boundary only for the narrow terminal states below. It never clears recovery_required,
# nonterminal, DB/Public-mutating, reboot-requiring, identity-mismatched, or junction states.
if(Test-Path -LiteralPath $migrationStatePath -PathType Leaf){
  try{
    $ms=Get-Content -LiteralPath $migrationStatePath -Raw|ConvertFrom-Json
    $phase=[string]$ms.phase
    $safePhysicalTerminal=($phase-eq'ROLLED_BACK'-or$phase-eq'RECOVERED_TO_PHYSICAL_WEB')
    $identityMatches=([string]$ms.expected_build-eq[string]$entry.build)-and([int]$ms.expected_managed_files-eq[int]$entry.managed_files)-and([string]$ms.expected_managed_tree_sha256-eq[string]$entry.managed_tree_sha256)
    $forbidden=([bool]$ms.recovery_required)-or([bool]$ms.database_mutation_performed)-or([bool]$ms.public_mutation_performed)-or([bool]$ms.windows_reboot_required)
    $webIsJunction=Is-ReparsePoint $webRoot
    if($safePhysicalTerminal-and$identityMatches-and(-not$forbidden)-and(-not$webIsJunction)){
      $historyRoot=Join-Path $maintenanceRoot 'local-slots\history'
      New-Item -ItemType Directory -Path $historyRoot -Force|Out-Null
      $history=Join-Path $historyRoot ('migration-state-installer-reconciled-'+[DateTime]::UtcNow.ToString('yyyyMMddTHHmmssfffZ')+'.json')
      Move-Item -LiteralPath $migrationStatePath -Destination $history
      $priorMigrationArchived=$true
    }elseif($phase-in@('COMMITTED','ALREADY_MIGRATED','RECOVERED_TO_VERIFIED_SLOT')){
      # A verified active-layout state belongs to Supervisor/Engine reconciliation; preserve it.
    }else{
      Evidence 'deferred' 'LOCAL_ADOPTION_PRIOR_MIGRATION_STATE_REQUIRES_REVIEW' ("Existing Local migration state is not safe for installer reconciliation; phase="+$phase) ([pscustomobject]@{migration_state_path=$migrationStatePath;expected_build=[string]$entry.build})
      exit 0
    }
  }catch{
    Evidence 'deferred' 'LOCAL_ADOPTION_PRIOR_MIGRATION_STATE_INVALID' $_.Exception.Message ([pscustomobject]@{migration_state_path=$migrationStatePath;expected_build=[string]$entry.build})
    exit 0
  }
}

$requestId=('adopt-'+([string]$entry.build -replace'[^A-Za-z0-9_.:-]','-')+'-'+([string]$entry.managed_tree_sha256).Substring(0,12))
if($requestId.Length-gt128){$requestId=$requestId.Substring(0,128)}
$request=[ordered]@{
  format='sokna-local-slot-adoption-request-v1'
  schema_version=1
  request_id=$requestId
  expected_build=[string]$entry.build
  expected_managed_files=[int]$entry.managed_files
  expected_managed_tree_sha256=[string]$entry.managed_tree_sha256
  source_package_version=[string]$plan.package_version
  created_at_utc=[DateTime]::UtcNow.ToString('o')
}

try{
  if(Test-Path -LiteralPath $requestPath -PathType Leaf){
    $existing=Get-Content -LiteralPath $requestPath -Raw|ConvertFrom-Json
    $same=([string]$existing.format-eq$request.format)-and([int]$existing.schema_version-eq1)-and([string]$existing.request_id-eq$request.request_id)-and([string]$existing.expected_build-eq$request.expected_build)-and([int]$existing.expected_managed_files-eq$request.expected_managed_files)-and([string]$existing.expected_managed_tree_sha256-eq$request.expected_managed_tree_sha256)
    if($same){
      Evidence 'queued' 'LOCAL_ADOPTION_ALREADY_QUEUED' 'The same durable Local adoption request is already present.' ([pscustomobject]@{request_id=$requestId;request_path=$requestPath;expected_build=$request.expected_build;prior_safe_migration_state_archived=$priorMigrationArchived})
      exit 0
    }
    Evidence 'deferred' 'LOCAL_ADOPTION_QUEUE_CONFLICT' 'A different durable Local adoption request already exists; it was not overwritten.' ([pscustomobject]@{request_path=$requestPath;expected_build=$request.expected_build;prior_safe_migration_state_archived=$priorMigrationArchived})
    exit 0
  }
  Write-Json $requestPath $request
  Evidence 'queued' 'LOCAL_ADOPTION_QUEUED' 'Durable Local layout adoption was queued for Maintenance Supervisor; installer does not execute the migration.' ([pscustomobject]@{request_id=$requestId;request_path=$requestPath;expected_build=$request.expected_build;prior_safe_migration_state_archived=$priorMigrationArchived})
  exit 0
}catch{
  Evidence 'deferred' 'LOCAL_ADOPTION_QUEUE_WRITE_FAILED' $_.Exception.Message ([pscustomobject]@{request_path=$requestPath;expected_build=$request.expected_build;prior_safe_migration_state_archived=$priorMigrationArchived})
  # Windows Services component installation is already committed. A Local adoption queue failure must not
  # turn the Windows component transaction into a failed install or trigger unrelated rollback.
  exit 0
}
