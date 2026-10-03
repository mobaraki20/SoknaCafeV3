param(
    [Parameter(Mandatory=$true)][string]$InstallerPath,
    [Parameter(Mandatory=$true)][string]$DiagnosticsRoot
)
$ErrorActionPreference='Stop'

$repoRoot=(Resolve-Path (Join-Path $PSScriptRoot '..\..\..')).Path
$version=(Get-Content -LiteralPath (Join-Path $repoRoot 'packaging\windows\WINDOWS_SERVICES_VERSION.txt') -Raw).Trim()
$InstallerPath=[IO.Path]::GetFullPath($InstallerPath)
$DiagnosticsRoot=[IO.Path]::GetFullPath($DiagnosticsRoot)
New-Item -ItemType Directory -Path $DiagnosticsRoot -Force|Out-Null
if(-not(Test-Path -LiteralPath $InstallerPath -PathType Leaf)){throw "Installer missing: $InstallerPath"}

$uninstallKey='HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall\{7D6E9D44-9A2B-4D7E-8FB1-53C9375A84F1}_is1'
$installRoot=Join-Path $env:ProgramFiles 'SOKNA Windows Services'

function Run-Setup([string]$name,[switch]$ExpectBlocked){
    $log=Join-Path $DiagnosticsRoot ($name+'.log')
    $args=@('/VERYSILENT','/SUPPRESSMSGBOXES','/NORESTART',('/LOG="'+$log+'"'))
    $process=Start-Process -FilePath $InstallerPath -ArgumentList $args -Wait -PassThru
    if(-not$ExpectBlocked -and $process.ExitCode-ne0){throw "$name installer exit code: $($process.ExitCode)"}
    if($ExpectBlocked -and $process.ExitCode-eq0){throw "$name was expected to block but exited 0"}
    if(-not(Test-Path -LiteralPath $log -PathType Leaf)){throw "$name log missing"}
    return @{Path=$log;Text=(Get-Content -LiteralPath $log -Raw)}
}

function Assert-Contains([string]$text,[string]$needle,[string]$label){
    if($text.IndexOf($needle,[StringComparison]::Ordinal)-lt0){throw "$label missing expected text: $needle"}
}

try {
    if(Test-Path -LiteralPath $uninstallKey){Remove-Item -LiteralPath $uninstallKey -Recurse -Force}
    Remove-Item -LiteralPath $installRoot -Recurse -Force -ErrorAction SilentlyContinue

    $fresh=Run-Setup 'fresh'
    Assert-Contains $fresh.Text 'SOKNA_INSTALL_MODE=نصب جدید' 'fresh mode'
    if(-not(Test-Path -LiteralPath $uninstallKey)){throw 'Uninstall registration was not created after fresh install.'}
    $displayVersion=[string](Get-ItemProperty -LiteralPath $uninstallKey -Name DisplayVersion -ErrorAction Stop).DisplayVersion
    if($displayVersion-ne$version){throw "Fresh DisplayVersion mismatch: $displayVersion"}

    $same=Run-Setup 'same-version'
    Assert-Contains $same.Text ("SOKNA_PREVIOUS_VERSION="+$version) 'same version previous detection'
    Assert-Contains $same.Text ("SOKNA_INSTALL_MODE=تعمیر / نصب مجدد نسخه "+$version) 'same version mode'

    Set-ItemProperty -LiteralPath $uninstallKey -Name DisplayVersion -Value '1.0.10'
    $upgrade=Run-Setup 'upgrade-from-1.0.10'
    Assert-Contains $upgrade.Text 'SOKNA_PREVIOUS_VERSION=1.0.10' 'upgrade previous detection'
    Assert-Contains $upgrade.Text ("SOKNA_INSTALL_MODE=به‌روزرسانی از نسخه 1.0.10 به نسخه "+$version) 'upgrade mode'
    $displayVersion=[string](Get-ItemProperty -LiteralPath $uninstallKey -Name DisplayVersion -ErrorAction Stop).DisplayVersion
    if($displayVersion-ne$version){throw "Upgrade DisplayVersion mismatch: $displayVersion"}

    Set-ItemProperty -LiteralPath $uninstallKey -Name DisplayVersion -Value '9.9.9'
    $blocked=Run-Setup 'blocked-downgrade' -ExpectBlocked
    Assert-Contains $blocked.Text ("SOKNA_INSTALL_MODE=BLOCK_DOWNGRADE 9.9.9 > "+$version) 'downgrade block mode'
    $displayVersion=[string](Get-ItemProperty -LiteralPath $uninstallKey -Name DisplayVersion -ErrorAction Stop).DisplayVersion
    if($displayVersion-ne'9.9.9'){throw 'Blocked downgrade unexpectedly rewrote installed version metadata.'}

    Set-ItemProperty -LiteralPath $uninstallKey -Name DisplayVersion -Value $version
    Set-Content -LiteralPath (Join-Path $DiagnosticsRoot 'installer-awareness-success.txt') -Value 'PASS' -Encoding UTF8
    Write-Host "WINDOWS SERVICES $version INSTALLER AWARENESS: PASS"
}
finally {
    $uninstaller=Join-Path $installRoot 'unins000.exe'
    if(Test-Path -LiteralPath $uninstaller -PathType Leaf){
        try { Start-Process -FilePath $uninstaller -ArgumentList @('/VERYSILENT','/NORESTART') -Wait -PassThru|Out-Null } catch {}
    }
    if(Test-Path -LiteralPath $uninstallKey){Remove-Item -LiteralPath $uninstallKey -Recurse -Force -ErrorAction SilentlyContinue}
    Remove-Item -LiteralPath $installRoot -Recurse -Force -ErrorAction SilentlyContinue
}
