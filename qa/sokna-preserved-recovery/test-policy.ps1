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
$known='368ea590a52b895d409d0bbc23926eebd58f512ac73a75dffac8163e5dc682dd'
$valid=@(@('0.3.22','0.3.22','0.1.1',$known),@('0.3.22','0.3.22','0.1.3',('a'*64)),@('0.3.23','0.3.23','0.1.4',('a'*64)),@('0.3.24','0.3.24','0.1.5',('a'*64)),@('0.3.25','0.3.25','0.1.6',('a'*64)))
foreach($v in $valid){
 $actual=AssertMaintenanceBaselineTuple -SupervisorVersion $v[0] -EngineVersion $v[1] -RecoveryVersion $v[2] -RecoverySha256 $v[3]
 if($actual-ne$v[2]){throw 'QUALIFIED_TUPLE_REJECTED'}
 Write-Host ('PASS qualified tuple '+$v[0]+'/'+$v[2])
}
$invalid=@(@('0.3.22','0.3.22','0.1.1',('f'*64)),@('0.3.22','0.3.22','0.1.1',''),@('0.3.22','0.3.21','0.1.1',$known),@('0.3.23','0.3.23','0.1.1',$known),@('0.3.22','0.3.22','0.1.7',$known),@('0.3.21','0.3.21','0.1.1',$known))
foreach($v in $invalid){
 $rejected=$false
 try{AssertMaintenanceBaselineTuple -SupervisorVersion $v[0] -EngineVersion $v[1] -RecoveryVersion $v[2] -RecoverySha256 $v[3]|Out-Null}
 catch [IO.InvalidDataException]{$rejected=$true}
 if(-not$rejected){throw 'UNTRUSTED_OR_MIXED_TUPLE_ACCEPTED'}
 Write-Host 'PASS rejected unknown hash, unsupported preservation or mismatched components'
}
Write-Host 'PASS 11 actual policy cases; NOT a physical 011 binary installation test'
