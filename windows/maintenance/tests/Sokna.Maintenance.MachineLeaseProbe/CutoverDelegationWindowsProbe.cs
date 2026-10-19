using System.Diagnostics;
using System.Security.Cryptography;
using System.Text.Json.Nodes;
using Sokna.Maintenance.Core;

// F1-S7D5A CI fixture: genuine signed Stage produced by P2D, but ONLY
// delegates read-only cutover attestation to a real Engine child process.
// The parent-death branch holds a Job Object over a separate long-lived
// test child; no installed Apache/junction/business DB can be touched.
internal static class CutoverDelegationWindowsProbe
{
    internal static int Run(string rootInput, string stageId, string engineDll)
    {
        var root = Validate(rootInput, stageId);
        var engine = Path.GetFullPath(engineDll);
        Check(File.Exists(engine), "qualified Engine DLL not found");
        var paths = new MaintenancePaths(root);
        var tx = SeedTransaction(paths, root, stageId, "positive");
        using (var lease = MachineMutationLease.Acquire(paths,
            LocalCodeCutoverWriteAheadJournal.Owner))
        {
            var exact = Exact(root, tx, stageId);
            var permit = CutoverEngineAttestationPermit.Issue(paths, lease, tx, stageId, exact);
            var noPermit = StartEngine(engine, exact, null);
            Check(noPermit.ExitCode == 75 && noPermit.Combined.Contains(
                CutoverEngineAttestationPermit.Required, StringComparison.Ordinal),
                "direct Engine cutover-attest was allowed");

            var altered = Exact(root, Guid.NewGuid().ToString("N"), stageId);
            var mismatch = StartEngine(engine, altered, permit);
            Check(mismatch.ExitCode == 75 && mismatch.Combined.Contains(
                CutoverEngineAttestationPermit.Rejected, StringComparison.Ordinal),
                "cross-transaction permit was accepted");

            var path = PermitPath(paths, permit);
            var bytes = File.ReadAllBytes(path);
            try
            {
                var forged = JsonNode.Parse(File.ReadAllText(path))!;
                forged["signature"] = new string('a',64);
                File.WriteAllText(path, forged.ToJsonString());
                var tampered = StartEngine(engine, exact, permit);
                Check(tampered.ExitCode == 75 && tampered.Combined.Contains(
                    CutoverEngineAttestationPermit.Rejected, StringComparison.Ordinal),
                    "forged delegation HMAC accepted");
            }
            finally { File.WriteAllBytes(path, bytes); }

            // Genuine .NET Engine is launched SUSPENDED, assigned to a
            // KILL_ON_JOB_CLOSE OS Job and then resumed. No service control.
            var executable = Environment.ProcessPath
                ?? throw new InvalidDataException("S7D5A_DOTNET_NOT_FOUND");
            using (var child = WindowsJobChildProcess.Start(executable,
                new[] { engine, exact[0], exact[1], exact[2], exact[3],
                    exact[4], exact[5], exact[6], "--delegation-id", permit },
                root))
            {
                var outcome = child.WaitAndCapture(18000);
                Check(outcome.Finished && outcome.ExitCode == 0 &&
                      outcome.Stdout.Contains("DELEGATED_SIGNED_STAGE_ATTESTED_READ_ONLY",
                          StringComparison.Ordinal) &&
                      outcome.Stdout.Contains("\"execution_authorized\":false",
                          StringComparison.Ordinal),
                    "real Job-bound Engine did not attest signed Stage read-only: " +
                    outcome.Stdout + " " + outcome.Stderr);
            }
            var replay = StartEngine(engine, exact, permit);
            Check(replay.ExitCode == 75 && replay.Combined.Contains(
                CutoverEngineAttestationPermit.Rejected, StringComparison.Ordinal),
                "consumed permit could be replayed");

            var stageDriftPermit = CutoverEngineAttestationPermit.Issue(
                paths, lease, tx, stageId, exact);
            var stage = new MaintenanceOperationStore(paths).Get(stageId)!;
            var stageFile = stage.StageReceiptPath!;
            var prior = File.ReadAllBytes(stageFile);
            try
            {
                File.AppendAllText(stageFile, " "); // JSON remains valid.
                var drift = StartEngine(engine, exact, stageDriftPermit);
                Check(drift.ExitCode == 75, "valid-JSON receipt drift accepted");
            }
            finally { File.WriteAllBytes(stageFile, prior); }

            var phaseDriftPermit = CutoverEngineAttestationPermit.Issue(
                paths, lease, tx, stageId, exact);
            new LocalCodeCutoverWriteAheadJournal(paths)
                .Advance(lease, tx, "apache_stop_intent", new string('a', 64));
            var phaseDrift = StartEngine(engine, exact, phaseDriftPermit);
            Check(phaseDrift.ExitCode == 75, "WAL phase change accepted");
        }

        var ownerTx = SeedTransaction(paths, root, stageId, "owner-death");
        var probeDll = typeof(CutoverDelegationWindowsProbe).Assembly.Location;
        using var owner = StartProcess(probeDll, "s7d5a-owner", root, stageId, ownerTx);
        try
        {
            WaitFor(Path.Combine(root, "s7d5a-child.pid"), owner, 18000);
            WaitFor(Path.Combine(root, "s7d5a-child-ready"), owner, 18000);
            var orphanId = int.Parse(File.ReadAllText(Path.Combine(root, "s7d5a-child.pid")));
            var unused = File.ReadAllText(Path.Combine(root, "s7d5a-unused-permit.id"));
            owner.Kill(entireProcessTree: false);
            Check(owner.WaitForExit(8000), "delegating parent could not be killed");
            Check(WaitGone(orphanId, 8000), "OS Job failed to kill delegated child on parent death");
            var orphan = StartEngine(engine, Exact(root, ownerTx, stageId), unused);
            Check(orphan.ExitCode == 75 && orphan.Combined.Contains(
                CutoverEngineAttestationPermit.Rejected, StringComparison.Ordinal),
                "dead owner allowed a signed unused permit");
        }
        finally
        {
            if (!owner.HasExited) { owner.Kill(entireProcessTree:true); owner.WaitForExit(8000); }
        }
        Console.WriteLine(System.Text.Json.JsonSerializer.Serialize(new
        {
            format = "sokna-f1-s7d5a-supervisor-engine-readonly-delegation-v1",
            result = "PASS",
            canonical_signed_stage_reverified_in_independent_engine = true,
            exact_transaction_and_command_binding = true,
            forged_hmac_rejected = true,
            direct_engine_command_rejected = true,
            replay_rejected = true,
            signed_receipt_drift_rejected = true,
            wal_phase_drift_rejected = true,
            os_job_child_assigned_before_resume = true,
            parent_death_kills_job_bound_child = true,
            parent_death_invalidates_unused_token = true,
            real_apache_cutover_performed = false,
            real_db_mutation_performed = false,
            execution_authorized = false,
            panel_update_ready = false
        }));
        return 0;
    }

