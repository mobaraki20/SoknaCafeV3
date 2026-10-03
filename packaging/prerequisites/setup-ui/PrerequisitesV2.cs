using Microsoft.Win32;
using System.Diagnostics;
using System.IO.Compression;
using System.Net;
using System.Net.Sockets;
using System.Reflection;
using System.Security.Cryptography;
using System.Security.Principal;
using System.Text;
using System.Text.Json;
using System.Text.Json.Nodes;
using System.Text.RegularExpressions;

namespace Sokna.Prerequisites.Setup;

internal static class ProgramV2
{
    [STAThread]
    public static int Main(string[] args)
    {
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
        DiagnosticsIntegration.Attach(form);
        Application.Run(form);
        return 0;
    }
}

internal static class UiHardening
{
    private static readonly char[] ForbiddenBidi = ['\u2066', '\u2067', '\u2068', '\u2069'];

    public static Font PreferredFont()
    {
        foreach (var name in new[] { "Vazirmatn", "Tahoma", "Segoe UI" })
        {
            try
            {
                using var probe = new Font(name, 10f, FontStyle.Regular, GraphicsUnit.Point);
                if (string.Equals(probe.Name, name, StringComparison.OrdinalIgnoreCase))
                    return new Font(name, 10f, FontStyle.Regular, GraphicsUnit.Point);
            }
            catch { }
        }
        return SystemFonts.MessageBoxFont;
    }

    public static void Apply(Form form)
    {
        form.RightToLeft = RightToLeft.Yes;
        form.RightToLeftLayout = true;
        form.AutoScaleMode = AutoScaleMode.Dpi;
        ApplyRecursive(form, PreferredFont().FontFamily);
    }

    private static void ApplyRecursive(Control control, FontFamily family)
    {
        try { control.Font = new Font(family, control.Font.Size, control.Font.Style, GraphicsUnit.Point); } catch { }

        switch (control)
        {
            case TextBox box when control is not RichTextBox:
                box.RightToLeft = RightToLeft.No;
                break;
            case NumericUpDown numeric:
                numeric.RightToLeft = RightToLeft.No;
                break;
            case RichTextBox rich:
                rich.RightToLeft = RightToLeft.Yes;
                break;
            case Label label:
                label.RightToLeft = RightToLeft.Yes;
                break;
            case Button button:
                button.RightToLeft = RightToLeft.Yes;
                break;
            case GroupBox group:
                group.RightToLeft = RightToLeft.Yes;
                break;
            case Panel panel when panel.AutoScroll:
                panel.RightToLeft = RightToLeft.Yes;
                panel.HorizontalScroll.Enabled = false;
                break;
        }

        foreach (Control child in control.Controls)
            ApplyRecursive(child, family);
    }

    public static bool HasForbiddenBidi(Control control)
    {
        if (!string.IsNullOrEmpty(control.Text) && control.Text.IndexOfAny(ForbiddenBidi) >= 0) return true;
        foreach (Control child in control.Controls)
            if (HasForbiddenBidi(child)) return true;
        return false;
    }
}

internal static class DiagnosticsIntegration
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
            using var dialog = new DiagnosticsForm(() => GetRoot(form), form);
            dialog.ShowDialog(form);
        };

        var analyze = PrivateField<Button>(form, "_analyze");
        if (analyze?.Parent is Control parent && !parent.Controls.ContainsKey(diagnosticsButton.Name))
            parent.Controls.Add(diagnosticsButton);

        var timer = new System.Windows.Forms.Timer { Interval = 5000 };
        timer.Tick += (_, _) => PrerequisitesStateEnricher.TryEnrich(GetRoot(form));
        form.Shown += (_, _) =>
        {
            PrerequisitesStateEnricher.TryEnrich(GetRoot(form));
            timer.Start();
        };
        form.FormClosed += (_, _) =>
        {
            timer.Stop();
            timer.Dispose();
            PrerequisitesStateEnricher.TryEnrich(GetRoot(form));
        };
    }

    internal static string GetRoot(MainForm form)
        => PrivateField<TextBox>(form, "_root")?.Text?.Trim() ?? "";

    internal static bool TrySetApachePort(MainForm form, int port)
    {
        var control = PrivateField<NumericUpDown>(form, "_apachePort");
        if (control is null || port < control.Minimum || port > control.Maximum) return false;
        control.Value = port;
        return true;
    }

    private static T? PrivateField<T>(object instance, string name) where T : class
        => instance.GetType().GetField(name, BindingFlags.Instance | BindingFlags.NonPublic)?.GetValue(instance) as T;
}

internal enum FindingSeverity { Ok, Info, Warning, Error }

internal sealed record DiagnosticFinding(
    string Code,
    FindingSeverity Severity,
    string Title,
    string Detail,
    string Action,
    bool CanRepair = false,
    string? RepairKey = null);

internal sealed record ServiceSnapshot(string Name, bool Registered, bool Running, int Pid, string ImagePath, string Raw);

