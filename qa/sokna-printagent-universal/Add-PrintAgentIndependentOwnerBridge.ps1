[CmdletBinding()]
param([Parameter(Mandatory=$true)][string]$CorePath)
$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest
if(!(Test-Path -LiteralPath $CorePath -PathType Leaf)){throw 'R238_CORE_MISSING'}
$raw=[IO.File]::ReadAllText($CorePath,[Text.Encoding]::UTF8)
$older="if(`$cmp-gt0){`$action='BLOCKED';`$reason='installed_newer'}"
$newer="if(`$cmp-gt0){if(`$id-eq'print-agent'-and([string]`$plan.mode-ne'repair')-and(Verified-IndependentPrintAgentReceipt `$m `$installedHash)){`$action='KEEP';`$reason='independent_print_agent_6212'}else{`$action='BLOCKED';`$reason='installed_newer'}}"
if(([regex]::Matches($raw,[regex]::Escape($older))).Count -ne 1){throw 'R238_CORE_NEWER_VERSION_PATTERN_NOT_EXACTLY_ONCE'}
$raw=$raw.Replace($older,$newer)
$oldCompat='return (Verified-IndependentMaintenancePair $m $actual)}'
$newCompat='return ((Verified-IndependentMaintenancePair $m $actual) -or (Verified-IndependentPrintAgentReceipt $m $actual))}'
if(([regex]::Matches($raw,[regex]::Escape($oldCompat))).Count -ne 1){throw 'R238_CORE_COMPATIBILITY_PREDICATE_NOT_EXACTLY_ONCE'}
$raw=$raw.Replace($oldCompat,$newCompat)
$needle='function Compatible-Fingerprint('
if(([regex]::Matches($raw,[regex]::Escape($needle))).Count -ne 1){throw 'R238_CORE_PREDICATE_INSERTION_ANCHOR_NOT_EXACTLY_ONCE'}
$proof=@'
function Verified-IndependentPrintAgentReceipt($m,[string]$actual){
  if([string](Prop $m 'component_id' '')-ne'print-agent'-or
     [string](Prop $m 'version' '')-ne'6.2.10'-or
     $actual-ne'b28f0028f3be5f0bf4a1ee49d5ddce06e60b95a56f28300d4ac17b05f9f6051b'){return $false}
  try{
    $componentRoot=Join-Path $InstallRoot 'PrintAgent'
    $svcExe=Join-Path $componentRoot 'Service\Sokna.PrintAgent.Service.exe'
    $worker=Join-Path $componentRoot 'Worker\Sokna.PrintAgent.Worker.exe'
    $manifestPath=Join-Path $componentRoot 'component-manifest.json'
    $receiptPath=Join-Path $DataRoot 'setup\print-agent-independent-v1\active.json'
    foreach($path in @($svcExe,$worker,$manifestPath,$receiptPath)){
      if(!(Test-Path -LiteralPath $path -PathType Leaf)){return $false}
      if(((Get-Item -LiteralPath $path -Force).Attributes-band[IO.FileAttributes]::ReparsePoint)-ne0){return $false}
    }
    $hService=Sha $svcExe;$hWorker=Sha $worker;$hManifest=Sha $manifestPath
    if($hService-ne'b28f0028f3be5f0bf4a1ee49d5ddce06e60b95a56f28300d4ac17b05f9f6051b'-or
       $hWorker-ne'cc638a50f191374a015341b27b48b5dccdf0f3017481a7b0454e2c67ea95873b'-or
       $hManifest-ne'6de6b7d54fceb4dddcc7344bafbcb42d9df385ad0359f8d6e19f52e634039e0c'-or
       (VersionOf $svcExe)-ne'6.2.12'){return $false}
    $r=Get-Content -LiteralPath $receiptPath -Raw |ConvertFrom-Json
    $tx=[Guid]::Empty
    if([string]$r.format-ne'sokna-printagent-independent-receipt-v1'-or
       [int]$r.schema_version-ne1-or[string]$r.status-ne'COMMITTED'-or
       -not[Guid]::TryParseExact([string]$r.transaction_id,'N',[ref]$tx)-or
       [string]$r.target_version-ne'6.2.12'-or[string]$r.service_name-ne'SoknaPrintWorker'-or
       -not[bool]$r.programdata_preserved-or-not[bool]$r.maintenance_unchanged-or-not[bool]$r.runtime_unchanged-or
       [string]$r.service_sha256-ne$hService-or[string]$r.worker_sha256-ne$hWorker-or
       [string]$r.component_manifest_sha256-ne$hManifest-or
       [IO.Path]::GetFullPath([string]$r.installed_root)-ne[IO.Path]::GetFullPath($componentRoot)){return $false}
    $manifest=Get-Content -LiteralPath $manifestPath -Raw|ConvertFrom-Json
    if([string]$manifest.format-ne'sokna-print-agent-component-v1'-or[string]$manifest.version-ne'6.2.12'-or
       [string]$manifest.component-ne'print-agent'){return $false}
    $files=@($manifest.files)
    if($files.Count-lt2-or$files.Count-gt100){return $false}
    $seen=@{}
    foreach($row in $files){
      $rel=[string]$row.path
      if($rel-notmatch'^(Service|Worker)/[A-Za-z0-9._/-]+$'-or$rel.Contains('..')-or$seen.ContainsKey($rel.ToLowerInvariant())){return $false}
      $seen[$rel.ToLowerInvariant()]=$true
      $path=[IO.Path]::GetFullPath((Join-Path $componentRoot ($rel.Replace('/','\'))))
      if(-not$path.StartsWith(($componentRoot.TrimEnd('\')+'\'),[StringComparison]::OrdinalIgnoreCase)-or
         !(Test-Path -LiteralPath $path -PathType Leaf)-or
         (Sha $path)-ne[string]$row.sha256-or
         (Get-Item -LiteralPath $path).Length-ne[long]$row.size){return $false}
    }
    if(-not$seen.ContainsKey('service/sokna.printagent.service.exe')-or-not$seen.ContainsKey('worker/sokna.printagent.worker.exe')){return $false}
    if(@(Get-ChildItem -LiteralPath $componentRoot -Recurse -File).Count-ne($files.Count+1)){return $false}
    $service=Service-Info 'SoknaPrintWorker'
    return $service.Installed -and [string]$service.State-eq'Running' -and
       (Under-OwnedRoot ([string]$service.ImagePath) $InstallRoot) -and
       (Registration-Matches 'print-agent' ([string]$service.ImagePath) $svcExe $DataRoot)
  }catch{return $false}
}
'@.Trim()
$raw=$raw.Replace($needle,($proof+"`r`n"+$needle))
[IO.File]::WriteAllText($CorePath,$raw,(New-Object Text.UTF8Encoding($true)))
Write-Host 'PASS_R238_PRIVILEGED_OWNER_BRIDGE_INJECTED_BYTE_PINNED_FAIL_CLOSED'