    internal static int Owner(string rootInput, string stageId, string tx)
    {
        var root = Validate(rootInput, stageId);
        var paths = new MaintenancePaths(root);
        using var lease = MachineMutationLease.Acquire(
            paths, LocalCodeCutoverWriteAheadJournal.Owner);
        var args = Exact(root, tx, stageId);
        var unused = CutoverEngineAttestationPermit.Issue(paths, lease, tx, stageId, args);
        var used = CutoverEngineAttestationPermit.Issue(paths, lease, tx, stageId, args);
        var exe = Environment.ProcessPath
            ?? throw new InvalidDataException("S7D5A_DOTNET_NOT_FOUND");
        var dll = typeof(CutoverDelegationWindowsProbe).Assembly.Location;
        using var child = WindowsJobChildProcess.Start(exe,
            new[] {dll, "s7d5a-child", root, stageId, tx, used}, root);
        File.WriteAllText(Path.Combine(root, "s7d5a-unused-permit.id"), unused);
        File.WriteAllText(Path.Combine(root, "s7d5a-child.pid"), child.ProcessId.ToString());
        WaitFor(Path.Combine(root, "s7d5a-child-ready"), null, 15000);
        Thread.Sleep(30000);
        throw new InvalidDataException("S7D5A_PARENT_SHOULD_HAVE_BEEN_KILLED");
    }

    internal static int Child(string rootInput, string stageId, string tx, string permit)
    {
        var root = Validate(rootInput, stageId);
        var paths = new MaintenancePaths(root);
        var p = CutoverEngineAttestationPermit.Consume(paths, permit, tx,
            stageId, Exact(root, tx, stageId));
        Check(p.InspectionOnly && !p.ExecutionAuthorized,
            "Job child gained mutation authority");
        File.WriteAllText(Path.Combine(root, "s7d5a-child-ready"), "attested");
        Thread.Sleep(30000);
        return 0;
    }

