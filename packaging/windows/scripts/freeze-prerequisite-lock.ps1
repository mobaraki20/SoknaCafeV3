param(
    [Parameter(Mandatory=$true)][string]$CandidateFile,
    [Parameter(Mandatory=$true)][string]$ArtifactRoot,
    [Parameter(Mandatory=$true)][string]$OutputLock,
    [string]$RepoRoot=(Resolve-Path (Join-Path $PSScriptRoot '..\..\..')).Path,
    [switch]$AllowDownload
)
$ErrorActionPreference='Stop'

function Assert-Https([string]$Url){
    $uri=$null
    if(-not [Uri]::TryCreate($Url,[UriKind]::Absolute,[ref]$uri) -or $uri.Scheme -ne 'https'){throw 'Prerequisite candidate source URL must be absolute HTTPS.'}
    if($uri.Fragment){throw 'Prerequisite candidate source URL must not contain a fragment.'}
}
function Assert-LeafName([string]$Name){
    if([string]::IsNullOrWhiteSpace($Name) -or [IO.Path]::GetFileName($Name) -ne $Name){throw 'Prerequisite candidate filename must be a leaf filename.'}
}
function Assert-HexSha([string]$Hash){if($Hash -notmatch '^[0-9a-fA-F]{64}$'){throw 'Prerequisite candidate expected SHA-256 must be 64 hexadecimal characters.'}}
function Test-Signature([string]$Path,$Policy){
    $result=[ordered]@{required=[bool]$Policy.required;status='not-required';subject=''}
    if(-not [bool]$Policy.required){return $result}
    $publisher=([string]$Policy.publisher_contains).Trim()
    if([string]::IsNullOrWhiteSpace($publisher)){throw 'Authenticode-required candidate must declare publisher_contains.'}
    $sig=Get-AuthenticodeSignature -LiteralPath $Path
    $result.status=[string]$sig.Status
    if($sig.SignerCertificate){$result.subject=[string]$sig.SignerCertificate.Subject}
    if($sig.Status -ne 'Valid'){throw "Prerequisite Authenticode signature is not valid: $([IO.Path]::GetFileName($Path))"}
    if($result.subject.IndexOf($publisher,[StringComparison]::OrdinalIgnoreCase) -lt 0){throw 'Prerequisite signer does not match candidate publisher policy.'}
    return $result
}
function Resolve-Version([string]$Path,$Artifact){
    $mode=([string]$Artifact.version_mode).Trim()
    if($mode -eq 'literal'){
        $v=([string]$Artifact.version).Trim()
        if([string]::IsNullOrWhiteSpace($v)){throw 'Literal prerequisite candidate version is empty.'}
        return $v
    }
    if($mode -eq 'file-product-version'){
        $v=([Diagnostics.FileVersionInfo]::GetVersionInfo($Path).ProductVersion).Trim()
        if([string]::IsNullOrWhiteSpace($v)){throw 'Could not extract exact ProductVersion from prerequisite artifact.'}
        return $v
    }
    throw "Unsupported prerequisite candidate version_mode: $mode"
}

$CandidateFile=[IO.Path]::GetFullPath($CandidateFile)
$ArtifactRoot=[IO.Path]::GetFullPath($ArtifactRoot)
$OutputLock=[IO.Path]::GetFullPath($OutputLock)
$RepoRoot=[IO.Path]::GetFullPath($RepoRoot)
if(-not(Test-Path -LiteralPath $CandidateFile -PathType Leaf)){throw 'Prerequisite provider candidate was not found.'}
$candidate=Get-Content -LiteralPath $CandidateFile -Raw|ConvertFrom-Json
if([string]$candidate.format -ne 'sokna-windows-prerequisite-candidate-v1' -or [int]$candidate.schema_version -ne 1){throw 'Prerequisite provider candidate format is unsupported.'}
if([bool]$candidate.release_frozen){throw 'Provider candidate must never claim release_frozen=true.'}
if([bool]$candidate.policy.automatic_install -or [string]$candidate.policy.shared_dependency_owner -ne 'external'){throw 'Provider candidate violates shared prerequisite ownership policy.'}
$appVersion=(Get-Content -LiteralPath (Join-Path $RepoRoot 'VERSION.txt') -Raw).Trim()
if(([string]$candidate.app_version).Trim() -ne $appVersion){throw 'Provider candidate app_version does not match VERSION.txt.'}
if(-not $candidate.artifacts -or @($candidate.artifacts).Count -lt 1){throw 'Provider candidate contains no artifacts.'}

