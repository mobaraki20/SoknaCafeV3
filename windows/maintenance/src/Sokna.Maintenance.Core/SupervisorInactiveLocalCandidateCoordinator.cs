using System.IO.Compression;
using System.Security.Cryptography;
using System.Text.Json;
using System.Text.Json.Serialization;

namespace Sokna.Maintenance.Core;

public sealed record InactiveLocalCandidateRequest(
    [property: JsonPropertyName("stage_operation_id")] string StageOperationId,
    [property: JsonPropertyName("operator_id")] string OperatorId);

public sealed record InactiveLocalCandidateReceipt(
    [property: JsonPropertyName("format")] string Format,
    [property: JsonPropertyName("schema_version")] int SchemaVersion,
    [property: JsonPropertyName("stage_operation_id")] string StageOperationId,
    [property: JsonPropertyName("release_set_id")] string ReleaseSetId,
    [property: JsonPropertyName("sequence")] long Sequence,
    [property: JsonPropertyName("operator_id")] string OperatorId,
    [property: JsonPropertyName("idempotency_key")] string IdempotencyKey,
    [property: JsonPropertyName("artifact_sha256")] string ArtifactSha256,
    [property: JsonPropertyName("stage_receipt_sha256")] string StageReceiptSha256,
    [property: JsonPropertyName("installed_identity_sha256")] string InstalledIdentitySha256,
    [property: JsonPropertyName("target_version")] string TargetVersion,
    [property: JsonPropertyName("target_managed_tree_sha256")] string TargetManagedTreeSha256,
    [property: JsonPropertyName("target_managed_files")] int TargetManagedFiles,
    [property: JsonPropertyName("candidate_root")] string CandidateRoot,
    [property: JsonPropertyName("prepared_at_utc")] DateTimeOffset PreparedAtUtc,
    [property: JsonPropertyName("active_slot_unchanged")] bool ActiveSlotUnchanged,
    [property: JsonPropertyName("promotion_qualified")] bool PromotionQualified,
    [property: JsonPropertyName("activation_performed")] bool ActivationPerformed,
    [property: JsonPropertyName("database_mutation_performed")] bool DatabaseMutationPerformed,
    [property: JsonPropertyName("public_mutation_performed")] bool PublicMutationPerformed);

public sealed record InactiveLocalCandidateOutcome(
    [property: JsonPropertyName("receipt")] InactiveLocalCandidateReceipt Receipt,
    [property: JsonPropertyName("created")] bool Created,
    [property: JsonPropertyName("reconciled")] bool Reconciled);

/// <summary>
/// F1-S7C1: prepare a cryptographically-bound candidate in Maintenance-owned
/// PRIVATE storage. Not an Apache production slot. Promotion, machine-local
/// overlay, target setup boot, and DB migration are separately blocked.
/// </summary>
public sealed class SupervisorInactiveLocalCandidateCoordinator
{
    private const long MaxInnerArchive = 1024L * 1024 * 1024;
    private const long MaxExpanded = 2L * 1024 * 1024 * 1024;
    private const int MaxFiles = 20_000;
    private readonly MaintenancePaths _paths;
    private readonly MaintenanceOperationStore _operations;
    private readonly SupervisorLocalStageRecoveryCoordinator _inspector;
    private readonly DurableJournal _journal;
    private readonly SupervisorState _state;

    public SupervisorInactiveLocalCandidateCoordinator(MaintenancePaths paths,
        MaintenanceOperationStore operations, SupervisorLocalStageRecoveryCoordinator inspector,
        DurableJournal journal, SupervisorState state)
    {
        _paths = paths; _operations = operations; _inspector = inspector;
        _journal = journal; _state = state;
    }

