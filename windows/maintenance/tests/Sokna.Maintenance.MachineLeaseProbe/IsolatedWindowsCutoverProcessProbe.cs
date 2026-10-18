using System.Diagnostics;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using Sokna.Maintenance.Core;

// F1-S7D3: disposable Windows CI only. Real NTFS junctions and genuine
// independent OS process termination; NEVER an installed Web/Apache service.
// This is deliberately NOT the production cutover executor, or a reboot test.
internal static class IsolatedWindowsCutoverProcessProbe
{
    private const string Tx = "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa";
    private const string Stage = "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb";
    private const string Installed = "cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc";
    private const string GuardFile = ".sokna-s7d3-test-fixture";
    private static readonly string[] Boundaries =
    {
        "begin", "stop-intent", "stop-observed", "switch-intent",
        "link-absent", "target-link", "switch-observed", "mock-started"
    };

    internal static int Run(string argument)
    {
        var root = GuardRoot(argument);
        Directory.CreateDirectory(root);
        File.WriteAllText(Path.Combine(root, GuardFile), "isolated-ci-only");
        foreach (var boundary in Boundaries)
            RunCase(root, boundary);
        Console.WriteLine(JsonSerializer.Serialize(new
        {
            format = "sokna-s7d3-windows-os-junction-process-crash-v1",
            result = "PASS",
            real_ntfs_junction = true,
            independent_process_forcibly_killed = true,
            real_os_machine_lease_reacquired = true,
            crash_boundaries = Boundaries.Length,
            missing_stable_junction_quarantined = true,
            job_bound_mock_server_killed_on_owner_crash = true,
            immutable_code_slots_verified = true,
            no_automatic_rollback = true,
            real_apache_service = false,
            actual_http_health = false,
            real_mariadb_or_backup = false,
            os_reboot_tested = false,
            real_host_uat = false,
            panel_update_ready = false,
            home_cafe_public_touched = false
        }));
        return 0;
    }

    internal static int Worker(string rootInput, string boundary, string fakePidInput)
    {
        var root = GuardAndVerifyFixture(rootInput);
        if (!Boundaries.Contains(boundary, StringComparer.Ordinal))
            throw new InvalidDataException("S7D3_BAD_BOUNDARY");
        var scenario = Scenario(root, boundary);
        if (!int.TryParse(fakePidInput, out var fakePid) || fakePid <= 0)
            throw new InvalidDataException("S7D3_BAD_FAKE_PROCESS");
        var paths = new MaintenancePaths(Path.Combine(scenario, "maintenance"));
        using var lease = MachineMutationLease.Acquire(paths, LocalCodeCutoverWriteAheadJournal.Owner);
        var wal = new LocalCodeCutoverWriteAheadJournal(paths);
        var oldSlot = Path.Combine(scenario, "package", "Web.release.old");
        var newSlot = Path.Combine(scenario, "package", "Web.release.new");
        var link = Path.Combine(scenario, "package", "Web");
        var pending = Path.Combine(scenario, "package", "Web.pending");
        var backup = Path.Combine(scenario, "package", "Web.previous");

        wal.Begin(lease, Tx, Stage, oldSlot, newSlot, Installed);
        if (boundary != "begin")
        {
            wal.Advance(lease, Tx, "apache_stop_intent", Evidence(scenario));
            if (boundary != "stop-intent")
            {
                using (var fake = Process.GetProcessById(fakePid))
                {
                    fake.Kill(entireProcessTree: true);
                    if (!fake.WaitForExit(8000))
                        throw new InvalidDataException("S7D3_MOCK_STOP_TIMEOUT");
                }
                wal.Advance(lease, Tx, "apache_stop_observed", Evidence(scenario));
                if (boundary is not "stop-observed")
                {
                    wal.Advance(lease, Tx, "pointer_switch_intent", Evidence(scenario));
                    if (boundary is not "switch-intent")
                    {
                        // A deliberately non-atomic two-rename cutover. Killing at
                        // the intermediate gap MUST produce unknown pointer state.
                        Directory.Move(link, backup);
                        if (boundary is not "link-absent")
                        {
                            Directory.Move(pending, link);
                            if (boundary is not "target-link")
                            {
                                wal.Advance(lease, Tx, "pointer_switch_observed", Evidence(scenario));
                            }
                        }
                    }
                }
            }
        }

        WindowsJobChildProcess? mockStarted = null;
        try
        {
            if (boundary == "mock-started")
            {
                var exe = Environment.ProcessPath
                    ?? throw new InvalidDataException("S7D3_DOTNET_HOST_MISSING");
                var dll = typeof(IsolatedWindowsCutoverProcessProbe).Assembly.Location;
                mockStarted = WindowsJobChildProcess.Start(exe,
                    new[] { dll, "s7d3-mock-apache", root, boundary, "target" }, scenario);
                File.WriteAllText(Path.Combine(scenario, "target-mock.pid"),
                    mockStarted.ProcessId.ToString());
                WaitForFile(Path.Combine(scenario, "mock-target.alive"), 8000);
            }

            // Parent kills the process while the WAL lease AND optionally the
            // mock Apache Job Object are still held by this worker.
            File.WriteAllText(Path.Combine(scenario, "worker-ready"), boundary);
            Thread.Sleep(30000);
            throw new InvalidDataException("S7D3_PARENT_FAILED_TO_KILL_WORKER");
        }
        finally { mockStarted?.Dispose(); }
    }

