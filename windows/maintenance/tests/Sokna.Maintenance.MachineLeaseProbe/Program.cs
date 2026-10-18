using Sokna.Maintenance.Core;
using System.Diagnostics;

// F1-S7C2 isolated file-system fixture. It does NOT claim real-host
// Apache/PHP/MariaDB qualification; that is separately required by Supervisor.
if (args.Length == 2 && args[0] == "inactive-sibling-writer")
{
    var dir = Path.GetFullPath(args[1]);
    var package = Path.Combine(dir, "Package");
    var active = Path.Combine(package, "Web.release.active");
    var stable = Path.Combine(package, "Web");
    var persistent = Path.Combine(package, "LocalWeb", "persistent");
    var source = Path.Combine(dir, "private-source");
    var target = Path.Combine(package, "Web.release.test-incoming");
    var baseline = "3.0.0-test-baseline";
    var next = "3.0.0-test-target";
    Directory.CreateDirectory(active);
    Directory.CreateDirectory(persistent);
    Directory.CreateDirectory(Path.Combine(source, "public"));
    Directory.CreateDirectory(Path.Combine(source, "resources"));
    File.WriteAllText(Path.Combine(package, "install.lock"),
        System.Text.Json.JsonSerializer.Serialize(new {
            format = "sokna-install-lock-v3", installation_id = "isolated-test-install-123" }));
    var binding = System.Text.Json.JsonSerializer.Serialize(new {
        format = "sokna-local-installation-binding-v1",
        installation_id = "isolated-test-install-123",
        web_version = baseline });
    File.WriteAllText(Path.Combine(active, ".sokna-installation.json"), binding);
    File.WriteAllText(Path.Combine(persistent, ".sokna-installation.json"), binding);
    Directory.CreateDirectory(Path.Combine(persistent, "public"));
    File.WriteAllText(Path.Combine(persistent, "public", "local-recovery.php"),
        "<?php echo 'recovery'; ?>");
    Directory.CreateDirectory(Path.Combine(active, "public"));
    File.Copy(Path.Combine(persistent, "public", "local-recovery.php"),
        Path.Combine(active, "public", "local-recovery.php"));
    File.WriteAllText(Path.Combine(source, "VERSION.txt"), next);
    File.WriteAllText(Path.Combine(source, "public", "index.php"),
        "<?php echo 'inactive'; ?>");
    File.WriteAllText(Path.Combine(source, "resources", "local-managed-files-v1.json"),
        System.Text.Json.JsonSerializer.Serialize(new {
            format = "sokna-local-managed-files-v1",
            files = new[] { "VERSION.txt", "public/index.php",
                "resources/local-managed-files-v1.json" } }));
    var cmd = new ProcessStartInfo("cmd.exe") {
        UseShellExecute = false, CreateNoWindow = true,
        RedirectStandardError = true, RedirectStandardOutput = true };
    cmd.ArgumentList.Add("/c"); cmd.ArgumentList.Add("mklink");
    cmd.ArgumentList.Add("/J"); cmd.ArgumentList.Add(stable); cmd.ArgumentList.Add(active);
    using (var proc = Process.Start(cmd) ?? throw new Exception("Junction fixture failed")) {
        if (!proc.WaitForExit(10000) || proc.ExitCode != 0)
            throw new InvalidDataException("Junction fixture was not created.");
    }
    var stableLink = Directory.ResolveLinkTarget(stable, returnFinalTarget:true)?.FullName
        ?? throw new InvalidDataException("Stable fixture link missing.");
    var originalPersistent = File.ReadAllBytes(Path.Combine(persistent,
        ".sokna-installation.json"));
    var expectedTree = LocalManagedTreeAttestation.Compute(source, 3);
    ProductionInactiveLocalSlotWriter.CopyToNewSibling(
        source, target, package, stable, baseline, next, 3, expectedTree);
    if (!File.Exists(Path.Combine(target, "public", "index.php")) ||
        !File.Exists(Path.Combine(target, ".sokna-installation.json")))
        throw new InvalidDataException("Inactive sibling missing verified target files");
    LocalManagedTreeAttestation.RequireExact(target, 3, expectedTree);
    RoutineLocalBinding.RequireTarget(persistent, target, package, next);
    if (Directory.ResolveLinkTarget(stable, returnFinalTarget:true)?.FullName != stableLink ||
        !File.ReadAllBytes(Path.Combine(persistent, ".sokna-installation.json"))
            .SequenceEqual(originalPersistent))
        throw new InvalidDataException("Private sibling writer altered active/persistent");
    bool duplicateRejected = false;
    try { ProductionInactiveLocalSlotWriter.CopyToNewSibling(
        source, target, package, stable, baseline, next, 3, expectedTree); }
    catch (InvalidDataException) { duplicateRejected = true; }
    if (!duplicateRejected) throw new InvalidDataException("Existing sibling overwritten");
    File.AppendAllText(Path.Combine(source,"public","index.php"),"tampered");
    bool tamperRejected = false;
    try { ProductionInactiveLocalSlotWriter.CopyToNewSibling(
        source, target + ".another", package, stable, baseline, next, 3, expectedTree); }
    catch (InvalidDataException) { tamperRejected = true; }
    if (!tamperRejected) throw new InvalidDataException("Corrupt source accepted");
    if (Directory.Exists(target + ".another"))
        throw new InvalidDataException("Tampered input wrote a partial sibling");
    File.WriteAllText(Path.Combine(source, "public", "index.php"),
        "<?php echo 'inactive'; ?>");
    File.AppendAllText(Path.Combine(persistent, "public", "local-recovery.php"), "drift");
    var driftRejected = false;
    try { ProductionInactiveLocalSlotWriter.CopyToNewSibling(
        source, target + ".drift", package, stable, baseline, next, 3, expectedTree); }
    catch (InvalidDataException) { driftRejected = true; }
    if (!driftRejected || Directory.Exists(target + ".drift"))
        throw new InvalidDataException("Stale machine-local persistent code copied into sibling");
    Console.WriteLine(System.Text.Json.JsonSerializer.Serialize(new {
        format = "sokna-inactive-sibling-writer-test-v1",
        result = "PASS", inactive_copy_created = true, verified_binding = true,
        active_junction_unchanged = true, persistent_binding_unchanged = true,
        existing_sibling_not_overwritten = true, corrupt_source_rejected_pre_mutation = true,
        stale_machine_overlay_rejected = true,
        production_host_qualification = false, apache_mutation = false,
        database_mutation = false, public_mutation = false }));
    return 0;
}