    public InactiveLocalCandidateOutcome Prepare(string operationId, string operatorId, string idem)
    {
        if (operationId is not { Length: 32 } || !operationId.All(char.IsAsciiHexDigit) ||
            !MaintenanceOperationStore.IsValidIdempotencyKey(idem) ||
            string.IsNullOrWhiteSpace(operatorId) || operatorId.Length > 128 ||
            operatorId.Any(static c => !(char.IsLetterOrDigit(c) || c is ':' or '.' or '-' or '_')))
            throw new InvalidDataException("INACTIVE_CANDIDATE_REQUEST_INVALID");
        lock (LocalUpdateOperationGate.Sync)
        {
            using var machineLease = MachineMutationLease.Acquire(_paths, "supervisor-inactive-candidate");
            if (_operations.ListBlocking().Count != 0)
                throw new InvalidOperationException("UPDATER_OPERATION_BUSY");
            var operation = _operations.Get(operationId)
                ?? throw new InvalidDataException("INACTIVE_CANDIDATE_STAGE_UNVERIFIED");
            if (operation.OperationType != "update.local-stage" ||
                operation.State != "staged" || operation.OperatorId != operatorId ||
                operation.StageReceiptPath is null ||
                operation.ActivationPerformed || operation.DatabaseMutationPerformed)
                throw new InvalidDataException("INACTIVE_CANDIDATE_STAGE_UNVERIFIED");

            // This independently rehashes every staged file and re-verifies
            // original signed package, installed baseline, sequence and receipt.
            var readiness = _inspector.InspectActivationReadiness(operationId);
            if (!readiness.StageEvidenceVerified ||
                readiness.ReleaseType != "local_only")
                throw new InvalidDataException("INACTIVE_CANDIDATE_NOT_LOCAL_ONLY");
            var stageReceipt = AtomicFile.ReadJson<StagedReleaseReceipt>(operation.StageReceiptPath);
            var stagedManifestPath = Path.Combine(stageReceipt.StageRoot, "release-set.json");
            var manifest = AtomicFile.ReadJson<ReleaseSetManifest>(stagedManifestPath);

            // A signed manifest MUST identify the exact nested Local Web ZIP.
            // The old generic 'payload.bin' is explicitly NOT a Web tree.
            var artifacts = manifest.Artifacts.Values
                .Where(static x => x.Component == "local-web" && x.Kind == "web-tree-zip-v1")
                .ToArray();
            if (artifacts.Length != 1 ||
                manifest.Artifacts.Values.Any(static x =>
                    x.Component == "local-web" && x.Kind != "web-tree-zip-v1"))
                throw new InvalidDataException("LOCAL_WEB_ZIP_MAPPING_UNQUALIFIED");
            var artifact = artifacts[0];
            if (artifact.SizeBytes is <= 0 or > MaxInnerArchive)
                throw new InvalidDataException("LOCAL_WEB_ZIP_SIZE_INVALID");
            var artifactPath = SafeRelative(artifact.Path);
            var archivePath = Path.GetFullPath(Path.Combine(stageReceipt.StageRoot, artifactPath));
            if (!archivePath.StartsWith(Path.GetFullPath(stageReceipt.StageRoot) + Path.DirectorySeparatorChar,
                StringComparison.OrdinalIgnoreCase) || !File.Exists(archivePath) ||
                IsReparse(archivePath) || new FileInfo(archivePath).Length != artifact.SizeBytes ||
                !string.Equals(Sha256File(archivePath), artifact.Sha256, StringComparison.OrdinalIgnoreCase))
                throw new InvalidDataException("LOCAL_WEB_ZIP_STAGE_MISMATCH");

            if (!manifest.Targets.TryGetProperty("local", out var target) ||
                target.ValueKind != JsonValueKind.Object ||
                !target.TryGetProperty("version", out var versionElement) ||
                !target.TryGetProperty("managed_tree_sha256", out var treeElement) ||
                !target.TryGetProperty("managed_files", out var countElement) ||
                !countElement.TryGetInt32(out var managedCount) || managedCount is < 1 or > MaxFiles)
                throw new InvalidDataException("SIGNED_LOCAL_TARGET_MAPPING_REQUIRED");
            var version = versionElement.GetString()
                ?? throw new InvalidDataException("SIGNED_LOCAL_TARGET_MAPPING_REQUIRED");
            var tree = LocalManagedTreeAttestation.NormalizeSha256(
                treeElement.GetString() ?? throw new InvalidDataException("SIGNED_LOCAL_TARGET_MAPPING_REQUIRED"));
            if (version.Length is < 1 or > 200 || version.Any(char.IsControl) ||
                manifest.SupportedBaselines.Length < 1)
                throw new InvalidDataException("SIGNED_LOCAL_TARGET_MAPPING_REQUIRED");

            var receiptSha = Sha256File(operation.StageReceiptPath);
            var installedSha = Sha256File(_paths.InstalledReleaseIdentityPath);
            var root = Path.GetFullPath(_paths.InactiveLocalCandidatesRoot);
            if (Directory.Exists(root) && IsReparse(root))
                throw new InvalidDataException("INACTIVE_CANDIDATE_ROOT_UNSAFE");
            Directory.CreateDirectory(root);
            var destination = Path.Combine(root, "local-" + operationId);
            var receiptPath = Path.Combine(destination, "candidate-receipt.json");
            var candidatePath = Path.Combine(destination, "source-web");
            // If a process died in the middle of extraction, no unattended
            // cleanup/retry may erase the evidence of the interrupted write.
            if (Directory.EnumerateDirectories(root, "local-" + operationId + ".__incoming.*").Any())
                throw new InvalidOperationException("INACTIVE_CANDIDATE_RECONCILIATION_REQUIRED");
            if (Directory.Exists(destination))
            {
                if (!File.Exists(receiptPath) || IsReparse(destination) || IsReparse(receiptPath))
                    throw new InvalidOperationException("INACTIVE_CANDIDATE_RECONCILIATION_REQUIRED");
                var existing = AtomicFile.ReadJson<InactiveLocalCandidateReceipt>(receiptPath);
                RequireExisting(existing, operationId, operatorId, idem, artifact, receiptSha,
                    installedSha, version, tree, managedCount, candidatePath);
                return new(existing, Created: false, Reconciled: true);
            }

            var incoming = destination + ".__incoming." + Guid.NewGuid().ToString("N");
            _journal.Append("update.local_inactive_candidate.prepare_intent",
                _state.InstanceId, _state.InstallGeneration,
                new Dictionary<string, object?> {
                    ["stage_operation_id"] = operationId,
                    ["artifact_sha256"] = artifact.Sha256,
                    ["candidate"] = destination,
                    ["active_slot_mutation"] = false
                });
            Directory.CreateDirectory(candidatePath = Path.Combine(incoming, "source-web"));
            // Intentionally leave an incomplete incoming tree quarantined
            // on every extraction or receipt/commit error.
            ExtractBoundedArchive(archivePath, candidatePath);
            ValidateCandidateTree(candidatePath, version, tree, managedCount);
            if (!string.Equals(Sha256File(archivePath), artifact.Sha256, StringComparison.OrdinalIgnoreCase) ||
                Sha256File(operation.StageReceiptPath) != receiptSha ||
                Sha256File(_paths.InstalledReleaseIdentityPath) != installedSha)
                throw new InvalidDataException("CANDIDATE_SOURCE_CHANGED_DURING_PREPARATION");

            var receipt = new InactiveLocalCandidateReceipt(
                "sokna-private-inactive-local-candidate-v1", 1, operationId,
                manifest.ReleaseSetId, manifest.Sequence, operatorId, idem,
                artifact.Sha256, receiptSha, installedSha, version, tree,
                managedCount, Path.Combine(destination, "source-web"),
                DateTimeOffset.UtcNow, ActiveSlotUnchanged: true,
                PromotionQualified: false, ActivationPerformed: false,
                DatabaseMutationPerformed: false, PublicMutationPerformed: false);
            AtomicFile.WriteJson(Path.Combine(incoming, "candidate-receipt.json"), receipt);
            _journal.Append("update.local_inactive_candidate.files_verified",
                _state.InstanceId, _state.InstallGeneration,
                new Dictionary<string, object?> { ["stage_operation_id"] = operationId,
                    ["tree_sha256"] = tree, ["promotion_qualified"] = false });
            Directory.Move(incoming, destination);
            _journal.Append("update.local_inactive_candidate.private_commit",
                _state.InstanceId, _state.InstallGeneration,
                new Dictionary<string, object?> { ["stage_operation_id"] = operationId,
                    ["activation_performed"] = false, ["public_mutation_performed"] = false });
            return new(receipt, Created: true, Reconciled: false);
        }
    }

