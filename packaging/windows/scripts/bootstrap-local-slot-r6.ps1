param([Parameter(Mandatory=$true)][string]$PlanFile)
$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest

function Safe([string]$v){
  $v=([string]$v-replace'[\r\n\0]',' ').Trim()
  $v=$v-replace'(?i)(token|secret|credential|password|signature)(\s*[:=]\s*)[^\s,;\}\]]+','$1$2[REDACTED]'
  if($v.Length-gt1600){$v=$v.Substring(0,1600)}
  return $v
}
function Write-Json([string]$path,$value){$value|ConvertTo-Json -Depth 12|Set-Content -LiteralPath $path -Encoding UTF8}
function Sha256-Bytes([byte[]]$bytes){
  $sha=[Security.Cryptography.SHA256]::Create()
  try{$digest=$sha.ComputeHash($bytes)}finally{$sha.Dispose()}
  return (([BitConverter]::ToString($digest))-replace'-','').ToLowerInvariant()
}
function Hmac-Sha256([byte[]]$key,[string]$text){
  $hmac=New-Object Security.Cryptography.HMACSHA256
  try{$hmac.Key=$key;$digest=$hmac.ComputeHash([Text.Encoding]::UTF8.GetBytes($text))}finally{$hmac.Dispose()}
  return (([BitConverter]::ToString($digest))-replace'-','').ToLowerInvariant()
}
function Service-Exe([string]$image){
  $text=[Environment]::ExpandEnvironmentVariables(([string]$image).Trim())
  if($text-match'^\s*"([^"]+\.exe)"'){return [IO.Path]::GetFullPath([string]$Matches[1])}
  if($text-match'^\s*([^\s"]+\.exe)'){return [IO.Path]::GetFullPath([string]$Matches[1])}
  return ''
}
function Evidence([string]$status,[string]$code,[string]$detail,$extra=$null){
  $row=[ordered]@{
    format='sokna-local-slot-bootstrap-evidence-v1'
    schema_version=1
    at_utc=[DateTime]::UtcNow.ToString('o')
    status=$status
    code=$code
    detail=(Safe $detail)
    activation_performed=$false
    database_mutation_performed=$false
    public_mutation_performed=$false
    windows_reboot_required=$false
  }
  if($null-ne$extra){foreach($p in $extra.PSObject.Properties){$row[$p.Name]=$p.Value}}
  Write-Json $EvidencePath $row
}

$PlanFile=[IO.Path]::GetFullPath($PlanFile)
if(-not(Test-Path -LiteralPath $PlanFile -PathType Leaf)){throw 'Install plan is missing.'}
$plan=Get-Content -LiteralPath $PlanFile -Raw|ConvertFrom-Json
if([string]$plan.format-ne'sokna-windows-install-plan-v3'){throw 'Unsupported install plan.'}
$ShellRoot=[IO.Path]::GetFullPath([string]$plan.shell_root)
$Tx=[string]$plan.transaction_id
$TxDir=[IO.Path]::GetDirectoryName($PlanFile)
$EvidencePath=Join-Path $TxDir 'local-slot-bootstrap.json'
$ContractPath=Join-Path $ShellRoot 'local-slot-bootstrap-contract-v1.json'
if(-not(Test-Path -LiteralPath $ContractPath -PathType Leaf)){Evidence 'failed_pre_mutation' 'BOOTSTRAP_CONTRACT_MISSING' 'Bootstrap contract is missing.';exit 20}
$contract=Get-Content -LiteralPath $ContractPath -Raw|ConvertFrom-Json
if([string]$contract.format-ne'sokna-local-slot-bootstrap-contract-v1'-or[int]$contract.schema_version-ne1){Evidence 'failed_pre_mutation' 'BOOTSTRAP_CONTRACT_INVALID' 'Bootstrap contract is invalid.';exit 20}
if([bool]$contract.database_mutation-or[bool]$contract.public_mutation-or[bool]$contract.windows_reboot_required){Evidence 'failed_pre_mutation' 'BOOTSTRAP_CONTRACT_UNSAFE' 'Bootstrap contract requests a forbidden mutation.';exit 20}

