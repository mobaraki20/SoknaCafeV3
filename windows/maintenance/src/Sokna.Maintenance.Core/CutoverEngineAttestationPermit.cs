using System.Diagnostics;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using System.Text.Json.Serialization;

namespace Sokna.Maintenance.Core;

// F1-S7D5A: a REAL one-use Supervisor-to-Engine OS-bound delegation,
// but ONLY for the non-mutating "cutover-attest" command. No command here
// can stop Apache, move a junction, change SQL or grant panel activation.
// The Stage, WAL, installed release and receipt must all be rechecked at
// issue time AND in the independent Engine process immediately before
// returning evidence. Production mutation remains a separate closed gate.
public sealed record CutoverEngineAttestationReceipt(
    [property: JsonPropertyName("format")] string Format,
    [property: JsonPropertyName("permit_id")] string PermitId,
    [property: JsonPropertyName("transaction_id")] string TransactionId,
    [property: JsonPropertyName("stage_operation_id")] string StageOperationId,
    [property: JsonPropertyName("wal_sha256")] string WalSha256,
    [property: JsonPropertyName("installed_sha256")] string InstalledSha256,
    [property: JsonPropertyName("stage_receipt_sha256")] string StageReceiptSha256,
    [property: JsonPropertyName("release_set_id")] string ReleaseSetId,
    [property: JsonPropertyName("sequence")] long Sequence,
    [property: JsonPropertyName("command_sha256")] string CommandSha256,
    [property: JsonPropertyName("installation_sha256")] string InstallationSha256,
    [property: JsonPropertyName("parent_pid")] int ParentPid,
    [property: JsonPropertyName("parent_start_ticks")] long ParentStartTicks,
    [property: JsonPropertyName("issued_utc_ticks")] long IssuedUtcTicks,
    [property: JsonPropertyName("expires_utc_ticks")] long ExpiresUtcTicks,
    [property: JsonPropertyName("inspection_only")] bool InspectionOnly,
    [property: JsonPropertyName("execution_authorized")] bool ExecutionAuthorized,
    [property: JsonPropertyName("signature")] string Signature);

public static class CutoverEngineAttestationPermit
{
    public const string Format = "sokna-cutover-engine-attestation-v1";
    public const string Required = "CUTOVER_ATTESTATION_DELEGATION_REQUIRED";
    public const string Rejected = "CUTOVER_ATTESTATION_DELEGATION_REJECTED";
    private static readonly TimeSpan Lifetime = TimeSpan.FromSeconds(45);
    private const string Domain = "SOKNA-ENGINE-CUTOVER-ATTESTATION-ONLY-NO-MUTATION-V1\n";

    public static string Issue(MaintenancePaths paths, MachineMutationLease lease,
        string tx, string stageId, IReadOnlyList<string> exactArguments)
    {
        RequireSupervisorLease(lease);
        ValidateArguments(paths, tx, stageId, exactArguments);
        // This is the canonical S7D4B independently-signed staged artifact
        // witness, not the earlier synthetic S7D4A evidence-only fixture.
        var stageSeal = new LocalCutoverCanonicalStageSealGate(paths);
        var sealId = stageSeal.Issue(lease, tx);
        var sealedStage = stageSeal.InspectAndBurn(lease, sealId, tx);
        if (sealedStage.StageOperationId != stageId || sealedStage.ExecutionAuthorized)
            throw new InvalidDataException(Rejected);
        var e = Observe(paths, tx, stageId);
        using var parent = Process.GetCurrentProcess();
        var now = DateTimeOffset.UtcNow;
        var p = new CutoverEngineAttestationReceipt(
            Format, Guid.NewGuid().ToString("N"), tx, stageId,
            e.WalSha256, e.InstalledSha256, e.StageReceiptSha256,
            e.ReleaseSetId, e.Sequence, Hash(Encoding.UTF8.GetBytes(JsonSerializer.Serialize(exactArguments))),
            InstallHash(paths), Environment.ProcessId, parent.StartTime.ToUniversalTime().Ticks,
            now.UtcTicks, now.Add(Lifetime).UtcTicks,
            InspectionOnly: true, ExecutionAuthorized: false, Signature: "");
        p = p with { Signature = Sign(paths, p) };
        var root = SafeRoot(paths, create: true);
        AtomicFile.WriteJson(Path.Combine(root, p.PermitId + ".json"), p);
        return p.PermitId;
    }

