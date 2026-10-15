param(
  [Parameter(Mandatory=$true)][string]$SupervisorExe,
  [Parameter(Mandatory=$true)][string]$RecoveryConsoleExe
)
$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest

function Assert([bool]$Condition,[string]$Message){if(-not$Condition){throw $Message}}
function Wait-File([string]$Path,[int]$Seconds=20){
  $deadline=[DateTime]::UtcNow.AddSeconds($Seconds)
  while([DateTime]::UtcNow-lt$deadline){if(Test-Path -LiteralPath $Path -PathType Leaf){return};Start-Sleep -Milliseconds 150}
  throw "Timed out waiting for file: $Path"
}
function Invoke-Recovery([string]$DataRoot,[string]$Command){
  $output=@(& $RecoveryConsoleExe --data-root $DataRoot $Command 2>&1);$code=$LASTEXITCODE
  return [pscustomobject]@{Code=$code;Text=($output -join "`n")}
}
function Invoke-SignedGet([string]$BaseUrl,[string]$KeyPath,[string]$Path,[string]$Nonce){
  $key=[Convert]::FromBase64String((Get-Content -LiteralPath $KeyPath -Raw).Trim())
  $timestamp=[DateTimeOffset]::UtcNow.ToString('O',[Globalization.CultureInfo]::InvariantCulture)
  $bodyHash='e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855'
  $canonical="GET`n$Path`n1.0`n$timestamp`n$Nonce`n$bodyHash"
  $hmac=[Security.Cryptography.HMACSHA256]::new($key)
  try{$signature=[Convert]::ToHexString($hmac.ComputeHash([Text.Encoding]::UTF8.GetBytes($canonical))).ToLowerInvariant()}finally{$hmac.Dispose()}
  $headers=@{
    'X-Sokna-Maintenance-Protocol'='1.0'
    'X-Sokna-Timestamp'=$timestamp
    'X-Sokna-Nonce'=$Nonce
    'X-Sokna-Key-Id'='recovery-console'
    'X-Sokna-Body-SHA256'=$bodyHash
    'X-Sokna-Signature'=$signature
  }
  return Invoke-WebRequest -Uri ($BaseUrl.TrimEnd('/')+$Path) -Method Get -Headers $headers -SkipHttpErrorCheck -TimeoutSec 8
}
function Assert-SecretAcl([string]$SecretsRoot){
  $acl=Get-Acl -LiteralPath $SecretsRoot
  Assert $acl.AreAccessRulesProtected 'Secrets root must have inheritance disabled.'
  $allowed=@('S-1-5-18','S-1-5-32-544')
  foreach($rule in $acl.Access){
    if($rule.AccessControlType-ne[Security.AccessControl.AccessControlType]::Allow){continue}
    $sid=$rule.IdentityReference.Translate([Security.Principal.SecurityIdentifier]).Value
    Assert ($allowed-contains$sid) "Unexpected allow ACE on secrets root: $sid"
  }
}
function Stop-ProcessSafe($Process){
  if($null-eq$Process){return}
  try{if(-not$Process.HasExited){Stop-Process -Id $Process.Id -Force -ErrorAction SilentlyContinue;$Process.WaitForExit(5000)|Out-Null}}catch{}
}

$SupervisorExe=(Resolve-Path -LiteralPath $SupervisorExe).Path
$RecoveryConsoleExe=(Resolve-Path -LiteralPath $RecoveryConsoleExe).Path
Assert ([IO.Path]::GetFileName($SupervisorExe)-eq'SoknaMaintenanceSupervisor.exe') 'Unexpected Supervisor executable name.'
Assert ([IO.Path]::GetFileName($RecoveryConsoleExe)-eq'SoknaMaintenanceRecoveryConsole.exe') 'Unexpected Recovery Console executable name.'

