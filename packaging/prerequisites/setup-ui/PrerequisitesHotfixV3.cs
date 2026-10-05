using System.Reflection;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using System.Text.Json.Nodes;

namespace Sokna.Prerequisites.Setup;

internal static class ProgramV3
{
    [STAThread]
    public static int Main(string[] args)
    {
        if (args.Any(x => x.Equals("--self-test-mariadb-server-locator", StringComparison.OrdinalIgnoreCase)))
            return MariaDbServerLocator.SelfTest();
        if (args.Any(x => x.Equals("--self-test-endpoint-port", StringComparison.OrdinalIgnoreCase)))
            return LocalEndpointPortPolicy.SelfTest();
        if (args.Any(x => x.Equals("--self-test-infrastructure-ownership", StringComparison.OrdinalIgnoreCase)))
            return InfrastructureOwnershipDetector.SelfTest();

        var probeIndex = Array.FindIndex(args, x => x.Equals("--probe-infrastructure-ownership", StringComparison.OrdinalIgnoreCase));
        if (probeIndex >= 0)
        {
            if (args.Length <= probeIndex + 2) return 43;
            return InfrastructureOwnershipDetector.Probe(args[probeIndex + 1], args[probeIndex + 2]);
        }

        var phpConfigIndex = Array.FindIndex(args, x => x.Equals("--qualify-php-config", StringComparison.OrdinalIgnoreCase));
        if (phpConfigIndex >= 0)
        {
            if (args.Length <= phpConfigIndex + 1) return 73;
            return PhpRuntimeConfiguration.ConfigureForQualification(args[phpConfigIndex + 1]);
        }

        if (args.Any(x => x.Equals("--self-test-prerequisites-v2", StringComparison.OrdinalIgnoreCase)))
            return PrerequisitesV2SelfTest.Run();

        ApplicationConfiguration.Initialize();
        Application.SetDefaultFont(UiHardening.PreferredFont());

        var renderIndex = Array.FindIndex(args, x => x.Equals("--render-prerequisites-ui", StringComparison.OrdinalIgnoreCase));
        if (renderIndex >= 0)
        {
            if (args.Length <= renderIndex + 3) return 81;
            if (!int.TryParse(args[renderIndex + 2], out var width) || !int.TryParse(args[renderIndex + 3], out var height)) return 82;
            return UiQualification.Render(args[renderIndex + 1], width, height);
        }

        var auditIndex = Array.FindIndex(args, x => x.Equals("--audit-prerequisites-ui", StringComparison.OrdinalIgnoreCase));
        if (auditIndex >= 0)
        {
            if (args.Length <= auditIndex + 2) return 83;
            if (!int.TryParse(args[auditIndex + 1], out var width) || !int.TryParse(args[auditIndex + 2], out var height)) return 84;
            return UiQualification.Audit(width, height);
        }

        using var form = new MainForm();
        UiHardening.Apply(form);
        SafeDiagnosticsIntegration.Attach(form);
        Application.Run(form);
        return 0;
    }
}

internal static class MariaDbServerLocator
{
    public static string? Find(string bin)
    {
        if (string.IsNullOrWhiteSpace(bin) || !Directory.Exists(bin)) return null;
        var mariadbd = Path.Combine(bin, "mariadbd.exe");
        if (File.Exists(mariadbd)) return mariadbd;
        var mysqld = Path.Combine(bin, "mysqld.exe");
        return File.Exists(mysqld) ? mysqld : null;
    }

    public static int SelfTest()
    {
        var root = Path.Combine(Path.GetTempPath(), "sokna-mariadb-locator-" + Guid.NewGuid().ToString("N"));
        try
        {
            Directory.CreateDirectory(root);
            File.WriteAllText(Path.Combine(root, "mariadb-upgrade-wizard.exe"), "not-a-server");
            File.WriteAllText(Path.Combine(root, "mariadb.exe"), "not-a-server");
            File.WriteAllText(Path.Combine(root, "mariadbd.exe"), "server");
            var found = Find(root);
            if (!string.Equals(found, Path.Combine(root, "mariadbd.exe"), StringComparison.OrdinalIgnoreCase)) return 121;
            File.Delete(Path.Combine(root, "mariadbd.exe"));
            File.WriteAllText(Path.Combine(root, "mysqld.exe"), "server-fallback");
            found = Find(root);
            if (!string.Equals(found, Path.Combine(root, "mysqld.exe"), StringComparison.OrdinalIgnoreCase)) return 122;
            File.Delete(Path.Combine(root, "mysqld.exe"));
            if (Find(root) is not null) return 123;
            Console.WriteLine("MARIADB_SERVER_LOCATOR=PASS");
            return 0;
        }
        finally
        {
            try { Directory.Delete(root, true); } catch { }
        }
    }
}

