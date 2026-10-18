using System.Diagnostics;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using System.Text.Json.Serialization;

namespace Sokna.Maintenance.Core;

/// <summary>
/// F1-S7D4A: a signed, single-use, EVIDENCE-ONLY cutover preauthorization
/// snapshot. This is NOT an executable Engine delegation, real stage
/// qualification, Apache operation, DB permission or UI activation.
/// The eventual executor MUST separately validate canonical stage trust,
/// backup/restore, runtime host identity, release set and authority.
/// </summary>
public sealed record LocalCutoverPreauthorizationEvidence(
    [property: JsonPropertyName("format")] string Format,
    [property: JsonPropertyName("permit_id")] string PermitId,
    [property: JsonPropertyName("transaction_id")] string TransactionId,
    [property: JsonPropertyName("stage_operation_id")] string StageOperationId,
    [property: JsonPropertyName("wal_first_event_sha256")] string WalFirstEventSha256,
    [property: JsonPropertyName("installed_identity_sha256")] string InstalledIdentitySha256,
    [property: JsonPropertyName("stage_receipt_sha256")] string StageReceiptSha256,
    [property: JsonPropertyName("previous_slot")] string PreviousSlot,
    [property: JsonPropertyName("target_slot")] string TargetSlot,
    [property: JsonPropertyName("installation_sha256")] string InstallationSha256,
    [property: JsonPropertyName("issued_utc_ticks")] long IssuedUtcTicks,
    [property: JsonPropertyName("expires_utc_ticks")] long ExpiresUtcTicks,
    [property: JsonPropertyName("dry_run_only")] bool DryRunOnly,
    [property: JsonPropertyName("execution_authorized")] bool ExecutionAuthorized,
    [property: JsonPropertyName("signature")] string Signature);

public sealed class LocalCutoverPreauthorizationGate
{
    public const string Format = "sokna-local-cutover-preauth-evidence-v1";
    public const string Rejected = "CUTOVER_PREAUTH_EVIDENCE_REJECTED";
    private readonly MaintenancePaths _paths;
    private static readonly TimeSpan Lifetime = TimeSpan.FromSeconds(45);

    public LocalCutoverPreauthorizationGate(MaintenancePaths paths) => _paths = paths;

    public string Issue(MachineMutationLease lease, string transactionId)
    {
        RequireLease(lease);
        var (wal, stageHash) = ValidateSource(transactionId);
        if (wal.Sequence != 1 || wal.Phase != "intent_created")
            throw new InvalidDataException(Rejected);
        var now = DateTimeOffset.UtcNow;
        var item = new LocalCutoverPreauthorizationEvidence(
            Format, Convert.ToHexString(RandomNumberGenerator.GetBytes(16)).ToLowerInvariant(),
            transactionId, wal.StageOperationId, wal.EventSha256,
            wal.InstalledIdentitySha256, stageHash, wal.PreviousSlot, wal.TargetSlot,
            Sha256(Encoding.UTF8.GetBytes(Path.GetFullPath(_paths.DataRoot).ToUpperInvariant())),
            now.UtcTicks, now.Add(Lifetime).UtcTicks, true, false, "");
        item = item with { Signature = Signature(item) };
        var root = SafeRoot(create: true);
        var path = Path.Combine(root, item.PermitId + ".json");
        AtomicFile.WriteJson(path, item);
        return item.PermitId;
    }

    /// <summary>
    /// Consumes the witness ONCE while Supervisor retains its live lease.
    /// Returns evidence ONLY; no boolean authorization / operational permit.
    /// </summary>
    public LocalCutoverPreauthorizationEvidence InspectAndBurn(
        MachineMutationLease lease, string permitId, string transactionId)
    {
        RequireLease(lease);
        if (!ValidId(permitId) || !ValidId(transactionId))
            throw new InvalidDataException(Rejected);
        var root = SafeRoot();
        var path = Path.Combine(root, permitId + ".json");
        if (!File.Exists(path) || IsReparse(path)) throw new InvalidDataException(Rejected);
        var evidence = AtomicFile.ReadJson<LocalCutoverPreauthorizationEvidence>(path);
        if (evidence is null || evidence.Format != Format ||
            evidence.PermitId != permitId || evidence.TransactionId != transactionId ||
            !evidence.DryRunOnly || evidence.ExecutionAuthorized ||
            evidence.ExpiresUtcTicks - evidence.IssuedUtcTicks != Lifetime.Ticks ||
            DateTimeOffset.UtcNow.UtcTicks > evidence.ExpiresUtcTicks ||
            DateTimeOffset.UtcNow.UtcTicks < evidence.IssuedUtcTicks - TimeSpan.FromSeconds(5).Ticks ||
            !ValidHash(evidence.Signature))
            throw new InvalidDataException(Rejected);
        byte[] actual;
        try { actual = Convert.FromHexString(evidence.Signature); }
        catch (FormatException) { throw new InvalidDataException(Rejected); }
        var expected = Convert.FromHexString(Signature(evidence with { Signature = "" }));
        if (!CryptographicOperations.FixedTimeEquals(actual, expected))
            throw new InvalidDataException(Rejected);

        var (wal, stageHash) = ValidateSource(transactionId);
        if (wal.Sequence != 1 || wal.Phase != "intent_created" ||
            evidence.WalFirstEventSha256 != wal.EventSha256 ||
            evidence.StageOperationId != wal.StageOperationId ||
            evidence.InstalledIdentitySha256 != wal.InstalledIdentitySha256 ||
            evidence.StageReceiptSha256 != stageHash ||
            evidence.PreviousSlot != wal.PreviousSlot || evidence.TargetSlot != wal.TargetSlot ||
            evidence.InstallationSha256 != Sha256(Encoding.UTF8.GetBytes(
                Path.GetFullPath(_paths.DataRoot).ToUpperInvariant())))
            throw new InvalidDataException(Rejected);
        var marker = Path.Combine(root, permitId + ".consumed");
        if (File.Exists(marker))
            throw new InvalidDataException(Rejected);
        try
        {
            using var stream = new FileStream(marker, FileMode.CreateNew, FileAccess.Write,
                FileShare.None, 4096, FileOptions.WriteThrough);
            var bytes = Encoding.UTF8.GetBytes("consumed_utc=" + DateTimeOffset.UtcNow.ToString("O"));
            stream.Write(bytes);
            stream.Flush(flushToDisk: true);
        }
        catch (IOException) { throw new InvalidDataException(Rejected); }
        return evidence;
    }

