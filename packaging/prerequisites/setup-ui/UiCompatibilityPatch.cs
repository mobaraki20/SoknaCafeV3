using System.Reflection;
using System.Runtime.CompilerServices;

namespace Sokna.Prerequisites.Setup;

/// <summary>
/// Presentation-only shell for the legacy Prerequisites MainForm.
/// Existing controls and their event handlers are preserved, but the long scrolling form
/// is recomposed into a compact Persian RTL dashboard with fixed header/footer and tabs.
/// No infrastructure state, services, payloads or MariaDB Data are mutated here.
/// </summary>
internal static class UiCompatibilityPatch
{
    private static readonly ConditionalWeakTable<Form, object> Applied = new();
    private static readonly IMessageFilter MessageFilter = new ApplyWhenPumpingFilter();
    private static readonly Color Brand = Color.FromArgb(20, 99, 93);
    private static readonly Color Surface = Color.White;
    private static readonly Color Canvas = Color.FromArgb(246, 246, 243);
    private static readonly Color Muted = Color.FromArgb(93, 102, 104);

    [ModuleInitializer]
    internal static void Initialize()
    {
        // Production reaches Idle through Application.Run. Qualification uses Show +
        // DoEvents. Keep both paths so screenshot evidence exercises the same shell.
        Application.Idle += (_, _) => ApplyOpenForms();
        Application.AddMessageFilter(MessageFilter);
    }

    private static void ApplyOpenForms()
    {
        foreach (Form form in Application.OpenForms)
        {
            if (form.GetType() != typeof(MainForm) || Applied.TryGetValue(form, out _)) continue;
            Apply(form);
            Applied.Add(form, new object());
        }
    }

    private sealed class ApplyWhenPumpingFilter : IMessageFilter
    {
        public bool PreFilterMessage(ref Message m)
        {
            ApplyOpenForms();
            return false;
        }
    }

    private static void Apply(Form form)
    {
        RebuildPathAndPort(form);
        RebuildMariaDbCredentials(form);
        BuildDashboardShell(form);
        NormalizeVersionText(form);
    }

