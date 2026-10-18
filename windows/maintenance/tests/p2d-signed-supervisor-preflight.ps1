param([string]$SupervisorDll,[string]$FixtureDll)
$ErrorActionPreference='Stop'
$PSNativeCommandUseErrorActionPreference=$false
Set-StrictMode -Version Latest
function Assert([bool]$Ok,[string]$Reason) { if(-not $Ok){throw $Reason} }
$SupervisorDll=(Resolve-Path -LiteralPath $SupervisorDll).Path
$FixtureDll=(Resolve-Path -LiteralPath $FixtureDll).Path
$root=Join-Path $env:RUNNER_TEMP ('sokna-f1-p2d-'+[guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $root -Force|Out-Null
$output=@(& dotnet $FixtureDll --root $root --variant valid --sequence 110 2>&1)
Assert ($LASTEXITCODE -eq 0) 'Fixture generation failed'
$f=($output -join [Environment]::NewLine)|ConvertFrom-Json
$inbox=Join-Path $root 'update/inbox';New-Item -ItemType Directory -Path $inbox -Force|Out-Null
$ref=[IO.Path]::GetFileName([string]$f.package)
$package=Join-Path $inbox $ref
Copy-Item -LiteralPath $f.package -Destination $package
Copy-Item -LiteralPath $f.identity -Destination (Join-Path $root 'installed-release-identity.json')
$port=18574;$base="http://127.0.0.1:$port"
$serviceKey='HKLM:\SYSTEM\CurrentControlSet\Services\SoknaApache'
Assert (-not(Test-Path -LiteralPath $serviceKey)) 'Runner has actual SoknaApache; refusing fixture'
$bin=Join-Path $root 'fake/Apache24/bin';New-Item -ItemType Directory -Path $bin -Force|Out-Null
$exe=Join-Path $bin 'httpd.exe'
Copy-Item -LiteralPath "$env:SystemRoot\System32\cmd.exe" -Destination $exe
New-Item -Path $serviceKey -Force|Out-Null
New-ItemProperty -Path $serviceKey -Name 'ImagePath' -PropertyType ExpandString -Value ('"'+$exe+'" -k runservice') -Force|Out-Null
New-ItemProperty -Path $serviceKey -Name 'ObjectName' -PropertyType String -Value 'LocalSystem' -Force|Out-Null
function Start-S {
  $p=Start-Process dotnet -ArgumentList @($SupervisorDll,'--data-root',$root,'--port',"$port") -PassThru
  $until=[DateTime]::UtcNow.AddSeconds(25)
  while([DateTime]::UtcNow -lt $until) {
    Assert (-not$p.HasExited) 'Supervisor crashed on startup'
    $aclFile=Join-Path $root 'web-adapter-acl.json'
    if((Test-Path -LiteralPath (Join-Path $root 'control-plane.json')) -and (Test-Path -LiteralPath $aclFile)) {
      try {
        $acl=Get-Content -LiteralPath $aclFile -Raw|ConvertFrom-Json
        if([string]$acl.state -eq 'ready'){return $p}
      }catch{}
    }
    Start-Sleep -Milliseconds 150
  }
  throw 'Supervisor ACL did not become ready'
}
function Stop-S($p) {
  if($null -ne $p -and -not $p.HasExited){
    Stop-Process -Id $p.Id -Force -ErrorAction SilentlyContinue
    $p.WaitForExit(5000)|Out-Null
  }
}
function Signed([string]$Method,[string]$Route,[string]$KeyId,[object]$Body,[string]$Idem=''){
  $bytes=if($null -eq $Body){[byte[]]::new(0)}else{[Text.Encoding]::UTF8.GetBytes(($Body|ConvertTo-Json -Compress -Depth 8))}
  $timestamp=[DateTimeOffset]::UtcNow.ToString('O',[Globalization.CultureInfo]::InvariantCulture)
  $nonce=[Convert]::ToHexString([Security.Cryptography.RandomNumberGenerator]::GetBytes(16)).ToLowerInvariant()
  $digest=[Convert]::ToHexString([Security.Cryptography.SHA256]::HashData($bytes)).ToLowerInvariant()
  $canonical=[string]::Join([char]10,@($Method,$Route,'1.0',$timestamp,$nonce,$digest))
  $key=[Convert]::FromBase64String((Get-Content -LiteralPath (Join-Path $root "secrets/$KeyId.key") -Raw).Trim())
  $mac=[Security.Cryptography.HMACSHA256]::new($key)
  try{$sig=[Convert]::ToHexString($mac.ComputeHash([Text.Encoding]::UTF8.GetBytes($canonical))).ToLowerInvariant()}
  finally{$mac.Dispose()}
  $headers=@{'X-Sokna-Maintenance-Protocol'='1.0';'X-Sokna-Timestamp'=$timestamp;'X-Sokna-Nonce'=$nonce;'X-Sokna-Key-Id'=$KeyId;'X-Sokna-Body-SHA256'=$digest;'X-Sokna-Signature'=$sig}
  if($Idem){$headers['X-Sokna-Idempotency-Key']=$Idem}
  $args=@{Uri=($base+$Route);Method=$Method;Headers=$headers;SkipHttpErrorCheck=$true;TimeoutSec=12}
  if($bytes.Length){$args.Body=$bytes;$args.ContentType='application/json; charset=utf-8'}
  $response=Invoke-WebRequest @args
  return [pscustomobject]@{Code=[int]$response.StatusCode;Data=($response.Content|ConvertFrom-Json)}
}
function No-Stage {
  Assert (-not(Test-Path -LiteralPath (Join-Path $root 'update/release-sequence.json'))) 'Preflight wrote release sequence'
  Assert (-not(Test-Path -LiteralPath (Join-Path $root 'staging/release-sets'))) 'Preflight created stage root'
}
$p=$null
try {
  $p=Start-S
  $unsigned=Invoke-WebRequest ($base+'/v1/local-updates/preflight') -Method POST -Body '{}' -ContentType 'application/json' -SkipHttpErrorCheck
  Assert ([int]$unsigned.StatusCode -eq 401) 'Unsigned POST accepted'
  $body=[ordered]@{package_ref=$ref;operator_id='user:test'}
  $forbidden=Signed 'POST' '/v1/local-updates/preflight' 'recovery-console' $body 'recovery-not-allowed'
  Assert ($forbidden.Code -eq 403) 'Recovery key gained ordinary preflight authority'
  $missing=Signed 'POST' '/v1/local-updates/preflight' 'web-adapter' $body
  Assert ($missing.Code -eq 400 -and $missing.Data.code -eq 'IDEMPOTENCY_KEY_REQUIRED') 'Missing idempotency accepted'
  $traversal=Signed 'POST' '/v1/local-updates/preflight' 'web-adapter' ([ordered]@{package_ref='../bad.zip';operator_id='user:test'}) 'unsafe-path'
  Assert ($traversal.Code -eq 400) 'Traversal path accepted'
  $good=Signed 'POST' '/v1/local-updates/preflight' 'web-adapter' $body 'p2d-valid-001'
  Assert ($good.Code -eq 200) ('Signed preflight rejected: '+($good.Data|ConvertTo-Json -Depth 7))
  Assert ($good.Data.data.operation.state -eq 'verified') 'Durable status not verified'
  Assert ([bool]$good.Data.data.receipt.verification.signature_verified) 'Signed proof missing'
  Assert ([bool]$good.Data.data.receipt.verification.exact_baseline_verified) 'Exact baseline proof missing'
  Assert (-not[bool]$good.Data.data.panel_update_ready) 'False panel readiness'
  $id=[string]$good.Data.operation_id
  Assert (Test-Path -LiteralPath (Join-Path $root ('operations/preflight-'+$id+'.json'))) 'Preflight receipt not persisted'
  No-Stage
  $retry=Signed 'POST' '/v1/local-updates/preflight' 'web-adapter' $body 'p2d-valid-001'
  Assert ($retry.Code -eq 200 -and $retry.Data.operation_id -eq $id -and [bool]$retry.Data.data.reconciled) 'Duplicate operation created'
  $conflict=Signed 'POST' '/v1/local-updates/preflight' 'web-adapter' ([ordered]@{package_ref=$ref;operator_id='user:other'}) 'p2d-valid-001'
  Assert ($conflict.Code -eq 409) 'Idempotency collision not rejected'
  $caps=Signed 'GET' '/v1/updates/capabilities' 'web-adapter' $null
  Assert ($caps.Code -eq 200 -and -not[bool]$caps.Data.data.panel_update_ready) 'Capability claimed panel-ready'
  Stop-S $p;$p=$null
  $p=Start-S
  $read=Signed 'GET' ('/v1/operations/'+$id) 'web-adapter' $null
  Assert ($read.Code -eq 200 -and $read.Data.data.state -eq 'verified') 'Operation lost after Supervisor restart'
  $again=Signed 'POST' '/v1/local-updates/preflight' 'web-adapter' $body 'p2d-valid-001'
  Assert ($again.Code -eq 200 -and $again.Data.operation_id -eq $id) 'Restart broke idempotency'
  $bytes=[IO.File]::ReadAllBytes($package);$bytes[$bytes.Length-12]=$bytes[$bytes.Length-12] -bxor 16;[IO.File]::WriteAllBytes($package,$bytes)
  $drift=Signed 'POST' '/v1/local-updates/preflight' 'web-adapter' $body 'p2d-valid-001'
  Assert ($drift.Code -eq 422 -and $drift.Data.data.operation.state -eq 'rejected_pre_mutation') 'Stale success returned after tampering'
  $after=Signed 'GET' ('/v1/operations/'+$id) 'web-adapter' $null
  Assert ($after.Data.data.state -eq 'rejected_pre_mutation') 'Stale durable result not revoked'
  No-Stage
  [ordered]@{format='sokna-f1-p2d-signed-preflight-v1';result='PASS';operation_id=$id;no_stage=$true;no_sequence_update=$true;restart_reconciled=$true;tamper_rejected=$true;panel_ready=$false}|ConvertTo-Json
}
finally {
  Stop-S $p
  if(Test-Path -LiteralPath $serviceKey){Remove-Item -LiteralPath $serviceKey -Recurse -Force}
}
exit 0
