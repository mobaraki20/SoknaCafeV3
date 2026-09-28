using Microsoft.Win32;
using System.Diagnostics;
using System.IO.Compression;
using System.Net;
using System.Net.Http.Headers;
using System.Net.Sockets;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using System.Text.Json.Serialization;
using System.Text.RegularExpressions;

namespace Sokna.Prerequisites.Setup;

internal sealed class InfrastructurePolicy
{
    [JsonPropertyName("format")] public string Format { get; init; } = "";
    [JsonPropertyName("schema_version")] public int SchemaVersion { get; init; }
    [JsonPropertyName("automatic_download_allowed")] public bool AutomaticDownloadAllowed { get; init; }
    [JsonPropertyName("automatic_install_allowed")] public bool AutomaticInstallAllowed { get; init; }
    [JsonPropertyName("local_web_payload_allowed")] public bool LocalWebPayloadAllowed { get; init; }
    [JsonPropertyName("database_application_provisioning_allowed")] public bool DatabaseApplicationProvisioningAllowed { get; init; }
}

internal sealed class ReleaseLock
{
    [JsonPropertyName("format")] public string Format { get; init; } = "";
    [JsonPropertyName("schema_version")] public int SchemaVersion { get; init; }
    [JsonPropertyName("release_frozen")] public bool ReleaseFrozen { get; init; }
    [JsonPropertyName("artifacts")] public List<LockedArtifact> Artifacts { get; init; } = [];
}

internal sealed class LockedArtifact
{
    [JsonPropertyName("dependency")] public string Dependency { get; init; } = "";
    [JsonPropertyName("version")] public string Version { get; init; } = "";
    [JsonPropertyName("filename")] public string FileName { get; init; } = "";
    [JsonPropertyName("source_url")] public string SourceUrl { get; init; } = "";
    [JsonPropertyName("fallback_urls")] public List<string> FallbackUrls { get; init; } = [];
    [JsonPropertyName("sha256")] public string Sha256 { get; init; } = "";
    [JsonPropertyName("size")] public long Size { get; init; }
}

internal enum OperationMode { Install, Repair, Recover }

internal sealed record ProcessResult(int ExitCode, string Output, string Error);

internal static class Program
{
    [STAThread]
    static void Main()
    {
        ApplicationConfiguration.Initialize();
        Application.Run(new MainForm());
    }
}

internal sealed class MainForm : Form
{
    private readonly InfrastructurePolicy _policy;
    private readonly ReleaseLock _lock;
    private readonly HttpClient _http = new(new SocketsHttpHandler { AutomaticDecompression = DecompressionMethods.All })
    {
        Timeout = TimeSpan.FromMinutes(30)
    };
    private readonly CancellationTokenSource _cts = new();