    private static void BuildDashboardShell(Form form)
    {
        if (form.Controls.Find("SoknaPrerequisitesDashboard", true).Length > 0) return;

        var modeBox = FindGroup(form, "حالت اجرا");
        var pathBox = FindGroup(form, "مسیر زیرساخت");
        var sourcesBox = FindGroup(form, "فایل‌های پیش‌نیاز");
        var dbBox = FindGroup(form, "MariaDB");
        var statusBox = FindGroup(form, "وضعیت و جزئیات");
        var modeHelp = Field<Label>(form, "_modeHelp");
        var progress = Field<ProgressBar>(form, "_progress");
        var progressText = Field<Label>(form, "_progressText");
        var run = Field<Button>(form, "_run");
        var analyze = Field<Button>(form, "_analyze");
        var logs = Field<Button>(form, "_logs");
        var support = Field<Button>(form, "_support");
        var cancel = Field<Button>(form, "_cancel");
        var install = Field<RadioButton>(form, "_install");
        var repair = Field<RadioButton>(form, "_repair");
        var recover = Field<RadioButton>(form, "_recover");
        var diagnostics = Descendants(form).OfType<Button>().FirstOrDefault(x => x.Name == "SoknaDiagnosticsButton");

        if (modeBox is null || pathBox is null || sourcesBox is null || dbBox is null || statusBox is null ||
            modeHelp is null || progress is null || progressText is null || run is null || analyze is null || logs is null ||
            support is null || cancel is null || install is null || repair is null || recover is null)
            return;

        foreach (var control in new Control[] { modeBox, pathBox, sourcesBox, dbBox, statusBox, modeHelp, progress, progressText, run, analyze, logs, support, cancel })
            Detach(control);
        if (diagnostics is not null) Detach(diagnostics);

        form.SuspendLayout();
        form.Controls.Clear();
        form.BackColor = Canvas;
        form.Padding = Padding.Empty;
        form.MinimumSize = new Size(900, 650);
        form.Text = $"سکنا | پیش‌نیازهای ویندوز — نسخه {SemanticVersion()}";

        var dashboard = new TableLayoutPanel
        {
            Name = "SoknaPrerequisitesDashboard",
            Dock = DockStyle.Fill,
            ColumnCount = 1,
            RowCount = 3,
            BackColor = Canvas,
            Margin = Padding.Empty,
            Padding = Padding.Empty,
            RightToLeft = RightToLeft.Yes,
            AutoScroll = false
        };
        dashboard.RowStyles.Add(new RowStyle(SizeType.Absolute, 86));
        dashboard.RowStyles.Add(new RowStyle(SizeType.Percent, 100));
        dashboard.RowStyles.Add(new RowStyle(SizeType.Absolute, 92));

        dashboard.Controls.Add(BuildHeader(), 0, 0);

        var tabs = new TabControl
        {
            Name = "SoknaPrerequisitesTabs",
            Dock = DockStyle.Fill,
            RightToLeft = RightToLeft.Yes,
            RightToLeftLayout = true,
            Padding = new Point(16, 6),
            Margin = new Padding(18, 10, 18, 6),
            Appearance = TabAppearance.Normal
        };

        var overviewTab = NewTab("وضعیت و اجرا", "SoknaOverviewTab");
        var sourcesTab = NewTab("فایل‌های پیش‌نیاز", "SoknaSourcesTab");
        var databaseTab = NewTab("نصب جدید / MariaDB", "SoknaDatabaseTab");
        var detailsTab = NewTab("جزئیات و گزارش", "SoknaDetailsTab");

        BuildOverview(overviewTab, modeBox, modeHelp, pathBox, install, repair, recover, databaseTab, tabs);
        BuildSources(sourcesTab, sourcesBox);
        BuildDatabase(databaseTab, dbBox);
        BuildDetails(detailsTab, statusBox);

        tabs.TabPages.AddRange([overviewTab, sourcesTab, databaseTab, detailsTab]);
        tabs.SelectedTab = overviewTab;
        dashboard.Controls.Add(tabs, 0, 1);

        dashboard.Controls.Add(BuildFooter(progress, progressText, run, analyze, diagnostics, logs, support, cancel), 0, 2);
        form.Controls.Add(dashboard);
        form.ResumeLayout(true);
        form.PerformLayout();
    }

