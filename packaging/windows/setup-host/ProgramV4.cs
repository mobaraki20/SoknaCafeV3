using System.Diagnostics;
using System.Security.Cryptography;
using System.Text.Json;
using System.Text.RegularExpressions;

namespace Sokna.SetupHost;

internal sealed class SetupPlanV3
{
    public string format { get; set; } = "";
    public int schema_version { get; set; }
    public string transaction_id { get; set; } = "";
    public string correlation_id { get; set; } = "";
    public string setup_session_id { get; set; } = "";
    public string mode { get; set; } = "";
    public string shell_root { get; set; } = "";
    public string install_root { get; set; } = "";
    public string data_root { get; set; } = "";
    public string package_version { get; set; } = "";
    public List<ComponentPlanV3> components { get; set; } = [];
}
internal sealed class ComponentPlanV3
{
    public string component_id { get; set; } = "";
    public bool primary { get; set; }
    public string current_version { get; set; } = "";
    public string target_version { get; set; } = "";
    public string health { get; set; } = "";
    public string update_state { get; set; } = "";
    public string action { get; set; } = "";
    public string reason { get; set; } = "";
    public string installed_sha256 { get; set; } = "";
    public string target_sha256 { get; set; } = "";
    public string service_name { get; set; } = "";
    public string transaction_group { get; set; } = "";
}

internal static class ProgramV4
{
    private static readonly JsonSerializerOptions Json = new() { PropertyNameCaseInsensitive = false, WriteIndented = true };
    private static string systemDir = "";
    private static SetupPlanV3? activePlan;

    public static int Main(string[] args)
    {
        try
        {
            if (!OperatingSystem.IsWindows()) return FailConsole("این برنامه فقط روی ویندوز قابل اجرا است.", 2);
            if (args.Length == 1 && args[0].Equals("--host-self-test", StringComparison.OrdinalIgnoreCase)) { SelfTest(); Console.WriteLine("SOKNA Setup Host v4 self-test PASS"); return 0; }
            var sourcePlanPath = ReadPlanArgument(args);
            var plan = LoadAndValidate(sourcePlanPath);
            activePlan = plan;
            systemDir = Path.Combine(plan.data_root, "Installer", "logs", plan.transaction_id);
            Directory.CreateDirectory(systemDir);
            CopyBootstrapEvidence(sourcePlanPath, plan);
            Event("setup_host_started", "started", new { process_id = Environment.ProcessId, mode = plan.mode, package_version = plan.package_version });
            VerifyPackage(plan);
            Event("package_identity_verified", "completed", new { package_version = plan.package_version });
            return RunPowerShell(plan);
        }
        catch (HostPlanException e)
        {
            EventBestEffort("preflight_blocked", "blocked", new { error = Redact(e.Message) });
            WriteResultBestEffort("failed", e.Message, "—", "Preflight", false, false, 64);
            return FailConsole(e.Message, 64);
        }
        catch (Exception e)
        {
            EventBestEffort("setup_failed", "failed", new { error = Redact(e.Message), exception = e.GetType().Name });
            WriteResultBestEffort("failed", e.Message, "—", "Setup Host", false, false, 2);
            return FailConsole("عملیات سرویس‌های سکنا کامل نشد: " + e.Message, 2);
        }
    }

    private static string ReadPlanArgument(string[] args)
    {
        if (args.Length != 2 || !args[0].Equals("--plan-file", StringComparison.OrdinalIgnoreCase)) throw new HostPlanException("فایل برنامه نصب مشخص نشده است.");
        var path = Path.GetFullPath(args[1]); if (!File.Exists(path)) throw new HostPlanException("فایل برنامه نصب وجود ندارد."); return path;
    }

