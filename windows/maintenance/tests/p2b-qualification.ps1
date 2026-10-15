param(
  [Parameter(Mandatory=$true)][string]$SupervisorExe,
  [Parameter(Mandatory=$true)][string]$FixtureExe
)
$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest

function Assert([bool]$Condition,[string]$Message){if(-not $Condition){throw $Message}}
function Wait-File([string]$Path,[int]$Seconds=20){$deadline=[DateTime]::UtcNow.AddSeconds($Seconds);while([DateTime]::UtcNow -lt $deadline){if(Test-Path -LiteralPath $Path -PathType Leaf){return};Start-Sleep -Milliseconds 150};throw "Timed out: $Path"}
function Wait-AclReady([string]$Path,[int]$Seconds=20){$deadline=[DateTime]::UtcNow.AddSeconds($Seconds);while([DateTime]::UtcNow-lt$deadline){if(Test-Path -LiteralPath $Path){try{$e=Get-Content -LiteralPath $Path -Raw|ConvertFrom-Json;if(([string]$e.state-eq'ready')-and[bool]$e.descriptor_read_granted){return $e}}catch{}};Start-Sleep -Milliseconds 100};throw "ACL evidence did not reconcile: $(if(Test-Path $Path){Get-Content $Path -Raw}else{'<missing>'})"}
function Stop-P($Process){if($Process){try{if(-not $Process.HasExited){Stop-Process -Id $Process.Id -Force -ErrorAction SilentlyContinue;$Process.WaitForExit(5000)|Out-Null}}catch{}}}
function New-Root([string]$Name){$path=Join-Path $env:RUNNER_TEMP ('sokna-p2b-'+$Name+'-'+[guid]::NewGuid().ToString('N'));New-Item -ItemType Directory -Path $path -Force|Out-Null;return $path}
function Fixture([string]$Root,[int64]$Sequence,[string]$ReleaseId){$output=@(& $FixtureExe --root $Root --variant valid --sequence ([string]$Sequence) --release-id $ReleaseId 2>&1);if($LASTEXITCODE -ne 0){throw($output -join "`n")};return (($output -join "`n")|ConvertFrom-Json)}
function JsonBytes($Object){return [Text.Encoding]::UTF8.GetBytes(($Object|ConvertTo-Json -Compress -Depth 10))}
function Signed([string]$Method,[string]$Url,[string]$KeyPath,[string]$KeyId,[byte[]]$Body,[string]$IdempotencyKey=''){
  $uri=[Uri]$Url;$key=[Convert]::FromBase64String((Get-Content $KeyPath -Raw).Trim());$timestamp=[DateTimeOffset]::UtcNow.ToString('O',[Globalization.CultureInfo]::InvariantCulture);$nonce=[guid]::NewGuid().ToString('N');$bodyHash=[Convert]::ToHexString([Security.Cryptography.SHA256]::HashData($Body)).ToLowerInvariant();$canonical="$($Method.ToUpperInvariant())`n$($uri.PathAndQuery)`n1.0`n$timestamp`n$nonce`n$bodyHash";$hmac=[Security.Cryptography.HMACSHA256]::new($key);try{$signature=[Convert]::ToHexString($hmac.ComputeHash([Text.Encoding]::UTF8.GetBytes($canonical))).ToLowerInvariant()}finally{$hmac.Dispose()};$headers=@{'X-Sokna-Maintenance-Protocol'='1.0';'X-Sokna-Timestamp'=$timestamp;'X-Sokna-Nonce'=$nonce;'X-Sokna-Key-Id'=$KeyId;'X-Sokna-Body-SHA256'=$bodyHash;'X-Sokna-Signature'=$signature};if($IdempotencyKey){$headers['X-Sokna-Idempotency-Key']=$IdempotencyKey};$args=@{Uri=$Url;Method=$Method;Headers=$headers;SkipHttpErrorCheck=$true;TimeoutSec=30};if($Body.Length){$args.Body=$Body;$args.ContentType='application/json; charset=utf-8'};return Invoke-WebRequest @args
}

