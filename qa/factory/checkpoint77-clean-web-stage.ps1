param([Parameter(Mandatory=$true)][string]$Root,[Parameter(Mandatory=$true)][string]$Package)
$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest
function Need([bool]$ok,[string]$reason){if(!$ok){throw $reason}}
Need ($env:GITHUB_ACTIONS -eq 'true' -and $env:RUNNER_OS -eq 'Windows') 'DISPOSABLE_CI_ONLY'
$root=[IO.Path]::GetFullPath($Root)
Need ((Split-Path $root -Leaf).StartsWith('Sokna-Infra-QA-') -and [IO.Path]::GetPathRoot($root).TrimEnd('\') -ne $env:SystemDrive) 'DISPOSABLE_NON_SYSTEM_ROOT_REQUIRED'
$web=Join-Path $root Web
$stage=Join-Path $root '.clean-web-cp77-stage'
$empty=Join-Path $root '.clean-web-cp77-empty'
$infra=Join-Path $root 'Infrastructure/infrastructure-state.json'
$diag=Join-Path $root 'Infrastructure/Logs/clean-web-stage-qa.log'
trap { if(Test-Path -LiteralPath (Split-Path -Parent $diag)){Add-Content -LiteralPath $diag -Value ('STAGE_ERROR '+$_.Exception.Message+' LINE '+$_.InvocationInfo.ScriptLineNumber)}; exit 1 }
function Mark([string]$m){Add-Content -LiteralPath $diag -Value $m}
Need (Test-Path $infra -PathType Leaf) 'NO_INFRASTRUCTURE_STATE'
foreach($path in @($root,(Join-Path $root Infrastructure),$infra,$web,(Join-Path $web public))){
 Need ((Test-Path -LiteralPath $path) -and -not ((Get-Item -LiteralPath $path -Force).Attributes -band [IO.FileAttributes]::ReparsePoint)) 'MISSING_PATH_OR_REPARSE_POINT'
}
$state=Get-Content -LiteralPath $infra -Raw|ConvertFrom-Json
Need ($state.format -eq 'sokna-infrastructure-state-v1' -and $state.last_operation -eq 'install' -and [IO.Path]::GetFullPath($state.root) -ieq $root -and [IO.Path]::GetFullPath($state.paths.local_web_root) -ieq $web -and [IO.Path]::GetFullPath($state.paths.apache_document_root) -ieq (Join-Path $web public)) 'INFRASTRUCTURE_IDENTITY_MISMATCH'
Need (-not (Test-Path (Join-Path $root config.php)) -and -not (Test-Path (Join-Path $root install.lock))) 'EXISTING_LOCAL_INSTALLATION'
Need (-not (Test-Path $stage) -and -not (Test-Path $empty)) 'UNRESOLVED_CLEAN_STAGE'
$entries=@(Get-ChildItem -LiteralPath $web -Recurse -Force)
Need ($entries.Count -eq 1 -and $entries[0].PSIsContainer -and $entries[0].Name -ceq 'public') 'WEB_NOT_EMPTY'
Need ((Get-FileHash -LiteralPath $Package -Algorithm SHA256).Hash.ToLowerInvariant() -ceq '2c14b241a3cddf5f1d436144691555b11a97ccc31b4d08b2ea8f8d92f04e3aa6') 'WEB_PACKAGE_PIN'
Need ((Get-Service SoknaApache).Status -eq 'Running' -and (Get-Service SoknaMariaDB).Status -eq 'Running') 'INFRA_NOT_READY'
Mark 'PRECHECK_PASS'
Add-Type -AssemblyName System.IO.Compression.FileSystem
$zip=[IO.Compression.ZipFile]::OpenRead($Package)
$created=$false;$stopped=$false;$moved=$false;$placed=$false
try {
 Mark 'ZIP_OPEN'
 $seen=[Collections.Generic.HashSet[string]]::new([StringComparer]::OrdinalIgnoreCase)
 Need ($zip.Entries.Count -eq 624) 'ZIP_COUNT'
 foreach($entry in $zip.Entries){
  $n=$entry.FullName
  Need ($n -match '^[A-Za-z0-9_.@()+/-]+$' -and -not $n.StartsWith('/') -and -not ($n.Split('/') -contains '..') -and -not ($n.Split('/') -contains '.') -and -not $n.EndsWith('/') -and $seen.Add($n)) 'ZIP_PATH_OR_DUPLICATE'
  Need ($n -notin @('config.php','install.lock','.env') -and -not $n.StartsWith('storage/')) 'RUNTIME_DATA_IN_WEB_PACKAGE'
 }
 Mark 'ZIP_PATHS_PASS'
 New-Item -ItemType Directory -Path $stage|Out-Null;$created=$true
 [IO.Compression.ZipFileExtensions]::ExtractToDirectory($zip,$stage)
 foreach($entry in $zip.Entries){
  $f=Join-Path $stage ($entry.FullName.Replace('/','\'))
  $stream=$entry.Open();$hasher=[Security.Cryptography.SHA256]::Create()
  try{$expected=[BitConverter]::ToString($hasher.ComputeHash($stream)).Replace('-','').ToLowerInvariant()}finally{$stream.Dispose();$hasher.Dispose()}
  Need ((Get-FileHash -LiteralPath $f -Algorithm SHA256).Hash.ToLowerInvariant() -ceq $expected) 'EXTRACT_READBACK'
 }
 Need (@(Get-ChildItem -LiteralPath $stage -Recurse -File -Force).Count -eq 624) 'EXTRACT_COUNT'
 Need ((Get-Content -LiteralPath (Join-Path $stage VERSION.txt) -Raw).Trim() -ceq '3.0.0-g6.2-workspace.231gg-checkpoint76-qa') 'VERSION_PIN'
 Mark 'EXTRACT_READBACK_PASS'
 Stop-Service SoknaApache -ErrorAction Stop;$stopped=$true
 Need (@(Get-ChildItem -LiteralPath $web -Recurse -Force).Count -eq 1 -and @(Get-ChildItem -LiteralPath (Join-Path $web public) -Force).Count -eq 0) 'WEB_CHANGED_DURING_STAGE'
 [IO.Directory]::Move($web,$empty);$moved=$true
 [IO.Directory]::Move($stage,$web);$placed=$true
 Mark 'WEB_SWAP_PASS'
 Start-Service SoknaApache -ErrorAction Stop
 $stopped=$false
 $url=[string]$state.endpoints.local_web.base_url
 Need ($url -match '^http://127\.0\.0\.1:[0-9]+/$') 'INVALID_LOCAL_ENDPOINT'
 $response=$null
 for($i=0;$i -lt 15;$i++){try{$response=Invoke-WebRequest ($url+'setup/index.php') -UseBasicParsing -TimeoutSec 5; if($response.StatusCode -eq 200){break}}catch{Mark ('HTTP_RETRY '+$_.Exception.Message)}; Start-Sleep -Seconds 1}
 Need ($null -ne $response) 'BROWSER_SETUP_NO_RESPONSE'
 Need ($response.StatusCode -eq 200) 'BROWSER_SETUP_NOT_READY'
 Need (@(Get-ChildItem -LiteralPath (Join-Path $empty public) -Force).Count -eq 0) 'EMPTY_BACKUP_DRIFT'
 [IO.Directory]::Delete((Join-Path $empty public));[IO.Directory]::Delete($empty)
 $moved=$false
 Mark 'PASS_NATIVE_CLEAN_WEB_STAGE_EXACT_PAYLOAD_BROWSER_SETUP_READY'
 Write-Host 'PASS_NATIVE_CLEAN_WEB_STAGE_EXACT_PAYLOAD_BROWSER_SETUP_READY'
}catch{
 if($moved -and (Test-Path $empty)){
  if((Get-Service SoknaApache).Status -ne 'Stopped'){Stop-Service SoknaApache -ErrorAction Stop}
  $stopped=$true
  if($placed -and (Test-Path $web)){[IO.Directory]::Move($web,$stage)}
  if(!(Test-Path $web)){[IO.Directory]::Move($empty,$web)}
 }
 if($stopped){Start-Service SoknaApache -ErrorAction Stop}
 # Leave ambiguous partial stage on disk; never erase unknown bytes.
 throw
}finally{$zip.Dispose()}
