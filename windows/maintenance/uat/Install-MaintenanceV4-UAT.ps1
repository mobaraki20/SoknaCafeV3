param(
    [string]$InstallRoot = (Join-Path $env:ProgramFiles 'SOKNA\MaintenanceV4-UAT'),
    [switch]$SkipStart
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$ServiceName = 'SoknaMaintenanceSupervisor'
$BundleRoot = Split-Path -Parent $PSScriptRoot
$BundleManifestPath = Join-Path $BundleRoot 'BUNDLE_MANIFEST.json'
$SourceApp = Join-Path $BundleRoot 'app'
$SourceRecovery = Join-Path $BundleRoot 'recovery'
$TargetApp = Join-Path $InstallRoot 'app'
$TargetRecovery = Join-Path $InstallRoot 'recovery'
$SupervisorExe = Join-Path $TargetApp 'SoknaMaintenanceSupervisor.exe'
$UpdateEngineExe = Join-Path $TargetApp 'SoknaMaintenanceUpdateEngine.exe'
$RecoveryExe = Join-Path $TargetRecovery 'SoknaMaintenanceRecoveryConsole.exe'
$DescriptorPath = Join-Path $env:ProgramData 'SOKNA\Maintenance\control-plane.json'
$AclEvidencePath = Join-Path $env:ProgramData 'SOKNA\Maintenance\web-adapter-acl.json'

function Assert-Administrator {
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    $principal = [Security.Principal.WindowsPrincipal]::new($identity)
    if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
        throw 'This UAT installer must run from an elevated Administrator PowerShell.'
    }
}

function Get-ServiceRecord([string]$Name) {
    Get-CimInstance Win32_Service -Filter ("Name='{0}'" -f $Name.Replace("'", "''")) -ErrorAction SilentlyContinue
}

function Normalize-ServiceExecutable([string]$PathName) {
    if ([string]::IsNullOrWhiteSpace($PathName)) { return $null }
    $text = [Environment]::ExpandEnvironmentVariables($PathName.Trim())
    if ($text.StartsWith('"')) {
        $end = $text.IndexOf('"', 1)
        if ($end -gt 1) { return [IO.Path]::GetFullPath($text.Substring(1, $end - 1)) }
    }
    $match = [regex]::Match($text, '^(.*?\.exe)(?:\s|$)', [Text.RegularExpressions.RegexOptions]::IgnoreCase)
    if ($match.Success) { return [IO.Path]::GetFullPath($match.Groups[1].Value.Trim()) }
    return $null
}

function Assert-BundleIntegrity {
    if (-not (Test-Path -LiteralPath $BundleManifestPath)) { throw 'BUNDLE_MANIFEST.json is missing.' }
    $manifest = Get-Content -Raw -LiteralPath $BundleManifestPath | ConvertFrom-Json
    if ([string]$manifest.product_source_commit -ne '61f881a0d68ab978de2e3435d37d759c4f07b4b4') {
        throw 'Bundle product source commit is not the qualified reconciled Windows checkpoint.'
    }
    foreach ($entry in @($manifest.files)) {
        $relative = [string]$entry.path
        if ([string]::IsNullOrWhiteSpace($relative) -or $relative.Contains('..')) { throw "Unsafe manifest path: $relative" }
        $candidate = Join-Path $BundleRoot ($relative -replace '/', [IO.Path]::DirectorySeparatorChar)
        if (-not (Test-Path -LiteralPath $candidate -PathType Leaf)) { throw "Bundle file is missing: $relative" }
        $actual = (Get-FileHash -LiteralPath $candidate -Algorithm SHA256).Hash.ToLowerInvariant()
        if ($actual -ne ([string]$entry.sha256).ToLowerInvariant()) { throw "Bundle hash mismatch: $relative" }
    }
}

function Wait-ServiceState([string]$Name, [string]$State, [int]$Seconds = 20) {
    $deadline = [DateTime]::UtcNow.AddSeconds($Seconds)
    do {
        $svc = Get-ServiceRecord $Name
        if ($null -eq $svc -and $State -eq 'Absent') { return }
        if ($null -ne $svc -and [string]$svc.State -eq $State) { return }
        Start-Sleep -Milliseconds 300
    } while ([DateTime]::UtcNow -lt $deadline)
    throw "Service $Name did not reach state $State within $Seconds seconds."
}

Assert-Administrator
Assert-BundleIntegrity

foreach ($required in @(
    (Join-Path $SourceApp 'SoknaMaintenanceSupervisor.exe'),
    (Join-Path $SourceApp 'SoknaMaintenanceUpdateEngine.exe'),
    (Join-Path $SourceRecovery 'SoknaMaintenanceRecoveryConsole.exe')
)) {
    if (-not (Test-Path -LiteralPath $required -PathType Leaf)) { throw "Required UAT binary missing: $required" }
}