    /// <summary>
    /// Read-only verification of a previously prepared PRIVATE candidate,
    /// under the caller's existing machine lease. Does NOT call Prepare and
    /// does NOT attempt a second lease acquisition during Activate.
    /// </summary>
    public InactiveLocalCandidateReceipt InspectExisting(string stageId, string actor)
    {
        if (stageId is not { Length: 32 } || !stageId.All(char.IsAsciiHexDigit))
            throw new InvalidDataException("INACTIVE_CANDIDATE_STAGE_ID_INVALID");
        var stage = _operations.Get(stageId)
            ?? throw new InvalidDataException("INACTIVE_CANDIDATE_SIGNED_STAGE_MISSING");
        if (stage.OperatorId != actor || stage.OperationType != "update.local-stage" ||
            stage.State != "staged" || stage.ActivationPerformed ||
            stage.DatabaseMutationPerformed || stage.StageReceiptPath is null)
            throw new InvalidDataException("INACTIVE_CANDIDATE_STAGE_NOT_STAGED");
        var signed = _inspector.InspectActivationReadiness(stageId);
        if (!signed.StageEvidenceVerified || signed.ReleaseType != "local_only")
            throw new InvalidDataException("INACTIVE_CANDIDATE_SIGNATURE_UNPROVEN");

        var receiptRoot = Path.GetFullPath(Path.Combine(
            _paths.InactiveLocalCandidatesRoot, "local-" + stageId));
        var receiptPath = Path.Combine(receiptRoot, "candidate-receipt.json");
        if (!File.Exists(receiptPath) || IsReparse(receiptRoot) ||
            IsReparse(receiptPath))
            throw new InvalidDataException("INACTIVE_CANDIDATE_PREPARE_REQUIRED");
        var receipt = AtomicFile.ReadJson<InactiveLocalCandidateReceipt>(receiptPath);
        var source = Path.Combine(receiptRoot, "source-web");
        if (receipt.Format != "sokna-private-inactive-local-candidate-v1" ||
            receipt.SchemaVersion != 1 || receipt.StageOperationId != stageId ||
            receipt.OperatorId != actor ||
            receipt.ReleaseSetId != signed.ReleaseSetId ||
            receipt.Sequence != signed.Sequence ||
            !receipt.ActiveSlotUnchanged || receipt.PromotionQualified ||
            receipt.ActivationPerformed || receipt.DatabaseMutationPerformed ||
            receipt.PublicMutationPerformed ||
            receipt.CandidateRoot != source ||
            !string.Equals(receipt.StageReceiptSha256,
                Sha256File(stage.StageReceiptPath), StringComparison.OrdinalIgnoreCase) ||
            !string.Equals(receipt.InstalledIdentitySha256,
                Sha256File(_paths.InstalledReleaseIdentityPath),
                StringComparison.OrdinalIgnoreCase))
            throw new InvalidDataException("INACTIVE_CANDIDATE_RECEIPT_TAMPERED");

        var stageReceipt = AtomicFile.ReadJson<StagedReleaseReceipt>(stage.StageReceiptPath);
        var manifest = AtomicFile.ReadJson<ReleaseSetManifest>(
            Path.Combine(stageReceipt.StageRoot, "release-set.json"));
        if (manifest.ReleaseType != "local_only" ||
            manifest.ReleaseSetId != signed.ReleaseSetId ||
            !manifest.Targets.TryGetProperty("local", out var target) ||
            target.ValueKind != JsonValueKind.Object ||
            !target.TryGetProperty("version", out var v) ||
            v.GetString() != receipt.TargetVersion ||
            !target.TryGetProperty("managed_tree_sha256", out var t) ||
            !string.Equals(t.GetString(), receipt.TargetManagedTreeSha256,
                StringComparison.OrdinalIgnoreCase) ||
            !target.TryGetProperty("managed_files", out var n) ||
            !n.TryGetInt32(out var count) || count != receipt.TargetManagedFiles)
            throw new InvalidDataException("INACTIVE_CANDIDATE_SIGNED_TARGET_DRIFT");
        ValidateCandidateTree(source, receipt.TargetVersion,
            receipt.TargetManagedTreeSha256, receipt.TargetManagedFiles);
        return receipt;
    }

