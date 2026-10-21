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
  $json=$value|ConvertTo-Json -Depth 14
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
function Canonical-Path([string]$path){
  if([string]::IsNullOrWhiteSpace($path)){return ''}
  return ([IO.Path]::GetFullPath($path)).TrimEnd('\','/')
}
function Same-Path([string]$a,[string]$b){
  return [string]::Equals((Canonical-Path $a),(Canonical-Path $b),[StringComparison]::OrdinalIgnoreCase)
}
function Is-Reparse([string]$path){
  if(-not(Test-Path -LiteralPath $path -PathType Container)){return $false}
  $item=Get-Item -LiteralPath $path -Force
  return (($item.Attributes-band[IO.FileAttributes]::ReparsePoint)-ne0)
}
function Prop($obj,[string]$name,$default=$null){
  if($null-eq$obj){return $default}
  if($obj.PSObject.Properties.Name-contains$name){return $obj.$name}
  return $default
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
$packageRoot=Canonical-Path $normalized.Substring(0,$markerIndex)
$webRoot=Join-Path $packageRoot 'Web'
$versionPath=Join-Path $webRoot 'VERSION.txt'
if(-not(Test-Path -LiteralPath $versionPath -PathType Leaf)){Evidence 'skipped' 'LOCAL_VERSION_UNAVAILABLE' 'Local VERSION.txt is unavailable.';exit 0}
$currentBuild=(Get-Content -LiteralPath $versionPath -Raw).Trim()
$entry=@($contract.entries|Where-Object{[string]$_.build-eq$currentBuild})|Select-Object -First 1
if(-not$entry){
  # Legacy physical-Web installations may use the older H300 managed
  # inventory, which the R8 same-build adoption engine cannot attest.
  # Recognize them WITHOUT adding an unsafe mutation allowlist entry.
  $legacy=Join-Path $webRoot 'resources\H300_MANAGED_FILES.json'
  $r8=Join-Path $webRoot 'resources\local-managed-files-v1.json'
  if((Test-Path -LiteralPath $legacy -PathType Leaf)-and
     (-not(Test-Path -LiteralPath $r8 -PathType Leaf))){
    Evidence 'skipped' 'LOCAL_LEGACY_LAYOUT_TRANSITION_REQUIRED' ("Legacy Local managed-inventory layout needs separately qualified source-to-slots transition before R8 adoption: "+$currentBuild)
    exit 0
  }
  Evidence 'skipped' 'LOCAL_BASELINE_NOT_ALLOWLISTED' ("Current Local build is outside this adoption contract: "+$currentBuild)
  exit 0
}

# Before honoring an exact release contract, independently rehash the *installed*
# managed tree. The release version string or Bootstrap JSON alone is not proof.
# Machine-specific persistent overlay (including recovery.php) is not managed.
try {
  $inventoryPath=Join-Path $webRoot 'resources\local-managed-files-v1.json'
  if(-not(Test-Path -LiteralPath $inventoryPath -PathType Leaf)){
    Evidence 'deferred' 'LOCAL_MANAGED_INVENTORY_MISSING' 'Installed Local managed-files inventory is missing; cannot attest baseline.'
    exit 0
  }
  $inventory=Get-Content -LiteralPath $inventoryPath -Raw|ConvertFrom-Json
  $files=@(Prop $inventory 'files' @())
  if([string](Prop $inventory 'format' '')-ne'sokna-local-managed-files-v1'-or
     $files.Count-ne[int]$entry.managed_files){
    Evidence 'deferred' 'LOCAL_MANAGED_INVENTORY_MISMATCH' 'Installed managed file count/format does not match the exact qualified baseline.'
    exit 0
  }
  $sorted=[string[]]@($files)
  [Array]::Sort($sorted,[StringComparer]::Ordinal)
  $digest=[Security.Cryptography.SHA256]::Create()
  try {
    $seen=New-Object 'System.Collections.Generic.HashSet[string]' ([StringComparer]::OrdinalIgnoreCase)
    foreach($rel in $sorted){
      $r=([string]$rel).Replace('\','/')
      if([string]::IsNullOrWhiteSpace($r)-or
         $r.StartsWith('/')-or
         $r-match'(^|/)\.\.?(?:/|$)'-or
         $r.Contains(':')-or
         -not$seen.Add($r)){throw 'Unsafe or duplicated managed file path.'}
      $file=Join-Path $webRoot ($r.Replace('/','\'))
      if(-not(Test-Path -LiteralPath $file -PathType Leaf)){throw 'Managed file missing.'}
      $hash=(Get-FileHash -LiteralPath $file -Algorithm SHA256).Hash.ToLowerInvariant()
      $line=$r+[char]0+$hash+"`n"
      $bytes=[Text.Encoding]::UTF8.GetBytes($line)
      [void]$digest.TransformBlock($bytes,0,$bytes.Length,$null,0)
    }
    [void]$digest.TransformFinalBlock([byte[]]@(),0,0)
    $installedTree=([BitConverter]::ToString($digest.Hash)-replace'-','').ToLowerInvariant()
  }finally{$digest.Dispose()}
  if($installedTree-ne([string]$entry.managed_tree_sha256).ToLowerInvariant()){
    Evidence 'deferred' 'LOCAL_MANAGED_TREE_HASH_MISMATCH' 'Installed Local tree does not equal the exact release contract; no identity or layout has been changed.'
    exit 0
  }
}catch{
  Evidence 'deferred' 'LOCAL_MANAGED_TREE_ATTESTATION_FAILED' $_.Exception.Message
  exit 0
}

# If Local is already on a junction-managed R8 slot, there is no physical
# Web directory to adopt. Do not rerun a layout migration against COMMITTED
# migration-state.json. A later signed Local-only Release Set must independently
# rebind the exact observed 231as identity during panel preflight.
if(Is-Reparse $webRoot){
  Evidence 'skipped' 'LOCAL_R8_ALREADY_PRESENT_SIGNED_REBIND_REQUIRED' 'The exact managed Local tree is verified and Web is already a junction. Physical layout adoption is not applicable. Signed next-release preflight must bind installed identity; no identity, signature, service or database was changed.' ([pscustomobject]@{verified_build=$currentBuild;verified_managed_files=[int]$entry.managed_files;verified_managed_tree_sha256=$installedTree})
  exit 0
}

$common=[Environment]::GetFolderPath([Environment+SpecialFolder]::CommonApplicationData)
$maintenanceRoot=Join-Path $common 'SOKNA\Maintenance'
$localSlotRoot=Join-Path $maintenanceRoot 'local-slots'
$migrationStatePath=Join-Path $localSlotRoot 'migration-state.json'
$legacyStateArchived=$false
$legacyStateArchivePath=''

# R8 may supersede one very specific historical state produced by the already-observed
# Home rollback: exact identity, phase ROLLED_BACK, physical healthy-layout boundary and
# no DB/Public/reboot/recovery obligation. The evidence is archived, never deleted.
if(Test-Path -LiteralPath $migrationStatePath -PathType Leaf){
  try{$legacy=Get-Content -LiteralPath $migrationStatePath -Raw|ConvertFrom-Json}catch{Evidence 'deferred' 'LEGACY_MIGRATION_STATE_INVALID' $_.Exception.Message;exit 0}
  $safe=(
    ([string](Prop $legacy 'format' '')-eq'sokna-local-slot-migration-state-v1')-and
    ([int](Prop $legacy 'schema_version' 0)-eq1)-and
    ([string](Prop $legacy 'phase' '')-eq'ROLLED_BACK')-and
    (-not[bool](Prop $legacy 'recovery_required' $true))-and
    (-not[bool](Prop $legacy 'database_mutation_performed' $true))-and
    (-not[bool](Prop $legacy 'public_mutation_performed' $true))-and
    (-not[bool](Prop $legacy 'windows_reboot_required' $true))-and
    ([string](Prop $legacy 'expected_build' '')-eq[string]$entry.build)-and
    ([int](Prop $legacy 'expected_managed_files' 0)-eq[int]$entry.managed_files)-and
    ([string](Prop $legacy 'expected_managed_tree_sha256' '').ToLowerInvariant()-eq([string]$entry.managed_tree_sha256).ToLowerInvariant())-and
    (Same-Path ([string](Prop $legacy 'package_root' '')) $packageRoot)-and
    (Same-Path ([string](Prop $legacy 'stable_web_root' '')) $webRoot)-and
    (Test-Path -LiteralPath $webRoot -PathType Container)-and
    (-not(Is-Reparse $webRoot))
  )
  if(-not$safe){
    Evidence 'deferred' 'LEGACY_MIGRATION_STATE_UNSAFE' 'Existing migration-state.json is not the exact proven ROLLED_BACK physical-Web state allowed for R8 supersession.' ([pscustomobject]@{state_path=$migrationStatePath;phase=[string](Prop $legacy 'phase' '')})
    exit 0
  }
  try{
    $history=Join-Path $localSlotRoot 'history'
    New-Item -ItemType Directory -Path $history -Force|Out-Null
    $legacyStateArchivePath=Join-Path $history ('migration-state-r8-superseded-'+[DateTime]::UtcNow.ToString('yyyyMMddTHHmmssfffZ')+'.json')
    Move-Item -LiteralPath $migrationStatePath -Destination $legacyStateArchivePath
    $legacyStateArchived=$true
  }catch{
    Evidence 'deferred' 'LEGACY_MIGRATION_STATE_ARCHIVE_FAILED' $_.Exception.Message ([pscustomobject]@{state_path=$migrationStatePath})
    exit 0
  }
}

$bootstrapRoot=Join-Path $maintenanceRoot 'bootstrap'
$requestPath=Join-Path $bootstrapRoot 'local-slot-adoption-request.json'
$packageVersion=[string]$plan.package_version
$requestId=('adopt-'+$packageVersion+'-'+([string]$entry.build -replace'[^A-Za-z0-9_.:-]','-')+'-'+([string]$entry.managed_tree_sha256).Substring(0,12))
$requestId=$requestId-replace'[^A-Za-z0-9_.:-]','-'
if($requestId.Length-gt128){$requestId=$requestId.Substring(0,128)}
$request=[ordered]@{
  format='sokna-local-slot-adoption-request-v1'
  schema_version=1
  request_id=$requestId
  expected_build=[string]$entry.build
  expected_managed_files=[int]$entry.managed_files
  expected_managed_tree_sha256=[string]$entry.managed_tree_sha256
  source_package_version=$packageVersion
  created_at_utc=[DateTime]::UtcNow.ToString('o')
}

try{
  if(Test-Path -LiteralPath $requestPath -PathType Leaf){
    $existing=Get-Content -LiteralPath $requestPath -Raw|ConvertFrom-Json
    $same=([string]$existing.format-eq$request.format)-and([int]$existing.schema_version-eq1)-and([string]$existing.request_id-eq$request.request_id)-and([string]$existing.expected_build-eq$request.expected_build)-and([int]$existing.expected_managed_files-eq$request.expected_managed_files)-and([string]$existing.expected_managed_tree_sha256-eq$request.expected_managed_tree_sha256)
    if($same){
      Evidence 'queued' 'LOCAL_ADOPTION_ALREADY_QUEUED' 'The same durable R8 Local adoption request is already present.' ([pscustomobject]@{request_id=$requestId;request_path=$requestPath;expected_build=$request.expected_build;legacy_state_archived=$legacyStateArchived;legacy_state_archive_path=$legacyStateArchivePath})
      exit 0
    }
    Evidence 'deferred' 'LOCAL_ADOPTION_QUEUE_CONFLICT' 'A different durable Local adoption request already exists; it was not overwritten.' ([pscustomobject]@{request_path=$requestPath;expected_build=$request.expected_build;legacy_state_archived=$legacyStateArchived;legacy_state_archive_path=$legacyStateArchivePath})
    exit 0
  }
  Write-Json $requestPath $request
  Evidence 'queued' 'LOCAL_ADOPTION_QUEUED_R8' 'Durable R8 Local layout adoption was queued for Maintenance Supervisor; installer does not execute the migration.' ([pscustomobject]@{request_id=$requestId;request_path=$requestPath;expected_build=$request.expected_build;legacy_state_archived=$legacyStateArchived;legacy_state_archive_path=$legacyStateArchivePath})
  exit 0
}catch{
  Evidence 'deferred' 'LOCAL_ADOPTION_QUEUE_WRITE_FAILED' $_.Exception.Message ([pscustomobject]@{request_path=$requestPath;expected_build=$request.expected_build;legacy_state_archived=$legacyStateArchived;legacy_state_archive_path=$legacyStateArchivePath})
  # Windows Services component installation is already committed. Queue failure must not
  # turn the Windows component transaction into a failed install or trigger unrelated rollback.
  exit 0
}
