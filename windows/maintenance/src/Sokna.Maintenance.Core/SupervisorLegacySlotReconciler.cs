using System.Security.Cryptography;
using System.Text.Json.Serialization;

namespace Sokna.Maintenance.Core;

/// <summary>
/// Settles an obsolete Phase-3 home-cycle recovery blocker ONLY after a later
/// committed R8 migration and independently observed live Windows state agree.
/// This is a ledger reconciliation, not activation, recovery or identity adoption.
/// </summary>
public sealed class SupervisorLegacySlotReconciler
{
    private readonly MaintenancePaths _paths;
    private readonly MaintenanceOperationStore _operations;
    private readonly DurableJournal _journal;
    private readonly SupervisorState _state;
    private readonly Func<LocalInstallLayout> _inspectHealthy;

    public SupervisorLegacySlotReconciler(MaintenancePaths paths,
        MaintenanceOperationStore operations, DurableJournal journal, SupervisorState state,
        Func<LocalInstallLayout>? inspectHealthy = null)
    {
        _paths = paths; _operations = operations; _journal = journal; _state = state;
        _inspectHealthy = inspectHealthy ?? new LocalSlotManager(paths).InspectHealthyCurrent;
    }

    public IReadOnlyList<MaintenanceOperation> Reconcile()
    {
        lock (LocalUpdateOperationGate.Sync)
        {
            var blocked = _operations.ListBlocking();
            var candidates = blocked.Where(static op =>
                op.OperationType == "update.local-slot-home-cycle" &&
                op.State == "recovery_required" && !op.DatabaseMutationPerformed).ToArray();
            if (candidates.Length == 0) return Array.Empty<MaintenanceOperation>();

            // A proof for one specific old operation must not erase unrelated
            // recovery blockers. Verify the live system BEFORE any ledger write.
            var layout = _inspectHealthy();
            if (!layout.WebIsReparsePoint || layout.ActiveSlotTarget is null)
                throw new InvalidDataException("LEGACY_SLOT_RECONCILE_JUNCTION_UNPROVEN");
            ProductionLocalSlotMigrationBridge.RequireProductionSlotPath(
                layout.ActiveSlotTarget, layout.PackageRoot);
            var identity = AtomicFile.ReadJson<InstalledReleaseIdentity>(
                _paths.InstalledReleaseIdentityPath);
            if (identity.Local is null || identity.Local.Version != layout.Build)
                throw new InvalidDataException("LEGACY_SLOT_RECONCILE_IDENTITY_VERSION_MISMATCH");
            var tree = LocalManagedTreeAttestation.RequireExact(
                layout.WebRoot, layout.ManagedFileCount, identity.Local.ManagedTreeSha256);
            var persistent = Path.Combine(layout.PackageRoot, "LocalWeb", "persistent");
            RoutineLocalBinding.RequireCurrent(layout.WebRoot, layout.PackageRoot, layout.Build);
            RoutineLocalBinding.RequireTarget(persistent, layout.ActiveSlotTarget,
                layout.PackageRoot, layout.Build);

            var path = Path.Combine(_paths.LocalSlotStateRoot, "migration-state.json");
            var migration = AtomicFile.ReadJson<ProductionLocalSlotMigrationState>(path);
            if (migration.Phase != "COMMITTED" || migration.RecoveryRequired ||
                migration.DatabaseMutationPerformed || migration.PublicMutationPerformed ||
                migration.WindowsRebootRequired ||
                migration.ExpectedBuild != layout.Build ||
                migration.ExpectedManagedFiles != layout.ManagedFileCount ||
                !EqualHash(migration.ExpectedManagedTreeSha256, tree) ||
                !SamePath(migration.PackageRoot, layout.PackageRoot) ||
                !SamePath(migration.StableWebRoot, layout.WebRoot) ||
                !SamePath(migration.ActiveSlot, layout.ActiveSlotTarget) ||
                !migration.Events.Any(e => e.State == "COMMITTED" &&
                    e.AtUtc <= migration.UpdatedAtUtc))
                throw new InvalidDataException("LEGACY_SLOT_RECONCILE_MIGRATION_STATE_UNPROVEN");

            var all = _operations.ListRecent(100); // only for diagnostics; proof lookup below scans all
            _ = all;
            var commits = _operations.ListCommittedLocalMigrations();
            var resolved = new List<MaintenanceOperation>();
            foreach (var old in candidates)
            {
                // Require a later successfully committed Supervisor migration,
                // not merely an external installer success message.
                var newer = commits.Where(op =>
                    op.CreatedAtUtc > old.CreatedAtUtc &&
                    op.UpdatedAtUtc >= migration.UpdatedAtUtc &&
                    op.StageReceiptPath is not null &&
                    SamePath(op.StageReceiptPath, path) &&
                    op.ResultCode is ("LOCAL_SLOT_MIGRATION_COMMITTED" or
                        "LOCAL_SLOT_LAYOUT_ALREADY_READY" or
                        "LOCAL_SLOT_COMMIT_RECONCILED_AFTER_ENGINE_EXIT") &&
                    op.PackageRef == $"production-local-slot-migration:{migration.ExpectedBuild}:" +
                        $"{migration.ExpectedManagedFiles}:{migration.ExpectedManagedTreeSha256}" &&
                    op.CanonicalRequestSha256 == MaintenanceOperationStore.CanonicalRequestHash(
                        "update.local-slot-migrate", op.PackageRef, op.OperatorId))
                    .OrderByDescending(static op => op.UpdatedAtUtc).FirstOrDefault();
                if (newer is null ||
                    !migration.Events.Any(e => e.State == "COMMITTED" && e.AtUtc > old.CreatedAtUtc))
                    continue;

                var receipt = new LegacySlotReconciliationReceipt(
                    "sokna-legacy-slot-reconciliation-v1", 1,
                    old.OperationId, old.State, old.ResultCode, old.ResultMessage,
                    newer.OperationId, newer.UpdatedAtUtc, migration.UpdatedAtUtc,
                    layout.Build, layout.ActiveSlotTarget, tree, DateTimeOffset.UtcNow,
                    "verified_newer_committed_migration_live_junction_apache_identity_and_tree");
                // Write evidence first. A crash before the operation transition
                // keeps the blocker intact; retry is safe and idempotent.
                AtomicFile.WriteJson(Path.Combine(_paths.OperationsRoot,
                    "reconciliation-" + old.OperationId + ".json"), receipt);
                var closed = _operations.Update(old, "superseded_verified",
                    "LOCAL_SLOT_SUPERSEDED_BY_VERIFIED_MIGRATION",
                    $"Legacy recovery superseded by independently verified committed migration {newer.OperationId}; original outcome retained in reconciliation receipt.",
                    activationPerformed: old.ActivationPerformed,
                    databaseMutationPerformed: false);
                _journal.Append("update.local_slot.legacy_reconciled", _state.InstanceId,
                    _state.InstallGeneration, new Dictionary<string, object?>
                    {
                        ["operation_id"] = old.OperationId,
                        ["original_result_code"] = old.ResultCode,
                        ["superseding_operation_id"] = newer.OperationId,
                        ["verified_build"] = layout.Build,
                        ["managed_tree_sha256"] = tree,
                        ["active_slot"] = layout.ActiveSlotTarget,
                        ["database_mutation_performed"] = false
                    });
                resolved.Add(closed);
            }
            return resolved;
        }
    }

