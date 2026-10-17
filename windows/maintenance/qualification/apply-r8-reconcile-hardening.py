from pathlib import Path

ROOT = Path(__file__).resolve().parents[3]
coord = ROOT / "windows/maintenance/src/Sokna.Maintenance.Core/SupervisorProductionMigrationCoordinator.cs"
queue = ROOT / "windows/maintenance/src/Sokna.Maintenance.Core/LocalSlotAdoptionQueue.cs"


def replace_once(text: str, old: str, new: str, label: str) -> str:
    count = text.count(old)
    if count != 1:
        raise SystemExit(f"{label}: expected exactly one anchor, found {count}")
    return text.replace(old, new, 1)


c = coord.read_text(encoding="utf-8")
c = replace_once(
    c,
    '''            LocalManagedTreeAttestation.RequireExact(state.StableWebRoot, expectedManagedFiles, expectedManagedTreeSha256);\n            if (!StableWebIsJunction(state.StableWebRoot)) throw new InvalidDataException("Committed Local migration did not leave the stable Web root as a junction.");\n\n            operation = _operations.Update(\n''',
    '''            LocalManagedTreeAttestation.RequireExact(state.StableWebRoot, expectedManagedFiles, expectedManagedTreeSha256);\n            if (!StableWebIsJunction(state.StableWebRoot)) throw new InvalidDataException("Committed Local migration did not leave the stable Web root as a junction.");\n            var committedTarget = Directory.ResolveLinkTarget(state.StableWebRoot, returnFinalTarget: true)?.FullName;\n            ProductionLocalSlotMigrationBridge.RequireProductionSlotPath(committedTarget, state.PackageRoot);\n            if (string.IsNullOrWhiteSpace(state.ActiveSlot) ||\n                !string.Equals(Path.GetFullPath(committedTarget!), Path.GetFullPath(state.ActiveSlot), StringComparison.OrdinalIgnoreCase))\n                throw new InvalidDataException("Committed Local migration active-slot state does not match the stable Web junction target.");\n\n            operation = _operations.Update(\n''',
    "coordinator committed result",
)
c = replace_once(
    c,
    '''            if (!Directory.Exists(state.StableWebRoot)) return false;\n            if (!StableWebIsJunction(state.StableWebRoot)) return false;\n            LocalManagedTreeAttestation.RequireExact(state.StableWebRoot, expectedManagedFiles, expectedManagedTreeSha256);\n            detail = $"Durable Local migration state and active junction were reconciled successfully; phase={state.Phase}.";\n''',
    '''            if (!Directory.Exists(state.StableWebRoot)) return false;\n            if (!StableWebIsJunction(state.StableWebRoot)) return false;\n            var activeTarget = Directory.ResolveLinkTarget(state.StableWebRoot, returnFinalTarget: true)?.FullName;\n            ProductionLocalSlotMigrationBridge.RequireProductionSlotPath(activeTarget, state.PackageRoot);\n            if (string.IsNullOrWhiteSpace(state.ActiveSlot) ||\n                !string.Equals(Path.GetFullPath(activeTarget!), Path.GetFullPath(state.ActiveSlot), StringComparison.OrdinalIgnoreCase))\n            {\n                detail = "Durable Local migration active-slot state does not match the stable Web junction target.";\n                return false;\n            }\n            LocalManagedTreeAttestation.RequireExact(state.StableWebRoot, expectedManagedFiles, expectedManagedTreeSha256);\n            detail = $"Durable Local migration state and active sibling-release junction were reconciled successfully; phase={state.Phase}.";\n''',
    "coordinator durable reconcile",
)
coord.write_text(c, encoding="utf-8")

q = queue.read_text(encoding="utf-8")
q = replace_once(
    q,
    '''            if (!Directory.Exists(migrationState.StableWebRoot) ||\n                (File.GetAttributes(migrationState.StableWebRoot) & FileAttributes.ReparsePoint) == 0)\n                return status;\n            LocalManagedTreeAttestation.RequireExact(\n''',
    '''            if (!Directory.Exists(migrationState.StableWebRoot) ||\n                (File.GetAttributes(migrationState.StableWebRoot) & FileAttributes.ReparsePoint) == 0)\n                return status;\n            try\n            {\n                var activeTarget = Directory.ResolveLinkTarget(migrationState.StableWebRoot, returnFinalTarget: true)?.FullName;\n                ProductionLocalSlotMigrationBridge.RequireProductionSlotPath(activeTarget, migrationState.PackageRoot);\n                if (string.IsNullOrWhiteSpace(migrationState.ActiveSlot) ||\n                    !string.Equals(Path.GetFullPath(activeTarget!), Path.GetFullPath(migrationState.ActiveSlot), StringComparison.OrdinalIgnoreCase))\n                    return status;\n            }\n            catch (Exception ex) when (ex is IOException or InvalidDataException or UnauthorizedAccessException)\n            {\n                return status;\n            }\n            LocalManagedTreeAttestation.RequireExact(\n''',
    "adoption recovery reconcile",
)
queue.write_text(q, encoding="utf-8")

print("R8 reconcile hardening patch applied")