    private (LocalCutoverJournalEvent wal, string stageHash) ValidateSource(string tx)
    {
        if (!ValidId(tx)) throw new InvalidDataException(Rejected);
        var wal = new LocalCodeCutoverWriteAheadJournal(_paths).ReadVerified(tx);
        var installedPath = Path.GetFullPath(_paths.InstalledReleaseIdentityPath);
        if (!File.Exists(installedPath) || IsReparse(installedPath) ||
            new FileInfo(installedPath).Length is <= 0 or > 100000 ||
            wal.InstalledIdentitySha256 != Sha256(File.ReadAllBytes(installedPath)))
            throw new InvalidDataException(Rejected);
        var op = new MaintenanceOperationStore(_paths).Get(wal.StageOperationId);
        if (op is null || op.OperationType != "update.local-stage" || op.State != "staged" ||
            op.ActivationPerformed || op.DatabaseMutationPerformed ||
            string.IsNullOrWhiteSpace(op.StageReceiptPath))
            throw new InvalidDataException(Rejected);
        var stagePath = Path.GetFullPath(op.StageReceiptPath);
        var stageRoot = Path.GetFullPath(_paths.StagingRoot);
        var stageDirectory = Path.GetDirectoryName(stagePath);
        if (stageDirectory is null || Path.GetFileName(stagePath) != "stage-receipt.json" ||
            !string.Equals(Path.GetDirectoryName(stageDirectory), stageRoot,
                StringComparison.OrdinalIgnoreCase) ||
            !Directory.Exists(stageDirectory) || IsReparse(stageDirectory) ||
            !File.Exists(stagePath) || IsReparse(stagePath) ||
            new FileInfo(stagePath).Length is <= 0 or > 100000)
            throw new InvalidDataException(Rejected);
        // NOTE: only a byte-exact binding, not signed release-stage validation.
        // A future independent inspector must prove stage provenance before
        // production activation. This contract must remain non-executable.
        return (wal, Sha256(File.ReadAllBytes(stagePath)));
    }

    private string SafeRoot(bool create = false)
    {
        var root = Path.GetFullPath(Path.Combine(_paths.CodeCutoverJournalRoot,
            "_preauthorization_evidence"));
        if (create)
        {
            if (!Directory.Exists(_paths.CodeCutoverJournalRoot))
                throw new InvalidDataException(Rejected);
            Directory.CreateDirectory(root);
        }
        if (!Directory.Exists(root) || IsReparse(root))
            throw new InvalidDataException(Rejected);
        return root;
    }
    private string Signature(LocalCutoverPreauthorizationEvidence e)
    {
        var key = new SecretStore(_paths).LoadKey("engine-delegation");
        var canonical = JsonSerializer.Serialize(e with { Signature = "" },
            new JsonSerializerOptions { WriteIndented = false });
        return Convert.ToHexString(HMACSHA256.HashData(key,
            Encoding.UTF8.GetBytes("SOKNA-CUTOVER-PREAUTH-EVIDENCE-ONLY-V1\n" + canonical)))
            .ToLowerInvariant();
    }
    private static void RequireLease(MachineMutationLease? lease)
    {
        if (lease is null || lease.IsDisposed ||
            lease.ProcessId != Environment.ProcessId ||
            lease.Owner != LocalCodeCutoverWriteAheadJournal.Owner)
            throw new InvalidOperationException("CUTOVER_SUPERVISOR_MACHINE_LEASE_REQUIRED");
    }
    private static bool ValidId(string? value) =>
        value is { Length: 32 } && value.All(char.IsAsciiHexDigit) &&
        value == value.ToLowerInvariant();
    private static bool ValidHash(string? value) =>
        value is { Length: 64 } && value.All(char.IsAsciiHexDigit) &&
        value == value.ToLowerInvariant();
    private static bool IsReparse(string path) =>
        (File.GetAttributes(path) & FileAttributes.ReparsePoint) != 0;
    private static string Sha256(byte[] bytes) =>
        Convert.ToHexString(SHA256.HashData(bytes)).ToLowerInvariant();
}
