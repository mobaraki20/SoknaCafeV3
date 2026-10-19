using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using System.Text.Json.Serialization;

namespace Sokna.Maintenance.Core;

/// <summary>
/// F1-S7D4B: a signed canonical-stage evidence SEAL, not an Engine permit.
/// No executable command, no Apache call, no data migration or panel unlock.
/// Unlike S7D4A's synthetic-stage witness, issuance now REQUIRES full signed
/// Release Set/stage artifact/high-water mark re-verification by the canonical
/// Supervisor recovery inspector. An executor MUST NOT treat it as permission.
/// </summary>
public sealed record CanonicalCutoverStageSeal(
    [property: JsonPropertyName("format")] string Format,
    [property: JsonPropertyName("seal_id")] string SealId,
    [property: JsonPropertyName("transaction_id")] string TransactionId,
    [property: JsonPropertyName("stage_operation_id")] string StageOperationId,
    [property: JsonPropertyName("wal_event_sha256")] string WalEventSha256,
    [property: JsonPropertyName("installed_identity_sha256")] string InstalledIdentitySha256,
    [property: JsonPropertyName("stage_receipt_sha256")] string StageReceiptSha256,
    [property: JsonPropertyName("release_set_id")] string ReleaseSetId,
    [property: JsonPropertyName("sequence")] long Sequence,
    [property: JsonPropertyName("installation_sha256")] string InstallationSha256,
    [property: JsonPropertyName("issued_utc_ticks")] long IssuedUtcTicks,
    [property: JsonPropertyName("expires_utc_ticks")] long ExpiresUtcTicks,
    [property: JsonPropertyName("canonical_signed_stage_verified")] bool CanonicalSignedStageVerified,
    [property: JsonPropertyName("execution_authorized")] bool ExecutionAuthorized,
    [property: JsonPropertyName("panel_update_ready")] bool PanelUpdateReady,
    [property: JsonPropertyName("signature")] string Signature);

public sealed class LocalCutoverCanonicalStageSealGate
{
    public const string Format = "sokna-cutover-canonical-stage-seal-v1";
    public const string Rejected = "CUTOVER_CANONICAL_STAGE_SEAL_REJECTED";
    private static readonly TimeSpan Lifetime = TimeSpan.FromSeconds(45);
    private readonly MaintenancePaths _paths;
    public LocalCutoverCanonicalStageSealGate(MaintenancePaths paths) => _paths = paths;

    public string Issue(MachineMutationLease lease, string transactionId)
    {
        RequireLease(lease);
        var (wal, receiptHash, inspection) = InspectSource(transactionId);
        var now = DateTimeOffset.UtcNow;
        var seal = new CanonicalCutoverStageSeal(
            Format, Guid.NewGuid().ToString("N"), transactionId, wal.StageOperationId,
            wal.EventSha256, wal.InstalledIdentitySha256, receiptHash,
            inspection.ReleaseSetId, inspection.Sequence, InstallationHash(),
            now.UtcTicks, now.Add(Lifetime).UtcTicks,
            CanonicalSignedStageVerified: true,
            ExecutionAuthorized: false, PanelUpdateReady: false, Signature: "");
        seal = seal with { Signature = Sign(seal) };
        var folder = SafeRoot(create: true);
        AtomicFile.WriteJson(Path.Combine(folder, seal.SealId + ".json"), seal);
        return seal.SealId;
    }

    /// <summary>Consumes one sealed inspection. NEVER returns a grant or a runnable command.</summary>
    public CanonicalCutoverStageSeal InspectAndBurn(
        MachineMutationLease lease, string sealId, string transactionId)
    {
        RequireLease(lease);
        if (!ValidId(sealId) || !ValidId(transactionId)) throw new InvalidDataException(Rejected);
        var folder = SafeRoot();
        var path = Path.Combine(folder, sealId + ".json");
        if (!File.Exists(path) || Reparse(path)) throw new InvalidDataException(Rejected);
        var seal = AtomicFile.ReadJson<CanonicalCutoverStageSeal>(path);
        if (seal is null || seal.Format != Format || seal.SealId != sealId ||
            seal.TransactionId != transactionId || !seal.CanonicalSignedStageVerified ||
            seal.ExecutionAuthorized || seal.PanelUpdateReady ||
            seal.IssuedUtcTicks <= 0 ||
            seal.ExpiresUtcTicks - seal.IssuedUtcTicks != Lifetime.Ticks ||
            DateTimeOffset.UtcNow.UtcTicks > seal.ExpiresUtcTicks ||
            DateTimeOffset.UtcNow.UtcTicks < seal.IssuedUtcTicks - TimeSpan.FromSeconds(5).Ticks ||
            !ValidHash(seal.Signature))
            throw new InvalidDataException(Rejected);
        var actual = Convert.FromHexString(seal.Signature);
        var expected = Convert.FromHexString(Sign(seal with { Signature = "" }));
        if (!CryptographicOperations.FixedTimeEquals(actual, expected))
            throw new InvalidDataException(Rejected);
        var (wal, receiptHash, inspection) = InspectSource(transactionId);
        if (seal.StageOperationId != wal.StageOperationId ||
            seal.WalEventSha256 != wal.EventSha256 ||
            seal.InstalledIdentitySha256 != wal.InstalledIdentitySha256 ||
            seal.StageReceiptSha256 != receiptHash ||
            seal.ReleaseSetId != inspection.ReleaseSetId ||
            seal.Sequence != inspection.Sequence ||
            seal.InstallationSha256 != InstallationHash())
            throw new InvalidDataException(Rejected);
        var used = Path.Combine(folder, sealId + ".consumed");
        if (File.Exists(used)) throw new InvalidDataException(Rejected);
        try
        {
            using var stream = new FileStream(used, FileMode.CreateNew,
                FileAccess.Write, FileShare.None, 4096, FileOptions.WriteThrough);
            var bytes = Encoding.ASCII.GetBytes("observed=" + DateTimeOffset.UtcNow.ToString("O"));
            stream.Write(bytes);
            stream.Flush(flushToDisk: true);
        }
        catch (IOException) { throw new InvalidDataException(Rejected); }
        return seal;
    }

