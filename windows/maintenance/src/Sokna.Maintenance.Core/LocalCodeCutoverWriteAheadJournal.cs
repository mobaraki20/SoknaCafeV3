using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using System.Text.Json.Serialization;

namespace Sokna.Maintenance.Core;

/// <summary>
/// F1-S7D1: durable, tamper-evident WRITE-AHEAD transaction protocol.
/// This component has NO method that stops Apache, swaps a junction, migrates
/// MariaDB, rolls back business data, invokes Public or permits deployment.
/// It must not be wired to an activation command until the separate S7D2
/// real-host executor, backup proof and failure matrix are qualified.
/// </summary>
public sealed record LocalCutoverJournalEvent(
    [property: JsonPropertyName("format")] string Format,
    [property: JsonPropertyName("schema_version")] int SchemaVersion,
    [property: JsonPropertyName("transaction_id")] string TransactionId,
    [property: JsonPropertyName("stage_operation_id")] string StageOperationId,
    [property: JsonPropertyName("sequence")] int Sequence,
    [property: JsonPropertyName("phase")] string Phase,
    [property: JsonPropertyName("previous_slot")] string PreviousSlot,
    [property: JsonPropertyName("target_slot")] string TargetSlot,
    [property: JsonPropertyName("installed_identity_sha256")] string InstalledIdentitySha256,
    [property: JsonPropertyName("evidence_sha256")] string EvidenceSha256,
    [property: JsonPropertyName("previous_event_sha256")] string PreviousEventSha256,
    [property: JsonPropertyName("event_sha256")] string EventSha256,
    [property: JsonPropertyName("created_at_utc")] DateTimeOffset CreatedAtUtc,
    [property: JsonPropertyName("dry_run_only")] bool DryRunOnly,
    [property: JsonPropertyName("execution_authorized")] bool ExecutionAuthorized);

public sealed record LocalCutoverRecoveryDecision(
    [property: JsonPropertyName("format")] string Format,
    [property: JsonPropertyName("transaction_id")] string TransactionId,
    [property: JsonPropertyName("last_phase")] string LastPhase,
    [property: JsonPropertyName("observed_pointer")] string ObservedPointer,
    [property: JsonPropertyName("decision")] string Decision,
    [property: JsonPropertyName("database_forward_only")] bool DatabaseForwardOnly,
    [property: JsonPropertyName("automatic_rollback_allowed")] bool AutomaticRollbackAllowed,
    [property: JsonPropertyName("activation_allowed")] bool ActivationAllowed,
    [property: JsonPropertyName("panel_update_ready")] bool PanelUpdateReady);

public sealed class LocalCodeCutoverWriteAheadJournal
{
    public const string Format = "sokna-local-cutover-wal-v1";
    public const string Owner = "supervisor-code-cutover";
    // AtomicFile.JsonOptions is indented; JSONL records MUST be single-line.
    private static readonly JsonSerializerOptions LineJson = new(AtomicFile.JsonOptions)
    {
        WriteIndented = false
    };
    private readonly MaintenancePaths _paths;

    public LocalCodeCutoverWriteAheadJournal(MaintenancePaths paths) => _paths = paths;

