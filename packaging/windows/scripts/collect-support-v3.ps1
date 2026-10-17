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
if(-not(Test-Path -LiteralPath $core -PathType Leaf)){throw 'Support Bundle v2 core collector is missing.'}
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

  $manifestPath=Join-Path $temp 'bundle-manifest.json'
  if(Test-Path -LiteralPath $manifestPath){Remove-Item -LiteralPath $manifestPath -Force}
  $entries=@()
  Get-ChildItem $temp -File -Recurse|Sort-Object FullName|ForEach-Object{
    $rel=$_.FullName.Substring($temp.Length+1).Replace('\','/')
    $entries+=([ordered]@{path=$rel;size=[int64]$_.Length;sha256=(Get-FileHash -LiteralPath $_.FullName -Algorithm SHA256).Hash.ToLowerInvariant()})
  }
  WriteJson $manifestPath ([ordered]@{format='sokna-support-bundle-manifest-v1';created_at_utc=[DateTime]::UtcNow.ToString('o');collector_extension='legacy-print-agent-v1';entries=$entries})

  $leaks=@()
  Get-ChildItem $temp -File -Recurse|Where-Object{$_.Extension -in @('.json','.jsonl','.log','.txt')}|ForEach-Object{
    try{$raw=Get-Content -LiteralPath $_.FullName -Raw -ErrorAction Stop;if($raw-match'(?i)ws1_[a-f0-9]{24}_[a-f0-9]{48}'){$leaks+=$_.FullName};if($raw-match'(?i)(password|passwd|pwd|token|secret|credential)\s*[:=]\s*(?!\[REDACTED\])\S+'){$leaks+=$_.FullName}}catch{}
  }
  if($leaks.Count-gt0){throw ('Support bundle secret-canary scan failed: '+(($leaks|Select-Object -Unique)-join', '))}

  Remove-Item -LiteralPath $zip.FullName -Force
  Compress-Archive -Path (Join-Path $temp '*') -DestinationPath $zip.FullName -CompressionLevel Optimal -Force
  $hash=(Get-FileHash -LiteralPath $zip.FullName -Algorithm SHA256).Hash.ToLowerInvariant()
  Write-Host ('SOKNA_SUPPORT_BUNDLE='+$zip.FullName)
  Write-Host ('SOKNA_SUPPORT_SHA256='+$hash)
}finally{Remove-Item -LiteralPath $temp -Recurse -Force -ErrorAction SilentlyContinue}