    private static SetupPlanV3 LoadAndValidate(string path)
    {
        var info = new FileInfo(path); if (info.Length is < 50 or > 1024 * 1024) throw new HostPlanException("اندازه فایل برنامه نصب معتبر نیست.");
        var p = JsonSerializer.Deserialize<SetupPlanV3>(File.ReadAllText(path), Json) ?? throw new HostPlanException("فایل برنامه نصب قابل خواندن نیست.");
        if (p.format != "sokna-windows-install-plan-v3" || p.schema_version != 3) throw new HostPlanException("نسخه برنامه نصب پشتیبانی نمی‌شود.");
        if (!Regex.IsMatch(p.transaction_id, "^ins-[0-9]{8}T[0-9]{6}Z-[a-f0-9]{10}$")) throw new HostPlanException("شناسه transaction معتبر نیست.");
        if (!Regex.IsMatch(p.correlation_id, "^corr-[a-f0-9]{32}$") || !Regex.IsMatch(p.setup_session_id, "^ui-[a-f0-9]{32}$")) throw new HostPlanException("شناسه correlation/session معتبر نیست.");
        p.mode = p.mode.Trim().ToLowerInvariant(); if (p.mode is not ("install" or "repair" or "uninstall")) throw new HostPlanException("حالت lifecycle معتبر نیست.");
        p.shell_root = Absolute(p.shell_root, "ShellRoot"); p.install_root = Absolute(p.install_root, "InstallRoot"); p.data_root = Absolute(p.data_root, "DataRoot");
        if (!Regex.IsMatch(p.package_version, "^\\d+\\.\\d+\\.\\d+(?:-[0-9A-Za-z.-]+)?$")) throw new HostPlanException("نسخه package معتبر نیست.");
        if (p.components.Count is < 3 or > 12) throw new HostPlanException("فهرست componentهای برنامه نصب معتبر نیست.");
        var ids = new HashSet<string>(StringComparer.Ordinal);
        foreach (var c in p.components)
        {
            var componentId = c.component_id ?? "";
            if (!Regex.IsMatch(componentId, "^[a-z][a-z0-9-]{1,63}$") || !ids.Add(componentId)) throw new HostPlanException("شناسه component تکراری یا نامعتبر است.");
            c.component_id = componentId;
            if (c.action is not ("KEEP" or "INSTALL" or "UPDATE" or "REPAIR" or "BLOCKED" or "PENDINGRESTART" or "PENDING_RESTART" or "NOACTIONOPTIONAL" or "NO_ACTION_OPTIONAL")) throw new HostPlanException("اقدام component معتبر نیست: " + c.component_id);
            if (c.action == "BLOCKED") throw new HostPlanException("برنامه شامل component مسدود است: " + c.component_id);
            if (!string.IsNullOrEmpty(c.target_sha256) && !Regex.IsMatch(c.target_sha256, "^[a-f0-9]{64}$")) throw new HostPlanException("هش هدف component معتبر نیست: " + c.component_id);
        }
        foreach (var required in new[] { "runtime", "print-agent", "maintenance", "maintenance-update-engine", "maintenance-recovery-console" }) if (!ids.Contains(required)) throw new HostPlanException("component ضروری در plan وجود ندارد: " + required);
        return p;
    }

    private static void VerifyPackage(SetupPlanV3 p)
    {
        var manifestPath = Path.Combine(p.shell_root, "windows-services-component-manifest-v2.json"); if (!File.Exists(manifestPath)) throw new HostPlanException("مانیفست componentهای بسته وجود ندارد.");
        using var d = JsonDocument.Parse(File.ReadAllText(manifestPath)); var r = d.RootElement;
        if (r.GetProperty("format").GetString() != "sokna-windows-component-manifest-v2" || r.GetProperty("schema_version").GetInt32() != 2) throw new HostPlanException("مانیفست componentهای بسته معتبر نیست.");
        if (!string.Equals(r.GetProperty("package_version").GetString(), p.package_version, StringComparison.Ordinal)) throw new HostPlanException("نسخه package در plan و manifest یکسان نیست.");
        var targets = r.GetProperty("components").EnumerateArray().ToDictionary(x => x.GetProperty("component_id").GetString()!, StringComparer.Ordinal);
        foreach (var c in p.components)
        {
            if (!targets.TryGetValue(c.component_id, out var t)) throw new HostPlanException("component plan در manifest هدف وجود ندارد: " + c.component_id);
            var rel = (t.GetProperty("payload_primary").GetString() ?? "").Replace('/', Path.DirectorySeparatorChar); var full = Path.GetFullPath(Path.Combine(p.shell_root, rel));
            if (!Under(full, p.shell_root) || !File.Exists(full)) throw new HostPlanException("payload هدف component وجود ندارد: " + c.component_id);
            var hash = Hash(full); if (!string.Equals(hash, c.target_sha256, StringComparison.OrdinalIgnoreCase)) throw new HostPlanException("payload هدف بعد از ایجاد plan تغییر کرده است: " + c.component_id);
            if (!string.Equals(t.GetProperty("version").GetString(), c.target_version, StringComparison.Ordinal)) throw new HostPlanException("نسخه هدف component با manifest یکسان نیست: " + c.component_id);
        }
        var script = Path.Combine(p.shell_root, "setup-windows-components-v4.ps1"); if (!File.Exists(script)) throw new HostPlanException("lifecycle engine جدید داخل بسته وجود ندارد.");
    }