// Inject a durable in-flight legacy migration into an isolated test data root.
// On the next Supervisor startup, the parent-death reconciler must quarantine it.
if (args.Length == 2 && args[0] == "seed-interrupted-migration")
{
    var p = new MaintenancePaths(Path.GetFullPath(args[1]));
    var store = new MaintenanceOperationStore(p);
    var key = "test-job-crash-" + Guid.NewGuid().ToString("N");
    var request = "test-job-interruption";
    var actor = "user:job-fixture";
    var hash = MaintenanceOperationStore.CanonicalRequestHash("update.local-slot-migrate", request, actor);
    var (operation, _) = store.GetOrCreate(key, hash, "update.local-slot-migrate", request, actor);
    operation = store.Update(operation, "running", "TEST_JOB_CHILD_RUNNING", incrementAttempt: true);
    Console.WriteLine("INTERRUPTED_MIGRATION_OPERATION " + operation.OperationId);
    return 0;
}

// F1-S6C OS process-death fixture: heartbeat process must not survive
// killing a parent that exclusively holds its kill-on-close Windows Job.
if (args.Length == 2 && args[0] == "job-child")
{
    var heartbeat = Path.GetFullPath(args[1]);
    for (var n = 0; n < 300; n++)
    {
        File.AppendAllText(heartbeat, $"{Environment.ProcessId}:{n}\n");
        Thread.Sleep(120);
    }
    return 0;
}
// Timeout qualification: disposing a live job kills the child before the
// issuer returns a terminal outcome. No production Engine command is needed.
if (args.Length == 2 && args[0] == "job-timeout")
{
    var root = Path.GetFullPath(args[1]);
    Directory.CreateDirectory(root);
    var dotnetExe = Environment.ProcessPath
        ?? throw new InvalidOperationException("dotnet host path unavailable.");
    var selfDll = System.Reflection.Assembly.GetExecutingAssembly().Location;
    using var child = WindowsJobChildProcess.Start(dotnetExe,
        new[] { selfDll, "job-child", Path.Combine(root, "timeout-heartbeat.txt") }, root);
    var childPid = child.ProcessId;
    var result = child.WaitAndCapture(900);
    if (result.Finished) throw new InvalidDataException("Unbounded child unexpectedly completed.");
    for (var n = 0; n < 50; n++)
    {
        if (Process.GetProcesses().All(p => p.Id != childPid))
        {
            Console.WriteLine("JOB_TIMEOUT_KILLED_CHILD " + childPid);
            return 0;
        }
        Thread.Sleep(100);
    }
    throw new InvalidDataException("Timed-out child remained running after Job close.");
}

