using System.Security.Cryptography;
using System.Text.Json.Serialization;

namespace Sokna.Maintenance.Core;

/// <summary>
/// Supervisor-owned durable reservation for a future Local/Public/Maintenance update.
/// This is a real operation-store / machine-lease integration, NOT an installer.
/// No method authorizes commit, database rollback, deployment or cleanup.
/// </summary>
public sealed record UniversalUpgradeFence(
    [property: JsonPropertyName("format")] string Format,
    [property: JsonPropertyName("operation_id")] string OperationId,
    [property: JsonPropertyName("phase")] string Phase,
    [property: JsonPropertyName("source_identity_sha256")] string SourceIdentitySha256,
    [property: JsonPropertyName("local_archive_sha256")] string LocalArchiveSha256,
    [property: JsonPropertyName("public_archive_sha256")] string PublicArchiveSha256,
    [property: JsonPropertyName("maintenance_archive_sha256")] string MaintenanceArchiveSha256,
    [property: JsonPropertyName("local_forward_report_sha256")] string? LocalForwardReportSha256,
    [property: JsonPropertyName("public_report_sha256")] string? PublicReportSha256,
    [property: JsonPropertyName("database_forward_reported")] bool DatabaseForwardReported,
    [property: JsonPropertyName("recovery_required")] bool RecoveryRequired,
    [property: JsonPropertyName("global_commit_verified")] bool GlobalCommitVerified,
    [property: JsonPropertyName("production_authorized")] bool ProductionAuthorized);

public sealed record UniversalUpgradeInspection(
    [property: JsonPropertyName("status")] string Status,
    [property: JsonPropertyName("phase")] string Phase,
    [property: JsonPropertyName("blocked")] bool Blocked,
    [property: JsonPropertyName("database_forward_reported")] bool DatabaseForwardReported,
    [property: JsonPropertyName("recovery_required")] bool RecoveryRequired,
    [property: JsonPropertyName("production_authorized")] bool ProductionAuthorized)
{
    // A human-facing explanation over the existing signed status endpoint.
    // Never turns a QA reservation into production install permission.
    [JsonPropertyName("user_status")]
    public string UserStatus => RecoveryRequired || Phase != "NONE" ? "needs_action" : "not_ready";

    [JsonPropertyName("user_message")]
    public string UserMessage => RecoveryRequired
        ? "به‌روزرسانی کامل نشده است. ادامهٔ خودکار برای حفظ اطلاعات متوقف شده است."
        : Phase == "NONE"
            ? "به‌روزرسانی خودکار هنوز آمادهٔ اجرا نیست."
            : "یک عملیات به‌روزرسانی ثبت شده و نتیجهٔ آن باید بررسی شود.";

    [JsonPropertyName("can_start_update")]
    public bool CanStartUpdate => false;
}

public sealed class SupervisorUniversalUpgradeFence
{
    public const string OperationType = "update.universal-three-component";
    public const string Format = "sokna-supervisor-universal-fence-v1";
    private const string Reserved = "RESERVED_NO_ACTIVATION";
    private const string Forward = "LOCAL_DB_FORWARD_REQUIRES_RECONCILIATION";
    private const string PublicObserved = "PUBLIC_OBSERVED_RECOVERY_UNQUALIFIED";
    private readonly MaintenancePaths _paths;
    private readonly MaintenanceOperationStore _operations;
    private string Root => Path.Combine(_paths.UpdateRoot, "universal-three-component");
    private string Receipt => Path.Combine(Root, "active.json");

    public SupervisorUniversalUpgradeFence(MaintenancePaths paths, MaintenanceOperationStore operations)
    {
        _paths = paths ?? throw new ArgumentNullException(nameof(paths));
        _operations = operations ?? throw new ArgumentNullException(nameof(operations));
    }

    private static void RequireSha(string? value, string code)
    {
        if (value is not { Length: 64 } ||
            value.Any(static c => c is not (>= '0' and <= '9' or >= 'a' and <= 'f')))
            throw new InvalidDataException(code);
    }

