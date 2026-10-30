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

# R24: universal, read-only inventory belongs to the existing support bundle,
# never to a host-specific command or a host-specific update package.
function UniversalFileFingerprint([string]$kind,[string]$path){
  $out=[ordered]@{component=$kind;status='not_found';exists=$false;path='';sha256=$null;size_bytes=$null;file_version=$null}
  if([string]::IsNullOrWhiteSpace($path)){return [pscustomobject]$out}
  try{
    if(-not(Test-Path -LiteralPath $path -PathType Leaf)){return [pscustomobject]$out}
    $item=Get-Item -LiteralPath $path -ErrorAction Stop
    $out.exists=$true;$out.path=$item.FullName;$out.size_bytes=[int64]$item.Length
    $out.sha256=(Get-FileHash -LiteralPath $item.FullName -Algorithm SHA256 -ErrorAction Stop).Hash.ToLowerInvariant()
    if($item.Extension -ieq '.exe'){$out.file_version=[string]$item.VersionInfo.FileVersion}
    $out.status='verified'
  }catch{$out.status='error';$out.sha256=$null;AddError 'universal-fingerprint' $_.Exception.Message}
  return [pscustomobject]$out
}
function UniversalServiceExe([string]$command){
  if([string]::IsNullOrWhiteSpace($command)){return ''}
  $m=[regex]::Match($command,'^\s*(?:"([^"]+\.exe)"|(.+?\.exe))', 'IgnoreCase')
  if(-not$m.Success){return ''}
  if($m.Groups[1].Success){return $m.Groups[1].Value}
  return $m.Groups[2].Value.Trim()
}
function UniversalLocalCandidateRoots([string]$dataRoot,$services){
  $roots=New-Object Collections.Generic.List[string]
  if($env:SOKNA_LOCAL_WEB_ROOT){$roots.Add($env:SOKNA_LOCAL_WEB_ROOT)}
  foreach($r in @($dataRoot,(Join-Path $dataRoot 'LocalWeb'),(Join-Path $dataRoot 'LocalWeb\Web'))){if($r){$roots.Add($r)}}
  foreach($drive in @(Get-PSDrive -PSProvider FileSystem -ErrorAction SilentlyContinue)){
    foreach($suffix in @('SOKNA\Web','SOKNA\LocalWeb\Web','SOKNA\LocalWeb')){
      $roots.Add((Join-Path $drive.Root $suffix))
    }
  }
  foreach($svc in @($services)){
    $exe=UniversalServiceExe ([string]$svc.path_name)
    if(-not$exe){continue}
    try{
      $dir=[IO.Path]::GetDirectoryName($exe)
      if(-not$dir){continue}
      $base=[IO.Directory]::GetParent($dir)
      if($null-eq$base){continue}
      foreach($rel in @('Web','LocalWeb\Web','LocalWeb')){
        $roots.Add((Join-Path $base.FullName $rel))
      }
      # Apache often resides beside the Web directory under one installation root.
      if(([string]$svc.name)-eq'SoknaApache' -and $null-ne$base.Parent){
        $roots.Add((Join-Path $base.Parent.FullName 'Web'))
      }
    }catch{AddError 'universal-layout' $_.Exception.Message}
  }
  return @($roots|Where-Object{$_}|Select-Object -Unique)
}
function CollectUniversalSupport([string]$dataRoot,$services){
  $items=@()
  foreach($map in @(
    @{name='runtime';svc='SoknaRuntime'},
    @{name='print-agent';svc='SoknaPrintWorker'},
    @{name='maintenance';svc='SoknaMaintenanceSupervisor'}
  )){
    $svc=@($services|Where-Object{$_.name-eq$map.svc}|Select-Object -First 1)
    $exe='';if($svc.Count){$exe=UniversalServiceExe ([string]$svc[0].path_name)}
    $items+=UniversalFileFingerprint $map.name $exe
  }
  $local=@()
  foreach($root in @(UniversalLocalCandidateRoots $dataRoot $services)){
    foreach($r in @($root,(Join-Path $root 'Web'))){
      $candidate=Join-Path $r 'src\Domain\Update\LocalUpdateService.php'
      if(Test-Path -LiteralPath $candidate -PathType Leaf){
        $fp=UniversalFileFingerprint 'local-updater-source' $candidate
        if($fp.status-eq'verified'){
          $versionPath=Join-Path $r 'VERSION.txt'
          $version='unknown'
          if(Test-Path -LiteralPath $versionPath -PathType Leaf){
            try{$v=(Get-Content -LiteralPath $versionPath -Raw -ErrorAction Stop).Trim();if($v.Length -le 128 -and $v -match '^[A-Za-z0-9_.+\-]+$'){$version=$v}}catch{AddError 'universal-local-version' $_.Exception.Message}
          }
          $local+=([pscustomobject][ordered]@{status='verified';source=$fp;version=$version})
        }
      }
    }
  }
  $local=@($local|Sort-Object {$_.source.path} -Unique)
  return [ordered]@{
    format='sokna-support-universal-fingerprints-v1';schema_version=1
    scope='read_only_source_identity';generated_at_utc=[DateTime]::UtcNow.ToString('o')
    package_identity_policy='universal_multi_baseline_not_host_specific'
    services=$items
    local=[ordered]@{status=$(if($local.Count){'verified'}else{'unknown'});matches=$local}
    database_rows_collected=$false;secret_file_contents_collected=$false
  }
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

  # Standard support collection: service + Local source fingerprints for ANY installation layout.
  # Always include this file in the normal support ZIP; no manual PowerShell follow-up.
  $universal=CollectUniversalSupport $DataRoot $serviceSummary
  WriteJson (Join-Path $windowsDir 'universal-source-inventory.json') $universal

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
