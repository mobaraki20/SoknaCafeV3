$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest
if($env:GITHUB_ACTIONS-ne'true' -or $env:RUNNER_OS-ne'Windows'){throw 'DISPOSABLE_WINDOWS_ONLY'}
$repo=Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
$wrapper=Join-Path $repo 'packaging/windows/scripts/invoke-maintenance-install-logged-v1.ps1'
$lab=Join-Path $env:RUNNER_TEMP ('logged-wrapper-'+[Guid]::NewGuid().ToString('N'))
$cases=@(
 @{name='specific';code='MAINTENANCE_INSTALLED_IDENTITY_BASELINE_MISMATCH';body="Write-Output 'SOKNA_INSTALLER_STAGE=INSTALLED_IDENTITY';Write-Output 'SOKNA_INSTALLER_FAILURE=MAINTENANCE_INSTALLED_IDENTITY_BASELINE_MISMATCH';Write-Error 'CI_PRIVATE_SECRET';exit 1";outcome='NOT_VERIFIED'},
 @{name='native';code='MAINTENANCE_INSTALLER_UNCLASSIFIED_FAILURE';body="throw 'CI_PRIVATE_SECRET'";outcome='NOT_VERIFIED'},
 @{name='rollback';code='R125_COMPONENT_TRANSACTION_FAILED';body="Write-Output 'SOKNA_INSTALLER_FAILURE=R125_COMPONENT_TRANSACTION_FAILED';Write-Output 'MAINTENANCE_0326_INSTALL_ROLLED_BACK';exit 65";outcome='SCRIPT_REPORTED_ROLLBACK'},
 @{name='recovery';code='R125_COMPONENT_TRANSACTION_FAILED';body="Write-Output 'SOKNA_INSTALLER_FAILURE=R125_COMPONENT_TRANSACTION_FAILED';Write-Output 'MAINTENANCE_0326_RECOVERY_REQUIRED';exit 66";outcome='RECOVERY_REQUIRED'},
 @{name='success';code='';body='exit 0';outcome=''}
)
foreach($case in $cases){
 $root=Join-Path $lab $case.name;$payload=Join-Path $root 'payload with spaces';$desktop=Join-Path $root 'desktop';$logs=Join-Path $root 'private'
 New-Item -ItemType Directory -Force $payload|Out-Null
 Set-Content (Join-Path $payload 'invoke-maintenance-components-v4.ps1') ('param([string]$PayloadRoot)'+[Environment]::NewLine+$case.body)
 & "$env:WINDIR/System32/WindowsPowerShell/v1.0/powershell.exe" -NoProfile -NonInteractive -ExecutionPolicy Bypass -File $wrapper -PayloadRoot $payload -DiagnosticRoot $logs -DesktopRoot $desktop
 $code=$LASTEXITCODE
 if($case.name-eq'success'){
  if($code-ne0 -or (Test-Path $desktop)){throw 'SUCCESS_WRONGLY_REPORTED_AS_FAILURE'}
 }else{
  if($code-ne2){throw 'FAILURE_EXIT_NOT_PRESERVED'}
  $reports=@(Get-ChildItem $desktop -Filter '*.json');if($reports.Count-ne1){throw 'EXACTLY_ONE_FAILURE_REPORT_REQUIRED'}
  $raw=Get-Content $reports[0].FullName -Raw;$report=$raw|ConvertFrom-Json
  if($report.safe_code-ne$case.code -or $report.transaction_outcome-ne$case.outcome -or $null-ne$report.rollback_verified -or $report.recovery_requested -or $report.production_approved){throw 'FAILURE_REPORT_UNTRUE'}
  if($raw.Contains('CI_PRIVATE_SECRET') -or $raw.Contains($root)){throw 'PRIVATE_CONTENT_LEAKED_TO_DESKTOP'}
  if(-not$report.private_diagnostic_log_written){throw 'PRIVATE_LOG_MISSING'}
 }
 Write-Host ('PASS logged wrapper '+$case.name)
}
# Exercise the actual failure function under Windows PowerShell 5.1. A wrong
# unqualified exception type must not replace the intended safe reason.
$script=Join-Path $repo 'packaging/windows/scripts/invoke-maintenance-components-v4.ps1'
$tokens=$null;$errors=$null
$ast=[Management.Automation.Language.Parser]::ParseFile($script,[ref]$tokens,[ref]$errors)
if($errors.Count-ne0){throw 'COMPONENT_SCRIPT_SYNTAX'}
$fn=$ast.Find({param($n) $n-is[Management.Automation.Language.FunctionDefinitionAst] -and $n.Name-eq'Fail'},$true)
. ([scriptblock]::Create($fn.Extent.Text))
$caught=$false
try{Fail 'R125_REQUIRED_FILE_MISSING'}catch{
 if($_.Exception-isnot[IO.InvalidDataException] -or $_.Exception.Message-ne'R125_REQUIRED_FILE_MISSING'){throw 'FAILURE_TYPE_MASKS_ORIGINAL_REASON'}
 $caught=$true
}
if(-not$caught){throw 'FAILURE_NOT_THROWN'}
Write-Host 'PASS actual qualified failure type and unchanged safe reason'
