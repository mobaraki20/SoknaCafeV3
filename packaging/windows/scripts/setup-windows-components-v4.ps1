param([Parameter(Mandatory=$true)][string]$PlanFile)
$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest

function Full([string]$p,[string]$label){
  if([string]::IsNullOrWhiteSpace($p)){throw "$label must be absolute."}
  if($p-notmatch'^(?:[A-Za-z]:[\\/]|\\\\[^\\]+\\[^\\]+)'){throw "$label must be absolute."}
  [IO.Path]::GetFullPath($p)
}
function Safe([string]$v){$v=($v-replace'[\r\n\0]',' ').Trim();$v=$v-replace'(?i)ws1_[a-f0-9]{24}_[a-f0-9]{48}','[PAIRING_CODE_REDACTED]';$v=$v-replace'(?i)(token|secret|credential|password)(\s*[:=]\s*)[^\s,;\}\]]+','$1$2[REDACTED]';if($v.Length-gt1200){$v=$v.Substring(0,1200)};$v}
function Sha([string]$p){
  if(-not(Test-Path -LiteralPath $p -PathType Leaf)){return ''}
  $stream=[IO.File]::OpenRead($p)
  try{
    $algorithm=[Security.Cryptography.SHA256]::Create()
    try{$bytes=$algorithm.ComputeHash($stream);return ([BitConverter]::ToString($bytes)).Replace('-','').ToLowerInvariant()}
    finally{$algorithm.Dispose()}
  }finally{$stream.Dispose()}
}
function VersionOf([string]$p){if(-not(Test-Path -LiteralPath $p -PathType Leaf)){return ''};$info=[Diagnostics.FileVersionInfo]::GetVersionInfo($p);$v=$info.FileVersion;if(-not$v){$v=$info.ProductVersion};if($v){return ([string]$v).Split('+')[0]};''}
function Normalize-Version4([string]$v){
  if([string]::IsNullOrWhiteSpace($v)){return ''}
  $base=([string]$v).Trim().Split('+')[0].Split('-')[0]
  $tokens=@($base.Split('.')|Where-Object{$_-ne''})
  if($tokens.Count-lt1-or$tokens.Count-gt4){return ''}
  $parts=New-Object Collections.Generic.List[string]
  foreach($token in $tokens){if($token-notmatch'^\d+$'){return ''};$parts.Add(([int]$token).ToString())}
  while($parts.Count-lt4){$parts.Add('0')}
  $parts -join '.'
}
function Compare-Version([string]$a,[string]$b){$aa=Normalize-Version4 $a;$bb=Normalize-Version4 $b;if(-not$aa-or-not$bb){return -1};([Version]$aa).CompareTo([Version]$bb)}
function Service-Info([string]$name){
  $key="HKLM:\SYSTEM\CurrentControlSet\Services\$name";if(-not(Test-Path -LiteralPath $key)){return [pscustomobject]@{Installed=$false;State='';ImagePath='';StartMode='';WasRunning=$false}}
  $image=[string](Get-ItemProperty -LiteralPath $key -Name ImagePath -ErrorAction SilentlyContinue).ImagePath
  $svc=Get-CimInstance Win32_Service -Filter ("Name='"+$name+"'") -ErrorAction SilentlyContinue
  [pscustomobject]@{Installed=$true;State=$(if($svc){[string]$svc.State}else{'Unknown'});ImagePath=$image;StartMode=$(if($svc){[string]$svc.StartMode}else{''});WasRunning=($svc-and[string]$svc.State-eq'Running')}
}
function Under-OwnedRoot([string]$image,[string]$root){if([string]::IsNullOrWhiteSpace($image)){return $false};$root=[IO.Path]::GetFullPath($root).TrimEnd('\')+'\';$needle=$root.Replace('\','\\');return $image.IndexOf($root,[StringComparison]::OrdinalIgnoreCase)-ge0}
function Quote([string]$v){'"'+$v.Replace('"','\"')+'"'}
function Runtime-Cmd([string]$exe,[string]$cfg){(Quote $exe)+' --config '+(Quote $cfg)}
function Simple-Cmd([string]$exe){Quote $exe}
function Stop-Svc([string]$name){$svc=Get-Service -Name $name -ErrorAction SilentlyContinue;if(-not$svc){return};try{if($svc.Status-ne'Stopped'){Stop-Service -Name $name -ErrorAction Stop;$svc.WaitForStatus('Stopped',[TimeSpan]::FromSeconds(30))}}finally{$svc.Dispose()}}
function Delete-Svc([string]$name){$svc=Get-Service -Name $name -ErrorAction SilentlyContinue;if(-not$svc){return};$svc.Dispose();Stop-Svc $name;& "$env:SystemRoot\System32\sc.exe" delete $name|Out-Null;if($LASTEXITCODE-ne0){throw "Service deletion failed: $name"};for($i=0;$i-lt80;$i++){if(-not(Get-Service -Name $name -ErrorAction SilentlyContinue)){return};Start-Sleep -Milliseconds 100};throw "Service deletion timeout: $name"}
function Create-Svc([string]$name,[string]$cmd,[string]$display,[bool]$automatic){New-Service -Name $name -BinaryPathName $cmd -DisplayName $display -StartupType $(if($automatic){'Automatic'}else{'Manual'}) -ErrorAction Stop|Out-Null;if($automatic){Set-ItemProperty -LiteralPath ("HKLM:\SYSTEM\CurrentControlSet\Services\"+$name) -Name DelayedAutoStart -Type DWord -Value 1 -Force}}
function Start-Svc([string]$name){Start-Service -Name $name -ErrorAction Stop;$svc=Get-Service -Name $name -ErrorAction Stop;try{$svc.WaitForStatus('Running',[TimeSpan]::FromSeconds(25))}finally{$svc.Dispose()}}
function Copy-DirAtomic([string]$src,[string]$dst){$parent=[IO.Path]::GetDirectoryName($dst);New-Item -ItemType Directory -Path $parent -Force|Out-Null;$stage=$dst+'.__incoming.'+[guid]::NewGuid().ToString('N');Copy-Item -LiteralPath $src -Destination $stage -Recurse -Force;if(Test-Path -LiteralPath $dst){Remove-Item -LiteralPath $dst -Recurse -Force};Move-Item -LiteralPath $stage -Destination $dst -Force}
function Snapshot-Dir([string]$src,[string]$dst){if(Test-Path -LiteralPath $src -PathType Container){Copy-Item -LiteralPath $src -Destination $dst -Recurse -Force;return $true};$false}
function Write-Json([string]$path,[object]$value){$value|ConvertTo-Json -Depth 12|Set-Content -LiteralPath $path -Encoding UTF8}

$PlanFile=Full $PlanFile 'PlanFile';if(-not(Test-Path -LiteralPath $PlanFile -PathType Leaf)){throw 'Plan file missing.'}
$plan=Get-Content -LiteralPath $PlanFile -Raw|ConvertFrom-Json
if([string]$plan.format-ne'sokna-windows-install-plan-v3'-or[int]$plan.schema_version-ne3){throw 'Unsupported install plan.'}
$ShellRoot=Full ([string]$plan.shell_root) 'ShellRoot';$InstallRoot=Full ([string]$plan.install_root) 'InstallRoot';$DataRoot=Full ([string]$plan.data_root) 'DataRoot'
$Tx=[string]$plan.transaction_id;$Corr=[string]$plan.correlation_id;$Session=[string]$plan.setup_session_id
$TxDir=[IO.Path]::GetDirectoryName($PlanFile);$Events=Join-Path $TxDir 'system-events.jsonl';$Result=Join-Path $TxDir 'install-result.json';$BackupRoot=Join-Path $TxDir 'rollback';New-Item -ItemType Directory -Path $BackupRoot -Force|Out-Null
$CurrentComponent='—';$CurrentPhase='Preflight';$Applied=New-Object Collections.Generic.List[string];$Snapshots=@{}
function Event([string]$name,[string]$result,[object]$data=$null){$row=[ordered]@{format='sokna-installer-event-v1';at_utc=[DateTime]::UtcNow.ToString('o');transaction_id=$Tx;correlation_id=$Corr;setup_session_id=$Session;event_name=$name;result=$result;component=$CurrentComponent;phase=$CurrentPhase;data=$data};Add-Content -LiteralPath $Events -Value ($row|ConvertTo-Json -Compress -Depth 8) -Encoding UTF8}
function Finish([string]$status,[int]$exit,[string]$summary,[bool]$rollback,[bool]$reboot){Write-Json $Result ([ordered]@{format='sokna-install-result-v1';transaction_id=$Tx;correlation_id=$Corr;completed_at_utc=[DateTime]::UtcNow.ToString('o');status=$status;exit_code=$exit;summary=(Safe $summary);failed_component=$(if($status-eq'completed'){'—'}else{$CurrentComponent});failed_phase=$(if($status-eq'completed'){'—'}else{$CurrentPhase});rollback_complete=$rollback;reboot_required=$reboot;components=@($resolved|ForEach-Object{[ordered]@{component_id=$_.Id;action=$_.Action;current_version=$_.CurrentVersion;target_version=$_.TargetVersion;before_sha256=$_.InstalledHash;target_sha256=$_.TargetHash}})})}

$manifestPath=Join-Path $ShellRoot 'windows-services-component-manifest-v2.json';if(-not(Test-Path -LiteralPath $manifestPath)){throw 'Component manifest missing.'};$manifest=Get-Content -LiteralPath $manifestPath -Raw|ConvertFrom-Json
if([string]$manifest.format-ne'sokna-windows-component-manifest-v2'-or[int]$manifest.schema_version-ne2){throw 'Component manifest invalid.'}
if([string]$manifest.package_version-ne[string]$plan.package_version){throw 'Package version mismatch.'}
$paired=$false;$statePath=Join-Path $DataRoot 'setup\windows-services-state.json';if(Test-Path -LiteralPath $statePath){try{$paired=[bool](Get-Content -LiteralPath $statePath -Raw|ConvertFrom-Json).paired}catch{}}

function Resolve-Component($m){
  $id=[string]$m.component_id;$target=Join-Path $ShellRoot (([string]$m.payload_primary).Replace('/','\'));$installed=Join-Path $InstallRoot (([string]$m.installed_primary).Replace('/','\'));$svcName=$(if($m.PSObject.Properties.Name-contains'service_name'){[string]$m.service_name}else{''});$svc=$(if($svcName){Service-Info $svcName}else{[pscustomobject]@{Installed=(Test-Path $installed);State='n/a';ImagePath='';StartMode='';WasRunning=$false}})
  $targetHash=Sha $target;if(-not$targetHash){throw "Target payload missing: $id"};$installedHash=Sha $installed;$current=VersionOf $installed;$exists=(Test-Path -LiteralPath $installed -PathType Leaf)-and($(if($svcName){[bool]$svc.Installed}else{$true}))
  $action='KEEP';$reason='verified'
  if(-not$exists){$action='INSTALL';$reason='missing'}else{
    $cmp=Compare-Version $current ([string]$m.version);if($cmp-gt0){$action='BLOCKED';$reason='installed_newer'}elseif($cmp-lt0){$action='UPDATE';$reason='version_older'}elseif($installedHash-ne$targetHash){$action='REPAIR';$reason='hash_drift'}
    if($svcName-and$action-ne'BLOCKED'){
      $ownedRoot=Split-Path -Parent $installed
      if(-not[bool]$svc.Installed){$action='INSTALL';$reason='service_missing'}
      elseif(-not(Under-OwnedRoot ([string]$svc.ImagePath) $InstallRoot)){$action='BLOCKED';$reason='service_image_outside_owned_root'}
      else{
        $expected=$(switch($id){'runtime'{Runtime-Cmd $installed (Join-Path $DataRoot 'runtime\runtime-config.json')};default{Simple-Cmd $installed}})
        if([string]$svc.ImagePath-cne$expected-and$action-eq'KEEP'){$action='REPAIR';$reason='service_registration_drift'}
      }
    }
  }
  if([string]$plan.mode-eq'repair'-and$action-eq'KEEP'){$action='REPAIR';$reason='explicit_repair'}
  [pscustomobject]@{Id=$id;Primary=[bool]$m.primary;Parent=$(if($m.PSObject.Properties.Name-contains'parent_component'){[string]$m.parent_component}else{''});TargetVersion=[string]$m.version;CurrentVersion=$(if($current){$current}else{'—'});Action=$action;Reason=$reason;TargetPath=$target;InstalledPath=$installed;TargetHash=$targetHash;InstalledHash=$installedHash;ServiceName=$svcName;Service=$svc;Group=[string]$m.transaction_group}
}

$resolved=@($manifest.components|ForEach-Object{Resolve-Component $_})
foreach($r in $resolved){if($r.Action-eq'BLOCKED'){throw "Unsafe component state: $($r.Id) / $($r.Reason)"}}
# Reconcile UI plan against privileged recomputation. Repair mode may intentionally turn a prior KEEP into REPAIR.
foreach($r in $resolved){$u=@($plan.components|Where-Object{[string]$_.component_id-eq$r.Id})|Select-Object -First 1;if(-not$u){throw "Component absent from UI plan: $($r.Id)"};$ua=([string]$u.action).Replace('_','');$ra=([string]$r.Action).Replace('_','');if($ua-ne$ra){throw "Component plan changed after elevation: $($r.Id), UI=$ua privileged=$ra"};if([string]$u.target_sha256-ne$r.TargetHash){throw "Target fingerprint changed after elevation: $($r.Id)"}}
Event 'installed_component_inventory_built' 'completed' ([ordered]@{components=@($resolved|ForEach-Object{[ordered]@{id=$_.Id;version=$_.CurrentVersion;action=$_.Action;reason=$_.Reason}})})

$runtimeRoot=Join-Path $InstallRoot 'Runtime';$runtimeExe=Join-Path $runtimeRoot 'SoknaRuntimeService.exe';$runtimeCfg=Join-Path $DataRoot 'runtime\runtime-config.json'
$printRoot=Join-Path $InstallRoot 'PrintAgent';$printExe=Join-Path $printRoot 'Service\Sokna.PrintAgent.Service.exe'
$maintRoot=Join-Path $InstallRoot 'Maintenance';$maintApp=Join-Path $maintRoot 'App';$maintRecovery=Join-Path $maintRoot 'Recovery';$maintExe=Join-Path $maintApp 'SoknaMaintenanceSupervisor.exe'

function Save-Snapshot([string]$id,[string]$root,[string]$service){$dst=Join-Path $BackupRoot $id;$exists=Snapshot-Dir $root $dst;$svc=$(if($service){Service-Info $service}else{$null});$Snapshots[$id]=[pscustomobject]@{Root=$root;Backup=$dst;Existed=$exists;Service=$service;WasRunning=$(if($svc){[bool]$svc.WasRunning}else{$false});WasInstalled=$(if($svc){[bool]$svc.Installed}else{$false})}}
function Restore-One([string]$id){if(-not$Snapshots.ContainsKey($id)){return};$s=$Snapshots[$id];if($s.Service){try{Delete-Svc $s.Service}catch{}};if(Test-Path $s.Root){Remove-Item -LiteralPath $s.Root -Recurse -Force};if($s.Existed){Copy-Item -LiteralPath $s.Backup -Destination $s.Root -Recurse -Force};if($s.WasInstalled-and$s.Existed){switch($id){'runtime'{Create-Svc 'SoknaRuntime' (Runtime-Cmd $runtimeExe $runtimeCfg) 'SOKNA Runtime' $s.WasRunning;if($s.WasRunning){Start-Svc 'SoknaRuntime'}};'print-agent'{Create-Svc 'SoknaPrintWorker' (Simple-Cmd $printExe) 'SOKNA Print Worker' $true;if($s.WasRunning){Start-Svc 'SoknaPrintWorker'}};'maintenance'{Create-Svc 'SoknaMaintenanceSupervisor' (Simple-Cmd $maintExe) 'SOKNA Maintenance Supervisor' $true;if($s.WasRunning){Start-Svc 'SoknaMaintenanceSupervisor'}}}}}
function Rollback-All(){Event 'rollback_started' 'started';$ok=$true;for($i=$Applied.Count-1;$i-ge0;$i--){$id=$Applied[$i];try{$script:CurrentComponent=$id;$script:CurrentPhase='Rollback';Restore-One $id;Event 'rollback_step' 'completed' @{component=$id}}catch{$ok=$false;Event 'rollback_step' 'failed' @{component=$id;error=(Safe $_.Exception.Message)}}};Event 'rollback_completed' $(if($ok){'completed'}else{'failed'});$ok}

function Apply-Runtime($row){$script:CurrentComponent='runtime';Save-Snapshot 'runtime' $runtimeRoot 'SoknaRuntime';$Applied.Add('runtime');$script:CurrentPhase='Service stop';Event 'component_action_started' 'started' @{action=$row.Action};if((Service-Info 'SoknaRuntime').Installed){Stop-Svc 'SoknaRuntime'};$script:CurrentPhase='Files';New-Item -ItemType Directory -Path $runtimeRoot -Force|Out-Null;Copy-Item -LiteralPath $row.TargetPath -Destination $runtimeExe -Force;if((Sha $runtimeExe)-ne$row.TargetHash){throw 'Runtime target hash verification failed after copy.'};$script:CurrentPhase='Service registration';Delete-Svc 'SoknaRuntime';Create-Svc 'SoknaRuntime' (Runtime-Cmd $runtimeExe $runtimeCfg) 'SOKNA Runtime' $paired;if($paired){$script:CurrentPhase='Health verification';Start-Svc 'SoknaRuntime'};Event 'component_action_completed' 'completed' @{action=$row.Action;after_sha256=(Sha $runtimeExe)}}
function Apply-Print($row){$script:CurrentComponent='print-agent';Save-Snapshot 'print-agent' $printRoot 'SoknaPrintWorker';$Applied.Add('print-agent');$script:CurrentPhase='Service stop';Event 'component_action_started' 'started' @{action=$row.Action};if((Service-Info 'SoknaPrintWorker').Installed){Stop-Svc 'SoknaPrintWorker'};$script:CurrentPhase='Files';Copy-DirAtomic (Join-Path $ShellRoot 'print-worker') $printRoot;if((Sha $printExe)-ne$row.TargetHash){throw 'Print Agent target hash verification failed after copy.'};$script:CurrentPhase='Service registration';Delete-Svc 'SoknaPrintWorker';Create-Svc 'SoknaPrintWorker' (Simple-Cmd $printExe) 'SOKNA Print Worker' $true;$script:CurrentPhase='Health verification';Start-Svc 'SoknaPrintWorker';Event 'component_action_completed' 'completed' @{action=$row.Action;after_sha256=(Sha $printExe)}}
function Apply-Maintenance($row){$script:CurrentComponent='maintenance';Save-Snapshot 'maintenance' $maintRoot 'SoknaMaintenanceSupervisor';$Applied.Add('maintenance');$script:CurrentPhase='Service stop';Event 'component_action_started' 'started' @{action=$row.Action};if((Service-Info 'SoknaMaintenanceSupervisor').Installed){Stop-Svc 'SoknaMaintenanceSupervisor'};$script:CurrentPhase='Files';Copy-DirAtomic (Join-Path $ShellRoot 'maintenance') $maintRoot;if((Sha $maintExe)-ne$row.TargetHash){throw 'Maintenance target hash verification failed after copy.'};$script:CurrentPhase='Service registration';Delete-Svc 'SoknaMaintenanceSupervisor';Create-Svc 'SoknaMaintenanceSupervisor' (Simple-Cmd $maintExe) 'SOKNA Maintenance Supervisor' $true;$script:CurrentPhase='Health verification';Start-Svc 'SoknaMaintenanceSupervisor';Event 'component_action_completed' 'completed' @{action=$row.Action;after_sha256=(Sha $maintExe)}}

try{
  if([string]$plan.mode-eq'uninstall'){
    $CurrentPhase='Uninstall';foreach($pair in @(@('runtime','SoknaRuntime',$runtimeRoot),@('print-agent','SoknaPrintWorker',$printRoot),@('maintenance','SoknaMaintenanceSupervisor',$maintRoot))){$CurrentComponent=$pair[0];Event 'component_action_started' 'started' @{action='UNINSTALL'};if((Service-Info $pair[1]).Installed){if(-not(Under-OwnedRoot ([string](Service-Info $pair[1]).ImagePath) $InstallRoot)){throw "Service ownership boundary mismatch: $($pair[1])"};Delete-Svc $pair[1]};if(Test-Path $pair[2]){Remove-Item -LiteralPath $pair[2] -Recurse -Force};Event 'component_action_completed' 'completed' @{action='UNINSTALL'}}
    $CurrentComponent='—';$CurrentPhase='Commit';Finish 'completed' 0 'Windows-owned services removed; ProgramData and business data preserved.' $false $false;exit 0
  }
  foreach($row in @($resolved|Where-Object{$_.Primary})){
    if($row.Action-eq'KEEP'){ $CurrentComponent=$row.Id;$CurrentPhase='No mutation';Event 'component_action_completed' 'kept' @{action='KEEP';proof='no_stop_no_copy_no_registration_no_restart'};continue }
    switch($row.Id){'runtime'{Apply-Runtime $row};'print-agent'{Apply-Print $row};'maintenance'{Apply-Maintenance $row};default{throw "Unknown primary component: $($row.Id)"}}
  }
  # Verify subordinate Maintenance fingerprints after the Maintenance transaction group.
  foreach($row in @($resolved|Where-Object{-not$_.Primary -and $_.Parent-eq'maintenance'})){if((Sha $row.InstalledPath)-ne$row.TargetHash){$CurrentComponent=$row.Id;$CurrentPhase='Health verification';throw "Maintenance subordinate fingerprint mismatch: $($row.Id)"};Event 'component_action_completed' 'completed' @{action=$row.Action;after_sha256=(Sha $row.InstalledPath)}}
  $CurrentComponent='—';$CurrentPhase='Commit';New-Item -ItemType Directory -Path (Join-Path $DataRoot 'setup') -Force|Out-Null
  $old=$null;if(Test-Path $statePath){try{$old=Get-Content -LiteralPath $statePath -Raw|ConvertFrom-Json}catch{}}
  $state=[ordered]@{format='sokna-windows-services-install-state-v2';package_owner='windows-services-packaging';package_version=[string]$plan.package_version;installed_at_utc=[DateTime]::UtcNow.ToString('o');install_root=$InstallRoot;data_root=$DataRoot;paired=$(if($old){[bool]$old.paired}else{$false});local_base_url=$(if($old){[string]$old.local_base_url}else{''});runtime_service='SoknaRuntime';print_service='SoknaPrintWorker';maintenance_service='SoknaMaintenanceSupervisor';component_actions=@($resolved|Where-Object{$_.Primary}|ForEach-Object{[ordered]@{component_id=$_.Id;action=$_.Action;version=$_.TargetVersion;sha256=(Sha $_.InstalledPath)}});external_infrastructure_mutated=$false;business_data_mutated=$false;transaction_id=$Tx}
  Write-Json $statePath $state;Event 'transaction_commit_completed' 'completed';Finish 'completed' 0 'Windows Services component plan completed successfully.' $false $false;exit 0
}catch{
  $msg=Safe $_.Exception.Message;Event 'component_action_failed' 'failed' @{error=$msg};$rb=Rollback-All;Finish 'failed' 2 $msg $rb $false;Write-Error $msg;exit 2
}