$existing = Get-ServiceRecord $ServiceName
if ($null -ne $existing) {
    $existingExe = Normalize-ServiceExecutable ([string]$existing.PathName)
    $expectedExe = [IO.Path]::GetFullPath($SupervisorExe)
    if ([string]::IsNullOrWhiteSpace($existingExe) -or -not $existingExe.Equals($expectedExe, [StringComparison]::OrdinalIgnoreCase)) {
        throw "Existing $ServiceName points outside this UAT install root. Refusing to replace it. Existing ImagePath: $($existing.PathName)"
    }
    if ([string]$existing.State -ne 'Stopped') {
        Stop-Service -Name $ServiceName -Force -ErrorAction Stop
        Wait-ServiceState $ServiceName 'Stopped'
    }
}

New-Item -ItemType Directory -Force -Path $TargetApp, $TargetRecovery | Out-Null
Copy-Item -LiteralPath (Join-Path $SourceApp '*') -Destination $TargetApp -Recurse -Force
Copy-Item -LiteralPath (Join-Path $SourceRecovery '*') -Destination $TargetRecovery -Recurse -Force

foreach ($required in @($SupervisorExe, $UpdateEngineExe, $RecoveryExe)) {
    if (-not (Test-Path -LiteralPath $required -PathType Leaf)) { throw "Installed UAT binary missing after copy: $required" }
}

$existing = Get-ServiceRecord $ServiceName
if ($null -eq $existing) {
    New-Service -Name $ServiceName -BinaryPathName ('"{0}"' -f $SupervisorExe) -DisplayName 'SOKNA Maintenance Supervisor (UAT)' -StartupType Automatic | Out-Null
} else {
    & sc.exe config $ServiceName "binPath= `"$SupervisorExe`"" 'start= auto' 'obj= LocalSystem' | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'Unable to reconcile the existing UAT Supervisor service configuration.' }
}

& sc.exe description $ServiceName 'SOKNA Maintenance V4 UAT-only Supervisor. Not a Production release.' | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'Unable to set the UAT Supervisor service description.' }
& sc.exe failure $ServiceName 'reset= 86400' 'actions= restart/5000/restart/15000/restart/30000' | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'Unable to configure UAT Supervisor service recovery actions.' }
& sc.exe failureflag $ServiceName 1 | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'Unable to enable UAT Supervisor failure actions.' }

$receipt = [ordered]@{
    format = 'sokna-maintenance-v4-uat-install-receipt-v1'
    installed_at_utc = [DateTimeOffset]::UtcNow.ToString('O')
    product_source_commit = '61f881a0d68ab978de2e3435d37d759c4f07b4b4'
    service_name = $ServiceName
    install_root = [IO.Path]::GetFullPath($InstallRoot)
    programdata_root = (Join-Path $env:ProgramData 'SOKNA\Maintenance')
    local_public_source_mutated = $false
    production_release = $false
}
$receipt | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath (Join-Path $InstallRoot 'UAT_INSTALL_RECEIPT.json') -Encoding utf8

if (-not $SkipStart) {
    Start-Service -Name $ServiceName -ErrorAction Stop
    Wait-ServiceState $ServiceName 'Running'

    $deadline = [DateTime]::UtcNow.AddSeconds(20)
    do {
        if ((Test-Path -LiteralPath $DescriptorPath) -and (Test-Path -LiteralPath $AclEvidencePath)) { break }
        Start-Sleep -Milliseconds 300
    } while ([DateTime]::UtcNow -lt $deadline)

    if (-not (Test-Path -LiteralPath $DescriptorPath)) { throw 'Supervisor started but control-plane.json was not published.' }
    if (-not (Test-Path -LiteralPath $AclEvidencePath)) { throw 'Supervisor started but web-adapter-acl.json was not published.' }
}

$finalService = Get-ServiceRecord $ServiceName
$aclState = $null
if (Test-Path -LiteralPath $AclEvidencePath) {
    try { $aclState = [string]((Get-Content -Raw -LiteralPath $AclEvidencePath | ConvertFrom-Json).state) } catch { $aclState = 'unreadable' }
}

[ordered]@{
    result = 'PASS'
    uat_only = $true
    service = if ($null -eq $finalService) { $null } else { [ordered]@{ name=$finalService.Name; state=$finalService.State; start_mode=$finalService.StartMode; start_name=$finalService.StartName; path_name=$finalService.PathName } }
    descriptor_present = (Test-Path -LiteralPath $DescriptorPath)
    web_adapter_acl_state = $aclState
    programdata_preserved = $true
    local_public_source_mutated = $false
} | ConvertTo-Json -Depth 6
