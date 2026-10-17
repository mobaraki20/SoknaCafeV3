from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[3]
coord = ROOT / "windows/maintenance/src/Sokna.Maintenance.Core/SupervisorProductionMigrationCoordinator.cs"
queue = ROOT / "windows/maintenance/src/Sokna.Maintenance.Core/LocalSlotAdoptionQueue.cs"


def sub_once(text: str, pattern: str, replacement: str, label: str) -> str:
    updated, count = re.subn(pattern, replacement, text, count=1, flags=re.MULTILINE)
    if count != 1:
        raise SystemExit(f"{label}: expected exactly one anchor, found {count}")
    return updated


c = coord.read_text(encoding="utf-8-sig")
c = sub_once(
    c,
    r'''(?P<indent>\s*)LocalManagedTreeAttestation\.RequireExact\(state\.StableWebRoot, expectedManagedFiles, expectedManagedTreeSha256\);\s*\n(?P=indent)if \(!StableWebIsJunction\(state\.StableWebRoot\)\) throw new InvalidDataException\("Committed Local migration did not leave the stable Web root as a junction\."\);\s*\n(?P=indent)\n(?P=indent)operation = _operations\.Update\(''',
    '''            LocalManagedTreeAttestation.RequireExact(state.StableWebRoot, expectedManagedFiles, expectedManagedTreeSha256);\n            if (!StableWebIsJunction(state.StableWebRoot)) throw new InvalidDataException("Committed Local migration did not leave the stable Web root as a junction.");\n            var committedTarget = Directory.ResolveLinkTarget(state.StableWebRoot, returnFinalTarget: true)?.FullName;\n            ProductionLocalSlotMigrationBridge.RequireProductionSlotPath(committedTarget, state.PackageRoot);\n            if (string.IsNullOrWhiteSpace(state.ActiveSlot) ||\n                !string.Equals(Path.GetFullPath(committedTarget!), Path.GetFullPath(state.ActiveSlot), StringComparison.OrdinalIgnoreCase))\n                throw new InvalidDataException("Committed Local migration active-slot state does not match the stable Web junction target.");\n\n            operation = _operations.Update(''',
    "coordinator committed result",
)
c = sub_once(
    c,
    r'''(?P<indent>\s*)if \(!Directory\.Exists\(state\.StableWebRoot\)\) return false;\s*\n(?P=indent)if \(!StableWebIsJunction\(state\.StableWebRoot\)\) return false;\s*\n(?P=indent)LocalManagedTreeAttestation\.RequireExact\(state\.StableWebRoot, expectedManagedFiles, expectedManagedTreeSha256\);\s*\n(?P=indent)detail = \$"Durable Local migration state and active junction were reconciled successfully; phase=\{state\.Phase\}\.";''',
    '''            if (!Directory.Exists(state.StableWebRoot)) return false;\n            if (!StableWebIsJunction(state.StableWebRoot)) return false;\n            var activeTarget = Directory.ResolveLinkTarget(state.StableWebRoot, returnFinalTarget: true)?.FullName;\n            ProductionLocalSlotMigrationBridge.RequireProductionSlotPath(activeTarget, state.PackageRoot);\n            if (string.IsNullOrWhiteSpace(state.ActiveSlot) ||\n                !string.Equals(Path.GetFullPath(activeTarget!), Path.GetFullPath(state.ActiveSlot), StringComparison.OrdinalIgnoreCase))\n            {\n                detail = "Durable Local migration active-slot state does not match the stable Web junction target.";\n                return false;\n            }\n            LocalManagedTreeAttestation.RequireExact(state.StableWebRoot, expectedManagedFiles, expectedManagedTreeSha256);\n            detail = $"Durable Local migration state and active sibling-release junction were reconciled successfully; phase={state.Phase}.";''',
    "coordinator durable reconcile",
)
coord.write_text(c, encoding="utf-8")

q = queue.read_text(encoding="utf-8-sig")
q = sub_once(
    q,
    r'''(?P<indent>\s*)if \(!Directory\.Exists\(migrationState\.StableWebRoot\) \|\|\s*\n\s*\(File\.GetAttributes\(migrationState\.StableWebRoot\) & FileAttributes\.ReparsePoint\) == 0\)\s*\n\s*return status;\s*\n\s*LocalManagedTreeAttestation\.RequireExact\(''',
    '''            if (!Directory.Exists(migrationState.StableWebRoot) ||\n                (File.GetAttributes(migrationState.StableWebRoot) & FileAttributes.ReparsePoint) == 0)\n                return status;\n            try\n            {\n                var activeTarget = Directory.ResolveLinkTarget(migrationState.StableWebRoot, returnFinalTarget: true)?.FullName;\n                ProductionLocalSlotMigrationBridge.RequireProductionSlotPath(activeTarget, migrationState.PackageRoot);\n                if (string.IsNullOrWhiteSpace(migrationState.ActiveSlot) ||\n                    !string.Equals(Path.GetFullPath(activeTarget!), Path.GetFullPath(migrationState.ActiveSlot), StringComparison.OrdinalIgnoreCase))\n                    return status;\n            }\n            catch (Exception ex) when (ex is IOException or InvalidDataException or UnauthorizedAccessException)\n            {\n                return status;\n            }\n            LocalManagedTreeAttestation.RequireExact(''',
    "adoption recovery reconcile",
)
queue.write_text(q, encoding="utf-8")

print("R8 reconcile hardening patch applied")
