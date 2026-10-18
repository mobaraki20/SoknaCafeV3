using System.Security.Cryptography;
using System.Text.Json;
using System.Text.Json.Serialization;

namespace Sokna.Maintenance.Core;

public sealed record LocalReleaseStageRequest(
    [property: JsonPropertyName("preflight_operation_id")] string PreflightOperationId,
    [property: JsonPropertyName("operator_id")] string OperatorId);

public sealed record LocalReleaseStageOutcome(
    [property: JsonPropertyName("operation")] MaintenanceOperation Operation,
    [property: JsonPropertyName("preflight_operation_id")] string PreflightOperationId,
    [property: JsonPropertyName("created")] bool Created,
    [property: JsonPropertyName("reconciled")] bool Reconciled,
    [property: JsonPropertyName("receipt")] StagedReleaseReceipt? Receipt);

/// <summary>
/// F1-S2 signed-package Stage only. Does NOT activate a slot or touch DB/Public.
/// The shared gate protects the ordinary preflight/stage path; until every legacy
/// owner is serialised by the same coordinator, capabilities remain not-ready.
/// </summary>
public sealed class SupervisorLocalReleaseStageCoordinator
{
    private readonly MaintenancePaths _paths;
    private readonly MaintenanceOperationStore _operations;
    private readonly DurableJournal _journal;
    private readonly SupervisorState _state;

    public SupervisorLocalReleaseStageCoordinator(
        MaintenancePaths paths, MaintenanceOperationStore operations,
        DurableJournal journal, SupervisorState state)
    {
        _paths = paths;
        _operations = operations;
        _journal = journal;
        _state = state;
    }

