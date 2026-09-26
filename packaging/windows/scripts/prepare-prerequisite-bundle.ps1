param(
    [Parameter(Mandatory=$true)][string]$LockFile,
    [Parameter(Mandatory=$true)][string]$ArtifactRoot,
    [Parameter(Mandatory=$true)][string]$OutputRoot,
    [switch]$AllowDownload
)
$ErrorActionPreference='Stop'

function Read-Lock([string]$Path){
    if(-not(Test-Path -LiteralPath $Path -PathType Leaf)){throw 'Prerequisite release lock was not found.'}
    $lock=Get-Content -LiteralPath $Path -Raw | ConvertFrom-Json
    if([string]$lock.format -ne 'sokna-windows-prerequisite-lock-v1' -or [int]$lock.schema_version -ne 1){throw 'Prerequisite release lock format is unsupported.'}
    if(-not [bool]$lock.release_frozen){throw 'Prerequisite release lock is not frozen; release acquisition is forbidden.'}
    $appVersion=([string]$lock.app_version).Trim()
    if([string]::IsNullOrWhiteSpace($appVersion) -or $appVersion -match '^REPLACE_'){throw 'Frozen prerequisite lock must contain the exact SOKNA app_version.'}
    if(-not $lock.artifacts -or @($lock.artifacts).Count -lt 1){throw 'Frozen prerequisite lock contains no artifacts.'}
    return $lock
}
function Assert-LeafName([string]$Name){
    if([string]::IsNullOrWhiteSpace($Name) -or [IO.Path]::GetFileName($Name) -ne $Name){throw 'Prerequisite artifact filename must be a leaf filename.'}
}
function Assert-Https([string]$Url){
    $uri=$null
    if(-not [Uri]::TryCreate($Url,[UriKind]::Absolute,[ref]$uri) -or $uri.Scheme -ne 'https'){throw 'Prerequisite source URL must be absolute HTTPS.'}
    if($uri.Fragment){throw 'Prerequisite source URL must not contain a fragment.'}
}
function Assert-HexSha([string]$Hash){if($Hash -notmatch '^[0-9a-fA-F]{64}$'){throw 'Prerequisite SHA-256 must be 64 hexadecimal characters.'}}
function Assert-Signature([string]$Path,$Policy){
    if(-not [bool]$Policy.required){return}
    $sig=Get-AuthenticodeSignature -LiteralPath $Path
    if($sig.Status -ne 'Valid'){throw "Prerequisite Authenticode signature is not valid: $([IO.Path]::GetFileName($Path))"}
    $publisher=[string]$Policy.publisher_contains
    if([string]::IsNullOrWhiteSpace($publisher)){throw 'Authenticode-required artifact must freeze publisher_contains.'}
    if(([string]$sig.SignerCertificate.Subject).IndexOf($publisher,[StringComparison]::OrdinalIgnoreCase) -lt 0){throw 'Prerequisite signer does not match the frozen publisher contract.'}
}

$lock=Read-Lock $LockFile
$ArtifactRoot=[IO.Path]::GetFullPath($ArtifactRoot)
$OutputRoot=[IO.Path]::GetFullPath($OutputRoot)
if(Test-Path -LiteralPath $OutputRoot){Remove-Item -LiteralPath $OutputRoot -Recurse -Force}
New-Item -ItemType Directory -Path $OutputRoot -Force|Out-Null
$seen=@{}
$seenFilenames=@{}
$allowedDependencies=@('php','apache','openssl','mariadb','vc_runtime')
$bundleArtifacts=@()
foreach($artifact in @($lock.artifacts)){
    $id=[string]$artifact.id;$filename=[string]$artifact.filename;$url=[string]$artifact.source_url;$sha=([string]$artifact.sha256).ToLowerInvariant()
    if([string]::IsNullOrWhiteSpace($id) -or $seen.ContainsKey($id)){throw 'Prerequisite artifact IDs must be non-empty and unique.'}
    if($seenFilenames.ContainsKey($filename)){throw 'Prerequisite artifact filenames must be unique.'}
    $seen[$id]=$true;$seenFilenames[$filename]=$true
    Assert-LeafName $filename;Assert-Https $url;Assert-HexSha $sha
    if($allowedDependencies -notcontains ([string]$artifact.dependency)){throw 'Prerequisite dependency is outside the frozen supported set.'}
    if([string]::IsNullOrWhiteSpace([string]$artifact.version) -or ([string]$artifact.version) -match '^REPLACE_'){throw 'Prerequisite artifact version must be exact and frozen.'}
    if([long]$artifact.size -le 0){throw 'Prerequisite artifact size must be positive and frozen.'}
    if([string]$artifact.installation -ne 'manual-external'){throw 'Current SOKNA prerequisite bundle permits only manual-external shared dependency ownership.'}
    $source=Join-Path $ArtifactRoot $filename
    if(-not(Test-Path -LiteralPath $source -PathType Leaf)){
        if(-not $AllowDownload){throw "Missing frozen prerequisite artifact: $filename. Supply it offline or opt into release-build HTTPS acquisition."}
        New-Item -ItemType Directory -Path $ArtifactRoot -Force|Out-Null
        $tmp=$source+'.download'
        Remove-Item -LiteralPath $tmp -Force -ErrorAction SilentlyContinue
        try{
            $curl = Get-Command curl.exe -CommandType Application -ErrorAction SilentlyContinue | Select-Object -First 1
            if ($curl) {
                & $curl.Source --fail --silent --show-error --location --proto '=https' --proto-redir '=https' --connect-timeout 30 --max-time 600 --retry 2 --output $tmp $url
                if ($LASTEXITCODE -ne 0) { throw "Prerequisite HTTPS download failed: $name" }
            } else { Invoke-WebRequest -Uri $url -OutFile $tmp -UseBasicParsing }
            Move-Item -LiteralPath $tmp -Destination $source -Force
        }finally{Remove-Item -LiteralPath $tmp -Force -ErrorAction SilentlyContinue}
    }
    $info=Get-Item -LiteralPath $source
    if([long]$info.Length -ne [long]$artifact.size){throw "Prerequisite artifact size mismatch: $filename"}
    $actual=(Get-FileHash -LiteralPath $source -Algorithm SHA256).Hash.ToLowerInvariant()
    if($actual -ne $sha){throw "Prerequisite artifact SHA-256 mismatch: $filename"}
    Assert-Signature $source $artifact.authenticode
    Copy-Item -LiteralPath $source -Destination (Join-Path $OutputRoot $filename) -Force
    $bundleArtifacts += [ordered]@{
        id=$id;dependency=[string]$artifact.dependency;version=[string]$artifact.version;filename=$filename;
        source_url=$url;sha256=$sha;size=[long]$artifact.size;installation='manual-external';
        authenticode=[ordered]@{required=[bool]$artifact.authenticode.required;publisher_contains=[string]$artifact.authenticode.publisher_contains};
        instructions_fa=[string]$artifact.instructions_fa
    }
}
Copy-Item -LiteralPath $LockFile -Destination (Join-Path $OutputRoot 'release-lock.json') -Force
$manifest=[ordered]@{
    format='sokna-windows-prerequisite-bundle-v1';schema_version=1;app_version=[string]$lock.app_version;
    ownership='verified-offline-cache-only';shared_dependency_owner='external';automatic_install=$false;artifacts=$bundleArtifacts
}
$manifest|ConvertTo-Json -Depth 8|Set-Content (Join-Path $OutputRoot 'bundle-manifest.json') -Encoding UTF8
Write-Host "Prepared verified offline prerequisite bundle with $($bundleArtifacts.Count) artifact(s)."
