param(
  [Parameter(Mandatory=$true)][ValidateSet('Install','Repair','Uninstall')][string]$Mode,
  [Parameter(Mandatory=$true)][string]$PlanFile
)
$ErrorActionPreference='Stop'
function Test-FullyQualifiedPath([string]$Path){
  if([string]::IsNullOrWhiteSpace($Path)){return $false}
  try{
    $root=[IO.Path]::GetPathRoot($Path)
    if([string]::IsNullOrWhiteSpace($root)){return $false}
    if($root -match '^[A-Za-z]:[\\/]'){return $true}
    if($root.StartsWith('\\')){return $true}
  }catch{return $false}
  return $false
}
if(-not (Test-FullyQualifiedPath $PlanFile) -or -not (Test-Path -LiteralPath $PlanFile -PathType Leaf)){throw 'Windows services lifecycle plan file is missing.'}
$plan=Get-Content -LiteralPath $PlanFile -Raw|ConvertFrom-Json
if([int]$plan.schema_version-ne2){throw 'Unsupported Windows services lifecycle plan.'}
if(([string]$plan.mode).ToLowerInvariant()-ne$Mode.ToLowerInvariant()){throw 'Lifecycle mode and plan disagree.'}
foreach($n in @('shell_root','install_root','data_root')){if(-not (Test-FullyQualifiedPath ([string]$plan.$n))){throw "Lifecycle plan path is invalid: $n"}}
$script=Join-Path ([string]$plan.shell_root) 'setup-windows-services.ps1'
if(-not(Test-Path -LiteralPath $script -PathType Leaf)){throw 'Windows services lifecycle script is missing.'}
$start=if([bool]$plan.start_when_paired){1}else{0}
& $script -Mode $Mode -ShellRoot ([string]$plan.shell_root) -InstallRoot ([string]$plan.install_root) -DataRoot ([string]$plan.data_root) -PairingFile ([string]$plan.pairing_file) -StartWhenPaired $start
if($LASTEXITCODE-ne0){exit $LASTEXITCODE}
