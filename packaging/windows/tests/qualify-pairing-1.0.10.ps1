param(
    [Parameter(Mandatory=$true)][string]$ShellRoot,
    [Parameter(Mandatory=$true)][string]$DiagnosticsRoot
)
$ErrorActionPreference='Stop'

$repoRoot=(Resolve-Path (Join-Path $PSScriptRoot '..\..\..')).Path
$pairScript=Join-Path $ShellRoot 'pair-windows-services.ps1'
$setupScript=Join-Path $ShellRoot 'setup-windows-services.ps1'
$mock=Join-Path $repoRoot 'packaging\windows\tests\pairing-mock-server.py'
$ShellRoot=[IO.Path]::GetFullPath($ShellRoot)
$DiagnosticsRoot=[IO.Path]::GetFullPath($DiagnosticsRoot)
$testRoot=Join-Path $env:RUNNER_TEMP 'sokna-windows-services-pair-1.0.10-ci'
$installRoot=Join-Path $env:ProgramFiles 'SOKNA Windows Services Pair CI'
$dataRoot=Join-Path $testRoot 'Data'
$events=Join-Path $testRoot 'pairing-events.txt'
$plan=Join-Path $testRoot 'pair-plan.json'
$port=18091
$code='ws1_0123456789abcdef01234567_0123456789abcdef0123456789abcdef0123456789abcdef'
$server=$null

