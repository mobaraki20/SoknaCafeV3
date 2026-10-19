using System.Text.Json;
using Sokna.Maintenance.Core;

// Tests the SAME durable-operation guard used by the executable
// local-slot-activate path. Entirely disposable: no Apache or SQL mutation.
internal static class RoutineLocalStateSafetyProbe
{
    internal static int Run(string inputRoot)
    {
        var root = Path.GetFullPath(inputRoot);
        var pkg = Path.Combine(root, "Package");
        var stable = Path.Combine(pkg, "Web");
        var oldSlot = Path.Combine(pkg, "Web.release.old");
        var nextSlot = Path.Combine(pkg, "Web.release.next");
        Directory.CreateDirectory(oldSlot);
        Directory.CreateDirectory(nextSlot);
        var paths = new MaintenancePaths(Path.Combine(root, "Maintenance"));
        Directory.CreateDirectory(paths.LocalSlotStateRoot);
        const string hash = "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa";
        var now = DateTimeOffset.UtcNow;
        var state = new RoutineLocalSlotState(
            "sokna-local-slot-routine-state-v1", 1, "COMMITTED",
            pkg, stable, "test-apache.exe", "http://127.0.0.1/login.php",
            oldSlot, nextSlot, "old", 2, hash, "new", 2, hash,
            true, false, false, false, false, now,
            new[] { new RoutineLocalSlotEvent("COMMITTED", now) });
        var evidencePath = Path.Combine(paths.LocalSlotStateRoot, "routine-state.json");
        void Save(RoutineLocalSlotState item) => AtomicFile.WriteJson(evidencePath, item);
        bool Denies(Action action)
        {
            try { action(); return false; }
            catch (InvalidOperationException) { return true; }
        }
        bool DeniesInvalid(Action action)
        {
            try { action(); return false; }
            catch (InvalidDataException) { return true; }
        }
        void Require(bool value, string reason)
        {
            if (!value) throw new InvalidDataException("ROUTINE_STATE_GUARD_FAILED: " + reason);
        }

        // Old installations without a previous routine receipt may proceed
        // only after the calling Engine independently verifies live baseline.
        ProductionLocalSlotMigrationBridge.RoutineLocalSlotSafety.RequirePreviousSettled(
            paths, pkg, stable, oldSlot, "old");
        Save(state);
        ProductionLocalSlotMigrationBridge.RoutineLocalSlotSafety.RequirePreviousSettled(
            paths, pkg, stable, nextSlot, "new");
        Require(Denies(() => ProductionLocalSlotMigrationBridge.RoutineLocalSlotSafety
            .RequirePreviousSettled(paths, pkg, stable, oldSlot, "old")),
            "terminal receipt mismatched physical active slot");
        Require(Denies(() => ProductionLocalSlotMigrationBridge.RoutineLocalSlotSafety
            .RequirePreviousSettled(paths, pkg, stable, nextSlot, "old")),
            "terminal receipt mismatched active build");
        Require(Denies(() => ProductionLocalSlotMigrationBridge.RoutineLocalSlotSafety
            .RequireInterruptedForRecovery(state)),
            "committed release mistaken for interrupted operation");
        Save(state with { RecoveryRequired = true });
        Require(Denies(() => ProductionLocalSlotMigrationBridge.RoutineLocalSlotSafety
            .RequirePreviousSettled(paths, pkg, stable, nextSlot, "new")),
            "recovery-required state accepted for new activation");
        Save(state with {
            Phase = "POINTER_SWITCH_INTENT",
            Events = new[] { new RoutineLocalSlotEvent("POINTER_SWITCH_INTENT", now) }
        });
        Require(Denies(() => ProductionLocalSlotMigrationBridge.RoutineLocalSlotSafety
            .RequirePreviousSettled(paths, pkg, stable, nextSlot, "new")),
            "interrupted pointer cutover overwritten");
        ProductionLocalSlotMigrationBridge.RoutineLocalSlotSafety.RequireInterruptedForRecovery(
            state with { Phase = "POINTER_SWITCH_INTENT",
                Events = new[] { new RoutineLocalSlotEvent("POINTER_SWITCH_INTENT", now) } });
        Save(state with {
            Phase = "RECOVERED_TO_PREVIOUS_SLOT",
            Events = new[] { new RoutineLocalSlotEvent("RECOVERED_TO_PREVIOUS_SLOT", now) }
        });
        ProductionLocalSlotMigrationBridge.RoutineLocalSlotSafety.RequirePreviousSettled(
            paths, pkg, stable, oldSlot, "old");
        Save(state with {
            Phase = "COMMITTED",
            Events = new[] { new RoutineLocalSlotEvent("POINTER_SWITCH_INTENT", now) }
        });
        Require(Denies(() => ProductionLocalSlotMigrationBridge.RoutineLocalSlotSafety
            .RequirePreviousSettled(paths, pkg, stable, nextSlot, "new")),
            "tampered last event accepted");
        File.WriteAllText(evidencePath, "{broken");
        Require(Denies(() => ProductionLocalSlotMigrationBridge.RoutineLocalSlotSafety
            .RequirePreviousSettled(paths, pkg, stable, nextSlot, "new")),
            "corrupt prior receipt overwritten");
        // Read-only preflight of bytes used by RecoverRoutine: truncated,
        // unrelated or corrupt evidence must be rejected before Web mutation.
        var lifeStatePath = Path.Combine(root, "state.json");
        var managedPath = Path.Combine(root, "managed-files.json");
        var validState = JsonSerializer.SerializeToUtf8Bytes(new {
            format = "sokna-local-update-state-v1", active_version = "old"
        });
        var validManaged = JsonSerializer.SerializeToUtf8Bytes(new {
            format = "sokna-local-managed-files-v1",
            files = new[] { "VERSION.txt" }
        });
        var rollbackPath = Path.Combine(paths.LocalSlotStateRoot,
            "routine-local-lifecycle-before.json");
        void Backup(string version, string stateBase64, string managedBase64,
            string? identityPath = null, string? identityBase64 = null)
        {
            File.WriteAllBytes(rollbackPath, JsonSerializer.SerializeToUtf8Bytes(new {
                format = "sokna-routine-local-lifecycle-backup-v1",
                active_build = version, state_path = lifeStatePath,
                managed_path = managedPath, state_base64 = stateBase64,
                managed_base64 = managedBase64,
                installed_identity_path = identityPath,
                installed_identity_base64 = identityBase64
            }));
        }
        Backup("old", Convert.ToBase64String(validState),
            Convert.ToBase64String(validManaged));
        RoutineLocalBackupProof.ValidateSnapshot(rollbackPath, "old",
            lifeStatePath, managedPath);
        Backup("wrong", Convert.ToBase64String(validState),
            Convert.ToBase64String(validManaged));
        Require(DeniesInvalid(() => RoutineLocalBackupProof.ValidateSnapshot(
            rollbackPath, "old", lifeStatePath, managedPath)),
            "unrelated rollback snapshot accepted");
        Backup("old", "not base64", Convert.ToBase64String(validManaged));
        Require(DeniesInvalid(() => RoutineLocalBackupProof.ValidateSnapshot(
            rollbackPath, "old", lifeStatePath, managedPath)),
            "corrupt lifecycle base64 accepted");
        Backup("old", Convert.ToBase64String(validState), "not base64");
        Require(DeniesInvalid(() => RoutineLocalBackupProof.ValidateSnapshot(
            rollbackPath, "old", lifeStatePath, managedPath)),
            "corrupt managed inventory base64 accepted");
        Backup("old", Convert.ToBase64String(JsonSerializer.SerializeToUtf8Bytes(
            new { format="sokna-local-update-state-v1",active_version="other" })),
            Convert.ToBase64String(validManaged));
        Require(DeniesInvalid(() => RoutineLocalBackupProof.ValidateSnapshot(
            rollbackPath, "old", lifeStatePath, managedPath)),
            "wrong active build in backup accepted");

        // Qualified cross-version release snapshots must restore the
        // Windows Maintenance installed-release identity together with Local
        // lifecycle; malformed, wrong-version or redirected proof is refused.
        var identityPath = Path.Combine(root, "installed-release.json");
        var priorIdentity = new InstalledReleaseIdentity(
            new ReleaseComponentIdentity("old-release", "old", hash,
                "same-schema", "70"), null, "0.3.6", "0.3.6", null, null);
        var identityBytes = JsonSerializer.SerializeToUtf8Bytes(priorIdentity);
        var encodedState = Convert.ToBase64String(validState);
        var encodedManaged = Convert.ToBase64String(validManaged);
        var encodedIdentity = Convert.ToBase64String(identityBytes);
        Backup("old", encodedState, encodedManaged, identityPath, encodedIdentity);
        RoutineLocalBackupProof.ValidateSnapshot(rollbackPath, "old",
            lifeStatePath, managedPath, identityPath);
        Require(DeniesInvalid(() => RoutineLocalBackupProof.ValidateSnapshot(
            rollbackPath, "old", lifeStatePath, managedPath)),
            "qualified identity rollback proof was downgraded to unqualified");
        Require(DeniesInvalid(() => RoutineLocalBackupProof.ValidateSnapshot(
            rollbackPath, "old", lifeStatePath, managedPath, identityPath + ".other")),
            "qualified rollback proof redirected to another identity file");
        Backup("old", encodedState, encodedManaged, identityPath, "not-base64");
        Require(DeniesInvalid(() => RoutineLocalBackupProof.ValidateSnapshot(
            rollbackPath, "old", lifeStatePath, managedPath, identityPath)),
            "invalid installed-identity backup base64 accepted");
        var wrongIdentity = JsonSerializer.SerializeToUtf8Bytes(priorIdentity with {
            Local = priorIdentity.Local! with { Version = "wrong" }
        });
        Backup("old", encodedState, encodedManaged, identityPath,
            Convert.ToBase64String(wrongIdentity));
        Require(DeniesInvalid(() => RoutineLocalBackupProof.ValidateSnapshot(
            rollbackPath, "old", lifeStatePath, managedPath, identityPath)),
            "installed identity backup for another release accepted");
        Backup("old", encodedState, encodedManaged);
        Require(DeniesInvalid(() => RoutineLocalBackupProof.ValidateSnapshot(
            rollbackPath, "old", lifeStatePath, managedPath, identityPath)),
            "qualified recovery accepted missing installed-identity snapshot");

        Console.WriteLine(JsonSerializer.Serialize(new {
            result = "PASS",
            real_engine_guard = true,
            failed_or_interrupted_overwrite_denied = true,
            recovered_state_can_reenter = true,
            terminal_recovery_denied = true,
            rollback_backup_corruption_denied = true,
            installed_identity_qualified_rollback_proof_verified = true,
            actual_installed_host_mutated = false,
            panel_update_ready = false
        }));
        return 0;
    }
}
