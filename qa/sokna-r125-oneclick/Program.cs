using System.Diagnostics;
using System.Text.Json;
using Sokna.Maintenance.Core;

// R125-only guided recovery. Run by an explicit operator action.
// No independent file deletion, SQL, Local/Public activation, or service change.
if (!OperatingSystem.IsWindows())
{
    Console.Error.WriteLine("R125_WINDOWS_ONLY");
    Environment.ExitCode = 87;
    return;
}
if (args.Length != 0)
{
    Console.Error.WriteLine("R125_ONE_CLICK_NO_ARGUMENTS_ALLOWED");
    Environment.ExitCode = 64;
    return;
}
var desktop = Environment.GetFolderPath(Environment.SpecialFolder.DesktopDirectory);
if (string.IsNullOrWhiteSpace(desktop) || !Directory.Exists(desktop))
{
    Console.Error.WriteLine("R125_DESKTOP_REPORT_PATH_UNAVAILABLE");
    Environment.ExitCode = 65;
    return;
}
var reportFile = Path.Combine(desktop, $"SOKNA-R125-ONECLICK-RESULT-{DateTimeOffset.UtcNow:yyyyMMdd-HHmmss}-{Guid.NewGuid():N}.json");
Dictionary<string,object?> report;
int code;
try
{
    // A blank Windows host is not a completed R125 recovery. Require the
    // installed authority and historical state before calling the workflow.
    var paths = new MaintenancePaths(MaintenancePaths.DefaultDataRoot());
    if (!File.Exists(paths.DescriptorPath) ||
        !File.Exists(paths.InstalledReleaseIdentityPath) ||
        !File.Exists(Path.Combine(paths.LocalSlotStateRoot, "routine-state.json")))
        throw new InvalidDataException("R125_INSTALLED_HOST_EVIDENCE_MISSING");
    var result = await R125RecoveryWorkflow.RunAsync(paths, confirmed: true);
    report = result.Report;
    code = result.ExitCode;
}
catch
{
    // Fail closed; never serialize exception details, secrets or local paths.
    code = 2;
    report = new Dictionary<string,object?>
    {
        ["format"] = "sokna-r125-oneclick-failclosed-v1",
        ["at_utc"] = DateTimeOffset.UtcNow,
        ["status"] = "BLOCKED",
        ["code"] = "R125_UNEXPECTED_CONDITION_NOT_MODIFIED",
        ["reconciliation_performed"] = false,
        ["production_approved"] = false
    };
}
try
{
    byte[] bytes = JsonSerializer.SerializeToUtf8Bytes(report,
        new JsonSerializerOptions { WriteIndented = true });
    using (var file = new FileStream(reportFile, FileMode.CreateNew, FileAccess.Write,
        FileShare.None, 4096, FileOptions.WriteThrough))
    {
        file.Write(bytes);
        file.Flush(true);
    }
}
catch
{
    Console.Error.WriteLine("R125_SAFE_REPORT_WRITE_FAILED");
    Environment.ExitCode = 3;
    return;
}
var status = report.TryGetValue("status", out var s) ? s?.ToString() : "BLOCKED";
var safeCode = report.TryGetValue("code", out var c) ? c?.ToString() : "R125_UNKNOWN";
Console.WriteLine($"SOKNA R125: {status} / {safeCode}");
Console.WriteLine($"Report: {reportFile}");
try
{
    if (Environment.GetEnvironmentVariable("GITHUB_ACTIONS") != "true")
        Process.Start(new ProcessStartInfo("explorer.exe")
        {
            UseShellExecute = true,
            ArgumentList = { "/select," + reportFile }
        });
}
catch { /* Reporting is not an invitation to mutate system state. */ }
Environment.ExitCode = code;
