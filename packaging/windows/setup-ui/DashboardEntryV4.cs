using System.Drawing.Imaging;

namespace Sokna.SetupUi;

internal static class DashboardEntryV4
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
            foreach (var size in new[] { new Size(960, 600), new Size(1100, 660), new Size(1280, 720) })
            {
                using var form = new WindowsServicesDashboardV3(null) { ClientSize = size, StartPosition = FormStartPosition.Manual, Location = new Point(20, 20) };
                DashboardUiPolish.Apply(form);
                form.Show();
                Application.DoEvents();
                form.ApplyTestPlan(plan);
                form.PerformLayout();
                Application.DoEvents();
                var errors = LayoutAudit(form).ToArray();
                File.WriteAllLines(Path.Combine(output, $"dashboard-{size.Width}x{size.Height}.txt"), errors.Length == 0 ? ["PASS"] : errors);
                CapturePainted(form, Path.Combine(output, $"dashboard-{size.Width}x{size.Height}.png"), 800);
                if (errors.Length > 0) throw new InvalidOperationException(string.Join(" | ", errors));
                form.Hide();
            }

            using (var dialog = new ComponentPlanDialog(plan) { StartPosition = FormStartPosition.Manual, Location = new Point(30, 30) })
            {
                dialog.Show();
                Application.DoEvents();
                dialog.PerformLayout();
                Application.DoEvents();
                var errors = LayoutAudit(dialog).ToArray();
                File.WriteAllLines(Path.Combine(output, "component-plan.txt"), errors.Length == 0 ? ["PASS"] : errors);
                CapturePainted(dialog, Path.Combine(output, "component-plan.png"), 25);
                if (errors.Length > 0) throw new InvalidOperationException(string.Join(" | ", errors));
                dialog.Hide();
            }

            using (var result = new InstallerResultDialog(false, "Maintenance راه‌اندازی نشد", "ثبت سرویس انجام شد اما health check در زمان مجاز به وضعیت Running نرسید.", "ins-20261015T120000Z-abc123def0", "Maintenance", "Health verification", true, false)
            { StartPosition = FormStartPosition.Manual, Location = new Point(40, 40) })
            {
                result.Show();
                Application.DoEvents();
                result.PerformLayout();
                Application.DoEvents();
                var errors = LayoutAudit(result).ToArray();
                File.WriteAllLines(Path.Combine(output, "failure-result.txt"), errors.Length == 0 ? ["PASS"] : errors);
                CapturePainted(result, Path.Combine(output, "failure-result.png"), 25);
                if (errors.Length > 0) throw new InvalidOperationException(string.Join(" | ", errors));
                result.Hide();
            }

            File.WriteAllText(Path.Combine(output, "layout-self-test.txt"), "PASS");
            Console.WriteLine("SOKNA Dashboard V4 painted-layout self-test PASS");
            return 0;
        }
        catch (Exception exception)
        {
            var diagnostic = exception.GetType().FullName + Environment.NewLine + exception.Message + Environment.NewLine + exception.StackTrace;
            try { File.WriteAllText(Path.Combine(output, "layout-self-test-exception.txt"), diagnostic); } catch { }
            return 11;
        }
    }

    private static IEnumerable<string> LayoutAudit(Control root)
    {
        foreach (Control c in Desc(root))
        {
            if (!c.Visible) continue;
            if (c.Bounds.Width < 0 || c.Bounds.Height < 0) yield return $"invalid-bounds:{c.Name}:{c.Bounds}";
            if (c is Button b && (b.ClientSize.Width + 2 < b.PreferredSize.Width || b.ClientSize.Height + 2 < b.PreferredSize.Height))
                yield return $"button-clipped:{b.Text}:{b.ClientSize}:{b.PreferredSize}";
            if (c is Label l && !l.AutoSize && !l.AutoEllipsis && l.PreferredSize.Height > l.ClientSize.Height + 3)
                yield return $"label-clipped:{l.Text}";
        }
        if (root is ScrollableControl sc && sc.AutoScroll) yield return "unexpected-autoscroll";
    }

    private static IEnumerable<Control> Desc(Control root)
    {
        foreach (Control c in root.Controls)
        {
            yield return c;
            foreach (var d in Desc(c)) yield return d;
        }
    }

    private static void CapturePainted(Form form, string path, int minimumBrandPixels)
    {
        form.Refresh();
        Application.DoEvents();
        var surface = form.Controls.Count > 0 ? form.Controls[0] : form;
        var size = surface.ClientSize;
        using var bmp = new Bitmap(Math.Max(1, size.Width), Math.Max(1, size.Height), PixelFormat.Format32bppArgb);
        surface.DrawToBitmap(bmp, new Rectangle(Point.Empty, size));

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
