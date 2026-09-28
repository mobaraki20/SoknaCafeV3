$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest

$RepoRoot=(Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$SetupExe=Join-Path $RepoRoot 'packaging\prerequisites\out-smoke\SoknaPrerequisitesSetup.exe'
if(-not(Test-Path $SetupExe)){throw "Prerequisites UI is not built: $SetupExe"}

$lock=Get-Content (Join-Path $RepoRoot 'platform\windows\release-lock.json') -Raw|ConvertFrom-Json
$tmp=Join-Path $env:RUNNER_TEMP 'sokna-g7-apache-php'
$root='C:\SOKNA-G7-APACHE-PHP'
Remove-Item $tmp,$root -Recurse -Force -ErrorAction SilentlyContinue
New-Item -ItemType Directory -Path $tmp,$root -Force|Out-Null

foreach($dep in @('apache','php')){
  $a=$lock.artifacts|Where-Object dependency -eq $dep|Select-Object -First 1
  if(-not$a){throw "Missing locked $dep artifact"}
  $out=Join-Path $tmp $a.filename
  & curl.exe -fL --retry 3 --retry-delay 2 -o $out $a.source_url
  if($LASTEXITCODE-ne0){throw "$dep download failed: $LASTEXITCODE"}
  $hash=(Get-FileHash $out -Algorithm SHA256).Hash.ToLowerInvariant()
  if($hash-ne$a.sha256.ToLowerInvariant()){throw "$dep hash mismatch"}
  if((Get-Item $out).Length-ne[long]$a.size){throw "$dep size mismatch"}
}

$phpA=$lock.artifacts|Where-Object dependency -eq 'php'|Select-Object -First 1
$phpDir=Join-Path $root 'Infrastructure\PHP'
Expand-Archive -LiteralPath (Join-Path $tmp $phpA.filename) -DestinationPath $phpDir -Force

# Reproduce the real upgraded-machine defect: an old php.ini may contain more
# than one effective extension_dir directive, with a trailing relative "ext".
$ini=Join-Path $phpDir 'php.ini'
Copy-Item (Join-Path $phpDir 'php.ini-production') $ini -Force
Add-Content -LiteralPath $ini -Value @'
extension_dir = "C:/SOKNA-OLD/Infrastructure/PHP/ext"
extension_dir = "ext"
'@ -Encoding UTF8

$p=Start-Process -FilePath $SetupExe -ArgumentList @('--qualify-php-config',$phpDir) -Wait -PassThru -NoNewWindow
if($p.ExitCode-ne0){throw "Product PHP config helper failed: $($p.ExitCode)"}

$expectedExt=(Join-Path $phpDir 'ext').Replace('\','/')
$iniText=Get-Content $ini -Raw
$extDirLines=[regex]::Matches($iniText,'(?im)^\s*extension_dir\s*=.*$')
if($extDirLines.Count-ne1){throw "Expected exactly one effective extension_dir after repair, got $($extDirLines.Count)"}
if($iniText -notmatch [regex]::Escape('extension_dir = "'+$expectedExt+'"')){throw "php.ini does not use absolute extension_dir"}
if($iniText -notmatch 'extension=php_zip\.dll'){throw "php_zip.dll is not enabled"}

$phpExe=Join-Path $phpDir 'php.exe'
$effective=& $phpExe -r "echo ini_get('extension_dir');"
if($LASTEXITCODE-ne0){throw 'Unable to read effective PHP extension_dir'}
$effectiveFull=[IO.Path]::GetFullPath($effective.Trim()).TrimEnd('\')
$expectedFull=[IO.Path]::GetFullPath((Join-Path $phpDir 'ext')).TrimEnd('\')
if($effectiveFull -ine $expectedFull){throw "Effective extension_dir is still wrong: $effective"}

$mods=& $phpExe -m
if($LASTEXITCODE-ne0){throw 'php -m failed'}
foreach($ext in @('PDO','pdo_mysql','json','mbstring','sodium','zlib','zip','session','fileinfo','openssl')){
  if(-not($mods|Where-Object {$_.Trim()-ieq$ext})){throw "CLI extension missing: $ext"}
}

$apacheA=$lock.artifacts|Where-Object dependency -eq 'apache'|Select-Object -First 1
$apacheExtract=Join-Path $tmp 'apache-extract'
Expand-Archive -LiteralPath (Join-Path $tmp $apacheA.filename) -DestinationPath $apacheExtract -Force
$httpdSource=Get-ChildItem $apacheExtract -Filter httpd.exe -Recurse|Select-Object -First 1
if(-not$httpdSource){throw 'httpd.exe missing'}
$apacheSource=Split-Path (Split-Path $httpdSource.FullName -Parent) -Parent
$apacheDir=Join-Path $root 'Infrastructure\Apache'
New-Item -ItemType Directory -Path $apacheDir -Force|Out-Null
Copy-Item -Path (Join-Path $apacheSource '*') -Destination $apacheDir -Recurse -Force

# Simulate an upgraded machine with a conflicting/stale DLL in Apache\bin.
# The managed Apache config must explicitly load PHP's own libsodium.dll first.
$wrongSodium=Join-Path $apacheDir 'bin\libsodium.dll'
Copy-Item (Join-Path $phpDir 'libssl-3-x64.dll') $wrongSodium -Force
if((Get-FileHash $wrongSodium).Hash -eq (Get-FileHash (Join-Path $phpDir 'libsodium.dll')).Hash){throw 'conflict fixture did not differ'}

$web=Join-Path $root 'Web'
New-Item -ItemType Directory -Path $web -Force|Out-Null
Copy-Item -Path (Join-Path $RepoRoot 'apps\local-web\*') -Destination $web -Recurse -Force
Copy-Item -LiteralPath (Join-Path $RepoRoot 'apps\local-web\public\.htaccess') -Destination (Join-Path $web 'public\.htaccess') -Force

$conf=Join-Path $apacheDir 'conf\httpd.conf'
$a=$apacheDir.Replace('\','/')
$phpForward=$phpDir.Replace('\','/')
$w=(Join-Path $web 'public').Replace('\','/')
$text=Get-Content $conf -Raw
$text=[regex]::Replace($text,'(?im)^\s*Define\s+SRVROOT\s+\".*?\"\s*$',('Define SRVROOT "'+$a+'"'))
$text=[regex]::Replace($text,'(?im)^\s*Listen\s+.*$','Listen 127.0.0.1:18093',1)
$text=[regex]::Replace($text,'(?im)^\s*DocumentRoot\s+\".*?\"\s*$',('DocumentRoot "'+$w+'"'),1)
$rx=[regex]'(?im)^\s*#?\s*LoadModule\s+rewrite_module\s+modules/mod_rewrite\.so\s*$'
if($rx.IsMatch($text)){$text=$rx.Replace($text,'LoadModule rewrite_module modules/mod_rewrite.so',1)}
else{$text+="`r`nLoadModule rewrite_module modules/mod_rewrite.so`r`n"}
$managed=@(
  '# BEGIN SOKNA MANAGED',
  'ServerName 127.0.0.1:18093',
  ('LoadFile "'+$phpForward+'/libsodium.dll"'),
  ('LoadModule php_module "'+$phpForward+'/php8apache2_4.dll"'),
  ('PHPIniDir "'+$phpForward+'"'),
  '<FilesMatch \.php$>',
  '    SetHandler application/x-httpd-php',
  '</FilesMatch>',
  ('<Directory "'+$w+'">'),
  '    Options Indexes FollowSymLinks',
  '    AllowOverride All',
  '    Require all granted',
  '</Directory>',
  'DirectoryIndex index.php index.html',
  '# END SOKNA MANAGED'
)
$text+="`r`n"+($managed -join "`r`n")+"`r`n"
Set-Content -LiteralPath $conf -Value $text -Encoding UTF8

$httpd=Join-Path $apacheDir 'bin\httpd.exe'
& $httpd -t -f $conf
if($LASTEXITCODE-ne0){throw "Apache syntax failed: $LASTEXITCODE"}

$proc=Start-Process -FilePath $httpd -ArgumentList @('-f',$conf,'-DFOREGROUND') -PassThru -WindowStyle Hidden
try{
  $ready=$false
  for($i=0;$i-lt40;$i++){
    Start-Sleep -Milliseconds 500
    try{$tcp=New-Object Net.Sockets.TcpClient;$tcp.Connect('127.0.0.1',18093);$tcp.Dispose();$ready=$true;break}catch{}
  }
  if(-not$ready){throw 'Apache did not bind to 18093'}

  $rootCode=& curl.exe -sS -o NUL -w "%{http_code}" http://127.0.0.1:18093/
  if($rootCode-ne'302'){throw "Expected / to redirect to setup with 302, got $rootCode"}

  $statusFile=Join-Path $env:RUNNER_TEMP 'sokna-g7-setup-status.json'
  $code=& curl.exe -sS -o $statusFile -w "%{http_code}" http://127.0.0.1:18093/setup/api.php?action=status
  if($code-ne'200'){throw "Setup status HTTP $code"}
  $j=Get-Content $statusFile -Raw|ConvertFrom-Json
  if(-not$j.success){throw 'Setup status success=false'}
  foreach($id in @('ext_pdo','ext_pdo_mysql','ext_json','ext_mbstring','ext_sodium','ext_zlib','ext_zip','ext_session')){
    $c=$j.preflight.checks|Where-Object id -eq $id|Select-Object -First 1
    if(-not$c){throw "Preflight check missing: $id"}
    if(-not$c.ok){throw "Preflight check failed: $id current=$($c.current)"}
  }
  if(-not$j.preflight.ok){throw 'Browser Setup preflight not globally OK'}
  Write-Host 'G7_LEGACY_PHP_REPAIR_AND_APACHE_BROWSER_PREFLIGHT=PASS'
} finally {
  if($proc -and -not$proc.HasExited){Stop-Process -Id $proc.Id -Force -ErrorAction SilentlyContinue}
  $log=Join-Path $apacheDir 'logs\error.log'
  if(Test-Path $log){Write-Host '--- APACHE ERROR LOG ---';Get-Content $log -Tail 80}
  Remove-Item $root,$tmp -Recurse -Force -ErrorAction SilentlyContinue
}
