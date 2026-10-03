using Microsoft.Win32;
using System.ComponentModel;
using System.Diagnostics;
using System.Drawing.Text;
using System.Runtime.InteropServices;
using System.Text.Json;
using System.Text.RegularExpressions;

namespace Sokna.SetupUi;

internal static class DashboardProgram
{
    [STAThread]
    private static void Main()
    {
        ApplicationConfiguration.Initialize();
        TaskbarIdentity.Apply();
        Application.Run(new DashboardForm());
    }
}

internal enum InstallKind { NotInstalled, Partial, Current, Upgrade, Newer }

internal sealed record DashboardState(
    InstallKind InstallKind,
    string InstalledVersion,
    string CurrentVersion,
    bool RuntimeInstalled,
    bool RuntimeRunning,
    bool PrintInstalled,
    bool PrintRunning,
    bool Paired,
    string LocalBaseUrl,
    bool PrerequisiteReady,
    string PrerequisiteVersion);

internal sealed class DashboardForm : Form
{
    private const string RuntimeService = "SoknaRuntime";
    private const string PrintService = "SoknaPrintWorker";
    private static readonly Color Canvas = Color.FromArgb(246, 244, 240);
    private static readonly Color Surface = Color.White;
    private static readonly Color Brand = Color.FromArgb(20, 91, 84);
    private static readonly Color BrandDark = Color.FromArgb(12, 63, 59);
    private static readonly Color BrandSoft = Color.FromArgb(232, 242, 240);
    private static readonly Color Text = Color.FromArgb(35, 43, 42);
    private static readonly Color Muted = Color.FromArgb(98, 107, 106);
    private static readonly Color Border = Color.FromArgb(218, 223, 222);
    private static readonly Color Good = Color.FromArgb(25, 119, 79);
    private static readonly Color Warn = Color.FromArgb(161, 102, 17);
    private static readonly Color Bad = Color.FromArgb(164, 55, 55);

    private readonly PrivateFontCollection _fonts = new();
    private FontFamily? _faFamily;
    private readonly string _currentVersion;
    private DashboardState? _state;

    private readonly Label _installedValue = ValueLabel();
    private readonly Label _servicesValue = ValueLabel();
    private readonly Label _pairValue = ValueLabel();
    private readonly Label _mainTitle = new() { AutoSize = true, TextAlign = ContentAlignment.MiddleRight };
    private readonly Label _mainBody = new() { AutoSize = true, MaximumSize = new Size(610, 0), TextAlign = ContentAlignment.TopRight, ForeColor = Muted };
    private readonly Label _prereqLine = new() { AutoSize = true, TextAlign = ContentAlignment.MiddleRight };
    private readonly Button _primary = new() { AutoSize = true };
    private readonly Button _repair = new() { Text = "تعمیر نصب", AutoSize = true };
    private readonly Button _remove = new() { Text = "حذف سرویس‌ها", AutoSize = true };
    private readonly Button _refresh = new() { Text = "تازه‌سازی وضعیت", AutoSize = true };
    private readonly Button _support = new() { Text = "ساخت بسته عیب‌یابی", AutoSize = true };
    private readonly Button _logs = new() { Text = "باز کردن لاگ‌ها", AutoSize = true };
    private readonly Button _advanced = new() { Text = "تنظیمات پیشرفته", AutoSize = true };

    private readonly Panel _pairPanel = new() { Dock = DockStyle.Fill, BackColor = Surface };
    private readonly TextBox _baseUrl = new() { Text = "http://127.0.0.1:18080/", RightToLeft = RightToLeft.No, TextAlign = HorizontalAlignment.Left, Dock = DockStyle.Fill };
    private readonly TextBox _pairCode = new() { RightToLeft = RightToLeft.No, TextAlign = HorizontalAlignment.Left, Dock = DockStyle.Fill, MaxLength = 180 };
    private readonly CheckBox _startRuntime = new() { Text = "بعد از اتصال، Runtime شروع شود", Checked = true, AutoSize = true };
    private readonly Button _pairButton = new() { Text = "اتصال به Local Web", AutoSize = true };
    private readonly Label _pairHelp = new() { AutoSize = true, MaximumSize = new Size(430, 0), ForeColor = Muted, TextAlign = ContentAlignment.TopRight };

    private readonly Label _runtimeDetail = DetailLabel();
    private readonly Label _printDetail = DetailLabel();
    private readonly Label _versionDetail = DetailLabel();
    private readonly Label _pairDetail = DetailLabel();
    private readonly Label _status = new() { AutoSize = false, Dock = DockStyle.Fill, TextAlign = ContentAlignment.MiddleRight, ForeColor = Muted };
    private readonly ProgressBar _progress = new() { Dock = DockStyle.Fill, Style = ProgressBarStyle.Marquee, Visible = false };