    public static CutoverEngineAttestationReceipt Consume(MaintenancePaths paths,
        string permitId, string tx, string stageId, IReadOnlyList<string> exactArguments)
    {
        ValidateArguments(paths, tx, stageId, exactArguments);
        if (!ValidId(permitId)) throw new InvalidDataException(Rejected);
        var root = SafeRoot(paths);
        var file = Path.Combine(root, permitId + ".json");
        if (!File.Exists(file) || IsReparse(file)) throw new InvalidDataException(Rejected);
        var p = AtomicFile.ReadJson<CutoverEngineAttestationReceipt>(file);
        if (p is null || p.Format != Format || p.PermitId != permitId ||
            p.TransactionId != tx || p.StageOperationId != stageId ||
            !p.InspectionOnly || p.ExecutionAuthorized || p.ParentPid <= 0 ||
            p.ParentPid == Environment.ProcessId || p.ParentStartTicks <= 0 ||
            p.IssuedUtcTicks <= 0 || p.ExpiresUtcTicks - p.IssuedUtcTicks != Lifetime.Ticks ||
            DateTimeOffset.UtcNow.UtcTicks > p.ExpiresUtcTicks ||
            DateTimeOffset.UtcNow.UtcTicks < p.IssuedUtcTicks - TimeSpan.FromSeconds(5).Ticks ||
            p.CommandSha256 != Hash(Encoding.UTF8.GetBytes(JsonSerializer.Serialize(exactArguments))) ||
            p.InstallationSha256 != InstallHash(paths) || !ValidHash(p.Signature))
            throw new InvalidDataException(Rejected);
        var actual = Convert.FromHexString(p.Signature);
        var expected = Convert.FromHexString(Sign(paths, p with { Signature = "" }));
        if (!CryptographicOperations.FixedTimeEquals(actual, expected))
            throw new InvalidDataException(Rejected);

        // The parent must really be alive and own an exclusive OS-held lock.
        EngineDelegationPermit.RequireParentAndMachineLease(paths,
            p.ParentPid, p.ParentStartTicks, LocalCodeCutoverWriteAheadJournal.Owner);
        RequireMatch(p, Observe(paths, tx, stageId));
        var consumed = Path.Combine(root, permitId + ".consumed");
        if (File.Exists(consumed)) throw new InvalidDataException(Rejected);
        try
        {
            using var marker = new FileStream(consumed, FileMode.CreateNew,
                FileAccess.Write, FileShare.None, 4096, FileOptions.WriteThrough);
            var bytes = Encoding.ASCII.GetBytes(
                $"pid={Environment.ProcessId};utc={DateTimeOffset.UtcNow:O}");
            marker.Write(bytes);
            marker.Flush(flushToDisk: true);
        }
        catch (IOException) { throw new InvalidDataException(Rejected); }
        // Fail-closed: a crash between the two checks permanently burns the
        // permit; no silent resumption or second mutation opportunity.
        EngineDelegationPermit.RequireParentAndMachineLease(paths,
            p.ParentPid, p.ParentStartTicks, LocalCodeCutoverWriteAheadJournal.Owner);
        RequireMatch(p, Observe(paths, tx, stageId));
        return p;
    }

    private sealed record Observed(
        string WalSha256, string InstalledSha256, string StageReceiptSha256,
        string ReleaseSetId, long Sequence);

