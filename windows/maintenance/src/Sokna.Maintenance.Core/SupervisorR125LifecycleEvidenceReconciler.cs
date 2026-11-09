using System.ComponentModel;
using System.Diagnostics;
using System.Security.Cryptography;
using System.Text.Json.Serialization;

namespace Sokna.Maintenance.Core;

public sealed record R125LifecycleReconciliationRequest(
    [property: JsonPropertyName("operator_id")] string OperatorId,
    [property: JsonPropertyName("expected_build")] string ExpectedBuild,
    [property: JsonPropertyName("expected_proof_sha256")] string ExpectedProofSha256);

public sealed record R125LifecycleReconciliationResult(
    MaintenanceOperation Operation, bool Created, bool Reconciled,
    string ProofSha256, bool DatabaseMutationPerformed, bool PublicMutationPerformed);

/// <summary>
/// Explicit Recovery Console-only settlement of an orphan R125 lifecycle proof.
/// Not a general cleanup API: neither Local, Public, MariaDB, nor Windows services
/// are modified. Every mismatch fails closed, including uncertain DB forward state.
/// One machine lease protects the proof move, durable operation, and receipt.
/// </summary>
public sealed class SupervisorR125LifecycleEvidenceReconciler
{
    private const string OperationType = "update.local-r125-lifecycle-reconcile";
    private const string ProofFile = "routine-local-lifecycle-before.json";
    private readonly MaintenancePaths _paths;
    private readonly MaintenanceOperationStore _operations;
    private readonly DurableJournal _journal;
    private readonly SupervisorState _supervisor;
    // The production constructor ALWAYS performs the live read-only host/DB gate.
    // Internal test seam only: disposable CI can exercise the exact journal,
    // lease, archive and crash/retry logic without connecting to a cafe database.
    private readonly Action<string, string, string> _qualify;
    private readonly Action? _afterMoveTestHook;

    public SupervisorR125LifecycleEvidenceReconciler(MaintenancePaths paths,
        MaintenanceOperationStore operations, DurableJournal journal, SupervisorState supervisor)
    {
        _paths = paths; _operations = operations; _journal = journal; _supervisor = supervisor;
        _qualify = (proof, build, sha) => RequireReadOnlyQualification(_paths, proof, build, sha);
    }

    internal SupervisorR125LifecycleEvidenceReconciler(MaintenancePaths paths,
        MaintenanceOperationStore operations, DurableJournal journal,
        SupervisorState supervisor, Action<string, string, string> testQualification,
        Action? testAfterMove = null)
        : this(paths, operations, journal, supervisor)
    {
        _qualify = testQualification ?? throw new ArgumentNullException(nameof(testQualification));
        _afterMoveTestHook = testAfterMove;
    }

    /// <summary>
    /// Policy decision without inspecting or changing the live host.
    /// Do not archive if a previous DDL/DML forward boundary may have occurred.
    /// It requires separate recovery/DB restore qualification, not an unlock.
    /// </summary>
    public static void RequireSettledRoutine(RoutineLocalSlotState state, string expectedBuild)
    {
        if (state is null || state.Format != "sokna-local-slot-routine-state-v1" ||
            state.SchemaVersion != 1 || state.Phase != "RECOVERED_TO_PREVIOUS_SLOT" ||
            state.RecoveryRequired || !state.MaintenanceIdentityCommitExpected ||
            state.DatabaseMutationPerformed || state.PublicMutationPerformed ||
            state.WindowsRebootRequired ||
            state.Events is null || state.Events.Count == 0 ||
            state.Events[^1].State != "RECOVERED_TO_PREVIOUS_SLOT" ||
            !string.Equals(state.CurrentBuild, expectedBuild, StringComparison.Ordinal))
            throw new InvalidDataException("R125_RECOVERY_STATE_NOT_QUALIFIED");
    }