    private static string SeedTransaction(MaintenancePaths paths, string root,
        string stage, string caseId)
    {
        var folder = Path.Combine(root, "s7d5a-"+caseId);
        var old = Path.Combine(folder, "Web.release.old");
        var next = Path.Combine(folder, "Web.release.new");
        Directory.CreateDirectory(old);
        Directory.CreateDirectory(next);
        File.WriteAllText(Path.Combine(old,"VERSION.txt"),"old");
        File.WriteAllText(Path.Combine(next,"VERSION.txt"),"new");
        var id = Guid.NewGuid().ToString("N");
        using var lease = MachineMutationLease.Acquire(
            paths, LocalCodeCutoverWriteAheadJournal.Owner);
        var installedHash = Hash(File.ReadAllBytes(paths.InstalledReleaseIdentityPath));
        new LocalCodeCutoverWriteAheadJournal(paths).Begin(lease,id,stage,old,next,installedHash);
        return id;
    }
    private static string[] Exact(string root, string tx, string stage) =>
        new[] {"cutover-attest","--transaction-id",tx,"--stage-operation-id",stage,"--data-root",root};

    private sealed record Result(int ExitCode, string Combined);
    private static Result StartEngine(string engine, string[] args, string? permit)
    {
        var values = new List<string> { engine };
        values.AddRange(args);
        if (permit is not null) { values.Add("--delegation-id"); values.Add(permit); }
        var p = new ProcessStartInfo(Environment.ProcessPath ?? "dotnet")
        { UseShellExecute=false, RedirectStandardOutput=true, RedirectStandardError=true,
          CreateNoWindow=true };
        foreach (var arg in values) p.ArgumentList.Add(arg);
        using var child = Process.Start(p) ?? throw new InvalidDataException("S7D5A_ENGINE_START_FAILED");
        var stdout = child.StandardOutput.ReadToEndAsync();
        var stderr = child.StandardError.ReadToEndAsync();
        if (!child.WaitForExit(18000))
        {
            child.Kill(entireProcessTree:true);
            child.WaitForExit(8000);
            throw new TimeoutException("S7D5A_ENGINE_TIMEOUT");
        }
        return new(child.ExitCode, stdout.GetAwaiter().GetResult() + stderr.GetAwaiter().GetResult());
    }
    private static Process StartProcess(string dll, params string[] args)
    {
        var p = new ProcessStartInfo(Environment.ProcessPath ?? "dotnet")
        { UseShellExecute=false, CreateNoWindow=true };
        p.ArgumentList.Add(dll);
        foreach(var arg in args) p.ArgumentList.Add(arg);
        return Process.Start(p) ?? throw new InvalidDataException("S7D5A_OWNER_START_FAILED");
    }
    private static string PermitPath(MaintenancePaths p, string id) =>
        Path.Combine(p.CodeCutoverJournalRoot, "_engine_attestation_delegations", id+".json");

    private static bool WaitGone(int pid,int timeoutMs)
    {
        var timer=Stopwatch.StartNew();
        while(timer.ElapsedMilliseconds<timeoutMs)
        {
            try { using var child=Process.GetProcessById(pid); if(child.HasExited)return true; }
            catch(ArgumentException){return true;}
            Thread.Sleep(65);
        }
        return false;
    }
    private static void WaitFor(string path, Process? owner, int timeoutMs)
    {
        var timer=Stopwatch.StartNew();
        while(timer.ElapsedMilliseconds<timeoutMs)
        {
            if(File.Exists(path)) return;
            if(owner is not null && owner.HasExited)
                throw new InvalidDataException("S7D5A_OWNER_DIED_BEFORE_READY");
            Thread.Sleep(65);
        }
        throw new TimeoutException("S7D5A_NOT_READY: "+Path.GetFileName(path));
    }
    private static string Validate(string rootInput, string stage)
    {
        if(!OperatingSystem.IsWindows() ||
           Environment.GetEnvironmentVariable("GITHUB_ACTIONS")!="true" ||
           stage.Length!=32 || !stage.All(char.IsAsciiHexDigit))
            throw new InvalidDataException("S7D5A_DISPOSABLE_WINDOWS_CI_ONLY");
        var root=Path.GetFullPath(rootInput);
        var temp=Path.TrimEndingDirectorySeparator(Path.GetFullPath(
            Environment.GetEnvironmentVariable("RUNNER_TEMP")
            ?? throw new InvalidDataException("S7D5A_RUNNER_TEMP_MISSING")));
        if(!string.Equals(Path.GetDirectoryName(root),temp,StringComparison.OrdinalIgnoreCase) ||
           !Path.GetFileName(root).StartsWith("sokna-f1-p2d-",StringComparison.OrdinalIgnoreCase))
            throw new InvalidDataException("S7D5A_FOREIGN_ROOT");
        return root;
    }
    private static string Hash(byte[] bytes) =>
        Convert.ToHexString(SHA256.HashData(bytes)).ToLowerInvariant();
    private static void Check(bool yes,string reason)
    {
        if(!yes) throw new InvalidDataException("S7D5A_FAIL: "+reason);
    }
}
