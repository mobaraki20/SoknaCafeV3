# SOKNA Home TEST HOST: one-time registration of an ATTESTED Local 231ap baseline.
# Windows PowerShell 5.1, requires Administrator. Does not install, update, sign,
# stop services, change Apache/Local Web, or mutate the database.
[CmdletBinding()]
param()
Set-StrictMode -Version 2.0
$ErrorActionPreference = 'Stop'
$ExpectedBuild = '3.0.0-g6.2-workspace.231ap-connections-device-lifecycle-closure'
$ExpectedReleaseId = 'sokna-v3-connections-device-lifecycle-closure-20261017'
$ExpectedTree = '04fbce2d6a98042a05b023da8cd5ce0463365a621103a02214c68cb5a5331f7c'
$ExpectedFiles = 507
$ExpectedMigrations = 70
$maintenanceRoot = Join-Path ([Environment]::GetFolderPath('CommonApplicationData')) 'SOKNA\Maintenance'
$dest = Join-Path $maintenanceRoot 'installed-release-identity.json'
$issuer = Join-Path $maintenanceRoot 'release-issuer\home-local-uat-issuer-v1.json'
$lockPath = Join-Path $maintenanceRoot 'home-local-uat-identity-enrollment.lock'
function Fail([string]$reason) { throw ('SOKNA_LOCAL_IDENTITY_ENROLLMENT: ' + $reason) }
function HashBytes([byte[]]$bytes) {
    $sha = [Security.Cryptography.SHA256]::Create()
    try { return ([BitConverter]::ToString($sha.ComputeHash($bytes))).Replace('-', '').ToLowerInvariant() }
    finally { $sha.Dispose() }
}
function ShaFile([string]$p) { return (Get-FileHash -LiteralPath $p -Algorithm SHA256).Hash.ToLowerInvariant() }
function IsReparse([string]$p) {
    if (-not (Test-Path -LiteralPath $p)) { return $false }
    return [bool]((Get-Item -LiteralPath $p -Force).Attributes -band [IO.FileAttributes]::ReparsePoint)
}
function SafePath([string]$p, [bool]$allowWebJunction=$false) {
    $p = [IO.Path]::GetFullPath($p)
    $cur = $p
    while ($cur) {
        if (IsReparse $cur) {
            if (-not ($allowWebJunction -and $cur -eq $p)) { Fail ('Reparse point in protected path: ' + $cur) }
        }
        $parent = [IO.Directory]::GetParent($cur)
        if ($null -eq $parent -or $parent.FullName -eq $cur) { break }
        $cur = $parent.FullName
    }
}
function GetServiceExe([string]$name) {
    $svc = Get-CimInstance Win32_Service -Filter ("Name='" + $name + "'") -ErrorAction Stop
    if ($null -eq $svc) { Fail ('Required Windows service missing: ' + $name) }
    $raw = [string]$svc.PathName
    if ($raw -match '^\s*"([^"]+\.exe)"') { $exe = $matches[1] }
    elseif ($raw -match '^\s*(\S+?\.exe)(?:\s|$)') { $exe = $matches[1] }
    else { Fail ('Cannot resolve service EXE: ' + $name) }
    $exe = [IO.Path]::GetFullPath($exe)
    if (-not (Test-Path -LiteralPath $exe -PathType Leaf)) { Fail ('Service EXE missing: ' + $name) }
    return $exe
}
function SaveReport($record) {
    $desktop = [Environment]::GetFolderPath('DesktopDirectory')
    if (-not $desktop -or -not (Test-Path -LiteralPath $desktop)) { $desktop = [Environment]::GetFolderPath('MyDocuments') }
    if (-not $desktop -or -not (Test-Path -LiteralPath $desktop)) { Fail 'Desktop/Documents not found for public report.' }
    $tmp = Join-Path $env:TEMP ('sokna-local-identity-public-' + [Guid]::NewGuid().ToString('N'))
    $zip = Join-Path $desktop ('SOKNA-Home-Local231ap-Identity-Bound-' + [DateTime]::UtcNow.ToString('yyyyMMdd-HHmmss') + '.zip')
    New-Item -ItemType Directory -Path $tmp -Force | Out-Null
    try {
        [IO.File]::WriteAllText((Join-Path $tmp 'public-identity-result.json'), ($record | ConvertTo-Json -Depth 8), (New-Object Text.UTF8Encoding($false)))
        Compress-Archive -LiteralPath (Join-Path $tmp 'public-identity-result.json') -DestinationPath $zip -CompressionLevel Optimal
        Write-Host ('PUBLIC-ONLY RESULT ZIP: ' + $zip)
    } finally { Remove-Item $tmp -Recurse -Force -ErrorAction SilentlyContinue }
}
$principal = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) { Fail 'Run as Administrator.' }
SafePath $maintenanceRoot
if (-not (Test-Path -LiteralPath $maintenanceRoot -PathType Container)) { Fail 'Installed Maintenance root missing.' }
SafePath $issuer
if (-not (Test-Path -LiteralPath $issuer -PathType Leaf)) { Fail 'Home release signing public trust has not been enrolled.' }
$keyRecord = Get-Content -LiteralPath $issuer -Raw | ConvertFrom-Json
if ($keyRecord.format -ne 'sokna-home-test-release-issuer-v1' -or $keyRecord.mode -ne 'TEST_ONLY_NO_PRODUCTION_QUALIFICATION') { Fail 'Unexpected signing issuer record.' }
$keyId = [string]$keyRecord.key_id
if ($keyId -notmatch '^home-local-uat-[0-9a-f]{20}$') { Fail 'Invalid Home signing key ID.' }
$pubPath = Join-Path $maintenanceRoot ('trust\release-signing\' + $keyId + '.spki.pem')
SafePath $pubPath
if (-not (Test-Path -LiteralPath $pubPath -PathType Leaf)) { Fail 'Home public signing key is not trusted.' }
$pub = [IO.File]::ReadAllText($pubPath)
if (-not $pub.Contains('-----BEGIN PUBLIC KEY-----') -or $pub.Contains('PRIVATE KEY')) { Fail 'Signing trust file is not public-only.' }
$certificate = @(Get-ChildItem Cert:\LocalMachine\My | Where-Object Thumbprint -eq $keyRecord.certificate_thumbprint)
if ($certificate.Count -ne 1 -or -not $certificate[0].HasPrivateKey) { Fail 'Previously enrolled non-exportable signing key missing.' }

# Discover Local root from the installed Apache SERVICE, not from an assumed drive letter.
$apacheExe = GetServiceExe 'SoknaApache'
$bin = Split-Path -Parent $apacheExe
$apacheDir = Split-Path -Parent $bin
$infraDir = Split-Path -Parent $apacheDir
$packageRoot = Split-Path -Parent $infraDir
if ([IO.Path]::GetFileName($apacheDir) -ne 'Apache' -or [IO.Path]::GetFileName($infraDir) -ne 'Infrastructure') { Fail 'Apache service is outside the known SOKNA installation layout.' }
$web = Join-Path $packageRoot 'Web'
if (-not (Test-Path -LiteralPath $web -PathType Container) -or -not (IsReparse $web)) { Fail 'Installed Local Web is not the verified sibling-slot junction.' }
$versionFile = Join-Path $web 'VERSION.txt'
if (-not (Test-Path $versionFile) -or (Get-Content -LiteralPath $versionFile -Raw).Trim() -ne $ExpectedBuild) { Fail 'Home Local build is not exactly 231ap.' }
$releaseMetaPath = Join-Path $web 'resources\release-identity-v1.json'
$releaseMeta = Get-Content -LiteralPath $releaseMetaPath -Raw | ConvertFrom-Json
if ($releaseMeta.format -ne 'sokna-release-component-identity-v1' -or $releaseMeta.release_set_id -ne $ExpectedReleaseId -or $releaseMeta.version -ne $ExpectedBuild -or $releaseMeta.source.managed_file_count -ne $ExpectedFiles) { Fail 'Local source release ID does not match canonical 231ap.' }
$invPath = Join-Path $web 'resources\local-managed-files-v1.json'
$inv = Get-Content -LiteralPath $invPath -Raw | ConvertFrom-Json
if ($inv.format -ne 'sokna-local-managed-files-v1' -or $inv.files.Count -ne $ExpectedFiles) { Fail 'Local managed-file inventory does not match 231ap.' }
$seen = New-Object 'System.Collections.Generic.HashSet[string]' ([StringComparer]::OrdinalIgnoreCase)
$managedPaths = New-Object 'System.Collections.Generic.List[string]'
foreach ($name in $inv.files) {
    $name = [string]$name
    if (-not $name -or $name -match '(^[\\/]|[:]|(^|[\\/])\.\.?([\\/]|$)|[\\/]$)' -or $name.Length -gt 512 -or -not $seen.Add($name)) { Fail 'Unsafe or repeated managed filename.' }
    $managedPaths.Add($name)
}
$managedPaths.Sort([StringComparer]::Ordinal)
$sb = New-Object Text.StringBuilder
foreach ($rel in $managedPaths) {
    $path = Join-Path $web ($rel.Replace('/', '\'))
    if (-not (Test-Path -LiteralPath $path -PathType Leaf) -or (IsReparse $path)) { Fail ('Missing/unsafe managed file: ' + $rel) }
    [void]$sb.Append($rel.Replace('\','/')).Append([char]0).Append((ShaFile $path)).Append("`n")
}
$actualTree = HashBytes ([Text.Encoding]::UTF8.GetBytes($sb.ToString()))
if ($actualTree -ne $ExpectedTree) { Fail ('Local managed tree differs from canonical 231ap: ' + $actualTree) }

# PHP CLI runs only a fixed, read-only migration/installation probe: no install/migrate/setup APIs.
if (-not (Test-Path (Join-Path $packageRoot 'config.php') -PathType Leaf) -or -not (Test-Path (Join-Path $packageRoot 'install.lock') -PathType Leaf)) { Fail 'Existing installation config/lock is missing.' }
$phpCandidates = @(Get-ChildItem -LiteralPath $infraDir -Filter php.exe -File -Recurse -ErrorAction Stop)
if ($phpCandidates.Count -ne 1) { Fail ('Cannot uniquely locate the installed PHP CLI: ' + $phpCandidates.Count) }
$phpExe = $phpCandidates[0].FullName
$phpProbe = @'
<?php
if ($argc < 3) exit(65);
$root=$argv[1];$web=$argv[2];
if (!is_file($root.DIRECTORY_SEPARATOR.'config.php') || !is_file($root.DIRECTORY_SEPARATOR.'install.lock')) exit(66);
$config=require $root.DIRECTORY_SEPARATOR.'config.php';
if (!is_array($config)) exit(67);
require_once $web.'/src/Setup/BrowserSetupService.php';
$status=(new \Sokna\Local\Setup\BrowserSetupService($root,$web))->status();
if (($status['installed']??false)!==true || ($status['binding_current']??false)!==true) exit(68);
require_once $web.'/src/Core/Config.php';
require_once $web.'/src/Core/Database.php';
require_once $web.'/src/Core/Migrations.php';
$c=\Sokna\Local\Core\Config::fromArray($config);
$pdo=\Sokna\Local\Core\Database::connect($c);
$mgr=new \Sokna\Local\Core\Migrations($pdo,$web.'/database/migrations');
$catalog=$mgr->catalog();$applied=$mgr->appliedVersions();$keys=array_keys($catalog);
sort($keys,SORT_STRING);sort($applied,SORT_STRING);
if (count($keys)!==70 || count($applied)!==70 || $keys!==$applied) exit(69);
$lines=[];foreach ($keys as $v) $lines[]=$v."\0".hash_file('sha256',$catalog[$v]);
$head=end($applied);
$fingerprint='migration-ledger-sha256-v1:'.hash('sha256',implode("\n",$lines)."\n");
$dataDir=(string)($config['app']['data_dir']??'');
if ($dataDir==='') exit(70);
$life=$dataDir.DIRECTORY_SEPARATOR.'update'.DIRECTORY_SEPARATOR.'state.json';
if (!is_file($life)) exit(71);
$state=json_decode((string)file_get_contents($life),true);
if (!is_array($state) || ($state['active_version']??'')!==trim((string)file_get_contents($web.'/VERSION.txt'))) exit(72);
echo json_encode(['ok'=>true,'migration_count'=>count($applied),'migration_head'=>$head,'schema_fingerprint'=>$fingerprint,'local_lifecycle_bound'=>true],JSON_UNESCAPED_SLASHES),"\n";
'@
$probePath = Join-Path $env:TEMP ('sokna-local-identity-probe-' + [Guid]::NewGuid().ToString('N') + '.php')
$probe = $null
try {
    [IO.File]::WriteAllText($probePath, $phpProbe, (New-Object Text.UTF8Encoding($false)))
    $output = & $phpExe $probePath $packageRoot $web 2>$null
    if ($LASTEXITCODE -ne 0) { Fail ('Read-only PHP/DB installation proof rejected the host (exit ' + $LASTEXITCODE + ').') }
    $probe = ($output | Out-String).Trim() | ConvertFrom-Json
} finally { Remove-Item -LiteralPath $probePath -Force -ErrorAction SilentlyContinue }
if ($probe.ok -ne $true -or $probe.migration_count -ne $ExpectedMigrations -or $probe.schema_fingerprint -notmatch '^migration-ledger-sha256-v1:[0-9a-f]{64}$' -or -not $probe.migration_head) { Fail 'Database migration proof was not sufficient.' }

$supervisorExe = GetServiceExe 'SoknaMaintenanceSupervisor'
$supervisorVersion = [Diagnostics.FileVersionInfo]::GetVersionInfo($supervisorExe).FileVersion
if ([string]$supervisorVersion -notmatch '^0\.3\.7(?:\.0)?$') { Fail ('Expected Maintenance Supervisor 0.3.7 installed, got ' + $supervisorVersion) }
$engineExe = Join-Path (Split-Path -Parent $supervisorExe) 'SoknaMaintenanceUpdateEngine.exe'
if (-not (Test-Path -LiteralPath $engineExe -PathType Leaf)) { Fail 'Maintenance Update Engine is not adjacent to installed Supervisor.' }
$engineVersion = [Diagnostics.FileVersionInfo]::GetVersionInfo($engineExe).FileVersion
if ([string]$engineVersion -notmatch '^0\.3\.7(?:\.0)?$') { Fail ('Expected Maintenance Update Engine 0.3.7 installed, got ' + $engineVersion) }
$identity = [ordered]@{
    local = [ordered]@{
        release_id = $ExpectedReleaseId
        version = $ExpectedBuild
        managed_tree_sha256 = $actualTree
        schema_fingerprint = [string]$probe.schema_fingerprint
        migration_head = [string]$probe.migration_head
        authority_epoch_policy = $null
    }
    public = $null
    maintenance_supervisor_version = '0.3.7'
    update_engine_version = '0.3.7'
    backup_recovery_engine_version = $null
    public_maintenance_kernel_version = $null
}
$json = $identity | ConvertTo-Json -Depth 10
$identitySha = HashBytes ([Text.Encoding]::UTF8.GetBytes($json))
SafePath $dest
$lock = [IO.File]::Open($lockPath, [IO.FileMode]::OpenOrCreate, [IO.FileAccess]::ReadWrite, [IO.FileShare]::None)
try {
    if (Test-Path -LiteralPath $dest -PathType Leaf) {
        $old = Get-Content -LiteralPath $dest -Raw | ConvertFrom-Json
        if ($old.local.release_id -ne $ExpectedReleaseId -or $old.local.version -ne $ExpectedBuild -or $old.local.managed_tree_sha256 -ne $actualTree -or $old.local.schema_fingerprint -ne $probe.schema_fingerprint -or $old.local.migration_head -ne $probe.migration_head) { Fail 'Existing installed identity is different; no overwrite performed.' }
        $action = 'REUSED_EXACT_VERIFIED_IDENTITY'
    } else {
        $temp = $dest + '.new.' + [Guid]::NewGuid().ToString('N')
        try {
            [IO.File]::WriteAllText($temp, $json, (New-Object Text.UTF8Encoding($false)))
            [IO.File]::Move($temp, $dest)
        } finally { if (Test-Path -LiteralPath $temp) { Remove-Item $temp -Force } }
        $action = 'CREATED_FIRST_VERIFIED_IDENTITY'
    }
    $saved = Get-Content -LiteralPath $dest -Raw | ConvertFrom-Json
    if ($saved.local.version -ne $ExpectedBuild -or $saved.local.managed_tree_sha256 -ne $ExpectedTree -or $saved.local.schema_fingerprint -ne $probe.schema_fingerprint) { Fail 'Installed identity readback failed.' }
    $result = [ordered]@{
        format = 'sokna-home-local-v4-identity-enrollment-result-v1'
        status = 'SUCCESS_VERIFIED_LOCAL_IDENTITY_BOUND'
        action = $action
        local_build = $ExpectedBuild
        release_id = $ExpectedReleaseId
        local_managed_files = $ExpectedFiles
        managed_tree_sha256 = $ExpectedTree
        migrations_verified = $ExpectedMigrations
        schema_fingerprint = [string]$probe.schema_fingerprint
        migration_head = [string]$probe.migration_head
        signing_key_id = $keyId
        signature_trust_verified = $true
        installed_identity_file_present = $true
        installed_identity_sha256 = (ShaFile $dest)
        supervisor_version = '0.3.7'
        update_engine_version = '0.3.7'
        package_signed = $false
        package_for_install = $false
        release_qualification_bypassed = $false
        public_mutation = $false
        database_mutation = $false
        windows_services_restart = $false
    }
    SaveReport $result
    Write-Host ('SUCCESS. Local 231ap real host identity bound: ' + $action)
    Write-Host 'NEXT: truthful release qualification and P-256 signed Local 231ar Release Set, not direct source ZIP.'
} finally { $lock.Dispose() }