internal static class PrerequisitesDiagnostics
{
    private static readonly Regex StateRx = new(@"STATE\s*:\s*(?<n>\d+)\s+(?<name>\w+)", RegexOptions.IgnoreCase | RegexOptions.Compiled);
    private static readonly Regex PidRx = new(@"PID\s*:\s*(?<pid>\d+)", RegexOptions.IgnoreCase | RegexOptions.Compiled);

    public static IReadOnlyList<DiagnosticFinding> Run(string root)
    {
        var list = new List<DiagnosticFinding>();
        if (string.IsNullOrWhiteSpace(root) || Path.GetPathRoot(root) is null)
        {
            list.Add(new("PRQ-ROOT-001", FindingSeverity.Error, "مسیر ریشه معتبر نیست", "Root انتخاب‌شده قابل تفسیر نیست.", "یک مسیر کامل مثل D:\\SOKNA انتخاب کنید."));
            return list;
        }

        root = Path.GetFullPath(root);
        var infra = Path.Combine(root, "Infrastructure");
        var php = Path.Combine(infra, "PHP");
        var apache = Path.Combine(infra, "Apache");
        var maria = Path.Combine(infra, "MariaDB");
        var data = Path.Combine(root, "Data", "MariaDB");
        var state = Path.Combine(infra, "infrastructure-state.json");

        if (!IsAdministrator())
            list.Add(new("PRQ-UAC-001", FindingSeverity.Warning, "برنامه با دسترسی Administrator اجرا نشده", "تغییر سرویس‌های Windows ممکن است شکست بخورد.", "Setup را با دسترسی Administrator اجرا کنید."));
        else
            list.Add(new("PRQ-UAC-OK", FindingSeverity.Ok, "دسترسی Administrator برقرار است", "سطح دسترسی برای مدیریت سرویس‌ها مناسب است.", "اقدامی لازم نیست."));

        try
        {
            var drive = new DriveInfo(Path.GetPathRoot(root)!);
            if (drive.IsReady && drive.AvailableFreeSpace < 2L * 1024 * 1024 * 1024)
                list.Add(new("PRQ-ROOT-002", FindingSeverity.Warning, "فضای خالی کم است", $"فضای آزاد درایو حدود {drive.AvailableFreeSpace / 1024 / 1024} MB است.", "پیش از نصب یا Repair حداقل چند گیگابایت فضای آزاد ایجاد کنید."));
        }
        catch { }

        if (!File.Exists(state))
            list.Add(new("PRQ-STATE-001", FindingSeverity.Warning, "State زیرساخت پیدا نشد", state, "اگر نصب قبلی وجود دارد حالت Repair/Recover را بررسی کنید."));
        else
        {
            try
            {
                var node = JsonNode.Parse(File.ReadAllText(state)) as JsonObject;
                var setupVersion = node?["setup_version"]?.GetValue<string>();
                if (string.IsNullOrWhiteSpace(setupVersion))
                    list.Add(new("PRQ-STATE-002", FindingSeverity.Warning, "State فاقد provenance جدید است", "نسخه Setup و fingerprint سیاست‌ها هنوز در State ثبت نشده است.", "ثبت provenance بدون تغییر Data انجام شود.", true, "enrich-state"));
                else
                    list.Add(new("PRQ-STATE-OK", FindingSeverity.Ok, "State و provenance قابل خواندن است", $"Setup version: {setupVersion}", "اقدامی لازم نیست."));
            }
            catch (Exception ex)
            {
                list.Add(new("PRQ-STATE-003", FindingSeverity.Error, "State قابل خواندن نیست", ex.Message, "پیش از هر Repair از فایل State و Data نسخه پشتیبان بگیرید."));
            }
        }

        if (!File.Exists(Path.Combine(php, "php.exe")))
            list.Add(new("PRQ-PHP-001", FindingSeverity.Error, "PHP پیدا نشد", php, "Repair نصب موجود یا نصب جدید را اجرا کنید."));
        else
            list.Add(new("PRQ-PHP-OK", FindingSeverity.Ok, "PHP موجود است", DetectVersion(Path.Combine(php, "php.exe"), "-v"), "اقدامی لازم نیست."));

        var apacheExe = Path.Combine(apache, "bin", "httpd.exe");
        if (!File.Exists(apacheExe))
            list.Add(new("PRQ-APACHE-001", FindingSeverity.Error, "Apache binary پیدا نشد", apacheExe, "Repair نصب موجود را اجرا کنید."));

        var apacheService = Service("SoknaApache");
        if (!apacheService.Registered)
            list.Add(new("PRQ-APACHE-002", FindingSeverity.Error, "سرویس Apache ثبت نشده", "Windows Service با نام SoknaApache وجود ندارد.", "Repair/Recover را اجرا کنید."));
        else if (!apacheService.Running)
            list.Add(new("PRQ-APACHE-003", FindingSeverity.Warning, "سرویس Apache متوقف است", apacheService.ImagePath, "شروع امن سرویس Apache.", true, "start-apache"));
        else
            list.Add(new("PRQ-APACHE-OK", FindingSeverity.Ok, "Apache در حال اجراست", $"PID={apacheService.Pid}; {apacheService.ImagePath}", "اقدامی لازم نیست."));

        var mariaExe = Directory.Exists(Path.Combine(maria, "bin"))
            ? Directory.EnumerateFiles(Path.Combine(maria, "bin"), "maria*d.exe").FirstOrDefault() ?? Path.Combine(maria, "bin", "mysqld.exe")
            : Path.Combine(maria, "bin", "mariadbd.exe");
        if (!File.Exists(mariaExe))
            list.Add(new("PRQ-MARIA-001", FindingSeverity.Error, "MariaDB binary پیدا نشد", mariaExe, "Repair نصب موجود را اجرا کنید."));

        var dataInitialized = Directory.Exists(Path.Combine(data, "mysql"));
        if (dataInitialized)
            list.Add(new("PRQ-DATA-OK", FindingSeverity.Ok, "MariaDB Data موجود و محافظت‌شده است", data, "Data موجود نباید initialize یا حذف شود."));
        else
            list.Add(new("PRQ-DATA-001", FindingSeverity.Info, "MariaDB Data هنوز initialize نشده", data, "فقط در Fresh Install واقعی Data جدید ایجاد شود."));

        var mariaService = Service("SoknaMariaDB");
        if (!mariaService.Registered)
            list.Add(new("PRQ-MARIA-002", FindingSeverity.Warning, "سرویس MariaDB ثبت نشده", "Windows Service با نام SoknaMariaDB وجود ندارد.", dataInitialized ? "Recover/Repair را اجرا کنید؛ Data حفظ می‌شود." : "در نصب جدید، Setup سرویس را ایجاد می‌کند."));
        else if (!mariaService.Running && dataInitialized)
            list.Add(new("PRQ-MARIA-003", FindingSeverity.Warning, "سرویس MariaDB متوقف است", mariaService.ImagePath, "شروع امن سرویس MariaDB بدون تغییر Data.", true, "start-maria"));
        else if (mariaService.Running)
            list.Add(new("PRQ-MARIA-OK", FindingSeverity.Ok, "MariaDB در حال اجراست", $"PID={mariaService.Pid}; {mariaService.ImagePath}", "اقدامی لازم نیست."));

        var port = ReadApachePort(state) ?? 18080;
        var owner = PortOwner(port);
        if (owner is not null && !owner.Value.Name.Contains("httpd", StringComparison.OrdinalIgnoreCase))
        {
            var suggestion = LocalEndpointPortPolicy.FallbackCandidates.FirstOrDefault(p => LocalEndpointPortPolicy.CanBindLoopback(p, out _));
            list.Add(new("PRQ-PORT-001", FindingSeverity.Warning, $"پورت {port} در اختیار برنامه دیگری است", $"PID={owner.Value.Pid}; Process={owner.Value.Name}", suggestion > 0 ? $"پورت {suggestion} آزاد است و می‌تواند در Setup انتخاب شود." : "یک پورت loopback آزاد انتخاب کنید.", suggestion > 0, suggestion > 0 ? $"port:{suggestion}" : null));
        }
        else if (owner is not null)
            list.Add(new("PRQ-PORT-OK", FindingSeverity.Ok, $"پورت {port} متعلق به Apache است", $"PID={owner.Value.Pid}", "اقدامی لازم نیست."));
        else
            list.Add(new("PRQ-PORT-INFO", FindingSeverity.Info, $"روی پورت {port} Listener فعالی دیده نشد", "اگر Apache باید فعال باشد، وضعیت سرویس را بررسی کنید.", "در صورت نیاز Apache را شروع کنید."));

        try
        {
            var expectedMaria = ExpectedDependencyVersion("mariadb");
            var ownership = InfrastructureOwnershipDetector.Detect(root, expectedMaria);
            if (ownership.HasConflict)
                list.Add(new("PRQ-ROOT-003", FindingSeverity.Error, "تداخل Cross-root شناسایی شد", string.Join(Environment.NewLine, ownership.Conflicts), "از همان Root نصب قبلی استفاده کنید؛ Migration یا حذف خودکار انجام نمی‌شود."));
            else
                list.Add(new("PRQ-ROOT-OK", FindingSeverity.Ok, "تداخل Cross-root دیده نشد", root, "اقدامی لازم نیست."));
        }
        catch (Exception ex)
        {
            list.Add(new("PRQ-ROOT-004", FindingSeverity.Warning, "Ownership preflight کامل نشد", ex.Message, "قبل از Repair مسیر سرویس‌ها را در بسته پشتیبانی بررسی کنید."));
        }

        return list;
    }

