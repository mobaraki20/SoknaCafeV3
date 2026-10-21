using System.Security.Cryptography;
using System.Text.Json;
using System.Text.Json.Serialization;

namespace Sokna.Maintenance.Core;

public sealed record LocalStageRecoveryRequest(
    [property: JsonPropertyName("stage_operation_id")] string StageOperationId,
    [property: JsonPropertyName("operator_id")] string OperatorId);

public sealed record LocalStageRecoveryOutcome(
    [property: JsonPropertyName("operation")] MaintenanceOperation Operation,
    [property: JsonPropertyName("recovered")] bool Recovered,
    [property: JsonPropertyName("reconciled")] bool Reconciled,
    [property: JsonPropertyName("stage_receipt")] StagedReleaseReceipt? StageReceipt);

/// <summary>
/// Recovery-console only: recover an interrupted, fully PROVEN stage receipt.
/// No Stage execution, sequence write, pointer switch, DB or Public operation.
/// An incomplete stage remains quarantined; a second request never restages.
/// </summary>
public sealed class SupervisorLocalStageRecoveryCoordinator
{
    private readonly MaintenancePaths _paths;
    private readonly MaintenanceOperationStore _operations;
    private readonly DurableJournal? _journal;
    private readonly SupervisorState? _state;

    public SupervisorLocalStageRecoveryCoordinator(
        MaintenancePaths paths, MaintenanceOperationStore operations,
        DurableJournal journal, SupervisorState state)
    {
        _paths = paths;
        _operations = operations;
        _journal = journal;
        _state = state;
    }

    // F1-S7D4B: reuse the exact recovery-console signed Stage provenance
    // inspection without furnishing an operation executor or recovery writer.
    // The private instance has no journal/state so its Reconcile fails closed.
    private SupervisorLocalStageRecoveryCoordinator(
        MaintenancePaths paths, MaintenanceOperationStore operations)
    {
        _paths = paths;
        _operations = operations;
        _journal = null;
        _state = null;
    }

    public static LocalActivationReadiness InspectSignedStageReadOnly(
        MaintenancePaths paths, string stageOperationId)
    {
        ArgumentNullException.ThrowIfNull(paths);
        return new SupervisorLocalStageRecoveryCoordinator(
            paths, new MaintenanceOperationStore(paths))
            .InspectActivationReadiness(stageOperationId);
    }

    public LocalStageRecoveryOutcome Reconcile(string operationId, string operatorId)
    {
        if (_journal is null || _state is null)
            throw new InvalidOperationException("UPDATER_READ_ONLY_INSPECTOR_CANNOT_RECOVER");
        if (string.IsNullOrWhiteSpace(operationId) || operationId.Length != 32 ||
            !operationId.All(char.IsAsciiHexDigit))
            throw new InvalidDataException("stage_operation_id is invalid.");
        if (string.IsNullOrWhiteSpace(operatorId) || operatorId.Length > 128 ||
            operatorId.Any(static c => !(char.IsLetterOrDigit(c) || c is '-' or '_' or '.' or ':')))
            throw new InvalidDataException("operator_id is invalid.");

        lock (LocalUpdateOperationGate.Sync)
        {
            var operation = _operations.Get(operationId)
                ?? throw new InvalidDataException("Stage operation was not found.");
            if (operation.OperationType != "update.local-stage" ||
                operation.ActivationPerformed || operation.DatabaseMutationPerformed ||
                operation.State is not ("recovery_required" or "staged"))
                throw new InvalidDataException("Only a quarantined canonical Local Stage can be reconciled.");
            // Any other active or recovery-required operation means the operating
            // environment cannot be assumed exclusive.
            if (_operations.ListBlocking().Any(op => op.OperationId != operationId))
                throw new InvalidOperationException("UPDATER_OPERATION_BUSY");

            // The inspector throws without modifying the existing evidence.
            // This call cannot be bypassed, even when a prior retry had succeeded.
            var receipt = VerifyCompleteEvidence(operation);
            if (operation.State == "staged")
                return new(operation, false, true, receipt);

            operation = _operations.Update(operation, "staged",
                "STAGE_RECOVERED_FROM_VERIFIED_EVIDENCE",
                "Recovery Console independently verified signed package, baseline, all staged artifact hashes, receipt and anti-rollback high-water mark.",
                stageReceiptPath: Path.Combine(receipt.StageRoot, "stage-receipt.json"));
            _journal.Append("update.local_stage.recovery_evidence_committed",
                _state.InstanceId, _state.InstallGeneration,
                new Dictionary<string, object?>
                {
                    ["operation_id"] = operation.OperationId,
                    ["recovery_operator_id"] = operatorId,
                    ["release_set_id"] = receipt.ReleaseSetId,
                    ["sequence"] = receipt.Sequence,
                    ["activation_performed"] = false,
                    ["database_mutation_performed"] = false,
                    ["automatic_restage"] = false
                });
            return new(operation, true, false, receipt);
        }
    }

