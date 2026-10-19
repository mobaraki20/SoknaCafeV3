using System.Security.Cryptography;
using System.Text.Json.Serialization;

namespace Sokna.Maintenance.Core;

/// <summary>
/// Read-only, same-installation bridge between the signed Local Stage and
/// the already existing inactive physical sibling. NO cutover or execution.
/// Not an alternative updater and not an activation permit.
/// </summary>
public sealed record LocalCodeOnlyPreExecutionEvidence(
    [property: JsonPropertyName("stage_operation_id")] string StageOperationId,
    [property: JsonPropertyName("release_set_id")] string ReleaseSetId,
    [property: JsonPropertyName("previous_slot")] string PreviousSlot,
    [property: JsonPropertyName("target_slot")] string TargetSlot,
    [property: JsonPropertyName("target_version")] string TargetVersion,
    [property: JsonPropertyName("target_managed_tree_sha256")] string TargetTreeSha256,
    [property: JsonPropertyName("signed_stage_verified")] bool SignedStageVerified,
    [property: JsonPropertyName("physical_sibling_verified")] bool PhysicalSiblingVerified,
    [property: JsonPropertyName("unchanged_migrations_verified")] bool UnchangedMigrationsVerified,
    [property: JsonPropertyName("execution_authorized")] bool ExecutionAuthorized,
    [property: JsonPropertyName("panel_update_ready")] bool PanelUpdateReady);

public sealed class SupervisorLocalCodeOnlyPreExecution
{
    private readonly MaintenancePaths _paths;
    private readonly MaintenanceOperationStore _operations;
    private readonly SupervisorLocalStageRecoveryCoordinator _stages;

    public SupervisorLocalCodeOnlyPreExecution(MaintenancePaths paths,
        MaintenanceOperationStore operations,
        SupervisorLocalStageRecoveryCoordinator stages)
    {
        _paths = paths;
        _operations = operations;
        _stages = stages;
    }

