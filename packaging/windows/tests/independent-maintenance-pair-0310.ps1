param(
  [Parameter(Mandatory=$true)][string]$ShellRoot,
  [Parameter(Mandatory=$true)][string]$ReleaseRoot
)
$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest
$core=Join-Path $ShellRoot 'setup-windows-components-core-v5.ps1'
if(-not(Test-Path -LiteralPath $core -PathType Leaf)){throw 'Composed privileged lifecycle core missing'}
$tokens=$null;$errors=$null
$ast=[Management.Automation.Language.Parser]::ParseFile($core,[ref]$tokens,[ref]$errors)
if($errors.Count){throw ('Privileged PowerShell parse failed: '+(($errors|ForEach-Object Message)-join '; '))}
$functions=@($ast.FindAll({param($n) $n -is [Management.Automation.Language.FunctionDefinitionAst]},$true))
foreach($name in @('Prop','Sha','VersionOf','Service-Exe','Owned','Registration-Matches','Verified-IndependentMaintenancePair','Compatible-Fingerprint')){
  $fn=@($functions|Where-Object Name -eq $name)
  if($fn.Count-ne1){throw ('Missing/duplicate privileged function '+$name)}
  Invoke-Expression $fn[0].Extent.Text
}
$caseRoot=Join-Path $env:RUNNER_TEMP 'independent-maintenance-proof-0310'
$script:InstallRoot=Join-Path $caseRoot 'owned-root'
$script:DataRoot=Join-Path $caseRoot 'machine-data'
$app=Join-Path $InstallRoot 'Maintenance\App'
New-Item -ItemType Directory -Path $app,(Join-Path $DataRoot 'setup') -Force|Out-Null
$source=Join-Path $ShellRoot '_payload\maintenance\app'
foreach($name in @('SoknaMaintenanceSupervisor.exe','SoknaMaintenanceUpdateEngine.exe')){
  Copy-Item -LiteralPath (Join-Path $source $name) -Destination (Join-Path $app $name) -Force
}
$supervisor=Join-Path $app 'SoknaMaintenanceSupervisor.exe'
$engine=Join-Path $app 'SoknaMaintenanceUpdateEngine.exe'
$supervisorHash=Sha $supervisor
$engineHash=Sha $engine
if((VersionOf $supervisor)-ne'0.3.10'-or(VersionOf $engine)-ne'0.3.10'){throw 'Real test executables are not 0.3.10'}
$script:MockServiceState='Running'
$script:MockServiceImage='"'+$supervisor+'"'
function Service-Info([string]$name){
  if($name-ne'SoknaMaintenanceSupervisor'){throw 'Mock received other service'}
  [pscustomobject]@{Installed=$true;State=$script:MockServiceState;ImagePath=$script:MockServiceImage;WasRunning=($script:MockServiceState-eq'Running');ProcessId=[uint32]0}
}
$manifest=Get-Content (Join-Path $ShellRoot 'windows-services-component-manifest-v2.json') -Raw|ConvertFrom-Json
$mSupervisor=@($manifest.components|Where-Object component_id -eq 'maintenance')[0]
$mEngine=@($manifest.components|Where-Object component_id -eq 'maintenance-update-engine')[0]
$receiptPath=Join-Path $DataRoot 'setup\maintenance-only-component-receipt-v1.json'
$receipt=[ordered]@{
  format='sokna-maintenance-only-component-receipt-v1';schema_version=1;release_scope='maintenance_only';version='0.3.10';
  transaction_id=[Guid]::NewGuid().ToString('N');windows_shell_state_unchanged=$true;runtime_unchanged=$true;
  print_agent_unchanged=$true;recovery_console_unchanged=$true;
  supervisor_sha256=$supervisorHash;engine_sha256=$engineHash
}
function Save-Receipt { $receipt|ConvertTo-Json -Depth 5|Set-Content -LiteralPath $receiptPath -Encoding UTF8 }
function Expect([bool]$got,[bool]$expected,[string]$which){
  if($got-ne$expected){throw "Independent Maintenance privilege mismatch: $which expected=$expected got=$got"}
}
$otherTarget='0'*64
Save-Receipt
Expect (Compatible-Fingerprint $mSupervisor $supervisorHash $otherTarget) $true 'valid supervisor receipt'
Expect (Compatible-Fingerprint $mEngine $engineHash $otherTarget) $true 'valid engine receipt'
$receipt.engine_sha256='f'*64
Save-Receipt
Expect (Compatible-Fingerprint $mSupervisor $supervisorHash $otherTarget) $false 'tampered sibling hash supervisor'
Expect (Compatible-Fingerprint $mEngine $engineHash $otherTarget) $false 'tampered sibling hash engine'
$receipt.engine_sha256=$engineHash
$receipt.transaction_id='not-a-transaction'
Save-Receipt
Expect (Compatible-Fingerprint $mSupervisor $supervisorHash $otherTarget) $false 'invalid receipt transaction'
$receipt.transaction_id=[Guid]::NewGuid().ToString('N')
$receipt.runtime_unchanged=$false
Save-Receipt
Expect (Compatible-Fingerprint $mSupervisor $supervisorHash $otherTarget) $false 'forbidden runtime mutation'
$receipt.runtime_unchanged=$true
Save-Receipt
$script:MockServiceState='Stopped'
Expect (Compatible-Fingerprint $mSupervisor $supervisorHash $otherTarget) $false 'service not running'
$script:MockServiceState='Running'
$script:MockServiceImage='"'+(Join-Path $caseRoot 'outside\SoknaMaintenanceSupervisor.exe')+'"'
Expect (Compatible-Fingerprint $mSupervisor $supervisorHash $otherTarget) $false 'service outside owned root'
$script:MockServiceImage='"'+$supervisor+'"'
Expect (Compatible-Fingerprint ([pscustomobject]@{component_id='runtime';version='0.3.10'}) $supervisorHash $otherTarget) $false 'unrelated component'
Remove-Item $receiptPath -Force
Expect (Compatible-Fingerprint $mSupervisor $supervisorHash $otherTarget) $false 'missing receipt'
Write-Host 'Privileged V4 independent Maintenance 0.3.10: real EXE + valid/tampered/missing receipt and service state PASS'
