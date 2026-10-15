using System.ComponentModel;
using System.Diagnostics;
using System.Text.Json;

namespace Sokna.SetupUi;

internal static class DashboardEntryV3
{
    [STAThread]
    public static int Main(string[] args)
    {
        Application.EnableVisualStyles();
        Application.SetCompatibleTextRenderingDefault(false);
        if (args.Length == 2 && args[0].Equals("--layout-self-test", StringComparison.OrdinalIgnoreCase))
            return RunLayoutSelfTest(Path.GetFullPath(args[1]));
        if (args.Length == 1 && args[0].Equals("--planner-self-test", StringComparison.OrdinalIgnoreCase))
        {
            ComponentPlannerSelfTest.Run();
            Console.WriteLine("SOKNA component planner self-test PASS");
            return 0;
        }
        var telemetry = InstallerTelemetry.Start();
        Application.Run(new WindowsServicesDashboardV3(telemetry));
        return 0;
    }

    private static int RunLayoutSelfTest(string output)
    {
        Directory.CreateDirectory(output);
        var plan = TestFixtures.UpgradePlan();
        foreach (var size in new[] { new Size(960, 600), new Size(1100, 660), new Size(1280, 720) })
        {
            using var form = new WindowsServicesDashboardV3(null) { ClientSize = size };
            form.ApplyTestPlan(plan);
            form.CreateControl(); form.PerformLayout(); Application.DoEvents();
            var errors = LayoutAudit(form).ToArray();
            File.WriteAllLines(Path.Combine(output, $"dashboard-{size.Width}x{size.Height}.txt"), errors.Length == 0 ? ["PASS"] : errors);
            Capture(form, Path.Combine(output, $"dashboard-{size.Width}x{size.Height}.png"));
            if (errors.Length > 0) throw new InvalidOperationException(string.Join(" | ", errors));
        }
        using (var dialog = new ComponentPlanDialog(plan))
        {
            dialog.CreateControl(); dialog.PerformLayout(); Application.DoEvents(); Capture(dialog, Path.Combine(output, "component-plan.png"));
            var errors = LayoutAudit(dialog).ToArray(); File.WriteAllLines(Path.Combine(output, "component-plan.txt"), errors.Length == 0 ? ["PASS"] : errors);
            if (errors.Length > 0) throw new InvalidOperationException(string.Join(" | ", errors));
        }
        using (var result = new InstallerResultDialog(false, "Maintenance راه‌اندازی نشد", "ثبت سرویس انجام شد اما health check در زمان مجاز به وضعیت Running نرسید.", "ins-20261015T120000Z-abc123def0", "Maintenance", "Health verification", true, false))
        {
            result.CreateControl(); result.PerformLayout(); Application.DoEvents(); Capture(result, Path.Combine(output, "failure-result.png"));
            var errors = LayoutAudit(result).ToArray(); File.WriteAllLines(Path.Combine(output, "failure-result.txt"), errors.Length == 0 ? ["PASS"] : errors);
            if (errors.Length > 0) throw new InvalidOperationException(string.Join(" | ", errors));
        }
        Console.WriteLine("SOKNA Dashboard V3 layout self-test PASS");
        return 0;
    }

    private static IEnumerable<string> LayoutAudit(Control root)
    {
        foreach (Control c in Desc(root))
        {
            if (!c.Visible) continue;
            if (c.Bounds.Width < 0 || c.Bounds.Height < 0) yield return $"invalid-bounds:{c.Name}:{c.Bounds}";
            if (c is Button b && (b.ClientSize.Width + 2 < b.PreferredSize.Width || b.ClientSize.Height + 2 < b.PreferredSize.Height)) yield return $"button-clipped:{b.Text}:{b.ClientSize}:{b.PreferredSize}";
            if (c is Label l && !l.AutoSize && l.AutoEllipsis == false && l.PreferredSize.Height > l.ClientSize.Height + 3) yield return $"label-clipped:{l.Text}";
        }
        if (root is ScrollableControl sc && sc.AutoScroll) yield return "unexpected-autoscroll";
    }
    private static IEnumerable<Control> Desc(Control root)
    {
        foreach (Control c in root.Controls) { yield return c; foreach (var d in Desc(c)) yield return d; }
    }
    private static void Capture(Form form, string path)
    {
        var size = form.ClientSize; using var bmp = new Bitmap(Math.Max(1, size.Width), Math.Max(1, size.Height)); form.DrawToBitmap(bmp, new Rectangle(Point.Empty, size)); bmp.Save(path, System.Drawing.Imaging.ImageFormat.Png);
    }
}

