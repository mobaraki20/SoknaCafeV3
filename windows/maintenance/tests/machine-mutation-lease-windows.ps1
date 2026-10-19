param(
  [Parameter(Mandatory=$true)][string]$ProbeDll,
  [Parameter(Mandatory=$true)][string]$EngineDll
)
$ErrorActionPreference='Stop'
$PSNativeCommandUseErrorActionPreference=$false
Set-StrictMode -Version Latest
function Assert([bool]$OK,[string]$Reason){if(-not $OK){throw $Reason}}
$ProbeDll=(Resolve-Path -LiteralPath $ProbeDll).Path
$EngineDll=(Resolve-Path -LiteralPath $EngineDll).Path
$root=Join-Path $env:RUNNER_TEMP ('sokna-machine-lease-'+[guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Force -Path $root | Out-Null
$lockPath=Join-Path $root 'update/machine-mutation.lock'
$p=$null
try {
  $p=Start-Process dotnet -ArgumentList @($ProbeDll,'hold',$root,'30000') -PassThru
  $deadline=[DateTime]::UtcNow.AddSeconds(12)
  $ready=$false
  while([DateTime]::UtcNow -lt $deadline){
    Assert (-not$p.HasExited) 'Holder process exited before acquiring lease'
    if(Test-Path -LiteralPath $lockPath){
      try{if((Get-Content -LiteralPath $lockPath -Raw).Contains('owner=test-owner-A')){$ready=$true;break}}catch{}
    }
    Start-Sleep -Milliseconds 80
  }
  Assert $ready 'Holder did not acquire persistent Windows file-handle lease'
  $blocked=@(& dotnet $ProbeDll try $root 0 2>&1)
  Assert ($LASTEXITCODE -eq 75 -and ($blocked -join ' ').Contains('MACHINE_MUTATION_LEASE_BUSY')) ('Second process acquired lease: '+($blocked -join ' '))
  # The directly invoked legacy engine must fail BEFORE validating the
  # rest of this intentionally incomplete mutating command.
  $engineBlocked=@(& dotnet $EngineDll local-slot-migrate --data-root $root 2>&1)
  Assert ($LASTEXITCODE -eq 75 -and ($engineBlocked -join ' ').Contains('ENGINE_DELEGATION_REQUIRED')) 'Direct mutating Update Engine bypassed Supervisor authorization'
  $inspection=@(& dotnet $EngineDll local-slot-inspect --data-root $root 2>&1)
  Assert ($LASTEXITCODE -ne 75) 'Read-only engine inspection wrongly requires machine mutation lease'

  # Simulate process death. The file stays but the OS handle must be released.
  Stop-Process -Id $p.Id -Force
  $p.WaitForExit(5000) | Out-Null
  $p=$null
  Assert (Test-Path -LiteralPath $lockPath) 'Lock file was deleted, permitting inode/file replacement race'
  $afterCrash=@(& dotnet $ProbeDll try $root 0 2>&1)
  Assert ($LASTEXITCODE -eq 0 -and ($afterCrash -join ' ').Contains('MACHINE_LEASE_ACQUIRED')) 'Crash left stale process lock'
  $repeat=@(& dotnet $ProbeDll try $root 0 2>&1)
  Assert ($LASTEXITCODE -eq 0) 'Disposed lease could not be reused'
  [ordered]@{format='sokna-machine-mutation-lease-windows-v1';result='PASS';two_independent_processes=$true;direct_engine_fails_closed=$true;read_only_engine_not_blocked=$true;crash_releases_handle=$true;lock_file_not_deleted=$true}|ConvertTo-Json
}
finally {
  if($null -ne $p -and -not$p.HasExited){
    Stop-Process -Id $p.Id -Force -ErrorAction SilentlyContinue
  }
}
exit 0