    public LocalCutoverJournalEvent Begin(
        MachineMutationLease lease, string transactionId, string stageOperationId,
        string previousSlot, string targetSlot, string installedIdentitySha256)
    {
        RequireLease(lease);
        if (!ValidId(transactionId) || !ValidId(stageOperationId) ||
            !ValidHash(installedIdentitySha256))
            throw new InvalidDataException("CUTOVER_JOURNAL_IDENTITY_INVALID");
        // Require BOTH physical, non-reparse sibling slots, never a Web link.
        var previous = Path.GetFullPath(previousSlot);
        var target = Path.GetFullPath(targetSlot);
        var parent = Path.GetDirectoryName(previous);
        if (string.IsNullOrWhiteSpace(parent) ||
            !string.Equals(parent, Path.GetDirectoryName(target), StringComparison.OrdinalIgnoreCase) ||
            string.Equals(previous, target, StringComparison.OrdinalIgnoreCase))
            throw new InvalidDataException("CUTOVER_JOURNAL_SLOTS_INVALID");
        ProductionLocalSlotMigrationBridge.RequireProductionSlotPath(previous, parent);
        ProductionLocalSlotMigrationBridge.RequireProductionSlotPath(target, parent);

        var root = SafeJournalRoot(create: true);
        var directory = Path.Combine(root, transactionId);
        if (Directory.Exists(directory) || File.Exists(directory))
            throw new InvalidOperationException("CUTOVER_JOURNAL_ALREADY_EXISTS_OR_INTERRUPTED");
        // A crash after CreateDirectory but before first durable event is an
        // explicitly quarantined incomplete transaction, NOT a clean retry.
        Directory.CreateDirectory(directory);
        var first = MakeEvent(transactionId, stageOperationId, 1,
            "intent_created", previous, target, installedIdentitySha256,
            new string('0', 64), new string('0', 64));
        AppendCreateNew(Path.Combine(directory, "events.jsonl"), first);
        return first;
    }

    public LocalCutoverJournalEvent Advance(MachineMutationLease lease,
        string transactionId, string nextPhase, string evidenceSha256)
    {
        RequireLease(lease);
        if (!ValidId(transactionId) || !ValidHash(evidenceSha256))
            throw new InvalidDataException("CUTOVER_JOURNAL_EVIDENCE_INVALID");
        var current = ReadVerified(transactionId);
        if (!Allowed(current.Phase, nextPhase))
            throw new InvalidOperationException("CUTOVER_JOURNAL_ILLEGAL_TRANSITION");
        if (current.Sequence >= 32)
            throw new InvalidOperationException("CUTOVER_JOURNAL_TOO_MANY_EVENTS");
        var next = MakeEvent(current.TransactionId, current.StageOperationId,
            current.Sequence + 1, nextPhase, current.PreviousSlot,
            current.TargetSlot, current.InstalledIdentitySha256,
            evidenceSha256, current.EventSha256);
        var file = EventFile(transactionId);
        using var stream = new FileStream(file, FileMode.Open, FileAccess.Write,
            FileShare.None, 4096, FileOptions.WriteThrough);
        // Never append after a partially written/tampered/corrupt record.
        stream.Seek(0, SeekOrigin.End);
        var bytes = Encoding.UTF8.GetBytes(JsonSerializer.Serialize(next, LineJson) + "\n");
        stream.Write(bytes);
        stream.Flush(flushToDisk: true);
        return next;
    }