    public LocalReleaseStageOutcome Stage(string preflightOperationId, string operatorId, string idempotencyKey)
    {
        if (string.IsNullOrWhiteSpace(preflightOperationId) ||
            preflightOperationId.Length != 32 ||
            !preflightOperationId.All(static c => char.IsAsciiHexDigit(c)))
            throw new InvalidDataException("preflight_operation_id is invalid.");
        if (string.IsNullOrWhiteSpace(operatorId) || operatorId.Length > 128 ||
            operatorId.Any(static c => !(char.IsLetterOrDigit(c) || c is '-' or '_' or '.' or ':')))
            throw new InvalidDataException("operator_id is invalid.");

        lock (LocalUpdateOperationGate.Sync)
        {
            // Detect broken durable evidence or conflicting operations BEFORE mutation.
            var prior = _operations.GetByIdempotencyKey(idempotencyKey);
            var active = _operations.ListActive();
            if (active.Any(op => prior is null || op.OperationId != prior.OperationId))
                throw new InvalidOperationException("UPDATER_OPERATION_BUSY");
            if (prior is not null && prior.State is "accepted" or "running")
                throw new InvalidOperationException("UPDATER_OPERATION_RECONCILIATION_REQUIRED");

            var preflight = _operations.Get(preflightOperationId)
                ?? throw new InvalidDataException("Preflight operation does not exist.");
            if (preflight.OperationType != "update.local-package-preflight" ||
                preflight.State != "verified" ||
                preflight.OperatorId != operatorId || preflight.ActivationPerformed ||
                preflight.DatabaseMutationPerformed)
                throw new InvalidDataException("Preflight is not verified for the current operator.");

            var expectedReceiptPath = Path.GetFullPath(Path.Combine(
                _paths.OperationsRoot, "preflight-" + preflightOperationId + ".json"));
            if (!string.Equals(preflight.PreflightReceiptPath, expectedReceiptPath, StringComparison.OrdinalIgnoreCase))
                throw new InvalidDataException("Preflight operation has no trusted durable receipt path.");
            if (!File.Exists(expectedReceiptPath))
                throw new InvalidDataException("Preflight durable receipt is missing.");
            var preflightReceipt = AtomicFile.ReadJson<LocalPackagePreflightReceipt>(expectedReceiptPath);
            if (preflightReceipt is null ||
                preflightReceipt.Format != "sokna-local-package-preflight-v1" ||
                preflightReceipt.SchemaVersion != 1 ||
                preflightReceipt.OperationId != preflightOperationId ||
                preflightReceipt.PackageRef != preflight.PackageRef ||
                preflightReceipt.Verification is null ||
                !preflightReceipt.Verification.SignatureVerified ||
                !preflightReceipt.Verification.ArtifactIntegrityVerified ||
                preflightReceipt.Verification.ExactBaselineVerified != true ||
                preflightReceipt.Verification.ReleaseType != "local_only" ||
                preflightReceipt.Verification.PanelActivationReady ||
                preflightReceipt.Verification.StagePerformed ||
                preflightReceipt.Verification.ActivationPerformed ||
                preflightReceipt.Verification.DatabaseMutationPerformed)
                throw new InvalidDataException("Preflight receipt is invalid or not a Local-only release.");

            var packagePath = Path.GetFullPath(Path.Combine(_paths.InboxRoot, preflight.PackageRef));
            if (!string.Equals(Path.GetDirectoryName(packagePath), Path.GetFullPath(_paths.InboxRoot),
                    StringComparison.OrdinalIgnoreCase) ||
                !File.Exists(packagePath) ||
                (File.GetAttributes(_paths.InboxRoot) & FileAttributes.ReparsePoint) != 0 ||
                (File.GetAttributes(packagePath) & FileAttributes.ReparsePoint) != 0)
                throw new InvalidDataException("Supervisor-owned package path is unsafe or missing.");
            if (!File.Exists(_paths.InstalledReleaseIdentityPath))
                throw new InvalidDataException("Authoritative installed identity is missing.");

            var identityBytes = File.ReadAllBytes(_paths.InstalledReleaseIdentityPath);
            var installedIdentitySha = Sha256(identityBytes);
            if (!string.Equals(installedIdentitySha, preflightReceipt.InstalledIdentitySha256, StringComparison.Ordinal))
                throw new InvalidDataException("Installed release identity changed since Preflight.");
            var packageSha = HashFile(packagePath);
            if (!string.Equals(packageSha, preflightReceipt.Verification.PackageSha256, StringComparison.Ordinal))
                throw new InvalidDataException("Package changed since Preflight.");
            InstalledReleaseIdentity installed;
            try
            {
                installed = JsonSerializer.Deserialize<InstalledReleaseIdentity>(identityBytes, AtomicFile.JsonOptions)
                    ?? throw new InvalidDataException("Installed release identity is empty.");
            }
            catch (JsonException ex)
            {
                throw new InvalidDataException("Installed release identity JSON is invalid.", ex);
            }

            // Verify everything again after the preflight snapshot; not merely the SHA.
            var verified = new ReleaseSetVerifierStager(_paths).VerifyOnly(packagePath, installed);
            if (verified.ReleaseType != "local_only" ||
                verified.Sequence != preflightReceipt.Verification.Sequence ||
                verified.ReleaseSetId != preflightReceipt.Verification.ReleaseSetId ||
                verified.ManifestSha256 != preflightReceipt.Verification.ManifestSha256 ||
                verified.PackageSha256 != preflightReceipt.Verification.PackageSha256)
                throw new InvalidDataException("Signed Release Set provenance changed since Preflight.");

            var requestHash = MaintenanceOperationStore.CanonicalRequestHash(
                "update.local-stage." + preflightOperationId, preflight.PackageRef, operatorId);
            var (operation, created) = _operations.GetOrCreate(idempotencyKey, requestHash,
                "update.local-stage", preflight.PackageRef, operatorId);

            if (operation.State == "staged")
            {
                if (string.IsNullOrWhiteSpace(operation.StageReceiptPath) ||
                    !File.Exists(operation.StageReceiptPath) ||
                    !string.Equals(operation.PreflightReceiptPath, expectedReceiptPath, StringComparison.OrdinalIgnoreCase))
                    throw new InvalidDataException("Staged operation lost its provenance or durable receipt.");
                var previous = AtomicFile.ReadJson<StagedReleaseReceipt>(operation.StageReceiptPath);
                ValidateStageReceipt(previous, verified);
                return new(operation, preflightOperationId, created, true, previous);
            }
            if (operation.State is "rejected_pre_mutation" or "failed_pre_mutation" or "recovery_required")
                return new(operation, preflightOperationId, created, !created, null);
            if (operation.State != "accepted")
                throw new InvalidOperationException("UPDATER_OPERATION_RECONCILIATION_REQUIRED");

            operation = _operations.Update(operation, "running",
                resultCode: "STAGE_RUNNING", incrementAttempt: true,
                preflightReceiptPath: expectedReceiptPath);
            Journal("update.local_stage.running", operation, preflightOperationId);
            try
            {
                // VerifyAndStage rechecks signature, artifacts, exact baseline and
                // monotonic sequence in its own stage lock before any stage write.
                var stage = new ReleaseSetVerifierStager(_paths).VerifyAndStage(packagePath, installed);
                if (HashFile(packagePath) != verified.PackageSha256 ||
                    Sha256(File.ReadAllBytes(_paths.InstalledReleaseIdentityPath)) != installedIdentitySha)
                    throw new InvalidDataException("Package or Local identity changed during Stage.");
                ValidateStageReceipt(stage, verified);

                var receiptPath = Path.Combine(stage.StageRoot, "stage-receipt.json");
                var persisted = AtomicFile.ReadJson<StagedReleaseReceipt>(receiptPath);
                ValidateStageReceipt(persisted, verified);
                operation = _operations.Update(operation, "staged", "STAGED",
                    "Signed, preflight-bound Local-only release staged; no activation or DB mutation.",
                    stageReceiptPath: receiptPath);
                Journal("update.local_stage.staged", operation, preflightOperationId);
                return new(operation, preflightOperationId, created, !created, persisted);
            }
            catch (Exception ex) when (ex is InvalidDataException or IOException or UnauthorizedAccessException
                or CryptographicException or JsonException or InvalidOperationException)
            {
                // Stage may have persisted a receipt or anti-rollback state before
                // an error. Never classify uncertain disk state as simple rejection
                // or silently retry it after an interrupted run.
                operation = _operations.Update(operation, "recovery_required",
                    "STAGE_OUTCOME_UNPROVEN", ex.GetType().Name + ": " +
                    (ex.Message.Length > 450 ? ex.Message[..450] : ex.Message));
                Journal("update.local_stage.recovery_required", operation, preflightOperationId);
                return new(operation, preflightOperationId, created, !created, null);
            }
        }
    }

