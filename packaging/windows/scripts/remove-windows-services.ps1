param(
  [string]$InstallRoot="$env:ProgramFiles\SOKNA Windows Services",
  [string]$DataRoot="$env:ProgramData\SOKNA"
)
$ErrorActionPreference='Stop'
$script=Join-Path $PSScriptRoot 'setup-windows-services.ps1'
if(-not(Test-Path -LiteralPath $script -PathType Leaf)){throw 'Windows services lifecycle script is missing.'}
& $script -Mode Uninstall -ShellRoot $PSScriptRoot -InstallRoot $InstallRoot -DataRoot $DataRoot
if($LASTEXITCODE-ne0){exit $LASTEXITCODE}