    internal static int MockApache(string rootInput, string boundary, string identity)
    {
        var root = GuardAndVerifyFixture(rootInput);
        if (!Boundaries.Contains(boundary, StringComparer.Ordinal) ||
            identity is not ("old" or "target"))
            throw new InvalidDataException("S7D3_BAD_MOCK");
        var scenario = Scenario(root, boundary);
        File.WriteAllText(Path.Combine(scenario, $"mock-{identity}.alive"),
            $"{Environment.ProcessId}:synthetic-only");
        while (true) Thread.Sleep(500);
    }

    private static void RunCase(string root, string boundary)
    {
        var scenario = Scenario(root, boundary);
        var package = Path.Combine(scenario, "package");
        var oldSlot = Path.Combine(package, "Web.release.old");
        var newSlot = Path.Combine(package, "Web.release.new");
        Directory.CreateDirectory(oldSlot);
        Directory.CreateDirectory(newSlot);
        File.WriteAllText(Path.Combine(oldSlot, "VERSION.txt"), "old-ci");
        File.WriteAllText(Path.Combine(newSlot, "VERSION.txt"), "new-ci");
        var oldHash = Hash(File.ReadAllBytes(Path.Combine(oldSlot, "VERSION.txt")));
        var targetHash = Hash(File.ReadAllBytes(Path.Combine(newSlot, "VERSION.txt")));
        CreateJunction(Path.Combine(package, "Web"), oldSlot);
        CreateJunction(Path.Combine(package, "Web.pending"), newSlot);
        var dll = typeof(IsolatedWindowsCutoverProcessProbe).Assembly.Location;
        var exe = Environment.ProcessPath
            ?? throw new InvalidDataException("S7D3_DOTNET_HOST_MISSING");

        using var baseline = Spawn(exe, dll, "s7d3-mock-apache", root, boundary, "old");
        try
        {
            WaitForFile(Path.Combine(scenario, "mock-old.alive"), 8000);
            using var worker = Spawn(exe, dll, "s7d3-worker", root, boundary, baseline.Id.ToString());
            try
            {
                WaitForFile(Path.Combine(scenario, "worker-ready"), 12000, worker);
                Check(!worker.HasExited, "worker exited instead of holding OS lease");
                var paths = new MaintenancePaths(Path.Combine(scenario, "maintenance"));
                var conflicting = false;
                try
                {
                    using var concurrent = MachineMutationLease.Acquire(paths, "intruding-worker");
                }
                catch (InvalidOperationException ex) when
                    (ex.Message == MachineMutationLease.ConflictCode) { conflicting = true; }
                Check(conflicting, "simultaneous lease was accepted while worker alive");

                worker.Kill(entireProcessTree: false);
                Check(worker.WaitForExit(8000), "worker was not forcibly terminated");
                var wal = new LocalCodeCutoverWriteAheadJournal(paths);
                var expectedPhase = boundary switch
                {
                    "begin" => "intent_created",
                    "stop-intent" => "apache_stop_intent",
                    "stop-observed" => "apache_stop_observed",
                    "switch-intent" or "link-absent" or "target-link" => "pointer_switch_intent",
                    _ => "pointer_switch_observed"
                };
                Check(wal.ReadVerified(Tx).Phase == expectedPhase,
                    "last fsynced WAL phase mismatches killed worker boundary");

                string? observed = LinkTarget(Path.Combine(package, "Web"));
                var decision = wal.Classify(Tx, observed);
                var expectedPointer = boundary switch
                {
                    "link-absent" => "unknown",
                    "target-link" or "switch-observed" or "mock-started" => "target",
                    _ => "previous"
                };
                Check(decision.ObservedPointer == expectedPointer,
                    "recovered physical junction does not match expected phase");
                Check(!decision.AutomaticRollbackAllowed && !decision.ActivationAllowed &&
                      !decision.PanelUpdateReady && !decision.DatabaseForwardOnly,
                    "uncertain pre-DB crash incorrectly authorized recovery or install");
                if (expectedPointer == "previous")
                    Check(File.ReadAllText(Path.Combine(package, "Web", "VERSION.txt")) == "old-ci",
                        "old NTFS junction resolves to unexpected code");
                if (expectedPointer == "target")
                    Check(File.ReadAllText(Path.Combine(package, "Web", "VERSION.txt")) == "new-ci",
                        "target NTFS junction resolves to unexpected code");
                if (expectedPointer == "unknown")
                {
                    Check(!Directory.Exists(Path.Combine(package, "Web")),
                        "intermediate missing-link boundary was not reproduced");
                    Check(string.Equals(LinkTarget(Path.Combine(package, "Web.previous")),
                        oldSlot, StringComparison.OrdinalIgnoreCase),
                        "previous physical slot was lost at gap boundary");
                }

                // Force-killed coordinator must release the kernel-held lease.
                using var recoveryLease = MachineMutationLease.Acquire(
                    paths, LocalCodeCutoverWriteAheadJournal.Owner);
                var again = false;
                try { wal.Begin(recoveryLease, Tx, Stage, oldSlot, newSlot, Installed); }
                catch (InvalidOperationException ex) when
                    (ex.Message == "CUTOVER_JOURNAL_ALREADY_EXISTS_OR_INTERRUPTED") { again = true; }
                Check(again, "restart silently overwrote durable transaction");
                if (boundary == "mock-started")
                {
                    var targetPid = int.Parse(File.ReadAllText(
                        Path.Combine(scenario, "target-mock.pid")));
                    Check(WaitForExit(targetPid, 8000),
                        "job-bound target fake Apache survived the owner crash");
                }
                Check(Hash(File.ReadAllBytes(Path.Combine(oldSlot, "VERSION.txt"))) == oldHash &&
                      Hash(File.ReadAllBytes(Path.Combine(newSlot, "VERSION.txt"))) == targetHash,
                      "immutable code sibling changed under the NTFS fixture");
            }
            finally
            {
                if (!worker.HasExited)
                {
                    worker.Kill(entireProcessTree: true);
                    worker.WaitForExit(8000);
                }
            }
        }
        finally
        {
            if (!baseline.HasExited)
            {
                baseline.Kill(entireProcessTree: true);
                baseline.WaitForExit(8000);
            }
        }
    }