internal static class SafeDiagnosticsIntegration
{
    public static void Attach(MainForm form)
    {
        var diagnosticsButton = new Button
        {
            Text = "عیب‌یابی و تعمیر امن",
            AutoSize = true,
            Padding = new Padding(14, 7, 14, 7),
            RightToLeft = RightToLeft.Yes,
            Name = "SoknaDiagnosticsButton"
        };
        diagnosticsButton.Click += (_, _) =>
        {
            using var dialog = new SafeDiagnosticsForm(() => GetRoot(form), form);
            dialog.ShowDialog(form);
        };

        var analyze = PrivateField<Button>(form, "_analyze");
        if (analyze?.Parent is Control parent && !parent.Controls.ContainsKey(diagnosticsButton.Name))
            parent.Controls.Add(diagnosticsButton);

        // Metadata enrichment must never execute dependency binaries on a timer.
        form.Shown += (_, _) => SafePrerequisitesStateEnricher.TryEnrich(GetRoot(form));
        form.FormClosed += (_, _) => SafePrerequisitesStateEnricher.TryEnrich(GetRoot(form));
    }

    internal static string GetRoot(MainForm form)
        => PrivateField<TextBox>(form, "_root")?.Text?.Trim() ?? "";

    private static T? PrivateField<T>(object instance, string name) where T : class
        => instance.GetType().GetField(name, BindingFlags.Instance | BindingFlags.NonPublic)?.GetValue(instance) as T;
}

internal sealed class SafeDiagnosticsForm : Form
{
    private readonly Func<string> _rootProvider;
    private readonly MainForm _owner;
    private readonly ListView _list = new() { Dock = DockStyle.Fill, View = View.Details, FullRowSelect = true, MultiSelect = false, HideSelection = false, RightToLeft = RightToLeft.Yes };
    private readonly TextBox _detail = new() { Dock = DockStyle.Fill, Multiline = true, ReadOnly = true, ScrollBars = ScrollBars.Vertical, RightToLeft = RightToLeft.Yes };
    private readonly Label _summary = new() { Dock = DockStyle.Fill, AutoSize = true, TextAlign = ContentAlignment.MiddleRight, RightToLeft = RightToLeft.Yes };
    private readonly Button _repair = new() { Text = "اقدام امن", AutoSize = true, Enabled = false, Padding = new Padding(12, 6, 12, 6) };
    private IReadOnlyList<DiagnosticFinding> _findings = [];

    public SafeDiagnosticsForm(Func<string> rootProvider, MainForm owner)
    {
        _rootProvider = rootProvider;
        _owner = owner;
        Text = "عیب‌یابی Prerequisites";
        Width = 980;
        Height = 680;
        MinimumSize = new Size(820, 560);
        StartPosition = FormStartPosition.CenterParent;
        RightToLeft = RightToLeft.Yes;
        RightToLeftLayout = true;
        Font = UiHardening.PreferredFont();

        _list.Columns.Add("کد", 145);
        _list.Columns.Add("سطح", 90);
        _list.Columns.Add("موضوع", 285);
        _list.Columns.Add("اقدام پیشنهادی", 390);
        _list.SelectedIndexChanged += (_, _) => SelectionChanged();

        var refresh = new Button { Text = "بررسی دوباره", AutoSize = true, Padding = new Padding(12, 6, 12, 6) };
        var support = new Button { Text = "بسته پشتیبانی پیشرفته", AutoSize = true, Padding = new Padding(12, 6, 12, 6) };
        var logs = new Button { Text = "باز کردن لاگ‌ها", AutoSize = true, Padding = new Padding(12, 6, 12, 6) };
        var close = new Button { Text = "بستن", AutoSize = true, Padding = new Padding(12, 6, 12, 6) };
        refresh.Click += (_, _) => RefreshDiagnostics();
        _repair.Click += (_, _) => ApplyRepair();
        support.Click += (_, _) => CreateSupport();
        logs.Click += (_, _) => OpenLogs();
        close.Click += (_, _) => Close();

        var buttons = new FlowLayoutPanel { Dock = DockStyle.Fill, AutoSize = true, FlowDirection = FlowDirection.RightToLeft, WrapContents = true, Padding = new Padding(8) };
        buttons.Controls.AddRange([close, support, logs, refresh, _repair]);
        var layout = new TableLayoutPanel { Dock = DockStyle.Fill, ColumnCount = 1, RowCount = 4, Padding = new Padding(12), RightToLeft = RightToLeft.Yes };
        layout.RowStyles.Add(new RowStyle(SizeType.AutoSize));
        layout.RowStyles.Add(new RowStyle(SizeType.Percent, 65));
        layout.RowStyles.Add(new RowStyle(SizeType.Percent, 35));
        layout.RowStyles.Add(new RowStyle(SizeType.AutoSize));
        layout.Controls.Add(_summary, 0, 0);
        layout.Controls.Add(_list, 0, 1);
        layout.Controls.Add(_detail, 0, 2);
        layout.Controls.Add(buttons, 0, 3);
        Controls.Add(layout);
        Shown += (_, _) => RefreshDiagnostics();
    }