function New-ApacheIdentityFixture([string]$Root){
  $serviceKey='HKLM:\SYSTEM\CurrentControlSet\Services\SoknaApache'
  Assert (-not(Test-Path -LiteralPath $serviceKey)) 'SoknaApache service registry key already exists on runner.'
  $binDir=Join-Path $Root 'fake\Apache24\bin';New-Item -ItemType Directory -Path $binDir -Force|Out-Null
  $exe=Join-Path $binDir 'httpd.exe';Copy-Item "$env:SystemRoot\System32\cmd.exe" $exe
  New-Item -Path $serviceKey -Force|Out-Null
  New-ItemProperty -Path $serviceKey -Name 'ImagePath' -PropertyType ExpandString -Value ('"'+$exe+'" -k runservice') -Force|Out-Null
  New-ItemProperty -Path $serviceKey -Name 'ObjectName' -PropertyType String -Value 'LocalSystem' -Force|Out-Null
  New-ItemProperty -Path $serviceKey -Name 'Type' -PropertyType DWord -Value 16 -Force|Out-Null
  New-ItemProperty -Path $serviceKey -Name 'Start' -PropertyType DWord -Value 3 -Force|Out-Null
}
function Remove-ApacheIdentityFixture(){$serviceKey='HKLM:\SYSTEM\CurrentControlSet\Services\SoknaApache';if(Test-Path -LiteralPath $serviceKey){Remove-Item -LiteralPath $serviceKey -Recurse -Force}}
function Assert-KeyAcls([string]$WebKey,[string]$RecoveryKey){
  foreach($path in @($WebKey,$RecoveryKey)){
    $sids=@((Get-Acl $path).Access|Where-Object AccessControlType -eq Allow|ForEach-Object{$_.IdentityReference.Translate([Security.Principal.SecurityIdentifier]).Value})
    Assert ($sids -contains 'S-1-5-18') "SYSTEM missing from $path"
    Assert ($sids -contains 'S-1-5-32-544') "Administrators missing from $path"
    foreach($broadSid in @('S-1-1-0','S-1-5-11','S-1-5-32-545','S-1-5-32-546')){Assert (-not($sids -contains $broadSid)) "Broad SID $broadSid on $path"}
  }
}

$SupervisorExe=(Resolve-Path $SupervisorExe).Path
$FixtureExe=(Resolve-Path $FixtureExe).Path

$root=New-Root 'main'
$fixture=Fixture $root 101 'release-p2b-101'
$inbox=Join-Path $root 'update\inbox';New-Item -ItemType Directory -Path $inbox -Force|Out-Null
$packageName=[IO.Path]::GetFileName([string]$fixture.package);Copy-Item $fixture.package (Join-Path $inbox $packageName);Copy-Item $fixture.identity (Join-Path $root 'installed-release-identity.json')
New-ApacheIdentityFixture $root
$process=$null
try{
  $process=Start-Process $SupervisorExe -ArgumentList @('--data-root',$root,'--port','17631') -PassThru -WindowStyle Hidden
  Wait-File (Join-Path $root 'control-plane.json')
  $aclEvidence=Wait-AclReady (Join-Path $root 'web-adapter-acl.json')
  Assert ([string]$aclEvidence.service_sid -eq 'S-1-5-18') 'Final P1A LocalSystem service SID policy was not applied.'
  Assert ([string]$aclEvidence.key_acl_mode -eq 'existing_system_acl') 'Unexpected P1A key ACL mode.'
  Assert-KeyAcls (Join-Path $root 'secrets\web-adapter.key') (Join-Path $root 'secrets\recovery-console.key')

  $base='http://127.0.0.1:17631/';$webKey=Join-Path $root 'secrets\web-adapter.key';$recoveryKey=Join-Path $root 'secrets\recovery-console.key'
  $body=JsonBytes([ordered]@{package_ref=$packageName;operator_id='user:test-operator'});$idempotencyKey='idem-p2b-001'
  $response=Signed POST ($base+'v1/update/stage') $webKey 'web-adapter' $body $idempotencyKey
  Assert ([int]$response.StatusCode -eq 200) "stage failed: $($response.Content)"
  $stage=$response.Content|ConvertFrom-Json;Assert ([string]$stage.state -eq 'staged') 'not staged';$operationId=[string]$stage.operation_id
  Assert (-not[bool]$stage.data.operation.activation_performed) 'activation occurred';Assert (-not[bool]$stage.data.operation.database_mutation_performed) 'DB mutation occurred'

  $again=Signed POST ($base+'v1/update/stage') $webKey 'web-adapter' $body $idempotencyKey;$againJson=$again.Content|ConvertFrom-Json
  Assert ([string]$againJson.operation_id -eq $operationId) 'idempotency changed op';Assert ([bool]$againJson.data.reconciled) 'not reconciled'

  $different=JsonBytes([ordered]@{package_ref=$packageName;operator_id='user:different'});$conflict=Signed POST ($base+'v1/update/stage') $webKey 'web-adapter' $different $idempotencyKey
  Assert ([int]$conflict.StatusCode -eq 409) 'idempotency conflict not 409'

  $lookup=Signed GET ($base+'v1/operations/'+$operationId) $recoveryKey 'recovery-console' ([byte[]]::new(0));Assert ([int]$lookup.StatusCode -eq 200) 'operation lookup failed'
  $forbidden=Signed POST ($base+'v1/update/stage') $recoveryKey 'recovery-console' $body 'idem-recovery';Assert ([int]$forbidden.StatusCode -eq 403) 'recovery key authorized mutation'
  $unsafeBody=JsonBytes([ordered]@{package_ref='..\x.zip';operator_id='user:test'});$unsafe=Signed POST ($base+'v1/update/stage') $webKey 'web-adapter' $unsafeBody 'idem-unsafe';Assert ([int]$unsafe.StatusCode -eq 400) 'unsafe package ref accepted'
  $record=Get-Content (Join-Path $root ('operations\op-'+$operationId+'.json')) -Raw|ConvertFrom-Json;Assert ([string]$record.operator_id -eq 'user:test-operator') 'operator missing from durable op'
}finally{Stop-P $process;Remove-ApacheIdentityFixture}

