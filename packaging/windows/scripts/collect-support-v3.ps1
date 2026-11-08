param(
  [Parameter(Mandatory=$true)][string]$DataRoot,
  [Parameter(Mandatory=$true)][string]$OutputRoot,
  [ValidateRange(1,10)][int]$InstallerTransactions=5
)
$ErrorActionPreference='Stop'

function Redact([string]$v){
  if($null-eq$v){return ''}
  $v=$v-replace'(?i)ws1_[a-f0-9]{24}_[a-f0-9]{48}','[PAIRING_CODE_REDACTED]'
  $v=$v-replace'(?i)(authorization|cookie|csrf|password|passwd|pwd|token|secret|credential)(\s*[:=]\s*)[^\s,;\}\]]+','$1$2[REDACTED]'
  return $v
}
function WriteText([string]$path,[string]$value){[IO.File]::WriteAllText($path,(Redact $value),(New-Object Text.UTF8Encoding($false)))}
function WriteJson([string]$path,$value){WriteText $path ($value|ConvertTo-Json -Depth 14)}
function Capture([scriptblock]$block){try{Redact ((& $block 2>&1|Out-String -Width 280).TrimEnd())}catch{'ERROR: '+(Redact $_.Exception.Message)}}
function ServiceSnapshot([string]$name){
  $obj=[ordered]@{name=$name;exists=$false;state='missing';start_mode='';account='';path_name='';process_id=0;exit_code=$null;service_specific_exit_code=$null}
  try{
    $svc=Get-CimInstance Win32_Service -Filter ("Name='"+$name.Replace("'","''")+"'") -ErrorAction Stop
    if($null-ne$svc){$obj.exists=$true;$obj.state=[string]$svc.State;$obj.start_mode=[string]$svc.StartMode;$obj.account=[string]$svc.StartName;$obj.path_name=(Redact ([string]$svc.PathName));$obj.process_id=[int]$svc.ProcessId;$obj.exit_code=[int]$svc.ExitCode;$obj.service_specific_exit_code=[int]$svc.ServiceSpecificExitCode}
  }catch{}
  return [pscustomobject]$obj
}