    private string InstallRoot => Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.ProgramFiles), "SOKNA Windows Services");
    private string DataRoot => Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData), "SOKNA");

    public DashboardForm()
    {
        _currentVersion = DisplayVersion();
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
        Font = FaFont(10.2f);
        Icon = LoadIcon();

        Controls.Add(BuildRoot());
        ApplyFonts(this);
        ConfigureButtons();
        ConfigureEvents();

        Shown += async (_, _) =>
        {
            FitToScreen();
            ApplyWindowIcon();
            await RefreshStateAsync();
        };
    }

    private Control BuildRoot()
    {
        var root = new TableLayoutPanel
        {
            Dock = DockStyle.Fill,
            Padding = new Padding(22, 18, 22, 18),
            ColumnCount = 1,
            RowCount = 4,
            BackColor = Canvas,
            RightToLeft = RightToLeft.Yes
        };
        root.RowStyles.Add(new RowStyle(SizeType.Absolute, 94));
        root.RowStyles.Add(new RowStyle(SizeType.Absolute, 118));
        root.RowStyles.Add(new RowStyle(SizeType.Percent, 100));
        root.RowStyles.Add(new RowStyle(SizeType.Absolute, 58));
        root.Controls.Add(BuildHeader(), 0, 0);
        root.Controls.Add(BuildStatusCards(), 0, 1);
        root.Controls.Add(BuildMainArea(), 0, 2);
        root.Controls.Add(BuildFooter(), 0, 3);
        return root;
    }

    private Control BuildHeader()
    {
        var panel = new TableLayoutPanel
        {
            Dock = DockStyle.Fill,
            BackColor = Brand,
            ColumnCount = 2,
            RowCount = 1,
            Padding = new Padding(20, 13, 20, 12),
            Margin = new Padding(0, 0, 0, 12),
            RightToLeft = RightToLeft.Yes
        };
        panel.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 100));
        panel.ColumnStyles.Add(new ColumnStyle(SizeType.AutoSize));

        var copy = new TableLayoutPanel { Dock = DockStyle.Fill, ColumnCount = 1, RowCount = 2, BackColor = Brand, Margin = new Padding(0) };
        copy.RowStyles.Add(new RowStyle(SizeType.Percent, 60));
        copy.RowStyles.Add(new RowStyle(SizeType.Percent, 40));
        copy.Controls.Add(new Label
        {
            Text = "مدیریت سرویس‌های ویندوز سکنا",
            AutoSize = true,
            ForeColor = Color.White,
            Font = FaFont(17f, FontStyle.Bold),
            Anchor = AnchorStyles.Right
        }, 0, 0);
        copy.Controls.Add(new Label
        {
            Text = "نصب، به‌روزرسانی، اتصال و عیب‌یابی Runtime و Print Agent",
            AutoSize = true,
            ForeColor = Color.FromArgb(221, 238, 235),
            Font = FaFont(9.6f),
            Anchor = AnchorStyles.Right
        }, 0, 1);

        var version = new Label
        {
            Text = _currentVersion,
            AutoSize = true,
            RightToLeft = RightToLeft.No,
            TextAlign = ContentAlignment.MiddleCenter,
            BackColor = Color.White,
            ForeColor = BrandDark,
            Padding = new Padding(12, 7, 12, 7),
            Font = FaFont(10f, FontStyle.Bold),
            Anchor = AnchorStyles.None,
            Margin = new Padding(14, 0, 0, 0)
        };
        panel.Controls.Add(copy, 0, 0);
        panel.Controls.Add(version, 1, 0);
        return panel;
    }

    private Control BuildStatusCards()
    {
        var row = new TableLayoutPanel
        {
            Dock = DockStyle.Fill,
            ColumnCount = 3,
            RowCount = 1,
            Margin = new Padding(0, 0, 0, 12),
            RightToLeft = RightToLeft.Yes
        };
        for (var i = 0; i < 3; i++) row.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 33.333f));
        row.Controls.Add(StatusCard("نصب و نسخه", _installedValue), 0, 0);
        row.Controls.Add(StatusCard("سرویس‌ها", _servicesValue), 1, 0);
        row.Controls.Add(StatusCard("اتصال Local Web", _pairValue), 2, 0);
        return row;
    }

    private Control StatusCard(string title, Label value)
    {
        var card = Card();
        card.Padding = new Padding(16, 12, 16, 12);
        card.Margin = new Padding(5);
        var layout = new TableLayoutPanel { Dock = DockStyle.Fill, ColumnCount = 1, RowCount = 2, BackColor = Surface };
        layout.RowStyles.Add(new RowStyle(SizeType.AutoSize));
        layout.RowStyles.Add(new RowStyle(SizeType.Percent, 100));
        layout.Controls.Add(new Label { Text = title, AutoSize = true, ForeColor = Muted, Font = FaFont(9.4f), Anchor = AnchorStyles.Right }, 0, 0);
        value.Font = FaFont(12.2f, FontStyle.Bold);
        value.Anchor = AnchorStyles.Right;
        layout.Controls.Add(value, 0, 1);
        card.Controls.Add(layout);
        return card;
    }

    private Control BuildMainArea()
    {
        var main = new TableLayoutPanel
        {
            Dock = DockStyle.Fill,
            ColumnCount = 2,
            RowCount = 1,
            RightToLeft = RightToLeft.Yes,
            Margin = new Padding(0)
        };
        main.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 58));
        main.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 42));
        main.Controls.Add(BuildActionCard(), 0, 0);
        main.Controls.Add(BuildConnectionCard(), 1, 0);
        return main;
    }

    private Control BuildActionCard()
    {
        var card = Card();
        card.Margin = new Padding(5, 0, 5, 0);
        card.Padding = new Padding(18, 16, 18, 14);
        var layout = new TableLayoutPanel { Dock = DockStyle.Fill, ColumnCount = 1, RowCount = 5, BackColor = Surface };
        layout.RowStyles.Add(new RowStyle(SizeType.AutoSize));
        layout.RowStyles.Add(new RowStyle(SizeType.AutoSize));
        layout.RowStyles.Add(new RowStyle(SizeType.Percent, 100));
        layout.RowStyles.Add(new RowStyle(SizeType.AutoSize));
        layout.RowStyles.Add(new RowStyle(SizeType.AutoSize));

        _mainTitle.Font = FaFont(15f, FontStyle.Bold);
        _mainTitle.ForeColor = BrandDark;
        _mainBody.Font = FaFont(10f);
        _mainBody.Margin = new Padding(0, 8, 0, 10);
        _prereqLine.Font = FaFont(9.5f, FontStyle.Bold);
        _prereqLine.Margin = new Padding(0, 6, 0, 8);

        layout.Controls.Add(_mainTitle, 0, 0);
        layout.Controls.Add(_mainBody, 0, 1);
        layout.Controls.Add(BuildDetailsGrid(), 0, 2);
        layout.Controls.Add(_prereqLine, 0, 3);
        layout.Controls.Add(ActionFlow(_primary, _repair, _remove), 0, 4);
        card.Controls.Add(layout);
        return card;
    }

    private Control BuildDetailsGrid()
    {
        var grid = new TableLayoutPanel { Dock = DockStyle.Fill, ColumnCount = 2, RowCount = 4, BackColor = Surface, Padding = new Padding(0, 6, 0, 6) };
        grid.ColumnStyles.Add(new ColumnStyle(SizeType.Absolute, 135));
        grid.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 100));
        for (var i = 0; i < 4; i++) grid.RowStyles.Add(new RowStyle(SizeType.Percent, 25));
        AddDetail(grid, 0, "Runtime", _runtimeDetail);
        AddDetail(grid, 1, "Print Agent", _printDetail);
        AddDetail(grid, 2, "نسخه نصب‌شده", _versionDetail);
        AddDetail(grid, 3, "اتصال", _pairDetail);
        return grid;
    }

    private void AddDetail(TableLayoutPanel grid, int row, string title, Label value)
    {
        grid.Controls.Add(new Label { Text = title, AutoSize = true, ForeColor = Muted, Anchor = AnchorStyles.Right, Margin = new Padding(0, 6, 0, 0) }, 0, row);
        value.Anchor = AnchorStyles.Right;
        grid.Controls.Add(value, 1, row);
    }

    private Control BuildConnectionCard()
    {
        var card = Card();
        card.Margin = new Padding(5, 0, 5, 0);
        card.Padding = new Padding(18, 16, 18, 14);
        var layout = new TableLayoutPanel { Dock = DockStyle.Fill, ColumnCount = 1, RowCount = 7, BackColor = Surface };
        layout.RowStyles.Add(new RowStyle(SizeType.AutoSize));
        layout.RowStyles.Add(new RowStyle(SizeType.AutoSize));
        layout.RowStyles.Add(new RowStyle(SizeType.AutoSize));
        layout.RowStyles.Add(new RowStyle(SizeType.AutoSize));
        layout.RowStyles.Add(new RowStyle(SizeType.AutoSize));
        layout.RowStyles.Add(new RowStyle(SizeType.Percent, 100));
        layout.RowStyles.Add(new RowStyle(SizeType.AutoSize));

        layout.Controls.Add(new Label { Text = "اتصال به Local Web", AutoSize = true, Font = FaFont(14f, FontStyle.Bold), ForeColor = BrandDark }, 0, 0);
        _pairHelp.Text = "برای اتصال یا نوسازی اتصال، از Local Web یک کد کوتاه‌عمر بسازید. این کار سرویس‌ها را دوباره نصب نمی‌کند؛ فقط تنظیمات اتصال اعمال و سرویس‌های لازم restart می‌شوند.";
        _pairHelp.Margin = new Padding(0, 7, 0, 10);
        layout.Controls.Add(_pairHelp, 0, 1);
        layout.Controls.Add(Field("آدرس Local Web", _baseUrl), 0, 2);
        layout.Controls.Add(Field("کد اتصال", _pairCode), 0, 3);
        layout.Controls.Add(_startRuntime, 0, 4);
        layout.Controls.Add(new Panel { Dock = DockStyle.Fill, BackColor = Surface }, 0, 5);
        layout.Controls.Add(ActionFlow(_pairButton), 0, 6);
        _pairPanel.Controls.Add(layout);
        card.Controls.Add(_pairPanel);
        return card;
    }

    private Control BuildFooter()
    {
        var footer = new TableLayoutPanel
        {
            Dock = DockStyle.Fill,
            ColumnCount = 3,
            RowCount = 1,
            BackColor = Canvas,
            Padding = new Padding(4, 9, 4, 0),
            RightToLeft = RightToLeft.Yes
        };
        footer.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 100));
        footer.ColumnStyles.Add(new ColumnStyle(SizeType.Absolute, 160));
        footer.ColumnStyles.Add(new ColumnStyle(SizeType.AutoSize));
        footer.Controls.Add(_status, 0, 0);
        footer.Controls.Add(_progress, 1, 0);
        footer.Controls.Add(ActionFlow(_refresh, _support, _logs, _advanced), 2, 0);
        return footer;
    }

    private Control Field(string label, Control input)
    {
        var box = new TableLayoutPanel { Dock = DockStyle.Top, AutoSize = true, ColumnCount = 1, RowCount = 2, BackColor = Surface, Margin = new Padding(0, 4, 0, 5) };
        box.Controls.Add(new Label { Text = label, AutoSize = true, ForeColor = Text, Font = FaFont(9.3f, FontStyle.Bold), Anchor = AnchorStyles.Right }, 0, 0);
        input.Margin = new Padding(0, 5, 0, 0);
        input.Height = 34;
        box.Controls.Add(input, 0, 1);
        return box;
    }

    private static Panel Card() => new() { Dock = DockStyle.Fill, BackColor = Surface, BorderStyle = BorderStyle.FixedSingle };

    private FlowLayoutPanel ActionFlow(params Control[] controls)
    {
        var flow = new FlowLayoutPanel { AutoSize = true, Dock = DockStyle.Fill, FlowDirection = FlowDirection.RightToLeft, WrapContents = false, RightToLeft = RightToLeft.Yes, BackColor = Surface, Padding = new Padding(0, 4, 0, 0), Margin = new Padding(0) };
        foreach (var control in controls) { control.Margin = new Padding(7, 0, 0, 0); flow.Controls.Add(control); }
        return flow;
    }

    private void ConfigureButtons()
    {
        PrimaryButton(_primary);
        PrimaryButton(_pairButton);
        SecondaryButton(_repair);
        DangerButton(_remove);
        SecondaryButton(_refresh);
        SecondaryButton(_support);
        SecondaryButton(_logs);
        SecondaryButton(_advanced);
        foreach (var b in Descendants<Button>(this))
        {
            b.MinimumSize = new Size(116, 38);
            b.Cursor = Cursors.Hand;
            b.Font = FaFont(9.5f, FontStyle.Bold);
        }
    }

    private static void PrimaryButton(Button b)
    {
        b.FlatStyle = FlatStyle.Flat; b.FlatAppearance.BorderSize = 0; b.BackColor = Brand; b.ForeColor = Color.White; b.Padding = new Padding(12, 5, 12, 5); b.UseVisualStyleBackColor = false;
    }
    private static void SecondaryButton(Button b)
    {
        b.FlatStyle = FlatStyle.Flat; b.FlatAppearance.BorderColor = Border; b.BackColor = Surface; b.ForeColor = BrandDark; b.Padding = new Padding(10, 5, 10, 5); b.UseVisualStyleBackColor = false;
    }
    private static void DangerButton(Button b)
    {
        b.FlatStyle = FlatStyle.Flat; b.FlatAppearance.BorderColor = Color.FromArgb(229, 196, 196); b.BackColor = Color.FromArgb(255, 246, 246); b.ForeColor = Bad; b.Padding = new Padding(10, 5, 10, 5); b.UseVisualStyleBackColor = false;
    }

    private void ConfigureEvents()
    {
        _refresh.Click += async (_, _) => await RefreshStateAsync();
        _primary.Click += async (_, _) => await PrimaryActionAsync();
        _repair.Click += async (_, _) => await RunLifecycleAsync("repair");
        _remove.Click += async (_, _) => await RunLifecycleAsync("uninstall");
        _pairButton.Click += async (_, _) => await PairAsync();
        _logs.Click += (_, _) => OpenFolder(Path.Combine(DataRoot, "Logs"));
        _support.Click += async (_, _) => await BuildSupportBundleAsync();
        _advanced.Click += (_, _) => ShowAdvanced();
    }

    private async Task RefreshStateAsync()
    {
        SetBusy(true, "در حال بررسی وضعیت…");
        try
        {
            _state = await Task.Run(ReadState);
            RenderState(_state);
            _status.Text = "وضعیت به‌روز است.";
        }
        catch (Exception e)
        {
            _status.Text = "بررسی وضعیت کامل نشد.";
            MessageBox.Show(this, Safe(e.Message), "خطا در بررسی وضعیت", MessageBoxButtons.OK, MessageBoxIcon.Error, MessageBoxDefaultButton.Button1, MessageBoxOptions.RtlReading | MessageBoxOptions.RightAlign);
        }
        finally { SetBusy(false); }
    }

    private DashboardState ReadState()
    {
        var runtime = ReadService(RuntimeService);
        var print = ReadService(PrintService);
        var installedVersion = ReadInstalledVersion();
        var paired = false;
        var localBase = "";
        var statePath = Path.Combine(DataRoot, "setup", "windows-services-state.json");
        if (File.Exists(statePath))
        {
            try
            {
                using var doc = JsonDocument.Parse(File.ReadAllText(statePath));
                paired = doc.RootElement.TryGetProperty("paired", out var p) && p.ValueKind == JsonValueKind.True;
                if (doc.RootElement.TryGetProperty("local_base_url", out var u) && u.ValueKind == JsonValueKind.String) localBase = u.GetString() ?? "";
            }
            catch { }
        }

        var kind = ResolveInstallKind(runtime.Installed, print.Installed, installedVersion, _currentVersion);
        var prereq = ReadVcRuntime();
        return new DashboardState(kind, installedVersion, _currentVersion, runtime.Installed, runtime.Running, print.Installed, print.Running, paired, localBase, prereq.Ready, prereq.Version);
    }

    private static InstallKind ResolveInstallKind(bool runtime, bool print, string installed, string current)
    {
        if (!runtime && !print) return InstallKind.NotInstalled;
        if (!runtime || !print) return InstallKind.Partial;
        if (!TryVersion(installed, out var iv) || !TryVersion(current, out var cv)) return InstallKind.Partial;
        var cmp = iv.CompareTo(cv);
        if (cmp < 0) return InstallKind.Upgrade;
        if (cmp > 0) return InstallKind.Newer;
        return InstallKind.Current;
    }

    private void RenderState(DashboardState s)
    {
        _installedValue.Text = s.InstallKind switch
        {
            InstallKind.NotInstalled => "نصب نشده",
            InstallKind.Partial => "نصب ناقص",
            InstallKind.Upgrade => $"{s.InstalledVersion}  ←  {s.CurrentVersion}",
            InstallKind.Newer => $"نسخه {s.InstalledVersion}",
            _ => $"نسخه {s.InstalledVersion}"
        };
        _installedValue.ForeColor = s.InstallKind switch { InstallKind.Current => Good, InstallKind.Upgrade => Warn, InstallKind.NotInstalled => Muted, _ => Bad };

        var bothRunning = s.RuntimeRunning && s.PrintRunning;
        var expectedUnpaired = !s.Paired && s.PrintRunning && s.RuntimeInstalled;
        _servicesValue.Text = bothRunning ? "هر دو در حال اجرا" : expectedUnpaired ? "Print Agent فعال" : (s.RuntimeInstalled || s.PrintInstalled ? "نیاز به بررسی" : "—");
        _servicesValue.ForeColor = bothRunning || expectedUnpaired ? Good : (s.RuntimeInstalled || s.PrintInstalled ? Warn : Muted);

        _pairValue.Text = s.Paired ? "متصل" : (s.RuntimeInstalled && s.PrintInstalled ? "متصل نیست" : "بعد از نصب");
        _pairValue.ForeColor = s.Paired ? Good : (s.RuntimeInstalled && s.PrintInstalled ? Warn : Muted);

        _runtimeDetail.Text = s.RuntimeInstalled ? (s.RuntimeRunning ? "نصب شده • در حال اجرا" : "نصب شده • متوقف") : "نصب نشده";
        _printDetail.Text = s.PrintInstalled ? (s.PrintRunning ? "نصب شده • در حال اجرا" : "نصب شده • متوقف") : "نصب نشده";
        _versionDetail.Text = string.IsNullOrWhiteSpace(s.InstalledVersion) ? "قابل تشخیص نیست" : s.InstalledVersion;
        _versionDetail.RightToLeft = RightToLeft.No;
        _pairDetail.Text = s.Paired ? (string.IsNullOrWhiteSpace(s.LocalBaseUrl) ? "متصل" : "متصل به " + s.LocalBaseUrl) : "متصل نیست";

        _prereqLine.Text = s.PrerequisiteReady ? $"پیش‌نیاز ویندوز آماده است  •  Visual C++ {s.PrerequisiteVersion}" : "پیش‌نیاز Visual C++ آماده نیست؛ نصب/به‌روزرسانی تا رفع آن انجام نمی‌شود.";
        _prereqLine.ForeColor = s.PrerequisiteReady ? Good : Bad;

        _primary.Visible = true;
        _repair.Visible = s.RuntimeInstalled || s.PrintInstalled;
        _remove.Visible = s.RuntimeInstalled || s.PrintInstalled;
        _pairButton.Enabled = s.RuntimeInstalled && s.PrintInstalled && s.InstallKind is InstallKind.Current or InstallKind.Upgrade;
        _baseUrl.Enabled = _pairButton.Enabled;
        _pairCode.Enabled = _pairButton.Enabled;
        _startRuntime.Enabled = _pairButton.Enabled;

        switch (s.InstallKind)
        {
            case InstallKind.NotInstalled:
                _mainTitle.Text = "سرویس‌های سکنا هنوز نصب نشده‌اند";
                _mainBody.Text = "برای شروع، Runtime و Print Agent نصب می‌شوند. Local Web و زیرساخت وب دست‌کاری نمی‌شوند. بعد از نصب می‌توانید اتصال را جداگانه انجام دهید.";
                _primary.Text = "نصب سرویس‌ها";
                break;
            case InstallKind.Upgrade:
                _mainTitle.Text = "به‌روزرسانی آماده است";
                _mainBody.Text = $"نسخه نصب‌شده {s.InstalledVersion} است و این بسته نسخه {s.CurrentVersion}. با به‌روزرسانی، سرویس‌ها و فایل‌های متعلق به Windows Services جایگزین می‌شوند و داده‌های برنامه حفظ می‌شوند.";
                _primary.Text = $"به‌روزرسانی به {s.CurrentVersion}";
                break;
            case InstallKind.Current:
                _mainTitle.Text = "Windows Services به‌روز است";
                _mainBody.Text = s.Paired ? "نسخه نصب‌شده با این بسته یکسان است و اتصال Local Web ثبت شده. در صورت مشکل از تعمیر نصب یا بسته عیب‌یابی استفاده کنید." : "نسخه نصب‌شده با این بسته یکسان است. برای ادامه فقط اتصال به Local Web لازم است؛ اتصال، نصب سرویس‌ها را دوباره انجام نمی‌دهد.";
                _primary.Visible = false;
                break;
            case InstallKind.Newer:
                _mainTitle.Text = "نسخه جدیدتری روی سیستم نصب است";
                _mainBody.Text = $"نسخه نصب‌شده {s.InstalledVersion} از این بسته ({s.CurrentVersion}) جدیدتر است. Downgrade خودکار مسدود شده است.";
                _primary.Visible = false;
                _repair.Visible = false;
                break;
            default:
                _mainTitle.Text = "نصب ناقص یا نسخه نامشخص است";
                _mainBody.Text = "یکی از سرویس‌ها یا اطلاعات نسخه کامل نیست. ابتدا «تعمیر نصب» را اجرا کنید؛ اگر مشکل باقی ماند بسته عیب‌یابی بسازید.";
                _primary.Visible = false;
                break;
        }
    }

    private async Task PrimaryActionAsync()
    {
        if (_state is null) return;
        if (!_state.PrerequisiteReady)
        {
            MessageBox.Show(this, "Microsoft Visual C++ x64 Runtime لازم آماده نیست. ابتدا پیش‌نیاز را نصب کنید.", "پیش‌نیاز ناقص", MessageBoxButtons.OK, MessageBoxIcon.Warning, MessageBoxDefaultButton.Button1, MessageBoxOptions.RtlReading | MessageBoxOptions.RightAlign);
            return;
        }
        if (_state.InstallKind == InstallKind.NotInstalled || _state.InstallKind == InstallKind.Upgrade) await RunLifecycleAsync("install");
    }

    private async Task RunLifecycleAsync(string mode)
    {
        if (mode != "uninstall" && _state is not null && !_state.PrerequisiteReady)
        {
            MessageBox.Show(this, "پیش‌نیاز Visual C++ آماده نیست.", "پیش‌نیاز ناقص", MessageBoxButtons.OK, MessageBoxIcon.Warning, MessageBoxDefaultButton.Button1, MessageBoxOptions.RtlReading | MessageBoxOptions.RightAlign);
            return;
        }
        var text = mode switch
        {
            "repair" => "فایل‌های Runtime و Print Agent دوباره اعمال می‌شوند. داده‌های SOKNA حذف نمی‌شوند. ادامه می‌دهید؟",
            "uninstall" => "دو سرویس Runtime و Print Agent حذف می‌شوند؛ داده‌ها و Local Web باقی می‌مانند. ادامه می‌دهید؟",
            _ => "سرویس‌های Windows Services نصب یا به‌روزرسانی می‌شوند. ادامه می‌دهید؟"
        };
        if (MessageBox.Show(this, text, "تأیید عملیات", MessageBoxButtons.YesNo, mode == "uninstall" ? MessageBoxIcon.Warning : MessageBoxIcon.Information, mode == "uninstall" ? MessageBoxDefaultButton.Button2 : MessageBoxDefaultButton.Button1, MessageBoxOptions.RtlReading | MessageBoxOptions.RightAlign) != DialogResult.Yes) return;

        SetBusy(true, "در حال اجرای عملیات…");
        string? plan = null;
        try
        {
            plan = Path.Combine(Path.GetTempPath(), "sokna-services-plan-" + Guid.NewGuid().ToString("N") + ".json");
            var payload = new Dictionary<string, object?>
            {
                ["schema_version"] = 2,
                ["mode"] = mode,
                ["shell_root"] = AppContext.BaseDirectory.TrimEnd(Path.DirectorySeparatorChar),
                ["install_root"] = InstallRoot,
                ["data_root"] = DataRoot,
                ["pairing_file"] = "",
                ["pairing_code"] = "",
                ["pairing_base_url"] = "",
                ["start_when_paired"] = false
            };
            await File.WriteAllTextAsync(plan, JsonSerializer.Serialize(payload, new JsonSerializerOptions { WriteIndented = true }));
            var host = Path.Combine(AppContext.BaseDirectory, "SoknaSetupHost.exe");
            var exit = await RunElevatedAsync(host, new[] { "--plan-file", plan });
            if (exit != 0) throw new InvalidOperationException($"عملیات با کد {exit} متوقف شد. برای جزئیات لاگ نصب را بررسی کنید.");
            _status.Text = mode == "uninstall" ? "سرویس‌ها حذف شدند." : mode == "repair" ? "تعمیر نصب کامل شد." : "نصب/به‌روزرسانی کامل شد.";
            await RefreshStateAsync();
        }
        catch (Win32Exception e) when (e.NativeErrorCode == 1223) { _status.Text = "درخواست Administrator لغو شد."; }
        catch (Exception e) { ShowError(e.Message); }
        finally { if (plan is not null) try { File.Delete(plan); } catch { } SetBusy(false); }
    }

    private async Task PairAsync()
    {
        var code = _pairCode.Text.Trim();
        var baseUrl = _baseUrl.Text.Trim();
        if (!Regex.IsMatch(code, "^ws1_[a-f0-9]{24}_[a-f0-9]{48}$")) { ShowError("کد اتصال معتبر نیست. یک کد تازه از Local Web بسازید."); return; }
        if (!IsLoopbackOrigin(baseUrl)) { ShowError("آدرس Local Web باید یک origin محلی باشد؛ مانند http://127.0.0.1:18080/."); return; }

        SetBusy(true, "در حال اتصال به Local Web…");
        string? plan = null;
        try
        {
            plan = Path.Combine(Path.GetTempPath(), "sokna-pair-plan-" + Guid.NewGuid().ToString("N") + ".json");
            var payload = new Dictionary<string, object?>
            {
                ["format"] = "sokna-windows-services-pair-plan-v1",
                ["schema_version"] = 1,
                ["install_root"] = InstallRoot,
                ["data_root"] = DataRoot,
                ["pairing_base_url"] = baseUrl,
                ["pairing_code"] = code,
                ["start_when_paired"] = _startRuntime.Checked
            };
            await File.WriteAllTextAsync(plan, JsonSerializer.Serialize(payload, new JsonSerializerOptions { WriteIndented = true }));
            var ps = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.System), "WindowsPowerShell", "v1.0", "powershell.exe");
            var script = Path.Combine(AppContext.BaseDirectory, "pair-windows-services.ps1");
            var exit = await RunElevatedAsync(ps, new[] { "-NoProfile", "-NonInteractive", "-ExecutionPolicy", "Bypass", "-File", script, "-PlanFile", plan });
            if (exit != 0) throw new InvalidOperationException($"اتصال با کد {exit} متوقف شد. در صورت تکرار، بسته عیب‌یابی بسازید.");
            _pairCode.Clear();
            _status.Text = "اتصال Local Web با موفقیت انجام شد؛ سرویس‌ها دوباره نصب نشدند.";
            await RefreshStateAsync();
        }
        catch (Win32Exception e) when (e.NativeErrorCode == 1223) { _status.Text = "درخواست Administrator لغو شد."; }
        catch (Exception e) { ShowError(e.Message); }
        finally { if (plan is not null) try { File.Delete(plan); } catch { } SetBusy(false); }
    }

    private async Task BuildSupportBundleAsync()
    {
        SetBusy(true, "در حال ساخت بسته عیب‌یابی…");
        try
        {
            var ps = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.System), "WindowsPowerShell", "v1.0", "powershell.exe");
            var script = Path.Combine(AppContext.BaseDirectory, "collect-support.ps1");
            var output = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.DesktopDirectory), "SOKNA-Support");
            Directory.CreateDirectory(output);
            var exit = await RunElevatedAsync(ps, new[] { "-NoProfile", "-NonInteractive", "-ExecutionPolicy", "Bypass", "-File", script, "-DataRoot", DataRoot, "-OutputRoot", output });
            if (exit != 0) throw new InvalidOperationException("ساخت بسته عیب‌یابی کامل نشد.");
            OpenFolder(output);
            _status.Text = "بسته عیب‌یابی روی Desktop ساخته شد.";
        }
        catch (Win32Exception e) when (e.NativeErrorCode == 1223) { _status.Text = "درخواست Administrator لغو شد."; }
        catch (Exception e) { ShowError(e.Message); }
        finally { SetBusy(false); }
    }

    private void ShowAdvanced()
    {
        var text = "مسیر نصب سرویس‌ها:\n" + InstallRoot + "\n\nمسیر داده و لاگ‌ها:\n" + DataRoot + "\n\nنسخه بسته:\n" + _currentVersion;
        MessageBox.Show(this, text, "تنظیمات پیشرفته", MessageBoxButtons.OK, MessageBoxIcon.Information, MessageBoxDefaultButton.Button1, MessageBoxOptions.RtlReading | MessageBoxOptions.RightAlign);
    }

    private static async Task<int> RunElevatedAsync(string file, IEnumerable<string> args)
    {
        if (!File.Exists(file)) throw new FileNotFoundException("فایل اجرایی لازم پیدا نشد.", file);
        var psi = new ProcessStartInfo(file) { UseShellExecute = true, Verb = "runas", WorkingDirectory = AppContext.BaseDirectory };
        foreach (var arg in args) psi.ArgumentList.Add(arg);
        using var p = Process.Start(psi) ?? throw new InvalidOperationException("اجرای عملیات شروع نشد.");
        await p.WaitForExitAsync();
        return p.ExitCode;
    }

    private (bool Installed, bool Running) ReadService(string name)
    {
        try
        {
            using var key = Registry.LocalMachine.OpenSubKey(@"SYSTEM\CurrentControlSet\Services\" + name, false);
            if (key is null) return (false, false);
            var sc = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.System), "sc.exe");
            var psi = new ProcessStartInfo(sc) { UseShellExecute = false, RedirectStandardOutput = true, CreateNoWindow = true };
            psi.ArgumentList.Add("query"); psi.ArgumentList.Add(name);
            using var p = Process.Start(psi)!;
            var output = p.StandardOutput.ReadToEnd(); p.WaitForExit();
            return (true, p.ExitCode == 0 && output.Contains("RUNNING", StringComparison.OrdinalIgnoreCase));
        }
        catch { return (false, false); }
    }

    private string ReadInstalledVersion()
    {
        var state = Path.Combine(DataRoot, "setup", "windows-services-state.json");
        if (File.Exists(state))
        {
            try
            {
                using var doc = JsonDocument.Parse(File.ReadAllText(state));
                if (doc.RootElement.TryGetProperty("package_version", out var p) && p.ValueKind == JsonValueKind.String && !string.IsNullOrWhiteSpace(p.GetString())) return NormalizeVersion(p.GetString()!);
            }
            catch { }
        }
        var versionFile = Path.Combine(InstallRoot, "WINDOWS_SERVICES_VERSION.txt");
        if (File.Exists(versionFile))
        {
            try { var value = File.ReadAllText(versionFile).Trim(); if (!string.IsNullOrWhiteSpace(value)) return NormalizeVersion(value); } catch { }
        }
        var ui = Path.Combine(InstallRoot, "SoknaSetupUi.exe");
        if (File.Exists(ui))
        {
            try { var value = FileVersionInfo.GetVersionInfo(ui).ProductVersion ?? FileVersionInfo.GetVersionInfo(ui).FileVersion; if (!string.IsNullOrWhiteSpace(value)) return NormalizeVersion(value!); } catch { }
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

    private static bool TryVersion(string value, out Version version)
    {
        var m = Regex.Match(value ?? "", "(\\d+)\\.(\\d+)(?:\\.(\\d+))?(?:\\.(\\d+))?");
        if (!m.Success) { version = new Version(0, 0); return false; }
        var parts = new[] { m.Groups[1].Value, m.Groups[2].Value, m.Groups[3].Success ? m.Groups[3].Value : "0", m.Groups[4].Success ? m.Groups[4].Value : "0" };
        return Version.TryParse(string.Join('.', parts), out version!);
    }

    private static string NormalizeVersion(string value)
    {
        if (!TryVersion(value, out var v)) return value.Trim();
        return $"{v.Major}.{v.Minor}.{v.Build}";
    }

    private static bool IsLoopbackOrigin(string value)
    {
        if (!Uri.TryCreate(value, UriKind.Absolute, out var uri) || !uri.IsLoopback) return false;
        if (uri.Scheme != Uri.UriSchemeHttp && uri.Scheme != Uri.UriSchemeHttps) return false;
        return uri.Port is >= 1024 and <= 65535 && uri.AbsolutePath == "/" && string.IsNullOrEmpty(uri.Query) && string.IsNullOrEmpty(uri.Fragment) && string.IsNullOrEmpty(uri.UserInfo);
    }

    private void SetBusy(bool busy, string text = "")
    {
        _progress.Visible = busy;
        _primary.Enabled = !busy;
        _repair.Enabled = !busy;
        _remove.Enabled = !busy;
        _refresh.Enabled = !busy;
        _support.Enabled = !busy;
        _pairButton.Enabled = !busy && (_state?.RuntimeInstalled == true && _state?.PrintInstalled == true);
        if (!string.IsNullOrWhiteSpace(text)) _status.Text = text;
        UseWaitCursor = busy;
    }

    private void ShowError(string message)
    {
        _status.Text = "عملیات کامل نشد.";
        MessageBox.Show(this, Safe(message), "خطا", MessageBoxButtons.OK, MessageBoxIcon.Error, MessageBoxDefaultButton.Button1, MessageBoxOptions.RtlReading | MessageBoxOptions.RightAlign);
    }

    private static string Safe(string message)
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
        foreach (var candidate in new[]
        {
            Path.Combine(AppContext.BaseDirectory, "print-worker", "Worker", "Fonts", "Vazirmatn-Regular.ttf"),
            Path.Combine(AppContext.BaseDirectory, "PrintAgent", "Worker", "Fonts", "Vazirmatn-Regular.ttf")
        })
        {
            try
            {
                if (!File.Exists(candidate)) continue;
                _fonts.AddFontFile(candidate);
                _faFamily = _fonts.Families.FirstOrDefault();
                if (_faFamily is not null) return;
            }
            catch { }
        }
    }

    private Font FaFont(float size, FontStyle style = FontStyle.Regular)
    {
        if (_faFamily is not null) return new Font(_faFamily, size, style, GraphicsUnit.Point);
        foreach (var name in new[] { "Vazirmatn UI", "Vazirmatn", "Tahoma", "Segoe UI" })
        {
            try { using var probe = new Font(name, size, style, GraphicsUnit.Point); if (string.Equals(probe.Name, name, StringComparison.OrdinalIgnoreCase)) return new Font(name, size, style, GraphicsUnit.Point); } catch { }
        }
        return new Font(SystemFonts.MessageBoxFont.FontFamily, size, style, GraphicsUnit.Point);
    }

    private void ApplyFonts(Control root)
    {
        foreach (Control child in root.Controls)
        {
            if (child.Font.FontFamily.Name is "Microsoft Sans Serif" or "Segoe UI") child.Font = FaFont(child.Font.Size, child.Font.Style);
            ApplyFonts(child);
        }
    }

    private static Label ValueLabel() => new() { AutoSize = true, Text = "در حال بررسی…", ForeColor = Muted, TextAlign = ContentAlignment.MiddleRight };
    private static Label DetailLabel() => new() { AutoSize = true, Text = "—", ForeColor = Text, TextAlign = ContentAlignment.MiddleRight };

    private static IEnumerable<T> Descendants<T>(Control root) where T : Control
    {
        foreach (Control child in root.Controls)
        {
            if (child is T t) yield return t;
            foreach (var nested in Descendants<T>(child)) yield return nested;
        }
    }

    private void FitToScreen()
    {
        var work = Screen.FromControl(this).WorkingArea;
        var width = Math.Min(1120, Math.Max(980, work.Width - 36));
        var height = Math.Min(700, Math.Max(620, work.Height - 36));
        ClientSize = new Size(Math.Min(width, work.Width), Math.Min(height, work.Height));
        Location = new Point(work.Left + Math.Max(0, (work.Width - Width) / 2), work.Top + Math.Max(0, (work.Height - Height) / 2));
    }

    private static string DisplayVersion()
    {
        try
        {
            var exe = Environment.ProcessPath;
            if (!string.IsNullOrWhiteSpace(exe))
            {
                var raw = FileVersionInfo.GetVersionInfo(exe).ProductVersion ?? FileVersionInfo.GetVersionInfo(exe).FileVersion;
                if (!string.IsNullOrWhiteSpace(raw)) return NormalizeVersion(raw!);
            }
        }
        catch { }
        return NormalizeVersion(Application.ProductVersion);
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
}