$root=Join-Path $env:RUNNER_TEMP ('sokna-maintenance-p1-'+[guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $root -Force|Out-Null
$port=17631
$preexisting=@(Get-NetTCPConnection -LocalPort $port -State Listen -ErrorAction SilentlyContinue)
Assert ($preexisting.Count-eq0) 'Default Supervisor port 17631 is already occupied on qualification runner.'
$proc=$null
try{
  $proc=Start-Process -FilePath $SupervisorExe -ArgumentList @('--data-root',$root,'--port',"$port") -PassThru -WindowStyle Hidden
  $descriptorPath=Join-Path $root 'control-plane.json'
  Wait-File $descriptorPath
  $descriptor=Get-Content -LiteralPath $descriptorPath -Raw|ConvertFrom-Json
  Assert ([string]$descriptor.protocol-eq'1.0') 'Descriptor protocol mismatch.'
  Assert ([string]$descriptor.base_url-eq'http://127.0.0.1:17631/') 'Descriptor must freeze loopback default port 17631.'
  Assert ([bool]$descriptor.read_only_p1) 'Descriptor must declare read_only_p1.'
  Assert ([string]$descriptor.auth_scheme-eq'sokna-hmac-sha256-v1') 'Descriptor auth scheme mismatch.'

  $listener=@(Get-NetTCPConnection -OwningProcess $proc.Id -State Listen -ErrorAction Stop|Where-Object{$_.LocalPort-eq$port})
  Assert ($listener.Count-ge1) 'Supervisor listener was not observed.'
  foreach($row in $listener){Assert ([string]$row.LocalAddress-eq'127.0.0.1') "Supervisor exposed a non-loopback listener: $($row.LocalAddress)"}

  $health=Invoke-Recovery $root 'health'
  Assert ($health.Code-eq0) "Recovery Console health failed: $($health.Text)"
  $healthJson=$health.Text|ConvertFrom-Json
  Assert ([bool]$healthJson.ok) 'Authenticated health envelope must be ok.'
  Assert ([bool]$healthJson.data.read_only_p1) 'Health must declare read_only_p1.'
  Assert ([string]$healthJson.data.web_dependency-eq'none') 'Supervisor health must not depend on Web.'

  foreach($command in @('status','components','recovery','journal')){
    $result=Invoke-Recovery $root $command
    Assert ($result.Code-eq0) "Recovery Console command [$command] failed: $($result.Text)"
    $null=$result.Text|ConvertFrom-Json
  }

  $unauth=Invoke-WebRequest -Uri 'http://127.0.0.1:17631/v1/health' -SkipHttpErrorCheck -TimeoutSec 5
  Assert ([int]$unauth.StatusCode-eq401) 'Unsigned loopback request must be rejected.'
  $unauthJson=$unauth.Content|ConvertFrom-Json
  Assert ([string]$unauthJson.code-eq'AUTH_HEADERS_INVALID') 'Unsigned request error code mismatch.'

  $post=Invoke-WebRequest -Uri 'http://127.0.0.1:17631/v1/health' -Method Post -Body '{}' -ContentType 'application/json' -SkipHttpErrorCheck -TimeoutSec 5
  Assert ([int]$post.StatusCode-eq405) 'P1 mutation/non-GET must be rejected.'
  $postJson=$post.Content|ConvertFrom-Json
  Assert ([string]$postJson.code-eq'READ_ONLY_P1') 'P1 mutation rejection code mismatch.'

  $nonce=[guid]::NewGuid().ToString('N')
  $keyPath=Join-Path $root 'secrets\recovery-console.key'
  $first=Invoke-SignedGet 'http://127.0.0.1:17631/' $keyPath '/v1/health' $nonce
  Assert ([int]$first.StatusCode-eq200) 'First signed request should succeed.'
  $second=Invoke-SignedGet 'http://127.0.0.1:17631/' $keyPath '/v1/health' $nonce
  Assert ([int]$second.StatusCode-eq409) 'Replay of a signed nonce must be rejected.'
  $secondJson=$second.Content|ConvertFrom-Json
  Assert ([string]$secondJson.code-eq'AUTH_REPLAY') 'Replay error code mismatch.'

  Assert-SecretAcl (Join-Path $root 'secrets')
  $state=Get-Content -LiteralPath (Join-Path $root 'supervisor-state.json') -Raw|ConvertFrom-Json
  Assert ([int64]$state.install_generation-eq1) 'Initial install generation must be 1.'
  $journalLines=@(Get-Content -LiteralPath (Join-Path $root 'maintenance-journal.jsonl'))
  Assert ($journalLines.Count-ge2) 'Durable journal must contain starting + ready events.'

  $secondProcess=Start-Process -FilePath $SupervisorExe -ArgumentList @('--data-root',$root,'--port','17632') -PassThru -WindowStyle Hidden
  Assert ($secondProcess.WaitForExit(8000)) 'Second Supervisor process did not fail closed on lock contention.'
  Assert ($secondProcess.ExitCode-ne0) 'Second Supervisor process must fail on the same data-root lock.'
}
finally{
  Stop-ProcessSafe $proc
}

# Web-independent offline inspection: descriptor may be stale after forced process termination, so remove only discovery state.
Remove-Item -LiteralPath (Join-Path $root 'control-plane.json') -Force -ErrorAction SilentlyContinue
$offline=Invoke-Recovery $root 'status'
Assert ($offline.Code-eq1) "Offline Recovery Console should return degraded/read-only exit 1: $($offline.Text)"
$offlineJson=$offline.Text|ConvertFrom-Json
Assert ([string]$offlineJson.code-eq'OFFLINE_READ_ONLY_FALLBACK') 'Offline fallback code mismatch.'
Assert (-not[bool]$offlineJson.data.mutations_enabled) 'Offline fallback must never enable mutations in P1.'

# Qualify the same executable under the real Windows Service Control Manager.
$serviceName='SoknaMaintenanceSupervisor'
$serviceRoot=Join-Path $env:RUNNER_TEMP ('sokna-maintenance-p1-service-'+[guid]::NewGuid().ToString('N'))
$servicePort=17631
$existing=Get-Service -Name $serviceName -ErrorAction SilentlyContinue
Assert ($null-eq$existing) "Qualification runner already has service $serviceName."
$binPath='"'+$SupervisorExe+'" --data-root "'+$serviceRoot+'" --port '+$servicePort
try{
  New-Service -Name $serviceName -BinaryPathName $binPath -DisplayName 'SOKNA Maintenance Supervisor' -StartupType Manual|Out-Null
  Start-Service -Name $serviceName
  $svc=Get-Service -Name $serviceName
  $svc.WaitForStatus('Running',[TimeSpan]::FromSeconds(20))
  Wait-File (Join-Path $serviceRoot 'control-plane.json')
  $serviceHealth=Invoke-Recovery $serviceRoot 'health'
  Assert ($serviceHealth.Code-eq0) "Recovery Console could not authenticate to SCM-hosted Supervisor: $($serviceHealth.Text)"
  $before=(Get-Content -LiteralPath (Join-Path $serviceRoot 'supervisor-state.json') -Raw|ConvertFrom-Json)
  Stop-Service -Name $serviceName
  $svc.Refresh();$svc.WaitForStatus('Stopped',[TimeSpan]::FromSeconds(20))
  Start-Service -Name $serviceName
  $svc.Refresh();$svc.WaitForStatus('Running',[TimeSpan]::FromSeconds(20))
  Wait-File (Join-Path $serviceRoot 'control-plane.json')
  $after=(Get-Content -LiteralPath (Join-Path $serviceRoot 'supervisor-state.json') -Raw|ConvertFrom-Json)
  Assert ([string]$after.instance_id-eq[string]$before.instance_id) 'Supervisor instance identity must survive service restart.'
  Assert ([int64]$after.install_generation-eq[int64]$before.install_generation) 'Service restart must not invent a new install generation.'
}
finally{
  $svc=Get-Service -Name $serviceName -ErrorAction SilentlyContinue
  if($svc){try{if($svc.Status-ne'Stopped'){Stop-Service -Name $serviceName -Force -ErrorAction SilentlyContinue}}catch{};$svc.Dispose()}
  & sc.exe delete $serviceName|Out-Null
  for($i=0;$i-lt40;$i++){if(-not(Get-Service -Name $serviceName -ErrorAction SilentlyContinue)){break};Start-Sleep -Milliseconds 250}
}

[ordered]@{
  format='sokna-maintenance-v4-windows-p1-qualification-v1'
  result='PASS'
  protocol='1.0'
  default_port=17631
  supervisor_service='SoknaMaintenanceSupervisor'
  loopback_only='PASS'
  hmac_auth='PASS'
  replay_protection='PASS'
  read_only_mutation_fence='PASS'
  durable_state='PASS'
  durable_journal='PASS'
  single_instance_lock='PASS'
  secret_acl_fail_closed='PASS'
  recovery_console_web_independent='PASS'
  offline_read_only_fallback='PASS'
  scm_install_start_stop_restart='PASS'
  application_or_db_mutation='NOT_IMPLEMENTED_P1'
}|ConvertTo-Json -Depth 4
