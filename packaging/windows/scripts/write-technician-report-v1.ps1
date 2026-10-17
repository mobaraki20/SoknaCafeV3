param([Parameter(Mandatory=$true)][string]$BundleRoot)
$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest

$BundleRoot=[IO.Path]::GetFullPath($BundleRoot).TrimEnd('\')
if(-not(Test-Path -LiteralPath $BundleRoot -PathType Container)){throw 'BundleRoot is missing.'}

function Rel([string]$path){
  $full=[IO.Path]::GetFullPath($path)
  if($full.StartsWith($BundleRoot+'\',[StringComparison]::OrdinalIgnoreCase)){return $full.Substring($BundleRoot.Length+1).Replace('\','/')}
  return $full.Replace('\','/')
}
function Safe([string]$v){
  if($null-eq$v){return ''}
  $v=([string]$v-replace'[\r\n\0]',' ').Trim()
  $v=$v-replace'(?i)ws1_[a-f0-9]{24}_[a-f0-9]{48}','[PAIRING_CODE_REDACTED]'
  $v=$v-replace'(?i)(authorization|cookie|csrf|password|passwd|pwd|token|secret|credential)(\s*[:=]\s*)[^\s,;\}\]]+','$1$2[REDACTED]'
  if($v.Length-gt600){$v=$v.Substring(0,600)}
  return $v
}
function Read-Json([string]$path){try{return (Get-Content -LiteralPath $path -Raw -ErrorAction Stop|ConvertFrom-Json)}catch{return $null}}
function Html([string]$v){return [Net.WebUtility]::HtmlEncode((Safe $v))}

$findings=New-Object Collections.Generic.List[object]
$seen=@{}
function Add-Finding([string]$id,[string]$severity,[string]$component,[string]$title,[string]$detail,[string]$evidence,[string]$recommendation){
  $key=$id+'|'+$component+'|'+$evidence+'|'+$detail
  if($seen.ContainsKey($key)){return}
  $seen[$key]=$true
  $findings.Add([pscustomobject][ordered]@{
    id=$id;severity=$severity;component=$component;title=(Safe $title);detail=(Safe $detail);evidence=(Safe $evidence);recommended_action=(Safe $recommendation)
  })
}

$installResults=@(Get-ChildItem -LiteralPath $BundleRoot -Filter 'install-result.json' -File -Recurse -ErrorAction SilentlyContinue|Sort-Object FullName)
foreach($file in $installResults){
  $r=Read-Json $file.FullName
  if($null-eq$r){Add-Finding 'INSTALL_RESULT_UNREADABLE' 'warning' 'installer' 'Install result could not be parsed.' 'JSON parse failed.' (Rel $file.FullName) 'Inspect the original transaction artifacts.';continue}
  $status=[string]$r.status
  if($status-ne'completed'){
    $detail='status='+$status+'; exit_code='+[string]$r.exit_code+'; failed_component='+[string]$r.failed_component+'; failed_phase='+[string]$r.failed_phase+'; summary='+[string]$r.summary
    Add-Finding 'INSTALL_ATTEMPT_FAILED' 'error' 'installer' 'A Windows Services installation attempt failed.' $detail (Rel $file.FullName) 'Resolve the recorded failed component/phase before retrying the installer.'
  }
  if($r.PSObject.Properties.Name-contains'rollback_complete'){
    if($status-ne'completed'-and-not[bool]$r.rollback_complete){Add-Finding 'ROLLBACK_NOT_CONFIRMED' 'error' 'installer' 'Rollback was not confirmed after a failed installation.' ('status='+$status) (Rel $file.FullName) 'Inspect service/file state before another write attempt.'}
  }
}

$jsonFiles=@(Get-ChildItem -LiteralPath $BundleRoot -Filter '*.json' -File -Recurse -ErrorAction SilentlyContinue|Sort-Object FullName)
foreach($file in $jsonFiles){
  $obj=Read-Json $file.FullName
  if($null-eq$obj-or-not($obj.PSObject.Properties.Name-contains'components')){continue}
  foreach($c in @($obj.components)){
    if([string]$c.component_id-ne'maintenance-recovery-console'){continue}
    $action=([string]$c.action).Trim().ToUpperInvariant().Replace('_','')
    if($action-and$action-ne'KEEP'){
      Add-Finding 'RECOVERY_LIFECYCLE_CONFLICT' 'error' 'maintenance-recovery-console' 'Recovery Console was planned for a mutation unsupported by the Maintenance App lifecycle.' ('action='+$action+'; target_version='+[string]$c.target_version) (Rel $file.FullName) 'Use a package whose Recovery Console is KEEP, or a dedicated Recovery lifecycle.'
    }
  }
}

$eventFiles=@(Get-ChildItem -LiteralPath $BundleRoot -Filter 'system-events.jsonl' -File -Recurse -ErrorAction SilentlyContinue|Sort-Object FullName)
foreach($file in $eventFiles){
  foreach($line in @(Get-Content -LiteralPath $file.FullName -ErrorAction SilentlyContinue)){
    if([string]::IsNullOrWhiteSpace($line)){continue}
    try{$e=$line|ConvertFrom-Json}catch{continue}
    $name=[string]$e.event_name;$result=[string]$e.result
    if($name-eq'privileged_plan_preflight_failed'){
      Add-Finding 'PRIVILEGED_PREFLIGHT_FAILED' 'error' 'installer' 'Privileged plan preflight stopped the transaction before mutation.' ([string]$e.data.error) (Rel $file.FullName) 'Correct the plan/component conflict and generate a fresh plan before retrying.'
    }elseif($name-eq'preflight_failed'){
      Add-Finding 'CORE_PREFLIGHT_FAILED' 'error' 'installer' 'Elevated component preflight failed.' ([string]$e.data.error) (Rel $file.FullName) 'Correct the component state or package mismatch before retrying.'
    }elseif($name-eq'legacy_print_migration_failed'){
      Add-Finding 'LEGACY_PRINT_MIGRATION_FAILED' 'error' 'print-agent' 'Legacy Print migration did not complete.' ([string]$e.data.error) (Rel $file.FullName) 'Confirm the legacy service state and current Print Worker health before retrying.'
    }elseif($name-eq'rollback_completed'-and$result-eq'failed'){
      Add-Finding 'ROLLBACK_FAILED' 'critical' 'installer' 'Installer rollback reported failure.' 'At least one rollback step did not complete.' (Rel $file.FullName) 'Do not perform another write until service registrations and installed files are inspected.'
    }
  }
}

$legacySnapshot=Get-ChildItem -LiteralPath $BundleRoot -Filter 'SoknaPrintAgent6-snapshot.json' -File -Recurse -ErrorAction SilentlyContinue|Sort-Object FullName|Select-Object -First 1
if($null-ne$legacySnapshot){
  $legacy=Read-Json $legacySnapshot.FullName
  if($null-ne$legacy-and[bool]$legacy.exists){
    Add-Finding 'LEGACY_PRINT_SERVICE_PRESENT' 'warning' 'print-agent' 'Legacy SoknaPrintAgent6 service is still registered.' ('state='+[string]$legacy.state+'; start_mode='+[string]$legacy.start_mode) (Rel $legacySnapshot.FullName) 'Use the guarded migration path; preserve legacy files until the new Print Worker is verified stable.'
  }
}

$textFiles=@(Get-ChildItem -LiteralPath $BundleRoot -File -Recurse -ErrorAction SilentlyContinue|Where-Object{$_.Extension -in @('.txt','.log','.jsonl')}|Sort-Object FullName)
foreach($file in $textFiles){
  try{$raw=Get-Content -LiteralPath $file.FullName -Raw -ErrorAction Stop}catch{continue}
  if($raw-match'(?i)(sharing violation|being used by another process|file.{0,8}lock|cannot access the file because it is being used)'){
    Add-Finding 'FILE_LOCK_EVIDENCE' 'warning' 'filesystem' 'File/process lock evidence was captured.' ([string]$Matches[0]) (Rel $file.FullName) 'Identify the owning process/service and quiesce it before replacing the affected files.'
  }
}

$severityRank=@{critical=0;error=1;warning=2;info=3}
$ordered=@($findings|Sort-Object @{Expression={if($severityRank.ContainsKey($_.severity)){$severityRank[$_.severity]}else{9}}},id,component,evidence)
$overall='healthy'
if(@($ordered|Where-Object{$_.severity-eq'critical'}).Count-gt0){$overall='critical'}
elseif(@($ordered|Where-Object{$_.severity-eq'error'}).Count-gt0){$overall='error'}
elseif(@($ordered|Where-Object{$_.severity-eq'warning'}).Count-gt0){$overall='warning'}

$report=[ordered]@{
  format='sokna-technician-findings-v1';schema_version=1;overall_status=$overall;
  analyzed=[ordered]@{install_results=$installResults.Count;json_files=$jsonFiles.Count;event_streams=$eventFiles.Count;text_files=$textFiles.Count};
  finding_count=$ordered.Count;findings=$ordered
}
$utf8=New-Object Text.UTF8Encoding($false)
[IO.File]::WriteAllText((Join-Path $BundleRoot 'technician-findings.json'),($report|ConvertTo-Json -Depth 12),$utf8)

$lines=New-Object Collections.Generic.List[string]
$lines.Add('SOKNA WINDOWS SERVICES - TECHNICIAN SUMMARY')
$lines.Add('Overall status: '+$overall.ToUpperInvariant())
$lines.Add('Findings: '+$ordered.Count)
$lines.Add('Install results analyzed: '+$installResults.Count)
$lines.Add('')
if($ordered.Count-eq0){$lines.Add('No deterministic installer/service findings were detected in the captured evidence.')}
else{
  foreach($f in $ordered){
    $lines.Add('['+$f.severity.ToUpperInvariant()+'] '+$f.id+' - '+$f.title)
    $lines.Add('Component: '+$f.component)
    $lines.Add('Evidence: '+$f.evidence)
    if($f.detail){$lines.Add('Detail: '+$f.detail)}
    if($f.recommended_action){$lines.Add('Action: '+$f.recommended_action)}
    $lines.Add('')
  }
}
[IO.File]::WriteAllLines((Join-Path $BundleRoot 'TECHNICIAN-SUMMARY.txt'),$lines,$utf8)

$rows=New-Object Text.StringBuilder
foreach($f in $ordered){
  [void]$rows.Append('<tr><td><strong>'+[Net.WebUtility]::HtmlEncode($f.severity.ToUpperInvariant())+'</strong></td><td>'+[Net.WebUtility]::HtmlEncode($f.id)+'</td><td>'+[Net.WebUtility]::HtmlEncode($f.component)+'</td><td>'+[Net.WebUtility]::HtmlEncode($f.title)+'</td><td>'+[Net.WebUtility]::HtmlEncode($f.detail)+'</td><td>'+[Net.WebUtility]::HtmlEncode($f.evidence)+'</td><td>'+[Net.WebUtility]::HtmlEncode($f.recommended_action)+'</td></tr>')
}
if($ordered.Count-eq0){[void]$rows.Append('<tr><td colspan="7">No deterministic installer/service findings were detected in the captured evidence.</td></tr>')}
$html='<!doctype html><html><head><meta charset="utf-8"><title>SOKNA Technician Report</title><style>body{font-family:Segoe UI,Tahoma,sans-serif;margin:28px;color:#202124}h1{font-size:22px}table{border-collapse:collapse;width:100%;font-size:13px}th,td{border:1px solid #d7d7d7;padding:8px;vertical-align:top;text-align:left}th{background:#f4f4f4}.meta{margin-bottom:18px}</style></head><body><h1>SOKNA Windows Services - Technician Report</h1><div class="meta"><strong>Overall status:</strong> '+[Net.WebUtility]::HtmlEncode($overall.ToUpperInvariant())+'<br><strong>Findings:</strong> '+$ordered.Count+'<br><strong>Install results analyzed:</strong> '+$installResults.Count+'</div><table><thead><tr><th>Severity</th><th>ID</th><th>Component</th><th>Finding</th><th>Detail</th><th>Evidence</th><th>Recommended action</th></tr></thead><tbody>'+$rows.ToString()+'</tbody></table></body></html>'
[IO.File]::WriteAllText((Join-Path $BundleRoot 'TECHNICIAN-REPORT.html'),$html,$utf8)

Write-Host ('SOKNA_TECHNICIAN_STATUS='+$overall)
Write-Host ('SOKNA_TECHNICIAN_FINDINGS='+$ordered.Count)
