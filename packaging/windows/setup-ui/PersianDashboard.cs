using Microsoft.Win32;
using System.ComponentModel;
using System.Diagnostics;
using System.Drawing.Text;
using System.Text.Json;
using System.Text.RegularExpressions;

namespace Sokna.SetupUi;

internal enum WsInstallKind { NotInstalled, Partial, Current, Upgrade, Newer }

internal sealed record WsState(
    WsInstallKind InstallKind,
    string InstalledVersion,
    bool RuntimeInstalled,
    bool RuntimeRunning,
    bool PrintInstalled,
    bool PrintRunning,
    bool Paired,
    string LocalBaseUrl,
    bool PrerequisiteReady,
    string PrerequisiteVersion,
    string InstallRoot,
    string DataRoot);

internal sealed class PersianDashboardForm : Form
{
    private const string RuntimeService = "SoknaRuntime";
    private const string PrintService = "SoknaPrintWorker";

    private static readonly Color Canvas = Color.FromArgb(246, 244, 240);
    private static readonly Color Surface = Color.White;
    private static readonly Color Brand = Color.FromArgb(20, 91, 84);
    private static readonly Color BrandDark = Color.FromArgb(11, 63, 59);
    private static readonly Color BrandSoft = Color.FromArgb(234, 244, 242);
    private static readonly Color Text = Color.FromArgb(36, 44, 43);
    private static readonly Color Muted = Color.FromArgb(94, 104, 103);
    private static readonly Color Border = Color.FromArgb(216, 223, 221);
    private static readonly Color Good = Color.FromArgb(29, 118, 80);
    private static readonly Color Warn = Color.FromArgb(168, 105, 17);
    private static readonly Color Bad = Color.FromArgb(165, 54, 54);

    private readonly PrivateFontCollection _privateFonts = new();
    private FontFamily? _regularFamily;
    private readonly string _currentVersion;
    private string _installRoot;
    private string _dataRoot;
    private WsState? _state;

    private readonly Label _installValue = StatusValue();
    private readonly Label _serviceValue = StatusValue();
    private readonly Label _connectionValue = StatusValue();
    private readonly Label _title = new() { AutoSize = true, TextAlign = ContentAlignment.MiddleRight };
    private readonly Label _description = new() { AutoSize = true, TextAlign = ContentAlignment.TopRight, ForeColor = Muted };
    private readonly Label _runtimeValue = DetailValue();
    private readonly Label _printValue = DetailValue();
    private readonly Label _versionValue = DetailValue();
    private readonly Label _connectionDetail = DetailValue();
    private readonly Label _prerequisite = new() { AutoSize = true, TextAlign = ContentAlignment.MiddleRight };

    private readonly Button _primary = new() { AutoSize = true };
    private readonly Button _repair = new() { Text = "تعمیر نصب", AutoSize = true };
    private readonly Button _remove = new() { Text = "حذف سرویس‌ها", AutoSize = true };
    private readonly Button _refresh = new() { Text = "تازه‌سازی", AutoSize = true };
    private readonly Button _support = new() { Text = "بسته عیب‌یابی", AutoSize = true };
    private readonly Button _logs = new() { Text = "لاگ‌ها", AutoSize = true };
    private readonly Button _advanced = new() { Text = "جزئیات", AutoSize = true };

    private readonly TextBox _localUrl = new()
    {
        Dock = DockStyle.Fill,
        RightToLeft = RightToLeft.No,
        TextAlign = HorizontalAlignment.Left,
        Text = "http://127.0.0.1:18080/",
        MaxLength = 180
    };
    private readonly TextBox _pairCode = new()
    {
        Dock = DockStyle.Fill,
        RightToLeft = RightToLeft.No,
        TextAlign = HorizontalAlignment.Left,
        MaxLength = 180
    };
    private readonly CheckBox _startRuntime = new() { AutoSize = true, Checked = true, Text = "بعد از اتصال، Runtime شروع شود" };
    private readonly Button _pair = new() { AutoSize = true, Text = "اتصال به Local Web" };
    private readonly Label _pairHelp = new() { AutoSize = true, TextAlign = ContentAlignment.TopRight, ForeColor = Muted };

    private readonly Label _footerStatus = new() { Dock = DockStyle.Fill, TextAlign = ContentAlignment.MiddleRight, ForeColor = Muted };
    private readonly ProgressBar _progress = new() { Dock = DockStyle.Fill, Style = ProgressBarStyle.Marquee, Visible = false };