    /// <summary>
    /// Inspect an already-staged package using the same full provenance and
    /// staged artifact re-hash required for manual recovery. NO state changes.
    /// This does not grant approval to activate a release.
    /// </summary>
    public LocalActivationReadiness InspectActivationReadiness(string operationId, string? ownActivationOperationId = null)
    {
        if (string.IsNullOrWhiteSpace(operationId) || operationId.Length != 32 ||
            !operationId.All(char.IsAsciiHexDigit))
            throw new InvalidDataException("Stage operation ID is invalid.");
        lock (LocalUpdateOperationGate.Sync)
        {
            var operation = _operations.Get(operationId)
                ?? throw new InvalidDataException("Stage operation not found.");
            if (operation.OperationType != "update.local-stage" ||
                operation.State != "staged" ||
                operation.ActivationPerformed || operation.DatabaseMutationPerformed)
                throw new InvalidDataException("Only a completed canonical Local Stage may be inspected.");
            LocalUpdateOperationGate.RequireStageInspection(
                _operations, operation, ownActivationOperationId);

            var receipt = VerifyCompleteEvidence(operation);
            var stagedManifestPath = Path.Combine(receipt.StageRoot, "release-set.json");
            if (!File.Exists(stagedManifestPath) ||
                (File.GetAttributes(stagedManifestPath) & FileAttributes.ReparsePoint) != 0)
                throw new InvalidDataException("Staged manifest evidence is unsafe.");
            var manifest = AtomicFile.ReadJson<ReleaseSetManifest>(stagedManifestPath)
                ?? throw new InvalidDataException("Staged manifest is unreadable.");
            return LocalActivationReadinessPolicy.Assess(manifest, receipt, fullyVerifiedStage: true);
        }
    }

