param(
    [Parameter(Mandatory=$true)][string]$ShellRoot,
    [Parameter(Mandatory=$true)][string]$DiagnosticsRoot
)
$ErrorActionPreference='Stop'

$repoRoot=(Resolve-Path (Join-Path $PSScriptRoot '..\..\..')).Path
$expectedVersion=(Get-Content -LiteralPath (Join-Path $repoRoot 'packaging\windows\WINDOWS_SERVICES_VERSION.txt') -Raw).Trim()
$pairScript=Join-Path $ShellRoot 'pair-windows-services.ps1'
$setupScript=Join-Path $ShellRoot 'setup-windows-services.ps1'
$mock=Join-Path $repoRoot 'packaging\windows\tests\pairing-mock-server.py'
$ShellRoot=[IO.Path]::GetFullPath($ShellRoot)
$DiagnosticsRoot=[IO.Path]::GetFullPath($DiagnosticsRoot)
$testRoot=Join-Path $env:RUNNER_TEMP ("sokna-windows-services-pair-"+$expectedVersion+"-ci")
$installRoot=Join-Path $env:ProgramFiles 'SOKNA Windows Services Pair CI'
$dataRoot=Join-Path $testRoot 'Data'
$events=Join-Path $testRoot 'pairing-events.txt'
$plan=Join-Path $testRoot 'pair-plan.json'
$port=18091
$code='ws1_0123456789abcdef01234567_0123456789abcdef0123456789abcdef0123456789abcdef'
$server=$null

