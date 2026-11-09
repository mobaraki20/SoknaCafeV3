param([Parameter(Mandatory=$true)][string]$PayloadRoot,[string]$DiagnosticRoot='',[string]$DesktopRoot='')
$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest
$runId=[Guid]::NewGuid().ToString('N')
$started=[DateTimeOffset]::UtcNow
$exitCode=1;$stage='START';$safeCode='MAINTENANCE_INSTALLER_UNCLASSIFIED_FAILURE';$outcome='NOT_VERIFIED'
$ci=($env:GITHUB_ACTIONS-eq'true' -and $env:RUNNER_OS-eq'Windows')
if($DiagnosticRoot-ne'' -or $DesktopRoot-ne''){
 if(-not$ci){throw 'DIAGNOSTIC_OVERRIDE_ONLY_DISPOSABLE_CI'}
 $temp=([IO.Path]::GetFullPath($env:RUNNER_TEMP)).TrimEnd('\')+'\'
 foreach($p in @($DiagnosticRoot,$DesktopRoot)){
  if($p-eq'' -or -not([IO.Path]::GetFullPath($p).StartsWith($temp,[StringComparison]::OrdinalIgnoreCase))){throw 'DIAGNOSTIC_OVERRIDE_OUTSIDE_CI_TEMP'}
 }
}else{
 $DiagnosticRoot=Join-Path ([Environment]::GetFolderPath('CommonApplicationData')) 'SOKNA/setup/install-diagnostics'
 $DesktopRoot=[Environment]::GetFolderPath('DesktopDirectory')
}
$raw='';$privateLogWritten=$false;$privateReady=$false
try{
 $stage='DIAGNOSTIC_STORAGE'
 # Refuse redirected ancestors before placing a private log. Never publish raw
 # PowerShell error text (which can contain paths) into the Desktop report.
 for($dir=[IO.Path]::GetFullPath($DiagnosticRoot);$dir;$dir=Split-Path $dir -Parent){
  if((Test-Path -LiteralPath $dir) -and ((Get-Item -LiteralPath $dir -Force).Attributes-band[IO.FileAttributes]::ReparsePoint)){throw 'DIAGNOSTIC_ROOT_REDIRECTED'}
 }
 New-Item -ItemType Directory -Path $DiagnosticRoot -Force|Out-Null
 & "$env:WINDIR/System32/icacls.exe" $DiagnosticRoot /inheritance:r /grant:r '*S-1-5-18:(OI)(CI)F' '*S-1-5-32-544:(OI)(CI)F'|Out-Null
 if($LASTEXITCODE-ne0){throw 'DIAGNOSTIC_ACL_FAILED'}
 $privateReady=$true
 $stage='COMPONENT_SCRIPT'
 $PayloadRoot=[IO.Path]::GetFullPath($PayloadRoot)
 $script=Join-Path $PayloadRoot 'invoke-maintenance-components-v4.ps1'
 if(-not(Test-Path -LiteralPath $script -PathType Leaf)){throw 'COMPONENT_SCRIPT_MISSING'}
 if($PayloadRoot.Contains('"')){throw 'PAYLOAD_PATH_INVALID'}
 $info=[Diagnostics.ProcessStartInfo]::new()
 $info.FileName=Join-Path $env:WINDIR 'System32/WindowsPowerShell/v1.0/powershell.exe'
 $info.Arguments='-NoProfile -NonInteractive -ExecutionPolicy Bypass -File "'+$script+'" -PayloadRoot "'+$PayloadRoot+'"'
 $info.UseShellExecute=$false;$info.CreateNoWindow=$true
 $info.RedirectStandardOutput=$true;$info.RedirectStandardError=$true
 $process=[Diagnostics.Process]::Start($info)
 $stdout=$process.StandardOutput.ReadToEndAsync();$stderr=$process.StandardError.ReadToEndAsync()
 $process.WaitForExit();$exitCode=$process.ExitCode
 $raw=$stdout.Result+[Environment]::NewLine+$stderr.Result
 $process.Dispose()
 $private=Join-Path $DiagnosticRoot ('maintenance-install-'+$runId+'.log')
 [IO.File]::WriteAllText($private,$raw,[Text.UTF8Encoding]::new($false));$privateLogWritten=$true
 if($exitCode-eq0){Write-Host 'MAINTENANCE_COMPONENT_INSTALL_VERIFIED';exit 0}
 $codes=[regex]::Matches($raw,'(?m)^SOKNA_INSTALLER_FAILURE=([A-Z0-9_]{1,128})\r?$')
 if($codes.Count-gt0){$safeCode=$codes[$codes.Count-1].Groups[1].Value}
 $stages=[regex]::Matches($raw,'(?m)^SOKNA_INSTALLER_STAGE=([A-Z0-9_]{1,80})\r?$')
 if($stages.Count-gt0){$stage=$stages[$stages.Count-1].Groups[1].Value}
 $failedStages=[regex]::Matches($raw,'(?m)^SOKNA_INSTALLER_FAILURE_STAGE=([A-Z0-9_]{1,80})\r?$')
 if($failedStages.Count-gt0){$stage=$failedStages[$failedStages.Count-1].Groups[1].Value}
 if($raw.Contains('MAINTENANCE_0326_RECOVERY_REQUIRED')){$outcome='RECOVERY_REQUIRED'}
 elseif($raw.Contains('MAINTENANCE_0326_INSTALL_ROLLED_BACK')){$outcome='SCRIPT_REPORTED_ROLLBACK'}
}catch{
 if($stage-eq'DIAGNOSTIC_STORAGE'){$safeCode='MAINTENANCE_DIAGNOSTIC_STORAGE_UNAVAILABLE'}
 elseif($stage-eq'COMPONENT_SCRIPT'){$safeCode='MAINTENANCE_COMPONENT_PROCESS_UNAVAILABLE'}
 # Detailed exception stays private where possible; never copy it to Desktop.
 if($privateReady){try{[IO.File]::WriteAllText((Join-Path $DiagnosticRoot ('maintenance-install-'+$runId+'.log')),$raw+[Environment]::NewLine+$_.Exception.ToString(),[Text.UTF8Encoding]::new($false));$privateLogWritten=$true}catch{}}
}
$observedVersions=$null
$observed=[regex]::Matches($raw,'(?m)^SOKNA_INSTALLER_OBSERVED_VERSIONS=(\d{1,4}\.\d{1,4}\.\d{1,4}),(\d{1,4}\.\d{1,4}\.\d{1,4}),(\d{1,4}\.\d{1,4}\.\d{1,4})\r?$')
if($observed.Count-gt0){$last=$observed[$observed.Count-1];$observedVersions=@{supervisor=$last.Groups[1].Value;engine=$last.Groups[2].Value;recovery=$last.Groups[3].Value;source='physical_pe'}}
$report=[ordered]@{format='sokna-maintenance-component-install-failure-v1';run_id=$runId;started_at_utc=$started.ToString('O');status='BLOCKED';safe_code=$safeCode;failed_stage=$stage;component_exit_code=$exitCode;transaction_outcome=$outcome;rollback_verified=$null;private_diagnostic_log_written=$privateLogWritten;observed_versions=$observedVersions;recovery_requested=$false;database_schema_data_mutated=$false;public_mutated=$false;production_approved=$false}
New-Item -ItemType Directory -Path $DesktopRoot -Force|Out-Null
$reportPath=Join-Path $DesktopRoot ('SOKNA-MAINTENANCE-INSTALL-FAILED-'+$runId+'.json')
$bytes=[Text.UTF8Encoding]::new($false).GetBytes(($report|ConvertTo-Json -Depth 5))
$file=[IO.FileStream]::new($reportPath,[IO.FileMode]::CreateNew,[IO.FileAccess]::Write,[IO.FileShare]::None,4096,[IO.FileOptions]::WriteThrough)
try{$file.Write($bytes,0,$bytes.Length);$file.Flush($true)}finally{$file.Dispose()}
[IO.File]::WriteAllText((Join-Path $PayloadRoot 'installer-failure-code.txt'),$safeCode,[Text.UTF8Encoding]::new($false))
Write-Host ('SOKNA_INSTALLER_FAILURE='+$safeCode)
if(-not$ci){try{Start-Process explorer.exe -ArgumentList ('/select,"'+$reportPath+'"')}catch{}}
exit 2