$apache=Get-CimInstance Win32_Service -Filter "Name='SoknaApache'" -ErrorAction SilentlyContinue
if(-not$apache){Evidence 'skipped' 'LOCAL_APACHE_NOT_INSTALLED' 'SoknaApache is not installed on this host.';exit 0}
$apacheExe=Service-Exe ([string]$apache.PathName)
if([string]::IsNullOrWhiteSpace($apacheExe)){Evidence 'skipped' 'LOCAL_ROOT_UNRESOLVED' 'SoknaApache executable path could not be resolved.';exit 0}
$normalized=$apacheExe.Replace('/','\')
$marker='\Infrastructure\'
$markerIndex=$normalized.IndexOf($marker,[StringComparison]::OrdinalIgnoreCase)
if($markerIndex-le2){Evidence 'skipped' 'LOCAL_ROOT_UNRESOLVED' 'SoknaApache is not under the qualified SOKNA Infrastructure boundary.';exit 0}
$packageRoot=$normalized.Substring(0,$markerIndex)
$webRoot=Join-Path $packageRoot 'Web'
$versionPath=Join-Path $webRoot 'VERSION.txt'
if(-not(Test-Path -LiteralPath $versionPath -PathType Leaf)){Evidence 'skipped' 'LOCAL_VERSION_UNAVAILABLE' 'Local VERSION.txt is unavailable.';exit 0}
$currentBuild=(Get-Content -LiteralPath $versionPath -Raw).Trim()
$entry=@($contract.entries|Where-Object{[string]$_.build-eq$currentBuild})|Select-Object -First 1
if(-not$entry){Evidence 'skipped' 'LOCAL_BASELINE_NOT_ALLOWLISTED' ("Current Local build is outside this bootstrap contract: "+$currentBuild);exit 0}

$common=[Environment]::GetFolderPath([Environment+SpecialFolder]::CommonApplicationData)
$maintenanceRoot=Join-Path $common 'SOKNA\Maintenance'
$descriptorPath=Join-Path $maintenanceRoot 'control-plane.json'
$keyPath=Join-Path $maintenanceRoot 'secrets\web-adapter.key'
$deadline=[DateTime]::UtcNow.AddSeconds(30)
while([DateTime]::UtcNow-lt$deadline-and-not(Test-Path -LiteralPath $descriptorPath -PathType Leaf)){Start-Sleep -Milliseconds 250}
if(-not(Test-Path -LiteralPath $descriptorPath -PathType Leaf)){Evidence 'failed_pre_mutation' 'SUPERVISOR_DESCRIPTOR_UNAVAILABLE' 'Maintenance control-plane descriptor was not published after Windows component commit.';exit 20}
if(-not(Test-Path -LiteralPath $keyPath -PathType Leaf)){Evidence 'failed_pre_mutation' 'WEB_ADAPTER_KEY_UNAVAILABLE' 'Maintenance web-adapter key is unavailable to the elevated installer.';exit 20}

$descriptor=Get-Content -LiteralPath $descriptorPath -Raw|ConvertFrom-Json
$baseUrl=[string]$descriptor.base_url
$uri=$null
if(-not[Uri]::TryCreate($baseUrl,[UriKind]::Absolute,[ref]$uri)-or$uri.Scheme-ne'http'-or-not($uri.Host-eq'127.0.0.1'-or$uri.Host-eq'localhost')){Evidence 'failed_pre_mutation' 'SUPERVISOR_DESCRIPTOR_INVALID' 'Maintenance descriptor did not resolve to loopback HTTP.';exit 20}
try{$key=[Convert]::FromBase64String((Get-Content -LiteralPath $keyPath -Raw).Trim())}catch{Evidence 'failed_pre_mutation' 'WEB_ADAPTER_KEY_INVALID' 'Maintenance web-adapter key could not be decoded.';exit 20}
if($key.Length-ne32){Evidence 'failed_pre_mutation' 'WEB_ADAPTER_KEY_INVALID' 'Maintenance web-adapter key length is invalid.';exit 20}

$bodyObject=[ordered]@{
  expected_build=[string]$entry.build
  expected_managed_files=[int]$entry.managed_files
  expected_managed_tree_sha256=[string]$entry.managed_tree_sha256
  operator_id='windows-installer-r6'
}
$bodyText=$bodyObject|ConvertTo-Json -Compress
$bodyBytes=[Text.Encoding]::UTF8.GetBytes($bodyText)
$bodyHash=Sha256-Bytes $bodyBytes
$timestamp=[DateTimeOffset]::UtcNow.ToString('O',[Globalization.CultureInfo]::InvariantCulture)
$nonce=[guid]::NewGuid().ToString('N')
$relative='/v1/local-slots/migrate'
$canonical=('POST'+"`n"+$relative+"`n"+'1.0'+"`n"+$timestamp+"`n"+$nonce+"`n"+$bodyHash)
$signature=Hmac-Sha256 $key $canonical
$idempotency=('installer-r6-'+($Tx-replace'[^A-Za-z0-9_.:-]','-'))
$requestUrl=(New-Object Uri($uri,$relative)).AbsoluteUri

$response=$null
$responseText=''
$status=0
try{
  $request=[Net.HttpWebRequest]::Create($requestUrl)
  $request.Proxy=$null
  $request.Method='POST'
  $request.ContentType='application/json; charset=utf-8'
  $request.Accept='application/json'
  $request.Timeout=190000
  $request.ReadWriteTimeout=190000
  $request.Headers.Add('X-Sokna-Maintenance-Protocol','1.0')
  $request.Headers.Add('X-Sokna-Timestamp',$timestamp)
  $request.Headers.Add('X-Sokna-Nonce',$nonce)
  $request.Headers.Add('X-Sokna-Key-Id','web-adapter')
  $request.Headers.Add('X-Sokna-Body-SHA256',$bodyHash)
  $request.Headers.Add('X-Sokna-Signature',$signature)
  $request.Headers.Add('X-Sokna-Idempotency-Key',$idempotency)
  $request.ContentLength=$bodyBytes.Length
  $stream=$request.GetRequestStream();try{$stream.Write($bodyBytes,0,$bodyBytes.Length)}finally{$stream.Dispose()}
  try{$response=$request.GetResponse()}catch [Net.WebException]{if($_.Exception.Response){$response=$_.Exception.Response}else{throw}}
  $status=[int]$response.StatusCode
  $reader=New-Object IO.StreamReader($response.GetResponseStream(),[Text.Encoding]::UTF8)
  try{$responseText=$reader.ReadToEnd()}finally{$reader.Dispose();$response.Dispose()}
}catch{
  Evidence 'failed_pre_mutation' 'SUPERVISOR_REQUEST_FAILED' $_.Exception.Message
  exit 20
}

try{$json=$responseText|ConvertFrom-Json}catch{Evidence 'failed_pre_mutation' 'SUPERVISOR_RESPONSE_INVALID' ("HTTP "+$status+" response was not valid JSON.");exit 20}
$operationId=[string]$json.operation_id
$state=[string]$json.state
$code=[string]$json.code
$requiresRecovery=[bool]$json.requires_recovery_console
$activation=$false
try{$activation=[bool]$json.data.operation.activation_performed}catch{}
$extra=[pscustomobject]@{http_status=$status;operation_id=$operationId;operation_state=$state;result_code=$code;requires_recovery_console=$requiresRecovery;activation_performed=$activation;expected_build=[string]$entry.build;managed_tree_sha256=[string]$entry.managed_tree_sha256}
if($status-ge200-and$status-lt300-and[bool]$json.ok-and$state-eq'committed'){
  Evidence 'committed' $code 'Production Local slot migration committed through Maintenance Supervisor.' $extra
  exit 0
}
if($requiresRecovery-or$state-eq'recovery_required'){
  Evidence 'recovery_required' $code 'Production Local slot migration requires Maintenance recovery.' $extra
  exit 21
}
Evidence 'failed' $code ("Production Local slot migration was not committed. HTTP="+$status+" state="+$state) $extra
exit 20
