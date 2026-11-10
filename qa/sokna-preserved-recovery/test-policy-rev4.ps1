$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest
$root=Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
$source=Join-Path $root 'packaging/windows/scripts/invoke-maintenance-components-v4.ps1'
$tokens=$null;$errors=$null
$ast=[Management.Automation.Language.Parser]::ParseFile($source,[ref]$tokens,[ref]$errors)
if($errors.Count-ne0){throw 'SCRIPT_SYNTAX_FAILED'}
foreach($name in @('Fail','AssertMaintenanceBaselineTuple')){
 $fn=$ast.Find({param($n) $n-is[Management.Automation.Language.FunctionDefinitionAst] -and $n.Name-eq$name},$true)
 if(-not$fn){throw 'ACTUAL_POLICY_FUNCTION_MISSING'}
 . ([scriptblock]::Create($fn.Extent.Text))
}
$script:InstallerStage='TEST'
$fixture=Get-Content (Join-Path $PSScriptRoot 'host-baseline-sanitized.json') -Raw|ConvertFrom-Json
$known=[string]$fixture.recovery.recorded_sha256
$manifest=Get-Content (Join-Path $root 'packaging/windows/windows-services-component-manifest-v2.json') -Raw|ConvertFrom-Json
$official=@($manifest.components|Where-Object{$_.component_id-eq'maintenance-recovery-console'})
if($official.Count-ne1 -or $known-notin$official[0].accepted_installed_sha256){throw 'HOST_FIXTURE_NOT_AN_OFFICIAL_PRESERVED_FINGERPRINT'}
if($fixture.physical_legacy_installation_tested -or $fixture.production_approved -or -not$fixture.requires_fresh_physical_hash_match -or -not$fixture.requires_privileged_supervisor_engine_receipt){throw 'HOST_FIXTURE_SCOPE_INCORRECT'}
$hostDecision=AssertMaintenanceBaselineTuple -SupervisorVersion ($fixture.supervisor.physical_version-replace'\.0$','') -EngineVersion ($fixture.engine.physical_version-replace'\.0$','') -RecoveryVersion $fixture.recovery.recorded_version -RecoverySha256 $known
if($hostDecision-ne'0.1.1'){throw 'PREVIOUSLY_SUPPLIED_PRESERVED_BASELINE_DROPPED'}
Write-Host 'PASS prior KEEP hash recorded, but only physical rollback bytes bind the new upgrade; core receipts remain mandatory' 
$valid=@(@('0.3.22','0.3.22','0.1.1',$known),@('0.3.22','0.3.22','0.1.1',('f'*64)),@('0.3.22','0.3.22','0.1.3',('a'*64)),@('0.3.23','0.3.23','0.1.4',('a'*64)),@('0.3.24','0.3.24','0.1.5',('a'*64)),@('0.3.25','0.3.25','0.1.6',('a'*64)))
foreach($v in $valid){
 $actual=AssertMaintenanceBaselineTuple -SupervisorVersion $v[0] -EngineVersion $v[1] -RecoveryVersion $v[2] -RecoverySha256 $v[3]
 if($actual-ne$v[2]){throw 'QUALIFIED_TUPLE_REJECTED'}
 Write-Host ('PASS qualified tuple '+$v[0]+'/'+$v[2])
}
$invalid=@(@('0.3.22','0.3.22','0.1.1',('z'*64)),@('0.3.22','0.3.22','0.1.1',''),@('0.3.22','0.3.21','0.1.1',$known),@('0.3.23','0.3.23','0.1.1',$known),@('0.3.22','0.3.22','0.1.7',$known),@('0.3.21','0.3.21','0.1.1',$known))
foreach($v in $invalid){
 $rejected=$false
 try{AssertMaintenanceBaselineTuple -SupervisorVersion $v[0] -EngineVersion $v[1] -RecoveryVersion $v[2] -RecoverySha256 $v[3]|Out-Null}
 catch [IO.InvalidDataException]{$rejected=$true}
 if(-not$rejected){throw 'UNTRUSTED_OR_MIXED_TUPLE_ACCEPTED'}
 Write-Host 'PASS rejected invalid hash format, unsupported preservation or mismatched core versions'
}
Write-Host 'PASS physical SHA variants at policy boundary; six-case real Windows Inno test validates core receipt and rollback safety'
