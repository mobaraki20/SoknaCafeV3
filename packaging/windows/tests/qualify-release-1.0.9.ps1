param(
    [Parameter(Mandatory=$true)][string]$ShellRoot,
    [Parameter(Mandatory=$true)][string]$DiagnosticsRoot
)
$ErrorActionPreference='Stop'

$repoRoot=(Resolve-Path (Join-Path $PSScriptRoot '..\..\..')).Path
$setupScript=Join-Path $repoRoot 'packaging\windows\scripts\setup-windows-services.ps1'
$supportScript=Join-Path $repoRoot 'packaging\windows\scripts\collect-support.ps1'
$ShellRoot=[IO.Path]::GetFullPath($ShellRoot)
$DiagnosticsRoot=[IO.Path]::GetFullPath($DiagnosticsRoot)
$testRoot=Join-Path $env:RUNNER_TEMP 'sokna-windows-services-1.0.9-ci'
$installRoot=Join-Path $env:ProgramFiles 'SOKNA Windows Services 1.0.9 CI'
$dataRoot=Join-Path $testRoot 'Data'
$supportRoot=Join-Path $testRoot 'Support'
$transcript=Join-Path $DiagnosticsRoot 'lifecycle-transcript.txt'

New-Item -ItemType Directory -Path $DiagnosticsRoot -Force | Out-Null
Set-Content -LiteralPath (Join-Path $DiagnosticsRoot 'harness-started.txt') -Value ((Get-Date).ToString('o')) -Encoding UTF8

function Write-Diagnostics {
    param([System.Management.Automation.ErrorRecord]$Failure)
    try { ($Failure | Format-List * -Force | Out-String -Width 300) | Set-Content -LiteralPath (Join-Path $DiagnosticsRoot 'exception.txt') -Encoding UTF8 } catch {}
    try { ($Failure.ScriptStackTrace | Out-String -Width 300) | Set-Content -LiteralPath (Join-Path $DiagnosticsRoot 'script-stack.txt') -Encoding UTF8 } catch {}
    try {
        Get-CimInstance Win32_Service -ErrorAction SilentlyContinue |
            Where-Object { $_.Name -in @('SoknaRuntime','SoknaPrintWorker') } |
            Select-Object Name,DisplayName,State,Status,StartMode,ProcessId,ExitCode,PathName |
            Format-List | Out-String -Width 300 |
            Set-Content -LiteralPath (Join-Path $DiagnosticsRoot 'services-after-failure.txt') -Encoding UTF8
    } catch {}
    try {
        if(Test-Path -LiteralPath $installRoot){
            Get-ChildItem -LiteralPath $installRoot -Recurse -Force -ErrorAction SilentlyContinue |
                Select-Object FullName,Length,LastWriteTimeUtc |
                Format-Table -AutoSize | Out-String -Width 300 |
                Set-Content -LiteralPath (Join-Path $DiagnosticsRoot 'install-tree-after-failure.txt') -Encoding UTF8
        }
    } catch {}
    try {
        if(Test-Path -LiteralPath $dataRoot){
            Get-ChildItem -LiteralPath $dataRoot -Recurse -Force -ErrorAction SilentlyContinue |
                Select-Object FullName,Length,LastWriteTimeUtc |
                Format-Table -AutoSize | Out-String -Width 300 |
                Set-Content -LiteralPath (Join-Path $DiagnosticsRoot 'data-tree-after-failure.txt') -Encoding UTF8
        }
    } catch {}
}