    public LocalCodeOnlyPreExecutionEvidence Inspect(string stageOperationId)
    {
        if (stageOperationId is not {Length: 32} ||
            !stageOperationId.All(char.IsAsciiHexDigit))
            throw new InvalidDataException("CODE_ONLY_STAGE_ID_INVALID");
        var stage = _operations.Get(stageOperationId)
            ?? throw new InvalidDataException("CODE_ONLY_STAGE_NOT_FOUND");
        if (stage.OperationType != "update.local-stage" || stage.State != "staged" ||
            stage.ActivationPerformed || stage.DatabaseMutationPerformed)
            throw new InvalidDataException("CODE_ONLY_STAGE_NOT_STAGED");

        // Re-verify full signed Preflight, signed manifest, package bytes and
        // installed baseline, rather than trusting a UI claim or stage flag.
        var signed = _stages.InspectActivationReadiness(stageOperationId);
        if (!signed.StageEvidenceVerified || signed.ReleaseType != "local_only")
            throw new InvalidDataException("CODE_ONLY_SIGNED_STAGE_NOT_QUALIFIED");

        var root = Path.GetFullPath(Path.Combine(
            _paths.InactiveLocalSlotPromotionRoot, stageOperationId));
        var receiptPath = Path.Combine(root, "receipt.json");
        var marker = Path.Combine(root, "committed.sha256");
        if (!Directory.Exists(root) || Reparse(root) ||
            !File.Exists(receiptPath) || Reparse(receiptPath) ||
            !File.Exists(marker) || Reparse(marker) ||
            new FileInfo(receiptPath).Length is <= 0 or > 100_000 ||
            new FileInfo(marker).Length is <= 0 or > 256)
            throw new InvalidDataException("CODE_ONLY_INACTIVE_RECEIPT_UNAVAILABLE");
        var receiptHash = Sha(receiptPath);
        if (!string.Equals(File.ReadAllText(marker).Trim(), receiptHash,
            StringComparison.OrdinalIgnoreCase))
            throw new InvalidDataException("CODE_ONLY_INACTIVE_RECEIPT_TAMPERED");
        var receipt = AtomicFile.ReadJson<InactiveLocalSlotReceipt>(receiptPath);
        var installedHash = Sha(_paths.InstalledReleaseIdentityPath);
        RequireExactReceipt(receipt, stageOperationId, stage.OperatorId,
            signed.ReleaseSetId, installedHash);

        // Physical readback must be from the authoritative Apache layout.
        // No installation mutations or Web/Windows service stop/start.
        var manager = new LocalSlotManager(_paths);
        var layout = manager.InspectHealthyCurrent();
        var oldSlot = Path.GetFullPath(layout.ActiveSlotTarget
            ?? throw new InvalidDataException("CODE_ONLY_ACTIVE_SLOT_UNAVAILABLE"));
        var target = Path.GetFullPath(receipt.InactiveSlot);
        var packageRoot = Path.GetFullPath(layout.PackageRoot);
        ProductionLocalSlotMigrationBridge.RequireProductionSlotPath(oldSlot, packageRoot);
        ProductionLocalSlotMigrationBridge.RequireProductionSlotPath(target, packageRoot);
        if (!layout.WebIsReparsePoint ||
            !string.Equals(oldSlot, Path.GetFullPath(receipt.PreviousActiveSlot),
                StringComparison.OrdinalIgnoreCase) ||
            string.Equals(oldSlot, target, StringComparison.OrdinalIgnoreCase) ||
            !Directory.Exists(target) || Reparse(target))
            throw new InvalidDataException("CODE_ONLY_PHYSICAL_SLOT_MISMATCH");

        var identity = AtomicFile.ReadJson<InstalledReleaseIdentity>(
            _paths.InstalledReleaseIdentityPath);
        if (identity.Local is null || identity.Local.Version != layout.Build ||
            string.Equals(identity.Local.Version, receipt.TargetVersion, StringComparison.Ordinal))
            throw new InvalidDataException("CODE_ONLY_INSTALLED_VERSION_MISMATCH");
        var previousInventory = Path.Combine(layout.WebRoot,
            "resources", "local-managed-files-v1.json");
        using (var inventory = System.Text.Json.JsonDocument.Parse(
            File.ReadAllBytes(previousInventory)))
            LocalManagedTreeAttestation.RequireExact(layout.WebRoot,
                inventory.RootElement.GetProperty("files").GetArrayLength(),
                identity.Local.ManagedTreeSha256);

        LocalManagedTreeAttestation.RequireExact(target,
            receipt.TargetManagedFiles, receipt.TargetTreeSha256);
        var persistent = Path.Combine(packageRoot, "LocalWeb", "persistent");
        RoutineLocalBinding.RequireCurrent(layout.WebRoot, packageRoot, layout.Build);
        RoutineLocalBinding.RequireTarget(persistent, target, packageRoot, receipt.TargetVersion);
        RoutineLocalLifecycle.RequireUnchangedMigrationCatalog(layout.WebRoot, target);
        RoutineLocalLifecycle.RequireTargetSetupBoot(packageRoot, target);
        if (Sha(_paths.InstalledReleaseIdentityPath) != installedHash ||
            Sha(receiptPath) != receiptHash)
            throw new InvalidDataException("CODE_ONLY_EVIDENCE_CHANGED_DURING_INSPECTION");

        return new LocalCodeOnlyPreExecutionEvidence(
            stageOperationId, signed.ReleaseSetId, oldSlot, target,
            receipt.TargetVersion, receipt.TargetTreeSha256,
            SignedStageVerified: true, PhysicalSiblingVerified: true,
            UnchangedMigrationsVerified: true, ExecutionAuthorized: false,
            PanelUpdateReady: false);
    }

    public static void RequireExactReceipt(InactiveLocalSlotReceipt? r,
        string stageId, string operatorId, string releaseId, string installedHash)
    {
        if (r is null || r.Format != "sokna-inactive-local-slot-v1" ||
            r.SchemaVersion != 1 || r.StageOperationId != stageId ||
            r.OperatorId != operatorId || r.ReleaseSetId != releaseId ||
            !FixedHash(r.InstalledIdentitySha256, installedHash) ||
            r.ActivationAllowed || r.PromotionQualified || r.ActivationPerformed ||
            r.DatabaseMutationPerformed || r.PublicMutationPerformed ||
            !ValidSha(r.TargetTreeSha256) || r.TargetManagedFiles is < 1 or > 20_000 ||
            string.IsNullOrWhiteSpace(r.TargetVersion))
            throw new InvalidDataException("CODE_ONLY_INACTIVE_RECEIPT_MISMATCH");
    }

    private static bool ValidSha(string? value)
        => value is {Length: 64} && value.All(char.IsAsciiHexDigit);
    private static bool FixedHash(string? a, string? b)
    {
        if (!ValidSha(a) || !ValidSha(b)) return false;
        return CryptographicOperations.FixedTimeEquals(
            Convert.FromHexString(a!), Convert.FromHexString(b!));
    }
    private static bool Reparse(string path) =>
        (File.GetAttributes(path) & FileAttributes.ReparsePoint) != 0;
    private static string Sha(string path) => Convert.ToHexString(
        SHA256.HashData(File.ReadAllBytes(path))).ToLowerInvariant();
}