internal sealed class WindowsServicesDashboardV3 : Form
{
    private readonly InstallerTelemetry? startupTelemetry;
    private readonly string shellRoot = AppContext.BaseDirectory.TrimEnd(Path.DirectorySeparatorChar, Path.AltDirectorySeparatorChar);
    private readonly string installRoot;
    private readonly string dataRoot = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData), "SOKNA");
    private ComponentPlanSnapshot? plan;

    private readonly Label packageState = ValueLabel();
    private readonly Label serviceState = ValueLabel();
    private readonly Label connectionState = ValueLabel();
    private readonly TableLayoutPanel componentRows = new() { Dock = DockStyle.Fill, ColumnCount = 4, RowCount = 4, BackColor = SetupPalette.Surface };
    private readonly Label actionTitle = new() { AutoSize = true };
    private readonly Label actionText = new() { AutoSize = true, MaximumSize = new Size(520, 0) };
    private readonly Label prereqText = new() { AutoSize = true };
    private readonly TextBox localUrl = new() { Text = "http://127.0.0.1:18080/", RightToLeft = RightToLeft.No, TextAlign = HorizontalAlignment.Left, Dock = DockStyle.Fill };
    private readonly TextBox pairingCode = new() { RightToLeft = RightToLeft.No, TextAlign = HorizontalAlignment.Left, Dock = DockStyle.Fill, UseSystemPasswordChar = false };
    private readonly CheckBox startRuntime = new() { Text = "بعد از اتصال، Runtime شروع شود", Checked = true, AutoSize = true };
    private readonly Button primary = new() { AutoSize = true };
    private readonly Button repair = new() { Text = "تعمیر نصب", AutoSize = true };
    private readonly Button uninstall = new() { Text = "حذف سرویس‌ها", AutoSize = true };
    private readonly Button pair = new() { Text = "اتصال به Local Web", AutoSize = true };
    private readonly Button refresh = new() { Text = "تازه‌سازی", AutoSize = true };
    private readonly Button diagnostics = new() { Text = "بسته عیب‌یابی", AutoSize = true };
    private readonly Button logs = new() { Text = "لاگ نصب", AutoSize = true };
    private readonly Label footer = new() { Dock = DockStyle.Fill, TextAlign = ContentAlignment.MiddleRight };
    private readonly ProgressBar progress = new() { Dock = DockStyle.Fill, Visible = false, Style = ProgressBarStyle.Marquee };

    internal WindowsServicesDashboardV3(InstallerTelemetry? telemetry)
    {
        startupTelemetry = telemetry;
        installRoot = shellRoot;
        Text = "سکنا | مدیریت سرویس‌های ویندوز"; StartPosition = FormStartPosition.CenterScreen;
        BackColor = SetupPalette.Canvas; ForeColor = SetupPalette.Text; RightToLeft = RightToLeft.Yes; RightToLeftLayout = false;
        AutoScaleMode = AutoScaleMode.Dpi; AutoScroll = false; MinimumSize = new Size(940, 580); ClientSize = new Size(1120, 690); Font = SetupPalette.Font(9.5f);
        Controls.Add(BuildRoot()); StyleButtons(); Wire();
        Shown += async (_, _) => await RefreshAsync();
    }

    private Control BuildRoot()
    {
        var root = new TableLayoutPanel { Dock = DockStyle.Fill, ColumnCount = 1, RowCount = 5, Padding = new Padding(18, 15, 18, 12), BackColor = SetupPalette.Canvas };
        root.RowStyles.Add(new RowStyle(SizeType.Absolute, 76)); root.RowStyles.Add(new RowStyle(SizeType.Absolute, 90)); root.RowStyles.Add(new RowStyle(SizeType.Absolute, 170)); root.RowStyles.Add(new RowStyle(SizeType.Percent, 100)); root.RowStyles.Add(new RowStyle(SizeType.Absolute, 50));
        root.Controls.Add(Header(), 0, 0); root.Controls.Add(Summary(), 0, 1); root.Controls.Add(ComponentsCard(), 0, 2); root.Controls.Add(Content(), 0, 3); root.Controls.Add(Footer(), 0, 4); return root;
    }

    private Control Header()
    {
        var p = new TableLayoutPanel { Dock = DockStyle.Fill, ColumnCount = 2, RowCount = 1, Padding = new Padding(18, 8, 18, 8), BackColor = SetupPalette.Brand, Margin = new Padding(0, 0, 0, 8) };
        p.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 100)); p.ColumnStyles.Add(new ColumnStyle(SizeType.AutoSize));
        var titles = new TableLayoutPanel { Dock = DockStyle.Fill, ColumnCount = 1, RowCount = 2, BackColor = SetupPalette.Brand };
        titles.Controls.Add(new Label { AutoSize = true, Text = "سرویس‌های ویندوز سکنا", ForeColor = Color.White, Font = SetupPalette.Font(16f, FontStyle.Bold), Anchor = AnchorStyles.Right }, 0, 0);
        titles.Controls.Add(new Label { AutoSize = true, Text = "Runtime، Print و Maintenance — نصب، وضعیت و عیب‌یابی", ForeColor = Color.FromArgb(221, 239, 236), Font = SetupPalette.Font(9.1f), Anchor = AnchorStyles.Right }, 0, 1);
        var badge = new Label { AutoSize = true, Text = "Windows Services 1.1.0", RightToLeft = RightToLeft.No, ForeColor = SetupPalette.BrandDark, BackColor = Color.White, Padding = new Padding(10, 5, 10, 5), Anchor = AnchorStyles.None, Font = SetupPalette.Font(9f, FontStyle.Bold) };
        p.Controls.Add(titles, 0, 0); p.Controls.Add(badge, 1, 0); return p;
    }

    private Control Summary()
    {
        var p = new TableLayoutPanel { Dock = DockStyle.Fill, ColumnCount = 3, RowCount = 1, Margin = new Padding(0, 0, 0, 8) };
        for (var i = 0; i < 3; i++) p.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 33.333f));
        p.Controls.Add(SummaryCard("نسخه و تغییرات", packageState), 0, 0); p.Controls.Add(SummaryCard("سلامت سرویس‌ها", serviceState), 1, 0); p.Controls.Add(SummaryCard("اتصال Local Web", connectionState), 2, 0); return p;
    }
    private Control SummaryCard(string caption, Label value)
    {
        var card = Card(); card.Margin = new Padding(4); card.Padding = new Padding(13, 8, 13, 8);
        var g = new TableLayoutPanel { Dock = DockStyle.Fill, ColumnCount = 1, RowCount = 2, BackColor = SetupPalette.Surface };
        g.Controls.Add(new Label { AutoSize = true, Text = caption, ForeColor = SetupPalette.Muted, Anchor = AnchorStyles.Right }, 0, 0); value.Font = SetupPalette.Font(11f, FontStyle.Bold); value.Anchor = AnchorStyles.Right; g.Controls.Add(value, 0, 1); card.Controls.Add(g); return card;
    }

    private Control ComponentsCard()
    {
        var card = Card(); card.Margin = new Padding(4, 0, 4, 8); card.Padding = new Padding(14, 8, 14, 8);
        componentRows.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 31)); componentRows.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 23)); componentRows.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 23)); componentRows.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 23));
        componentRows.RowStyles.Add(new RowStyle(SizeType.Absolute, 30)); for (var i = 0; i < 3; i++) componentRows.RowStyles.Add(new RowStyle(SizeType.Percent, 33.333f));
        string[] h = ["جزء", "نسخه", "سلامت", "وضعیت به‌روزرسانی"]; for (var i = 0; i < 4; i++) componentRows.Controls.Add(GridCell(h[i], SetupPalette.Muted, true), i, 0);
        card.Controls.Add(componentRows); return card;
    }

    private Control Content()
    {
        var p = new TableLayoutPanel { Dock = DockStyle.Fill, ColumnCount = 2, RowCount = 1, Margin = new Padding(0) };
        p.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 56)); p.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 44)); p.Controls.Add(LifecycleCard(), 0, 0); p.Controls.Add(PairCard(), 1, 0); return p;
    }
    private Control LifecycleCard()
    {
        var card = Card(); card.Margin = new Padding(4); card.Padding = new Padding(15, 11, 15, 10);
        var g = new TableLayoutPanel { Dock = DockStyle.Fill, ColumnCount = 1, RowCount = 5, BackColor = SetupPalette.Surface };
        g.RowStyles.Add(new RowStyle(SizeType.AutoSize)); g.RowStyles.Add(new RowStyle(SizeType.AutoSize)); g.RowStyles.Add(new RowStyle(SizeType.Percent, 100)); g.RowStyles.Add(new RowStyle(SizeType.AutoSize)); g.RowStyles.Add(new RowStyle(SizeType.AutoSize));
        actionTitle.Font = SetupPalette.Font(13f, FontStyle.Bold); actionTitle.ForeColor = SetupPalette.BrandDark; actionText.ForeColor = SetupPalette.Muted; actionText.Margin = new Padding(0, 5, 0, 6); prereqText.Font = SetupPalette.Font(8.8f, FontStyle.Bold);
        g.Controls.Add(actionTitle, 0, 0); g.Controls.Add(actionText, 0, 1); g.Controls.Add(new Panel { Dock = DockStyle.Fill, BackColor = SetupPalette.Surface }, 0, 2); g.Controls.Add(prereqText, 0, 3); g.Controls.Add(Flow(primary, repair, uninstall), 0, 4); card.Controls.Add(g); return card;
    }
    private Control PairCard()
    {
        var card = Card(); card.Margin = new Padding(4); card.Padding = new Padding(15, 11, 15, 10);
        var g = new TableLayoutPanel { Dock = DockStyle.Fill, ColumnCount = 1, RowCount = 6, BackColor = SetupPalette.Surface };
        g.RowStyles.Add(new RowStyle(SizeType.AutoSize)); g.RowStyles.Add(new RowStyle(SizeType.AutoSize)); g.RowStyles.Add(new RowStyle(SizeType.AutoSize)); g.RowStyles.Add(new RowStyle(SizeType.AutoSize)); g.RowStyles.Add(new RowStyle(SizeType.Percent, 100)); g.RowStyles.Add(new RowStyle(SizeType.AutoSize));
        g.Controls.Add(new Label { AutoSize = true, Text = "اتصال به Local Web", ForeColor = SetupPalette.BrandDark, Font = SetupPalette.Font(12.5f, FontStyle.Bold) }, 0, 0);
        g.Controls.Add(Field("آدرس Local Web", localUrl), 0, 1); g.Controls.Add(Field("کد اتصال", pairingCode), 0, 2); g.Controls.Add(startRuntime, 0, 3); g.Controls.Add(new Panel { Dock = DockStyle.Fill, BackColor = SetupPalette.Surface }, 0, 4); g.Controls.Add(Flow(pair), 0, 5); card.Controls.Add(g); return card;
    }
    private Control Footer()
    {
        var g = new TableLayoutPanel { Dock = DockStyle.Fill, ColumnCount = 3, RowCount = 1, Padding = new Padding(2, 6, 2, 0) };
        g.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 100)); g.ColumnStyles.Add(new ColumnStyle(SizeType.Absolute, 120)); g.ColumnStyles.Add(new ColumnStyle(SizeType.AutoSize)); footer.ForeColor = SetupPalette.Muted; g.Controls.Add(footer, 0, 0); g.Controls.Add(progress, 1, 0); g.Controls.Add(Flow(refresh, diagnostics, logs), 2, 0); return g;
    }

    private static Label ValueLabel() => new() { AutoSize = true, Text = "در حال بررسی…", ForeColor = SetupPalette.Muted };
    private static Panel Card() => new() { Dock = DockStyle.Fill, BackColor = SetupPalette.Surface, BorderStyle = BorderStyle.FixedSingle };
    private static Label GridCell(string text, Color color, bool bold = false, bool rtl = true) => new() { Dock = DockStyle.Fill, Text = text, TextAlign = ContentAlignment.MiddleRight, RightToLeft = rtl ? RightToLeft.Yes : RightToLeft.No, ForeColor = color, Font = SetupPalette.Font(9.2f, bold ? FontStyle.Bold : FontStyle.Regular), Padding = new Padding(5), AutoEllipsis = true };
    private Control Field(string caption, Control input)
    {
        var g = new TableLayoutPanel { Dock = DockStyle.Top, AutoSize = true, ColumnCount = 1, RowCount = 2, Margin = new Padding(0, 2, 0, 4) };
        g.Controls.Add(new Label { AutoSize = true, Text = caption, Font = SetupPalette.Font(8.8f, FontStyle.Bold), Anchor = AnchorStyles.Right }, 0, 0); input.MinimumSize = new Size(0, 29); input.Margin = new Padding(0, 2, 0, 0); g.Controls.Add(input, 0, 1); return g;
    }
    private static FlowLayoutPanel Flow(params Control[] controls)
    {
        var f = new FlowLayoutPanel { Dock = DockStyle.Fill, AutoSize = true, FlowDirection = FlowDirection.RightToLeft, WrapContents = false, Padding = new Padding(0), Margin = new Padding(0) }; foreach (var c in controls) { c.Margin = new Padding(6, 0, 0, 0); f.Controls.Add(c); } return f;
    }
    private void StyleButtons()
    {
        Primary(primary); Primary(pair); Secondary(repair); Danger(uninstall); Secondary(refresh); Secondary(diagnostics); Secondary(logs);
        foreach (var b in Descendants<Button>(this)) { b.MinimumSize = new Size(b == primary || b == pair ? 118 : 88, 34); b.Cursor = Cursors.Hand; b.Font = SetupPalette.Font(8.9f, FontStyle.Bold); }
    }
    private static void Primary(Button b) { b.FlatStyle = FlatStyle.Flat; b.FlatAppearance.BorderSize = 0; b.BackColor = SetupPalette.Brand; b.ForeColor = Color.White; b.Padding = new Padding(10, 3, 10, 3); }
    private static void Secondary(Button b) { b.FlatStyle = FlatStyle.Flat; b.FlatAppearance.BorderColor = SetupPalette.Border; b.BackColor = SetupPalette.Surface; b.ForeColor = SetupPalette.BrandDark; b.Padding = new Padding(9, 3, 9, 3); }
    private static void Danger(Button b) { b.FlatStyle = FlatStyle.Flat; b.FlatAppearance.BorderColor = Color.FromArgb(229, 194, 194); b.BackColor = SetupPalette.DangerSoft; b.ForeColor = SetupPalette.Danger; b.Padding = new Padding(9, 3, 9, 3); }

    private void Wire()
    {
        refresh.Click += async (_, _) => await RefreshAsync(); primary.Click += async (_, _) => await RunLifecycleAsync("install", false); repair.Click += async (_, _) => await RunLifecycleAsync("repair", true); uninstall.Click += async (_, _) => await RunUninstallAsync(); pair.Click += async (_, _) => await RunPairingAsync();
        logs.Click += (_, _) => OpenFolder(Path.Combine(dataRoot, "Installer", "logs")); diagnostics.Click += async (_, _) => await BuildDiagnosticsAsync();
    }

    private async Task RefreshAsync()
    {
        SetBusy(true, "در حال بررسی componentها…");
        try { plan = await Task.Run(() => ComponentUpdatePlanner.Build(shellRoot, installRoot, dataRoot)); Render(plan); footer.Text = "وضعیت componentها بررسی شد."; }
        catch (Exception e) { footer.Text = "بررسی کامل نشد."; ShowError(e.Message); }
        finally { SetBusy(false); }
    }

    private void Render(ComponentPlanSnapshot p)
    {
        packageState.Text = p.IsBlocked ? "نیاز به بررسی" : p.HasMutation ? "تغییر لازم است" : "به‌روز"; packageState.ForeColor = p.IsBlocked ? SetupPalette.Danger : p.HasMutation ? SetupPalette.Warning : SetupPalette.Good;
        var prim = p.PrimaryRows; var running = prim.Count(x => x.Health == ComponentHealth.Healthy); serviceState.Text = $"{running} از {prim.Count} آماده"; serviceState.ForeColor = running == prim.Count ? SetupPalette.Good : SetupPalette.Warning;
        var paired = ReadPaired(); connectionState.Text = paired ? "متصل" : "متصل نیست"; connectionState.ForeColor = paired ? SetupPalette.Good : SetupPalette.Warning;
        while (componentRows.Controls.Count > 4) componentRows.Controls.RemoveAt(componentRows.Controls.Count - 1);
        var r = 1; foreach (var c in prim)
        {
            componentRows.Controls.Add(GridCell(c.DisplayName, SetupPalette.Text, true), 0, r); componentRows.Controls.Add(GridCell(c.CurrentVersion == "—" ? "نصب نشده" : c.CurrentVersion, SetupPalette.Text, false, false), 1, r); componentRows.Controls.Add(GridCell(c.HealthText, HealthColor(c.Health)), 2, r); componentRows.Controls.Add(GridCell(UpdateText(c), ActionColor(c.Action), c.Action != ComponentAction.Keep), 3, r); r++;
        }
        primary.Visible = p.HasMutation && !p.IsBlocked; repair.Visible = prim.Any(x => x.CurrentVersion != "—"); uninstall.Visible = repair.Visible;
        primary.Text = prim.Any(x => x.Action == ComponentAction.Install) ? "نصب اجزای لازم" : "اجرای به‌روزرسانی";
        if (p.IsBlocked) { actionTitle.Text = "ادامه نصب ایمن نیست"; actionText.Text = "حداقل یک component ناسازگار یا جدیدتر از release هدف تشخیص داده شد. هیچ mutation انجام نمی‌شود."; }
        else if (p.HasMutation) { actionTitle.Text = "برنامه تغییرات آماده است"; actionText.Text = "قبل از اجرا، برنامه دقیق هر component نمایش داده می‌شود. اجزای سالم بدون توقف و جایگزینی باقی می‌مانند."; }
        else { actionTitle.Text = "همه اجزا با release هدف یکسان‌اند"; actionText.Text = "نسخه، fingerprint و ثبت سرویس‌ها بررسی شده است. در صورت مشکل عملیاتی می‌توانید تعمیر نصب یا بسته عیب‌یابی را اجرا کنید."; }
        var vc = ReadVcRuntime(); prereqText.Text = vc.ready ? $"پیش‌نیاز ویندوز آماده است • Visual C++ {vc.version}" : "پیش‌نیاز Visual C++ آماده نیست؛ mutation مسدود می‌شود."; prereqText.ForeColor = vc.ready ? SetupPalette.Good : SetupPalette.Danger;
        pair.Enabled = !p.IsBlocked && prim.All(x => x.CurrentVersion != "—"); localUrl.Enabled = pair.Enabled; pairingCode.Enabled = pair.Enabled; startRuntime.Enabled = pair.Enabled;
    }

    internal void ApplyTestPlan(ComponentPlanSnapshot test) { plan = test; Render(test); }

    private async Task RunLifecycleAsync(string mode, bool forceRepair)
    {
        ComponentPlanSnapshot current;
        try { current = ComponentUpdatePlanner.Build(shellRoot, installRoot, dataRoot, forceRepair); }
        catch (Exception e) { ShowError(e.Message); return; }
        using (var dlg = new ComponentPlanDialog(current)) if (dlg.ShowDialog(this) != DialogResult.OK) return;
        var telemetry = InstallerTelemetry.Start("setup_lifecycle_requested");
        var planPath = telemetry.WritePlan(current, mode, shellRoot, installRoot, dataRoot);
        telemetry.Event("elevation_requested", "started", new { mode }); SetBusy(true, "در حال اجرای برنامه componentها…");
        try
        {
            var host = Path.Combine(shellRoot, "SoknaSetupHost.exe");
            var code = await RunElevatedAsync(host, $"--plan-file {Q(planPath)}");
            telemetry.Event("setup_host_completed", code == 0 ? "completed" : "failed", new { exit_code = code });
            var result = ReadInstallResult(telemetry.TransactionId);
            if (code != 0)
            {
                telemetry.WriteUiResult("failed", code, result.summary, result.rollback);
                using var rd = new InstallerResultDialog(false, "عملیات کامل نشد", result.summary, telemetry.TransactionId, result.component, result.phase, result.rollback, result.reboot); rd.ShowDialog(this);
            }
            else
            {
                telemetry.WriteUiResult("completed", code, "برنامه componentها با موفقیت اجرا شد.");
                using var rd = new InstallerResultDialog(true, "عملیات با موفقیت کامل شد", "componentهای موردنیاز اعمال و دوباره بررسی شدند.", telemetry.TransactionId); rd.ShowDialog(this);
            }
            await RefreshAsync();
        }
        catch (Win32Exception ex) when (ex.NativeErrorCode == 1223) { telemetry.Event("elevation_cancelled", "cancelled"); telemetry.WriteUiResult("cancelled", null, "درخواست Administrator توسط کاربر لغو شد."); footer.Text = "درخواست Administrator لغو شد."; }
        catch (Exception e) { telemetry.Event("setup_failed", "failed", new { error = Safe(e.Message) }); telemetry.WriteUiResult("failed", null, e.Message); using var rd = new InstallerResultDialog(false, "عملیات کامل نشد", Safe(e.Message), telemetry.TransactionId, "—", "Bootstrap", false, false); rd.ShowDialog(this); }
        finally { SetBusy(false); }
    }

    private async Task RunUninstallAsync()
    {
        if (MessageBox.Show(this, "Runtime، Print و Maintenance حذف می‌شوند؛ داده‌ها و Local Web باقی می‌مانند. ادامه می‌دهید؟", "حذف سرویس‌ها", MessageBoxButtons.YesNo, MessageBoxIcon.Warning, MessageBoxDefaultButton.Button2, MessageBoxOptions.RtlReading | MessageBoxOptions.RightAlign) != DialogResult.Yes) return;
        var telemetry = InstallerTelemetry.Start("setup_uninstall_requested");
        ComponentPlanSnapshot current; try { current = ComponentUpdatePlanner.Build(shellRoot, installRoot, dataRoot); } catch { current = TestFixtures.EmptyPlan(); }
        var planPath = telemetry.WritePlan(current, "uninstall", shellRoot, installRoot, dataRoot); SetBusy(true, "در حال حذف سرویس‌ها…");
        try { var code = await RunElevatedAsync(Path.Combine(shellRoot, "SoknaSetupHost.exe"), $"--plan-file {Q(planPath)}"); if (code != 0) throw new InvalidOperationException($"حذف با کد {code} متوقف شد."); await RefreshAsync(); }
        catch (Exception e) { ShowError(e.Message); } finally { SetBusy(false); }
    }

    private async Task RunPairingAsync()
    {
        var code = pairingCode.Text.Trim(); if (!System.Text.RegularExpressions.Regex.IsMatch(code, "^ws1_[a-f0-9]{24}_[a-f0-9]{48}$")) { ShowError("کد اتصال معتبر نیست."); return; }
        if (!Uri.TryCreate(localUrl.Text.Trim(), UriKind.Absolute, out var uri) || !uri.IsLoopback || (uri.Scheme != "http" && uri.Scheme != "https")) { ShowError("آدرس Local Web باید یک origin محلی معتبر باشد."); return; }
        var tmp = Path.Combine(Path.GetTempPath(), "sokna-pair-" + Guid.NewGuid().ToString("N") + ".json");
        try
        {
            File.WriteAllText(tmp, JsonSerializer.Serialize(new { format = "sokna-windows-services-pair-plan-v1", schema_version = 1, install_root = installRoot, data_root = dataRoot, pairing_base_url = localUrl.Text.Trim(), pairing_code = code, start_when_paired = startRuntime.Checked }, new JsonSerializerOptions { WriteIndented = true }));
            SetBusy(true, "در حال اتصال به Local Web…"); var ps = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.System), "WindowsPowerShell", "v1.0", "powershell.exe"); var script = Path.Combine(shellRoot, "pair-windows-services.ps1");
            var exit = await RunElevatedAsync(ps, $"-NoProfile -NonInteractive -ExecutionPolicy Bypass -File {Q(script)} -PlanFile {Q(tmp)}"); if (exit != 0) throw new InvalidOperationException($"اتصال با کد {exit} متوقف شد."); pairingCode.Clear(); await RefreshAsync();
        }
        catch (Exception e) { ShowError(e.Message); } finally { try { File.Delete(tmp); } catch { } SetBusy(false); }
    }

    private async Task BuildDiagnosticsAsync()
    {
        var outDir = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.DesktopDirectory), "SOKNA-Diagnostics"); Directory.CreateDirectory(outDir); var script = Path.Combine(shellRoot, File.Exists(Path.Combine(shellRoot, "collect-support-v2.ps1")) ? "collect-support-v2.ps1" : "collect-support.ps1"); var ps = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.System), "WindowsPowerShell", "v1.0", "powershell.exe");
        try { SetBusy(true, "در حال ساخت بسته عیب‌یابی…"); var code = await RunElevatedAsync(ps, $"-NoProfile -NonInteractive -ExecutionPolicy Bypass -File {Q(script)} -DataRoot {Q(dataRoot)} -OutputRoot {Q(outDir)}"); if (code != 0) throw new InvalidOperationException($"ساخت بسته عیب‌یابی با کد {code} متوقف شد."); OpenFolder(outDir); }
        catch (Exception e) { ShowError(e.Message); } finally { SetBusy(false); }
    }

    private (string summary, string component, string phase, bool rollback, bool reboot) ReadInstallResult(string tx)
    {
        try
        {
            var p = Path.Combine(dataRoot, "Installer", "logs", tx, "install-result.json"); if (!File.Exists(p)) return ("عملیات در Setup Host متوقف شد. لاگ transaction برای بررسی نگه داشته شده است.", "—", "Setup Host", false, false);
            using var d = JsonDocument.Parse(File.ReadAllText(p)); var r = d.RootElement; return (Get(r, "summary", "عملیات کامل نشد."), Get(r, "failed_component", "—"), Get(r, "failed_phase", "—"), Bool(r, "rollback_complete"), Bool(r, "reboot_required"));
        }
        catch { return ("نتیجه ساختاریافته قابل خواندن نبود؛ لاگ transaction را بررسی کنید.", "—", "Result", false, false); }
    }

    private bool ReadPaired() { try { var p = Path.Combine(dataRoot, "setup", "windows-services-state.json"); if (!File.Exists(p)) return false; using var d = JsonDocument.Parse(File.ReadAllText(p)); return d.RootElement.TryGetProperty("paired", out var x) && x.ValueKind == JsonValueKind.True; } catch { return false; } }
    private static (bool ready, string version) ReadVcRuntime() { try { using var k = Microsoft.Win32.Registry.LocalMachine.OpenSubKey(@"SOFTWARE\Microsoft\VisualStudio\14.0\VC\Runtimes\x64"); if (k is null) return (false, "—"); var installed = Convert.ToInt32(k.GetValue("Installed", 0)) == 1; return (installed, Convert.ToString(k.GetValue("Version")) ?? "—"); } catch { return (false, "—"); } }
    private void SetBusy(bool busy, string text = "") { progress.Visible = busy; foreach (var b in Descendants<Button>(this)) b.Enabled = !busy; if (busy) footer.Text = text; UseWaitCursor = busy; }
    private static async Task<int> RunElevatedAsync(string file, string args) { var psi = new ProcessStartInfo(file) { UseShellExecute = true, Verb = "runas", Arguments = args, WorkingDirectory = AppContext.BaseDirectory }; using var p = Process.Start(psi) ?? throw new InvalidOperationException("فرآیند Administrator اجرا نشد."); await p.WaitForExitAsync(); return p.ExitCode; }
    private static string Q(string s) => "\"" + s.Replace("\"", "\\\"") + "\"";
    private static void OpenFolder(string path) { try { Directory.CreateDirectory(path); Process.Start(new ProcessStartInfo("explorer.exe", Q(path)) { UseShellExecute = true }); } catch { } }
    private void ShowError(string message) => MessageBox.Show(this, Safe(message), "سکنا", MessageBoxButtons.OK, MessageBoxIcon.Error, MessageBoxDefaultButton.Button1, MessageBoxOptions.RtlReading | MessageBoxOptions.RightAlign);
    private static string Safe(string s) { s = (s ?? "").Replace('\r', ' ').Replace('\n', ' ').Trim(); return s.Length > 700 ? s[..700] : s; }
    private static string Get(JsonElement r, string p, string d) => r.TryGetProperty(p, out var x) && x.ValueKind == JsonValueKind.String ? x.GetString() ?? d : d;
    private static bool Bool(JsonElement r, string p) => r.TryGetProperty(p, out var x) && x.ValueKind == JsonValueKind.True;
    private static string UpdateText(ComponentPlanRow c) => c.Action switch { ComponentAction.Keep => "به‌روز", ComponentAction.Install => "نیاز به نصب", ComponentAction.Update => "نیاز به به‌روزرسانی", ComponentAction.Repair => "نیاز به تعمیر", ComponentAction.Blocked => "ناسازگار / مسدود", _ => c.ActionText };
    private static Color HealthColor(ComponentHealth h) => h switch { ComponentHealth.Healthy => SetupPalette.Good, ComponentHealth.Stopped or ComponentHealth.Degraded => SetupPalette.Warning, ComponentHealth.Failed => SetupPalette.Danger, _ => SetupPalette.Muted };
    private static Color ActionColor(ComponentAction a) => a switch { ComponentAction.Keep => SetupPalette.Good, ComponentAction.Blocked => SetupPalette.Danger, ComponentAction.Install or ComponentAction.Update or ComponentAction.Repair => SetupPalette.Warning, _ => SetupPalette.Muted };
    private static IEnumerable<T> Descendants<T>(Control root) where T : Control { foreach (Control c in root.Controls) { if (c is T t) yield return t; foreach (var d in Descendants<T>(c)) yield return d; } }
}

