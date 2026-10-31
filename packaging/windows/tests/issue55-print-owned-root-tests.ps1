$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest
$repo=(Resolve-Path (Join-Path $PSScriptRoot '..\..\..')).Path
$core=(Join-Path $repo 'packaging\windows\scripts\setup-windows-components-v5.ps1')
$raw=[IO.File]::ReadAllText($core,[Text.Encoding]::UTF8)
$start=$raw.IndexOf('function Owned-TreeHash(',[StringComparison]::Ordinal)
$end=$raw.IndexOf('function Snapshot(',[StringComparison]::Ordinal)
if($start-lt0-or$end-le$start){throw 'ISSUE55_CORE_HELPERS_NOT_FOUND'}
. ([ScriptBlock]::Create($raw.Substring($start,$end-$start)))
function Sha([string]$p){if(-not(Test-Path -LiteralPath $p -PathType Leaf)){return ''};(Get-FileHash -LiteralPath $p -Algorithm SHA256).Hash.ToLowerInvariant()}
$root=Join-Path $env:RUNNER_TEMP ('issue55-print-'+[guid]::NewGuid().ToString('N'))
$src=Join-Path $root 'incoming';$dst=Join-Path $root 'installed';$backup=Join-Path $root 'snapshot'
try{
  foreach($p in @((Join-Path $src 'Service'),(Join-Path $src 'Worker'),(Join-Path $dst 'Service'),(Join-Path $dst 'Worker'))){New-Item -ItemType Directory -Path $p -Force|Out-Null}
  [IO.File]::WriteAllText((Join-Path $dst 'Service\Sokna.PrintAgent.Service.exe'),'old-service')
  [IO.File]::WriteAllText((Join-Path $dst 'Worker\Sokna.PrintAgent.Worker.exe'),'old-worker')
  [IO.File]::WriteAllText((Join-Path $dst 'preserve-config.txt'),'must-not-be-deleted')
  $original=Owned-TreeHash $dst
  Copy-Item -LiteralPath $dst -Destination $backup -Recurse -Force
  if((Owned-TreeHash $backup)-ne$original){throw 'ISSUE55_SNAPSHOT_MISMATCH'}
  [IO.File]::WriteAllText((Join-Path $src 'Service\Sokna.PrintAgent.Service.exe'),'new-service')
  [IO.File]::WriteAllText((Join-Path $src 'Worker\Sokna.PrintAgent.Worker.exe'),'new-worker')
  $locked=Join-Path $dst 'Worker\Sokna.PrintAgent.Worker.exe'
  $handle=[IO.File]::Open($locked,[IO.FileMode]::Open,[IO.FileAccess]::Read,[IO.FileShare]::None)
  try{
    $blocked=$false
    try{Copy-PrintTreeInPlace $src $dst}catch{
      $blocked=([string]$_.Exception.Message).Contains('PRINT_AGENT_LOCK_OR_FILE_ACL')
    }
    if(-not$blocked){throw 'ISSUE55_LOCK_WAS_NOT_DETECTED'}
    if((Owned-TreeHash $dst)-ne$original){throw 'ISSUE55_LOCK_TEST_MUTATED_SOURCE'}
  }finally{$handle.Dispose()}
  Copy-PrintTreeInPlace $src $dst
  if((Sha (Join-Path $src 'Service\Sokna.PrintAgent.Service.exe'))-ne(Sha (Join-Path $dst 'Service\Sokna.PrintAgent.Service.exe'))){throw 'ISSUE55_SERVICE_COPY_HASH'}
  if((Sha (Join-Path $src 'Worker\Sokna.PrintAgent.Worker.exe'))-ne(Sha (Join-Path $dst 'Worker\Sokna.PrintAgent.Worker.exe'))){throw 'ISSUE55_WORKER_COPY_HASH'}
  if([IO.File]::ReadAllText((Join-Path $dst 'preserve-config.txt'))-ne'must-not-be-deleted'){throw 'ISSUE55_OWNED_CONFIG_LOST'}
  Restore-PrintTree $backup $dst $true $original
  if((Owned-TreeHash $dst)-ne$original){throw 'ISSUE55_ROLLBACK_READBACK_MISMATCH'}
  if([IO.File]::ReadAllText((Join-Path $dst 'Service\Sokna.PrintAgent.Service.exe'))-ne'old-service'){throw 'ISSUE55_RESTORE_CONTENT'}
  Write-Host 'ISSUE55_PRINT_OWNED_ROOT_LOCK_AND_ROLLBACK_PASS'
}finally{if(Test-Path -LiteralPath $root){Remove-Item -LiteralPath $root -Recurse -Force}}