    public LocalCutoverJournalEvent ReadVerified(string transactionId)
    {
        var path = EventFile(transactionId);
        if (!File.Exists(path) || (File.GetAttributes(path) & FileAttributes.ReparsePoint) != 0)
            throw new InvalidDataException("CUTOVER_JOURNAL_INCOMPLETE");
        if (new FileInfo(path).Length is <= 0 or > 300000)
            throw new InvalidDataException("CUTOVER_JOURNAL_UNBOUNDED");
        LocalCutoverJournalEvent? prior = null;
        int count = 0;
        foreach (var line in File.ReadLines(path))
        {
            count++;
            if (count > 32 || line.Length > 10000)
                throw new InvalidDataException("CUTOVER_JOURNAL_UNBOUNDED");
            LocalCutoverJournalEvent item;
            try { item = JsonSerializer.Deserialize<LocalCutoverJournalEvent>(line, AtomicFile.JsonOptions)
                ?? throw new InvalidDataException("CUTOVER_JOURNAL_CORRUPT"); }
            catch (JsonException) { throw new InvalidDataException("CUTOVER_JOURNAL_CORRUPT"); }
            if (item.Format != Format || item.SchemaVersion != 1 ||
                item.TransactionId != transactionId || !ValidId(item.StageOperationId) ||
                item.Sequence != count || item.DryRunOnly != true || item.ExecutionAuthorized ||
                !ValidHash(item.InstalledIdentitySha256) ||
                !ValidHash(item.EventSha256) || !ValidHash(item.PreviousEventSha256) ||
                !ValidHash(item.EvidenceSha256) ||
                item.EventSha256 != HashEvent(item))
                throw new InvalidDataException("CUTOVER_JOURNAL_TAMPERED");
            if (prior is null)
            {
                if (item.Phase != "intent_created" ||
                    item.PreviousEventSha256 != new string('0', 64))
                    throw new InvalidDataException("CUTOVER_JOURNAL_START_INVALID");
                // Re-validate paths read from disk before decisions.
                var a = Path.GetFullPath(item.PreviousSlot);
                var b = Path.GetFullPath(item.TargetSlot);
                var root = Path.GetDirectoryName(a)
                    ?? throw new InvalidDataException("CUTOVER_JOURNAL_SLOTS_INVALID");
                if (string.Equals(a, b, StringComparison.OrdinalIgnoreCase) ||
                    !string.Equals(root, Path.GetDirectoryName(b), StringComparison.OrdinalIgnoreCase))
                    throw new InvalidDataException("CUTOVER_JOURNAL_SLOTS_INVALID");
                ProductionLocalSlotMigrationBridge.RequireProductionSlotPath(a, root);
                ProductionLocalSlotMigrationBridge.RequireProductionSlotPath(b, root);
            }
            else if (item.PreviousEventSha256 != prior.EventSha256 ||
                item.StageOperationId != prior.StageOperationId ||
                item.PreviousSlot != prior.PreviousSlot || item.TargetSlot != prior.TargetSlot ||
                item.InstalledIdentitySha256 != prior.InstalledIdentitySha256 ||
                !Allowed(prior.Phase, item.Phase))
                throw new InvalidDataException("CUTOVER_JOURNAL_CHAIN_INVALID");
            prior = item;
        }
        return prior ?? throw new InvalidDataException("CUTOVER_JOURNAL_INCOMPLETE");
    }

    /// <summary>
    /// Read-only crash classifier. Physical pointer is OBSERVED by caller,
    /// never supplied as a proof of Apache health or DB status. No automatic
    /// rollback, even when previous pointer appears intact.
    /// </summary>
    public LocalCutoverRecoveryDecision Classify(string transactionId, string? observedPhysicalPointer)
    {
        var last = ReadVerified(transactionId);
        var observed = "unknown";
        if (!string.IsNullOrWhiteSpace(observedPhysicalPointer))
        {
            var full = Path.GetFullPath(observedPhysicalPointer);
            if (string.Equals(full, last.PreviousSlot, StringComparison.OrdinalIgnoreCase))
                observed = "previous";
            else if (string.Equals(full, last.TargetSlot, StringComparison.OrdinalIgnoreCase))
                observed = "target";
            else observed = "unexpected";
        }
        // A crash after the DB mutation INTENT leaves its effects unproven.
        // Classify even this boundary as forward-only until independent DB evidence.
        var forwardOnly = last.Phase is "db_forward_intent" or "db_forward_committed" or
            "forward_recovery_required" or "committed";
        var decision = forwardOnly ? "FORWARD_ONLY_RECOVERY_REQUIRED"
            : observed == "previous" ? "VERIFY_PREVIOUS_HTTP_AND_SCHEMA_BEFORE_RESUME"
            : observed == "target" ? "PRE_DB_TARGET_STATE_REQUIRES_RECONCILIATION"
            : "UNKNOWN_POINTER_RECOVERY_REQUIRED";
        return new LocalCutoverRecoveryDecision("sokna-local-cutover-recovery-decision-v1",
            transactionId, last.Phase, observed, decision, forwardOnly,
            AutomaticRollbackAllowed: false, ActivationAllowed: false, PanelUpdateReady: false);
    }