function Service-Image([string]$name){return [string](Get-ItemProperty -LiteralPath ("HKLM:\SYSTEM\CurrentControlSet\Services\"+$name) -ErrorAction Stop).ImagePath}
function Hash([string]$path){return (Get-FileHash -LiteralPath $path -Algorithm SHA256).Hash.ToLowerInvariant()}
function Capture([string]$name,[object]$value){New-Item -ItemType Directory -Path $DiagnosticsRoot -Force|Out-Null;($value|Out-String -Width 300)|Set-Content -LiteralPath (Join-Path $DiagnosticsRoot $name) -Encoding UTF8}

New-Item -ItemType Directory -Path $testRoot,$DiagnosticsRoot -Force|Out-Null
try {
    foreach($path in @($pairScript,$setupScript,$mock)){if(-not(Test-Path -LiteralPath $path -PathType Leaf)){throw "Required pairing qualification file missing: $path"}}

    & $setupScript -Mode Install -ShellRoot $ShellRoot -InstallRoot $installRoot -DataRoot $dataRoot -PairingFile '' -StartWhenPaired 0

    $runtimeExe=Join-Path $installRoot 'Runtime\SoknaRuntimeService.exe'
    $printExe=Join-Path $installRoot 'PrintAgent\Service\Sokna.PrintAgent.Service.exe'
    $workerExe=Join-Path $installRoot 'PrintAgent\Worker\Sokna.PrintAgent.Worker.exe'
    foreach($path in @($runtimeExe,$printExe,$workerExe)){if(-not(Test-Path -LiteralPath $path -PathType Leaf)){throw "Installed payload missing: $path"}}

    $before=[ordered]@{runtime_hash=Hash $runtimeExe;print_hash=Hash $printExe;worker_hash=Hash $workerExe;runtime_image=Service-Image 'SoknaRuntime';print_image=Service-Image 'SoknaPrintWorker'}
    $before|ConvertTo-Json|Set-Content -LiteralPath (Join-Path $DiagnosticsRoot 'pair-before.json') -Encoding UTF8

    $server=Start-Process -FilePath 'python' -ArgumentList @($mock,'--port',[string]$port,'--events',$events) -PassThru -WindowStyle Hidden
    $deadline=[DateTime]::UtcNow.AddSeconds(15)
    do {
        if($server.HasExited){throw 'Pairing mock server exited before test.'}
        try { $tcp=New-Object Net.Sockets.TcpClient; $tcp.Connect('127.0.0.1',$port); $tcp.Dispose(); break } catch { Start-Sleep -Milliseconds 200 }
    } while([DateTime]::UtcNow-lt$deadline)

    $planBody=[ordered]@{format='sokna-windows-services-pair-plan-v1';schema_version=1;install_root=$installRoot;data_root=$dataRoot;pairing_base_url="http://127.0.0.1:$port/";pairing_code=$code;start_when_paired=$true}
    $planBody|ConvertTo-Json -Depth 5|Set-Content -LiteralPath $plan -Encoding UTF8
    & $pairScript -PlanFile $plan

    $after=[ordered]@{runtime_hash=Hash $runtimeExe;print_hash=Hash $printExe;worker_hash=Hash $workerExe;runtime_image=Service-Image 'SoknaRuntime';print_image=Service-Image 'SoknaPrintWorker'}
    $after|ConvertTo-Json|Set-Content -LiteralPath (Join-Path $DiagnosticsRoot 'pair-after.json') -Encoding UTF8
    if($before.runtime_hash-ne$after.runtime_hash-or$before.print_hash-ne$after.print_hash-or$before.worker_hash-ne$after.worker_hash){throw 'Pairing mutated installed executable payload.'}
    if($before.runtime_image-ne$after.runtime_image-or$before.print_image-ne$after.print_image){throw 'Pairing changed Windows Service registration/image path.'}

    $statePath=Join-Path $dataRoot 'setup\windows-services-state.json'
    $state=Get-Content -LiteralPath $statePath -Raw|ConvertFrom-Json
    if(-not[bool]$state.paired){throw 'Pairing state was not recorded.'}
    if([string]$state.package_version-ne$expectedVersion){throw "Pairing state package version mismatch: $($state.package_version), expected $expectedVersion"}
    if([string]$state.local_base_url-ne"http://127.0.0.1:$port/"){throw 'Pairing state Local Web URL mismatch.'}

    $runtimeCfgPath=Join-Path $dataRoot 'runtime\runtime-config.json'
    $runtimeToken=Join-Path $dataRoot 'runtime\runtime-token.private'
    $localToken=Join-Path $dataRoot 'runtime\local-token.private'
    foreach($path in @($runtimeCfgPath,$runtimeToken,$localToken)){if(-not(Test-Path -LiteralPath $path -PathType Leaf)){throw "Pairing output missing: $path"}}
    $cfgHash=Hash $runtimeCfgPath;$runtimeTokenHash=Hash $runtimeToken;$localTokenHash=Hash $localToken

    & $setupScript -Mode Repair -ShellRoot $ShellRoot -InstallRoot $installRoot -DataRoot $dataRoot -PairingFile '' -StartWhenPaired 0
    $stateAfter=Get-Content -LiteralPath $statePath -Raw|ConvertFrom-Json
    if(-not[bool]$stateAfter.paired){throw 'Repair cleared pairing state.'}
    if([string]$stateAfter.package_version-ne$expectedVersion){throw 'Repair lost current package version.'}
    if((Hash $runtimeCfgPath)-ne$cfgHash-or(Hash $runtimeToken)-ne$runtimeTokenHash-or(Hash $localToken)-ne$localTokenHash){throw 'Repair changed preserved pairing configuration or tokens.'}

    $runtime=Get-CimInstance Win32_Service -Filter "Name='SoknaRuntime'" -ErrorAction Stop
    $print=Get-CimInstance Win32_Service -Filter "Name='SoknaPrintWorker'" -ErrorAction Stop
    if($runtime.State-ne'Running'-or$print.State-ne'Running'){throw "Services not running after paired repair: runtime=$($runtime.State) print=$($print.State)"}

    Start-Sleep -Milliseconds 300
    $actions=@(Get-Content -LiteralPath $events -ErrorAction SilentlyContinue)
    if($actions.Count-ne2-or$actions[0]-ne'exchange'-or$actions[1]-ne'confirm'){throw "Unexpected Local Web pairing actions: $($actions -join ', ')"}
    Capture 'pairing-actions.txt' ($actions -join [Environment]::NewLine)
    Set-Content -LiteralPath (Join-Path $DiagnosticsRoot 'pairing-success.txt') -Value 'PASS' -Encoding UTF8
    Write-Host "WINDOWS SERVICES $expectedVersion PAIRING + PRESERVATION QUALIFICATION: PASS"
}
catch {
    Capture 'pairing-exception.txt' ($_|Format-List * -Force)
    throw
}
finally {
    if($server-and-not$server.HasExited){try{Stop-Process -Id $server.Id -Force -ErrorAction SilentlyContinue}catch{}}
    try { & $setupScript -Mode Uninstall -ShellRoot $ShellRoot -InstallRoot $installRoot -DataRoot $dataRoot -PairingFile '' -StartWhenPaired 0 } catch {}
    Remove-Item -LiteralPath $installRoot -Recurse -Force -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath $testRoot -Recurse -Force -ErrorAction SilentlyContinue
}