    public static bool ApplySafeRepair(DiagnosticFinding finding, string root, MainForm? owner, out string message)
    {
        try
        {
            switch (finding.RepairKey)
            {
                case "enrich-state":
                    if (PrerequisitesStateEnricher.TryEnrich(root)) { message = "Provenance در State ثبت شد."; return true; }
                    message = "State تغییری نیاز نداشت یا در حال استفاده بود.";
                    return true;
                case "start-apache":
                    return StartService("SoknaApache", out message);
                case "start-maria":
                    return StartService("SoknaMariaDB", out message);
                default:
                    if (finding.RepairKey?.StartsWith("port:", StringComparison.OrdinalIgnoreCase) == true && owner is not null && int.TryParse(finding.RepairKey[5..], out var port))
                    {
                        var changed = DiagnosticsIntegration.TrySetApachePort(owner, port);
                        message = changed ? $"پورت پیشنهادی {port} در فرم اصلی انتخاب شد؛ برای اعمال، Repair را اجرا کنید." : "امکان تغییر پورت در فرم اصلی نبود.";
                        return changed;
                    }
                    message = "برای این مورد تعمیر خودکار تعریف نشده است.";
                    return false;
            }
        }
        catch (Exception ex)
        {
            message = ex.Message;
            return false;
        }
    }

