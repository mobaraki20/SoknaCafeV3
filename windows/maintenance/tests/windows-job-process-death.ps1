param([Parameter(Mandatory=$true)][string]$ProbeDll)
$ErrorActionPreference='Stop'
$PSNativeCommandUseErrorActionPreference=$false
Set-StrictMode -Version Latest
function Assert([bool]$v,[string]$why){if(-not$v){throw $why}}
$ProbeDll=(Resolve-Path -LiteralPath $ProbeDll).Path
$root=Join-Path $env:RUNNER_TEMP ('sokna-win-job-'+[guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Force -Path $root|Out-Null
$stdout=Join-Path $root 'parent.stdout'
$stderr=Join-Path $root 'parent.stderr'
$p=$null
try{
  $p=Start-Process dotnet -ArgumentList @($ProbeDll,'job-holder',$root,'30000') -PassThru -RedirectStandardOutput $stdout -RedirectStandardError $stderr
  $until=[DateTime]::UtcNow.AddSeconds(17)
  $childPid=0
  $heartbeat=Join-Path $root 'heartbeat.txt'
  while([DateTime]::UtcNow -lt $until){
    Assert (-not$p.HasExited) ('Suspended child launch failed: '+$(if(Test-Path $stderr){Get-Content $stderr -Raw}else{''}))
    if(Test-Path $stdout){
      $text=Get-Content $stdout -Raw
      if($text -match 'JOB_BOUND_CHILD ([0-9]+)'){
        $childPid=[int]$Matches[1]
        if((Test-Path $heartbeat) -and ((Get-Content $heartbeat).Count -ge 4)){break}
      }
    }
    Start-Sleep -Milliseconds 110
  }
  Assert ($childPid -gt 0) 'Parent never created a pre-resume Job-bound child'
  Assert (Test-Path $heartbeat) 'Job-bound child never executed after ResumeThread'
  $child=Get-Process -Id $childPid -ErrorAction SilentlyContinue
  Assert ($null-ne$child -and -not$child.HasExited) 'Child stopped before parent crash test'
  $before=(Get-Item -LiteralPath $heartbeat).Length
  Stop-Process -Id $p.Id -Force
  $p.WaitForExit(5000)|Out-Null
  $p=$null
  $ended=$false
  for($i=0;$i -lt 60;$i++){
    if(-not(Get-Process -Id $childPid -ErrorAction SilentlyContinue)){$ended=$true;break}
    Start-Sleep -Milliseconds 100
  }
  Assert $ended 'KILL_ON_JOB_CLOSE failed: orphan child survived parent crash'
  $after=(Get-Item -LiteralPath $heartbeat).Length
  Start-Sleep -Milliseconds 600
  Assert ((Get-Item -LiteralPath $heartbeat).Length -eq $after) 'Orphan heartbeat kept mutating after parent crash'
  [ordered]@{format='sokna-windows-job-parent-death-v1';result='PASS';child_suspended_until_job_assignment=$true;child_started_after_resume=$true;parent_forced_exit=$true;job_closure_killed_child=$true;heartbeat_stopped=$true;no_orphan_process=$true}|ConvertTo-Json
}finally{
  if($null-ne$p -and -not$p.HasExited){Stop-Process -Id $p.Id -Force -ErrorAction SilentlyContinue}
}
exit 0
