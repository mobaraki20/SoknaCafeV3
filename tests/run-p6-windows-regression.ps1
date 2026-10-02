$ErrorActionPreference='Stop'
$PSNativeCommandUseErrorActionPreference=$true
$root=(Resolve-Path (Join-Path $PSScriptRoot '..')).Path
Set-Location $root

function Assert-Exit([string]$label){
    if($LASTEXITCODE -ne 0){throw "$label failed with exit code $LASTEXITCODE"}
}

Write-Host '==> Runtime build + authenticated health self-test'
dotnet build windows/runtime/source/Sokna.Runtime.Service.csproj -c Release
Assert-Exit 'Runtime build'
dotnet run --project windows/runtime/source/Sokna.Runtime.Service.csproj -c Release --no-build -- --health-self-test
Assert-Exit 'Runtime health self-test'

Write-Host '==> Print Agent build + deep recovery acceptance'
Push-Location windows/print-agent/source
try {
    dotnet build Sokna.PrintWorker.slnx -c Release
    Assert-Exit 'Print Agent build'
    dotnet run --project tests/Sokna.PrintAgent.Tests/Sokna.PrintAgent.Tests.csproj -c Release
    Assert-Exit 'Print Agent unit tests'
    dotnet run --project tests/Sokna.PrintAgent.ContractAcceptance/Sokna.PrintAgent.ContractAcceptance.csproj -c Release -- --case A18 --results "$env:RUNNER_TEMP\sokna-p6-a18"
    Assert-Exit 'Print Agent A18'
    dotnet run --project tests/Sokna.PrintAgent.TransportAcceptance/Sokna.PrintAgent.TransportAcceptance.csproj -c Release -- --case A06 --results "$env:RUNNER_TEMP\sokna-p6-a06"
    Assert-Exit 'Print Agent A06'
    dotnet run --project tests/Sokna.PrintAgent.ServiceAcceptance/Sokna.PrintAgent.ServiceAcceptance.csproj -c Release -- --case A12 --results "$env:RUNNER_TEMP\sokna-p6-a12"
    Assert-Exit 'Print Agent A12'
    foreach($case in @('A25','A26','A27')){
        dotnet run --project tests/Sokna.PrintAgent.WindowsFaultAcceptance/Sokna.PrintAgent.WindowsFaultAcceptance.csproj -c Release -- --case $case --results "$env:RUNNER_TEMP\sokna-p6-$($case.ToLowerInvariant())"
        Assert-Exit "Print Agent $case"
    }
} finally { Pop-Location }

Write-Host '==> Windows Setup owners + pairing handoff'
dotnet publish packaging/windows/setup-host/Sokna.SetupHost.csproj -c Release -o packaging/windows/setup-host/publish
Assert-Exit 'Setup Host publish'
dotnet publish packaging/windows/setup-ui/Sokna.SetupUi.csproj -c Release -o packaging/windows/setup-ui/publish
Assert-Exit 'Setup UI publish'
& .\packaging\windows\setup-host\publish\SoknaSetupHost.exe --pairing-self-test
Assert-Exit 'Setup Host pairing self-test'
$setupScript=Get-Content packaging/windows/scripts/setup-windows-services.ps1 -Raw
foreach($needle in @('SOKNA_WINDOWS_SERVICES_PAIRING_B64','Clear-StalePairingFiles','Write-TransientPrivateJson')){
    if(-not $setupScript.Contains($needle)){throw "Pairing handoff script contract missing: $needle"}
}
if($setupScript.Contains("Join-Path $env:TEMP ('sokna-print-pair-")){throw 'Print pairing secret still uses general TEMP.'}
$setupUi=Get-Content packaging/windows/setup-ui/Program.cs -Raw
foreach($needle in @('_pairingBaseUrl','_pairingCode','["pairing_code"] = pairingCode','["pairing_base_url"] = string.IsNullOrWhiteSpace(pairingCode) ? "" : pairingBaseUrl','["pairing_file"] = ""')){
    if(-not $setupUi.Contains($needle)){throw "Setup UI pairing-code contract missing: $needle"}
}
foreach($legacy in @('_browsePairing','فایل Pairing')){
    if($setupUi.Contains($legacy)){throw "Legacy pairing-file UI is still productized: $legacy"}
}

Write-Host '==> PowerShell owner parse + Windows PowerShell 5.1 smoke'
Get-ChildItem platform/windows,packaging/windows -Recurse -Filter *.ps1 | ForEach-Object {
    $tokens=$null;$errors=$null
    [System.Management.Automation.Language.Parser]::ParseFile($_.FullName,[ref]$tokens,[ref]$errors)|Out-Null
    if($errors.Count){$errors|ForEach-Object{Write-Error ("$($_.Extent.File): $($_.Message)")};throw 'PowerShell parse failure'}
}
$bad=Get-ChildItem packaging/windows/scripts -Filter *.ps1 | Select-String -SimpleMatch 'IsPathFullyQualified'
if($bad){$bad|ForEach-Object{Write-Error $_};throw 'Windows PowerShell 5.1-incompatible Path.IsPathFullyQualified usage found.'}
$supportData=Join-Path $env:RUNNER_TEMP 'sokna-p6-support-data'
$supportOut=Join-Path $env:RUNNER_TEMP 'sokna-p6-support-out'
New-Item -ItemType Directory -Path $supportData,$supportOut -Force | Out-Null
$winPs="$env:SystemRoot\System32\WindowsPowerShell\v1.0\powershell.exe"
& $winPs -NoProfile -NonInteractive -ExecutionPolicy Bypass -File packaging/windows/scripts/collect-support.ps1 -DataRoot $supportData -OutputRoot $supportOut
Assert-Exit 'Windows PowerShell 5.1 support bundle smoke'
$zip=Get-ChildItem -LiteralPath $supportOut -Filter 'SOKNA-Support-*.zip' -File | Select-Object -First 1
if(-not $zip){throw 'Support bundle smoke did not create a ZIP under Windows PowerShell 5.1.'}

