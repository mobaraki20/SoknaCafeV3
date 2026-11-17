$ErrorActionPreference='Stop'
# Replays the historical native Windows install/upgrade chain into exact R262,
# then executes R262 -> R273 on the SAME isolated Windows installation via
# the real Maintenance harness and HTTP readback. Never targets an operator PC.
function Need([bool]$ok,[string]$code){if(-not $ok){throw $code}}
$r262Package=(Get-ChildItem $env:R273_R262_DIR -Recurse -File -Filter 'SOKNA-R262-LOCAL-231FG-CUMULATIVE-PUBLIC-MOVE-NATIVE-UAT-UNQUALIFIED.zip' | Select-Object -First 1).FullName
$r273Package=(Get-ChildItem $env:R273_TARGET_DIR -Recurse -File -Filter 'SOKNA-R273-LOCAL-231FJ-EXISTING-PUBLIC-OWNER-TRANSFER-QA-DO-NOT-INSTALL.zip' | Select-Object -First 1).FullName
Need ([bool]$r262Package -and [bool]$r273Package) 'R273_WINDOWS_EXACT_ZIP_NOT_FOUND'
Need ((Get-FileHash $r273Package -Algorithm SHA256).Hash.ToLowerInvariant() -eq 'a75471e7b540b504ed8fa0ad93bca31cbd295a9fb3f7b55bba479f7d8c23041f') 'R273_WINDOWS_CANDIDATE_SHA_MISMATCH'
$r262Dir=Join-Path $env:RUNNER_TEMP 'r262-fetched';Expand-Archive $r262Package $r262Dir
$r273Dir=Join-Path $env:RUNNER_TEMP 'r273-fetched';Expand-Archive $r273Package $r273Dir
$m262=Get-Content (Join-Path $r262Dir 'release-set.json') -Raw | ConvertFrom-Json
$m273=Get-Content (Join-Path $r273Dir 'release-set.json') -Raw | ConvertFrom-Json
Need ($m262.targets.local.version -ceq '3.0.0-g6.2-workspace.231fg-r262-public-move-qa') 'R262_TARGET_VERSION_WRONG'
Need ($m273.targets.local.version -ceq '3.0.0-g6.2-workspace.231fj-r273-existing-public-owner-safe-move') 'R273_TARGET_VERSION_WRONG'
Need ($m273.supported_baselines.Count -eq 1 -and $m273.supported_baselines[0].local.managed_tree_sha256 -ceq $m262.targets.local.managed_tree_sha256) 'R273_BASELINE_NOT_PRECISE_R262'
Need ($m273.targets.local.managed_files -eq 604 -and $m273.targets.local.migration_head -ceq '0082_public_move_owner_approval') 'R273_SCOPE_UNEXPECTED'
$source=Get-Content 'qa/sokna-r204/windows_r204_from_actual_231fa.ps1' -Raw
$oldPackageName='SOKNA-R204-LOCAL-231FB-61BASE-EXACT-WINDOWS-TECH-CANDIDATE.zip'
$oldPackageSHA='279d64dcf3800a764c3fcf751a0fc800857de501943af32428c87cc8db2539a3'
$oldVersion='3.0.0-g6.2-workspace.231fb-r204-ux-zero-empty-canary'
Need ($source.Contains($oldPackageName) -and $source.Contains($oldPackageSHA) -and $source.Contains($oldVersion)) 'R273_NATIVE_ORIGIN_SCRIPT_CHANGED'
$source=$source.Replace($oldPackageName,[IO.Path]::GetFileName($r262Package))
$source=$source.Replace($oldPackageSHA,[string](Get-FileHash $r262Package -Algorithm SHA256).Hash.ToLowerInvariant())
$source=$source.Replace($oldVersion,[string]$m262.targets.local.version)
$source=$source.Replace('$r204Ledger -eq ''81''','$r204Ledger -eq ''82''')
$source=$source.Replace('R204_MIGRATION_LEDGER_NOT_81','R262_MIGRATION_LEDGER_NOT_82')
$needle=" Write-Host 'PASS_R204_EXACT_INSTALLED_CAFE_231FA_TO_231FB_ACTIVATE_HTTP_MIGRATIONS_81'"
Need ($source.Contains($needle)) 'R273_NATIVE_INJECTION_POINT_NOT_FOUND'
$append=@'
 Write-Host 'PASS_R273_WINDOWS_SOURCE_R262_231FG_ACTIVATE_HTTP_MIGRATIONS_82'
 # Next: use the same installed database, Windows services and Maintenance runner.
 $r273Outer=Join-Path $lab 'r273-outer';Expand-Archive $env:R273_EXACT_PACKAGE $r273Outer
 $r273Manifest=Get-Content (Join-Path $r273Outer 'release-set.json') -Raw|ConvertFrom-Json
 Need ($r273Manifest.supported_baselines.Count -eq 1) 'R273_NATIVE_BASELINES_NOT_ONE'
 Need ($r273Manifest.supported_baselines[0].local.managed_tree_sha256 -ceq $r204Manifest.targets.local.managed_tree_sha256) 'R273_NATIVE_MANIFEST_R262_TREE_MISMATCH'
 Need ($r273Manifest.supported_baselines[0].local.version -ceq $r204Version) 'R273_NATIVE_INSTALLED_R262_VERSION_MISMATCH'
 $r262Base=Join-Path $lab 'baseline.r262.json';$r273Target=Join-Path $lab 'target.r273.json'
 $r273Manifest.supported_baselines[0].local|ConvertTo-Json -Depth 7|Set-Content $r262Base -Encoding utf8NoBOM
 $r273Manifest.targets.local|ConvertTo-Json -Depth 7|Set-Content $r273Target -Encoding utf8NoBOM
 $r273Args=@($root,$r262Base,$r273Target,$engine,'none',$env:R273_EXACT_PACKAGE)
 Run $harness (@('prepare')+$r273Args)
 Run $harness (@('panel-activate')+$r273Args)
 $v273=[string]$r273Manifest.targets.local.version
 Need ((Get-Content (Join-Path $root 'Web/VERSION.txt') -Raw).Trim() -ceq $v273) 'R273_NATIVE_ACTIVATED_VERSION_READBACK_WRONG'
 $http273=Invoke-WebRequest 'http://127.0.0.1:39831/login.php' -UseBasicParsing -TimeoutSec 8
 Need ($http273.StatusCode-eq200 -and $http273.Headers['X-Sokna-Build'] -ceq $v273) 'R273_NATIVE_HTTP_READBACK_WRONG'
 $ledger273=& $mysql --no-defaults --protocol=tcp --host=127.0.0.1 "--port=$dbPort" --user=root --skip-column-names --execute='SELECT COUNT(*) FROM sokna_r186_exact.schema_migrations;'
 Need ($LASTEXITCODE -eq 0 -and [string]$ledger273 -eq '82') 'R273_NATIVE_LOCAL_MIGRATIONS_CHANGED'
 Write-Host 'PASS_R273_EXACT_NATIVE_R262_TO_R273_WINDOWS_STAGE_ACTIVATE_HTTP_DB_82'
'@
$source=$source.Replace($needle, $needle + "`n" + $append)
$path=Join-Path $env:RUNNER_TEMP 'native-r273-exact.ps1'
Set-Content $path $source -Encoding utf8NoBOM
$env:R204_TARGET_DIR=$env:R273_R262_DIR
$env:R273_EXACT_PACKAGE=$r273Package
& $path
if($LASTEXITCODE -ne 0){throw 'R273_NATIVE_CHAIN_EXECUTION_FAILED'}
Write-Host 'PASS_R273_NATIVE_WINDOWS_DIRECT_R262_TO_R273_NO_CAFE_DEPLOY'
