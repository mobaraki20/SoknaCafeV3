param(
 [Parameter(Mandatory=$true)][string]$RepoRoot,
 [Parameter(Mandatory=$true)][string]$MaintenanceAppBundle,
 [Parameter(Mandatory=$true)][string]$OutputRoot
)
$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest
$RepoRoot=[IO.Path]::GetFullPath($RepoRoot)
$MaintenanceAppBundle=[IO.Path]::GetFullPath($MaintenanceAppBundle)
$OutputRoot=[IO.Path]::GetFullPath($OutputRoot)
if(-not(Test-Path -LiteralPath $MaintenanceAppBundle -PathType Container)){throw 'Maintenance app build missing.'}
if(Test-Path -LiteralPath $OutputRoot){throw 'Refusing to overwrite existing component release staging.'}
New-Item -ItemType Directory -Path (Join-Path $OutputRoot 'maintenance\app') -Force|Out-Null
Copy-Item -Path (Join-Path $MaintenanceAppBundle '*') -Destination (Join-Path $OutputRoot 'maintenance\app') -Recurse -Force
foreach($p in @('SoknaMaintenanceSupervisor.exe','SoknaMaintenanceUpdateEngine.exe')){
 $candidate=Join-Path $OutputRoot ('maintenance\app\'+$p)
 if(-not(Test-Path -LiteralPath $candidate -PathType Leaf)){throw "Missing required release component: $p"}
 $v=[string]([Diagnostics.FileVersionInfo]::GetVersionInfo($candidate).FileVersion)
 if($v-ne'0.3.10.0'){throw "Component version does not match 0.3.10.0: $p ($v)"}
}
$files=@(Get-ChildItem -LiteralPath (Join-Path $OutputRoot 'maintenance\app') -Recurse -File | Sort-Object FullName | ForEach-Object{
 $rel=$_.FullName.Substring($OutputRoot.Length+1).Replace('\','/')
 [ordered]@{path=$rel;size_bytes=[long]$_.Length;sha256=(Get-FileHash -LiteralPath $_.FullName -Algorithm SHA256).Hash.ToLowerInvariant()}
})
if($files.Count-lt2){throw 'Empty Maintenance release.'}
$release=[ordered]@{
 format='sokna-maintenance-only-installer-v1';schema_version=1;component_version='0.3.10'
 release_scope='maintenance_only';manager_changes=$false;runtime_changes=$false;print_agent_changes=$false;recovery_console_changes=$false
 files=$files
}
[IO.File]::WriteAllText((Join-Path $OutputRoot 'component-install-manifest-v1.json'),($release|ConvertTo-Json -Depth 12),[Text.UTF8Encoding]::new($false))
$manifest=[ordered]@{
 format='sokna-windows-component-manifest-v2';schema_version=2;package_version='0.3.10';release_scope='maintenance_only'
 components=@(
 [ordered]@{component_id='maintenance';primary=$true;parent_component='';version='0.3.10';payload_primary='_payload/maintenance/app/SoknaMaintenanceSupervisor.exe';installed_primary='Maintenance/App/SoknaMaintenanceSupervisor.exe';service_name='SoknaMaintenanceSupervisor';transaction_group='maintenance';target_sha256=''},
 [ordered]@{component_id='maintenance-update-engine';primary=$false;parent_component='maintenance';version='0.3.10';payload_primary='_payload/maintenance/app/SoknaMaintenanceUpdateEngine.exe';installed_primary='Maintenance/App/SoknaMaintenanceUpdateEngine.exe';service_name='';transaction_group='maintenance';target_sha256=''}
 )
}
foreach($c in $manifest.components){
 $c.target_sha256=(Get-FileHash -LiteralPath (Join-Path $OutputRoot (([string]$c.payload_primary).Replace('_payload/','').Replace('/','\'))) -Algorithm SHA256).Hash.ToLowerInvariant()
 $c.payload_primary=([string]$c.payload_primary).Replace('_payload/','')
}
[IO.File]::WriteAllText((Join-Path $OutputRoot 'windows-services-component-manifest-v2.json'),($manifest|ConvertTo-Json -Depth 12),[Text.UTF8Encoding]::new($false))
foreach($name in @('invoke-maintenance-only-v1.ps1','setup-windows-components-maintenance-v1.ps1')){
 $src=Join-Path $RepoRoot ('packaging\windows\scripts\'+$name)
 $txt=[IO.File]::ReadAllText($src,[Text.Encoding]::UTF8)
 [IO.File]::WriteAllText((Join-Path $OutputRoot $name),$txt,[Text.UTF8Encoding]::new($true))
}
Write-Host ('Prepared scoped Maintenance-only bundle, file count: '+$files.Count)
