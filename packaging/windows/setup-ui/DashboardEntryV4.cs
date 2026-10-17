using System.Drawing.Imaging;
using System.Runtime.InteropServices;

namespace Sokna.SetupUi;

internal static class DashboardEntryV4
{
    private const int SwHide = 0;
    private const int SwShowNoActivate = 4;

    [DllImport("user32.dll")]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool ShowWindow(IntPtr hWnd, int nCmdShow);

    [DllImport("user32.dll")]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool UpdateWindow(IntPtr hWnd);

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
        using var form = new WindowsServicesDashboardV3(telemetry);
        DashboardUiPolish.Apply(form);
        Application.Run(form);
        return 0;
    }

    private static int RunLayoutSelfTest(string output)
    {
        Directory.CreateDirectory(output);
        try
        {
            var plan = TestFixtures.UpgradePlan();
            var expectedBadge = DashboardPackageVersionBinder.BadgeText(AppContext.BaseDirectory);
            if (expectedBadge.EndsWith(" unknown", StringComparison.Ordinal))
                throw new InvalidOperationException("package-manifest-unavailable-for-painted-self-test");

            foreach (var size in new[] { new Size(960, 600), new Size(1100, 660), new Size(1280, 720) })
            {
                using var form = new WindowsServicesDashboardV3(null) { ClientSize = size, StartPosition = FormStartPosition.Manual, Location = new Point(8, 8) };
                DashboardUiPolish.Apply(form);
                CreateControlTree(form);

                // Exercise the real Render path directly. Render removes and adds component
                // controls dynamically, reproducing the Cafe collection-mutation surface.
                // Native ShowWindow makes the HWND genuinely paintable without calling
                // Form.Show(), so the production Shown -> RefreshAsync path is not invoked.
                form.ApplyTestPlan(plan);
                PerformLayoutTree(form);
                NativePaintReady(form);

                var errors = LayoutAudit(form).ToList();
                var labels = Desc(form).OfType<Label>().ToArray();
                var subtitle = labels.FirstOrDefault(x => x.Text.Contains("سه سرویس اصلی سکنا", StringComparison.Ordinal));
                if (subtitle is null || !subtitle.Visible || subtitle.ClientSize.Height < 10)
                    errors.Add("header-subtitle-not-visible");

                var badge = labels.FirstOrDefault(x => x.Text.StartsWith("Windows Services ", StringComparison.Ordinal));
                if (badge is null)
                    errors.Add("package-badge-missing");
                else if (!string.Equals(badge.Text, expectedBadge, StringComparison.Ordinal))
                    errors.Add($"package-badge-mismatch:expected={expectedBadge}:actual={badge.Text}");
                if (labels.Any(x => string.Equals(x.Text, "Windows Services 1.1.6", StringComparison.Ordinal)))
                    errors.Add("stale-package-badge-1.1.6-painted");

                File.WriteAllLines(Path.Combine(output, $"dashboard-{size.Width}x{size.Height}.txt"), errors.Count == 0 ? ["PASS", "BADGE=" + expectedBadge] : errors);
                CapturePainted(form, Path.Combine(output, $"dashboard-{size.Width}x{size.Height}.png"), 800);
                if (errors.Count > 0) throw new InvalidOperationException(string.Join(" | ", errors));
                ShowWindow(form.Handle, SwHide);
            }

            using (var dialog = new ComponentPlanDialog(plan) { StartPosition = FormStartPosition.Manual, Location = new Point(16, 16) })
            {
                CreateControlTree(dialog);
                PerformLayoutTree(dialog);
                NativePaintReady(dialog);
                var errors = LayoutAudit(dialog).ToArray();
                File.WriteAllLines(Path.Combine(output, "component-plan.txt"), errors.Length == 0 ? ["PASS"] : errors);
                CapturePainted(dialog, Path.Combine(output, "component-plan.png"), 25);
                if (errors.Length > 0) throw new InvalidOperationException(string.Join(" | ", errors));
                ShowWindow(dialog.Handle, SwHide);
            }

            using (var result = new InstallerResultDialog(false, "Maintenance راه‌اندازی نشد", "ثبت سرویس انجام شد اما health check در زمان مجاز به وضعیت Running نرسید.", "ins-20261015T120000Z-abc123def0", "Maintenance", "Health verification", true, false)
            { StartPosition = FormStartPosition.Manual, Location = new Point(24, 24) })
            {
                CreateControlTree(result);
                PerformLayoutTree(result);
                NativePaintReady(result);
                var errors = LayoutAudit(result).ToArray();
                File.WriteAllLines(Path.Combine(output, "failure-result.txt"), errors.Length == 0 ? ["PASS"] : errors);
                CapturePainted(result, Path.Combine(output, "failure-result.png"), 25);
                if (errors.Length > 0) throw new InvalidOperationException(string.Join(" | ", errors));
                ShowWindow(result.Handle, SwHide);
            }

            File.WriteAllText(Path.Combine(output, "layout-self-test.txt"), "PASS" + Environment.NewLine + "BADGE=" + expectedBadge + Environment.NewLine + "DYNAMIC_RENDER=PASS" + Environment.NewLine + "NATIVE_PAINT=PASS");
            Console.WriteLine("SOKNA Dashboard V4 visible native-paint render and canonical-version self-test PASS: " + expectedBadge);
            return 0;
        }
        catch (Exception exception)
        {
            var diagnostic = exception.GetType().FullName + Environment.NewLine + exception.Message + Environment.NewLine + exception.StackTrace;
            try { File.WriteAllText(Path.Combine(output, "layout-self-test-exception.txt"), diagnostic); } catch { }
            return 11;
        }
    }

    private static void CreateControlTree(Control root)
    {
        root.CreateControl();
        var children = root.Controls.Cast<Control>().ToArray();
        foreach (var child in children) CreateControlTree(child);
    }

    private static void PerformLayoutTree(Control root)
    {
        var children = root.Controls.Cast<Control>().ToArray();
        foreach (var child in children) PerformLayoutTree(child);
        root.PerformLayout();
    }

    private static void NativePaintReady(Form form)
    {
        _ = form.Handle;
        if (!ShowWindow(form.Handle, SwShowNoActivate))
        {
            // A false return can simply mean the prior native visibility was false; the
            // HWND may still have been transitioned successfully. Verify by painting.
        }
        UpdateWindow(form.Handle);
        PerformLayoutTree(form);
        form.Invalidate(true);
        form.Update();
        Application.DoEvents();
        Thread.Sleep(40);
    }

    private static IEnumerable<string> LayoutAudit(Control root)
    {
        foreach (var c in Desc(root).ToArray())
        {
            if (!c.Visible) continue;
            if (c.Bounds.Width < 0 || c.Bounds.Height < 0) yield return $"invalid-bounds:{c.Name}:{c.Bounds}";
            if (c.Parent is not null && (c.Left < -2 || c.Top < -2 || c.Right > c.Parent.ClientSize.Width + 2 || c.Bottom > c.Parent.ClientSize.Height + 2))
                yield return $"overflow:{c.GetType().Name}:{c.Text}:{c.Bounds}:parent={c.Parent.ClientSize}";
            if (c is Button or CheckBox or TextBox or Label && (c.ClientSize.Width <= 2 || c.ClientSize.Height <= 2))
                yield return $"collapsed:{c.GetType().Name}:{c.Text}:{c.ClientSize}";
            if (c is Button b && (b.ClientSize.Width + 2 < b.PreferredSize.Width || b.ClientSize.Height + 2 < b.PreferredSize.Height))
                yield return $"button-clipped:{b.Text}:{b.ClientSize}:{b.PreferredSize}";
            if (c is Label l && !l.AutoSize && !l.AutoEllipsis && l.PreferredSize.Height > l.ClientSize.Height + 3)
                yield return $"label-clipped:{l.Text}";
        }
        if (root is ScrollableControl sc && sc.AutoScroll) yield return "unexpected-autoscroll";
    }

    private static IEnumerable<Control> Desc(Control root)
    {
        var children = root.Controls.Cast<Control>().ToArray();
        foreach (var c in children)
        {
            yield return c;
            foreach (var d in Desc(c)) yield return d;
        }
    }

    private static void CapturePainted(Form form, string path, int minimumBrandPixels)
    {
        NativePaintReady(form);
        var size = form.ClientSize;
        using var bmp = new Bitmap(Math.Max(1, size.Width), Math.Max(1, size.Height), PixelFormat.Format32bppArgb);
        form.DrawToBitmap(bmp, new Rectangle(Point.Empty, size));

        var brand = SetupPalette.Brand;
        var brandPixels = 0;
        var sampleStep = Math.Max(1, Math.Min(size.Width, size.Height) / 300);
        for (var y = 0; y < size.Height; y += sampleStep)
        for (var x = 0; x < size.Width; x += sampleStep)
        {
            var px = bmp.GetPixel(x, y);
            if (Math.Abs(px.R - brand.R) <= 3 && Math.Abs(px.G - brand.G) <= 3 && Math.Abs(px.B - brand.B) <= 3) brandPixels++;
        }
        if (brandPixels < minimumBrandPixels)
            throw new InvalidOperationException($"paint_sanity_failed:{Path.GetFileName(path)}:brand_pixels={brandPixels}:required={minimumBrandPixels}");

        bmp.Save(path, ImageFormat.Png);
    }
}
