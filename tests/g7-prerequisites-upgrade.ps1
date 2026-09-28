$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest

$RepoRoot=(Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$current=(Get-Content (Join-Path $RepoRoot 'packaging\prerequisites\VERSION.txt') -Raw).Trim()
if($current-ne'1.0.9'){throw "This regression currently expects 1.0.9, got $current"}
$new=Join-Path $RepoRoot "packaging\prerequisites\out\installer\SOKNA-Prerequisites-Setup-$current.exe"
if(-not(Test-Path $new)){throw "Current installer missing: $new"}

$previous='1.0.8'
$old=Join-Path $env:RUNNER_TEMP "SOKNA-Prerequisites-Setup-$previous.exe"
$url="https://github.com/mobaraki20/SoknaCafeV3/releases/download/prerequisites-v$previous/SOKNA-Prerequisites-Setup-$previous.exe"
& curl.exe -fL --retry 3 --retry-delay 2 -o $old $url
if($LASTEXITCODE-ne0){throw "Failed to download $previous release"}

$oldInstall=Start-Process -FilePath $old -ArgumentList @('/VERYSILENT','/SUPPRESSMSGBOXES','/NORESTART') -Wait -PassThru
if($oldInstall.ExitCode-ne0){throw "$previous seed install failed: $($oldInstall.ExitCode)"}

$installed='C:\Program Files\SOKNA Prerequisites\SoknaPrerequisitesSetup.exe'
if(-not(Test-Path $installed)){throw "Installed $previous UI missing"}
$oldVersion=([Diagnostics.FileVersionInfo]::GetVersionInfo($installed).ProductVersion).Split('+')[0]
if($oldVersion-ne$previous){throw "Seed version mismatch: $oldVersion"}

$oldUi=Start-Process -FilePath $installed -PassThru
try{
  Start-Sleep -Seconds 3
  if($oldUi.HasExited){throw "$previous UI did not stay running for lock reproduction"}

  $upgrade=Start-Process -FilePath $new -ArgumentList @('/VERYSILENT','/SUPPRESSMSGBOXES','/NORESTART') -Wait -PassThru
  if($upgrade.ExitCode-ne0){throw "$current upgrade failed: $($upgrade.ExitCode)"}
  Start-Sleep -Seconds 2
  $oldUi.Refresh()
  if(-not$oldUi.HasExited){throw 'Old UI remained running after upgrade; file-lock handling failed'}

  $newVersion=([Diagnostics.FileVersionInfo]::GetVersionInfo($installed).ProductVersion).Split('+')[0]
  if($newVersion-ne$current){throw "Installed upgrade version mismatch: $newVersion"}

  $key='HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall\{BF4DB10B-9F11-4A7A-8B02-1B090FC57B31}_is1'
  if(-not(Test-Path $key)){throw 'Prerequisites uninstall registration missing after upgrade'}
  $display=(Get-ItemProperty $key).DisplayVersion
  if($display-ne$current){throw "DisplayVersion mismatch after upgrade: $display"}
  Write-Host "G7_UPGRADE_$($previous)_TO_$($current)_WITH_OLD_UI_OPEN=PASS"
} finally {
  if($oldUi -and -not$oldUi.HasExited){Stop-Process -Id $oldUi.Id -Force -ErrorAction SilentlyContinue}
  $uninstall='C:\Program Files\SOKNA Prerequisites\unins000.exe'
  if(Test-Path $uninstall){
    $u=Start-Process -FilePath $uninstall -ArgumentList @('/VERYSILENT','/SUPPRESSMSGBOXES','/NORESTART') -Wait -PassThru
    Write-Host "Cleanup uninstall exit=$($u.ExitCode)"
  }
}
