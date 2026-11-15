[CmdletBinding()]
param(
  [ValidateSet('Probe','Apply')][string]$Action = 'Probe',
  [Parameter(Mandatory=$true)][string]$PackageZip,
  [string]$OutputDir = "$env:TEMP\SOKNA-PrintAgent-QA-Reports",
  [string]$FixtureJson,
  [switch]$AllowMutation
)
$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
$TargetVersion = [version]'6.2.12'
$ExpectedZipSha = '464eb276b446ffe6e30bdf05e36a4ef08671324ac0784f830f241d142d19efb5'
$ServiceName = 'SoknaPrintWorker'
$TargetPrefix = 'SOKNA Windows Services'
$RunId = [guid]::NewGuid().ToString('N')
$Report = [ordered]@{ format='sokna-printagent-universal-qa-v1'; transaction_id=$RunId; timestamp_utc=[DateTime]::UtcNow.ToString('o'); action=$Action; target_version='6.2.12'; status='BLOCKED'; reason='NOT_STARTED'; source_version=$null; service_name=$ServiceName; install_mode=$null; rollback='not_needed'; programdata_mutated=$false; maintenance_mutated=$false; runtime_mutated=$false; local_mutated=$false; public_mutated=$false }
function Fail([string]$Code) { throw $Code }
function Canon([string]$Path) { return [IO.Path]::GetFullPath($Path).TrimEnd('\') }
function Assert-NoReparse([string]$Path) {
  $p = Canon $Path
  while (Test-Path -LiteralPath $p) {
    $i = Get-Item -LiteralPath $p -Force
    if (($i.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { Fail 'REPARSE_POINT_BLOCKED' }
    $parent = Split-Path $p -Parent
    if ([string]::IsNullOrWhiteSpace($parent) -or $parent -eq $p) { break }
    $p = $parent
  }
}
function Validate-Payload([string]$Dir) {
  $manifestPath = Join-Path $Dir 'component-manifest.json'
  if (!(Test-Path -LiteralPath $manifestPath -PathType Leaf)) { Fail 'TARGET_MANIFEST_MISSING' }
  $m = Get-Content -LiteralPath $manifestPath -Raw | ConvertFrom-Json
  if ($m.format -ne 'sokna-print-agent-component-v1' -or $m.component -ne 'print-agent') { Fail 'TARGET_MANIFEST_FORMAT_INVALID' }
  $v = $null
  if (-not [version]::TryParse([string]$m.version, [ref]$v)) { Fail 'TARGET_VERSION_INVALID' }
  $seen = @{}
  $files = @($m.files)
  if ($files.Count -lt 2 -or $files.Count -gt 500) { Fail 'TARGET_MANIFEST_COUNT_INVALID' }
  foreach ($entry in $files) {
    $rel = [string]$entry.path
    if ($rel -notmatch '^(Service|Worker)/[A-Za-z0-9._/-]+$' -or $rel.Contains('..') -or $rel.StartsWith('/') -or $rel.Contains('//')) { Fail 'MANIFEST_UNSAFE_FILE_PATH' }
    $key = $rel.ToLowerInvariant()
    if ($seen.ContainsKey($key)) { Fail 'MANIFEST_DUPLICATE_PATH' }
    $seen[$key] = $true
    $p = Canon (Join-Path $Dir ($rel.Replace('/','\')))
    if (!$p.StartsWith(((Canon $Dir)+'\'),[StringComparison]::OrdinalIgnoreCase)) { Fail 'MANIFEST_OUTSIDE_ROOT' }
    if (!(Test-Path -LiteralPath $p -PathType Leaf)) { Fail 'MANIFEST_MISSING_FILE' }
    Assert-NoReparse $p
    if ([long](Get-Item -LiteralPath $p).Length -ne [long]$entry.size) { Fail 'MANIFEST_SIZE_MISMATCH' }
    if ((Get-FileHash -LiteralPath $p -Algorithm SHA256).Hash.ToLowerInvariant() -ne [string]$entry.sha256) { Fail 'MANIFEST_HASH_MISMATCH' }
  }
  foreach ($name in @('Service/Sokna.PrintAgent.Service.exe','Worker/Sokna.PrintAgent.Worker.exe')) {
    if (!$seen.ContainsKey($name.ToLowerInvariant())) { Fail 'MANIFEST_REQUIRED_EXE_MISSING' }
  }
  # Never silently discard unknown files during directory replacement.
  $actual = @(Get-ChildItem -LiteralPath $Dir -File -Recurse -Force | ForEach-Object { $_.FullName.Substring((Canon $Dir).Length+1).Replace('\','/').ToLowerInvariant() })
  foreach ($rel in $actual) { if ($rel -ne 'component-manifest.json' -and !$seen.ContainsKey($rel)) { Fail 'UNMANAGED_COMPONENT_FILE_BLOCKED' } }
  return $m
}
function Get-ServiceSnapshot {
  if ($FixtureJson) {
    if ($Action -ne 'Probe') { Fail 'FIXTURE_CANNOT_MUTATE' }
    return (Get-Content -LiteralPath $FixtureJson -Raw | ConvertFrom-Json)
  }
  $all = @(Get-CimInstance Win32_Service -Filter "Name='$ServiceName'" -ErrorAction SilentlyContinue)
  if ($all.Count -gt 1) { Fail 'MULTIPLE_PRINT_SERVICES_BLOCKED' }
  if ($all.Count -eq 0) { return $null }
  return $all[0]
}
function Service-Exe([string]$CmdLine) {
  $x = [Environment]::ExpandEnvironmentVariables($CmdLine.Trim())
  if ($x -match '^"([^"]+\.exe)"(?:\s|$)') { return $Matches[1] }
  if ($x -match '^([^\s"]+\.exe)(?:\s|$)') { return $Matches[1] }
  Fail 'SERVICE_IMAGE_PARSE_BLOCKED'
}
function Safe-Write-Report {
  try {
    # Reports go outside the component payload; never touch durable print queue/config/secrets.
    New-Item -ItemType Directory -Path $OutputDir -Force | Out-Null
    $name = 'SOKNA-PrintAgent-Universal-'+$RunId+'.json'
    $Report | ConvertTo-Json -Depth 8 | Set-Content -LiteralPath (Join-Path $OutputDir $name) -Encoding UTF8
    Write-Host ('REPORT='+ (Join-Path $OutputDir $name))
  } catch { Write-Warning ('REPORT_WRITE_FAILED: '+$_.Exception.Message) }
}
$failed = $false; $root = $null
try {
  if (!(Test-Path -LiteralPath $PackageZip -PathType Leaf)) { Fail 'PAYLOAD_ZIP_MISSING' }
  if ((Get-FileHash -LiteralPath $PackageZip -Algorithm SHA256).Hash.ToLowerInvariant() -ne $ExpectedZipSha) { Fail 'PAYLOAD_ZIP_IDENTITY_MISMATCH' }
  $snap = Get-ServiceSnapshot
  $parent = Canon (Join-Path $env:ProgramFiles $TargetPrefix)
  $root = Canon (Join-Path $parent 'PrintAgent')
  Assert-NoReparse $parent
  Assert-NoReparse $root
  if ($null -eq $snap) {
    if (Test-Path -LiteralPath $root) { Fail 'ORPHAN_COMPONENT_ROOT_BLOCKED' }
    $Report.install_mode = 'clean_install'
  } else {
    $exe = Canon (Service-Exe ([string]$snap.PathName))
    $expectedExe = Canon (Join-Path $root 'Service\Sokna.PrintAgent.Service.exe')
    if ($exe -ne $expectedExe) { Fail 'FOREIGN_OR_UNRECOGNIZED_SERVICE_OWNER' }
    if ([string]$snap.StartName -ne 'LocalSystem') { Fail 'UNSUPPORTED_SERVICE_ACCOUNT' }
    if (!(Test-Path -LiteralPath $root -PathType Container)) { Fail 'INSTALLED_ROOT_MISSING' }
    $current = Validate-Payload $root
    $Report.source_version = [string]$current.version
    $sourceVersion = $null
    if (-not [version]::TryParse([string]$current.version,[ref]$sourceVersion)) { Fail 'INSTALLED_VERSION_INVALID' }
    if ($sourceVersion -gt $TargetVersion) { Fail 'DOWNGRADE_BLOCKED' }
    $Report.install_mode = $(if ($sourceVersion -eq $TargetVersion) { 'verify_or_repair' } else { 'upgrade' })
    $wasRunning = ([string]$snap.State -eq 'Running')
  }
  $temp = Join-Path ([IO.Path]::GetTempPath()) ('sokna-print-agent-'+$RunId)
  New-Item -ItemType Directory -Path $temp -Force | Out-Null
  try {
    Expand-Archive -LiteralPath $PackageZip -DestinationPath $temp -Force
    $target = Validate-Payload $temp
    if ([version]$target.version -ne $TargetVersion) { Fail 'PAYLOAD_VERSION_MISMATCH' }
  } finally { Remove-Item -LiteralPath $temp -Force -Recurse -ErrorAction SilentlyContinue }
  $Report.status = 'READY_FOR_GATED_APPLY'; $Report.reason='PREFLIGHT_PASS'
  if ($Action -eq 'Probe') { Write-Host 'PASS_UNIVERSAL_NONMUTATING_PREFLIGHT'; return }
  if (-not $AllowMutation) { Fail 'MUTATION_NOT_AUTHORIZED' }
  $admin = [Security.Principal.WindowsPrincipal] [Security.Principal.WindowsIdentity]::GetCurrent()
  if (-not $admin.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) { Fail 'ADMIN_REQUIRED' }
  # This package is QA-only pending real Windows lifecycle qualification. Deliberately
  # refuses live mutation even with AllowMutation so it cannot be used on the cafe.
  Fail 'QA_HOLD_REAL_WINDOWS_UPGRADE_AND_OWNER_COMPATIBILITY_NOT_QUALIFIED'
} catch {
  $failed = $true; $Report.status='BLOCKED'; $Report.reason=$_.Exception.Message
  Write-Error ('SOKNA_PRINT_AGENT_'+$Report.reason) -ErrorAction Continue
} finally { Safe-Write-Report }

if ($failed) { exit 1 }