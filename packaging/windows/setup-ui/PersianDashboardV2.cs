using Microsoft.Win32;
using System.ComponentModel;
using System.Diagnostics;
using System.Drawing.Text;
using System.Runtime.InteropServices;
using System.Text.Json;
using System.Text.RegularExpressions;

namespace Sokna.SetupUi;

internal enum WindowsInstallState
{
    NotInstalled,
    Partial,
    Current,
    Upgrade,
    Newer
}

internal sealed record WindowsDashboardState(
    WindowsInstallState InstallState,
    string InstalledVersion,
    bool RuntimeInstalled,
    bool RuntimeRunning,
    bool PrintInstalled,
    bool PrintRunning,
    bool Paired,
    string LocalBaseUrl,
    bool PrerequisiteReady,
    string PrerequisiteVersion);

internal sealed class PersianDashboardFormV2 : Form
{
    private const string RuntimeService = "SoknaRuntime";
    private const string PrintService = "SoknaPrintWorker";

    private static readonly Color CanvasColor = Color.FromArgb(246, 244, 240);
    private static readonly Color SurfaceColor = Color.White;
    private static readonly Color BrandColor = Color.FromArgb(20, 91, 84);
    private static readonly Color BrandDarkColor = Color.FromArgb(11, 63, 59);
    private static readonly Color BrandSoftColor = Color.FromArgb(233, 244, 241);
    private static readonly Color TextColor = Color.FromArgb(35, 44, 43);
    private static readonly Color MutedColor = Color.FromArgb(96, 106, 104);
    private static readonly Color BorderColor = Color.FromArgb(215, 223, 221);
    private static readonly Color GoodColor = Color.FromArgb(30, 117, 79);
    private static readonly Color WarningColor = Color.FromArgb(166, 103, 15);
    private static readonly Color DangerColor = Color.FromArgb(164, 53, 53);

    private readonly PrivateFontCollection _privateFonts = new();
    private FontFamily? _persianFontFamily;

    private readonly string _currentVersion;
    private string _installRoot;
    private string _dataRoot;
    private WindowsDashboardState? _state;

    private readonly Label _installStatus = StatusValueLabel();
    private readonly Label _servicesStatus = StatusValueLabel();
    private readonly Label _connectionStatus = StatusValueLabel();
    private readonly Label _actionTitle = new() { AutoSize = true, TextAlign = ContentAlignment.MiddleRight };
    private readonly Label _actionDescription = new() { AutoSize = true, TextAlign = ContentAlignment.TopRight };
    private readonly Label _runtimeDetail = DetailValueLabel();
    private readonly Label _printDetail = DetailValueLabel();
    private readonly Label _versionDetail = DetailValueLabel();
    private readonly Label _connectionDetail = DetailValueLabel();
    private readonly Label _prerequisiteDetail = new() { AutoSize = true, TextAlign = ContentAlignment.MiddleRight };

    private readonly Button _primaryButton = new() { AutoSize = true };
    private readonly Button _repairButton = new() { Text = "تعمیر نصب", AutoSize = true };
    private readonly Button _removeButton = new() { Text = "حذف سرویس‌ها", AutoSize = true };

    private readonly TextBox _localWebUrl = new()
    {
        Dock = DockStyle.Fill,
        RightToLeft = RightToLeft.No,
        TextAlign = HorizontalAlignment.Left,
        Text = "http://127.0.0.1:18080/",
        MaxLength = 180
    };
    private readonly TextBox _pairingCode = new()
    {
        Dock = DockStyle.Fill,
        RightToLeft = RightToLeft.No,
        TextAlign = HorizontalAlignment.Left,
        MaxLength = 180
    };
    private readonly CheckBox _startRuntime = new()
    {
        AutoSize = true,
        Checked = true,
        Text = "بعد از اتصال، Runtime شروع شود"
    };
    private readonly Button _pairButton = new() { Text = "اتصال به Local Web", AutoSize = true };

    private readonly Button _refreshButton = new() { Text = "تازه‌سازی", AutoSize = true };
    private readonly Button _supportButton = new() { Text = "بسته عیب‌یابی", AutoSize = true };
    private readonly Button _logsButton = new() { Text = "لاگ‌ها", AutoSize = true };
    private readonly Button _detailsButton = new() { Text = "جزئیات", AutoSize = true };
    private readonly Label _footerStatus = new() { Dock = DockStyle.Fill, TextAlign = ContentAlignment.MiddleRight };
    private readonly ProgressBar _progress = new() { Dock = DockStyle.Fill, Style = ProgressBarStyle.Marquee, Visible = false };