    public static ServiceSnapshot Service(string name)
    {
        var image = "";
        try
        {
            using var key = Registry.LocalMachine.OpenSubKey($@"SYSTEM\CurrentControlSet\Services\{name}");
            image = key?.GetValue("ImagePath")?.ToString() ?? "";
            if (key is null) return new(name, false, false, 0, "", "");
        }
        catch { return new(name, false, false, 0, "", ""); }

        var raw = RunCapture("sc.exe", $"queryex \"{name}\"");
        var state = StateRx.Match(raw);
        var pid = PidRx.Match(raw);
        var running = state.Success && state.Groups["n"].Value == "4";
        var processId = pid.Success && int.TryParse(pid.Groups["pid"].Value, out var parsed) ? parsed : 0;
        return new(name, true, running, processId, Environment.ExpandEnvironmentVariables(image), raw);
    }

    internal static string RunCapture(string file, string arguments, int timeoutMs = 15000)
    {
        try
        {
            using var process = new Process
            {
                StartInfo = new ProcessStartInfo(file, arguments)
                {
                    UseShellExecute = false,
                    RedirectStandardOutput = true,
                    RedirectStandardError = true,
                    CreateNoWindow = true
                }
            };
            process.Start();
            var output = process.StandardOutput.ReadToEnd();
            var error = process.StandardError.ReadToEnd();
            if (!process.WaitForExit(timeoutMs))
            {
                try { process.Kill(true); } catch { }
                return output + Environment.NewLine + "TIMEOUT";
            }
            return output + (string.IsNullOrWhiteSpace(error) ? "" : Environment.NewLine + error);
        }
        catch (Exception ex) { return "ERROR: " + ex.Message; }
    }

    internal static string DetectVersion(string exe, string args)
    {
        if (!File.Exists(exe)) return "<missing>";
        var text = RunCapture(exe, args, 10000).Trim();
        var first = text.Split(['\r', '\n'], StringSplitOptions.RemoveEmptyEntries).FirstOrDefault() ?? "<unknown>";
        return first.Length > 300 ? first[..300] : first;
    }

    private static bool StartService(string name, out string message)
    {
        var text = RunCapture("sc.exe", $"start \"{name}\"");
        for (var i = 0; i < 30; i++)
        {
            Thread.Sleep(300);
            if (Service(name).Running)
            {
                message = $"سرویس {name} شروع شد.";
                return true;
            }
        }
        message = $"سرویس {name} به حالت Running نرسید. خروجی: {text}";
        return false;
    }

    private static bool IsAdministrator()
    {
        try
        {
            using var identity = WindowsIdentity.GetCurrent();
            return new WindowsPrincipal(identity).IsInRole(WindowsBuiltInRole.Administrator);
        }
        catch { return false; }
    }

    private static int? ReadApachePort(string statePath)
    {
        try
        {
            if (!File.Exists(statePath)) return null;
            using var doc = JsonDocument.Parse(File.ReadAllText(statePath));
            if (doc.RootElement.TryGetProperty("endpoints", out var ep) && ep.TryGetProperty("local_web", out var local) && local.TryGetProperty("port", out var port) && port.TryGetInt32(out var value))
                return value;
        }
        catch { }
        return null;
    }

    private static (int Pid, string Name)? PortOwner(int port)
    {
        var text = RunCapture("netstat.exe", "-ano -p tcp");
        foreach (var line in text.Split(['\r', '\n'], StringSplitOptions.RemoveEmptyEntries))
        {
            if (!line.Contains("LISTENING", StringComparison.OrdinalIgnoreCase)) continue;
            var columns = Regex.Split(line.Trim(), @"\s+");
            if (columns.Length < 5) continue;
            var local = columns[1];
            var colon = local.LastIndexOf(':');
            if (colon < 0 || !int.TryParse(local[(colon + 1)..], out var parsedPort) || parsedPort != port) continue;
            if (!int.TryParse(columns[^1], out var pid)) continue;
            var name = "unknown";
            try { name = Process.GetProcessById(pid).ProcessName; } catch { }
            return (pid, name);
        }
        return null;
    }