    private static void RequireExisting(InactiveLocalCandidateReceipt receipt,
        string id, string actor, string idem, ReleaseArtifact artifact, string stageSha,
        string installedSha, string version, string tree, int count, string candidate)
    {
        if (receipt.Format != "sokna-private-inactive-local-candidate-v1" ||
            receipt.SchemaVersion != 1 || receipt.StageOperationId != id ||
            receipt.OperatorId != actor || receipt.IdempotencyKey != idem ||
            !string.Equals(receipt.ArtifactSha256, artifact.Sha256, StringComparison.OrdinalIgnoreCase) ||
            receipt.StageReceiptSha256 != stageSha || receipt.InstalledIdentitySha256 != installedSha ||
            receipt.TargetVersion != version || receipt.TargetManagedTreeSha256 != tree ||
            receipt.TargetManagedFiles != count ||
            !string.Equals(Path.GetFullPath(receipt.CandidateRoot), Path.GetFullPath(candidate),
                StringComparison.OrdinalIgnoreCase) ||
            !receipt.ActiveSlotUnchanged || receipt.PromotionQualified ||
            receipt.ActivationPerformed || receipt.DatabaseMutationPerformed ||
            receipt.PublicMutationPerformed)
            throw new InvalidOperationException("INACTIVE_CANDIDATE_CONFLICT_OR_TAMPER");
        ValidateCandidateTree(candidate, version, tree, count);
    }