function Service-Image([string]$name){
    return [string](Get-ItemProperty -LiteralPath ("HKLM:\SYSTEM\CurrentControlSet\Services\"+$name) -ErrorAction Stop).ImagePath
}
function Hash([string]$path){return (Get-FileHash -LiteralPath $path -Algorithm SHA256).Hash.ToLowerInvariant()}
function Capture-Diagnostics([string]$name,[object]$value){
    New-Item -ItemType Directory -Path $DiagnosticsRoot -Force|Out-Null
    ($value|Out-String -Width 300)|Set-Content -LiteralPath (Join-Path $DiagnosticsRoot $name) -Encoding UTF8
}

New-Item -ItemType Directory -Path $testRoot,$DiagnosticsRoot -Force|Out-Null
try {
    if(-not(Test-Path -LiteralPath $pairScript -PathType Leaf)){throw 'Packaged pairing script missing.'}
    if(-not(Test-Path -LiteralPath $setupScript -PathType Leaf)){throw 'Packaged setup entrypoint missing.'}
    if(-not(Test-Path -LiteralPath $mock -PathType Leaf)){throw 'Pairing mock server missing.'}

    Write-Host 'PAIR PHASE=install-unpaired'
    & $setupScript -Mode Install -ShellRoot $ShellRoot -InstallRoot $installRoot -DataRoot $dataRoot -PairingFile '' -StartWhenPaired 0

    $runtimeExe=Join-Path $installRoot 'Runtime\SoknaRuntimeService.exe'
    $printExe=Join-Path $installRoot 'PrintAgent\Service\Sokna.PrintAgent.Service.exe'
    $workerExe=Join-Path $installRoot 'PrintAgent\Worker\Sokna.PrintAgent.Worker.exe'
    foreach($path in @($runtimeExe,$printExe,$workerExe)){if(-not(Test-Path -LiteralPath $path -PathType Leaf)){throw "Installed payload missing: $path"}}

    $before=[ordered]@{
        runtime_hash=Hash $runtimeExe
        print_hash=Hash $printExe
        worker_hash=Hash $workerExe
        runtime_image=Service-Image 'SoknaRuntime'
        print_image=Service-Image 'SoknaPrintWorker'
    }
    $before|ConvertTo-Json|Set-Content -LiteralPath (Join-Path $DiagnosticsRoot 'pair-before.json') -Encoding UTF8

    Write-Host 'PAIR PHASE=start-local-web-mock'
    $server=Start-Process -FilePath 'python' -ArgumentList @($mock,'--port',[string]$port,'--events',$events) -PassThru -WindowStyle Hidden
    $deadline=[DateTime]::UtcNow.AddSeconds(15)
    do {
        try {
            $probe=Invoke-WebRequest -Uri ("http://127.0.0.1:$port/") -UseBasicParsing -TimeoutSec 1 -ErrorAction Stop
        } catch {
            if($_.Exception.Response){break}
        }
        Start-Sleep -Milliseconds 200
    } while([DateTime]::UtcNow-lt$deadline)
    if($server.HasExited){throw 'Pairing mock server exited before test.'}

    $planBody=[ordered]@{
        format='sokna-windows-services-pair-plan-v1'
        schema_version=1
        install_root=$installRoot
        data_root=$dataRoot
        pairing_base_url="http://127.0.0.1:$port/"
        pairing_code=$code
        start_when_paired=$true
    }
    $planBody|ConvertTo-Json -Depth 5|Set-Content -LiteralPath $plan -Encoding UTF8

    Write-Host 'PAIR PHASE=pair-without-reinstall'
    & $pairScript -PlanFile $plan

    $after=[ordered]@{
        runtime_hash=Hash $runtimeExe
        print_hash=Hash $printExe
        worker_hash=Hash $workerExe
        runtime_image=Service-Image 'SoknaRuntime'
        print_image=Service-Image 'SoknaPrintWorker'
    }
    $after|ConvertTo-Json|Set-Content -LiteralPath (Join-Path $DiagnosticsRoot 'pair-after.json') -Encoding UTF8

    if($before.runtime_hash-ne$after.runtime_hash-or$before.print_hash-ne$after.print_hash-or$before.worker_hash-ne$after.worker_hash){throw 'Pairing mutated installed executable payload.'}
    if($before.runtime_image-ne$after.runtime_image-or$before.print_image-ne$after.print_image){throw 'Pairing changed Windows Service registration/image path.'}
    $residue=@(Get-ChildItem -LiteralPath $installRoot -Directory -ErrorAction SilentlyContinue|Where-Object{$_.Name-like'PrintAgent.__*'})
    if($residue.Count-gt0){throw "Pairing left payload replacement residue: $($residue.FullName -join ', ')"}

    $runtime=Get-CimInstance Win32_Service -Filter "Name='SoknaRuntime'" -ErrorAction Stop
    $print=Get-CimInstance Win32_Service -Filter "Name='SoknaPrintWorker'" -ErrorAction Stop
    if($runtime.State-ne'Running'-or[uint32]$runtime.ProcessId-le0){throw "Runtime not running after pair: state=$($runtime.State) pid=$($runtime.ProcessId)"}
    if($print.State-ne'Running'-or[uint32]$print.ProcessId-le0){throw "Print Worker not running after pair: state=$($print.State) pid=$($print.ProcessId)"}

    $statePath=Join-Path $dataRoot 'setup\windows-services-state.json'
    $state=Get-Content -LiteralPath $statePath -Raw|ConvertFrom-Json
    if(-not[bool]$state.paired){throw 'Pairing state was not recorded.'}
    if([string]$state.pairing_lifecycle-ne'independent-v1'){throw 'Independent pairing lifecycle marker missing.'}
    if([string]$state.package_version-ne'1.0.10'){throw "Pairing state package version mismatch: $($state.package_version)"}
    if([string]$state.local_base_url-ne"http://127.0.0.1:$port/"){throw 'Pairing state Local Web URL mismatch.'}

    $runtimeCfg=Get-Content -LiteralPath (Join-Path $dataRoot 'runtime\runtime-config.json') -Raw|ConvertFrom-Json
    if([string]$runtimeCfg.localBaseUrl-ne"http://127.0.0.1:$port/"){throw 'Runtime pairing configuration mismatch.'}
    foreach($secret in @('runtime\runtime-token.private','runtime\local-token.private')){
        $path=Join-Path $dataRoot $secret
        if(-not(Test-Path -LiteralPath $path -PathType Leaf)){throw "Pairing secret missing: $secret"}
    }

    Start-Sleep -Milliseconds 300
    $actions=@(Get-Content -LiteralPath $events -ErrorAction SilentlyContinue)
    if($actions.Count-lt2-or$actions[0]-ne'exchange'-or$actions[1]-ne'confirm'){throw "Unexpected pairing server actions: $($actions -join ', ')"}
    Capture-Diagnostics 'pairing-actions.txt' ($actions -join [Environment]::NewLine)
    Set-Content -LiteralPath (Join-Path $DiagnosticsRoot 'pairing-success.txt') -Value 'PASS' -Encoding UTF8
    Write-Host 'WINDOWS SERVICES INDEPENDENT PAIRING QUALIFICATION: PASS'
}
catch {
    Capture-Diagnostics 'pairing-exception.txt' ($_|Format-List * -Force)
    try {
        Get-CimInstance Win32_Service -ErrorAction SilentlyContinue|Where-Object{$_.Name-in@('SoknaRuntime','SoknaPrintWorker')}|Select-Object Name,State,StartMode,ProcessId,PathName|Format-List|Out-String -Width 300|Set-Content -LiteralPath (Join-Path $DiagnosticsRoot 'pairing-services-on-failure.txt') -Encoding UTF8
    } catch {}
    throw
}
finally {
    if($server-and-not$server.HasExited){try{Stop-Process -Id $server.Id -Force -ErrorAction SilentlyContinue}catch{}}
    try { & $setupScript -Mode Uninstall -ShellRoot $ShellRoot -InstallRoot $installRoot -DataRoot $dataRoot -PairingFile '' -StartWhenPaired 0 } catch {}
    Remove-Item -LiteralPath $installRoot -Recurse -Force -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath $testRoot -Recurse -Force -ErrorAction SilentlyContinue
}
