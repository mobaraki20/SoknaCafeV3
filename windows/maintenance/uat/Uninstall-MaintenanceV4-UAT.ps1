param(
    [string]$InstallRoot = (Join-Path $env:ProgramFiles 'SOKNA\MaintenanceV4-UAT')
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$ServiceName = 'SoknaMaintenanceSupervisor'
$ExpectedSupervisor = [IO.Path]::GetFullPath((Join-Path $InstallRoot 'app\SoknaMaintenanceSupervisor.exe'))
$MaintenanceDataRoot = Join-Path $env:ProgramData 'SOKNA\Maintenance'

function Assert-Administrator {
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    $principal = [Security.Principal.WindowsPrincipal]::new($identity)
    if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
        throw 'This UAT uninstaller must run from an elevated Administrator PowerShell.'
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

function Wait-Absent([string]$Name, [int]$Seconds = 20) {
    $deadline = [DateTime]::UtcNow.AddSeconds($Seconds)
    do {
        if ($null -eq (Get-ServiceRecord $Name)) { return }
        Start-Sleep -Milliseconds 300
    } while ([DateTime]::UtcNow -lt $deadline)
    throw "Service $Name still exists after delete request."
}

Assert-Administrator

$svc = Get-ServiceRecord $ServiceName
if ($null -ne $svc) {
    $actualExe = Normalize-ServiceExecutable ([string]$svc.PathName)
    if ([string]::IsNullOrWhiteSpace($actualExe) -or -not $actualExe.Equals($ExpectedSupervisor, [StringComparison]::OrdinalIgnoreCase)) {
        throw "Refusing to uninstall $ServiceName because its ImagePath is not owned by this UAT root. Current ImagePath: $($svc.PathName)"
    }

    if ([string]$svc.State -ne 'Stopped') {
        Stop-Service -Name $ServiceName -Force -ErrorAction Stop
        $deadline = [DateTime]::UtcNow.AddSeconds(20)
        do {
            Start-Sleep -Milliseconds 250
            $svc = Get-ServiceRecord $ServiceName
        } while ($null -ne $svc -and [string]$svc.State -ne 'Stopped' -and [DateTime]::UtcNow -lt $deadline)
        if ($null -ne $svc -and [string]$svc.State -ne 'Stopped') { throw 'Supervisor service did not stop.' }
    }

    & sc.exe delete $ServiceName | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'Unable to delete the UAT Supervisor service.' }
    Wait-Absent $ServiceName
}

if (Test-Path -LiteralPath $InstallRoot) {
    Remove-Item -LiteralPath $InstallRoot -Recurse -Force
}

[ordered]@{
    result = 'PASS'
    uat_only = $true
    service_removed = ($null -eq (Get-ServiceRecord $ServiceName))
    install_root_removed = (-not (Test-Path -LiteralPath $InstallRoot))
    maintenance_programdata_preserved = (Test-Path -LiteralPath $MaintenanceDataRoot)
    programdata_note = 'Maintenance ProgramData state/secrets are intentionally preserved by uninstall for evidence/recovery continuity.'
    local_public_source_mutated = $false
} | ConvertTo-Json -Depth 5
