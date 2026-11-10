param([Parameter(Mandatory)][string]$PayloadRoot)
$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest
$code='MAINTENANCE_UNIVERSAL_UNCLASSIFIED'
$stage='INIT'
$runId=[Guid]::NewGuid().ToString('N')
$started=[DateTimeOffset]::UtcNow.ToString('O')
$sourceVersion=$null; $recoveryVersion=$null; $recoverySha=$null
$completed=@(); $outcome='NO_MUTATION_ATTEMPTED'
$root=[IO.Path]::GetFullPath($PayloadRoot)
$desktop=[Environment]::GetFolderPath('DesktopDirectory')
$diagnosticRoot=Join-Path ([Environment]::GetFolderPath('CommonApplicationData')) 'SOKNA\setup\install-diagnostics'
$targetExeNames=@{
 '0326'='SOKNA-Maintenance-Only-0326-Independent-R1-QA-NOT-PRODUCTION.exe'
 '0327'='SOKNA-Maintenance-Only-0327-Independent-R1-QA-NOT-PRODUCTION.exe'
}
$targetDigests=@{
 '0326'='93981643923860e9d78bb7280be7d6d9f8e960bdbba4ffb9dea166bd59197874'
 '0327'='cd55273e84cba349cc59fc260a7457f720f3b2752876277ad4f404868e598dd5'
}
function Refuse([string]$why) { throw [IO.InvalidDataException]::new($why) }
function Fingerprint([string]$path){
 if(-not(Test-Path -LiteralPath $path -PathType Leaf)){Refuse 'MAINTENANCE_REQUIRED_BINARY_MISSING'}
 if((Get-Item -LiteralPath $path -Force).Attributes -band [IO.FileAttributes]::ReparsePoint){Refuse 'MAINTENANCE_REPARSE_FILE_BLOCKED'}
 (Get-FileHash -LiteralPath $path -Algorithm SHA256).Hash.ToLowerInvariant()
}
function ReadVersion([string]$path){
 [string]$v=[Diagnostics.FileVersionInfo]::GetVersionInfo($path).FileVersion
 if($v-notmatch '^(\d+\.\d+\.\d+)\.0$'){Refuse 'MAINTENANCE_FILE_VERSION_UNREADABLE'}
 return [string]$Matches[1]
}
function TestDir([string]$path){
 if(-not(Test-Path -LiteralPath $path -PathType Container)){Refuse 'MAINTENANCE_REQUIRED_DIRECTORY_MISSING'}
 if((Get-Item -LiteralPath $path -Force).Attributes -band [IO.FileAttributes]::ReparsePoint){Refuse 'MAINTENANCE_REPARSE_DIRECTORY_BLOCKED'}
}
try {
 $stage='VERIFY_BUNDLED_INSTALLERS'
 TestDir $root
 foreach($k in @('0326','0327')){
   $p=Join-Path $root $targetExeNames[$k]
   if((Fingerprint $p)-cne$targetDigests[$k]){Refuse ('MAINTENANCE_STAGE_'+$k+'_SHA_MISMATCH')}
 }
 $planScript=Join-Path $root 'Get-SoknaMaintenanceUpgradePlan.ps1'
 if(-not(Test-Path -LiteralPath $planScript -PathType Leaf)){Refuse 'MAINTENANCE_ROUTER_MISSING'}
 . $planScript
 $stage='DETECT_INSTALLED_MAINTENANCE'
 $svc=Get-CimInstance Win32_Service -Filter "Name='SoknaMaintenanceSupervisor'" -ErrorAction SilentlyContinue
 if($null-eq$svc){Refuse 'MAINTENANCE_SERVICE_NOT_FOUND'}
 $img=[Environment]::ExpandEnvironmentVariables(([string]$svc.PathName).Trim())
 if($img-match '^"([^"]+\.exe)"'){$supervisor=[string]$Matches[1]}
 elseif($img-match '^([^\s"]+\.exe)'){$supervisor=[string]$Matches[1]}
 else{Refuse 'MAINTENANCE_SERVICE_IMAGE_INVALID'}
 $supervisor=[IO.Path]::GetFullPath($supervisor)
 if([IO.Path]::GetFileName($supervisor)-cne'SoknaMaintenanceSupervisor.exe' -or
    [IO.Path]::GetFileName((Split-Path $supervisor -Parent))-cne'App' -or
    [IO.Path]::GetFileName((Split-Path (Split-Path $supervisor -Parent) -Parent))-cne'Maintenance'){
   Refuse 'MAINTENANCE_SERVICE_OWNER_INVALID'
 }
 $installRoot=Split-Path (Split-Path (Split-Path $supervisor -Parent) -Parent) -Parent
 foreach($d in @($installRoot,(Join-Path $installRoot 'Maintenance'),(Join-Path $installRoot 'Maintenance\App'),(Join-Path $installRoot 'Maintenance\Recovery'))){TestDir $d}
 $engine=Join-Path $installRoot 'Maintenance\App\SoknaMaintenanceUpdateEngine.exe'
 $recovery=Join-Path $installRoot 'Maintenance\Recovery\SoknaMaintenanceRecoveryConsole.exe'
 [void](Fingerprint $supervisor); [void](Fingerprint $engine)
 $recoverySha=Fingerprint $recovery
 $recoveryVersion=ReadVersion $recovery
 $v1=ReadVersion $supervisor; $v2=ReadVersion $engine
 $sourceVersion=$v1
 $plan=Get-SoknaMaintenanceUpgradePlan -SupervisorVersion $v1 -EngineVersion $v2
 $stage='EXECUTE_QUALIFIED_CHAIN'
 foreach($hop in $plan.stages) {
   $stage='EXECUTE_'+$hop
   $installer=Join-Path $root $targetExeNames[$hop]
   # The existing immutable, hash-pinned Inno installers own every mutation,
   # backup, rollback, service-health and signed-identity check.
   $p=Start-Process -FilePath $installer -ArgumentList @('/VERYSILENT','/SUPPRESSMSGBOXES','/NORESTART','/SP-') -Wait -PassThru
   if($null-eq$p -or $p.ExitCode-ne0){Refuse ('MAINTENANCE_STAGE_'+$hop+'_INSTALL_FAILED')}
   $completed+= $hop
   $wanted=if($hop-eq'0326'){'0.3.26'}else{'0.3.27'}
   if((ReadVersion $supervisor)-ne$wanted -or (ReadVersion $engine)-ne$wanted){Refuse ('MAINTENANCE_STAGE_'+$hop+'_POST_VERSION_FAILED')}
   if((Fingerprint $recovery)-cne$recoverySha){Refuse 'MAINTENANCE_RECOVERY_CHANGED'}
   $outcome='VERIFIED_INTERMEDIATE_'+$wanted.Replace('.','_')
 }
 $stage='FINAL_VERIFY'
 if((ReadVersion $supervisor)-ne'0.3.27' -or (ReadVersion $engine)-ne'0.3.27'){Refuse 'MAINTENANCE_FINAL_VERSION_FAILED'}
 if((Fingerprint $recovery)-cne$recoverySha){Refuse 'MAINTENANCE_RECOVERY_CHANGED'}
 $outcome='VERIFIED_0327';$code='MAINTENANCE_UNIVERSAL_VERIFIED'
 Write-Host ('SOKNA_UNIVERSAL_MAINTENANCE='+$code)
 exit 0
}
catch {
 $raw=[string]$_.Exception.Message
 if($raw-match '^[A-Z0-9_]{5,128}$'){$code=$raw}
 else{$code='MAINTENANCE_UNIVERSAL_PROCESS_FAILED'}
 # Never claim rollback if a complete intermediate Maintenance 0326 was installed.
 if($completed.Count-gt0){$outcome='STOPPED_AFTER_VERIFIED_STAGE'}
 try{
  New-Item -ItemType Directory -Force -Path $diagnosticRoot | Out-Null
  $logPath=Join-Path $diagnosticRoot ('maintenance-universal-'+$runId+'.log')
  [IO.File]::WriteAllText($logPath,$_.Exception.ToString(),[Text.UTF8Encoding]::new($false))
 }catch{}
 $report=[ordered]@{
  format='sokna-maintenance-universal-failure-v1'
  run_id=$runId;started_at_utc=$started
  status='BLOCKED';safe_code=$code;failed_stage=$stage
  observed_maintenance_version=$sourceVersion
  observed_recovery_version=$recoveryVersion
  observed_recovery_sha256=$recoverySha
  completed_qualified_stages=@($completed)
  transaction_outcome=$outcome
  rollback_verified=$null
  local_mutated=$false;public_mutated=$false;database_mutated=$false
  production_approved=$false
 }
 try{
  New-Item -ItemType Directory -Path $desktop -Force|Out-Null
  $path=Join-Path $desktop ('SOKNA-MAINTENANCE-UNIVERSAL-FAILED-'+$runId+'.json')
  $report|ConvertTo-Json -Depth 6|Set-Content -LiteralPath $path -Encoding UTF8
 }catch{}
 try{[IO.File]::WriteAllText((Join-Path $root 'installer-failure-code.txt'),$code,[Text.UTF8Encoding]::new($false))}catch{}
 Write-Host ('SOKNA_UNIVERSAL_MAINTENANCE='+$code)
 exit 2
}