    private void RefreshDiagnostics()
    {
        _findings = PrerequisitesDiagnostics.Run(_rootProvider()).ToList();
        var bin = Path.Combine(_rootProvider(), "Infrastructure", "MariaDB", "bin");
        if (Directory.Exists(bin) && MariaDbServerLocator.Find(bin) is null)
            _findings = _findings.Concat([new DiagnosticFinding("PRQ-MARIA-004", FindingSeverity.Error, "MariaDB server binary پیدا نشد", "فقط mariadbd.exe یا mysqld.exe به‌عنوان server معتبر است؛ ابزارهای Upgrade/GUI server محسوب نمی‌شوند.", "Repair نصب موجود را اجرا کنید.")]).ToList();

        _list.BeginUpdate();
        try
        {
            _list.Items.Clear();
            foreach (var f in _findings)
            {
                var item = new ListViewItem(f.Code) { Tag = f };
                item.SubItems.Add(f.Severity.ToString());
                item.SubItems.Add(f.Title);
                item.SubItems.Add(f.Action);
                _list.Items.Add(item);
            }
            var errors = _findings.Count(x => x.Severity == FindingSeverity.Error);
            var warnings = _findings.Count(x => x.Severity == FindingSeverity.Warning);
            _summary.Text = errors == 0 && warnings == 0 ? "زیرساخت در بررسی فعلی مشکل مسدودکننده‌ای ندارد." : $"نتیجه بررسی: {errors} خطای مسدودکننده و {warnings} هشدار.";
            _detail.Clear();
            _repair.Enabled = false;
        }
        finally { _list.EndUpdate(); }
    }

    private void SelectionChanged()
    {
        if (_list.SelectedItems.Count != 1 || _list.SelectedItems[0].Tag is not DiagnosticFinding f) { _detail.Clear(); _repair.Enabled = false; return; }
        _detail.Text = $"کد: {f.Code}{Environment.NewLine}{Environment.NewLine}{f.Title}{Environment.NewLine}{Environment.NewLine}{f.Detail}{Environment.NewLine}{Environment.NewLine}اقدام پیشنهادی:{Environment.NewLine}{f.Action}";
        _repair.Enabled = f.CanRepair;
    }

    private void ApplyRepair()
    {
        if (_list.SelectedItems.Count != 1 || _list.SelectedItems[0].Tag is not DiagnosticFinding f) return;
        bool ok;
        string message;
        if (string.Equals(f.RepairKey, "enrich-state", StringComparison.OrdinalIgnoreCase))
        {
            ok = SafePrerequisitesStateEnricher.TryEnrich(_rootProvider());
            message = ok ? "Provenance در State ثبت شد." : "State تغییری نیاز نداشت یا قابل به‌روزرسانی نبود.";
        }
        else
        {
            ok = PrerequisitesDiagnostics.ApplySafeRepair(f, _rootProvider(), _owner, out message);
        }
        MessageBox.Show(this, message, ok ? "اقدام امن انجام شد" : "اقدام انجام نشد", MessageBoxButtons.OK, ok ? MessageBoxIcon.Information : MessageBoxIcon.Warning);
        RefreshDiagnostics();
    }

