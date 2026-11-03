param(
  [string]$OutputRoot = [Environment]::GetFolderPath('Desktop'),
  [ValidateRange(1,15)][int]$RecentOperations = 12
)
$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
# Support Bundle One: no credentials, DB rows, recovery PHP content or raw
# installed-identity secrets are exported. Errors are represented explicitly.
$base = Join-Path ([Environment]::GetFolderPath('CommonApplicationData')) 'SOKNA'
if(-not(Test-Path -LiteralPath $OutputRoot -PathType Container)) {
  $OutputRoot = [Environment]::GetFolderPath('MyDocuments')
}
if(-not(Test-Path -LiteralPath $OutputRoot -PathType Container)) {
  $OutputRoot = $env:TEMP
}
$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$work = Join-Path $env:TEMP ('SoknaUnifiedSupport-'+[Guid]::NewGuid().ToString('N'))
$zipOutput = Join-Path $OutputRoot ('SOKNA-ONE-SUPPORT-'+$stamp+'.zip')
New-Item -ItemType Directory -Path $work -Force|Out-Null
$script:errors=New-Object Collections.Generic.List[object]
function Safe([object]$v) {
  $s=[string]$v
  if($s.Length -gt 500){$s=$s.Substring(0,500)}
  $s=[regex]::Replace($s,'(?i)\b(password|passwd|secret|token|credential|pairing[_-]?code|api[_-]?key)\s*[:=]\s*[^\s,;}]+','$1=[REDACTED]')
  $s=[regex]::Replace($s,'(?i)ws1_[0-9a-f]{24}_[0-9a-f]{48}','[REDACTED]')
  return $s
}
function JsonSafe([string]$file,[object]$value) {
  $parent=Split-Path -Parent $file
  if(-not(Test-Path -LiteralPath $parent)){New-Item -ItemType Directory -Path $parent -Force|Out-Null}
  [IO.File]::WriteAllText($file,($value|ConvertTo-Json -Depth 20),[Text.UTF8Encoding]::new($false))
}
function ReadSafe([string]$path,[string]$category) {
  if(-not(Test-Path -LiteralPath $path -PathType Leaf)){return $null}
  try {
    if((Get-Item -LiteralPath $path -Force).Attributes -band [IO.FileAttributes]::ReparsePoint){throw 'REDIRECTED_FILE'}
    if((Get-Item -LiteralPath $path).Length -gt 5MB){throw 'OVERSIZED_SOURCE'}
    return (Get-Content -LiteralPath $path -Raw -ErrorAction Stop|ConvertFrom-Json -ErrorAction Stop)
  }catch {
    $script:errors.Add([ordered]@{source=$category;reason=(Safe $_.Exception.GetType().Name)})
    return $null
  }
}
function Sha([string]$path) {
  return (Get-FileHash -LiteralPath $path -Algorithm SHA256).Hash.ToLowerInvariant()
}
function Observe([string]$path) {
  if(-not(Test-Path -LiteralPath $path -PathType Leaf)){return [ordered]@{present=$false}}
  try {
    $f=Get-Item -LiteralPath $path -Force
    if($f.Attributes -band [IO.FileAttributes]::ReparsePoint){return [ordered]@{present=$true;redirected=$true}}
    return [ordered]@{present=$true;size_bytes=[long]$f.Length;sha256=(Sha $path)}
  }catch{return [ordered]@{present=$true;readback_error=(Safe $_.Exception.GetType().Name)}}
}
function Info([string]$path) {
  $observed=Observe $path
  if($observed.present -and -not $observed.redirected -and -not $observed.readback_error) {
    $observed.version=[string]([Diagnostics.FileVersionInfo]::GetVersionInfo($path).FileVersion)
  }
  return $observed
}
function StringProperty($source,[string]$field){
  if($null -eq $source){return ''}
  $p=$source.PSObject.Properties[$field]
  if($null -eq $p -or $null -eq $p.Value){return ''}
  return [string]$p.Value
}
try {
  $diagnostics=Join-Path $work 'unified'
  New-Item -ItemType Directory -Path $diagnostics -Force|Out-Null
  $state=ReadSafe (Join-Path $base 'Maintenance\installed-release-identity.json') 'identity'
  $local=$null
  if($null -ne $state) {$local=$state.local}
  $safeIdentity=[ordered]@{
    format='sokna-safe-identity-v1'
    present=($null -ne $state)
    local=[ordered]@{
      version=(StringProperty $local 'version')
      release_id=(StringProperty $local 'release_id')
      managed_tree_sha256=(StringProperty $local 'managed_tree_sha256')
      schema_fingerprint=(StringProperty $local 'schema_fingerprint')
      migration_head=(StringProperty $local 'migration_head')
      authority_epoch_policy=(StringProperty $local 'authority_epoch_policy')
    }
    maintenance_supervisor_version=(StringProperty $state 'maintenance_supervisor_version')
    update_engine_version=(StringProperty $state 'update_engine_version')
  }
  JsonSafe (Join-Path $diagnostics 'installed-identity-safe.json') $safeIdentity

  # Source of truth for physical binaries, not stale Windows Services
  # aggregate install-state metadata.
  $service=Get-CimInstance Win32_Service -Filter "Name='SoknaMaintenanceSupervisor'" -ErrorAction SilentlyContinue
  $binary=''
  if($null -ne $service){
    $image=[Environment]::ExpandEnvironmentVariables(([string]$service.PathName).Trim())
    if($image -match '^"([^"]+\.exe)"') {$binary=$Matches[1]}
    elseif($image -match '^([^\s"]+\.exe)') {$binary=$Matches[1]}
  }
  $maintApp=''
  if($binary){$maintApp=Split-Path -Parent $binary}
  $components=[ordered]@{format='sokna-physical-component-versions-v1';service_state=$(if($null -ne $service){[string]$service.State}else{'missing'});binary_origin='SCM Win32_Service.PathName';supervisor=$(if($binary){Info $binary}else{@{present=$false}});update_engine=$(if($maintApp){Info (Join-Path $maintApp 'SoknaMaintenanceUpdateEngine.exe')}else{@{present=$false}})}
  JsonSafe (Join-Path $diagnostics 'maintenance-physical-versions.json') $components

  # Exact read-only Local physical inventory and migration catalogue. The
  # active Web may be a junction: read it, never repair/rename it here.
  $installRoot=''
  foreach($root in @('D:\SOKNA','C:\SOKNA')) {
    if(Test-Path -LiteralPath (Join-Path $root 'Web\VERSION.txt') -PathType Leaf){$installRoot=$root;break}
  }
  $physical=[ordered]@{format='sokna-local-read-only-evidence-v1';install_root=$installRoot;version='';inventory_count=0;managed_tree_sha256='';migration_catalog_count=0;migration_head='';migration_catalog_fingerprint='';status='NOT_COLLECTED'}
  if($installRoot){
    $web=Join-Path $installRoot 'Web'
    $version=Join-Path $web 'VERSION.txt'
    if(Test-Path -LiteralPath $version){$physical.version=(Get-Content -LiteralPath $version -Raw).Trim()}
    try {
      $inv=ReadSafe (Join-Path $web 'resources\local-managed-files-v1.json') 'local-managed-inventory'
      if($null -eq $inv -or [string]$inv.format -ne 'sokna-local-managed-files-v1'){throw 'INVALID_INVENTORY'}
      $names=@($inv.files)
      if($names.Count -lt 1 -or $names.Count -gt 30000){throw 'INVENTORY_COUNT_INVALID'}
      $seen=New-Object 'System.Collections.Generic.HashSet[string]' ([StringComparer]::OrdinalIgnoreCase)
      $lines=New-Object 'System.Collections.Generic.List[string]'
      foreach($name in ($names|Sort-Object -CaseSensitive)){
        $rel=[string]$name
        if(!$seen.Add($rel) -or $rel -match '(^[/\\]|:|(^|[/\\])\.\.([/\\]|$))'){throw 'INVENTORY_PATH_UNSAFE'}
        $file=Join-Path $web $rel.Replace('/','\')
        if(!(Test-Path -LiteralPath $file -PathType Leaf)){throw 'MANAGED_FILE_MISSING'}
        $lines.Add($rel+"`0"+(Sha $file)+"`n")
      }
      $algorithm=[Security.Cryptography.SHA256]::Create()
      try {$physical.managed_tree_sha256=([BitConverter]::ToString($algorithm.ComputeHash([Text.Encoding]::UTF8.GetBytes([string]::Concat($lines.ToArray()))))-replace'-','').ToLowerInvariant()}finally{$algorithm.Dispose()}
      $physical.inventory_count=$names.Count
      $dir=Join-Path $web 'database\migrations'
      $migrations=@(Get-ChildItem -LiteralPath $dir -Filter '*.sql' -File|Sort-Object Name)
      if($migrations.Count -gt 10000){throw 'MIGRATION_COUNT_INVALID'}
      $catalog=New-Object 'System.Collections.Generic.List[string]'
      foreach($m in $migrations){
        if($m.BaseName -notmatch '^\d{4}_[a-z0-9_]+$'){throw 'MIGRATION_NAME_INVALID'}
        $catalog.Add($m.BaseName+"`0"+(Sha $m.FullName)+"`n")
      }
      $physical.migration_catalog_count=$migrations.Count
      if($migrations.Count){$physical.migration_head=$migrations[-1].BaseName}
      $algorithm=[Security.Cryptography.SHA256]::Create()
      try {$physical.migration_catalog_fingerprint='migration-ledger-sha256-v1:'+(([BitConverter]::ToString($algorithm.ComputeHash([Text.Encoding]::UTF8.GetBytes([string]::Concat($catalog.ToArray()))))-replace'-','').ToLowerInvariant())}finally{$algorithm.Dispose()}
      $physical.status='PHYSICAL_CATALOG_ATTESTED__LIVE_DB_LEDGER_NOT_READ'
    }catch {$physical.status='PHYSICAL_READ_FAILED';$script:errors.Add([ordered]@{source='local-physical';reason=(Safe $_.Exception.Message)})}
    $overlay=[ordered]@{
      active=(Observe (Join-Path $web 'public\local-recovery.php'))
      persistent=(Observe (Join-Path $installRoot 'LocalWeb\persistent\public\local-recovery.php'))
    }
    JsonSafe (Join-Path $diagnostics 'stable-recovery-overlay.json') $overlay
  }
  JsonSafe (Join-Path $diagnostics 'local-physical-readonly.json') $physical

  $ops=Join-Path $base 'Maintenance\operations'
  $recent=New-Object Collections.Generic.List[object]
  if(Test-Path -LiteralPath $ops -PathType Container){
    foreach($p in @(Get-ChildItem -LiteralPath $ops -Filter 'op-*.json' -File|Sort-Object LastWriteTimeUtc -Descending|Select-Object -First $RecentOperations)){
      $o=ReadSafe $p.FullName 'maintenance-operations'
      if($null -eq $o){continue}
      $recent.Add([ordered]@{operation_id=(StringProperty $o 'operation_id');operation_type=(StringProperty $o 'operation_type');state=(StringProperty $o 'state');result_code=(StringProperty $o 'result_code');result_message=(Safe (StringProperty $o 'result_message'));updated_at_utc=(StringProperty $o 'updated_at_utc');activation_performed=[bool]$o.activation_performed;database_mutation_performed=[bool]$o.database_mutation_performed})
    }
  }
  JsonSafe (Join-Path $diagnostics 'maintenance-operations-safe.json') ([ordered]@{format='sokna-recent-maintenance-operations-v1';operations=@($recent.ToArray())})

  # This historical gap was the reason the operator was asked for two files.
  # Aggregate Windows installer logs do NOT include Maintenance-only attempts.
  $setup=Join-Path $base 'setup'
  $lastError=Join-Path $setup 'maintenance-only-last-error.txt'
  if(Test-Path -LiteralPath $lastError -PathType Leaf){
    $msg=Safe ((Get-Content -LiteralPath $lastError -Raw).Trim())
    JsonSafe (Join-Path $diagnostics 'maintenance-only-last-error.json') ([ordered]@{present=$true;message=$msg})
  } else {
    JsonSafe (Join-Path $diagnostics 'maintenance-only-last-error.json') ([ordered]@{present=$false;reason='NOT_PRESENT'})
  }
  $receipt=ReadSafe (Join-Path $setup 'maintenance-only-component-receipt-v1.json') 'maintenance-only-receipt'
  $safeReceipt=[ordered]@{present=($null -ne $receipt);version=(StringProperty $receipt 'version');transaction_id=(StringProperty $receipt 'transaction_id');supervisor_sha256=(StringProperty $receipt 'supervisor_sha256');engine_sha256=(StringProperty $receipt 'engine_sha256');runtime_unchanged=$(if($null -ne $receipt){[bool]$receipt.runtime_unchanged}else{$false});print_agent_unchanged=$(if($null -ne $receipt){[bool]$receipt.print_agent_unchanged}else{$false})}
  JsonSafe (Join-Path $diagnostics 'maintenance-only-receipt-safe.json') $safeReceipt
  $history=New-Object Collections.Generic.List[object]
  $txRoot=Join-Path $setup 'component-transactions'
  if(Test-Path -LiteralPath $txRoot -PathType Container) {
    foreach($d in @(Get-ChildItem -LiteralPath $txRoot -Directory -Filter 'maintenance-*'|Sort-Object LastWriteTimeUtc -Descending|Select-Object -First 7)) {
      $result=ReadSafe (Join-Path $d.FullName 'install-result.json') 'maintenance-only-transaction'
      $history.Add([ordered]@{transaction=$d.Name;result_present=($null -ne $result);status=(StringProperty $result 'status');exit_code=(StringProperty $result 'exit_code');failed_component=(StringProperty $result 'failed_component');failed_phase=(StringProperty $result 'failed_phase');summary=(Safe (StringProperty $result 'summary'));rollback_complete=$(if($null -ne $result){[bool]$result.rollback_complete}else{$false})})
    }
  }
  JsonSafe (Join-Path $diagnostics 'maintenance-only-transactions.json') ([ordered]@{format='sokna-maintenance-only-transactions-v1';transactions=@($history.ToArray())})
  $backup=ReadSafe (Join-Path $base 'Maintenance\backup\state.json') 'backup-state'
  JsonSafe (Join-Path $diagnostics 'backup-readiness.json') ([ordered]@{state=$(if($null -eq $backup){'NO_BACKUP_STATE'}else{'PRESENT__REQUIRES_VERIFICATION'});backup_state_present=($null -ne $backup)})

  # Bundle the existing Windows Services collector INSIDE this ZIP, if the
  # installed script is available. Never request a separate second upload.
  $legacyScript=Join-Path $PSScriptRoot 'collect-support-v2.ps1'
  $legacyDir=Join-Path $work 'existing-windows-support'
  New-Item -ItemType Directory -Path $legacyDir -Force|Out-Null
  $oldCollected=$false
  if(Test-Path -LiteralPath $legacyScript -PathType Leaf) {
    try {
      & $legacyScript -DataRoot $base -OutputRoot $legacyDir -InstallerTransactions 5 6>$null | Out-Null
      $oldCollected=@(Get-ChildItem -LiteralPath $legacyDir -Filter 'SOKNA-Support-v2-*.zip' -File).Count -gt 0
    }catch {$script:errors.Add([ordered]@{source='legacy-windows-collector';reason=(Safe $_.Exception.GetType().Name)})}
  }
  JsonSafe (Join-Path $diagnostics 'coverage.json') ([ordered]@{
    format='sokna-unified-support-coverage-v1'
    created_at_utc=[DateTime]::UtcNow.ToString('o')
    physical_component_versions='COLLECTED'
    local_installed_identity='COLLECTED_IF_PRESENT'
    local_physical_files='COLLECTED_IF_AVAILABLE'
    live_database_rows_or_credentials='NEVER_COLLECTED'
    live_database_migration_ledger='NOT_COLLECTED'
    maintenance_component_only_history='COLLECTED_IF_PRESENT'
    prior_windows_aggregate_support=$(if($oldCollected){'EMBEDDED_IN_SAME_ZIP'}else{'NOT_AVAILABLE'})
    collection_errors=@($script:errors.ToArray())
    note='Support is read-only. Missing evidence is not success.'
  })
  Add-Type -AssemblyName System.IO.Compression.FileSystem
  [IO.Compression.ZipFile]::CreateFromDirectory($work,$zipOutput)
  Write-Host ('SOKNA_ONE_SUPPORT='+$zipOutput)
  Write-Host ('SOKNA_ONE_SUPPORT_SHA256='+(Sha $zipOutput))
} finally {
  Remove-Item -LiteralPath $work -Recurse -Force -ErrorAction SilentlyContinue
}
