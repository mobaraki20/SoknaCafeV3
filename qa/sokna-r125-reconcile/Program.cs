using Sokna.Maintenance.Core;

var previous = "3.0.0-g6.2-workspace.231dg-r112-guest-safe-append-uat";
var baseState = new RoutineLocalSlotState(
    Format: "sokna-local-slot-routine-state-v1",
    SchemaVersion: 1,
    Phase: "RECOVERED_TO_PREVIOUS_SLOT",
    PackageRoot: @"D:\SOKNA",
    StableWebRoot: @"D:\SOKNA\Web",
    ApacheExecutable: @"D:\SOKNA\Infrastructure\Apache\bin\httpd.exe",
    HealthUrl: "http://127.0.0.1:18080/",
    PreviousSlot: @"D:\SOKNA\LocalWeb\A",
    TargetSlot: @"D:\SOKNA\LocalWeb\B",
    CurrentBuild: previous,
    CurrentManagedFiles: 600,
    CurrentManagedTreeSha256: new string('a', 64),
    TargetBuild: "target",
    TargetManagedFiles: 650,
    TargetManagedTreeSha256: new string('b', 64),
    ActivationPerformed: true,
    DatabaseMutationPerformed: false,
    PublicMutationPerformed: false,
    WindowsRebootRequired: false,
    RecoveryRequired: false,
    UpdatedAtUtc: DateTimeOffset.UtcNow,
    Events: new[] { new RoutineLocalSlotEvent(
        "RECOVERED_TO_PREVIOUS_SLOT", DateTimeOffset.UtcNow) },
    MaintenanceIdentityCommitExpected: true);

static void Allowed(string name, RoutineLocalSlotState state, string expected)
{
    SupervisorR125LifecycleEvidenceReconciler.RequireSettledRoutine(state, expected);
    Console.WriteLine("PASS allowed: " + name);
}
static void Refused(string name, RoutineLocalSlotState state, string expected)
{
    try
    {
        SupervisorR125LifecycleEvidenceReconciler.RequireSettledRoutine(state, expected);
    }
    catch (InvalidDataException ex) when (ex.Message == "R125_RECOVERY_STATE_NOT_QUALIFIED")
    {
        Console.WriteLine("PASS rejected: " + name);
        return;
    }
    throw new Exception("UNSAFE R125 STATE ACCEPTED: " + name);
}
Allowed("settled R125 previous build", baseState, previous);
Refused("database forward intent", baseState with { DatabaseMutationPerformed = true }, previous);
Refused("public mutation", baseState with { PublicMutationPerformed = true }, previous);
Refused("recovery still required", baseState with { RecoveryRequired = true }, previous);
Refused("unsigned legacy lifecycle", baseState with { MaintenanceIdentityCommitExpected = false }, previous);
Refused("Windows reboot pending", baseState with { WindowsRebootRequired = true }, previous);
Refused("interrupted cutover", baseState with { Phase = "DATABASE_FORWARD_INTENT" }, previous);
Refused("committed newer release", baseState with { Phase = "COMMITTED",
    Events = new[] { new RoutineLocalSlotEvent("COMMITTED", DateTimeOffset.UtcNow) } }, previous);
Refused("wrong current build", baseState with { CurrentBuild = "wrong" }, previous);
Refused("no durable history", baseState with { Events = Array.Empty<RoutineLocalSlotEvent>() }, previous);
Refused("last event mismatch", baseState with { Events = new[] {
    new RoutineLocalSlotEvent("RECOVERY_REQUIRED", DateTimeOffset.UtcNow) } }, previous);
Refused("wrong schema", baseState with { SchemaVersion = 2 }, previous);
Refused("wrong format", baseState with { Format = "wrong" }, previous);
Console.WriteLine("PASS all 13 R125 reconciliation safety policy scenarios");