    private static string ExpectedDependencyVersion(string dependency)
    {
        try
        {
            var path = Path.Combine(AppContext.BaseDirectory, "release-lock.json");
            using var doc = JsonDocument.Parse(File.ReadAllText(path));
            foreach (var artifact in doc.RootElement.GetProperty("artifacts").EnumerateArray())
                if (artifact.GetProperty("dependency").GetString()?.Equals(dependency, StringComparison.OrdinalIgnoreCase) == true)
                    return artifact.GetProperty("version").GetString() ?? "";
        }
        catch { }
        return "";
    }
}

internal sealed class DiagnosticsForm : Form
{
    private readonly Func<string> _rootProvider;
    private readonly MainForm _owner;
    private readonly ListView _list = new() { Dock = DockStyle.Fill, View = View.Details, FullRowSelect = true, MultiSelect = false, HideSelection = false, RightToLeft = RightToLeft.Yes };
    private readonly TextBox _detail = new() { Dock = DockStyle.Fill, Multiline = true, ReadOnly = true, ScrollBars = ScrollBars.Vertical, RightToLeft = RightToLeft.Yes };
    private readonly Label _summary = new() { Dock = DockStyle.Fill, AutoSize = true, TextAlign = ContentAlignment.MiddleRight, RightToLeft = RightToLeft.Yes };
    private readonly Button _repair = new() { Text = "اقدام امن", AutoSize = true, Enabled = false, Padding = new Padding(12, 6, 12, 6) };
    private IReadOnlyList<DiagnosticFinding> _findings = [];

    public DiagnosticsForm(Func<string> rootProvider, MainForm owner)
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

        var root = new TableLayoutPanel { Dock = DockStyle.Fill, ColumnCount = 1, RowCount = 4, Padding = new Padding(12), RightToLeft = RightToLeft.Yes };
        root.RowStyles.Add(new RowStyle(SizeType.AutoSize));
        root.RowStyles.Add(new RowStyle(SizeType.Percent, 65));
        root.RowStyles.Add(new RowStyle(SizeType.Percent, 35));
        root.RowStyles.Add(new RowStyle(SizeType.AutoSize));
        root.Controls.Add(_summary, 0, 0);
        root.Controls.Add(_list, 0, 1);
        root.Controls.Add(_detail, 0, 2);
        root.Controls.Add(buttons, 0, 3);
        Controls.Add(root);
        Shown += (_, _) => RefreshDiagnostics();
    }

    private void RefreshDiagnostics()
    {
        Cursor = Cursors.WaitCursor;
        try
        {
            _findings = PrerequisitesDiagnostics.Run(_rootProvider());
            _list.BeginUpdate();
            _list.Items.Clear();
            foreach (var f in _findings)
            {
                var item = new ListViewItem(f.Code) { Tag = f };
                item.SubItems.Add(SeverityText(f.Severity));
                item.SubItems.Add(f.Title);
                item.SubItems.Add(f.Action);
                _list.Items.Add(item);
            }
            var errors = _findings.Count(x => x.Severity == FindingSeverity.Error);
            var warnings = _findings.Count(x => x.Severity == FindingSeverity.Warning);
            _summary.Text = errors == 0 && warnings == 0
                ? "زیرساخت در بررسی فعلی مشکل مسدودکننده‌ای ندارد."
                : $"نتیجه بررسی: {errors} خطای مسدودکننده و {warnings} هشدار. هیچ Data به‌صورت خودکار حذف یا initialize نمی‌شود.";
            _detail.Clear();
            _repair.Enabled = false;
        }
        finally
        {
            _list.EndUpdate();
            Cursor = Cursors.Default;
        }
    }

    private void SelectionChanged()
    {
        if (_list.SelectedItems.Count != 1 || _list.SelectedItems[0].Tag is not DiagnosticFinding finding)
        {
            _detail.Clear();
            _repair.Enabled = false;
            return;
        }
        _detail.Text = $"کد: {finding.Code}{Environment.NewLine}سطح: {SeverityText(finding.Severity)}{Environment.NewLine}{Environment.NewLine}{finding.Title}{Environment.NewLine}{Environment.NewLine}{finding.Detail}{Environment.NewLine}{Environment.NewLine}اقدام پیشنهادی:{Environment.NewLine}{finding.Action}";
        _repair.Enabled = finding.CanRepair;
    }

    private void ApplyRepair()
    {
        if (_list.SelectedItems.Count != 1 || _list.SelectedItems[0].Tag is not DiagnosticFinding finding) return;
        var ok = PrerequisitesDiagnostics.ApplySafeRepair(finding, _rootProvider(), _owner, out var message);
        MessageBox.Show(this, message, ok ? "اقدام امن انجام شد" : "اقدام انجام نشد", MessageBoxButtons.OK, ok ? MessageBoxIcon.Information : MessageBoxIcon.Warning);
        RefreshDiagnostics();
    }

    private void CreateSupport()
    {
        try
        {
            var path = PrerequisitesSupportBundle.Create(_rootProvider(), _findings.Count > 0 ? _findings : PrerequisitesDiagnostics.Run(_rootProvider()));
            MessageBox.Show(this, $"بسته پشتیبانی ساخته شد:{Environment.NewLine}{path}{Environment.NewLine}{Environment.NewLine}رمزها و credentialهای شناخته‌شده عمداً وارد بسته نمی‌شوند.", "بسته پشتیبانی", MessageBoxButtons.OK, MessageBoxIcon.Information);
        }
        catch (Exception ex)
        {
            MessageBox.Show(this, ex.Message, "خطای ساخت بسته پشتیبانی", MessageBoxButtons.OK, MessageBoxIcon.Error);
        }
    }

    private void OpenLogs()
    {
        var path = Path.Combine(_rootProvider(), "Infrastructure", "Logs");
        try { if (Directory.Exists(path)) Process.Start(new ProcessStartInfo("explorer.exe", $"\"{path}\"") { UseShellExecute = true }); }
        catch (Exception ex) { MessageBox.Show(this, ex.Message); }
    }

    private static string SeverityText(FindingSeverity severity) => severity switch
    {
        FindingSeverity.Error => "خطا",
        FindingSeverity.Warning => "هشدار",
        FindingSeverity.Info => "اطلاع",
        _ => "سالم"
    };
}