    private static Process Spawn(string exe, string dll, params string[] arguments)
    {
        var spec = new ProcessStartInfo(exe) { UseShellExecute = false,
            CreateNoWindow = true, RedirectStandardError = true };
        spec.ArgumentList.Add(dll);
        foreach (var arg in arguments) spec.ArgumentList.Add(arg);
        return Process.Start(spec) ?? throw new InvalidOperationException("S7D3_START_FAILED");
    }

    private static void WaitForFile(string file, int timeoutMs, Process? worker = null)
    {
        var start = Stopwatch.StartNew();
        while (start.ElapsedMilliseconds < timeoutMs)
        {
            if (File.Exists(file)) return;
            if (worker is not null && worker.HasExited)
                throw new InvalidDataException("S7D3_WORKER_EARLY_EXIT: " +
                    worker.StandardError.ReadToEnd());
            Thread.Sleep(40);
        }
        throw new TimeoutException("S7D3_FIXTURE_READY_TIMEOUT: " + Path.GetFileName(file));
    }

    private static bool WaitForExit(int pid, int timeoutMs)
    {
        var start = Stopwatch.StartNew();
        while (start.ElapsedMilliseconds < timeoutMs)
        {
            try
            {
                using var proc = Process.GetProcessById(pid);
                if (proc.HasExited) return true;
            }
            catch (ArgumentException) { return true; }
            Thread.Sleep(60);
        }
        return false;
    }