    internal PersianDashboardFormV2()
    {
        _currentVersion = DisplayVersion();
        _installRoot = AppContext.BaseDirectory.TrimEnd(Path.DirectorySeparatorChar, Path.AltDirectorySeparatorChar);
        _dataRoot = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData), "SOKNA");
        ResolveExistingRoots();
        LoadPersianFont();

        base.Text = $"سکنا | سرویس‌های ویندوز — نسخه {_currentVersion}";
        StartPosition = FormStartPosition.CenterScreen;
        BackColor = CanvasColor;
        ForeColor = TextColor;
        RightToLeft = RightToLeft.Yes;
        RightToLeftLayout = false;
        AutoScaleMode = AutoScaleMode.Dpi;
        AutoScroll = false;
        MinimumSize = new Size(960, 600);
        ClientSize = new Size(1100, 660);
        Font = PersianFont(10f);
        Icon = LoadIcon();

        Controls.Add(BuildRoot());
        StyleButtons();
        ApplyPersianFont(this);
        WireEvents();

        Shown += async (_, _) =>
        {
            FitToWorkingArea();
            ApplyWindowIcon();
            await RefreshStateAsync();
        };
    }

    private Control BuildRoot()
    {
        var root = new TableLayoutPanel
        {
            Dock = DockStyle.Fill,
            ColumnCount = 1,
            RowCount = 4,
            Padding = new Padding(18, 16, 18, 14),
            BackColor = CanvasColor,
            RightToLeft = RightToLeft.Yes,
            AutoScroll = false
        };
        root.RowStyles.Add(new RowStyle(SizeType.Absolute, 86));
        root.RowStyles.Add(new RowStyle(SizeType.Absolute, 104));
        root.RowStyles.Add(new RowStyle(SizeType.Percent, 100));
        root.RowStyles.Add(new RowStyle(SizeType.Absolute, 54));
        root.Controls.Add(BuildHeader(), 0, 0);
        root.Controls.Add(BuildSummary(), 0, 1);
        root.Controls.Add(BuildContent(), 0, 2);
        root.Controls.Add(BuildFooter(), 0, 3);
        return root;
    }

    private Control BuildHeader()
    {
        var header = new TableLayoutPanel
        {
            Dock = DockStyle.Fill,
            ColumnCount = 2,
            RowCount = 1,
            Padding = new Padding(18, 10, 18, 10),
            Margin = new Padding(0, 0, 0, 10),
            BackColor = BrandColor,
            RightToLeft = RightToLeft.Yes
        };
        header.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 100));
        header.ColumnStyles.Add(new ColumnStyle(SizeType.AutoSize));

        var titles = new TableLayoutPanel
        {
            Dock = DockStyle.Fill,
            ColumnCount = 1,
            RowCount = 2,
            Margin = new Padding(0),
            BackColor = BrandColor
        };
        titles.RowStyles.Add(new RowStyle(SizeType.Percent, 60));
        titles.RowStyles.Add(new RowStyle(SizeType.Percent, 40));
        titles.Controls.Add(new Label
        {
            AutoSize = true,
            Text = "مدیریت سرویس‌های ویندوز سکنا",
            ForeColor = Color.White,
            Font = PersianFont(16.5f, FontStyle.Bold),
            Anchor = AnchorStyles.Right
        }, 0, 0);
        titles.Controls.Add(new Label
        {
            AutoSize = true,
            Text = "نصب، به‌روزرسانی، اتصال و عیب‌یابی Runtime و Print Agent",
            ForeColor = Color.FromArgb(220, 238, 235),
            Font = PersianFont(9.3f),
            Anchor = AnchorStyles.Right
        }, 0, 1);

        var version = new Label
        {
            AutoSize = true,
            Text = _currentVersion,
            RightToLeft = RightToLeft.No,
            TextAlign = ContentAlignment.MiddleCenter,
            BackColor = Color.White,
            ForeColor = BrandDarkColor,
            Font = PersianFont(9.8f, FontStyle.Bold),
            Padding = new Padding(11, 6, 11, 6),
            Anchor = AnchorStyles.None,
            Margin = new Padding(12, 0, 0, 0)
        };
        header.Controls.Add(titles, 0, 0);
        header.Controls.Add(version, 1, 0);
        return header;
    }

    private Control BuildSummary()
    {
        var row = new TableLayoutPanel
        {
            Dock = DockStyle.Fill,
            ColumnCount = 3,
            RowCount = 1,
            Margin = new Padding(0, 0, 0, 10),
            RightToLeft = RightToLeft.Yes
        };
        for (var i = 0; i < 3; i++) row.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 33.333f));
        row.Controls.Add(SummaryCard("نصب و نسخه", _installStatus), 0, 0);
        row.Controls.Add(SummaryCard("وضعیت سرویس‌ها", _servicesStatus), 1, 0);
        row.Controls.Add(SummaryCard("اتصال Local Web", _connectionStatus), 2, 0);
        return row;
    }

    private Control SummaryCard(string caption, Label value)
    {
        var card = Card();
        card.Margin = new Padding(4);
        card.Padding = new Padding(14, 10, 14, 10);
        var grid = new TableLayoutPanel { Dock = DockStyle.Fill, ColumnCount = 1, RowCount = 2, Margin = new Padding(0), BackColor = SurfaceColor };
        grid.RowStyles.Add(new RowStyle(SizeType.AutoSize));
        grid.RowStyles.Add(new RowStyle(SizeType.Percent, 100));
        grid.Controls.Add(new Label
        {
            AutoSize = true,
            Text = caption,
            ForeColor = MutedColor,
            Font = PersianFont(9.1f),
            Anchor = AnchorStyles.Right
        }, 0, 0);
        value.Font = PersianFont(11.8f, FontStyle.Bold);
        value.Anchor = AnchorStyles.Right;
        grid.Controls.Add(value, 0, 1);
        card.Controls.Add(grid);
        return card;
    }

    private Control BuildContent()
    {
        var content = new TableLayoutPanel
        {
            Dock = DockStyle.Fill,
            ColumnCount = 2,
            RowCount = 1,
            Margin = new Padding(0),
            RightToLeft = RightToLeft.Yes
        };
        content.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 57));
        content.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 43));
        content.Controls.Add(BuildLifecycleCard(), 0, 0);
        content.Controls.Add(BuildPairCard(), 1, 0);
        return content;
    }

    private Control BuildLifecycleCard()
    {
        var card = Card();
        card.Margin = new Padding(4, 0, 4, 0);
        card.Padding = new Padding(16, 13, 16, 12);

        var grid = new TableLayoutPanel { Dock = DockStyle.Fill, ColumnCount = 1, RowCount = 5, BackColor = SurfaceColor };
        grid.RowStyles.Add(new RowStyle(SizeType.AutoSize));
        grid.RowStyles.Add(new RowStyle(SizeType.AutoSize));
        grid.RowStyles.Add(new RowStyle(SizeType.Percent, 100));
        grid.RowStyles.Add(new RowStyle(SizeType.AutoSize));
        grid.RowStyles.Add(new RowStyle(SizeType.AutoSize));

        _actionTitle.Font = PersianFont(14.3f, FontStyle.Bold);
        _actionTitle.ForeColor = BrandDarkColor;
        _actionDescription.Font = PersianFont(9.5f);
        _actionDescription.ForeColor = MutedColor;
        _actionDescription.MaximumSize = new Size(560, 0);
        _actionDescription.Margin = new Padding(0, 6, 0, 6);
        _prerequisiteDetail.Font = PersianFont(9.1f, FontStyle.Bold);
        _prerequisiteDetail.Margin = new Padding(0, 3, 0, 6);

        grid.Controls.Add(_actionTitle, 0, 0);
        grid.Controls.Add(_actionDescription, 0, 1);
        grid.Controls.Add(BuildLifecycleDetails(), 0, 2);
        grid.Controls.Add(_prerequisiteDetail, 0, 3);
        grid.Controls.Add(ActionFlow(_primaryButton, _repairButton, _removeButton), 0, 4);
        card.Controls.Add(grid);
        return card;
    }

    private Control BuildLifecycleDetails()
    {
        var details = new TableLayoutPanel
        {
            Dock = DockStyle.Fill,
            ColumnCount = 2,
            RowCount = 4,
            BackColor = SurfaceColor,
            Padding = new Padding(0, 3, 0, 3)
        };
        details.ColumnStyles.Add(new ColumnStyle(SizeType.Absolute, 120));
        details.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 100));
        for (var i = 0; i < 4; i++) details.RowStyles.Add(new RowStyle(SizeType.Percent, 25));
        AddDetail(details, 0, "Runtime", _runtimeDetail);
        AddDetail(details, 1, "Print Agent", _printDetail);
        AddDetail(details, 2, "نسخه نصب‌شده", _versionDetail);
        AddDetail(details, 3, "اتصال", _connectionDetail);
        return details;
    }

    private void AddDetail(TableLayoutPanel parent, int row, string caption, Label value)
    {
        parent.Controls.Add(new Label
        {
            AutoSize = true,
            Text = caption,
            ForeColor = MutedColor,
            Anchor = AnchorStyles.Right,
            Margin = new Padding(0, 4, 0, 0)
        }, 0, row);
        value.Anchor = AnchorStyles.Right;
        parent.Controls.Add(value, 1, row);
    }

    private Control BuildPairCard()
    {
        var card = Card();
        card.Margin = new Padding(4, 0, 4, 0);
        card.Padding = new Padding(16, 13, 16, 12);

        var grid = new TableLayoutPanel { Dock = DockStyle.Fill, ColumnCount = 1, RowCount = 7, BackColor = SurfaceColor };
        grid.RowStyles.Add(new RowStyle(SizeType.AutoSize));
        grid.RowStyles.Add(new RowStyle(SizeType.AutoSize));
        grid.RowStyles.Add(new RowStyle(SizeType.AutoSize));
        grid.RowStyles.Add(new RowStyle(SizeType.AutoSize));
        grid.RowStyles.Add(new RowStyle(SizeType.AutoSize));
        grid.RowStyles.Add(new RowStyle(SizeType.Percent, 100));
        grid.RowStyles.Add(new RowStyle(SizeType.AutoSize));

        grid.Controls.Add(new Label
        {
            AutoSize = true,
            Text = "اتصال به Local Web",
            ForeColor = BrandDarkColor,
            Font = PersianFont(13.5f, FontStyle.Bold)
        }, 0, 0);

        var help = new Label
        {
            AutoSize = true,
            Text = "یک کد کوتاه‌عمر از Local Web وارد کنید. اتصال مستقل است و سرویس‌ها را دوباره نصب نمی‌کند؛ فقط تنظیمات اتصال اعمال و سرویس‌های لازم restart می‌شوند.",
            ForeColor = MutedColor,
            Font = PersianFont(9.3f),
            TextAlign = ContentAlignment.TopRight,
            MaximumSize = new Size(420, 0),
            Margin = new Padding(0, 5, 0, 7)
        };
        grid.Controls.Add(help, 0, 1);
        grid.Controls.Add(Field("آدرس Local Web", _localWebUrl), 0, 2);
        grid.Controls.Add(Field("کد اتصال", _pairingCode), 0, 3);
        grid.Controls.Add(_startRuntime, 0, 4);
        grid.Controls.Add(new Panel { Dock = DockStyle.Fill, BackColor = SurfaceColor }, 0, 5);
        grid.Controls.Add(ActionFlow(_pairButton), 0, 6);
        card.Controls.Add(grid);
        return card;
    }

    private Control BuildFooter()
    {
        var footer = new TableLayoutPanel
        {
            Dock = DockStyle.Fill,
            ColumnCount = 3,
            RowCount = 1,
            Padding = new Padding(2, 8, 2, 0),
            BackColor = CanvasColor,
            RightToLeft = RightToLeft.Yes
        };
        footer.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 100));
        footer.ColumnStyles.Add(new ColumnStyle(SizeType.Absolute, 130));
        footer.ColumnStyles.Add(new ColumnStyle(SizeType.AutoSize));
        _footerStatus.ForeColor = MutedColor;
        footer.Controls.Add(_footerStatus, 0, 0);
        footer.Controls.Add(_progress, 1, 0);
        footer.Controls.Add(ActionFlow(_refreshButton, _supportButton, _logsButton, _detailsButton), 2, 0);
        return footer;
    }

    private Control Field(string caption, Control input)
    {
        var field = new TableLayoutPanel
        {
            Dock = DockStyle.Top,
            AutoSize = true,
            ColumnCount = 1,
            RowCount = 2,
            Margin = new Padding(0, 2, 0, 4),
            BackColor = SurfaceColor
        };
        field.Controls.Add(new Label
        {
            AutoSize = true,
            Text = caption,
            ForeColor = TextColor,
            Font = PersianFont(9f, FontStyle.Bold),
            Anchor = AnchorStyles.Right
        }, 0, 0);
        input.Margin = new Padding(0, 3, 0, 0);
        input.MinimumSize = new Size(0, 30);
        field.Controls.Add(input, 0, 1);
        return field;
    }

    private static Panel Card() => new()
    {
        Dock = DockStyle.Fill,
        BackColor = SurfaceColor,
        BorderStyle = BorderStyle.FixedSingle
    };

    private FlowLayoutPanel ActionFlow(params Control[] controls)
    {
        var flow = new FlowLayoutPanel
        {
            Dock = DockStyle.Fill,
            AutoSize = true,
            FlowDirection = FlowDirection.RightToLeft,
            WrapContents = false,
            RightToLeft = RightToLeft.Yes,
            BackColor = Color.Transparent,
            Padding = new Padding(0, 2, 0, 0),
            Margin = new Padding(0)
        };
        foreach (var control in controls)
        {
            control.Margin = new Padding(6, 0, 0, 0);
            flow.Controls.Add(control);
        }
        return flow;
    }

    private void StyleButtons()
    {
        PrimaryButton(_primaryButton);
        PrimaryButton(_pairButton);
        SecondaryButton(_repairButton);
        DangerButton(_removeButton);
        SecondaryButton(_refreshButton);
        SecondaryButton(_supportButton);
        SecondaryButton(_logsButton);
        SecondaryButton(_detailsButton);

        foreach (var button in Descendants<Button>(this))
        {
            button.MinimumSize = new Size(button == _primaryButton || button == _pairButton ? 116 : 88, 34);
            button.Cursor = Cursors.Hand;
            button.Font = PersianFont(9.1f, FontStyle.Bold);
        }
    }

    private static void PrimaryButton(Button button)
    {
        button.UseVisualStyleBackColor = false;
        button.FlatStyle = FlatStyle.Flat;
        button.FlatAppearance.BorderSize = 0;
        button.BackColor = BrandColor;
        button.ForeColor = Color.White;
        button.Padding = new Padding(11, 4, 11, 4);
    }

    private static void SecondaryButton(Button button)
    {
        button.UseVisualStyleBackColor = false;
        button.FlatStyle = FlatStyle.Flat;
        button.FlatAppearance.BorderColor = BorderColor;
        button.BackColor = SurfaceColor;
        button.ForeColor = BrandDarkColor;
        button.Padding = new Padding(9, 4, 9, 4);
    }

    private static void DangerButton(Button button)
    {
        button.UseVisualStyleBackColor = false;
        button.FlatStyle = FlatStyle.Flat;
        button.FlatAppearance.BorderColor = Color.FromArgb(228, 194, 194);
        button.BackColor = Color.FromArgb(255, 246, 246);
        button.ForeColor = DangerColor;
        button.Padding = new Padding(9, 4, 9, 4);
    }

    private void WireEvents()
    {
        _refreshButton.Click += async (_, _) => await RefreshStateAsync();
        _primaryButton.Click += async (_, _) => await RunPrimaryAsync();
        _repairButton.Click += async (_, _) => await RunLifecycleAsync("repair");
        _removeButton.Click += async (_, _) => await RunLifecycleAsync("uninstall");
        _pairButton.Click += async (_, _) => await RunPairingAsync();
        _supportButton.Click += async (_, _) => await BuildSupportBundleAsync();
        _logsButton.Click += (_, _) => OpenFolder(Path.Combine(_dataRoot, "Logs"));
        _detailsButton.Click += (_, _) => ShowDetailsDialog();
    }

    private async Task RefreshStateAsync()
    {
        SetBusy(true, "در حال بررسی وضعیت…");
        try
        {
            ResolveExistingRoots();
            _state = await Task.Run(ReadState);
            RenderState(_state);
            _footerStatus.Text = "وضعیت به‌روز است.";
        }
        catch (Exception exception)
        {
            _footerStatus.Text = "بررسی وضعیت کامل نشد.";
            ShowError(exception.Message);
        }
        finally
        {
            SetBusy(false);
        }
    }

    private WindowsDashboardState ReadState()
    {
        var runtime = ReadService(RuntimeService);
        var print = ReadService(PrintService);
        var anyInstalled = runtime.Installed || print.Installed;
        var installedVersion = ReadInstalledVersion(anyInstalled);

        var paired = false;
        var localBaseUrl = "";
        var statePath = Path.Combine(_dataRoot, "setup", "windows-services-state.json");
        if (File.Exists(statePath))
        {
            try
            {
                using var document = JsonDocument.Parse(File.ReadAllText(statePath));
                paired = document.RootElement.TryGetProperty("paired", out var pairedNode) && pairedNode.ValueKind == JsonValueKind.True;
                if (document.RootElement.TryGetProperty("local_base_url", out var urlNode) && urlNode.ValueKind == JsonValueKind.String)
                    localBaseUrl = urlNode.GetString() ?? "";
            }
            catch { }
        }
        if (paired && string.IsNullOrWhiteSpace(localBaseUrl))
        {
            var runtimeConfigPath = Path.Combine(_dataRoot, "runtime", "runtime-config.json");
            if (File.Exists(runtimeConfigPath))
            {
                try
                {
                    using var document = JsonDocument.Parse(File.ReadAllText(runtimeConfigPath));
                    if (document.RootElement.TryGetProperty("localBaseUrl", out var urlNode) && urlNode.ValueKind == JsonValueKind.String)
                        localBaseUrl = urlNode.GetString() ?? "";
                }
                catch { }
            }
        }

        var prerequisite = ReadVcRuntime();
        return new WindowsDashboardState(
            ResolveInstallState(runtime.Installed, print.Installed, installedVersion, _currentVersion),
            installedVersion,
            runtime.Installed,
            runtime.Running,
            print.Installed,
            print.Running,
            paired,
            localBaseUrl,
            prerequisite.Ready,
            prerequisite.Version);
    }

    private void RenderState(WindowsDashboardState state)
    {
        _installStatus.Text = state.InstallState switch
        {
            WindowsInstallState.NotInstalled => "نصب نشده",
            WindowsInstallState.Partial => "نیاز به تعمیر",
            WindowsInstallState.Upgrade => $"{state.InstalledVersion}  ←  {_currentVersion}",
            WindowsInstallState.Newer => $"نسخه {state.InstalledVersion}",
            _ => $"نسخه {state.InstalledVersion}"
        };
        _installStatus.ForeColor = state.InstallState switch
        {
            WindowsInstallState.Current => GoodColor,
            WindowsInstallState.Upgrade => WarningColor,
            WindowsInstallState.NotInstalled => MutedColor,
            _ => DangerColor
        };

        var expectedHealthy = state.PrintRunning && (state.RuntimeRunning || !state.Paired);
        _servicesStatus.Text = !state.RuntimeInstalled && !state.PrintInstalled
            ? "—"
            : expectedHealthy
                ? state.Paired ? "هر دو فعال" : "Print Agent فعال"
                : "نیاز به بررسی";
        _servicesStatus.ForeColor = !state.RuntimeInstalled && !state.PrintInstalled
            ? MutedColor
            : expectedHealthy ? GoodColor : WarningColor;

        _connectionStatus.Text = !state.RuntimeInstalled || !state.PrintInstalled
            ? "بعد از نصب"
            : state.Paired ? "متصل" : "متصل نیست";
        _connectionStatus.ForeColor = state.Paired
            ? GoodColor
            : state.RuntimeInstalled && state.PrintInstalled ? WarningColor : MutedColor;

        _runtimeDetail.Text = state.RuntimeInstalled
            ? state.RuntimeRunning ? "نصب شده • در حال اجرا" : "نصب شده • متوقف"
            : "نصب نشده";
        _printDetail.Text = state.PrintInstalled
            ? state.PrintRunning ? "نصب شده • در حال اجرا" : "نصب شده • متوقف"
            : "نصب نشده";
        _versionDetail.Text = string.IsNullOrWhiteSpace(state.InstalledVersion) ? "قابل تشخیص نیست" : state.InstalledVersion;
        _versionDetail.RightToLeft = RightToLeft.No;
        _connectionDetail.Text = state.Paired ? "متصل" : "متصل نیست";

        _prerequisiteDetail.Text = state.PrerequisiteReady
            ? $"پیش‌نیاز ویندوز آماده است • Visual C++ {state.PrerequisiteVersion}"
            : "پیش‌نیاز Visual C++ آماده نیست؛ نصب یا به‌روزرسانی متوقف است.";
        _prerequisiteDetail.ForeColor = state.PrerequisiteReady ? GoodColor : DangerColor;

        _primaryButton.Visible = true;
        _repairButton.Visible = state.RuntimeInstalled || state.PrintInstalled;
        _removeButton.Visible = state.RuntimeInstalled || state.PrintInstalled;

        var canPair = state.InstallState == WindowsInstallState.Current && state.RuntimeInstalled && state.PrintInstalled;
        _pairButton.Enabled = canPair;
        _pairButton.Text = state.Paired ? "نوسازی اتصال" : "اتصال به Local Web";
        _localWebUrl.Enabled = canPair;
        _pairingCode.Enabled = canPair;
        _startRuntime.Enabled = canPair;
        if (state.Paired && !string.IsNullOrWhiteSpace(state.LocalBaseUrl)) _localWebUrl.Text = state.LocalBaseUrl;

        switch (state.InstallState)
        {
            case WindowsInstallState.NotInstalled:
                _actionTitle.Text = "سرویس‌های سکنا هنوز نصب نشده‌اند";
                _actionDescription.Text = "Runtime و Print Agent نصب می‌شوند. Local Web و داده‌های کسب‌وکار دست‌کاری نمی‌شوند. اتصال Local Web بعد از نصب، جداگانه انجام می‌شود.";
                _primaryButton.Text = "نصب سرویس‌ها";
                _repairButton.Visible = false;
                break;

            case WindowsInstallState.Upgrade:
                _actionTitle.Text = "به‌روزرسانی آماده است";
                _actionDescription.Text = $"نسخه نصب‌شده {state.InstalledVersion} است و این بسته نسخه {_currentVersion}. فقط Windows Services به‌روزرسانی می‌شود و داده‌ها و اتصال موجود حفظ می‌شوند.";
                _primaryButton.Text = $"به‌روزرسانی به {_currentVersion}";
                break;

            case WindowsInstallState.Current:
                _actionTitle.Text = "Windows Services به‌روز است";
                _actionDescription.Text = state.Paired
                    ? "نسخه نصب‌شده به‌روز و اتصال Local Web برقرار است. در صورت مشکل از تعمیر نصب یا بسته عیب‌یابی استفاده کنید."
                    : "نسخه نصب‌شده به‌روز است. مرحله بعد فقط اتصال به Local Web است؛ Pairing دیگر نصب یا جایگزینی فایل‌های سرویس را اجرا نمی‌کند.";
                _primaryButton.Visible = false;
                break;

            case WindowsInstallState.Newer:
                _actionTitle.Text = "نسخه جدیدتری روی سیستم نصب است";
                _actionDescription.Text = $"نسخه نصب‌شده {state.InstalledVersion} از این بسته ({_currentVersion}) جدیدتر است. Downgrade خودکار مسدود شده است.";
                _primaryButton.Visible = false;
                _repairButton.Visible = false;
                break;

            default:
                _actionTitle.Text = "نصب ناقص یا نسخه نامشخص است";
                _actionDescription.Text = "یکی از سرویس‌ها یا اطلاعات نسخه کامل نیست. تعمیر نصب را اجرا کنید؛ اگر مشکل باقی ماند بسته عیب‌یابی بسازید.";
                _primaryButton.Visible = false;
                break;
        }
    }

    internal void ApplyLayoutTestScenario()
    {
        var state = new WindowsDashboardState(
            WindowsInstallState.Upgrade,
            "1.0.9",
            true,
            true,
            true,
            true,
            true,
            "http://127.0.0.1:18080/",
            true,
            "14.44.35211");
        _state = state;
        RenderState(state);
    }

    private async Task RunPrimaryAsync()
    {
        if (_state is null) return;
        if (!_state.PrerequisiteReady)
        {
            ShowError("Microsoft Visual C++ x64 Runtime آماده نیست. ابتدا پیش‌نیاز را نصب کنید.");
            return;
        }
        if (_state.InstallState is WindowsInstallState.NotInstalled or WindowsInstallState.Upgrade)
            await RunLifecycleAsync("install");
    }

    private async Task RunLifecycleAsync(string mode)
    {
        if (mode != "uninstall" && _state is not null && !_state.PrerequisiteReady)
        {
            ShowError("پیش‌نیاز Visual C++ آماده نیست.");
            return;
        }

        var message = mode switch
        {
            "repair" => "فایل‌های Runtime و Print Agent دوباره اعمال می‌شوند. داده‌ها و اتصال موجود حفظ می‌شوند. ادامه می‌دهید؟",
            "uninstall" => "دو سرویس Runtime و Print Agent حذف می‌شوند؛ داده‌ها و Local Web باقی می‌مانند. ادامه می‌دهید؟",
            _ when _state?.InstallState == WindowsInstallState.Upgrade => $"نسخه {_state.InstalledVersion} به {_currentVersion} به‌روزرسانی می‌شود. ادامه می‌دهید؟",
            _ => "Windows Services نصب می‌شود. ادامه می‌دهید؟"
        };

        var result = MessageBox.Show(
            this,
            message,
            "تأیید عملیات",
            MessageBoxButtons.YesNo,
            mode == "uninstall" ? MessageBoxIcon.Warning : MessageBoxIcon.Information,
            mode == "uninstall" ? MessageBoxDefaultButton.Button2 : MessageBoxDefaultButton.Button1,
            MessageBoxOptions.RtlReading | MessageBoxOptions.RightAlign);
        if (result != DialogResult.Yes) return;

        SetBusy(true, "در حال اجرای عملیات…");
        string? planPath = null;
        try
        {
            planPath = Path.Combine(Path.GetTempPath(), "sokna-services-plan-" + Guid.NewGuid().ToString("N") + ".json");
            var plan = new Dictionary<string, object?>
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
            await File.WriteAllTextAsync(planPath, JsonSerializer.Serialize(plan, new JsonSerializerOptions { WriteIndented = true }));

            var host = Path.Combine(AppContext.BaseDirectory, "SoknaSetupHost.exe");
            var exitCode = await RunElevatedAsync(host, new[] { "--plan-file", planPath });
            if (exitCode != 0) throw new InvalidOperationException($"عملیات با کد {exitCode} متوقف شد. برای جزئیات، لاگ نصب را بررسی کنید.");

            _footerStatus.Text = mode switch
            {
                "uninstall" => "سرویس‌ها حذف شدند.",
                "repair" => "تعمیر نصب کامل شد.",
                _ => "نصب یا به‌روزرسانی کامل شد."
            };
            await RefreshStateAsync();
        }
        catch (Win32Exception exception) when (exception.NativeErrorCode == 1223)
        {
            _footerStatus.Text = "درخواست Administrator لغو شد.";
        }
        catch (Exception exception)
        {
            ShowError(exception.Message);
        }
        finally
        {
            if (planPath is not null) try { File.Delete(planPath); } catch { }
            SetBusy(false);
        }
    }

    private async Task RunPairingAsync()
    {
        var code = _pairingCode.Text.Trim();
        var baseUrl = _localWebUrl.Text.Trim();
        if (!Regex.IsMatch(code, "^ws1_[a-f0-9]{24}_[a-f0-9]{48}$"))
        {
            ShowError("کد اتصال معتبر نیست. یک کد تازه از Local Web بسازید.");
            return;
        }
        if (!IsLoopbackOrigin(baseUrl))
        {
            ShowError("آدرس Local Web باید یک origin محلی باشد؛ مانند http://127.0.0.1:18080/.");
            return;
        }

        SetBusy(true, "در حال اتصال به Local Web…");
        string? planPath = null;
        try
        {
            planPath = Path.Combine(Path.GetTempPath(), "sokna-pair-plan-" + Guid.NewGuid().ToString("N") + ".json");
            var plan = new Dictionary<string, object?>
            {
                ["format"] = "sokna-windows-services-pair-plan-v1",
                ["schema_version"] = 1,
                ["install_root"] = _installRoot,
                ["data_root"] = _dataRoot,
                ["pairing_base_url"] = baseUrl,
                ["pairing_code"] = code,
                ["start_when_paired"] = _startRuntime.Checked
            };
            await File.WriteAllTextAsync(planPath, JsonSerializer.Serialize(plan, new JsonSerializerOptions { WriteIndented = true }));

            var powershell = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.System), "WindowsPowerShell", "v1.0", "powershell.exe");
            var pairScript = Path.Combine(AppContext.BaseDirectory, "pair-windows-services.ps1");
            var exitCode = await RunElevatedAsync(powershell, new[]
            {
                "-NoProfile", "-NonInteractive", "-ExecutionPolicy", "Bypass",
                "-File", pairScript, "-PlanFile", planPath
            });
            if (exitCode != 0) throw new InvalidOperationException($"اتصال با کد {exitCode} متوقف شد. در صورت تکرار، بسته عیب‌یابی بسازید.");

            _pairingCode.Clear();
            _footerStatus.Text = "اتصال Local Web انجام شد؛ سرویس‌ها دوباره نصب نشدند.";
            await RefreshStateAsync();
        }
        catch (Win32Exception exception) when (exception.NativeErrorCode == 1223)
        {
            _footerStatus.Text = "درخواست Administrator لغو شد.";
        }
        catch (Exception exception)
        {
            ShowError(exception.Message);
        }
        finally
        {
            if (planPath is not null) try { File.Delete(planPath); } catch { }
            SetBusy(false);
        }
    }

    private async Task BuildSupportBundleAsync()
    {
        SetBusy(true, "در حال ساخت بسته عیب‌یابی…");
        try
        {
            var powershell = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.System), "WindowsPowerShell", "v1.0", "powershell.exe");
            var script = Path.Combine(AppContext.BaseDirectory, "collect-support.ps1");
            var outputRoot = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.DesktopDirectory), "SOKNA-Support");
            Directory.CreateDirectory(outputRoot);
            var exitCode = await RunElevatedAsync(powershell, new[]
            {
                "-NoProfile", "-NonInteractive", "-ExecutionPolicy", "Bypass",
                "-File", script, "-DataRoot", _dataRoot, "-OutputRoot", outputRoot
            });
            if (exitCode != 0) throw new InvalidOperationException("ساخت بسته عیب‌یابی کامل نشد.");
            OpenFolder(outputRoot);
            _footerStatus.Text = "بسته عیب‌یابی روی Desktop ساخته شد.";
        }
        catch (Win32Exception exception) when (exception.NativeErrorCode == 1223)
        {
            _footerStatus.Text = "درخواست Administrator لغو شد.";
        }
        catch (Exception exception)
        {
            ShowError(exception.Message);
        }
        finally
        {
            SetBusy(false);
        }
    }

    private void ResolveExistingRoots()
    {
        var printExecutable = ExtractExecutablePath(ReadServiceImage(PrintService));
        if (!string.IsNullOrWhiteSpace(printExecutable))
        {
            try
            {
                var serviceDirectory = Path.GetDirectoryName(printExecutable);
                var printRoot = serviceDirectory is null ? null : Directory.GetParent(serviceDirectory)?.FullName;
                var installRoot = printRoot is null ? null : Directory.GetParent(printRoot)?.FullName;
                if (!string.IsNullOrWhiteSpace(installRoot)) _installRoot = Path.GetFullPath(installRoot);
            }
            catch { }
        }
        else
        {
            var runtimeExecutable = ExtractExecutablePath(ReadServiceImage(RuntimeService));
            if (!string.IsNullOrWhiteSpace(runtimeExecutable))
            {
                try
                {
                    var runtimeDirectory = Path.GetDirectoryName(runtimeExecutable);
                    var installRoot = runtimeDirectory is null ? null : Directory.GetParent(runtimeDirectory)?.FullName;
                    if (!string.IsNullOrWhiteSpace(installRoot)) _installRoot = Path.GetFullPath(installRoot);
                }
                catch { }
            }
        }

        var printDataRoot = ReadRegistryString(@"SOFTWARE\Sokna\Local\PrintWorker", "DataRoot");
        if (!string.IsNullOrWhiteSpace(printDataRoot))
        {
            try
            {
                var parent = Directory.GetParent(Path.GetFullPath(printDataRoot))?.FullName;
                if (!string.IsNullOrWhiteSpace(parent)) _dataRoot = parent;
            }
            catch { }
        }
        else
        {
            var runtimeImage = ReadServiceImage(RuntimeService);
            var match = Regex.Match(runtimeImage, "--config\\s+(?:\"([^\"]+)\"|(\\S+))", RegexOptions.IgnoreCase);
            if (match.Success)
            {
                var configPath = match.Groups[1].Success ? match.Groups[1].Value : match.Groups[2].Value;
                try
                {
                    var runtimeDirectory = Path.GetDirectoryName(Path.GetFullPath(configPath));
                    var dataRoot = runtimeDirectory is null ? null : Directory.GetParent(runtimeDirectory)?.FullName;
                    if (!string.IsNullOrWhiteSpace(dataRoot)) _dataRoot = dataRoot;
                }
                catch { }
            }
        }
    }

    private string ReadInstalledVersion(bool anyInstalled)
    {
        var statePath = Path.Combine(_dataRoot, "setup", "windows-services-state.json");
        if (File.Exists(statePath))
        {
            try
            {
                using var document = JsonDocument.Parse(File.ReadAllText(statePath));
                if (document.RootElement.TryGetProperty("package_version", out var versionNode) &&
                    versionNode.ValueKind == JsonValueKind.String &&
                    !string.IsNullOrWhiteSpace(versionNode.GetString()))
                    return NormalizeVersion(versionNode.GetString()!);
            }
            catch { }
        }

        if (anyInstalled)
        {
            var legacyMarker = Path.Combine(
                Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData),
                "SOKNA", "setup", "previous-windows-services-version.txt");
            if (File.Exists(legacyMarker))
            {
                try
                {
                    var value = File.ReadAllText(legacyMarker).Trim();
                    if (TryVersion(value, out _)) return NormalizeVersion(value);
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

    private void ShowDetailsDialog()
    {
        var anyInstalled = _state?.RuntimeInstalled == true || _state?.PrintInstalled == true;
        using var dialog = new Form
        {
            Text = "جزئیات Windows Services",
            StartPosition = FormStartPosition.CenterParent,
            ClientSize = new Size(650, anyInstalled ? 250 : 305),
            MinimizeBox = false,
            MaximizeBox = false,
            ShowInTaskbar = false,
            RightToLeft = RightToLeft.Yes,
            BackColor = SurfaceColor,
            Font = PersianFont(9.8f)
        };

        var installPath = ReadOnlyPath(_installRoot);
        var dataPath = new TextBox
        {
            Dock = DockStyle.Top,
            Text = _dataRoot,
            ReadOnly = anyInstalled,
            RightToLeft = RightToLeft.No,
            TextAlign = HorizontalAlignment.Left
        };
        var save = new Button { Text = "ذخیره", AutoSize = true, Visible = !anyInstalled };
        SecondaryButton(save);
        save.Click += (_, _) =>
        {
            try
            {
                var candidate = dataPath.Text.Trim();
                if (string.IsNullOrWhiteSpace(candidate) || !Path.IsPathFullyQualified(candidate)) throw new InvalidOperationException("مسیر داده باید کامل باشد.");
                _dataRoot = Path.GetFullPath(candidate);
                dialog.DialogResult = DialogResult.OK;
                dialog.Close();
            }
            catch (Exception exception)
            {
                MessageBox.Show(dialog, Safe(exception.Message), "مسیر نامعتبر", MessageBoxButtons.OK, MessageBoxIcon.Warning, MessageBoxDefaultButton.Button1, MessageBoxOptions.RtlReading | MessageBoxOptions.RightAlign);
            }
        };

        var grid = new TableLayoutPanel { Dock = DockStyle.Fill, Padding = new Padding(18), ColumnCount = 1, RowCount = anyInstalled ? 6 : 8, BackColor = SurfaceColor };
        grid.Controls.Add(new Label { AutoSize = true, Text = "مسیر نصب سرویس‌ها", Font = PersianFont(9f, FontStyle.Bold) });
        grid.Controls.Add(installPath);
        grid.Controls.Add(new Label { AutoSize = true, Text = "مسیر داده و لاگ‌ها", Font = PersianFont(9f, FontStyle.Bold), Margin = new Padding(0, 8, 0, 0) });
        grid.Controls.Add(dataPath);
        grid.Controls.Add(new Label { AutoSize = true, Text = $"نسخه این بسته: {_currentVersion}", Margin = new Padding(0, 8, 0, 0) });
        if (!anyInstalled)
        {
            grid.Controls.Add(new Label { AutoSize = true, Text = "مسیر داده را فقط پیش از اولین نصب می‌توانید تغییر دهید.", ForeColor = MutedColor, Margin = new Padding(0, 6, 0, 0) });
            grid.Controls.Add(ActionFlow(save));
        }
        dialog.Controls.Add(grid);
        dialog.ShowDialog(this);
    }

    private void SetBusy(bool busy, string? message = null)
    {
        _progress.Visible = busy;
        foreach (var button in Descendants<Button>(this)) button.Enabled = !busy;
        if (!busy && _state is not null)
        {
            var canPair = _state.InstallState == WindowsInstallState.Current && _state.RuntimeInstalled && _state.PrintInstalled;
            _pairButton.Enabled = canPair;
            _localWebUrl.Enabled = canPair;
            _pairingCode.Enabled = canPair;
            _startRuntime.Enabled = canPair;
        }
        if (!string.IsNullOrWhiteSpace(message)) _footerStatus.Text = message;
        UseWaitCursor = busy;
    }

    private void ShowError(string message)
    {
        _footerStatus.Text = "عملیات کامل نشد.";
        MessageBox.Show(this, Safe(message), "خطا", MessageBoxButtons.OK, MessageBoxIcon.Error, MessageBoxDefaultButton.Button1, MessageBoxOptions.RtlReading | MessageBoxOptions.RightAlign);
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
        _persianFontFamily = _privateFonts.Families.FirstOrDefault();
    }

    private Font PersianFont(float size, FontStyle style = FontStyle.Regular)
    {
        if (_persianFontFamily is not null)
        {
            try { return new Font(_persianFontFamily, size, style, GraphicsUnit.Point); } catch { }
        }
        foreach (var family in new[] { "Vazirmatn UI", "Vazirmatn", "Tahoma", "Segoe UI" })
        {
            try
            {
                using var probe = new Font(family, size, style, GraphicsUnit.Point);
                if (string.Equals(probe.Name, family, StringComparison.OrdinalIgnoreCase))
                    return new Font(family, size, style, GraphicsUnit.Point);
            }
            catch { }
        }
        return new Font(SystemFonts.MessageBoxFont.FontFamily, size, style, GraphicsUnit.Point);
    }

    private void ApplyPersianFont(Control root)
    {
        foreach (Control child in root.Controls)
        {
            if (child is not TextBox) child.Font = PersianFont(child.Font.Size, child.Font.Style);
            ApplyPersianFont(child);
        }
    }

    private void FitToWorkingArea()
    {
        var workingArea = Screen.FromControl(this).WorkingArea;
        var width = Math.Min(1100, Math.Max(960, workingArea.Width - 28));
        var height = Math.Min(660, Math.Max(600, workingArea.Height - 28));
        ClientSize = new Size(Math.Min(width, workingArea.Width), Math.Min(height, workingArea.Height));
        Location = new Point(
            workingArea.Left + Math.Max(0, (workingArea.Width - Width) / 2),
            workingArea.Top + Math.Max(0, (workingArea.Height - Height) / 2));
    }

    private static (bool Installed, bool Running) ReadService(string serviceName)
    {
        try
        {
            using var registry = Registry.LocalMachine.OpenSubKey(@"SYSTEM\CurrentControlSet\Services\" + serviceName, false);
            if (registry is null) return (false, false);
            var serviceControl = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.System), "sc.exe");
            var startInfo = new ProcessStartInfo(serviceControl) { UseShellExecute = false, RedirectStandardOutput = true, CreateNoWindow = true };
            startInfo.ArgumentList.Add("query");
            startInfo.ArgumentList.Add(serviceName);
            using var process = Process.Start(startInfo);
            if (process is null) return (true, false);
            var output = process.StandardOutput.ReadToEnd();
            process.WaitForExit();
            return (true, process.ExitCode == 0 && output.Contains("RUNNING", StringComparison.OrdinalIgnoreCase));
        }
        catch { return (false, false); }
    }

    private static string ReadServiceImage(string serviceName)
    {
        try
        {
            using var registry = Registry.LocalMachine.OpenSubKey(@"SYSTEM\CurrentControlSet\Services\" + serviceName, false);
            return Convert.ToString(registry?.GetValue("ImagePath")) ?? "";
        }
        catch { return ""; }
    }

    private static string ReadRegistryString(string path, string valueName)
    {
        foreach (var view in new[] { RegistryView.Registry64, RegistryView.Registry32 })
        {
            try
            {
                using var root = RegistryKey.OpenBaseKey(RegistryHive.LocalMachine, view);
                using var key = root.OpenSubKey(path, false);
                var value = Convert.ToString(key?.GetValue(valueName));
                if (!string.IsNullOrWhiteSpace(value)) return value;
            }
            catch { }
        }
        return "";
    }

    private static string ExtractExecutablePath(string command)
    {
        var value = (command ?? "").Trim();
        if (value.StartsWith('"'))
        {
            var end = value.IndexOf('"', 1);
            return end > 1 ? value[1..end] : "";
        }
        var exeIndex = value.IndexOf(".exe", StringComparison.OrdinalIgnoreCase);
        if (exeIndex >= 0) return value[..(exeIndex + 4)];
        return value.Split(' ', StringSplitOptions.RemoveEmptyEntries).FirstOrDefault() ?? "";
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

    private static WindowsInstallState ResolveInstallState(bool runtimeInstalled, bool printInstalled, string installedVersion, string currentVersion)
    {
        if (!runtimeInstalled && !printInstalled) return WindowsInstallState.NotInstalled;
        if (!runtimeInstalled || !printInstalled) return WindowsInstallState.Partial;
        if (!TryVersion(installedVersion, out var installed) || !TryVersion(currentVersion, out var current)) return WindowsInstallState.Partial;
        var comparison = installed.CompareTo(current);
        if (comparison < 0) return WindowsInstallState.Upgrade;
        if (comparison > 0) return WindowsInstallState.Newer;
        return WindowsInstallState.Current;
    }

    private static bool TryVersion(string? value, out Version version)
    {
        var match = Regex.Match(value ?? "", "(\\d+)\\.(\\d+)(?:\\.(\\d+))?(?:\\.(\\d+))?");
        if (!match.Success)
        {
            version = new Version(0, 0);
            return false;
        }
        var normalized = string.Join('.', new[]
        {
            match.Groups[1].Value,
            match.Groups[2].Value,
            match.Groups[3].Success ? match.Groups[3].Value : "0",
            match.Groups[4].Success ? match.Groups[4].Value : "0"
        });
        if (Version.TryParse(normalized, out var parsed) && parsed is not null)
        {
            version = parsed;
            return true;
        }
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
        return uri.Port is >= 1024 and <= 65535 &&
               uri.AbsolutePath == "/" &&
               string.IsNullOrEmpty(uri.Query) &&
               string.IsNullOrEmpty(uri.Fragment) &&
               string.IsNullOrEmpty(uri.UserInfo);
    }

    private static async Task<int> RunElevatedAsync(string executable, IEnumerable<string> arguments)
    {
        if (!File.Exists(executable)) throw new FileNotFoundException("فایل لازم برای عملیات پیدا نشد.", executable);
        var startInfo = new ProcessStartInfo(executable)
        {
            UseShellExecute = true,
            Verb = "runas",
            WorkingDirectory = AppContext.BaseDirectory
        };
        foreach (var argument in arguments) startInfo.ArgumentList.Add(argument);
        using var process = Process.Start(startInfo) ?? throw new InvalidOperationException("اجرای عملیات شروع نشد.");
        await process.WaitForExitAsync();
        return process.ExitCode;
    }

    private static TextBox ReadOnlyPath(string value) => new()
    {
        Dock = DockStyle.Top,
        ReadOnly = true,
        Text = value,
        RightToLeft = RightToLeft.No,
        TextAlign = HorizontalAlignment.Left
    };

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

    private Icon? LoadIcon()
    {
        try
        {
            var path = Path.Combine(AppContext.BaseDirectory, "Sokna.ico");
            return File.Exists(path) ? new Icon(path) : null;
        }
        catch { return null; }
    }

    private void ApplyWindowIcon()
    {
        try
        {
            var path = Path.Combine(AppContext.BaseDirectory, "Sokna.ico");
            if (!File.Exists(path)) return;
            using var source = new Icon(path);
            var clone = (Icon)source.Clone();
            Icon = clone;
            if (IsHandleCreated) TaskbarIdentity.SetWindowIcon(Handle, clone.Handle);
        }
        catch { }
    }

    private static string DisplayVersion()
    {
        try
        {
            var executable = Environment.ProcessPath;
            if (!string.IsNullOrWhiteSpace(executable))
            {
                var info = FileVersionInfo.GetVersionInfo(executable);
                var value = info.ProductVersion ?? info.FileVersion;
                if (!string.IsNullOrWhiteSpace(value)) return NormalizeVersion(value);
            }
        }
        catch { }
        return NormalizeVersion(Application.ProductVersion);
    }

    private static Label StatusValueLabel() => new()
    {
        AutoSize = true,
        Text = "در حال بررسی…",
        ForeColor = MutedColor,
        TextAlign = ContentAlignment.MiddleRight
    };

    private static Label DetailValueLabel() => new()
    {
        AutoSize = true,
        Text = "—",
        ForeColor = TextColor,
        TextAlign = ContentAlignment.MiddleRight
    };

    private static IEnumerable<T> Descendants<T>(Control root) where T : Control
    {
        foreach (Control child in root.Controls)
        {
            if (child is T typed) yield return typed;
            foreach (var nested in Descendants<T>(child)) yield return nested;
        }
    }
}

internal static class TaskbarIdentity
{
    private const uint WmSetIcon = 0x0080;
    private static readonly IntPtr IconSmall = IntPtr.Zero;
    private static readonly IntPtr IconBig = new(1);

    internal static void Apply()
    {
        try { _ = SetCurrentProcessExplicitAppUserModelID("SOKNA.WindowsServices.SetupUi"); } catch { }
    }

    internal static void SetWindowIcon(IntPtr window, IntPtr icon)
    {
        if (window == IntPtr.Zero || icon == IntPtr.Zero) return;
        try
        {
            _ = SendMessage(window, WmSetIcon, IconBig, icon);
            _ = SendMessage(window, WmSetIcon, IconSmall, icon);
        }
        catch { }
    }

    [DllImport("shell32.dll", CharSet = CharSet.Unicode)]
    private static extern int SetCurrentProcessExplicitAppUserModelID(string appId);

    [DllImport("user32.dll", CharSet = CharSet.Auto)]
    private static extern IntPtr SendMessage(IntPtr hWnd, uint msg, IntPtr wParam, IntPtr lParam);
}
