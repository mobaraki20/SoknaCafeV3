param(
    [Parameter(Mandatory=$true)][string]$BundleRoot,
    [string]$ExpectedAppVersion = ''
)
$ErrorActionPreference='Stop'
$BundleRoot=[IO.Path]::GetFullPath($BundleRoot)
function Assert-Signature([string]$Path,$Policy){
    if(-not [bool]$Policy.required){return}
    $sig=Get-AuthenticodeSignature -LiteralPath $Path
    if($sig.Status -ne 'Valid'){throw "Prerequisite Authenticode signature is not valid: $([IO.Path]::GetFileName($Path))"}
    $publisher=[string]$Policy.publisher_contains
    if([string]::IsNullOrWhiteSpace($publisher)){throw 'Authenticode-required artifact must freeze publisher_contains.'}
    if(([string]$sig.SignerCertificate.Subject).IndexOf($publisher,[StringComparison]::OrdinalIgnoreCase) -lt 0){throw 'Prerequisite signer does not match the frozen publisher contract.'}
}

$manifestPath=Join-Path $BundleRoot 'bundle-manifest.json'
$lockPath=Join-Path $BundleRoot 'release-lock.json'
if(-not(Test-Path -LiteralPath $manifestPath -PathType Leaf) -or -not(Test-Path -LiteralPath $lockPath -PathType Leaf)){throw 'Prerequisite bundle manifest/lock is missing.'}
$manifest=Get-Content -LiteralPath $manifestPath -Raw|ConvertFrom-Json
$lock=Get-Content -LiteralPath $lockPath -Raw|ConvertFrom-Json
if([string]$manifest.format -ne 'sokna-windows-prerequisite-bundle-v1' -or [int]$manifest.schema_version -ne 1){throw 'Prerequisite bundle manifest is unsupported.'}
if([string]$manifest.ownership -ne 'verified-offline-cache-only' -or [string]$manifest.shared_dependency_owner -ne 'external' -or [bool]$manifest.automatic_install){throw 'Prerequisite bundle ownership contract is invalid.'}
if([string]$lock.format -ne 'sokna-windows-prerequisite-lock-v1' -or [int]$lock.schema_version -ne 1 -or -not [bool]$lock.release_frozen){throw 'Prerequisite release lock is not frozen.'}

$lockAppVersion=([string]$lock.app_version).Trim()
$manifestAppVersion=([string]$manifest.app_version).Trim()
if([string]::IsNullOrWhiteSpace($lockAppVersion) -or $lockAppVersion -match '^REPLACE_'){throw 'Prerequisite release lock app_version is not frozen.'}
if($manifestAppVersion -ne $lockAppVersion){throw 'Prerequisite bundle app_version does not match the frozen release lock.'}
if(-not [string]::IsNullOrWhiteSpace($ExpectedAppVersion) -and $manifestAppVersion -ne $ExpectedAppVersion.Trim()){throw "Prerequisite bundle app_version does not match expected SOKNA version: $ExpectedAppVersion"}

$lockById=@{}
$lockFilenames=@{}
foreach($artifact in @($lock.artifacts)){
    $id=([string]$artifact.id).Trim()
    $filename=[string]$artifact.filename
    if([string]::IsNullOrWhiteSpace($id) -or $lockById.ContainsKey($id)){throw 'Prerequisite release lock artifact IDs must be non-empty and unique.'}
    if([IO.Path]::GetFileName($filename) -ne $filename){throw 'Prerequisite release lock path is unsafe.'}
    if($lockFilenames.ContainsKey($filename)){throw 'Prerequisite release lock artifact filenames must be unique.'}
    if(@('php','apache','openssl','mariadb','vc_runtime') -notcontains ([string]$artifact.dependency)){throw 'Prerequisite release lock dependency is unsupported.'}
    if([string]::IsNullOrWhiteSpace([string]$artifact.version) -or ([string]$artifact.version) -match '^REPLACE_'){throw 'Prerequisite release lock artifact version is not frozen.'}
    $uri=$null
    if(-not [Uri]::TryCreate([string]$artifact.source_url,[UriKind]::Absolute,[ref]$uri) -or $uri.Scheme -ne 'https'){throw 'Prerequisite release lock source URL must be absolute HTTPS.'}
    if(([string]$artifact.sha256) -notmatch '^[0-9a-fA-F]{64}$' -or [long]$artifact.size -le 0){throw 'Prerequisite release lock artifact hash/size is invalid.'}
    if($null -eq $artifact.authenticode){throw 'Prerequisite release lock must freeze Authenticode policy.'}
    if([bool]$artifact.authenticode.required -and [string]::IsNullOrWhiteSpace([string]$artifact.authenticode.publisher_contains)){throw 'Authenticode-required artifact must freeze publisher_contains.'}
    if([string]$artifact.installation -ne 'manual-external'){throw 'Prerequisite release lock ownership contract is invalid.'}
    $lockById[$id]=$artifact
    $lockFilenames[$filename]=$true
}
if($lockById.Count -lt 1){throw 'Prerequisite release lock contains no artifacts.'}

