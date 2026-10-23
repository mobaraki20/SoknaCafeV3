using System.Security.Cryptography;
using System.Text.Json.Serialization;

namespace Sokna.Maintenance.Core;

public sealed record InactiveLocalSlotRequest(
    [property: JsonPropertyName("stage_operation_id")] string StageOperationId,
    [property: JsonPropertyName("operator_id")] string OperatorId,
    [property: JsonPropertyName("private_candidate_idempotency_key")] string PrivateCandidateIdempotencyKey);

public sealed record InactiveLocalSlotReceipt(
    [property: JsonPropertyName("format")] string Format,
    [property: JsonPropertyName("schema_version")] int SchemaVersion,
    [property: JsonPropertyName("stage_operation_id")] string StageOperationId,
    [property: JsonPropertyName("operator_id")] string OperatorId,
    [property: JsonPropertyName("idempotency_key")] string IdempotencyKey,
    [property: JsonPropertyName("release_set_id")] string ReleaseSetId,
    [property: JsonPropertyName("target_version")] string TargetVersion,
    [property: JsonPropertyName("target_tree_sha256")] string TargetTreeSha256,
    [property: JsonPropertyName("target_managed_files")] int TargetManagedFiles,
    [property: JsonPropertyName("private_candidate_sha256")] string PrivateCandidateSha256,
    [property: JsonPropertyName("installed_identity_sha256")] string InstalledIdentitySha256,
    [property: JsonPropertyName("previous_active_slot")] string PreviousActiveSlot,
    [property: JsonPropertyName("inactive_slot")] string InactiveSlot,
    [property: JsonPropertyName("prepared_at_utc")] DateTimeOffset PreparedAtUtc,
    [property: JsonPropertyName("actual_host_health_qualified")] bool ActualHostHealthQualified,
    [property: JsonPropertyName("promotion_qualified")] bool PromotionQualified,
    [property: JsonPropertyName("activation_allowed")] bool ActivationAllowed,
    [property: JsonPropertyName("activation_performed")] bool ActivationPerformed,
    [property: JsonPropertyName("database_mutation_performed")] bool DatabaseMutationPerformed,
    [property: JsonPropertyName("public_mutation_performed")] bool PublicMutationPerformed);

public sealed record InactiveLocalSlotOutcome(
    [property: JsonPropertyName("receipt")] InactiveLocalSlotReceipt Receipt,
    [property: JsonPropertyName("created")] bool Created,
    [property: JsonPropertyName("reconciled")] bool Reconciled);

/// <summary>
/// F1-S7C2. Creates an INACTIVE physical Web.release.* sibling of the actual
/// Apache-stable junction. It never swaps the junction, stops services,
/// touches persistent snapshots, runs DB migrations or contacts Public.
/// An interrupted copy is quarantined for manual recovery, not overwritten.
/// </summary>
public sealed class SupervisorInactiveLocalSlotCoordinator
{
    private readonly MaintenancePaths _paths;
    private readonly MaintenanceOperationStore _operations;
    private readonly SupervisorInactiveLocalCandidateCoordinator _private;
    private readonly DurableJournal _journal;
    private readonly SupervisorState _state;

    public SupervisorInactiveLocalSlotCoordinator(MaintenancePaths paths,
        MaintenanceOperationStore operations,
        SupervisorInactiveLocalCandidateCoordinator privateCandidate,
        DurableJournal journal, SupervisorState state)
    {
        _paths = paths; _operations = operations; _private = privateCandidate;
        _journal = journal; _state = state;
    }

    public InactiveLocalSlotOutcome Prepare(string stageOperationId, string operatorId,
        string idempotencyKey, string privateCandidateIdempotencyKey,
        string? ownActivationOperationId = null)
    {
        var step = "CANDIDATE";
        try { return PrepareCore(stageOperationId, operatorId, idempotencyKey,
            privateCandidateIdempotencyKey, ownActivationOperationId, value => step = value); }
        catch (Exception ex) when (ex is IOException or InvalidDataException or
            UnauthorizedAccessException or InvalidOperationException or
            System.ComponentModel.Win32Exception or TimeoutException or
            System.Text.Json.JsonException or CryptographicException)
        {
            var error = new LocalPreparationException(step, ex);
            _journal.Append("update.local_inactive_slot.failed", _state.InstanceId,
                _state.InstallGeneration, new Dictionary<string, object?> {
                    ["stage_operation_id"] = stageOperationId,
                    ["preparation_step"] = error.Step, ["reason_code"] = error.ReasonCode,
                    ["activation_performed"] = false, ["database_mutation_performed"] = false
                });
            throw error;
        }
    }

