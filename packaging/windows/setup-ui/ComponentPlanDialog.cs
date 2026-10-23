namespace Sokna.SetupUi;

internal static class SetupPalette
{
    internal static readonly Color Canvas = Color.FromArgb(247, 246, 243);
    internal static readonly Color Surface = Color.White;
    internal static readonly Color Brand = Color.FromArgb(20, 91, 84);
    internal static readonly Color BrandDark = Color.FromArgb(12, 67, 62);
    internal static readonly Color BrandSoft = Color.FromArgb(234, 245, 242);
    internal static readonly Color Text = Color.FromArgb(33, 42, 41);
    internal static readonly Color Muted = Color.FromArgb(99, 108, 106);
    internal static readonly Color Border = Color.FromArgb(216, 222, 220);
    internal static readonly Color Good = Color.FromArgb(35, 116, 77);
    internal static readonly Color Warning = Color.FromArgb(158, 96, 10);
    internal static readonly Color Danger = Color.FromArgb(164, 53, 53);
    internal static readonly Color DangerSoft = Color.FromArgb(255, 245, 245);

    internal static Font Font(float size, FontStyle style = FontStyle.Regular)
    {
        var preferred = FontFamily.Families.FirstOrDefault(x => x.Name.Equals("Vazirmatn", StringComparison.OrdinalIgnoreCase))
            ?? FontFamily.Families.FirstOrDefault(x => x.Name.Equals("Vazir", StringComparison.OrdinalIgnoreCase));
        return new Font(preferred ?? new FontFamily("Segoe UI"), size, style, GraphicsUnit.Point);
    }
}

internal sealed class ComponentPlanDialog : Form
{
    private readonly ComponentPlanSnapshot plan;
    private readonly Button proceed = new() { Text = "اجرای برنامه", DialogResult = DialogResult.OK, AutoSize = true };

    internal ComponentPlanDialog(ComponentPlanSnapshot plan, string title = "برنامه نصب و به‌روزرسانی")
    {
        this.plan = plan;
        Text = title;
        StartPosition = FormStartPosition.CenterParent;
        BackColor = SetupPalette.Canvas;
        ForeColor = SetupPalette.Text;
        RightToLeft = RightToLeft.Yes;
        RightToLeftLayout = true;
        AutoScaleMode = AutoScaleMode.Dpi;
        ClientSize = new Size(880, 480);
        MinimumSize = new Size(760, 440);
        MaximumSize = new Size(1100, 650);
        Font = SetupPalette.Font(9.4f);
        FormBorderStyle = FormBorderStyle.Sizable;
        MaximizeBox = false;
        Controls.Add(Build());
        AcceptButton = proceed;
    }

    private Control Build()
    {
        var root = new TableLayoutPanel { Dock = DockStyle.Fill, ColumnCount = 1, RowCount = 4, Padding = new Padding(20), BackColor = SetupPalette.Canvas };
        root.RowStyles.Add(new RowStyle(SizeType.AutoSize));
        root.RowStyles.Add(new RowStyle(SizeType.AutoSize));
        root.RowStyles.Add(new RowStyle(SizeType.Percent, 100));
        root.RowStyles.Add(new RowStyle(SizeType.AutoSize));

        root.Controls.Add(new Label { AutoSize = true, Text = "برنامه تغییرات این نصب", Font = SetupPalette.Font(16f, FontStyle.Bold), ForeColor = SetupPalette.BrandDark, Margin = new Padding(0, 0, 0, 4) }, 0, 0);
        var description = plan.IsBlocked
            ? "یکی از اجزا با این نسخه سازگار نیست. تا رفع وضعیت مسدود، هیچ تغییری اعمال نمی‌شود."
            : plan.HasMutation ? "فقط اجزایی که در ستون «اقدام» مشخص شده‌اند تغییر می‌کنند؛ اجزای «بدون تغییر» متوقف یا جایگزین نمی‌شوند."
            : "همه اجزای نصب‌شده با نسخه هدف یکسان هستند و تغییری لازم نیست.";
        root.Controls.Add(new Label { AutoSize = true, MaximumSize = new Size(820, 0), Text = description, ForeColor = plan.IsBlocked ? SetupPalette.Danger : SetupPalette.Muted, Margin = new Padding(0, 0, 0, 14) }, 0, 1);
        root.Controls.Add(BuildTable(), 0, 2);
        root.Controls.Add(BuildFooter(), 0, 3);
        return root;
    }

