param(
    [Parameter(Mandatory=$true)][string]$OpenSslExe,
    [string]$DataRoot = "$env:ProgramData\SOKNA",
    [string]$Hostname = 'sokna.local',
    [switch]$ValidateOnly
)
$ErrorActionPreference = 'Stop'
Import-Module (Join-Path $PSScriptRoot 'setup-support.psm1') -DisableNameChecking
if (-not ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) { throw 'Administrator privileges are required.' }
if (-not (Test-Path -LiteralPath $OpenSslExe -PathType Leaf)) { throw 'OpenSSL executable was not found.' }
if ($Hostname -notmatch '^(?=.{1,253}$)[a-z0-9]+(?:[.-][a-z0-9]+)*$') { throw 'Invalid local hostname.' }
$OpenSslExe = [IO.Path]::GetFullPath($OpenSslExe)
$Secrets = Join-Path $DataRoot 'secrets\tls'
Assert-SoknaSafePath $Secrets
$CaKey = Join-Path $Secrets 'local-ca.key.pem'
$CaCert = Join-Path $Secrets 'local-ca.crt.pem'
$Key = Join-Path $Secrets 'server.key.pem'
$Cert = Join-Path $Secrets 'server.crt.pem'
$required = @($CaKey,$CaCert,$Key,$Cert)
$present = @($required | Where-Object { Test-Path -LiteralPath $_ -PathType Leaf }).Count
if ($present -gt 0 -and $present -ne $required.Count) { throw 'Partial TLS identity: preserve existing files and recover them explicitly. No key was regenerated.' }
$Hosts = "$env:SystemRoot\System32\drivers\etc\hosts"
$Lines = @(Get-Content -LiteralPath $Hosts)
foreach ($line in $Lines) {
    $mapping = ($line -split '#',2)[0].Trim() -split '\s+'
    if ($mapping.Count -gt 1 -and $mapping[1..($mapping.Count-1)] -contains $Hostname -and $mapping[0] -ne '127.0.0.1') {
        throw 'Local hostname has a conflicting hosts mapping; no hosts entry was removed.'
    }
}

# Native OpenSSL builds may use ANSI argv. Keep file arguments ASCII/relative
# and let CreateProcessW set the Unicode working directory.
function Assert-TlsIdentity([string]$Directory) {
    $ca = 'local-ca.crt.pem'
    $caPrivate = 'local-ca.key.pem'
    $server = 'server.crt.pem'
    $serverPrivate = 'server.key.pem'
    Invoke-SoknaProcess -File $OpenSslExe -WorkingDirectory $Directory -Arguments @('verify','-CAfile',$ca,'-verify_hostname',$Hostname,$server) | Out-Null
    foreach ($pair in @(@($ca,$caPrivate),@($server,$serverPrivate))) {
        Invoke-SoknaProcess -File $OpenSslExe -WorkingDirectory $Directory -Arguments @('x509','-in',$pair[0],'-checkend','0','-noout') | Out-Null
        $public = (Invoke-SoknaProcess -File $OpenSslExe -WorkingDirectory $Directory -Arguments @('x509','-in',$pair[0],'-pubkey','-noout')).Output.Trim()
        $privatePublic = (Invoke-SoknaProcess -File $OpenSslExe -WorkingDirectory $Directory -Arguments @('pkey','-in',$pair[1],'-pubout')).Output.Trim()
        if ($public -ne $privatePublic) { throw 'TLS certificate/private key mismatch. Existing identity was preserved.' }
    }
}

if ($ValidateOnly) {
    if ($present -eq $required.Count) { Assert-TlsIdentity $Secrets }
    [pscustomobject]@{ valid=$true; identity_exists=($present -eq 4) } | ConvertTo-Json -Compress
    return
}

if ($present -eq $required.Count) {
    Assert-TlsIdentity $Secrets
    New-SoknaPrivateDirectory $Secrets | Out-Null
} else {
    # Build a complete validated identity in a private sibling; never overwrite old keys.
    $parent = New-SoknaPrivateDirectory (Join-Path $DataRoot 'secrets')
    $staging = New-SoknaPrivateDirectory (Join-Path $parent ('tls-staging-' + [guid]::NewGuid().ToString('N')))
    try {
        $Cfg = Join-Path $staging 'openssl-sokna.cnf'
@"
[req]
distinguished_name=dn
prompt=no
req_extensions=v3_req
[dn]
CN=$Hostname
[v3_req]
subjectAltName=@alt_names
extendedKeyUsage=serverAuth
keyUsage=digitalSignature,keyEncipherment
[alt_names]
DNS.1=$Hostname
DNS.2=localhost
IP.1=127.0.0.1
IP.2=::1
"@ | Set-Content -LiteralPath $Cfg -Encoding ascii
        $stagedCaKey = 'local-ca.key.pem'
        $stagedCa = 'local-ca.crt.pem'
        $stagedKey = 'server.key.pem'
        $csr = 'server.csr.pem'
        $stagedCert = 'server.crt.pem'
        Invoke-SoknaProcess -File $OpenSslExe -WorkingDirectory $staging -Arguments @('genrsa','-out',$stagedCaKey,'3072') | Out-Null
        Invoke-SoknaProcess -File $OpenSslExe -WorkingDirectory $staging -Arguments @('req','-x509','-new','-key',$stagedCaKey,'-sha256','-days','3650','-subj','/CN=SOKNA Local CA','-out',$stagedCa) | Out-Null
        Invoke-SoknaProcess -File $OpenSslExe -WorkingDirectory $staging -Arguments @('genrsa','-out',$stagedKey,'2048') | Out-Null
        Invoke-SoknaProcess -File $OpenSslExe -WorkingDirectory $staging -Arguments @('req','-new','-key',$stagedKey,'-out',$csr,'-config','openssl-sokna.cnf') | Out-Null
        Invoke-SoknaProcess -File $OpenSslExe -WorkingDirectory $staging -Arguments @('x509','-req','-in',$csr,'-CA',$stagedCa,'-CAkey',$stagedCaKey,'-CAcreateserial','-out',$stagedCert,'-days','825','-sha256','-extensions','v3_req','-extfile','openssl-sokna.cnf') | Out-Null
        Assert-TlsIdentity $staging
        if (Test-Path -LiteralPath $Secrets) {
            if (@(Get-ChildItem -LiteralPath $Secrets -Force).Count -gt 0) { throw 'TLS directory is not empty; explicit recovery is required.' }
            Remove-Item -LiteralPath $Secrets
        }
        Move-Item -LiteralPath $staging -Destination $Secrets
    } finally { if (Test-Path -LiteralPath $staging) { Remove-Item -LiteralPath $staging -Recurse -Force } }
}
Import-Certificate -FilePath $CaCert -CertStoreLocation 'Cert:\LocalMachine\Root' | Out-Null
$found = $false
foreach ($line in $Lines) {
    $mapping = ($line -split '#',2)[0].Trim() -split '\s+'
    if ($mapping.Count -gt 1 -and $mapping[0] -eq '127.0.0.1' -and $mapping[1..($mapping.Count-1)] -contains $Hostname) { $found = $true }
}
if (-not $found) { Add-Content -LiteralPath $Hosts -Value "`r`n127.0.0.1`t$Hostname`t# SOKNA managed" -Encoding ascii }
[pscustomobject]@{ hostname=$Hostname; certificate=$Cert; private_key=$Key; ca_certificate=$CaCert; data_root=$DataRoot; identity_reused=($present -eq 4) } | ConvertTo-Json -Depth 3
