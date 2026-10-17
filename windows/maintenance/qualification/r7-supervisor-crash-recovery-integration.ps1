param(
  [Parameter(Mandatory=$true)][string]$SupervisorExe,
  [Parameter(Mandatory=$true)][string]$FakeApacheExe,
  [Parameter(Mandatory=$true)][string]$HarnessExe,
  [Parameter(Mandatory=$true)][string]$WorkspaceRoot
)
$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest

function Full([string]$p){[IO.Path]::GetFullPath($p)}
function Assert([bool]$ok,[string]$message){if(-not$ok){throw $message}}
$SupervisorExe=Full $SupervisorExe
$FakeApacheExe=Full $FakeApacheExe
$HarnessExe=Full $HarnessExe
$WorkspaceRoot=Full $WorkspaceRoot
New-Item -ItemType Directory -Path $WorkspaceRoot -Force|Out-Null

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
  $root=Join-Path $WorkspaceRoot $name
  if(Test-Path $root){Remove-Item $root -Recurse -Force}
  $package=Join-Path $root 'SOKNA'
  $web=Join-Path $package 'Web'
  $public=Join-Path $web 'public'
  $resources=Join-Path $web 'resources'
  $data=Join-Path $root 'maintenance-data'
  $apache=Join-Path $package 'Infrastructure\Apache24'
  New-Item -ItemType Directory -Path $public,$resources,$data,(Join-Path $apache 'bin'),(Join-Path $apache 'conf') -Force|Out-Null
  Copy-Item $FakeApacheExe (Join-Path $apache 'bin\httpd.exe') -Force

  $build='3.0.0-g6.2-workspace.999y-r7-supervisor-crash-fixture'
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
  [pscustomobject]@{Root=$root;Package=$package;Web=$web;Data=$data;Build=$build;Digest=$digest;Port=$port}
}
function Write-Request($f){
  $bootstrap=Join-Path $f.Data 'bootstrap'
  New-Item -ItemType Directory -Path $bootstrap -Force|Out-Null
  $requestId='adopt-r7-crash-'+[guid]::NewGuid().ToString('N')
  [ordered]@{
    format='sokna-local-slot-adoption-request-v1';schema_version=1;request_id=$requestId;
    expected_build=$f.Build;expected_managed_files=4;expected_managed_tree_sha256=$f.Digest;
    source_package_version='1.1.6-r7-crash-integration';created_at_utc=[DateTime]::UtcNow.ToString('o')
  }|ConvertTo-Json -Depth 5|Set-Content (Join-Path $bootstrap 'local-slot-adoption-request.json') -Encoding utf8
  return $requestId
}
function Write-Fault($f,[string]$fault){
  $stateRoot=Join-Path $f.Data 'local-slots'
  New-Item -ItemType Directory -Path $stateRoot -Force|Out-Null
  Set-Content (Join-Path $stateRoot 'test-engine-fault.txt') $fault -Encoding ascii
}
function Start-Supervisor($f,[int]$port){
  $p=Start-Process $SupervisorExe -ArgumentList @('--data-root',$f.Data,'--port',[string]$port) -PassThru -WindowStyle Hidden
  $descriptor=Join-Path $f.Data 'control-plane.json'
  $deadline=[DateTime]::UtcNow.AddSeconds(25)
  while([DateTime]::UtcNow-lt$deadline){
    if($p.HasExited){throw "Supervisor exited early: $($p.ExitCode)"}
    if(Test-Path $descriptor -PathType Leaf){return $p}
    Start-Sleep -Milliseconds 200
  }
  try{Stop-Process -Id $p.Id -Force -ErrorAction SilentlyContinue}catch{}
  throw 'Supervisor did not become ready.'
}
function Stop-Supervisor($p){
  if($null-eq$p){return}
  try{if(-not$p.HasExited){Stop-Process -Id $p.Id -Force -ErrorAction SilentlyContinue}}catch{}
}
function Wait-Status($f,[string]$state,[int]$seconds=35){
  $path=Join-Path $f.Data 'bootstrap\local-slot-adoption-status.json'
  $deadline=[DateTime]::UtcNow.AddSeconds($seconds)
  while([DateTime]::UtcNow-lt$deadline){
    if(Test-Path $path -PathType Leaf){
      try{$s=Get-Content $path -Raw|ConvertFrom-Json;if([string]$s.state-eq$state){return $s}}catch{}
    }
    Start-Sleep -Milliseconds 150
  }
  $last=if(Test-Path $path){Get-Content $path -Raw}else{'<missing>'}
  throw "Timed out waiting for adoption state '$state'. Last=$last"
}

