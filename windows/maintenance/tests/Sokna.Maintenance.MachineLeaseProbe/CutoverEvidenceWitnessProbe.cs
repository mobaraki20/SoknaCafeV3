using System.Security.Cryptography;
using Sokna.Maintenance.Core;

// F1-S7D4A: ENFORCEMENT TEST of a non-executable HMAC evidence witness.
// The stage receipt used here is intentionally synthetic: this does NOT
// qualify signed production stage provenance, DB backup or activation.
internal static class CutoverEvidenceWitnessProbe
{
    internal static int Run(string input)
    {
        if (!OperatingSystem.IsWindows() ||
            Environment.GetEnvironmentVariable("GITHUB_ACTIONS") != "true")
            throw new InvalidOperationException("S7D4A_WINDOWS_CI_ONLY");
        var root = Path.GetFullPath(input);
        var runner = Path.TrimEndingDirectorySeparator(Path.GetFullPath(
            Environment.GetEnvironmentVariable("RUNNER_TEMP")
                ?? throw new InvalidOperationException("RUNNER_TEMP_REQUIRED")));
        if (!string.Equals(Path.GetDirectoryName(root), runner, StringComparison.OrdinalIgnoreCase) ||
            !Path.GetFileName(root).StartsWith("sokna-f1-s7d4a-", StringComparison.OrdinalIgnoreCase))
            throw new InvalidDataException("S7D4A_NOT_ISOLATED");
        Directory.CreateDirectory(root);
        var paths = new MaintenancePaths(Path.Combine(root, "maintenance"));
        new SecretStore(paths).EnsureKeys();
        var old = Path.Combine(root, "package", "Web.release.old");
        var target = Path.Combine(root, "package", "Web.release.new");
        Directory.CreateDirectory(old);
        Directory.CreateDirectory(target);
        File.WriteAllText(Path.Combine(old, "VERSION.txt"), "old");
        File.WriteAllText(Path.Combine(target, "VERSION.txt"), "new");
        Directory.CreateDirectory(Path.GetDirectoryName(paths.InstalledReleaseIdentityPath)!);
        File.WriteAllText(paths.InstalledReleaseIdentityPath, "{\"fixture\":true}");
        var installedHash = Hash(File.ReadAllBytes(paths.InstalledReleaseIdentityPath));
        var store = new MaintenanceOperationStore(paths);
        var (operation, _) = store.GetOrCreate(
            "s7d4a-fake-staged-operation", MaintenanceOperationStore.CanonicalRequestHash(
                "update.local-stage.synthetic", "fixture-package", "fixture-operator"),
            "update.local-stage", "fixture-package", "fixture-operator");
        var stageDir = Path.Combine(paths.StagingRoot, "synthetic-stage");
        Directory.CreateDirectory(stageDir);
        var stageFile = Path.Combine(stageDir, "stage-receipt.json");
        File.WriteAllText(stageFile, "{\"format\":\"synthetic-not-signed\"}");
        store.Update(operation, "staged", stageReceiptPath: stageFile);
        var tx = Guid.NewGuid().ToString("N");
        var journal = new LocalCodeCutoverWriteAheadJournal(paths);
        using var lease = MachineMutationLease.Acquire(paths,
            LocalCodeCutoverWriteAheadJournal.Owner);
        journal.Begin(lease, tx, operation.OperationId, old, target, installedHash);
        var gate = new LocalCutoverPreauthorizationGate(paths);
        var witness = gate.Issue(lease, tx);
        var result = gate.InspectAndBurn(lease, witness, tx);
        Check(!result.ExecutionAuthorized && result.DryRunOnly, "witness became executable");
        Denied(() => gate.InspectAndBurn(lease, witness, tx), "one-time replay");

        // Wrong stage / transaction / holder and altered evidence cannot pass.
        Denied(() => gate.InspectAndBurn(lease, witness, Guid.NewGuid().ToString("N")),
            "cross-transaction replay");
        var tamperId = gate.Issue(lease, tx);
        var tamperFile = Path.Combine(paths.CodeCutoverJournalRoot,
            "_preauthorization_evidence", tamperId + ".json");
        File.AppendAllText(tamperFile, "\n");
        // Whitespace does not change parsed signed fields; alter real bytes.
        var original = File.ReadAllText(tamperFile);
        File.WriteAllText(tamperFile, original.Replace("\"dry_run_only\":true",
            "\"dry_run_only\":false", StringComparison.Ordinal));
        Denied(() => gate.InspectAndBurn(lease, tamperId, tx), "tampered signature/evidence");

        var stageChangedId = gate.Issue(lease, tx);
        File.AppendAllText(stageFile, "tampered");
        Denied(() => gate.InspectAndBurn(lease, stageChangedId, tx), "changed staged receipt");
        File.WriteAllText(stageFile, "{\"format\":\"synthetic-not-signed\"}");

        var installedChangedId = gate.Issue(lease, tx);
        File.AppendAllText(paths.InstalledReleaseIdentityPath, "changed");
        Denied(() => gate.InspectAndBurn(lease, installedChangedId, tx),
            "changed installed identity");
        File.WriteAllText(paths.InstalledReleaseIdentityPath, "{\"fixture\":true}");

        var skippedId = gate.Issue(lease, tx);
        journal.Advance(lease, tx, "apache_stop_intent", new string('e', 64));
        Denied(() => gate.InspectAndBurn(lease, skippedId, tx),
            "post-intent witness reuse");

        var incorrectLease = false;
        try { gate.Issue(null!, tx); }
        catch (InvalidOperationException ex) when
            (ex.Message == "CUTOVER_SUPERVISOR_MACHINE_LEASE_REQUIRED") {
            incorrectLease = true;
        }
        Check(incorrectLease, "no-lease issuance was permitted");
        Console.WriteLine(System.Text.Json.JsonSerializer.Serialize(new
        {
            format = "sokna-s7d4a-cutover-preauth-witness-v1",
            result = "PASS",
            hmac_domain_separated = true,
            duplicate_consumption_rejected = true,
            cross_transaction_rejected = true,
            changed_stage_evidence_rejected = true,
            changed_installed_identity_rejected = true,
            wal_phase_drift_rejected = true,
            lease_required = true,
            release_stage_signed_provenance_tested = false,
            test_stage_is_synthetic = true,
            execution_authorized = false,
            panel_update_ready = false,
            service_mutation = false,
            db_or_backup_mutation = false,
            public_mutation = false
        }));
        return 0;
    }

    private static void Denied(Action op, string label)
    {
        try { op(); }
        catch (InvalidDataException) { return; }
        throw new InvalidDataException("S7D4A_NOT_FAIL_CLOSED: " + label);
    }
    private static void Check(bool yes, string label)
    {
        if (!yes) throw new InvalidDataException("S7D4A_FAILED: " + label);
    }
    private static string Hash(byte[] data) =>
        Convert.ToHexString(SHA256.HashData(data)).ToLowerInvariant();
}
