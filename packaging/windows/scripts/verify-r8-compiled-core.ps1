param(
  [Parameter(Mandatory=$true)][string]$CorePath,
  [Parameter(Mandatory=$true)][string]$SourceManifest,
  [Parameter(Mandatory=$true)][string]$EvidencePath
)

$ErrorActionPreference='Stop'
function Sha([string]$Path){return (Get-FileHash -LiteralPath $Path -Algorithm SHA256).Hash.ToLowerInvariant()}

$CorePath=(Resolve-Path -LiteralPath $CorePath).Path
$SourceManifest=(Resolve-Path -LiteralPath $SourceManifest).Path
$evidenceParent=Split-Path -Parent $EvidencePath
if($evidenceParent){New-Item -ItemType Directory -Path $evidenceParent -Force|Out-Null}
$tempRoot=if($env:RUNNER_TEMP){$env:RUNNER_TEMP}else{[IO.Path]::GetTempPath()}
$dest=Join-Path $tempRoot ('sokna-r8-core-verify-'+[guid]::NewGuid().ToString('N'))
$log=Join-Path $tempRoot ('sokna-r8-core-verify-'+[guid]::NewGuid().ToString('N')+'.log')
New-Item -ItemType Directory -Path $dest -Force|Out-Null

try{
  $arguments=@('/VERYSILENT','/SUPPRESSMSGBOXES','/NORESTART','/NOICONS',("/DIR=$dest"),("/LOG=$log"))
  $process=Start-Process -FilePath $CorePath -ArgumentList $arguments -Wait -PassThru
  if($process.ExitCode-ne0){throw "Core isolated install failed with exit code $($process.ExitCode)"}

  $installedManifest=Join-Path $dest 'payload-manifest.json'
  if(-not(Test-Path -LiteralPath $installedManifest -PathType Leaf)){throw 'Installed core omitted payload-manifest.json'}
  if((Sha $SourceManifest)-ne(Sha $installedManifest)){throw 'Installed payload-manifest.json differs from composed shell manifest'}

  $manifest=Get-Content -LiteralPath $installedManifest -Raw|ConvertFrom-Json
  if([string]$manifest.package_version-ne'1.1.7'){throw 'Installed R8 payload manifest package version mismatch'}
  $verified=0
  foreach($entry in @($manifest.files)){
    $relative=([string]$entry.path).Replace('/','\')
    $path=Join-Path $dest $relative
    if(-not(Test-Path -LiteralPath $path -PathType Leaf)){throw "Installed core missing manifested file: $relative"}
    $file=Get-Item -LiteralPath $path
    if([long]$file.Length-ne[long]$entry.size){throw "Installed core size drift: $relative"}
    if((Sha $path)-ne([string]$entry.sha256).ToLowerInvariant()){throw "Installed core SHA256 drift: $relative"}
    $verified++
  }

  $keep=@{
    (Join-Path $dest '_payload/runtime/SoknaRuntimeService.exe')='26deed62524278fa93a9a576d958f9177fe97c878b75829a8dc93a1440955afd';
    (Join-Path $dest '_payload/print-worker/Service/Sokna.PrintAgent.Service.exe')='99565602163463e9bce0ca6b33933411635db87a03acaf03340d2a56d9c85ff4';
    (Join-Path $dest '_payload/maintenance/recovery/SoknaMaintenanceRecoveryConsole.exe')='85989bc8d1096575b0fcfc7d7d55aa999674c20ac8092fd3ea16d90e574c17e9'
  }
  foreach($path in $keep.Keys){if((Sha $path)-ne$keep[$path]){throw "Installed canonical KEEP drift: $path"}}

  foreach($required in @('queue-local-slot-adoption-r8.ps1','setup-windows-components-core-v5.ps1','local-slot-bootstrap-contract-v1.json')){
    $requiredPath=Join-Path $dest $required
    if(-not(Test-Path -LiteralPath $requiredPath -PathType Leaf)){throw "Installed R8 core missing: $required"}
  }
  if(Test-Path -LiteralPath (Join-Path $dest 'queue-local-slot-adoption-r7.ps1') -PathType Leaf){throw 'R7 adoption queue leaked into compiled R8 core'}

  [ordered]@{
    format='sokna-r8-compiled-core-verification-v1'
    verified_manifest_files=$verified
    core_exit_code=$process.ExitCode
    manifest_sha256=Sha $installedManifest
    generated_at_utc=[DateTime]::UtcNow.ToString('o')
  }|ConvertTo-Json|Set-Content -LiteralPath $EvidencePath -Encoding utf8
  Write-Host "R8 compiled core full-manifest verification: PASS ($verified files)"
}
catch{
  Write-Host "R8_COMPILED_CORE_VERIFY_ERROR=$($_.Exception.Message)"
  if(Test-Path -LiteralPath $log -PathType Leaf){Write-Host '--- INNO CORE LOG (tail) ---';Get-Content -LiteralPath $log -Tail 250|ForEach-Object{Write-Host $_}}
  if(Test-Path -LiteralPath $dest -PathType Container){Write-Host '--- ISOLATED INSTALL FILES (first 200) ---';Get-ChildItem -LiteralPath $dest -File -Recurse|Select-Object -First 200 FullName,Length|Format-Table -AutoSize|Out-String|Write-Host}
  throw
}