Write-Host '==> Qualified installer build'
choco install innosetup --no-progress -y
Assert-Exit 'Inno Setup installation'
$iscc=@(
    "${env:ProgramFiles(x86)}\Inno Setup 6\ISCC.exe",
    "$env:ProgramFiles\Inno Setup 6\ISCC.exe"
) | Where-Object {$_ -and (Test-Path -LiteralPath $_ -PathType Leaf)} | Select-Object -First 1
if(-not $iscc){throw 'Inno Setup 6 ISCC.exe was not found.'}
$out=Join-Path $root 'release\out-windows'
& release/build-windows-release.ps1 -RepoRoot $root -OutputRoot $out -IsccExe $iscc
Assert-Exit 'Qualified Windows Services release build'

Write-Host '==> Real Windows SCM lifecycle smoke'
$shell=Join-Path $out 'shell'
$install='C:\Program Files\SOKNA Windows Services P6 CI'
$data='D:\SOKNA-P6-CI\Data'
$legacyDefault=Join-Path $env:ProgramData 'SOKNA\print-worker'
New-Item -ItemType Directory -Path $legacyDefault -Force | Out-Null
Set-Content -LiteralPath (Join-Path $legacyDefault 'queue.db') -Value 'intentionally-invalid-stale-default'
$mutexName='Global\SoknaPrintAgentV6Service'
$keeper=New-Object System.Threading.Mutex($false,$mutexName)
$childScript='$m=[Threading.Mutex]::OpenExisting("Global\SoknaPrintAgentV6Service");$null=$m.WaitOne();[Environment]::Exit(0)'
$encoded=[Convert]::ToBase64String([Text.Encoding]::Unicode.GetBytes($childScript))
$child=Start-Process -FilePath $winPs -ArgumentList '-NoProfile','-NonInteractive','-EncodedCommand',$encoded -Wait -PassThru
if($child.ExitCode-ne0){throw "Failed to seed abandoned Print Agent mutex: $($child.ExitCode)"}
try {
    & packaging/windows/scripts/setup-windows-services.ps1 -Mode Install -ShellRoot $shell -InstallRoot $install -DataRoot $data -PairingFile '' -StartWhenPaired 0
    $runtime=Get-Service -Name SoknaRuntime -ErrorAction Stop
    $print=Get-Service -Name SoknaPrintWorker -ErrorAction Stop
    $runtime.Dispose();$print.Dispose()
    $expectedExe=Join-Path $install 'Runtime\SoknaRuntimeService.exe'
    $expectedCfg=Join-Path $data 'runtime\runtime-config.json'
    $actualRuntime=[string](Get-ItemProperty -LiteralPath 'HKLM:\SYSTEM\CurrentControlSet\Services\SoknaRuntime').ImagePath
    if(-not $actualRuntime.StartsWith('"')){throw "Runtime ImagePath executable is not quoted: [$actualRuntime]"}
    if(-not $actualRuntime.Contains($expectedExe)){throw "Runtime ImagePath missing executable: [$actualRuntime]"}
    if(-not $actualRuntime.Contains($expectedCfg)){throw "Runtime ImagePath missing config path: [$actualRuntime]"}
    $expectedPrintRoot=Join-Path $data 'print-worker'
    $actualPrintRoot=[string](Get-ItemProperty -LiteralPath 'HKLM:\SOFTWARE\Sokna\Local\PrintWorker' -Name DataRoot).DataRoot
    if([IO.Path]::GetFullPath($actualPrintRoot)-cne[IO.Path]::GetFullPath($expectedPrintRoot)){throw "Print Agent DataRoot mismatch: [$actualPrintRoot]"}
} finally {
    try { & packaging/windows/scripts/setup-windows-services.ps1 -Mode Uninstall -ShellRoot $shell -InstallRoot $install -DataRoot $data -PairingFile '' -StartWhenPaired 0 } catch { Write-Warning $_ }
    Remove-Item -LiteralPath $install -Recurse -Force -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath 'D:\SOKNA-P6-CI' -Recurse -Force -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath $legacyDefault -Recurse -Force -ErrorAction SilentlyContinue
    if($keeper){$keeper.Dispose()}
}

Write-Host '==> Qualified installer integrity'
$version=(Get-Content packaging/windows/WINDOWS_SERVICES_VERSION.txt -Raw).Trim()
$expected=Join-Path $out "SOKNA-Windows-Services-Setup-$version.exe"
if(-not(Test-Path -LiteralPath $expected -PathType Leaf)){throw "Qualified Windows Services installer missing: $expected"}
$index=Get-Content (Join-Path $out 'artifact-index.json') -Raw | ConvertFrom-Json
if([string]$index.windows_services.file-ne[IO.Path]::GetFileName($expected)){throw 'Artifact index installer filename drift.'}
$sha=(Get-FileHash -LiteralPath $expected -Algorithm SHA256).Hash.ToLowerInvariant()
if($sha-ne[string]$index.windows_services.sha256){throw 'Artifact index installer SHA256 drift.'}

Write-Host 'P6 Windows Integrated Regression: PASS'
