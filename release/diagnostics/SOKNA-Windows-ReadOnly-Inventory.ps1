# SOKNA Windows Services Universal Read-Only Inventory v1
# Identical for Home, Cafe and future independent installations.
# Windows PowerShell 5.1 / PowerShell 7; no elevated privileges required.
# Never invokes an updater, restarts services, modifies registry or reads secrets.
[CmdletBinding()]
param()
$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest
function Service-Info([string]$name) {
  $base=[ordered]@{name=$name;installed=$false;state='absent';start_mode=$null;
    binary_path=$null;binary_exists=$null;product_version=$null;file_version=$null;binary_sha256=$null;
    observation_error=$null}
  try {
    $s=Get-CimInstance -ClassName Win32_Service -Filter ("Name='"+$name+"'") -ErrorAction Stop
    if($null -eq $s){return $base}
    $base.installed=$true;$base.state=[string]$s.State;$base.start_mode=[string]$s.StartMode
    # SCM path only. Do not save raw command line; it might contain arguments.
    $raw=[string]$s.PathName
    $exe=$null
    if($raw -match '^"([^"]+?\.exe)"(?:\s|$)'){$exe=$Matches[1]}
    elseif($raw -match '^(.+?\.exe)(?:\s|$)'){$exe=$Matches[1]}
    if(-not $exe){$base.observation_error='SCM_BINARY_PATH_UNPARSEABLE';return $base}
    $full=[IO.Path]::GetFullPath([Environment]::ExpandEnvironmentVariables($exe))
    $base.binary_path=$full
    $base.binary_exists=Test-Path -LiteralPath $full -PathType Leaf
    if($base.binary_exists){
      $v=[Diagnostics.FileVersionInfo]::GetVersionInfo($full)
      $base.product_version=[string]$v.ProductVersion
      $base.file_version=[string]$v.FileVersion
      $base.binary_sha256=(Get-FileHash -LiteralPath $full -Algorithm SHA256).Hash.ToLowerInvariant()
    }else{$base.observation_error='SCM_BINARY_MISSING'}
  } catch {$base.observation_error=$_.Exception.GetType().Name}
  return $base
}
$services=[ordered]@{}
foreach($name in @('SoknaMaintenanceSupervisor','SoknaRuntime','SoknaPrintWorker','SoknaApache')){
  $services[$name]=Service-Info $name
}
$uninstall=@()
foreach($root in @('HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall',
                  'HKLM:\SOFTWARE\WOW6432Node\Microsoft\Windows\CurrentVersion\Uninstall')){
  try {
    foreach($item in (Get-ChildItem -LiteralPath $root -ErrorAction SilentlyContinue)){
      try {
        $v=Get-ItemProperty -LiteralPath $item.PSPath -ErrorAction Stop
        if([string]$v.DisplayName -like 'SOKNA Windows Services*'){
          $uninstall+=([ordered]@{display_name=[string]$v.DisplayName;
            display_version=[string]$v.DisplayVersion;
            install_location=[string]$v.InstallLocation})
        }
      } catch {}
    }
  } catch {}
}
$installState=[ordered]@{present=$false;package_version=$null;installed_at_utc=$null;
  install_root=$null;data_root=$null;observation_error=$null}
try {
  # Read only a product-owned, locally registered path; do not scan drives.
  $reg='HKLM:\SOFTWARE\Sokna\Local\PrintWorker'
  if(Test-Path -LiteralPath $reg){
    $v=Get-ItemProperty -LiteralPath $reg -ErrorAction Stop
    $root=[string]$v.DataRoot
    if($root -and [IO.Path]::IsPathRooted($root)){
      $dataRoot=[IO.Path]::GetDirectoryName([IO.Path]::GetFullPath($root.TrimEnd('\')))
      $statePath=Join-Path $dataRoot 'setup\windows-services-state.json'
      if(Test-Path -LiteralPath $statePath -PathType Leaf){
        $st=Get-Content -LiteralPath $statePath -Raw -ErrorAction Stop|ConvertFrom-Json
        if([string]$st.format -eq 'sokna-windows-services-install-state-v2'){
          $installState.present=$true
          $installState.package_version=[string]$st.package_version
          $installState.installed_at_utc=[string]$st.installed_at_utc
          $installState.install_root=[string]$st.install_root
          $installState.data_root=[string]$st.data_root
        } else {$installState.observation_error='INSTALL_STATE_FORMAT_UNRECOGNIZED'}
      } else {$installState.observation_error='INSTALL_STATE_NOT_FOUND'}
    } else {$installState.observation_error='PRINT_DATA_ROOT_UNKNOWN'}
  }else{$installState.observation_error='PRINT_REGISTRATION_MISSING'}
}catch{$installState.observation_error=$_.Exception.GetType().Name}
$os=Get-CimInstance Win32_OperatingSystem -ErrorAction SilentlyContinue
$checks=[ordered]@{
  supervisor_installed=($services['SoknaMaintenanceSupervisor'].installed -eq $true)
  supervisor_binary_identified=($services['SoknaMaintenanceSupervisor'].binary_exists -eq $true)
  complete_installer_version_identified=($installState.package_version -ne $null -or $uninstall.Count -gt 0)
  any_unresolved_observation=($services.Values | Where-Object {$_.observation_error}).Count -gt 0
}
$document=[ordered]@{
 format='sokna-windows-universal-readonly-inventory-v1';schema_version=1
 collected_at_utc=[DateTime]::UtcNow.ToString('o')
 machine_type='independent_single_site';source='Windows SCM, executable metadata and Windows installation registry'
 os_caption=if($os){[string]$os.Caption}else{'unavailable'}
 services=$services;installed_products=$uninstall;install_state=$installState
 checks=$checks;no_mutation_performed=$true
 installation_authorized=$false;real_host_upgrade_uat_complete=$false
}
$desktop=[Environment]::GetFolderPath('Desktop')
if(-not $desktop -or -not(Test-Path -LiteralPath $desktop -PathType Container)){
  $desktop=[Environment]::GetFolderPath('UserProfile')
}
$file=Join-Path $desktop ('SOKNA-Windows-Inventory-'+[DateTime]::UtcNow.ToString('yyyyMMdd-HHmmss')+'.json')
$document|ConvertTo-Json -Depth 10|Set-Content -LiteralPath $file -Encoding UTF8
Write-Host 'Read-only inventory saved to:' $file
Write-Host 'No service, file, registry, database, or web configuration was changed.'
Write-Host 'Send the generated JSON to ChatGPT. Do not run a Windows upgrade yet.'
