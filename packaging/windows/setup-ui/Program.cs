using Microsoft.Win32;
using System.ComponentModel;
using System.Diagnostics;
using System.Net;
using System.Net.Http.Headers;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using System.Text.Json.Serialization;

namespace Sokna.SetupUi;

internal sealed class PrerequisitePolicy
{
    [JsonPropertyName("format")] public string Format { get; init; } = "";
    [JsonPropertyName("schema_version")] public int SchemaVersion { get; init; }
    [JsonPropertyName("ownership")] public string Ownership { get; init; } = "";
    [JsonPropertyName("automatic_download_allowed")] public bool AutomaticDownloadAllowed { get; init; }
    [JsonPropertyName("automatic_install_allowed")] public bool AutomaticInstallAllowed { get; init; }
    [JsonPropertyName("items")] public List<PrerequisiteItem> Items { get; init; } = [];
}

internal sealed class PrerequisiteItem
{
    [JsonPropertyName("id")] public string Id { get; init; } = "";
    [JsonPropertyName("display_name")] public string DisplayName { get; init; } = "";
    [JsonPropertyName("required_for")] public string RequiredFor { get; init; } = "";
    [JsonPropertyName("blocks_windows_services")] public bool BlocksWindowsServices { get; init; }
    [JsonPropertyName("detection")] public DetectionRule Detection { get; init; } = new();
    [JsonPropertyName("release_lock_dependency")] public string ReleaseLockDependency { get; init; } = "";
    [JsonPropertyName("manual_guidance_fa")] public string ManualGuidanceFa { get; init; } = "";
}

internal sealed class DetectionRule
{
    [JsonPropertyName("type")] public string Type { get; init; } = "";
    [JsonPropertyName("commands")] public List<string> Commands { get; init; } = [];
    [JsonPropertyName("version_argument")] public string VersionArgument { get; init; } = "";
    [JsonPropertyName("minimum_version")] public string MinimumVersion { get; init; } = "";
    [JsonPropertyName("required_extensions")] public List<string> RequiredExtensions { get; init; } = [];
    [JsonPropertyName("registry_path")] public string RegistryPath { get; init; } = "";
    [JsonPropertyName("value_name")] public string ValueName { get; init; } = "";
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
    [JsonPropertyName("id")] public string Id { get; init; } = "";
    [JsonPropertyName("dependency")] public string Dependency { get; init; } = "";
    [JsonPropertyName("version")] public string Version { get; init; } = "";
    [JsonPropertyName("filename")] public string Filename { get; init; } = "";
    [JsonPropertyName("source_url")] public string SourceUrl { get; init; } = "";
    [JsonPropertyName("fallback_urls")] public List<string> FallbackUrls { get; init; } = [];
    [JsonPropertyName("sha256")] public string Sha256 { get; init; } = "";
    [JsonPropertyName("size")] public long Size { get; init; }
    [JsonPropertyName("installation")] public string Installation { get; init; } = "";
    [JsonPropertyName("authenticode")] public AuthenticodePolicy Authenticode { get; init; } = new();
    [JsonPropertyName("instructions_fa")] public string InstructionsFa { get; init; } = "";
}

internal sealed class AuthenticodePolicy
{
    [JsonPropertyName("required")] public bool Required { get; init; }
    [JsonPropertyName("publisher_contains")] public string PublisherContains { get; init; } = "";
}

internal sealed record DetectionResult(bool Satisfied, string Status, string FoundPath, string FoundVersion);
internal sealed record ServiceSnapshot(string Name, string DisplayName, bool Installed, string State, string StartType, string ProcessId, string ImagePath);

internal static class Program
{
    [STAThread]
    private static void Main()
    {
        ApplicationConfiguration.Initialize();
        Application.Run(new SetupForm());
    }
}

internal sealed class SetupForm : Form
{
    private const string RuntimeService = "SoknaRuntime";
    private const string PrintService = "SoknaPrintWorker";
    private static readonly JsonSerializerOptions StrictJson = new() { PropertyNameCaseInsensitive = false };

    private readonly TextBox _installRoot = PathBox();
    private readonly TextBox _dataRoot = PathBox();
    private readonly TextBox _pairing = PathBox();
    private readonly CheckBox _startPaired = new() { Text = "بعد از Pairing، Runtime هم خودکار شروع شود", AutoSize = true, Checked = true };
    private readonly ListView _prereqs = CreateRtlList();
    private readonly ListView _services = CreateRtlList();
    private readonly ProgressBar _progress = new() { Dock = DockStyle.Fill, Minimum = 0, Maximum = 100 };
    private readonly Label _status = new() { Dock = DockStyle.Fill, AutoSize = true, Text = "آماده", TextAlign = ContentAlignment.MiddleRight };
    private readonly Label _prereqHelp = new() { Dock = DockStyle.Fill, AutoSize = true, Padding = new Padding(8), Text = "برای دیدن توضیح هر مورد، یک ردیف را انتخاب کنید." };
    private readonly Label _serviceSummary = new() { Dock = DockStyle.Fill, AutoSize = true, Padding = new Padding(8), Text = "وضعیت سرویس‌ها هنوز بررسی نشده است." };
    private readonly Label _applicationHealth = new() { Dock = DockStyle.Fill, AutoSize = true, Padding = new Padding(8), Text = "سلامت داخلی Runtime و Print Agent هنوز بررسی نشده است." };
    private readonly Label _lastServiceEvent = new() { Dock = DockStyle.Fill, AutoSize = true, Padding = new Padding(8), Text = "آخرین رخداد مرتبط سرویس‌ها هنوز خوانده نشده است." };

    private readonly Button _install = new() { Text = "نصب / به‌روزرسانی سرویس‌ها", AutoSize = true };
    private readonly Button _repair = new() { Text = "تعمیر نصب", AutoSize = true };
    private readonly Button _uninstall = new() { Text = "حذف سرویس‌ها", AutoSize = true };
    private readonly Button _refresh = new() { Text = "بررسی دوباره", AutoSize = true };
    private readonly Button _download = new() { Text = "دریافت فایل رسمی و تأییدشده", AutoSize = true };
    private readonly Button _localPrereq = new() { Text = "انتخاب فایل از کامپیوتر", AutoSize = true };
    private readonly Button _cancelDownload = new() { Text = "لغو دانلود", AutoSize = true, Enabled = false };
    private readonly Button _guidance = new() { Text = "راهنمای گام‌به‌گام", AutoSize = true };
    private readonly Button _officialPage = new() { Text = "صفحه رسمی", AutoSize = true };
    private readonly Button _browsePairing = new() { Text = "انتخاب فایل…", AutoSize = true };
    private readonly Button _refreshServices = new() { Text = "تازه‌سازی وضعیت", AutoSize = true };
    private readonly Button _openLogs = new() { Text = "باز کردن لاگ‌ها", AutoSize = true };
    private readonly Button _openSetupLog = new() { Text = "آخرین گزارش نصب", AutoSize = true };
    private readonly Button _supportBundle = new() { Text = "ساخت بسته عیب‌یابی", AutoSize = true };
    private readonly Button _servicesConsole = new() { Text = "Windows Services", AutoSize = true };
    private readonly Button _eventViewer = new() { Text = "Event Viewer", AutoSize = true };

    private readonly PrerequisitePolicy _policy;
    private readonly ReleaseLock _lock;
    private readonly Dictionary<string, DetectionResult> _results = new(StringComparer.OrdinalIgnoreCase);
    private readonly ToolTip _tips = new() { AutoPopDelay = 15000, InitialDelay = 350, ReshowDelay = 100 };
    private CancellationTokenSource? _downloadCts;

