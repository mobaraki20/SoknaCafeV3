param(
  [Parameter(Mandatory=$true)][string]$DataRoot,
  [Parameter(Mandatory=$true)][string]$OutputRoot,
  [ValidateRange(1,10)][int]$InstallerTransactions=5
)
$ErrorActionPreference='Stop'

try {
  if(-not('SoknaSupport.ConsoleWindow' -as [type])){
    Add-Type -TypeDefinition @'
using System;
using System.Runtime.InteropServices;
namespace SoknaSupport {
  public static class ConsoleWindow {
    [DllImport("kernel32.dll")] public static extern IntPtr GetConsoleWindow();
    [DllImport("user32.dll")] public static extern bool ShowWindow(IntPtr hWnd, int nCmdShow);
  }
}
'@ -Language CSharp -ErrorAction Stop
  }
  $console=[SoknaSupport.ConsoleWindow]::GetConsoleWindow()
  if($console-ne[IntPtr]::Zero){[void][SoknaSupport.ConsoleWindow]::ShowWindow($console,0)}
}catch{}

function Full([string]$p,[string]$label){
  if([string]::IsNullOrWhiteSpace($p)){throw "$label must be absolute."}
  try{$root=[IO.Path]::GetPathRoot($p);$full=[IO.Path]::GetFullPath($p)}catch{throw "$label must be an absolute valid path."}
  if([string]::IsNullOrWhiteSpace($root)-or-not(($root-match'^[A-Za-z]:[\\/]')-or$root.StartsWith('\\'))){throw "$label must be absolute."}
  return $full
}
function Redact([string]$v){
  if($null-eq$v){return ''}
  $v=$v-replace'(?i)ws1_[a-f0-9]{24}_[a-f0-9]{48}','[PAIRING_CODE_REDACTED]'
  $v=$v-replace'(?i)(authorization|cookie|csrf|password|passwd|pwd|token|secret|credential)(\s*[:=]\s*)[^\s,;\}\]]+','$1$2[REDACTED]'
  return $v
}
function WriteText([string]$p,[string]$v){[IO.File]::WriteAllText($p,(Redact $v),(New-Object Text.UTF8Encoding($false)))}
function WriteJson([string]$p,$v){WriteText $p ($v|ConvertTo-Json -Depth 12)}
function Capture([scriptblock]$b){try{Redact ((& $b 2>&1|Out-String -Width 280).TrimEnd())}catch{"ERROR: "+(Redact $_.Exception.Message)}}
function AddError([string]$provider,[string]$message){$script:collectionErrors+=([ordered]@{provider=$provider;error=(Redact $message)})}
function CopySafeTextTree([string]$src,[string]$dst){
  if(-not(Test-Path $src -PathType Container)){return}
  $root=[IO.Path]::GetFullPath($src).TrimEnd('\')
  Get-ChildItem $root -File -Recurse -ErrorAction SilentlyContinue|Where-Object{$_.Extension -in @('.json','.jsonl','.log','.txt') -and $_.Name -notmatch '(?i)(secret|token|credential|private|pairing|password)' }|ForEach-Object{
    $rel=$_.FullName.Substring($root.Length+1);$out=Join-Path $dst $rel;New-Item -ItemType Directory -Path ([IO.Path]::GetDirectoryName($out)) -Force|Out-Null
    try{$raw=Get-Content $_.FullName -Raw -ErrorAction Stop;if($raw.Length-gt5MB){$raw=$raw.Substring([Math]::Max(0,$raw.Length-5MB))};WriteText $out $raw}catch{AddError 'text-copy' $_.Exception.Message}
  }
}
function ServiceSnapshot([string]$name){
  $obj=[ordered]@{name=$name;exists=$false;state='missing';start_mode='';account='';path_name='';process_id=0;exit_code=$null;service_specific_exit_code=$null}
  try{
    $svc=Get-CimInstance Win32_Service -Filter ("Name='"+$name.Replace("'","''")+"'") -ErrorAction Stop
    if($null-ne$svc){$obj.exists=$true;$obj.state=[string]$svc.State;$obj.start_mode=[string]$svc.StartMode;$obj.account=[string]$svc.StartName;$obj.path_name=(Redact ([string]$svc.PathName));$obj.process_id=[int]$svc.ProcessId;$obj.exit_code=[int]$svc.ExitCode;$obj.service_specific_exit_code=[int]$svc.ServiceSpecificExitCode}
  }catch{AddError ('service-'+$name) $_.Exception.Message}
  return [pscustomobject]$obj
}
function PortListening([int]$port){
  try{return $null-ne(Get-NetTCPConnection -State Listen -LocalPort $port -ErrorAction Stop|Select-Object -First 1)}catch{
    try{return [bool]((& "$env:SystemRoot\System32\netstat.exe" -ano -p tcp 2>$null|Select-String -Pattern (':'+$port+'\s+.*LISTENING')).Count)}catch{return $false}
  }
}
function FileMeta([string]$path){
  if(-not(Test-Path -LiteralPath $path -PathType Leaf)){return $null}
  try{$f=Get-Item -LiteralPath $path -ErrorAction Stop;return [ordered]@{name=$f.Name;length=[int64]$f.Length;last_write_utc=$f.LastWriteTimeUtc.ToString('o');sha256=(Get-FileHash -LiteralPath $path -Algorithm SHA256).Hash.ToLowerInvariant()}}catch{AddError 'file-meta' $_.Exception.Message;return $null}
}

$DataRoot=Full $DataRoot 'DataRoot';$OutputRoot=Full $OutputRoot 'OutputRoot';New-Item -ItemType Directory -Path $OutputRoot -Force|Out-Null
$stamp=(Get-Date).ToString('yyyyMMdd-HHmmss');$work=Join-Path $env:TEMP ("SOKNA-Support-v2-$stamp-"+[guid]::NewGuid().ToString('N'));New-Item -ItemType Directory -Path $work -Force|Out-Null
$zip=Join-Path $OutputRoot "SOKNA-Support-v2-$stamp.zip";$collectionErrors=@()
try {
  $baselineOk=$false
  try{
    $core=Join-Path $PSScriptRoot 'collect-support-core.ps1'
    if(Test-Path $core -PathType Leaf){
      $legacy=Join-Path $work '_legacy-out';New-Item -ItemType Directory -Path $legacy -Force|Out-Null
      & $core -DataRoot $DataRoot -OutputRoot $legacy
      $legacyZip=Get-ChildItem $legacy -Filter '*.zip' -File|Sort-Object LastWriteTimeUtc -Descending|Select-Object -First 1
      if($legacyZip){$baseline=Join-Path $work 'baseline';Expand-Archive -LiteralPath $legacyZip.FullName -DestinationPath $baseline -Force;$baselineOk=$true}
      Remove-Item $legacy -Recurse -Force -ErrorAction SilentlyContinue
    }
  }catch{AddError 'baseline' $_.Exception.Message}

  $windowsDir=Join-Path $work 'windows';New-Item -ItemType Directory -Path $windowsDir -Force|Out-Null
  $svcDir=Join-Path $windowsDir 'services';New-Item -ItemType Directory -Path $svcDir -Force|Out-Null
  $serviceNames=@('SoknaRuntime','SoknaPrintWorker','SoknaMaintenanceSupervisor','SoknaApache','SoknaMariaDB','Spooler')
  $serviceSummary=@()
  foreach($name in $serviceNames){
    $snap=ServiceSnapshot $name;$serviceSummary+=$snap
    WriteJson (Join-Path $svcDir ($name+'-snapshot.json')) $snap
    WriteText (Join-Path $svcDir ($name+'-query.txt')) (Capture {& "$env:SystemRoot\System32\sc.exe" queryex $name})
    WriteText (Join-Path $svcDir ($name+'-config.txt')) (Capture {& "$env:SystemRoot\System32\sc.exe" qc $name})
    WriteText (Join-Path $svcDir ($name+'-failure.txt')) (Capture {& "$env:SystemRoot\System32\sc.exe" qfailure $name})
  }

  try{
    $os=Get-CimInstance Win32_OperatingSystem -ErrorAction Stop
    $tz=Get-TimeZone -ErrorAction Stop
    WriteJson (Join-Path $windowsDir 'os-boot.json') ([ordered]@{caption=$os.Caption;version=$os.Version;build=$os.BuildNumber;architecture=$os.OSArchitecture;last_boot_utc=$os.LastBootUpTime.ToUniversalTime().ToString('o');captured_utc=[DateTime]::UtcNow.ToString('o');timezone_id=$tz.Id;utc_offset_minutes=[int][TimeZoneInfo]::Local.GetUtcOffset((Get-Date)).TotalMinutes})
  }catch{AddError 'os-boot' $_.Exception.Message}
  WriteText (Join-Path $windowsDir 'disks.txt') (Capture {Get-CimInstance Win32_LogicalDisk -Filter 'DriveType=3'|Select-Object DeviceID,FileSystem,Size,FreeSpace|Format-Table -AutoSize})
  WriteText (Join-Path $windowsDir 'network.txt') (Capture {Get-CimInstance Win32_NetworkAdapterConfiguration -Filter 'IPEnabled=True'|Select-Object Description,IPAddress,DefaultIPGateway,DNSServerSearchOrder,DHCPEnabled|Format-List})
  WriteText (Join-Path $windowsDir 'scm-events.txt') (Capture {Get-WinEvent -FilterHashtable @{LogName='System';ProviderName='Service Control Manager';StartTime=(Get-Date).AddDays(-7)} -ErrorAction Stop|Where-Object{$_.Message-match'Sokna|MariaDB|Apache|Spooler'}|Select-Object -First 220 TimeCreated,Id,LevelDisplayName,Message|Format-List})
  WriteText (Join-Path $windowsDir 'application-events.txt') (Capture {Get-WinEvent -FilterHashtable @{LogName='Application';StartTime=(Get-Date).AddDays(-7)} -ErrorAction Stop|Where-Object{$_.ProviderName-match'(?i)(MariaDB|MySQL|Application Error|\.NET Runtime|Apache)' -or $_.Message-match'(?i)(SoknaMariaDB|mysqld|mariadbd|SoknaApache|SoknaRuntime|SoknaPrint|SoknaMaintenance)'}|Select-Object -First 240 TimeCreated,ProviderName,Id,LevelDisplayName,Message|Format-List})
  WriteText (Join-Path $windowsDir 'unexpected-reboot-events.txt') (Capture {Get-WinEvent -FilterHashtable @{LogName='System';StartTime=(Get-Date).AddDays(-14);Id=41,6008,1074,6006} -ErrorAction Stop|Select-Object -First 100 TimeCreated,ProviderName,Id,LevelDisplayName,Message|Format-List})

  $dbDir=Join-Path $work 'db';New-Item -ItemType Directory -Path $dbDir -Force|Out-Null
  $maria=$serviceSummary|Where-Object{$_.name-eq'SoknaMariaDB'}|Select-Object -First 1
  $defaultsFile='';$dataDir='';$mariaVersion=''
  if($maria -and $maria.exists){
    $pathName=[string]$maria.path_name
    $m=[regex]::Match($pathName,'(?i)--defaults-file(?:=|\s+)(?:"([^"]+)"|([^\s]+))')
    if($m.Success){if($m.Groups[1].Success){$defaultsFile=$m.Groups[1].Value}else{$defaultsFile=$m.Groups[2].Value}}
    if(-not[string]::IsNullOrWhiteSpace($defaultsFile)){
      try{$defaultsFile=[IO.Path]::GetFullPath($defaultsFile.Trim('"'))}catch{AddError 'mariadb-defaults-path' $_.Exception.Message;$defaultsFile=''}
      if($defaultsFile -and (Test-Path -LiteralPath $defaultsFile -PathType Leaf)){
        try{
          foreach($line in Get-Content -LiteralPath $defaultsFile -ErrorAction Stop){
            if($line-match'^\s*datadir\s*=\s*(.+?)\s*$'){$dataDir=$matches[1].Trim().Trim('"')}
          }
          if($dataDir){if(-not[IO.Path]::IsPathRooted($dataDir)){$dataDir=Join-Path ([IO.Path]::GetDirectoryName($defaultsFile)) $dataDir};$dataDir=[IO.Path]::GetFullPath($dataDir)}
        }catch{AddError 'mariadb-config' $_.Exception.Message}
      }
    }
    try{
      $exeMatch=[regex]::Match($pathName,'^\s*(?:"([^"]+\.exe)"|([^\s]+\.exe))','IgnoreCase')
      $exe='';if($exeMatch.Success){if($exeMatch.Groups[1].Success){$exe=$exeMatch.Groups[1].Value}else{$exe=$exeMatch.Groups[2].Value}}
      if($exe -and (Test-Path -LiteralPath $exe -PathType Leaf)){$mariaVersion=(Get-Item -LiteralPath $exe).VersionInfo.FileVersion}
    }catch{AddError 'mariadb-version' $_.Exception.Message}
  }
  $dbFiles=@()
  if($dataDir -and (Test-Path -LiteralPath $dataDir -PathType Container)){
    foreach($name in @('tc.log','aria_log_control','ibdata1','ib_logfile0','ib_logfile1')){$meta=FileMeta (Join-Path $dataDir $name);if($null-ne$meta){$dbFiles+=$meta}}
  }
  $port3306=PortListening 3306;$port18080=PortListening 18080
  $mariaRunning=($maria -and $maria.exists -and $maria.state-eq'Running')
  $dependencyClass='healthy'
  if(-not$mariaRunning){$dependencyClass='database_service_unavailable'}elseif(-not$port3306){$dependencyClass='database_listener_unavailable'}elseif(-not$port18080){$dependencyClass='local_http_listener_unavailable'}
  WriteJson (Join-Path $dbDir 'mariadb-prerequisite.json') ([ordered]@{service=$maria;version=$mariaVersion;defaults_file=$defaultsFile;data_dir=$dataDir;tcp_3306_listening=$port3306;local_18080_listening=$port18080;dependency_class=$dependencyClass;control_files=$dbFiles;raw_database_rows_collected=$false;database_password_collected=$false})
  WriteText (Join-Path $dbDir 'mariadb-events.txt') (Capture {Get-WinEvent -FilterHashtable @{LogName='Application';StartTime=(Get-Date).AddDays(-7)} -ErrorAction Stop|Where-Object{$_.ProviderName-match'(?i)(MariaDB|MySQL)' -or $_.Message-match'(?i)(SoknaMariaDB|mysqld|mariadbd|tc log|aria|innodb)'}|Select-Object -First 220 TimeCreated,ProviderName,Id,LevelDisplayName,Message|Format-List})

  $installerRoot=Join-Path $DataRoot 'Installer\logs';$dest=Join-Path $work 'installer';New-Item -ItemType Directory -Path $dest -Force|Out-Null
  if(Test-Path $installerRoot -PathType Container){foreach($tx in @(Get-ChildItem $installerRoot -Directory|Sort-Object LastWriteTimeUtc -Descending|Select-Object -First $InstallerTransactions)){CopySafeTextTree $tx.FullName (Join-Path $dest $tx.Name)}}
  $userBootstrap=Join-Path $env:LOCALAPPDATA 'SOKNA\Installer\logs';$userDest=Join-Path $work 'user-bootstrap';New-Item -ItemType Directory -Path $userDest -Force|Out-Null
  if(Test-Path $userBootstrap -PathType Container){foreach($tx in @(Get-ChildItem $userBootstrap -Directory|Sort-Object LastWriteTimeUtc -Descending|Select-Object -First $InstallerTransactions)){CopySafeTextTree $tx.FullName (Join-Path $userDest $tx.Name)}}

  # Maintenance Local-updater evidence: bounded, read-only, no file content,
  # raw database rows, credentials or private operation payloads.
  # This prevents repeated one-file-at-a-time operator diagnostics.
  $maintDir=Join-Path $work 'maintenance';New-Item -ItemType Directory -Path $maintDir -Force|Out-Null
  try{
    $recent=@()
    $ops=Join-Path $DataRoot 'Maintenance\operations'
    if(Test-Path -LiteralPath $ops -PathType Container){
      foreach($item in @(Get-ChildItem -LiteralPath $ops -File -Filter 'op-*.json'|Sort-Object LastWriteTimeUtc -Descending|Select-Object -First 25)){
        try{
          $op=Get-Content -LiteralPath $item.FullName -Raw|ConvertFrom-Json
          $recent+=([ordered]@{
            operation_id=[string]$op.operation_id;operation_type=[string]$op.operation_type
            state=[string]$op.state;result_code=[string]$op.result_code
            result_message=(Redact ([string]$op.result_message))
            updated_at_utc=[string]$op.updated_at_utc
            activation_performed=[bool]$op.activation_performed
            database_mutation_performed=[bool]$op.database_mutation_performed
          })
        }catch{AddError 'maintenance-operation-summary' $_.Exception.Message}
      }
    }
    WriteJson (Join-Path $maintDir 'recent-operations.json') ([ordered]@{format='sokna-maintenance-recent-operations-v1';operations=$recent})
  }catch{AddError 'maintenance-operations' $_.Exception.Message}
  try{
    $roots=@(Get-PSDrive -PSProvider FileSystem|ForEach-Object{Join-Path $_.Root 'SOKNA'}|Select-Object -Unique)
    $snapshots=@()
    foreach($root in $roots){
      $web=Join-Path $root 'Web'
      $persistent=Join-Path $root 'LocalWeb\persistent'
      if(-not(Test-Path -LiteralPath $web -PathType Container) -and -not(Test-Path -LiteralPath $persistent -PathType Container)){continue}
      $paths=@(
        (Join-Path $web 'public\local-recovery.php'),
        (Join-Path $persistent 'public\local-recovery.php')
      )
      $entry=@()
      foreach($candidate in $paths){
        $meta=FileMeta $candidate
        $reparse=$false
        if(Test-Path -LiteralPath $candidate){
          $reparse=[bool]((Get-Item -LiteralPath $candidate -Force).Attributes -band [IO.FileAttributes]::ReparsePoint)
        }
        $entry+=([ordered]@{
          path=$candidate;present=($null-ne$meta);size_bytes=$(if($meta){$meta.length}else{$null})
          sha256=$(if($meta){$meta.sha256}else{$null});reparse_point=$reparse
        })
      }
      $snapshots+=([ordered]@{install_root=$root;files=$entry})
    }
    WriteJson (Join-Path $maintDir 'stable-recovery-overlay.json') ([ordered]@{
      format='sokna-stable-recovery-overlay-diagnostics-v1'
      captured_at_utc=[DateTime]::UtcNow.ToString('o')
      reference_0315_sha256='fd36bf0170a357aa3d0a283e9989fa0b442607f238bb70b659c2def8f5791598'
      legacy_cafe_sha256='e23949e5522c42fc8e6cf661b302a2fbb23f0c2d6138a873bef8644c2a5ee7a9'
      installations=$snapshots
    })
  }catch{AddError 'maintenance-stable-recovery-overlay' $_.Exception.Message}

  # BK-2b whitelist-only diagnostic evidence. Never bundle DPAPI, policy, bearer or passphrase.
  try {
    $backupRoot=Join-Path $DataRoot 'Maintenance\backups'
    $backupStatePath=Join-Path $backupRoot 'schedule-state.json'
    $safe=[ordered]@{format='sokna-backup-support-v1';evidence_available=$false;state='DISABLED';code='NO_BACKUP_STATE';pending_backup_id='';pending_sha256='';pending_size=$null;last_verified_backup_id='';last_verified_sha256='';last_remote_receipt_id='';last_remote_verified_at_utc='';last_attempt_at_utc='';next_due_utc=''}
    if(Test-Path -LiteralPath $backupStatePath -PathType Leaf){
      $s=Get-Content -LiteralPath $backupStatePath -Raw -ErrorAction Stop|ConvertFrom-Json -ErrorAction Stop
      $safe.evidence_available=$true
      foreach($field in @('state','code')){
        $v=[string]$s.$field
        if($v -cmatch '^[A-Z][A-Z0-9_]{0,95}$'){$safe[$field]=$v}
      }
      foreach($pair in @(@('backupId','pending_backup_id'),@('artifactSha256','pending_sha256'),@('lastVerifiedBackupId','last_verified_backup_id'),@('lastVerifiedSha256','last_verified_sha256'),@('lastRemoteReceiptId','last_remote_receipt_id'))){
        $v=[string]$s.($pair[0])
        if($v -cmatch '^[A-Za-z0-9_-]{1,128}$'){$safe[$pair[1]]=$v}
      }
      if($null-ne $s.artifactSize -and [long]$s.artifactSize -ge 0){$safe.pending_size=[long]$s.artifactSize}
      foreach($pair in @(@('lastRemoteVerifiedAtUtc','last_remote_verified_at_utc'),@('lastAttemptAtUtc','last_attempt_at_utc'),@('nextDueUtc','next_due_utc'))){
        $v=[string]$s.($pair[0]);$when=[datetimeoffset]::MinValue
        if([datetimeoffset]::TryParse($v,[ref]$when)){$safe[$pair[1]]=$when.ToUniversalTime().ToString('o')}
      }
    }
    WriteJson (Join-Path $maintDir 'backup-status-safe.json') $safe
  }catch{AddError 'maintenance-backup-status' $_.Exception.GetType().Name}
  try {
    $events=@();$backupJournal=Join-Path $DataRoot 'Maintenance\maintenance-journal.jsonl'
    if(Test-Path -LiteralPath $backupJournal -PathType Leaf){
      foreach($line in @(Get-Content -LiteralPath $backupJournal -Tail 1000 -ErrorAction Stop)){
        try {
          $row=$line|ConvertFrom-Json -ErrorAction Stop
          $kind=[string]$row.event_type
          if($kind -notmatch '^backup\.'){$kind=[string]$row.type}
          if($kind -notmatch '^backup\.[a-z0-9_.-]{1,90}$'){continue}
          $details=$row.details;$safeCode='';$safeType=''
          if($null-ne $details){
            $v=[string]$details.code
            if($v -cmatch '^[A-Z][A-Z0-9_]{0,95}$'){$safeCode=$v}
            $v=[string]$details.error_type
            if($v -cmatch '^[A-Za-z][A-Za-z0-9_.]{0,95}$'){$safeType=$v}
          }
          $stamp=[string]$row.occurred_at_utc
          if(-not $stamp){$stamp=[string]$row.timestamp_utc}
          $when=[datetimeoffset]::MinValue
          if(-not [datetimeoffset]::TryParse($stamp,[ref]$when)){$stamp=''}else{$stamp=$when.ToUniversalTime().ToString('o')}
          $events+=([ordered]@{event_type=$kind;timestamp_utc=$stamp;code=$safeCode;error_type=$safeType})
        }catch{}
      }
    }
    WriteJson (Join-Path $maintDir 'backup-events-safe.json') ([ordered]@{format='sokna-backup-events-safe-v1';events=@($events|Select-Object -Last 100)})
  }catch{AddError 'maintenance-backup-events' $_.Exception.GetType().Name}

  $completeness='complete';if($collectionErrors.Count-gt0){$completeness='partial'}
  $summary=[ordered]@{format='sokna-windows-support-v2';schema_version=2;created_at_utc=[DateTime]::UtcNow.ToString('o');computer_name=$env:COMPUTERNAME;collector='collect-support-v2.ps1';baseline_collected=$baselineOk;completeness=$completeness;dependency_class=$dependencyClass;ports=[ordered]@{local_http_18080=$port18080;mariadb_3306=$port3306};collection_errors=$collectionErrors;note='Bounded text evidence is redacted. Secrets, pairing codes, credentials, raw DB rows and private files are excluded.'}
  WriteJson (Join-Path $work 'support-v2-summary.json') $summary

  $manifestEntries=@()
  Get-ChildItem $work -File -Recurse|Where-Object{$_.FullName-ne(Join-Path $work 'bundle-manifest.json')}|ForEach-Object{
    $rel=$_.FullName.Substring($work.Length+1).Replace('\','/')
    $manifestEntries+=([ordered]@{path=$rel;size=[int64]$_.Length;sha256=(Get-FileHash -LiteralPath $_.FullName -Algorithm SHA256).Hash.ToLowerInvariant()})
  }
  WriteJson (Join-Path $work 'bundle-manifest.json') ([ordered]@{format='sokna-support-bundle-manifest-v1';created_at_utc=[DateTime]::UtcNow.ToString('o');completeness=$completeness;collection_errors=$collectionErrors;entries=$manifestEntries})

  $leaks=@()
  Get-ChildItem $work -File -Recurse|Where-Object{$_.Extension -in @('.json','.jsonl','.log','.txt')}|ForEach-Object{
    try{$raw=Get-Content -LiteralPath $_.FullName -Raw -ErrorAction Stop;if($raw-match'(?i)ws1_[a-f0-9]{24}_[a-f0-9]{48}'){$leaks+=$_.FullName};if($raw-match'(?i)(password|passwd|pwd|token|secret|credential)\s*[:=]\s*(?!\[REDACTED\])\S+'){$leaks+=$_.FullName}}catch{}
  }
  if($leaks.Count-gt0){throw ('Support bundle secret-canary scan failed: '+(($leaks|Select-Object -Unique|ForEach-Object{[IO.Path]::GetFileName($_)})-join','))}

  Compress-Archive -Path (Join-Path $work '*') -DestinationPath $zip -CompressionLevel Optimal -Force
  $hash=(Get-FileHash $zip -Algorithm SHA256).Hash.ToLowerInvariant();Write-Host ("SOKNA_SUPPORT_BUNDLE="+$zip);Write-Host ("SOKNA_SUPPORT_SHA256="+$hash)
}finally{Remove-Item $work -Recurse -Force -ErrorAction SilentlyContinue}