    private static int RunPowerShell(SetupPlanV3 p)
    {
        var copiedPlan = Path.Combine(systemDir, "update-plan.json");
        var script = Path.Combine(p.shell_root, "setup-windows-components-v4.ps1");
        var powershell = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.System), "WindowsPowerShell", "v1.0", "powershell.exe");
        Event("preflight_completed", "completed", new { component_count = p.components.Count });
        var psi = new ProcessStartInfo(powershell) { UseShellExecute = false, CreateNoWindow = true, RedirectStandardOutput = true, RedirectStandardError = true, WorkingDirectory = p.shell_root };
        foreach (var a in new[] { "-NoProfile", "-NonInteractive", "-ExecutionPolicy", "Bypass", "-File", script, "-PlanFile", copiedPlan }) psi.ArgumentList.Add(a);
        Event("powershell_lifecycle_started", "started");
        using var process = Process.Start(psi) ?? throw new InvalidOperationException("PowerShell lifecycle اجرا نشد.");
        var outTask = process.StandardOutput.ReadToEndAsync(); var errTask = process.StandardError.ReadToEndAsync(); process.WaitForExit(); Task.WaitAll(outTask, errTask);
        var stdout = Redact(outTask.Result); var stderr = Redact(errTask.Result);
        File.WriteAllText(Path.Combine(systemDir, "powershell-stdout.log"), Limit(stdout, 200_000)); File.WriteAllText(Path.Combine(systemDir, "powershell-stderr.log"), Limit(stderr, 200_000));
        Event("powershell_lifecycle_completed", process.ExitCode == 0 ? "completed" : "failed", new { exit_code = process.ExitCode });
        var resultPath = Path.Combine(systemDir, "install-result.json");
        if (!File.Exists(resultPath)) WriteResultBestEffort(process.ExitCode == 0 ? "completed" : "failed", process.ExitCode == 0 ? "عملیات کامل شد." : "Lifecycle بدون نتیجه ساختاریافته متوقف شد.", "—", "Lifecycle", false, false, process.ExitCode);
        return process.ExitCode;
    }

    private static void CopyBootstrapEvidence(string sourcePlanPath, SetupPlanV3 p)
    {
        var userDir = Path.GetDirectoryName(sourcePlanPath)!;
        var expectedRoot = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "SOKNA", "Installer", "logs", p.transaction_id);
        if (!string.Equals(Path.GetFullPath(userDir).TrimEnd(Path.DirectorySeparatorChar), Path.GetFullPath(expectedRoot).TrimEnd(Path.DirectorySeparatorChar), StringComparison.OrdinalIgnoreCase)) throw new HostPlanException("plan خارج از پوشه bootstrap مورد انتظار است.");
        File.Copy(sourcePlanPath, Path.Combine(systemDir, "update-plan.json"), true);
        var events = Path.Combine(userDir, "bootstrap-events.jsonl"); if (File.Exists(events)) File.Copy(events, Path.Combine(systemDir, "bootstrap-events.jsonl"), true);
    }

    private static void Event(string name, string result, object? data = null)
    {
        if (activePlan is null || string.IsNullOrWhiteSpace(systemDir)) return;
        var row = new { format = "sokna-installer-event-v1", at_utc = DateTimeOffset.UtcNow, transaction_id = activePlan.transaction_id, correlation_id = activePlan.correlation_id, setup_session_id = activePlan.setup_session_id, event_name = name, result, data };
        File.AppendAllText(Path.Combine(systemDir, "system-events.jsonl"), JsonSerializer.Serialize(row) + Environment.NewLine);
    }
    private static void EventBestEffort(string name, string result, object? data = null) { try { Event(name, result, data); } catch { } }

    private static void WriteResultBestEffort(string status, string summary, string component, string phase, bool rollback, bool reboot, int exitCode)
    {
        try
        {
            if (activePlan is null || string.IsNullOrWhiteSpace(systemDir)) return;
            var result = new { format = "sokna-install-result-v1", transaction_id = activePlan.transaction_id, correlation_id = activePlan.correlation_id, completed_at_utc = DateTimeOffset.UtcNow, status, exit_code = exitCode, summary = Redact(summary), failed_component = component, failed_phase = phase, rollback_complete = rollback, reboot_required = reboot };
            File.WriteAllText(Path.Combine(systemDir, "install-result.json"), JsonSerializer.Serialize(result, Json));
        }
        catch { }
    }

    private static string Absolute(string value, string label) { if (string.IsNullOrWhiteSpace(value) || !Path.IsPathFullyQualified(value)) throw new HostPlanException(label + " باید مسیر کامل باشد."); return Path.GetFullPath(value); }
    private static bool Under(string path, string root) { root = Path.GetFullPath(root).TrimEnd(Path.DirectorySeparatorChar) + Path.DirectorySeparatorChar; return Path.GetFullPath(path).StartsWith(root, StringComparison.OrdinalIgnoreCase); }
    private static string Hash(string path) { using var s = File.OpenRead(path); return Convert.ToHexString(SHA256.HashData(s)).ToLowerInvariant(); }
    private static int FailConsole(string message, int code) { Console.Error.WriteLine(Redact(message)); return code; }
    private static string Limit(string value, int max) => value.Length <= max ? value : value[..max];
    private static string Redact(string value)
    {
        value = (value ?? "").Replace('\0', ' ');
        value = Regex.Replace(value, @"ws1_[a-f0-9]{24}_[a-f0-9]{48}", "[PAIRING_CODE_REDACTED]", RegexOptions.IgnoreCase);
        value = Regex.Replace(value, @"(?i)(token|secret|credential|password)(\s*[:=]\s*)[^\s,;\}\]]+", "$1$2[REDACTED]");
        return Limit(value, 200_000);
    }

    private static void SelfTest()
    {
        var sample = Redact("token=abc123 ws1_0123456789abcdef01234567_0123456789abcdef0123456789abcdef0123456789abcdef");
        if (sample.Contains("abc123") || sample.Contains("ws1_")) throw new InvalidOperationException("redaction_selftest_failed");
    }
}

internal sealed class HostPlanException(string message) : Exception(message);
