param([Parameter(Mandatory=$true)][string]$RepoRoot)
$ErrorActionPreference='Stop';$RepoRoot=(Resolve-Path $RepoRoot).Path
$lab=Join-Path $env:TEMP ('sokna-support-full-'+[guid]::NewGuid().ToString('N'));New-Item -ItemType Directory $lab|Out-Null
$rows=@();function Check([bool]$pass,[string]$test){$script:rows+=@{test=$test;pass=$pass};if(-not$pass){throw $test}}
try{
 $shell=Join-Path $lab 'shell';$data=Join-Path $lab 'data';$out=Join-Path $lab 'out';New-Item -ItemType Directory -Force $shell,$out,(Join-Path $data 'Maintenance\operations')|Out-Null
 $scripts=Join-Path $RepoRoot 'packaging\windows\scripts'
 Copy-Item (Join-Path $scripts 'collect-support-v3.ps1') (Join-Path $shell 'collect-support.ps1')
 Copy-Item (Join-Path $scripts 'collect-support-v2.ps1') (Join-Path $shell 'collect-support-v2-core.ps1')
 Copy-Item (Join-Path $scripts 'collect-support.ps1') (Join-Path $shell 'collect-support-core.ps1')
 Copy-Item (Join-Path $scripts 'write-technician-report-v1.ps1') $shell
 $id=Join-Path $data 'Maintenance\installed-release-identity.json';$fixture=@{local=@{version='3.0.0-g6.2-workspace.231bf-u01-iran-time-updater-rc1';release_id='sokna-local-231bf-u01-iran-time-updater-20261023';managed_tree_sha256=('a'*64);migration_head='0074_iran_time_notification_boundaries';schema_fingerprint=('migration-ledger-sha256-v1:'+('b'*64));authority_epoch_policy=$null};public=$null;maintenance_supervisor_version='0.3.18';update_engine_version='0.3.18';private_canary='NEVER_EXPORT_PRIVATE_IDENTITY_VALUE'}
 $fixture|ConvertTo-Json -Depth 8|Set-Content $id -Encoding UTF8;$before=(Get-FileHash $id).Hash
 $op=@{operation_id=('f'*32);operation_type='update.local-package-preflight';state='rejected_pre_mutation';result_code='PACKAGE_PREFLIGHT_REJECTED';result_message='LOCAL_REBIND_UNIQUE_SIGNED_BASELINE_REQUIRED';activation_performed=$false;database_mutation_performed=$false;updated_at_utc='2026-11-01T18:43:13Z'};$op|ConvertTo-Json|Set-Content (Join-Path $data 'Maintenance\operations\op-fixture.json')
 & (Join-Path $shell 'collect-support.ps1') -DataRoot $data -OutputRoot $out | Out-Null
 $zip=Get-ChildItem $out -Filter '*.zip'|Sort-Object LastWriteTimeUtc -Descending|Select-Object -First 1;$expanded=Join-Path $lab 'expanded';Expand-Archive $zip.FullName $expanded
 $found=Get-Content (Join-Path $expanded 'maintenance\installed-release-identity.json') -Raw|ConvertFrom-Json
 Check ($found.evidence_available-eq$true-and$found.identity.local.migration_head-eq'0074_iran_time_notification_boundaries') 'real v3 to v2 ZIP chain contains actual installed identity'
 $ops=Get-Content (Join-Path $expanded 'maintenance\recent-operations.json') -Raw|ConvertFrom-Json
 Check ($ops.operations[0].result_message-eq'LOCAL_REBIND_UNIQUE_SIGNED_BASELINE_REQUIRED') 'same ZIP contains detailed cause of rejected operation'
 Check ($ops.operations[0].activation_performed-eq$false-and$ops.operations[0].database_mutation_performed-eq$false) 'same ZIP preserves explicit non-mutation evidence'
 $manifest=Get-Content (Join-Path $expanded 'bundle-manifest.json') -Raw|ConvertFrom-Json
 Write-Host ('MANIFEST_DEBUG '+(@{format=$manifest.format;count=@($manifest.entries).Count;paths=@($manifest.entries|ForEach-Object {$_.path}|Where-Object {$_-match'identity|maintenance'})}|ConvertTo-Json -Compress))
 Check (@($manifest.entries|Where-Object {$_.path.Replace('\','/') -eq 'maintenance/installed-release-identity.json'}).Count-eq1) 'final wrapper manifest indexes identity evidence'
 $exported=Get-Content (Join-Path $expanded 'maintenance\installed-release-identity.json') -Raw
 Check (-not$exported.Contains('NEVER_EXPORT_PRIVATE_IDENTITY_VALUE')) 'real archive excludes extra private identity fields'
 Check ((Get-FileHash $id).Hash-eq$before) 'full collection leaves original installed identity unchanged'
 $rows|ConvertTo-Json -Depth 8
}finally{Remove-Item $lab -Recurse -Force -ErrorAction SilentlyContinue}
