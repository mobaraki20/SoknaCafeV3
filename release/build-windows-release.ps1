param(
 [string]$RepoRoot=(Resolve-Path (Join-Path $PSScriptRoot '..')).Path,
 [string]$OutputRoot=(Join-Path $PSScriptRoot 'out-windows'),
 [string]$IsccExe=''
)
$ErrorActionPreference='Stop'
$OutputRoot=[IO.Path]::GetFullPath($OutputRoot);if(Test-Path $OutputRoot){Remove-Item $OutputRoot -Recurse -Force};New-Item -ItemType Directory -Path $OutputRoot -Force|Out-Null
$runtimeOut=Join-Path $OutputRoot 'runtime';$printOut=Join-Path $OutputRoot 'print';$extract=Join-Path $OutputRoot 'extract';$shell=Join-Path $OutputRoot 'shell'
& (Join-Path $RepoRoot 'windows\runtime\build-component-package.ps1') -RepoRoot $RepoRoot -OutputDir $runtimeOut
& (Join-Path $RepoRoot 'windows\print-agent\build-component-package.ps1') -RepoRoot $RepoRoot -OutputDir $printOut
$runtimeZip=Get-ChildItem $runtimeOut -Filter 'SoknaRuntime-*-win-x64.zip'|Select-Object -First 1
$printZip=Get-ChildItem $printOut -Filter 'SoknaPrintAgent-*-win-x64.zip'|Select-Object -First 1
if(-not$runtimeZip-or-not$printZip){throw 'Independent Runtime/Print artifacts were not produced.'}
$runtimeExtract=Join-Path $extract 'runtime';$printExtract=Join-Path $extract 'print';Expand-Archive $runtimeZip.FullName $runtimeExtract -Force;Expand-Archive $printZip.FullName $printExtract -Force
$runtimeExe=Join-Path $runtimeExtract 'SoknaRuntimeService.exe';if(-not(Test-Path $runtimeExe)){throw 'Runtime artifact is incomplete.'}
$setupHost=Join-Path $OutputRoot 'SoknaSetupHost.exe';$setupUi=Join-Path $OutputRoot 'SoknaSetupUi.exe'
& (Join-Path $RepoRoot 'packaging\windows\scripts\build-setup-host.ps1') -RepoRoot $RepoRoot -OutputPath $setupHost
& (Join-Path $RepoRoot 'packaging\windows\scripts\build-setup-ui.ps1') -RepoRoot $RepoRoot -OutputPath $setupUi
$sha=(& git -C $RepoRoot rev-parse HEAD).Trim()
& (Join-Path $RepoRoot 'packaging\windows\scripts\prepare-shell-payload.ps1') -RepoRoot $RepoRoot -OutputRoot $shell -RuntimeServiceExe $runtimeExe -SetupHostExe $setupHost -SetupUiExe $setupUi -PrintWorkerBundle $printExtract -GitSha $sha
& (Join-Path $RepoRoot 'packaging\windows\scripts\build-installer.ps1') -RepoRoot $RepoRoot -ShellPayloadRoot $shell -IsccExe $IsccExe
$installerOut=Join-Path $RepoRoot 'packaging\windows\installer\out';$setup=Get-ChildItem $installerOut -Filter 'SOKNA-Windows-Services-Setup-*.exe'|Select-Object -First 1
if(-not$setup){throw 'Windows Services installer artifact was not produced.'}
Copy-Item $setup.FullName (Join-Path $OutputRoot $setup.Name) -Force
[ordered]@{format='sokna-windows-release-index-v1';schema_version=1;source_commit=$sha;runtime=(Get-Content (Join-Path $runtimeOut 'runtime-artifact.json') -Raw|ConvertFrom-Json);print_agent=(Get-Content (Join-Path $printOut 'print-agent-artifact.json') -Raw|ConvertFrom-Json);windows_services=[ordered]@{file=$setup.Name;sha256=(Get-FileHash $setup.FullName -Algorithm SHA256).Hash.ToLowerInvariant()}}|ConvertTo-Json -Depth 8|Set-Content (Join-Path $OutputRoot 'artifact-index.json') -Encoding UTF8
Write-Host 'SOKNA Windows release artifacts: PASS'