    private InactiveLocalSlotOutcome PrepareCore(string stageOperationId, string operatorId,
        string idempotencyKey, string privateCandidateIdempotencyKey,
        string? ownActivationOperationId, Action<string> step)
    {
        if (!MaintenanceOperationStore.IsValidIdempotencyKey(idempotencyKey) ||
            !MaintenanceOperationStore.IsValidIdempotencyKey(privateCandidateIdempotencyKey))
            throw new InvalidDataException("INACTIVE_SLOT_IDEMPOTENCY_INVALID");
        // Reattest the signed Stage and the private extraction independently.
        // This owner holds a different OS lease, so complete the private C1
        // inspection before acquiring our cross-process machine-wide lease.
        var candidate = _private.Prepare(stageOperationId, operatorId, privateCandidateIdempotencyKey,
            ownActivationOperationId);
        var sourceReceipt = candidate.Receipt;
        step("HOST_BASELINE");
        lock (LocalUpdateOperationGate.Sync)
        {
            using var lease = MachineMutationLease.Acquire(_paths, "supervisor-inactive-slot");
            var stage = _operations.Get(stageOperationId)
                ?? throw new InvalidDataException("INACTIVE_SLOT_STAGE_MISSING");
            LocalUpdateOperationGate.RequireStageInspection(_operations, stage, ownActivationOperationId);
            if (sourceReceipt.StageOperationId != stageOperationId ||
                sourceReceipt.OperatorId != operatorId ||
                sourceReceipt.PromotionQualified || sourceReceipt.ActivationPerformed ||
                sourceReceipt.DatabaseMutationPerformed || sourceReceipt.PublicMutationPerformed)
                throw new InvalidDataException("INACTIVE_SLOT_CANDIDATE_UNQUALIFIED");

            // Current host is discovered from the authoritative SoknaApache
            // service and must be healthy BEFORE any sibling mutation.
            var manager = new LocalSlotManager(_paths);
            var layout = manager.InspectHealthyCurrent();
            if (!layout.WebIsReparsePoint || layout.ActiveSlotTarget is null)
                throw new InvalidDataException("INACTIVE_SLOT_REQUIRES_R8_JUNCTION");
            ProductionLocalSlotMigrationBridge.RequireProductionSlotPath(
                layout.ActiveSlotTarget, layout.PackageRoot);
            var previous = Path.GetFullPath(layout.ActiveSlotTarget);
            var root = Path.GetFullPath(layout.PackageRoot);
            var target = ProductionLocalSlotMigrationBridge.BuildProductionSlotPath(
                root, sourceReceipt.TargetVersion, sourceReceipt.TargetManagedTreeSha256);
            if (string.Equals(target, previous, StringComparison.OrdinalIgnoreCase))
                throw new InvalidDataException("INACTIVE_SLOT_TARGET_IS_ACTIVE");
            if (Directory.Exists(target) && (File.GetAttributes(target) & FileAttributes.ReparsePoint) != 0)
                throw new InvalidDataException("INACTIVE_SLOT_TARGET_REPARSE_FORBIDDEN");

            var identity = AtomicFile.ReadJson<InstalledReleaseIdentity>(
                _paths.InstalledReleaseIdentityPath);
            if (identity.Local is null ||
                !string.Equals(identity.Local.Version, layout.Build, StringComparison.Ordinal))
                throw new InvalidDataException("INACTIVE_SLOT_INSTALLED_BASELINE_MISMATCH");
            var currentInventory = Path.Combine(layout.WebRoot,
                "resources", "local-managed-files-v1.json");
            using (var doc = System.Text.Json.JsonDocument.Parse(
                       File.ReadAllBytes(currentInventory)))
            {
                var managedCount = doc.RootElement.GetProperty("files").GetArrayLength();
                LocalManagedTreeAttestation.RequireExact(layout.WebRoot, managedCount,
                    identity.Local.ManagedTreeSha256);
            }
            var persistent = Path.Combine(root, "LocalWeb", "persistent");
            step("BINDING");
            RoutineLocalBinding.RequireCurrent(layout.WebRoot, root, layout.Build);
            RoutineLocalBinding.RequireTarget(persistent, previous, root, layout.Build);
            // Opens lifecycle in read-only mode and rejects pending repair,
            // installed Web drift, recovery markers and staged mutations.
            var lifecycle = RoutineLocalLifecycle.Open(root,
                _paths.LocalSlotStateRoot, layout.Build);
            lifecycle.RequireIdle();
            step("EVIDENCE");

            var installedSha = Hash(_paths.InstalledReleaseIdentityPath);
            if (sourceReceipt.InstalledIdentitySha256 != installedSha)
                throw new InvalidDataException("INACTIVE_SLOT_STALE_PRIVATE_CANDIDATE");
            var candidateReceiptPath = Path.Combine(
                Path.GetDirectoryName(sourceReceipt.CandidateRoot)!,
                "candidate-receipt.json");
            var candidateSha = Hash(candidateReceiptPath);
            LocalManagedTreeAttestation.RequireExact(sourceReceipt.CandidateRoot,
                sourceReceipt.TargetManagedFiles, sourceReceipt.TargetManagedTreeSha256);

            var evidenceRoot = Path.Combine(_paths.InactiveLocalSlotPromotionRoot,
                stageOperationId);
            if (Directory.Exists(evidenceRoot) &&
                (File.GetAttributes(evidenceRoot) & FileAttributes.ReparsePoint) != 0)
                throw new InvalidDataException("INACTIVE_SLOT_JOURNAL_REPARSE_FORBIDDEN");
            var receiptPath = Path.Combine(evidenceRoot, "receipt.json");
            var marker = Path.Combine(evidenceRoot, "committed.sha256");
            var incomplete = target + ".__incoming." + stageOperationId;
            if (Directory.Exists(incomplete) || File.Exists(incomplete))
                throw new InvalidOperationException("INACTIVE_SLOT_RECOVERY_REQUIRED");
            if (Directory.Exists(target) || Directory.Exists(evidenceRoot))
            {
                if (!Directory.Exists(target) || !File.Exists(receiptPath) ||
                    !File.Exists(marker) ||
                    (File.GetAttributes(target) & FileAttributes.ReparsePoint) != 0)
                    throw new InvalidOperationException("INACTIVE_SLOT_RECOVERY_REQUIRED");
                var old = AtomicFile.ReadJson<InactiveLocalSlotReceipt>(receiptPath);
                if (old.Format != "sokna-inactive-local-slot-v1" ||
                    old.SchemaVersion != 1 || old.StageOperationId != stageOperationId ||
                    old.OperatorId != operatorId || old.IdempotencyKey != idempotencyKey ||
                    old.InstalledIdentitySha256 != installedSha ||
                    old.PrivateCandidateSha256 != candidateSha ||
                    !string.Equals(old.InactiveSlot, target, StringComparison.OrdinalIgnoreCase) ||
                    !string.Equals(old.PreviousActiveSlot, previous, StringComparison.OrdinalIgnoreCase) ||
                    old.ActivationAllowed || old.PromotionQualified ||
                    old.ActivationPerformed || old.DatabaseMutationPerformed ||
                    old.PublicMutationPerformed ||
                    !string.Equals(File.ReadAllText(marker).Trim(), Hash(receiptPath),
                        StringComparison.OrdinalIgnoreCase))
                    throw new InvalidOperationException("INACTIVE_SLOT_EVIDENCE_CONFLICT");
                VerifyTarget(target, persistent, layout, old, lifecycle, manager);
                return new(old, Created: false, Reconciled: true);
            }

            _journal.Append("update.local_inactive_slot.write_intent",
                _state.InstanceId, _state.InstallGeneration,
                new Dictionary<string, object?> {
                    ["stage_operation_id"] = stageOperationId,
                    ["target"] = target, ["previous"] = previous,
                    ["active_pointer_change"] = false,
                    ["db_mutation"] = false, ["public_mutation"] = false
                });
            step("COPY");
            ProductionInactiveLocalSlotWriter.CopyToNewSibling(
                sourceReceipt.CandidateRoot, incomplete, root, layout.WebRoot,
                layout.Build, sourceReceipt.TargetVersion,
                sourceReceipt.TargetManagedFiles, sourceReceipt.TargetManagedTreeSha256);
            // Read-only PHP/installed configuration status and migration catalog
            // checks are MANDATORY before committing a new inactive sibling.
            step("DATABASE_PLAN");
            RoutineLocalDatabase.Plan(layout.WebRoot, incomplete);
            step("TARGET_BOOT");
            RoutineLocalLifecycle.RequireTargetSetupBoot(root, incomplete);
            step("FINAL_ATTESTATION");
            LocalManagedTreeAttestation.RequireExact(incomplete,
                sourceReceipt.TargetManagedFiles, sourceReceipt.TargetManagedTreeSha256);
            lifecycle.RequireIdle();
            if (Hash(_paths.InstalledReleaseIdentityPath) != installedSha ||
                Hash(candidateReceiptPath) != candidateSha)
                throw new InvalidDataException("INACTIVE_SLOT_BASELINE_DRIFT");
            RequireUnchangedActive(manager, previous, layout.Build);
            // Atomic rename only into a previously ABSENT sibling path.
            Directory.Move(incomplete, target);
            var receipt = new InactiveLocalSlotReceipt(
                "sokna-inactive-local-slot-v1", 1,
                stageOperationId, operatorId, idempotencyKey,
                sourceReceipt.ReleaseSetId, sourceReceipt.TargetVersion,
                sourceReceipt.TargetManagedTreeSha256, sourceReceipt.TargetManagedFiles,
                candidateSha, installedSha, previous, target, DateTimeOffset.UtcNow,
                ActualHostHealthQualified: true,
                PromotionQualified: false, ActivationAllowed: false,
                ActivationPerformed: false, DatabaseMutationPerformed: false,
                PublicMutationPerformed: false);
            Directory.CreateDirectory(evidenceRoot);
            AtomicFile.WriteJson(receiptPath, receipt);
            File.WriteAllText(marker, Hash(receiptPath) + "\n");
            VerifyTarget(target, persistent, layout, receipt, lifecycle, manager);
            _journal.Append("update.local_inactive_slot.committed",
                _state.InstanceId, _state.InstallGeneration,
                new Dictionary<string, object?> {
                    ["stage_operation_id"] = stageOperationId,
                    ["slot"] = target, ["activation_performed"] = false,
                    ["public_mutation_performed"] = false
                });
            return new(receipt, Created: true, Reconciled: false);
        }
    }

