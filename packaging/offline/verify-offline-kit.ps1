param(
  [Parameter(Mandatory=$true)][string]$KitRoot,
  [string]$RepoRoot=(Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
)
$ErrorActionPreference='Stop'
$lock=Get-Content (Join-Path $RepoRoot 'platform\windows\release-lock.json') -Raw | ConvertFrom-Json
if($lock.format -ne 'sokna-windows-prerequisite-lock-v1' -or -not $lock.release_frozen){ throw 'Invalid release-lock.json' }

$deps=@('php','apache','mariadb','vc_runtime')
$failed=$false
foreach($dep in $deps){
  $a=$lock.artifacts | Where-Object dependency -eq $dep | Select-Object -First 1
  if(-not $a){ Write-Host "MISSING LOCK: $dep"; $failed=$true; continue }
  $file=Get-ChildItem -LiteralPath $KitRoot -Recurse -File -Filter $a.filename -ErrorAction SilentlyContinue | Select-Object -First 1
  if(-not $file){ Write-Host "MISSING: $($a.filename)"; $failed=$true; continue }
  if($file.Length -ne [long]$a.size){ Write-Host "BAD SIZE: $($a.filename)"; $failed=$true; continue }
  $hash=(Get-FileHash -LiteralPath $file.FullName -Algorithm SHA256).Hash.ToLowerInvariant()
  if($hash -ne [string]$a.sha256){ Write-Host "BAD SHA256: $($a.filename)"; $failed=$true; continue }
  if($a.authenticode.required){
    $sig=Get-AuthenticodeSignature -LiteralPath $file.FullName
    if($sig.Status -ne 'Valid' -or -not $sig.SignerCertificate -or $sig.SignerCertificate.Subject.IndexOf([string]$a.authenticode.publisher_contains,[StringComparison]::OrdinalIgnoreCase) -lt 0){
      Write-Host "BAD SIGNATURE: $($a.filename)"; $failed=$true; continue
    }
  }
  Write-Host "OK: $($a.filename)"
}
if($failed){ exit 2 }
Write-Host 'SOKNA Offline Kit prerequisites: PASS'
