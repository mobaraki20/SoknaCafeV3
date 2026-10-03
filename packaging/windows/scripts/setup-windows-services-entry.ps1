param(
  [Parameter(Mandatory=$true)][ValidateSet('Install','Repair','Uninstall')][string]$Mode,
  [Parameter(Mandatory=$true)][string]$ShellRoot,
  [Parameter(Mandatory=$true)][string]$InstallRoot,
  [Parameter(Mandatory=$true)][string]$DataRoot,
  [string]$PairingFile='',
  [ValidateSet(0,1)][int]$StartWhenPaired=1
)
$ErrorActionPreference='Stop'

$core=Join-Path $PSScriptRoot 'setup-windows-services-core.ps1'
if(-not(Test-Path -LiteralPath $core -PathType Leaf)){throw 'Windows Services lifecycle core is missing.'}

& $core -Mode $Mode -ShellRoot $ShellRoot -InstallRoot $InstallRoot -DataRoot $DataRoot -PairingFile $PairingFile -StartWhenPaired $StartWhenPaired

if($Mode-ne'Uninstall'){
  $manifestPath=Join-Path $ShellRoot 'payload-manifest.json'
  if(-not(Test-Path -LiteralPath $manifestPath -PathType Leaf)){throw 'Payload manifest is missing after lifecycle.'}
  $manifest=Get-Content -LiteralPath $manifestPath -Raw|ConvertFrom-Json
  $packageVersion=([string]$manifest.package_version).Trim()
  if($packageVersion-notmatch'^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$'){throw 'Payload package version is invalid.'}

  $statePath=Join-Path ([IO.Path]::GetFullPath($DataRoot)) 'setup\windows-services-state.json'
  if(Test-Path -LiteralPath $statePath -PathType Leaf){
    $state=Get-Content -LiteralPath $statePath -Raw|ConvertFrom-Json
    $state|Add-Member -NotePropertyName package_version -NotePropertyValue $packageVersion -Force
    $state|Add-Member -NotePropertyName version_recorded_at_utc -NotePropertyValue ([DateTime]::UtcNow.ToString('o')) -Force
    $state|ConvertTo-Json -Depth 8|Set-Content -LiteralPath $statePath -Encoding UTF8
  }
}