    private static void VerifyTarget(string target, string persistent,
        LocalInstallLayout layout, InactiveLocalSlotReceipt receipt,
        RoutineLocalLifecycle lifecycle, LocalSlotManager manager)
    {
        ProductionLocalSlotMigrationBridge.RequireProductionSlotPath(
            target, layout.PackageRoot);
        LocalManagedTreeAttestation.RequireExact(target,
            receipt.TargetManagedFiles, receipt.TargetTreeSha256);
        RoutineLocalBinding.RequireTarget(persistent, target,
            layout.PackageRoot, receipt.TargetVersion);
        RoutineLocalDatabase.Plan(layout.WebRoot, target);
        RoutineLocalLifecycle.RequireTargetSetupBoot(layout.PackageRoot, target);
        lifecycle.RequireIdle();
        RequireUnchangedActive(manager, receipt.PreviousActiveSlot, layout.Build);
    }

    private static void RequireUnchangedActive(LocalSlotManager manager,
        string expectedSlot, string expectedBuild)
    {
        var current = manager.InspectHealthyCurrent();
        if (!current.WebIsReparsePoint ||
            !string.Equals(current.ActiveSlotTarget, expectedSlot,
                StringComparison.OrdinalIgnoreCase) ||
            current.Build != expectedBuild)
            throw new InvalidDataException("INACTIVE_SLOT_ACTIVE_POINTER_CHANGED");
    }

    private static string Hash(string path)
    {
        if (!File.Exists(path) || (File.GetAttributes(path) & FileAttributes.ReparsePoint) != 0)
            throw new InvalidDataException("INACTIVE_SLOT_EVIDENCE_MISSING");
        using var stream = File.OpenRead(path);
        return Convert.ToHexString(SHA256.HashData(stream)).ToLowerInvariant();
    }
}