    public R125LifecycleReconciliationResult Reconcile(
        R125LifecycleReconciliationRequest request, string idempotencyKey)
    {
        ArgumentNullException.ThrowIfNull(request);
        if (!MaintenanceOperationStore.IsValidIdempotencyKey(idempotencyKey) ||
            !MaintenanceOperationStore.IsValidIdempotencyKey(request.OperatorId) ||
            string.IsNullOrWhiteSpace(request.ExpectedBuild) ||
            request.ExpectedBuild.Length > 180 || request.ExpectedBuild.Any(char.IsControl))
            throw new InvalidDataException("R125_RECOVERY_REQUEST_INVALID");
        var sha = LocalManagedTreeAttestation.NormalizeSha256(request.ExpectedProofSha256);
        var requestRef = request.ExpectedBuild + ":" + sha;
        var requestHash = MaintenanceOperationStore.CanonicalRequestHash(
            OperationType, requestRef, request.OperatorId);
        lock (LocalUpdateOperationGate.Sync)
        {
            using var lease = MachineMutationLease.Acquire(_paths, EngineDelegationPermit.Owner);
            var prior = _operations.GetByIdempotencyKey(idempotencyKey);
            if (prior is not null &&
                (prior.OperationType != OperationType ||
                 prior.PackageRef != requestRef ||
                 prior.OperatorId != request.OperatorId ||
                 prior.CanonicalRequestSha256 != requestHash))
                throw new InvalidDataException("R125_RECOVERY_IDEMPOTENCY_CONFLICT");
            var other = _operations.ListBlocking()
                .Where(o => prior is null || o.OperationId != prior.OperationId).ToArray();
            if (other.Length != 0)
                throw new InvalidOperationException("UPDATER_OPERATION_BUSY");
            if (prior is not null && prior.State == "committed")
            {
                var saved = Path.Combine(_paths.LocalSlotStateRoot,
                    "reconciled-lifecycle-evidence", "proof-" + prior.OperationId + ".json");
                var receiptPath = Path.Combine(_paths.LocalSlotStateRoot,
                    "reconciled-lifecycle-evidence", "proof-" + prior.OperationId + ".receipt.json");
                VerifySourceSha(saved, sha);
                var committedRoot = Path.Combine(_paths.LocalSlotStateRoot,
                    "reconciled-lifecycle-evidence");
                if (File.Exists(Path.Combine(_paths.LocalSlotStateRoot, ProofFile)))
                    throw new InvalidDataException("R125_COMMITTED_SOURCE_PROOF_REAPPEARED");
                VerifyIntent(Path.Combine(committedRoot,
                    "proof-" + prior.OperationId + ".intent.json"),
                    prior.OperationId, sha, request.ExpectedBuild);
                VerifyReceipt(receiptPath, prior.OperationId, sha, request.ExpectedBuild);
                return new(prior, false, true, sha, false, false);
            }
            if (prior is not null && prior.State == "failed_pre_mutation")
                return new(prior, false, true, sha, false, false);
            if (prior is not null && prior.State is not ("accepted" or "running" or "recovery_required"))
                throw new InvalidDataException("R125_RECOVERY_OPERATION_STATE_INVALID");

            var root = Path.Combine(_paths.LocalSlotStateRoot, "reconciled-lifecycle-evidence");
            var source = Path.Combine(_paths.LocalSlotStateRoot, ProofFile);
            var archive = prior is null ? "" :
                Path.Combine(root, "proof-" + prior.OperationId + ".json");
            var existingArchive = prior is not null && File.Exists(archive);
            var evidence = existingArchive ? archive : source;

            // All preconditions are read-only, including a live SELECT-only
            // migration ledger check.  Failure here never moves a file.
            _qualify(evidence, request.ExpectedBuild, sha);
            var (operation, created) = prior is null
                ? _operations.GetOrCreate(idempotencyKey, requestHash, OperationType,
                    requestRef, request.OperatorId)
                : (prior, false);
            archive = Path.Combine(root, "proof-" + operation.OperationId + ".json");
            var intent = Path.Combine(root, "proof-" + operation.OperationId + ".intent.json");
            var receipt = Path.Combine(root, "proof-" + operation.OperationId + ".receipt.json");
            if (operation.State != "running")
            {
                operation = _operations.Update(operation, "running",
                    "R125_RECONCILIATION_INTENT_DURABLE",
                    "R125 settled-proof archive intent; no Local/Public/DB mutation.",
                    incrementAttempt: true);
            }

            try
            {
                // A retry after a process crash is permitted ONLY for the same
                // signed, idempotent operation, and only after verifying its
                // immutable intent and the complete archived snapshot.
                EnsureRestrictedArchiveRoot(root);
                if (File.Exists(archive))
                {
                    if (File.Exists(source) || !File.Exists(intent))
                        throw new InvalidDataException("R125_RECOVERY_DUAL_OR_MISSING_PROOF");
                    VerifyIntent(intent, operation.OperationId, sha, request.ExpectedBuild);
                    _qualify(archive, request.ExpectedBuild, sha);
                }
                else
                {
                    if (!File.Exists(source))
                        throw new InvalidDataException("R125_RECOVERY_PROOF_DISAPPEARED");
                    _qualify(source, request.ExpectedBuild, sha);
                    if (File.Exists(intent))
                        VerifyIntent(intent, operation.OperationId, sha, request.ExpectedBuild);
                    else
                        AtomicFile.WriteJson(intent, new R125ArchiveIntent(
                            "sokna-r125-lifecycle-archive-intent-v1",
                            operation.OperationId, request.ExpectedBuild, sha,
                            DateTimeOffset.UtcNow));
                    VerifySourceSha(source, sha);
                    File.Move(source, archive); // Same disk; never delete evidence.
                    VerifySourceSha(archive, sha);
                    _afterMoveTestHook?.Invoke();
                }
                if (File.Exists(receipt))
                    VerifyReceipt(receipt, operation.OperationId, sha, request.ExpectedBuild);
                else
                {
                    AtomicFile.WriteJson(receipt, new R125ArchiveReceipt(
                        "sokna-r125-lifecycle-archive-receipt-v1",
                        operation.OperationId, request.ExpectedBuild, sha,
                        "RECOVERED_TO_PREVIOUS_SLOT", true, true, true,
                        false, false, DateTimeOffset.UtcNow));
                    VerifyReceipt(receipt, operation.OperationId, sha, request.ExpectedBuild);
                }
                _journal.Append("update.local_r125_lifecycle.reconciled",
                    _supervisor.InstanceId, _supervisor.InstallGeneration,
                    new Dictionary<string, object?>
                    {
                        ["operation_id"] = operation.OperationId,
                        ["proof_sha256"] = sha,
                        ["state"] = "RECOVERED_TO_PREVIOUS_SLOT",
                        ["live_db_ledger_inspected"] = true,
                        ["local_mutated"] = false,
                        ["database_mutated"] = false,
                        ["public_mutated"] = false,
                        ["service_mutated"] = false
                    });
                operation = _operations.Update(operation, "committed",
                    "R125_RESIDUAL_PROOF_ARCHIVED_AND_DB_BASELINE_VERIFIED",
                    "Original R125 proof retained under protected Maintenance evidence; no DB/Public/Local mutation.");
                return new(operation, created, false, sha, false, false);
            }
            catch (Exception ex) when (ex is InvalidDataException or IOException or
                UnauthorizedAccessException or Win32Exception or TimeoutException or
                InvalidOperationException or System.Security.Cryptography.CryptographicException)
            {
                var uncertain = File.Exists(archive) || !File.Exists(source);
                operation = _operations.Update(operation,
                    uncertain ? "recovery_required" : "failed_pre_mutation",
                    uncertain ? "R125_ARCHIVE_OUTCOME_REQUIRES_RECONCILIATION" :
                        "R125_ARCHIVE_BLOCKED_BEFORE_PROOF_MOVE",
                    "Retained all available evidence; manual Recovery Console assessment required.");
                _journal.Append("update.local_r125_lifecycle.blocked",
                    _supervisor.InstanceId, _supervisor.InstallGeneration,
                    new Dictionary<string, object?>
                    {
                        ["operation_id"] = operation.OperationId,
                        ["error_type"] = ex.GetType().Name,
                        ["needs_recovery"] = uncertain,
                        ["proof_deleted"] = false,
                        ["database_mutated"] = false
                    });
                return new(operation, created, false, sha, false, false);
            }
        }
    }