    private StagedReleaseReceipt VerifyCompleteEvidence(MaintenanceOperation operation)
    {
        var preflightReceiptPath = operation.PreflightReceiptPath;
        if (string.IsNullOrWhiteSpace(preflightReceiptPath))
            throw new InvalidDataException("Stage has no durable Preflight binding.");
        var preflightId = Path.GetFileNameWithoutExtension(preflightReceiptPath);
        if (!preflightId.StartsWith("preflight-", StringComparison.Ordinal) ||
            preflightId.Length != "preflight-".Length + 32)
            throw new InvalidDataException("Preflight receipt identifier is invalid.");
        preflightId = preflightId["preflight-".Length..];
        if (!preflightId.All(char.IsAsciiHexDigit))
            throw new InvalidDataException("Preflight receipt identifier contains invalid characters.");
        var expectedPreflight = Path.GetFullPath(Path.Combine(_paths.OperationsRoot, "preflight-" + preflightId + ".json"));
        if (!string.Equals(Path.GetFullPath(preflightReceiptPath), expectedPreflight, StringComparison.OrdinalIgnoreCase))
            throw new InvalidDataException("Preflight receipt path is not Supervisor-owned.");
        var preflightOp = _operations.Get(preflightId)
            ?? throw new InvalidDataException("Preflight operation record is missing.");
        if (preflightOp.State != "verified" || preflightOp.OperationType != "update.local-package-preflight" ||
            preflightOp.OperatorId != operation.OperatorId ||
            preflightOp.PackageRef != operation.PackageRef ||
            preflightOp.PreflightReceiptPath != expectedPreflight)
            throw new InvalidDataException("Preflight record does not bind this Stage.");

        var expectedRequestHash = MaintenanceOperationStore.CanonicalRequestHash(
            "update.local-stage." + preflightId, operation.PackageRef, operation.OperatorId);
        if (!string.Equals(expectedRequestHash, operation.CanonicalRequestSha256, StringComparison.Ordinal))
            throw new InvalidDataException("Stage canonical request does not bind this Preflight.");

        var proof = AtomicFile.ReadJson<LocalPackagePreflightReceipt>(expectedPreflight);
        if (proof is null || proof.Format != "sokna-local-package-preflight-v1" ||
            proof.SchemaVersion != 1 || proof.OperationId != preflightId ||
            proof.PackageRef != operation.PackageRef || proof.Verification is null ||
            proof.Verification.ReleaseType != "local_only" ||
            proof.Verification.ExactBaselineVerified != true ||
            !OperatorUploadedLocalPackage.HasTrustedPackageEvidence(
                proof.Verification.SignatureVerified, proof.Verification.OperatorAuthorized) ||
            !proof.Verification.ArtifactIntegrityVerified ||
            proof.Verification.StagePerformed || proof.Verification.ActivationPerformed ||
            proof.Verification.DatabaseMutationPerformed)
            throw new InvalidDataException("Preflight receipt cannot prove Stage provenance.");

        var inbox = Path.GetFullPath(_paths.InboxRoot);
        var package = Path.GetFullPath(Path.Combine(inbox, operation.PackageRef));
        if (!string.Equals(Path.GetDirectoryName(package), inbox, StringComparison.OrdinalIgnoreCase) ||
            !File.Exists(package) || !Directory.Exists(inbox) ||
            IsReparse(inbox) || IsReparse(package))
            throw new InvalidDataException("Source signed package is missing or unsafe.");
        if (!File.Exists(_paths.InstalledReleaseIdentityPath) ||
            IsReparse(_paths.InstalledReleaseIdentityPath))
            throw new InvalidDataException("Installed identity is missing or unsafe.");
        var installedBytes = File.ReadAllBytes(_paths.InstalledReleaseIdentityPath);
        if (Sha256(installedBytes) != proof.InstalledIdentitySha256)
            throw new InvalidDataException("Installed release identity has changed.");
        var installed = JsonSerializer.Deserialize<InstalledReleaseIdentity>(installedBytes, AtomicFile.JsonOptions)
            ?? throw new InvalidDataException("Installed identity JSON is invalid.");
        var verified = new ReleaseSetVerifierStager(_paths).VerifyOnly(package, installed);
        if (verified.ReleaseType != "local_only" ||
            verified.ReleaseSetId != proof.Verification.ReleaseSetId ||
            verified.Sequence != proof.Verification.Sequence ||
            verified.PackageSha256 != proof.Verification.PackageSha256 ||
            verified.ManifestSha256 != proof.Verification.ManifestSha256)
            throw new InvalidDataException("Signed package no longer matches Preflight.");
        if (Sha256File(package) != verified.PackageSha256 ||
            Sha256File(_paths.InstalledReleaseIdentityPath) != proof.InstalledIdentitySha256)
            throw new InvalidDataException("Package/identity changed during recovery inspection.");

        // The sequence must have been DURABLY committed by Stage, not inferred
        // from the ZIP or stage receipt alone.
        var seq = AtomicFile.ReadJson<ReleaseSequenceState>(_paths.ReleaseSequenceStatePath);
        if (seq.Format != "sokna-maintenance-release-sequence-v1" ||
            seq.SchemaVersion != 1 || seq.HighestSequence != verified.Sequence ||
            seq.ReleaseSetId != verified.ReleaseSetId ||
            seq.ManifestSha256 != verified.ManifestSha256)
            throw new InvalidDataException("Anti-rollback high-water mark does not prove a completed Stage.");

        // Never follow a path supplied by a receipt. Derive the expected release
        // root from the exact signed release identity.
        using var archive = System.IO.Compression.ZipFile.OpenRead(package);
        var manifestEntry = archive.GetEntry("release-set.json")
            ?? throw new InvalidDataException("Package manifest missing.");
        ReleaseSetManifest manifest;
        using (var source = manifestEntry.Open())
        {
            manifest = JsonSerializer.Deserialize<ReleaseSetManifest>(source, AtomicFile.JsonOptions)
                ?? throw new InvalidDataException("Release Set manifest is invalid.");
        }
        if (manifest.Artifacts is null || manifest.Artifacts.Count is < 1 or > 512 ||
            manifest.ReleaseSetId != verified.ReleaseSetId ||
            manifest.Sequence != verified.Sequence)
            throw new InvalidDataException("Stage manifest does not match signed verification.");

        var safeId = SanitizeId(verified.ReleaseSetId);
        var expectedRoot = Path.GetFullPath(Path.Combine(_paths.StagingRoot, $"{verified.Sequence:D20}-{safeId}"));
        if (!Directory.Exists(expectedRoot) || IsReparse(expectedRoot) ||
            !string.Equals(Path.GetDirectoryName(expectedRoot), Path.GetFullPath(_paths.StagingRoot),
                StringComparison.OrdinalIgnoreCase))
            throw new InvalidDataException("Stage root is missing or is not trusted.");
        var receiptPath = Path.Combine(expectedRoot, "stage-receipt.json");
        if (!File.Exists(receiptPath) || IsReparse(receiptPath))
            throw new InvalidDataException("Durable Stage receipt is missing or unsafe.");
        if (!string.IsNullOrWhiteSpace(operation.StageReceiptPath) &&
            !string.Equals(Path.GetFullPath(operation.StageReceiptPath), receiptPath, StringComparison.OrdinalIgnoreCase))
            throw new InvalidDataException("Operation Stage receipt path disagrees with expected root.");
        var staged = AtomicFile.ReadJson<StagedReleaseReceipt>(receiptPath);
        if (staged is null || staged.Format != "sokna-maintenance-staged-release-v1" ||
            staged.SchemaVersion != 1 || staged.ReleaseSetId != verified.ReleaseSetId ||
            staged.Sequence != verified.Sequence || staged.PackageSha256 != verified.PackageSha256 ||
            staged.ManifestSha256 != verified.ManifestSha256 ||
            !string.Equals(Path.GetFullPath(staged.StageRoot), expectedRoot, StringComparison.OrdinalIgnoreCase) ||
            !OperatorUploadedLocalPackage.HasTrustedPackageEvidence(
                staged.SignatureVerified, staged.OperatorAuthorized) ||
            staged.OperatorAuthorized != proof.Verification.OperatorAuthorized ||
            !staged.ArtifactIntegrityVerified ||
            !staged.PreflightIdentityMatch || staged.ActivationPerformed ||
            staged.DatabaseMutationPerformed)
            throw new InvalidDataException("Stage receipt is inconsistent or indicates a mutation.");

        var manifestFile = Path.Combine(expectedRoot, "release-set.json");
        if (!File.Exists(manifestFile) || IsReparse(manifestFile))
            throw new InvalidDataException("Staged manifest is missing or unsafe.");
        if (!File.ReadAllBytes(manifestFile).AsSpan().SequenceEqual(ReadArchiveEntry(manifestEntry)))
            throw new InvalidDataException("Staged manifest does not match signed package bytes.");

        // Re-hash every staged artifact, and reject unexpected files. Even a
        // correct stage-receipt.json with missing/corrupt staged bytes fails.
        var expectedFiles = new HashSet<string>(StringComparer.OrdinalIgnoreCase)
        {
            Path.GetFullPath(manifestFile), Path.GetFullPath(receiptPath)
        };
        foreach (var artifact in manifest.Artifacts.Values)
        {
            if (artifact is null || artifact.SizeBytes < 0 ||
                artifact.SizeBytes > 16L * 1024 * 1024 * 1024 ||
                string.IsNullOrWhiteSpace(artifact.Path))
                throw new InvalidDataException("Staged artifact metadata is invalid.");
            var normalized = artifact.Path.Replace('\\', '/');
            var segments = normalized.Split('/', StringSplitOptions.None);
            if (normalized.StartsWith('/') || normalized.Contains(':') ||
                segments.Any(x => x is "" or "." or ".."))
                throw new InvalidDataException("Staged artifact path is unsafe.");
            var file = Path.GetFullPath(Path.Combine(expectedRoot,
                normalized.Replace('/', Path.DirectorySeparatorChar)));
            if (!file.StartsWith(expectedRoot + Path.DirectorySeparatorChar, StringComparison.OrdinalIgnoreCase) ||
                !expectedFiles.Add(file) || !File.Exists(file))
                throw new InvalidDataException("Staged artifact path is missing, duplicate, or escapes Stage.");
            if (new FileInfo(file).Length != artifact.SizeBytes)
                throw new InvalidDataException("Staged artifact length mismatch.");
            if (Sha256File(file) != artifact.Sha256.ToLowerInvariant())
                throw new InvalidDataException("Staged artifact hash mismatch.");
        }
        var actualFiles = Directory.EnumerateFiles(expectedRoot, "*", SearchOption.AllDirectories)
            .Select(Path.GetFullPath).ToArray();
        if (actualFiles.Length != expectedFiles.Count ||
            actualFiles.Any(f => !expectedFiles.Contains(f) || IsReparse(f)))
            throw new InvalidDataException("Stage contains unexpected or unsafe files.");
        var actualDirs = Directory.EnumerateDirectories(expectedRoot, "*", SearchOption.AllDirectories);
        if (actualDirs.Any(IsReparse))
            throw new InvalidDataException("Stage contains unsafe reparse-point directories.");

        return staged;
    }

