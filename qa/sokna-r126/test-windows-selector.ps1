$ErrorActionPreference='Stop'
$script=Join-Path $PSScriptRoot 'HOME-SELECT-READONLY.ps1'
$tokens=$null;$issues=$null
[void][System.Management.Automation.Language.Parser]::ParseFile($script,[ref]$tokens,[ref]$issues)
if($issues.Count -gt 0){throw "Powershell parse errors: $($issues[0].Message)"}
$env:ProgramData=Join-Path $env:RUNNER_TEMP 'Sokna-R126-ReadOnly-QA'
$dataRoot=Join-Path $env:ProgramData 'SOKNA\Maintenance'
New-Item -ItemType Directory -Path (Join-Path $dataRoot 'local-slots') -Force|Out-Null
# Synthetic metadata only. Do not run on an operator computer.
$idx=@{format='sokna-r126-home-adaptive-selector-v1';lanes=@();public=@{zip='stub.zip';sha256=('a'*64)}}
$idx | ConvertTo-Json -Depth 15 | Set-Content -Path (Join-Path $PSScriptRoot 'index.json') -Encoding UTF8
@{local=@{version='unknown-ci-test'}} | ConvertTo-Json -Depth 10 | Set-Content -Path (Join-Path $dataRoot 'installed-release-identity.json') -Encoding UTF8
& powershell.exe -NoProfile -ExecutionPolicy Bypass -File $script | Out-Host
if($LASTEXITCODE -ne 21){throw 'Unknown Local identity was not blocked'}
$report=Get-Content (Join-Path $PSScriptRoot 'SOKNA-HOME-R126-PREFLIGHT-RESULT.txt') -Raw
if($report -notmatch 'BASELINE_NOT_VERIFIED_OR_UNKNOWN' -or $report -notmatch 'READY_FOR_HOME_UAT_UPLOAD: NO'){throw 'Unknown identity rejection report missing'}
$base=@{version='3.0.0-ci-only';release_id='fake-r0';managed_tree_sha256=('b'*64);schema_fingerprint='test-hash';migration_head='0076_late_served_accounting';authority_epoch_policy='preserved'}
$idx=@{format='sokna-r126-home-adaptive-selector-v1';lanes=@(@{lane='legacy';baselines=@($base);zip='fake.zip';zip_sha256=('b'*64)});public=@{zip='stub.zip';sha256=('a'*64)}}
$idx|ConvertTo-Json -Depth 15|Set-Content (Join-Path $PSScriptRoot 'index.json') -Encoding UTF8
@{local=$base;maintenance_supervisor_version='0.3.21';update_engine_version='0.3.21'}|ConvertTo-Json -Depth 15|Set-Content (Join-Path $dataRoot 'installed-release-identity.json') -Encoding UTF8
@{phase='RECOVERY_REQUIRED';recovery_required=$true}|ConvertTo-Json -Depth 10|Set-Content (Join-Path $dataRoot 'local-slots\routine-state.json') -Encoding UTF8
& powershell.exe -NoProfile -ExecutionPolicy Bypass -File $script | Out-Host
if($LASTEXITCODE -ne 21){throw 'Recovery-required snapshot was not blocked'}
$report=Get-Content (Join-Path $PSScriptRoot 'SOKNA-HOME-R126-PREFLIGHT-RESULT.txt') -Raw
if($report -notmatch 'UNRESOLVED_LOCAL_ROUTINE'){throw 'Recovery-required gate missing'}
foreach($name in 'SOKNA-HOME-SELECTED-LOCAL-UAT.zip','SOKNA-HOME-SELECTED-PUBLIC-UAT.zip'){
 if(Test-Path (Join-Path $PSScriptRoot $name)){throw 'Unsafe selector output created'}
}
Write-Host 'PASS Windows PowerShell syntax; unknown baseline and recovery_required blocked without selecting packages'