    private static string PackageRef(UniversalUpgradeFence f)
        => f.LocalArchiveSha256 + ":" + f.PublicArchiveSha256 + ":" + f.MaintenanceArchiveSha256;

    private static void RequireSafeFile(string path)
    {
        var full = Path.GetFullPath(path);
        if (!File.Exists(full) || (File.GetAttributes(full) & FileAttributes.ReparsePoint) != 0)
            throw new InvalidDataException("UNIVERSAL_REQUIRED_EVIDENCE_MISSING_OR_LINK");
    }

    private void RequireSafeOwnedRoot()
    {
        var parent = Path.GetFullPath(_paths.UpdateRoot);
        var root = Path.GetFullPath(Root);
        if (!string.Equals(Path.GetDirectoryName(root), parent, StringComparison.OrdinalIgnoreCase))
            throw new InvalidDataException("UNIVERSAL_ROOT_ESCAPE");
        if (Directory.Exists(parent) && (File.GetAttributes(parent) & FileAttributes.ReparsePoint) != 0 ||
            Directory.Exists(root) && (File.GetAttributes(root) & FileAttributes.ReparsePoint) != 0 ||
            File.Exists(Receipt) && (File.GetAttributes(Receipt) & FileAttributes.ReparsePoint) != 0)
            throw new InvalidDataException("UNIVERSAL_REPARSE_POINT_UNTRUSTED");
    }

    private UniversalUpgradeFence? LoadValidated()
    {
        RequireSafeOwnedRoot();
        if (!File.Exists(Receipt)) return null;
        var f = AtomicFile.ReadJson<UniversalUpgradeFence>(Receipt);
        if (f.Format != Format ||
            f.OperationId is not { Length: 32 } || f.OperationId.Any(static c => !char.IsAsciiHexDigit(c)) ||
            f.Phase is not (Reserved or Forward or PublicObserved) ||
            f.GlobalCommitVerified || f.ProductionAuthorized ||
            f.RecoveryRequired != (f.Phase != Reserved) ||
            f.DatabaseForwardReported != (f.Phase != Reserved))
            throw new InvalidDataException("UNIVERSAL_FENCE_SCHEMA_OR_PHASE_UNTRUSTED");
        RequireSha(f.SourceIdentitySha256, "UNIVERSAL_SOURCE_IDENTITY_UNTRUSTED");
        RequireSha(f.LocalArchiveSha256, "UNIVERSAL_LOCAL_HASH_UNTRUSTED");
        RequireSha(f.PublicArchiveSha256, "UNIVERSAL_PUBLIC_HASH_UNTRUSTED");
        RequireSha(f.MaintenanceArchiveSha256, "UNIVERSAL_MAINTENANCE_HASH_UNTRUSTED");
        if (f.Phase == Reserved && (f.LocalForwardReportSha256 is not null || f.PublicReportSha256 is not null) ||
            f.Phase != Reserved && f.LocalForwardReportSha256 is null ||
            f.Phase == Forward && f.PublicReportSha256 is not null ||
            f.Phase == PublicObserved && f.PublicReportSha256 is null)
            throw new InvalidDataException("UNIVERSAL_FENCE_EVIDENCE_PHASE_MISMATCH");
        if (f.LocalForwardReportSha256 is not null)
            RequireSha(f.LocalForwardReportSha256, "UNIVERSAL_FORWARD_HASH_UNTRUSTED");
        if (f.PublicReportSha256 is not null)
            RequireSha(f.PublicReportSha256, "UNIVERSAL_PUBLIC_RECEIPT_HASH_UNTRUSTED");
        var op = _operations.Get(f.OperationId)
            ?? throw new InvalidDataException("UNIVERSAL_FENCE_OPERATION_MISSING");
        if (op.OperationType != OperationType ||
            op.PackageRef != PackageRef(f) ||
            op.CanonicalRequestSha256 != MaintenanceOperationStore.CanonicalRequestHash(
                OperationType, PackageRef(f), op.OperatorId) ||
            op.State != (f.Phase == Reserved ? "accepted" : "recovery_required") ||
            op.DatabaseMutationPerformed != f.DatabaseForwardReported ||
            op.ActivationPerformed != f.DatabaseForwardReported)
            throw new InvalidDataException("UNIVERSAL_FENCE_OPERATION_DRIFT");
        return f;
    }

