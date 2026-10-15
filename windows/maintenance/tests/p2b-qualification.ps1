param(
  [Parameter(Mandatory=$true)][string]$SupervisorExe,
  [Parameter(Mandatory=$true)][string]$FixtureExe
)
$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest

function Assert([bool]$Condition,[string]$Message){if(-not$Condition){throw $Message}}
function Wait-File([string]$Path,[int]$Seconds=20){$deadline=[DateTime]::UtcNow.AddSeconds($Seconds);while([DateTime]::UtcNow-lt$deadline){if(Test-Path -LiteralPath $Path -PathType Leaf){return};Start-Sleep -Milliseconds 150};throw "Timed out waiting for file: $Path"}
function Stop-ProcessSafe($Process){if($null-eq$Process){return};try{if(-not$Process.HasExited){Stop-Process -Id $Process.Id -Force -ErrorAction SilentlyContinue;$Process.WaitForExit(5000)|Out-Null}}catch{}}
function New-Root([string]$Name){$p=Join-Path $env:RUNNER_TEMP ('sokna-p2b-'+$Name+'-'+[guid]::NewGuid().ToString('N'));New-Item -ItemType Directory -Path $p -Force|Out-Null;return $p}
function New-Fixture([string]$Root,[long]$Sequence=101,[string]$ReleaseId='release-p2b'){
  $raw=@(& $FixtureExe --root $Root --variant valid --sequence ([string]$Sequence) --release-id $ReleaseId 2>&1);if($LASTEXITCODE-ne0){throw "Fixture failed: $($raw -join "`n")"};return (($raw -join "`n")|ConvertFrom-Json)
}
function New-SignedRequest([string]$Method,[string]$Url,[string]$KeyPath,[string]$KeyId,[byte[]]$Body,[string]$IdempotencyKey=''){
  $uri=[Uri]$Url
  $key=[Convert]::FromBase64String((Get-Content -LiteralPath $KeyPath -Raw).Trim())
  $timestamp=[DateTimeOffset]::UtcNow.ToString('O',[Globalization.CultureInfo]::InvariantCulture)
  $nonce=[guid]::NewGuid().ToString('N')
  $sha=[Security.Cryptography.SHA256]::HashData($Body)
  $bodyHash=[Convert]::ToHexString($sha).ToLowerInvariant()
  $canonical="$($Method.ToUpperInvariant())`n$($uri.PathAndQuery)`n1.0`n$timestamp`n$nonce`n$bodyHash"
  $hmac=[Security.Cryptography.HMACSHA256]::new($key)
  try{$signature=[Convert]::ToHexString($hmac.ComputeHash([Text.Encoding]::UTF8.GetBytes($canonical))).ToLowerInvariant()}finally{$hmac.Dispose()}
  $headers=@{
    'X-Sokna-Maintenance-Protocol'='1.0';'X-Sokna-Timestamp'=$timestamp;'X-Sokna-Nonce'=$nonce;'X-Sokna-Key-Id'=$KeyId;
    'X-Sokna-Body-SHA256'=$bodyHash;'X-Sokna-Signature'=$signature
  }
  if($IdempotencyKey){$headers['X-Sokna-Idempotency-Key']=$IdempotencyKey}
  return [pscustomobject]@{Headers=$headers;Body=$Body}
}
function Invoke-Signed([string]$Method,[string]$Url,[string]$KeyPath,[string]$KeyId,[byte[]]$Body,[string]$IdempotencyKey=''){
  $signed=New-SignedRequest $Method $Url $KeyPath $KeyId $Body $IdempotencyKey
  $args=@{Uri=$Url;Method=$Method;Headers=$signed.Headers;SkipHttpErrorCheck=$true;TimeoutSec=30}
  if($Body.Length-gt0){$args.Body=$Body;$args.ContentType='application/json; charset=utf-8'}
  return Invoke-WebRequest @args
}
function Create-FakeApacheService([string]$Root){
  $serviceName='SoknaApache'
  Assert ($null-eq(Get-Service -Name $serviceName -ErrorAction SilentlyContinue)) 'Qualification runner unexpectedly already has SoknaApache.'
  $apacheDir=Join-Path $Root 'fake-apache\Apache24\bin';New-Item -ItemType Directory -Path $apacheDir -Force|Out-Null
  $httpd=Join-Path $apacheDir 'httpd.exe';Copy-Item "$env:SystemRoot\System32\cmd.exe" $httpd
  $bin='"'+$httpd+'" -k runservice'
  $out=@(& sc.exe create $serviceName "binPath= $bin" "start= demand" "obj= NT AUTHORITY\LocalService" 2>&1)
  Assert ($LASTEXITCODE-eq0) "Unable to create fake SoknaApache service: $($out -join ' | ')"
  return $httpd
}
function Delete-FakeApacheService(){
  $svc=Get-Service -Name 'SoknaApache' -ErrorAction SilentlyContinue
  if($svc){$svc.Dispose();& sc.exe delete SoknaApache|Out-Null;for($i=0;$i-lt40;$i++){if(-not(Get-Service -Name SoknaApache -ErrorAction SilentlyContinue)){break};Start-Sleep -Milliseconds 250}}
}
function Assert-KeyAcl([string]$WebKey,[string]$RecoveryKey,[string]$ExpectedSid){
  $webAcl=Get-Acl -LiteralPath $WebKey
  $recoveryAcl=Get-Acl -LiteralPath $RecoveryKey
  $webSids=@($webAcl.Access|Where-Object{$_.AccessControlType-eq'Allow'}|ForEach-Object{$_.IdentityReference.Translate([Security.Principal.SecurityIdentifier]).Value})
  $recoverySids=@($recoveryAcl.Access|Where-Object{$_.AccessControlType-eq'Allow'}|ForEach-Object{$_.IdentityReference.Translate([Security.Principal.SecurityIdentifier]).Value})
  Assert ($webSids-contains$ExpectedSid) 'Exact Apache service SID does not have web-adapter key access.'
  Assert (-not($recoverySids-contains$ExpectedSid)) 'Apache service SID must not have recovery-console key access.'
  foreach($broad in @('S-1-1-0','S-1-5-11','S-1-5-32-545','S-1-5-32-546')){Assert (-not($webSids-contains$broad)) "Broad principal $broad has web-adapter key access.";Assert (-not($recoverySids-contains$broad)) "Broad principal $broad has recovery key access."}
}

$SupervisorExe=(Resolve-Path -LiteralPath $SupervisorExe).Path
$FixtureExe=(Resolve-Path -LiteralPath $FixtureExe).Path
$root=New-Root 'main';$fixture=New-Fixture $root 101 'release-p2b-101'
$inbox=Join-Path $root 'update\inbox';New-Item -ItemType Directory -Path $inbox -Force|Out-Null
$packageName=[IO.Path]::GetFileName([string]$fixture.package);Copy-Item -LiteralPath $fixture.package -Destination (Join-Path $inbox $packageName)
Copy-Item -LiteralPath $fixture.identity -Destination (Join-Path $root 'installed-release-identity.json')
$null=Create-FakeApacheService $root
$proc=$null
try{
  $proc=Start-Process -FilePath $SupervisorExe -ArgumentList @('--data-root',$root,'--port','17631') -PassThru -WindowStyle Hidden
  Wait-File (Join-Path $root 'control-plane.json')
  Wait-File (Join-Path $root 'web-adapter-acl.json')
  $aclEvidence=Get-Content -LiteralPath (Join-Path $root 'web-adapter-acl.json') -Raw|ConvertFrom-Json
  Assert ([string]$aclEvidence.state-eq'ready') "Web adapter ACL did not become ready: $($aclEvidence|ConvertTo-Json -Compress)"
  Assert ([string]$aclEvidence.service_sid-eq'S-1-5-19') 'SoknaApache LocalService SID mapping mismatch.'
  Assert ([bool]$aclEvidence.exact_identity_read_granted) 'Exact Web service key grant missing.'
  Assert (-not[bool]$aclEvidence.broad_read_granted) 'ACL evidence must not claim broad read.'
  Assert-KeyAcl (Join-Path $root 'secrets\web-adapter.key') (Join-Path $root 'secrets\recovery-console.key') 'S-1-5-19'

  $base='http://127.0.0.1:17631/'
  $webKey=Join-Path $root 'secrets\web-adapter.key';$recoveryKey=Join-Path $root 'secrets\recovery-console.key'
  $requestJson=[Text.Json.JsonSerializer]::SerializeToUtf8Bytes([ordered]@{package_ref=$packageName;operator_id='user:test-operator'})
  $idem='idem-p2b-001'
  $response=Invoke-Signed 'POST' ($base+'v1/update/stage') $webKey 'web-adapter' $requestJson $idem
  Assert ([int]$response.StatusCode-eq200) "Signed stage mutation failed HTTP $($response.StatusCode): $($response.Content)"
  $body=$response.Content|ConvertFrom-Json
  Assert ([bool]$body.ok) 'Stage response must be ok.'
  Assert ([string]$body.state-eq'staged') 'Stage operation must reach staged.'
  Assert (-not[bool]$body.data.operation.activation_performed) 'P2B operation must not activate.'
  Assert (-not[bool]$body.data.operation.database_mutation_performed) 'P2B operation must not mutate DB.'
  $operationId=[string]$body.operation_id;Assert (-not[string]::IsNullOrWhiteSpace($operationId)) 'Stage response omitted operation_id.'
  Assert (Test-Path -LiteralPath ([string]$body.data.operation.stage_receipt_path) -PathType Leaf) 'Operation stage receipt is missing.'

  $again=Invoke-Signed 'POST' ($base+'v1/update/stage') $webKey 'web-adapter' $requestJson $idem
  Assert ([int]$again.StatusCode-eq200) "Idempotent restage failed: $($again.Content)"
  $againJson=$again.Content|ConvertFrom-Json
  Assert ([string]$againJson.operation_id-eq$operationId) 'Same idempotency key/request returned a new operation.'
  Assert ([bool]$againJson.data.reconciled) 'Repeated stage should be reported as reconciled.'

  $different=[Text.Json.JsonSerializer]::SerializeToUtf8Bytes([ordered]@{package_ref=$packageName;operator_id='user:different'})
  $conflict=Invoke-Signed 'POST' ($base+'v1/update/stage') $webKey 'web-adapter' $different $idem
  Assert ([int]$conflict.StatusCode-eq409) "Idempotency conflict was not rejected: $($conflict.Content)"
  Assert ([string](($conflict.Content|ConvertFrom-Json).code)-eq'IDEMPOTENCY_CONFLICT') 'Idempotency conflict code mismatch.'

  $query=Invoke-Signed 'GET' ($base+'v1/operations/'+$operationId) $recoveryKey 'recovery-console' ([byte[]]::new(0))
  Assert ([int]$query.StatusCode-eq200) "Operation lookup failed: $($query.Content)"
  $queryJson=$query.Content|ConvertFrom-Json
  Assert ([string]$queryJson.operation_id-eq$operationId) 'Operation lookup identity mismatch.'
  Assert ([string]$queryJson.data.state-eq'staged') 'Operation lookup did not preserve staged state.'

  $forbidden=Invoke-Signed 'POST' ($base+'v1/update/stage') $recoveryKey 'recovery-console' $requestJson 'idem-recovery-key'
  Assert ([int]$forbidden.StatusCode-eq403) 'Recovery Console key must not authorize Web staging mutation.'
  Assert ([string](($forbidden.Content|ConvertFrom-Json).code)-eq'WEB_ADAPTER_KEY_REQUIRED') 'Recovery-key mutation rejection code mismatch.'

  $signed=New-SignedRequest 'POST' ($base+'v1/update/stage') $webKey 'web-adapter' $requestJson 'idem-tamper'
  $tampered=[Text.Encoding]::UTF8.GetBytes('{"package_ref":"tampered.zip","operator_id":"user:test-operator"}')
  $tamper=Invoke-WebRequest -Uri ($base+'v1/update/stage') -Method Post -Headers $signed.Headers -Body $tampered -ContentType 'application/json; charset=utf-8' -SkipHttpErrorCheck -TimeoutSec 10
  Assert ([int]$tamper.StatusCode-eq401) 'Tampered signed body must be rejected.'
  Assert ([string](($tamper.Content|ConvertFrom-Json).code)-eq'AUTH_BODY_HASH_INVALID') 'Tampered-body rejection code mismatch.'

  $unsafe=[Text.Json.JsonSerializer]::SerializeToUtf8Bytes([ordered]@{package_ref='..\outside.zip';operator_id='user:test-operator'})
  $unsafeResp=Invoke-Signed 'POST' ($base+'v1/update/stage') $webKey 'web-adapter' $unsafe 'idem-unsafe-path'
  Assert ([int]$unsafeResp.StatusCode-eq400) 'Unsafe package path must reject pre-mutation.'

  $opRecord=Get-Content -LiteralPath (Join-Path $root ('operations\op-'+$operationId+'.json')) -Raw|ConvertFrom-Json
  Assert ([string]$opRecord.operator_id-eq'user:test-operator') 'Durable operation omitted signed operator identity.'
  Assert (-not[bool]$opRecord.activation_performed) 'Durable operation falsely records activation.'
  Assert (-not[bool]$opRecord.database_mutation_performed) 'Durable operation falsely records DB mutation.'
}
finally{Stop-ProcessSafe $proc;Delete-FakeApacheService}

# Durable reconciliation survives Supervisor process restart without Web or CLI state.
$proc=$null
try{
  $null=Create-FakeApacheService $root
  $proc=Start-Process -FilePath $SupervisorExe -ArgumentList @('--data-root',$root,'--port','17631') -PassThru -WindowStyle Hidden
  Wait-File (Join-Path $root 'control-plane.json')
  $query=Invoke-Signed 'GET' ('http://127.0.0.1:17631/v1/operations/'+$operationId) (Join-Path $root 'secrets\recovery-console.key') 'recovery-console' ([byte[]]::new(0))
  Assert ([int]$query.StatusCode-eq200) 'Durable operation could not be reconciled after Supervisor restart.'
  Assert ([string](($query.Content|ConvertFrom-Json).data.state)-eq'staged') 'Restart changed durable staging state.'
}
finally{Stop-ProcessSafe $proc;Delete-FakeApacheService}

# Fail closed if exact Apache identity cannot be established: even an administrator holding the key cannot exercise normal Web mutation path.
$pendingRoot=New-Root 'acl-pending';$pendingFixture=New-Fixture $pendingRoot 102 'release-p2b-102';$pendingInbox=Join-Path $pendingRoot 'update\inbox';New-Item -ItemType Directory -Path $pendingInbox -Force|Out-Null
$pendingPackage=[IO.Path]::GetFileName([string]$pendingFixture.package);Copy-Item $pendingFixture.package (Join-Path $pendingInbox $pendingPackage);Copy-Item $pendingFixture.identity (Join-Path $pendingRoot 'installed-release-identity.json')
$proc=$null
try{
  $proc=Start-Process -FilePath $SupervisorExe -ArgumentList @('--data-root',$pendingRoot,'--port','17632') -PassThru -WindowStyle Hidden
  Wait-File (Join-Path $pendingRoot 'control-plane.json');Wait-File (Join-Path $pendingRoot 'web-adapter-acl.json')
  $ev=Get-Content -LiteralPath (Join-Path $pendingRoot 'web-adapter-acl.json') -Raw|ConvertFrom-Json
  Assert ([string]$ev.state-eq'acl_pending') 'Missing Apache identity must leave ACL pending.'
  $json=[Text.Json.JsonSerializer]::SerializeToUtf8Bytes([ordered]@{package_ref=$pendingPackage;operator_id='user:test'})
  $resp=Invoke-Signed 'POST' 'http://127.0.0.1:17632/v1/update/stage' (Join-Path $pendingRoot 'secrets\web-adapter.key') 'web-adapter' $json 'idem-pending'
  Assert ([int]$resp.StatusCode-eq503) "ACL-pending mutation should fail closed: $($resp.Content)"
  Assert ([string](($resp.Content|ConvertFrom-Json).code)-eq'WEB_ADAPTER_ACL_PENDING') 'ACL-pending error code mismatch.'
  Assert (-not(Test-Path -LiteralPath (Join-Path $pendingRoot 'update\release-sequence.json'))) 'ACL-pending request reached Update Engine/staging.'
}
finally{Stop-ProcessSafe $proc}

[ordered]@{
  format='sokna-maintenance-v4-windows-p2b-qualification-v1';result='PASS';supervisor_version='0.2.0';update_engine_version='0.2.0';protocol='1.0';
  web_adapter_acl_exact_service_sid='PASS';web_adapter_acl_broad_principals_absent='PASS';recovery_key_isolation='PASS';acl_pending_fail_closed='PASS';
  signed_post_body_integrity='PASS';web_key_only_mutation_authority='PASS';durable_operation_before_reconciliation='PASS';idempotent_same_request='PASS';
  idempotency_conflict='PASS';operation_lookup_after_restart='PASS';supervisor_owned_child_update_engine='PASS';inbox_path_confinement='PASS';
  activation='NOT_IMPLEMENTED_P2B';database_mutation='NOT_IMPLEMENTED_P2B';apache_restart='NOT_PERFORMED_BY_STAGING';evidence_tier='windows-2022-ci-runtime-not-real-cafe-host'
}|ConvertTo-Json -Depth 5