    public SetupForm()
    {
        Text = $"مدیریت سرویس‌های سکنا — نسخه {Application.ProductVersion}";
        Width = 1220;
        Height = 840;
        MinimumSize = new Size(900, 650);
        StartPosition = FormStartPosition.CenterScreen;
        RightToLeft = RightToLeft.Yes;

        // Keep the native Windows title-bar controls in their standard top-right position.
        // Internal controls are mirrored explicitly below.
        RightToLeftLayout = false;
        Font = PickFont();
        AutoScaleMode = AutoScaleMode.Dpi;
        ShowIcon = true;
        Icon = LoadAppIcon();

        _installRoot.Text = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.ProgramFiles), "SOKNA Windows Services");
        _dataRoot.Text = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData), "SOKNA");
        _policy = LoadJson<PrerequisitePolicy>(Path.Combine(AppContext.BaseDirectory, "prerequisites.json"), "فایل سیاست پیش‌نیازها");
        _lock = LoadJson<ReleaseLock>(Path.Combine(AppContext.BaseDirectory, "release-lock.json"), "فایل نسخه‌های تأییدشده پیش‌نیازها");
        ValidateContracts();
        BuildUi();
        ConfigureTooltips();
        Load += (_, _) => FitToWorkingArea();
        Shown += async (_, _) =>
        {
            await RefreshPrerequisitesAsync();
            await RefreshServicesAsync();
        };
    }

    private static Font PickFont()
    {
        foreach (var name in new[] { "Tahoma", "Segoe UI" })
        {
            try
            {
                using var probe = new Font(name, 10.0f, FontStyle.Regular, GraphicsUnit.Point);
                if (string.Equals(probe.Name, name, StringComparison.OrdinalIgnoreCase))
                    return new Font(name, 10.0f, FontStyle.Regular, GraphicsUnit.Point);
            }
            catch { }
        }
        return SystemFonts.MessageBoxFont;
    }

    private static Icon? LoadAppIcon()
    {
        try
        {
            var path = Path.Combine(AppContext.BaseDirectory, "Sokna.ico");
            return File.Exists(path) ? new Icon(path) : null;
        }
        catch { return null; }
    }

    private void FitToWorkingArea()
    {
        var work = Screen.FromControl(this).WorkingArea;
        var width = Math.Min(1240, Math.Max(MinimumSize.Width, work.Width - 60));
        var height = Math.Min(880, Math.Max(MinimumSize.Height, work.Height - 60));
        Size = new Size(Math.Min(width, work.Width), Math.Min(height, work.Height));
        Location = new Point(
            work.Left + Math.Max(0, (work.Width - Width) / 2),
            work.Top + Math.Max(0, (work.Height - Height) / 2));
    }

    private void BuildUi()
    {
        var root = new TableLayoutPanel { Dock = DockStyle.Fill, Padding = new Padding(16), ColumnCount = 1, RowCount = 3, RightToLeft = RightToLeft.Yes };
        root.RowStyles.Add(new RowStyle(SizeType.AutoSize));
        root.RowStyles.Add(new RowStyle(SizeType.Percent, 100));
        root.RowStyles.Add(new RowStyle(SizeType.AutoSize));

        var header = new TableLayoutPanel { Dock = DockStyle.Fill, AutoSize = true, ColumnCount = 1, RightToLeft = RightToLeft.Yes, Padding = new Padding(0, 0, 0, 10) };
        header.Controls.Add(new Label
        {
            Text = $"مدیریت سرویس‌های سکنا — نسخه {Application.ProductVersion}",
            AutoSize = true,
            Font = new Font(Font, FontStyle.Bold),
            Padding = new Padding(0, 0, 0, 4)
        });
        header.Controls.Add(new Label
        {
            Text = "این برنامه فقط Runtime و Print Agent سکنا را نصب و نگهداری می‌کند. زیرساخت Local Web در ابزار مستقل «SOKNA Prerequisites Setup» آماده می‌شود و در این برنامه نمایش یا مدیریت نمی‌شود.",
            AutoSize = true,
            MaximumSize = new Size(980, 0)
        });
        root.Controls.Add(header, 0, 0);

        var tabs = new TabControl { Dock = DockStyle.Fill, RightToLeft = RightToLeft.Yes, RightToLeftLayout = true };
        tabs.TabPages.Add(BuildPrerequisitesTab());
        tabs.TabPages.Add(BuildLifecycleTab());
        tabs.TabPages.Add(BuildDiagnosticsTab());
        root.Controls.Add(tabs, 0, 1);

        var bottom = new TableLayoutPanel { Dock = DockStyle.Fill, AutoSize = true, ColumnCount = 3, RightToLeft = RightToLeft.Yes, Padding = new Padding(0, 10, 0, 0) };
        bottom.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 100));
        bottom.ColumnStyles.Add(new ColumnStyle(SizeType.Absolute, 260));
        bottom.ColumnStyles.Add(new ColumnStyle(SizeType.AutoSize));
        bottom.Controls.Add(_status, 0, 0);
        bottom.Controls.Add(_progress, 1, 0);
        bottom.Controls.Add(_cancelDownload, 2, 0);
        root.Controls.Add(bottom, 0, 2);

        Controls.Add(root);

        _refresh.Click += async (_, _) => await RefreshPrerequisitesAsync();
        _download.Click += async (_, _) => await DownloadSelectedAsync();
        _localPrereq.Click += async (_, _) => await SelectLocalPrerequisiteAsync();
        _cancelDownload.Click += (_, _) => _downloadCts?.Cancel();
        _guidance.Click += (_, _) => ShowGuidance();
        _officialPage.Click += (_, _) => OpenOfficialPage();
        _browsePairing.Click += (_, _) => BrowsePairing();
        _install.Click += async (_, _) => await RunLifecycleAsync("install");
        _repair.Click += async (_, _) => await RunLifecycleAsync("repair");
        _uninstall.Click += async (_, _) => await RunLifecycleAsync("uninstall");
        _refreshServices.Click += async (_, _) => await RefreshServicesAsync();
        _openLogs.Click += (_, _) => OpenLogsFolder();
        _openSetupLog.Click += (_, _) => OpenSetupLog();
        _supportBundle.Click += async (_, _) => await BuildSupportBundleAsync();
        _servicesConsole.Click += (_, _) => OpenServicesConsole();
        _eventViewer.Click += (_, _) => OpenEventViewer();
        _prereqs.SelectedIndexChanged += (_, _) => UpdatePrerequisiteHelp();
    }

    private TabPage BuildPrerequisitesTab()
    {
        var page = new TabPage("۱. پیش‌نیازها") { RightToLeft = RightToLeft.Yes, AutoScroll = true };
        var layout = new TableLayoutPanel { Dock = DockStyle.Top, AutoSize = true, AutoSizeMode = AutoSizeMode.GrowAndShrink, Padding = new Padding(12), ColumnCount = 1, RowCount = 6, RightToLeft = RightToLeft.Yes };
        for (var i = 0; i < 6; i++) layout.RowStyles.Add(new RowStyle(SizeType.AutoSize));

        layout.Controls.Add(InfoBox(
            "اول چه چیزی را باید انجام بدهم؟",
            "این بخش فقط پیش‌نیازهای مستقیم Runtime و Print Agent را نشان می‌دهد. زیرساخت Local Web از این نصب جداست. اگر Microsoft Visual C++ آماده نیست، ردیف را انتخاب کنید و از فایل رسمی و تأییدشده یا راهنمای نصب استفاده کنید."
        ));

        _prereqs.Dock = DockStyle.Top;
        _prereqs.Height = 280;
        _prereqs.MinimumSize = new Size(0, 240);
        _prereqs.Columns.Add("پیش‌نیاز", 270);
        _prereqs.Columns.Add("مربوط به", 150);
        _prereqs.Columns.Add("وضعیت", 210);
        _prereqs.Columns.Add("نسخه / مسیر", 390);
        layout.Controls.Add(_prereqs);

        layout.Controls.Add(_prereqHelp);
        layout.Controls.Add(Flow(_refresh, _download, _localPrereq, _guidance, _officialPage));
        page.Controls.Add(layout);
        return page;
    }

    private TabPage BuildLifecycleTab()
    {
        var page = new TabPage("۲. نصب و نگهداری") { RightToLeft = RightToLeft.Yes, AutoScroll = true };
        var layout = new TableLayoutPanel { Dock = DockStyle.Top, AutoSize = true, AutoSizeMode = AutoSizeMode.GrowAndShrink, Padding = new Padding(12), ColumnCount = 1, RowCount = 8, RightToLeft = RightToLeft.Yes };
        for (var i = 0; i < 8; i++) layout.RowStyles.Add(new RowStyle(SizeType.AutoSize));

        layout.Controls.Add(InfoBox(
            "این مرحله چه کاری انجام می‌دهد؟",
            "«نصب / به‌روزرسانی» فقط دو سرویس SOKNA Runtime و SOKNA Print Worker را روی ویندوز نصب یا به‌روزرسانی می‌کند. داده‌های کسب‌وکار، Local Web، Apache، PHP و MariaDB دست‌کاری نمی‌شوند. عملیات نیاز به دسترسی Administrator دارد."
        ));
        layout.Controls.Add(LabeledRow("پوشه نصب سرویس‌ها", _installRoot, "فایل‌های اجرایی سرویس‌های سکنا در این مسیر قرار می‌گیرند."));
        layout.Controls.Add(LabeledRow("پوشه داده و لاگ‌ها", _dataRoot, "وضعیت، تنظیمات، لاگ‌ها و فایل‌های عیب‌یابی سکنا در این مسیر نگهداری می‌شوند."));

        var pairHelp = new Label
        {
            AutoSize = true,
            Dock = DockStyle.Fill,
            Text = "Pairing اختیاری است. اگر Local Web هنوز آماده نیست، این قسمت را خالی بگذارید؛ Print Agent نصب و اجرا می‌شود و Runtime تا زمان Pairing منتظر می‌ماند.",
            Padding = new Padding(4, 8, 4, 4)
        };
        layout.Controls.Add(pairHelp);

        var pairRow = new TableLayoutPanel { Dock = DockStyle.Fill, AutoSize = true, ColumnCount = 3, RightToLeft = RightToLeft.Yes };
        pairRow.ColumnStyles.Add(new ColumnStyle(SizeType.AutoSize));
        pairRow.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 100));
        pairRow.ColumnStyles.Add(new ColumnStyle(SizeType.AutoSize));
        pairRow.Controls.Add(new Label { Text = "فایل Pairing", AutoSize = true, Anchor = AnchorStyles.Right, Padding = new Padding(0, 7, 0, 0) }, 0, 0);
        pairRow.Controls.Add(_pairing, 1, 0);
        pairRow.Controls.Add(_browsePairing, 2, 0);
        layout.Controls.Add(pairRow);

        layout.Controls.Add(_startPaired);

        var actions = Flow(_install, _repair, _uninstall);
        actions.Padding = new Padding(0, 14, 0, 0);
        layout.Controls.Add(actions);

        layout.Controls.Add(new Label
        {
            AutoSize = true,
            Dock = DockStyle.Fill,
            Text = "توجه: «تعمیر نصب» فایل‌های دو سرویس را دوباره اعمال می‌کند. «حذف سرویس‌ها» فقط سرویس‌های متعلق به سکنا را حذف می‌کند و پوشه داده‌ها و زیرساخت خارجی را نگه می‌دارد.",
            Padding = new Padding(8)
        });
        page.Controls.Add(layout);
        return page;
    }

    private TabPage BuildDiagnosticsTab()
    {
        var page = new TabPage("۳. وضعیت و عیب‌یابی") { RightToLeft = RightToLeft.Yes, AutoScroll = true };
        var layout = new TableLayoutPanel { Dock = DockStyle.Top, AutoSize = true, AutoSizeMode = AutoSizeMode.GrowAndShrink, Padding = new Padding(12), ColumnCount = 1, RowCount = 6, RightToLeft = RightToLeft.Yes };
        for (var i = 0; i < 6; i++) layout.RowStyles.Add(new RowStyle(SizeType.AutoSize));

        layout.Controls.Add(InfoBox(
            "فقط نصب بودن سرویس کافی نیست",
            "در این بخش می‌بینید هر سرویس واقعاً نصب شده و در حال اجرا هست یا نه، نوع شروع آن چیست و Process ID دارد یا خیر. برای بررسی خطا می‌توانید لاگ‌ها را باز کنید یا یک بسته عیب‌یابی قابل ارسال بسازید."
        ));

        _services.Dock = DockStyle.Top;
        _services.Height = 310;
        _services.MinimumSize = new Size(0, 260);
        _services.Columns.Add("سرویس", 250);
        _services.Columns.Add("نصب", 100);
        _services.Columns.Add("وضعیت اجرا", 170);
        _services.Columns.Add("نوع شروع", 150);
        _services.Columns.Add("PID", 100);
        _services.Columns.Add("مسیر اجرا", 330);
        layout.Controls.Add(_services);

        layout.Controls.Add(_serviceSummary);
        layout.Controls.Add(_applicationHealth);
        layout.Controls.Add(_lastServiceEvent);
        layout.Controls.Add(Flow(_refreshServices, _openLogs, _openSetupLog, _supportBundle, _servicesConsole, _eventViewer));
        page.Controls.Add(layout);
        return page;
    }

    private void ConfigureTooltips()
    {
        _tips.SetToolTip(_refresh, "پیش‌نیاز مستقیم سرویس‌های ویندوزی را دوباره بررسی می‌کند. هیچ تغییری ایجاد نمی‌کند.");
        _tips.SetToolTip(_download, "فایل نسخه قفل‌شده را دانلود می‌کند، اندازه و SHA-256 آن را می‌سنجد و فقط فایل را نشان می‌دهد؛ نصب خودکار انجام نمی‌شود.");
        _tips.SetToolTip(_localPrereq, "برای حالت آفلاین همان فایل رسمی را از کامپیوتر یا فلش انتخاب می‌کند، اندازه/SHA-256 و در صورت نیاز امضای دیجیتال را بررسی و در Cache تأییدشده ذخیره می‌کند.");
        _tips.SetToolTip(_guidance, "برای مورد انتخاب‌شده یک راهنمای فارسی مرحله‌به‌مرحله نشان می‌دهد.");
        _tips.SetToolTip(_officialPage, "صفحه رسمی ارائه‌دهنده پیش‌نیاز انتخاب‌شده را در مرورگر باز می‌کند.");
        _tips.SetToolTip(_cancelDownload, "دانلود جاری را متوقف می‌کند. فایل ناقص برای ادامه دانلود در نوبت بعد نگه داشته می‌شود.");
        _tips.SetToolTip(_install, "Runtime و Print Agent را نصب/به‌روزرسانی می‌کند و ممکن است سرویس‌های قبلی را برای چند لحظه متوقف کند.");
        _tips.SetToolTip(_repair, "همان دو سرویس را از روی بسته نصب دوباره اعمال می‌کند. داده‌های برنامه حذف نمی‌شوند.");
        _tips.SetToolTip(_uninstall, "فقط سرویس‌های متعلق به سکنا را حذف می‌کند؛ داده‌ها و PHP/Apache/MariaDB حذف نمی‌شوند.");
        _tips.SetToolTip(_openLogs, "پوشه لاگ‌های SOKNA در ProgramData را باز می‌کند.");
        _tips.SetToolTip(_openSetupLog, "آخرین گزارش اجرای نصب/تعمیر/حذف سرویس‌ها را باز می‌کند.");
        _tips.SetToolTip(_supportBundle, "یک ZIP شامل وضعیت سرویس‌ها، رخدادهای مرتبط ویندوز و لاگ‌های غیرمحرمانه می‌سازد.");
        _tips.SetToolTip(_servicesConsole, "کنسول استاندارد Services ویندوز را باز می‌کند.");
        _tips.SetToolTip(_eventViewer, "Event Viewer ویندوز را برای بررسی رخدادهای سیستمی باز می‌کند.");
    }

    private static ListView CreateRtlList() => new()
    {
        Dock = DockStyle.Fill,
        View = View.Details,
        FullRowSelect = true,
        GridLines = true,
        MultiSelect = false,
        RightToLeft = RightToLeft.Yes,
        RightToLeftLayout = true,
        HideSelection = false
    };

    private static TextBox PathBox() => new()
    {
        Dock = DockStyle.Fill,
        RightToLeft = RightToLeft.No,
        TextAlign = HorizontalAlignment.Left
    };

    private static Control InfoBox(string title, string body)
    {
        var panel = new TableLayoutPanel { Dock = DockStyle.Fill, AutoSize = true, ColumnCount = 1, Padding = new Padding(10), RightToLeft = RightToLeft.Yes };
        panel.Controls.Add(new Label { Text = title, AutoSize = true, Dock = DockStyle.Fill, TextAlign = ContentAlignment.MiddleRight, Font = new Font("Tahoma", 10F, FontStyle.Bold) });
        panel.Controls.Add(new Label { Text = body, AutoSize = true, Dock = DockStyle.Fill, TextAlign = ContentAlignment.TopRight, MaximumSize = new Size(1080, 0), Padding = new Padding(0, 4, 0, 0) });
        return panel;
    }

    private static Control LabeledRow(string label, Control control, string help)
    {
        var outer = new TableLayoutPanel { Dock = DockStyle.Fill, AutoSize = true, ColumnCount = 1, RightToLeft = RightToLeft.Yes, Padding = new Padding(0, 5, 0, 5) };
        var row = new TableLayoutPanel { Dock = DockStyle.Fill, AutoSize = true, ColumnCount = 2, RightToLeft = RightToLeft.Yes };
        row.ColumnStyles.Add(new ColumnStyle(SizeType.AutoSize));
        row.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 100));
        row.Controls.Add(new Label { Text = label, AutoSize = true, Anchor = AnchorStyles.Right, Padding = new Padding(0, 7, 0, 0) }, 0, 0);
        row.Controls.Add(control, 1, 0);
        outer.Controls.Add(row);
        outer.Controls.Add(new Label { Text = help, AutoSize = true, Padding = new Padding(4, 2, 4, 0) });
        return outer;
    }

    private static FlowLayoutPanel Flow(params Control[] controls)
    {
        var p = new FlowLayoutPanel { AutoSize = true, Dock = DockStyle.Fill, FlowDirection = FlowDirection.RightToLeft, WrapContents = true, RightToLeft = RightToLeft.Yes, Padding = new Padding(0, 4, 0, 4) };
        foreach (var control in controls)
        {
            if (control is Button button)
            {
                button.MinimumSize = new Size(110, 36);
                button.Margin = new Padding(8, 3, 0, 3);
            }
        }
        p.Controls.AddRange(controls);
        return p;
    }

    private void ValidateContracts()
    {
        if (_policy.Format != "sokna-windows-prerequisites-v2" || _policy.SchemaVersion != 2 || _policy.Ownership != "external" || _policy.AutomaticInstallAllowed)
            throw new InvalidOperationException("قرارداد پیش‌نیازهای Windows Services معتبر نیست.");
        if (_lock.Format != "sokna-windows-prerequisite-lock-v1" || _lock.SchemaVersion != 1 || !_lock.ReleaseFrozen)
            throw new InvalidOperationException("فهرست نسخه‌های تأییدشده پیش‌نیازها معتبر نیست.");

        foreach (var a in _lock.Artifacts)
        {
            var urls = new[] { a.SourceUrl }.Concat(a.FallbackUrls ?? []);
            if (urls.Any(u => !Uri.TryCreate(u, UriKind.Absolute, out var uri) || uri.Scheme != Uri.UriSchemeHttps))
                throw new InvalidOperationException("یکی از آدرس‌های دانلود پیش‌نیازها امن یا معتبر نیست.");
            if (!IsSha(a.Sha256) || a.Size <= 0 || Path.GetFileName(a.Filename) != a.Filename || a.Installation != "manual-external")
                throw new InvalidOperationException("یکی از فایل‌های قفل‌شده پیش‌نیازها معتبر نیست.");
            if (a.Authenticode.Required && string.IsNullOrWhiteSpace(a.Authenticode.PublisherContains))
                throw new InvalidOperationException("سیاست امضای یکی از فایل‌های پیش‌نیاز کامل نیست.");
        }
    }

    private async Task RefreshPrerequisitesAsync()
    {
        SetBusy(true, "در حال بررسی پیش‌نیازها…");
        try
        {
            _results.Clear();
            _prereqs.Items.Clear();
            foreach (var item in _policy.Items)
            {
                var result = await Task.Run(() => Detect(item));
                _results[item.Id] = result;

                var row = new ListViewItem(item.DisplayName) { Tag = item.Id };
                row.SubItems.Add(item.RequiredFor.Equals("windows-services", StringComparison.OrdinalIgnoreCase) ? "سرویس‌های ویندوز" : "Local Web");
                row.SubItems.Add(result.Status);
                row.SubItems.Add(string.Join(" · ", new[] { result.FoundVersion, result.FoundPath }.Where(x => !string.IsNullOrWhiteSpace(x))));
                _prereqs.Items.Add(row);
            }

            var blockers = _policy.Items.Where(x => x.BlocksWindowsServices && (!_results.TryGetValue(x.Id, out var r) || !r.Satisfied)).ToList();
            _status.Text = blockers.Count == 0
                ? "پیش‌نیاز لازم برای سرویس‌های ویندوز آماده است. زیرساخت Local Web در ابزار مستقل Prerequisites Setup مدیریت می‌شود."
                : "برای نصب سرویس‌های ویندوز ابتدا این مورد را آماده کنید: " + string.Join("، ", blockers.Select(x => x.DisplayName));

            if (_prereqs.Items.Count > 0 && _prereqs.SelectedItems.Count == 0)
            {
                var firstProblem = _prereqs.Items.Cast<ListViewItem>().FirstOrDefault(x =>
                {
                    var id = Convert.ToString(x.Tag) ?? "";
                    return _results.TryGetValue(id, out var r) && !r.Satisfied;
                }) ?? _prereqs.Items[0];
                firstProblem.Selected = true;
            }
        }
        catch (Exception e)
        {
            ShowError(FriendlyError(e));
        }
        finally
        {
            SetBusy(false);
        }
    }

    private DetectionResult Detect(PrerequisiteItem item)
    {
        try
        {
            if (item.Detection.Type == "registry") return DetectRegistry(item.Detection);
            var exe = FindCommand(item);
            if (string.IsNullOrWhiteSpace(exe)) return new(false, "پیدا نشد", "", "");

            var versionOutput = RunCapture(exe, item.Detection.VersionArgument);
            var version = ExtractVersion(versionOutput);
            if (!VersionAtLeast(version, item.Detection.MinimumVersion))
                return new(false, string.IsNullOrWhiteSpace(version) ? "نسخه قابل تشخیص نیست" : "نسخه قدیمی", exe, version);

            if (item.Detection.Type == "php" && item.Detection.RequiredExtensions.Count > 0)
            {
                var modules = RunCapture(exe, "-m")
                    .Split(new[] { '\r', '\n' }, StringSplitOptions.RemoveEmptyEntries)
                    .Select(x => x.Trim())
                    .ToHashSet(StringComparer.OrdinalIgnoreCase);
                var missing = item.Detection.RequiredExtensions.Where(x => !modules.Contains(x)).ToList();
                if (missing.Count > 0)
                    return new(false, "افزونه ناقص: " + string.Join(", ", missing), exe, version);
            }

            return new(true, "سازگار", exe, version);
        }
        catch (Exception e)
        {
            return new(false, "خطای بررسی: " + FriendlyError(e), "", "");
        }
    }

    private static DetectionResult DetectRegistry(DetectionRule rule)
    {
        foreach (var view in new[] { RegistryView.Registry64, RegistryView.Registry32 })
        {
            using var baseKey = RegistryKey.OpenBaseKey(RegistryHive.LocalMachine, view);
            using var key = baseKey.OpenSubKey(rule.RegistryPath, false);
            if (key is null) continue;

            var value = Convert.ToString(key.GetValue(rule.ValueName)) ?? "";
            var version = ExtractVersion(value);
            if (VersionAtLeast(version, rule.MinimumVersion))
                return new(true, "سازگار", "HKLM\\" + rule.RegistryPath, version);
            if (!string.IsNullOrWhiteSpace(version))
                return new(false, "نسخه قدیمی", "HKLM\\" + rule.RegistryPath, version);
        }
        return new(false, "پیدا نشد", "", "");
    }

    private async Task DownloadSelectedAsync()
    {
        if (!_policy.AutomaticDownloadAllowed)
        {
            ShowError("دریافت آنلاین طبق سیاست این نسخه غیرفعال است.");
            return;
        }

        var item = SelectedItem();
        if (item is null)
        {
            ShowError("ابتدا یک ردیف از پیش‌نیازها را انتخاب کنید.");
            return;
        }

        var artifact = ArtifactFor(item);
        if (artifact is null)
        {
            ShowError("برای این پیش‌نیاز فایل تأییدشده‌ای ثبت نشده است.");
            return;
        }

        var sizeMb = artifact.Size / 1024d / 1024d;
        var confirm = MessageBox.Show(
            this,
            $"فایل رسمی «{artifact.Filename}» با حجم حدود {sizeMb:0.0} مگابایت دانلود می‌شود.\n\nپس از دانلود، SHA-256 و در صورت نیاز امضای فایل بررسی می‌شود. SOKNA این فایل را خودکار نصب یا اجرا نمی‌کند و بعد از تأیید، راهنمای مرحله بعد را نشان می‌دهد.\n\nدانلود شروع شود؟",
            "دریافت پیش‌نیاز",
            MessageBoxButtons.YesNo,
            MessageBoxIcon.Information,
            MessageBoxDefaultButton.Button1,
            MessageBoxOptions.RtlReading | MessageBoxOptions.RightAlign);
        if (confirm != DialogResult.Yes) return;

        _downloadCts?.Dispose();
        _downloadCts = new CancellationTokenSource();
        var token = _downloadCts.Token;
        SetBusy(true, "در حال دریافت فایل تأییدشده…", allowCancel: true);

        try
        {
            var cache = CacheRoot();
            Directory.CreateDirectory(cache);
            var final = Path.Combine(cache, artifact.Filename);
            var partial = final + ".partial";

            if (File.Exists(final) && await VerifyArtifactAsync(final, artifact))
            {
                _status.Text = "فایل قبلاً دانلود و تأیید شده است: " + final;
                OpenExplorer(final);
                ShowGuidance(item, artifact, final);
                return;
            }

            if (File.Exists(final)) File.Delete(final);
            await DownloadWithFallbacksAsync(artifact, partial, token);

            if (!File.Exists(partial) || new FileInfo(partial).Length != artifact.Size)
                throw new InvalidOperationException("اندازه فایل دریافت‌شده با نسخه تأییدشده یکسان نیست.");
            if (!await VerifyArtifactAsync(partial, artifact))
                throw new InvalidOperationException("هش یا امضای فایل دریافت‌شده معتبر نیست. فایل نصب نشده است.");

            File.Move(partial, final, true);
            _progress.Value = 100;
            _status.Text = "دانلود کامل و فایل تأیید شد. نصب خودکار انجام نشده است.";
            OpenExplorer(final);
            ShowGuidance(item, artifact, final);
        }
        catch (OperationCanceledException)
        {
            _status.Text = "دانلود توسط کاربر لغو شد. بخش دریافت‌شده نگه داشته شده و دفعه بعد از همان‌جا ادامه می‌یابد.";
        }
        catch (Exception e)
        {
            var message = FriendlyDownloadError(e, artifact);
            AppendUiLog("DOWNLOAD ERROR " + item.Id + ": " + message);
            ShowError(message);
        }
        finally
        {
            SetBusy(false);
            _downloadCts?.Dispose();
            _downloadCts = null;
        }
    }

    private async Task SelectLocalPrerequisiteAsync()
    {
        var item = SelectedItem();
        if (item is null)
        {
            ShowError("ابتدا یک ردیف از پیش‌نیازها را انتخاب کنید.");
            return;
        }

        var artifact = ArtifactFor(item);
        if (artifact is null)
        {
            ShowError("برای این پیش‌نیاز فایل تأییدشده‌ای ثبت نشده است.");
            return;
        }

        using var dialog = new OpenFileDialog
        {
            CheckFileExists = true,
            Multiselect = false,
            Title = $"انتخاب فایل {item.DisplayName} — نسخه {artifact.Version}",
            FileName = artifact.Filename,
            Filter = $"فایل مورد انتظار ({artifact.Filename})|{artifact.Filename}|همه فایل‌ها (*.*)|*.*"
        };
        if (dialog.ShowDialog(this) != DialogResult.OK) return;

        SetBusy(true, "در حال بررسی فایل انتخاب‌شده…");
        try
        {
            var info = new FileInfo(dialog.FileName);
            if (info.Length != artifact.Size)
                throw new InvalidOperationException($"حجم فایل انتخاب‌شده معتبر نیست. انتظار: {artifact.Size / 1024d / 1024d:0.0} MB؛ دریافت‌شده: {info.Length / 1024d / 1024d:0.0} MB.");

            _progress.Value = 35;
            _status.Text = "حجم صحیح است؛ در حال بررسی SHA-256 و امضای دیجیتال…";
            if (!await VerifyArtifactAsync(dialog.FileName, artifact))
                throw new InvalidOperationException("SHA-256 یا امضای دیجیتال فایل انتخاب‌شده با نسخه تأییدشده SOKNA تطبیق ندارد.");

            var cache = CacheRoot();
            Directory.CreateDirectory(cache);
            var final = Path.Combine(cache, artifact.Filename);
            if (!string.Equals(Path.GetFullPath(dialog.FileName), Path.GetFullPath(final), StringComparison.OrdinalIgnoreCase))
            {
                var temp = final + ".importing";
                try
                {
                    File.Copy(dialog.FileName, temp, true);
                    File.Move(temp, final, true);
                }
                finally
                {
                    try { if (File.Exists(temp)) File.Delete(temp); } catch { }
                }
            }

            try
            {
                var partial = final + ".partial";
                if (File.Exists(partial)) File.Delete(partial);
            }
            catch { }

            _progress.Value = 100;
            _status.Text = "فایل آفلاین تأیید شد و برای استفاده آماده است.";
            AppendUiLog($"LOCAL PREREQUISITE ACCEPTED {item.Id}: {artifact.Filename}");
            ShowGuidance(item, artifact, final);
        }
        catch (Exception e)
        {
            var message = "فایل انتخاب‌شده پذیرفته نشد: " + FriendlyError(e);
            AppendUiLog("LOCAL PREREQUISITE ERROR " + item.Id + ": " + Safe(message));
            ShowError(message);
        }
        finally
        {
            SetBusy(false);
        }
    }

    private async Task DownloadWithFallbacksAsync(LockedArtifact artifact, string partial, CancellationToken token)
    {
        var urls = new[] { artifact.SourceUrl }.Concat(artifact.FallbackUrls ?? []).Distinct(StringComparer.OrdinalIgnoreCase).ToList();
        var failures = new List<string>();

        foreach (var url in urls)
        {
            token.ThrowIfCancellationRequested();
            try
            {
                await DownloadResumableAsync(artifact, url, partial, token);
                return;
            }
            catch (OperationCanceledException)
            {
                throw;
            }
            catch (Exception e)
            {
                failures.Add(new Uri(url).Host + ": " + Safe(e.Message));
                AppendUiLog($"DOWNLOAD SOURCE FAILED {new Uri(url).Host}: {Safe(e.Message)}");
            }
        }

        throw new HttpRequestException("همه مسیرهای دانلود ناموفق بودند. " + string.Join(" | ", failures));
    }

    private async Task DownloadResumableAsync(LockedArtifact artifact, string sourceUrl, string partial, CancellationToken token)
    {
        var existing = File.Exists(partial) ? new FileInfo(partial).Length : 0L;
        if (existing < 0 || existing > artifact.Size)
        {
            File.Delete(partial);
            existing = 0;
        }

        using var handler = new HttpClientHandler
        {
            AllowAutoRedirect = true,
            AutomaticDecompression = DecompressionMethods.All
        };
        using var client = new HttpClient(handler) { Timeout = TimeSpan.FromMinutes(30) };
        client.DefaultRequestHeaders.UserAgent.ParseAdd("SOKNA-Windows-Services/1.0");
        client.DefaultRequestHeaders.Accept.Add(new MediaTypeWithQualityHeaderValue("application/octet-stream"));
        client.DefaultRequestHeaders.Accept.Add(new MediaTypeWithQualityHeaderValue("*/*"));

        using var request = new HttpRequestMessage(HttpMethod.Get, sourceUrl);
        if (existing > 0) request.Headers.Range = new RangeHeaderValue(existing, null);

        using var response = await client.SendAsync(request, HttpCompletionOption.ResponseHeadersRead, token);
        if (response.RequestMessage?.RequestUri?.Scheme != Uri.UriSchemeHttps)
            throw new InvalidOperationException("مسیر دانلود از HTTPS خارج شد و برای امنیت متوقف شد.");

        if (existing > 0 && response.StatusCode != HttpStatusCode.PartialContent)
        {
            existing = 0;
            if (File.Exists(partial)) File.Delete(partial);
        }

        if (!response.IsSuccessStatusCode)
            throw new HttpRequestException($"سرور دانلود کد {(int)response.StatusCode} ({response.ReasonPhrase}) برگرداند.", null, response.StatusCode);

        var mode = existing > 0 ? FileMode.Append : FileMode.Create;
        await using var input = await response.Content.ReadAsStreamAsync(token);
        await using var output = new FileStream(partial, mode, FileAccess.Write, FileShare.None, 128 * 1024, true);

        var buffer = new byte[128 * 1024];
        long done = existing;
        while (true)
        {
            var read = await input.ReadAsync(buffer.AsMemory(0, buffer.Length), token);
            if (read <= 0) break;
            await output.WriteAsync(buffer.AsMemory(0, read), token);
            done += read;

            if (done > artifact.Size)
                throw new InvalidOperationException("حجم دانلود از اندازه نسخه تأییدشده بیشتر شد.");

            var pct = artifact.Size <= 0 ? 0 : (int)Math.Clamp(done * 100L / artifact.Size, 0, 100);
            _progress.Value = pct;
            _status.Text = $"دریافت {artifact.Filename}: {pct}%";
        }
    }

    private static async Task<bool> VerifyArtifactAsync(string path, LockedArtifact artifact)
    {
        await using var stream = File.OpenRead(path);
        var hash = Convert.ToHexString(await SHA256.HashDataAsync(stream)).ToLowerInvariant();
        if (!hash.Equals(artifact.Sha256, StringComparison.OrdinalIgnoreCase)) return false;
        if (!artifact.Authenticode.Required) return true;

        var ps = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.System), "WindowsPowerShell", "v1.0", "powershell.exe");
        var escaped = path.Replace("'", "''");
        var publisher = artifact.Authenticode.PublisherContains.Replace("'", "''");
        var script = $"$s=Get-AuthenticodeSignature -LiteralPath '{escaped}'; if($s.Status -ne 'Valid' -or -not $s.SignerCertificate -or $s.SignerCertificate.Subject.IndexOf('{publisher}',[StringComparison]::OrdinalIgnoreCase) -lt 0){{exit 7}}";
        var psi = new ProcessStartInfo(ps) { UseShellExecute = false, CreateNoWindow = true };
        psi.ArgumentList.Add("-NoProfile");
        psi.ArgumentList.Add("-NonInteractive");
        psi.ArgumentList.Add("-Command");
        psi.ArgumentList.Add(script);
        using var p = Process.Start(psi) ?? throw new InvalidOperationException("بررسی امضای فایل اجرا نشد.");
        await p.WaitForExitAsync();
        return p.ExitCode == 0;
    }

    private async Task RunLifecycleAsync(string mode)
    {
        if (mode != "uninstall")
        {
            var blockers = _policy.Items.Where(x => x.BlocksWindowsServices && (!_results.TryGetValue(x.Id, out var r) || !r.Satisfied)).ToList();
            if (blockers.Count > 0)
            {
                ShowError("پیش از نصب سرویس‌ها این مورد باید آماده شود: " + string.Join("، ", blockers.Select(x => x.DisplayName)));
                return;
            }
        }

        ValidatePath(_installRoot.Text, "پوشه سرویس‌ها");
        ValidatePath(_dataRoot.Text, "پوشه داده");
        if (!string.IsNullOrWhiteSpace(_pairing.Text) && (!Path.IsPathFullyQualified(_pairing.Text.Trim()) || !File.Exists(_pairing.Text.Trim())))
        {
            ShowError("فایل Pairing معتبر نیست یا پیدا نشد.");
            return;
        }

        var description = mode switch
        {
            "repair" => "فایل‌های Runtime و Print Agent دوباره اعمال می‌شوند و سرویس‌ها ممکن است برای چند لحظه متوقف و دوباره اجرا شوند. داده‌های SOKNA حذف نمی‌شوند.",
            "uninstall" => "دو سرویس SOKNA Runtime و SOKNA Print Worker حذف می‌شوند. داده‌ها، Local Web، PHP، Apache و MariaDB حذف نمی‌شوند.",
            _ => "Runtime و Print Agent نصب یا به‌روزرسانی می‌شوند. این عملیات فقط سرویس‌های متعلق به SOKNA را تغییر می‌دهد و نیاز به دسترسی Administrator دارد."
        };

        var icon = mode == "uninstall" ? MessageBoxIcon.Warning : MessageBoxIcon.Information;
        var confirm = MessageBox.Show(
            this,
            description + "\n\nادامه می‌دهید؟",
            mode == "uninstall" ? "تأیید حذف سرویس‌ها" : "تأیید عملیات",
            MessageBoxButtons.YesNo,
            icon,
            mode == "uninstall" ? MessageBoxDefaultButton.Button2 : MessageBoxDefaultButton.Button1,
            MessageBoxOptions.RtlReading | MessageBoxOptions.RightAlign);
        if (confirm != DialogResult.Yes) return;

        SetBusy(true, "در حال اجرای عملیات سرویس‌ها…");
        string? temp = null;
        try
        {
            temp = Path.Combine(Path.GetTempPath(), "sokna-services-plan-" + Guid.NewGuid().ToString("N") + ".json");
            var plan = new Dictionary<string, object?>
            {
                ["schema_version"] = 2,
                ["mode"] = mode,
                ["shell_root"] = AppContext.BaseDirectory.TrimEnd(Path.DirectorySeparatorChar),
                ["install_root"] = Path.GetFullPath(_installRoot.Text.Trim()),
                ["data_root"] = Path.GetFullPath(_dataRoot.Text.Trim()),
                ["pairing_file"] = string.IsNullOrWhiteSpace(_pairing.Text) ? "" : Path.GetFullPath(_pairing.Text.Trim()),
                ["start_when_paired"] = _startPaired.Checked
            };
            await File.WriteAllTextAsync(temp, JsonSerializer.Serialize(plan, new JsonSerializerOptions { WriteIndented = true }));

            var host = Path.Combine(AppContext.BaseDirectory, "SoknaSetupHost.exe");
            if (!File.Exists(host)) throw new InvalidOperationException("SoknaSetupHost.exe در بسته وجود ندارد.");

            var psi = new ProcessStartInfo(host)
            {
                UseShellExecute = true,
                Verb = "runas",
                WorkingDirectory = AppContext.BaseDirectory
            };
            psi.ArgumentList.Add("--plan-file");
            psi.ArgumentList.Add(temp);

            using var p = Process.Start(psi) ?? throw new InvalidOperationException("Setup Host اجرا نشد.");
            await p.WaitForExitAsync();

            if (p.ExitCode != 0)
                throw new InvalidOperationException($"عملیات با کد {p.ExitCode} متوقف شد. از تب «وضعیت و عیب‌یابی»، «آخرین گزارش نصب» را باز کنید.");

            _status.Text = mode switch
            {
                "uninstall" => "سرویس‌های سکنا حذف شدند؛ داده‌ها و زیرساخت خارجی دست‌نخورده باقی ماندند.",
                "repair" => "سرویس‌های سکنا تعمیر شدند.",
                _ => "Runtime و Print Agent نصب/به‌روزرسانی شدند."
            };

            await RefreshServicesAsync();
            MessageBox.Show(
                this,
                _status.Text,
                "عملیات کامل شد",
                MessageBoxButtons.OK,
                MessageBoxIcon.Information,
                MessageBoxDefaultButton.Button1,
                MessageBoxOptions.RtlReading | MessageBoxOptions.RightAlign);
        }
        catch (Win32Exception e) when (e.NativeErrorCode == 1223)
        {
            _status.Text = "درخواست دسترسی Administrator لغو شد؛ تغییری اعمال نشد.";
        }
        catch (Exception e)
        {
            var message = FriendlyError(e);
            AppendUiLog("LIFECYCLE ERROR: " + message);
            ShowError(message);
        }
        finally
        {
            if (temp is not null)
            {
                try { File.Delete(temp); } catch { }
            }
            SetBusy(false);
        }
    }

    private async Task RefreshServicesAsync()
    {
        _refreshServices.Enabled = false;
        _serviceSummary.Text = "در حال بررسی وضعیت واقعی سرویس‌ها…";
        try
        {
            var snapshots = await Task.Run(() => new[]
            {
                ReadService(RuntimeService, "SOKNA Runtime"),
                ReadService(PrintService, "SOKNA Print Worker")
            });

            _services.Items.Clear();
            foreach (var s in snapshots)
            {
                var row = new ListViewItem(s.DisplayName) { Tag = s.Name };
                row.SubItems.Add(s.Installed ? "نصب شده" : "نصب نشده");
                row.SubItems.Add(ServiceStateFa(s.State));
                row.SubItems.Add(StartTypeFa(s.StartType));
                row.SubItems.Add(string.IsNullOrWhiteSpace(s.ProcessId) || s.ProcessId == "0" ? "—" : s.ProcessId);
                row.SubItems.Add(s.ImagePath);
                _services.Items.Add(row);
            }

            var paired = IsPaired();
            var runtime = snapshots.First(x => x.Name == RuntimeService);
            var print = snapshots.First(x => x.Name == PrintService);

            if (!runtime.Installed && !print.Installed)
                _serviceSummary.Text = "هیچ‌کدام از سرویس‌های سکنا نصب نشده‌اند.";
            else if (print.Installed && print.State.Equals("RUNNING", StringComparison.OrdinalIgnoreCase) &&
                     runtime.Installed && runtime.State.Equals("RUNNING", StringComparison.OrdinalIgnoreCase))
                _serviceSummary.Text = "هر دو سرویس نصب شده و واقعاً در حال اجرا هستند.";
            else if (!paired && print.Installed && print.State.Equals("RUNNING", StringComparison.OrdinalIgnoreCase) && runtime.Installed)
                _serviceSummary.Text = "Print Agent در حال اجرا است. Runtime هنوز Pairing نشده؛ متوقف بودن Runtime در این مرحله می‌تواند طبیعی باشد.";
            else
                _serviceSummary.Text = "حداقل یکی از سرویس‌ها نصب است اما در وضعیت مورد انتظار اجرا نمی‌شود. «آخرین گزارش نصب» و «باز کردن لاگ‌ها» را بررسی کنید؛ در صورت نیاز بسته عیب‌یابی بسازید.";

            var diagnostics = await Task.Run(() => (Health: ReadApplicationHealth(), Event: ReadLastServiceEvent()));
            _applicationHealth.Text = diagnostics.Health;
            _lastServiceEvent.Text = diagnostics.Event;
        }
        catch (Exception e)
        {
            _serviceSummary.Text = "بررسی وضعیت سرویس‌ها کامل نشد: " + FriendlyError(e);
        }
        finally
        {
            _refreshServices.Enabled = true;
        }
    }

    private static ServiceSnapshot ReadService(string serviceName, string displayName)
    {
        var sc = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.System), "sc.exe");
        var query = RunProcess(sc, "queryex", serviceName);
        if (query.ExitCode != 0 || query.Output.Contains("1060", StringComparison.OrdinalIgnoreCase))
            return new(serviceName, displayName, false, "NOT_INSTALLED", "", "", "");

        var qc = RunProcess(sc, "qc", serviceName);
        var state = MatchValue(query.Output, @"STATE\s*:\s*\d+\s+([A-Z_]+)");
        var pid = MatchValue(query.Output, @"PID\s*:\s*(\d+)");
        var start = MatchValue(qc.Output, @"START_TYPE\s*:\s*\d+\s+([A-Z_-]+)");
        var image = "";
        try
        {
            using var key = Registry.LocalMachine.OpenSubKey(@"SYSTEM\CurrentControlSet\Services\" + serviceName, false);
            image = Convert.ToString(key?.GetValue("ImagePath")) ?? "";
        }
        catch { }

        return new(serviceName, displayName, true, state, start, pid, image);
    }

    private bool IsPaired()
    {
        try
        {
            var state = Path.Combine(DataRoot(), "setup", "windows-services-state.json");
            if (!File.Exists(state)) return false;
            using var doc = JsonDocument.Parse(File.ReadAllText(state));
            return doc.RootElement.TryGetProperty("paired", out var p) && p.ValueKind == JsonValueKind.True;
        }
        catch
        {
            return false;
        }
    }

    private void UpdatePrerequisiteHelp()
    {
        var item = SelectedItem();
        if (item is null)
        {
            _prereqHelp.Text = "برای دیدن توضیح هر مورد، یک ردیف را انتخاب کنید.";
            return;
        }

        var result = _results.TryGetValue(item.Id, out var r) ? r : null;
        var role = item.BlocksWindowsServices
            ? "این مورد برای نصب سرویس‌های ویندوز الزامی است."
            : "این مورد مربوط به Local Web است و نبودن آن جلوی نصب Runtime و Print Agent را نمی‌گیرد.";
        var state = result is null ? "" : " وضعیت فعلی: " + result.Status + ".";
        _prereqHelp.Text = role + state + " برای مراحل دقیق «راهنمای گام‌به‌گام» را بزنید.";
    }

    private void ShowGuidance()
    {
        var item = SelectedItem();
        if (item is null)
        {
            ShowError("ابتدا یک ردیف از پیش‌نیازها را انتخاب کنید.");
            return;
        }
        ShowGuidance(item, ArtifactFor(item), null);
    }

    private void ShowGuidance(PrerequisiteItem item, LockedArtifact? artifact, string? downloadedPath)
    {
        var form = new Form
        {
            Text = "راهنمای نصب — " + item.DisplayName,
            Width = 760,
            Height = 620,
            MinimumSize = new Size(640, 480),
            StartPosition = FormStartPosition.CenterParent,
            RightToLeft = RightToLeft.Yes,
            RightToLeftLayout = false,
            Font = Font
        };

        var root = new TableLayoutPanel { Dock = DockStyle.Fill, Padding = new Padding(16), ColumnCount = 1, RowCount = 4, RightToLeft = RightToLeft.Yes };
        root.RowStyles.Add(new RowStyle(SizeType.AutoSize));
        root.RowStyles.Add(new RowStyle(SizeType.Percent, 100));
        root.RowStyles.Add(new RowStyle(SizeType.AutoSize));
        root.RowStyles.Add(new RowStyle(SizeType.AutoSize));

        root.Controls.Add(new Label
        {
            Text = item.DisplayName,
            AutoSize = true,
            Font = new Font(Font, FontStyle.Bold),
            Padding = new Padding(0, 0, 0, 8)
        });

        var guide = new RichTextBox
        {
            Dock = DockStyle.Fill,
            ReadOnly = true,
            BorderStyle = BorderStyle.FixedSingle,
            RightToLeft = RightToLeft.Yes,
            DetectUrls = true,
            Text = BuildGuideText(item, artifact, downloadedPath)
        };
        root.Controls.Add(guide);

        if (!string.IsNullOrWhiteSpace(downloadedPath))
        {
            var p = new TextBox
            {
                Dock = DockStyle.Fill,
                ReadOnly = true,
                RightToLeft = RightToLeft.No,
                Text = downloadedPath
            };
            root.Controls.Add(p);
        }
        else
        {
            root.Controls.Add(new Label { AutoSize = true, Text = "اگر هنوز فایل را ندارید، می‌توانید از پنجره اصلی «دریافت فایل رسمی و تأییدشده» را بزنید." });
        }

        var close = new Button { Text = "بستن", AutoSize = true, DialogResult = DialogResult.OK };
        var official = new Button { Text = "باز کردن صفحه رسمی", AutoSize = true };
        official.Click += (_, _) => OpenUrl(OfficialPage(item.Id));
        var folder = new Button { Text = "باز کردن پوشه فایل", AutoSize = true, Enabled = !string.IsNullOrWhiteSpace(downloadedPath) };
        folder.Click += (_, _) =>
        {
            if (!string.IsNullOrWhiteSpace(downloadedPath)) OpenExplorer(downloadedPath);
        };
        root.Controls.Add(Flow(close, official, folder));

        form.Controls.Add(root);
        form.AcceptButton = close;
        form.ShowDialog(this);
    }

    private string BuildGuideText(PrerequisiteItem item, LockedArtifact? artifact, string? downloadedPath)
    {
        var version = artifact?.Version ?? item.Detection.MinimumVersion;
        var header =
            $"این راهنما برای نسخه {version} است.\n" +
            "این برنامه فقط پیش‌نیاز مستقیم Windows Services را مدیریت می‌کند. زیرساخت Local Web در ابزار مستقل Prerequisites Setup قرار دارد.\n\n";

        var steps = item.Id switch
        {
            "php" =>
                "۱) فایل ZIP مربوط به PHP x64 Thread Safe را دریافت کنید.\n" +
                "۲) یک پوشه ثابت برای PHP بسازید؛ برای مثال C:\\PHP82 یا مسیر استاندارد زیرساخت خودتان.\n" +
                "۳) تمام محتوای ZIP را داخل همان پوشه Extract کنید. PHP فایل Setup.exe ندارد و نصب آن در ویندوز یعنی Extract و تنظیم آن.\n" +
                "۴) فایل php.ini-production را به php.ini کپی کنید.\n" +
                "۵) در php.ini مسیر extension_dir را روی ext قرار دهید و افزونه‌های pdo_mysql، fileinfo، openssl، sodium و mbstring را فعال کنید.\n" +
                "۶) پوشه‌ای که php.exe داخل آن است را به PATH ویندوز اضافه کنید؛ سپس یک Command Prompt جدید باز کنید و php -v و php -m را اجرا کنید.\n" +
                "۷) به SOKNA برگردید و «بررسی دوباره» را بزنید. اگر افزونه‌ای ناقص باشد، نام همان افزونه در ستون وضعیت نوشته می‌شود.",

            "apache" =>
                "۱) فایل ZIP دانلودشده را Extract کنید. در بسته Apache Lounge معمولاً پوشه‌ای به نام Apache24 وجود دارد.\n" +
                "۲) برای ساده‌ترین نصب، پوشه Apache24 را در C:\\Apache24 قرار دهید؛ در این حالت فایل اصلی C:\\Apache24\\bin\\httpd.exe خواهد بود.\n" +
                "۳) Command Prompt را با Run as administrator باز کنید.\n" +
                "۴) ابتدا C:\\Apache24\\bin\\httpd.exe -t را اجرا کنید. اگر Syntax OK دیدید، تنظیمات پایه معتبر است.\n" +
                "۵) برای ثبت Apache به‌عنوان سرویس ویندوز C:\\Apache24\\bin\\httpd.exe -k install را اجرا کنید.\n" +
                "۶) سپس C:\\Apache24\\bin\\httpd.exe -k start را اجرا کنید یا سرویس Apache را از Services ویندوز شروع کنید.\n" +
                "۷) به SOKNA برگردید و «بررسی دوباره» را بزنید. خود SOKNA سرویس Apache را نصب، حذف یا Restart نمی‌کند.",

            "mariadb" =>
                "۱) فایل MSI را اجرا کنید. اگر Windows SmartScreen یا UAC ظاهر شد، نام فایل و منبع را بررسی کرده و سپس ادامه دهید.\n" +
                "۲) MariaDB Server x64 را نصب کنید و اجازه دهید به‌عنوان Windows Service ثبت شود.\n" +
                "۳) برای نصب عادی، پورت پیش‌فرض 3306 مناسب است مگر این‌که روی سیستم شما سرویس دیگری از همین پورت استفاده کند.\n" +
                "۴) در مرحله تنظیم حساب مدیریتی، رمز را در محل امن نگه دارید؛ در نصب Local Web برای ساخت دیتابیس به اطلاعات مدیریتی MariaDB نیاز خواهید داشت.\n" +
                "۵) بعد از پایان نصب، از Services ویندوز مطمئن شوید سرویس MariaDB در حال اجرا است.\n" +
                "۶) به SOKNA برگردید و «بررسی دوباره» را بزنید.",

            "vc_runtime" =>
                "۱) فایل VC_redist.x64.exe را اجرا کنید.\n" +
                "۲) اگر گزینه Install نمایش داده شد آن را انتخاب کنید؛ اگر نسخه قبلی موجود است ممکن است Repair نمایش داده شود.\n" +
                "۳) در صورت درخواست ویندوز برای Restart، راه‌اندازی مجدد را انجام دهید.\n" +
                "۴) دوباره SOKNA Windows Services را باز کنید و «بررسی دوباره» را بزنید. تا زمانی که این مورد سازگار نشود، نصب سرویس‌های سکنا شروع نمی‌شود.",

            _ => item.ManualGuidanceFa
        };

        var verified = artifact is null
            ? ""
            : $"\n\nفایل تأییدشده این نسخه:\n{artifact.Filename}\nSHA-256: {artifact.Sha256}\n";

        var location = string.IsNullOrWhiteSpace(downloadedPath)
            ? ""
            : $"\nفایل دانلودشده اکنون در این مسیر است:\n{downloadedPath}\n";

        return header + steps + verified + location +
               "\nپس از انجام مراحل، در پنجره اصلی دکمه «بررسی دوباره» را بزنید. اگر وضعیت هنوز آماده نبود، از تب «وضعیت و عیب‌یابی» لاگ‌ها یا بسته عیب‌یابی را باز کنید.";
    }

    private void OpenOfficialPage()
    {
        var item = SelectedItem();
        if (item is null)
        {
            ShowError("ابتدا یک ردیف از پیش‌نیازها را انتخاب کنید.");
            return;
        }
        OpenUrl(OfficialPage(item.Id));
    }

    private static string OfficialPage(string id) => id switch
    {
        "php" => "https://www.php.net/downloads.php",
        "apache" => "https://www.apachelounge.com/download/",
        "mariadb" => "https://mariadb.org/download/",
        "vc_runtime" => "https://learn.microsoft.com/en-us/cpp/windows/latest-supported-vc-redist",
        _ => "https://github.com/mobaraki20/SoknaCafeV3"
    };

    private async Task BuildSupportBundleAsync()
    {
        var script = Path.Combine(AppContext.BaseDirectory, "collect-support.ps1");
        if (!File.Exists(script))
        {
            ShowError("ابزار ساخت بسته عیب‌یابی در این نسخه پیدا نشد.");
            return;
        }

        var supportRoot = Path.Combine(DataRoot(), "Support");
        Directory.CreateDirectory(supportRoot);
        var confirm = MessageBox.Show(
            this,
            "یک فایل ZIP برای عیب‌یابی ساخته می‌شود. این بسته شامل وضعیت سرویس‌ها، گزارش نصب، رخدادهای مرتبط Service Control Manager و لاگ‌های SOKNA است. فایل‌های secret و token عمداً جمع‌آوری نمی‌شوند.\n\nادامه می‌دهید؟",
            "ساخت بسته عیب‌یابی",
            MessageBoxButtons.YesNo,
            MessageBoxIcon.Information,
            MessageBoxDefaultButton.Button1,
            MessageBoxOptions.RtlReading | MessageBoxOptions.RightAlign);
        if (confirm != DialogResult.Yes) return;

        SetBusy(true, "در حال ساخت بسته عیب‌یابی…");
        try
        {
            var ps = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.System), "WindowsPowerShell", "v1.0", "powershell.exe");
            var psi = new ProcessStartInfo(ps)
            {
                UseShellExecute = false,
                CreateNoWindow = true,
                RedirectStandardOutput = true,
                RedirectStandardError = true,
                WorkingDirectory = AppContext.BaseDirectory
            };
            foreach (var a in new[] { "-NoProfile", "-NonInteractive", "-ExecutionPolicy", "Bypass", "-File", script, "-DataRoot", DataRoot(), "-OutputRoot", supportRoot })
                psi.ArgumentList.Add(a);

            using var p = Process.Start(psi) ?? throw new InvalidOperationException("PowerShell برای ساخت بسته عیب‌یابی اجرا نشد.");
            var stdoutTask = p.StandardOutput.ReadToEndAsync();
            var stderrTask = p.StandardError.ReadToEndAsync();
            await p.WaitForExitAsync();
            var stdout = await stdoutTask;
            var stderr = await stderrTask;
            AppendUiLog($"SUPPORT BUNDLE exit={p.ExitCode} stdout={Safe(stdout)} stderr={Safe(stderr)}");
            if (p.ExitCode != 0)
                throw new InvalidOperationException("ساخت بسته عیب‌یابی کامل نشد. " + (string.IsNullOrWhiteSpace(stderr) ? $"کد خطا: {p.ExitCode}" : FriendlyError(stderr)));

            var zip = new DirectoryInfo(supportRoot).GetFiles("SOKNA-Support-*.zip")
                .OrderByDescending(x => x.LastWriteTimeUtc)
                .FirstOrDefault();
            if (zip is null) throw new InvalidOperationException("فایل ZIP عیب‌یابی ساخته نشد.");

            _status.Text = "بسته عیب‌یابی ساخته شد: " + zip.FullName;
            OpenExplorer(zip.FullName);
        }
        catch (Win32Exception e) when (e.NativeErrorCode == 1223)
        {
            _status.Text = "ساخت بسته عیب‌یابی توسط کاربر لغو شد.";
        }
        catch (Exception e)
        {
            ShowError(FriendlyError(e));
        }
        finally
        {
            SetBusy(false);
        }
    }

    private void OpenLogsFolder()
    {
        var dir = LogsRoot();
        Directory.CreateDirectory(dir);
        OpenFolder(dir);
    }

    private void OpenSetupLog()
    {
        var path = Path.Combine(LogsRoot(), "windows-services-setup.log");
        if (File.Exists(path))
        {
            try
            {
                Process.Start(new ProcessStartInfo(path) { UseShellExecute = true });
            }
            catch
            {
                OpenExplorer(path);
            }
            return;
        }

        var message = "هنوز گزارش نصب سرویس‌ها در ProgramData ایجاد نشده است. اگر خود Installer قبل از اجرای این برنامه خطا داده، در مسیر %TEMP% فایل‌های «Setup Log*.txt» مربوط به Inno Setup را بررسی کنید.";
        MessageBox.Show(this, message, "گزارش نصب", MessageBoxButtons.OK, MessageBoxIcon.Information, MessageBoxDefaultButton.Button1, MessageBoxOptions.RtlReading | MessageBoxOptions.RightAlign);
        OpenFolder(Path.GetTempPath());
    }

    private static void OpenServicesConsole()
    {
        try { Process.Start(new ProcessStartInfo("services.msc") { UseShellExecute = true }); } catch { }
    }

    private static void OpenEventViewer()
    {
        try { Process.Start(new ProcessStartInfo("eventvwr.msc") { UseShellExecute = true }); } catch { }
    }

    private string ReadApplicationHealth()
    {
        var parts = new List<string>();

        try
        {
            var runtimeState = Path.Combine(DataRoot(), "runtime", "state", "runtime-state.json");
            if (File.Exists(runtimeState))
            {
                using var doc = JsonDocument.Parse(File.ReadAllText(runtimeState));
                var root = doc.RootElement;
                var status = JsonString(root, "status");
                var scheduler = JsonString(root, "scheduler_error");
                var printStatus = JsonString(root, "print_agent_status");
                parts.Add($"Runtime داخلی: {FaHealth(status)}" +
                          (string.IsNullOrWhiteSpace(scheduler) ? "" : $"؛ خطای scheduler: {scheduler}") +
                          (string.IsNullOrWhiteSpace(printStatus) ? "" : $"؛ مشاهده Print Agent: {FaHealth(printStatus)}"));
            }
            else
            {
                parts.Add("Runtime داخلی: هنوز فایل وضعیت تولید نشده است.");
            }
        }
        catch (Exception e)
        {
            parts.Add("Runtime داخلی: خواندن وضعیت ناموفق بود (" + Safe(e.Message) + ").");
        }

        try
        {
            var printHealth = Path.Combine(DataRoot(), "print-worker", "health.json");
            if (File.Exists(printHealth))
            {
                using var doc = JsonDocument.Parse(File.ReadAllText(printHealth));
                var root = doc.RootElement;
                var state = JsonStringInsensitive(root, "State");
                var transport = JsonStringInsensitive(root, "TransportState");
                var coordinator = JsonStringInsensitive(root, "CoordinatorState");
                var error = FirstNonEmpty(
                    JsonStringInsensitive(root, "LastError"),
                    JsonStringInsensitive(root, "LastTransportErrorCode"),
                    JsonStringInsensitive(root, "LastCoordinatorErrorCode"),
                    JsonStringInsensitive(root, "PrinterDiscoveryError"));
                parts.Add($"Print Agent داخلی: {FaHealth(state)}؛ ارتباط: {FaHealth(transport)}؛ هماهنگ‌کننده: {FaHealth(coordinator)}" +
                          (string.IsNullOrWhiteSpace(error) ? "" : $"؛ آخرین خطا: {error}"));
            }
            else
            {
                parts.Add("Print Agent داخلی: هنوز health.json تولید نشده است.");
            }
        }
        catch (Exception e)
        {
            parts.Add("Print Agent داخلی: خواندن health.json ناموفق بود (" + Safe(e.Message) + ").");
        }

        return "سلامت داخلی برنامه — " + string.Join(" | ", parts);
    }

    private static string ReadLastServiceEvent()
    {
        try
        {
            var ps = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.System), "WindowsPowerShell", "v1.0", "powershell.exe");
            var script = "$e=Get-WinEvent -FilterHashtable @{LogName='System';ProviderName='Service Control Manager';StartTime=(Get-Date).AddDays(-7)} -ErrorAction SilentlyContinue | Where-Object {$_.Message -match 'SoknaRuntime|SoknaPrintWorker|SOKNA Runtime|SOKNA Print Worker'} | Select-Object -First 1; if($e){('{0:yyyy-MM-dd HH:mm:ss} | Event {1} | {2}' -f $e.TimeCreated,$e.Id,($e.Message -replace '[\r\n]+',' '))}";
            var result = RunProcess(ps, "-NoProfile", "-NonInteractive", "-Command", script);
            var text = Safe(result.Output);
            return string.IsNullOrWhiteSpace(text)
                ? "آخرین رخداد Service Control Manager — رخداد مرتبطی در ۷ روز اخیر پیدا نشد."
                : "آخرین رخداد Service Control Manager — " + text;
        }
        catch (Exception e)
        {
            return "آخرین رخداد Service Control Manager — قابل خواندن نبود: " + Safe(e.Message);
        }
    }

    private static string JsonString(JsonElement root, string name)
    {
        if (!root.TryGetProperty(name, out var value)) return "";
        return value.ValueKind == JsonValueKind.String ? value.GetString() ?? "" : value.ToString();
    }

    private static string JsonStringInsensitive(JsonElement root, string name)
    {
        foreach (var p in root.EnumerateObject())
            if (p.Name.Equals(name, StringComparison.OrdinalIgnoreCase))
                return p.Value.ValueKind == JsonValueKind.String ? p.Value.GetString() ?? "" : p.Value.ToString();
        return "";
    }

    private static string FirstNonEmpty(params string[] values) => values.FirstOrDefault(x => !string.IsNullOrWhiteSpace(x)) ?? "";

    private static string FaHealth(string value) => (value ?? "").Trim().ToLowerInvariant() switch
    {
        "running" => "سالم / در حال اجرا",
        "healthy" => "سالم",
        "starting" => "در حال شروع",
        "degraded" => "دارای خطا / افت سلامت",
        "failed" => "خطادار",
        "stopped" => "متوقف",
        "unknown" => "نامشخص",
        "reconciliation_required" => "نیازمند تطبیق وضعیت",
        "disabled" => "غیرفعال",
        "" => "نامشخص",
        _ => value
    };

    private void BrowsePairing()
    {
        using var d = new OpenFileDialog
        {
            Filter = "فایل JSON (*.json)|*.json|همه فایل‌ها (*.*)|*.*",
            CheckFileExists = true,
            Title = "انتخاب فایل Pairing"
        };
        if (d.ShowDialog(this) == DialogResult.OK) _pairing.Text = d.FileName;
    }

    private PrerequisiteItem? SelectedItem()
    {
        if (_prereqs.SelectedItems.Count != 1) return null;
        var id = Convert.ToString(_prereqs.SelectedItems[0].Tag) ?? "";
        return _policy.Items.SingleOrDefault(x => x.Id == id);
    }

    private LockedArtifact? ArtifactFor(PrerequisiteItem item) =>
        _lock.Artifacts.SingleOrDefault(x => x.Dependency.Equals(item.ReleaseLockDependency, StringComparison.OrdinalIgnoreCase));

    private void SetBusy(bool busy, string? text = null, bool allowCancel = false)
    {
        foreach (var b in new[] { _install, _repair, _uninstall, _refresh, _download, _localPrereq, _guidance, _officialPage, _browsePairing, _refreshServices, _supportBundle })
            b.Enabled = !busy;

        _cancelDownload.Enabled = busy && allowCancel;
        if (text is not null) _status.Text = text;
        if (!busy && _progress.Value == 100) _progress.Value = 0;
    }

    private void ShowError(string message)
    {
        var safe = Safe(message);
        _status.Text = safe;
        MessageBox.Show(
            this,
            safe,
            "SOKNA Windows Services",
            MessageBoxButtons.OK,
            MessageBoxIcon.Warning,
            MessageBoxDefaultButton.Button1,
            MessageBoxOptions.RtlReading | MessageBoxOptions.RightAlign);
    }

    private static string FriendlyDownloadError(Exception e, LockedArtifact artifact)
    {
        var text = e.ToString();
        if (e is HttpRequestException hre && hre.StatusCode == HttpStatusCode.Forbidden || text.Contains("403", StringComparison.OrdinalIgnoreCase))
            return "سرور ارائه‌دهنده دانلود مستقیم را با خطای 403 رد کرد. هیچ فایلی نصب یا اجرا نشده است. «صفحه رسمی» یا «راهنمای گام‌به‌گام» را باز کنید؛ SOKNA در نسخه جدید مسیرهای جایگزین تأییدشده را نیز امتحان می‌کند.";
        if (text.Contains("SSL connection could not be established", StringComparison.OrdinalIgnoreCase) ||
            text.Contains("authentication failed", StringComparison.OrdinalIgnoreCase) ||
            text.Contains("certificate", StringComparison.OrdinalIgnoreCase))
            return "ارتباط امن SSL/TLS با سرور دانلود برقرار نشد. SOKNA اعتبار گواهی را نادیده نمی‌گیرد. اتصال اینترنت، تاریخ و ساعت ویندوز و آنتی‌ویروس/Proxy را بررسی کنید؛ سپس دوباره تلاش کنید یا از «صفحه رسمی» فایل را دستی دریافت کنید.";
        if (e is TaskCanceledException)
            return "زمان دانلود به پایان رسید. اتصال اینترنت را بررسی کنید و دوباره تلاش کنید؛ فایل ناقص برای ادامه دانلود نگه داشته می‌شود.";
        return $"دریافت «{artifact.Filename}» کامل نشد: {FriendlyError(e)}";
    }

    private static string FriendlyError(Exception e) => FriendlyError(e.Message);

    private static string FriendlyError(string message)
    {
        var value = Safe(message);
        return value
            .Replace("Response status code does not indicate success:", "سرور دانلود پاسخ موفق نداد:")
            .Replace("The SSL connection could not be established, see inner exception.", "ارتباط امن SSL/TLS با سرور برقرار نشد.")
            .Replace("See inner exception.", "جزئیات در گزارش خطا ثبت شده است.");
    }

    private void AppendUiLog(string text)
    {
        try
        {
            var dir = LogsRoot();
            Directory.CreateDirectory(dir);
            File.AppendAllText(Path.Combine(dir, "setup-ui.log"), $"{DateTimeOffset.Now:yyyy-MM-dd HH:mm:ss zzz} {Safe(text)}{Environment.NewLine}", Encoding.UTF8);
        }
        catch { }
    }

    private string DataRoot()
    {
        try
        {
            return string.IsNullOrWhiteSpace(_dataRoot.Text)
                ? Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData), "SOKNA")
                : Path.GetFullPath(_dataRoot.Text.Trim());
        }
        catch
        {
            return Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData), "SOKNA");
        }
    }

    private string LogsRoot() => Path.Combine(DataRoot(), "Logs");
    private static string CacheRoot() => Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData), "SOKNA", "PrerequisiteCache");

    private static string FindCommand(PrerequisiteItem item)
    {
        var path = Environment.GetEnvironmentVariable("PATH") ?? "";
        foreach (var d in path.Split(Path.PathSeparator, StringSplitOptions.RemoveEmptyEntries))
        {
            foreach (var n in item.Detection.Commands)
            {
                try
                {
                    var p = Path.Combine(d.Trim().Trim('"'), n);
                    if (File.Exists(p)) return Path.GetFullPath(p);
                }
                catch { }
            }
        }

        foreach (var candidate in CommonCandidates(item.Id))
        {
            try
            {
                if (File.Exists(candidate)) return Path.GetFullPath(candidate);
            }
            catch { }
        }

        if (item.Id == "mariadb")
        {
            try
            {
                var programFiles = Environment.GetFolderPath(Environment.SpecialFolder.ProgramFiles);
                foreach (var dir in Directory.GetDirectories(programFiles, "MariaDB *").OrderByDescending(x => x))
                {
                    foreach (var name in item.Detection.Commands)
                    {
                        var p = Path.Combine(dir, "bin", name);
                        if (File.Exists(p)) return Path.GetFullPath(p);
                    }
                }
            }
            catch { }
        }

        return "";
    }

    private static IEnumerable<string> CommonCandidates(string id) => id switch
    {
        "php" => new[]
        {
            @"C:\PHP82\php.exe",
            @"C:\PHP\php.exe",
            @"C:\SOKNA\Infrastructure\PHP\php.exe"
        },
        "apache" => new[]
        {
            @"C:\Apache24\bin\httpd.exe",
            @"C:\Program Files\Apache24\bin\httpd.exe"
        },
        _ => []
    };

    private static string RunCapture(string exe, string args)
    {
        var psi = new ProcessStartInfo(exe)
        {
            UseShellExecute = false,
            CreateNoWindow = true,
            RedirectStandardOutput = true,
            RedirectStandardError = true
        };
        foreach (var a in SplitArgs(args)) psi.ArgumentList.Add(a);

        using var p = Process.Start(psi) ?? throw new InvalidOperationException("فرآیند بررسی اجرا نشد.");
        var o = p.StandardOutput.ReadToEndAsync();
        var e = p.StandardError.ReadToEndAsync();
        if (!p.WaitForExit(15000))
        {
            try { p.Kill(true); } catch { }
            throw new InvalidOperationException("زمان بررسی پیش‌نیاز تمام شد.");
        }

        Task.WaitAll(o, e);
        return o.Result + "\n" + e.Result;
    }

    private static (int ExitCode, string Output) RunProcess(string exe, params string[] args)
    {
        var psi = new ProcessStartInfo(exe)
        {
            UseShellExecute = false,
            CreateNoWindow = true,
            RedirectStandardOutput = true,
            RedirectStandardError = true
        };
        foreach (var a in args) psi.ArgumentList.Add(a);
        using var p = Process.Start(psi) ?? throw new InvalidOperationException("فرآیند بررسی سرویس اجرا نشد.");
        var o = p.StandardOutput.ReadToEndAsync();
        var e = p.StandardError.ReadToEndAsync();
        if (!p.WaitForExit(10000))
        {
            try { p.Kill(true); } catch { }
            throw new InvalidOperationException("بررسی وضعیت سرویس بیش از حد طول کشید.");
        }
        Task.WaitAll(o, e);
        return (p.ExitCode, o.Result + "\n" + e.Result);
    }

    private static string MatchValue(string text, string pattern)
    {
        var m = System.Text.RegularExpressions.Regex.Match(text ?? "", pattern, System.Text.RegularExpressions.RegexOptions.IgnoreCase);
        return m.Success ? m.Groups[1].Value.Trim() : "";
    }

    private static string ServiceStateFa(string state) => state.ToUpperInvariant() switch
    {
        "RUNNING" => "در حال اجرا",
        "STOPPED" => "متوقف",
        "START_PENDING" => "در حال شروع",
        "STOP_PENDING" => "در حال توقف",
        "PAUSED" => "مکث",
        "NOT_INSTALLED" => "—",
        "" => "نامشخص",
        _ => state
    };

    private static string StartTypeFa(string type) => type.ToUpperInvariant() switch
    {
        "AUTO_START" => "خودکار",
        "DEMAND_START" => "دستی",
        "DISABLED" => "غیرفعال",
        "" => "—",
        _ => type
    };

    private static IEnumerable<string> SplitArgs(string value) =>
        string.IsNullOrWhiteSpace(value) ? [] : value.Split(' ', StringSplitOptions.RemoveEmptyEntries);

    private static string ExtractVersion(string text)
    {
        var m = System.Text.RegularExpressions.Regex.Match(text ?? "", @"(?<!\d)(\d+\.\d+(?:\.\d+){0,2})");
        return m.Success ? m.Groups[1].Value : "";
    }

    private static bool VersionAtLeast(string found, string minimum)
    {
        if (!Version.TryParse(NormalizeVersion(found), out var f) || !Version.TryParse(NormalizeVersion(minimum), out var m)) return false;
        return f >= m;
    }

    private static string NormalizeVersion(string v)
    {
        var p = (v ?? "").Split('.', StringSplitOptions.RemoveEmptyEntries).Take(4).ToList();
        while (p.Count < 2) p.Add("0");
        return string.Join('.', p);
    }

    private static bool IsSha(string value) => (value ?? "").Length == 64 && value.All(Uri.IsHexDigit);

    private static void ValidatePath(string value, string label)
    {
        if (string.IsNullOrWhiteSpace(value) || !Path.IsPathFullyQualified(value.Trim()))
            throw new InvalidOperationException(label + " باید مسیر کامل ویندوز باشد.");
        _ = Path.GetFullPath(value.Trim());
    }

    private static T LoadJson<T>(string path, string label)
    {
        if (!File.Exists(path)) throw new InvalidOperationException(label + " در بسته وجود ندارد.");
        return JsonSerializer.Deserialize<T>(File.ReadAllText(path), StrictJson) ?? throw new InvalidOperationException(label + " معتبر نیست.");
    }

    private static string Safe(string message)
    {
        var v = (message ?? "").Replace('\r', ' ').Replace('\n', ' ').Trim();
        return v.Length > 1000 ? v[..1000] : v;
    }

    private static void OpenExplorer(string path)
    {
        try { Process.Start(new ProcessStartInfo("explorer.exe", $"/select,\"{path}\"") { UseShellExecute = true }); } catch { }
    }

    private static void OpenFolder(string path)
    {
        try { Process.Start(new ProcessStartInfo("explorer.exe", $"\"{path}\"") { UseShellExecute = true }); } catch { }
    }

    private static void OpenUrl(string url)
    {
        try { Process.Start(new ProcessStartInfo(url) { UseShellExecute = true }); } catch { }
    }
}