$manifestArtifacts=@($manifest.artifacts)
if($manifestArtifacts.Count -ne $lockById.Count){throw 'Prerequisite bundle artifact set does not match the frozen release lock.'}
$expected=@('bundle-manifest.json','release-lock.json')
$seenManifestIds=@{}
$seenManifestFilenames=@{}
foreach($artifact in $manifestArtifacts){
    $id=([string]$artifact.id).Trim()
    $filename=[string]$artifact.filename
    if([string]::IsNullOrWhiteSpace($id) -or $seenManifestIds.ContainsKey($id)){throw 'Prerequisite manifest artifact IDs must be non-empty and unique.'}
    if($null -eq $artifact.authenticode){throw 'Prerequisite manifest must preserve Authenticode policy.'}
    if([IO.Path]::GetFileName($filename) -ne $filename){throw 'Prerequisite manifest path is unsafe.'}
    if($seenManifestFilenames.ContainsKey($filename)){throw 'Prerequisite manifest artifact filenames must be unique.'}
    if(-not $lockById.ContainsKey($id)){throw "Prerequisite manifest artifact is not present in the frozen release lock: $id"}
    $frozen=$lockById[$id]
    if(
        [string]$artifact.dependency -ne [string]$frozen.dependency -or
        [string]$artifact.version -ne [string]$frozen.version -or
        $filename -ne [string]$frozen.filename -or
        [string]$artifact.source_url -ne [string]$frozen.source_url -or
        ([string]$artifact.sha256).ToLowerInvariant() -ne ([string]$frozen.sha256).ToLowerInvariant() -or
        [long]$artifact.size -ne [long]$frozen.size -or
        [string]$artifact.installation -ne [string]$frozen.installation -or
        [bool]$artifact.authenticode.required -ne [bool]$frozen.authenticode.required -or
        [string]$artifact.authenticode.publisher_contains -ne [string]$frozen.authenticode.publisher_contains -or
        [string]$artifact.instructions_fa -ne [string]$frozen.instructions_fa
    ){throw "Prerequisite manifest artifact does not match the frozen release lock: $id"}

    $path=Join-Path $BundleRoot $filename
    if(-not(Test-Path -LiteralPath $path -PathType Leaf)){throw "Prerequisite bundle artifact is missing: $filename"}
    $info=Get-Item -LiteralPath $path
    if([long]$info.Length -ne [long]$frozen.size){throw "Prerequisite bundle artifact size mismatch: $filename"}
    $hash=(Get-FileHash -LiteralPath $path -Algorithm SHA256).Hash.ToLowerInvariant()
    if($hash -ne ([string]$frozen.sha256).ToLowerInvariant()){throw "Prerequisite bundle artifact hash mismatch: $filename"}
    Assert-Signature $path $frozen.authenticode
    $expected += $filename
    $seenManifestIds[$id]=$true
    $seenManifestFilenames[$filename]=$true
}
$actual=@(Get-ChildItem -LiteralPath $BundleRoot -File|ForEach-Object{$_.Name})
if(Compare-Object ($expected|Sort-Object) ($actual|Sort-Object)){throw 'Prerequisite bundle contains untracked or missing files.'}
Write-Host "Verified SOKNA offline prerequisite bundle for $manifestAppVersion."
