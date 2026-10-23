# Installation metadata belongs to the Windows component lifecycle. A Local
# release identity must retain its Local/Public identities when binaries change.
function Sync-MaintenanceInstalledVersions([string]$AppRoot,[string]$EvidenceRoot){
 $ErrorActionPreference='Stop'
 $store=Join-Path ([Environment]::GetFolderPath('CommonApplicationData')) 'SOKNA\Maintenance'
 $identityPath=Join-Path $store 'installed-release-identity.json'
 if(-not(Test-Path -LiteralPath $identityPath -PathType Leaf)){return} # No fabricated first-bind.
 $AppRoot=[IO.Path]::GetFullPath($AppRoot)
 $svc=Get-CimInstance Win32_Service -Filter "Name='SoknaMaintenanceSupervisor'" -ErrorAction Stop
 if(-not$svc -or $svc.State-ne'Running'){throw 'COMPONENT_IDENTITY_RUNNING_SERVICE_REQUIRED'}
 $raw=[Environment]::ExpandEnvironmentVariables(([string]$svc.PathName).Trim())
 if($raw-match '^"([^"]+\.exe)"'){$image=$Matches[1]}
 elseif($raw-match '^([^\s"]+\.exe)'){$image=$Matches[1]}
 else{throw 'COMPONENT_IDENTITY_SERVICE_PATH_INVALID'}
 $super=Join-Path $AppRoot 'SoknaMaintenanceSupervisor.exe'
 $engine=Join-Path $AppRoot 'SoknaMaintenanceUpdateEngine.exe'
 if(-not[IO.Path]::GetFullPath($image).Equals($super,[StringComparison]::OrdinalIgnoreCase) -or
    [IO.Path]::GetFileName($AppRoot)-cne'App' -or [IO.Path]::GetFileName([IO.Path]::GetDirectoryName($AppRoot))-cne'Maintenance'){
  throw 'COMPONENT_IDENTITY_SERVICE_OWNERSHIP_UNPROVEN'
 }
 foreach($p in @($AppRoot,$super,$engine,$store,$identityPath)){
  if(-not(Test-Path -LiteralPath $p) -or ((Get-Item -LiteralPath $p -Force).Attributes-band[IO.FileAttributes]::ReparsePoint)){
   throw 'COMPONENT_IDENTITY_UNSAFE_PATH'
  }
 }
 $versions=@();$hashes=@()
 foreach($p in @($super,$engine)){
  $v=[string]([Diagnostics.FileVersionInfo]::GetVersionInfo($p).FileVersion)
  if($v-ne'0.3.13.0'){throw 'COMPONENT_IDENTITY_PHYSICAL_0313_REQUIRED'}
  $versions+='0.3.13'
  $hashes+=(Get-FileHash -LiteralPath $p -Algorithm SHA256).Hash.ToLowerInvariant()
 }
 Assert-MaintenanceSignedHealth -ExpectedVersion '0.3.13' -Attempts 10
 $update=Join-Path $store 'update'
 New-Item -ItemType Directory -Path $update -Force|Out-Null
 $lockPath=Join-Path $update 'machine-mutation.lock'
 foreach($p in @($update,$lockPath)){
  if((Test-Path -LiteralPath $p)-and((Get-Item -LiteralPath $p -Force).Attributes-band[IO.FileAttributes]::ReparsePoint)){throw 'COMPONENT_IDENTITY_UNSAFE_LEASE'}
 }
 # Same file-handle lease as the Supervisor and Engine. Never remove it.
 $lease=[IO.File]::Open($lockPath,[IO.FileMode]::OpenOrCreate,[IO.FileAccess]::ReadWrite,[IO.FileShare]::Read)
 try{
  $ops=Join-Path $store 'operations'
  $records=@(Get-ChildItem -LiteralPath $ops -Filter 'op-*.json' -File -ErrorAction Stop)
  if($records.Count-gt20000){throw 'COMPONENT_IDENTITY_OPERATION_LIMIT'}
  foreach($file in $records){
   $op=[IO.File]::ReadAllText($file.FullName)|ConvertFrom-Json -ErrorAction Stop
   if($op.format-ne'sokna-maintenance-operation-v1' -or $op.schema_version-ne1 -or
      $op.operation_id-notmatch'^[a-f0-9]{32}$' -or $file.Name-cne('op-'+$op.operation_id+'.json')){throw 'COMPONENT_IDENTITY_OPERATION_CORRUPT'}
   if($op.state-in@('accepted','running','activating','recovering','recovery_required')){throw 'COMPONENT_IDENTITY_UPDATER_BLOCKED'}
  }
  $before=[IO.File]::ReadAllBytes($identityPath)
  $doc=[Text.Encoding]::UTF8.GetString($before)|ConvertFrom-Json -ErrorAction Stop
  $names=@('maintenance_supervisor_version','update_engine_version')
  foreach($name in $names){
   if(-not$doc.PSObject.Properties[$name] -or [string]$doc.$name-notmatch'^0\.3\.(?:[0-9]|1[0-3])$'){
    throw 'COMPONENT_IDENTITY_PREVIOUS_VERSION_UNQUALIFIED'
   }
  }
  if($doc.maintenance_supervisor_version-eq'0.3.13' -and $doc.update_engine_version-eq'0.3.13'){return}
  $beforeSemantic=$doc|ConvertTo-Json -Depth 100 -Compress
  $prior=@([string]$doc.maintenance_supervisor_version,[string]$doc.update_engine_version)
  $doc.maintenance_supervisor_version=$versions[0];$doc.update_engine_version=$versions[1]
  $after=($doc|ConvertTo-Json -Depth 100)+"`n"
  # Prove the only semantic changes are the two component versions.
  $check=$after|ConvertFrom-Json
  $check.maintenance_supervisor_version=$prior[0];$check.update_engine_version=$prior[1]
  if(($check|ConvertTo-Json -Depth 100 -Compress)-cne$beforeSemantic){throw 'COMPONENT_IDENTITY_SCOPE_VIOLATION'}
  New-Item -ItemType Directory -Path $EvidenceRoot -Force|Out-Null
  $backup=Join-Path $EvidenceRoot 'installed-release-identity.before.json'
  $out=[IO.File]::Open($backup,[IO.FileMode]::CreateNew,[IO.FileAccess]::Write,[IO.FileShare]::None)
  try{$out.Write($before,0,$before.Length);$out.Flush($true)}finally{$out.Dispose()}
  if([Convert]::ToBase64String([IO.File]::ReadAllBytes($identityPath))-cne[Convert]::ToBase64String($before)){throw 'COMPONENT_IDENTITY_CHANGED_DURING_SYNC'}
  foreach($i in @(0,1)){
   $p=@($super,$engine)[$i]
   if((Get-FileHash -LiteralPath $p -Algorithm SHA256).Hash.ToLowerInvariant()-cne$hashes[$i]){throw 'COMPONENT_IDENTITY_BINARY_CHANGED'}
  }
  $temp=$identityPath+'.tmp.'+[Guid]::NewGuid().ToString('N')
  $bytes=[Text.UTF8Encoding]::new($false).GetBytes($after)
  $out=[IO.FileStream]::new($temp,[IO.FileMode]::CreateNew,[IO.FileAccess]::Write,[IO.FileShare]::None,4096,[IO.FileOptions]::WriteThrough)
  try{$out.Write($bytes,0,$bytes.Length);$out.Flush($true)}finally{$out.Dispose()}
  # Atomic replace on the existing volume; the original also remains in evidence.
  [IO.File]::Replace($temp,$identityPath,(Join-Path $EvidenceRoot 'installed-release-identity.atomic-before.json'))
  if([IO.File]::ReadAllText($identityPath)-cne$after){throw 'COMPONENT_IDENTITY_READBACK_UNPROVEN'}
  $receipt=[ordered]@{format='sokna-maintenance-installed-version-sync-v1';at_utc=[DateTime]::UtcNow.ToString('o');previous_versions=$prior;observed_versions=$versions;observed_binary_sha256=$hashes;local_identity_changed=$false;public_identity_changed=$false;services_changed=$false;database_mutation_performed=$false}
  [IO.File]::WriteAllText((Join-Path $EvidenceRoot 'component-version-sync.json'),($receipt|ConvertTo-Json -Depth 10),[Text.UTF8Encoding]::new($false))
 }finally{$lease.Dispose()}
}
