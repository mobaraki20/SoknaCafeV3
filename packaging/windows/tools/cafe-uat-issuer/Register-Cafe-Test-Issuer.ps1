# SOKNA Cafe Maintenance V4, one-time TEST signing trust enrollment.
# Windows PowerShell 5.1 / Windows 10+, must run elevated. NO PRIVATE KEY EXPORT.
# Writes ONLY Windows Certificate Store nonexportable key, public SPKI trust and public metadata.
[CmdletBinding()]
param()
Set-StrictMode -Version 2.0
$ErrorActionPreference = 'Stop'
$Subject = 'CN=SOKNA Cafe V4 UAT Local Release Signing'
$MachineStore = 'Cert:\LocalMachine\My'
$root = Join-Path ([Environment]::GetFolderPath('CommonApplicationData')) 'SOKNA\Maintenance'
$trustRoot = Join-Path $root 'trust\release-signing'
$issuerRoot = Join-Path $root 'release-issuer'
$issuerRecordPath = Join-Path $issuerRoot 'cafe-local-uat-issuer-v1.json'
$ExpectedLocalBuild = '3.0.0-g6.2-workspace.231ap-connections-device-lifecycle-closure'

function Fail([string]$message) { throw ('SOKNA_UAT_SIGNING_ENROLLMENT: ' + $message) }
function ShaHex([byte[]]$data) {
    $h = [System.Security.Cryptography.SHA256]::Create()
    try { return ([BitConverter]::ToString($h.ComputeHash($data))).Replace('-', '').ToLowerInvariant() }
    finally { $h.Dispose() }
}
function HexBytes([string]$hex) {
    $bytes = [byte[]]::new([int]($hex.Length / 2))
    for ($i=0; $i -lt $bytes.Length; $i++) {
        $bytes[$i] = [Convert]::ToByte($hex.Substring($i*2, 2), 16)
    }
    return ,$bytes
}
function WriteUtf8Atomic([string]$path, [string]$content) {
    $temp = $path + '.new.' + [Guid]::NewGuid().ToString('N')
    try {
        [IO.File]::WriteAllText($temp, $content, (New-Object System.Text.UTF8Encoding($false)))
        if (Test-Path -LiteralPath $path) { Fail ('Existing file cannot be replaced: ' + [IO.Path]::GetFileName($path)) }
        [IO.File]::Move($temp, $path)
    } finally { if (Test-Path -LiteralPath $temp) { Remove-Item -LiteralPath $temp -Force } }
}
function IsReparse([string]$p) {
    if (-not (Test-Path -LiteralPath $p)) { return $false }
    return [bool]((Get-Item -LiteralPath $p -Force).Attributes -band [IO.FileAttributes]::ReparsePoint)
}
function RequireSafeDirectory([string]$p) {
    $full = [IO.Path]::GetFullPath($p)
    $walk = $full
    while ($walk) {
        if (IsReparse $walk) { Fail ('Refusing reparse path: ' + $walk) }
        $parent = [IO.Directory]::GetParent($walk)
        if ($null -eq $parent -or $parent.FullName -eq $walk) { break }
        $walk = $parent.FullName
    }
}
function WritePublicReport($state) {
    $desktop = [Environment]::GetFolderPath('DesktopDirectory')
    if ([string]::IsNullOrEmpty($desktop) -or -not (Test-Path -LiteralPath $desktop)) {
        $desktop = [Environment]::GetFolderPath('MyDocuments')
    }
    if (-not $desktop -or -not (Test-Path -LiteralPath $desktop)) { Fail 'Desktop/Documents is missing.' }
    $stamp = [DateTime]::UtcNow.ToString('yyyyMMdd-HHmmss')
    $zipPath = Join-Path $desktop ('SOKNA-Cafe-V4-Trust-Enrollment-' + $stamp + '.zip')
    $tmp = Join-Path $env:TEMP ('sokna-cafe-issuer-public-' + [Guid]::NewGuid().ToString('N'))
    New-Item -ItemType Directory -Path $tmp -ErrorAction Stop | Out-Null
    try {
        $json = $state | ConvertTo-Json -Depth 8
        [IO.File]::WriteAllText((Join-Path $tmp 'public-enrollment-result.json'), $json, (New-Object System.Text.UTF8Encoding($false)))
        Compress-Archive -Path (Join-Path $tmp 'public-enrollment-result.json') -DestinationPath $zipPath -CompressionLevel Optimal
    } finally { Remove-Item -LiteralPath $tmp -Recurse -Force -ErrorAction SilentlyContinue }
    Write-Host ('PUBLIC-ONLY RESULT ZIP: ' + $zipPath)
}