    private string SafeJournalRoot(bool create = false)
    {
        var root = Path.GetFullPath(_paths.CodeCutoverJournalRoot);
        if (Directory.Exists(root) && (File.GetAttributes(root) & FileAttributes.ReparsePoint) != 0)
            throw new InvalidDataException("CUTOVER_JOURNAL_REPARSE_ROOT");
        if (create) Directory.CreateDirectory(root);
        if (!Directory.Exists(root))
            throw new InvalidDataException("CUTOVER_JOURNAL_NOT_FOUND");
        return root;
    }

    private string EventFile(string id)
    {
        if (!ValidId(id)) throw new InvalidDataException("CUTOVER_JOURNAL_ID_INVALID");
        var directory = Path.Combine(SafeJournalRoot(), id);
        if (!Directory.Exists(directory) ||
            (File.GetAttributes(directory) & FileAttributes.ReparsePoint) != 0)
            throw new InvalidDataException("CUTOVER_JOURNAL_INCOMPLETE");
        return Path.Combine(directory, "events.jsonl");
    }

    private static void AppendCreateNew(string path, LocalCutoverJournalEvent item)
    {
        using var stream = new FileStream(path, FileMode.CreateNew, FileAccess.Write,
            FileShare.None, 4096, FileOptions.WriteThrough);
        var bytes = Encoding.UTF8.GetBytes(JsonSerializer.Serialize(item, LineJson) + "\n");
        stream.Write(bytes);
        stream.Flush(flushToDisk: true);
    }

    private static LocalCutoverJournalEvent MakeEvent(string id, string stage, int seq, string phase,
        string previous, string target, string installedHash, string evidenceHash, string priorHash)
    {
        var value = new LocalCutoverJournalEvent(Format, 1, id, stage, seq, phase,
            previous, target, installedHash, evidenceHash, priorHash,
            "", DateTimeOffset.UtcNow, DryRunOnly: true, ExecutionAuthorized: false);
        return value with { EventSha256 = HashEvent(value) };
    }
    private static string HashEvent(LocalCutoverJournalEvent e)
    {
        var canonical = JsonSerializer.Serialize(e with { EventSha256 = "" }, LineJson);
        return Convert.ToHexString(SHA256.HashData(Encoding.UTF8.GetBytes(canonical))).ToLowerInvariant();
    }
    private static bool ValidHash(string? value) =>
        value is { Length: 64 } && value.All(char.IsAsciiHexDigit) && value == value.ToLowerInvariant();
    private static bool ValidId(string? value) =>
        value is { Length: 32 } && value.All(char.IsAsciiHexDigit) && value == value.ToLowerInvariant();
    private static void RequireLease(MachineMutationLease? lease)
    {
        if (lease is null || lease.IsDisposed || lease.ProcessId != Environment.ProcessId ||
            lease.Owner != Owner)
            throw new InvalidOperationException("CUTOVER_SUPERVISOR_MACHINE_LEASE_REQUIRED");
    }

    private static bool Allowed(string before, string after) => (before, after) switch
    {
        ("intent_created", "apache_stop_intent") => true,
        ("apache_stop_intent", "apache_stop_observed") => true,
        ("apache_stop_observed", "pointer_switch_intent") => true,
        ("pointer_switch_intent", "pointer_switch_observed") => true,
        ("pointer_switch_observed", "target_http_observed") => true,
        ("target_http_observed", "db_forward_intent") => true,
        ("db_forward_intent", "db_forward_committed") => true,
        ("db_forward_committed", "committed") => true,
        ("db_forward_committed", "forward_recovery_required") => true,
        ("intent_created", "pre_db_rollback_intent") => true,
        ("apache_stop_intent", "pre_db_rollback_intent") => true,
        ("apache_stop_observed", "pre_db_rollback_intent") => true,
        ("pointer_switch_intent", "pre_db_rollback_intent") => true,
        ("pointer_switch_observed", "pre_db_rollback_intent") => true,
        ("target_http_observed", "pre_db_rollback_intent") => true,
        ("pre_db_rollback_intent", "previous_pointer_observed") => true,
        ("previous_pointer_observed", "previous_http_observed") => true,
        _ => false
    };
}
