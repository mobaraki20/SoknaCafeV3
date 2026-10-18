using Sokna.Maintenance.Core;

// F1-S6C OS process-death fixture: heartbeat process must not survive
// killing a parent that exclusively holds its kill-on-close Windows Job.
if (args.Length == 3 && args[0] == "job-child")
{
    var heartbeat = Path.GetFullPath(args[2]);
    for (var n = 0; n < 300; n++)
    {
        File.AppendAllText(heartbeat, $"{Environment.ProcessId}:{n}\n");
        Thread.Sleep(120);
    }
    return 0;
}
if (args.Length == 4 && args[0] == "job-holder" && int.TryParse(args[3], out var jobHoldMs) &&
    jobHoldMs is >= 0 and <= 60000)
{
    var dotnetExe = Path.Combine(Path.GetDirectoryName(Environment.ProcessPath)!, "dotnet.exe");
    var selfDll = Path.GetFullPath(args[1]);
    var root = Path.GetFullPath(args[2]);
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