internal static class PrerequisitesStateEnricher
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
            foreach (var pair in expected)
                components[pair.Key] = new JsonObject { ["expected_version"] = pair.Value };

            var infra = Path.Combine(Path.GetFullPath(root), "Infrastructure");
            components["php"]!["detected"] = PrerequisitesDiagnostics.DetectVersion(Path.Combine(infra, "PHP", "php.exe"), "-v");
            components["apache"]!["detected"] = PrerequisitesDiagnostics.DetectVersion(Path.Combine(infra, "Apache", "bin", "httpd.exe"), "-v");
            var maria = Directory.Exists(Path.Combine(infra, "MariaDB", "bin"))
                ? Directory.EnumerateFiles(Path.Combine(infra, "MariaDB", "bin"), "maria*d.exe").FirstOrDefault() ?? Path.Combine(infra, "MariaDB", "bin", "mysqld.exe")
                : Path.Combine(infra, "MariaDB", "bin", "mariadbd.exe");
            if (components["mariadb"] is not null)
                components["mariadb"]!["detected"] = PrerequisitesDiagnostics.DetectVersion(maria, "--version");

            state["setup_version"] = Application.ProductVersion;
            state["release_lock_sha256"] = File.Exists(releaseLock) ? FileSha256(releaseLock) : "<missing>";
            state["infrastructure_policy_sha256"] = File.Exists(policy) ? FileSha256(policy) : "<missing>";
            state["components"] = components;
            state["state_writer"] = "SOKNA Prerequisites Setup";
            state["state_write_mode"] = "atomic-replace";

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
        var result = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase)
        {
            ["php"] = "<unknown>", ["apache"] = "<unknown>", ["mariadb"] = "<unknown>"
        };
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

internal static class PrerequisitesSupportBundle
{
    private static readonly Regex SecretAssignment = new(@"(?im)(password|passwd|pwd|token|secret|authorization)\s*[:=]\s*([^\r\n;]+)", RegexOptions.Compiled);
    private static readonly Regex UriCredential = new(@"(?i)(https?://)([^/@:\s]+):([^/@\s]+)@", RegexOptions.Compiled);