New-ApacheIdentityFixture $root;$process=$null
try{
  $process=Start-Process $SupervisorExe -ArgumentList @('--data-root',$root,'--port','17631') -PassThru -WindowStyle Hidden
  Wait-File (Join-Path $root 'control-plane.json');$null=Wait-AclReady (Join-Path $root 'web-adapter-acl.json')
  $lookup=Signed GET ('http://127.0.0.1:17631/v1/operations/'+$operationId) (Join-Path $root 'secrets\recovery-console.key') 'recovery-console' ([byte[]]::new(0))
  Assert ([string](($lookup.Content|ConvertFrom-Json).data.state) -eq 'staged') 'restart lost operation'
}finally{Stop-P $process;Remove-ApacheIdentityFixture}

$pending=New-Root 'pending';$pendingFixture=Fixture $pending 102 'release-p2b-102';$pendingInbox=Join-Path $pending 'update\inbox';New-Item -ItemType Directory -Path $pendingInbox -Force|Out-Null
$pendingPackage=[IO.Path]::GetFileName([string]$pendingFixture.package);Copy-Item $pendingFixture.package (Join-Path $pendingInbox $pendingPackage);Copy-Item $pendingFixture.identity (Join-Path $pending 'installed-release-identity.json');$process=$null
try{
  $process=Start-Process $SupervisorExe -ArgumentList @('--data-root',$pending,'--port','17632') -PassThru -WindowStyle Hidden
  Wait-File (Join-Path $pending 'control-plane.json');Wait-File (Join-Path $pending 'web-adapter-acl.json')
  $pendingEvidence=Get-Content (Join-Path $pending 'web-adapter-acl.json') -Raw|ConvertFrom-Json;Assert ([string]$pendingEvidence.state -eq 'acl_pending') 'missing Apache did not remain pending'
  $pendingBody=JsonBytes([ordered]@{package_ref=$pendingPackage;operator_id='user:test'});$pendingResponse=Signed POST 'http://127.0.0.1:17632/v1/update/stage' (Join-Path $pending 'secrets\web-adapter.key') 'web-adapter' $pendingBody 'idem-pending'
  Assert ([int]$pendingResponse.StatusCode -eq 503) 'ACL pending did not fail closed';Assert (-not(Test-Path(Join-Path $pending 'update\release-sequence.json'))) 'ACL pending reached engine'
}finally{Stop-P $process}

[ordered]@{
  format='sokna-maintenance-v4-windows-p2b-qualification-v2-p1a-reconciled'
  result='PASS'
  p2b_parent='f6a4dc3020957c618372f107a4d2eb0e7281d8e9'
  p1a_policy_source='68aa695233205d104a8cecc7ea520071d4f31682'
  supervisor_version='0.2.1'
  update_engine_version='0.2.0'
  protocol='1.0'
  exact_apache_identity_policy='LocalSystem_or_exact_NT_SERVICE_SoknaApache_only'
  key_hardening_system_admin_only='PASS'
  recovery_key_no_extra_web_grant='PASS'
  descriptor_acl_reconciliation='PASS'
  acl_pending_fail_closed='PASS'
  signed_staging='PASS'
  idempotency='PASS'
  restart_operation_reconciliation='PASS'
  activation='NOT_IMPLEMENTED_P2B'
  database_mutation='NOT_IMPLEMENTED_P2B'
  evidence_tier='windows-2022-ci-runtime-not-real-cafe-host'
  real_cafe_host_uat=$false
}|ConvertTo-Json -Depth 4
