using Sokna.Maintenance.Core;

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