    private static Observed Observe(MaintenancePaths paths, string tx, string stageId)
    {
        var wal = new LocalCodeCutoverWriteAheadJournal(paths).ReadVerified(tx);
        if (wal.StageOperationId != stageId || wal.Sequence != 1 ||
            wal.Phase != "intent_created" || !wal.DryRunOnly || wal.ExecutionAuthorized)
            throw new InvalidDataException(Rejected);
        // REUSE full verified signed package and staged artifact inspection:
        // preflight, exact baseline, anti-rollback, all staged artifact hashes.
        var stage = SupervisorLocalStageRecoveryCoordinator
            .InspectSignedStageReadOnly(paths, stageId);
        if (!stage.StageEvidenceVerified || stage.ReleaseType != "local_only" ||
            stage.ActivationAllowed || stage.PanelUpdateReady)
            throw new InvalidDataException(Rejected);
        var installed = paths.InstalledReleaseIdentityPath;
        if (!File.Exists(installed) || IsReparse(installed)) throw new InvalidDataException(Rejected);
        var installedHash = Hash(File.ReadAllBytes(installed));
        if (installedHash != wal.InstalledIdentitySha256)
            throw new InvalidDataException(Rejected);
        var operation = new MaintenanceOperationStore(paths).Get(stageId);
        if (operation is null || operation.OperationType != "update.local-stage" ||
            operation.State != "staged" || string.IsNullOrWhiteSpace(operation.StageReceiptPath))
            throw new InvalidDataException(Rejected);
        var stageFile = Path.GetFullPath(operation.StageReceiptPath);
        if (!File.Exists(stageFile) || IsReparse(stageFile))
            throw new InvalidDataException(Rejected);
        return new(wal.EventSha256, installedHash, Hash(File.ReadAllBytes(stageFile)),
            stage.ReleaseSetId, stage.Sequence);
    }

    private static void RequireMatch(CutoverEngineAttestationReceipt p, Observed x)
    {
        if (p.WalSha256 != x.WalSha256 || p.InstalledSha256 != x.InstalledSha256 ||
            p.StageReceiptSha256 != x.StageReceiptSha256 ||
            p.ReleaseSetId != x.ReleaseSetId || p.Sequence != x.Sequence)
            throw new InvalidDataException(Rejected);
    }
    public static void ValidateArguments(MaintenancePaths paths, string tx,
        string stageId, IReadOnlyList<string> args)
    {
        if (!ValidId(tx) || !ValidId(stageId) || args.Count != 7 ||
            args[0] != "cutover-attest" || args[1] != "--transaction-id" ||
            args[2] != tx || args[3] != "--stage-operation-id" ||
            args[4] != stageId || args[5] != "--data-root" ||
            !string.Equals(Path.GetFullPath(args[6]),
                Path.GetFullPath(paths.DataRoot), StringComparison.OrdinalIgnoreCase))
            throw new InvalidDataException(Rejected);
    }

    private static string SafeRoot(MaintenancePaths paths, bool create = false)
    {
        var parent = Path.GetFullPath(paths.CodeCutoverJournalRoot);
        if (!Directory.Exists(parent) || IsReparse(parent))
            throw new InvalidDataException(Rejected);
        var root = Path.Combine(parent, "_engine_attestation_delegations");
        if (create) Directory.CreateDirectory(root);
        if (!Directory.Exists(root) || IsReparse(root))
            throw new InvalidDataException(Rejected);
        return root;
    }
    private static void RequireSupervisorLease(MachineMutationLease? lease)
    {
        if (lease is null || lease.IsDisposed ||
            lease.ProcessId != Environment.ProcessId ||
            lease.Owner != LocalCodeCutoverWriteAheadJournal.Owner)
            throw new InvalidOperationException("CUTOVER_SUPERVISOR_MACHINE_LEASE_REQUIRED");
    }
    private static string InstallHash(MaintenancePaths paths) =>
        Hash(Encoding.UTF8.GetBytes(Path.GetFullPath(paths.DataRoot).ToUpperInvariant()));
    private static string Sign(MaintenancePaths paths, CutoverEngineAttestationReceipt p)
    {
        var message = Encoding.UTF8.GetBytes(Domain +
            JsonSerializer.Serialize(p with { Signature = "" }));
        return HashHmac(new SecretStore(paths).LoadKey("engine-delegation"), message);
    }
    private static string Hash(byte[] bytes) =>
        Convert.ToHexString(SHA256.HashData(bytes)).ToLowerInvariant();
    private static string HashHmac(byte[] key, byte[] bytes) =>
        Convert.ToHexString(HMACSHA256.HashData(key, bytes)).ToLowerInvariant();
    private static bool IsReparse(string path) =>
        (File.GetAttributes(path) & FileAttributes.ReparsePoint) != 0;
    private static bool ValidId(string? x) =>
        x is { Length: 32 } && x.All(c => c is >= '0' and <= '9' or >= 'a' and <= 'f');
    private static bool ValidHash(string? x) =>
        x is { Length: 64 } && x.All(c => c is >= '0' and <= '9' or >= 'a' and <= 'f');
}
