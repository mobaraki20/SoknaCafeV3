$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest
if($env:GITHUB_ACTIONS-ne'true'-or$env:RUNNER_OS-ne'Windows'){throw 'DISPOSABLE_WINDOWS_CI_ONLY'}
if(Get-Service SoknaApache -ErrorAction SilentlyContinue){throw 'REFUSE_EXISTING_SOKNA_APACHE'}
function Need([bool]$ok,[string]$why){if(-not$ok){throw $why}}
function P([string]$path){[IO.Path]::GetFullPath($path).Replace('\','/')}
function Run([string]$exe,[string[]]$arguments){$pushed=$false;if([IO.Path]::GetFileName($exe)-ieq'php.exe'){$dir=Split-Path -Parent $exe;$ini=Join-Path $dir 'php.ini';if(Test-Path -LiteralPath $ini -PathType Leaf){$arguments=@('-c','php.ini')+$arguments;Push-Location -LiteralPath $dir;$pushed=$true}};try{& $exe @arguments;$code=$LASTEXITCODE}finally{if($pushed){Pop-Location}};if($code-ne0){throw "Command failed ($code): $exe"}}
$repo=$PWD.Path
$lab=Join-Path $env:RUNNER_TEMP 'sokna-u01-dependencies'
New-Item -ItemType Directory -Force $lab|Out-Null
$mdbZip=Join-Path $lab 'mariadb.zip'
$mdbUrl='https://archive.mariadb.org/mariadb-11.4.7/winx64-packages/'
Invoke-WebRequest ($mdbUrl+'mariadb-11.4.7-winx64.zip') -OutFile $mdbZip
$sums=(Invoke-WebRequest ($mdbUrl+'sha256sums.txt')).Content
$expected=[regex]::Match([string]$sums,'(?im)^([0-9a-f]{64})\s+\*?(?:\./)?mariadb-11\.4\.7-winx64\.zip\s*$').Groups[1].Value
Need ($expected.Length-eq64-and(Get-FileHash $mdbZip).Hash-eq$expected) 'MARIADB_DOWNLOAD_SHA256'
Expand-Archive $mdbZip (Join-Path $lab 'mdb')
$mdb=(Get-ChildItem (Join-Path $lab 'mdb') -Directory|Select-Object -First 1).FullName
$dbData=Join-Path $lab 'db';$dbPort=39733
Run (Join-Path $mdb 'bin/mariadb-install-db.exe') @("--datadir=$dbData","--port=$dbPort")
$psi=[Diagnostics.ProcessStartInfo]::new((Join-Path $mdb 'bin/mariadbd.exe'))
$psi.UseShellExecute=$false;$psi.RedirectStandardOutput=$true;$psi.RedirectStandardError=$true
foreach($a in @('--no-defaults',"--basedir=$mdb","--datadir=$dbData","--port=$dbPort",'--bind-address=127.0.0.1','--console')){$psi.ArgumentList.Add($a)}
$db=[Diagnostics.Process]::Start($psi);$dbOut=$db.StandardOutput.ReadToEndAsync();$dbErr=$db.StandardError.ReadToEndAsync()
$phpExe=(Get-Command php.exe).Source;$phpRoot=Split-Path -Parent $phpExe
Need (Test-Path (Join-Path $phpRoot 'php8apache2_4.dll')) 'THREAD_SAFE_PHP_APACHE_MODULE_MISSING'
$apacheCandidates=@('C:\tools\Apache24','C:\tools\apache','C:\ProgramData\chocolatey\lib\apache-httpd')
$apacheExe=$null
foreach($a in $apacheCandidates){if(Test-Path $a){$apacheExe=Get-ChildItem $a -Recurse -Filter httpd.exe -File|Select-Object -First 1;if($apacheExe){break}}}
if(-not$apacheExe){
  choco install apache-httpd --version=2.4.55 -y --no-progress --params "'/installLocation:$lab\httpd /noService'"
  Need ($LASTEXITCODE-eq0) 'APACHE_INSTALL_FAILED'
  $apacheExe=Get-ChildItem (Join-Path $lab 'httpd') -Recurse -Filter httpd.exe -File|Select-Object -First 1
}
Need ([bool]$apacheExe) 'APACHE_BINARY_MISSING'
$apacheSource=Split-Path -Parent (Split-Path -Parent $apacheExe.FullName)
$build=Join-Path $lab 'build'
Run dotnet @('publish','windows/maintenance/tests/Sokna.Maintenance.U01Harness/Sokna.Maintenance.U01Harness.csproj','-c','Release','-r','win-x64','--self-contained','false','-o',"$build/harness")
Run dotnet @('publish','windows/maintenance/src/Sokna.Maintenance.UpdateEngine/Sokna.Maintenance.UpdateEngine.csproj','-c','Release','-r','win-x64','--self-contained','false','-o',"$build/engine")
$harness=Join-Path $build 'harness/Sokna.Maintenance.U01Harness.exe';$engine=Join-Path $build 'engine/SoknaMaintenanceUpdateEngine.exe'
$package=Join-Path $env:RUNNER_TEMP 'u01-local/SOKNA-Local-231bf-U01-Panel-Update.zip'
$target=Join-Path $env:RUNNER_TEMP 'u01-local/target.json'
$baseline=Join-Path $repo 'release/local-u01/m06-baseline.json'
$roots=@('D:\سامانه کافه آزمون U01','C:\SoknaU01-CI','C:\Sokna U01 CI With Spaces','D:\SoknaU01-CI')
$faults=@('none','APACHE_STOPPED','DATABASE_BACKUP_VERIFIED','DATABASE_FORWARD_INTENT','POINTER_SWITCHED','COMMITTED')
$results=@()
try {
  for($attempt=0;$attempt-lt60;$attempt++){
    & (Join-Path $mdb 'bin/mariadb-admin.exe') --protocol=tcp --host=127.0.0.1 "--port=$dbPort" --user=root ping 2>$null|Out-Null
    if($LASTEXITCODE-eq0){break};Start-Sleep -Milliseconds 300
  }
  Need ($LASTEXITCODE-eq0) 'MARIADB_NOT_READY'
  foreach($base in $roots){foreach($fault in $faults){
    $root=Join-Path $base ([guid]::NewGuid().ToString('N'))
    New-Item -ItemType Directory -Force $root|Out-Null
    Set-Content (Join-Path $root '.u01-ci-only') -Value 'disposable' -Encoding Ascii
    try {
      $old=Join-Path $root 'Web.release.m06';Expand-Archive (Join-Path $repo 'release/local-u01/m06-baseline.zip') $old
      $infra=Join-Path $root 'Infrastructure';New-Item -ItemType Directory -Force $infra|Out-Null
      Copy-Item $phpRoot (Join-Path $infra 'PHP') -Recurse
      New-Item -ItemType Directory -Force (Join-Path $infra 'MariaDB')|Out-Null
      Copy-Item (Join-Path $mdb 'bin') (Join-Path $infra 'MariaDB/bin') -Recurse
      Copy-Item $apacheSource (Join-Path $infra 'Apache') -Recurse
      $localPhp=Join-Path $infra 'PHP/php.exe'
      Run $localPhp @('-r','if(!in_array("mysql",PDO::getAvailableDrivers(),true))exit(74);')
      Run $localPhp @((Join-Path $repo 'windows/maintenance/tests/u01-fixture.php'),$root,$old,"$dbPort",'init')
      $configHash=(Get-FileHash (Join-Path $root 'config.php')).Hash;$lockHash=(Get-FileHash (Join-Path $root 'install.lock')).Hash
      New-Item -ItemType Junction -Path (Join-Path $root 'Web') -Target $old|Out-Null
      $apache=Join-Path $infra 'Apache';$httpd=Join-Path $apache 'bin/httpd.exe';$conf=Join-Path $apache 'conf/httpd.conf'
      $ar=P $apache;$pr=P (Join-Path $infra 'PHP');$doc=P (Join-Path $root 'Web/public');$log=P (Join-Path $root 'apache-error.log')
      @"
ServerRoot "$ar"
Listen 127.0.0.1:39831
ServerName 127.0.0.1:39831
LoadModule authz_core_module modules/mod_authz_core.so
LoadModule dir_module modules/mod_dir.so
LoadModule mime_module modules/mod_mime.so
LoadModule php_module "$pr/php8apache2_4.dll"
PHPIniDir "$pr"
TypesConfig conf/mime.types
AddType application/x-httpd-php .php
DocumentRoot "$doc"
<Directory "$doc">
  Require all granted
  AllowOverride None
</Directory>
ErrorLog "$log"
PidFile "$ar/logs/httpd.pid"
"@ | Set-Content $conf -Encoding utf8NoBOM
      Run $httpd @('-t','-f',$conf)
      Run $httpd @('-k','install','-n','SoknaApache','-f',$conf)
      Start-Service SoknaApache
      $common=@($root,$baseline,$target,$engine,$fault,$package)
      Run $harness (@('prepare')+$common)
      if($fault-eq'none') {Run $harness (@('panel-activate')+$common)}
      else {
        & $harness @(@('activate')+$common)
        Need ($LASTEXITCODE-eq97) "FAULT_NOT_REACHED_$fault"
        Run $harness (@('recover')+$common)
      }
      $state=Get-Content (Join-Path $root 'Maintenance/local-slots/routine-state.json') -Raw|ConvertFrom-Json
      $forward=$fault-in@('none','DATABASE_FORWARD_INTENT','POINTER_SWITCHED','COMMITTED')
      Need ($state.phase-eq$(if($forward){'COMMITTED'}else{'RECOVERED_TO_PREVIOUS_SLOT'})) "WRONG_TERMINAL_PHASE_$fault"
      Need ($state.database_mutation_performed-eq$forward) "FALSE_DATABASE_FLAG_$fault"
      Need (-not$state.recovery_required) "UNRESOLVED_RECOVERY_$fault"
      Run $localPhp @((Join-Path $repo 'windows/maintenance/tests/u01-fixture.php'),$root,(Join-Path $root 'Web'),"$dbPort",'verify')
      Need ((Get-FileHash (Join-Path $root 'config.php')).Hash-eq$configHash) 'CONFIG_CHANGED'
      Need ((Get-FileHash (Join-Path $root 'install.lock')).Hash-eq$lockHash) 'INSTALL_IDENTITY_CHANGED'
      $results+=@{root=$base;fault=$fault;phase=$state.phase;database_mutation=$state.database_mutation_performed;status='PASS'}
      Write-Host "U01_CYCLE_PASS $base / $fault"
    } finally {
      $routine=Join-Path $root 'Maintenance/local-slots/routine-state.json'
      if(Test-Path -LiteralPath $routine){Write-Host 'U01_CI_ROUTINE_STATE';Get-Content -LiteralPath $routine -Raw|Write-Host}
      if(Get-Service SoknaApache -ErrorAction SilentlyContinue){Stop-Service SoknaApache -ErrorAction SilentlyContinue;& sc.exe delete SoknaApache|Out-Null;Start-Sleep -Milliseconds 500}
      if(Test-Path (Join-Path $root 'Web')){[IO.Directory]::Delete((Join-Path $root 'Web'))}
      if(Test-Path $root){Remove-Item $root -Recurse -Force}
    }
  }}
  Need ($results.Count-eq24) 'REQUIRE_24_COMPLETED_CYCLES'
  $results|ConvertTo-Json -Depth 8|Set-Content (Join-Path $env:RUNNER_TEMP 'U01-WINDOWS-CYCLES.json') -Encoding utf8NoBOM
  Write-Host 'U01_WINDOWS_REAL_APACHE_PHP_MARIADB_24_CYCLES_PASS'
  # Run the actual Home bridge PHP+Go result parsers on Windows against this
  # disposable MariaDB, then build the two Win10-compatible entrypoints.
  Run python @('release/build-home-u01.py','--panel-directory',"$env:RUNNER_TEMP/u01-local")
  $homeOut=Join-Path $env:RUNNER_TEMP 'u01-home';New-Item -ItemType Directory -Force $homeOut|Out-Null
  $env:SOKNA_U01_TEST_PHP=$phpExe;$env:SOKNA_U01_TEST_DB_PORT=[string]$dbPort
  Push-Location (Join-Path $repo 'windows/maintenance/tools/HomeBootstrap')
  try {
    & go test -json -count=1 ./... | Tee-Object (Join-Path $homeOut 'HOME-WINDOWS-TESTS.jsonl')
    Need ($LASTEXITCODE-eq0) 'HOME_WINDOWS_MARIADB_BRIDGE_TESTS_FAILED'
    Run go @('vet','./...')
    Run go @('build','-buildvcs=false','-trimpath','-ldflags=-H=windowsgui -s -w -X main.allowMutationBuild=false','-o',"$homeOut/SOKNA-Home-231au-to-231bf-PREFLIGHT.exe",'.')
    Run go @('build','-buildvcs=false','-trimpath','-ldflags=-H=windowsgui -s -w -X main.allowMutationBuild=true','-o',"$homeOut/SOKNA-Home-231au-to-231bf-UPDATE-UAT.exe",'.')
    Get-ChildItem $homeOut -Filter *.exe | ForEach-Object { "$((Get-FileHash $_.FullName -Algorithm SHA256).Hash.ToLowerInvariant())  $($_.Name)" } | Set-Content (Join-Path $homeOut 'SHA256SUMS-HOME.txt') -Encoding ascii
  } finally { Pop-Location }
} finally {
  if(-not$db.HasExited){$db.Kill($true);$db.WaitForExit()}
  $dbOut.Result|Set-Content (Join-Path $lab 'mariadb-stdout.log');$dbErr.Result|Set-Content (Join-Path $lab 'mariadb-stderr.log')
}
