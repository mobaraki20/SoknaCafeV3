[CmdletBinding()]
param()
$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest

$manifest=Get-Content 'packaging/windows/windows-services-component-manifest-v2.json' -Raw | ConvertFrom-Json
if($manifest.format -ne 'sokna-windows-component-manifest-v2'){throw 'OWNER_MANIFEST_CONTRACT_DRIFT'}
$agents=@($manifest.components | Where-Object {$_.component_id -eq 'print-agent'})
if($agents.Count -ne 1){throw 'PRINT_OWNER_COMPONENT_MISSING_OR_DUPLICATE'}
$p=$agents[0]
if($p.service_name -ne 'SoknaPrintWorker'){throw 'PRINT_OWNER_SERVICE_NAME_DRIFT'}
if($p.installed_primary -ne 'PrintAgent/Service/Sokna.PrintAgent.Service.exe'){throw 'PRINT_OWNER_INSTALLED_PATH_DRIFT'}
if($p.transaction_group -ne 'print-agent'){throw 'PRINT_OWNER_TX_GROUP_DRIFT'}
if($manifest.rules.version_only_is_sufficient -ne $false){throw 'PRINT_VERSION_ONLY_UNSAFE'}
if($manifest.rules.unsafe_service_image_outside_owned_root -ne 'BLOCKED'){throw 'PRINT_FOREIGN_OWNER_UNSAFE'}
if($manifest.rules.keep_mutation_allowed -ne $false){throw 'PRINT_KEEP_MUTATION_UNSAFE'}

$maintScript=Get-Content 'packaging/windows/scripts/invoke-maintenance-independent-v1.ps1' -Raw
if($maintScript -notmatch 'machine-mutation\.lock'){throw 'MAINTENANCE_COORDINATION_LEASE_REFERENCE_MISSING'}
if($maintScript -notmatch 'maintenance-independent-pair-v1'){throw 'MAINTENANCE_INDEPENDENT_RECEIPT_REFERENCE_MISSING'}

$probe=Get-Content 'qa/sokna-printagent-universal/Invoke-SoknaPrintAgentUniversal.ps1' -Raw
if($probe -notmatch 'QA_HOLD_REAL_WINDOWS_UPGRADE_AND_OWNER_COMPATIBILITY_NOT_QUALIFIED'){throw 'PRINT_APPLY_WAS_UNSAFEly_ENABLED'}
if($probe -notmatch '\$TargetVersion\s*=\s*\[version\]\s*\x276\.2\.12\x27'){throw 'PRINT_QA_TARGET_DRIFT'}
if($probe -notmatch 'SoknaPrintWorker'){throw 'PRINT_QA_SERVICE_OWNER_DRIFT'}
if($probe -notmatch 'maintenance_mutated=\$false'){throw 'MAINTENANCE_SCOPE_GUARD_NOT_FOUND'}
if($probe -notmatch 'runtime_mutated=\$false'){throw 'RUNTIME_SCOPE_GUARD_NOT_FOUND'}

Write-Host 'PASS_PRINT_OWNER_LEGACY_CONTRACT_RECORDED_AND_APPLY_BLOCKED'
Write-Host 'PENDING: independent Print receipt validator integrated into Windows Services lifecycle owner'
Write-Host 'PENDING: shared mutation lease, durable queue preservation, service readback and rollback on real Windows'

# The previous QA scenario intentionally returned nonzero; do not inherit it here.
$global:LASTEXITCODE = 0