    private readonly TextBox _root = new();
    private readonly RadioButton _install = new() { Text = "نصب جدید", Checked = true, AutoSize = true };
    private readonly RadioButton _repair = new() { Text = "تعمیر نصب موجود", AutoSize = true };
    private readonly RadioButton _recover = new() { Text = "بازیابی بعد از نصب مجدد ویندوز", AutoSize = true };
    private readonly TextBox _password = new() { UseSystemPasswordChar = true };
    private readonly TextBox _password2 = new() { UseSystemPasswordChar = true };
    private readonly CheckBox _showPassword = new() { Text = "نمایش رمز", AutoSize = true };
    private readonly Label _paths = new() { AutoSize = true, Dock = DockStyle.Fill, TextAlign = ContentAlignment.TopRight, RightToLeft = RightToLeft.Yes };
    private readonly Label _modeHelp = new() { AutoSize = true, Dock = DockStyle.Fill, TextAlign = ContentAlignment.TopRight, RightToLeft = RightToLeft.Yes, Padding = new Padding(0, 6, 0, 8) };
    private readonly RichTextBox _status = new() { ReadOnly = true, Dock = DockStyle.Fill, BackColor = SystemColors.Window, BorderStyle = BorderStyle.FixedSingle, RightToLeft = RightToLeft.Yes, DetectUrls = false };
    private readonly ProgressBar _progress = new() { Dock = DockStyle.Fill, Minimum = 0, Maximum = 100 };
    private readonly Label _progressText = new() { AutoSize = true, Dock = DockStyle.Fill, TextAlign = ContentAlignment.MiddleRight };
    private readonly Button _run = new() { Text = "شروع", AutoSize = true, Padding = new Padding(14, 7, 14, 7) };
    private readonly Button _analyze = new() { Text = "بررسی وضعیت", AutoSize = true, Padding = new Padding(14, 7, 14, 7) };
    private readonly Button _browse = new() { Text = "انتخاب مسیر", AutoSize = true };
    private readonly Button _logs = new() { Text = "باز کردن لاگ‌ها", AutoSize = true };
    private readonly Button _support = new() { Text = "ساخت بسته پشتیبانی", AutoSize = true };
    private readonly Button _cancel = new() { Text = "لغو عملیات", AutoSize = true, Enabled = false };
    private string? _currentLog;
    private readonly string _sessionLog = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData), "SOKNA", "Prerequisites", "Logs", $"ui-{DateTime.Now:yyyyMMdd-HHmmss}.log");

    public MainForm()
    {
        Text = "آماده‌سازی زیرساخت SOKNA";
        Width = 1120;
        Height = 800;
        MinimumSize = new Size(900, 650);
        StartPosition = FormStartPosition.CenterScreen;
        AutoScaleMode = AutoScaleMode.Dpi;
        RightToLeft = RightToLeft.Yes;
        RightToLeftLayout = false;
        Font = PickFont();
        Icon = TryLoadIcon();

        _http.DefaultRequestHeaders.UserAgent.Add(new ProductInfoHeaderValue("SOKNA-Prerequisites", "1.0"));
        _policy = LoadJson<InfrastructurePolicy>("infrastructure-prerequisites.json", "سیاست زیرساخت");
        _lock = LoadJson<ReleaseLock>("release-lock.json", "فهرست نسخه‌های قفل‌شده");
        ValidateContracts();

        _root.Text = DefaultRoot();
        _root.TextChanged += (_, _) => RefreshPathSummary();
        _browse.Click += (_, _) => BrowseRoot();
        _analyze.Click += async (_, _) => await AnalyzeAsync();
        _run.Click += async (_, _) => await RunAsync();
        _logs.Click += (_, _) => OpenLogs();
        _support.Click += async (_, _) => await CreateSupportBundleAsync();
        _cancel.Click += (_, _) => _cts.Cancel();
        _showPassword.CheckedChanged += (_, _) => _password.UseSystemPasswordChar = _password2.UseSystemPasswordChar = !_showPassword.Checked;
        _install.CheckedChanged += (_, _) => RefreshModeHelp();
        _repair.CheckedChanged += (_, _) => RefreshModeHelp();
        _recover.CheckedChanged += (_, _) => RefreshModeHelp();

        Controls.Add(BuildUi());
        Load += (_, _) => FitToWorkingArea();
        EnsureSessionLog();
        Log("Prerequisites UI started.");
        RefreshPathSummary();
        RefreshModeHelp();
    }

    private static Font PickFont()
    {
        foreach (var name in new[] { "Tahoma", "Segoe UI" })
        {
            try
            {
                using var f = new Font(name, 10.0f, FontStyle.Regular, GraphicsUnit.Point);
                if (string.Equals(f.Name, name, StringComparison.OrdinalIgnoreCase))
                    return new Font(name, 10.0f, FontStyle.Regular, GraphicsUnit.Point);
            }
            catch { }
        }
        return SystemFonts.MessageBoxFont;
    }

    private Icon? TryLoadIcon()
    {
        try
        {
            var p = Path.Combine(AppContext.BaseDirectory, "Sokna.ico");
            return File.Exists(p) ? new Icon(p) : null;
        }
        catch { return null; }
    }

    private Control BuildUi()
    {
        var root = new TableLayoutPanel
        {
            Dock = DockStyle.Fill,
            ColumnCount = 1,
            RowCount = 3,
            RightToLeft = RightToLeft.Yes,
            Padding = new Padding(0),
            Margin = new Padding(0)
        };
        root.RowStyles.Add(new RowStyle(SizeType.Percent, 100));
        root.RowStyles.Add(new RowStyle(SizeType.AutoSize));
        root.RowStyles.Add(new RowStyle(SizeType.AutoSize));

        var scroll = new Panel
        {
            Dock = DockStyle.Fill,
            AutoScroll = true,
            RightToLeft = RightToLeft.Yes,
            Padding = new Padding(24, 20, 24, 12)
        };

        var content = new TableLayoutPanel
        {
            Dock = DockStyle.Top,
            AutoSize = true,
            AutoSizeMode = AutoSizeMode.GrowAndShrink,
            ColumnCount = 1,
            RowCount = 7,
            RightToLeft = RightToLeft.Yes,
            Margin = new Padding(0),
            Padding = new Padding(0)
        };
        content.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 100));

        var title = new Label
        {
            Text = "آماده‌سازی زیرساخت Local Web",
            Font = new Font(Font.FontFamily, 14.0f, FontStyle.Bold),
            AutoSize = true,
            Dock = DockStyle.Fill,
            TextAlign = ContentAlignment.MiddleRight,
            Padding = new Padding(0, 0, 0, 8)
        };
        var intro = new Label
        {
            AutoSize = true,
            Dock = DockStyle.Fill,
            TextAlign = ContentAlignment.TopRight,
            MaximumSize = new Size(1040, 0),
            Padding = new Padding(0, 0, 0, 12),
            Text = "این ابزار فقط PHP، Apache و MariaDB را آماده می‌کند. هیچ فایل Local Web را نصب یا کپی نمی‌کند و هیچ دیتابیس کاربردی SOKNA نمی‌سازد. بعد از پایان این مرحله، بسته Local Web را جداگانه مثل WordPress داخل مسیر Web قرار می‌دهید و نصب را در مرورگر انجام می‌دهید."
        };

        var modeBox = new GroupBox { Text = "حالت اجرا", Dock = DockStyle.Top, AutoSize = true, Padding = new Padding(14, 12, 14, 14), RightToLeft = RightToLeft.Yes };
        var modes = new FlowLayoutPanel
        {
            Dock = DockStyle.Top,
            AutoSize = true,
            FlowDirection = FlowDirection.RightToLeft,
            WrapContents = true,
            RightToLeft = RightToLeft.Yes,
            Padding = new Padding(4)
        };
        foreach (var radio in new[] { _install, _repair, _recover })
        {
            radio.RightToLeft = RightToLeft.Yes;
            radio.AutoSize = true;
            radio.Margin = new Padding(16, 4, 0, 4);
        }
        modes.Controls.AddRange([_install, _repair, _recover]);
        modeBox.Controls.Add(modes);

        var pathBox = new GroupBox { Text = "مسیر زیرساخت", Dock = DockStyle.Top, AutoSize = true, Padding = new Padding(14, 12, 14, 14), RightToLeft = RightToLeft.Yes };
        var pathLayout = new TableLayoutPanel { Dock = DockStyle.Top, AutoSize = true, ColumnCount = 3, RowCount = 2, RightToLeft = RightToLeft.No };
        pathLayout.ColumnStyles.Add(new ColumnStyle(SizeType.AutoSize));
        pathLayout.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 100));
        pathLayout.ColumnStyles.Add(new ColumnStyle(SizeType.AutoSize));
        _browse.MinimumSize = new Size(105, 34);
        _root.Dock = DockStyle.Fill;
        _root.RightToLeft = RightToLeft.No;
        _root.TextAlign = HorizontalAlignment.Left;
        _root.Margin = new Padding(8, 3, 8, 8);
        var rootLabel = new Label { Text = "ریشه:", AutoSize = true, Anchor = AnchorStyles.Right, RightToLeft = RightToLeft.Yes, TextAlign = ContentAlignment.MiddleRight, Padding = new Padding(8, 8, 0, 0) };
        pathLayout.Controls.Add(_browse, 0, 0);
        pathLayout.Controls.Add(_root, 1, 0);
        pathLayout.Controls.Add(rootLabel, 2, 0);
        _paths.Padding = new Padding(4, 2, 4, 0);
        pathLayout.Controls.Add(_paths, 0, 1);
        pathLayout.SetColumnSpan(_paths, 3);
        pathBox.Controls.Add(pathLayout);

        var dbBox = new GroupBox { Text = "MariaDB — فقط برای نصب جدید", Dock = DockStyle.Top, AutoSize = true, Padding = new Padding(14, 12, 14, 14), RightToLeft = RightToLeft.Yes };
        var db = new TableLayoutPanel { Dock = DockStyle.Top, AutoSize = true, ColumnCount = 3, RowCount = 3, RightToLeft = RightToLeft.No };
        db.ColumnStyles.Add(new ColumnStyle(SizeType.AutoSize));
        db.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 100));
        db.ColumnStyles.Add(new ColumnStyle(SizeType.AutoSize));
        _showPassword.RightToLeft = RightToLeft.Yes;
        _showPassword.Margin = new Padding(0, 6, 8, 0);
        _password.Dock = DockStyle.Fill;
        _password.RightToLeft = RightToLeft.No;
        _password.TextAlign = HorizontalAlignment.Left;
        _password.Margin = new Padding(8, 3, 8, 7);
        _password2.Dock = DockStyle.Fill;
        _password2.RightToLeft = RightToLeft.No;
        _password2.TextAlign = HorizontalAlignment.Left;
        _password2.Margin = new Padding(8, 3, 8, 7);
        var passLabel = new Label { Text = "رمز مدیر MariaDB:", AutoSize = true, Anchor = AnchorStyles.Right, RightToLeft = RightToLeft.Yes, TextAlign = ContentAlignment.MiddleRight, Padding = new Padding(8, 7, 0, 0) };
        var pass2Label = new Label { Text = "تکرار رمز:", AutoSize = true, Anchor = AnchorStyles.Right, RightToLeft = RightToLeft.Yes, TextAlign = ContentAlignment.MiddleRight, Padding = new Padding(8, 7, 0, 0) };
        db.Controls.Add(_showPassword, 0, 0);
        db.Controls.Add(_password, 1, 0);
        db.Controls.Add(passLabel, 2, 0);
        db.Controls.Add(new Panel { Width = 1, Height = 1 }, 0, 1);
        db.Controls.Add(_password2, 1, 1);
        db.Controls.Add(pass2Label, 2, 1);
        var dbHelp = new Label
        {
            Text = "این رمز فقط هنگام راه‌اندازی اولیه MariaDB استفاده می‌شود و در Log یا State ذخیره نمی‌شود. اگر Data قبلی وجود داشته باشد، Setup اجازه راه‌اندازی مجدد دیتابیس را نمی‌دهد.",
            AutoSize = true,
            Dock = DockStyle.Fill,
            RightToLeft = RightToLeft.Yes,
            TextAlign = ContentAlignment.TopRight,
            MaximumSize = new Size(980, 0),
            Padding = new Padding(4, 4, 4, 0)
        };
        db.Controls.Add(dbHelp, 0, 2);
        db.SetColumnSpan(dbHelp, 3);
        dbBox.Controls.Add(db);

        var statusBox = new GroupBox { Text = "وضعیت و جزئیات", Dock = DockStyle.Top, Height = 250, Padding = new Padding(12), RightToLeft = RightToLeft.Yes };
        _status.Font = new Font(Font.FontFamily, 9.75f, FontStyle.Regular);
        _status.RightToLeft = RightToLeft.Yes;
        _status.WordWrap = true;
        statusBox.Controls.Add(_status);

        content.Controls.Add(title, 0, 0);
        content.Controls.Add(intro, 0, 1);
        content.Controls.Add(modeBox, 0, 2);
        content.Controls.Add(pathBox, 0, 3);
        content.Controls.Add(dbBox, 0, 4);
        content.Controls.Add(_modeHelp, 0, 5);
        content.Controls.Add(statusBox, 0, 6);
        scroll.Controls.Add(content);

        var progressLayout = new TableLayoutPanel
        {
            Dock = DockStyle.Fill,
            AutoSize = true,
            ColumnCount = 1,
            RowCount = 2,
            Padding = new Padding(24, 4, 24, 2),
            RightToLeft = RightToLeft.Yes
        };
        _progressText.TextAlign = ContentAlignment.MiddleRight;
        _progressText.Padding = new Padding(0, 0, 0, 3);
        progressLayout.Controls.Add(_progressText, 0, 0);
        progressLayout.Controls.Add(_progress, 0, 1);

        var actions = new FlowLayoutPanel
        {
            Dock = DockStyle.Fill,
            AutoSize = true,
            FlowDirection = FlowDirection.RightToLeft,
            WrapContents = true,
            RightToLeft = RightToLeft.Yes,
            Padding = new Padding(24, 8, 24, 14)
        };
        foreach (var button in new[] { _run, _analyze, _logs, _support, _cancel })
        {
            button.MinimumSize = new Size(118, 38);
            button.Margin = new Padding(8, 0, 0, 0);
        }
        _run.MinimumSize = new Size(100, 38);
        actions.Controls.AddRange([_run, _analyze, _logs, _support, _cancel]);

        root.Controls.Add(scroll, 0, 0);
        root.Controls.Add(progressLayout, 0, 1);
        root.Controls.Add(actions, 0, 2);
        return root;
    }

    private void RefreshModeHelp()
    {
        _modeHelp.Text = SelectedMode() switch
        {
            OperationMode.Install => "نصب جدید: نسخه‌های تأییدشده دانلود و بررسی می‌شوند، PHP / Apache / MariaDB آماده می‌شوند و سرویس‌های ویندوز ثبت می‌شوند. اگر Data قبلی پیدا شود، عملیات برای جلوگیری از بازنویسی متوقف می‌شود.",
            OperationMode.Repair => "تعمیر نصب موجود: فایل‌ها و تنظیمات زیرساخت بررسی و ترمیم می‌شوند. Web و Data موجود حفظ می‌شوند و MariaDB دوباره initialize نمی‌شود.",
            _ => "بازیابی بعد از نصب مجدد ویندوز: از فایل‌ها و Data باقی‌مانده روی درایو انتخاب‌شده استفاده می‌شود و سرویس‌های ویندوز دوباره ثبت می‌شوند. Web و Data قبلی دست‌نخورده می‌مانند."
        };
    }

    private void FitToWorkingArea()
    {
        var work = Screen.FromControl(this).WorkingArea;
        var width = Math.Min(1160, Math.Max(900, work.Width - 80));
        var height = Math.Min(820, Math.Max(650, work.Height - 80));
        Size = new Size(width, height);
        Location = new Point(work.Left + Math.Max(0, (work.Width - Width) / 2), work.Top + Math.Max(0, (work.Height - Height) / 2));
    }

    private void EnsureSessionLog()
    {
        try
        {
            Directory.CreateDirectory(Path.GetDirectoryName(_sessionLog)!);
            if (!File.Exists(_sessionLog))
                File.WriteAllText(_sessionLog, $"[{DateTime.Now:yyyy-MM-dd HH:mm:ss.fff}] SOKNA Prerequisites UI session started.{Environment.NewLine}", new UTF8Encoding(false));
        }
        catch { }
    }

    private OperationMode SelectedMode() => _recover.Checked ? OperationMode.Recover : _repair.Checked ? OperationMode.Repair : OperationMode.Install;

    private void BrowseRoot()
    {
        using var d = new FolderBrowserDialog { Description = "پوشه ریشه SOKNA را انتخاب کنید", SelectedPath = _root.Text, ShowNewFolderButton = true };
        if (d.ShowDialog(this) == DialogResult.OK) _root.Text = d.SelectedPath;
    }

    private static string DefaultRoot()
    {
        try
        {
            var systemRoot = Path.GetPathRoot(Environment.SystemDirectory);
            var drive = DriveInfo.GetDrives()
                .Where(d => d.DriveType == DriveType.Fixed && d.IsReady && !string.Equals(d.RootDirectory.FullName, systemRoot, StringComparison.OrdinalIgnoreCase))
                .OrderByDescending(d => d.AvailableFreeSpace)
                .FirstOrDefault();
            if (drive is not null) return Path.Combine(drive.RootDirectory.FullName, "SOKNA");
        }
        catch { }
        var root = Path.GetPathRoot(Environment.SystemDirectory) ?? "C:\\";
        return Path.Combine(root, "SOKNA");
    }

    private string RootPath() => Path.GetFullPath(Environment.ExpandEnvironmentVariables(_root.Text.Trim()));
    private string InfraPath() => Path.Combine(RootPath(), "Infrastructure");
    private string PhpPath() => Path.Combine(InfraPath(), "PHP");
    private string ApachePath() => Path.Combine(InfraPath(), "Apache");
    private string MariaPath() => Path.Combine(InfraPath(), "MariaDB");
    private string LogsPath() => Path.Combine(InfraPath(), "Logs");
    private string WebPath() => Path.Combine(RootPath(), "Web");
    private string DataPath() => Path.Combine(RootPath(), "Data", "MariaDB");
    private string StatePath() => Path.Combine(InfraPath(), "infrastructure-state.json");

    private void RefreshPathSummary()
    {
        try
        {
            _paths.Text =
                $"زیرساخت: {InfraPath()}\n" +
                $"داده MariaDB: {DataPath()}\n" +
                $"Web Root برای مرحله بعد: {WebPath()}\n" +
                "Local Web در این مرحله نصب نمی‌شود.";
        }
        catch { _paths.Text = "مسیر واردشده معتبر نیست."; }
    }

    private void ValidateContracts()
    {
        if (_policy.Format != "sokna-infrastructure-prerequisites-v1" || _policy.SchemaVersion != 1 ||
            !_policy.AutomaticDownloadAllowed || !_policy.AutomaticInstallAllowed ||
            _policy.LocalWebPayloadAllowed || _policy.DatabaseApplicationProvisioningAllowed)
            throw new InvalidOperationException("قرارداد مستقل زیرساخت معتبر نیست.");
        if (_lock.Format != "sokna-windows-prerequisite-lock-v1" || _lock.SchemaVersion != 1 || !_lock.ReleaseFrozen)
            throw new InvalidOperationException("release-lock پیش‌نیازها معتبر نیست.");
        foreach (var dep in new[] { "php", "apache", "mariadb" })
            if (_lock.Artifacts.SingleOrDefault(x => x.Dependency == dep) is null)
                throw new InvalidOperationException($"artifact قفل‌شده برای {dep} وجود ندارد.");
    }

    private T LoadJson<T>(string file, string title)
    {
        var p = Path.Combine(AppContext.BaseDirectory, file);
        if (!File.Exists(p)) throw new FileNotFoundException($"{title} پیدا نشد.", p);
        return JsonSerializer.Deserialize<T>(File.ReadAllText(p, Encoding.UTF8)) ?? throw new InvalidOperationException($"{title} قابل خواندن نیست.");
    }

    private async Task AnalyzeAsync()
    {
        try
        {
            SetBusy(true, "در حال بررسی وضعیت...");
            await Task.Run(() =>
            {
                var sb = new StringBuilder();
                sb.AppendLine($"Root: {RootPath()}");
                sb.AppendLine($"PHP: {(File.Exists(Path.Combine(PhpPath(), "php.exe")) ? "موجود" : "پیدا نشد")}");
                sb.AppendLine($"Apache files: {(File.Exists(Path.Combine(ApachePath(), "bin", "httpd.exe")) ? "موجود" : "پیدا نشد")}");
                sb.AppendLine($"Apache service: {ServiceStatus("SoknaApache")}");
                sb.AppendLine($"Apache port 80: {(TcpOpen(80) ? "پاسخ می‌دهد" : "بسته/در دسترس نیست")}");
                sb.AppendLine($"MariaDB files: {(FindMariaServer() is not null ? "موجود" : "پیدا نشد")}");
                sb.AppendLine($"MariaDB data: {(MariaDataInitialized() ? "موجود — preserve" : "initialize نشده")}");
                sb.AppendLine($"MariaDB service: {ServiceStatus("SoknaMariaDB")}");
                sb.AppendLine($"MariaDB port 3306: {(TcpOpen(3306) ? "پاسخ می‌دهد" : "بسته/در دسترس نیست")}");
                sb.AppendLine($"Web Root: {WebPath()}");
                if (File.Exists(StatePath())) sb.AppendLine($"State: {StatePath()}");
                BeginInvoke(new Action(() => _status.Text = sb.ToString()));
            });
        }
        catch (Exception ex) { ShowError(ex); }
        finally { SetBusy(false, "بررسی تمام شد."); }
    }

    private async Task RunAsync()
    {
        try
        {
            var mode = SelectedMode();
            ValidateInputs(mode);
            SetBusy(true, "شروع عملیات...");
            PreparePersistentLayout();
            StartLog(mode);

            Log($"Mode={mode}; Root={RootPath()}");
            Log("Local Web payload is explicitly out of scope.");

            if (mode == OperationMode.Install && MariaDataInitialized())
                throw new InvalidOperationException("در مسیر انتخاب‌شده Data قبلی MariaDB وجود دارد. برای جلوگیری از overwrite، «تعمیر» یا «بازیابی بعد از ویندوز» را انتخاب کنید.");

            await InstallPhpAsync(_cts.Token);
            await InstallApacheAsync(_cts.Token);
            await InstallMariaAsync(mode, _cts.Token);
            await ValidateHealthAsync(_cts.Token);
            WriteState(mode);

            SetProgress(100, "زیرساخت آماده است.");
            _status.AppendText("\n✓ زیرساخت آماده شد.\n");
            _status.AppendText($"مرحله بعد: فایل Local Web را داخل «{WebPath()}» قرار دهید و http://localhost/ را در مرورگر باز کنید.\n");
            MessageBox.Show(this,
                $"زیرساخت آماده شد.\n\nWeb Root:\n{WebPath()}\n\nدر مرحله بعد Local Web را جداگانه داخل این مسیر قرار دهید و http://localhost/ را باز کنید.",
                "SOKNA", MessageBoxButtons.OK, MessageBoxIcon.Information);
        }
        catch (OperationCanceledException)
        {
            Log("Operation cancelled by user.");
            _status.AppendText("\nعملیات توسط کاربر لغو شد.\n");
        }
        catch (Exception ex)
        {
            Log("ERROR: " + ex);
            ShowError(ex);
        }
        finally { SetBusy(false, "آماده"); }
    }

    private void ValidateInputs(OperationMode mode)
    {
        var root = RootPath();
        if (string.IsNullOrWhiteSpace(root) || Path.GetPathRoot(root) is null) throw new InvalidOperationException("مسیر ریشه معتبر نیست.");
        if (mode == OperationMode.Install && !MariaDataInitialized())
        {
            if (_password.Text.Length < 10) throw new InvalidOperationException("برای نصب جدید، رمز root ماریا‌دی‌بی حداقل ۱۰ نویسه باشد.");
            if (_password.Text.Contains('"') || _password.Text.Contains('\r') || _password.Text.Contains('\n'))
                throw new InvalidOperationException("رمز root نباید شامل علامت نقل‌قول یا خط جدید باشد.");
            if (_password.Text != _password2.Text) throw new InvalidOperationException("رمز و تکرار آن یکسان نیستند.");
        }
    }

    private void PreparePersistentLayout()
    {
        Directory.CreateDirectory(InfraPath());
        Directory.CreateDirectory(LogsPath());
        Directory.CreateDirectory(WebPath());
        Directory.CreateDirectory(Path.Combine(RootPath(), "Data"));
        Directory.CreateDirectory(Path.Combine(RootPath(), "Backups"));
    }

    private void StartLog(OperationMode mode)
    {
        _currentLog = Path.Combine(LogsPath(), $"prerequisites-{DateTime.Now:yyyyMMdd-HHmmss}.log");
        Log($"SOKNA Prerequisites Setup 1.0.0 | {mode}");
    }

    private async Task InstallPhpAsync(CancellationToken ct)
    {
        SetProgress(5, "PHP: دریافت و بررسی فایل رسمی...");
        var a = Artifact("php");
        var zip = await DownloadVerifiedAsync(a, ct);
        var tmp = NewTemp("php");
        try
        {
            ZipFile.ExtractToDirectory(zip, tmp, true);
            var source = FindRootContaining(tmp, "php.exe") ?? throw new InvalidOperationException("php.exe داخل بسته پیدا نشد.");
            CopyDirectory(source, PhpPath());
            ConfigurePhp();
            var r = RunProcess(Path.Combine(PhpPath(), "php.exe"), "-v", "-v");
            if (r.ExitCode != 0) throw new InvalidOperationException("PHP بعد از نصب اجرا نشد: " + r.Error);
            Log("PHP ready: " + FirstLine(r.Output));
            SetProgress(25, "PHP آماده شد.");
        }
        finally { SafeDelete(tmp); }
    }

    private void ConfigurePhp()
    {
        var ini = Path.Combine(PhpPath(), "php.ini");
        if (!File.Exists(ini))
        {
            var source = Path.Combine(PhpPath(), "php.ini-production");
            if (!File.Exists(source)) throw new InvalidOperationException("php.ini-production پیدا نشد.");
            File.Copy(source, ini, true);
        }
        else BackupFile(ini);

        var text = File.ReadAllText(ini, Encoding.UTF8);
        text = Regex.Replace(text, @"(?im)^\s*;?\s*extension_dir\s*=.*$", "extension_dir = \"ext\"");
        foreach (var dll in new[] { "php_fileinfo.dll", "php_mbstring.dll", "php_mysqli.dll", "php_pdo_mysql.dll", "php_openssl.dll", "php_sodium.dll" })
        {
            if (!File.Exists(Path.Combine(PhpPath(), "ext", dll))) continue;
            var rx = new Regex(@"(?im)^\s*;?\s*extension\s*=\s*" + Regex.Escape(dll) + @"\s*$");
            if (rx.IsMatch(text)) text = rx.Replace(text, $"extension={dll}", 1);
            else text += Environment.NewLine + $"extension={dll}";
        }
        File.WriteAllText(ini, text, new UTF8Encoding(false));
    }

    private async Task InstallApacheAsync(CancellationToken ct)
    {
        SetProgress(30, "Apache: دریافت و آماده‌سازی...");
        var a = Artifact("apache");
        var zip = await DownloadVerifiedAsync(a, ct);
        var tmp = NewTemp("apache");
        try
        {
            ZipFile.ExtractToDirectory(zip, tmp, true);
            var source = FindRootContaining(tmp, Path.Combine("bin", "httpd.exe")) ?? throw new InvalidOperationException("httpd.exe داخل بسته Apache پیدا نشد.");
            CopyDirectory(source, ApachePath());
            ConfigureApache();

            var httpd = Path.Combine(ApachePath(), "bin", "httpd.exe");
            var conf = Path.Combine(ApachePath(), "conf", "httpd.conf");
            var syntax = RunProcess(httpd, $"-t -f \"{conf}\"", $"-t -f \"{conf}\"");
            if (syntax.ExitCode != 0) throw new InvalidOperationException("Apache config معتبر نیست: " + syntax.Error + syntax.Output);

            ReinstallApacheService(httpd, conf);
            SetProgress(55, "Apache و سرویس SoknaApache آماده شدند.");
        }
        finally { SafeDelete(tmp); }
    }

    private void ConfigureApache()
    {
        var conf = Path.Combine(ApachePath(), "conf", "httpd.conf");
        if (!File.Exists(conf)) throw new InvalidOperationException("httpd.conf پیدا نشد.");
        BackupFile(conf);

        var a = Slash(ApachePath());
        var p = Slash(PhpPath());
        var w = Slash(WebPath());
        var text = File.ReadAllText(conf, Encoding.UTF8);
        text = Regex.Replace(text, "(?im)^\\s*Define\\s+SRVROOT\\s+\\\".*?\\\"\\s*$", $"Define SRVROOT \"{a}\"");
        text = new Regex(@"(?im)^\s*Listen\s+.*$").Replace(text, "Listen 127.0.0.1:80", 1);
        text = new Regex("(?im)^\\s*DocumentRoot\\s+\\\".*?\\\"\\s*$").Replace(text, $"DocumentRoot \"{w}\"", 1);
        text = Regex.Replace(text, @"(?is)\r?\n# BEGIN SOKNA MANAGED.*?# END SOKNA MANAGED\r?\n?", Environment.NewLine);
        text += $"""
# BEGIN SOKNA MANAGED
LoadModule php_module "{p}/php8apache2_4.dll"
PHPIniDir "{p}"
<FilesMatch \.php$>
    SetHandler application/x-httpd-php
</FilesMatch>
<Directory "{w}">
    Options Indexes FollowSymLinks
    AllowOverride All
    Require all granted
</Directory>
DirectoryIndex index.php index.html
# END SOKNA MANAGED

""";
        File.WriteAllText(conf, text, new UTF8Encoding(false));
    }

    private void ReinstallApacheService(string httpd, string conf)
    {
        if (ServiceExists("SoknaApache"))
        {
            RunProcess("sc.exe", "stop SoknaApache", "stop SoknaApache", allowFailure: true);
            RunProcess(httpd, "-k uninstall -n \"SoknaApache\"", "-k uninstall -n \"SoknaApache\"", allowFailure: true);
        }
        var install = RunProcess(httpd, $"-k install -n \"SoknaApache\" -f \"{conf}\"", $"-k install -n \"SoknaApache\" -f \"{conf}\"");
        if (install.ExitCode != 0) throw new InvalidOperationException("ثبت سرویس Apache ناموفق بود: " + install.Error + install.Output);
        RunProcess("sc.exe", "config SoknaApache start= auto", "config SoknaApache start= auto", allowFailure: true);
        RunProcess("sc.exe", "start SoknaApache", "start SoknaApache", allowFailure: true);
    }

    private async Task InstallMariaAsync(OperationMode mode, CancellationToken ct)
    {
        SetProgress(60, "MariaDB: آماده‌سازی binary...");
        await EnsureMariaBinariesAsync(ct);
        var initialized = MariaDataInitialized();

        if (!initialized)
        {
            if (mode != OperationMode.Install)
                throw new InvalidOperationException("Data قبلی MariaDB پیدا نشد. برای ساخت دیتای جدید، حالت «نصب جدید» را انتخاب کنید.");
            Directory.CreateDirectory(DataPath());
            var init = FindMariaInstallDb() ?? throw new InvalidOperationException("mariadb-install-db.exe پیدا نشد.");
            var args = $"--datadir=\"{DataPath()}\" --service=SoknaMariaDB --password=\"{_password.Text}\" --port=3306 --silent";
            var safe = $"--datadir=\"{DataPath()}\" --service=SoknaMariaDB --password=<redacted> --port=3306 --silent";
            var r = RunProcess(init, args, safe, timeoutMs: 180000);
            if (r.ExitCode != 0) throw new InvalidOperationException("Initialize اولیه MariaDB ناموفق بود: " + r.Error + r.Output);
            Log("MariaDB data initialized once.");
        }
        else
        {
            Log("Existing MariaDB data detected; initialization skipped.");
            EnsureMariaConfigForExistingData();
            RegisterExistingMariaServiceIfNeeded();
        }

        RunProcess("sc.exe", "config SoknaMariaDB start= auto", "config SoknaMariaDB start= auto", allowFailure: true);
        RunProcess("sc.exe", "start SoknaMariaDB", "start SoknaMariaDB", allowFailure: true);
        SetProgress(82, "MariaDB آماده شد؛ Data موجود حفظ شده است.");
    }

    private async Task EnsureMariaBinariesAsync(CancellationToken ct)
    {
        if (FindMariaServer() is not null)
        {
            Log("MariaDB binaries already present; MSI binary install skipped.");
            return;
        }

        var a = Artifact("mariadb");
        var msi = await DownloadVerifiedAsync(a, ct);
        Directory.CreateDirectory(MariaPath());
        var msiLog = Path.Combine(LogsPath(), $"mariadb-msi-{DateTime.Now:yyyyMMdd-HHmmss}.log");
        var args = $"/i \"{msi}\" INSTALLDIR=\"{MariaPath()}\" ADDLOCAL=MYSQLSERVER,Client,SharedLibraries REMOVE=DBInstance,DEVEL,HeidiSQL /qn /norestart /l*v \"{msiLog}\"";
        var r = RunProcess("msiexec.exe", args, args, timeoutMs: 600000);
        if (r.ExitCode is not (0 or 3010))
            throw new InvalidOperationException($"نصب binary ماریا‌دی‌بی ناموفق بود (Exit {r.ExitCode}). لاگ MSI: {msiLog}");
        if (FindMariaServer() is null) throw new InvalidOperationException("MariaDB MSI پایان یافت ولی mariadbd.exe در مسیر انتخاب‌شده پیدا نشد.");
    }

    private void EnsureMariaConfigForExistingData()
    {
        var ini = Path.Combine(DataPath(), "my.ini");
        if (File.Exists(ini)) return;
        var server = FindMariaServer() ?? throw new InvalidOperationException("mariadbd.exe پیدا نشد.");
        var baseDir = Directory.GetParent(Path.GetDirectoryName(server)!)!.FullName;
        var log = Slash(Path.Combine(LogsPath(), "mariadb-error.log"));
        var text = $"[mysqld]\r\nbasedir={Slash(baseDir)}\r\ndatadir={Slash(DataPath())}\r\nport=3306\r\nbind-address=127.0.0.1\r\ncharacter-set-server=utf8mb4\r\ncollation-server=utf8mb4_unicode_ci\r\nlog-error={log}\r\n";
        File.WriteAllText(ini, text, new UTF8Encoding(false));
    }

    private void RegisterExistingMariaServiceIfNeeded()
    {
        if (ServiceExists("SoknaMariaDB")) return;
        var server = FindMariaServer() ?? throw new InvalidOperationException("mariadbd.exe پیدا نشد.");
        var ini = Path.Combine(DataPath(), "my.ini");
        var r = RunProcess(server, $"--defaults-file=\"{ini}\" --install SoknaMariaDB", $"--defaults-file=\"{ini}\" --install SoknaMariaDB");
        if (r.ExitCode != 0) throw new InvalidOperationException("ثبت مجدد سرویس MariaDB ناموفق بود: " + r.Error + r.Output);
        Log("Existing MariaDB data reattached to Windows service without reinitialization.");
    }

    private async Task ValidateHealthAsync(CancellationToken ct)
    {
        SetProgress(86, "Health check نهایی...");
        await Task.Delay(1500, ct);

        var php = RunProcess(Path.Combine(PhpPath(), "php.exe"), "-m", "-m");
        if (php.ExitCode != 0) throw new InvalidOperationException("PHP health check ناموفق بود.");
        foreach (var ext in new[] { "pdo_mysql", "fileinfo", "openssl", "sodium", "mbstring" })
            if (!php.Output.Split('\n').Any(x => string.Equals(x.Trim(), ext, StringComparison.OrdinalIgnoreCase)))
                throw new InvalidOperationException($"PHP extension آماده نیست: {ext}");

        if (!ServiceExists("SoknaApache")) throw new InvalidOperationException("سرویس SoknaApache ثبت نشده است.");
        if (!ServiceExists("SoknaMariaDB")) throw new InvalidOperationException("سرویس SoknaMariaDB ثبت نشده است.");

        var until = DateTime.UtcNow.AddSeconds(30);
        while (DateTime.UtcNow < until && (!TcpOpen(80) || !TcpOpen(3306)))
        {
            await Task.Delay(1000, ct);
        }
        if (!TcpOpen(80)) throw new InvalidOperationException("Apache روی 127.0.0.1:80 پاسخ نمی‌دهد. از «باز کردن لاگ‌ها» استفاده کنید.");
        if (!TcpOpen(3306)) throw new InvalidOperationException("MariaDB روی 127.0.0.1:3306 پاسخ نمی‌دهد. از «باز کردن لاگ‌ها» استفاده کنید.");

        SetProgress(96, "Health check موفق بود.");
    }

    private void WriteState(OperationMode mode)
    {
        var state = new
        {
            format = "sokna-infrastructure-state-v1",
            schema_version = 1,
            updated_at_utc = DateTime.UtcNow,
            last_operation = mode.ToString().ToLowerInvariant(),
            root = RootPath(),
            paths = new { infrastructure = InfraPath(), php = PhpPath(), apache = ApachePath(), mariadb = MariaPath(), mariadb_data = DataPath(), web_root = WebPath(), logs = LogsPath() },
            services = new { apache = "SoknaApache", mariadb = "SoknaMariaDB" },
            endpoints = new { local_web = "http://localhost/", mariadb = "127.0.0.1:3306" },
            safeguards = new { local_web_payload_managed = false, sokna_database_managed = false, existing_mariadb_data_reinitialized = false }
        };
        File.WriteAllText(StatePath(), JsonSerializer.Serialize(state, new JsonSerializerOptions { WriteIndented = true }), new UTF8Encoding(false));
    }

    private LockedArtifact Artifact(string dependency) =>
        _lock.Artifacts.Single(x => string.Equals(x.Dependency, dependency, StringComparison.OrdinalIgnoreCase));

    private async Task<string> DownloadVerifiedAsync(LockedArtifact a, CancellationToken ct)
    {
        var cache = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData), "SOKNA", "PrerequisiteCache");
        Directory.CreateDirectory(cache);
        var dest = Path.Combine(cache, a.FileName);
        if (File.Exists(dest) && VerifyFile(dest, a))
        {
            Log($"Cache hit: {a.FileName}");
            return dest;
        }
        try { File.Delete(dest); } catch { }

        var urls = new[] { a.SourceUrl }.Concat(a.FallbackUrls ?? []).Where(x => !string.IsNullOrWhiteSpace(x)).Distinct().ToList();
        Exception? last = null;
        foreach (var url in urls)
        {
            try
            {
                Log($"Download: {a.Dependency} from {url}");
                using var req = new HttpRequestMessage(HttpMethod.Get, url);
                using var resp = await _http.SendAsync(req, HttpCompletionOption.ResponseHeadersRead, ct);
                resp.EnsureSuccessStatusCode();
                await using var input = await resp.Content.ReadAsStreamAsync(ct);
                await using var output = new FileStream(dest, FileMode.Create, FileAccess.Write, FileShare.None, 1024 * 1024, true);
                var buf = new byte[1024 * 256];
                long total = 0;
                while (true)
                {
                    var n = await input.ReadAsync(buf, ct);
                    if (n <= 0) break;
                    await output.WriteAsync(buf.AsMemory(0, n), ct);
                    total += n;
                    var pct = a.Size > 0 ? (int)Math.Min(100, total * 100 / a.Size) : 0;
                    BeginInvoke(new Action(() => _progressText.Text = $"دانلود {a.Dependency}: {pct}%"));
                }
                await output.FlushAsync(ct);
                if (!VerifyFile(dest, a)) throw new InvalidOperationException($"فایل {a.FileName} با hash/size قفل‌شده تطبیق ندارد.");
                return dest;
            }
            catch (Exception ex) when (ex is not OperationCanceledException)
            {
                last = ex;
                Log($"Download failed: {url} | {ex.Message}");
                try { File.Delete(dest); } catch { }
            }
        }
        throw new InvalidOperationException($"دریافت فایل رسمی {a.FileName} از همه آدرس‌های ثبت‌شده ناموفق بود.", last);
    }

    private static bool VerifyFile(string path, LockedArtifact a)
    {
        var fi = new FileInfo(path);
        if (!fi.Exists || (a.Size > 0 && fi.Length != a.Size)) return false;
        using var s = File.OpenRead(path);
        var hash = Convert.ToHexString(SHA256.HashData(s)).ToLowerInvariant();
        return string.Equals(hash, a.Sha256, StringComparison.OrdinalIgnoreCase);
    }

    private string? FindMariaServer()
    {
        var candidates = new[]
        {
            Path.Combine(MariaPath(), "bin", "mariadbd.exe"),
            Path.Combine(MariaPath(), "bin", "mysqld.exe")
        };
        return candidates.FirstOrDefault(File.Exists);
    }

    private string? FindMariaInstallDb()
    {
        foreach (var name in new[] { "mariadb-install-db.exe", "mysql_install_db.exe" })
        {
            var p = Path.Combine(MariaPath(), "bin", name);
            if (File.Exists(p)) return p;
        }
        return null;
    }

    private bool MariaDataInitialized() => Directory.Exists(Path.Combine(DataPath(), "mysql"));

    private static string? FindRootContaining(string root, string relative)
    {
        if (File.Exists(Path.Combine(root, relative))) return root;
        foreach (var d in Directory.EnumerateDirectories(root, "*", SearchOption.AllDirectories))
            if (File.Exists(Path.Combine(d, relative))) return d;
        return null;
    }

    private static void CopyDirectory(string source, string dest)
    {
        Directory.CreateDirectory(dest);
        foreach (var file in Directory.GetFiles(source))
            File.Copy(file, Path.Combine(dest, Path.GetFileName(file)), true);
        foreach (var dir in Directory.GetDirectories(source))
            CopyDirectory(dir, Path.Combine(dest, Path.GetFileName(dir)));
    }

    private static string NewTemp(string name)
    {
        var p = Path.Combine(Path.GetTempPath(), $"sokna-prereq-{name}-{Guid.NewGuid():N}");
        Directory.CreateDirectory(p);
        return p;
    }

    private static void SafeDelete(string path)
    {
        try { if (Directory.Exists(path)) Directory.Delete(path, true); } catch { }
    }

    private void BackupFile(string file)
    {
        if (!File.Exists(file)) return;
        var backup = file + ".sokna-backup-" + DateTime.Now.ToString("yyyyMMdd-HHmmss");
        File.Copy(file, backup, true);
    }

    private ProcessResult RunProcess(string file, string args, string safeArgs, int timeoutMs = 120000, bool allowFailure = false)
    {
        Log($"RUN {file} {safeArgs}");
        using var p = new Process
        {
            StartInfo = new ProcessStartInfo
            {
                FileName = file,
                Arguments = args,
                UseShellExecute = false,
                CreateNoWindow = true,
                RedirectStandardOutput = true,
                RedirectStandardError = true,
                WorkingDirectory = Path.GetDirectoryName(file) is { Length: > 0 } d && Directory.Exists(d) ? d : Environment.CurrentDirectory
            }
        };
        p.Start();
        var so = p.StandardOutput.ReadToEndAsync();
        var se = p.StandardError.ReadToEndAsync();
        if (!p.WaitForExit(timeoutMs))
        {
            try { p.Kill(true); } catch { }
            throw new TimeoutException($"زمان اجرای {Path.GetFileName(file)} تمام شد.");
        }
        Task.WaitAll(so, se);
        var r = new ProcessResult(p.ExitCode, so.Result, se.Result);
        Log($"EXIT {p.ExitCode} | {TrimLog(r.Output)} | {TrimLog(r.Error)}");
        if (!allowFailure && r.ExitCode != 0) return r;
        return r;
    }

    private static string TrimLog(string s)
    {
        s = s.Replace("\r", " ").Replace("\n", " ").Trim();
        return s.Length <= 700 ? s : s[..700] + "...";
    }

    private bool ServiceExists(string name)
    {
        var r = RunProcess("sc.exe", $"query \"{name}\"", $"query \"{name}\"", allowFailure: true);
        return r.ExitCode == 0;
    }

    private string ServiceStatus(string name)
    {
        var r = RunProcess("sc.exe", $"query \"{name}\"", $"query \"{name}\"", allowFailure: true);
        if (r.ExitCode != 0) return "ثبت نشده";
        var m = Regex.Match(r.Output, @"STATE\s*:\s*\d+\s+(\w+)", RegexOptions.IgnoreCase);
        return m.Success ? m.Groups[1].Value : "ثبت شده";
    }

    private static bool TcpOpen(int port)
    {
        try
        {
            using var c = new TcpClient();
            var t = c.ConnectAsync("127.0.0.1", port);
            return t.Wait(TimeSpan.FromMilliseconds(700)) && c.Connected;
        }
        catch { return false; }
    }

    private static string Slash(string p) => p.Replace('\\', '/');
    private static string FirstLine(string s) => s.Split(['\r', '\n'], StringSplitOptions.RemoveEmptyEntries).FirstOrDefault() ?? "";

    private void SetBusy(bool busy, string message)
    {
        _run.Enabled = !busy;
        _analyze.Enabled = !busy;
        _browse.Enabled = !busy;
        _cancel.Enabled = busy;
        _progressText.Text = message;
        UseWaitCursor = busy;
    }

    private void SetProgress(int value, string text)
    {
        if (InvokeRequired) { BeginInvoke(new Action(() => SetProgress(value, text))); return; }
        _progress.Value = Math.Clamp(value, 0, 100);
        _progressText.Text = text;
        _status.AppendText(text + Environment.NewLine);
        Log(text);
    }

    private void Log(string line)
    {
        if (string.IsNullOrWhiteSpace(_currentLog)) return;
        try
        {
            Directory.CreateDirectory(Path.GetDirectoryName(_currentLog)!);
            File.AppendAllText(_currentLog, $"[{DateTime.Now:yyyy-MM-dd HH:mm:ss.fff}] {line}{Environment.NewLine}", new UTF8Encoding(false));
        }
        catch { }
    }

    private void OpenLogs()
    {
        try
        {
            Directory.CreateDirectory(LogsPath());
            Process.Start(new ProcessStartInfo("explorer.exe", $"\"{LogsPath()}\"") { UseShellExecute = true });
        }
        catch (Exception ex) { ShowError(ex); }
    }

    private async Task CreateSupportBundleAsync()
    {
        try
        {
            PreparePersistentLayout();
            var support = Path.Combine(InfraPath(), "Support");
            Directory.CreateDirectory(support);
            var temp = NewTemp("support");
            try
            {
                if (File.Exists(StatePath())) File.Copy(StatePath(), Path.Combine(temp, "infrastructure-state.json"), true);
                if (Directory.Exists(LogsPath()))
                {
                    var dst = Path.Combine(temp, "logs");
                    Directory.CreateDirectory(dst);
                    foreach (var f in Directory.GetFiles(LogsPath(), "*.log").TakeLast(20))
                        File.Copy(f, Path.Combine(dst, Path.GetFileName(f)), true);
                }
                var diag = new StringBuilder();
                diag.AppendLine("SOKNA Prerequisites support bundle");
                diag.AppendLine("No passwords or application credentials are intentionally included.");
                diag.AppendLine($"Root={RootPath()}");
                diag.AppendLine($"Apache={ServiceStatus("SoknaApache")}; Port80={TcpOpen(80)}");
                diag.AppendLine($"MariaDB={ServiceStatus("SoknaMariaDB")}; Port3306={TcpOpen(3306)}");
                diag.AppendLine($"MariaDataPresent={MariaDataInitialized()}");
                await File.WriteAllTextAsync(Path.Combine(temp, "diagnostics.txt"), diag.ToString(), new UTF8Encoding(false));
                var zip = Path.Combine(support, $"SOKNA-Prerequisites-Support-{DateTime.Now:yyyyMMdd-HHmmss}.zip");
                ZipFile.CreateFromDirectory(temp, zip, CompressionLevel.Optimal, false);
                MessageBox.Show(this, $"بسته پشتیبانی ساخته شد:\n{zip}\n\nرمزها و credentialهای Local Web عمداً در آن قرار نگرفته‌اند.", "SOKNA", MessageBoxButtons.OK, MessageBoxIcon.Information);
                Process.Start(new ProcessStartInfo("explorer.exe", $"/select,\"{zip}\"") { UseShellExecute = true });
            }
            finally { SafeDelete(temp); }
        }
        catch (Exception ex) { ShowError(ex); }
    }

    private void ShowError(Exception ex)
    {
        _status.AppendText($"\nخطا: {ex.Message}\n");
        var log = _currentLog is null ? "هنوز فایل log ساخته نشده است." : $"Log:\n{_currentLog}";
        MessageBox.Show(this, $"{ex.Message}\n\n{log}\n\nبرای بررسی بیشتر از «ساخت بسته پشتیبانی» استفاده کنید.", "خطای آماده‌سازی زیرساخت", MessageBoxButtons.OK, MessageBoxIcon.Error);
    }

    protected override void OnFormClosing(FormClosingEventArgs e)
    {
        if (_cancel.Enabled) _cts.Cancel();
        base.OnFormClosing(e);
    }
}