    public static string Create(string root, IReadOnlyList<DiagnosticFinding> findings)
    {
        if (string.IsNullOrWhiteSpace(root) || Path.GetPathRoot(root) is null) throw new InvalidOperationException("Root معتبر نیست.");
        root = Path.GetFullPath(root);
        var infra = Path.Combine(root, "Infrastructure");
        var supportDir = Path.Combine(infra, "Support");
        Directory.CreateDirectory(supportDir);
        var temp = Path.Combine(Path.GetTempPath(), "sokna-prereq-support-" + Guid.NewGuid().ToString("N"));
        Directory.CreateDirectory(temp);
        try
        {
            File.WriteAllText(Path.Combine(temp, "diagnostics-v2.json"), JsonSerializer.Serialize(findings, new JsonSerializerOptions { WriteIndented = true }), new UTF8Encoding(false));
            File.WriteAllText(Path.Combine(temp, "diagnostics-v2.txt"), BuildHuman(findings), new UTF8Encoding(false));
            File.WriteAllText(Path.Combine(temp, "system.txt"), BuildSystem(root), new UTF8Encoding(false));

            var serviceDir = Path.Combine(temp, "services");
            Directory.CreateDirectory(serviceDir);
            foreach (var svc in new[] { "SoknaApache", "SoknaMariaDB" })
            {
                File.WriteAllText(Path.Combine(serviceDir, svc + "-queryex.txt"), Redact(PrerequisitesDiagnostics.RunCapture("sc.exe", $"queryex \"{svc}\"")), new UTF8Encoding(false));
                File.WriteAllText(Path.Combine(serviceDir, svc + "-qc.txt"), Redact(PrerequisitesDiagnostics.RunCapture("sc.exe", $"qc \"{svc}\"")), new UTF8Encoding(false));
            }

            var state = Path.Combine(infra, "infrastructure-state.json");
            CopySanitized(state, Path.Combine(temp, "infrastructure-state.json"));
            CopySanitized(Path.Combine(infra, "Apache", "conf", "httpd.conf"), Path.Combine(temp, "apache-httpd.conf"));
            CopySanitized(Path.Combine(infra, "PHP", "php.ini"), Path.Combine(temp, "php.ini"));
            CopySanitized(Path.Combine(infra, "Apache", "logs", "error.log"), Path.Combine(temp, "apache-error.log"));

            var logOut = Path.Combine(temp, "logs");
            Directory.CreateDirectory(logOut);
            var logs = Path.Combine(infra, "Logs");
            if (Directory.Exists(logs))
                foreach (var file in Directory.GetFiles(logs, "*.log").OrderBy(x => File.GetLastWriteTimeUtc(x)).TakeLast(20))
                    CopySanitized(file, Path.Combine(logOut, Path.GetFileName(file)));

            File.WriteAllText(Path.Combine(temp, "listening-ports.txt"), FilterRelevantNetstat(PrerequisitesDiagnostics.RunCapture("netstat.exe", "-ano -p tcp")), new UTF8Encoding(false));
            File.WriteAllText(Path.Combine(temp, "processes.txt"), RelevantProcesses(), new UTF8Encoding(false));
            File.WriteAllText(Path.Combine(temp, "service-control-manager-events.txt"), Redact(PrerequisitesDiagnostics.RunCapture("wevtutil.exe", "qe System /q:\"*[System[Provider[@Name='Service Control Manager'] and TimeCreated[timediff(@SystemTime) <= 604800000]]]\" /c:100 /rd:true /f:text", 30000)), new UTF8Encoding(false));

            var output = Path.Combine(supportDir, $"SOKNA-Prerequisites-Support-v2-{DateTime.Now:yyyyMMdd-HHmmss}.zip");
            ZipFile.CreateFromDirectory(temp, output, CompressionLevel.Optimal, false);
            return output;
        }
        finally
        {
            try { Directory.Delete(temp, true); } catch { }
        }
    }

    internal static string Redact(string text)
    {
        text = SecretAssignment.Replace(text, m => m.Groups[1].Value + "=<redacted>");
        text = UriCredential.Replace(text, "$1<redacted>:<redacted>@");
        return text;
    }

    private static void CopySanitized(string source, string destination)
    {
        if (!File.Exists(source)) return;
        try { File.WriteAllText(destination, Redact(File.ReadAllText(source)), new UTF8Encoding(false)); } catch { }
    }

    private static string BuildHuman(IReadOnlyList<DiagnosticFinding> findings)
    {
        var sb = new StringBuilder();
        sb.AppendLine("SOKNA Prerequisites diagnostics v2");
        sb.AppendLine("No known passwords, tokens or application credentials are intentionally included.");
        sb.AppendLine();
        foreach (var f in findings)
        {
            sb.AppendLine($"[{f.Severity}] {f.Code} — {f.Title}");
            sb.AppendLine("Detail: " + f.Detail);
            sb.AppendLine("Action: " + f.Action);
            sb.AppendLine();
        }
        return Redact(sb.ToString());
    }

    private static string BuildSystem(string root)
    {
        var sb = new StringBuilder();
        sb.AppendLine("SOKNA Prerequisites support bundle v2");
        sb.AppendLine("SetupVersion=" + Application.ProductVersion);
        sb.AppendLine("OS=" + Environment.OSVersion);
        sb.AppendLine("Is64BitOS=" + Environment.Is64BitOperatingSystem);
        sb.AppendLine("Root=" + root);
        try
        {
            var drive = new DriveInfo(Path.GetPathRoot(root)!);
            if (drive.IsReady)
            {
                sb.AppendLine("DriveFormat=" + drive.DriveFormat);
                sb.AppendLine("DriveFreeBytes=" + drive.AvailableFreeSpace);
            }
        }
        catch { }
        return Redact(sb.ToString());
    }

    private static string FilterRelevantNetstat(string text)
    {
        var allowed = new HashSet<int>(LocalEndpointPortPolicy.FallbackCandidates) { 18080, 3306 };
        var sb = new StringBuilder();
        foreach (var line in text.Split(['\r', '\n'], StringSplitOptions.RemoveEmptyEntries))
        {
            var columns = Regex.Split(line.Trim(), @"\s+");
            if (columns.Length < 2) continue;
            var local = columns[1];
            var colon = local.LastIndexOf(':');
            if (colon > 0 && int.TryParse(local[(colon + 1)..], out var port) && allowed.Contains(port)) sb.AppendLine(line);
        }
        return sb.ToString();
    }

