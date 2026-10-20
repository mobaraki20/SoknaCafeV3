param(
  [Parameter(Mandatory=$true)][string]$SupervisorExe,
  [Parameter(Mandatory=$true)][string]$FakeApacheExe,
  [Parameter(Mandatory=$true)][string]$HarnessExe,
  [Parameter(Mandatory=$true)][string]$WorkspaceRoot
)
$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest

function Full([string]$p){[IO.Path]::GetFullPath($p)}
$SupervisorExe=Full $SupervisorExe
$FakeApacheExe=Full $FakeApacheExe
$HarnessExe=Full $HarnessExe
$WorkspaceRoot=Full $WorkspaceRoot
New-Item -ItemType Directory -Path $WorkspaceRoot -Force|Out-Null

function Assert([bool]$condition,[string]$message){if(-not$condition){throw $message}}
function Remove-Apache {
  try{Stop-Service SoknaApache -Force -ErrorAction SilentlyContinue}catch{}
  & "$env:SystemRoot\System32\sc.exe" delete SoknaApache *> $null
  $global:LASTEXITCODE=0
  for($i=0;$i-lt50;$i++){
    & "$env:SystemRoot\System32\sc.exe" query SoknaApache *> $null
    if($LASTEXITCODE-ne0){$global:LASTEXITCODE=0;return}
    Start-Sleep -Milliseconds 200
  }
  throw 'SoknaApache fixture service could not be deleted.'
}
function Stop-Supervisor($proc){
  if($null-eq$proc){return}
  try{
    if(-not$proc.HasExited){Stop-Process -Id $proc.Id -Force -ErrorAction SilentlyContinue}
    try{Wait-Process -Id $proc.Id -Timeout 15 -ErrorAction SilentlyContinue}catch{}
  }catch{}
}
function Start-Supervisor([string]$dataRoot,[int]$port,[string]$logRoot){
  New-Item -ItemType Directory -Path $logRoot -Force|Out-Null
  $stdout=Join-Path $logRoot 'supervisor.stdout.log'
  $stderr=Join-Path $logRoot 'supervisor.stderr.log'
  $p=Start-Process -FilePath $SupervisorExe -ArgumentList @('--data-root',$dataRoot,'--port',([string]$port)) -PassThru -RedirectStandardOutput $stdout -RedirectStandardError $stderr
  Start-Sleep -Milliseconds 300
  if($p.HasExited){
    $out=$(if(Test-Path $stdout){Get-Content $stdout -Raw}else{''})
    $err=$(if(Test-Path $stderr){Get-Content $stderr -Raw}else{''})
    throw "Supervisor exited during startup. code=$($p.ExitCode) stdout=$out stderr=$err"
  }
  return $p
}
function Wait-Status([string]$statusPath,[string[]]$states,[int]$timeoutSeconds=90){
  $deadline=[DateTime]::UtcNow.AddSeconds($timeoutSeconds)
  $last=$null
  while([DateTime]::UtcNow-lt$deadline){
    if(Test-Path $statusPath -PathType Leaf){
      try{
        $last=Get-Content $statusPath -Raw|ConvertFrom-Json
        if($states-contains[string]$last.state){return $last}
      }catch{}
    }
    Start-Sleep -Milliseconds 100
  }
  $summary=$(if($null-ne$last){$last|ConvertTo-Json -Compress -Depth 8}else{'<none>'})
  throw "Timed out waiting for adoption state [$($states -join ',')]. last=$summary"
}
function New-Fixture([string]$name,[int]$apachePort){
  Remove-Apache
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

  $build='3.0.0-g6.2-workspace.999r7-process-integration'
  Set-Content (Join-Path $web 'VERSION.txt') $build -Encoding ascii
  Set-Content (Join-Path $web 'app.txt') 'fixture-app' -Encoding ascii
  Set-Content (Join-Path $public 'login.php') '<?php // fixture ?>' -Encoding ascii
  # R8 routine Local binding requires the exact installation lock identity
  # and current Web build. Old R7 fixtures with a bare ID are invalid now.
  [ordered]@{format='sokna-local-installation-binding-v1';
    installation_id='fixture-process-integration';web_version=$build;
    bound_at='2026-10-20T00:00:00Z';source='disposable-r7-test'} |
    ConvertTo-Json -Compress | Set-Content (Join-Path $web '.sokna-installation.json') -Encoding utf8
  Set-Content (Join-Path $public 'local-recovery.php') '<?php // recovery ?>' -Encoding ascii
  Set-Content (Join-Path $public 'local-update-runner.php') '<?php // update ?>' -Encoding ascii
  Set-Content (Join-Path $package 'config.php') '<?php // persistent config ?>' -Encoding ascii
  [ordered]@{format='sokna-install-lock-v3';installation_id='fixture-process-integration'} |
    ConvertTo-Json -Compress | Set-Content (Join-Path $package 'install.lock') -Encoding utf8
  [ordered]@{format='sokna-local-managed-files-v1';schema_version=1;files=@('VERSION.txt','app.txt','public/login.php','resources/local-managed-files-v1.json')}|ConvertTo-Json -Depth 4|Set-Content (Join-Path $resources 'local-managed-files-v1.json') -Encoding utf8

  $slashPublic=$public.Replace('\','/')
  $slashInclude=(Join-Path $apache 'conf\sokna-local.conf').Replace('\','/')
  @"
ServerRoot "$($apache.Replace('\','/'))"
# BEGIN SOKNA LOCAL MANAGED INCLUDE v1
Include "$slashInclude"
# END SOKNA LOCAL MANAGED INCLUDE v1
"@|Set-Content (Join-Path $apache 'conf\httpd.conf') -Encoding ascii
  @"
# SOKNA-MANAGED-APACHE-VHOST v1
<VirtualHost *:$apachePort>
    DocumentRoot "$slashPublic"
</VirtualHost>
"@|Set-Content (Join-Path $apache 'conf\sokna-local.conf') -Encoding ascii

  $httpd=(Resolve-Path (Join-Path $apache 'bin\httpd.exe')).Path
  & "$env:SystemRoot\System32\sc.exe" create SoknaApache binPath= ('"'+$httpd+'"') start= demand|Out-Null
  if($LASTEXITCODE-ne0){throw 'SoknaApache fixture registration failed.'}
  Start-Service SoknaApache
  (Get-Service SoknaApache).WaitForStatus([ServiceProcess.ServiceControllerStatus]::Running,[TimeSpan]::FromSeconds(10))

  $digest=(& $HarnessExe digest $web 4).Trim()
  if($LASTEXITCODE-ne0-or$digest.Length-ne64){throw 'Fixture managed-tree digest failed.'}
  [pscustomobject]@{Root=$root;Package=$package;Web=$web;Data=$data;Build=$build;Digest=$digest;ApachePort=$apachePort}
}
function Write-Request($fixture,[string]$requestId){
  $bootstrap=Join-Path $fixture.Data 'bootstrap'
  New-Item -ItemType Directory -Path $bootstrap -Force|Out-Null
  $path=Join-Path $bootstrap 'local-slot-adoption-request.json'
  [ordered]@{
    format='sokna-local-slot-adoption-request-v1';schema_version=1;request_id=$requestId;
    expected_build=$fixture.Build;expected_managed_files=4;expected_managed_tree_sha256=$fixture.Digest;
    source_package_version='r7-process-integration';created_at_utc=[DateTimeOffset]::UtcNow.ToString('o')
  }|ConvertTo-Json -Depth 5|Set-Content $path -Encoding utf8
  return $path
}
function Assert-Junction([string]$path){
  Assert (Test-Path $path -PathType Container) "Stable Web missing: $path"
  $item=Get-Item $path -Force
  Assert (($item.Attributes-band[IO.FileAttributes]::ReparsePoint)-ne0) "Expected Web junction: $path"
}

$proc=$null
try{
  # Scenario 1: a queued request is consumed after real Supervisor process startup without installer HTTP bootstrap.
  $f=New-Fixture 'process-commit' 18441
  Write-Request $f 'adopt-process-commit'|Out-Null
  $proc=Start-Supervisor $f.Data 18541 (Join-Path $f.Root 'logs-1')
  $statusPath=Join-Path $f.Data 'bootstrap\local-slot-adoption-status.json'
  $status=Wait-Status $statusPath @('committed') 120
  Assert ([int]$status.attempt_count-eq1) 'Fresh process commit should complete on attempt 1.'
  Assert ([bool]$status.activation_performed) 'Fresh process commit should record activation.'
  Assert-Junction $f.Web
  Assert ((Get-Service SoknaApache).Status-eq'Running') 'Apache is not running after process commit.'
  Stop-Supervisor $proc;$proc=$null
  Write-Host 'PASS real Supervisor startup consumes durable adoption request.'

  # Scenario 2: pre-mutation failure becomes durable retry; Supervisor is killed; a fresh process resumes it.
  $f=New-Fixture 'process-restart-resume' 18442
  Stop-Service SoknaApache -Force
  (Get-Service SoknaApache).WaitForStatus([ServiceProcess.ServiceControllerStatus]::Stopped,[TimeSpan]::FromSeconds(10))
  Write-Request $f 'adopt-process-restart-resume'|Out-Null
  $proc=Start-Supervisor $f.Data 18542 (Join-Path $f.Root 'logs-before-restart')
  $statusPath=Join-Path $f.Data 'bootstrap\local-slot-adoption-status.json'
  $retry=Wait-Status $statusPath @('retry_wait') 90
  Assert ([int]$retry.attempt_count-ge1) 'Stopped-Apache scenario did not persist a retry attempt.'
  Assert (-not[bool]$retry.activation_performed) 'Pre-mutation retry incorrectly claimed activation.'
  Stop-Supervisor $proc;$proc=$null

  Start-Service SoknaApache
  (Get-Service SoknaApache).WaitForStatus([ServiceProcess.ServiceControllerStatus]::Running,[TimeSpan]::FromSeconds(10))
  $proc=Start-Supervisor $f.Data 18542 (Join-Path $f.Root 'logs-after-restart')
  $committed=Wait-Status $statusPath @('committed') 120
  Assert ([int]$committed.attempt_count-ge2) 'Restarted Supervisor did not resume with a later attempt.'
  Assert ([bool]$committed.activation_performed) 'Restarted Supervisor commit did not record activation.'
  Assert-Junction $f.Web
  Assert ((Get-Service SoknaApache).Status-eq'Running') 'Apache is not running after restart-resume commit.'
  Stop-Supervisor $proc;$proc=$null
  Write-Host 'PASS real Supervisor restart resumes durable pre-mutation retry and commits.'

  [ordered]@{
    format='sokna-r7-supervisor-process-integration-v1';schema_version=1;startup_consume_pass=$true;restart_resume_pass=$true;
    installer_http_bootstrap_required=$false;database_mutation_performed=$false;public_mutation_performed=$false;windows_reboot_required=$false;
    completed_at_utc=[DateTimeOffset]::UtcNow.ToString('o')
  }|ConvertTo-Json -Depth 5|Set-Content (Join-Path $WorkspaceRoot 'R7_SUPERVISOR_PROCESS_INTEGRATION.json') -Encoding utf8
  Write-Host 'R7 SUPERVISOR PROCESS INTEGRATION: PASS'
}
finally{
  Stop-Supervisor $proc
  Remove-Apache
}
