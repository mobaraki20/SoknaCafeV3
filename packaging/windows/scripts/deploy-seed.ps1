param(
    [ValidateSet('New','Recover')][string]$Mode,
    [Parameter(Mandatory=$true)][string]$ShellRoot,
    [Parameter(Mandatory=$true)][string]$AppRoot,
    [Parameter(Mandatory=$true)][string]$DataRoot,
    [Parameter(Mandatory=$true)][string]$PhpExe,
    [Parameter(Mandatory=$true)][string]$SetupConfigFile,
    [string]$OpenSslExe = '',
    [string]$WebServerExe = '',
    [string]$Hostname = 'sokna.local',
    [string]$RecoveryFile = '',
    [string]$RecoveryPassphraseFile = '',
    [switch]$RequireWebServerPreflight,
    [switch]$SkipHttps,
    [switch]$SkipService
)
$ErrorActionPreference='Stop'

function Assert-SafeTarget([string]$Path) {
    $full=[IO.Path]::GetFullPath($Path).TrimEnd('\')
    $cursor=$full
    while($cursor){
        if(Test-Path -LiteralPath $cursor){
            if((Get-Item -Force -LiteralPath $cursor).Attributes -band [IO.FileAttributes]::ReparsePoint){ throw 'Seed deployment refuses a reparse-point path.' }
        }
        $parent=[IO.Directory]::GetParent($cursor)
        if($null -eq $parent){break}
        $cursor=$parent.FullName
    }
    return $full
}

function Assert-EmptyInstallTarget([string]$Path) {
    if(-not(Test-Path -LiteralPath $Path)){return}
    if(-not(Test-Path -LiteralPath $Path -PathType Container)){throw 'Application target exists but is not a directory.'}
    if(@(Get-ChildItem -LiteralPath $Path -Force).Count -gt 0){throw 'New/Recover requires an empty application target.'}
}

function Test-ManifestFile([string]$Root,[object]$Entry) {
    $raw=([string]$Entry.path).Trim()
    $rel=$raw.Replace('/','\')
    if([string]::IsNullOrWhiteSpace($rel) -or [IO.Path]::IsPathRooted($rel)){throw "Installer payload manifest path is invalid: $raw"}
    $prefix=[IO.Path]::GetFullPath($Root).TrimEnd('\')+'\'
    $file=[IO.Path]::GetFullPath((Join-Path $Root $rel))
    if(-not $file.StartsWith($prefix,[StringComparison]::OrdinalIgnoreCase)){throw "Installer payload manifest path escapes shell root: $raw"}
    if(-not(Test-Path -LiteralPath $file -PathType Leaf)){throw "Installer payload file is missing: $raw"}
    if((Get-Item -LiteralPath $file).Length -ne [int64]$Entry.size){throw "Installer payload size mismatch: $raw"}
    $hash=([string]$Entry.sha256).Trim().ToLowerInvariant()
    if($hash -notmatch '^[a-f0-9]{64}$'){throw "Installer payload hash is invalid: $raw"}
    if((Get-FileHash -LiteralPath $file -Algorithm SHA256).Hash.ToLowerInvariant() -ne $hash){throw "Installer payload hash mismatch: $raw"}
    return $file
}

function Expand-SoknaSeedSecure([string]$ZipPath,[string]$Destination) {
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $dest=[IO.Path]::GetFullPath($Destination).TrimEnd('\')
    [IO.Directory]::CreateDirectory($dest)|Out-Null
    $prefix=$dest+'\'
    $archive=[IO.Compression.ZipFile]::OpenRead($ZipPath)
    try{
        foreach($entry in $archive.Entries){
            $name=([string]$entry.FullName).Replace('/','\')
            if([string]::IsNullOrWhiteSpace($name)){continue}
            if([IO.Path]::IsPathRooted($name)){throw 'Seed archive contains an absolute path.'}
            $target=[IO.Path]::GetFullPath((Join-Path $dest $name))
            if(-not $target.StartsWith($prefix,[StringComparison]::OrdinalIgnoreCase)){throw 'Seed archive contains a path traversal entry.'}
            if($name.EndsWith('\')){[IO.Directory]::CreateDirectory($target)|Out-Null;continue}
            [IO.Directory]::CreateDirectory([IO.Path]::GetDirectoryName($target))|Out-Null
            [IO.Compression.ZipFileExtensions]::ExtractToFile($entry,$target,$false)
        }
    } finally {$archive.Dispose()}
}

function Invoke-SetupChild([string]$SetupScript,[string[]]$Arguments,[int[]]$SuccessCodes=@(0)) {
    $hostExe=(Get-Process -Id $PID -ErrorAction Stop).Path
    $all=@('-NoProfile','-ExecutionPolicy','Bypass','-File',$SetupScript)+$Arguments
    $output=@(& $hostExe @all 2>&1)
    $code=[int]$LASTEXITCODE
    foreach($line in $output){Write-Host ([string]$line)}
    if($SuccessCodes -notcontains $code){throw "SOKNA setup child failed with exit code $code."}
    return $code
}

$ShellRoot=Assert-SafeTarget $ShellRoot
$AppRoot=Assert-SafeTarget $AppRoot
$DataRoot=Assert-SafeTarget $DataRoot
Assert-EmptyInstallTarget $AppRoot
if(-not(Test-Path -LiteralPath $SetupConfigFile -PathType Leaf)){throw 'Setup config file is required.'}
if($Mode -eq 'Recover' -and -not(Test-Path -LiteralPath $RecoveryFile -PathType Leaf)){throw 'Recovery Set file is required.'}

$manifestPath=Join-Path $ShellRoot 'payload-manifest.json'
if(-not(Test-Path -LiteralPath $manifestPath -PathType Leaf)){throw 'Installer payload manifest is missing.'}
$manifest=Get-Content -LiteralPath $manifestPath -Raw | ConvertFrom-Json
if([string]$manifest.format -ne 'sokna-windows-shell-payload-v1'){throw 'Installer payload manifest format is unsupported.'}
if([string]$manifest.ownership -ne 'msi-shell-cache-only' -or [string]$manifest.live_app_owner -ne 'sokna-updater'){throw 'Installer payload ownership contract is invalid.'}
$entries=@($manifest.files)
if($entries.Count -lt 1){throw 'Installer payload manifest is empty.'}
$seen=@{}
$seed=''
foreach($entry in $entries){
    $key=([string]$entry.path).Replace('\','/').ToLowerInvariant()
    if($seen.ContainsKey($key)){throw "Installer payload manifest contains duplicate path: $key"}
    $seen[$key]=$true
    $verified=Test-ManifestFile $ShellRoot $entry
    if($key -eq 'soknaapppayload.zip'){$seed=$verified}
}
# Exact-set verification: an installer-owned executable/script must never be consumed unless it is covered by the signed/hash manifest.
$rootPrefix=[IO.Path]::GetFullPath($ShellRoot).TrimEnd('\')+'\'
$actual=@(Get-ChildItem -LiteralPath $ShellRoot -File -Recurse -Force | Where-Object { $_.FullName -ne $manifestPath } | ForEach-Object {
    $_.FullName.Substring($rootPrefix.Length).Replace('\','/').ToLowerInvariant()
})
if($actual.Count -ne $seen.Count){throw 'Installer payload contains files that are missing from the verified manifest.'}
foreach($key in $actual){if(-not $seen.ContainsKey($key)){throw "Installer payload contains an unmanifested file: $key"}}
if(-not $seed){throw 'Installer seed package is missing from the verified manifest.'}

$setupScript=Join-Path $ShellRoot 'setup-sokna.ps1'
$serviceHost=Join-Path $ShellRoot 'SoknaRuntimeService.exe'
$printWorker=Join-Path $ShellRoot 'print-worker'
foreach($required in @($setupScript,(Join-Path $ShellRoot 'setup-support.psm1'),(Join-Path $ShellRoot 'provision-local-https.ps1'),$serviceHost)){
    if(-not(Test-Path -LiteralPath $required)){throw "Required installer shell file is missing: $required"}
}
if(-not(Test-Path -LiteralPath $printWorker -PathType Container)){throw 'Internal Print Worker bundle is missing.'}

$staging=Join-Path $env:TEMP ('SOKNA-seed-'+[guid]::NewGuid().ToString('N'))
$targetExisted=Test-Path -LiteralPath $AppRoot
$createdTarget=$false
try{
    Expand-SoknaSeedSecure $seed $staging
    $stagedVersion=(Get-Content -LiteralPath (Join-Path $staging 'VERSION.txt') -Raw).Trim()
    if($stagedVersion -ne [string]$manifest.app_version){throw 'Seed version does not match installer payload manifest.'}
    foreach($required in @('tools\setup-machine.php','runtime\sokna-runtime.php','database\schema.sql')){
        if(-not(Test-Path -LiteralPath (Join-Path $staging $required) -PathType Leaf)){throw "Seed is missing required application file: $required"}
    }

    # Preflight runs against the staged app and canonical business setup owner before target mutation.
    $common=@(
        '-Mode',$Mode,
        '-PreflightOnly',
        '-AppRoot',$staging,
        '-DataRoot',$DataRoot,
        '-PhpExe',$PhpExe,
        '-SetupConfigFile',$SetupConfigFile,
        '-ServiceHostExe',$serviceHost,
        '-PrintWorkerBundle',$printWorker,
        '-Hostname',$Hostname
    )
    if($OpenSslExe){$common+=@('-OpenSslExe',$OpenSslExe)}
    if($WebServerExe){$common+=@('-WebServerExe',$WebServerExe)}
    if($RequireWebServerPreflight){$common+='-RequireWebServerPreflight'}
    if($SkipHttps){$common+='-SkipHttps'}
    if($SkipService){$common+='-SkipService'}
    if($Mode -eq 'Recover'){
        $common+=@('-RecoveryFile',$RecoveryFile)
        if($RecoveryPassphraseFile){$common+=@('-RecoveryPassphraseFile',$RecoveryPassphraseFile)}
    }
    $preflightExit=Invoke-SetupChild $setupScript $common @(0,3010)
    if($preflightExit -eq 3010){Write-Host 'SOKNA setup requires Windows reboot before deployment.';exit 3010}

    # Target is still required to be empty immediately before deploy to close the preflight/deploy race.
    Assert-EmptyInstallTarget $AppRoot
    if(-not(Test-Path -LiteralPath $AppRoot)){
        [IO.Directory]::CreateDirectory($AppRoot)|Out-Null
        $createdTarget=$true
    }
    Get-ChildItem -LiteralPath $staging -Force|ForEach-Object{Copy-Item -LiteralPath $_.FullName -Destination (Join-Path $AppRoot $_.Name) -Recurse -Force}

    $run=@(
        '-Mode',$Mode,
        '-AppRoot',$AppRoot,
        '-DataRoot',$DataRoot,
        '-PhpExe',$PhpExe,
        '-SetupConfigFile',$SetupConfigFile,
        '-ServiceHostExe',$serviceHost,
        '-PrintWorkerBundle',$printWorker,
        '-Hostname',$Hostname
    )
    if($OpenSslExe){$run+=@('-OpenSslExe',$OpenSslExe)}
    if($WebServerExe){$run+=@('-WebServerExe',$WebServerExe)}
    if($RequireWebServerPreflight){$run+='-RequireWebServerPreflight'}
    if($SkipHttps){$run+='-SkipHttps'}
    if($SkipService){$run+='-SkipService'}
    if($Mode -eq 'Recover'){
        $run+=@('-RecoveryFile',$RecoveryFile)
        if($RecoveryPassphraseFile){$run+=@('-RecoveryPassphraseFile',$RecoveryPassphraseFile)}
    }
    # New/Recover can legitimately commit business state and then stop at the external
    # Apache ownership boundary. Preserve the canonical setup exit code so Setup Host/UI
    # can present the required Reload/Repair action instead of collapsing it to code 1.
    $finalExit=Invoke-SetupChild (Join-Path $AppRoot 'runtime\windows\setup-sokna.ps1') $run @(0,20,21,3010)
    Write-Host "SOKNA $Mode deployment orchestration finished with exit code $finalExit."
    if($finalExit -in @(20,21,3010)){exit $finalExit}
} catch {
    # Roll back only the application files created by this deployer and only while business setup has not persisted its markers.
    if((Test-Path -LiteralPath $AppRoot -PathType Container) -and
       -not(Test-Path -LiteralPath (Join-Path $AppRoot 'config.php')) -and
       -not(Test-Path -LiteralPath (Join-Path $AppRoot 'install.lock'))){
        # Remove only paths that originated from this verified seed. Never recurse-delete unknown target content.
        if(Test-Path -LiteralPath $staging -PathType Container){
            $seedFiles=@(Get-ChildItem -LiteralPath $staging -File -Recurse -Force | Sort-Object FullName -Descending)
            foreach($sourceFile in $seedFiles){
                $rel=$sourceFile.FullName.Substring($staging.TrimEnd('\').Length+1)
                $targetFile=Join-Path $AppRoot $rel
                if(Test-Path -LiteralPath $targetFile -PathType Leaf){Remove-Item -LiteralPath $targetFile -Force -ErrorAction SilentlyContinue}
            }
            $seedDirs=@(Get-ChildItem -LiteralPath $staging -Directory -Recurse -Force | Sort-Object { $_.FullName.Length } -Descending)
            foreach($sourceDir in $seedDirs){
                $rel=$sourceDir.FullName.Substring($staging.TrimEnd('\').Length+1)
                $targetDir=Join-Path $AppRoot $rel
                if((Test-Path -LiteralPath $targetDir -PathType Container) -and @(Get-ChildItem -LiteralPath $targetDir -Force).Count -eq 0){Remove-Item -LiteralPath $targetDir -Force -ErrorAction SilentlyContinue}
            }
        }
        if($createdTarget -and @(Get-ChildItem -LiteralPath $AppRoot -Force).Count -eq 0){Remove-Item -LiteralPath $AppRoot -Force -ErrorAction SilentlyContinue}
    }
    throw
} finally {
    Remove-Item -LiteralPath $staging -Recurse -Force -ErrorAction SilentlyContinue
}