    public static void RequireReadOnlyQualification(MaintenancePaths paths, string proof, string expectedBuild, string sha)
    {
        VerifySourceSha(proof, sha);
        var layout = new LocalSlotManager(paths).InspectHealthyCurrent();
        if (layout.Build != expectedBuild || layout.ActiveSlotTarget is null ||
            !layout.WebIsReparsePoint)
            throw new InvalidDataException("R125_ACTIVE_BUILD_OR_LAYOUT_MISMATCH");
        var statePath = Path.Combine(paths.LocalSlotStateRoot, "routine-state.json");
        if (!File.Exists(statePath) ||
            (File.GetAttributes(statePath) & FileAttributes.ReparsePoint) != 0)
            throw new InvalidDataException("R125_RECOVERY_STATE_MISSING");
        var state = AtomicFile.ReadJson<RoutineLocalSlotState>(statePath);
        var external = state.DatabaseMutationPerformed;
        var baseline = RoutineLocalDatabase.Plan(layout.WebRoot, layout.WebRoot);
        if (external)
        {
            // Historical R129 mutation is not current DB state. Require the entire
            // protected evidence chain; never clear or reinterpret the stored flag.
            R129ExternalRollbackEvidence.Require(paths, layout.Build, baseline);
        }
        else RequireSettledRoutine(state, layout.Build);
        ProductionLocalSlotMigrationBridge.RoutineLocalSlotSafety.RequirePreviousSettled(
            paths, layout.PackageRoot, layout.WebRoot, layout.ActiveSlotTarget, layout.Build);
        LocalManagedTreeAttestation.RequireExact(
            layout.WebRoot, state.CurrentManagedFiles, state.CurrentManagedTreeSha256);
        var identityPath = paths.InstalledReleaseIdentityPath;
        var identity = AtomicFile.ReadJson<InstalledReleaseIdentity>(identityPath);
        if (identity.Local?.Version != layout.Build ||
            !string.Equals(identity.Local.ManagedTreeSha256, state.CurrentManagedTreeSha256,
                StringComparison.OrdinalIgnoreCase))
            throw new InvalidDataException("R125_INSTALLED_IDENTITY_NOT_QUALIFIED");
        RoutineLocalLifecycle.RequireRecoveredByteExact(
            layout.PackageRoot, paths.LocalSlotStateRoot, layout.Build, identityPath, proof);

        // No DDL, DML, new backup, rollback or restore; this helper strictly
        // SELECTs the real live migration ledger and rejects partial statements.
        if (baseline.RequiresMigration)
            throw new InvalidDataException("R125_UNEXPECTED_MIGRATION_PLAN");
        RoutineLocalDatabase.RequireBaseline(layout.PackageRoot, layout.WebRoot,
            layout.WebRoot, baseline);
        if (external) RoutineLocalDatabase.RequireExternalRollbackBaseline(
            layout.PackageRoot, layout.WebRoot, baseline);
    }

