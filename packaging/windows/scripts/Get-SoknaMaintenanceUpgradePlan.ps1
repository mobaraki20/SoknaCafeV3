# Maintenance-only version routing. No I/O and no host mutation.
Set-StrictMode -Version Latest
function Get-SoknaMaintenanceUpgradePlan {
  [CmdletBinding()]
  param([Parameter(Mandatory)][string]$SupervisorVersion,
        [Parameter(Mandatory)][string]$EngineVersion)
  if ($SupervisorVersion -ne $EngineVersion) {
    throw [IO.InvalidDataException]::new('MAINTENANCE_COMPONENT_VERSIONS_DISAGREE')
  }
  switch ($SupervisorVersion) {
    { $_ -in @('0.3.22','0.3.23','0.3.24','0.3.25') } {
      return [pscustomobject]@{ source=$SupervisorVersion; target='0.3.27'; stages=@('0326','0327'); classification='LEGACY_BRIDGE' }
    }
    '0.3.26' {
      return [pscustomobject]@{ source=$SupervisorVersion; target='0.3.27'; stages=@('0327'); classification='INDEPENDENT_PAIR_BRIDGE' }
    }
    '0.3.27' {
      return [pscustomobject]@{ source=$SupervisorVersion; target='0.3.27'; stages=@('0327'); classification='VERIFY_ALREADY_CURRENT' }
    }
    default {
      throw [IO.InvalidDataException]::new('MAINTENANCE_BASELINE_NOT_QUALIFIED')
    }
  }
}
