param(
  [Parameter(Mandatory=$true)][string]$ProbeDll,
  [Parameter(Mandatory=$true)][string]$EngineDll
)
$ErrorActionPreference='Stop'
$PSNativeCommandUseErrorActionPreference=$false
Set-StrictMode -Version Latest
function Assert([bool]$Ok,[string]$Why){if(-not$Ok){throw $Why}}
$ProbeDll=(Resolve-Path -LiteralPath $ProbeDll).Path
$EngineDll=(Resolve-Path -LiteralPath $EngineDll).Path
$root=Join-Path $env:RUNNER_TEMP ('sokna-engine-permit-'+[guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Force -Path $root|Out-Null
$parents=[System.Collections.Generic.List[System.Diagnostics.Process]]::new()
function Start-Issuer([string]$root,[int]$millis=30000){
  $out=Join-Path $root ('issuer-'+[guid]::NewGuid().ToString('N')+'.out')
  $err=Join-Path $root ('issuer-'+[guid]::NewGuid().ToString('N')+'.err')
  New-Item -ItemType Directory -Force -Path $root|Out-Null
  $p=Start-Process dotnet -ArgumentList @($ProbeDll,'permit',$root,"$millis") -PassThru -RedirectStandardOutput $out -RedirectStandardError $err
  $parents.Add($p)
  $until=[DateTime]::UtcNow.AddSeconds(18)
  while([DateTime]::UtcNow -lt $until){
    if($p.HasExited){throw ('Issuer crashed '+(if(Test-Path $err){Get-Content $err -Raw}else{''}))}
    if(Test-Path $out){
      $text=Get-Content -LiteralPath $out -Raw
      if($text -match 'ENGINE_PERMIT_ISSUED ([0-9a-f]{32})'){
        return [pscustomobject]@{Process=$p;Id=$Matches[1]}
      }
    }
    Start-Sleep -Milliseconds 100
  }
  throw 'Permit issuer did not become ready'
}
try {
  $direct=@(& dotnet $EngineDll local-slot-migrate --data-root $root 2>&1)
  Assert ($LASTEXITCODE -eq 75 -and ($direct -join ' ').Contains('ENGINE_DELEGATION_REQUIRED')) 'Direct Engine mutation still permitted'
  $first=Start-Issuer $root
  $id=$first.Id
  $mismatch=@(& dotnet $EngineDll local-slot-recover --data-root $root --delegation-id $id 2>&1)
  Assert ($LASTEXITCODE -eq 75 -and ($mismatch -join ' ').Contains('ENGINE_DELEGATION_REJECTED')) 'Cross-command permit replay was accepted'
  $permitPath=Join-Path $root ('update/delegations/'+$id+'.json')
  Assert (Test-Path -LiteralPath $permitPath) 'Issued durable permit is missing'
  $original=[IO.File]::ReadAllBytes($permitPath)
  $forged=Get-Content $permitPath -Raw | ConvertFrom-Json
  $forged.signature='ab' * 32
  $forged | ConvertTo-Json -Depth 8 | Set-Content -LiteralPath $permitPath -Encoding utf8
  $badSignature=@(& dotnet $EngineDll local-slot-migrate --data-root $root --delegation-id $id 2>&1)
  Assert ($LASTEXITCODE -eq 75 -and ($badSignature -join ' ').Contains('ENGINE_DELEGATION_REJECTED')) 'Forged HMAC was accepted'
  [IO.File]::WriteAllBytes($permitPath,$original)
  $positive=@(& dotnet $EngineDll local-slot-migrate --data-root $root --delegation-id $id 2>&1)
  Assert ($LASTEXITCODE -eq 3 -and -not($positive -join ' ').Contains('ENGINE_DELEGATION_REJECTED')) ('Valid exact permit failed: '+($positive -join ' '))
  Assert (Test-Path -LiteralPath (Join-Path $root ('update/delegations/'+$id+'.consumed'))) 'Permit consumption marker missing'
  $replayed=@(& dotnet $EngineDll local-slot-migrate --data-root $root --delegation-id $id 2>&1)
  Assert ($LASTEXITCODE -eq 75 -and ($replayed -join ' ').Contains('ENGINE_DELEGATION_REJECTED')) 'Consumed permit replay was accepted'
  Stop-Process -Id $first.Process.Id -Force -ErrorAction SilentlyContinue
  $first.Process.WaitForExit(5000)|Out-Null

  $second=Start-Issuer $root
  $unconsumed=$second.Id
  Stop-Process -Id $second.Process.Id -Force
  $second.Process.WaitForExit(5000)|Out-Null
  $orphan=@(& dotnet $EngineDll local-slot-migrate --data-root $root --delegation-id $unconsumed 2>&1)
  Assert ($LASTEXITCODE -eq 75 -and ($orphan -join ' ').Contains('ENGINE_DELEGATION_REJECTED')) 'Dead Supervisor issuer still authorized Engine'
  $otherRoot=Join-Path $env:RUNNER_TEMP ('sokna-other-install-'+[guid]::NewGuid().ToString('N'))
  $third=Start-Issuer $root
  $foreign=@(& dotnet $EngineDll local-slot-migrate --data-root $otherRoot --delegation-id $third.Id 2>&1)
  Assert ($LASTEXITCODE -eq 75 -and ($foreign -join ' ').Contains('ENGINE_DELEGATION_REJECTED')) 'Cross-installation permit accepted'
  # Prove actual wall-clock expiry while the issuing parent is alive and
  # still owns its machine lease. No test-only shorter TTL is added to production.
  Stop-Process -Id $third.Process.Id -Force -ErrorAction SilentlyContinue
  $third.Process.WaitForExit(5000)|Out-Null
  $fourth=Start-Issuer $root 60000
  Start-Sleep -Seconds 47
  Assert (-not$fourth.Process.HasExited) 'Expiry test parent died before the permit TTL'
  $expired=@(& dotnet $EngineDll local-slot-migrate --data-root $root --delegation-id $fourth.Id 2>&1)
  Assert ($LASTEXITCODE -eq 75 -and ($expired -join ' ').Contains('ENGINE_DELEGATION_REJECTED')) 'Expired one-use permit was accepted while its parent remained alive'
  Assert (-not(Test-Path -LiteralPath (Join-Path $root ('update/delegations/'+$fourth.Id+'.consumed')))) 'Expired permit was consumed'
  [ordered]@{format='sokna-engine-delegation-windows-v1';result='PASS';direct_cli_denied=$true;exact_argv_binding=$true;forged_hmac_denied=$true;permit_consumed_once=$true;replay_denied=$true;dead_parent_denied=$true;cross_installation_denied=$true;expired_permit_denied=$true;no_successful_business_mutation=$true}|ConvertTo-Json
}
finally {
  foreach($p in $parents){
    if($null -ne $p -and -not$p.HasExited){Stop-Process -Id $p.Id -Force -ErrorAction SilentlyContinue}
  }
}
exit 0
