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
function VersionOf([string]$p){if(-not(Test-Path $p -PathType Leaf)){return ''};$v=[Diagnostics.FileVersionInfo]::GetVersionInfo($p).ProductVersion;if(-not$v){$v=[Diagnostics.FileVersionInfo]::GetVersionInfo($p).FileVersion};if($v){return ([string]$v).Split('+')[0]};''}
function Compare-Version([string]$a,[string]$b){$va=$null;$vb=$null;if(-not[Version]::TryParse(([string]$a).Split('-')[0],[ref]$va)){return -1};if(-not[Version]::TryParse(([string]$b).Split('-')[0],[ref]$vb)){return -1};$va.CompareTo($vb)}
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
  try{if($svc.Status-ne'Stopped'){Stop-Service $name -ErrorAction Stop;$svc.WaitForStatus('Stopped',[TimeSpan]::FromSeconds(30))}}finally{$svc.Dispose()}
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
if([string]$plan.format-ne'sokna-windows-install-plan-v3'-or[int]$plan.schema_version-ne3){throw 'Unsupported install plan.'}
$ShellRoot=Full ([string]$plan.shell_root) 'ShellRoot';$InstallRoot=Full ([string]$plan.install_root) 'InstallRoot';$DataRoot=Full ([string]$plan.data_root) 'DataRoot';$Tx=[string]$plan.transaction_id;$Corr=[string]$plan.correlation_id;$Session=[string]$plan.setup_session_id
$TxDir=[IO.Path]::GetDirectoryName($PlanFile);$Events=Join-Path $TxDir 'system-events.jsonl';$Result=Join-Path $TxDir 'install-result.json';$Backup=Join-Path $TxDir 'rollback';New-Item -ItemType Directory -Path $Backup -Force|Out-Null
$CurrentComponent='—';$CurrentPhase='Preflight';$Applied=New-Object Collections.Generic.List[string];$Snapshots=@{}
function Event([string]$name,[string]$result,$data=$null){$row=[ordered]@{format='sokna-installer-event-v1';at_utc=[DateTime]::UtcNow.ToString('o');transaction_id=$Tx;correlation_id=$Corr;setup_session_id=$Session;event_name=$name;result=$result;component=$CurrentComponent;phase=$CurrentPhase;data=$data};Add-Content $Events ($row|ConvertTo-Json -Compress -Depth 8) -Encoding UTF8}
function Finish([string]$status,[int]$code,[string]$summary,[bool]$rollback,[bool]$reboot){WriteJson $Result ([ordered]@{format='sokna-install-result-v1';transaction_id=$Tx;correlation_id=$Corr;completed_at_utc=[DateTime]::UtcNow.ToString('o');status=$status;exit_code=$code;summary=(Safe $summary);failed_component=$(if($status-eq'completed'){'—'}else{$CurrentComponent});failed_phase=$(if($status-eq'completed'){'—'}else{$CurrentPhase});rollback_complete=$rollback;reboot_required=$reboot;components=@($script:Resolved|ForEach-Object{[ordered]@{component_id=$_.Id;action=$_.Action;current_version=$_.CurrentVersion;target_version=$_.TargetVersion;before_sha256=$_.InstalledHash;target_sha256=$_.TargetHash}})})}

$manifest=Get-Content (Join-Path $ShellRoot 'windows-services-component-manifest-v2.json') -Raw|ConvertFrom-Json
if([string]$manifest.format-ne'sokna-windows-component-manifest-v2'-or[int]$manifest.schema_version-ne2-or[string]$manifest.package_version-ne[string]$plan.package_version){throw 'Component manifest mismatch.'}
$statePath=Join-Path $DataRoot 'setup\windows-services-state.json';$oldState=$null;if(Test-Path $statePath){try{$oldState=Get-Content $statePath -Raw|ConvertFrom-Json}catch{}};$paired=[bool](Prop $oldState 'paired' $false)