    private Control BuildTable()
    {
        var panel = new Panel { Dock = DockStyle.Fill, BackColor = SetupPalette.Surface, BorderStyle = BorderStyle.FixedSingle, Padding = new Padding(1) };
        var grid = new TableLayoutPanel { Dock = DockStyle.Fill, ColumnCount = 5, RowCount = plan.PrimaryRows.Count + 1, BackColor = SetupPalette.Surface, Padding = new Padding(12, 8, 12, 8) };
        grid.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 24));
        grid.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 18));
        grid.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 18));
        grid.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 18));
        grid.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 22));
        grid.RowStyles.Add(new RowStyle(SizeType.Absolute, 34));
        string[] headers = ["جزء", "نسخه فعلی", "نسخه هدف", "وضعیت سرویس", "اقدام"];
        for (var c = 0; c < headers.Length; c++) grid.Controls.Add(Cell(headers[c], true, SetupPalette.Muted), c, 0);
        var row = 1;
        foreach (var item in plan.PrimaryRows)
        {
            grid.RowStyles.Add(new RowStyle(SizeType.Percent, 100f / Math.Max(1, plan.PrimaryRows.Count)));
            grid.Controls.Add(Cell(item.DisplayName, false, SetupPalette.Text, FontStyle.Bold), 0, row);
            grid.Controls.Add(Cell(item.CurrentVersion, false, SetupPalette.Text, rtl: false), 1, row);
            grid.Controls.Add(Cell(item.TargetVersion, false, SetupPalette.Text, rtl: false), 2, row);
            grid.Controls.Add(Cell(item.HealthText == "فعال" ? "در حال اجرا" : item.HealthText, false, HealthColor(item.Health)), 3, row);
            grid.Controls.Add(Cell(item.ActionText, false, ActionColor(item.Action), FontStyle.Bold), 4, row);
            row++;
        }
        panel.Controls.Add(grid);
        return panel;
    }

    private Control BuildFooter()
    {
        var footer = new TableLayoutPanel { Dock = DockStyle.Fill, AutoSize = true, ColumnCount = 2, RowCount = 1, Margin = new Padding(0, 14, 0, 0) };
        footer.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 100));
        footer.ColumnStyles.Add(new ColumnStyle(SizeType.AutoSize));
        var note = new Label { AutoSize = true, Text = $"Windows Services {Application.ProductVersion}  •  بررسی {Sokna.Shared.IranPresentation.Clock(plan.VerifiedAtUtc)}", ForeColor = SetupPalette.Muted, Anchor = AnchorStyles.Right };
        var buttons = new FlowLayoutPanel { AutoSize = true, FlowDirection = FlowDirection.RightToLeft, WrapContents = false };
        var cancel = new Button { Text = "انصراف", DialogResult = DialogResult.Cancel, AutoSize = true, MinimumSize = new Size(86, 36), FlatStyle = FlatStyle.Flat, BackColor = SetupPalette.Surface, ForeColor = SetupPalette.BrandDark };
        cancel.FlatAppearance.BorderColor = SetupPalette.Border;
        proceed.MinimumSize = new Size(120, 36); proceed.FlatStyle = FlatStyle.Flat; proceed.FlatAppearance.BorderSize = 0; proceed.BackColor = SetupPalette.Brand; proceed.ForeColor = Color.White;
        proceed.Enabled = !plan.IsBlocked && plan.HasMutation;
        proceed.Text = plan.IsBlocked ? "امکان اجرا نیست" : plan.HasMutation ? "اجرای برنامه" : "تغییری لازم نیست";
        buttons.Controls.Add(proceed); buttons.Controls.Add(cancel);
        footer.Controls.Add(note, 0, 0); footer.Controls.Add(buttons, 1, 0);
        return footer;
    }

    private static Label Cell(string text, bool header, Color color, FontStyle style = FontStyle.Regular, bool rtl = true) => new()
    {
        Dock = DockStyle.Fill,
        Text = text,
        TextAlign = ContentAlignment.MiddleRight,
        RightToLeft = rtl ? RightToLeft.Yes : RightToLeft.No,
        ForeColor = color,
        Font = SetupPalette.Font(header ? 8.8f : 10.1f, header ? FontStyle.Bold : style),
        Padding = new Padding(6),
        AutoEllipsis = true
    };

    private static Color HealthColor(ComponentHealth h) => h switch { ComponentHealth.Healthy => SetupPalette.Good, ComponentHealth.Stopped or ComponentHealth.Degraded => SetupPalette.Warning, ComponentHealth.Failed => SetupPalette.Danger, _ => SetupPalette.Muted };
    private static Color ActionColor(ComponentAction a) => a switch { ComponentAction.Keep => SetupPalette.Good, ComponentAction.Install or ComponentAction.Update or ComponentAction.Repair => SetupPalette.Warning, ComponentAction.Blocked => SetupPalette.Danger, _ => SetupPalette.Muted };
}