    private static void ExtractBoundedArchive(string archivePath, string destination)
    {
        using var zip = ZipFile.OpenRead(archivePath);
        if (zip.Entries.Count is < 3 or > MaxFiles + 100)
            throw new InvalidDataException("LOCAL_WEB_ARCHIVE_ENTRY_COUNT_INVALID");
        var seen = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
        long total = 0;
        foreach (var entry in zip.Entries)
        {
            if (entry.FullName.EndsWith("/", StringComparison.Ordinal))
            {
                // Directory entries are unnecessary; reject ambiguous types.
                throw new InvalidDataException("LOCAL_WEB_ARCHIVE_DIRECTORY_ENTRY_FORBIDDEN");
            }
            var unixKind = (entry.ExternalAttributes >> 16) & 0xF000;
            if (unixKind != 0 && unixKind != 0x8000)
                throw new InvalidDataException("LOCAL_WEB_ARCHIVE_SYMLINK_OR_SPECIAL_FILE");
            var relative = SafeRelative(entry.FullName);
            if (!seen.Add(relative))
                throw new InvalidDataException("LOCAL_WEB_ARCHIVE_DUPLICATE");
            if (ForbiddenMachineLocal(relative))
                throw new InvalidDataException("LOCAL_WEB_MACHINE_LOCAL_OVERLAY_FORBIDDEN");
            if (entry.Length is < 0 or > MaxExpanded ||
                total > MaxExpanded - entry.Length)
                throw new InvalidDataException("LOCAL_WEB_ARCHIVE_EXPANSION_LIMIT");
            total += entry.Length;
            var path = Path.GetFullPath(Path.Combine(destination, relative));
            if (!path.StartsWith(Path.GetFullPath(destination) + Path.DirectorySeparatorChar,
                StringComparison.OrdinalIgnoreCase))
                throw new InvalidDataException("LOCAL_WEB_ARCHIVE_PATH_ESCAPE");
            Directory.CreateDirectory(Path.GetDirectoryName(path)!);
            using var input = entry.Open();
            using var output = new FileStream(path, FileMode.CreateNew,
                FileAccess.Write, FileShare.None, 81920, FileOptions.WriteThrough);
            var buffer = new byte[81920];
            long written = 0;
            while (true)
            {
                var n = input.Read(buffer, 0, buffer.Length);
                if (n == 0) break;
                written += n;
                if (written > entry.Length)
                    throw new InvalidDataException("LOCAL_WEB_ENTRY_OVERRUN");
                output.Write(buffer, 0, n);
            }
            output.Flush(flushToDisk: true);
            if (written != entry.Length)
                throw new InvalidDataException("LOCAL_WEB_ENTRY_LENGTH_MISMATCH");
        }
        if (!seen.Contains("VERSION.txt") ||
            !seen.Contains("resources" + Path.DirectorySeparatorChar + "local-managed-files-v1.json"))
            throw new InvalidDataException("LOCAL_WEB_REQUIRED_METADATA_MISSING");
    }