if (args.Length == 3 && args[0] == "job-holder" && int.TryParse(args[2], out var jobHoldMs) &&
    jobHoldMs is >= 0 and <= 60000)
{
    var dotnetExe = Path.Combine(Path.GetDirectoryName(Environment.ProcessPath)!, "dotnet.exe");
    var selfDll = Path.GetFullPath(typeof(EngineDelegationPermit).Assembly.Location);
    // Resolve the probe assembly rather than the referenced Core assembly.
    selfDll = Path.GetFullPath(System.Reflection.Assembly.GetExecutingAssembly().Location);
    var root = Path.GetFullPath(args[1]);
    Directory.CreateDirectory(root);
    using var child = WindowsJobChildProcess.Start(dotnetExe,
        new[] { selfDll, "job-child", Path.Combine(root, "heartbeat.txt") }, root);
    Console.WriteLine("JOB_BOUND_CHILD " + child.ProcessId);
    Console.Out.Flush();
    Thread.Sleep(jobHoldMs);
    return 0;
}


// Isolated qualification issuer: NOT shipped with the Engine or Installer.
if (args.Length == 3 && args[0] == "permit" &&
    int.TryParse(args[2], out var permitHoldMs) && permitHoldMs is >= 0 and <= 60000)
{
    var permitPaths = new MaintenancePaths(Path.GetFullPath(args[1]));
    new SecretStore(permitPaths).EnsureKeys();
    using var lease = MachineMutationLease.Acquire(permitPaths, EngineDelegationPermit.Owner);
    var exactArgs = new[] { "local-slot-migrate", "--data-root", permitPaths.DataRoot };
    var permitId = EngineDelegationPermit.Issue(permitPaths, lease, new string('a', 32), exactArgs);
    Console.WriteLine("ENGINE_PERMIT_ISSUED " + permitId);
    Console.Out.Flush();
    if (permitHoldMs > 0) Thread.Sleep(permitHoldMs);
    return 0;
}

if (args.Length != 3 ||
    args[0] is not ("hold" or "try") ||
    !int.TryParse(args[2], out var holdMs) || holdMs is < 0 or > 30000)
{
    Console.Error.WriteLine("Usage: hold|try <test-data-root> <hold-ms>");
    return 64;
}

var paths = new MaintenancePaths(Path.GetFullPath(args[1]));
try
{
    using var lease = MachineMutationLease.Acquire(paths, args[0] == "hold" ? "test-owner-A" : "test-owner-B");
    Console.WriteLine("MACHINE_LEASE_ACQUIRED");
    Console.Out.Flush();
    if (holdMs > 0) Thread.Sleep(holdMs);
    return 0;
}
catch (InvalidOperationException ex) when (ex.Message == MachineMutationLease.ConflictCode)
{
    Console.WriteLine(MachineMutationLease.ConflictCode);
    return 75;
}
catch (InvalidOperationException ex) when (ex.Message == MachineMutationLease.UnavailableCode)
{
    Console.WriteLine(MachineMutationLease.UnavailableCode);
    return 76;
}
