# F1-S7E3A: disposable GitHub Windows Runner only. Real Windows Apache & SCM.
# No SOKNA installed Apache service, Local host, Public, or DB is accessed.
$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
if ($env:GITHUB_ACTIONS -ne 'true' -or $env:RUNNER_OS -ne 'Windows' -or
    -not $env:RUNNER_TEMP -or -not (Test-Path -LiteralPath $env:RUNNER_TEMP -PathType Container)) {
  throw 'S7E3A_ONLY_DISPOSABLE_GITHUB_WINDOWS_RUNNER'
}

function Assert([bool] $ok, [string] $code) { if (-not $ok) { throw ('S7E3A_' + $code) } }
function Normalize([string] $p) { return [IO.Path]::GetFullPath($p).Replace('\','/') }
function HttpContent([int] $port) {
  try {
    $response = Invoke-WebRequest -Uri "http://127.0.0.1:$port/health.txt" -UseBasicParsing -TimeoutSec 2
    if ([int]$response.StatusCode -ne 200) { return $null }
    return ([string]$response.Content).Trim()
  } catch { return $null }
}
function AwaitHttp([int] $port, [string] $expected, [int] $seconds = 15) {
  $sw = [Diagnostics.Stopwatch]::StartNew()
  while ($sw.Elapsed.TotalSeconds -lt $seconds) {
    if ((HttpContent $port) -ceq $expected) { return $true }
    Start-Sleep -Milliseconds 180
  }
  return $false
}
function AwaitDown([int] $port, [int] $seconds = 15) {
  $sw = [Diagnostics.Stopwatch]::StartNew()
  while ($sw.Elapsed.TotalSeconds -lt $seconds) {
    if ($null -eq (HttpContent $port)) { return $true }
    Start-Sleep -Milliseconds 180
  }
  return $false
}
function Junction([string] $path, [string] $target) {
  Assert (-not (Test-Path -LiteralPath $path)) 'JUNCTION_ALREADY_EXISTS'
  & cmd.exe /d /c mklink /J $path $target | Out-Null
  Assert ($LASTEXITCODE -eq 0) 'MKLINK_FAILED'
  $link = Get-Item -LiteralPath $path -Force
  Assert (($link.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) 'EXPECTED_JUNCTION'
}
function RemoveJunction([string] $path) {
  if (Test-Path -LiteralPath $path) {
    Assert (((Get-Item -LiteralPath $path -Force).Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) 'REFUSE_REMOVE_NON_JUNCTION'
    & cmd.exe /d /c rmdir $path | Out-Null
    Assert ($LASTEXITCODE -eq 0) 'RMDIR_JUNCTION_FAILED'
  }
}

$fixture = Join-Path $env:RUNNER_TEMP ('sokna-f1-s7e3a-' + [guid]::NewGuid().ToString('N'))
$install = Join-Path $fixture 'apache-packaged'
$package = Join-Path $fixture 'slots'
$old = Join-Path $package 'Web.release.old'
$new = Join-Path $package 'Web.release.new'
$live = Join-Path $package 'Web'
$pending = Join-Path $package 'Web.pending'
$backup = Join-Path $package 'Web.previous'
$serviceName = 'SoknaCiHttpdE3' + [guid]::NewGuid().ToString('N').Substring(0,8)
$serviceInstalled = $false
$httpd = $null
$port = 0
New-Item -ItemType Directory -Path $install,$old,$new -Force | Out-Null
Set-Content -LiteralPath (Join-Path $fixture '.lab-only') -Value 'no-real-host-mutation' -NoNewline -Encoding Ascii
Set-Content -LiteralPath (Join-Path $old 'health.txt') -Value 'old-ci' -NoNewline -Encoding Ascii
Set-Content -LiteralPath (Join-Path $new 'health.txt') -Value 'new-ci' -NoNewline -Encoding Ascii
$oldHash = (Get-FileHash -LiteralPath (Join-Path $old 'health.txt') -Algorithm SHA256).Hash
$newHash = (Get-FileHash -LiteralPath (Join-Path $new 'health.txt') -Algorithm SHA256).Hash
$listener = [Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback,0)
$listener.Start()
$port = ([Net.IPEndPoint]$listener.LocalEndpoint).Port
$listener.Stop()
Assert (-not (Get-Service -Name $serviceName -ErrorAction SilentlyContinue)) 'SERVICE_NAME_COLLISION'
Assert (-not (Get-Service -Name 'SoknaApache' -ErrorAction SilentlyContinue)) 'REAL_SOKNA_APACHE_FOUND_ON_RUNNER'

try {
  # Version is pinned; package installation is runner-scoped, no default Apache service.
  & choco install apache-httpd --version=2.4.55 --force -y --no-progress --params "'/installLocation:$install /port:$port /noService'"
  Assert ($LASTEXITCODE -eq 0) 'CHOCOLATEY_APACHE_INSTALL_FAILED'
  $exe = @(Get-ChildItem -LiteralPath $install -Recurse -File -Filter httpd.exe | Where-Object { $_.Directory.Name -eq 'bin' } | Select-Object -First 1)
  Assert ($exe.Count -eq 1) 'APACHE_HTTPD_BINARY_MISSING'
  $httpd = $exe[0].FullName
  $apacheRoot = Split-Path -Parent (Split-Path -Parent $httpd)
  foreach ($module in @('mod_authz_core.so','mod_dir.so')) {
    Assert (Test-Path -LiteralPath (Join-Path $apacheRoot ('modules\' + $module))) ('APACHE_MODULE_MISSING_' + $module)
  }
  $conf = Join-Path $fixture 'apache-fixture.conf'
  $errorLog = Normalize (Join-Path $fixture 'apache-error.log')
  $pidFile = Normalize (Join-Path $fixture 'apache.pid')
  $serverRoot = Normalize $apacheRoot
  $webRoot = Normalize $live
  $config = @"
ServerRoot "$serverRoot"
Listen 127.0.0.1:$port
ServerName 127.0.0.1:$port
LoadModule authz_core_module modules/mod_authz_core.so
LoadModule dir_module modules/mod_dir.so
DocumentRoot "$webRoot"
<Directory "$webRoot">
  Options -Indexes
  AllowOverride None
  Require all granted
</Directory>
DirectoryIndex health.txt
ErrorLog "$errorLog"
PidFile "$pidFile"
LogLevel warn
"@
  Set-Content -LiteralPath $conf -Value $config -Encoding Ascii
  Junction $live $old
  Junction $pending $new
  & $httpd -t -f $conf
  Assert ($LASTEXITCODE -eq 0) 'APACHE_CONFIG_INVALID'
  & $httpd -k install -n $serviceName -f $conf
  Assert ($LASTEXITCODE -eq 0) 'SCM_APACHE_SERVICE_INSTALL_FAILED'
  $serviceInstalled = $true
  # Service must bind only to this disposable fixture config and ephemeral port.
  $winSvc = Get-CimInstance -ClassName Win32_Service -Filter "Name='$serviceName'"
  Assert ($null -ne $winSvc -and $winSvc.PathName.Contains($httpd)) 'SCM_SERVICE_NOT_FIXTURE_BOUND'
  Start-Service -Name $serviceName
  Assert (AwaitHttp $port 'old-ci') 'OLD_HTTP_NOT_READY'
  Stop-Service -Name $serviceName
  Assert (AwaitDown $port) 'HTTP_REMAINED_UP_AFTER_STOP'
  Assert ((Get-Service -Name $serviceName).Status -eq 'Stopped') 'SCM_STOP_NOT_OBSERVED'

  # Physical NTFS two-rename gap, explicitly do not restart the service:
  # there is no valid DocumentRoot and no evidence of HTTP health.
  [IO.Directory]::Move($live,$backup)
  Assert (-not (Test-Path -LiteralPath $live)) 'MISSING_LINK_GAP_NOT_OBSERVED'
  Assert ((HttpContent $port) -eq $null) 'HTTP_CLAIMED_HEALTH_WITH_MISSING_JUNCTION'
  [IO.Directory]::Move($pending,$live)
  Start-Service -Name $serviceName
  Assert (AwaitHttp $port 'new-ci') 'TARGET_HTTP_NOT_READY'

  # HTTP 200 with the wrong target or tampered bytes is NOT healthy.
  Set-Content -LiteralPath (Join-Path $new 'health.txt') -Value 'tampered' -NoNewline -Encoding Ascii
  Assert ((AwaitHttp $port 'new-ci' 2) -eq $false) 'TAMPERED_HTTP200_ACCEPTED'
  Set-Content -LiteralPath (Join-Path $new 'health.txt') -Value 'new-ci' -NoNewline -Encoding Ascii
  Assert (AwaitHttp $port 'new-ci') 'TARGET_HTTP_NOT_RESTORED'

  # Real OS service crash: kill the actual SCM service process (not a fake host).
  $svcProc = Get-CimInstance Win32_Service -Filter "Name='$serviceName'"
  Assert ($null -ne $svcProc -and [int]$svcProc.ProcessId -gt 0) 'SCM_SERVICE_PID_MISSING'
  Stop-Process -Id ([int]$svcProc.ProcessId) -Force
  $sw = [Diagnostics.Stopwatch]::StartNew()
  while ($sw.Elapsed.TotalSeconds -lt 15 -and (Get-Service -Name $serviceName).Status -ne 'Stopped') {
    Start-Sleep -Milliseconds 200
  }
  Assert ((Get-Service -Name $serviceName).Status -eq 'Stopped') 'SCM_CRASH_DID_NOT_REPORT_STOPPED'
  Assert (AwaitDown $port) 'CRASHED_APACHE_STILL_SERVING_HTTP'
  Start-Service -Name $serviceName
  Assert (AwaitHttp $port 'new-ci') 'SCM_CRASH_RESTART_HTTP_FAILED'
  Assert ((Get-FileHash -LiteralPath (Join-Path $old 'health.txt') -Algorithm SHA256).Hash -eq $oldHash) 'PREVIOUS_CODE_CHANGED'
  Assert ((Get-FileHash -LiteralPath (Join-Path $new 'health.txt') -Algorithm SHA256).Hash -eq $newHash) 'TARGET_CODE_CHANGED'
  [ordered]@{
    format='sokna-f1-s7e3a-windows-real-apache-scm-http-v1'
    result='PASS';apache_binary_real=$true;windows_scm_service_real=$true
    real_loopback_http=$true;stop_start_qualified=$true
    two_rename_missing_pointer_fail_closed=$true;http200_tamper_rejected=$true
    os_service_crash_and_recovery=$true;immutable_fixture_slots=$true
    binary_source='chocolatey-apache-httpd-2.4.55'
    installed_sokna_apache_touched=$false;real_host_uat=$false
    real_business_db_touched=$false;public_touched=$false
    production_cutover_authorized=$false;panel_update_ready=$false
  } | ConvertTo-Json -Compress | Write-Host
} finally {
  if ($serviceInstalled) {
    try { Stop-Service -Name $serviceName -ErrorAction SilentlyContinue } catch {}
    try { & $httpd -k uninstall -n $serviceName | Out-Null } catch {}
    if (Get-Service -Name $serviceName -ErrorAction SilentlyContinue) {
      & sc.exe delete $serviceName | Out-Null
    }
  }
  try { RemoveJunction $live } catch { Write-Warning $_ }
  try { RemoveJunction $backup } catch { Write-Warning $_ }
  try { RemoveJunction $pending } catch { Write-Warning $_ }
  # Only the dedicated randomly named RUNNER_TEMP fixture is deleted.
  if ((Get-Content -LiteralPath (Join-Path $fixture '.lab-only') -Raw -ErrorAction SilentlyContinue) -eq 'no-real-host-mutation') {
    Remove-Item -LiteralPath $fixture -Force -Recurse -ErrorAction SilentlyContinue
  }
}