    private static void VerifySourceSha(string path, string expected)
    {
        if (!File.Exists(path) ||
            (File.GetAttributes(path) & FileAttributes.ReparsePoint) != 0 ||
            new FileInfo(path).Length is <= 0 or > 8_000_000)
            throw new InvalidDataException("R125_PROOF_UNAVAILABLE_OR_UNSAFE");
        using var s = File.OpenRead(path);
        var hash = Convert.ToHexString(SHA256.HashData(s)).ToLowerInvariant();
        if (!string.Equals(hash, expected, StringComparison.Ordinal))
            throw new InvalidDataException("R125_PROOF_SHA256_MISMATCH");
    }

    private static void VerifyReceipt(string path, string operationId, string sha, string build)
    {
        if (!File.Exists(path) ||
            (File.GetAttributes(path) & FileAttributes.ReparsePoint) != 0 ||
            new FileInfo(path).Length is <= 0 or > 16_384)
            throw new InvalidDataException("R125_COMMITTED_ARCHIVE_RECEIPT_MISSING");
        var receipt = AtomicFile.ReadJson<R125ArchiveReceipt>(path);
        if (receipt.Format != "sokna-r125-lifecycle-archive-receipt-v1" ||
            receipt.OperationId != operationId || receipt.ExpectedBuild != build ||
            receipt.ProofSha256 != sha ||
            receipt.FinalLifecyclePhase != "RECOVERED_TO_PREVIOUS_SLOT" ||
            !receipt.BytesExact || !receipt.ActiveIdentityQualified ||
            !receipt.LiveDbMigrationLedgerQualified ||
            receipt.DatabaseMutationPerformed || receipt.PublicMutationPerformed)
            throw new InvalidDataException("R125_COMMITTED_ARCHIVE_RECEIPT_UNQUALIFIED");
    }