    private (LocalCutoverJournalEvent Wal, string ReceiptHash, LocalActivationReadiness Inspection)
        InspectSource(string tx)
    {
        if (!ValidId(tx)) throw new InvalidDataException(Rejected);
        var wal = new LocalCodeCutoverWriteAheadJournal(_paths).ReadVerified(tx);
        if (wal.Sequence != 1 || wal.Phase != "intent_created" ||
            wal.ExecutionAuthorized || !wal.DryRunOnly)
            throw new InvalidDataException(Rejected);
        var installed = _paths.InstalledReleaseIdentityPath;
        if (!File.Exists(installed) || Reparse(installed) ||
            !HashFile(installed).Equals(wal.InstalledIdentitySha256, StringComparison.Ordinal))
            throw new InvalidDataException(Rejected);
        // Reuse exact canonical full-trust stage recovery inspection. This
        // verifies signed ZIP, immutable artifacts, preflight, stage receipt,
        // anti-rollback high-water mark and installed baseline independently.
        var inspection = SupervisorLocalStageRecoveryCoordinator
            .InspectSignedStageReadOnly(_paths, wal.StageOperationId);
        if (!inspection.StageEvidenceVerified || inspection.ActivationAllowed ||
            inspection.PanelUpdateReady || inspection.ReleaseType != "local_only")
            throw new InvalidDataException(Rejected);
        var op = new MaintenanceOperationStore(_paths).Get(wal.StageOperationId)
            ?? throw new InvalidDataException(Rejected);
        if (op.State != "staged" || op.OperationType != "update.local-stage" ||
            string.IsNullOrEmpty(op.StageReceiptPath))
            throw new InvalidDataException(Rejected);
        var receipt = Path.GetFullPath(op.StageReceiptPath);
        if (!File.Exists(receipt) || Reparse(receipt))
            throw new InvalidDataException(Rejected);
        var receiptHash = HashFile(receipt);
        if (HashFile(installed) != wal.InstalledIdentitySha256)
            throw new InvalidDataException(Rejected);
        return (wal, receiptHash, inspection);
    }

    private string SafeRoot(bool create = false)
    {
        var parent = Path.GetFullPath(_paths.CodeCutoverJournalRoot);
        if (!Directory.Exists(parent) || Reparse(parent))
            throw new InvalidDataException(Rejected);
        var root = Path.Combine(parent, "_canonical_stage_seals");
        if (create) Directory.CreateDirectory(root);
        if (!Directory.Exists(root) || Reparse(root))
            throw new InvalidDataException(Rejected);
        return root;
    }
    private static void RequireLease(MachineMutationLease? lease)
    {
        if (lease is null || lease.IsDisposed ||
            lease.ProcessId != Environment.ProcessId ||
            lease.Owner != LocalCodeCutoverWriteAheadJournal.Owner)
            throw new InvalidOperationException("CUTOVER_SUPERVISOR_MACHINE_LEASE_REQUIRED");
    }
    private string InstallationHash() => Hex(SHA256.HashData(Encoding.UTF8.GetBytes(
        Path.GetFullPath(_paths.DataRoot).ToUpperInvariant())));
    private string Sign(CanonicalCutoverStageSeal value)
    {
        var bytes = Encoding.UTF8.GetBytes("SOKNA-CANONICAL-SIGNED-STAGE-EVIDENCE-NOT-PERMIT-V1\n" +
            JsonSerializer.Serialize(value with { Signature = "" },
                new JsonSerializerOptions { WriteIndented = false }));
        return Hex(HMACSHA256.HashData(
            new SecretStore(_paths).LoadKey("engine-delegation"), bytes));
    }
    private static string HashFile(string p)
    {
        using var stream = File.OpenRead(p);
        return Hex(SHA256.HashData(stream));
    }
    private static string Hex(byte[] data) => Convert.ToHexString(data).ToLowerInvariant();
    private static bool Reparse(string p) =>
        (File.GetAttributes(p) & FileAttributes.ReparsePoint) != 0;
    private static bool ValidId(string? id) => id is { Length: 32 } &&
        id.All(char.IsAsciiHexDigit) && id == id.ToLowerInvariant();
    private static bool ValidHash(string? hash) => hash is { Length: 64 } &&
        hash.All(char.IsAsciiHexDigit) && hash == hash.ToLowerInvariant();
}