    private static void ValidateStageReceipt(StagedReleaseReceipt? staged, ReleaseSetPreflightResult verified)
    {
        if (staged is null || staged.Format != "sokna-maintenance-staged-release-v1" ||
            staged.SchemaVersion != 1 ||
            staged.ReleaseSetId != verified.ReleaseSetId ||
            staged.Sequence != verified.Sequence ||
            staged.ManifestSha256 != verified.ManifestSha256 ||
            staged.PackageSha256 != verified.PackageSha256 ||
            !staged.SignatureVerified || !staged.ArtifactIntegrityVerified ||
            !staged.PreflightIdentityMatch || staged.ActivationPerformed ||
            staged.DatabaseMutationPerformed)
            throw new InvalidDataException("Stage receipt does not prove the exact preflight package.");
    }

    private static string Sha256(byte[] bytes)
        => Convert.ToHexString(SHA256.HashData(bytes)).ToLowerInvariant();

    private static string HashFile(string path)
    {
        using var stream = File.OpenRead(path);
        return Convert.ToHexString(SHA256.HashData(stream)).ToLowerInvariant();
    }

    private void Journal(string kind, MaintenanceOperation operation, string preflightOperationId)
        => _journal.Append(kind, _state.InstanceId, _state.InstallGeneration,
            new Dictionary<string, object?>
            {
                ["operation_id"] = operation.OperationId,
                ["preflight_operation_id"] = preflightOperationId,
                ["operator_id"] = operation.OperatorId,
                ["package_ref"] = operation.PackageRef,
                ["state"] = operation.State,
                ["activation_performed"] = false,
                ["database_mutation_performed"] = false
            });
}
