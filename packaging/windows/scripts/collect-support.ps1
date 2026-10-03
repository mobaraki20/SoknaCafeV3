param(
    [string]$OutputPath = '',
    [string]$AppRoot = '',
    [string]$DataRoot = "$env:ProgramData\SOKNA",
    [int]$RecentHours = 72
)
$ErrorActionPreference = 'Stop'
$scriptRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$supportModule = Join-Path $scriptRoot 'setup-support.psm1'
if (-not (Test-Path -LiteralPath $supportModule -PathType Leaf)) { throw 'فایل پشتیبان تشخیص سکنا در دسترس نیست.' }
Import-Module $supportModule -DisableNameChecking -Force

if ([string]::IsNullOrWhiteSpace($OutputPath)) {
    $desktop = [Environment]::GetFolderPath('Desktop')
    if ([string]::IsNullOrWhiteSpace($desktop)) { $desktop = $env:TEMP }
    $OutputPath = Join-Path $desktop ('SOKNA-Support-' + (Get-Date -Format 'yyyyMMdd-HHmmss') + '.zip')
}
$OutputPath = [IO.Path]::GetFullPath($OutputPath)
Assert-SoknaSafePath $OutputPath

$stage = Join-Path $env:TEMP ('SOKNA-support-' + [guid]::NewGuid().ToString('N'))
New-SoknaPrivateDirectory $stage | Out-Null
try {
    $manifest = [ordered]@{
        schema_version = 1
        captured_utc = [DateTime]::UtcNow.ToString('o')
        source = 'sokna-windows-support-collector-v1'
        setup_session_found = $false
        installer_logs = @()
    }

    $cutoff = (Get-Date).AddHours(-[Math]::Abs($RecentHours))
    $session = Get-ChildItem -LiteralPath $env:TEMP -Directory -Filter 'SOKNA-setup-*' -ErrorAction SilentlyContinue |
        Where-Object { $_.LastWriteTime -ge $cutoff } |
        Sort-Object LastWriteTime -Descending | Select-Object -First 1
    if ($session) {
        $manifest.setup_session_found = $true
        foreach ($name in @('summary.json','events.jsonl','components.json')) {
            $src = Join-Path $session.FullName $name
            if (Test-Path -LiteralPath $src -PathType Leaf) {
                Copy-SoknaSanitizedDiagnosticLog -Source $src -Destination (Join-Path $stage $name) -MaxBytes 2097152 | Out-Null
            }
        }
    }

    $logIndex = 0
    $logs = Get-ChildItem -LiteralPath $env:TEMP -File -Filter 'SOKNA-Cafe-Setup*.log' -ErrorAction SilentlyContinue |
        Where-Object { $_.LastWriteTime -ge $cutoff } |
        Sort-Object LastWriteTime -Descending | Select-Object -First 4
    foreach ($log in @($logs)) {
        $logIndex++
        $name = ('installer-{0:D2}.log' -f $logIndex)
        if (Copy-SoknaSanitizedDiagnosticLog -Source $log.FullName -Destination (Join-Path $stage $name) -MaxBytes 4194304) {
            $manifest.installer_logs += $name
        }
    }

    $runtimeService = Get-SoknaServiceDiagnostic 'SoknaRuntime'
    $printService = Get-SoknaServiceDiagnostic 'SoknaPrintWorker'
    $appVersion = ''
    if (-not [string]::IsNullOrWhiteSpace($AppRoot)) {
        $AppRoot = [IO.Path]::GetFullPath($AppRoot)
        Assert-SoknaSafePath $AppRoot
        $versionFile = Join-Path $AppRoot 'VERSION.txt'
        if (Test-Path -LiteralPath $versionFile -PathType Leaf) { $appVersion = (Get-Content -LiteralPath $versionFile -Raw).Trim() }
    }
    $manifest.runtime_service = $runtimeService
    $manifest.print_worker_service = $printService
    $manifest.app_version = Protect-SoknaLog $appVersion
    $manifest.print_worker_health = Get-SoknaPrintHealthDiagnostic $DataRoot
    $manifest | ConvertTo-Json -Depth 10 | Set-Content -LiteralPath (Join-Path $stage 'collector.json') -Encoding UTF8

    $files = @(Get-ChildItem -LiteralPath $stage -File | ForEach-Object { $_.FullName })
    if ($files.Count -lt 1) { throw 'هیچ داده تشخیصی امنی برای بسته پشتیبانی ساخته نشد.' }
    if (Test-Path -LiteralPath $OutputPath) { Remove-Item -LiteralPath $OutputPath -Force }
    Compress-Archive -LiteralPath $files -DestinationPath $OutputPath -Force
    Write-Host ('بسته پشتیبانی سکنا ساخته شد: ' + $OutputPath)
} finally {
    Remove-Item -LiteralPath $stage -Recurse -Force -ErrorAction SilentlyContinue
}