    private static Control BuildHeader()
    {
        var header = new TableLayoutPanel
        {
            Name = "SoknaPrerequisitesHeader",
            Dock = DockStyle.Fill,
            BackColor = Brand,
            ColumnCount = 2,
            RowCount = 1,
            Padding = new Padding(22, 14, 22, 12),
            RightToLeft = RightToLeft.Yes,
            Margin = Padding.Empty
        };
        header.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 100));
        header.ColumnStyles.Add(new ColumnStyle(SizeType.AutoSize));

        var text = new TableLayoutPanel { Dock = DockStyle.Fill, AutoSize = true, ColumnCount = 1, RowCount = 2, BackColor = Brand, Margin = Padding.Empty };
        var title = new Label
        {
            Text = "مدیریت پیش‌نیازهای ویندوز سکنا",
            AutoSize = true,
            Dock = DockStyle.Fill,
            ForeColor = Color.White,
            Font = new Font(UiFontFamily(), 15.5f, FontStyle.Bold),
            TextAlign = ContentAlignment.MiddleRight,
            RightToLeft = RightToLeft.Yes
        };
        var subtitle = new Label
        {
            Text = "PHP • Apache • MariaDB — زیرساخت Local Web",
            AutoSize = true,
            Dock = DockStyle.Fill,
            ForeColor = Color.FromArgb(221, 238, 236),
            Font = new Font(UiFontFamily(), 9.5f, FontStyle.Regular),
            TextAlign = ContentAlignment.MiddleRight,
            RightToLeft = RightToLeft.Yes
        };
        text.Controls.Add(title, 0, 0);
        text.Controls.Add(subtitle, 0, 1);

        var version = new Label
        {
            Name = "SoknaVersionBadge",
            Text = $"نسخه {SemanticVersion()}",
            AutoSize = false,
            Size = new Size(104, 38),
            BackColor = Color.White,
            ForeColor = Brand,
            Font = new Font(UiFontFamily(), 9.5f, FontStyle.Bold),
            TextAlign = ContentAlignment.MiddleCenter,
            Anchor = AnchorStyles.Left | AnchorStyles.Top,
            Margin = new Padding(18, 6, 0, 0),
            RightToLeft = RightToLeft.Yes
        };

        header.Controls.Add(text, 0, 0);
        header.Controls.Add(version, 1, 0);
        return header;
    }

    private static void BuildOverview(TabPage page, GroupBox modeBox, Label modeHelp, GroupBox pathBox,
        RadioButton install, RadioButton repair, RadioButton recover, TabPage databaseTab, TabControl tabs)
    {
        var layout = new TableLayoutPanel
        {
            Name = "SoknaOverviewLayout",
            Dock = DockStyle.Fill,
            ColumnCount = 1,
            RowCount = 4,
            Padding = new Padding(14, 10, 14, 10),
            BackColor = Surface,
            AutoScroll = false,
            RightToLeft = RightToLeft.Yes
        };
        layout.RowStyles.Add(new RowStyle(SizeType.AutoSize));
        layout.RowStyles.Add(new RowStyle(SizeType.Absolute, 58));
        layout.RowStyles.Add(new RowStyle(SizeType.Percent, 100));
        layout.RowStyles.Add(new RowStyle(SizeType.Absolute, 34));

        modeBox.Dock = DockStyle.Top;
        modeBox.AutoSize = true;
        modeBox.Padding = new Padding(12, 10, 12, 10);
        modeBox.Margin = new Padding(0, 0, 0, 6);
        layout.Controls.Add(modeBox, 0, 0);

        modeHelp.Dock = DockStyle.Fill;
        modeHelp.AutoSize = false;
        modeHelp.AutoEllipsis = true;
        modeHelp.TextAlign = ContentAlignment.TopRight;
        modeHelp.ForeColor = Muted;
        modeHelp.Padding = new Padding(4, 4, 4, 2);
        modeHelp.Margin = Padding.Empty;
        layout.Controls.Add(modeHelp, 0, 1);

        pathBox.Dock = DockStyle.Fill;
        pathBox.AutoSize = false;
        pathBox.Margin = Padding.Empty;
        layout.Controls.Add(pathBox, 0, 2);

        var state = new Label
        {
            Name = "SoknaModeSummary",
            Dock = DockStyle.Fill,
            AutoSize = false,
            TextAlign = ContentAlignment.MiddleRight,
            RightToLeft = RightToLeft.Yes,
            Font = new Font(UiFontFamily(), 9.3f, FontStyle.Bold),
            ForeColor = Brand,
            Padding = new Padding(4, 0, 4, 0)
        };
        layout.Controls.Add(state, 0, 3);

        void RefreshModeUi()
        {
            state.Text = recover.Checked
                ? "بازیابی انتخاب شده است؛ فایل‌ها و Data موجود حفظ می‌شوند و سرویس‌ها دوباره ثبت می‌شوند."
                : repair.Checked
                    ? "تعمیر نصب موجود انتخاب شده است؛ Web و MariaDB Data حذف یا initialize نمی‌شوند."
                    : "نصب جدید انتخاب شده است؛ برای تعریف رمز اولیه MariaDB از تب «نصب جدید / MariaDB» استفاده کنید.";
            databaseTab.Enabled = install.Checked;
            if (!databaseTab.Enabled && tabs.SelectedTab == databaseTab) tabs.SelectedIndex = 0;
        }
        install.CheckedChanged += (_, _) => RefreshModeUi();
        repair.CheckedChanged += (_, _) => RefreshModeUi();
        recover.CheckedChanged += (_, _) => RefreshModeUi();
        RefreshModeUi();

        page.Controls.Add(layout);
    }

    private static void BuildSources(TabPage page, GroupBox sourcesBox)
    {
        var host = new Panel { Dock = DockStyle.Fill, Padding = new Padding(14, 12, 14, 12), BackColor = Surface, AutoScroll = false };
        sourcesBox.Dock = DockStyle.Fill;
        sourcesBox.AutoSize = false;
        sourcesBox.Margin = Padding.Empty;
        host.Controls.Add(sourcesBox);
        page.Controls.Add(host);
    }

    private static void BuildDatabase(TabPage page, GroupBox dbBox)
    {
        var layout = new TableLayoutPanel
        {
            Dock = DockStyle.Fill,
            ColumnCount = 1,
            RowCount = 2,
            Padding = new Padding(14, 12, 14, 12),
            BackColor = Surface,
            AutoScroll = false,
            RightToLeft = RightToLeft.Yes
        };
        layout.RowStyles.Add(new RowStyle(SizeType.AutoSize));
        layout.RowStyles.Add(new RowStyle(SizeType.Percent, 100));
        var intro = new Label
        {
            Text = "این بخش فقط در نصب جدید استفاده می‌شود. رمز در Log یا State ذخیره نمی‌شود و Repair/Recover هیچ Data موجودی را دوباره initialize نمی‌کند.",
            AutoSize = true,
            Dock = DockStyle.Top,
            RightToLeft = RightToLeft.Yes,
            TextAlign = ContentAlignment.TopRight,
            ForeColor = Muted,
            Padding = new Padding(4, 0, 4, 10)
        };
        dbBox.Dock = DockStyle.Top;
        dbBox.AutoSize = true;
        dbBox.Margin = Padding.Empty;
        layout.Controls.Add(intro, 0, 0);
        layout.Controls.Add(dbBox, 0, 1);
        page.Controls.Add(layout);
    }

    private static void BuildDetails(TabPage page, GroupBox statusBox)
    {
        var host = new Panel { Dock = DockStyle.Fill, Padding = new Padding(14, 12, 14, 12), BackColor = Surface, AutoScroll = false };
        statusBox.Dock = DockStyle.Fill;
        statusBox.AutoSize = false;
        statusBox.Margin = Padding.Empty;
        host.Controls.Add(statusBox);
        page.Controls.Add(host);
    }

    private static Control BuildFooter(ProgressBar progress, Label progressText, Button run, Button analyze,
        Button? diagnostics, Button logs, Button support, Button cancel)
    {
        var footer = new TableLayoutPanel
        {
            Name = "SoknaPrerequisitesFooter",
            Dock = DockStyle.Fill,
            ColumnCount = 1,
            RowCount = 3,
            Padding = new Padding(18, 5, 18, 8),
            BackColor = Color.White,
            RightToLeft = RightToLeft.Yes,
            Margin = Padding.Empty,
            AutoScroll = false
        };
        footer.RowStyles.Add(new RowStyle(SizeType.Absolute, 18));
        footer.RowStyles.Add(new RowStyle(SizeType.Absolute, 14));
        footer.RowStyles.Add(new RowStyle(SizeType.Percent, 100));

        progressText.Dock = DockStyle.Fill;
        progressText.AutoSize = false;
        progressText.TextAlign = ContentAlignment.MiddleRight;
        progressText.ForeColor = Muted;
        progressText.Padding = Padding.Empty;
        footer.Controls.Add(progressText, 0, 0);

        progress.Dock = DockStyle.Fill;
        progress.Margin = new Padding(0, 1, 0, 1);
        footer.Controls.Add(progress, 0, 1);

        var actions = new FlowLayoutPanel
        {
            Name = "SoknaFooterActions",
            Dock = DockStyle.Fill,
            FlowDirection = FlowDirection.RightToLeft,
            WrapContents = false,
            RightToLeft = RightToLeft.Yes,
            Padding = new Padding(0, 5, 0, 0),
            Margin = Padding.Empty,
            AutoScroll = false
        };

        StyleAction(run, primary: true);
        StyleAction(analyze);
        if (diagnostics is not null) StyleAction(diagnostics, primary: true);
        StyleAction(logs);
        StyleAction(support);
        StyleAction(cancel, danger: true);

        actions.Controls.Add(run);
        if (diagnostics is not null) actions.Controls.Add(diagnostics);
        actions.Controls.Add(analyze);
        actions.Controls.Add(logs);
        actions.Controls.Add(support);
        actions.Controls.Add(cancel);
        footer.Controls.Add(actions, 0, 2);
        return footer;
    }

    private static void StyleAction(Button button, bool primary = false, bool danger = false)
    {
        button.AutoSize = false;
        button.Height = 34;
        button.Width = button.Name == "SoknaDiagnosticsButton" ? 142 : 116;
        button.MinimumSize = new Size(button.Width, 34);
        button.Margin = new Padding(7, 0, 0, 0);
        button.Padding = Padding.Empty;
        button.FlatStyle = FlatStyle.Flat;
        button.FlatAppearance.BorderSize = 1;
        button.FlatAppearance.BorderColor = danger ? Color.FromArgb(211, 91, 82) : primary ? Brand : Color.FromArgb(198, 207, 207);
        button.BackColor = danger ? Color.FromArgb(255, 247, 246) : primary ? Brand : Color.White;
        button.ForeColor = danger ? Color.FromArgb(179, 50, 43) : primary ? Color.White : Color.FromArgb(37, 55, 56);
        button.RightToLeft = RightToLeft.Yes;
    }

    private static TabPage NewTab(string text, string name) => new(text)
    {
        Name = name,
        BackColor = Surface,
        Padding = Padding.Empty,
        RightToLeft = RightToLeft.Yes,
        AutoScroll = false
    };

    private static void RebuildPathAndPort(Form form)
    {
        var root = Field<TextBox>(form, "_root");
        var port = Field<NumericUpDown>(form, "_apachePort");
        var browse = Field<Button>(form, "_browse");
        var paths = Field<Label>(form, "_paths");
        if (root is null || port is null || browse is null || paths is null) return;

        var group = Ancestor<GroupBox>(root);
        if (group is null || group.Controls.Find("SoknaResponsivePathLayout", true).Length > 0) return;

        Detach(root);
        Detach(port);
        Detach(browse);
        Detach(paths);

        group.SuspendLayout();
        group.Controls.Clear();
        group.AutoSize = true;
        group.Padding = new Padding(16, 16, 16, 14);

        var layout = new TableLayoutPanel
        {
            Name = "SoknaResponsivePathLayout",
            Dock = DockStyle.Fill,
            AutoSize = false,
            ColumnCount = 1,
            RowCount = 7,
            RightToLeft = RightToLeft.Yes,
            Margin = Padding.Empty,
            Padding = Padding.Empty
        };
        layout.RowStyles.Add(new RowStyle(SizeType.Absolute, 24));
        layout.RowStyles.Add(new RowStyle(SizeType.Absolute, 40));
        layout.RowStyles.Add(new RowStyle(SizeType.Absolute, 24));
        layout.RowStyles.Add(new RowStyle(SizeType.Absolute, 36));
        layout.RowStyles.Add(new RowStyle(SizeType.Absolute, 42));
        layout.RowStyles.Add(new RowStyle(SizeType.Percent, 100));
        layout.RowStyles.Add(new RowStyle(SizeType.Absolute, 2));
        layout.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 100));

        layout.Controls.Add(FieldLabel("مسیر ریشه زیرساخت"), 0, 0);

        var rootRow = new TableLayoutPanel
        {
            Dock = DockStyle.Fill,
            ColumnCount = 2,
            RowCount = 1,
            RightToLeft = RightToLeft.No,
            Margin = new Padding(0, 2, 0, 5)
        };
        rootRow.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 100));
        rootRow.ColumnStyles.Add(new ColumnStyle(SizeType.Absolute, 120));
        root.Dock = DockStyle.Fill;
        root.AutoSize = false;
        root.MinimumSize = new Size(300, 30);
        root.RightToLeft = RightToLeft.No;
        root.TextAlign = HorizontalAlignment.Left;
        root.Margin = new Padding(0, 0, 8, 0);
        browse.Dock = DockStyle.Fill;
        browse.AutoSize = false;
        browse.Margin = Padding.Empty;
        rootRow.Controls.Add(root, 0, 0);
        rootRow.Controls.Add(browse, 1, 0);
        layout.Controls.Add(rootRow, 0, 1);

        layout.Controls.Add(FieldLabel("پورت Local Web"), 0, 2);

        var portRow = new FlowLayoutPanel
        {
            Dock = DockStyle.Fill,
            FlowDirection = FlowDirection.LeftToRight,
            WrapContents = false,
            RightToLeft = RightToLeft.No,
            Margin = new Padding(0, 2, 0, 2),
            AutoScroll = false
        };
        port.AutoSize = false;
        port.Width = 150;
        port.Height = 30;
        port.MinimumSize = new Size(130, 30);
        port.RightToLeft = RightToLeft.No;
        port.Margin = Padding.Empty;
        portRow.Controls.Add(port);
        layout.Controls.Add(portRow, 0, 3);

        var hint = new Label
        {
            Text = "پورت پیش‌فرض 18080 است. اگر اشغال باشد، Setup یک پورت آزاد loopback پیشنهاد می‌دهد.",
            AutoSize = false,
            Dock = DockStyle.Fill,
            RightToLeft = RightToLeft.Yes,
            TextAlign = ContentAlignment.MiddleRight,
            ForeColor = Muted,
            AutoEllipsis = true
        };
        layout.Controls.Add(hint, 0, 4);

        paths.Dock = DockStyle.Fill;
        paths.AutoSize = false;
        paths.RightToLeft = RightToLeft.Yes;
        paths.TextAlign = ContentAlignment.TopRight;
        paths.Padding = new Padding(0, 4, 0, 0);
        layout.Controls.Add(paths, 0, 5);
        layout.Controls.Add(new Panel { Height = 2, Dock = DockStyle.Fill }, 0, 6);

        group.Controls.Add(layout);
        group.ResumeLayout(true);
    }

    private static void RebuildMariaDbCredentials(Form form)
    {
        var password = Field<TextBox>(form, "_password");
        var password2 = Field<TextBox>(form, "_password2");
        var showPassword = Field<CheckBox>(form, "_showPassword");
        if (password is null || password2 is null || showPassword is null) return;

        var group = Ancestor<GroupBox>(password);
        if (group is null || group.Controls.Find("SoknaResponsiveMariaLayout", true).Length > 0) return;

        var helpText = Descendants(group).OfType<Label>()
            .Select(x => x.Text)
            .FirstOrDefault(x => x.Contains("این رمز فقط", StringComparison.Ordinal))
            ?? "این رمز فقط هنگام راه‌اندازی اولیه MariaDB استفاده می‌شود و در Log یا State ذخیره نمی‌شود. اگر Data قبلی وجود داشته باشد، Setup اجازه راه‌اندازی مجدد دیتابیس را نمی‌دهد.";

        Detach(password);
        Detach(password2);
        Detach(showPassword);

        group.SuspendLayout();
        group.Controls.Clear();
        group.AutoSize = true;
        group.Padding = new Padding(16, 16, 16, 14);

        var layout = new TableLayoutPanel
        {
            Name = "SoknaResponsiveMariaLayout",
            Dock = DockStyle.Top,
            AutoSize = true,
            AutoSizeMode = AutoSizeMode.GrowAndShrink,
            ColumnCount = 1,
            RowCount = 6,
            RightToLeft = RightToLeft.Yes,
            Margin = Padding.Empty,
            Padding = Padding.Empty
        };
        layout.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 100));

        layout.Controls.Add(FieldLabel("رمز مدیر MariaDB"), 0, 0);
        ConfigurePassword(password);
        layout.Controls.Add(password, 0, 1);
        layout.Controls.Add(FieldLabel("تکرار رمز"), 0, 2);
        ConfigurePassword(password2);
        layout.Controls.Add(password2, 0, 3);

        showPassword.AutoSize = true;
        showPassword.RightToLeft = RightToLeft.Yes;
        showPassword.Anchor = AnchorStyles.Right;
        showPassword.Margin = new Padding(0, 6, 0, 6);
        layout.Controls.Add(showPassword, 0, 4);

        var help = new Label
        {
            Text = helpText,
            AutoSize = true,
            Dock = DockStyle.Top,
            RightToLeft = RightToLeft.Yes,
            TextAlign = ContentAlignment.TopRight,
            ForeColor = Muted,
            Padding = new Padding(0, 4, 0, 0)
        };
        layout.Controls.Add(help, 0, 5);

        group.Controls.Add(layout);
        group.ResumeLayout(true);
    }

    private static void ConfigurePassword(TextBox box)
    {
        box.Dock = DockStyle.Top;
        box.AutoSize = false;
        box.Height = 32;
        box.MinimumSize = new Size(300, 32);
        box.RightToLeft = RightToLeft.No;
        box.TextAlign = HorizontalAlignment.Left;
        box.Margin = new Padding(0, 4, 0, 10);
    }

    private static Label FieldLabel(string text) => new()
    {
        Text = text,
        AutoSize = true,
        Dock = DockStyle.Top,
        RightToLeft = RightToLeft.Yes,
        TextAlign = ContentAlignment.MiddleRight,
        Margin = Padding.Empty
    };

    private static GroupBox? FindGroup(Control root, string contains)
        => Descendants(root).OfType<GroupBox>().FirstOrDefault(x => x.Text.Contains(contains, StringComparison.OrdinalIgnoreCase));

    private static void Detach(Control control)
        => control.Parent?.Controls.Remove(control);

    private static T? Ancestor<T>(Control control) where T : Control
    {
        for (Control? current = control.Parent; current is not null; current = current.Parent)
            if (current is T match) return match;
        return null;
    }

    private static IEnumerable<Control> Descendants(Control root)
    {
        foreach (Control child in root.Controls)
        {
            yield return child;
            foreach (var nested in Descendants(child)) yield return nested;
        }
    }

    private static void NormalizeVersionText(Control control)
    {
        var raw = Application.ProductVersion ?? "";
        var semantic = raw.Split('+')[0];
        if (!string.IsNullOrWhiteSpace(raw) && raw != semantic && !string.IsNullOrEmpty(control.Text))
            control.Text = control.Text.Replace(raw, semantic, StringComparison.Ordinal);

        foreach (Control child in control.Controls)
            NormalizeVersionText(child);
    }

    private static string SemanticVersion() => (Application.ProductVersion ?? "0.0.0").Split('+')[0];

    private static FontFamily UiFontFamily()
    {
        try { return UiHardening.PreferredFont().FontFamily; }
        catch { return SystemFonts.MessageBoxFont.FontFamily; }
    }

    private static T? Field<T>(object instance, string name) where T : class
        => instance.GetType().GetField(name, BindingFlags.Instance | BindingFlags.NonPublic)?.GetValue(instance) as T;
}