    private static void VerifyIntent(string path, string operationId, string sha, string build)
    {
        if (!File.Exists(path) ||
            (File.GetAttributes(path) & FileAttributes.ReparsePoint) != 0)
            throw new InvalidDataException("R125_RECONCILIATION_INTENT_UNAVAILABLE");
        var saved = AtomicFile.ReadJson<R125ArchiveIntent>(path);
        if (saved.Format != "sokna-r125-lifecycle-archive-intent-v1" ||
            saved.OperationId != operationId || saved.ExpectedBuild != build ||
            saved.ProofSha256 != sha)
            throw new InvalidDataException("R125_RECONCILIATION_INTENT_MISMATCH");
    }

    private static void EnsureRestrictedArchiveRoot(string root)
    {
        var parent = Path.GetDirectoryName(root) ??
            throw new InvalidDataException("R125_ARCHIVE_ROOT_UNSAFE");
        if (!Directory.Exists(parent) ||
            (File.GetAttributes(parent) & FileAttributes.ReparsePoint) != 0)
            throw new InvalidDataException("R125_ARCHIVE_PARENT_UNSAFE");
        Directory.CreateDirectory(root);
        if ((File.GetAttributes(root) & FileAttributes.ReparsePoint) != 0)
            throw new InvalidDataException("R125_ARCHIVE_ROOT_REDIRECTED");
        var psi = new ProcessStartInfo("icacls.exe")
        {
            UseShellExecute = false, CreateNoWindow = true,
            RedirectStandardOutput = true, RedirectStandardError = true
        };
        foreach (var argument in new[]
        {
            root, "/inheritance:r", "/grant:r", "*S-1-5-18:(OI)(CI)F", "*S-1-5-32-544:(OI)(CI)F"
        }) psi.ArgumentList.Add(argument);
        using var process = Process.Start(psi) ??
            throw new InvalidDataException("R125_ARCHIVE_ACL_UNAVAILABLE");
        var stdout = process.StandardOutput.ReadToEndAsync();
        var stderr = process.StandardError.ReadToEndAsync();
        if (!process.WaitForExit(15_000))
        {
            process.Kill(entireProcessTree: true);
            throw new TimeoutException("R125_ARCHIVE_ACL_TIMEOUT");
        }
        Task.WaitAll(stdout, stderr);
        if (process.ExitCode != 0)
            throw new InvalidDataException("R125_ARCHIVE_ACL_FAILED");
    }

    private sealed record R125ArchiveIntent(
        string Format, string OperationId, string ExpectedBuild,
        string ProofSha256, DateTimeOffset CreatedAtUtc);

    private sealed record R125ArchiveReceipt(
        string Format, string OperationId, string ExpectedBuild,
        string ProofSha256, string FinalLifecyclePhase, bool BytesExact,
        bool ActiveIdentityQualified, bool LiveDbMigrationLedgerQualified,
        bool DatabaseMutationPerformed, bool PublicMutationPerformed,
        DateTimeOffset CompletedAtUtc);
}

