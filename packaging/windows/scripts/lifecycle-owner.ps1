param(
  [Parameter(Mandatory=$true)][ValidateSet('New','Update','Repair','Recover','Rollback','Uninstall')][string]$Mode,
  [Parameter(Mandatory=$true)][string]$PlanFile
)
$ErrorActionPreference='Stop'
if(-not [IO.Path]::IsPathFullyQualified($PlanFile) -or -not (Test-Path -LiteralPath $PlanFile -PathType Leaf)){ throw 'Lifecycle plan file is missing.' }
$plan=Get-Content -LiteralPath $PlanFile -Raw | ConvertFrom-Json
if($plan.schema_version -ne 1){ throw 'Unsupported lifecycle plan.' }
# This script is an installer-internal bridge only. Component activation remains
# manifest/hash/compatibility owned and business data is never deleted by Repair/Update.
$allowed=@('local','public','runtime','print-agent','platform')
foreach($component in @($plan.components)){
  if($allowed -notcontains [string]$component.name){ throw 'Unknown component in lifecycle plan.' }
  if([string]::IsNullOrWhiteSpace([string]$component.package_manifest)){ throw 'Component manifest is required.' }
  if(-not (Test-Path -LiteralPath ([string]$component.package_manifest) -PathType Leaf)){ throw 'Component manifest is missing.' }
}
[ordered]@{success=$true;mode=$Mode;validated_components=@($plan.components).Count;business_data_mutated=$false} | ConvertTo-Json -Compress
