from pathlib import Path

ROOT = Path(__file__).resolve().parents[3]
coord = ROOT / "windows/maintenance/src/Sokna.Maintenance.Core/SupervisorProductionMigrationCoordinator.cs"
queue = ROOT / "windows/maintenance/src/Sokna.Maintenance.Core/LocalSlotAdoptionQueue.cs"


def insert_after_once(text: str, anchor: str, addition: str, label: str) -> str:
    count = text.count(anchor)
    if count != 1:
        raise SystemExit(f"{label}: expected exactly one anchor, found {count}")
    return text.replace(anchor, anchor + addition, 1)


def insert_before_once(text: str, anchor: str, addition: str, label: str) -> str:
    count = text.count(anchor)
    if count != 1:
        raise SystemExit(f"{label}: expected exactly one anchor, found {count}")
    return text.replace(anchor, addition + anchor, 1)


c = coord.read_text(encoding="utf-8-sig")
anchor1 = '            if (!StableWebIsJunction(state.StableWebRoot)) throw new InvalidDataException("Committed Local migration did not leave the stable Web root as a junction.");\n'
add1 = '''            var committedTarget = Directory.ResolveLinkTarget(state.StableWebRoot, returnFinalTarget: true)?.FullName;\n            ProductionLocalSlotMigrationBridge.RequireProductionSlotPath(committedTarget, state.PackageRoot);\n            if (string.IsNullOrWhiteSpace(state.ActiveSlot) ||\n                !string.Equals(Path.GetFullPath(committedTarget!), Path.GetFullPath(state.ActiveSlot), StringComparison.OrdinalIgnoreCase))\n                throw new InvalidDataException("Committed Local migration active-slot state does not match the stable Web junction target.");\n'''
c = insert_after_once(c, anchor1, add1, "coordinator committed result")

anchor2 = '            if (!StableWebIsJunction(state.StableWebRoot)) return false;\n'
add2 = '''            var activeTarget = Directory.ResolveLinkTarget(state.StableWebRoot, returnFinalTarget: true)?.FullName;\n            ProductionLocalSlotMigrationBridge.RequireProductionSlotPath(activeTarget, state.PackageRoot);\n            if (string.IsNullOrWhiteSpace(state.ActiveSlot) ||\n                !string.Equals(Path.GetFullPath(activeTarget!), Path.GetFullPath(state.ActiveSlot), StringComparison.OrdinalIgnoreCase))\n            {\n                detail = "Durable Local migration active-slot state does not match the stable Web junction target.";\n                return false;\n            }\n'''
c = insert_after_once(c, anchor2, add2, "coordinator durable reconcile")
c = c.replace(
    '            detail = $"Durable Local migration state and active junction were reconciled successfully; phase={state.Phase}.";\n',
    '            detail = $"Durable Local migration state and active sibling-release junction were reconciled successfully; phase={state.Phase}.";\n',
    1,
)
coord.write_text(c, encoding="utf-8")

q = queue.read_text(encoding="utf-8-sig")
anchor3 = '''            LocalManagedTreeAttestation.RequireExact(\n                migrationState.StableWebRoot,\n'''
add3 = '''            try\n            {\n                var activeTarget = Directory.ResolveLinkTarget(migrationState.StableWebRoot, returnFinalTarget: true)?.FullName;\n                ProductionLocalSlotMigrationBridge.RequireProductionSlotPath(activeTarget, migrationState.PackageRoot);\n                if (string.IsNullOrWhiteSpace(migrationState.ActiveSlot) ||\n                    !string.Equals(Path.GetFullPath(activeTarget!), Path.GetFullPath(migrationState.ActiveSlot), StringComparison.OrdinalIgnoreCase))\n                    return status;\n            }\n            catch (Exception ex) when (ex is IOException or InvalidDataException or UnauthorizedAccessException)\n            {\n                return status;\n            }\n'''
q = insert_before_once(q, anchor3, add3, "adoption recovery reconcile")
queue.write_text(q, encoding="utf-8")

print("R8 reconcile hardening patch applied")