    private static bool EqualHash(string? a, string? b) =>
        a is not null && b is not null && a.Length == 64 &&
        b.Length == 64 && CryptographicOperations.FixedTimeEquals(
            Convert.FromHexString(a), Convert.FromHexString(b));
    private static bool SamePath(string? a, string? b) =>
        !string.IsNullOrWhiteSpace(a) && !string.IsNullOrWhiteSpace(b) &&
        string.Equals(Path.GetFullPath(a), Path.GetFullPath(b),
            StringComparison.OrdinalIgnoreCase);
}

public sealed record LegacySlotReconciliationReceipt(
    [property: JsonPropertyName("format")] string Format,
    [property: JsonPropertyName("schema_version")] int SchemaVersion,
    [property: JsonPropertyName("old_operation_id")] string OldOperationId,
    [property: JsonPropertyName("old_state")] string OldState,
    [property: JsonPropertyName("old_result_code")] string? OldResultCode,
    [property: JsonPropertyName("old_result_message")] string? OldResultMessage,
    [property: JsonPropertyName("superseding_operation_id")] string SupersedingOperationId,
    [property: JsonPropertyName("superseding_committed_at_utc")] DateTimeOffset SupersedingCommittedAtUtc,
    [property: JsonPropertyName("migration_state_updated_at_utc")] DateTimeOffset MigrationStateUpdatedAtUtc,
    [property: JsonPropertyName("observed_build")] string ObservedBuild,
    [property: JsonPropertyName("observed_active_slot")] string ObservedActiveSlot,
    [property: JsonPropertyName("observed_tree_sha256")] string ObservedTreeSha256,
    [property: JsonPropertyName("reconciled_at_utc")] DateTimeOffset ReconciledAtUtc,
    [property: JsonPropertyName("evidence")] string Evidence);
