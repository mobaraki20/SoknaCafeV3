param(
  [Parameter(Mandatory=$true)][ValidateSet('Install','Repair','Uninstall')][string]$Mode,
  [Parameter(Mandatory=$true)][string]$ShellRoot,
  [Parameter(Mandatory=$true)][string]$InstallRoot,
  [Parameter(Mandatory=$true)][string]$DataRoot,
  [string]$PairingFile='',
  [ValidateSet(0,1)][int]$StartWhenPaired=1
)
$ErrorActionPreference='Stop'
$RuntimeService='SoknaRuntime'
$PrintService='SoknaPrintWorker'

function Assert-Admin {
  $id=[Security.Principal.WindowsIdentity]::GetCurrent();$p=New-Object Security.Principal.WindowsPrincipal($id)
  if(-not $p.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)){throw 'Administrator elevation is required.'}
}
function Test-FullyQualifiedPath([string]$Path){
  if([string]::IsNullOrWhiteSpace($Path)){return $false}
  try{
    $root=[IO.Path]::GetPathRoot($Path)
    if([string]::IsNullOrWhiteSpace($root)){return $false}
    if($root -match '^[A-Za-z]:[\\/]'){return $true}
    if($root.StartsWith('\\')){return $true}
  }catch{return $false}
  return $false
}
function Full([string]$Path,[string]$Label){
  if(-not (Test-FullyQualifiedPath $Path)){throw "$Label must be an absolute path."}
  return [IO.Path]::GetFullPath($Path)
}
function Quote([string]$v){if($v -notmatch '[\s"]'){return $v};return '"'+($v -replace '(\\*)"','$1$1\\"' -replace '(\\+)$','$1$1')+'"'}
function Runtime-Command([string]$exe,[string]$config){return ((@($exe,'--config',$config)|ForEach-Object{Quote $_}) -join ' ')}
function Print-Command([string]$exe){return (Quote $exe)}
function Service-Image([string]$name){try{return [string](Get-ItemProperty -LiteralPath ("HKLM:\SYSTEM\CurrentControlSet\Services\"+$name) -ErrorAction Stop).ImagePath}catch{return ''}}
function Get-ServiceProcessId([string]$name){
  try{return [uint32](Get-CimInstance Win32_Service -Filter ("Name='"+$name+"'") -ErrorAction Stop).ProcessId}catch{return [uint32]0}
}
function Get-OwnedProcesses([string]$root){
  if([string]::IsNullOrWhiteSpace($root)){return @()}
  try{$root=[IO.Path]::GetFullPath($root).TrimEnd('\\')}catch{return @()}
  $prefix=$root+'\\'
  return @(Get-CimInstance Win32_Process -ErrorAction SilentlyContinue|Where-Object{
    $path=[string]$_.ExecutablePath
    -not[string]::IsNullOrWhiteSpace($path) -and ($path.Equals($root,[StringComparison]::OrdinalIgnoreCase) -or $path.StartsWith($prefix,[StringComparison]::OrdinalIgnoreCase))
  })
}
function Wait-ProcessExit([uint32]$processId,[int]$seconds,[string]$label){
  if($processId-le0){return}
  $deadline=[DateTime]::UtcNow.AddSeconds($seconds)
  while([DateTime]::UtcNow-lt$deadline){
    if(-not(Get-Process -Id $processId -ErrorAction SilentlyContinue)){return}
    Start-Sleep -Milliseconds 150
  }
  throw "$label process did not exit after service stop (PID $processId)."
}
function Wait-OwnedProcessesExit([string]$root,[int]$seconds,[bool]$forceAfterTimeout){
  if([string]::IsNullOrWhiteSpace($root)){return}
  $deadline=[DateTime]::UtcNow.AddSeconds($seconds)
  do{
    $rows=@(Get-OwnedProcesses $root)
    if($rows.Count-eq0){return}
    Start-Sleep -Milliseconds 200
  }while([DateTime]::UtcNow-lt$deadline)

  if($forceAfterTimeout){
    foreach($row in $rows){
      try{Stop-Process -Id ([int]$row.ProcessId) -Force -ErrorAction Stop}catch{}
    }
    $forceDeadline=[DateTime]::UtcNow.AddSeconds(5)
    do{
      $rows=@(Get-OwnedProcesses $root)
      if($rows.Count-eq0){return}
      Start-Sleep -Milliseconds 150
    }while([DateTime]::UtcNow-lt$forceDeadline)
  }

  $detail=($rows|ForEach-Object{([string]$_.Name)+'#'+([string]$_.ProcessId)}) -join ', '
  throw "Owned process shutdown did not complete under [$root]: $detail"
}
function Stop-Owned([string]$name,[string]$expected,[string]$ownedRoot){
  $svc=Get-Service -Name $name -ErrorAction SilentlyContinue
  if(-not$svc){
    $orphan=@(Get-OwnedProcesses $ownedRoot)
    if($orphan.Count-gt0){
      $detail=($orphan|ForEach-Object{([string]$_.Name)+'#'+([string]$_.ProcessId)}) -join ', '
      throw "Service $name is not registered but owned processes are still running: $detail"
    }
    return
  }
  $serviceProcessId=Get-ServiceProcessId $name
  try{
    if((Service-Image $name)-cne$expected){throw "Service ownership mismatch for $name."}
    if($svc.Status-ne'Stopped'){
      Stop-Service -Name $name -ErrorAction Stop
      $svc.WaitForStatus('Stopped',[TimeSpan]::FromSeconds(30))
    }
  }finally{$svc.Dispose()}

  # SCM reaching Stopped is not proof that the executable and native DLL handles are gone.
  # Wait for the exact service process first, then prove no SOKNA-owned process remains under its payload root.
  if($serviceProcessId-gt0){
    try{Wait-ProcessExit $serviceProcessId 15 $name}catch{
      $p=Get-Process -Id $serviceProcessId -ErrorAction SilentlyContinue
      if($p){try{Stop-Process -Id $serviceProcessId -Force -ErrorAction Stop}catch{}}
      Wait-ProcessExit $serviceProcessId 5 $name
    }
  }
  Wait-OwnedProcessesExit $ownedRoot 15 $true
}
function Invoke-ScChecked([string[]]$Arguments,[string]$Action){
  $output=@(& "$env:SystemRoot\System32\sc.exe" @Arguments 2>&1);$code=$LASTEXITCODE
  if($code-ne0){throw "$Action failed (sc.exe exit $code): $($output -join ' | ')"}
  return $output
}
function Delete-Owned([string]$name,[string]$expected,[string]$ownedRoot){
  $svc=Get-Service -Name $name -ErrorAction SilentlyContinue
  if(-not$svc){return}
  $svc.Dispose()
  Stop-Owned $name $expected $ownedRoot
  Invoke-ScChecked @('delete',$name) "Service deletion: $name"|Out-Null
  for($i=0;$i-lt100;$i++){
    $probe=Get-Service -Name $name -ErrorAction SilentlyContinue
    if(-not$probe){return}
    $probe.Dispose();Start-Sleep -Milliseconds 100
  }
  throw "Service deletion did not complete: $name"
}
function Create-Owned([string]$name,[string]$command,[string]$display){
  try{New-Service -Name $name -BinaryPathName $command -DisplayName $display -StartupType Manual -ErrorAction Stop|Out-Null}
  catch{throw "Service registration failed: $name. $($_.Exception.Message)"}
  $actual=Service-Image $name
  if($actual-cne$command){throw "Service ImagePath verification failed: $name. expected=[$command] actual=[$actual]"}
}
function Set-DelayedAutomatic([string]$name){
  Set-Service -Name $name -StartupType Automatic -ErrorAction Stop
  Set-ItemProperty -LiteralPath ("HKLM:\SYSTEM\CurrentControlSet\Services\"+$name) -Name DelayedAutoStart -Type DWord -Value 1 -Force
}
function Invoke-PathRetry([scriptblock]$Action,[scriptblock]$Completed,[string]$label){
  $delay=150;$last=$null
  for($attempt=1;$attempt-le10;$attempt++){
    try{
      & $Action
      if(& $Completed){return}
    }catch{$last=$_}
    if($attempt-lt10){Start-Sleep -Milliseconds $delay;$delay=[Math]::Min(2000,$delay*2)}
  }
  $detail=if($last){$last.Exception.Message}else{'operation did not reach the expected state'}
  throw "$label failed after retry window: $detail"
}
function Remove-PathRobust([string]$path,[string]$label){
  if(-not(Test-Path -LiteralPath $path)){return}
  Invoke-PathRetry {Remove-Item -LiteralPath $path -Recurse -Force -ErrorAction Stop} {-not(Test-Path -LiteralPath $path)} $label
}
function Move-PathRobust([string]$source,[string]$destination,[string]$label){
  Invoke-PathRetry {Move-Item -LiteralPath $source -Destination $destination -Force -ErrorAction Stop} {(Test-Path -LiteralPath $destination)-and-not(Test-Path -LiteralPath $source)} $label
}
function Copy-FileRobust([string]$source,[string]$destination,[string]$label){
  $dir=[IO.Path]::GetDirectoryName($destination);New-Item -ItemType Directory -Path $dir -Force|Out-Null
  Invoke-PathRetry {Copy-Item -LiteralPath $source -Destination $destination -Force -ErrorAction Stop} {Test-Path -LiteralPath $destination -PathType Leaf} $label
}
function Replace-DirectoryTransactional([string]$source,[string]$destination,[string[]]$requiredRelativeFiles){
  $parent=[IO.Path]::GetDirectoryName($destination);New-Item -ItemType Directory -Path $parent -Force|Out-Null
  $leaf=[IO.Path]::GetFileName($destination)
  $token=[guid]::NewGuid().ToString('N')
  $stage=Join-Path $parent ($leaf+'.__incoming.'+$token)
  $backup=Join-Path $parent ($leaf+'.__backup.'+$token)
  $oldMoved=$false;$newActivated=$false
  try{
    Copy-Item -LiteralPath $source -Destination $stage -Recurse -Force -ErrorAction Stop
    foreach($relative in $requiredRelativeFiles){
      if(-not(Test-Path -LiteralPath (Join-Path $stage $relative) -PathType Leaf)){throw "Staged Print Agent payload is incomplete: $relative"}
    }
    if(Test-Path -LiteralPath $destination){Move-PathRobust $destination $backup 'Print Agent backup rename';$oldMoved=$true}
    Move-PathRobust $stage $destination 'Print Agent activation rename';$newActivated=$true
  }catch{
    if($oldMoved-and-not$newActivated-and-not(Test-Path -LiteralPath $destination)-and(Test-Path -LiteralPath $backup)){
      try{Move-PathRobust $backup $destination 'Print Agent rollback rename'}catch{}
    }
    throw
  }finally{
    if(Test-Path -LiteralPath $stage){try{Remove-PathRobust $stage 'Print Agent staging cleanup'}catch{}}
  }
  if(Test-Path -LiteralPath $backup){
    try{Remove-PathRobust $backup 'Print Agent backup cleanup'}catch{Write-Warning $_.Exception.Message}
  }
}
function Configure-PrintDataRoot([string]$root){
  $root=Full $root 'Print Agent data root'
  New-Item -ItemType Directory -Path $root,(Join-Path $root 'logs'),(Join-Path $root 'work') -Force|Out-Null
  $key='HKLM:\SOFTWARE\Sokna\Local\PrintWorker'
  New-Item -Path $key -Force|Out-Null
  New-ItemProperty -Path $key -Name DataRoot -PropertyType String -Value $root -Force|Out-Null
  $actual=[string](Get-ItemProperty -LiteralPath $key -Name DataRoot -ErrorAction Stop).DataRoot
  if([IO.Path]::GetFullPath($actual)-cne$root){throw "Print Agent DataRoot verification failed. expected=[$root] actual=[$actual]"}
}
function Start-Owned([string]$name,[string]$startupFatal=''){
  try{
    Start-Service -Name $name -ErrorAction Stop
    $svc=Get-Service -Name $name -ErrorAction Stop
    try{$svc.WaitForStatus('Running',[TimeSpan]::FromSeconds(20))}finally{$svc.Dispose()}
  }catch{
    $detail=$_.Exception.Message
    if(-not[string]::IsNullOrWhiteSpace($startupFatal)-and(Test-Path -LiteralPath $startupFatal -PathType Leaf)){
      try{
        $fatal=(Get-Content -LiteralPath $startupFatal -Raw -ErrorAction Stop).Replace([char]13,' ').Replace([char]10,' ').Trim()
        if($fatal.Length-gt1200){$fatal=$fatal.Substring(0,1200)}
        if($fatal){$detail+=' | startup-fatal: '+$fatal}
      }catch{}
    }
    throw "Service start failed: $name. $detail"
  }
}
function Write-Secret([string]$path,[string]$value){
  if([string]::IsNullOrWhiteSpace($value)-or$value.Length-lt32-or$value.Length-gt512){throw 'Pairing secret length is invalid.'}
  New-Item -ItemType Directory -Path ([IO.Path]::GetDirectoryName($path)) -Force|Out-Null
  [IO.File]::WriteAllText($path,$value,(New-Object Text.UTF8Encoding($false)))
  & icacls.exe $path /inheritance:r /grant:r 'SYSTEM:(F)' 'Administrators:(F)'|Out-Null
}
function Read-Pairing([string]$path){
  $raw=''
  $inline=[string]$env:SOKNA_WINDOWS_SERVICES_PAIRING_B64
  if(-not[string]::IsNullOrWhiteSpace($inline)){
    if(-not[string]::IsNullOrWhiteSpace($path)){throw 'Pairing file and pairing handoff cannot be used together.'}
    try{$bytes=[Convert]::FromBase64String($inline);if($bytes.Length-lt32-or$bytes.Length-gt65536){throw 'Pairing handoff size is invalid.'};$raw=[Text.Encoding]::UTF8.GetString($bytes)}
    catch{throw 'Pairing handoff is invalid.'}
    finally{$env:SOKNA_WINDOWS_SERVICES_PAIRING_B64=''}
  }elseif(-not[string]::IsNullOrWhiteSpace($path)){
    $path=Full $path 'Pairing file';if(-not(Test-Path -LiteralPath $path -PathType Leaf)){throw 'Pairing file not found.'};$raw=Get-Content -LiteralPath $path -Raw
  }else{return $null}
  $p=$raw|ConvertFrom-Json;$raw=''
  if([string]$p.format-ne'sokna-windows-services-pairing-v1'-or[int]$p.schema_version-ne1){throw 'Pairing file format is unsupported.'}
  $uri=$null
  if(-not[Uri]::TryCreate([string]$p.local_base_url,[UriKind]::Absolute,[ref]$uri)-or-not$uri.IsLoopback){throw 'Pairing local_base_url must be loopback.'}
  if($uri.Scheme-ne'http'-and$uri.Scheme-ne'https'){throw 'Pairing local_base_url scheme must be HTTP/HTTPS.'}
  if($uri.Port-lt1024-or$uri.Port-gt65535){throw 'Pairing local_base_url port must be between 1024 and 65535.'}
  if($uri.AbsolutePath-ne'/'-or-not[string]::IsNullOrEmpty($uri.Query)-or-not[string]::IsNullOrEmpty($uri.Fragment)-or-not[string]::IsNullOrEmpty($uri.UserInfo)){throw 'Pairing local_base_url must be an origin-only URL.'}
  $originUri=$null
  if(-not[Uri]::TryCreate([string]$p.local_bridge_allowed_origin,[UriKind]::Absolute,[ref]$originUri)-or-not$originUri.IsLoopback){throw 'Pairing local_bridge_allowed_origin must be loopback.'}
  if($originUri.Scheme-ne'http'-and$originUri.Scheme-ne'https'){throw 'Pairing local_bridge_allowed_origin scheme must be HTTP/HTTPS.'}
  if($originUri.Port-lt1024-or$originUri.Port-gt65535){throw 'Pairing local_bridge_allowed_origin port must be between 1024 and 65535.'}
  if($originUri.AbsolutePath-ne'/'-or-not[string]::IsNullOrEmpty($originUri.Query)-or-not[string]::IsNullOrEmpty($originUri.Fragment)-or-not[string]::IsNullOrEmpty($originUri.UserInfo)){throw 'Pairing local_bridge_allowed_origin must be an exact origin.'}
  $baseAuthority=$uri.GetLeftPart([UriPartial]::Authority);$bridgeAuthority=$originUri.GetLeftPart([UriPartial]::Authority)
  if(-not[string]::Equals($baseAuthority,$bridgeAuthority,[StringComparison]::OrdinalIgnoreCase)){throw 'Pairing Local Web URL and bridge origin must be the same origin.'}
  foreach($k in @('runtime_token','local_token','print_agent_token')){$v=[string]$p.$k;if($v.Length-lt32-or$v-match'^REPLACE_'){throw "Pairing secret is not production-ready: $k"}}
  return $p
}
function Write-TransientPrivateJson([string]$path,[string]$json){
  $dir=[IO.Path]::GetDirectoryName($path);New-Item -ItemType Directory -Path $dir -Force|Out-Null
  [IO.File]::WriteAllText($path,$json,(New-Object Text.UTF8Encoding($false)))
  & icacls.exe $path /inheritance:r /grant:r 'SYSTEM:(F)' 'Administrators:(F)'|Out-Null
}
function Clear-StalePairingFiles([string]$dir){
  if(-not(Test-Path -LiteralPath $dir -PathType Container)){return}
  Get-ChildItem -LiteralPath $dir -Filter 'sokna-print-pair-*.json' -File -ErrorAction SilentlyContinue|ForEach-Object{
    try{if($_.LastWriteTimeUtc-lt[DateTime]::UtcNow.AddMinutes(-15)){Remove-Item -LiteralPath $_.FullName -Force -ErrorAction SilentlyContinue}}catch{}
  }
}

if($env:OS -ne 'Windows_NT'){throw 'Windows services lifecycle runs on Windows only.'}
Assert-Admin
$ShellRoot=Full $ShellRoot 'ShellRoot';$InstallRoot=Full $InstallRoot 'InstallRoot';$DataRoot=Full $DataRoot 'DataRoot'
$compatPath=Join-Path $ShellRoot 'windows-services-compatibility-v1.json'
if(-not(Test-Path -LiteralPath $compatPath -PathType Leaf)){throw 'Windows services compatibility manifest is missing.'}
$compat=Get-Content -LiteralPath $compatPath -Raw|ConvertFrom-Json
if([string]$compat.format-ne'sokna-windows-services-compatibility-v1'-or[int]$compat.schema_version-ne1){throw 'Windows services compatibility manifest is unsupported.'}
if([string]$compat.external_infrastructure.owner-ne'external'-or[bool]$compat.external_infrastructure.installer_ownership){throw 'External infrastructure ownership contract is invalid.'}
$runtimeSource=Join-Path $ShellRoot 'SoknaRuntimeService.exe';$printSource=Join-Path $ShellRoot 'print-worker'
$runtimeRoot=Join-Path $InstallRoot 'Runtime';$runtimeExe=Join-Path $runtimeRoot 'SoknaRuntimeService.exe'
$printRoot=Join-Path $InstallRoot 'PrintAgent';$printExe=Join-Path $printRoot 'Service\Sokna.PrintAgent.Service.exe'
$runtimeConfig=Join-Path $DataRoot 'runtime\runtime-config.json';$runtimeToken=Join-Path $DataRoot 'runtime\runtime-token.private';$localToken=Join-Path $DataRoot 'runtime\local-token.private'
$printDataRoot=Join-Path $DataRoot 'print-worker';$printStartupFatal=Join-Path $printDataRoot 'logs\startup-fatal.json'
$pairingTransientRoot=Join-Path $DataRoot 'setup\transient-pairing';Clear-StalePairingFiles $pairingTransientRoot
$runtimeCmd=Runtime-Command $runtimeExe $runtimeConfig;$printCmd=Print-Command $printExe

if($Mode-eq'Uninstall'){
  Delete-Owned $RuntimeService $runtimeCmd $runtimeRoot
  Delete-Owned $PrintService $printCmd $printRoot
  [ordered]@{success=$true;mode='uninstall';services_removed=@($RuntimeService,$PrintService);shutdown_proof='service-status+process-exit';data_preserved=$true;external_infrastructure_mutated=$false}|ConvertTo-Json -Compress
  exit 0
}
if(-not(Test-Path -LiteralPath $runtimeSource -PathType Leaf)){throw 'Runtime payload missing.'}
$requiredPrint=@('component-manifest.json','Service\Sokna.PrintAgent.Service.exe','Worker\Sokna.PrintAgent.Worker.exe')
foreach($f in $requiredPrint){if(-not(Test-Path -LiteralPath (Join-Path $printSource $f)-PathType Leaf)){throw "Print Agent payload missing: $f"}}
$runtimeActual=([Diagnostics.FileVersionInfo]::GetVersionInfo($runtimeSource).ProductVersion).Split('+')[0]
if($runtimeActual-and$runtimeActual-ne[string]$compat.components.runtime.version){throw "Runtime payload version mismatch: $runtimeActual"}
$printManifest=Get-Content -LiteralPath (Join-Path $printSource 'component-manifest.json') -Raw|ConvertFrom-Json
$printActual='';foreach($n in @('version','component_version','agent_version')){if($printManifest.PSObject.Properties.Name-contains$n){$printActual=[string]$printManifest.$n;break}}
if($printActual-and$printActual-ne[string]$compat.components.'print-agent'.version){throw "Print Agent payload version mismatch: $printActual"}
$pair=Read-Pairing $PairingFile
New-Item -ItemType Directory -Path $InstallRoot,$DataRoot,(Join-Path $DataRoot 'runtime'),(Join-Path $DataRoot 'setup') -Force|Out-Null
Configure-PrintDataRoot $printDataRoot

# Shutdown proof must precede any mutation of installed binaries. This closes the 1.0.8 e_sqlite3.dll race.
Stop-Owned $RuntimeService $runtimeCmd $runtimeRoot
Stop-Owned $PrintService $printCmd $printRoot

Copy-FileRobust $runtimeSource $runtimeExe 'Runtime payload replacement'
Replace-DirectoryTransactional $printSource $printRoot $requiredPrint

if($pair){
  Write-Secret $runtimeToken ([string]$pair.runtime_token);Write-Secret $localToken ([string]$pair.local_token)
  $cfg=[ordered]@{contractVersion=1;instanceId=('runtime-'+[guid]::NewGuid().ToString('N'));dataRoot=(Join-Path $DataRoot 'runtime\state');healthPort=17621;runtimeTokenFile=$runtimeToken;localTokenFile=$localToken;localBaseUrl=[string]$pair.local_base_url;printAgentServiceName=$PrintService;supervisePrintAgent=$true;triggers=@($pair.runtime_triggers)}
  $cfg|ConvertTo-Json -Depth 8|Set-Content -LiteralPath $runtimeConfig -Encoding UTF8
  $private=Join-Path $pairingTransientRoot ('sokna-print-pair-'+[guid]::NewGuid().ToString('N')+'.json')
  try{
    $printPair=[ordered]@{server_base_url=[string]$pair.local_base_url;token=[string]$pair.print_agent_token;agent_name=$env:COMPUTERNAME;local_bridge_allowed_origin=[string]$pair.local_bridge_allowed_origin}|ConvertTo-Json -Compress
    Write-TransientPrivateJson $private $printPair;$printPair=''
    & $printExe --provision-file $private|Out-Null
    if($LASTEXITCODE-ne0){throw 'Print Agent pairing failed.'}
  }finally{Remove-Item -LiteralPath $private -Force -ErrorAction SilentlyContinue}
}
Delete-Owned $RuntimeService $runtimeCmd $runtimeRoot
Delete-Owned $PrintService $printCmd $printRoot
Create-Owned $RuntimeService $runtimeCmd 'SOKNA Runtime'
Create-Owned $PrintService $printCmd 'SOKNA Print Worker'
Set-DelayedAutomatic $PrintService
Start-Owned $PrintService $printStartupFatal
if($pair-and$StartWhenPaired-eq1){Set-DelayedAutomatic $RuntimeService;Start-Owned $RuntimeService}
$state=[ordered]@{format='sokna-windows-services-install-state-v1';package_owner='windows-services-packaging';installed_at_utc=[DateTime]::UtcNow.ToString('o');install_root=$InstallRoot;data_root=$DataRoot;print_data_root=$printDataRoot;paired=($null-ne$pair);runtime_service=$RuntimeService;print_service=$PrintService;shutdown_proof='service-status+process-exit';payload_replacement='transactional-print-agent';external_infrastructure_mutated=$false;business_data_mutated=$false}
$state|ConvertTo-Json -Depth 6|Set-Content -LiteralPath (Join-Path $DataRoot 'setup\windows-services-state.json') -Encoding UTF8
[ordered]@{success=$true;mode=$Mode.ToLowerInvariant();paired=($null-ne$pair);runtime_start=$(if($pair-and$StartWhenPaired-eq1){'started'}else{'manual_waiting_for_pairing'});print_agent='started_waiting_or_configured';shutdown_proof='service-status+process-exit';payload_replacement='transactional-print-agent';business_data_mutated=$false;external_infrastructure_mutated=$false}|ConvertTo-Json -Compress