    private static string RelevantProcesses()
    {
        var sb = new StringBuilder();
        foreach (var p in Process.GetProcesses().OrderBy(x => x.ProcessName))
        {
            if (!(p.ProcessName.Contains("httpd", StringComparison.OrdinalIgnoreCase) || p.ProcessName.Contains("maria", StringComparison.OrdinalIgnoreCase) || p.ProcessName.Contains("mysql", StringComparison.OrdinalIgnoreCase) || p.ProcessName.Equals("php", StringComparison.OrdinalIgnoreCase))) continue;
            try { sb.AppendLine($"PID={p.Id}; Name={p.ProcessName}; Path={p.MainModule?.FileName}"); }
            catch { sb.AppendLine($"PID={p.Id}; Name={p.ProcessName}; Path=<unavailable>"); }
            finally { p.Dispose(); }
        }
        return Redact(sb.ToString());
    }
}

internal static class UiQualification
{
    public static int Render(string output, int width, int height)
    {
        try
        {
            Directory.CreateDirectory(Path.GetDirectoryName(Path.GetFullPath(output))!);
            using var form = new MainForm();
            UiHardening.Apply(form);
            DiagnosticsIntegration.Attach(form);
            form.StartPosition = FormStartPosition.Manual;
            form.Location = new Point(0, 0);
            form.ShowInTaskbar = false;
            form.Show();
            Application.DoEvents();
            form.Size = new Size(Math.Max(width, 900), Math.Max(height, 650));
            form.Refresh();
            Application.DoEvents();
            using var bitmap = new Bitmap(form.ClientSize.Width, form.ClientSize.Height);
            form.DrawToBitmap(bitmap, new Rectangle(Point.Empty, bitmap.Size));
            bitmap.Save(output, System.Drawing.Imaging.ImageFormat.Png);
            form.Close();
            return File.Exists(output) && new FileInfo(output).Length > 20_000 ? 0 : 85;
        }
        catch (Exception ex)
        {
            Console.Error.WriteLine(ex);
            return 86;
        }
    }

    public static int Audit(int width, int height)
    {
        try
        {
            using var form = new MainForm();
            UiHardening.Apply(form);
            DiagnosticsIntegration.Attach(form);
            form.ShowInTaskbar = false;
            form.Show();
            Application.DoEvents();
            form.Size = new Size(Math.Max(width, 900), Math.Max(height, 650));
            form.Refresh();
            Application.DoEvents();
            if (!form.RightToLeftLayout) return 87;
            if (UiHardening.HasForbiddenBidi(form)) return 88;
            var diagButton = FindByName(form, "SoknaDiagnosticsButton");
            if (diagButton is null || !diagButton.Visible) return 89;
            foreach (var button in AllControls(form).OfType<Button>())
            {
                if (button.Width < Math.Min(button.PreferredSize.Width, 40) || button.Height < Math.Min(button.PreferredSize.Height, 24)) return 90;
            }
            form.Close();
            return 0;
        }
        catch (Exception ex)
        {
            Console.Error.WriteLine(ex);
            return 91;
        }
    }

    private static Control? FindByName(Control root, string name)
        => AllControls(root).FirstOrDefault(x => x.Name == name);

    private static IEnumerable<Control> AllControls(Control root)
    {
        foreach (Control child in root.Controls)
        {
            yield return child;
            foreach (var nested in AllControls(child)) yield return nested;
        }
    }
}

internal static class PrerequisitesV2SelfTest
{
    public static int Run()
    {
        var temp = Path.Combine(Path.GetTempPath(), "sokna-prereq-v2-selftest-" + Guid.NewGuid().ToString("N"));
        try
        {
            Directory.CreateDirectory(Path.Combine(temp, "Infrastructure"));
            File.WriteAllText(Path.Combine(temp, "Infrastructure", "infrastructure-state.json"), "{\"format\":\"sokna-infrastructure-state-v1\",\"schema_version\":1}");
            if (!PrerequisitesStateEnricher.TryEnrich(temp)) return 101;
            var state = JsonNode.Parse(File.ReadAllText(Path.Combine(temp, "Infrastructure", "infrastructure-state.json"))) as JsonObject;
            if (state?["setup_version"]?.GetValue<string>() != Application.ProductVersion) return 102;
            if (string.IsNullOrWhiteSpace(state?["release_lock_sha256"]?.GetValue<string>())) return 103;

            const string secret = "password=super-secret-token\nAuthorization: abc123\nhttps://user:pass@example.test/";
            var redacted = PrerequisitesSupportBundle.Redact(secret);
            if (redacted.Contains("super-secret-token", StringComparison.Ordinal) || redacted.Contains("abc123", StringComparison.Ordinal) || redacted.Contains("user:pass@", StringComparison.Ordinal)) return 104;

            var findings = PrerequisitesDiagnostics.Run(temp);
            if (!findings.Any(x => x.Code == "PRQ-PHP-001")) return 105;
            if (!findings.Any(x => x.Code.StartsWith("PRQ-APACHE-", StringComparison.Ordinal))) return 106;
            Console.WriteLine("PREREQUISITES_V2_SELFTEST=PASS");
            return 0;
        }
        catch (Exception ex)
        {
            Console.Error.WriteLine(ex);
            return 107;
        }
        finally
        {
            try { Directory.Delete(temp, true); } catch { }
        }
    }
}