$enginePath=Join-Path ([IO.Path]::GetDirectoryName($SupervisorExe)) 'SoknaMaintenanceUpdateEngine.exe'
$realEngineBackup=$enginePath+'.r7-real-backup'
if(-not(Test-Path $enginePath -PathType Leaf)){throw 'Real Update Engine is missing from Supervisor bundle.'}
Copy-Item $enginePath $realEngineBackup -Force
Copy-Item $HarnessExe $enginePath -Force
$results=@()
$supervisor=$null
try{
  # Scenario 1: abrupt engine death after physical Web move is still pre-activation.
  # Coordinator must recover the physical Web, return a retryable failure, and worker must commit on attempt 2.
  $f=New-Fixture 'crash-after-physical-move' 18461
  $requestId=Write-Request $f
  Write-Fault $f 'failfast:after_physical_move'
  $supervisor=Start-Supervisor $f 18741
  $retry=Wait-Status $f 'retry_wait' 35
  Assert ([int]$retry.attempt_count-eq1) 'Pre-activation crash did not record attempt 1.'
  Assert (-not[bool]$retry.activation_performed) 'Pre-activation crash incorrectly recorded activation.'
  Assert-PhysicalWeb $f.Web
  Assert ((Get-Service SoknaApache).Status-eq'Running') 'Apache was not restored before retry.'
  $committed=Wait-Status $f 'committed' 35
  Assert ([int]$committed.attempt_count-eq2) 'Recovered pre-activation crash did not commit on attempt 2.'
  Assert-JunctionWeb $f.Web
  Assert ((Get-Service SoknaApache).Status-eq'Running') 'Apache is not running after retry commit.'
  $results += [pscustomobject]@{scenario='failfast_after_physical_move';request_id=$requestId;first_state='retry_wait';final_state='committed';attempts=[int]$committed.attempt_count;result='PASS'}
  Stop-Supervisor $supervisor;$supervisor=$null

  # Scenario 2: abrupt engine death after pointer switch is post-activation.
  # It must stop unattended retries, require recovery, then signed recovery must verify the slot and the worker must reconcile queue -> committed.
  $f=New-Fixture 'crash-after-pointer-switch' 18462
  $requestId=Write-Request $f
  Write-Fault $f 'failfast:after_pointer_switch'
  $supervisor=Start-Supervisor $f 18742
  $recovery=Wait-Status $f 'recovery_required' 35
  Assert ([int]$recovery.attempt_count-eq1) 'Post-pointer crash did not stop at attempt 1.'
  Assert ([bool]$recovery.activation_performed) 'Post-pointer crash lost activation evidence.'
  Assert-JunctionWeb $f.Web
  Start-Sleep -Seconds 5
  $still=Get-Content (Join-Path $f.Data 'bootstrap\local-slot-adoption-status.json') -Raw|ConvertFrom-Json
  Assert ([string]$still.state-eq'recovery_required'-and[int]$still.attempt_count-eq1) 'Post-pointer crash was retried unattended.'

  $recoveryOutput=& $HarnessExe call-recover --data-root $f.Data 2>&1
  if($LASTEXITCODE-ne0){throw "Signed recovery call failed: $($recoveryOutput -join "`n")"}
  $reconciled=Wait-Status $f 'committed' 20
  Assert ([string]$reconciled.last_code-eq'LOCAL_ADOPTION_RECOVERY_COMMITTED') 'Verified recovery did not reconcile durable adoption status.'
  Assert ([int]$reconciled.attempt_count-eq1) 'Recovery reconciliation must not fabricate an automatic migration attempt.'
  Assert-JunctionWeb $f.Web
  Assert ((Get-Service SoknaApache).Status-eq'Running') 'Apache is not running after verified recovery.'
  $migrationState=Get-Content (Join-Path $f.Data 'local-slots\migration-state.json') -Raw|ConvertFrom-Json
  Assert ([string]$migrationState.phase-eq'RECOVERED_TO_VERIFIED_SLOT'-and-not[bool]$migrationState.recovery_required) 'Durable migration state was not verified after recovery.'
  $results += [pscustomobject]@{scenario='failfast_after_pointer_switch';request_id=$requestId;first_state='recovery_required';final_state='committed_after_signed_recovery';attempts=[int]$reconciled.attempt_count;result='PASS'}
  Stop-Supervisor $supervisor;$supervisor=$null

  [ordered]@{
    format='sokna-r7-supervisor-crash-recovery-integration-v1';
    source_git_sha=$env:GITHUB_SHA;
    scenarios=$results;
    database_mutation_performed=$false;public_mutation_performed=$false;windows_reboot_required=$false;
    generated_at_utc=[DateTime]::UtcNow.ToString('o')
  }|ConvertTo-Json -Depth 8|Set-Content (Join-Path $WorkspaceRoot 'R7_SUPERVISOR_CRASH_RECOVERY_INTEGRATION.json') -Encoding utf8
  Write-Host 'R7 Supervisor crash/recovery integration: PASS 2/2'
}
finally{
  Stop-Supervisor $supervisor
  Remove-SoknaApache
  if(Test-Path $realEngineBackup -PathType Leaf){Copy-Item $realEngineBackup $enginePath -Force;Remove-Item $realEngineBackup -Force}
}