    private static void CreateJunction(string link, string target)
    {
        if (Directory.Exists(link) || File.Exists(link))
            throw new InvalidDataException("S7D3_FIXTURE_LINK_EXISTS");
        using var proc = new Process();
        var info = new ProcessStartInfo("cmd.exe") {
            UseShellExecute = false, CreateNoWindow = true,
            RedirectStandardError = true, RedirectStandardOutput = true };
        info.ArgumentList.Add("/d");
        info.ArgumentList.Add("/c");
        info.ArgumentList.Add("mklink");
        info.ArgumentList.Add("/J");
        info.ArgumentList.Add(link);
        info.ArgumentList.Add(target);
        proc.StartInfo = info;
        if (!proc.Start() || !proc.WaitForExit(10000) || proc.ExitCode != 0)
            throw new InvalidDataException("S7D3_NTFS_JUNCTION_CREATION_FAILED");
        Check(string.Equals(LinkTarget(link), target, StringComparison.OrdinalIgnoreCase),
            "real Windows junction did not resolve to expected sibling");
    }

    private static string? LinkTarget(string link)
    {
        if (!Directory.Exists(link)) return null;
        if ((File.GetAttributes(link) & FileAttributes.ReparsePoint) == 0)
            throw new InvalidDataException("S7D3_STABLE_NOT_A_JUNCTION");
        return Directory.ResolveLinkTarget(link, returnFinalTarget: true)?.FullName
            ?? throw new InvalidDataException("S7D3_BROKEN_JUNCTION");
    }

    private static string Evidence(string root)
    {
        var package = Path.Combine(root, "package");
        var state = (LinkTarget(Path.Combine(package, "Web")) ?? "missing")
            + "|" + (Directory.Exists(Path.Combine(package, "Web.previous")) ? "backup" : "none");
        return Hash(Encoding.UTF8.GetBytes(state));
    }
    private static string Hash(byte[] value) =>
        Convert.ToHexString(SHA256.HashData(value)).ToLowerInvariant();

    private static string Scenario(string root, string boundary) =>
        Path.Combine(root, "case-" + boundary);

    private static string GuardAndVerifyFixture(string root)
    {
        var valid = GuardRoot(root);
        if (!File.Exists(Path.Combine(valid, GuardFile)) ||
            File.ReadAllText(Path.Combine(valid, GuardFile)) != "isolated-ci-only")
            throw new InvalidDataException("S7D3_FIXTURE_MARKER_MISSING");
        return valid;
    }

    private static string GuardRoot(string argument)
    {
        if (!OperatingSystem.IsWindows() ||
            !string.Equals(Environment.GetEnvironmentVariable("GITHUB_ACTIONS"),
                "true", StringComparison.OrdinalIgnoreCase))
            throw new InvalidOperationException("S7D3_ONLY_DISPOSABLE_GITHUB_WINDOWS_CI");
        var runnerTemp = Environment.GetEnvironmentVariable("RUNNER_TEMP")
            ?? throw new InvalidOperationException("S7D3_NO_RUNNER_TEMP");
        var temp = Path.TrimEndingDirectorySeparator(Path.GetFullPath(runnerTemp));
        var root = Path.TrimEndingDirectorySeparator(Path.GetFullPath(argument));
        if (!string.Equals(Path.GetDirectoryName(root), temp, StringComparison.OrdinalIgnoreCase) ||
            !Path.GetFileName(root).StartsWith("sokna-f1-s7d3-", StringComparison.OrdinalIgnoreCase))
            throw new InvalidDataException("S7D3_PATH_OUTSIDE_DISPOSABLE_RUNNER_TEMP");
        return root;
    }
    private static void Check(bool state, string reason)
    {
        if (!state) throw new InvalidDataException("S7D3_TEST_FAILED: " + reason);
    }
}