    private static void ValidateCandidateTree(string source, string version, string tree, int count)
    {
        if (!Directory.Exists(source) || IsReparse(source) ||
            Directory.EnumerateFiles(source, "*", SearchOption.AllDirectories).Any(IsReparse) ||
            Directory.EnumerateDirectories(source, "*", SearchOption.AllDirectories).Any(IsReparse))
            throw new InvalidDataException("LOCAL_WEB_CANDIDATE_PATH_UNSAFE");
        if (File.ReadAllText(Path.Combine(source, "VERSION.txt")).Trim() != version)
            throw new InvalidDataException("LOCAL_WEB_CANDIDATE_VERSION_MISMATCH");
        var inventoryFile = Path.Combine(source, "resources", "local-managed-files-v1.json");
        using var doc = JsonDocument.Parse(File.ReadAllBytes(inventoryFile));
        if (!doc.RootElement.TryGetProperty("format", out var fmt) ||
            fmt.GetString() != "sokna-local-managed-files-v1" ||
            !doc.RootElement.TryGetProperty("files", out var files) ||
            files.ValueKind != JsonValueKind.Array)
            throw new InvalidDataException("LOCAL_WEB_INVENTORY_INVALID");
        var listed = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
        foreach (var item in files.EnumerateArray())
        {
            var rel = SafeRelative(item.GetString() ?? "");
            if (ForbiddenMachineLocal(rel) || !listed.Add(rel))
                throw new InvalidDataException("LOCAL_WEB_INVENTORY_UNSAFE");
        }
        if (listed.Count != count ||
            !listed.SetEquals(Directory.EnumerateFiles(source, "*", SearchOption.AllDirectories)
                .Select(file => Path.GetRelativePath(source, file))))
            throw new InvalidDataException("LOCAL_WEB_INVENTORY_FILES_MISMATCH");
        LocalManagedTreeAttestation.RequireExact(source, count, tree);
    }

    private static string SafeRelative(string relative)
    {
        if (string.IsNullOrWhiteSpace(relative) || relative.Length > 512 ||
            relative.Contains('\0') || relative.Contains(':') ||
            relative.StartsWith('/') || relative.StartsWith('\\') ||
            relative.EndsWith('/') || relative.EndsWith('\\'))
            throw new InvalidDataException("LOCAL_WEB_UNSAFE_RELATIVE_PATH");
        var normalized = relative.Replace('/', Path.DirectorySeparatorChar)
            .Replace('\\', Path.DirectorySeparatorChar);
        if (normalized.Split(Path.DirectorySeparatorChar).Any(static part =>
            part is "" or "." or ".." || part.EndsWith(' ') || part.EndsWith('.')))
            throw new InvalidDataException("LOCAL_WEB_UNSAFE_RELATIVE_PATH");
        return normalized;
    }

    private static bool ForbiddenMachineLocal(string relative)
    {
        var p = relative.Replace('\\', '/');
        return p.Equals(".sokna-installation.json", StringComparison.OrdinalIgnoreCase) ||
            p.Equals("config.php", StringComparison.OrdinalIgnoreCase) ||
            p.Equals("install.lock", StringComparison.OrdinalIgnoreCase) ||
            p.Equals("public/local-update-runner.php", StringComparison.OrdinalIgnoreCase) ||
            p.Equals("public/local-recovery.php", StringComparison.OrdinalIgnoreCase);
    }

    private static bool IsReparse(string path)
        => (File.GetAttributes(path) & FileAttributes.ReparsePoint) != 0;
    private static string Sha256File(string path)
    {
        using var stream = File.OpenRead(path);
        return Convert.ToHexString(SHA256.HashData(stream)).ToLowerInvariant();
    }
}
