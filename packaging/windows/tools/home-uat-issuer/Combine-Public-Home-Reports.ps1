# Public result archives only; no key material, no installation state is copied.
[CmdletBinding()]
param()
$ErrorActionPreference='Stop'
$desktop=[Environment]::GetFolderPath('DesktopDirectory')
if (-not $desktop -or -not (Test-Path -LiteralPath $desktop)) { $desktop=[Environment]::GetFolderPath('MyDocuments') }
if (-not $desktop -or -not (Test-Path -LiteralPath $desktop)) { throw 'Desktop/Documents not found.' }
$t=Get-ChildItem -LiteralPath $desktop -Filter 'SOKNA-Home-V4-Trust-Enrollment-*.zip' -File | Sort-Object LastWriteTime -Descending | Select-Object -First 1
$i=Get-ChildItem -LiteralPath $desktop -Filter 'SOKNA-Home-Local231ap-Identity-Bound-*.zip' -File | Sort-Object LastWriteTime -Descending | Select-Object -First 1
if (-not $t -or -not $i) { throw 'Both public-only source reports required.' }
if ($t.LastWriteTime -lt (Get-Date).AddMinutes(-10) -or $i.LastWriteTime -lt (Get-Date).AddMinutes(-10)) { throw 'A required source report is older than this run.' }
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem
foreach ($input in @(@($t,'public-enrollment-result.json'),@($i,'public-identity-result.json'))) {
 $z=[IO.Compression.ZipFile]::OpenRead($input[0].FullName)
 try { if (-not $z.GetEntry($input[1]) -or $z.Entries.Count -ne 1) { throw ('Unexpected public report content in '+$input[0].Name) } }
 finally { $z.Dispose() }
}
$zip=Join-Path $desktop ('SOKNA-Home-V4-Local-Preflight-Results-'+[DateTime]::UtcNow.ToString('yyyyMMdd-HHmmss')+'.zip')
Compress-Archive -LiteralPath @($t.FullName,$i.FullName) -DestinationPath $zip -CompressionLevel Optimal
Write-Host ('SEND THIS SINGLE REPORT ZIP: '+$zip)