internal static class TestFixtures
{
    internal static ComponentPlanSnapshot UpgradePlan() => new("1.1.0", DateTimeOffset.UtcNow, new[]
    {
        Row("runtime","Runtime","1.0.5","1.0.5",ComponentHealth.Healthy,"فعال",ComponentAction.Keep,"up_to_date","بدون تغییر"),
        Row("print-agent","Print Agent","6.2.9","6.2.9",ComponentHealth.Healthy,"فعال",ComponentAction.Keep,"up_to_date","بدون تغییر"),
        Row("maintenance","Maintenance","—","0.2.1",ComponentHealth.Unknown,"نصب نشده",ComponentAction.Install,"install_required","نصب"),
        Row("maintenance-update-engine","Update Engine","—","0.2.0",ComponentHealth.Unknown,"نصب نشده",ComponentAction.Install,"install_required","نصب",false,"maintenance"),
        Row("maintenance-recovery-console","Recovery Console","—","0.1.1",ComponentHealth.Unknown,"نصب نشده",ComponentAction.Install,"install_required","نصب",false,"maintenance")
    });
    internal static ComponentPlanSnapshot EmptyPlan() => new("1.1.0", DateTimeOffset.UtcNow, Array.Empty<ComponentPlanRow>());
    private static ComponentPlanRow Row(string id,string name,string current,string target,ComponentHealth health,string healthText,ComponentAction action,string state,string actionText,bool primary=true,string? parent=null) => new(id,name,primary?"service":"tool",primary,true,parent,current,target,health,healthText,state,action,actionText,"self-test","","","","",primary? id switch {"runtime"=>"SoknaRuntime","print-agent"=>"SoknaPrintWorker",_=>"SoknaMaintenanceSupervisor"}:"","RUNNING",true,parent ?? id);
}

internal static class ComponentPlannerSelfTest
{
    internal static void Run()
    {
        var p = TestFixtures.UpgradePlan();
        if (p.PrimaryRows.Count != 3) throw new InvalidOperationException("planner_primary_count");
        if (p.PrimaryRows[0].Action != ComponentAction.Keep || p.PrimaryRows[1].Action != ComponentAction.Keep || p.PrimaryRows[2].Action != ComponentAction.Install) throw new InvalidOperationException("planner_action_contract");
        if (!p.HasMutation || p.IsBlocked) throw new InvalidOperationException("planner_summary_contract");
    }
}
