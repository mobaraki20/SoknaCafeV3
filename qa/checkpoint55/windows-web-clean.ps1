$ErrorActionPreference='Stop'
$ProgressPreference='SilentlyContinue'
Set-StrictMode -Version Latest
if($env:GITHUB_ACTIONS -ne 'true' -or $env:RUNNER_OS -ne 'Windows' -or $env:SOKNA_R186_CI_ONLY -ne 'YES') {throw 'R277_NATIVE_DISPOSABLE_CI_ONLY'}
if(Get-Service SoknaApache -ErrorAction SilentlyContinue){throw 'R277_REFUSE_EXISTING_APACHE_SERVICE'}
function Need([bool]$ok,[string]$code){if(-not $ok){throw ('R277_NATIVE_'+$code)}}
function Run([string]$exe,[string[]]$argvNative){
 $pushed=$false
 if([IO.Path]::GetFileName($exe) -ieq 'php.exe'){Push-Location (Split-Path -Parent $exe);$pushed=$true}
 try {& $exe @argvNative;$result=$LASTEXITCODE} finally {if($pushed){Pop-Location}}
 if($result -ne 0){throw "R277_NATIVE_COMMAND_EXIT_$result $exe"}
}
function P([string]$path){[IO.Path]::GetFullPath($path).Replace('\','/')}
$lab=Join-Path $env:RUNNER_TEMP ('sokna-r301-native-'+[guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Force $lab|Out-Null
# Reuse the externally SHA-pinned PHP and MariaDB components already used by R275 native acceptance.
$phpUrl='https://downloads.php.net/~windows/releases/archives/php-8.4.24-Win32-vs17-x64.zip'
$phpZip=Join-Path $lab 'php-ts.zip';$phpRoot=Join-Path $lab 'php-ts'
& curl.exe --location --fail --silent --show-error --max-time 180 --output $phpZip $phpUrl
Need ($LASTEXITCODE -eq 0) 'PHP_DOWNLOAD_FAILED'
Write-Host 'PHP download completed'
Need ((Get-FileHash $phpZip -Algorithm SHA256).Hash.ToLowerInvariant() -ceq '7b57fc9840273ab153834d0e2bd06e0bcf4fead36e381182b4b8fe9cedff3174') 'PINNED_PHP_SHA_MISMATCH'
Expand-Archive $phpZip $phpRoot
Copy-Item (Join-Path $phpRoot 'php.ini-development') (Join-Path $phpRoot 'php.ini')
Add-Content (Join-Path $phpRoot 'php.ini') -Value ('extension_dir="'+(P (Join-Path $phpRoot 'ext'))+'"')
foreach($e in @('pdo_mysql','mbstring','zip','sodium','fileinfo','gd','curl')){Add-Content (Join-Path $phpRoot 'php.ini') -Value ('extension='+$e)}
$mdbUrl='https://archive.mariadb.org/mariadb-11.4.7/winx64-packages/'
$mdbZip=Join-Path $lab 'mariadb.zip'
& curl.exe --location --fail --silent --show-error --max-time 240 --output $mdbZip ($mdbUrl+'mariadb-11.4.7-winx64.zip')
Need ($LASTEXITCODE -eq 0) 'MARIADB_DOWNLOAD_FAILED'
Write-Host 'MariaDB download completed'
$sums=(Invoke-WebRequest ($mdbUrl+'sha256sums.txt') -TimeoutSec 45).Content
$expected=[regex]::Match([string]$sums,'(?im)^([0-9a-f]{64})\s+\*?(?:\./)?mariadb-11\.4\.7-winx64\.zip\s*$').Groups[1].Value
Need ($expected.Length -eq 64 -and (Get-FileHash $mdbZip -Algorithm SHA256).Hash.ToLowerInvariant() -ceq $expected) 'MARIADB_SHA_INVALID'
Expand-Archive $mdbZip (Join-Path $lab 'mdb')
$mdb=(Get-ChildItem (Join-Path $lab 'mdb') -Directory|Select-Object -First 1).FullName
$dbData=Join-Path $mdb 'data';$dbPort=39745
Run (Join-Path $mdb 'bin/mariadb-install-db.exe') @("--datadir=$dbData","--port=$dbPort")
$psi=[Diagnostics.ProcessStartInfo]::new((Join-Path $mdb 'bin/mariadbd.exe'))
$psi.UseShellExecute=$false;$psi.RedirectStandardOutput=$true;$psi.RedirectStandardError=$true
foreach($arg in @('--no-defaults',"--basedir=$mdb","--datadir=$dbData","--port=$dbPort",'--bind-address=127.0.0.1','--console')){$psi.ArgumentList.Add($arg)}
$db=[Diagnostics.Process]::Start($psi);$dbOut=$db.StandardOutput.ReadToEndAsync();$dbErr=$db.StandardError.ReadToEndAsync()
$apacheStarted=$false;$httpd=$null
try {
 $ready=$false
 for($i=0;$i-lt20;$i++){
  if($db.HasExited){Write-Host 'R278_DB_EARLY_EXIT';break}
  & (Join-Path $mdb 'bin/mariadb-admin.exe') --protocol=tcp --host=127.0.0.1 "--port=$dbPort" --connect-timeout=1 --user=root ping 2>$null|Out-Null
  if($LASTEXITCODE-eq0){$ready=$true;break}
  Start-Sleep -Milliseconds 500
 }
 if(-not $ready){
  Write-Host ('R278_MARIADB_DIAGNOSTIC_HAS_EXITED '+$db.HasExited)
  if(-not $db.HasExited){$db.Kill($true);$db.WaitForExit()}
  try{Write-Host ('R278_MARIADB_STDERR '+$dbErr.Result)}catch{}
  try{Write-Host ('R278_MARIADB_STDOUT '+$dbOut.Result)}catch{}
 }
 Need $ready 'MARIADB_NOT_STARTED'
 Write-Host 'MariaDB disposable server ready'
 $apacheSource=$null
 foreach($a in @('C:\tools\Apache24','C:\tools\apache','C:\ProgramData\chocolatey\lib\apache-httpd')){
  if(Test-Path $a){$e=Get-ChildItem $a -Recurse -Filter httpd.exe -File|Select-Object -First 1;if($e){$apacheSource=Split-Path -Parent (Split-Path -Parent $e.FullName);break}}
 }
 Need ([bool]$apacheSource) 'RUNNER_APACHE_NOT_FOUND__NO_SUBSTITUTE_DOWNLOAD'

 $web=Join-Path $lab 'Web'
 $data=Join-Path $lab 'Data'
 $pack=Join-Path $env:RUNNER_TEMP 'exact/LOCAL-FINAL-WEB-QA-DO-NOT-INSTALL.zip'
 Need ((Get-FileHash $pack -Algorithm SHA256).Hash.ToLowerInvariant() -ceq '45b671f0d9f9689223f8d899a2f7c9af7de9493e7d44ad5a9ed21553227a03b4') 'EXACT_WEB_SHA_WRONG'
 Expand-Archive $pack $web
 New-Item -ItemType Directory -Force (Join-Path $data 'guest-media')|Out-Null
 $ap=Join-Path $lab 'Apache';Copy-Item $apacheSource $ap -Recurse
 $httpd=Join-Path $ap 'bin/httpd.exe';$conf=Join-Path $ap 'conf/httpd.conf';$ar=P $ap;$pr=P $phpRoot;$doc=P (Join-Path $web 'public');$log=P (Join-Path $lab 'apache-error.log')
 & $httpd -v
 & (Join-Path $phpRoot 'php.exe') -v
 & (Join-Path $mdb 'bin/mariadbd.exe') --version
 $conftext=@"
ServerRoot "$ar"
Listen 127.0.0.1:39841
ServerName 127.0.0.1:39841
LoadModule authz_core_module modules/mod_authz_core.so
LoadModule authz_host_module modules/mod_authz_host.so
LoadModule dir_module modules/mod_dir.so
LoadModule mime_module modules/mod_mime.so
LoadModule php_module "$pr/php8apache2_4.dll"
PHPIniDir "$pr"
TypesConfig conf/mime.types
AddType application/x-httpd-php .php
DocumentRoot "$doc"
<Directory "$doc">
 Require local
 AllowOverride None
</Directory>
ErrorLog "$log"
PidFile "$ar/logs/httpd.pid"
"@
 Set-Content $conf $conftext -Encoding utf8NoBOM
 Run $httpd @('-t','-f',$conf)
 Run $httpd @('-k','install','-n','SoknaApache','-f',$conf)
 Start-Service SoknaApache;$apacheStarted=$true
 $origin='http://127.0.0.1:39841'
 $ready=$false
 for($i=0;$i -lt 20;$i++){
   try {$res=Invoke-WebRequest "$origin/setup/index.php" -TimeoutSec 5; if($res.StatusCode -eq 200){$ready=$true;break}} catch{}
   Start-Sleep -Milliseconds 500
 }
 Need $ready 'SETUP_HTTP_UNAVAILABLE'
 Write-Host 'Apache and PHP HTTP setup ready'
 $status=Invoke-RestMethod "$origin/setup/api.php?action=status" -SessionVariable session -TimeoutSec 30
 Need ([bool]$status.success -and [bool]$status.preflight.ok -and $status.setup.state -eq 'new') 'SETUP_PREFLIGHT_FAILED'
 $body=@{csrf=$status.csrf;data_dir=$data;db=@{host='127.0.0.1';port=[string]$dbPort;name='sokna_ci_clean_windows';user='root';pass=''};create_database=$true;admin_user='ci-admin';admin_password='disposable-windows-qa-20261126';cafe_name='DISPOSABLE CI';table_count=5}
 $ins=Invoke-RestMethod "$origin/setup/api.php?action=install_new" -Method Post -ContentType 'application/json' -Body ($body|ConvertTo-Json -Depth 8) -WebSession $session -TimeoutSec 360
 Need ([bool]$ins.success -and $ins.state -eq 'installed' -and $ins.health.migrations_applied -eq 93 -and $ins.health.migration_files -eq 93) 'SETUP_INSTALL_FAILED'
 $post=Invoke-RestMethod "$origin/setup/api.php?action=status" -WebSession $session -TimeoutSec 30
 Need ([bool]$post.setup.installed -and [bool]$post.setup.binding_current) 'SETUP_BINDING_FAILED'
 $duplicate=Invoke-WebRequest "$origin/setup/api.php?action=install_new" -Method Post -ContentType 'application/json' -Body ($body|ConvertTo-Json -Depth 8) -WebSession $session -SkipHttpErrorCheck -TimeoutSec 30
 Need ($duplicate.StatusCode -eq 423 -and ($duplicate.Content|ConvertFrom-Json).code -eq 'setup_locked') 'DUPLICATE_INSTALL_NOT_REJECTED'
 $login=Invoke-WebRequest "$origin/login.php" -TimeoutSec 30
 Need ($login.StatusCode -eq 200) 'LOGIN_HTTP_FAILED'
 $mysql=Join-Path $mdb 'bin/mariadb.exe'
 $ledger=& $mysql --no-defaults --protocol=tcp --host=127.0.0.1 "--port=$dbPort" --user=root --skip-column-names --execute='SELECT COUNT(*) FROM sokna_ci_clean_windows.schema_migrations;'
 Need ($LASTEXITCODE -eq 0 -and [string]$ledger -eq '93') 'DB_LEDGER_WRONG'
 $receipt=@{status='PASS';package_sha256='45b671f0d9f9689223f8d899a2f7c9af7de9493e7d44ad5a9ed21553227a03b4';actual_windows=$true;actual_http_setup=$true;db_migrations=93;duplicate_rejected=$true;runtime_lock_qualified=$false;native_exe_qualified=$false;distribution_authorized=$false;disposable_only=$true}
 $receipt|ConvertTo-Json|Set-Content (Join-Path $env:RUNNER_TEMP 'checkpoint55-web-windows-receipt.json') -Encoding utf8NoBOM
 Write-Host 'PASS EXACT LOCAL WEB ON DISPOSABLE WINDOWS HTTP AND MARIADB; NOT EXE OR LOCKED INFRA QUALIFICATION'
} finally {
 if($apacheStarted -or (Get-Service SoknaApache -ErrorAction SilentlyContinue)){try{Stop-Service SoknaApache -ErrorAction SilentlyContinue}catch{};try{& $httpd -k uninstall -n SoknaApache|Out-Null}catch{};try{& sc.exe delete SoknaApache|Out-Null}catch{}}
 if($null -ne $db -and -not $db.HasExited){$db.Kill($true);$db.WaitForExit()}
 if(Test-Path (Join-Path $lab 'apache-error.log')){Get-Content (Join-Path $lab 'apache-error.log') -Tail 25}
}