    /// <summary>
    /// Authenticated read-only Supervisor endpoint can call this method.
    /// Missing/corrupt evidence never grants eligibility.
    /// </summary>
    public UniversalUpgradeInspection Inspect()
    {
        var f = LoadValidated();
        var blockers = _operations.ListBlocking();
        if (f is null)
        {
            // A forged terminal operation or missing receipt can never make
            // an earlier universal attempt disappear from the eligibility view.
            var orphan = _operations.ListAllForUniversalQualification()
                .Any(static op => op.OperationType == OperationType);
            return new UniversalUpgradeInspection(
                orphan ? "UNIVERSAL_ORPHANED_OWNER_UNQUALIFIED" :
                    blockers.Count != 0 ? "UPDATER_OPERATION_BUSY" : "UNIVERSAL_NOT_QUALIFIED",
                "NONE", true, false, orphan || blockers.Count != 0, false);
        }
        if (blockers.Count != 1 || blockers[0].OperationId != f.OperationId)
            throw new InvalidDataException("UNIVERSAL_CONCURRENT_OPERATION_UNRECONCILED");
        return new UniversalUpgradeInspection(
            f.Phase == Reserved ? "RESERVED_BLOCKING_ALL_UPDATES" : "DB_FORWARD_RECOVERY_NOT_QUALIFIED",
            f.Phase, true, f.DatabaseForwardReported, f.RecoveryRequired, false);
    }

    /// <summary>
    /// Internal Supervisor API only. No panel route, release or activation wired.
    /// A crash after the operation is reserved but before its receipt is written
    /// leaves an "accepted" operation which blocks all future updates.
    /// </summary>
    public UniversalUpgradeFence Reserve(string operatorId, string idempotencyKey,
        string localSha, string publicSha, string maintenanceSha)
    {
        RequireSha(localSha, "UNIVERSAL_LOCAL_HASH_INVALID");
        RequireSha(publicSha, "UNIVERSAL_PUBLIC_HASH_INVALID");
        RequireSha(maintenanceSha, "UNIVERSAL_MAINTENANCE_HASH_INVALID");
        lock (LocalUpdateOperationGate.Sync)
        {
            using var lease = MachineMutationLease.Acquire(_paths, "supervisor-universal-reserve");
            LocalUpdateOperationGate.RequireNoPendingMutation(_operations);
            // A non-blocking state can still be unsafe after a historical DB
            // failure or when 'verified'/'staged' lacks original signed receipts.
            // Do NOT let the new owner bypass the café's R125 recovery ledger.
            foreach (var historical in _operations.ListAllForUniversalQualification())
            {
                if (historical.OperationType == OperationType)
                    throw new InvalidOperationException("UNIVERSAL_ORPHANED_OWNER_REQUIRES_RECONCILIATION");
                if (historical.State is not ("committed" or "rolled_back" or
                    "recovered" or "cancelled" or "failed_pre_mutation"))
                    throw new InvalidOperationException("UNIVERSAL_HISTORICAL_LEDGER_NOT_QUALIFIED");
                if (historical.State != "committed" &&
                    (historical.DatabaseMutationPerformed || historical.ActivationPerformed))
                    throw new InvalidOperationException("UNIVERSAL_HISTORICAL_RECOVERY_PROOF_REQUIRED");
            }
            if (LoadValidated() is not null)
                throw new InvalidOperationException("UNIVERSAL_PREVIOUS_RESERVATION_REQUIRES_RECONCILIATION");
            RequireSafeFile(_paths.InstalledReleaseIdentityPath);
            var identity = File.ReadAllBytes(_paths.InstalledReleaseIdentityPath);
            if (identity.Length is < 2 or > 1048576)
                throw new InvalidDataException("UNIVERSAL_INSTALLED_IDENTITY_INVALID_SIZE");
            var identitySha = Convert.ToHexString(SHA256.HashData(identity)).ToLowerInvariant();
            var packageRef = localSha + ":" + publicSha + ":" + maintenanceSha;
            var requestHash = MaintenanceOperationStore.CanonicalRequestHash(OperationType, packageRef, operatorId);
            var (op, created) = _operations.GetOrCreate(idempotencyKey, requestHash,
                OperationType, packageRef, operatorId);
            if (!created)
                throw new InvalidOperationException("UNIVERSAL_REPLAY_REQUIRES_READONLY_INSPECTION");
            var f = new UniversalUpgradeFence(Format, op.OperationId, Reserved,
                identitySha, localSha, publicSha, maintenanceSha,
                null, null, false, false, false, false);
            AtomicFile.WriteJson(Receipt, f);
            return f;
        }
    }

