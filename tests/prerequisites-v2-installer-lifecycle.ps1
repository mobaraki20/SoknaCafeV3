$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest

$RepoRoot=(Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$current=(Get-Content (Join-Path $RepoRoot 'packaging\prerequisites\VERSION.txt') -Raw).Trim()
if($current-ne'1.0.12'){throw "Expected 1.0.12, got $current"}
$new=Join-Path $RepoRoot "packaging\prerequisites\out\installer\SOKNA-Prerequisites-Setup-$current.exe"
if(-not(Test-Path $new)){throw "Current installer missing: $new"}

$previous='1.0.11'
$old=Join-Path $env:RUNNER_TEMP "SOKNA-Prerequisites-Setup-$previous.exe"
$url="https://github.com/mobaraki20/SoknaCafeV3/releases/download/prerequisites-v$previous/SOKNA-Prerequisites-Setup-$previous.exe"
& curl.exe -fL --retry 3 --retry-delay 2 -o $old $url
if($LASTEXITCODE-ne0){throw "Failed to download previous release $previous"}

function Invoke-Setup([string]$path){
  return Start-Process -FilePath $path -ArgumentList @('/VERYSILENT','/SUPPRESSMSGBOXES','/NORESTART') -Wait -PassThru
}

function Installed-Version {
  $key='HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall\{BF4DB10B-9F11-4A7A-8B02-1B090FC57B31}_is1'
  if(-not(Test-Path $key)){return ''}
  return [string](Get-ItemProperty $key).DisplayVersion
}

$installed='C:\Program Files\SOKNA Prerequisites\SoknaPrerequisitesSetup.exe'
$uninstall='C:\Program Files\SOKNA Prerequisites\unins000.exe'
try {
  $seed=Invoke-Setup $old
  if($seed.ExitCode-ne0){throw "Seed install failed: $($seed.ExitCode)"}
  if((Installed-Version)-ne$previous){throw "Seed DisplayVersion mismatch: $(Installed-Version)"}
  if(-not(Test-Path $installed)){throw 'Installed manager missing'}

  $oldUi=Start-Process -FilePath $installed -PassThru
  try {
    Start-Sleep -Seconds 3
    if($oldUi.HasExited){throw 'Previous UI did not remain open for file-lock regression'}
    $upgrade=Invoke-Setup $new
    if($upgrade.ExitCode-ne0){throw "Upgrade failed: $($upgrade.ExitCode)"}
    Start-Sleep -Seconds 2
    $oldUi.Refresh()
    if(-not$oldUi.HasExited){throw 'Previous UI stayed open; CloseApplications regression'}
  } finally {
    if($oldUi -and -not$oldUi.HasExited){Stop-Process -Id $oldUi.Id -Force -ErrorAction SilentlyContinue}
  }

  if((Installed-Version)-ne$current){throw "Upgrade DisplayVersion mismatch: $(Installed-Version)"}
  $same=Invoke-Setup $new
  if($same.ExitCode-ne0){throw "Same-version repair refresh failed: $($same.ExitCode)"}
  if((Installed-Version)-ne$current){throw 'Same-version run changed DisplayVersion unexpectedly'}

  $iscc=@("${env:ProgramFiles(x86)}\Inno Setup 6\ISCC.exe","$env:ProgramFiles\Inno Setup 6\ISCC.exe")|Where-Object{$_ -and(Test-Path $_)}|Select-Object -First 1
  if(-not$iscc){throw 'ISCC.exe missing'}
  $publish=Join-Path $RepoRoot 'packaging\prerequisites\out\publish'
  $futureOut=Join-Path $env:RUNNER_TEMP 'sokna-prereq-future'
  New-Item -ItemType Directory -Path $futureOut -Force|Out-Null
  $iss=Join-Path $RepoRoot 'packaging\prerequisites\installer\SOKNA-Prerequisites.iss'
  & $iscc "/DSourceRoot=$publish" '/DProductVersion=9.9.9' "/O$futureOut" $iss
  if($LASTEXITCODE-ne0){throw 'Future installer build failed'}
  $future=Join-Path $futureOut 'SOKNA-Prerequisites-Setup-9.9.9.exe'
  if(-not(Test-Path $future)){throw 'Future installer missing'}
  $futureInstall=Invoke-Setup $future
  if($futureInstall.ExitCode-ne0){throw "Future seed failed: $($futureInstall.ExitCode)"}
  if((Installed-Version)-ne'9.9.9'){throw 'Future DisplayVersion was not installed'}

  $downgrade=Invoke-Setup $new
  if($downgrade.ExitCode-eq0){throw 'Silent downgrade unexpectedly succeeded'}
  if((Installed-Version)-ne'9.9.9'){throw "Downgrade guard mutated installed version: $(Installed-Version)"}

  Write-Host 'PREREQUISITES_INSTALLER_UPGRADE_REPAIR_DOWNGRADE=PASS'
}
finally {
  if(Test-Path $uninstall){
    $u=Start-Process -FilePath $uninstall -ArgumentList @('/VERYSILENT','/SUPPRESSMSGBOXES','/NORESTART') -Wait -PassThru
    Write-Host "Cleanup uninstall exit=$($u.ExitCode)"
  }
}
