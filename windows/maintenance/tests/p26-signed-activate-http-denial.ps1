param(
  [Parameter(Mandatory=$true)][string]$SupervisorDll,
  [Parameter(Mandatory=$true)][string]$SeedHarnessDll
)
$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest
# REAL HTTP + HMAC + hosted worker, but a disposable synthetic STAGE:
# negative fail-closed integration, explicitly NOT signed real release/install.
if($env:GITHUB_ACTIONS -ne 'true' -or $env:RUNNER_OS -ne 'Windows' -or
   [string]::IsNullOrWhiteSpace($env:RUNNER_TEMP)){
  throw 'P26_DISPOSABLE_GITHUB_WINDOWS_ONLY'
}
function Assert([bool]$condition,[string]$code){
  if(-not $condition){throw 'P26_'+$code}
}
$SupervisorDll=(Resolve-Path -LiteralPath $SupervisorDll).Path
$SeedHarnessDll=(Resolve-Path -LiteralPath $SeedHarnessDll).Path
$root=Join-Path $env:RUNNER_TEMP ('SoknaP26SignedHttp-'+[Guid]::NewGuid().ToString('N'))
$reg='HKLM:\SYSTEM\CurrentControlSet\Services\SoknaApache'
$fakeBin=Join-Path $root 'mock-Apache24\bin'
$fakeExe=Join-Path $fakeBin 'httpd.exe'
$regCreated=$false
$server=$null
$listener=[Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback,0)
$listener.Start()
$port=([Net.IPEndPoint]$listener.LocalEndpoint).Port
$listener.Stop()
$base="http://127.0.0.1:$port"
Assert (-not(Test-Path -LiteralPath $reg)) 'REFUSE_REAL_SOKNA_APACHE'
Assert (-not(Get-Service -Name SoknaMaintenanceSupervisor -ErrorAction SilentlyContinue)) 'REFUSE_INSTALLED_SUPERVISOR'
New-Item -ItemType Directory -Path $fakeBin -Force|Out-Null
Copy-Item -LiteralPath "$env:SystemRoot\System32\cmd.exe" -Destination $fakeExe
function Start-Supervisor {
  $process=Start-Process dotnet -ArgumentList @($SupervisorDll,'--data-root',$root,'--port',"$port") -PassThru
  $deadline=[DateTimeOffset]::UtcNow.AddSeconds(35)
  while([DateTimeOffset]::UtcNow -lt $deadline){
    Assert (-not $process.HasExited) 'SUPERVISOR_EXITED'
    $aclPath=Join-Path $root 'web-adapter-acl.json'
    if(Test-Path $aclPath){
      $acl=(Get-Content -LiteralPath $aclPath -Raw|ConvertFrom-Json)
      if($acl.state -eq 'ready' -and (Test-Path (Join-Path $root 'secrets/web-adapter.key'))){
        return $process
      }
    }
    Start-Sleep -Milliseconds 150
  }
  throw 'P26_SUPERVISOR_ACL_NOT_READY'
}
function Stop-Supervisor($p){
  if($null -ne $p -and -not $p.HasExited){
    Stop-Process -Id $p.Id -Force -ErrorAction SilentlyContinue
    [void]$p.WaitForExit(5000)
  }
}
function Signed([string]$verb,[string]$path,[string]$key,[object]$payload,[string]$idempotency=''){
  [byte[]]$body=[byte[]]::new(0)
  if($null -ne $payload){
    $body=[Text.Encoding]::UTF8.GetBytes(($payload|ConvertTo-Json -Compress -Depth 10))
  }
  $timestamp=[DateTimeOffset]::UtcNow.ToString('O',[Globalization.CultureInfo]::InvariantCulture)
  $nonce=[Convert]::ToHexString([Security.Cryptography.RandomNumberGenerator]::GetBytes(16)).ToLowerInvariant()
  $digest=[Convert]::ToHexString([Security.Cryptography.SHA256]::HashData($body)).ToLowerInvariant()
  $canonical=($verb,$path,'1.0',$timestamp,$nonce,$digest) -join [char]10
  $keyPath=Join-Path $root ('secrets/'+$key+'.key')
  $hmac=[Security.Cryptography.HMACSHA256]::new([Convert]::FromBase64String((Get-Content -LiteralPath $keyPath -Raw).Trim()))
  try{$signature=[Convert]::ToHexString($hmac.ComputeHash([Text.Encoding]::UTF8.GetBytes($canonical))).ToLowerInvariant()}
  finally{$hmac.Dispose()}
  $headers=@{
    'X-Sokna-Maintenance-Protocol'='1.0'
    'X-Sokna-Timestamp'=$timestamp
    'X-Sokna-Nonce'=$nonce
    'X-Sokna-Key-Id'=$key
    'X-Sokna-Body-SHA256'=$digest
    'X-Sokna-Signature'=$signature
  }
  if($idempotency){$headers['X-Sokna-Idempotency-Key']=$idempotency}
  $args=@{Uri=($base+$path);Method=$verb;Headers=$headers;SkipHttpErrorCheck=$true;TimeoutSec=10}
  if($body.Length){$args['Body']=$body;$args['ContentType']='application/json; charset=utf-8'}
  $response=Invoke-WebRequest @args
  return [pscustomobject]@{Status=[int]$response.StatusCode;Body=($response.Content|ConvertFrom-Json)}
}
try{
  # Test-only stand-in for service discovery; NOT a real Apache binary or web host.
  New-Item -Path $reg -Force|Out-Null
  $regCreated=$true
  New-ItemProperty -Path $reg -Name ImagePath -PropertyType ExpandString -Value ('"'+$fakeExe+'" -k runservice') -Force|Out-Null
  New-ItemProperty -Path $reg -Name ObjectName -PropertyType String -Value 'LocalSystem' -Force|Out-Null
  $seed=@(& dotnet $SeedHarnessDll --seed-http-denial $root)
  Assert ($LASTEXITCODE -eq 0 -and $seed.Count -eq 1 -and
    [string]$seed[0] -match '^[a-f0-9]{32}$') 'SYNTHETIC_STAGE_SEED_INVALID'
  $stageId=[string]$seed[0]
  $server=Start-Supervisor
  $cap=Signed 'GET' '/v1/updates/capabilities' 'web-adapter' $null
  Assert ($cap.Status -eq 200 -and -not[bool]$cap.Body.data.panel_update_ready) 'FALSE_PANEL_READY'
  $body=[ordered]@{stage_operation_id=$stageId;operator_id='user:ci'}
  $timer=[Diagnostics.Stopwatch]::StartNew()
  $accepted=Signed 'POST' '/v1/local-updates/activate-code-only' 'web-adapter' $body 'p26-http-real-202'
  $timer.Stop()
  Assert ($accepted.Status -eq 202 -and [bool]$accepted.Body.ok -and
    $accepted.Body.data.operation.state -eq 'accepted' -and
    [bool]$accepted.Body.data.completion_requires_operation_poll -and
    -not[bool]$accepted.Body.data.activation_committed -and
    -not[bool]$accepted.Body.data.panel_update_ready) 'HTTP_202_IS_NOT_DURABLE_PENDING'
  Assert ($timer.Elapsed.TotalSeconds -lt 10) 'HTTP_WAITED_FOR_ENGINE'
  $operationId=[string]$accepted.Body.operation_id
  Assert ($operationId -match '^[a-f0-9]{32}$' -and
    $operationId -eq [string]$accepted.Body.data.operation.operation_id) 'OPERATION_ID_DRIFT'
  $final=$null
  for($i=0;$i -lt 90;$i++){
    $poll=Signed 'GET' ('/v1/operations/'+$operationId) 'web-adapter' $null
    Assert ($poll.Status -eq 200 -and $poll.Body.data.operation_id -eq $operationId) 'DURABLE_READBACK_MISSING'
    if($poll.Body.data.state -in @('failed','recovery_required','committed')){$final=$poll;break}
    Start-Sleep -Milliseconds 200
  }
  Assert ($null -ne $final -and $final.Body.data.state -eq 'failed' -and
    -not[bool]$final.Body.data.activation_performed) 'SYNTHETIC_STAGE_NOT_DENIED_PREENGINE'
  $repeated=Signed 'POST' '/v1/local-updates/activate-code-only' 'web-adapter' $body 'p26-http-real-202'
  Assert ($repeated.Status -eq 409 -and
    $repeated.Body.operation_id -eq $operationId -and
    -not[bool]$repeated.Body.data.created) 'BLIND_RETRY_CREATED_OPERATION'
  $byKey=Signed 'GET' '/v1/operations/by-idempotency/p26-http-real-202' 'web-adapter' $null
  Assert ($byKey.Status -eq 200 -and $byKey.Body.data.operation_id -eq $operationId) 'IDEMPOTENCY_READBACK_LOST'
  $active=Signed 'GET' '/v1/operations/active' 'web-adapter' $null
  Assert ($active.Status -eq 200 -and $active.Body.data.blocking_count -eq 0) 'FAILED_PREENGINE_BLOCKED_UNNECESSARILY'
  Assert (-not(Test-Path (Join-Path $root 'update/release-sequence.json')) -and
     -not(Test-Path (Join-Path $root 'installed-release-identity.json'))) 'DENIAL_MODIFIED_INSTALL_IDENTITY'
  Stop-Supervisor $server
  $server=$null
  $server=Start-Supervisor
  $afterRestart=Signed 'GET' ('/v1/operations/'+$operationId) 'web-adapter' $null
  Assert ($afterRestart.Status -eq 200 -and $afterRestart.Body.data.state -eq 'failed') 'RESTART_LOST_DURABLE_RESULT'
  $afterCapabilities=Signed 'GET' '/v1/updates/capabilities' 'web-adapter' $null
  Assert (-not[bool]$afterCapabilities.Body.data.panel_update_ready) 'POST_RESTART_FALSE_READY'
  # Simulate a lost in-process Worker notification after the durable write.
  # Only a CI fixture can reserve a synthetic Stage without the HTTP signal.
  $lostSignal=@(& dotnet $SeedHarnessDll --reserve-http-denial $root $stageId 'user:ci' 'p26-durable-no-notify')
  Assert ($LASTEXITCODE -eq 0 -and $lostSignal.Count -eq 1 -and
    [string]$lostSignal[0] -match '^[a-f0-9]{32}$') 'UNSIGNALED_RESERVATION_NOT_PERSISTED'
  $lostId=[string]$lostSignal[0]
  $lostFinal=$null
  for($i=0;$i -lt 85;$i++){
    $check=Signed 'GET' ('/v1/operations/'+$lostId) 'web-adapter' $null
    Assert ($check.Status -eq 200 -and $check.Body.data.operation_id -eq $lostId) 'LOST_SIGNAL_DURABLE_OPERATION_MISSING'
    if($check.Body.data.state -in @('failed','committed','recovery_required')){$lostFinal=$check;break}
    Start-Sleep -Milliseconds 200
  }
  Assert ($null -ne $lostFinal -and $lostFinal.Body.data.state -eq 'failed' -and
    -not[bool]$lostFinal.Body.data.activation_performed) 'UNSIGNALED_OPERATION_STUCK_OR_FALSE_SUCCESS'
  $lostByKey=Signed 'GET' '/v1/operations/by-idempotency/p26-durable-no-notify' 'web-adapter' $null
  Assert ($lostByKey.Status -eq 200 -and
    $lostByKey.Body.data.operation_id -eq $lostId -and
    $lostByKey.Body.data.attempt_count -eq 0) 'UNSIGNALED_RESERVATION_RETRIED_ENGINE'
  $noSecondInstall=Signed 'GET' '/v1/operations/active' 'web-adapter' $null
  Assert ($noSecondInstall.Status -eq 200 -and
    $noSecondInstall.Body.data.blocking_count -eq 0) 'UNSIGNALED_RESERVATION_DID_NOT_TERMINATE'
  Write-Host 'P26_LOST_WORKER_SIGNAL_RECOVERED_FROM_DURABLE_LEDGER_PASS'
  Write-Host ('P26_SIGNED_HTTP_202_WORKER_POLL_SAFE_DENIAL_PASS elapsed_ms='+[math]::Round($timer.Elapsed.TotalMilliseconds))
}
finally{
  Stop-Supervisor $server
  if($regCreated -and (Test-Path $reg)){Remove-Item -LiteralPath $reg -Recurse -Force}
  if((Test-Path $root) -and ($root -like (Join-Path $env:RUNNER_TEMP 'SoknaP26SignedHttp-*'))){
    Remove-Item -LiteralPath $root -Recurse -Force -ErrorAction SilentlyContinue
  }
}
