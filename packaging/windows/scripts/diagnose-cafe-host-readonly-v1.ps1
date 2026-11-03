param(
    [string]$InstallRoot = '',
    [string]$DataRoot = '',
    [string]$OutputRoot = ''
)
# SOKNA single-archive, read-only local host diagnostic.
# Does not invoke Stage, Activate, MariaDB, maintenance repair or service control.
$ErrorActionPreference = 'Stop'
Set-StrictMode -Version 2
# Protected Maintenance identity and operation ledger usually require admin.
# Fixture runs specifying both roots never elevate.
if (-not $InstallRoot -and -not $DataRoot) {
    $identity=[Security.Principal.WindowsIdentity]::GetCurrent()
    $principal=New-Object Security.Principal.WindowsPrincipal($identity)
    if(-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
        $self='"'+$PSCommandPath+'"'
        Start-Process -FilePath (Join-Path $PSHOME 'powershell.exe') -Verb RunAs -ArgumentList ('-NoProfile -ExecutionPolicy Bypass -File '+$self) -Wait
        exit 0
    }
}
function Fp([string]$p) {
    if (-not (Test-Path -LiteralPath $p -PathType Leaf)) { return $null }
    try {
        $f = Get-Item -LiteralPath $p -Force
        if (($f.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { return 'REDIRECTED' }
        if ($f.Length -gt 8MB) { return 'OVERSIZED' }
        return (Get-FileHash -LiteralPath $p -Algorithm SHA256).Hash.ToLowerInvariant()
    } catch { return 'UNREADABLE' }
}
function Json([string]$p) {
    if (-not (Test-Path -LiteralPath $p -PathType Leaf)) { return $null }
    try {
        $item = Get-Item -LiteralPath $p -Force
        if (($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0 -or $item.Length -gt 5MB) { return $null }
        return (Get-Content -LiteralPath $p -Raw -ErrorAction Stop | ConvertFrom-Json -ErrorAction Stop)
    } catch { return $null }
}
function Data([string]$path) {
    $hash=Fp $path
    return [ordered]@{ exists=($null -ne $hash); sha256=$hash }
}
function Pair([string]$web,[string]$persistent,[string]$name) {
    $a=Data (Join-Path $web $name)
    $b=Data (Join-Path $persistent $name)
    $match=if(-not $a.exists -and -not $b.exists){'BOTH_MISSING'}
           elseif(-not $a.exists -or -not $b.exists){'ONE_MISSING'}
           elseif($a.sha256 -in @('REDIRECTED','OVERSIZED','UNREADABLE') -or
                  $b.sha256 -in @('REDIRECTED','OVERSIZED','UNREADABLE')){'UNKNOWN'}
           elseif($a.sha256 -eq $b.sha256){'MATCH'}else{'DRIFT'}
    return [ordered]@{ name=$name; state=$match; active=$a; persistent=$b }
}
function SafeCode([object]$s){
    $v=[string]$s
    if($v -match '^[A-Za-z][A-Za-z0-9_.-]{0,99}$'){return $v}
    return 'UNAVAILABLE'
}
$common=Join-Path ([Environment]::GetFolderPath('CommonApplicationData')) 'SOKNA'
if(-not $DataRoot){$DataRoot=Join-Path $common 'Maintenance'}
if(-not $InstallRoot) {
    foreach($candidate in @('D:\SOKNA','C:\SOKNA')) {
        if(Test-Path -LiteralPath (Join-Path $candidate 'Web\VERSION.txt')) {$InstallRoot=$candidate;break}
    }
}
if(-not $OutputRoot){
    $OutputRoot=[Environment]::GetFolderPath('Desktop')
    if(-not $OutputRoot -or -not(Test-Path -LiteralPath $OutputRoot -PathType Container)){
        $OutputRoot=[Environment]::GetFolderPath('MyDocuments')
    }
}
if(-not $OutputRoot -or -not(Test-Path -LiteralPath $OutputRoot -PathType Container)) {throw 'OUTPUT_DIRECTORY_UNAVAILABLE'}
$web=if($InstallRoot){Join-Path $InstallRoot 'Web'}else{''}
$persistent=if($InstallRoot){Join-Path $InstallRoot 'LocalWeb\persistent'}else{''}
$report=[ordered]@{
  format='sokna-cafe-readonly-host-audit-v1'
  checked_at_utc=[DateTime]::UtcNow.ToString('o')
  does_not_authorize_install=$true
  machine_mutation_performed=$false
  database_inspected_or_changed=$false
  install_found=[bool]$web
  host_evidence='PARTIAL_READ_ONLY_DIAGNOSTIC_NOT_SUPERVISOR_AUTHORITY'
  active_web_reparse='UNKNOWN'
  apache_service='UNKNOWN'
  maintenance_service='UNKNOWN'
  local_version=''
  identity_present=$false
  identity_local_version=''
  identity_version_matches=$false
  managed_tree_state='NOT_CHECKED'
  managed_tree_calculated=''
  identity_tree_matches=$false
  overlays=@()
  ledger=[ordered]@{present=$false; recent_states=@(); blocking_found='UNKNOWN'}
  verified_backup='NOT_CHECKED'
  suspected_blockers=@()
  direct_recommendation='DO_NOT_REPEAT_STAGE_OR_ACTIVATE'
}
try {
  foreach($serviceName in @('SoknaApache','SoknaMaintenanceSupervisor')){
    try {
      $svc=Get-Service -Name $serviceName -ErrorAction Stop
      $state=[string]$svc.Status
    }catch {$state='UNAVAILABLE'}
    if($serviceName -eq 'SoknaApache'){$report.apache_service=$state}
    else{$report.maintenance_service=$state}
  }
  if(-not $InstallRoot -or -not(Test-Path -LiteralPath $web -PathType Container)){
    $report.suspected_blockers=@('LOCAL_WEB_ROOT_NOT_FOUND')
  } else {
    try {
      $attrs=(Get-Item -LiteralPath $web -Force).Attributes
      $report.active_web_reparse=if(($attrs -band [IO.FileAttributes]::ReparsePoint) -ne 0){'YES'}else{'NO'}
    }catch{$report.active_web_reparse='UNKNOWN'}
    try {
      $versionFile=Join-Path $web 'VERSION.txt'
      $raw=Get-Content -LiteralPath $versionFile -Raw -ErrorAction Stop
      if($raw.Length -lt 256 -and $raw.Trim() -match '^[A-Za-z0-9._-]{1,220}$'){
        $report.local_version=$raw.Trim()
      }
    }catch{}
    $identity=Json (Join-Path $DataRoot 'installed-release-identity.json')
    $report.identity_present=($null -ne $identity)
    if($null -ne $identity -and $null -ne $identity.local) {
      $value=[string]$identity.local.version
      if($value -match '^[A-Za-z0-9._-]{1,220}$'){$report.identity_local_version=$value}
      $report.identity_version_matches=($report.local_version -ne '' -and
          $report.identity_local_version -eq $report.local_version)
    }
    $report.overlays=@(
      Pair $web $persistent 'public\local-recovery.php'
      Pair $web $persistent 'public\local-update-runner.php'
    )
    # Exactly the same canonical inventory digest as LocalManagedTreeAttestation:
    # SHA256(UTF8(join(path + NUL + lowercase(file SHA256) + LF)) sorted ordinal).
    try {
      $inventory=Json (Join-Path $web 'resources\local-managed-files-v1.json')
      if($null -eq $inventory -or $inventory.format -ne 'sokna-local-managed-files-v1'){
        throw 'INVENTORY_CONTRACT_UNAVAILABLE'
      }
      [string[]]$names=@($inventory.files)
      if($names.Count -lt 1 -or $names.Count -gt 30000){throw 'INVENTORY_COUNT_OUT_OF_RANGE'}
      [Array]::Sort($names,[StringComparer]::Ordinal)
      $seen=New-Object 'System.Collections.Generic.HashSet[string]' ([StringComparer]::OrdinalIgnoreCase)
      $builder=New-Object Text.StringBuilder
      foreach($name in $names) {
        if($name -notmatch '^[a-zA-Z0-9._/-]+$' -or
            $name -match '(^|/)\.\.(/|$)' -or
            -not $seen.Add($name)){throw 'INVENTORY_PATH_UNSAFE'}
        $file=Join-Path $web ($name.Replace('/','\'))
        $h=Fp $file
        if($null -eq $h -or $h -in @('REDIRECTED','OVERSIZED','UNREADABLE')){
          throw 'INVENTORY_FILE_EVIDENCE_UNAVAILABLE'
        }
        [void]$builder.Append($name)
        [void]$builder.Append([char]0)
        [void]$builder.Append($h)
        [void]$builder.Append([char]10)
      }
      $utf8=[Text.Encoding]::UTF8.GetBytes($builder.ToString())
      $alg=[Security.Cryptography.SHA256]::Create()
      try{$tree=([BitConverter]::ToString($alg.ComputeHash($utf8))-replace '-','').ToLowerInvariant()}finally{$alg.Dispose()}
      $report.managed_tree_calculated=$tree
      $report.managed_tree_state='CALCULATED'
      if($null -ne $identity -and $null -ne $identity.local) {
        $expected=[string]$identity.local.managed_tree_sha256
        $report.identity_tree_matches=($expected -match '^[a-fA-F0-9]{64}$' -and $tree -eq $expected.ToLowerInvariant())
      }
    }catch {
      $report.managed_tree_state=SafeCode $_.Exception.Message
    }
    $blockers=New-Object 'System.Collections.Generic.List[string]'
    if($report.active_web_reparse -eq 'NO'){$blockers.Add('ACTIVE_R8_JUNCTION_UNQUALIFIED')}
    if(-not $report.identity_present -or -not $report.identity_version_matches -or
       ($report.managed_tree_state -eq 'CALCULATED' -and -not $report.identity_tree_matches)){
      $blockers.Add('INSTALLED_LOCAL_IDENTITY_OR_TREE_MISMATCH')
    }
    $bad=@($report.overlays|Where-Object{$_.state -in @('ONE_MISSING','BOTH_MISSING','DRIFT')})
    if($bad.Count){$blockers.Add('PERSISTENT_MACHINE_LOCAL_OVERLAY_DRIFT')}
    if($report.apache_service -ne 'Running'){$blockers.Add('SOKNA_APACHE_DISCOVERY_OR_HEALTH_UNPROVEN')}
    $report.suspected_blockers=@($blockers.ToArray())
  }
  $folder=Join-Path $DataRoot 'operations'
  if(Test-Path -LiteralPath $folder -PathType Container) {
    $report.ledger.present=$true
    $rows=New-Object Collections.Generic.List[object]
    foreach($f in @(Get-ChildItem -LiteralPath $folder -Filter 'op-*.json' -File|
                     Sort-Object LastWriteTimeUtc -Descending|Select-Object -First 15)) {
      $o=Json $f.FullName
      if($null -eq $o){continue}
      $type=SafeCode $o.operation_type
      $state=SafeCode $o.state
      $code=SafeCode $o.result_code
      $rows.Add([ordered]@{type=$type;state=$state;code=$code})
    }
    $report.ledger.recent_states=@($rows.ToArray())
    $report.ledger.blocking_found=[bool](@($rows.ToArray()|Where-Object{
      $_.state -in @('accepted','running','activating','recovering','recovery_required')
    }).Count)
    # Only recent 15 inspected: an apparently idle result is NOT authoritative.
    if(-not $report.ledger.blocking_found){$report.ledger.blocking_found='NOT_PROVEN_FROM_BOUNDED_HISTORY'}
  }
  $backup=Json (Join-Path $DataRoot 'backups\schedule-state.json')
  if($null -ne $backup){$report.verified_backup='STATE_PRESENT_NOT_A_VERIFIED_BACKUP'}
  else{$report.verified_backup='STATE_ABSENT_OR_UNREADABLE'}
}
finally {
  $temp=Join-Path $env:TEMP ('SOKNA-readonly-'+[guid]::NewGuid().ToString('N'))
  [void](New-Item -ItemType Directory -Path $temp)
  try{
    $json=$report|ConvertTo-Json -Depth 18
    [IO.File]::WriteAllText((Join-Path $temp 'readiness.json'),$json,[Text.UTF8Encoding]::new($false))
    [IO.File]::WriteAllText((Join-Path $temp 'README.txt'),
      'Read-only PARTIAL on-host audit. No installed code or database changed. Report suspected blockers only. Full Supervisor diagnosis may still be needed.',
      [Text.UTF8Encoding]::new($false))
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $file=Join-Path $OutputRoot ('SOKNA-Host-Audit-'+(Get-Date -Format 'yyyyMMdd-HHmmss')+'.zip')
    [IO.Compression.ZipFile]::CreateFromDirectory($temp,$file)
    Write-Host ('SOKNA_HOST_AUDIT_ZIP='+$file)
    if(-not $InstallRoot -or $report.suspected_blockers.Count -eq 0){
      Write-Host 'No conclusive host blocker found from read-only audit. DO NOT retry activation.'
    }else{
      Write-Host ('SUSPECTED_BLOCKERS='+($report.suspected_blockers -join ','))
    }
  } finally {Remove-Item -LiteralPath $temp -Recurse -Force -ErrorAction SilentlyContinue}
}