New-Item -ItemType Directory -Path $ArtifactRoot -Force|Out-Null
$outDir=[IO.Path]::GetDirectoryName($OutputLock)
if($outDir){New-Item -ItemType Directory -Path $outDir -Force|Out-Null}
$allowed=@('php','apache','openssl','mariadb','vc_runtime')
$seenId=@{};$seenName=@{};$locked=@();$evidence=@()
foreach($artifact in @($candidate.artifacts)){
    $id=([string]$artifact.id).Trim();$dep=([string]$artifact.dependency).Trim();$name=([string]$artifact.filename).Trim();$url=([string]$artifact.source_url).Trim();$expected=([string]$artifact.expected_sha256).Trim().ToLowerInvariant()
    if([string]::IsNullOrWhiteSpace($id) -or $seenId.ContainsKey($id)){throw 'Prerequisite candidate IDs must be unique and non-empty.'}
    if($seenName.ContainsKey($name)){throw 'Prerequisite candidate filenames must be unique.'}
    if($allowed -notcontains $dep){throw "Unsupported prerequisite candidate dependency: $dep"}
    Assert-LeafName $name;Assert-Https $url;Assert-HexSha $expected
    $seenId[$id]=$true;$seenName[$name]=$true
    $path=Join-Path $ArtifactRoot $name
    $downloaded=$false
    if(-not(Test-Path -LiteralPath $path -PathType Leaf)){
        if(-not $AllowDownload){throw "Missing prerequisite candidate artifact: $name. Supply it offline or opt into release-engineering download."}
        $tmp=$path+'.download'
        Remove-Item -LiteralPath $tmp -Force -ErrorAction SilentlyContinue
        try{
            Write-Host "Verifying provider download: $id ($url)"
            try {
                # Windows inbox curl avoids PowerShell response-parser failures on some providers.
                # Refuse HTTPS downgrades on every redirect and retain the pinned hash gate below.
                $curl = Get-Command curl.exe -CommandType Application -ErrorAction SilentlyContinue | Select-Object -First 1
                if ($curl) {
                    & $curl.Source --fail --silent --show-error --location --proto '=https' --proto-redir '=https' --connect-timeout 30 --max-time 600 --retry 2 --output $tmp $url
                    if ($LASTEXITCODE -ne 0) { throw "HTTPS transport exited with code $LASTEXITCODE." }
                } else { Invoke-WebRequest -Uri $url -OutFile $tmp -UseBasicParsing }
            }
            catch { throw "Prerequisite download failed for ${id}: $($_.Exception.Message)" }
            Move-Item -LiteralPath $tmp -Destination $path -Force
            $downloaded=$true
        }finally{Remove-Item -LiteralPath $tmp -Force -ErrorAction SilentlyContinue}
    }
    $actual=(Get-FileHash -LiteralPath $path -Algorithm SHA256).Hash.ToLowerInvariant()
    if($actual -ne $expected){throw "Prerequisite provider hash mismatch: $name"}
    $info=Get-Item -LiteralPath $path
    if([long]$info.Length -le 0){throw "Prerequisite provider artifact is empty: $name"}
    $signature=Test-Signature $path $artifact.authenticode
    $version=Resolve-Version $path $artifact
    $locked += [ordered]@{
        id=$id;dependency=$dep;version=$version;filename=$name;source_url=$url;sha256=$actual;size=[long]$info.Length;
        installation='manual-external';authenticode=[ordered]@{required=[bool]$artifact.authenticode.required;publisher_contains=[string]$artifact.authenticode.publisher_contains};
        instructions_fa=[string]$artifact.instructions_fa
    }
    $evidence += [ordered]@{id=$id;filename=$name;downloaded=$downloaded;size=[long]$info.Length;sha256=$actual;version=$version;authenticode=$signature}
}
$candidateHash=(Get-FileHash -LiteralPath $CandidateFile -Algorithm SHA256).Hash.ToLowerInvariant()
$lock=[ordered]@{
    format='sokna-windows-prerequisite-lock-v1';schema_version=1;release_frozen=$true;app_version=$appVersion;
    source_candidate_sha256=$candidateHash;artifacts=$locked
}
$lock|ConvertTo-Json -Depth 10|Set-Content -LiteralPath $OutputLock -Encoding UTF8
$evidencePath=$OutputLock+'.evidence.json'
[ordered]@{
    format='sokna-windows-prerequisite-freeze-evidence-v1';schema_version=1;app_version=$appVersion;
    source_candidate_sha256=$candidateHash;generated_at_utc=[DateTime]::UtcNow.ToString('o');host_os=[Environment]::OSVersion.VersionString;artifacts=$evidence
}|ConvertTo-Json -Depth 10|Set-Content -LiteralPath $evidencePath -Encoding UTF8
Write-Host "Frozen prerequisite lock generated for $appVersion. Review and commit release-lock.json only after this evidence is accepted."
Write-Host "Lock: $OutputLock"
Write-Host "Evidence: $evidencePath"
