param([Parameter(Mandatory=$true)][string]$PlanFile)
$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest

function Full([string]$p,[string]$label){
  if([string]::IsNullOrWhiteSpace($p)){throw "$label must be absolute."}
  try{$root=[IO.Path]::GetPathRoot($p);$full=[IO.Path]::GetFullPath($p)}catch{throw "$label must be an absolute valid path."}
  if([string]::IsNullOrWhiteSpace($root)-or-not(($root-match'^[A-Za-z]:[\\/]')-or$root.StartsWith('\\'))){throw "$label must be absolute."}
  return $full
}
function Safe([string]$v){$v=([string]$v-replace'[\r\n\0]',' ').Trim();$v=$v-replace'(?i)ws1_[a-f0-9]{24}_[a-f0-9]{48}','[PAIRING_CODE_REDACTED]';$v=$v-replace'(?i)(token|secret|credential|password)(\s*[:=]\s*)[^\s,;\}\]]+','$1$2[REDACTED]';if($v.Length-gt1200){$v=$v.Substring(0,1200)};$v}
function Sha([string]$p){
  if(-not(Test-Path -LiteralPath $p -PathType Leaf)){return ''}
  $stream=[IO.File]::OpenRead($p)
  try{
    $hasher=[Security.Cryptography.SHA256]::Create()
    try{$bytes=$hasher.ComputeHash($stream)}finally{$hasher.Dispose()}
  }finally{$stream.Dispose()}
  return (([BitConverter]::ToString($bytes))-replace'-','').ToLowerInvariant()
}
function VersionOf([string]$p){if(-not(Test-Path $p -PathType Leaf)){return ''};$info=[Diagnostics.FileVersionInfo]::GetVersionInfo($p);$v=$info.FileVersion;if(-not$v){$v=$info.ProductVersion};if($v){$base=([string]$v).Split('+')[0];if($base-match'^\d+\.\d+\.\d+\.0$'){$base=$base.Substring(0,$base.Length-2)};return $base};''}
function Compare-Version([string]$a,[string]$b){
  function Parts([string]$v){
    $base=([string]$v).Split('+')[0].Split('-')[0].Trim()
    $tokens=@($base.Split('.')|Where-Object{$_-ne''})
    if($tokens.Count-lt1-or$tokens.Count-gt4){return $null}
    $parts=@(0,0,0,0)
    for($i=0;$i-lt$tokens.Count;$i++){$n=0;if(-not[int]::TryParse($tokens[$i],[ref]$n)-or$n-lt0){return $null};$parts[$i]=$n}
    return ,$parts
  }
  $pa=Parts $a;$pb=Parts $b
  if($null-eq$pa-or$null-eq$pb){return -1}
  for($i=0;$i-lt4;$i++){if($pa[$i]-gt$pb[$i]){return 1};if($pa[$i]-lt$pb[$i]){return -1}}
  return 0
}
function Prop($o,[string]$n,$default=$null){if($null-ne$o-and$o.PSObject.Properties.Name-contains$n){return $o.$n};$default}
function Service-Info([string]$name){
  $key="HKLM:\SYSTEM\CurrentControlSet\Services\$name"
  if(-not(Test-Path $key)){return [pscustomobject]@{Installed=$false;State='';ImagePath='';WasRunning=$false;ProcessId=[uint32]0}}
  $image=[string](Get-ItemProperty $key -Name ImagePath -ErrorAction SilentlyContinue).ImagePath
  $svc=Get-CimInstance Win32_Service -Filter ("Name='"+$name+"'") -ErrorAction SilentlyContinue
  [pscustomobject]@{Installed=$true;State=$(if($svc){[string]$svc.State}else{'Unknown'});ImagePath=$image;WasRunning=($svc-and[string]$svc.State-eq'Running');ProcessId=$(if($svc){[uint32]$svc.ProcessId}else{[uint32]0})}
}
function Service-Exe([string]$image){
  if([string]::IsNullOrWhiteSpace($image)){return ''}
  if($image-match'^\s*"([^"]+\.exe)"'){return [string]$Matches[1]}
  if($image-match'^\s*([^\s"]+\.exe)'){return [string]$Matches[1]}
  return ''
}
function Owned([string]$image,[string]$root){
  try{
    $exe=Service-Exe $image
    if([string]::IsNullOrWhiteSpace($exe)){return $false}
    $full=[IO.Path]::GetFullPath($exe)
    $prefix=[IO.Path]::GetFullPath($root).TrimEnd('\')+'\'
    return $full.StartsWith($prefix,[StringComparison]::OrdinalIgnoreCase)
  }catch{return $false}
}
function Quote([string]$v){'"'+$v.Replace('"','\"')+'"'}
function Runtime-Cmd([string]$exe,[string]$cfg){(Quote $exe)+' --config '+(Quote $cfg)}
function Simple-Cmd([string]$exe){Quote $exe}
function Registration-Matches([string]$id,[string]$image,[string]$installed,[string]$dataRoot){
  if([string]::IsNullOrWhiteSpace($image)){return $false}
  $normalized=$image.Trim()
  if($normalized.IndexOf($installed,[StringComparison]::OrdinalIgnoreCase)-lt0){return $false}
  if($id-eq'runtime'){
    $cfg=Join-Path $dataRoot 'runtime\runtime-config.json'
    return $normalized.IndexOf('--config',[StringComparison]::OrdinalIgnoreCase)-ge0-and$normalized.IndexOf($cfg,[StringComparison]::OrdinalIgnoreCase)-ge0
  }
  return $true
}
function Wait-ProcessExit([uint32]$processId,[int]$seconds,[string]$label){
  if($processId-le0){return}
  $deadline=[DateTime]::UtcNow.AddSeconds($seconds)
  while([DateTime]::UtcNow-lt$deadline){if(-not(Get-Process -Id $processId -ErrorAction SilentlyContinue)){return};Start-Sleep -Milliseconds 150}
  $process=Get-Process -Id $processId -ErrorAction SilentlyContinue
  if($process){try{Stop-Process -Id $processId -Force -ErrorAction Stop}catch{}}
  $deadline=[DateTime]::UtcNow.AddSeconds(5)
  while([DateTime]::UtcNow-lt$deadline){if(-not(Get-Process -Id $processId -ErrorAction SilentlyContinue)){return};Start-Sleep -Milliseconds 150}
  throw "$label process did not exit after service stop (PID $processId)."
}
function Get-OwnedProcesses([string]$root){
  if([string]::IsNullOrWhiteSpace($root)){return @()}
  try{$base=[IO.Path]::GetFullPath($root).TrimEnd('\')}catch{return @()}
  $prefix=$base+'\'
  return @(Get-CimInstance Win32_Process -ErrorAction SilentlyContinue|Where-Object{
    $path=[string]$_.ExecutablePath
    -not[string]::IsNullOrWhiteSpace($path)-and($path.Equals($base,[StringComparison]::OrdinalIgnoreCase)-or$path.StartsWith($prefix,[StringComparison]::OrdinalIgnoreCase))
  })
}
function Wait-OwnedProcessesExit([string]$root,[int]$seconds,[bool]$forceAfterTimeout){
  if([string]::IsNullOrWhiteSpace($root)){return}
  $deadline=[DateTime]::UtcNow.AddSeconds($seconds)
  do{$rows=@(Get-OwnedProcesses $root);if($rows.Count-eq0){return};Start-Sleep -Milliseconds 200}while([DateTime]::UtcNow-lt$deadline)
  if($forceAfterTimeout){
    foreach($row in $rows){try{Stop-Process -Id ([int]$row.ProcessId) -Force -ErrorAction Stop}catch{}}
    $deadline=[DateTime]::UtcNow.AddSeconds(5)
    do{$rows=@(Get-OwnedProcesses $root);if($rows.Count-eq0){return};Start-Sleep -Milliseconds 150}while([DateTime]::UtcNow-lt$deadline)
  }
  $detail=($rows|ForEach-Object{([string]$_.Name)+'#'+([string]$_.ProcessId)})-join', '
  throw "Owned process shutdown did not complete under [$root]: $detail"
}
function Stop-Svc([string]$name,[string]$ownedRoot){
  $probe=Service-Info $name
  if(-not$probe.Installed){if(-not[string]::IsNullOrWhiteSpace($ownedRoot)){Wait-OwnedProcessesExit $ownedRoot 1 $true};return}
  $processId=[uint32]$probe.ProcessId
  $svc=Get-Service $name -ErrorAction Stop
  try{
    if($svc.Status-ne'Stopped'){
      try{
        if($svc.Status-ne'StopPending'){Stop-Service -Name $name -ErrorAction Stop}
        $svc.WaitForStatus('Stopped',[TimeSpan]::FromSeconds(30))
      }catch{
        $scmError=Safe ([string]$_.Exception.Message)
        $errorType=$_.Exception.GetType().Name
        $svc.Refresh()
        if($svc.Status-eq'StopPending'){
          try{$svc.WaitForStatus('Stopped',[TimeSpan]::FromSeconds(15))}catch{$svc.Refresh()}
        }
        $svc.Refresh()
        if($svc.Status-ne'Stopped'){
          Event 'service_stop_rejected' 'failed' @{
            service=$name;service_state=[string]$svc.Status;
            can_stop=[bool]$svc.CanStop;pid=$processId;
            error_type=$errorType;scm_error=$scmError
          }
          throw "SERVICE_STOP_REJECTED: $name status=$($svc.Status) pid=$processId error_type=$errorType; $scmError"
        }
        Event 'service_stop_reconciled' 'completed' @{service=$name;pid=$processId;scm_error=$scmError}
      }
    }
  }finally{$svc.Dispose()}
  Wait-ProcessExit $processId 15 $name
  if(-not[string]::IsNullOrWhiteSpace($ownedRoot)){Wait-OwnedProcessesExit $ownedRoot 15 $true}
}
function Delete-Svc([string]$name,[string]$ownedRoot){
  $s=Get-Service $name -ErrorAction SilentlyContinue
  if(-not$s){if(-not[string]::IsNullOrWhiteSpace($ownedRoot)){Wait-OwnedProcessesExit $ownedRoot 1 $true};return}
  $s.Dispose();Stop-Svc $name $ownedRoot
  & "$env:SystemRoot\System32\sc.exe" delete $name|Out-Null
  if($LASTEXITCODE-ne0){throw "Service deletion failed: $name"}
  for($i=0;$i-lt100;$i++){$probe=Get-Service $name -ErrorAction SilentlyContinue;if(-not$probe){return};$probe.Dispose();Start-Sleep -Milliseconds 100}
  throw "Service deletion timeout: $name"
}
function Create-Svc([string]$name,[string]$cmd,[string]$display,[bool]$auto){New-Service -Name $name -BinaryPathName $cmd -DisplayName $display -StartupType $(if($auto){'Automatic'}else{'Manual'}) -ErrorAction Stop|Out-Null;if($auto){Set-ItemProperty ("HKLM:\SYSTEM\CurrentControlSet\Services\"+$name) -Name DelayedAutoStart -Type DWord -Value 1 -Force}}
function Start-Svc([string]$name){Start-Service $name -ErrorAction Stop;$s=Get-Service $name -ErrorAction Stop;try{$s.WaitForStatus('Running',[TimeSpan]::FromSeconds(25))}finally{$s.Dispose()}}
function Copy-TreeAtomic([string]$src,[string]$dst){if(-not(Test-Path $src -PathType Container)){throw "Payload root missing: $src"};$parent=[IO.Path]::GetDirectoryName($dst);New-Item -ItemType Directory -Path $parent -Force|Out-Null;$stage=$dst+'.__incoming.'+[guid]::NewGuid().ToString('N');$backup=$dst+'.__old.'+[guid]::NewGuid().ToString('N');Copy-Item $src $stage -Recurse -Force;$old=$false;try{if(Test-Path $dst){Move-Item $dst $backup -Force;$old=$true};Move-Item $stage $dst -Force;if($old-and(Test-Path $backup)){Remove-Item $backup -Recurse -Force}}catch{if(Test-Path $stage){Remove-Item $stage -Recurse -Force -ErrorAction SilentlyContinue};if($old-and-not(Test-Path $dst)-and(Test-Path $backup)){Move-Item $backup $dst -Force};throw}}
function Snapshot([string]$src,[string]$dst){if(Test-Path $src -PathType Container){Copy-Item $src $dst -Recurse -Force;return $true};$false}
function WriteJson([string]$p,$o){$o|ConvertTo-Json -Depth 12|Set-Content $p -Encoding UTF8}

$PlanFile=Full $PlanFile 'PlanFile';$plan=Get-Content $PlanFile -Raw|ConvertFrom-Json
if([string]$plan.format-ne'sokna-windows-install-plan-v3'-or[int]$plan.schema_version-ne3 -or [string](Prop $plan 'release_scope' '')-ne'maintenance_only' -or [string]$plan.mode-ne'update'){throw 'MAINTENANCE_ONLY_SCOPE_REQUIRED'}
$ShellRoot=Full ([string]$plan.shell_root) 'ShellRoot';$InstallRoot=Full ([string]$plan.install_root) 'InstallRoot';$DataRoot=Full ([string]$plan.data_root) 'DataRoot';$Tx=[string]$plan.transaction_id;$Corr=[string]$plan.correlation_id;$Session=[string]$plan.setup_session_id
$TxDir=[IO.Path]::GetDirectoryName($PlanFile);$Events=Join-Path $TxDir 'system-events.jsonl';$Result=Join-Path $TxDir 'install-result.json';$Backup=Join-Path $TxDir 'rollback';New-Item -ItemType Directory -Path $Backup -Force|Out-Null
$CurrentComponent='—';$CurrentPhase='Preflight';$Applied=New-Object Collections.Generic.List[string];$Snapshots=@{}
function Event([string]$name,[string]$result,$data=$null){$row=[ordered]@{format='sokna-installer-event-v1';at_utc=[DateTime]::UtcNow.ToString('o');transaction_id=$Tx;correlation_id=$Corr;setup_session_id=$Session;event_name=$name;result=$result;component=$CurrentComponent;phase=$CurrentPhase;data=$data};Add-Content $Events ($row|ConvertTo-Json -Compress -Depth 8) -Encoding UTF8}
function Finish([string]$status,[int]$code,[string]$summary,[bool]$rollback,[bool]$reboot,[string]$originalComponent='',[string]$originalPhase=''){WriteJson $Result ([ordered]@{format='sokna-install-result-v1';transaction_id=$Tx;correlation_id=$Corr;completed_at_utc=[DateTime]::UtcNow.ToString('o');status=$status;exit_code=$code;summary=(Safe $summary);failed_component=$(if($status-eq'completed'){'—'}elseif($originalComponent){$originalComponent}else{$CurrentComponent});failed_phase=$(if($status-eq'completed'){'—'}elseif($originalPhase){$originalPhase}else{$CurrentPhase});rollback_complete=$rollback;reboot_required=$reboot;components=@($script:Resolved|ForEach-Object{[ordered]@{component_id=$_.Id;action=$_.Action;current_version=$_.CurrentVersion;target_version=$_.TargetVersion;before_sha256=$_.InstalledHash;target_sha256=$_.TargetHash}})})}

$manifest=Get-Content (Join-Path $Sh