    internal PersianDashboardForm()
    {
        _currentVersion = DisplayVersion();
        _installRoot = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.ProgramFiles), "SOKNA Windows Services");
        _dataRoot = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData), "SOKNA");
        ResolveExistingRoots();
        LoadPersianFont();

        Text = $"سکنا | سرویس‌های ویندوز — نسخه {_currentVersion}";
        StartPosition = FormStartPosition.CenterScreen;
        BackColor = Canvas;
        ForeColor = Text;
        RightToLeft = RightToLeft.Yes;
        RightToLeftLayout = false;
        AutoScaleMode = AutoScaleMode.Dpi;
        AutoScroll = false;
        MinimumSize = new Size(980, 620);
        ClientSize = new Size(1120, 680);
        Font = FaFont(10.1f);
        Icon = LoadIcon();

        Controls.Add(BuildRoot());
        StyleButtons();
        WireEvents();
        ApplyFonts(this);

        Shown += async (_, _) =>
        {
            FitToWorkingArea();
            ApplyWindowIcon();
            await RefreshAsync();
        };
    }

    private Control BuildRoot()
    {
        var root = new TableLayoutPanel
        {
            Dock = DockStyle.Fill,
            Padding = new Padding(20, 17, 20, 16),
            ColumnCount = 1,
            RowCount = 4,
            BackColor = Canvas,
            RightToLeft = RightToLeft.Yes,
            AutoScroll = false
        };
        root.RowStyles.Add(new RowStyle(SizeType.Absolute, 92));
        root.RowStyles.Add(new RowStyle(SizeType.Absolute, 112));
        root.RowStyles.Add(new RowStyle(SizeType.Percent, 100));
        root.RowStyles.Add(new RowStyle(SizeType.Absolute, 58));
        root.Controls.Add(BuildHeader(), 0, 0);
        root.Controls.Add(BuildSummaryRow(), 0, 1);
        root.Controls.Add(BuildContent(), 0, 2);
        root.Controls.Add(BuildFooter(), 0, 3);
        return root;
    }

    private Control BuildHeader()
    {
        var panel = new TableLayoutPanel
        {
            Dock = DockStyle.Fill,
            ColumnCount = 2,
            RowCount = 1,
            BackColor = Brand,
            Padding = new Padding(20, 13, 20, 12),
            Margin = new Padding(0, 0, 0, 10),
            RightToLeft = RightToLeft.Yes
        };
        panel.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 100));
        panel.ColumnStyles.Add(new ColumnStyle(SizeType.AutoSize));

        var copy = new TableLayoutPanel { Dock = DockStyle.Fill, ColumnCount = 1, RowCount = 2, BackColor = Brand, Margin = new Padding(0) };
        copy.RowStyles.Add(new RowStyle(SizeType.Percent, 60));
        copy.RowStyles.Add(new RowStyle(SizeType.Percent, 40));
        copy.Controls.Add(new Label
        {
            AutoSize = true,
            Text = "مدیریت سرویس‌های ویندوز سکنا",
            ForeColor = Color.White,
            Font = FaFont(17f, FontStyle.Bold),
            Anchor = AnchorStyles.Right
        }, 0, 0);
        copy.Controls.Add(new Label
        {
            AutoSize = true,
            Text = "نصب، به‌روزرسانی، اتصال و عیب‌یابی Runtime و Print Agent",
            ForeColor = Color.FromArgb(221, 238, 235),
            Font = FaFont(9.5f),
            Anchor = AnchorStyles.Right
        }, 0, 1);

        var version = new Label
        {
            AutoSize = true,
            Text = _currentVersion,
            RightToLeft = RightToLeft.No,
            TextAlign = ContentAlignment.MiddleCenter,
            BackColor = Color.White,
            ForeColor = BrandDark,
            Font = FaFont(10f, FontStyle.Bold),
            Padding = new Padding(12, 7, 12, 7),
            Anchor = AnchorStyles.None,
            Margin = new Padding(14, 0, 0, 0)
        };
        panel.Controls.Add(copy, 0, 0);
        panel.Controls.Add(version, 1, 0);
        return panel;
    }

    private Control BuildSummaryRow()
    {
        var row = new TableLayoutPanel { Dock = DockStyle.Fill, ColumnCount = 3, RowCount = 1, Margin = new Padding(0, 0, 0, 10), RightToLeft = RightToLeft.Yes };
        for (var i = 0; i < 3; i++) row.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 33.333f));
        row.Controls.Add(BuildSummaryCard("نصب و نسخه", _installValue), 0, 0);
        row.Controls.Add(BuildSummaryCard("وضعیت سرویس‌ها", _serviceValue), 1, 0);
        row.Controls.Add(BuildSummaryCard("اتصال Local Web", _connectionValue), 2, 0);
        return row;
    }

    private Control BuildSummaryCard(string caption, Label value)
    {
        var card = Card();
        card.Margin = new Padding(5);
        card.Padding = new Padding(15, 11, 15, 11);
        var grid = new TableLayoutPanel { Dock = DockStyle.Fill, ColumnCount = 1, RowCount = 2, BackColor = Surface, Margin = new Padding(0) };
        grid.RowStyles.Add(new RowStyle(SizeType.AutoSize));
        grid.RowStyles.Add(new RowStyle(SizeType.Percent, 100));
        grid.Controls.Add(new Label { AutoSize = true, Text = caption, ForeColor = Muted, Font = FaFont(9.3f), Anchor = AnchorStyles.Right }, 0, 0);
        value.Font = FaFont(12f, FontStyle.Bold);
        value.Anchor = AnchorStyles.Right;
        grid.Controls.Add(value, 0, 1);
        card.Controls.Add(grid);
        return card;
    }

    private Control BuildContent()
    {
        var split = new TableLayoutPanel { Dock = DockStyle.Fill, ColumnCount = 2, RowCount = 1, Margin = new Padding(0), RightToLeft = RightToLeft.Yes };
        split.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 58));
        split.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 42));
        split.Controls.Add(BuildMainCard(), 0, 0);
        split.Controls.Add(BuildPairCard(), 1, 0);
        return split;
    }

    private Control BuildMainCard()
    {
        var card = Card();
        card.Margin = new Padding(5, 0, 5, 0);
        card.Padding = new Padding(18, 15, 18, 14);
        var grid = new TableLayoutPanel { Dock = DockStyle.Fill, ColumnCount = 1, RowCount = 5, BackColor = Surface };
        grid.RowStyles.Add(new RowStyle(SizeType.AutoSize));
        grid.RowStyles.Add(new RowStyle(SizeType.AutoSize));
        grid.RowStyles.Add(new RowStyle(SizeType.Percent, 100));
        grid.RowStyles.Add(new RowStyle(SizeType.AutoSize));
        grid.RowStyles.Add(new RowStyle(SizeType.AutoSize));

        _title.Font = FaFont(14.8f, FontStyle.Bold);
        _title.ForeColor = BrandDark;
        _description.Font = FaFont(9.8f);
        _description.MaximumSize = new Size(610, 0);
        _description.Margin = new Padding(0, 7, 0, 7);
        _prerequisite.Font = FaFont(9.3f, FontStyle.Bold);
        _prerequisite.Margin = new Padding(0, 4, 0, 7);

        grid.Controls.Add(_title, 0, 0);
        grid.Controls.Add(_description, 0, 1);
        grid.Controls.Add(BuildDetails(), 0, 2);
        grid.Controls.Add(_prerequisite, 0, 3);
        grid.Controls.Add(ActionFlow(_primary, _repair, _remove), 0, 4);
        card.Controls.Add(grid);
        return card;
    }

    private Control BuildDetails()
    {
        var grid = new TableLayoutPanel { Dock = DockStyle.Fill, ColumnCount = 2, RowCount = 4, BackColor = Surface, Padding = new Padding(0, 4, 0, 4) };
        grid.ColumnStyles.Add(new ColumnStyle(SizeType.Absolute, 125));
        grid.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 100));
        for (var i = 0; i < 4; i++) grid.RowStyles.Add(new RowStyle(SizeType.Percent, 25));
        AddDetail(grid, 0, "Runtime", _runtimeValue);
        AddDetail(grid, 1, "Print Agent", _printValue);
        AddDetail(grid, 2, "نسخه نصب‌شده", _versionValue);
        AddDetail(grid, 3, "اتصال", _connectionDetail);
        return grid;
    }

    private void AddDetail(TableLayoutPanel grid, int row, string caption, Label value)
    {
        grid.Controls.Add(new Label { AutoSize = true, Text = caption, ForeColor = Muted, Anchor = AnchorStyles.Right, Margin = new Padding(0, 5, 0, 0) }, 0, row);
        value.Anchor = AnchorStyles.Right;
        grid.Controls.Add(value, 1, row);
    }

    private Control BuildPairCard()
    {
        var card = Card();
        card.Margin = new Padding(5, 0, 5, 0);
        card.Padding = new Padding(18, 15, 18, 14);
        var grid = new TableLayoutPanel { Dock = DockStyle.Fill, ColumnCount = 1, RowCount = 7, BackColor = Surface };
        grid.RowStyles.Add(new RowStyle(SizeType.AutoSize));
        grid.RowStyles.Add(new RowStyle(SizeType.AutoSize));
        grid.RowStyles.Add(new RowStyle(SizeType.AutoSize));
        grid.RowStyles.Add(new RowStyle(SizeType.AutoSize));
        grid.RowStyles.Add(new RowStyle(SizeType.AutoSize));
        grid.RowStyles.Add(new RowStyle(SizeType.Percent, 100));
        grid.RowStyles.Add(new RowStyle(SizeType.AutoSize));

        grid.Controls.Add(new Label { AutoSize = true, Text = "اتصال به Local Web", ForeColor = BrandDark, Font = FaFont(13.8f, FontStyle.Bold) }, 0, 0);
        _pairHelp.Text = "از Local Web یک کد کوتاه‌عمر بسازید. اتصال مستقل است: فایل‌های سرویس جایگزین نمی‌شوند و Windows Service دوباره ساخته نمی‌شود؛ فقط تنظیمات اتصال اعمال و سرویس‌های لازم restart می‌شوند.";
        _pairHelp.Font = FaFont(9.5f);
        _pairHelp.MaximumSize = new Size(430, 0);
        _pairHelp.Margin = new Padding(0, 6, 0, 8);
        grid.Controls.Add(_pairHelp, 0, 1);
        grid.Controls.Add(Field("آدرس Local Web", _localUrl), 0, 2);
        grid.Controls.Add(Field("کد اتصال", _pairCode), 0, 3);
        grid.Controls.Add(_startRuntime, 0, 4);
        grid.Controls.Add(new Panel { Dock = DockStyle.Fill, BackColor = Surface }, 0, 5);
        grid.Controls.Add(ActionFlow(_pair), 0, 6);
        card.Controls.Add(grid);
        return card;
    }

    private Control BuildFooter()
    {
        var footer = new TableLayoutPanel { Dock = DockStyle.Fill, ColumnCount = 3, RowCount = 1, BackColor = Canvas, Padding = new Padding(4, 9, 4, 0), RightToLeft = RightToLeft.Yes };
        footer.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 100));
        footer.ColumnStyles.Add(new ColumnStyle(SizeType.Absolute, 145));
        footer.ColumnStyles.Add(new ColumnStyle(SizeType.AutoSize));
        footer.Controls.Add(_footerStatus, 0, 0);
        footer.Controls.Add(_progress, 1, 0);
        footer.Controls.Add(ActionFlow(_refresh, _support, _logs, _advanced), 2, 0);
        return footer;
    }

    private Control Field(string caption, Control input)
    {
        var box = new TableLayoutPanel { Dock = DockStyle.Top, AutoSize = true, ColumnCount = 1, RowCount = 2, BackColor = Surface, Margin = new Padding(0, 3, 0, 4) };
        box.Controls.Add(new Label { AutoSize = true, Text = caption, ForeColor = Text, Font = FaFont(9.2f, FontStyle.Bold), Anchor = AnchorStyles.Right }, 0, 0);
        input.Margin = new Padding(0, 4, 0, 0);
        input.MinimumSize = new Size(0, 32);
        box.Controls.Add(input, 0, 1);
        return box;
    }

    private static Panel Card() => new() { Dock = DockStyle.Fill, BackColor = Surface, BorderStyle = BorderStyle.FixedSingle };

    private FlowLayoutPanel ActionFlow(params Control[] controls)
    {
        var flow = new FlowLayoutPanel { Dock = DockStyle.Fill, AutoSize = true, FlowDirection = FlowDirection.RightToLeft, WrapContents = false, RightToLeft = RightToLeft.Yes, BackColor = Color.Transparent, Margin = new Padding(0), Padding = new Padding(0, 3, 0, 0) };
        foreach (var control in controls) { control.Margin = new Padding(7, 0, 0, 0); flow.Controls.Add(control); }
        return flow;
    }

    private void StyleButtons()
    {
        Primary(_primary);
        Primary(_pair);
        Secondary(_repair);
        Danger(_remove);
        Secondary(_refresh);
        Secondary(_support);
        Secondary(_logs);
        Secondary(_advanced);
        foreach (var button in Descendants<Button>(this))
        {
            button.MinimumSize = new Size(104, 36);
            button.Cursor = Cursors.Hand;
            button.Font = FaFont(9.3f, FontStyle.Bold);
        }
    }

    private static void Primary(Button b)
    {
        b.UseVisualStyleBackColor = false;
        b.FlatStyle = FlatStyle.Flat;
        b.FlatAppearance.BorderSize = 0;
        b.BackColor = Brand;
        b.ForeColor = Color.White;
        b.Padding = new Padding(12, 5, 12, 5);
    }

    private static void Secondary(Button b)
    {
        b.UseVisualStyleBackColor = false;
        b.FlatStyle = FlatStyle.Flat;
        b.FlatAppearance.BorderColor = Border;
        b.BackColor = Surface;
        b.ForeColor = BrandDark;
        b.Padding = new Padding(10, 5, 10, 5);
    }

    private static void Danger(Button b)
    {
        b.UseVisualStyleBackColor = false;
        b.FlatStyle = FlatStyle.Flat;
        b.FlatAppearance.BorderColor = Color.FromArgb(229, 194, 194);
        b.BackColor = Color.FromArgb(255, 246, 246);
        b.ForeColor = Bad;
        b.Padding = new Padding(10, 5, 10, 5);
    }

    private void WireEvents()
    {
        _refresh.Click += async (_, _) => await RefreshAsync();
        _primary.Click += async (_, _) => await RunPrimaryAsync();
        _repair.Click += async (_, _) => await RunLifecycleAsync("repair");
        _remove.Click += async (_, _) => await RunLifecycleAsync("uninstall");
        _pair.Click += async (_, _) => await PairAsync();
        _support.Click += async (_, _) => await BuildSupportBundleAsync();
        _logs.Click += (_, _) => OpenFolder(Path.Combine(_dataRoot, "Logs"));
        _advanced.Click += (_, _) => ShowDetails();
    }

    private async Task RefreshAsync()
    {
        SetBusy(true, "در حال بررسی وضعیت…");
        try
        {
            ResolveExistingRoots();
            _state = await Task.Run(ReadState);
            Render(_state);
            _footerStatus.Text = "وضعیت به‌روز است.";
        }
        catch (Exception e)
        {
            _footerStatus.Text = "بررسی وضعیت کامل نشد.";
            ShowError(e.Message);
        }
        finally { SetBusy(false); }
    }

    private WsState ReadState()
    {
        var runtime = ReadService(RuntimeService);
        var print = ReadService(PrintService);
        var installedVersion = ReadInstalledPackageVersion(runtime.Installed || print.Installed);
        var paired = false;
        var localBaseUrl = "";
        var statePath = Path.Combine(_dataRoot, "setup", "windows-services-state.json");
        if (File.Exists(statePath))
        {
            try
            {
                using var doc = JsonDocument.Parse(File.ReadAllText(statePath));
                paired = doc.RootElement.TryGetProperty("paired", out var p) && p.ValueKind == JsonValueKind.True;
                if (doc.RootElement.TryGetProperty("local_base_url", out var u) && u.ValueKind == JsonValueKind.String) localBaseUrl = u.GetString() ?? "";
            }
            catch { }
        }

        var prerequisite = ReadVcRuntime();
        var kind = ResolveInstallKind(runtime.Installed, print.Installed, installedVersion, _currentVersion);
        return new WsState(kind, installedVersion, runtime.Installed, runtime.Running, print.Installed, print.Running, paired, localBaseUrl, prerequisite.Ready, prerequisite.Version, _installRoot, _dataRoot);
    }

    private void Render(WsState s)
    {
        _installValue.Text = s.InstallKind switch
        {
            WsInstallKind.NotInstalled => "نصب نشده",
            WsInstallKind.Partial => "نیاز به تعمیر",
            WsInstallKind.Upgrade => $"{s.InstalledVersion}  ←  {_currentVersion}",
            WsInstallKind.Newer => $"نسخه {s.InstalledVersion}",
            _ => $"نسخه {s.InstalledVersion}"
        };
        _installValue.ForeColor = s.InstallKind switch { WsInstallKind.Current => Good, WsInstallKind.Upgrade => Warn, WsInstallKind.NotInstalled => Muted, _ => Bad };

        var healthy = s.PrintRunning && (s.RuntimeRunning || !s.Paired);
        _serviceValue.Text = !s.RuntimeInstalled && !s.PrintInstalled ? "—" : healthy ? (s.Paired ? "هر دو فعال" : "Print Agent فعال") : "نیاز به بررسی";
        _serviceValue.ForeColor = !s.RuntimeInstalled && !s.PrintInstalled ? Muted : healthy ? Good : Warn;

        _connectionValue.Text = !s.RuntimeInstalled || !s.PrintInstalled ? "بعد از نصب" : s.Paired ? "متصل" : "متصل نیست";
        _connectionValue.ForeColor = s.Paired ? Good : (s.RuntimeInstalled && s.PrintInstalled ? Warn : Muted);

        _runtimeValue.Text = s.RuntimeInstalled ? (s.RuntimeRunning ? "نصب شده • در حال اجرا" : "نصب شده • متوقف") : "نصب نشده";
        _printValue.Text = s.PrintInstalled ? (s.PrintRunning ? "نصب شده • در حال اجرا" : "نصب شده • متوقف") : "نصب نشده";
        _versionValue.Text = string.IsNullOrWhiteSpace(s.InstalledVersion) ? "قابل تشخیص نیست" : s.InstalledVersion;
        _versionValue.RightToLeft = RightToLeft.No;
        _connectionDetail.Text = s.Paired ? (string.IsNullOrWhiteSpace(s.LocalBaseUrl) ? "متصل" : "متصل") : "متصل نیست";

        _prerequisite.Text = s.PrerequisiteReady ? $"پیش‌نیاز ویندوز آماده است • Visual C++ {s.PrerequisiteVersion}" : "پیش‌نیاز Visual C++ آماده نیست؛ نصب یا به‌روزرسانی تا رفع آن متوقف است.";
        _prerequisite.ForeColor = s.PrerequisiteReady ? Good : Bad;

        _primary.Visible = true;
        _repair.Visible = s.RuntimeInstalled || s.PrintInstalled;
        _remove.Visible = s.RuntimeInstalled || s.PrintInstalled;
        var canPair = s.InstallKind == WsInstallKind.Current && s.RuntimeInstalled && s.PrintInstalled;
        _pair.Enabled = canPair;
        _pair.Text = s.Paired ? "نوسازی اتصال" : "اتصال به Local Web";
        _localUrl.Enabled = canPair;
        _pairCode.Enabled = canPair;
        _startRuntime.Enabled = canPair;
        if (s.Paired && !string.IsNullOrWhiteSpace(s.LocalBaseUrl)) _localUrl.Text = s.LocalBaseUrl;

        switch (s.InstallKind)
        {
            case WsInstallKind.NotInstalled:
                _title.Text = "سرویس‌های سکنا هنوز نصب نشده‌اند";
                _description.Text = "Runtime و Print Agent نصب می‌شوند. زیرساخت Local Web و داده‌های کسب‌وکار دست‌کاری نمی‌شوند. اتصال Local Web بعد از نصب، در مرحله‌ای مستقل انجام می‌شود.";
                _primary.Text = "نصب سرویس‌ها";
                _repair.Visible = false;
                break;
            case WsInstallKind.Upgrade:
                _title.Text = "به‌روزرسانی آماده است";
                _description.Text = $"نسخه نصب‌شده {s.InstalledVersion} است و این بسته نسخه {_currentVersion}. به‌روزرسانی فقط Windows Services را جایگزین می‌کند؛ تنظیمات اتصال موجود و داده‌ها حفظ می‌شوند.";
                _primary.Text = $"به‌روزرسانی به {_currentVersion}";
                break;
            case WsInstallKind.Current:
                _title.Text = "Windows Services به‌روز است";
                _description.Text = s.Paired
                    ? "نسخه نصب‌شده به‌روز و اتصال Local Web برقرار است. در صورت مشکل می‌توانید تعمیر نصب یا بسته عیب‌یابی را اجرا کنید."
                    : "نسخه نصب‌شده به‌روز است. مرحله بعد فقط اتصال به Local Web است؛ Pairing دیگر نصب یا جایگزینی فایل‌های سرویس را اجرا نمی‌کند.";
                _primary.Visible = false;
                break;
            case WsInstallKind.Newer:
                _title.Text = "نسخه جدیدتری روی سیستم نصب است";
                _description.Text = $"نسخه نصب‌شده {s.InstalledVersion} از این بسته ({_currentVersion}) جدیدتر است. Downgrade خودکار مسدود شده است.";
                _primary.Visible = false;
                _repair.Visible = false;
                break;
            default:
                _title.Text = "نصب ناقص یا نسخه نامشخص است";
                _description.Text = "یکی از سرویس‌ها یا اطلاعات نسخه کامل نیست. تعمیر نصب را اجرا کنید؛ اگر مشکل باقی ماند بسته عیب‌یابی بسازید.";
                _primary.Visible = false;
                break;
        }
    }

    private async Task RunPrimaryAsync()
    {
        if (_state is null) return;
        if (!_state.PrerequisiteReady) { ShowError("Microsoft Visual C++ x64 Runtime آماده نیست. ابتدا پیش‌نیاز را نصب کنید."); return; }
        if (_state.InstallKind is WsInstallKind.NotInstalled or WsInstallKind.Upgrade) await RunLifecycleAsync("install");
    }

    private async Task RunLifecycleAsync(string mode)
    {
        if (mode != "uninstall" && _state is not null && !_state.PrerequisiteReady) { ShowError("پیش‌نیاز Visual C++ آماده نیست."); return; }
        var message = mode switch
        {
            "repair" => "فایل‌های Runtime و Print Agent دوباره اعمال می‌شوند. داده‌ها و اتصال موجود حفظ می‌شوند. ادامه می‌دهید؟",
            "uninstall" => "دو سرویس Runtime و Print Agent حذف می‌شوند؛ داده‌ها و Local Web باقی می‌مانند. ادامه می‌دهید؟",
            _ => _state?.InstallKind == WsInstallKind.Upgrade ? $"نسخه {(_state?.InstalledVersion ?? "قبلی")} به {_currentVersion} به‌روزرسانی می‌شود. ادامه می‌دهید؟" : "سرویس‌های Windows Services نصب می‌شوند. ادامه می‌دهید؟"
        };
        if (MessageBox.Show(this, message, "تأیید عملیات", MessageBoxButtons.YesNo, mode == "uninstall" ? MessageBoxIcon.Warning : MessageBoxIcon.Information, mode == "uninstall" ? MessageBoxDefaultButton.Button2 : MessageBoxDefaultButton.Button1, MessageBoxOptions.RtlReading | MessageBoxOptions.RightAlign) != DialogResult.Yes) return;

        SetBusy(true, "در حال اجرای عملیات…");
        string? plan = null;
        try
        {
            plan = Path.Combine(Path.GetTempPath(), "sokna-services-plan-" + Guid.NewGuid().ToString("N") + ".json");
            var body = new Dictionary<string, object?>
            {
                ["schema_version"] = 2,
                ["mode"] = mode,
                ["shell_root"] = AppContext.BaseDirectory.TrimEnd(Path.DirectorySeparatorChar),
                ["install_root"] = _installRoot,
                ["data_root"] = _dataRoot,
                ["pairing_file"] = "",
                ["pairing_code"] = "",
                ["pairing_base_url"] = "",
                ["start_when_paired"] = false
            };
            await File.WriteAllTextAsync(plan, JsonSerializer.Serialize(body, new JsonSerializerOptions { WriteIndented = true }));
            var host = Path.Combine(AppContext.BaseDirectory, "SoknaSetupHost.exe");
            var exit = await RunElevatedAsync(host, new[] { "--plan-file", plan });
            if (exit != 0) throw new InvalidOperationException($"عملیات با کد {exit} متوقف شد. برای جزئیات لاگ نصب را بررسی کنید.");
            _footerStatus.Text = mode == "uninstall" ? "سرویس‌ها حذف شدند." : mode == "repair" ? "تعمیر نصب کامل شد." : "نصب یا به‌روزرسانی کامل شد.";
            await RefreshAsync();
        }
        catch (Win32Exception e) when (e.NativeErrorCode == 1223) { _footerStatus.Text = "درخواست Administrator لغو شد."; }
        catch (Exception e) { ShowError(e.Message); }
        finally { if (plan is not null) try { File.Delete(plan); } catch { } SetBusy(false); }
    }

    private async Task PairAsync()
    {
        var code = _pairCode.Text.Trim();
        var url = _localUrl.Text.Trim();
        if (!Regex.IsMatch(code, "^ws1_[a-f0-9]{24}_[a-f0-9]{48}$")) { ShowError("کد اتصال معتبر نیست. یک کد تازه از Local Web بسازید."); return; }
        if (!IsLoopbackOrigin(url)) { ShowError("آدرس Local Web باید یک origin محلی باشد؛ مانند http://127.0.0.1:18080/."); return; }

        SetBusy(true, "در حال اتصال به Local Web…");
        string? plan = null;
        try
        {
            plan = Path.Combine(Path.GetTempPath(), "sokna-pair-plan-" + Guid.NewGuid().ToString("N") + ".json");
            var body = new Dictionary<string, object?>
            {
                ["format"] = "sokna-windows-services-pair-plan-v1",
                ["schema_version"] = 1,
                ["install_root"] = _installRoot,
                ["data_root"] = _dataRoot,
                ["pairing_base_url"] = url,
                ["pairing_code"] = code,
                ["start_when_paired"] = _startRuntime.Checked
            };
            await File.WriteAllTextAsync(plan, JsonSerializer.Serialize(body, new JsonSerializerOptions { WriteIndented = true }));
            var powershell = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.System), "WindowsPowerShell", "v1.0", "powershell.exe");
            var script = Path.Combine(AppContext.BaseDirectory, "pair-windows-services.ps1");
            var exit = await RunElevatedAsync(powershell, new[] { "-NoProfile", "-NonInteractive", "-ExecutionPolicy", "Bypass", "-File", script, "-PlanFile", plan });
            if (exit != 0) throw new InvalidOperationException($"اتصال با کد {exit} متوقف شد. در صورت تکرار، بسته عیب‌یابی بسازید.");
            _pairCode.Clear();
            _footerStatus.Text = "اتصال Local Web انجام شد؛ سرویس‌ها دوباره نصب نشدند.";
            await RefreshAsync();
        }
        catch (Win32Exception e) when (e.NativeErrorCode == 1223) { _footerStatus.Text = "درخواست Administrator لغو شد."; }
        catch (Exception e) { ShowError(e.Message); }
        finally { if (plan is not null) try { File.Delete(plan); } catch { } SetBusy(false); }
    }

    private async Task BuildSupportBundleAsync()
    {
        SetBusy(true, "در حال ساخت بسته عیب‌یابی…");
        try
        {
            var powershell = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.System), "WindowsPowerShell", "v1.0", "powershell.exe");
            var script = Path.Combine(AppContext.BaseDirectory, "collect-support.ps1");
            var output = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.DesktopDirectory), "SOKNA-Support");
            Directory.CreateDirectory(output);
            var exit = await RunElevatedAsync(powershell, new[] { "-NoProfile", "-NonInteractive", "-ExecutionPolicy", "Bypass", "-File", script, "-DataRoot", _dataRoot, "-OutputRoot", output });
            if (exit != 0) throw new InvalidOperationException("ساخت بسته عیب‌یابی کامل نشد.");
            OpenFolder(output);
            _footerStatus.Text = "بسته عیب‌یابی روی Desktop ساخته شد.";
        }
        catch (Win32Exception e) when (e.NativeErrorCode == 1223) { _footerStatus.Text = "درخواست Administrator لغو شد."; }
        catch (Exception e) { ShowError(e.Message); }
        finally { SetBusy(false); }
    }

    private void ResolveExistingRoots()
    {
        var printImage = ReadServiceImage(PrintService);
        var printExe = ExtractExecutablePath(printImage);
        if (!string.IsNullOrWhiteSpace(printExe))
        {
            try
            {
                var serviceDir = Path.GetDirectoryName(printExe);
                var printRoot = serviceDir is null ? null : Directory.GetParent(serviceDir)?.FullName;
                var install = printRoot is null ? null : Directory.GetParent(printRoot)?.FullName;
                if (!string.IsNullOrWhiteSpace(install)) _installRoot = Path.GetFullPath(install);
            }
            catch { }
        }
        else
        {
            var runtimeImage = ReadServiceImage(RuntimeService);
            var runtimeExe = ExtractExecutablePath(runtimeImage);
            if (!string.IsNullOrWhiteSpace(runtimeExe))
            {
                try
                {
                    var runtimeDir = Path.GetDirectoryName(runtimeExe);
                    var install = runtimeDir is null ? null : Directory.GetParent(runtimeDir)?.FullName;
                    if (!string.IsNullOrWhiteSpace(install)) _installRoot = Path.GetFullPath(install);
                }
                catch { }
            }
        }

        var printData = ReadRegistryString(@"SOFTWARE\Sokna\Local\PrintWorker", "DataRoot");
        if (!string.IsNullOrWhiteSpace(printData))
        {
            try
            {
                var full = Path.GetFullPath(printData);
                var parent = Directory.GetParent(full)?.FullName;
                if (!string.IsNullOrWhiteSpace(parent)) _dataRoot = parent;
            }
            catch { }
        }
        else
        {
            var runtimeImage = ReadServiceImage(RuntimeService);
            var match = Regex.Match(runtimeImage ?? "", "--config\\s+(?:\"([^\"]+)\"|(\\S+))", RegexOptions.IgnoreCase);
            var config = match.Success ? (match.Groups[1].Success ? match.Groups[1].Value : match.Groups[2].Value) : "";
            if (!string.IsNullOrWhiteSpace(config))
            {
                try
                {
                    var runtimeDir = Directory.GetParent(Path.GetDirectoryName(Path.GetFullPath(config)) ?? "")?.FullName;
                    if (!string.IsNullOrWhiteSpace(runtimeDir)) _dataRoot = runtimeDir;
                }
                catch { }
            }
        }
    }

    private string ReadInstalledPackageVersion(bool anyServiceInstalled)
    {
        var statePath = Path.Combine(_dataRoot, "setup", "windows-services-state.json");
        if (File.Exists(statePath))
        {
            try
            {
                using var doc = JsonDocument.Parse(File.ReadAllText(statePath));
                if (doc.RootElement.TryGetProperty("package_version", out var p) && p.ValueKind == JsonValueKind.String && !string.IsNullOrWhiteSpace(p.GetString())) return NormalizeVersion(p.GetString()!);
            }
            catch { }
        }

        if (anyServiceInstalled)
        {
            var previousMarker = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData), "SOKNA", "setup", "previous-windows-services-version.txt");
            if (File.Exists(previousMarker))
            {
                try
                {
                    var previous = File.ReadAllText(previousMarker).Trim();
                    if (TryVersion(previous, out _)) return NormalizeVersion(previous);
                }
                catch { }
            }
        }

        var versionFile = Path.Combine(_installRoot, "WINDOWS_SERVICES_VERSION.txt");
        if (File.Exists(versionFile))
        {
            try
            {
                var value = File.ReadAllText(versionFile).Trim();
                if (TryVersion(value, out _)) return NormalizeVersion(value);
            }
            catch { }
        }
        return "";
    }

    private static (bool Installed, bool Running) ReadService(string name)
    {
        try
        {
            using var key = Registry.LocalMachine.OpenSubKey(@"SYSTEM\CurrentControlSet\Services\" + name, false);
            if (key is null) return (false, false);
            var sc = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.System), "sc.exe");
            var psi = new ProcessStartInfo(sc) { UseShellExecute = false, RedirectStandardOutput = true, CreateNoWindow = true };
            psi.ArgumentList.Add("query");
            psi.ArgumentList.Add(name);
            using var process = Process.Start(psi);
            if (process is null) return (true, false);
            var output = process.StandardOutput.ReadToEnd();
            process.WaitForExit();
            return (true, process.ExitCode == 0 && output.Contains("RUNNING", StringComparison.OrdinalIgnoreCase));
        }
        catch { return (false, false); }
    }

    private static string ReadServiceImage(string name)
    {
        try
        {
            using var key = Registry.LocalMachine.OpenSubKey(@"SYSTEM\CurrentControlSet\Services\" + name, false);
            return Convert.ToString(key?.GetValue("ImagePath")) ?? "";
        }
        catch { return ""; }
    }

    private static string ExtractExecutablePath(string command)
    {
        var value = (command ?? "").Trim();
        if (value.StartsWith('"'))
        {
            var end = value.IndexOf('"', 1);
            return end > 1 ? value[1..end] : "";
        }
        var exe = value.IndexOf(".exe", StringComparison.OrdinalIgnoreCase);
        return exe >= 0 ? value[..(exe + 4)] : value.Split(' ', StringSplitOptions.RemoveEmptyEntries).FirstOrDefault() ?? "";
    }

    private static string ReadRegistryString(string path, string name)
    {
        foreach (var view in new[] { RegistryView.Registry64, RegistryView.Registry32 })
        {
            try
            {
                using var root = RegistryKey.OpenBaseKey(RegistryHive.LocalMachine, view);
                using var key = root.OpenSubKey(path, false);
                var value = Convert.ToString(key?.GetValue(name));
                if (!string.IsNullOrWhiteSpace(value)) return value;
            }
            catch { }
        }
        return "";
    }

    private static (bool Ready, string Version) ReadVcRuntime()
    {
        foreach (var view in new[] { RegistryView.Registry64, RegistryView.Registry32 })
        {
            try
            {
                using var root = RegistryKey.OpenBaseKey(RegistryHive.LocalMachine, view);
                using var key = root.OpenSubKey(@"SOFTWARE\Microsoft\VisualStudio\14.0\VC\Runtimes\x64", false);
                var raw = Convert.ToString(key?.GetValue("Version")) ?? "";
                if (TryVersion(raw, out var version) && version >= new Version(14, 0)) return (true, version.ToString());
            }
            catch { }
        }
        return (false, "");
    }

    private static WsInstallKind ResolveInstallKind(bool runtimeInstalled, bool printInstalled, string installed, string current)
    {
        if (!runtimeInstalled && !printInstalled) return WsInstallKind.NotInstalled;
        if (!runtimeInstalled || !printInstalled) return WsInstallKind.Partial;
        if (!TryVersion(installed, out var installedVersion) || !TryVersion(current, out var currentVersion)) return WsInstallKind.Partial;
        var comparison = installedVersion.CompareTo(currentVersion);
        if (comparison < 0) return WsInstallKind.Upgrade;
        if (comparison > 0) return WsInstallKind.Newer;
        return WsInstallKind.Current;
    }

    private static bool TryVersion(string? value, out Version version)
    {
        var match = Regex.Match(value ?? "", "(\\d+)\\.(\\d+)(?:\\.(\\d+))?(?:\\.(\\d+))?");
        if (!match.Success) { version = new Version(0, 0); return false; }
        var normalized = string.Join('.', new[]
        {
            match.Groups[1].Value,
            match.Groups[2].Value,
            match.Groups[3].Success ? match.Groups[3].Value : "0",
            match.Groups[4].Success ? match.Groups[4].Value : "0"
        });
        if (Version.TryParse(normalized, out var parsed) && parsed is not null) { version = parsed; return true; }
        version = new Version(0, 0);
        return false;
    }

    private static string NormalizeVersion(string value)
    {
        if (!TryVersion(value, out var version)) return value.Trim();
        return $"{version.Major}.{version.Minor}.{version.Build}";
    }

    private static bool IsLoopbackOrigin(string value)
    {
        if (!Uri.TryCreate(value, UriKind.Absolute, out var uri) || !uri.IsLoopback) return false;
        if (uri.Scheme != Uri.UriSchemeHttp && uri.Scheme != Uri.UriSchemeHttps) return false;
        return uri.Port is >= 1024 and <= 65535 && uri.AbsolutePath == "/" && string.IsNullOrEmpty(uri.Query) && string.IsNullOrEmpty(uri.Fragment) && string.IsNullOrEmpty(uri.UserInfo);
    }

    private static async Task<int> RunElevatedAsync(string file, IEnumerable<string> args)
    {
        if (!File.Exists(file)) throw new FileNotFoundException("فایل لازم برای عملیات پیدا نشد.", file);
        var psi = new ProcessStartInfo(file) { UseShellExecute = true, Verb = "runas", WorkingDirectory = AppContext.BaseDirectory };
        foreach (var arg in args) psi.ArgumentList.Add(arg);
        using var process = Process.Start(psi) ?? throw new InvalidOperationException("اجرای عملیات شروع نشد.");
        await process.WaitForExitAsync();
        return process.ExitCode;
    }

    private void ShowDetails()
    {
        using var dialog = new Form
        {
            Text = "جزئیات Windows Services",
            StartPosition = FormStartPosition.CenterParent,
            ClientSize = new Size(650, 245),
            MinimumSize = new Size(650, 245),
            MaximumSize = new Size(850, 320),
            RightToLeft = RightToLeft.Yes,
            BackColor = Surface,
            Font = FaFont(10f),
            ShowInTaskbar = false
        };
        var grid = new TableLayoutPanel { Dock = DockStyle.Fill, Padding = new Padding(18), ColumnCount = 1, RowCount = 6, BackColor = Surface };
        grid.RowStyles.Add(new RowStyle(SizeType.AutoSize));
        grid.RowStyles.Add(new RowStyle(SizeType.AutoSize));
        grid.RowStyles.Add(new RowStyle(SizeType.AutoSize));
        grid.RowStyles.Add(new RowStyle(SizeType.AutoSize));
        grid.RowStyles.Add(new RowStyle(SizeType.AutoSize));
        grid.RowStyles.Add(new RowStyle(SizeType.Percent, 100));
        grid.Controls.Add(new Label { AutoSize = true, Text = "مسیر نصب سرویس‌ها", Font = FaFont(9.2f, FontStyle.Bold) }, 0, 0);
        grid.Controls.Add(ReadOnlyPath(_installRoot), 0, 1);
        grid.Controls.Add(new Label { AutoSize = true, Text = "مسیر داده و لاگ‌ها", Font = FaFont(9.2f, FontStyle.Bold), Margin = new Padding(0, 10, 0, 0) }, 0, 2);
        grid.Controls.Add(ReadOnlyPath(_dataRoot), 0, 3);
        grid.Controls.Add(new Label { AutoSize = true, Text = $"نسخه این بسته: {_currentVersion}", Margin = new Padding(0, 10, 0, 0) }, 0, 4);
        dialog.Controls.Add(grid);
        dialog.ShowDialog(this);
    }

    private static TextBox ReadOnlyPath(string value) => new() { Dock = DockStyle.Top, ReadOnly = true, Text = value, RightToLeft = RightToLeft.No, TextAlign = HorizontalAlignment.Left };

    private void SetBusy(bool busy, string? text = null)
    {
        _progress.Visible = busy;
        foreach (var button in Descendants<Button>(this)) button.Enabled = !busy;
        if (!busy && _state is not null)
        {
            var canPair = _state.InstallKind == WsInstallKind.Current && _state.RuntimeInstalled && _state.PrintInstalled;
            _pair.Enabled = canPair;
            _localUrl.Enabled = canPair;
            _pairCode.Enabled = canPair;
            _startRuntime.Enabled = canPair;
        }
        if (!string.IsNullOrWhiteSpace(text)) _footerStatus.Text = text;
        UseWaitCursor = busy;
    }

    private void ShowError(string message)
    {
        _footerStatus.Text = "عملیات کامل نشد.";
        MessageBox.Show(this, Safe(message), "خطا", MessageBoxButtons.OK, MessageBoxIcon.Error, MessageBoxDefaultButton.Button1, MessageBoxOptions.RtlReading | MessageBoxOptions.RightAlign);
    }

    private static string Safe(string? message)
    {
        var value = (message ?? "").Replace('\r', ' ').Replace('\n', ' ').Trim();
        return value.Length > 900 ? value[..900] : value;
    }

    private static void OpenFolder(string path)
    {
        Directory.CreateDirectory(path);
        Process.Start(new ProcessStartInfo("explorer.exe", path) { UseShellExecute = true });
    }

    private void LoadPersianFont()
    {
        foreach (var path in new[]
        {
            Path.Combine(AppContext.BaseDirectory, "print-worker", "Worker", "Fonts", "Vazirmatn-Regular.ttf"),
            Path.Combine(AppContext.BaseDirectory, "print-worker", "Worker", "Fonts", "Vazirmatn-Bold.ttf")
        })
        {
            try { if (File.Exists(path)) _privateFonts.AddFontFile(path); } catch { }
        }
        _regularFamily = _privateFonts.Families.FirstOrDefault();
    }

    private Font FaFont(float size, FontStyle style = FontStyle.Regular)
    {
        if (_regularFamily is not null)
        {
            try { return new Font(_regularFamily, size, style, GraphicsUnit.Point); } catch { }
        }
        foreach (var name in new[] { "Vazirmatn UI", "Vazirmatn", "Tahoma", "Segoe UI" })
        {
            try
            {
                using var probe = new Font(name, size, style, GraphicsUnit.Point);
                if (string.Equals(probe.Name, name, StringComparison.OrdinalIgnoreCase)) return new Font(name, size, style, GraphicsUnit.Point);
            }
            catch { }
        }
        return new Font(SystemFonts.MessageBoxFont.FontFamily, size, style, GraphicsUnit.Point);
    }

    private void ApplyFonts(Control root)
    {
        foreach (Control child in root.Controls)
        {
            if (child is not TextBox) child.Font = FaFont(child.Font.Size, child.Font.Style);
            ApplyFonts(child);
        }
    }

    private void FitToWorkingArea()
    {
        var work = Screen.FromControl(this).WorkingArea;
        var targetWidth = Math.Min(1120, Math.Max(980, work.Width - 32));
        var targetHeight = Math.Min(680, Math.Max(620, work.Height - 32));
        ClientSize = new Size(Math.Min(targetWidth, work.Width), Math.Min(targetHeight, work.Height));
        Location = new Point(work.Left + Math.Max(0, (work.Width - Width) / 2), work.Top + Math.Max(0, (work.Height - Height) / 2));
    }

    private Icon? LoadIcon()
    {
        try { var path = Path.Combine(AppContext.BaseDirectory, "Sokna.ico"); return File.Exists(path) ? new Icon(path) : null; } catch { return null; }
    }

    private void ApplyWindowIcon()
    {
        try
        {
            var path = Path.Combine(AppContext.BaseDirectory, "Sokna.ico");
            if (!File.Exists(path)) return;
            using var source = new Icon(path);
            var icon = (Icon)source.Clone();
            Icon = icon;
            if (IsHandleCreated) TaskbarIdentity.SetWindowIcon(Handle, icon.Handle);
        }
        catch { }
    }

    private static string DisplayVersion()
    {
        try
        {
            var exe = Environment.ProcessPath;
            if (!string.IsNullOrWhiteSpace(exe))
            {
                var info = FileVersionInfo.GetVersionInfo(exe);
                var raw = info.ProductVersion ?? info.FileVersion;
                if (!string.IsNullOrWhiteSpace(raw)) return NormalizeVersion(raw);
            }
        }
        catch { }
        return NormalizeVersion(Application.ProductVersion);
    }

    private static Label StatusValue() => new() { AutoSize = true, Text = "در حال بررسی…", ForeColor = Muted, TextAlign = ContentAlignment.MiddleRight };
    private static Label DetailValue() => new() { AutoSize = true, Text = "—", ForeColor = Text, TextAlign = ContentAlignment.MiddleRight };

    private static IEnumerable<T> Descendants<T>(Control root) where T : Control
    {
        foreach (Control child in root.Controls)
        {
            if (child is T typed) yield return typed;
            foreach (var nested in Descendants<T>(child)) yield return nested;
        }
    }
}
