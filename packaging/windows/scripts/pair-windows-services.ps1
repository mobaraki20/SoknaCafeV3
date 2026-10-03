param(
    [Parameter(Mandatory=$true)][string]$PlanFile
)
$ErrorActionPreference='Stop'
$RuntimeService='SoknaRuntime'
$PrintService='SoknaPrintWorker'

function Assert-Admin {
    $id=[Security.Principal.WindowsIdentity]::GetCurrent()
    $principal=New-Object Security.Principal.WindowsPrincipal($id)
    if(-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)){throw 'Administrator elevation is required.'}
}
function Full([string]$path,[string]$label){
    if([string]::IsNullOrWhiteSpace($path)-or-not[IO.Path]::IsPathFullyQualified($path)){throw "$label must be an absolute path."}
    return [IO.Path]::GetFullPath($path)
}
function Is-LoopbackOrigin([string]$value){
    $uri=$null
    if(-not[Uri]::TryCreate(($value??'').Trim(),[UriKind]::Absolute,[ref]$uri)){return $false}
    if(-not$uri.IsLoopback){return $false}
    if($uri.Scheme-ne'http'-and$uri.Scheme-ne'https'){return $false}
    if($uri.Port-lt1024-or$uri.Port-gt65535){return $false}
    if($uri.AbsolutePath-ne'/'-or$uri.Query-or$uri.Fragment-or$uri.UserInfo){return $false}
    return $true
}
function Service-Image([string]$name){
    try{return [string](Get-ItemProperty -LiteralPath ("HKLM:\SYSTEM\CurrentControlSet\Services\"+$name) -ErrorAction Stop).ImagePath}catch{return ''}
}
function Normalize-CommandPath([string]$command){
    $value=($command??'').Trim()
    if($value.StartsWith('"')){
        $end=$value.IndexOf('"',1)
        if($end-gt1){return $value.Substring(1,$end-1)}
    }
    $space=$value.IndexOf(' ')
    return $(if($space-gt0){$value.Substring(0,$space)}else{$value})
}
function Stop-ServiceSafe([string]$name){
    $svc=Get-Service -Name $name -ErrorAction SilentlyContinue
    if(-not$svc){throw "Required service is not installed: $name"}
    try{
        if($svc.Status-ne'Stopped'){
            Stop-Service -Name $name -ErrorAction Stop
            $svc.WaitForStatus('Stopped',[TimeSpan]::FromSeconds(30))
        }
    }finally{$svc.Dispose()}
}
function Start-ServiceSafe([string]$name){
    Start-Service -Name $name -ErrorAction Stop
    $svc=Get-Service -Name $name -ErrorAction Stop
    try{$svc.WaitForStatus('Running',[TimeSpan]::FromSeconds(20))}finally{$svc.Dispose()}
}
function Write-Secret([string]$path,[string]$value){
    if([string]::IsNullOrWhiteSpace($value)-or$value.Length-lt32-or$value.Length-gt512){throw 'Pairing secret length is invalid.'}
    New-Item -ItemType Directory -Path ([IO.Path]::GetDirectoryName($path)) -Force|Out-Null
    [IO.File]::WriteAllText($path,$value,(New-Object Text.UTF8Encoding($false)))
    & icacls.exe $path /inheritance:r /grant:r 'SYSTEM:(F)' 'Administrators:(F)'|Out-Null
}
function Write-PrivateJson([string]$path,[string]$json){
    New-Item -ItemType Directory -Path ([IO.Path]::GetDirectoryName($path)) -Force|Out-Null
    [IO.File]::WriteAllText($path,$json,(New-Object Text.UTF8Encoding($false)))
    & icacls.exe $path /inheritance:r /grant:r 'SYSTEM:(F)' 'Administrators:(F)'|Out-Null
}
function Read-BytesOrNull([string]$path){if(Test-Path -LiteralPath $path -PathType Leaf){return [IO.File]::ReadAllBytes($path)};return $null}
function Restore-Bytes([string]$path,[byte[]]$bytes){
    if($null-eq$bytes){Remove-Item -LiteralPath $path -Force -ErrorAction SilentlyContinue;return}
    New-Item -ItemType Directory -Path ([IO.Path]::GetDirectoryName($path)) -Force|Out-Null
    [IO.File]::WriteAllBytes($path,$bytes)
}
function Post-Pairing([Uri]$endpoint,[string]$action,[string]$code){
    $body=@{action=$action;pairing_code=$code}|ConvertTo-Json -Compress
    $headers=@{'X-Sokna-Windows-Services-Pairing'='1'}
    try{return Invoke-RestMethod -Method Post -Uri $endpoint -Headers $headers -ContentType 'application/json' -Body $body -TimeoutSec 12 -UseBasicParsing}
    catch{throw "Local Web pairing request failed ($action): $($_.Exception.Message)"}
}
function Validate-Bundle($bundle,[Uri]$baseUri){
    if($null-eq$bundle){throw 'Local Web did not return a pairing bundle.'}
    if([string]$bundle.format-ne'sokna-windows-services-pairing-v1'-or[int]$bundle.schema_version-ne1){throw 'Pairing bundle format is unsupported.'}
    foreach($name in @('runtime_token','local_token','print_agent_token')){
        $value=[string]$bundle.$name
        if([string]::IsNullOrWhiteSpace($value)-or$value.Length-lt32-or$value.Length-gt512-or$value.StartsWith('REPLACE_',[StringComparison]::OrdinalIgnoreCase)){throw "Pairing bundle contains an invalid secret: $name"}
    }
    if(-not(Is-LoopbackOrigin ([string]$bundle.local_base_url))){throw 'Pairing bundle local_base_url is invalid.'}
    if(-not(Is-LoopbackOrigin ([string]$bundle.local_bridge_allowed_origin))){throw 'Pairing bundle bridge origin is invalid.'}
    $bundleBase=[Uri]([string]$bundle.local_base_url)
    $bundleBridge=[Uri]([string]$bundle.local_bridge_allowed_origin)
    if($bundleBase.GetLeftPart([UriPartial]::Authority)-ne$baseUri.GetLeftPart([UriPartial]::Authority)-or$bundleBridge.GetLeftPart([UriPartial]::Authority)-ne$baseUri.GetLeftPart([UriPartial]::Authority)){throw 'Pairing bundle origin does not match Local Web.'}
    $triggers=@($bundle.runtime_triggers)
    if($triggers.Count-lt1-or$triggers.Count-gt64){throw 'Pairing bundle runtime trigger list is invalid.'}
}
function Set-DelayedAuto([string]$name){
    Set-Service -Name $name -StartupType Automatic -ErrorAction Stop
    Set-ItemProperty -LiteralPath ("HKLM:\SYSTEM\CurrentControlSet\Services\"+$name) -Name DelayedAutoStart -Type DWord -Value 1 -Force
}

if($env:OS-ne'Windows_NT'){throw 'Windows Services pairing runs on Windows only.'}
Assert-Admin
$PlanFile=Full $PlanFile 'PlanFile'
if(-not(Test-Path -LiteralPath $PlanFile -PathType Leaf)){throw 'Pairing plan file not found.'}
$plan=Get-Content -LiteralPath $PlanFile -Raw|ConvertFrom-Json
if([string]$plan.format-ne'sokna-windows-services-pair-plan-v1'-or[int]$plan.schema_version-ne1){throw 'Pairing plan format is unsupported.'}
$InstallRoot=Full ([string]$plan.install_root) 'InstallRoot'
$DataRoot=Full ([string]$plan.data_root) 'DataRoot'
$baseUrl=([string]$plan.pairing_base_url).Trim()
$code=([string]$plan.pairing_code).Trim()
$startWhenPaired=[bool]$plan.start_when_paired
if(-not(Is-LoopbackOrigin $baseUrl)){throw 'Local Web address must be a loopback origin.'}
if($code-notmatch'^ws1_[a-f0-9]{24}_[a-f0-9]{48}$'){throw 'Windows Services pairing code is invalid.'}

$runtimeRoot=Join-Path $InstallRoot 'Runtime'
$printRoot=Join-Path $InstallRoot 'PrintAgent'
$runtimeExe=Join-Path $runtimeRoot 'SoknaRuntimeService.exe'
$printExe=Join-Path $printRoot 'Service\Sokna.PrintAgent.Service.exe'
if(-not(Test-Path -LiteralPath $runtimeExe -PathType Leaf)-or-not(Test-Path -LiteralPath $printExe -PathType Leaf)){throw 'Windows Services must be installed before pairing.'}
$runtimeRegistered=Normalize-CommandPath (Service-Image $RuntimeService)
$printRegistered=Normalize-CommandPath (Service-Image $PrintService)
if(-not$runtimeRegistered.Equals($runtimeExe,[StringComparison]::OrdinalIgnoreCase)-or-not$printRegistered.Equals($printExe,[StringComparison]::OrdinalIgnoreCase)){throw 'Installed Windows Services ownership does not match this installation root.'}

$runtimeDir=Join-Path $DataRoot 'runtime'
$runtimeConfig=Join-Path $runtimeDir 'runtime-config.json'
$runtimeToken=Join-Path $runtimeDir 'runtime-token.private'
$localToken=Join-Path $runtimeDir 'local-token.private'
$printDataRoot=Join-Path $DataRoot 'print-worker'
$transientRoot=Join-Path $DataRoot 'setup\transient-pairing'
$statePath=Join-Path $DataRoot 'setup\windows-services-state.json'
New-Item -ItemType Directory -Path $runtimeDir,$transientRoot,(Split-Path -Parent $statePath) -Force|Out-Null

$backupConfig=Read-BytesOrNull $runtimeConfig
$backupRuntimeToken=Read-BytesOrNull $runtimeToken
$backupLocalToken=Read-BytesOrNull $localToken
$runtimeWasRunning=(Get-Service -Name $RuntimeService -ErrorAction Stop).Status-eq'Running'
$printWasRunning=(Get-Service -Name $PrintService -ErrorAction Stop).Status-eq'Running'
$baseUri=[Uri]$baseUrl
$endpoint=[Uri]::new($baseUri,'internal/windows-services/v1/pairing.php')
$exchanged=$false
$private=''
try{
    $exchange=Post-Pairing $endpoint 'exchange' $code
    if(-not[bool]$exchange.success){throw 'Local Web did not accept the pairing code.'}
    $bundle=$exchange.bundle
    Validate-Bundle $bundle $baseUri
    $exchanged=$true

    Stop-ServiceSafe $RuntimeService
    Stop-ServiceSafe $PrintService

    Write-Secret $runtimeToken ([string]$bundle.runtime_token)
    Write-Secret $localToken ([string]$bundle.local_token)

    $instanceId='runtime-'+[guid]::NewGuid().ToString('N')
    $healthPort=17621
    if(Test-Path -LiteralPath $runtimeConfig -PathType Leaf){
        try{
            $old=Get-Content -LiteralPath $runtimeConfig -Raw|ConvertFrom-Json
            if([string]$old.instanceId){$instanceId=[string]$old.instanceId}
            if([int]$old.healthPort-ge1024-and[int]$old.healthPort-le65535){$healthPort=[int]$old.healthPort}
        }catch{}
    }
    $cfg=[ordered]@{
        contractVersion=1
        instanceId=$instanceId
        dataRoot=(Join-Path $runtimeDir 'state')
        healthPort=$healthPort
        runtimeTokenFile=$runtimeToken
        localTokenFile=$localToken
        localBaseUrl=[string]$bundle.local_base_url
        printAgentServiceName=$PrintService
        supervisePrintAgent=$true
        triggers=@($bundle.runtime_triggers)
    }
    $cfg|ConvertTo-Json -Depth 8|Set-Content -LiteralPath $runtimeConfig -Encoding UTF8

    $private=Join-Path $transientRoot ('sokna-print-pair-'+[guid]::NewGuid().ToString('N')+'.json')
    $printPair=[ordered]@{server_base_url=[string]$bundle.local_base_url;token=[string]$bundle.print_agent_token;agent_name=$env:COMPUTERNAME;local_bridge_allowed_origin=[string]$bundle.local_bridge_allowed_origin}|ConvertTo-Json -Compress
    Write-PrivateJson $private $printPair
    & $printExe --provision-file $private|Out-Null
    if($LASTEXITCODE-ne0){throw 'Print Agent pairing failed.'}

    Set-DelayedAuto $PrintService
    Start-ServiceSafe $PrintService
    if($startWhenPaired){Set-DelayedAuto $RuntimeService;Start-ServiceSafe $RuntimeService}

    $packageVersion=''
    $versionPath=Join-Path $InstallRoot 'WINDOWS_SERVICES_VERSION.txt'
    if(Test-Path -LiteralPath $versionPath -PathType Leaf){$packageVersion=(Get-Content -LiteralPath $versionPath -Raw).Trim()}
    $state=[ordered]@{}
    if(Test-Path -LiteralPath $statePath -PathType Leaf){
        try{(Get-Content -LiteralPath $statePath -Raw|ConvertFrom-Json).PSObject.Properties|ForEach-Object{$state[$_.Name]=$_.Value}}catch{}
    }
    $state['format']='sokna-windows-services-install-state-v1'
    $state['package_owner']='windows-services-packaging'
    if($packageVersion){$state['package_version']=$packageVersion}
    $state['install_root']=$InstallRoot
    $state['data_root']=$DataRoot
    $state['print_data_root']=$printDataRoot
    $state['paired']=$true
    $state['paired_at_utc']=[DateTime]::UtcNow.ToString('o')
    $state['local_base_url']=[string]$bundle.local_base_url
    $state['runtime_service']=$RuntimeService
    $state['print_service']=$PrintService
    $state['pairing_lifecycle']='independent-v1'
    $state['external_infrastructure_mutated']=$false
    $state['business_data_mutated']=$false
    $state|ConvertTo-Json -Depth 8|Set-Content -LiteralPath $statePath -Encoding UTF8

    $confirm=Post-Pairing $endpoint 'confirm' $code
    if(-not[bool]$confirm.success){throw 'Local Web did not confirm the pairing.'}

    [ordered]@{success=$true;mode='pair';paired=$true;pairing_lifecycle='independent-v1';service_reinstall=$false;payload_replacement=$false;runtime_started=$startWhenPaired;print_agent_started=$true}|ConvertTo-Json -Compress
}
catch{
    try{Restore-Bytes $runtimeConfig $backupConfig;Restore-Bytes $runtimeToken $backupRuntimeToken;Restore-Bytes $localToken $backupLocalToken}catch{}
    if($exchanged){try{Post-Pairing $endpoint 'cancel' $code|Out-Null}catch{}}
    try{if($printWasRunning-and(Get-Service -Name $PrintService -ErrorAction SilentlyContinue).Status-ne'Running'){Start-ServiceSafe $PrintService}}catch{}
    try{if($runtimeWasRunning-and(Get-Service -Name $RuntimeService -ErrorAction SilentlyContinue).Status-ne'Running'){Start-ServiceSafe $RuntimeService}}catch{}
    throw
}
finally{
    if($private){Remove-Item -LiteralPath $private -Force -ErrorAction SilentlyContinue}
    Remove-Item -LiteralPath $PlanFile -Force -ErrorAction SilentlyContinue
}
