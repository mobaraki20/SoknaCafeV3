param(
    [Parameter(Mandatory=$true)][string]$RepoRoot,
    [Parameter(Mandatory=$true)][string]$OutputRoot,
    [Parameter(Mandatory=$true)][string]$ServiceHostExe,
    [Parameter(Mandatory=$true)][string]$SetupHostExe,
    [Parameter(Mandatory=$true)][string]$SetupUiExe,
    [Parameter(Mandatory=$true)][string]$PrintWorkerBundle,
    [string]$PrerequisiteBundleRoot = '',
    [string]$GitSha = ''
)
$ErrorActionPreference='Stop'
$RepoRoot=[IO.Path]::GetFullPath($RepoRoot).TrimEnd('\')
$OutputRoot=[IO.Path]::GetFullPath($OutputRoot).TrimEnd('\')
if(Test-Path $OutputRoot){Remove-Item $OutputRoot -Recurse -Force}
New-Item -ItemType Directory -Path $OutputRoot -Force|Out-Null
$version=(Get-Content (Join-Path $RepoRoot 'VERSION.txt') -Raw).Trim()
if(-not $GitSha){try{$GitSha=(& git -C $RepoRoot rev-parse HEAD 2>$null).Trim()}catch{$GitSha='unknown'}}

# Installer-owned immutable shell. Do not copy config.php/install.lock/storage/uploads or a live app tree here.
$owned=@(
    'runtime\windows\setup-sokna.ps1',
    'runtime\windows\setup-support.psm1',
    'runtime\windows\provision-local-https.ps1',
    'runtime\windows\configure-apache.ps1',
    'runtime\windows\apache\sokna-local-https.conf.template',
    'runtime\windows\prerequisites.json',
    'installer\windows\scripts\deploy-seed.ps1',
    'installer\windows\scripts\collect-support.ps1',
    'installer\windows\scripts\verify-prerequisite-bundle.ps1',
    'installer\windows\assets\Sokna.ico'
)
foreach($rel in $owned){
    $src=Join-Path $RepoRoot $rel
    if(-not(Test-Path $src -PathType Leaf)){throw "Missing installer-owned source: $rel"}
    $dst=Join-Path $OutputRoot ([IO.Path]::GetFileName($src))
    Copy-Item $src $dst -Force
}
Copy-Item $ServiceHostExe (Join-Path $OutputRoot 'SoknaRuntimeService.exe') -Force
Copy-Item $SetupHostExe (Join-Path $OutputRoot 'SoknaSetupHost.exe') -Force
Copy-Item $SetupUiExe (Join-Path $OutputRoot 'SoknaSetupUi.exe') -Force
Copy-Item $PrintWorkerBundle (Join-Path $OutputRoot 'print-worker') -Recurse -Force

if(-not [string]::IsNullOrWhiteSpace($PrerequisiteBundleRoot)){
    $PrerequisiteBundleRoot=[IO.Path]::GetFullPath($PrerequisiteBundleRoot).TrimEnd('\')
    $verifyBundle=Join-Path $RepoRoot 'installer\windows\scripts\verify-prerequisite-bundle.ps1'
    & $verifyBundle -BundleRoot $PrerequisiteBundleRoot -ExpectedAppVersion $version
    # The PowerShell verifier throws on failure; LASTEXITCODE belongs to native commands.
    Copy-Item -LiteralPath $PrerequisiteBundleRoot -Destination (Join-Path $OutputRoot 'Prerequisites') -Recurse -Force
}

# The seed is a cache artifact for New/Recover only. MSI never installs these files into the live AppRoot.
$seedStage=Join-Path ([IO.Path]::GetTempPath()) ('sokna-seed-'+[guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory $seedStage|Out-Null
try{
    $excludeTop=@('.git','storage','uploads','installer')
    # Package tracked source only. CI builds leave bin/obj outputs under runtime;
    # copying the workspace recursively would duplicate generated binaries in the app seed.
    $tracked = @(& git -C $RepoRoot -c core.quotepath=false ls-files --cached)
    if ($LASTEXITCODE -ne 0 -or $tracked.Count -eq 0) { throw 'Cannot enumerate tracked application seed files.' }
    foreach ($relative in $tracked) {
        $top = ($relative -split '/')[0]
        if ($excludeTop -contains $top -or $relative -in @('config.php','install.lock')) { continue }
        $source = Join-Path $RepoRoot $relative
        if (-not (Test-Path -LiteralPath $source -PathType Leaf)) { throw "Tracked seed source is missing: $relative" }
        $destination = Join-Path $seedStage $relative
        New-Item -ItemType Directory -Path ([IO.Path]::GetDirectoryName($destination)) -Force | Out-Null
        Copy-Item -LiteralPath $source -Destination $destination -Force
    }
    $seed=Join-Path $OutputRoot 'SoknaAppPayload.zip'
    Compress-Archive -Path (Join-Path $seedStage '*') -DestinationPath $seed -CompressionLevel Optimal
}finally{Remove-Item $seedStage -Recurse -Force -ErrorAction SilentlyContinue}

$files=@()
Get-ChildItem $OutputRoot -File -Recurse|ForEach-Object{
    $files += [ordered]@{path=$_.FullName.Substring($OutputRoot.Length+1).Replace('\','/');size=$_.Length;sha256=(Get-FileHash $_.FullName -Algorithm SHA256).Hash.ToLowerInvariant()}
}
$manifest=[ordered]@{
    format='sokna-windows-shell-payload-v1';
    app_version=$version;
    source_git_sha=$GitSha;
    ownership='msi-shell-cache-only';
    live_app_owner='sokna-updater';
    print_worker_owner='sokna-local-internal';
    files=$files
}
$manifest|ConvertTo-Json -Depth 8|Set-Content (Join-Path $OutputRoot 'payload-manifest.json') -Encoding UTF8
Write-Host "Prepared SOKNA installer shell payload for $version ($GitSha)"