    /// <summary>
    /// Records an irreversible forward-DB hazard, never asserts restore is verified.
    /// The supplied hash is a report identity, NOT trusted proof of migration safety.
    /// </summary>
    public UniversalUpgradeFence ReportDatabaseForward(string operationId, string reportSha)
    {
        RequireSha(reportSha, "UNIVERSAL_FORWARD_RECEIPT_HASH_INVALID");
        lock (LocalUpdateOperationGate.Sync)
        {
            using var lease = MachineMutationLease.Acquire(_paths, "supervisor-universal-db-forward");
            var f = LoadValidated() ?? throw new InvalidDataException("UNIVERSAL_FENCE_MISSING");
            if (f.OperationId != operationId || f.Phase != Reserved)
                throw new InvalidOperationException("UNIVERSAL_FORWARD_TRANSITION_REFUSED");
            var blockers = _operations.ListBlocking();
            if (blockers.Count != 1 || blockers[0].OperationId != operationId)
                throw new InvalidOperationException("UNIVERSAL_CONCURRENT_OPERATION_UNRECONCILED");
            // Persist blocking state before writing the follow-up receipt;
            // any crash between these two writes stays blocked and visible.
            var op = _operations.Get(operationId)!;
            _operations.Update(op, "recovery_required", "UNIVERSAL_DB_FORWARD_REPORTED_UNQUALIFIED",
                activationPerformed: true, databaseMutationPerformed: true);
            var next = f with { Phase = Forward, LocalForwardReportSha256 = reportSha,
                DatabaseForwardReported = true, RecoveryRequired = true };
            AtomicFile.WriteJson(Receipt, next);
            return next;
        }
    }

    public UniversalUpgradeFence ReportPublicObserved(string operationId, string reportSha)
    {
        RequireSha(reportSha, "UNIVERSAL_PUBLIC_REPORT_HASH_INVALID");
        lock (LocalUpdateOperationGate.Sync)
        {
            using var lease = MachineMutationLease.Acquire(_paths, "supervisor-universal-public-observed");
            var f = LoadValidated() ?? throw new InvalidDataException("UNIVERSAL_FENCE_MISSING");
            if (f.OperationId != operationId || f.Phase != Forward)
                throw new InvalidOperationException("UNIVERSAL_PUBLIC_TRANSITION_REFUSED");
            var blockers = _operations.ListBlocking();
            if (blockers.Count != 1 || blockers[0].OperationId != operationId)
                throw new InvalidOperationException("UNIVERSAL_CONCURRENT_OPERATION_UNRECONCILED");
            var next = f with { Phase = PublicObserved, PublicReportSha256 = reportSha };
            AtomicFile.WriteJson(Receipt, next);
            return next;
        }
    }
}
