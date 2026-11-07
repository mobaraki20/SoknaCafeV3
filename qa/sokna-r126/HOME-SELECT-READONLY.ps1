# Home UAT R126: read-only OS/DB validation. Never calls update endpoints or controls services.
# A matching bundle is selected by verified installed identity, not by the operator typing a version.
$ErrorActionPreference = 'Stop'
$utf8=New-Object System.Text.UTF8Encoding($false)
$OutputEncoding=$utf8
[Console]::OutputEncoding=$utf8
$here = Split-Path -Parent $MyInvocation.MyCommand.Path
$report = Join-Path $here 'SOKNA-HOME-R126-PREFLIGHT-RESULT.txt'
$selectedLocal = Join-Path $here 'SOKNA-HOME-SELECTED-LOCAL-UAT.zip'
$selectedPublic = Join-Path $here 'SOKNA-HOME-SELECTED-PUBLIC-UAT.zip'
Remove-Item -LiteralPath $selectedLocal,$selectedPublic -ErrorAction SilentlyContinue
$lines = New-Object System.Collections.Generic.List[string]
function Add-Report([string]$line){ $script:lines.Add($line); Write-Output $line }
function Stop-Safe([string]$code) { Add-Report "BLOCKED: $code"; throw "R126_FAIL_CLOSED" }
function Sha-Check([string]$path,[string]$want) {
    if (!(Test-Path -LiteralPath $path -PathType Leaf)) { Stop-Safe 'BUNDLE_FILE_MISSING' }
    $actual = (Get-FileHash -Algorithm SHA256 -LiteralPath $path).Hash.ToLowerInvariant()
    if ($actual -cne $want.ToLowerInvariant()) { Stop-Safe 'BUNDLE_HASH_MISMATCH' }
}
try {
    Add-Report 'SOKNA_HOME_R126_READ_ONLY_AUTOSELECT'
    Add-Report 'NO_INSTALL_NO_DATABASE_CHANGES_NO_SERVICE_CONTROL'
    $idx=Get-Content -LiteralPath (Join-Path $here 'index.json') -Raw -Encoding UTF8 | ConvertFrom-Json
    if ($idx.format -cne 'sokna-r126-home-adaptive-selector-v1') { Stop-Safe 'INDEX_INVALID' }
    $identityPath=Join-Path $env:ProgramData 'SOKNA\Maintenance\installed-release-identity.json'
    if (!(Test-Path -LiteralPath $identityPath -PathType Leaf)){Stop-Safe 'MAINTENANCE_IDENTITY_MISSING'}
    $id=Get-Content -LiteralPath $identityPath -Raw -Encoding UTF8 | ConvertFrom-Json
    $local=$id.local
    if ($null -eq $local -or [string]::IsNullOrEmpty($local.version)){Stop-Safe 'INSTALLED_LOCAL_IDENTITY_MISSING'}
    Add-Report "INSTALLED_LOCAL_VERSION: $($local.version)"
    $dataRoot=Join-Path $env:ProgramData 'SOKNA\Maintenance'
    $routinePath=Join-Path $dataRoot 'local-slots\routine-state.json'
    if (Test-Path -LiteralPath $routinePath -PathType Leaf){
        $routine=Get-Content -LiteralPath $routinePath -Raw -Encoding UTF8 | ConvertFrom-Json
        if ($routine.recovery_required -eq $true -or @('RECOVERY_REQUIRED','ACTIVATING','DATABASE_FORWARD_INTENT','READY_TO_ACTIVATE','APACHE_STOPPED') -contains $routine.phase){Stop-Safe 'UNRESOLVED_LOCAL_ROUTINE'}
    }
    $operationsPath=Join-Path $dataRoot 'operations'
    if (Test-Path -LiteralPath $operationsPath -PathType Container) {
        foreach($f in Get-ChildItem -LiteralPath $operationsPath -File -Recurse -Filter '*.json' -ErrorAction SilentlyContinue){
            try { $x=Get-Content -LiteralPath $f.FullName -Raw -Encoding UTF8 | ConvertFrom-Json }
            catch { Stop-Safe 'UNREADABLE_OPERATION_RECORD' }
            if (@('recovery_required','accepted','activating','running','recovering') -contains $x.state){Stop-Safe 'BLOCKING_MAINTENANCE_OPERATION'}
        }
    }
    $matched=@()
    foreach($lane in $idx.lanes){
        foreach($base in $lane.baselines){
            if ($base.version -ceq $local.version -and
                $base.release_id -ceq $local.release_id -and
                $base.managed_tree_sha256 -ceq $local.managed_tree_sha256 -and
                $base.schema_fingerprint -ceq $local.schema_fingerprint -and
                $base.migration_head -ceq $local.migration_head -and
                $base.authority_epoch_policy -ceq $local.authority_epoch_policy){
                $matched+=,$lane
            }
        }
    }
    if ($matched.Count -ne 1){Stop-Safe 'BASELINE_NOT_VERIFIED_OR_UNKNOWN'}
    $lane=$matched[0]
    Add-Report "AUTO_SELECTED_BRANCH: $($lane.lane)"
    Add-Report "TARGET_LOCAL: $($lane.version)"
    if ([string]::IsNullOrEmpty($id.maintenance_supervisor_version) -or
        [version]$id.maintenance_supervisor_version -lt [version]'0.3.19' -or
        [string]::IsNullOrEmpty($id.update_engine_version) -or
        [version]$id.update_engine_version -lt [version]'0.3.19'){Stop-Safe 'MAINTENANCE_VERSION_UNSUPPORTED'}
    $drives=Get-PSDrive -PSProvider FileSystem
    $installRoots=@($drives | ForEach-Object { Join-Path $_.Root 'SOKNA' })
    $roots=@($installRoots | Where-Object { Test-Path -LiteralPath (Join-Path $_ 'config.php') -PathType Leaf })
    if ($roots.Count -ne 1) { Stop-Safe 'SOKNA_INSTALL_ROOT_NOT_UNIQUE' }
    $installRoot=$roots[0]
    $phpRoot=Join-Path $installRoot 'Infrastructure'
    if (!(Test-Path -LiteralPath $phpRoot -PathType Container)) {Stop-Safe 'PHP_INFRASTRUCTURE_MISSING'}
    $php=Get-ChildItem -LiteralPath $phpRoot -Filter 'php.exe' -Recurse -File -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($null -eq $php) {Stop-Safe 'PHP_BINARY_MISSING'}
    $phpArgs=@()
    $ini=Join-Path $php.Directory.FullName 'php.ini'
    if (Test-Path -LiteralPath $ini -PathType Leaf){$phpArgs+=@('-c',$ini)}
    $phpArgs+=@('-f',(Join-Path $here 'HOME-READONLY-PREFLIGHT.php'),'--',(Join-Path $installRoot 'config.php'),[string]$local.migration_head,[string]$lane.lane)
    $dbOut=& $php.FullName @phpArgs 2>&1
    foreach($line in @($dbOut)){ Add-Report ([string]$line) }
    if ($LASTEXITCODE -ne 0 -or -not (@($dbOut) | Where-Object { [string]$_ -match '^DB_PREFLIGHT_PASS' })) {Stop-Safe 'DATABASE_PREFLIGHT_REJECTED'}
    $localZip=Join-Path $here $lane.zip
    $publicZip=Join-Path $here $idx.public.zip
    Sha-Check $localZip $lane.zip_sha256
    Sha-Check $publicZip $idx.public.sha256
    # Only copies already qualified package bytes. It does not install or schedule an update.
    Copy-Item -LiteralPath $localZip -Destination $selectedLocal
    Copy-Item -LiteralPath $publicZip -Destination $selectedPublic
    Add-Report 'READY_FOR_HOME_UAT_UPLOAD: YES'
    Add-Report "SELECTED_LOCAL_FILE: $(Split-Path -Leaf $selectedLocal)"
    Add-Report "SELECTED_PUBLIC_FILE: $(Split-Path -Leaf $selectedPublic)"
    Add-Report 'CAFE_RECOVERY: NOT_ATTEMPTED'
    $exitCode=0
} catch {
    Add-Report 'READY_FOR_HOME_UAT_UPLOAD: NO'
    Remove-Item -LiteralPath $selectedLocal,$selectedPublic -ErrorAction SilentlyContinue
    if ($_.Exception.Message -ne 'R126_FAIL_CLOSED') {Add-Report 'BLOCKED: READ_ONLY_CHECK_ERROR'}
    $exitCode=21
} finally {
    [System.IO.File]::WriteAllLines($report,$lines,(New-Object System.Text.UTF8Encoding($false)))
}
exit $exitCode