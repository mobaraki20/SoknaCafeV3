$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest
if($env:GITHUB_ACTIONS -ne 'true' -or $env:RUNNER_OS -ne 'Windows' -or
   $env:SOKNA_R282_CI_ONLY -ne 'YES') {throw 'R282_DISPOSABLE_WINDOWS_CI_ONLY'}
function Need([bool]$ok,[string]$reason){if(-not $ok){throw ('R282_WINDOWS_'+$reason)}}
function Run([string]$exe,[string[]]$argvNative) {
  $pushed=$false
  if([IO.Path]::GetFileName($exe) -ieq 'php.exe'){Push-Location (Split-Path -Parent $exe);$pushed=$true}
  try { & $exe @argvNative; $code=$LASTEXITCODE } finally {if($pushed){Pop-Location}}
  if($code -ne 0){throw ("R282_WINDOWS_COMMAND_EXIT_"+$code+" "+$exe)}
}
function P([string]$path){[IO.Path]::GetFullPath($path).Replace('\','/')}
foreach($service in @('SoknaApache','SoknaPrintAgent','SoknaMaintenance')) {
  Need (-not [bool](Get-Service $service -ErrorAction SilentlyContinue)) ('EXISTING_SERVICE_'+$service)
}
$package=$env:R282_PKG
Need ([bool]$package -and (Test-Path $package)) 'SOURCE_EXACT_PACKAGE_MISSING'
$proofFile=Join-Path (Split-Path -Parent $package) 'R282-PREFACTORY-PROOF.json'
Need (Test-Path $proofFile) 'ORIGINAL_PRODUCER_PROOF_MISSING'
$proof=Get-Content $proofFile -Raw|ConvertFrom-Json
Need ($proof.parent_sha256 -ceq '0326dfad22e00a95a8ce5b6907de904ddc3a85d9469774cf61977641755aca3a') 'R280_PARENT_SHA_CHANGED'
Need ($proof.managed_file_count -eq 605 -and $proof.operator_technical_installable -eq $true) 'QA_ONLY_SOURCE_PROOF_INVALID'
Need ((Get-FileHash $package -Algorithm SHA256).Hash.ToLowerInvariant() -ceq [string]$proof.sha256) 'SOURCE_ZIP_SHA_CHANGED'
$lab=Join-Path $env:RUNNER_TEMP ('sokna-r282-newmachine-'+[guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Force $lab|Out-Null
$outer=Join-Path $lab 'candidate-outer';$newWeb=Join-Path $lab 'new-machine/Web'
Expand-Archive $package $outer
$manifest=Get-Content (Join-Path $outer 'release-set.json') -Raw|ConvertFrom-Json
Need ($manifest.targets.local.version -ceq
 '3.0.0-g6.2-workspace.231fq-r282-isolated-restore-ui') 'TARGET_VERSION_WRONG'
Need (($manifest.for_install -eq $true) -and ($manifest.production_pass -eq $true)) 'R282_CONTROLLED_INSTALL_MANIFEST_INVALID'
Expand-Archive (Join-Path $outer 'artifacts/local/web.zip') $newWeb
Need ((Get-ChildItem $newWeb -Recurse -File|Measure-Object).Count -eq 605) 'REAL_SOURCE_605_FILES_MISSING'
Need ((Get-Content (Join-Path $newWeb 'VERSION.txt') -Raw).Trim() -ceq
 [string]$manifest.targets.local.version) 'VERSION_READBACK_WRONG'
python packaging/windows/release-gates/check_local_release_set.py $package
Need ($LASTEXITCODE -eq 0) 'LOCAL_SOURCE_MANIFEST_INVALID'

$phpUrl='https://downloads.php.net/~windows/releases/archives/php-8.4.24-Win32-vs17-x64.zip'
$phpZip=Join-Path $lab 'php.zip';$phpRoot=Join-Path $lab 'php'
Invoke-WebRequest $phpUrl -OutFile $phpZip
Need ((Get-FileHash $phpZip -Algorithm SHA256).Hash.ToLowerInvariant() -ceq
 '7b57fc9840273ab153834d0e2bd06e0bcf4fead36e381182b4b8fe9cedff3174') 'PINNED_PHP_TAMPER'
Expand-Archive $phpZip $phpRoot
Copy-Item (Join-Path $phpRoot 'php.ini-development') (Join-Path $phpRoot 'php.ini')
Add-Content (Join-Path $phpRoot 'php.ini') -Value ('extension_dir="'+(P (Join-Path $phpRoot 'ext'))+'"')
foreach($ext in @('pdo_mysql','sodium','mbstring','zip')) {
 Add-Content (Join-Path $phpRoot 'php.ini') -Value ('extension='+$ext)
}
$php=Join-Path $phpRoot 'php.exe'
$testScript=(Resolve-Path 'r282/tests/stage_82_local_restore.php').Path
Run $php @('-c',(Join-Path $phpRoot 'php.ini'),'-l',$testScript)

$mdbUrl='https://archive.mariadb.org/mariadb-11.4.7/winx64-packages/'
$mdbZip=Join-Path $lab 'mariadb.zip'
Invoke-WebRequest ($mdbUrl+'mariadb-11.4.7-winx64.zip') -OutFile $mdbZip
$expected=[regex]::Match(
 [string](Invoke-WebRequest ($mdbUrl+'sha256sums.txt')).Content,
 '(?im)^([0-9a-f]{64})\s+\*?(?:\./)?mariadb-11\.4\.7-winx64\.zip\s*$'
).Groups[1].Value
Need ($expected.Length -eq 64 -and
 (Get-FileHash $mdbZip -Algorithm SHA256).Hash.ToLowerInvariant() -ceq $expected) 'MARIADB_PIN_MISMATCH'
Expand-Archive $mdbZip (Join-Path $lab 'mariadb')
$mdb=(Get-ChildItem (Join-Path $lab 'mariadb') -Directory|Select-Object -First 1).FullName
$dbData=Join-Path $mdb 'data';$port=39762
Run (Join-Path $mdb 'bin/mariadb-install-db.exe') @("--datadir=$dbData","--port=$port")
$psi=[Diagnostics.ProcessStartInfo]::new((Join-Path $mdb 'bin/mariadbd.exe'))
$psi.UseShellExecute=$false;$psi.RedirectStandardOutput=$true;$psi.RedirectStandardError=$true
foreach($arg in @('--no-defaults',"--basedir=$mdb","--datadir=$dbData","--port=$port",
                   '--bind-address=127.0.0.1','--console')){$psi.ArgumentList.Add($arg)}
$db=[Diagnostics.Process]::Start($psi)
$outTask=$db.StandardOutput.ReadToEndAsync();$errTask=$db.StandardError.ReadToEndAsync()
try {
 $ready=$false
 for($i=0;$i -lt 45;$i++){
  if($db.HasExited){break}
  & (Join-Path $mdb 'bin/mariadb-admin.exe') --protocol=tcp --host=127.0.0.1 "--port=$port" --connect-timeout=1 --user=root ping 2>$null|Out-Null
  if($LASTEXITCODE -eq 0){$ready=$true;break}
  Start-Sleep -Milliseconds 400
 }
 if(-not $ready){
   if($db.HasExited){Write-Host ('R280_MARIADB_EARLY_EXIT '+$db.ExitCode)}
   throw 'R282_WINDOWS_MARIADB_NOT_READY'
 }
 $env:R280_DB_HOST='127.0.0.1'
 $env:R280_DB_PORT="$port"
 $env:R280_DB_PASSWORD=''
 $env:R280_LOCAL_SOURCE_ROOT=$newWeb
 Run $php @('-c',(Join-Path $phpRoot 'php.ini'),$testScript)
 $pairingScript=(Resolve-Path 'r280/tests/new_machine_services_pairing.php').Path
 Run $php @('-c',(Join-Path $phpRoot 'php.ini'),$pairingScript)
 foreach($service in @('SoknaApache','SoknaPrintAgent','SoknaMaintenance')){
   Need (-not [bool](Get-Service $service -ErrorAction SilentlyContinue)) ('UNEXPECTED_SERVICE_CLONED_'+$service)
 }
 Write-Host 'R282_WINDOWS_82_SCHEMA_SEPARATE_STAGE_RESTORE_AND_AGENT_PAIRING_PASS'
 Write-Host 'R282_WINDOWS_PRINTAGENT_MAINTENANCE_PHYSICAL_DELIVERY_NOT_TESTED_OPERATOR_BLOCKED'
} finally {
 if($null -ne $db -and -not $db.HasExited){$db.Kill($true);$db.WaitForExit()}
 try{$outTask.Result|Set-Content (Join-Path $lab 'mariadb-stdout.log')}catch{}
 try{$errTask.Result|Set-Content (Join-Path $lab 'mariadb-stderr.log')}catch{}
}
$global:LASTEXITCODE=0
