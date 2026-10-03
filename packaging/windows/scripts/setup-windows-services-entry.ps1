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

$DataRoot=[IO.Path]::GetFullPath($DataRoot)
$statePath=Join-Path $DataRoot 'setup\windows-services-state.json'
$runtimeConfig=Join-Path $DataRoot 'runtime\runtime-config.json'
$runtimeToken=Join-Path $DataRoot 'runtime\runtime-token.private'
$localToken=Join-Path $DataRoot 'runtime\local-token.private'
$hadInlinePairing=-not[string]::IsNullOrWhiteSpace([string]$env:SOKNA_WINDOWS_SERVICES_PAIRING_B64)
$newPairingSupplied=$hadInlinePairing-or-not[string]::IsNullOrWhiteSpace($PairingFile)

$previousPaired=$false
$previousLocalBaseUrl=''
$previousPairedAt=''
$previousPairingLifecycle=''
if($Mode-ne'Uninstall'-and(Test-Path -LiteralPath $statePath -PathType Leaf)){
  try{
    $previous=Get-Content -LiteralPath $statePath -Raw|ConvertFrom-Json
    $previousPaired=[bool]$previous.paired
    $previousLocalBaseUrl=[string]$previous.local_base_url
    $previousPairedAt=[string]$previous.paired_at_utc
    $previousPairingLifecycle=[string]$previous.pairing_lifecycle
  }catch{}
}
if($previousPaired-and[string]::IsNullOrWhiteSpace($previousLocalBaseUrl)-and(Test-Path -LiteralPath $runtimeConfig -PathType Leaf)){
  try{
    $previousRuntimeConfig=Get-Content -LiteralPath $runtimeConfig -Raw|ConvertFrom-Json
    $previousLocalBaseUrl=[string]$previousRuntimeConfig.localBaseUrl
  }catch{}
}

& $core -Mode $Mode -ShellRoot $ShellRoot -InstallRoot $InstallRoot -DataRoot $DataRoot -PairingFile $PairingFile -StartWhenPaired $StartWhenPaired

if($Mode-ne'Uninstall'){
  $manifestPath=Join-Path $ShellRoot 'payload-manifest.json'
  if(-not(Test-Path -LiteralPath $manifestPath -PathType Leaf)){throw 'Payload manifest is missing after lifecycle.'}
  $manifest=Get-Content -LiteralPath $manifestPath -Raw|ConvertFrom-Json
  $packageVersion=([string]$manifest.package_version).Trim()
  if($packageVersion-notmatch'^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$'){throw 'Payload package version is invalid.'}

  if(-not(Test-Path -LiteralPath $statePath -PathType Leaf)){throw 'Windows Services install state is missing after lifecycle.'}
  $state=Get-Content -LiteralPath $statePath -Raw|ConvertFrom-Json

  # A normal upgrade/repair must not destroy a previously valid Local Web pairing.
  # The core lifecycle intentionally receives no secrets during a standard upgrade, so preserve the existing
  # configuration/tokens and restore the paired state when they are still present.
  if($previousPaired-and-not$newPairingSupplied){
    if(-not(Test-Path -LiteralPath $runtimeConfig -PathType Leaf)-or-not(Test-Path -LiteralPath $runtimeToken -PathType Leaf)-or-not(Test-Path -LiteralPath $localToken -PathType Leaf)){
      throw 'Existing pairing metadata was present but Runtime pairing files are incomplete after lifecycle.'
    }
    $state|Add-Member -NotePropertyName paired -NotePropertyValue $true -Force
    if(-not[string]::IsNullOrWhiteSpace($previousLocalBaseUrl)){$state|Add-Member -NotePropertyName local_base_url -NotePropertyValue $previousLocalBaseUrl -Force}
    if(-not[string]::IsNullOrWhiteSpace($previousPairedAt)){$state|Add-Member -NotePropertyName paired_at_utc -NotePropertyValue $previousPairedAt -Force}
    $lifecycle=$(if(-not[string]::IsNullOrWhiteSpace($previousPairingLifecycle)){$previousPairingLifecycle}else{'preserved-existing'})
    $state|Add-Member -NotePropertyName pairing_lifecycle -NotePropertyValue $lifecycle -Force
    $state|Add-Member -NotePropertyName pairing_preserved_during_lifecycle -NotePropertyValue $true -Force

    Set-Service -Name 'SoknaRuntime' -StartupType Automatic -ErrorAction Stop
    Set-ItemProperty -LiteralPath 'HKLM:\SYSTEM\CurrentControlSet\Services\SoknaRuntime' -Name DelayedAutoStart -Type DWord -Value 1 -Force
    $runtimeService=Get-Service -Name 'SoknaRuntime' -ErrorAction Stop
    try{
      if($runtimeService.Status-ne'Running'){
        Start-Service -Name 'SoknaRuntime' -ErrorAction Stop
        $runtimeService.WaitForStatus('Running',[TimeSpan]::FromSeconds(20))
      }
    }finally{$runtimeService.Dispose()}
  }

  $state|Add-Member -NotePropertyName package_version -NotePropertyValue $packageVersion -Force
  $state|Add-Member -NotePropertyName version_recorded_at_utc -NotePropertyValue ([DateTime]::UtcNow.ToString('o')) -Force
  $state|ConvertTo-Json -Depth 8|Set-Content -LiteralPath $statePath -Encoding UTF8
}
