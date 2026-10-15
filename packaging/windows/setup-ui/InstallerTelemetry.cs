using System.Text.Json;

namespace Sokna.SetupUi;

internal sealed class InstallerTelemetry
{
    private readonly object gate = new();
    internal string TransactionId { get; }
    internal string CorrelationId { get; }
    internal string SessionId { get; }
    internal string DirectoryPath { get; }
    internal string EventPath => Path.Combine(DirectoryPath, "bootstrap-events.jsonl");

    private InstallerTelemetry(string transactionId, string correlationId, string sessionId, string directoryPath)
    {
        TransactionId = transactionId;
        CorrelationId = correlationId;
        SessionId = sessionId;
        DirectoryPath = directoryPath;
    }

    internal static InstallerTelemetry Start(string eventName = "setup_bootstrap_started")
    {
        var transactionId = "ins-" + DateTimeOffset.UtcNow.ToString("yyyyMMddTHHmmssZ") + "-" + Guid.NewGuid().ToString("N")[..10];
        var correlationId = "corr-" + Guid.NewGuid().ToString("N");
        var sessionId = "ui-" + Guid.NewGuid().ToString("N");
        var root = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "SOKNA", "Installer", "logs", transactionId);
        Directory.CreateDirectory(root);
        var t = new InstallerTelemetry(transactionId, correlationId, sessionId, root);
        t.Event(eventName, "started", new { process_id = Environment.ProcessId, user_interactive = Environment.UserInteractive });
        return t;
    }

    internal void Event(string eventName, string result, object? data = null)
    {
        try
        {
            var row = new Dictionary<string, object?>
            {
                ["format"] = "sokna-installer-event-v1",
                ["at_utc"] = DateTimeOffset.UtcNow.ToString("O"),
                ["transaction_id"] = TransactionId,
                ["correlation_id"] = CorrelationId,
                ["setup_session_id"] = SessionId,
                ["event"] = Safe(eventName),
                ["result"] = Safe(result),
                ["data"] = Sanitize(data)
            };
            var line = JsonSerializer.Serialize(row) + Environment.NewLine;
            lock (gate) File.AppendAllText(EventPath, line);
        }
        catch { }
    }

    internal string WritePlan(ComponentPlanSnapshot plan, string mode, string shellRoot, string installRoot, string dataRoot)
    {
        var path = Path.Combine(DirectoryPath, "update-plan.json");
        var payload = new
        {
            format = "sokna-windows-install-plan-v3",
            schema_version = 3,
            transaction_id = TransactionId,
            correlation_id = CorrelationId,
            setup_session_id = SessionId,
            created_at_utc = DateTimeOffset.UtcNow,
            mode,
            shell_root = Path.GetFullPath(shellRoot),
            install_root = Path.GetFullPath(installRoot),
            data_root = Path.GetFullPath(dataRoot),
            package_version = plan.PackageVersion,
            components = plan.Rows.Select(r => new
            {
                component_id = r.ComponentId,
                primary = r.Primary,
                current_version = r.CurrentVersion,
                target_version = r.TargetVersion,
                health = r.Health.ToString().ToLowerInvariant(),
                update_state = r.UpdateState,
                action = r.Action.ToString().ToUpperInvariant(),
                reason = r.Reason,
                installed_sha256 = r.InstalledSha256,
                target_sha256 = r.TargetSha256,
                service_name = r.ServiceName,
                transaction_group = r.TransactionGroup
            })
        };
        File.WriteAllText(path, JsonSerializer.Serialize(payload, new JsonSerializerOptions { WriteIndented = true }));
        Event("update_plan_computed", plan.IsBlocked ? "blocked" : "completed", new { mode, package_version = plan.PackageVersion, actions = plan.Rows.Select(r => new { r.ComponentId, action = r.Action.ToString() }).ToArray() });
        return path;
    }

    internal void WriteUiResult(string status, int? exitCode, string summary, bool rollbackComplete = false, bool rebootRequired = false)
    {
        try
        {
            var result = new
            {
                format = "sokna-installer-ui-result-v1",
                transaction_id = TransactionId,
                correlation_id = CorrelationId,
                completed_at_utc = DateTimeOffset.UtcNow,
                status = Safe(status),
                exit_code = exitCode,
                summary = Safe(summary, 500),
                rollback_complete = rollbackComplete,
                reboot_required = rebootRequired
            };
            File.WriteAllText(Path.Combine(DirectoryPath, "ui-result.json"), JsonSerializer.Serialize(result, new JsonSerializerOptions { WriteIndented = true }));
        }
        catch { }
    }

    private static object? Sanitize(object? value)
    {
        if (value is null) return null;
        // Callers only pass identifiers, versions, actions and result codes. Secret-bearing values are forbidden by contract.
        return value;
    }
    private static string Safe(string value, int max = 160)
    {
        value = (value ?? "").Replace('\r', ' ').Replace('\n', ' ').Trim();
        return value.Length <= max ? value : value[..max];
    }
}
