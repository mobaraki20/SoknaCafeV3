[CmdletBinding()]
param(
  [Parameter(Mandatory=$true)][string]$ReceiptPath,
  [Parameter(Mandatory=$true)][string]$ExpectedPrintRoot,
  [switch]$RequireRunning
)
$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest
function Fail([string]$reason){throw ('PRINT_INDEPENDENT_RECEIPT_'+$reason)}
function Full([string]$p){[IO.Path]::GetFullPath($p).TrimEnd('\')}
function Sha([string]$p){if(!(Test-Path -LiteralPath $p -PathType Leaf)){Fail 'FILE_MISSING'};return (Get-FileHash -LiteralPath $p -Algorithm SHA256).Hash.ToLowerInvariant()}
function Reparse([string]$p){while(Test-Path -LiteralPath $p){$x=Get-Item -LiteralPath $p -Force;if(($x.Attributes -band [IO.FileAttributes]::ReparsePoint)-ne0){Fail 'UNSAFE_REPARSE'};$q=Split-Path $p -Parent;if(!$q -or $q -eq $p){break};$p=$q}}
if(!(Test-Path -LiteralPath $ReceiptPath -PathType Leaf)){Fail 'MISSING'}
Reparse (Full $ReceiptPath)
$r=Get-Content -LiteralPath $ReceiptPath -Raw -ErrorAction Stop|ConvertFrom-Json -ErrorAction Stop
if($r.format -ne 'sokna-printagent-independent-receipt-v1' -or [int]$r.schema_version -ne 1 -or [string]$r.status -ne 'COMMITTED'){Fail 'FORMAT_OR_STATUS_INVALID'}
$guid=[Guid]::Empty
if(-not[Guid]::TryParseExact([string]$r.transaction_id,'N',[ref]$guid)){Fail 'TX_INVALID'}
if($r.service_name -ne 'SoknaPrintWorker' -or $r.target_version -ne '6.2.12'){Fail 'OWNER_OR_VERSION_INVALID'}
if(!$r.programdata_preserved -or !$r.runtime_unchanged -or !$r.maintenance_unchanged){Fail 'SCOPE_NOT_ATTESTED'}
$root=Full $ExpectedPrintRoot
if((Full ([string]$r.installed_root)) -ne $root){Fail 'ROOT_MISMATCH'}
Reparse $root
$manifestPath=Join-Path $root 'component-manifest.json'
$serviceExe=Join-Path $root 'Service\Sokna.PrintAgent.Service.exe'
$workerExe=Join-Path $root 'Worker\Sokna.PrintAgent.Worker.exe'
$expectedManifest='6de6b7d54fceb4dddcc7344bafbcb42d9df385ad0359f8d6e19f52e634039e0c'
$expectedService='b28f0028f3be5f0bf4a1ee49d5ddce06e60b95a56f28300d4ac17b05f9f6051b'
$expectedWorker='cc638a50f191374a015341b27b48b5dccdf0f3017481a7b0454e2c67ea95873b'
if((Sha $manifestPath) -ne $expectedManifest -or [string]$r.component_manifest_sha256 -ne $expectedManifest){Fail 'MANIFEST_SHA_DRIFT'}
$m=Get-Content -LiteralPath $manifestPath -Raw |ConvertFrom-Json
if($m.format -ne 'sokna-print-agent-component-v1' -or $m.component -ne 'print-agent' -or $m.version -ne '6.2.12'){Fail 'COMPONENT_MANIFEST_INVALID'}
if((Sha $serviceExe) -ne $expectedService -or [string]$r.service_sha256 -ne $expectedService){Fail 'SERVICE_SHA_DRIFT'}
if((Sha $workerExe) -ne $expectedWorker -or [string]$r.worker_sha256 -ne $expectedWorker){Fail 'WORKER_SHA_DRIFT'}
$rows=@($m.files)
if($rows.Count -lt 2 -or $rows.Count -gt 100){Fail 'FILE_LIST_COUNT_INVALID'}
$seen=@{}
foreach($f in $rows){
  $rel=[string]$f.path
  if($rel -notmatch '^(Service|Worker)/[A-Za-z0-9._/-]+$' -or $rel.Contains('..')){Fail 'FILE_LIST_PATH_INVALID'}
  $key=$rel.ToLowerInvariant();if($seen.ContainsKey($key)){Fail 'FILE_LIST_DUPLICATE'}
  $seen[$key]=$true
  $full=Full (Join-Path $root ($rel.Replace('/','\')))
  if(!$full.StartsWith(($root+'\'),[StringComparison]::OrdinalIgnoreCase)){Fail 'FILE_LIST_OUTSIDE_ROOT'}
  Reparse $full
  if([long](Get-Item -LiteralPath $full).Length -ne [long]$f.size -or (Sha $full) -ne [string]$f.sha256){Fail 'FILE_LIST_HASH_DRIFT'}
}
$service=Get-CimInstance Win32_Service -Filter "Name='SoknaPrintWorker'" -ErrorAction Stop
if(!$service){Fail 'SERVICE_REGISTRATION_MISSING'}
if([string]$service.StartName -ne 'LocalSystem'){Fail 'SERVICE_ACCOUNT_MISMATCH'}
$raw=([string]$service.PathName).Trim()
if($raw -match '^"([^"]+\.exe)"(?:\s|$)'){$image=$Matches[1]}
elseif($raw -match '^([^\s"]+\.exe)(?:\s|$)'){$image=$Matches[1]}
else{Fail 'SERVICE_IMAGE_UNPARSEABLE'}
if((Full $image) -ne (Full $serviceExe)){Fail 'SERVICE_OWNER_MISMATCH'}
if($RequireRunning -and [string]$service.State -ne 'Running'){Fail 'SERVICE_NOT_RUNNING'}
[ordered]@{format='sokna-printagent-independent-receipt-verified-v1';status='VERIFIED';version='6.2.12';receipt_transaction_id=$r.transaction_id;service_sha256=$expectedService;worker_sha256=$expectedWorker;service_state=[string]$service.State;no_mutation=$true}|ConvertTo-Json -Compress