    private void CreateSupport()
    {
        try { MessageBox.Show(this, PrerequisitesSupportBundle.Create(_rootProvider(), _findings), "بسته پشتیبانی"); }
        catch (Exception ex) { MessageBox.Show(this, ex.Message, "خطای ساخت بسته پشتیبانی", MessageBoxButtons.OK, MessageBoxIcon.Error); }
    }

    private void OpenLogs()
    {
        var path = Path.Combine(_rootProvider(), "Infrastructure", "Logs");
        try { if (Directory.Exists(path)) System.Diagnostics.Process.Start(new System.Diagnostics.ProcessStartInfo("explorer.exe", $"\"{path}\"") { UseShellExecute = true }); }
        catch (Exception ex) { MessageBox.Show(this, ex.Message); }
    }
}

internal static class SafePrerequisitesStateEnricher
{
    public static bool TryEnrich(string root)
    {
        try
        {
            if (string.IsNullOrWhiteSpace(root) || Path.GetPathRoot(root) is null) return false;
            var statePath = Path.Combine(Path.GetFullPath(root), "Infrastructure", "infrastructure-state.json");
            if (!File.Exists(statePath)) return false;
            var text = File.ReadAllText(statePath, Encoding.UTF8);
            if (JsonNode.Parse(text) is not JsonObject state) return false;

            var releaseLock = Path.Combine(AppContext.BaseDirectory, "release-lock.json");
            var policy = Path.Combine(AppContext.BaseDirectory, "infrastructure-prerequisites.json");
            var expected = ReadExpectedVersions(releaseLock);
            var components = new JsonObject();
            foreach (var pair in expected) components[pair.Key] = new JsonObject { ["expected_version"] = pair.Value };

            var infra = Path.Combine(Path.GetFullPath(root), "Infrastructure");
            components["php"]!["detected"] = PrerequisitesDiagnostics.DetectVersion(Path.Combine(infra, "PHP", "php.exe"), "-v");
            components["apache"]!["detected"] = PrerequisitesDiagnostics.DetectVersion(Path.Combine(infra, "Apache", "bin", "httpd.exe"), "-v");
            var server = MariaDbServerLocator.Find(Path.Combine(infra, "MariaDB", "bin"));
            if (components["mariadb"] is not null)
                components["mariadb"]!["detected"] = server is null ? "<missing-server>" : PrerequisitesDiagnostics.DetectVersion(server, "--version");

            state["setup_version"] = Application.ProductVersion;
            state["release_lock_sha256"] = File.Exists(releaseLock) ? FileSha256(releaseLock) : "<missing>";
            state["infrastructure_policy_sha256"] = File.Exists(policy) ? FileSha256(policy) : "<missing>";
            state["components"] = components;
            state["state_writer"] = "SOKNA Prerequisites Setup";
            state["state_write_mode"] = "atomic-replace";
            state["mariadb_server_locator"] = "exact-name-only";

            var updated = state.ToJsonString(new JsonSerializerOptions { WriteIndented = true });
            if (NormalizeJson(text) == NormalizeJson(updated)) return false;
            var temp = statePath + ".tmp-" + Guid.NewGuid().ToString("N");
            File.WriteAllText(temp, updated, new UTF8Encoding(false));
            File.Move(temp, statePath, true);
            return true;
        }
        catch { return false; }
    }

    private static Dictionary<string, string> ReadExpectedVersions(string path)
    {
        var result = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase) { ["php"] = "<unknown>", ["apache"] = "<unknown>", ["mariadb"] = "<unknown>" };
        if (!File.Exists(path)) return result;
        try
        {
            using var doc = JsonDocument.Parse(File.ReadAllText(path));
            foreach (var a in doc.RootElement.GetProperty("artifacts").EnumerateArray())
            {
                var dep = a.GetProperty("dependency").GetString() ?? "";
                if (result.ContainsKey(dep)) result[dep] = a.GetProperty("version").GetString() ?? "<unknown>";
            }
        }
        catch { }
        return result;
    }

    private static string FileSha256(string path)
    {
        using var stream = File.OpenRead(path);
        return Convert.ToHexString(SHA256.HashData(stream)).ToLowerInvariant();
    }

    private static string NormalizeJson(string text)
    {
        try { return JsonNode.Parse(text)?.ToJsonString() ?? text; }
        catch { return text; }
    }
}
