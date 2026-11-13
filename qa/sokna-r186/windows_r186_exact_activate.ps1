$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest
if($env:GITHUB_ACTIONS -ne 'true' -or $env:RUNNER_OS -ne 'Windows' -or $env:SOKNA_R186_CI_ONLY -ne 'YES') { throw 'R186_REAL_WINDOWS_DISPOSABLE_CI_ONLY' }
if(Get-Service SoknaApache -ErrorAction SilentlyContinue){throw 'R186_REFUSE_EXISTING_SOKNA_APACHE'}
function Need([bool]$ok,[string]$code){if(-not $ok){throw ('R186_'+$code)}}
function Run([string]$exe,[string[]]$a){
 $pushed=$false
 if([IO.Path]::GetFileName($exe) -ieq 'php.exe') { Push-Location (Split-Path -Parent $exe); $pushed=$true }
 try { & $exe @a; $c=$LASTEXITCODE } finally { if($pushed){Pop-Location} }
 if($c -ne 0){throw "R186_COMMAND_EXIT_$c $exe"}
}
function P([string]$s){[IO.Path]::GetFullPath($s).Replace('\','/')}
$lab=Join-Path $env:RUNNER_TEMP ('sokna-r186-exact-'+[guid]::NewGuid().ToString('N'))
$root=Join-Path $lab 'local-machine'
$old=Join-Path $root 'Web.release.r153'
$oldPackage=(Get-ChildItem $env:R186_R153_DIR -Recurse -Filter 'SOKNA-R153-LOCAL-231ED-44BASE-CONTRACT-COMPLETE-QA-NOT-INSTALL.zip'|Select-Object -First 1).FullName
$newPackage=(Get-ChildItem $env:R186_R184_DIR -Recurse -Filter 'SOKNA-R184-CI-QUARANTINED-STAGE-PROBE-NOT-FOR-INSTALL.zip'|Select-Object -First 1).FullName
Need ([bool]$oldPackage -and [bool]$newPackage) 'EXACT_ARCHIVES_MISSING'
Need ((Get-FileHash $oldPackage -Algorithm SHA256).Hash.ToLowerInvariant() -eq '4f98e7eb948e78c49b09f4f41299ec7b4c68b562cfac8dbe72e0c827a69ef613') 'OLD_ARCHIVE_HASH'
Need ((Get-FileHash $newPackage -Algorithm SHA256).Hash.ToLowerInvariant() -eq 'da884efc86b3801d11d3842411d6189d916ff2736451aefe3801dafbea7a59ec') 'TARGET_ARCHIVE_HASH'
New-Item -ItemType Directory -Force $root,$old|Out-Null
Set-Content (Join-Path $root '.u01-ci-only') 'disposable-r186' -Encoding ascii
$oldOuter=Join-Path $lab 'old-outer';$newOuter=Join-Path $lab 'new-outer'
Expand-Archive $oldPackage $oldOuter
Expand-Archive (Join-Path $oldOuter 'artifacts/local/web.zip') $old
Expand-Archive $newPackage $newOuter
$m=Get-Content (Join-Path $newOuter 'release-set.json') -Raw|ConvertFrom-Json
$homeBaseline=@($m.supported_baselines|Where-Object {$_.local.release_id -eq 'sokna-r153-local-44base-home-canary-20261111'})
Need ($homeBaseline.Count -eq 1 -and $homeBaseline[0].local.managed_files -eq 583) 'EXACT_HOME_BASELINE_AMBIGUOUS'
$baseline=Join-Path $lab 'baseline.json';$target=Join-Path $lab 'target.json'
$homeBaseline[0].local|ConvertTo-Json -Depth 7|Set-Content $baseline -Encoding utf8NoBOM
$m.targets.local|ConvertTo-Json -Depth 7|Set-Content $target -Encoding utf8NoBOM
Need ((Get-Content (Join-Path $old 'VERSION.txt') -Raw).Trim() -ceq $homeBaseline[0].local.version) 'OLD_SOURCE_VERSION_WRONG'
$phpUrl='https://downloads.php.net/~windows/releases/archives/php-8.4.24-Win32-vs17-x64.zip'
$phpZip=Join-Path $lab 'php-ts.zip';$phpRoot=Join-Path $lab 'php-ts'
Invoke-WebRequest $phpUrl -OutFile $phpZip
Need ((Get-FileHash $phpZip -Algorithm SHA256).Hash.ToLowerInvariant() -eq '7b57fc9840273ab153834d0e2bd06e0bcf4fead36e381182b4b8fe9cedff3174') 'PINNED_PHP_SHA_MISMATCH'
Expand-Archive $phpZip $phpRoot
Copy-Item (Join-Path $phpRoot 'php.ini-development') (Join-Path $phpRoot 'php.ini')
Add-Content (Join-Path $phpRoot 'php.ini') -Value ('extension_dir="'+(P (Join-Path $phpRoot 'ext'))+'"')
foreach($e in @('pdo_mysql','mbstring','zip')){Add-Content (Join-Path $phpRoot 'php.ini') -Value ('extension='+$e)}
$mdbUrl='https://archive.mariadb.org/mariadb-11.4.7/winx64-packages/'
$mdbZip=Join-Path $lab 'mariadb.zip';Invoke-WebRequest ($mdbUrl+'mariadb-11.4.7-winx64.zip') -OutFile $mdbZip
$sums=(Invoke-WebRequest ($mdbUrl+'sha256sums.txt')).Content
$expected=[regex]::Match([string]$sums,'(?im)^([0-9a-f]{64})\s+\*?(?:\./)?mariadb-11\.4\.7-winx64\.zip\s*$').Groups[1].Value
Need ($expected.Length -eq 64 -and (Get-FileHash $mdbZip -Algorithm SHA256).Hash.ToLowerInvariant() -eq $expected) 'MARIADB_SHA_INVALID'
Expand-Archive $mdbZip (Join-Path $lab 'mdb')
$mdb=(Get-ChildItem (Join-Path $lab 'mdb') -Directory | Select-Object -First 1).FullName
$dbData=Join-Path $lab 'mdb-data';$dbPort=39733
Run (Join-Path $mdb 'bin/mariadb-install-db.exe') @("--datadir=$dbData","--port=$dbPort")
$psi=[Diagnostics.ProcessStartInfo]::new((Join-Path $mdb 'bin/mariadbd.exe'))
$psi.UseShellExecute=$false;$psi.RedirectStandardOutput=$true;$psi.RedirectStandardError=$true
foreach($x in @('--no-defaults',"--basedir=$mdb","--datadir=$dbData","--port=$dbPort",'--bind-address=127.0.0.1','--console')){$psi.ArgumentList.Add($x)}
$db=[Diagnostics.Process]::Start($psi);$dbOut=$db.StandardOutput.ReadToEndAsync();$dbErr=$db.StandardError.ReadToEndAsync()
$apacheStarted=$false;$httpd=$null
try{
 $ready=$false
 for($i=0;$i-lt90;$i++){& (Join-Path $mdb 'bin/mariadb-admin.exe') --protocol=tcp --host=127.0.0.1 "--port=$dbPort" --user=root ping 2>$null|Out-Null;if($LASTEXITCODE-eq0){$ready=$true;break};Start-Sleep -Milliseconds 300}
 Need $ready 'MARIADB_NOT_STARTED'
 $apacheSource=$null
 foreach($a in @('C:\tools\Apache24','C:\tools\apache','C:\ProgramData\chocolatey\lib\apache-httpd')){
  if(Test-Path $a){$e=Get-ChildItem $a -Recurse -Filter httpd.exe -File|Select-Object -First 1;if($e){$apacheSource=Split-Path -Parent (Split-Path -Parent $e.FullName);break}}
 }
 if(!$apacheSource){choco install apache-httpd --version=2.4.55 -y --no-progress --params "'/installLocation:$lab\httpd /noService'";Need ($LASTEXITCODE-eq0) 'APACHE_INSTALL_FAILED';$e=Get-ChildItem (Join-Path $lab 'httpd') -Recurse -Filter httpd.exe|Select-Object -First 1;$apacheSource=Split-Path -Parent (Split-Path -Parent $e.FullName)}
 $infra=Join-Path $root 'Infrastructure';New-Item -ItemType Directory -Force $infra|Out-Null
 Copy-Item $phpRoot (Join-Path $infra 'PHP') -Recurse
 New-Item -ItemType Directory -Force (Join-Path $infra 'MariaDB')|Out-Null
 Copy-Item (Join-Path $mdb 'bin') (Join-Path $infra 'MariaDB/bin') -Recurse
 Copy-Item $apacheSource (Join-Path $infra 'Apache') -Recurse
 $php=Join-Path $infra 'PHP/php.exe'
 # Setup the actual original R153 Web source against disposable MariaDB (80 migrations).
 $env:SOKNA_R186_CI_ONLY='YES'
 Run $php @('-c','php.ini',(Join-Path $PWD.Path 'qa/sokna-r186/bootstrap_r186_ci.php'),$old,$root,"$dbPort")
 $persistent=Join-Path $root 'LocalWeb/persistent';New-Item -ItemType Directory -Force (Join-Path $persistent 'public')|Out-Null
 Need (Test-Path (Join-Path $old '.sokna-installation.json')) 'OLD_BINDING_NOT_CREATED'
 Copy-Item (Join-Path $old '.sokna-installation.json') $persistent
 Copy-Item (Join-Path $PWD.Path 'windows/maintenance/src/Sokna.Maintenance.Core/StableLocalRecovery.php') (Join-Path $old 'public/local-recovery.php') -Force
 Copy-Item (Join-Path $old 'public/local-recovery.php') (Join-Path $persistent 'public/local-recovery.php')
 New-Item -ItemType Directory -Force (Join-Path $root 'Data/lifecycle/local')|Out-Null
 $oldVersion=(Get-Content (Join-Path $old 'VERSION.txt') -Raw).Trim()
 $state=Join-Path $root 'Data/lifecycle/local/state.json';if(-not(Test-Path $state)){@{format='sokna-local-update-state-v1';active_version=$oldVersion;staged=$null;activation_journal=$null;recovery_mode=$null;history=@()}|ConvertTo-Json -Depth 5|Set-Content $state -Encoding utf8NoBOM}
 Copy-Item (Join-Path $old 'resources/local-managed-files-v1.json') (Join-Path $root 'Data/lifecycle/local/managed-files.json') -Force
 New-Item -ItemType Junction -Path (Join-Path $root 'Web') -Target $old|Out-Null
 $ap=Join-Path $infra 'Apache';$httpd=Join-Path $ap 'bin/httpd.exe';$conf=Join-Path $ap 'conf/httpd.conf';$ar=P $ap;$pr=P (Join-Path $infra 'PHP');$doc=P (Join-Path $root 'Web/public');$log=P (Join-Path $root 'apache-error.log')
 $conftext=@"
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
"@
 Set-Content $conf $conftext -Encoding utf8NoBOM
 Run $httpd @('-t','-f',$conf)
 Run $httpd @('-k','install','-n','SoknaApache','-f',$conf)
 Start-Service SoknaApache;$apacheStarted=$true
 $w=Invoke-WebRequest 'http://127.0.0.1:39831/login.php' -UseBasicParsing -TimeoutSec 8
 Need ($w.StatusCode-eq200 -and $w.Headers['X-Sokna-Build'] -ceq $oldVersion) 'OLD_HTTP_HEALTH_MISSING'
 Write-Host 'PASS_R186_REAL_R153_231ED_WEB_HTTP_HEALTH_AND_80_MIGRATIONS'
 $build=Join-Path $lab 'build';New-Item -ItemType Directory -Force $build|Out-Null
 Run dotnet @('publish','qa/sokna-r186/R186Harness.csproj','-c','Release','-r','win-x64','--self-contained','false','-o',(Join-Path $build 'harness'))
 Run dotnet @('publish','windows/maintenance/src/Sokna.Maintenance.UpdateEngine/Sokna.Maintenance.UpdateEngine.csproj','-c','Release','-r','win-x64','--self-contained','false','-o',(Join-Path $build 'engine'))
 $harness=Join-Path $build 'harness/R186Harness.exe';$engine=Join-Path $build 'engine/SoknaMaintenanceUpdateEngine.exe'
 Need (Test-Path $harness -and(Test-Path $engine)) 'ENGINE_OR_HARNESS_MISSING'
 $common=@($root,$baseline,$target,$engine,'none',$newPackage)
 Run $harness (@('prepare')+$common)
 Run $harness (@('panel-activate')+$common)
 $newVersion=[string]$m.targets.local.version
 Need ((Get-Content (Join-Path $root 'Web/VERSION.txt') -Raw).Trim() -ceq $newVersion) 'R184_TARGET_READBACK_WRONG'
 $s=Invoke-WebRequest 'http://127.0.0.1:39831/login.php' -UseBasicParsing -TimeoutSec 8
 Need ($s.StatusCode-eq200 -and $s.Headers['X-Sokna-Build'] -ceq $newVersion) 'R184_TARGET_HTTP_HEALTH_WRONG'
 $mysql=Join-Path $mdb 'bin/mariadb.exe'
 $ledger=& $mysql --no-defaults --protocol=tcp --host=127.0.0.1 "--port=$dbPort" --user=root --skip-column-names --execute='SELECT COUNT(*) FROM sokna_r186_exact.schema_migrations;'
 Need ($LASTEXITCODE-eq0 -and [string]$ledger -eq '81') 'EXACT_R184_MIGRATION_LEDGER_NOT_81'
 Write-Host 'PASS_R186_EXACT_R153_HOME_TO_R184_TARGET_ACTIVATE_READBACK_AND_MIGRATION_80_TO_81'
}finally{
 if($apacheStarted -or (Get-Service SoknaApache -ErrorAction SilentlyContinue)) {try{Stop-Service SoknaApache -ErrorAction SilentlyContinue}catch{};try{& $httpd -k uninstall -n SoknaApache|Out-Null}catch{};try{& sc.exe delete SoknaApache|Out-Null}catch{}}
 if($null -ne $db -and -not $db.HasExited){$db.Kill($true);$db.WaitForExit()}
 try{$dbOut.Result|Set-Content (Join-Path $lab 'mariadb-stdout.log')}catch{}
 try{$dbErr.Result|Set-Content (Join-Path $lab 'mariadb-stderr.log')}catch{}
}
