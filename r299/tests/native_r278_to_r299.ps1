$ErrorActionPreference='Stop'
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
$lab=Join-Path $env:RUNNER_TEMP ('sokna-r299-native-'+[guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Force $lab|Out-Null
$pack=$env:SOKNA_R299_CANDIDATE_ZIP
$parent=$env:SOKNA_R278_ORIGINAL_ZIP
Need ((Test-Path $pack) -and (Get-FileHash $pack -Algorithm SHA256).Hash.ToLowerInvariant() -ceq '8a897f3ac9215e06d72f67be292b11cf17263a0ab687d9b59d8bc341ac818507') 'R299_EXACT_PACKAGE_SHA_INVALID'
Need ((Test-Path $parent) -and (Get-FileHash $parent -Algorithm SHA256).Hash.ToLowerInvariant() -ceq '3a1070e18b14307fb2571634c961b20ca3e5d38e6c9a9dc1afad68be89fe6824') 'R278_EXACT_PARENT_PACKAGE_SHA_INVALID'
$root=Join-Path $lab 'local-machine'
$old=Join-Path $root 'Web.release.r278'
New-Item -ItemType Directory -Force $root|Out-Null
$parentOuter=Join-Path $lab 'parent-r278-outer'
Expand-Archive $parent $parentOuter
Expand-Archive (Join-Path $parentOuter 'artifacts/local/web.zip') $old
Set-Content (Join-Path $root '.u01-ci-only') 'disposable-r299' -Encoding ascii
$baseline=Join-Path $lab 'baseline-r278.json'
$target=Join-Path $lab 'target-r299.json'
$expanded=Join-Path $lab 'r299-release-contents'
Expand-Archive $pack $expanded
$r299Manifest=Get-Content (Join-Path $expanded 'release-set.json') -Raw | ConvertFrom-Json
$r299Manifest.targets.local | ConvertTo-Json -Depth 12 | Set-Content $target -Encoding utf8NoBOM
$baselineCandidates=@($r299Manifest.supported_baselines | Where-Object {$_.local.version -ceq '3.0.0-g6.2-workspace.231fn-r278-rial-policy-persist'})
Need ($baselineCandidates.Count -eq 1) 'R278_BASELINE_NOT_UNIQUE_IN_R299'
Need ($baselineCandidates[0].local.managed_tree_sha256 -ceq '2e0d5febc7c8c023bcfe333648a2d83bea2013e7092c7269afb2cc17632d04c5') 'R278_BASELINE_TREE_MISMATCH'
$baselineCandidates[0].local | ConvertTo-Json -Depth 12 | Set-Content $baseline -Encoding utf8NoBOM
python packaging/windows/release-gates/check_local_release_set.py $pack --require-installable
Need ($LASTEXITCODE -eq 0) 'R299_REQUIRE_INSTALLABLE_CONTRACT_FAILED'
$baselineInfo=Get-Content $baseline -Raw|ConvertFrom-Json
$targetInfo=Get-Content $target -Raw|ConvertFrom-Json
Need ($baselineInfo.version -ceq '3.0.0-g6.2-workspace.231fn-r278-rial-policy-persist') 'BASELINE_VERSION_WRONG'
Need ($targetInfo.version -ceq '3.0.0-g6.2-workspace.231fq-r299-cumulative-controlled-install') 'R299_VERSION_WRONG'
Need ($targetInfo.managed_tree_sha256 -ceq '04e4bf1cc5c9c860835f4081a9f7f847f5fd9d9979f3d8558c5fcd3cee79517f') 'R299_TARGET_TREE_MISMATCH'
Need ($targetInfo.migration_head -ceq '0083_remaining_account_discount') 'R299_NEW_MIGRATION_MISSING'
# Reuse the externally SHA-pinned PHP and MariaDB components already used by R275 native acceptance.
$phpUrl='https://downloads.php.net/~windows/releases/archives/php-8.4.24-Win32-vs17-x64.zip'
$phpZip=Join-Path $lab 'php-ts.zip';$phpRoot=Join-Path $lab 'php-ts'
Invoke-WebRequest $phpUrl -OutFile $phpZip
Need ((Get-FileHash $phpZip -Algorithm SHA256).Hash.ToLowerInvariant() -ceq '7b57fc9840273ab153834d0e2bd06e0bcf4fead36e381182b4b8fe9cedff3174') 'PINNED_PHP_SHA_MISMATCH'
Expand-Archive $phpZip $phpRoot
Copy-Item (Join-Path $phpRoot 'php.ini-development') (Join-Path $phpRoot 'php.ini')
Add-Content (Join-Path $phpRoot 'php.ini') -Value ('extension_dir="'+(P (Join-Path $phpRoot 'ext'))+'"')
foreach($e in @('pdo_mysql','mbstring','zip','sodium')){Add-Content (Join-Path $phpRoot 'php.ini') -Value ('extension='+$e)}
$mdbUrl='https://archive.mariadb.org/mariadb-11.4.7/winx64-packages/'
$mdbZip=Join-Path $lab 'mariadb.zip';Invoke-WebRequest ($mdbUrl+'mariadb-11.4.7-winx64.zip') -OutFile $mdbZip
$sums=(Invoke-WebRequest ($mdbUrl+'sha256sums.txt')).Content
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
 New-Item -ItemType Directory -Force (Join-Path $root 'Data/guest-media')|Out-Null
 $bootstrap=Join-Path $PWD.Path 'r277/tests/windows_bootstrap_r275.php'
 Need (Test-Path $bootstrap) 'PHP_BOOTSTRAP_FILE_MISSING'
 Write-Host ('R278_PHP_BOOTSTRAP_SCRIPT '+$bootstrap)
 Write-Host ('R278_PHP_BOOTSTRAP_OLD '+$old)
 Push-Location (Split-Path -Parent $php)
 try {
   & $php -c php.ini $bootstrap $old $root "$dbPort"
   $bootstrapExit=$LASTEXITCODE
 } finally { Pop-Location }
 Write-Host ('R278_PHP_BOOTSTRAP_EXIT '+$bootstrapExit)
 Need ($bootstrapExit -eq 0) 'PHP_BOOTSTRAP_FAILED'
 Write-Host ('R278_PHP_BOOTSTRAP_BINDING '+(Test-Path (Join-Path $old '.sokna-installation.json')))
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
Listen 127.0.0.1:39841
ServerName 127.0.0.1:39841
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
 $before=Invoke-WebRequest 'http://127.0.0.1:39841/login.php' -UseBasicParsing -TimeoutSec 10
 Need ($before.StatusCode-eq200 -and $before.Headers['X-Sokna-Build'] -ceq $oldVersion) 'R277_HTTP_READBACK_WRONG'
 $mysql=Join-Path $mdb 'bin/mariadb.exe'
 $priorLedger=& $mysql --no-defaults --protocol=tcp --host=127.0.0.1 "--port=$dbPort" --user=root --skip-column-names --execute='SELECT COUNT(*) FROM sokna_r186_exact.schema_migrations;'
 Need ($LASTEXITCODE -eq 0 -and [string]$priorLedger -eq '82') 'R277_LEDGER_NOT_82'
 $build=Join-Path $lab 'build';New-Item -ItemType Directory -Force $build|Out-Null
 Run dotnet @('publish','qa/sokna-r186/R186Harness.csproj','-c','Release','-r','win-x64','--self-contained','false','-o',(Join-Path $build 'harness'))
 Run dotnet @('publish','windows/maintenance/src/Sokna.Maintenance.UpdateEngine/Sokna.Maintenance.UpdateEngine.csproj','-c','Release','-r','win-x64','--self-contained','false','-o',(Join-Path $build 'engine'))
 $harness=Join-Path $build 'harness/R186Harness.exe';$engine=Join-Path $build 'engine/SoknaMaintenanceUpdateEngine.exe'
 Need ((Test-Path $harness) -and (Test-Path $engine)) 'MAINTENANCE_ENGINE_MISSING'
 $args=@($root,$baseline,$target,$engine,'none',$pack)
 Run $harness (@('prepare')+$args)
 Need ((Get-Content (Join-Path $root 'Web/VERSION.txt') -Raw).Trim() -ceq $oldVersion) 'STAGE_MUTATED_ACTIVE_VERSION'
 Run $harness (@('panel-activate')+$args)
 Need ((Get-Content (Join-Path $root 'Web/VERSION.txt') -Raw).Trim() -ceq [string]$targetInfo.version) 'R278_ACTIVATE_VERSION_READBACK_WRONG'
 $after=Invoke-WebRequest 'http://127.0.0.1:39841/login.php' -UseBasicParsing -TimeoutSec 10
 Need ($after.StatusCode -eq 200 -and $after.Headers['X-Sokna-Build'] -ceq [string]$targetInfo.version) 'R278_HTTP_READBACK_WRONG'
 $afterLedger=& $mysql --no-defaults --protocol=tcp --host=127.0.0.1 "--port=$dbPort" --user=root --skip-column-names --execute='SELECT COUNT(*) FROM sokna_r186_exact.schema_migrations;'
 Need ($LASTEXITCODE -eq 0 -and [string]$afterLedger -eq '83') 'R299_DB_MIGRATION_0083_NOT_APPLIED'
 $grantExists=& $mysql --no-defaults --protocol=tcp --host=127.0.0.1 "--port=$dbPort" --user=root --skip-column-names --execute="SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='sokna_r186_exact' AND table_name IN ('remaining_account_discount_grants','remaining_account_discount_lines');"
 Need ($LASTEXITCODE -eq 0 -and [string]$grantExists -eq '2') 'R299_DISCOUNT_TABLES_MISSING'
 Need ((Test-Path (Join-Path $root 'LocalWeb/persistent/.sokna-installation.json')) -and (Test-Path (Join-Path $root 'config.php')) -and (Test-Path (Join-Path $root 'install.lock'))) 'PERSISTENT_BINDING_LOST'
 Write-Host 'R299_NATIVE_WINDOWS_REAL_MAINTENANCE_R278_231FN_TO_R299_231FQ_STAGE_ACTIVATE_HTTP_DB83_PERSISTENT_PASS'
} finally {
 if($apacheStarted -or (Get-Service SoknaApache -ErrorAction SilentlyContinue)){try{Stop-Service SoknaApache -ErrorAction SilentlyContinue}catch{};try{& $httpd -k uninstall -n SoknaApache|Out-Null}catch{};try{& sc.exe delete SoknaApache|Out-Null}catch{}}
 if($null -ne $db -and -not $db.HasExited){$db.Kill($true);$db.WaitForExit()}
 try{$dbOut.Result|Set-Content (Join-Path $lab 'mariadb-stdout.log')}catch{}
 try{$dbErr.Result|Set-Content (Join-Path $lab 'mariadb-stderr.log')}catch{}
}
$global:LASTEXITCODE=0
