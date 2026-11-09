$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
if ($env:GITHUB_ACTIONS -ne 'true' -or $env:RUNNER_OS -ne 'Windows') { throw 'DISPOSABLE_CI_WINDOWS_ONLY' }
if (Get-Service SoknaApache -ErrorAction SilentlyContinue) { throw 'REFUSE_EXISTING_SOKNA_APACHE' }
function Need([bool]$ok,[string]$message) { if (-not $ok) { throw $message } }
function P([string]$path) { return [IO.Path]::GetFullPath($path).Replace('\','/') }
function Run([string]$exe,[string[]]$arguments) {
    $pushed=$false
    if ([IO.Path]::GetFileName($exe) -ieq 'php.exe') {
       Push-Location (Split-Path -Parent $exe);$pushed=$true
    }
    try { & $exe @arguments; $exit=$LASTEXITCODE } finally { if ($pushed) { Pop-Location } }
    if ($exit -ne 0) { throw "Command failed with exit $exit : $exe" }
}
$repo=$PWD.Path
$lab=Join-Path $env:RUNNER_TEMP 'sokna-r149b-windows-exact-e2e'
New-Item -ItemType Directory -Force $lab | Out-Null
Run python @('qa/sokna-r140/exact-ci/reconstruct.py',$lab)
Run python @('qa/sokna-r140/exact-ci/build-install-qualified-r140.py',(Join-Path $lab 'r140.zip'),(Join-Path $lab 'qualified-r140.zip'))
Run python @('qa/sokna-r149b/build_r149b.py',(Join-Path $lab 'qualified-r140.zip'),'qa/sokna-r149b/overlay.zip',$lab)
Run python @('qa/sokna-r149b/test-manifest-matrix.py',(Join-Path $lab 'qualified-r140.zip'),(Join-Path $lab 'r149b-candidate.zip'),(Join-Path $lab 'r149b-qualified.zip'))
Expand-Archive (Join-Path $lab 'r149b-qualified.zip') (Join-Path $lab 'r149b-outer')
Expand-Archive (Join-Path $lab 'r149b-outer/artifacts/local/web.zip') (Join-Path $lab 'r149b-web')
$phpUrl='https://downloads.php.net/~windows/releases/archives/php-8.4.24-Win32-vs17-x64.zip'
$phpZip=Join-Path $lab 'php-ts.zip'; $phpRoot=Join-Path $lab 'php-ts'
Invoke-WebRequest $phpUrl -OutFile $phpZip
Need ((Get-FileHash $phpZip -Algorithm SHA256).Hash.ToLowerInvariant() -eq '7b57fc9840273ab153834d0e2bd06e0bcf4fead36e381182b4b8fe9cedff3174') 'PHP_TS_SHA_MISMATCH'
Expand-Archive $phpZip $phpRoot
Need (Test-Path (Join-Path $phpRoot 'php8apache2_4.dll')) 'PHP_THREAD_SAFE_REQUIRED'
Copy-Item (Join-Path $phpRoot 'php.ini-development') (Join-Path $phpRoot 'php.ini')
Add-Content (Join-Path $phpRoot 'php.ini') -Value ('extension_dir="' + (P (Join-Path $phpRoot 'ext')) + '"')
Add-Content (Join-Path $phpRoot 'php.ini') -Value 'extension=pdo_mysql'
Add-Content (Join-Path $phpRoot 'php.ini') -Value 'extension=mbstring'
$phpExe=Join-Path $phpRoot 'php.exe'
$mdbUrl='https://archive.mariadb.org/mariadb-11.4.7/winx64-packages/'
$mdbZip=Join-Path $lab 'mariadb.zip'
Invoke-WebRequest ($mdbUrl+'mariadb-11.4.7-winx64.zip') -OutFile $mdbZip
$sums=(Invoke-WebRequest ($mdbUrl+'sha256sums.txt')).Content
$expected=[regex]::Match([string]$sums,'(?im)^([0-9a-f]{64})\s+\*?(?:\./)?mariadb-11\.4\.7-winx64\.zip\s*$').Groups[1].Value
Need ($expected.Length -eq 64 -and (Get-FileHash $mdbZip -Algorithm SHA256).Hash.ToLowerInvariant() -eq $expected) 'MARIA_DB_SHA_MISMATCH'
Expand-Archive $mdbZip (Join-Path $lab 'mdb')
$mdb=(Get-ChildItem (Join-Path $lab 'mdb') -Directory | Select-Object -First 1).FullName
$dbData=Join-Path $lab 'mariadb-data';$dbPort=39733
Run (Join-Path $mdb 'bin/mariadb-install-db.exe') @("--datadir=$dbData","--port=$dbPort")
$psi=[Diagnostics.ProcessStartInfo]::new((Join-Path $mdb 'bin/mariadbd.exe'))
$psi.UseShellExecute=$false;$psi.RedirectStandardOutput=$true;$psi.RedirectStandardError=$true
foreach($arg in @('--no-defaults',"--basedir=$mdb","--datadir=$dbData","--port=$dbPort",'--bind-address=127.0.0.1','--console')){$psi.ArgumentList.Add($arg)}
$db=[Diagnostics.Process]::Start($psi);$dbOut=$db.StandardOutput.ReadToEndAsync();$dbErr=$db.StandardError.ReadToEndAsync()
$restoreDb=$null;$restoreOut=$null;$restoreErr=$null;$restorePort=39734
try {
    $ready=$false
    for($i=0;$i -lt 90;$i++){
        & (Join-Path $mdb 'bin/mariadb-admin.exe') --protocol=tcp --host=127.0.0.1 "--port=$dbPort" --user=root ping 2>$null | Out-Null
        if($LASTEXITCODE -eq 0){$ready=$true;break};Start-Sleep -Milliseconds 300
    }
    Need $ready 'MARIADB_STARTUP_FAILED'
    # A separate server/datadir is the restore target. Never DROP or restore
    # over the application's live database while Apache is running, even in CI.
    $restoreData=Join-Path $lab 'isolated-restore-data'
    Run (Join-Path $mdb 'bin/mariadb-install-db.exe') @("--datadir=$restoreData","--port=$restorePort")
    $restorePsi=[Diagnostics.ProcessStartInfo]::new((Join-Path $mdb 'bin/mariadbd.exe'))
    $restorePsi.UseShellExecute=$false;$restorePsi.RedirectStandardOutput=$true;$restorePsi.RedirectStandardError=$true
    foreach($arg in @('--no-defaults',"--basedir=$mdb","--datadir=$restoreData","--port=$restorePort",'--bind-address=127.0.0.1','--console')){$restorePsi.ArgumentList.Add($arg)}
    $restoreDb=[Diagnostics.Process]::Start($restorePsi)
    $restoreOut=$restoreDb.StandardOutput.ReadToEndAsync();$restoreErr=$restoreDb.StandardError.ReadToEndAsync()
    $ready=$false
    for($i=0;$i -lt 90;$i++){
        & (Join-Path $mdb 'bin/mariadb-admin.exe') --protocol=tcp --host=127.0.0.1 "--port=$restorePort" --user=root ping 2>$null | Out-Null
        if($LASTEXITCODE -eq 0){$ready=$true;break};Start-Sleep -Milliseconds 300
    }
    Need $ready 'ISOLATED_RESTORE_SERVER_STARTUP_FAILED'
    $apacheExe=$null
    foreach($a in @('C:\tools\Apache24','C:\tools\apache','C:\ProgramData\chocolatey\lib\apache-httpd')){
        if(Test-Path $a){$apacheExe=Get-ChildItem $a -Recurse -Filter httpd.exe -File | Select-Object -First 1;if($apacheExe){break}}
    }
    if(-not $apacheExe){
        choco install apache-httpd --version=2.4.55 -y --no-progress --params "'/installLocation:$lab\httpd /noService'"
        Need ($LASTEXITCODE -eq 0) 'APACHE_INSTALL_FAILED'
        $apacheExe=Get-ChildItem (Join-Path $lab 'httpd') -Recurse -Filter httpd.exe -File | Select-Object -First 1
    }
    Need ([bool]$apacheExe) 'APACHE_BINARY_MISSING'
    $apacheSource=Split-Path -Parent (Split-Path -Parent $apacheExe.FullName)
    $engineOut=Join-Path $lab 'engine';$harnessOut=Join-Path $lab 'harness'
    Run dotnet @('publish','windows/maintenance/src/Sokna.Maintenance.UpdateEngine/Sokna.Maintenance.UpdateEngine.csproj','-c','Release','-r','win-x64','--self-contained','false','-o',$engineOut)
    Run dotnet @('publish','windows/maintenance/tests/Sokna.Maintenance.U01Harness/Sokna.Maintenance.U01Harness.csproj','-c','Release','-r','win-x64','--self-contained','false','-o',$harnessOut)
    $harness=Join-Path $harnessOut 'Sokna.Maintenance.U01Harness.exe';$engine=Join-Path $engineOut 'SoknaMaintenanceUpdateEngine.exe'
    Need (Test-Path $harness) 'HARNESS_MISSING';Need (Test-Path $engine) 'ENGINE_MISSING'
    $compatOut=Join-Path $lab 'compat-probe'
    Run dotnet @('publish','qa/sokna-r129-compat/CompatProbe.csproj','-c','Release','-r','win-x64','--self-contained','false','-o',$compatOut)
    $compat=Join-Path $compatOut 'Sokna.R125ArchiveIntegrationProbe.exe'
    $engineHash=(Get-FileHash $engine -Algorithm SHA256).Hash.ToLowerInvariant()
    $engineVersion=(Get-Item $engine).VersionInfo.FileVersion
    Need (-not [string]::IsNullOrWhiteSpace($engineVersion)) 'ENGINE_PHYSICAL_VERSION_MISSING'
    Run $engine @('--help')
    $artifacts=Join-Path $lab 'artifacts';New-Item -ItemType Directory -Force $artifacts | Out-Null
    $baseline=Join-Path $artifacts 'baseline.json';$target=Join-Path $artifacts 'target.json';$package=Join-Path $artifacts 'temporary-ci-qualified.zip'
    $results=@()
    $sharedPackageHash=$null
    foreach($sourceName in @('r75','r140')){
    Run python @('qa/sokna-r149b/make_fixture.py',$lab,$sourceName)
    $packageHash=(Get-FileHash $package -Algorithm SHA256).Hash.ToLowerInvariant()
    if($null -eq $sharedPackageHash){$sharedPackageHash=$packageHash}
    Need ($packageHash -eq $sharedPackageHash) 'UNIVERSAL_TARGET_ZIP_CHANGED_BETWEEN_BASELINES'
    $sourceCount=if($sourceName -eq 'r75'){76}else{79}
    $sentinelQty=if($sourceName -eq 'r75'){75}else{140}
    foreach($fault in @('none','APACHE_STOPPED','DATABASE_BACKUP_VERIFIED','DATABASE_FORWARD_INTENT','POINTER_SWITCHED','COMMITTED')){
        $folder=if($sourceName -eq 'r75'){'آزمون خانه '+$sourceName+'-'+$fault}else{'host-'+$sourceName+'-'+$fault}
        $root=Join-Path $lab $folder
        New-Item -ItemType Directory -Force $root | Out-Null
        Set-Content (Join-Path $root '.u01-ci-only') 'disposable' -Encoding ascii
        $old=Join-Path $root ('Web.release.'+$sourceName)
        Copy-Item (Join-Path $lab ($sourceName+'-web')) $old -Recurse
        $infra=Join-Path $root 'Infrastructure'
        Copy-Item $phpRoot (Join-Path $infra 'PHP') -Recurse
        New-Item -ItemType Directory -Force (Join-Path $infra 'MariaDB') | Out-Null
        Copy-Item (Join-Path $mdb 'bin') (Join-Path $infra 'MariaDB/bin') -Recurse
        Copy-Item $apacheSource (Join-Path $infra 'Apache') -Recurse
        $localPhp=Join-Path $infra 'PHP/php.exe'
        Run $localPhp @('-c','php.ini','-r','if(!in_array("mysql",PDO::getAvailableDrivers(),true))exit(74);')
        $env:SOKNA_R140_PORT=[string]$dbPort;$env:SOKNA_R140_PASSWORD=''
        Run $localPhp @('-c','php.ini',(Join-Path $repo 'qa/sokna-r140/exact-ci/upgrade.php'),'baseline',$lab)
        if($sourceName -eq 'r140'){
            Run $localPhp @('-c','php.ini',(Join-Path $repo 'qa/sokna-r140/exact-ci/upgrade.php'),'upgrade',$lab)
        }
        $mysqlExe=Join-Path $mdb 'bin/mariadb.exe'
        Run $mysqlExe @('--no-defaults','--protocol=tcp','--host=127.0.0.1',"--port=$dbPort",'--user=root',
            "--execute=CREATE TABLE sokna_r140_exact.ci_upgrade_sentinel(id INT PRIMARY KEY,qty INT NOT NULL) ENGINE=InnoDB; INSERT INTO sokna_r140_exact.ci_upgrade_sentinel VALUES(1,$sentinelQty)")
        $dataDir=P (Join-Path $root 'Data')
        $config="<?php return ['installation'=>['id'=>'r140-disposable-ci'],'app'=>['data_dir'=>'$dataDir','timezone'=>'Asia/Tehran'],'db'=>['host'=>'127.0.0.1','port'=>$dbPort,'name'=>'sokna_r140_exact','charset'=>'utf8mb4','user'=>'root','pass'=>'']];"
        Set-Content (Join-Path $root 'config.php') $config -Encoding utf8NoBOM
        Set-Content (Join-Path $root 'install.lock') '{"format":"sokna-install-lock-v3","installation_id":"r140-disposable-ci"}' -Encoding ascii
        $oldVersion=(Get-Content (Join-Path $old 'VERSION.txt') -Raw).Trim()
        $binding=@{format='sokna-local-installation-binding-v1';installation_id='r140-disposable-ci';web_version=$oldVersion}|ConvertTo-Json -Compress
        Set-Content (Join-Path $old '.sokna-installation.json') $binding -Encoding utf8NoBOM
        $persistent=Join-Path $root 'LocalWeb/persistent';New-Item -ItemType Directory -Force (Join-Path $persistent 'public') | Out-Null
        Copy-Item (Join-Path $old '.sokna-installation.json') $persistent
        Copy-Item (Join-Path $repo 'windows/maintenance/src/Sokna.Maintenance.Core/StableLocalRecovery.php') (Join-Path $old 'public/local-recovery.php')
        Copy-Item (Join-Path $old 'public/local-recovery.php') (Join-Path $persistent 'public/local-recovery.php')
        New-Item -ItemType Directory -Force (Join-Path $root 'Data/lifecycle/local') | Out-Null
        New-Item -ItemType Directory -Force (Join-Path $root 'Data/guest-media') | Out-Null
        Set-Content (Join-Path $root 'Data/guest-media/sentinel.txt') 'preserved'
        @{format='sokna-local-update-state-v1';active_version=$oldVersion;staged=$null;activation_journal=$null;recovery_mode=$null;history=@()}|ConvertTo-Json -Depth 4|Set-Content (Join-Path $root 'Data/lifecycle/local/state.json')
        Copy-Item (Join-Path $old 'resources/local-managed-files-v1.json') (Join-Path $root 'Data/lifecycle/local/managed-files.json')
        New-Item -ItemType Junction -Path (Join-Path $root 'Web') -Target $old | Out-Null
        $ap=Join-Path $infra 'Apache';$httpd=Join-Path $ap 'bin/httpd.exe';$conf=Join-Path $ap 'conf/httpd.conf'
        $ar=P $ap;$pr=P (Join-Path $infra 'PHP');$doc=P (Join-Path $root 'Web/public');$err=P (Join-Path $root 'apache-error.log')
        $apacheConfig=@"
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
ErrorLog "$err"
PidFile "$ar/logs/httpd.pid"
"@
        Set-Content $conf $apacheConfig -Encoding utf8NoBOM
        try {
            Run $httpd @('-t','-f',$conf)
            Run $httpd @('-k','install','-n','SoknaApache','-f',$conf)
            Start-Service SoknaApache
            $common=@($root,$baseline,$target,$engine,$fault,$package)
            Run $harness (@('prepare')+$common)
            if($sourceName-eq'r75' -and $fault-eq'none'){
                Run $compat @('exercise-live-r129',$root,$oldVersion)
            }
            if($fault -eq 'none') {Run $harness (@('panel-activate')+$common)}
            else {
                & $harness @(@('activate')+$common)
                Need ($LASTEXITCODE -eq 97) ('FAULT_NOT_REACHED_'+$fault)
                Run $harness (@('recover')+$common)
            }
            $state=Get-Content (Join-Path $root 'Maintenance/local-slots/routine-state.json') -Raw | ConvertFrom-Json
            $forward=$fault -in @('none','DATABASE_FORWARD_INTENT','POINTER_SWITCHED','COMMITTED')
            $expectedPhase=if($forward){'COMMITTED'}else{'RECOVERED_TO_PREVIOUS_SLOT'}
            Need ($state.phase -eq $expectedPhase) "PHASE_MISMATCH_$fault actual=$($state.phase)"
            Need ($state.database_mutation_performed -eq $forward) "DB_MUTATION_FLAG_WRONG_$fault"
            Need (-not $state.recovery_required) "UNRESOLVED_RECOVERY_$fault"
            Need ((Get-Content (Join-Path $root 'Data/guest-media/sentinel.txt') -Raw).Trim() -eq 'preserved') 'MEDIA_LOST'
            $mdbExe=Join-Path $mdb 'bin/mariadb.exe'
            $actualCount= & $mdbExe --no-defaults --protocol=tcp --host=127.0.0.1 "--port=$dbPort" --user=root --skip-column-names --execute='SELECT COUNT(*) FROM sokna_r140_exact.schema_migrations;'
            $expectedCount=if($forward){80}else{$sourceCount}
            Need ($LASTEXITCODE -eq 0 -and [string]$actualCount -eq [string]$expectedCount) "R149B_MIGRATION_LEDGER_BAD fault=$fault expected=$expectedCount got=$actualCount"
            if($forward){
                Run $localPhp @('-c','php.ini',(Join-Path $repo 'qa/sokna-r149b/test-exact-upgrade.php'),'verify',$lab)
            }
            if($fault -eq 'none'){
                # Restore the actual Engine backup on the separate server.
                $dbBackupRoot=Join-Path $root 'Maintenance/local-slots/private-db-backups'
                Need (Test-Path $dbBackupRoot) 'R149B_ENGINE_BACKUP_ROOT_MISSING'
                $backups=@(Get-ChildItem -LiteralPath $dbBackupRoot -Filter '*.sql' -File)
                Need ($backups.Count -eq 1) 'R149B_ENGINE_BACKUP_AMBIGUOUS'
                $backup=$backups[0].FullName
                Need ((Get-Item $backup).Length -gt 500) 'R149B_ENGINE_BACKUP_EMPTY'
                $backupHash=(Get-FileHash $backup -Algorithm SHA256).Hash
                $mysqlExe=Join-Path $mdb 'bin/mariadb.exe'
                Run $mysqlExe @('--no-defaults','--protocol=tcp','--host=127.0.0.1',"--port=$restorePort",'--user=root','--execute=DROP DATABASE IF EXISTS sokna_r140_exact')
                $restore=[Diagnostics.ProcessStartInfo]::new($mysqlExe)
                $restore.UseShellExecute=$false;$restore.RedirectStandardInput=$true
                foreach($arg in @('--no-defaults','--protocol=tcp','--host=127.0.0.1',"--port=$restorePort",'--user=root')){$restore.ArgumentList.Add($arg)}
                $process=[Diagnostics.Process]::Start($restore)
                $inputStream=[IO.File]::OpenRead($backup)
                try{$inputStream.CopyTo($process.StandardInput.BaseStream)}
                finally{$inputStream.Dispose();$process.StandardInput.Close()}
                Need ($process.WaitForExit(120000)) 'R149B_BACKUP_RESTORE_TIMEOUT'
                Need ($process.ExitCode -eq 0) 'R149B_BACKUP_RESTORE_FAILED'
                Need ((Get-FileHash $backup -Algorithm SHA256).Hash -eq $backupHash) 'R149B_BACKUP_TAMPERED_DURING_RESTORE'
                $ledger= & $mysqlExe --no-defaults --protocol=tcp --host=127.0.0.1 "--port=$restorePort" --user=root --skip-column-names --execute='SELECT COUNT(*) FROM sokna_r140_exact.schema_migrations;'
                Need ($LASTEXITCODE -eq 0 -and [string]$ledger -eq [string]$sourceCount) ('R149B_RESTORED_BASELINE_LEDGER_BAD '+$ledger)
                $items= & $mysqlExe --no-defaults --protocol=tcp --host=127.0.0.1 "--port=$restorePort" --user=root --skip-column-names --execute='SELECT COUNT(*) FROM sokna_r140_exact.items;'
                Need ($LASTEXITCODE -eq 0 -and [int]$items -gt 100) 'R149B_BACKUP_RESTORED_MENU_MISSING'
                $restoredQty= & $mysqlExe --no-defaults --protocol=tcp --host=127.0.0.1 "--port=$restorePort" --user=root --skip-column-names --execute='SELECT qty FROM sokna_r140_exact.ci_upgrade_sentinel WHERE id=1;'
                Need ($LASTEXITCODE -eq 0 -and [string]$restoredQty -eq [string]$sentinelQty) 'RESTORED_SENTINEL_DATA_MISMATCH'
                $liveLedger= & $mysqlExe --no-defaults --protocol=tcp --host=127.0.0.1 "--port=$dbPort" --user=root --skip-column-names --execute='SELECT COUNT(*) FROM sokna_r140_exact.schema_migrations;'
                Need ($LASTEXITCODE -eq 0 -and [string]$liveLedger -eq '80') 'ISOLATED_RESTORE_CHANGED_LIVE_DATABASE'
                Write-Host "ISOLATED_ENGINE_BACKUP_RESTORED_WITH_LIVE_DB_PRESERVED source=$sourceName"
            }
            $liveQty= & $mysqlExe --no-defaults --protocol=tcp --host=127.0.0.1 "--port=$dbPort" --user=root --skip-column-names --execute='SELECT qty FROM sokna_r140_exact.ci_upgrade_sentinel WHERE id=1;'
            Need ($LASTEXITCODE -eq 0 -and [string]$liveQty -eq [string]$sentinelQty) 'UPGRADE_OR_RECOVERY_LOST_SENTINEL_DATA'
            $results+=@{source=$sourceName;source_migrations=$sourceCount;target_migrations=[int]$actualCount;package_sha256=$packageHash;engine_sha256=$engineHash;engine_physical_version=$engineVersion;fault=$fault;phase=$state.phase;db_forward=$forward;sentinel_preserved=$true;isolated_restore_verified=($fault -eq 'none');physical_host_tested=$false;status='PASS'}
            Write-Host "R149B_EXACT_WINDOWS_APACHE_MARIADB_CYCLE_PASS source=$sourceName fault=$fault phase=$($state.phase)"
        } finally {
            if(Get-Service SoknaApache -ErrorAction SilentlyContinue){Stop-Service SoknaApache -ErrorAction SilentlyContinue;& sc.exe delete SoknaApache|Out-Null;Start-Sleep -Milliseconds 500}
            if(Test-Path (Join-Path $root 'Web')){[IO.Directory]::Delete((Join-Path $root 'Web'))}
        }
    }
    }
    Need ($results.Count -eq 12) 'TWO_BASELINE_CYCLE_MATRIX_INCOMPLETE'
    $results|ConvertTo-Json -Depth 6|Set-Content (Join-Path $artifacts 'r149b-windows-cycles.json') -Encoding utf8NoBOM
    Write-Host 'R140_EXACT_REAL_WINDOWS_FULL_ACTIVATION_RECOVERY_PASS'
} finally {
    if($null -ne $restoreDb -and -not $restoreDb.HasExited){$restoreDb.Kill($true);$restoreDb.WaitForExit()}
    if($null -ne $restoreOut){try{$restoreOut.Result|Set-Content (Join-Path $lab 'restore-stdout.log')}catch{}}
    if($null -ne $restoreErr){try{$restoreErr.Result|Set-Content (Join-Path $lab 'restore-stderr.log')}catch{}}
    if(-not $db.HasExited){$db.Kill($true);$db.WaitForExit()}
    try{$dbOut.Result|Set-Content (Join-Path $lab 'mariadb-stdout.log')}catch{}
    try{$dbErr.Result|Set-Content (Join-Path $lab 'mariadb-stderr.log')}catch{}
}
