#!/usr/bin/env python3
"""Validate Maintenance-only 0.3.22 exact version + scope, not a production permit."""
from pathlib import Path
import re
root=Path(__file__).resolve().parents[2]
def read(p):return (root/p).read_text(encoding="utf-8")
for name in ["Sokna.Maintenance.Core","Sokna.Maintenance.Supervisor","Sokna.Maintenance.UpdateEngine"]:
    p=f"windows/maintenance/src/{name}/{name}.csproj"
    s=read(p)
    assert "<Version>0.3.22</Version>" in s,p
    assert "<FileVersion>0.3.22.0</FileVersion>" in s,p
for p in ["packaging/windows/scripts/prepare-maintenance-only-payload-v1.ps1",
          "packaging/windows/scripts/invoke-maintenance-only-v1.ps1",
          "packaging/windows/scripts/setup-windows-components-maintenance-v1.ps1",
          "packaging/windows/scripts/maintenance-installed-versions-v1.ps1"]:
    s=read(p)
    assert "0.3.22" in s and "0.3.21" not in s or "0.3.21" in s and "0.3.22" in s,p
    assert "Runtime" not in p and "PrintAgent" not in p
inv=read("packaging/windows/scripts/invoke-maintenance-only-v1.ps1")
assert "'0.3.20','0.3.21','0.3.22'" in inv
assert "if($installedVersion-eq'0.3.22')" in inv
assert "Assert-MaintenanceSignedHealth" in inv
assert "release_scope='maintenance_only'" in inv
core=read("packaging/windows/scripts/setup-windows-components-maintenance-v1.ps1")
assert "'0.3.20','0.3.21')" in core
assert "'0.3.22'" in core
assert "MAINTENANCE_GROUP_EXACTLY_TWO_REQUIRED" in core
prepare=read("packaging/windows/scripts/prepare-maintenance-only-payload-v1.ps1")
assert "runtime_changes=$false;print_agent_changes=$false;recovery_console_changes=$false" in prepare
assert "release_scope='maintenance_only'" in prepare
assert "SoknaMaintenanceSupervisor.exe" in prepare and "SoknaMaintenanceUpdateEngine.exe" in prepare
iss=read("packaging/windows/installer/SOKNA-Maintenance-Only-v1.iss")
assert "AppVersion=0.3.22" in iss
assert "OutputBaseFilename=SOKNA-Maintenance-Only-0.3.22-R135-RECOVERY-SAFETY-UAT" in iss
stage=read("windows/maintenance/src/Sokna.Maintenance.Core/SupervisorLocalReleaseStageCoordinator.cs")
assert stage.index("RequireInstallableLegacyRelease(") < stage.index("_operations.GetOrCreate("), "Stage must reject before durable allocation"
print("R135_SCOPED_SOURCE_RELEASE_CONTRACT_PASS")