function Resolve-One($m){
  $id=[string]$m.component_id;$target=Join-Path $ShellRoot (([string]$m.payload_primary).Replace('/','\'));$installed=Join-Path $InstallRoot (([string]$m.installed_primary).Replace('/','\'));$svcName=[string](Prop $m 'service_name' '');$svc=$(if($svcName){Service-Info $svcName}else{[pscustomobject]@{Installed=(Test-Path $installed);State='n/a';ImagePath='';WasRunning=$false;ProcessId=[uint32]0}});$targetHash=Sha $target;if(-not$targetHash){throw "Target payload missing: $id"};$ih=Sha $installed;$current=VersionOf $installed;$exists=(Test-Path $installed -PathType Leaf)-and($(if($svcName){[bool]$svc.Installed}else{$true}));$action='KEEP';$reason='verified'
  if($svcName-and[bool]$svc.Installed-and-not(Owned ([string]$svc.ImagePath) $InstallRoot)){$action='BLOCKED';$reason='service_image_outside_owned_root'}
  elseif(-not$exists){$action='INSTALL';$reason='missing'}
  else{$cmp=Compare-Version $current ([string]$m.version);if($cmp-gt0){$action='BLOCKED';$reason='installed_newer'}elseif($cmp-lt0){$action='UPDATE';$reason='version_older'}elseif($ih-ne$targetHash){$action='REPAIR';$reason='hash_drift'};if($svcName-and$action-eq'KEEP'){$expected=$(if($id-eq'runtime'){Runtime-Cmd $installed (Join-Path $DataRoot 'runtime\runtime-config.json')}else{Simple-Cmd $installed});if([string]$svc.ImagePath-cne$expected){$action='REPAIR';$reason='service_registration_drift'}}}
  if([string]$plan.mode-eq'repair'-and$action-eq'KEEP'){$action='REPAIR';$reason='explicit_repair'}
  [pscustomobject]@{Id=$id;Primary=[bool]$m.primary;Parent=[string](Prop $m 'parent_component' '');TargetVersion=[string]$m.version;CurrentVersion=$(if($current){$current}else{'—'});Action=$action;Reason=$reason;TargetPath=$target;InstalledPath=$installed;TargetHash=$targetHash;InstalledHash=$ih;ServiceName=$svcName;Service=$svc;Group=[string]$m.transaction_group}
}
$script:Resolved=@($manifest.components|ForEach-Object{Resolve-One $_})
foreach($child in @($script:Resolved|Where-Object{-not$_.Primary-and$_.Parent})){if($child.Action-eq'BLOCKED'){$p=@($script:Resolved|Where-Object{$_.Id-eq$child.Parent})[0];$p.Action='BLOCKED';$p.Reason='subordinate_blocked'}elseif($child.Action-in@('INSTALL','UPDATE','REPAIR')){$p=@($script:Resolved|Where-Object{$_.Id-eq$child.Parent})[0];if($p.Action-eq'KEEP'){$p.Action='REPAIR';$p.Reason='subordinate_requires_sync'}}}
foreach($r in $script:Resolved){if($r.Action-eq'BLOCKED'){throw "Unsafe component state: $($r.Id) / $($r.Reason)"};$u=@($plan.components|Where-Object{[string]$_.component_id-eq$r.Id})|Select-Object -First 1;if(-not$u){throw "Component absent from UI plan: $($r.Id)"};if(([string]$u.action).Replace('_','')-ne([string]$r.Action).Replace('_','')){throw "Component plan changed after elevation: $($r.Id), UI=$($u.action), privileged=$($r.Action)"};if([string]$u.target_sha256-ne$r.TargetHash){throw "Target fingerprint changed after elevation: $($r.Id)"}}
Event 'installed_component_inventory_built' 'completed' @{components=@($script:Resolved|ForEach-Object{@{id=$_.Id;version=$_.CurrentVersion;action=$_.Action;reason=$_.Reason}})}

$runtimeRoot=Join-Path $InstallRoot 'Runtime';$runtimeExe=Join-Path $runtimeRoot 'SoknaRuntimeService.exe';$runtimeCfg=Join-Path $DataRoot 'runtime\runtime-config.json';$printRoot=Join-Path $InstallRoot 'PrintAgent';$printExe=Join-Path $printRoot 'Service\Sokna.PrintAgent.Service.exe';$maintRoot=Join-Path $InstallRoot 'Maintenance';$maintExe=Join-Path $maintRoot 'App\SoknaMaintenanceSupervisor.exe'
function SaveSnap([string]$id,[string]$root,[string]$svcName){$dst=Join-Path $Backup $id;$ex=Snapshot $root $dst;$svc=$(if($svcName){Service-Info $svcName}else{$null});$Snapshots[$id]=[pscustomobject]@{Root=$root;Backup=$dst;Existed=$ex;Service=$svcName;WasInstalled=$(if($svc){[bool]$svc.Installed}else{$false});WasRunning=$(if($svc){[bool]$svc.WasRunning}else{$false})}}
function Restore([string]$id){if(-not$Snapshots.ContainsKey($id)){return};$s=$Snapshots[$id];if($s.Service){Delete-Svc $s.Service $s.Root};if(Test-Path $s.Root){Remove-Item $s.Root -Recurse -Force};if($s.Existed){Copy-Item $s.Backup $s.Root -Recurse -Force};if($s.WasInstalled-and$s.Existed){switch($id){'runtime'{Create-Svc 'SoknaRuntime' (Runtime-Cmd $runtimeExe $runtimeCfg) 'SOKNA Runtime' $s.WasRunning;if($s.WasRunning){Start-Svc 'SoknaRuntime'}};'print-agent'{Create-Svc 'SoknaPrintWorker' (Simple-Cmd $printExe) 'SOKNA Print Worker' $true;if($s.WasRunning){Start-Svc 'SoknaPrintWorker'}};'maintenance'{Create-Svc 'SoknaMaintenanceSupervisor' (Simple-Cmd $maintExe) 'SOKNA Maintenance Supervisor' $true;if($s.WasRunning){Start-Svc 'SoknaMaintenanceSupervisor'}}}}}
function Rollback(){Event 'rollback_started' 'started';$ok=$true;for($i=$Applied.Count-1;$i-ge0;$i--){$id=$Applied[$i];try{$script:CurrentComponent=$id;$script:CurrentPhase='Rollback';Restore $id;Event 'rollback_step' 'completed'}catch{$ok=$false;Event 'rollback_step' 'failed' @{error=(Safe $_.Exception.Message)}}};Event 'rollback_completed' $(if($ok){'completed'}else{'failed'});$ok}
function ApplyRuntime($r){$script:CurrentComponent='runtime';SaveSnap 'runtime' $runtimeRoot 'SoknaRuntime';$Applied.Add('runtime');$script:CurrentPhase='Service stop';Event 'component_action_started' 'started' @{action=$r.Action};if((Service-Info 'SoknaRuntime').Installed){Stop-Svc 'SoknaRuntime' $runtimeRoot};$script:CurrentPhase='Files';New-Item -ItemType Directory -Path $runtimeRoot -Force|Out-Null;Copy-Item $r.TargetPath $runtimeExe -Force;if((Sha $runtimeExe)-ne$r.TargetHash){throw 'Runtime hash verification failed.'};$script:CurrentPhase='Service registration';Delete-Svc 'SoknaRuntime' $runtimeRoot;Create-Svc 'SoknaRuntime' (Runtime-Cmd $runtimeExe $runtimeCfg) 'SOKNA Runtime' $paired;if($paired){$script:CurrentPhase='Health verification';Start-Svc 'SoknaRuntime'};Event 'component_action_completed' 'completed' @{action=$r.Action;after_sha256=(Sha $runtimeExe);shutdown_proof='service-status+process-exit+owned-process-exit'}}
function ApplyPrint($r){$script:CurrentComponent='print-agent';SaveSnap 'print-agent' $printRoot 'SoknaPrintWorker';$Applied.Add('print-agent');$script:CurrentPhase='Service stop';Event 'component_action_started' 'started' @{action=$r.Action};if((Service-Info 'SoknaPrintWorker').Installed){Stop-Svc 'SoknaPrintWorker' $printRoot};$script:CurrentPhase='Files';$source=(Split-Path (Split-Path $r.TargetPath -Parent) -Parent);Copy-TreeAtomic $source $printRoot;if((Sha $printExe)-ne$r.TargetHash){throw 'Print Agent hash verification failed.'};$script:CurrentPhase='Service registration';Delete-Svc 'SoknaPrintWorker' $printRoot;Create-Svc 'SoknaPrintWorker' (Simple-Cmd $printExe) 'SOKNA Print Worker' $true;$script:CurrentPhase='Health verification';Start-Svc 'SoknaPrintWorker';Event 'component_action_completed' 'completed' @{action=$r.Action;after_sha256=(Sha $printExe);shutdown_proof='service-status+process-exit+owned-process-exit'}}
function ApplyMaintenance($r){$script:CurrentComponent='maintenance';SaveSnap 'maintenance' $maintRoot 'SoknaMaintenanceSupervisor';$Applied.Add('maintenance');$script:CurrentPhase='Service stop';Event 'component_action_started' 'started' @{action=$r.Action};if((Service-Info 'SoknaMaintenanceSupervisor').Installed){Stop-Svc 'SoknaMaintenanceSupervisor' $maintRoot};$script:CurrentPhase='Files';$source=(Split-Path (Split-Path $r.TargetPath -Parent) -Parent);Copy-TreeAtomic $source $maintRoot;if((Sha $maintExe)-ne$r.TargetHash){throw 'Maintenance hash verification failed.'};$script:CurrentPhase='Service registration';Delete-Svc 'SoknaMaintenanceSupervisor' $maintRoot;Create-Svc 'SoknaMaintenanceSupervisor' (Simple-Cmd $maintExe) 'SOKNA Maintenance Supervisor' $true;$script:CurrentPhase='Health verification';Start-Svc 'SoknaMaintenanceSupervisor';Event 'component_action_completed' 'completed' @{action=$r.Action;after_sha256=(Sha $maintExe);shutdown_proof='service-status+process-exit+owned-process-exit'}}

try{
 if([string]$plan.mode-eq'uninstall'){$CurrentPhase='Uninstall';foreach($x in @(@('runtime','SoknaRuntime',$runtimeRoot),@('print-agent','SoknaPrintWorker',$printRoot),@('maintenance','SoknaMaintenanceSupervisor',$maintRoot))){$CurrentComponent=$x[0];$svc=Service-Info $x[1];if($svc.Installed-and-not(Owned ([string]$svc.ImagePath) $InstallRoot)){throw "Service ownership boundary mismatch: $($x[1])"};Event 'component_action_started' 'started' @{action='UNINSTALL'};if($svc.Installed){Delete-Svc $x[1] $x[2]};if(Test-Path $x[2]){Remove-Item $x[2] -Recurse -Force};Event 'component_action_completed' 'completed'};$CurrentComponent='—';$CurrentPhase='Commit';Finish 'completed' 0 'Windows-owned services removed; ProgramData and business data preserved.' $false $false;exit 0}
 foreach($r in @($script:Resolved|Where-Object{$_.Primary})){if($r.Action-eq'KEEP'){$CurrentComponent=$r.Id;$CurrentPhase='No mutation';Event 'component_action_completed' 'kept' @{action='KEEP';proof='no_stop_no_copy_no_registration_no_restart'};continue};switch($r.Id){'runtime'{ApplyRuntime $r};'print-agent'{ApplyPrint $r};'maintenance'{ApplyMaintenance $r};default{throw "Unknown primary component: $($r.Id)"}}}
 foreach($r in @($script:Resolved|Where-Object{-not$_.Primary-and$_.Parent-eq'maintenance'})){if((Sha $r.InstalledPath)-ne$r.TargetHash){$CurrentComponent=$r.Id;$CurrentPhase='Health verification';throw "Maintenance subordinate fingerprint mismatch: $($r.Id)"};Event 'component_action_completed' 'completed' @{action=$r.Action;after_sha256=(Sha $r.InstalledPath)}}
 $CurrentComponent='—';$CurrentPhase='Commit';New-Item -ItemType Directory -Path (Join-Path $DataRoot 'setup') -Force|Out-Null;$state=[ordered]@{format='sokna-windows-services-install-state-v2';package_owner='windows-services-packaging';package_version=[string]$plan.package_version;installed_at_utc=[DateTime]::UtcNow.ToString('o');install_root=$InstallRoot;data_root=$DataRoot;paired=$paired;local_base_url=[string](Prop $oldState 'local_base_url' '');paired_at_utc=[string](Prop $oldState 'paired_at_utc' '');pairing_lifecycle=[string](Prop $oldState 'pairing_lifecycle' '');runtime_service='SoknaRuntime';print_service='SoknaPrintWorker';maintenance_service='SoknaMaintenanceSupervisor';component_actions=@($script:Resolved|Where-Object{$_.Primary}|ForEach-Object{@{component_id=$_.Id;action=$_.Action;version=$_.TargetVersion;sha256=(Sha $_.InstalledPath)}});shutdown_proof='per-mutated-component:service-status+process-exit+owned-process-exit';external_infrastructure_mutated=$false;business_data_mutated=$false;transaction_id=$Tx};WriteJson $statePath $state;Event 'transaction_commit_completed' 'completed';Finish 'completed' 0 'Windows Services component plan completed successfully.' $false $false;exit 0
}catch{$msg=Safe $_.Exception.Message;Event 'component_action_failed' 'failed' @{error=$msg};$rb=Rollback;Finish 'failed' 2 $msg $rb $false;Write-Error $msg;exit 2}
