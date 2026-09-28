$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest

$RepoRoot=(Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$lock=Get-Content (Join-Path $RepoRoot 'platform\windows\release-lock.json') -Raw|ConvertFrom-Json
$tmp=Join-Path $env:RUNNER_TEMP 'sokna-g7-apache-service-sodium'
$root='C:\SOKNA-G7-APACHE-SERVICE-SODIUM'
Remove-Item $tmp,$root -Recurse -Force -ErrorAction SilentlyContinue
New-Item -ItemType Directory -Path $tmp,$root -Force|Out-Null

$svc=Get-Service SoknaApache -ErrorAction SilentlyContinue
if($svc){
  & sc.exe stop SoknaApache 2>$null
  Start-Sleep -Seconds 1
  & sc.exe delete SoknaApache 2>$null
  $svc.Dispose()
  Start-Sleep -Seconds 1
}

foreach($dep in @('apache','php')){
  $a=$lock.artifacts|Where-Object dependency -eq $dep|Select-Object -First 1
  $out=Join-Path $tmp $a.filename
  & curl.exe -fL --retry 3 --retry-delay 2 -o $out $a.source_url
  if($LASTEXITCODE-ne0){throw "$dep download failed"}
  if((Get-FileHash $out -Algorithm SHA256).Hash.ToLowerInvariant()-ne$a.sha256.ToLowerInvariant()){throw "$dep hash mismatch"}
}

$phpA=$lock.artifacts|Where-Object dependency -eq 'php'|Select-Object -First 1
$phpDir=Join-Path $root 'Infrastructure\PHP'
Expand-Archive (Join-Path $tmp $phpA.filename) $phpDir -Force

$setupExe=Join-Path $RepoRoot 'packaging\prerequisites\out-smoke\SoknaPrerequisitesSetup.exe'
if(-not(Test-Path $setupExe)){throw 'Prerequisites UI must be built before service sodium regression'}
$p=Start-Process $setupExe -ArgumentList @('--qualify-php-config',$phpDir) -Wait -PassThru -NoNewWindow
if($p.ExitCode-ne0){throw "PHP config qualification failed: $($p.ExitCode)"}

$apacheA=$lock.artifacts|Where-Object dependency -eq 'apache'|Select-Object -First 1
$extract=Join-Path $tmp 'apache'
Expand-Archive (Join-Path $tmp $apacheA.filename) $extract -Force
$h=Get-ChildItem $extract -Filter httpd.exe -Recurse|Select-Object -First 1
if(-not$h){throw 'httpd.exe missing'}
$src=Split-Path (Split-Path $h.FullName -Parent) -Parent
$apacheDir=Join-Path $root 'Infrastructure\Apache'
New-Item -ItemType Directory $apacheDir -Force|Out-Null
Copy-Item (Join-Path $src '*') $apacheDir -Recurse -Force

# Force a conflicting DLL into the Apache application directory. The absolute
# LoadFile below must make PHP use its own libsodium.dll instead.
Copy-Item (Join-Path $phpDir 'libssl-3-x64.dll') (Join-Path $apacheDir 'bin\libsodium.dll') -Force

$web=Join-Path $root 'Web\public'
New-Item -ItemType Directory $web -Force|Out-Null
Set-Content (Join-Path $web 'probe.php') '<?php header("Content-Type: application/json"); echo json_encode(["sodium"=>extension_loaded("sodium"),"pdo_mysql"=>extension_loaded("pdo_mysql"),"mbstring"=>extension_loaded("mbstring"),"zip"=>extension_loaded("zip"),"ini"=>php_ini_loaded_file(),"extdir"=>ini_get("extension_dir")]);' -Encoding UTF8

$conf=Join-Path $apacheDir 'conf\httpd.conf'
$a=$apacheDir.Replace('\','/')
$pdir=$phpDir.Replace('\','/')
$w=$web.Replace('\','/')
$t=Get-Content $conf -Raw
$t=[regex]::Replace($t,'(?im)^\s*Define\s+SRVROOT\s+\".*?\"\s*$',('Define SRVROOT "'+$a+'"'))
$t=[regex]::Replace($t,'(?im)^\s*Listen\s+.*$','Listen 127.0.0.1:18096',1)
$t=[regex]::Replace($t,'(?im)^\s*DocumentRoot\s+\".*?\"\s*$',('DocumentRoot "'+$w+'"'),1)
$managed=@(
  'ServerName 127.0.0.1:18096',
  ('LoadFile "'+$pdir+'/libsodium.dll"'),
  ('LoadModule php_module "'+$pdir+'/php8apache2_4.dll"'),
  ('PHPIniDir "'+$pdir+'"'),
  '<FilesMatch \.php$>','SetHandler application/x-httpd-php','</FilesMatch>',
  ('<Directory "'+$w+'">'),'AllowOverride All','Require all granted','</Directory>',
  'DirectoryIndex index.php'
)
$t+="`r`n"+($managed -join "`r`n")+"`r`n"
Set-Content $conf $t -Encoding UTF8

$httpd=Join-Path $apacheDir 'bin\httpd.exe'
try{
  & $httpd -t -f $conf
  if($LASTEXITCODE-ne0){throw 'Apache syntax failed'}
  & $httpd -k install -n SoknaApache -f $conf
  if($LASTEXITCODE-ne0){throw "Apache service install failed: $LASTEXITCODE"}
  & sc.exe start SoknaApache | Out-Host
  Start-Sleep -Seconds 3
  $state=Get-Service SoknaApache
  if($state.Status-ne'Running'){throw "Apache service did not reach Running: $($state.Status)"}
  $body=& curl.exe -sS http://127.0.0.1:18096/probe.php
  if($LASTEXITCODE-ne0){throw 'Apache service HTTP probe failed'}
  Write-Host $body
  $j=$body|ConvertFrom-Json
  foreach($ext in @('sodium','pdo_mysql','mbstring','zip')){
    if(-not$j.$ext){throw "Apache service PHP extension missing: $ext"}
  }
  $expectedIni=(Join-Path $phpDir 'php.ini')
  if(-not([IO.Path]::GetFullPath([string]$j.ini) -ieq [IO.Path]::GetFullPath($expectedIni))){throw "Apache service loaded unexpected php.ini: $($j.ini)"}
  Write-Host 'G7_APACHE_WINDOWS_SERVICE_SODIUM=PASS'
} finally {
  & sc.exe stop SoknaApache 2>$null
  Start-Sleep -Seconds 1
  & sc.exe delete SoknaApache 2>$null
  Remove-Item $root,$tmp -Recurse -Force -ErrorAction SilentlyContinue
}