internal sealed class InstallerResultDialog : Form
{
    internal InstallerResultDialog(bool success, string title, string summary, string diagnosticId, string failedComponent = "", string failedPhase = "", bool rollbackComplete = false, bool rebootRequired = false)
    {
        summary = Normalize(summary);
        failedComponent = Normalize(failedComponent);
        failedPhase = Normalize(failedPhase);
        Text = success ? "نتیجه عملیات" : "عملیات کامل نشد";
        StartPosition = FormStartPosition.CenterParent;
        BackColor = SetupPalette.Canvas; ForeColor = SetupPalette.Text; RightToLeft = RightToLeft.Yes; RightToLeftLayout = true;
        AutoScaleMode = AutoScaleMode.Dpi; ClientSize = new Size(650, 390); MinimumSize = new Size(590, 360); MaximizeBox = false; Font = SetupPalette.Font(9.5f);
        var root = new TableLayoutPanel { Dock = DockStyle.Fill, ColumnCount = 1, RowCount = 5, Padding = new Padding(22), BackColor = SetupPalette.Canvas };
        root.RowStyles.Add(new RowStyle(SizeType.AutoSize)); root.RowStyles.Add(new RowStyle(SizeType.AutoSize)); root.RowStyles.Add(new RowStyle(SizeType.Percent, 100)); root.RowStyles.Add(new RowStyle(SizeType.AutoSize)); root.RowStyles.Add(new RowStyle(SizeType.AutoSize));
        root.Controls.Add(new Label { AutoSize = true, Text = title, Font = SetupPalette.Font(16f, FontStyle.Bold), ForeColor = success ? SetupPalette.Good : SetupPalette.Danger }, 0, 0);
        root.Controls.Add(new Label { AutoSize = true, MaximumSize = new Size(600, 0), Text = summary, ForeColor = SetupPalette.Text, Margin = new Padding(0, 8, 0, 14) }, 0, 1);
        var details = new TableLayoutPanel { Dock = DockStyle.Fill, ColumnCount = 2, RowCount = 5, BackColor = SetupPalette.Surface, Padding = new Padding(14), Margin = new Padding(0), CellBorderStyle = TableLayoutPanelCellBorderStyle.Single };
        details.ColumnStyles.Add(new ColumnStyle(SizeType.Absolute, 150)); details.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 100));
        for (var i = 0; i < 5; i++) details.RowStyles.Add(new RowStyle(SizeType.Percent, 20));
        Add(details, 0, "شناسه عیب‌یابی", diagnosticId, false);
        Add(details, 1, "جزء", success ? "لازم نیست" : string.IsNullOrWhiteSpace(failedComponent) ? "—" : failedComponent, true);
        Add(details, 2, "مرحله", success ? "لازم نیست" : string.IsNullOrWhiteSpace(failedPhase) ? "—" : failedPhase, true);
        Add(details, 3, "بازگشت تغییرات", success ? "لازم نبود" : rollbackComplete ? "کامل شد" : "نیاز به بررسی", true);
        Add(details, 4, "راه‌اندازی مجدد", rebootRequired ? "لازم است" : "لازم نیست", true);
        root.Controls.Add(details, 0, 2);
        var hint = new Label { AutoSize = true, MaximumSize = new Size(600, 0), Text = success ? "وضعیت اجزا دوباره بررسی می‌شود." : "برای بررسی دقیق، «عیب‌یابی» را از صفحه مدیریت اجرا کنید؛ نیازی به خط فرمان نیست.", ForeColor = SetupPalette.Muted, Margin = new Padding(0, 12, 0, 10) };
        root.Controls.Add(hint, 0, 3);
        var ok = new Button { Text = "بستن", DialogResult = DialogResult.OK, AutoSize = true, MinimumSize = new Size(96, 36), Anchor = AnchorStyles.Left, FlatStyle = FlatStyle.Flat, BackColor = SetupPalette.Brand, ForeColor = Color.White };
        ok.FlatAppearance.BorderSize = 0; root.Controls.Add(ok, 0, 4); AcceptButton = ok; Controls.Add(root);
    }

    private static string Normalize(string value) => (value ?? "")
        .Replace("componentهای", "اجزای", StringComparison.Ordinal)
        .Replace("componentها", "اجزا", StringComparison.Ordinal)
        .Replace("component", "جزء", StringComparison.Ordinal)
        .Replace("Health verification", "بررسی سلامت", StringComparison.OrdinalIgnoreCase)
        .Replace("health check", "بررسی سلامت", StringComparison.OrdinalIgnoreCase)
        .Replace("Preflight", "بررسی اولیه", StringComparison.OrdinalIgnoreCase)
        .Replace("Setup Host", "اجرای نصب", StringComparison.OrdinalIgnoreCase)
        .Replace("Lifecycle", "اجرای تغییرات", StringComparison.OrdinalIgnoreCase)
        .Replace("Bootstrap", "راه‌اندازی اولیه", StringComparison.OrdinalIgnoreCase)
        .Replace("Result", "نتیجه", StringComparison.OrdinalIgnoreCase)
        .Replace("Running", "در حال اجرا", StringComparison.OrdinalIgnoreCase);

    private static void Add(TableLayoutPanel t, int row, string caption, string value, bool rtl)
    {
        t.Controls.Add(new Label { Dock = DockStyle.Fill, Text = caption, TextAlign = ContentAlignment.MiddleRight, ForeColor = SetupPalette.Muted, Padding = new Padding(5), AutoEllipsis = true }, 0, row);
        t.Controls.Add(new Label { Dock = DockStyle.Fill, Text = value, TextAlign = ContentAlignment.MiddleRight, RightToLeft = rtl ? RightToLeft.Yes : RightToLeft.No, ForeColor = SetupPalette.Text, Font = SetupPalette.Font(9.5f, FontStyle.Bold), Padding = new Padding(5), AutoEllipsis = true }, 1, row);
    }
}