$core=Join-Path $PSScriptRoot 'collect-support-v2-core.ps1'
$technician=Join-Path $PSScriptRoot 'write-technician-report-v1.ps1'
if(-not(Test-Path -LiteralPath $core -PathType Leaf)){throw 'Support Bundle v2 core collector is missing.'}
if(-not(Test-Path -LiteralPath $technician -PathType Leaf)){throw 'Technician report generator is missing.'}
$before=Get-Date
& $core -DataRoot $DataRoot -OutputRoot $OutputRoot -InstallerTransactions $InstallerTransactions 6>$null | Out-Null
$zip=Get-ChildItem -LiteralPath $OutputRoot -Filter 'SOKNA-Support-v2-*.zip' -File|Where-Object{$_.LastWriteTime-ge$before.AddSeconds(-2)}|Sort-Object LastWriteTimeUtc -Descending|Select-Object -First 1
if($null-eq$zip){throw 'Support Bundle v2 core did not produce an archive.'}
$temp=Join-Path $env:TEMP ('sokna-support-v3-'+[guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $temp -Force|Out-Null
try{
  Expand-Archive -LiteralPath $zip.FullName -DestinationPath $temp -Force
  $legacyDir=Join-Path $temp 'windows\legacy-print-agent';New-Item -ItemType Directory -Path $legacyDir -Force|Out-Null
  $legacy=ServiceSnapshot 'SoknaPrintAgent6'
  WriteJson (Join-Path $legacyDir 'SoknaPrintAgent6-snapshot.json') $legacy
  WriteText (Join-Path $legacyDir 'SoknaPrintAgent6-query.txt') (Capture {& "$env:SystemRoot\System32\sc.exe" queryex 'SoknaPrintAgent6'})
  WriteText (Join-Path $legacyDir 'SoknaPrintAgent6-config.txt') (Capture {& "$env:SystemRoot\System32\sc.exe" qc 'SoknaPrintAgent6'})
  WriteText (Join-Path $legacyDir 'legacy-print-processes.txt') (Capture {Get-CimInstance Win32_Process -ErrorAction SilentlyContinue|Where-Object{([string]$_.ExecutablePath)-match'(?i)\\Sokna\\PrintAgent\\'}|Select-Object Name,ProcessId,ExecutablePath|Format-Table -AutoSize})

  $summaryPath=Join-Path $temp 'support-v2-summary.json'
  if(Test-Path -LiteralPath $summaryPath -PathType Leaf){
    try{
      $summary=Get-Content -LiteralPath $summaryPath -Raw|ConvertFrom-Json
      $summary|Add-Member -NotePropertyName legacy_print_agent -NotePropertyValue ([ordered]@{service_name='SoknaPrintAgent6';exists=[bool]$legacy.exists;state=[string]$legacy.state;start_mode=[string]$legacy.start_mode;path_name=[string]$legacy.path_name;migration_target='SoknaPrintWorker'}) -Force
      WriteJson $summaryPath $summary
    }catch{}
  }

  & $technician -BundleRoot $temp 6>$null | Out-Null
  if(-not(Test-Path -LiteralPath (Join-Path $temp 'TECHNICIAN-SUMMARY.txt') -PathType Leaf)){throw 'Technician summary was not generated.'}
  if(-not(Test-Path -LiteralPath (Join-Path $temp 'TECHNICIAN-REPORT.html') -PathType Leaf)){throw 'Technician HTML report was not generated.'}
  if(-not(Test-Path -LiteralPath (Join-Path $temp 'technician-findings.json') -PathType Leaf)){throw 'Technician findings JSON was not generated.'}

  # P0: include existing on-box read-only Engine diagnostics in the *same*
  # standard support ZIP. Reuse the ONE SUPPORT inspector rather than building
  # another diagnostic engine or asking an operator to run a separate script.
  # Fail closed: missing/old Engine means UNKNOWN, NEVER "host healthy".
  $p0Host=[ordered]@{
    format='sokna-host-readiness-safe-v1';evidence_available=$false
    status='NOT_COLLECTED';blocking_code='';blockers=@()
  }
  $p0Versions=[ordered]@{
    format='sokna-physical-component-versions-v1';evidence_available=$false
    supervisor=@{present=$false};update_engine=@{present=$false}
  }
  $oneSupport=Join-Path $PSScriptRoot 'collect-support-unified-v1.ps1'
  $p0Temp=Join-Path $env:TEMP ('sokna-p0-evidence-'+[guid]::NewGuid().ToString('N'))
  try {
    if(-not(Test-Path -LiteralPath $oneSupport -PathType Leaf)) {
      throw 'P0_READONLY_INSPECTOR_MISSING'
    }
    New-Item -ItemType Directory -Path $p0Temp -Force|Out-Null
    # SkipLegacyCollector prevents recursion into this same v2/v3 collector.
    & $oneSupport -DataRoot $DataRoot -OutputRoot $p0Temp -SkipLegacyCollector 6>$null|Out-Null
    $oneZip=Get-ChildItem -LiteralPath $p0Temp -Filter 'SOKNA-ONE-SUPPORT-*.zip' -File |
      Sort-Object LastWriteTimeUtc -Descending|Select-Object -First 1
    if($null-eq$oneZip){throw 'P0_READONLY_INSPECTOR_ARCHIVE_MISSING'}
    $extract=Join-Path $p0Temp 'extracted'
    Expand-Archive -LiteralPath $oneZip.FullName -DestinationPath $extract -Force
    $hostFile=Join-Path $extract 'unified\maintenance-host-readiness-safe.json'
    $versionFile=Join-Path $extract 'unified\maintenance-physical-versions.json'
    if(-not(Test-Path -LiteralPath $hostFile -PathType Leaf) -or
       -not(Test-Path -LiteralPath $versionFile -PathType Leaf)) {
      throw 'P0_READONLY_INSPECTOR_OUTPUT_MISSING'
    }
    $hostRaw=Get-Content -LiteralPath $hostFile -Raw -ErrorAction Stop|ConvertFrom-Json -ErrorAction Stop
    $versionsRaw=Get-Content -LiteralPath $versionFile -Raw -ErrorAction Stop|ConvertFrom-Json -ErrorAction Stop
    if([string]$hostRaw.format -ne 'sokna-host-readiness-safe-v1' -or
       [string]$versionsRaw.format -ne 'sokna-physical-component-versions-v1') {
      throw 'P0_READONLY_INSPECTOR_CONTRACT_MISMATCH'
    }
    # Whitelist only fixed machine-safe codes. No paths, journal messages,
    # private request envelopes, DB rows or HMAC secrets cross this boundary.
    $allowedCodes=@('UPDATER_MUTATION_OR_RECOVERY_PENDING',
      'MUTATION_LEDGER_EVIDENCE_UNAVAILABLE',
      'SOKNA_APACHE_DISCOVERY_OR_HEALTH_UNPROVEN',
      'ACTIVE_R8_JUNCTION_UNQUALIFIED',
      'INSTALLED_LOCAL_IDENTITY_OR_TREE_MISMATCH',
      'MACHINE_BINDING_OR_INSTALL_LOCK_MISMATCH',
      'PERSISTENT_MACHINE_LOCAL_OVERLAY_DRIFT',
      'LOCAL_LIFECYCLE_NOT_IDLE_OR_UNPROVEN')
    $safeCodes=@($hostRaw.blockers|Where-Object{
      $_ -is [string] -and $_ -in $allowedCodes
    }|Select-Object -Unique)
    $globalCode=[string]$hostRaw.blocking_code
    if($globalCode -notmatch '^[A-Z][A-Z0-9_]{0,99}
  if(Test-Path -LiteralPath $manifestPath){Remove-Item -LiteralPath $manifestPath -Force}
  $entries=@()
  Get-ChildItem $temp -File -Recurse|Sort-Object FullName|ForEach-Object{
    $rel=$_.FullName.Substring($temp.Length+1).Replace('\','/')
    $entries+=([ordered]@{path=$rel;size=[int64]$_.Length;sha256=(Get-FileHash -LiteralPath $_.FullName -Algorithm SHA256).Hash.ToLowerInvariant()})
  }
  WriteJson $manifestPath ([ordered]@{format='sokna-support-bundle-manifest-v1';created_at_utc=[DateTime]::UtcNow.ToString('o');collector_extension='windows-services-diagnostics-v2';technician_report='sokna-technician-findings-v1';entries=$entries})

  $leaks=@()
  Get-ChildItem $temp -File -Recurse|Where-Object{$_.Extension -in @('.json','.jsonl','.log','.txt','.html')}|ForEach-Object{
    try{$raw=Get-Content -LiteralPath $_.FullName -Raw -ErrorAction Stop;if($raw-match'(?i)ws1_[a-f0-9]{24}_[a-f0-9]{48}'){$leaks+=$_.FullName};if($raw-match'(?i)(password|passwd|pwd|token|secret|credential)\s*[:=]\s*(?!\[REDACTED\])\S+'){$leaks+=$_.FullName}}catch{}
  }
  if($leaks.Count-gt0){throw ('Support bundle secret-canary scan failed: '+(($leaks|Select-Object -Unique)-join', '))}

  Remove-Item -LiteralPath $zip.FullName -Force
  Compress-Archive -Path (Join-Path $temp '*') -DestinationPath $zip.FullName -CompressionLevel Optimal -Force
  $hash=(Get-FileHash -LiteralPath $zip.FullName -Algorithm SHA256).Hash.ToLowerInvariant()
  Write-Host ('SOKNA_SUPPORT_BUNDLE='+$zip.FullName)
  Write-Host ('SOKNA_SUPPORT_SHA256='+$hash)
}finally{Remove-Item -LiteralPath $temp -Recurse -Force -ErrorAction SilentlyContinue}
){$globalCode='invalid_or_redacted'}
    $found=($hostRaw.evidence_available -eq $true)
    $p0Host=[ordered]@{
      format='sokna-host-readiness-safe-v1'
      evidence_available=$found
      status=$(if($found){'LIVE_READ_ONLY_ENGINE_INSPECTION'}else{'INSPECTION_UNAVAILABLE'})
      blocking_code=$(if($found){$globalCode}else{''})
      blockers=$(if($found){$safeCodes}else{@()})
      host_preparation_preconditions_met=($found -and $hostRaw.host_preparation_preconditions_met -eq $true)
      apache_healthy=($found -and $hostRaw.apache_healthy -eq $true)
      active_junction_qualified=($found -and $hostRaw.active_junction_qualified -eq $true)
      installed_identity_and_tree_qualified=($found -and $hostRaw.installed_identity_and_tree_qualified -eq $true)
      machine_binding_qualified=($found -and $hostRaw.machine_binding_qualified -eq $true)
      persistent_overlay_qualified=($found -and $hostRaw.persistent_overlay_qualified -eq $true)
      local_lifecycle_idle=($found -and $hostRaw.local_lifecycle_idle -eq $true)
      mutation_ledger_idle=($found -and $hostRaw.mutation_ledger_idle -eq $true)
    }
    $versionsOut=[ordered]@{}
    foreach($kind in @('supervisor','update_engine')) {
      $entry=$versionsRaw.$kind
      $sha=$(if([string]$entry.sha256 -match '^[a-fA-F0-9]{64}
  if(Test-Path -LiteralPath $manifestPath){Remove-Item -LiteralPath $manifestPath -Force}
  $entries=@()
  Get-ChildItem $temp -File -Recurse|Sort-Object FullName|ForEach-Object{
    $rel=$_.FullName.Substring($temp.Length+1).Replace('\','/')
    $entries+=([ordered]@{path=$rel;size=[int64]$_.Length;sha256=(Get-FileHash -LiteralPath $_.FullName -Algorithm SHA256).Hash.ToLowerInvariant()})
  }
  WriteJson $manifestPath ([ordered]@{format='sokna-support-bundle-manifest-v1';created_at_utc=[DateTime]::UtcNow.ToString('o');collector_extension='windows-services-diagnostics-v2';technician_report='sokna-technician-findings-v1';entries=$entries})

  $leaks=@()
  Get-ChildItem $temp -File -Recurse|Where-Object{$_.Extension -in @('.json','.jsonl','.log','.txt','.html')}|ForEach-Object{
    try{$raw=Get-Content -LiteralPath $_.FullName -Raw -ErrorAction Stop;if($raw-match'(?i)ws1_[a-f0-9]{24}_[a-f0-9]{48}'){$leaks+=$_.FullName};if($raw-match'(?i)(password|passwd|pwd|token|secret|credential)\s*[:=]\s*(?!\[REDACTED\])\S+'){$leaks+=$_.FullName}}catch{}
  }
  if($leaks.Count-gt0){throw ('Support bundle secret-canary scan failed: '+(($leaks|Select-Object -Unique)-join', '))}

  Remove-Item -LiteralPath $zip.FullName -Force
  Compress-Archive -Path (Join-Path $temp '*') -DestinationPath $zip.FullName -CompressionLevel Optimal -Force
  $hash=(Get-FileHash -LiteralPath $zip.FullName -Algorithm SHA256).Hash.ToLowerInvariant()
  Write-Host ('SOKNA_SUPPORT_BUNDLE='+$zip.FullName)
  Write-Host ('SOKNA_SUPPORT_SHA256='+$hash)
}finally{Remove-Item -LiteralPath $temp -Recurse -Force -ErrorAction SilentlyContinue}
){[string]$entry.sha256}else{''})
      $ver=[string]$entry.version
      if($ver -notmatch '^[0-9a-zA-Z._+-]{0,100}
  if(Test-Path -LiteralPath $manifestPath){Remove-Item -LiteralPath $manifestPath -Force}
  $entries=@()
  Get-ChildItem $temp -File -Recurse|Sort-Object FullName|ForEach-Object{
    $rel=$_.FullName.Substring($temp.Length+1).Replace('\','/')
    $entries+=([ordered]@{path=$rel;size=[int64]$_.Length;sha256=(Get-FileHash -LiteralPath $_.FullName -Algorithm SHA256).Hash.ToLowerInvariant()})
  }
  WriteJson $manifestPath ([ordered]@{format='sokna-support-bundle-manifest-v1';created_at_utc=[DateTime]::UtcNow.ToString('o');collector_extension='windows-services-diagnostics-v2';technician_report='sokna-technician-findings-v1';entries=$entries})

  $leaks=@()
  Get-ChildItem $temp -File -Recurse|Where-Object{$_.Extension -in @('.json','.jsonl','.log','.txt','.html')}|ForEach-Object{
    try{$raw=Get-Content -LiteralPath $_.FullName -Raw -ErrorAction Stop;if($raw-match'(?i)ws1_[a-f0-9]{24}_[a-f0-9]{48}'){$leaks+=$_.FullName};if($raw-match'(?i)(password|passwd|pwd|token|secret|credential)\s*[:=]\s*(?!\[REDACTED\])\S+'){$leaks+=$_.FullName}}catch{}
  }
  if($leaks.Count-gt0){throw ('Support bundle secret-canary scan failed: '+(($leaks|Select-Object -Unique)-join', '))}

  Remove-Item -LiteralPath $zip.FullName -Force
  Compress-Archive -Path (Join-Path $temp '*') -DestinationPath $zip.FullName -CompressionLevel Optimal -Force
  $hash=(Get-FileHash -LiteralPath $zip.FullName -Algorithm SHA256).Hash.ToLowerInvariant()
  Write-Host ('SOKNA_SUPPORT_BUNDLE='+$zip.FullName)
  Write-Host ('SOKNA_SUPPORT_SHA256='+$hash)
}finally{Remove-Item -LiteralPath $temp -Recurse -Force -ErrorAction SilentlyContinue}
){$ver=''}
      $versionsOut[$kind]=[ordered]@{
        present=($entry.present -eq $true);sha256=$sha;version=$ver
      }
    }
    $p0Versions=[ordered]@{
      format='sokna-physical-component-versions-v1'
      evidence_available=$true
      supervisor=$versionsOut.supervisor
      update_engine=$versionsOut.update_engine
    }
  }catch {
    # Expose type only; exception Message may contain private host paths.
    $p0Host.status='INSPECTION_UNAVAILABLE'
    $p0Host.error_type=$_.Exception.GetType().Name
  }finally {
    Remove-Item -LiteralPath $p0Temp -Recurse -Force -ErrorAction SilentlyContinue
  }
  $p0Dir=Join-Path $temp 'maintenance'
  New-Item -ItemType Directory -Path $p0Dir -Force|Out-Null
  WriteJson (Join-Path $p0Dir 'host-readiness-safe.json') $p0Host
  WriteJson (Join-Path $p0Dir 'physical-versions-safe.json') $p0Versions

  $manifestPath=Join-Path $temp 'bundle-manifest.json'
  if(Test-Path -LiteralPath $manifestPath){Remove-Item -LiteralPath $manifestPath -Force}
  $entries=@()
  Get-ChildItem $temp -File -Recurse|Sort-Object FullName|ForEach-Object{
    $rel=$_.FullName.Substring($temp.Length+1).Replace('\','/')
    $entries+=([ordered]@{path=$rel;size=[int64]$_.Length;sha256=(Get-FileHash -LiteralPath $_.FullName -Algorithm SHA256).Hash.ToLowerInvariant()})
  }
  WriteJson $manifestPath ([ordered]@{format='sokna-support-bundle-manifest-v1';created_at_utc=[DateTime]::UtcNow.ToString('o');collector_extension='windows-services-diagnostics-v2';technician_report='sokna-technician-findings-v1';entries=$entries})

  $leaks=@()
  Get-ChildItem $temp -File -Recurse|Where-Object{$_.Extension -in @('.json','.jsonl','.log','.txt','.html')}|ForEach-Object{
    try{$raw=Get-Content -LiteralPath $_.FullName -Raw -ErrorAction Stop;if($raw-match'(?i)ws1_[a-f0-9]{24}_[a-f0-9]{48}'){$leaks+=$_.FullName};if($raw-match'(?i)(password|passwd|pwd|token|secret|credential)\s*[:=]\s*(?!\[REDACTED\])\S+'){$leaks+=$_.FullName}}catch{}
  }
  if($leaks.Count-gt0){throw ('Support bundle secret-canary scan failed: '+(($leaks|Select-Object -Unique)-join', '))}

  Remove-Item -LiteralPath $zip.FullName -Force
  Compress-Archive -Path (Join-Path $temp '*') -DestinationPath $zip.FullName -CompressionLevel Optimal -Force
  $hash=(Get-FileHash -LiteralPath $zip.FullName -Algorithm SHA256).Hash.ToLowerInvariant()
  Write-Host ('SOKNA_SUPPORT_BUNDLE='+$zip.FullName)
  Write-Host ('SOKNA_SUPPORT_SHA256='+$hash)
}finally{Remove-Item -LiteralPath $temp -Recurse -Force -ErrorAction SilentlyContinue}