try { Start-Transcript -LiteralPath $transcript -Force | Out-Null } catch {}
try {
    if(-not(Test-Path -LiteralPath $setupScript -PathType Leaf)){throw "Setup lifecycle script missing: $setupScript"}
    if(-not(Test-Path -LiteralPath $supportScript -PathType Leaf)){throw "Support script missing: $supportScript"}
    if(-not(Test-Path -LiteralPath $ShellRoot -PathType Container)){throw "Shell payload missing: $ShellRoot"}

    Write-Host 'PHASE=install'
    & $setupScript -Mode Install -ShellRoot $ShellRoot -InstallRoot $installRoot -DataRoot $dataRoot -PairingFile '' -StartWhenPaired 0

    $print=Get-CimInstance Win32_Service -Filter "Name='SoknaPrintWorker'" -ErrorAction Stop
    if($print.State -ne 'Running' -or [uint32]$print.ProcessId -le 0){
        throw "Print Worker unhealthy after install: state=$($print.State) pid=$($print.ProcessId)"
    }
    $sqlite=Join-Path $installRoot 'PrintAgent\Service\e_sqlite3.dll'
    if(-not(Test-Path -LiteralPath $sqlite -PathType Leaf)){throw 'e_sqlite3.dll missing after install.'}

    foreach($pass in 1..2){
        Write-Host "PHASE=repair-$pass"
        $before=Get-CimInstance Win32_Service -Filter "Name='SoknaPrintWorker'" -ErrorAction Stop
        if($before.State -ne 'Running' -or [uint32]$before.ProcessId -le 0){
            throw "Print Worker must be live before repair pass ${pass}: state=$($before.State) pid=$($before.ProcessId)"
        }
        Write-Host "Pre-repair PID=$($before.ProcessId)"
        & $setupScript -Mode Repair -ShellRoot $ShellRoot -InstallRoot $installRoot -DataRoot $dataRoot -PairingFile '' -StartWhenPaired 0
        $after=Get-CimInstance Win32_Service -Filter "Name='SoknaPrintWorker'" -ErrorAction Stop
        if($after.State -ne 'Running' -or [uint32]$after.ProcessId -le 0){
            throw "Print Worker unhealthy after repair pass ${pass}: state=$($after.State) pid=$($after.ProcessId)"
        }
        if(-not(Test-Path -LiteralPath $sqlite -PathType Leaf)){throw "e_sqlite3.dll missing after repair pass $pass"}
        $incoming=@(Get-ChildItem -LiteralPath $installRoot -Directory -ErrorAction SilentlyContinue | Where-Object { $_.Name -like 'PrintAgent.__incoming.*' })
        if($incoming.Count -gt 0){throw "Print Agent staging residue after repair pass ${pass}: $($incoming.FullName -join ', ')"}
    }

    Write-Host 'PHASE=state-validation'
    $statePath=Join-Path $dataRoot 'setup\windows-services-state.json'
    $state=Get-Content -LiteralPath $statePath -Raw | ConvertFrom-Json
    if([string]$state.shutdown_proof -ne 'service-status+process-exit'){throw 'Shutdown proof marker missing from install state.'}
    if([string]$state.payload_replacement -ne 'transactional-print-agent'){throw 'Transactional payload marker missing from install state.'}

    Write-Host 'PHASE=support-bundle'
    New-Item -ItemType Directory -Path $supportRoot -Force | Out-Null
    & $supportScript -DataRoot $dataRoot -OutputRoot $supportRoot
    $zip=Get-ChildItem -LiteralPath $supportRoot -Filter 'SOKNA-Support-*.zip' -File | Sort-Object LastWriteTimeUtc -Descending | Select-Object -First 1
    if(-not $zip){throw 'Support Bundle was not created.'}
    Copy-Item -LiteralPath $zip.FullName -Destination (Join-Path $DiagnosticsRoot $zip.Name) -Force

    $extract=Join-Path $env:RUNNER_TEMP ('sokna-support-check-'+[guid]::NewGuid().ToString('N'))
    try {
        Expand-Archive -LiteralPath $zip.FullName -DestinationPath $extract -Force
        $summary=Get-Content -LiteralPath (Join-Path $extract 'summary.json') -Raw | ConvertFrom-Json
        if([string]$summary.format -ne 'sokna-windows-support-v2'){throw "Unexpected support bundle format: $($summary.format)"}
        foreach($relative in @('file-locks\restart-manager-locks.txt','file-locks\tasklist-e_sqlite3.txt','file-locks\target-files.txt')){
            if(-not(Test-Path -LiteralPath (Join-Path $extract $relative) -PathType Leaf)){throw "Support Bundle diagnostic missing: $relative"}
        }
    } finally {
        Remove-Item -LiteralPath $extract -Recurse -Force -ErrorAction SilentlyContinue
    }

    Set-Content -LiteralPath (Join-Path $DiagnosticsRoot 'qualification-success.txt') -Value 'PASS' -Encoding UTF8
    Write-Host 'WINDOWS SERVICES LIFECYCLE QUALIFICATION: PASS'
}
catch {
    Write-Diagnostics -Failure $_
    throw
}
finally {
    try { Stop-Transcript | Out-Null } catch {}
    try {
        if(Test-Path -LiteralPath $ShellRoot -PathType Container){
            & $setupScript -Mode Uninstall -ShellRoot $ShellRoot -InstallRoot $installRoot -DataRoot $dataRoot -PairingFile '' -StartWhenPaired 0
        }
    } catch {
        try { ($_ | Format-List * -Force | Out-String -Width 300) | Set-Content -LiteralPath (Join-Path $DiagnosticsRoot 'cleanup-exception.txt') -Encoding UTF8 } catch {}
    }
    Remove-Item -LiteralPath $installRoot -Recurse -Force -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath $testRoot -Recurse -Force -ErrorAction SilentlyContinue
}
