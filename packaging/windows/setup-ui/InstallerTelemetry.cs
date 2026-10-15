using System.Text.Json;
using System.Text.RegularExpressions;

namespace Sokna.SetupUi;

internal sealed class InstallerTelemetry
{
    private static readonly object ambientGate = new();
    private static InstallerTelemetry? ambient;
    private readonly object gate = new();
    internal string TransactionId { get; }
    internal string CorrelationId { get; }
    internal string SessionId { get; }
    internal string DirectoryPath { get; }
    internal string EventPath => Path.Combine(DirectoryPath, "bootstrap-events.jsonl");

    private InstallerTelemetry(string transactionId, string correlationId, string sessionId, string directoryPath)
    {
        TransactionId = transactionId; CorrelationId = correlationId; SessionId = sessionId; DirectoryPath = directoryPath;
    }

    internal static InstallerTelemetry Start(string eventName = "setup_bootstrap_started")
    {
        lock (ambientGate)
        {
            if (ambient is not null && !File.Exists(Path.Combine(ambient.DirectoryPath, "ui-result.json")))
            {
                ambient.Event(eventName, "started", new { process_id = Environment.ProcessId, resumed = true });
                return ambient;
            }
            ambient = TryResume() ?? CreateNew();
            ambient.Event(eventName, "started", new { process_id = Environment.ProcessId, user_interactive = Environment.UserInteractive, resumed = File.Exists(Path.Combine(ambient.DirectoryPath, "bootstrap-context.json")) });
            return ambient;
        }
    }

    private static InstallerTelemetry CreateNew()
    {
        var tx = "ins-" + DateTimeOffset.UtcNow.ToString("yyyyMMddTHHmmssZ") + "-" + Guid.NewGuid().ToString("N")[..10];
        var corr = "corr-" + Guid.NewGuid().ToString("N"); var session = "ui-" + Guid.NewGuid().ToString("N");
        var root = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "SOKNA", "Installer", "logs", tx); Directory.CreateDirectory(root);
        return new InstallerTelemetry(tx, corr, session, root);
    }

    private static InstallerTelemetry? TryResume()
    {
        var installerRoot = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "SOKNA", "Installer");
        var active = Path.Combine(installerRoot, "active-context.json");
        try
        {
            if (!File.Exists(active)) return null;
            using var d = JsonDocument.Parse(File.ReadAllText(active)); var r = d.RootElement;
            if (r.GetProperty("format").GetString() != "sokna-installer-active-context-v1") return null;
            var tx = r.GetProperty("transaction_id").GetString() ?? ""; var corr = r.GetProperty("correlation_id").GetString() ?? ""; var session = r.GetProperty("setup_session_id").GetString() ?? "";
            var expires = r.GetProperty("expires_at_utc").GetDateTimeOffset(); if (DateTimeOffset.UtcNow > expires) return null;
            if (!Regex.IsMatch(tx, "^ins-[0-9]{8}T[0-9]{6}Z-[a-f0-9]{10}$") || !Regex.IsMatch(corr, "^corr-[a-f0-9]{32}$") || !Regex.IsMatch(session, "^ui-[a-f0-9]{32}$")) return null;
            var expected = Path.Combine(installerRoot, "logs", tx); var logged = Path.GetFullPath(r.GetProperty("log_directory").GetString() ?? "");
            if (!string.Equals(Path.GetFullPath(expected).TrimEnd(Path.DirectorySeparatorChar), logged.TrimEnd(Path.DirectorySeparatorChar), StringComparison.OrdinalIgnoreCase)) return null;
            Directory.CreateDirectory(expected); return new InstallerTelemetry(tx, corr, session, expected);
        }
        catch { return null; }
    }

    internal void Event(string eventName, string result, object? data = null)
    {
        try
        {
            var row = new Dictionary<string, object?>
            {
                ["format"] = "sokna-installer-event-v1", ["at_utc"] = DateTimeOffset.UtcNow.ToString("O"), ["transaction_id"] = TransactionId,
                ["correlation_id"] = CorrelationId, ["setup_session_id"] = SessionId, ["event_name"] = Safe(eventName), ["result"] = Safe(result), ["data"] = data
            };
            lock (gate) File.AppendAllText(EventPath, JsonSerializer.Serialize(row) + Environment.NewLine);
        }
        catch { }
    }

    internal string WritePlan(ComponentPlanSnapshot plan, string mode, string shellRoot, string installRoot, string dataRoot)
    {
        var path = Path.Combine(DirectoryPath, "update-plan.json");
        var payload = new
        {
            format = "sokna-windows-install-plan-v3", schema_version = 3, transaction_id = TransactionId, correlation_id = CorrelationId, setup_session_id = SessionId,
            created_at_utc = DateTimeOffset.UtcNow, mode, shell_root = Path.GetFullPath(shellRoot), install_root = Path.GetFullPath(installRoot), data_root = Path.GetFullPath(dataRoot), package_version = plan.PackageVersion,
            components = plan.Rows.Select(r => new { component_id = r.ComponentId, primary = r.Primary, current_version = r.CurrentVersion, target_version = r.TargetVersion, health = r.Health.ToString().ToLowerInvariant(), update_state = r.UpdateState, action = ActionToken(r.Action), reason = r.Reason, installed_sha256 = r.InstalledSha256, target_sha256 = r.TargetSha256, service_name = r.ServiceName, transaction_group = r.TransactionGroup })
        };
        File.WriteAllText(path, JsonSerializer.Serialize(payload, new JsonSerializerOptions { WriteIndented = true }));
        Event("update_plan_computed", plan.IsBlocked ? "blocked" : "completed", new { mode, package_version = plan.PackageVersion, actions = plan.Rows.Select(r => new { r.ComponentId, action = ActionToken(r.Action) }).ToArray() }); return path;
    }

    internal void WriteUiResult(string status, int? exitCode, string summary, bool rollbackComplete = false, bool rebootRequired = false)
    {
        try
        {
            var result = new { format = "sokna-installer-ui-result-v1", transaction_id = TransactionId, correlation_id = CorrelationId, completed_at_utc = DateTimeOffset.UtcNow, status = Safe(status), exit_code = exitCode, summary = Safe(summary, 500), rollback_complete = rollbackComplete, reboot_required = rebootRequired };
            File.WriteAllText(Path.Combine(DirectoryPath, "ui-result.json"), JsonSerializer.Serialize(result, new JsonSerializerOptions { WriteIndented = true }));
            var active = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "SOKNA", "Installer", "active-context.json");
            try { if (File.Exists(active)) File.Delete(active); } catch { }
        }
        catch { }
        finally { lock (ambientGate) { if (ReferenceEquals(ambient, this)) ambient = null; } }
    }

    private static string ActionToken(ComponentAction a) => a switch { ComponentAction.PendingRestart => "PENDING_RESTART", ComponentAction.NoActionOptional => "NO_ACTION_OPTIONAL", _ => a.ToString().ToUpperInvariant() };
    private static string Safe(string value, int max = 160) { value = (value ?? "").Replace('\r', ' ').Replace('\n', ' ').Trim(); return value.Length <= max ? value : value[..max]; }
}
