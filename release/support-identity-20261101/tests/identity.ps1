param([Parameter(Mandatory=$true)][string]$Collector)
$ErrorActionPreference='Stop'
$tokens=$null;$errors=$null;$ast=[Management.Automation.Language.Parser]::ParseFile((Resolve-Path $Collector),[ref]$tokens,[ref]$errors)
if($errors.Count){throw 'Collector syntax invalid'}
$names=@('Select-SupportIdentityText','Select-SupportComponentIdentity','Get-SupportInstalledIdentity','Get-SupportNullableBoolean')
foreach($name in $names){$fn=$ast.FindAll({param($node)$node-is[Management.Automation.Language.FunctionDefinitionAst]-and$node.Name-eq$name},$true);if($fn.Count-ne1){throw "Missing actual collector function $name"};. ([scriptblock]::Create($fn[0].Extent.Text))}
$script:rows=@();function Check([bool]$pass,[string]$test){$script:rows+=@{test=$test;pass=$pass};if(-not$pass){throw $test}}
$root=Join-Path $env:TEMP ('sokna-identity-test-'+[guid]::NewGuid().ToString('N'));New-Item -ItemType Directory $root|Out-Null
try{
 $file=Join-Path $root 'installed-release-identity.json'
 $fixture=[ordered]@{backup_recovery_engine_version=$null;local=[ordered]@{authority_epoch_policy=$null;managed_tree_sha256='80fd968a9e4f1642c3f1519f66d7ab6a2fb1bb64f1a8c0a26444d0d8da1712be';migration_head='0074_iran_time_notification_boundaries';release_id='sokna-local-231bf-u01-iran-time-updater-20261023';schema_fingerprint='migration-ledger-sha256-v1:43e4572dea9709b4dcd278497f0373962bb4e2460022d7400ef51d8b317fdcbf';version='3.0.0-g6.2-workspace.231bf-u01-iran-time-updater-rc1'};maintenance_supervisor_version='0.3.18';public=$null;public_maintenance_kernel_version=$null;update_engine_version='0.3.18';secret='must-never-be-exported'}
 $fixture|ConvertTo-Json -Depth 8|Set-Content -LiteralPath $file -Encoding UTF8
 $sha=(Get-FileHash -LiteralPath $file).Hash
 $r=Get-SupportInstalledIdentity $file
 Check ($r.evidence_available-eq$true-and$r.state-eq'available') 'actual Cafe BF identity is available'
 Check ($r.identity.local.version-eq$fixture.local.version) 'stale installed version is preserved, never replaced by current Web version'
 Check ($r.identity.local.migration_head-eq'0074_iran_time_notification_boundaries') 'stale migration head 74 is preserved, never normalized to 76'
 Check ($r.identity.local.schema_fingerprint-eq$fixture.local.schema_fingerprint) 'exact trusted schema fingerprint retained'
 Check ($null-eq$r.identity.local.authority_epoch_policy) 'legacy null epoch policy remains unknown'
 Check ($null-eq$r.identity.public-and$null-eq$r.identity.backup_recovery_engine_version) 'absent components stay null'
 Check (-not(($r|ConvertTo-Json -Depth 8).Contains('must-never-be-exported'))) 'unrecognized private fields are excluded'
 Check ((Get-FileHash -LiteralPath $file).Hash-eq$sha) 'collecting identity never mutates it'
 $r=Get-SupportInstalledIdentity (Join-Path $root 'missing.json');Check (-not$r.evidence_available-and$r.state-eq'missing') 'missing identity is explicit evidence gap'
 '{invalid'|Set-Content $file;$r=Get-SupportInstalledIdentity $file;Check (-not$r.evidence_available-and$r.state-eq'unavailable') 'corrupt identity is explicit unknown, never healthy'
 $fixture.local.version='private/password=secret';$fixture|ConvertTo-Json -Depth 8|Set-Content $file;$r=Get-SupportInstalledIdentity $file;Check (-not$r.evidence_available-and-not(($r|ConvertTo-Json -Depth 8).Contains('password'))) 'unsafe identity is rejected without leaking raw content'
 Check ($null-eq(Get-SupportNullableBoolean $null)) 'missing mutation evidence stays null'
 Check ($null-eq(Get-SupportNullableBoolean 'false')) 'invalid boolean string is not treated as safe false'
 Check ((Get-SupportNullableBoolean $false)-eq$false) 'explicit false is retained'
 Check ((Get-SupportNullableBoolean $true)-eq$true) 'explicit true is retained'
 $raw=Get-Content $Collector -Raw
 Check ($raw.Contains("WriteJson (Join-Path `$maintDir 'installed-release-identity.json') `$identityEvidence")) 'identity is connected to the real ZIP assembly path'
 Check ($raw.Contains("result_message=(Redact ([string]`$op.result_message))")) 'detailed rejected-operation cause stays in the same bundle'
 Check ($raw.Contains("AddError 'maintenance-installed-identity' `$identityEvidence.error_code")) 'collection gap marks real bundle incomplete'
 $script:rows|ConvertTo-Json -Depth 8
}finally{Remove-Item -LiteralPath $root -Force -Recurse}