    private static byte[] ReadArchiveEntry(System.IO.Compression.ZipArchiveEntry entry)
    {
        if (entry.Length > 2 * 1024 * 1024) throw new InvalidDataException("Manifest exceeds the permitted size.");
        using var stream = entry.Open();
        using var buffer = new MemoryStream();
        stream.CopyTo(buffer);
        if (buffer.Length > 2 * 1024 * 1024) throw new InvalidDataException("Manifest expanded beyond permitted size.");
        return buffer.ToArray();
    }

    private static bool IsReparse(string path) => (File.GetAttributes(path) & FileAttributes.ReparsePoint) != 0;
    private static string Sha256(byte[] bytes) => Convert.ToHexString(SHA256.HashData(bytes)).ToLowerInvariant();
    private static string Sha256File(string path)
    {
        using var file = File.OpenRead(path);
        return Convert.ToHexString(SHA256.HashData(file)).ToLowerInvariant();
    }
    private static string SanitizeId(string value)
    {
        var chars = value.Select(static ch => char.IsLetterOrDigit(ch) || ch is '-' or '_' or '.' ? ch : '_').ToArray();
        var result = new string(chars).Trim('.', ' ');
        return result.Length == 0 ? "release" : result[..Math.Min(result.Length, 96)];
    }
}