$principal = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) { Fail 'Run as Administrator is required.' }
if (-not (Get-Command New-SelfSignedCertificate -ErrorAction SilentlyContinue)) { Fail 'Windows certificate enrollment command unavailable.' }
foreach ($p in @($root, $trustRoot, $issuerRoot, $issuerRecordPath)) { RequireSafeDirectory $p }

# Take exclusive lock so two accidental double clicks cannot create duplicate issuers.
New-Item -ItemType Directory -Path $root -Force | Out-Null
$lockFile = Join-Path $root 'cafe-local-uat-issuer-enrollment.lock'
$lock = [IO.File]::Open($lockFile, [IO.FileMode]::OpenOrCreate, [IO.FileAccess]::ReadWrite, [IO.FileShare]::None)
try {
    $existingRecord = $null
    if (Test-Path -LiteralPath $issuerRecordPath -PathType Leaf) {
        try { $existingRecord = Get-Content -LiteralPath $issuerRecordPath -Raw | ConvertFrom-Json }
        catch { Fail 'Existing issuer metadata is invalid; refusing replacement.' }
        if ($existingRecord.format -ne 'sokna-cafe-test-release-issuer-v1') { Fail 'Existing issuer metadata format mismatch.' }
    }
    $matching = @(Get-ChildItem -Path $MachineStore | Where-Object { $_.Subject -eq $Subject })
    if ($existingRecord) {
        $matching = @($matching | Where-Object { $_.Thumbprint -eq $existingRecord.certificate_thumbprint })
        if ($matching.Count -ne 1) { Fail 'Previously registered signing certificate is unavailable; no new key created.' }
        $cert = $matching[0]
        $created = $false
    } else {
        if ($matching.Count -gt 1) { Fail 'Multiple existing Cafe issuer certificates found; manual resolution required.' }
        if ($matching.Count -eq 1) { $cert = $matching[0]; $created = $false }
        else {
            $cert = New-SelfSignedCertificate -Type Custom -Subject $Subject -CertStoreLocation $MachineStore `
                -KeyAlgorithm ECDSA_nistP256 -HashAlgorithm SHA256 -KeyUsage DigitalSignature `
                -KeyExportPolicy NonExportable -Provider 'Microsoft Software Key Storage Provider' `
                -NotAfter (Get-Date).AddYears(2)
            $created = $true
        }
    }
    if (-not $cert.HasPrivateKey -or $cert.NotAfter.ToUniversalTime() -le [DateTime]::UtcNow) { Fail 'Signing certificate lacks an active private key.' }
    if ($cert.PublicKey.Oid.Value -ne '1.2.840.10045.2.1') { Fail 'Certificate is not an ECDSA public key.' }
    # Export PUBLIC SubjectPublicKeyInfo manually (standard P-256 ASN.1 wrapper):
    # SEQUENCE { AlgorithmIdentifier(id-ecPublicKey, prime256v1), BIT STRING 0x04||X||Y }
    # No private key is ever exported.
    [byte[]]$point = $cert.GetPublicKey()
    if ($point.Length -ne 65 -or $point[0] -ne 4) { Fail ('Expected 65-byte P-256 EC public point, found ' + $point.Length) }
    [byte[]]$prefix = HexBytes '3059301306072a8648ce3d020106082a8648ce3d030107034200'
    [byte[]]$der = [byte[]]::new([int]($prefix.Length + $point.Length))
    [Array]::Copy($prefix, 0, $der, 0, $prefix.Length)
    [Array]::Copy($point, 0, $der, $prefix.Length, $point.Length)
    $keyHash = ShaHex $der
    $keyId = 'cafe-local-uat-' + $keyHash.Substring(0,20)
    $base64 = [Convert]::ToBase64String($der, [Base64FormattingOptions]::InsertLineBreaks)
    $pem = "-----BEGIN PUBLIC KEY-----`n" + ($base64.Replace("`r`n","`n")) + "`n-----END PUBLIC KEY-----`n"
    $trustFile = Join-Path $trustRoot ($keyId + '.spki.pem')

    # Check signing-capability and fixed-format IEEE-P1363 64-byte signature locally.
    $signer = [System.Security.Cryptography.X509Certificates.ECDsaCertificateExtensions]::GetECDsaPrivateKey($cert)
    if ($null -eq $signer -or $signer.KeySize -ne 256) { Fail 'Windows ECDSA P-256 private key unavailable.' }
    try {
        [byte[]]$challenge = [Text.Encoding]::UTF8.GetBytes('SOKNA-UAT-P256-SIGNATURE-SELFTEST-V1')
        [byte[]]$sig = $signer.SignData($challenge, [System.Security.Cryptography.HashAlgorithmName]::SHA256)
        if ($sig.Length -ne 64) { Fail ('Signature is not 64-byte IEEE P1363 format: ' + $sig.Length) }
        $verifier = [System.Security.Cryptography.X509Certificates.ECDsaCertificateExtensions]::GetECDsaPublicKey($cert)
        if ($null -eq $verifier) { Fail 'ECDSA public verifier unavailable.' }
        try {
            if (-not $verifier.VerifyData($challenge, $sig, [System.Security.Cryptography.HashAlgorithmName]::SHA256)) { Fail 'ECDSA challenge signature verification failed.' }
        } finally { $verifier.Dispose() }
    } finally { $signer.Dispose() }

    if (Test-Path -LiteralPath $trustFile -PathType Leaf) {
        $current = [IO.File]::ReadAllText($trustFile)
        if ($current.Replace("`r`n","`n") -ne $pem) { Fail 'Existing public signing trust file does not match this certificate.' }
    } else {
        New-Item -ItemType Directory -Path $trustRoot -Force | Out-Null
        WriteUtf8Atomic $trustFile $pem
    }
    if (-not $existingRecord) {
        New-Item -ItemType Directory -Path $issuerRoot -Force | Out-Null
        $record = [ordered]@{
            format = 'sokna-cafe-test-release-issuer-v1'
            key_id = $keyId
            certificate_thumbprint = $cert.Thumbprint
            certificate_store = 'LocalMachine/My'
            public_spki_sha256 = $keyHash
            private_key_exported = $false
            mode = 'TEST_ONLY_NO_PRODUCTION_QUALIFICATION'
        }
        WriteUtf8Atomic $issuerRecordPath ($record | ConvertTo-Json -Depth 5)
    } elseif ($existingRecord.key_id -ne $keyId -or $existingRecord.public_spki_sha256 -ne $keyHash) {
        Fail 'Existing issuer metadata does not match the current ECDSA certificate.'
    }

    $identityPath = Join-Path $root 'installed-release-identity.json'
    $identityPresent = Test-Path -LiteralPath $identityPath -PathType Leaf
    $state = [ordered]@{
        format = 'sokna-cafe-test-signing-enrollment-result-v1'
        status = 'SUCCESS_TEST_SIGNING_TRUST_ENROLLED'
        key_id = $keyId
        public_spki_sha256 = $keyHash
        certificate_thumbprint = $cert.Thumbprint
        private_key_exported = $false
        private_key_storage = 'Windows LocalMachine CNG certificate store; non-exportable'
        reused_existing_key = (-not $created)
        trusted_public_key_file_present = (Test-Path -LiteralPath $trustFile -PathType Leaf)
        windows_maintenance_root = $root
        installed_release_identity_present = $identityPresent
        expected_local_build = $ExpectedLocalBuild
        signed_local_release_set_ready = $false
        qualification_not_bypassed = $true
        notes = @('TEST CAFE ONLY; certificate private key never included in report.',
                  'Local installed identity bootstrap and truthful qualification remain separate.',
                  'Do not upload this result or inner managed web ZIP to Update Center.')
    }
    WritePublicReport $state
    Write-Host ('SUCCESS. Cafe test signing key ID: ' + $keyId)
    if (-not $identityPresent) { Write-Host 'NEXT BLOCKER: installed-release-identity.json absent; no identity was fabricated.' }
} finally { $lock.Dispose() }