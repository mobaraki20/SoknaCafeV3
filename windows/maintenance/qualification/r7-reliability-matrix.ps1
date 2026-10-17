param(
  [Parameter(Mandatory=$true)][string]$HarnessExe,
  [Parameter(Mandatory=$true)][string]$FakeApacheExe,
  [Parameter(Mandatory=$true)][string]$QueueScript,
  [Parameter(Mandatory=$true)][string]$WorkspaceRoot
)
$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest

function Full([string]$p){[IO.Path]::GetFullPath($p)}
$HarnessExe=Full $HarnessExe
$FakeApacheExe=Full $FakeApacheExe
$QueueScript=Full $QueueScript
$WorkspaceRoot=Full $WorkspaceRoot
$fixtureRoot=Join-Path $WorkspaceRoot 'fixtures'
New-Item -ItemType Directory -Path $fixtureRoot -Force|Out-Null
$script:passed=0
$script:results=@()

function Pass([string]$name,[string]$kind){
  $script:passed++
  $script:results += [pscustomobject]@{cycle=$script:passed;name=$name;kind=$kind;result='PASS'}
  Write-Host ("PASS [{0:00}] {1}" -f $script:passed,$name)
}
function Assert([bool]$ok,[string]$message){if(-not$ok){throw $message}}
function Remove-SoknaApache {
  try { Stop-Service SoknaApache -Force -ErrorAction SilentlyContinue } catch {}
  & "$env:SystemRoot\System32\sc.exe" delete SoknaApache *> $null
  $global:LASTEXITCODE=0
  for($i=0;$i-lt40;$i++){
    & "$env:SystemRoot\System32\sc.exe" query SoknaApache *> $null
    if($LASTEXITCODE-ne0){$global:LASTEXITCODE=0;return}
    Start-Sleep -Milliseconds 200
  }
  throw 'SoknaApache test service could not be deleted.'
}
function Assert-PhysicalWeb([string]$web){
  Assert (Test-Path $web -PathType Container) "Physical Web missing: $web"
  $item=Get-Item $web -Force
  Assert (($item.Attributes-band[IO.FileAttributes]::ReparsePoint)-eq0) "Expected physical Web, found reparse point: $web"
}
function Assert-JunctionWeb([string]$web){
  Assert (Test-Path $web -PathType Container) "Stable Web missing: $web"
  $item=Get-Item $web -Force
  Assert (($item.Attributes-band[IO.FileAttributes]::ReparsePoint)-ne0) "Expected Web junction: $web"
}
function New-Fixture([string]$name,[int]$port){
  Remove-SoknaApache
  $root=Join-Path $fixtureRoot $name
  if(Test-Path $root){Remove-Item $root -Recurse -Force}
  $package=Join-Path $root 'SOKNA'
  $web=Join-Path $package 'Web'
  $public=Join-Path $web 'public'
  $resources=Join-Path $web 'resources'
  $data=Join-Path $root 'maintenance-data'
  $apache=Join-Path $package 'Infrastructure\Apache24'
  New-Item -ItemType Directory -Path $public,$resources,$data,(Join-Path $apache 'bin'),(Join-Path $apache 'conf') -Force|Out-Null
  Copy-Item $FakeApacheExe (Join-Path $apache 'bin\httpd.exe') -Force

  $build='3.0.0-g6.2-workspace.999z-r7-reliability-fixture'
  Set-Content (Join-Path $web 'VERSION.txt') $build -Encoding ascii
  Set-Content (Join-Path $web 'app.txt') 'fixture-app' -Encoding ascii
  Set-Content (Join-Path $public 'login.php') '<?php // fixture ?>' -Encoding ascii
  Set-Content (Join-Path $web '.sokna-installation.json') '{"installation_id":"fixture-installation"}' -Encoding ascii
  Set-Content (Join-Path $public 'local-recovery.php') '<?php // recovery ?>' -Encoding ascii
  Set-Content (Join-Path $public 'local-update-runner.php') '<?php // update ?>' -Encoding ascii
  Set-Content (Join-Path $package 'config.php') '<?php // persistent config ?>' -Encoding ascii
  Set-Content (Join-Path $package 'install.lock') 'fixture-lock' -Encoding ascii
  [ordered]@{format='sokna-local-managed-files-v1';schema_version=1;files=@('VERSION.txt','app.txt','public/login.php','resources/local-managed-files-v1.json')} | ConvertTo-Json -Depth 4 | Set-Content (Join-Path $resources 'local-managed-files-v1.json') -Encoding utf8

  $slashPublic=$public.Replace('\','/')
  $slashInclude=(Join-Path $apache 'conf\sokna-local.conf').Replace('\','/')
  @"
ServerRoot "$($apache.Replace('\','/'))"
# BEGIN SOKNA LOCAL MANAGED INCLUDE v1
Include "$slashInclude"
# END SOKNA LOCAL MANAGED INCLUDE v1
"@ | Set-Content (Join-Path $apache 'conf\httpd.conf') -Encoding ascii
  @"
# SOKNA-MANAGED-APACHE-VHOST v1
<VirtualHost *:$port>
    DocumentRoot "$slashPublic"
</VirtualHost>
"@ | Set-Content (Join-Path $apache 'conf\sokna-local.conf') -Encoding ascii

  $httpd=(Resolve-Path (Join-Path $apache 'bin\httpd.exe')).Path
  & "$env:SystemRoot\System32\sc.exe" create SoknaApache binPath= ('"'+$httpd+'"') start= demand | Out-Null
  if($LASTEXITCODE-ne0){throw 'SoknaApache fixture registration failed.'}
  Start-Service SoknaApache
  (Get-Service SoknaApache).WaitForStatus([ServiceProcess.ServiceControllerStatus]::Running,[TimeSpan]::FromSeconds(10))
  $digest=(& $HarnessExe digest $web 4).Trim()
  if($LASTEXITCODE-ne0-or$digest.Length-ne64){throw 'Fixture managed-tree digest failed.'}
  [pscustomobject]@{Root=$root;Package=$package;Web=$web;Data=$data;Apache=$apache;Build=$build;Digest=$digest;Port=$port}
}
function Run-Migrate($f,[string]$inject='none'){
  $out=& $HarnessExe migrate $f.Data $f.Build 4 $f.Digest $inject 2>&1
  [pscustomobject]@{Exit=$LASTEXITCODE;Output=($out -join "`n")}
}
function Run-Recover($f){
  $out=& $HarnessExe recover $f.Data 2>&1
  [pscustomobject]@{Exit=$LASTEXITCODE;Output=($out -join "`n")}
}

$programDataBootstrap=Join-Path ([Environment]::GetFolderPath([Environment+SpecialFolder]::CommonApplicationData)) 'SOKNA\Maintenance\bootstrap'
$programDataRequest=Join-Path $programDataBootstrap 'local-slot-adoption-request.json'
$programDataStatus=Join-Path $programDataBootstrap 'local-slot-adoption-status.json'

try {
  # Cycles 1-8: clean physical -> immutable slot activation.
  for($i=1;$i-le8;$i++){
    $f=New-Fixture ("success-{0:00}" -f $i) (18300+$i)
    $m=Run-Migrate $f
    Assert ($m.Exit-eq0) "Healthy migration cycle $i failed: $($m.Output)"
    $r=$m.Output|ConvertFrom-Json
    Assert ($r.ok-and$r.phase-eq'COMMITTED') "Healthy migration cycle $i did not commit."
    Assert (-not$r.database_mutation_performed-and-not$r.public_mutation_performed-and-not$r.windows_reboot_required) "Healthy cycle $i violated mutation boundary."
    Assert-JunctionWeb $f.Web
    Assert ((Get-Service SoknaApache).Status-eq'Running') "Apache not running after healthy cycle $i."
    Pass ("healthy_migration_{0:00}" -f $i) 'healthy'
  }

  # Cycles 9-12: failure after pointer switch must auto-rollback to healthy physical Web.
  for($i=9;$i-le12;$i++){
    $f=New-Fixture ("rollback-{0:00}" -f $i) (18300+$i)
    $m=Run-Migrate $f 'throw:after_pointer_switch'
    Assert ($m.Exit-ne0) "Injected activation failure $i unexpectedly succeeded."
    Assert-PhysicalWeb $f.Web
    $state=Get-Content (Join-Path $f.Data 'local-slots\migration-state.json') -Raw|ConvertFrom-Json
    Assert ($state.phase-eq'ROLLED_BACK'-and-not$state.recovery_required-and$state.activation_performed) "Rollback state invalid in cycle $i."
    Assert ((Get-Service SoknaApache).Status-eq'Running') "Apache not running after rollback cycle $i."
    Pass ("post_pointer_failure_rollback_{0:00}" -f $i) 'rollback'
  }

  # Cycles 13-16: abrupt process death after physical move must recover from durable state without Local Web.
  for($i=13;$i-le16;$i++){
    $f=New-Fixture ("crash-{0:00}" -f $i) (18300+$i)
    $m=Run-Migrate $f 'failfast:after_physical_move'
    Assert ($m.Exit-ne0) "Injected failfast $i unexpectedly succeeded."
    Assert (-not(Test-Path $f.Web)) "Crash cycle $i should leave Web absent before recovery."
    $state=Get-Content (Join-Path $f.Data 'local-slots\migration-state.json') -Raw|ConvertFrom-Json
    Assert ($state.phase-eq'PHYSICAL_WEB_BACKED_UP'-and(Test-Path $state.physical_web_backup)) "Crash durable state invalid in cycle $i."
    $rec=Run-Recover $f
    Assert ($rec.Exit-eq0) "Crash recovery cycle $i failed: $($rec.Output)"
    Assert-PhysicalWeb $f.Web
    Assert ((Get-Service SoknaApache).Status-eq'Running') "Apache not running after crash recovery $i."
    Pass ("crash_after_backup_recovery_{0:00}" -f $i) 'recovery'
  }

  # Cycles 17-18: unhealthy baseline must fail before any activation/state mutation.
  for($i=17;$i-le18;$i++){
    $f=New-Fixture ("unhealthy-{0:00}" -f $i) (18300+$i)
    Stop-Service SoknaApache -Force
    (Get-Service SoknaApache).WaitForStatus([ServiceProcess.ServiceControllerStatus]::Stopped,[TimeSpan]::FromSeconds(10))
    $m=Run-Migrate $f
    Assert ($m.Exit-ne0) "Unhealthy baseline $i unexpectedly migrated."
    Assert-PhysicalWeb $f.Web
    Assert (-not(Test-Path (Join-Path $f.Data 'local-slots\migration-state.json'))) "Unhealthy baseline $i created migration state before baseline proof."
    Pass ("unhealthy_baseline_zero_activation_{0:00}" -f $i) 'preflight'
  }

  # Cycle 19: rerunning a committed adoption is a verified no-op and preserves physical backup evidence.
  $f=New-Fixture 'idempotent-19' 18319
  $first=Run-Migrate $f
  Assert ($first.Exit-eq0) 'Cycle 19 initial migration failed.'
  $statePath=Join-Path $f.Data 'local-slots\migration-state.json'
  $before=Get-Content $statePath -Raw|ConvertFrom-Json
  $backup=[string]$before.physical_web_backup
  $second=Run-Migrate $f
  Assert ($second.Exit-eq0) 'Cycle 19 idempotent rerun failed.'
  $after=Get-Content $statePath -Raw|ConvertFrom-Json
  Assert ([string]$after.physical_web_backup-eq$backup) 'Cycle 19 lost durable physical backup evidence.'
  Assert-JunctionWeb $f.Web
  Pass 'committed_rerun_is_verified_noop_19' 'idempotency'

  # Cycle 20: recovery must work when Web entry is absent and Apache is down.
  $f=New-Fixture 'webdown-recovery-20' 18320
  $m=Run-Migrate $f
  Assert ($m.Exit-eq0) 'Cycle 20 initial migration failed.'
  Stop-Service SoknaApache -Force
  (Get-Service SoknaApache).WaitForStatus([ServiceProcess.ServiceControllerStatus]::Stopped,[TimeSpan]::FromSeconds(10))
  [IO.Directory]::Delete($f.Web,$false)
  Assert (-not(Test-Path $f.Web)) 'Cycle 20 failed to remove stable Web junction.'
  $rec=Run-Recover $f
  Assert ($rec.Exit-eq0) "Cycle 20 recovery failed: $($rec.Output)"
  Assert-PhysicalWeb $f.Web
  Assert ((Get-Service SoknaApache).Status-eq'Running') 'Cycle 20 Apache not running after recovery.'
  Pass 'web_absent_apache_down_recovery_20' 'recovery'

  Assert ($script:passed-eq20) "Expected 20 production bridge cycles, got $script:passed."

  # Separate R7 bootstrap regression: no HTTP readiness/control-plane/HMAC dependency exists anymore.
  $f=New-Fixture 'queue-regression' 18350
  if(Test-Path $programDataRequest){Remove-Item $programDataRequest -Force}
  if(Test-Path $programDataStatus){Remove-Item $programDataStatus -Force}
  $shell=Join-Path $f.Root 'shell'
  $tx=Join-Path $f.Root 'tx'
  New-Item -ItemType Directory -Path $shell,$tx -Force|Out-Null
  [ordered]@{
    format='sokna-local-slot-bootstrap-contract-v1';schema_version=1;database_mutation=$false;public_mutation=$false;windows_reboot_required=$false;
    entries=@([ordered]@{build=$f.Build;managed_files=4;managed_tree_sha256=$f.Digest})
  }|ConvertTo-Json -Depth 6|Set-Content (Join-Path $shell 'local-slot-bootstrap-contract-v1.json') -Encoding utf8
  $planPath=Join-Path $tx 'install-plan.json'
  [ordered]@{format='sokna-windows-install-plan-v3';schema_version=3;transaction_id='r7-matrix-queue';package_version='test-r7';shell_root=$shell}|ConvertTo-Json -Depth 5|Set-Content $planPath -Encoding utf8

  # A stale descriptor is deliberately present, but no Supervisor service/listener/key is required by R7 queueing.
  $maintenanceRoot=Split-Path $programDataBootstrap -Parent
  New-Item -ItemType Directory -Path $maintenanceRoot -Force|Out-Null
  Set-Content (Join-Path $maintenanceRoot 'control-plane.json') '{"base_url":"http://127.0.0.1:65530","stale":true}' -Encoding ascii
  & "$env:SystemRoot\System32\WindowsPowerShell\v1.0\powershell.exe" -NoProfile -NonInteractive -ExecutionPolicy Bypass -File $QueueScript -PlanFile $planPath
  Assert ($LASTEXITCODE-eq0) 'R7 durable queue helper failed in stale-descriptor/no-listener regression.'
  Assert (Test-Path $programDataRequest -PathType Leaf) 'R7 durable adoption request was not persisted.'
  $request=Get-Content $programDataRequest -Raw|ConvertFrom-Json
  Assert ($request.expected_build-eq$f.Build-and[int]$request.expected_managed_files-eq4-and$request.expected_managed_tree_sha256-eq$f.Digest) 'R7 durable request identity mismatch.'
  $queueSource=Get-Content $QueueScript -Raw
  foreach($forbidden in @('control-plane.json','web-adapter.key','X-Sokna-Signature','/v1/local-slots/migrate')){
    Assert (-not$queueSource.Contains($forbidden)) "R7 queue helper regressed to synchronous Supervisor bootstrap: $forbidden"
  }
  Write-Host 'PASS R7 durable queue decouples installer from Supervisor readiness/HMAC transport.'

  # Repeated installer execution must leave the same request rather than duplicate/replace it.
  $firstBytes=[IO.File]::ReadAllBytes($programDataRequest)
  & "$env:SystemRoot\System32\WindowsPowerShell\v1.0\powershell.exe" -NoProfile -NonInteractive -ExecutionPolicy Bypass -File $QueueScript -PlanFile $planPath
  Assert ($LASTEXITCODE-eq0) 'Repeated R7 queue helper invocation failed.'
  $secondBytes=[IO.File]::ReadAllBytes($programDataRequest)
  Assert ([Convert]::ToBase64String($firstBytes)-eq[Convert]::ToBase64String($secondBytes)) 'Repeated queue invocation rewrote the durable request.'
  Write-Host 'PASS R7 repeated installer queue is idempotent.'

  [ordered]@{
    format='sokna-r7-reliability-matrix-v1';schema_version=1;production_bridge_cycles=$script:passed;all_cycles_passed=$true;
    durable_queue_regressions_passed=$true;database_mutation_performed=$false;public_mutation_performed=$false;windows_reboot_required=$false;
    results=$script:results;completed_at_utc=[DateTimeOffset]::UtcNow.ToString('o')
  }|ConvertTo-Json -Depth 8|Set-Content (Join-Path $WorkspaceRoot 'R7_RELIABILITY_MATRIX.json') -Encoding utf8
  Write-Host 'R7 RELIABILITY MATRIX: PASS (20/20 real bridge cycles + durable queue regressions)'
}
finally {
  Remove-SoknaApache
  foreach($p in @($programDataRequest,$programDataStatus)){
    if(Test-Path $p){Remove-Item $p -Force -ErrorAction SilentlyContinue}
  }
}
