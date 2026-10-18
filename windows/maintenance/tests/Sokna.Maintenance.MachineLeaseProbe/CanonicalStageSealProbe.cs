using System.Security.Cryptography;
using Sokna.Maintenance.Core;

// F1-S7D4B live-CI proof: the REAL signed canonical Stage created by
// p2d-signed-supervisor-preflight.ps1, not S7D4A's fake Stage.
// This still does not execute any activation, service or SQL mutation.
internal static class CanonicalStageSealProbe
{
    internal static int Run(string rootInput, string stageId)
    {
        if (!OperatingSystem.IsWindows() ||
            Environment.GetEnvironmentVariable("GITHUB_ACTIONS") != "true" ||
            !IsHex(stageId, 32))
            throw new InvalidDataException("S7D4B_SIGNED_STAGE_CI_ONLY");
        var root = Path.GetFullPath(rootInput);
        var runnerTemp = Path.TrimEndingDirectorySeparator(Path.GetFullPath(
            Environment.GetEnvironmentVariable("RUNNER_TEMP")
            ?? throw new InvalidDataException("S7D4B_NO_RUNNER_TEMP")));
        if (!string.Equals(Path.GetDirectoryName(root), runnerTemp, StringComparison.OrdinalIgnoreCase) ||
            !Path.GetFileName(root).StartsWith("sokna-f1-p2d-", StringComparison.OrdinalIgnoreCase))
            throw new InvalidDataException("S7D4B_PATH_NOT_P2D_CI");
        var paths = new MaintenancePaths(root);
        var inspector = SupervisorLocalStageRecoveryCoordinator
            .InspectSignedStageReadOnly(paths, stageId);
        Check(inspector.StageEvidenceVerified &&
              !inspector.ActivationAllowed && !inspector.PanelUpdateReady,
              "canonical Stage did not produce full signed evidence");

        var slots = Path.Combine(root, "s7d4b-verified-probe");
        var oldSlot = Path.Combine(slots, "Web.release.old");
        var newSlot = Path.Combine(slots, "Web.release.new");
        Directory.CreateDirectory(oldSlot);
        Directory.CreateDirectory(newSlot);
        File.WriteAllText(Path.Combine(oldSlot, "VERSION.txt"), "old-ci-fixture");
        File.WriteAllText(Path.Combine(newSlot, "VERSION.txt"), "new-ci-fixture");
        var oldHash = Hash(File.ReadAllBytes(Path.Combine(oldSlot,"VERSION.txt")));
        var newHash = Hash(File.ReadAllBytes(Path.Combine(newSlot,"VERSION.txt")));
        var installedHash = Hash(File.ReadAllBytes(paths.InstalledReleaseIdentityPath));
        var tx = Guid.NewGuid().ToString("N");
        using var lease = MachineMutationLease.Acquire(
            paths, LocalCodeCutoverWriteAheadJournal.Owner);
        var journal = new LocalCodeCutoverWriteAheadJournal(paths);
        journal.Begin(lease, tx, stageId, oldSlot, newSlot, installedHash);
        var gate = new LocalCutoverCanonicalStageSealGate(paths);
        var id = gate.Issue(lease, tx);
        var proof = gate.InspectAndBurn(lease, id, tx);
        Check(proof.CanonicalSignedStageVerified &&
              proof.StageOperationId == stageId &&
              proof.ReleaseSetId == inspector.ReleaseSetId &&
              proof.Sequence == inspector.Sequence &&
              proof.ExecutionAuthorized == false &&
              proof.PanelUpdateReady == false,
              "signed canonical Stage seal incorrectly became executable");
        Denied(() => gate.InspectAndBurn(lease, id, tx), "replay");

        var otherId = gate.Issue(lease, tx);
        Denied(() => gate.InspectAndBurn(lease, otherId,
            Guid.NewGuid().ToString("N")), "wrong-transaction");
        var sealPath = Path.Combine(paths.CodeCutoverJournalRoot,
            "_canonical_stage_seals", otherId + ".json");
        var original = File.ReadAllBytes(sealPath);
        try {
            var node = System.Text.Json.Nodes.JsonNode.Parse(File.ReadAllText(sealPath))
                ?? throw new InvalidDataException("S7D4B_NO_SEAL");
            node["execution_authorized"] = true;
            File.WriteAllText(sealPath, node.ToJsonString());
            Denied(() => gate.InspectAndBurn(lease, otherId, tx),
                "tampered-execution-flag");
        }
        finally { File.WriteAllBytes(sealPath, original); }

        var stageChangedId = gate.Issue(lease, tx);
        var stage = new MaintenanceOperationStore(paths).Get(stageId)
            ?? throw new InvalidDataException("S7D4B_STAGE_MISSING");
        var receiptPath = stage.StageReceiptPath
            ?? throw new InvalidDataException("S7D4B_STAGE_RECEIPT_MISSING");
        var bytesBefore = File.ReadAllBytes(receiptPath);
        try {
            File.AppendAllText(receiptPath, "unsafe");
            Denied(() => gate.InspectAndBurn(lease, stageChangedId, tx),
                "stage-receipt-tamper");
        }
        finally { File.WriteAllBytes(receiptPath, bytesBefore); }

        var advancedId = gate.Issue(lease, tx);
        journal.Advance(lease, tx, "apache_stop_intent", new string('a',64));
        Denied(() => gate.InspectAndBurn(lease, advancedId, tx),
            "wal-phase-changed");
        Check(Hash(File.ReadAllBytes(Path.Combine(oldSlot,"VERSION.txt"))) == oldHash &&
              Hash(File.ReadAllBytes(Path.Combine(newSlot,"VERSION.txt"))) == newHash,
              "synthetic immutable slots changed");
        Console.WriteLine(System.Text.Json.JsonSerializer.Serialize(new {
            format="sokna-f1-s7d4b-canonical-signed-stage-seal-ci-v1",
            result="PASS", canonical_signed_stage_verified=true,
            signed_package_and_stage_artifacts_verified=true,
            read_only_seal_consumption=true, replay_denied=true,
            cross_transaction_denied=true, tampered_execution_denied=true,
            tampered_stage_receipt_denied=true, wal_phase_drift_denied=true,
            execution_authorized=false, panel_update_ready=false,
            actual_apache_mutation=false, db_mutation=false,
            public_mutation=false, real_host_uat=false
        }));
        return 0;
    }
    private static bool IsHex(string x,int len) =>
        x.Length==len && x.All(char.IsAsciiHexDigit);
    private static string Hash(byte[] bytes) =>
        Convert.ToHexString(SHA256.HashData(bytes)).ToLowerInvariant();
    private static void Denied(Action operation,string reason)
    {
        try { operation(); }
        catch (InvalidDataException) { return; }
        throw new InvalidDataException("S7D4B_FAIL_OPEN_"+reason);
    }
    private static void Check(bool yes,string reason)
    {
        if (!yes) throw new InvalidDataException("S7D4B_FAILED_"+reason);
    }
}
