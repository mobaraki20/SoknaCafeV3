# Café R130: read-only environmental + database suitability assessment.
# Does not call Maintenance endpoints, modify JSON, send HTTP writes, or control services.
$ErrorActionPreference='Stop'
$root = Split-Path -Parent $MyInvocation.MyCommand.Path
$report=Join-Path $root 'SOKNA-R130-CAFE-PREFLIGHT-RESULT.txt'
$log=New-Object System.Collections.Generic.List[string]
function Report([string]$line) {$script:log.Add($line);Write-Output $line}
function Block([string]$code) { Report "BLOCKED: $code";throw 'FAIL_CLOSED' }
$expectedVersion='0.0.0-test'
$expectedTree='0000000000000000000000000000000000000000000000000000000000000000'
$expectedFP='migration-ledger-sha256-v1:0000000000000000000000000000000000000000000000000000000000000000'
$expectedRelease='qa-source-only-stub'
try {
 Report 'TOOL: SOKNA_R130_CAFE_PREFLIGHT_READ_ONLY'
 Report 'MODE: NO_UPDATE_NO_SQL_MUTATION_NO_SERVICE_RESTART'
 $dataRoot=Join-Path $env:ProgramData 'SOKNA\Maintenance'
 $identPath=Join-Path $dataRoot 'installed-release-identity.json'
 if(!(Test-Path -LiteralPath $identPath -PathType Leaf)){Block 'IDENTITY_MISSING'}
 $id=Get-Content -LiteralPath $identPath -Raw -Encoding UTF8 | ConvertFrom-Json
 $li=$id.local
 if($null -eq $li -or $li.version -cne $expectedVersion -or $li.release_id -cne $expectedRelease -or $li.managed_tree_sha256 -cne $expectedTree -or $li.migration_head -cne '0076_late_served_accounting' -or $li.schema_fingerprint -cne $expectedFP){Block 'R112_IDENTITY_NOT_EXACT'}
 Report 'PRECHECK: EXACT_R112_INSTALLED_IDENTITY'
 $routineFile=Join-Path $dataRoot 'local-slots\routine-state.json'
 if(!(Test-Path -LiteralPath $routineFile -PathType Leaf)){Block 'ROUTINE_STATE_MISSING'}
 $routine=Get-Content -LiteralPath $routineFile -Raw -Encoding UTF8 | ConvertFrom-Json
 if($routine.phase -cne 'RECOVERED_TO_PREVIOUS_SLOT' -or $routine.recovery_required -ne $false -or $routine.current_build -cne $expectedVersion){Block 'R129_ROUTINE_NOT_SETTLED'}
 if(@($routine.events).Count -lt 1 -or @($routine.events)[-1].state -cne 'RECOVERED_TO_PREVIOUS_SLOT'){Block 'R129_TERMINAL_EVENT_MISSING'}
 $r125op=Join-Path $dataRoot 'operations\op-00000000000000000000000000000000.json'
 if(!(Test-Path -LiteralPath $r125op -PathType Leaf)){Block 'R125_OPERATION_MISSING'}
 $past=Get-Content -LiteralPath $r125op -Raw -Encoding UTF8 | ConvertFrom-Json
 if($past.state -cne 'failed' -or $past.result_code -cne 'CODE_ONLY_ENGINE_ROLLED_BACK_VERIFIED'){Block 'R129_LEDGER_NOT_SETTLED'}
 $receipt=Join-Path $dataRoot 'local-slots\r125-reconcile-intent-20261107-r125-r112.json'
 if(!(Test-Path -LiteralPath $receipt -PathType Leaf)){Block 'R129_INTENT_MISSING'}
 $operations=Join-Path $dataRoot 'operations'
 foreach($f in Get-ChildItem -LiteralPath $operations -Filter 'op-*.json' -File){
   try {$op=Get-Content -LiteralPath $f.FullName -Raw -Encoding UTF8|ConvertFrom-Json} catch{Block 'CORRUPT_MAINTENANCE_JOURNAL'}
   if(@('accepted','running','activating','recovering','recovery_required') -contains $op.state){Block 'BLOCKING_OPERATION_PRESENT'}
 }
 Report 'PRECHECK: MAINTENANCE_SETTLED_NO_BLOCKERS'
 $webRoot='D:\SOKNA\Web'
 $actualVersion=(Get-Content -LiteralPath (Join-Path $webRoot 'VERSION.txt') -Raw -Encoding UTF8).Trim()
 if($actualVersion -cne $expectedVersion){Block 'LIVE_VERSION_NOT_R112'}
 $invPath=Join-Path $webRoot 'resources\local-managed-files-v1.json'
 $inv=Get-Content -LiteralPath $invPath -Raw -Encoding UTF8 | ConvertFrom-Json
 $files=@($inv.files)
 if($inv.format -cne 'sokna-local-managed-files-v1' -or $files.Count -ne 537 -or @($files|Select-Object -Unique).Count -ne 537){Block 'MANAGED_FILES_COUNT_MISMATCH'}
 $all = New-Object System.Text.StringBuilder
 $sorted=[string[]]$files
 [Array]::Sort($sorted,[StringComparer]::Ordinal)
 foreach($name in $sorted){
   $name=[string]$name
   if($name.StartsWith('/') -or $name.Contains('..') -or $name.Contains(':') -or $name.Contains('\')){Block 'UNSAFE_INVENTORY_PATH'}
   $path=Join-Path $webRoot ($name.Replace('/','\'))
   if(!(Test-Path -LiteralPath $path -PathType Leaf)){Block 'MANAGED_FILE_MISSING'}
   $sha=(Get-FileHash -LiteralPath $path -Algorithm SHA256).Hash.ToLowerInvariant()
   [void]$all.Append($name);[void]$all.Append([char]0);[void]$all.Append($sha);[void]$all.Append("`n")
 }
 $shaBuilder=[System.Security.Cryptography.SHA256]::Create()
 try{$tree=([System.BitConverter]::ToString($shaBuilder.ComputeHash([Text.Encoding]::UTF8.GetBytes($all.ToString())))).Replace('-','').ToLowerInvariant()}finally{$shaBuilder.Dispose()}
 if($tree -cne $expectedTree){Block 'CURRENT_MANAGED_TREE_MISMATCH'}
 Report 'PRECHECK: R112_537_MANAGED_FILES_HASH_VERIFIED'
 try{$web=Invoke-WebRequest -UseBasicParsing -Uri 'http://127.0.0.1:18080/login.php' -TimeoutSec 8 -Method Get}
 catch {Block 'LOCAL_HTTP_NOT_RESPONDING'}
 if($web.StatusCode -lt 200 -or $web.StatusCode -ge 400){Block 'LOCAL_HTTP_NOT_HEALTHY'}
 Report 'PRECHECK: APACHE_AND_LOCAL_WEB_RESPONDING'
 $phpPath=$null
 foreach($p in Get-ChildItem -LiteralPath 'D:\SOKNA\Infrastructure' -Filter 'php.exe' -File -Recurse -ErrorAction SilentlyContinue){$phpPath=$p.FullName;break}
 if(!$phpPath){Block 'PHP_RUNTIME_NOT_FOUND'}
 $phpIni=Join-Path (Split-Path -Parent $phpPath) 'php.ini'
 $cfg='D:\SOKNA\config.php'
 if(!(Test-Path -LiteralPath $cfg -PathType Leaf)){Block 'LOCAL_DB_CONFIG_NOT_FOUND'}
 $phpArgs=@()
 if(Test-Path -LiteralPath $phpIni -PathType Leaf){$phpArgs+=@('-c',$phpIni)}
 $phpArgs+=@('-f',(Join-Path $root 'R130-DB-READONLY.php'),$cfg,'0076_late_served_accounting','legacy')
 $dbOut=@(& $phpPath @phpArgs 2>&1 | ForEach-Object {"$_"})
 $dbExit=$LASTEXITCODE
 foreach($entry in $dbOut){if($entry -match '^DB_PREFLIGHT_|^TEA_MATCHED_FAMILIES|^TEA_SIZE_MIGRATION') {Report $entry}}
 if($dbExit -ne 0 -or @($dbOut | Where-Object {$_ -match '^DB_PREFLIGHT_PASS '}).Count -ne 1){Block 'DATABASE_PREFLIGHT_REJECTED'}
 Report 'SUCCESS: R130_CAFE_CANDIDATE_PREFLIGHT_READ_ONLY_PASS'
 Report 'IMPORTANT: THIS_DOES_NOT_ACTIVATE_OR_AUTHORIZE_PRODUCTION_INSTALL'
} catch {
 if($_.Exception.Message -ne 'FAIL_CLOSED'){Report 'BLOCKED: UNEXPECTED_READONLY_CHECK_EXCEPTION'}
} finally {
 [System.IO.File]::WriteAllLines($report,[string[]]$log,(New-Object System.Text.UTF8Encoding($false)))
}
if($log -contains 'SUCCESS: R130_CAFE_CANDIDATE_PREFLIGHT_READ_ONLY_PASS'){exit 0}
exit 19